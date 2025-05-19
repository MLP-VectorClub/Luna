<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ChangeShowTableUnique extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('show', function (Blueprint $table) {
            $table->dropUnique(['season', 'episode']);
            $table->unique(['season', 'episode', 'generation']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('show', function (Blueprint $table) {
            $table->dropUnique(['season', 'episode', 'generation']);
            $table->unique(['season', 'episode']);
        });
    }
}
