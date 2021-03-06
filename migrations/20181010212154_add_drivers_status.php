<?php

use Phpmig\Migration\Migration;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

class AddDriversStatus extends Migration {
  public function up() {
    Capsule::schema()->table('drivers', function(Blueprint $table) {
      $table->integer('status')->default(0);
    });
  }

  public function down() {
    Capsule::schema()->table('drivers', function(Blueprint $table) {
      $table->dropColumn('status');
    });
  }
}
