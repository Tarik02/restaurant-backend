<?php
namespace App\Controllers;

use App\Services\Uploads;
use App\Util\Paginator;
use App\Util\Serializer;
use App\Util\OrderStatus;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Collection;
use Slim\Container;
use Slim\Http\Request;
use Slim\Http\Response;
use Slim\Http\UploadedFile;

class OperatorController extends Controller {
  /** @var Container */
  protected $container;

  /** @var Connection */
  protected $db;

  /** @var Uploads */
  protected $uploads;

  /** @var Serializer */
  private $serializer;

  public function __construct(Container $container) {
    parent::__construct($container);

    $this->container = $container;
    $this->db = $container['db']->connection();
    $this->uploads = $this->container['uploads'];
    $this->serializer = $container['serializer'];
  }

  public function orders(Request $request, Response $response, array $args) {
    $this->assertAbility($request, $response, 'operator');

    $page = $request->getParsedBodyParam('page', 1);
    $perPage = clamp($request->getParsedBodyParam('perPage', 15), 5, 50);
    $filter = $request->getParsedBodyParam('filter', 1);

    $query = DB::table('orders')
      ->orderBy('created_at', 'desc');
    if ($statusFilter = $filter['status'] ?? null) {
      $query->whereIn('status', array_map(function (string $name) {
        return OrderStatus::fromString($name);
      },$filter['status']));
    }
    $query->forPage($page, $perPage);

    $total = $query->getCountForPagination();
    $orders = $query->get();

    return $response->withJson([
      'data' => $orders->map(function(array $order) {
        return [
          'id' => $order['id'],
          'name' => $order['contact_name'],
          'phone' => $order['phone'],

          'created_at' => $this->serializer->dateTime($order['created_at']),
          'price' => $order['price'],
          'status' => OrderStatus::toString($order['status']),

          'address' => $order['address'],
          'lat' => $order['latitude'],
          'lng' => $order['longtitude'],

          'driver_id' => $order['driver_id'],
        ];
      }),

      'total' => $total,
    ]);
  }

  public function courses(Request $request, Response $response, array $args) {
    $this->assertAbility($request, $response, 'operator');

    $courses = DB::table('courses')->orderBy('id', 'desc')->get();
    $coursesIds = $courses->pluck('id');

    $ingredients = DB::table('courses_ingredients')
      ->whereIn('course_id', $coursesIds)
      ->get()
      ->groupBy('course_id');

    $images = DB::table('courses_images')
      ->whereIn('course_id', $coursesIds)
      ->get()
      ->groupBy('course_id');

    return $response->withJson([
      'data' => $courses->map(function(array $course) use($ingredients, $images, $request) {
        $id = intval($course['id']);

        /** @var Collection|null $courseIngredients */
        $courseIngredients = $ingredients[$id] ?? null;

        /** @var Collection|null $courseImages */
        $courseImages = $images[$id] ?? null;

        return [
          'id' => $id,
          'title' => $course['title'],
          'description' => $course['description'],
          'images' => $courseImages !== null
            ? collect($courseImages)
              ->pluck('src')->toArray()
            : [],
          'price' => intval($course['price']),
          'visible' => boolval($course['visible']),

          'ingredients' => $courseIngredients !== null
            ? $courseIngredients
              ->pluck('amount', 'ingredient_id')
              ->map(function($v) {
                return floatval($v);
              })
            : [],
        ];
      }),
    ]);
  }

  public function courseSave(Request $request, Response $response, array $args) {
    $this->assertAbility($request, $response, 'operator');

    $body = json_decode($request->getParsedBodyParam('data'), true);
    $files = $request->getUploadedFiles()['files'] ?? [];

    $id = $body['id'] ?? null;
    $images = $body['images'];

    $firstNull = 0;
    /** @var UploadedFile $file */
    foreach ($files as $file) {
      $public = $this->uploads->upload($file);

      while ($images[$firstNull] ?? null !== null) {
        ++$firstNull;
      }
      $images[$firstNull++] = $public;
    }

    /** @noinspection PhpUnhandledExceptionInspection */
    return DB::connection()->transaction(function() use($id, $body, $images, $response) {
      $courses = DB::table('courses');
      $coursesIngredients = DB::table('courses_ingredients');
      $coursesImages = DB::table('courses_images');

      if ($id !== null) {
        $coursesIngredients->where('course_id', $id)->delete();
        $coursesImages->where('course_id', $id)->delete();
      }

      $data = [
        'title' => $body['title'],
        'description' => $body['description'],
        'price' => $body['price'],
        'visible' => $body['visible'],
      ];

      if ($id === null) {
        $id = $courses->insertGetId($data);
      } else {
        $courses->where('id', $id)->update($data);
      }

      $coursesIngredients->insert(
        collect($body['ingredients'])
          ->mapWithKeys(function (float $amount, int $ingredient) use ($id) {
            return [
              $ingredient => [
                'course_id' => $id,
                'ingredient_id' => $ingredient,
                'amount' => $amount,
              ]
            ];
          })
          ->values()
          ->toArray()
      );

      $coursesImages->insert(
        collect($images)->map(function (string $image) use ($id) {
          return [
            'course_id' => $id,
            'src' => $image,
          ];
        })->toArray()
      );

      return $response->withJson([
        'status' => 'ok',
        'id' => $id,
      ]);
    });
  }

  public function courseRemove(Request $request, Response $response, array $args) {
    $this->assertAbility($request, $response, 'operator');

    $id = $request->getParsedBodyParam('id');

    DB::table('courses')->where('id', $id)->delete();
    DB::table('courses_ingredients')->where('course_id', $id)->delete();
    DB::table('courses_images')->where('course_id', $id)->delete();

    return $response->withJson([
      'status' => 'ok',
    ]);
  }

  public function ingredients(Request $request, Response $response, array $args) {
    $this->assertAbility($request, $response, 'operator');

    $query = DB::table('ingredients');

    $all = $request->getParam('all', 'false') === 'true';

    $sortBy = $request->getParam('sortBy', 'title');
    $descending = $request->getParam('descending', 'false') === 'true';

    if (!in_array($sortBy, ['title', 'price', 'unit'])) {
      return $response->withStatus(500);
    }

    if (!$all) {
      $page = intval($request->getParam('page', 1));
      $perPage = clamp(intval($request->getParam('perPage', 15)), 5, 100);

      $query
        ->orderBy($sortBy, $descending ? 'desc' : 'asc')
        ->forPage($page, $perPage);
      $total = $query->getCountForPagination();
    } else {
      $page = null;
      $perPage = null;
      $total = null;
    }

    $ingredients = $query->get();

    return $response->withJson([
      'data' => $ingredients->map(function (array $ingredient) {
        return [
          'id' => intval($ingredient['id']),
          'title' => $ingredient['title'],
          'price' => intval($ingredient['price']),
          'unit' => $ingredient['unit'],
          'floating' => boolval($ingredient['floating']),
        ];
      }),

      'pagination' => [
        'page' => $page,
        'perPage' => $perPage,
        'totalCount' => $total,
      ],
    ]);
  }

  public function ingredientSave(Request $request, Response $response, array $args) {
    $this->assertAbility($request, $response, 'operator');

    $ingredients = DB::table('ingredients');

    $body = $request->getParsedBody();
    $id = $body['id'] ?? null;

    $data = [
      'title' => $body['title'],
      'price' => $body['price'],
      'unit' => $body['unit'],
      'floating' => $body['floating'],
    ];
    if ($id === null) {
      $id = $ingredients->insertGetId($data);
    } else {
      $ingredients->where('id', $id)->update($data);
    }

    return $response->withJson([
      'status' => 'ok',
      'id' => $id,
    ]);
  }

  public function ingredientDelete(Request $request, Response $response, array $args) {
    $this->assertAbility($request, $response, 'operator');

    $id = intval($request->getParam('id'));
    if ($id === 0) {
      return $response->withJson([
        'status' => 'error',
        'reason' => 'bad_request',
      ]);
    }

    $ingredients = DB::table('ingredients');
    $coursesIngredients = DB::table('courses_ingredients');

    if ($ingredients->delete($id) !== 1) {
      return $response->withJson([
        'status' => 'error',
        'reason' => 'not_exist',
      ]);
    }

    $coursesIngredients->where('ingredient_id', $id)->delete();

    return $response->withJson([
      'status' => 'ok',
    ]);
  }


  public function users(Request $request, Response $response, array $args) {
    $this->assertAbility($request, $response, 'operator');

    $storages = $this->db->table('storages')->get();

    $query = $this->db->table('users');
    $roles = $request->getParam('roles', []);
    foreach ($roles as $role) {
      $query->orWhereJsonContains('roles', $role);
    }

    $pagination = (new Paginator())
      ->minPerPage(5)
      ->maxPerPage(100)
      ->page(intval($request->getParam('page', 1)))
      ->perPage(intval($request->getParam('perPage', 15)))
      ->apply($response, $query);

    $total = $query->getCountForPagination();
    $reviews = $query->get();

    return $response->withJson([
      'storages' => $storages->map(function (array $storage) {
        return [
          'id' => intval($storage['id']),
          'name' => $storage['name'],
        ];
      }),

      'roles' => [
//        'user' => 'Користувач',
        'driver' => 'Водій',
        'storage' => 'Кафе',
        'operator' => 'Оператор',
        'reviews' => 'Відгуки',
        'cook' => 'Кухар',
        'stats' => 'Статистика',
      ],

      'data' => $reviews->map(function (array $user) {
        $id = intval($user['id']);
        $roles = json_decode($user['roles'], false);

        $additonal = [];
        if (in_array('cook', $roles)) {
          $cook = $this->db->table('cooks')->where('user_id', $id)->first();

          if ($cook === null) {
            $additonal['storage_id'] = null;
          } else {
            $additonal['storage_id'] = intval($cook['storage_id']);
          }
        }

        return [
          'id' => $id,

          'username' => $user['username'],
          'email' => $user['email'],
          'phone' => $user['phone'],

          'roles' => $roles,

          'additonal' => $additonal,
        ];
      }),

      'meta' => [
        'totalCount' => $total,
        'pagination' => $pagination,
      ],
    ]);
  }

  public function userRoles(Request $request, Response $response, array $args) {
    $this->assertAbility($request, $response, 'operator');

    $id = $this->assert($response, $args['id'] ?? null);
    $roles = $request->getParsedBodyParam('roles');

    $user = $this->db->table('users')->find($id);

    if ($user === null) {
      return $response->withJson([
        'status' => 'not-exists',
      ]);
    }

    $this->db->table('users')
      ->where('id', $id)
      ->update([
        'roles' => json_encode($roles),
      ]);

    if (in_array('cook', $roles)) {
      $storageId = $request->getParsedBodyParam('storage_id');
      $this->db->table('cooks')->updateOrInsert([
        'user_id' => $id,
      ], [
        'storage_id' => $storageId,
      ]);
    }

    return $response->withJson([
      'status' => 'ok',
    ]);
  }
}
