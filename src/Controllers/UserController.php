<?php

namespace App\Controllers;

use App\Services\UsersService;
use Slim\Container;
use Slim\Http\Request;
use Slim\Http\Response;

class UserController extends Controller {
  /** @var Container */
  protected $container;

  /** @var UsersService */
  protected $users;

  public function __construct(Container $container) {
    parent::__construct($container);

    $this->container = $container;
    $this->users = $container['users'];
  }

  public function register(Request $request, Response $response, array $args) {
    $body = $request->getParsedBody();

    if ($this->users->exists($body['username'], $body['email'], $body['phone'])) {
      return $response->withJson([
        'status' => 'error',
        'reason' => 'already_exists',
      ]);
    }

    $id = $this->users->register([
      'username' => $body['username'],
      'email' => $body['email'] ?? null,
      'phone' => $body['phone'] ?? null,
      'password' => $body['password'],
    ]);

    return $response->withJson([
      'status' => 'ok',
      'id' => $id,
    ]);
  }

  public function user(Request $request, Response $response, array $args) {
    $user = $this->users->getUserFromRequest($request);

    return $response->withJson(null === $user ? null : [
      'id' => $user['id'],
      'username' => $user['username'],
      'email' => $user['email'],
      'phone' => $user['phone'],
      'avatar' => $user['avatar'],
      'roles' => $user['roles'],
    ]);
  }
}
