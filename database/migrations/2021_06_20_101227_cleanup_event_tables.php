<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CleanupEventTables extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('event_entries', function (Blueprint $table) {
            $table->dropColumn('score');
        });

        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('type');
        });

        Schema::table('event_entry_votes', function (Blueprint $table) {
            $table->drop();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('event_entries', function (Blueprint $table) {
           $table->integer('score')->nullable();
        });

        Schema::table('events', function (Blueprint $table) {
           $table->string('type', 10)->default('collab');
        });

        Schema::create('event_entry_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entry_id')->constrained('event_entries')->onDelete('cascade')->onUpdate('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('restrict')->onUpdate('cascade');
            $table->smallInteger('value');
            $table->timestampsTz(config('app.timestamp_precision'));

            $table->unique(['entry_id', 'user_id']);
        });
    }
}
