<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateUsersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('users', function (Blueprint $table) {
            $ts_precision = config('app.timestamp_precision');

            $table->bigIncrements('id');
            $table->string('name')->unique();
            $table->string('email')->unique()->nullable();
            $table->string('role', 10)->default('user');
            $table->timestampTz('email_verified_at', $ts_precision)->nullable();
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->timestampsTz($ts_precision);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('users');
    }
}
