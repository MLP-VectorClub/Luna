<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ImportOldSchema extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('deviantart_users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->addColumn('citext', 'name')->unique();
            $table->string('role', 10)->default('user');
            $table->string('avatar_url')->nullable()->default(null);
            $table->timestampsTz();
        });

        Schema::create('appearances', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('order')->nullable()->index();
            $table->string('label', 70)->index();
            $table->text('notes_src')->nullable();
            $table->text('notes_rend')->nullable();
            $table->string('guide', 4)->nullable()->index();
            $table->boolean('private')->default(false);
            $table->uuid('owner_id')->nullable()->index();
            $table->timestampTz('last_cleared')->nullable();
            $table->uuid('token');
            $table->string('sprite_hash', 32);
            $table->timestampsTz();

            $table->foreign('owner_id')->references('id')->on('deviantart_users')->onDelete('restrict')->onUpdate('cascade');
        });

        Schema::create('color_groups', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('appearance_id')->constrained('appearances')->onDelete('cascade')->onUpdate('cascade');
            $table->string('label', 255);
            $table->integer('order');
            $table->unique(['appearance_id', 'label']);
        });

        Schema::create('colors', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('group_id')->index()->constrained('color_groups')->onDelete('cascade')->onUpdate('cascade');
            $table->integer('order');
            $table->string('label', 255);
            $table->char('hex', 7)->nullable();
        });

        Schema::create('cutiemarks', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('appearance_id')->index()->constrained('appearances')->onDelete('cascade')->onUpdate('cascade');
            $table->string('facing', 10)->nullable();
            $table->string('favme', 7)->nullable();
            $table->smallInteger('rotation');
            $table->uuid('contributor_id')->nullable()->nullable();
            $table->string('label', 24)->nullable();
        });

        Schema::create('discord_members', function (Blueprint $table) {
            $table->bigInteger('id');
            $table->uuid('user_id')->unique()->nullable();
            $table->string('username', 255);
            $table->char('discriminator', 4);
            $table->string('nick', 255)->nullable();
            $table->string('avatar_hash', 255)->nullable();
            $table->timestampTz('joined_at')->nullable();
            $table->string('access', 30)->nullable();
            $table->string('refresh', 30)->nullable();
            $table->string('scope', 30)->nullable();
            $table->timestampTz('expires')->nullable();
            $table->timestampTz('last_synced')->nullable();

            $table->foreign('user_id')->references('id')->on('deviantart_users')->onDelete('restrict')->onUpdate('cascade');
        });

        Schema::create('events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 64);
            $table->string('type', 10);
            $table->string('entry_role', 15);
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->uuid('added_by')->index();
            $table->timestampsTz();
            $table->text('desc_src');
            $table->text('desc_rend');
            $table->integer('max_entries')->nullable();
            $table->string('vote_role', 15)->nullable();
            $table->string('result_favme', 7)->nullable();
            $table->timestampTz('finalized_at')->nullable();
            $table->uuid('finalized_by')->index()->nullable();

            $table->foreign('added_by')->references('id')->on('deviantart_users')->onDelete('restrict')->onUpdate('cascade');
            $table->foreign('finalized_by')->references('id')->on('deviantart_users')->onDelete('restrict')->onUpdate('cascade');
        });

        Schema::create('event_entries', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('event_id')->index()->constrained('events')->onDelete('cascade')->onUpdate('cascade');
            $table->string('prev_src', 255)->nullable();
            $table->string('prev_full', 255)->nullable();
            $table->string('prev_thumb', 255)->nullable();
            $table->string('sub_prov', 20);
            $table->string('sub_id', 20);
            $table->uuid('submitted_by');
            $table->timestampsTz();
            $table->string('title', 64);
            $table->integer('score')->nullable();

            $table->foreign('submitted_by')->references('id')->on('deviantart_users')->onDelete('restrict')->onUpdate('cascade');
        });

        Schema::create('event_entry_votes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('entry_id')->references('id')->on('event_entries')->onDelete('cascade')->onUpdate('cascade');
            $table->uuid('user_id');
            $table->smallInteger('value');
            $table->timestampsTz();
            $table->unique(['entry_id', 'user_id']);

            $table->foreign('user_id')->references('id')->on('deviantart_users')->onDelete('restrict')->onUpdate('cascade');
        });

        Schema::create('notices', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('message_html', 500);
            $table->string('type', 16);
            $table->uuid('posted_by')->index();
            $table->timestampsTz();
            $table->timestampTz('hide_after');
            $table->uuid('old_id')->nullable();

            $table->foreign('posted_by')->references('id')->on('deviantart_users')->onDelete('restrict')->onUpdate('cascade');
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('recipient_id')->index();
            $table->string('type', 15)->index();
            $table->jsonb('data');
            $table->timestampsTz();
            $table->timestampTz('read_at');
            $table->string('read_action', 15);

            $table->foreign('recipient_id')->references('id')->on('deviantart_users')->onDelete('restrict')->onUpdate('cascade');
        });

        Schema::create('pcg_point_grants', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('receiver_id')->index();
            $table->uuid('sender_id')->index();
            $table->integer('amount');
            $table->string('comment', 140)->nullable();
            $table->timestampsTz();

            $table->foreign('receiver_id')->references('id')->on('deviantart_users')->onDelete('restrict')->onUpdate('cascade');
            $table->foreign('sender_id')->references('id')->on('deviantart_users')->onDelete('restrict')->onUpdate('cascade');
        });

        Schema::create('pcg_slot_history', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('user_id')->index();
            $table->string('change_type', 15);
            $table->jsonb('change_data')->nullable();
            $table->integer('change_amount');
            $table->timestampsTz();

            $table->foreign('user_id')->references('id')->on('deviantart_users')->onDelete('restrict')->onUpdate('cascade');
        });

        Schema::create('show', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('type', 10);
            $table->integer('season')->nullable();
            $table->integer('episode')->nullable();
            $table->integer('parts')->default(1)->nullable();
            $table->text('title');
            $table->timestampsTz();
            $table->uuid('posted_by')->index();
            $table->timestampTz('airs')->nullable();
            $table->smallInteger('no')->nullable();
            $table->unsignedFloat('score', 2, 1)->default(0);
            $table->text('notes')->nullable();
            $table->timestampTz('synopsis_last_checked')->nullable();
            $table->unique(['season', 'episode']);

            $table->foreign('posted_by')->references('id')->on('deviantart_users')->onDelete('restrict')->onUpdate('cascade');
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('old_id')->nullable()->index();
            $table->string('type', 3)->nullable();
            $table->string('preview', 1024)->nullable();
            $table->string('fullsize', 1024)->nullable();
            $table->string('label', 255)->nullable();
            $table->uuid('requested_by')->nullable()->index();
            $table->timestampTz('requested_at')->nullable();
            $table->uuid('reserved_by')->nullable()->index();
            $table->timestampTz('reserved_at')->nullable();
            $table->string('deviation_id', 7)->nullable();
            $table->boolean('lock')->default(false);
            $table->timestampTz('finished_at')->nullable();
            $table->boolean('broken')->default(false);
            $table->foreignId('show_id')->index()->constrained('show')->onDelete('cascade')->onUpdate('cascade');
            $table->timestampTz('updated_at')->nullable();

            $table->foreign('requested_by')->references('id')->on('deviantart_users')->onDelete('restrict')->onUpdate('cascade');
            $table->foreign('reserved_by')->references('id')->on('deviantart_users')->onDelete('restrict')->onUpdate('cascade');
        });

        Schema::create('related_appearances', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('source_id')->constrained('appearances')->onDelete('cascade')->onUpdate('cascade');
            $table->foreignId('target_id')->constrained('appearances')->onDelete('cascade')->onUpdate('cascade');
            $table->unique(['source_id', 'target_id']);
        });

        Schema::create('show_appearances', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('show_id')->constrained('show')->onDelete('cascade')->onUpdate('cascade');
            $table->foreignId('appearance_id')->constrained('appearances')->onDelete('cascade')->onUpdate('cascade');
            $table->unique(['show_id', 'appearance_id']);
        });

        Schema::create('show_videos', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('provider', 2);
            $table->string('external_id', 64);
            $table->integer('part')->default(1);
            $table->boolean('fullep')->default(true);
            $table->timestampsTz();
            $table->timestampTz('not_broken_at')->nullable();
            $table->foreignId('show_id')->constrained('show')->onDelete('cascade')->onUpdate('cascade');
            $table->unique(['show_id', 'provider', 'part']);
        });

        Schema::create('show_votes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('user_id')->index();
            $table->smallInteger('vote');
            $table->foreignId('show_id')->constrained('show')->onDelete('cascade')->onUpdate('cascade');
            $table->unique(['show_id', 'user_id']);

            $table->foreign('user_id')->references('id')->on('deviantart_users')->onDelete('restrict')->onUpdate('cascade');
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 30)->index();
            $table->string('title', 255)->nullable();
            $table->string('type', 4)->nullable();
            $table->bigInteger('uses')->default(0);
            $table->foreignId('synonym_of')->nullable()->constrained('tags')->onDelete('set null')->onUpdate('cascade');
            $table->unique(['name', 'type']);
        });

        Schema::create('tag_changes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tag_id')->index()->constrained('tags')->onDelete('cascade')->onUpdate('cascade');
            $table->foreignId('appearance_id')->index()->constrained('appearances')->onDelete('cascade')->onUpdate('cascade');
            $table->uuid('user_id')->index();
            $table->boolean('added');
            $table->string('tag_name', 30);
            $table->timestampsTz();

            $table->foreign('user_id')->references('id')->on('deviantart_users')->onDelete('restrict')->onUpdate('cascade');
        });

        Schema::create('tagged', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tag_id')->constrained('tags')->onDelete('cascade')->onUpdate('cascade');
            $table->foreignId('appearance_id')->constrained('appearances')->onDelete('cascade')->onUpdate('cascade');
            $table->unique(['tag_id', 'appearance_id']);
        });

        Schema::create('useful_links', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('url', 255);
            $table->string('label', 40);
            $table->string('title', 255);
            $table->string('minrole', 10);
            $table->integer('order')->nullable();
        });

        Schema::create('user_prefs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('user_id');
            $table->string('key', 50);
            $table->text('value')->nullable();
            $table->unique(['user_id', 'key']);

            $table->foreign('user_id')->references('id')->on('deviantart_users')->onDelete('restrict')->onUpdate('cascade');
        });

        Schema::create('name_changes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('user_id');
            $table->addColumn('citext', 'old');
            $table->addColumn('citext', 'new');

            $table->foreign('user_id')->references('id')->on('deviantart_users')->onDelete('restrict')->onUpdate('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('colors');
        Schema::dropIfExists('color_groups');
        Schema::dropIfExists('cutiemarks');
        Schema::dropIfExists('discord_members');
        Schema::dropIfExists('event_entry_votes');
        Schema::dropIfExists('event_entries');
        Schema::dropIfExists('events');
        Schema::dropIfExists('notices');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('pcg_point_grants');
        Schema::dropIfExists('pcg_slot_history');
        Schema::dropIfExists('posts');
        Schema::dropIfExists('related_appearances');
        Schema::dropIfExists('show_appearances');
        Schema::dropIfExists('show_videos');
        Schema::dropIfExists('show_votes');
        Schema::dropIfExists('show');
        Schema::dropIfExists('tag_changes');
        Schema::dropIfExists('tagged');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('useful_links');
        Schema::dropIfExists('user_prefs');
        Schema::dropIfExists('appearances');
        Schema::dropIfExists('name_changes');
        Schema::dropIfExists('deviantart_users');
    }
}
