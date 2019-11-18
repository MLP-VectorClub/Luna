<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddPreviousFieldsToUsers extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email', 128)->nullable(true)->change();
            $table->string('role')->default('user');
            $table->string('avatar_url')->nullable()->default(null);
        });

        DB::statement('ALTER TABLE "users" ALTER "name" TYPE citext');
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'avatar_url']);
        });

        DB::statement('ALTER TABLE "users" ALTER "name" TYPE character varying(255)');
    }
}
