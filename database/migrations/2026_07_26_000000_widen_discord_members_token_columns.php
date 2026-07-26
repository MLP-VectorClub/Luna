<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('discord_members', function (Blueprint $table) {
            $table->string('access', 255)->nullable()->change();
            $table->string('refresh', 255)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('discord_members', function (Blueprint $table) {
            $table->string('access', 30)->nullable()->change();
            $table->string('refresh', 30)->nullable()->change();
        });
    }
};
