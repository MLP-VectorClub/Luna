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
        // The generation column no longer exists in fresh databases, the unique key stays on (season, episode)
        if (!Schema::hasColumn('show', 'generation')) {
            return;
        }

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
        if (!Schema::hasColumn('show', 'generation')) {
            return;
        }

        Schema::table('show', function (Blueprint $table) {
            $table->dropUnique(['season', 'episode', 'generation']);
            $table->unique(['season', 'episode']);
        });
    }
}
