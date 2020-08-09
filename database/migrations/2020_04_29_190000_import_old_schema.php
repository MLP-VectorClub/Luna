<?php

use App\EloquentFixes\DBAL\Types\CitextType;
use App\EloquentFixes\DBAL\Types\MlpGenerationType;
use App\Enums\MlpGeneration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ImportOldSchema extends Migration
{
    private ?int $ts_precision;

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $this->ts_precision = config('app.timestamp_precision');

        Schema::create('deviantart_users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->index()->constrained('users')->onDelete('cascade')->onUpdate('cascade');
            $table->addColumn(CitextType::CITEXT, 'name')->unique();
            $table->string('avatar_url');
            $table->timestampsTz($this->ts_precision);
            $table->string('access', 50)->nullable();
            $table->string('refresh', 40)->nullable();
            $table->timestampTz('access_expires', $this->ts_precision)->nullable();
            $table->string('scope', 50)->nullable();
        });

        Schema::create('appearances', function (Blueprint $table) {
            $table->id();
            $table->integer('order')->nullable()->index();
            $table->string('label', 70)->index();
            $table->text('notes_src')->nullable();
            $table->text('notes_rend')->nullable();
            $table->string('guide', 4)->nullable()->index();
            $table->boolean('private')->default(false);
            $table->foreignId('owner_id')->nullable()->index()->constrained('users')->onDelete('restrict')->onUpdate('cascade');
            $table->timestampTz('last_cleared', $this->ts_precision)->nullable();
            $table->uuid('token');
            $table->string('sprite_hash', 32)->nullable();
            $table->timestampsTz($this->ts_precision);
        });

        Schema::create('color_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appearance_id')->constrained('appearances')->onDelete('cascade')->onUpdate('cascade');
            $table->string('label', 255);
            $table->integer('order');

            $table->unique(['appearance_id', 'label']);
        });

        Schema::create('colors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->index()->constrained('color_groups')->onDelete('cascade')->onUpdate('cascade');
            $table->integer('order');
            $table->string('label', 255);
            $table->char('hex', 7)->nullable();
        });

        Schema::create('major_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appearance_id')->index()->constrained('appearances')->onDelete('cascade')->onUpdate('cascade');
            $table->string('reason', 255);
            $table->foreignId('user_id')->index()->constrained('users')->onDelete('restrict')->onUpdate('cascade');
            $table->timestampsTz($this->ts_precision);
        });

        Schema::create('cutiemarks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appearance_id')->index()->constrained('appearances')->onDelete('cascade')->onUpdate('cascade');
            $table->string('facing', 10)->nullable();
            $table->string('favme', 7)->nullable();
            $table->smallInteger('rotation');
            $table->foreignUuid('contributor_id')->nullable()->index()->constrained('deviantart_users')->onDelete('cascade')->onUpdate('cascade');
            $table->string('label', 24)->nullable();
        });

        Schema::create('discord_members', function (Blueprint $table) {
            $table->bigInteger('id')->primary();
            $table->foreignId('user_id')->nullable()->index()->constrained('users')->onDelete('restrict')->onUpdate('cascade');
            $table->string('username');
            $table->char('discriminator', 4);
            $table->string('nick')->nullable();
            $table->string('avatar_hash', 255)->nullable();
            $table->timestampTz('joined_at', $this->ts_precision)->nullable();
            $table->string('access', 30)->nullable();
            $table->string('refresh', 30)->nullable();
            $table->string('scope', 50)->nullable();
            $table->timestampTz('expires', $this->ts_precision)->nullable();
            $table->timestampTz('last_synced', $this->ts_precision)->nullable();
        });

        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64);
            $table->string('type', 10);
            $table->string('entry_role', 15);
            $table->timestampTz('starts_at', $this->ts_precision);
            $table->timestampTz('ends_at', $this->ts_precision);
            $table->foreignId('added_by')->index()->constrained('users')->onDelete('restrict')->onUpdate('cascade');
            $table->timestampsTz($this->ts_precision);
            $table->text('desc_src');
            $table->text('desc_rend');
            $table->integer('max_entries')->nullable();
            $table->string('vote_role', 15)->nullable();
            $table->string('result_favme', 7)->nullable();
            $table->timestampTz('finalized_at', $this->ts_precision)->nullable();
            $table->foreignId('finalized_by')->index()->nullable()->constrained('users')->onDelete('restrict')->onUpdate('cascade');
        });

        Schema::create('event_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->index()->constrained('events')->onDelete('cascade')->onUpdate('cascade');
            $table->string('prev_src', 255)->nullable();
            $table->string('prev_full', 255)->nullable();
            $table->string('prev_thumb', 255)->nullable();
            $table->string('sub_prov', 20);
            $table->string('sub_id', 20);
            $table->timestampsTz($this->ts_precision);
            $table->foreignId('submitted_by')->index()->constrained('users')->onDelete('restrict')->onUpdate('cascade');
            $table->string('title', 64);
            $table->integer('score')->nullable();
        });

        Schema::create('event_entry_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entry_id')->constrained('event_entries')->onDelete('cascade')->onUpdate('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('restrict')->onUpdate('cascade');
            $table->smallInteger('value');
            $table->timestampsTz($this->ts_precision);

            $table->unique(['entry_id', 'user_id']);
        });

        Schema::create('notices', function (Blueprint $table) {
            $table->id();
            $table->string('message_html', 500);
            $table->string('type', 16);
            $table->foreignId('posted_by')->index()->constrained('users')->onDelete('restrict')->onUpdate('cascade');
            $table->timestampsTz($this->ts_precision);
            $table->timestampTz('hide_after', $this->ts_precision);
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipient_id')->index()->constrained('users')->onDelete('restrict')->onUpdate('cascade');
            $table->string('type', 15)->index();
            $table->jsonb('data');
            $table->timestampsTz($this->ts_precision);
            $table->timestampTz('read_at', $this->ts_precision)->nullable();
            $table->string('read_action', 15)->nullable();
        });

        Schema::create('pcg_point_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receiver_id')->index()->constrained('users')->onDelete('restrict')->onUpdate('cascade');
            $table->foreignId('sender_id')->index()->constrained('users')->onDelete('restrict')->onUpdate('cascade');
            $table->integer('amount');
            $table->string('comment', 140)->nullable();
            $table->timestampsTz($this->ts_precision);
        });

        Schema::create('pcg_slot_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->index()->constrained('users')->onDelete('restrict')->onUpdate('cascade');
            $table->string('change_type', 15);
            $table->jsonb('change_data')->nullable();
            $table->integer('change_amount');
            $table->timestampsTz($this->ts_precision);
        });


        DB::statement(sprintf(/** @lang PostgreSQL */ "DROP TYPE IF EXISTS %s", MlpGenerationType::MLP_GENERATION));
        DB::statement(sprintf(/** @lang PostgreSQL */ "CREATE TYPE %s AS ENUM ('%s', '%s')", MlpGenerationType::MLP_GENERATION, MlpGeneration::FriendshipIsMagic(), MlpGeneration::PonyLife()));

        Schema::create('show', function (Blueprint $table) {
            $table->id();
            $table->string('type', 10);
            $table->integer('season')->nullable();
            $table->integer('episode')->nullable();
            $table->integer('parts')->default(1)->nullable();
            $table->text('title');
            $table->timestampsTz($this->ts_precision);
            $table->foreignId('posted_by')->index()->constrained('users')->onDelete('restrict')->onUpdate('cascade');
            $table->timestampTz('airs', $this->ts_precision)->nullable();
            $table->smallInteger('no')->nullable();
            $table->unsignedFloat('score', 2, 1)->default(0);
            $table->text('notes')->nullable();
            $table->timestampTz('synopsis_last_checked', $this->ts_precision)->nullable();
            $table->addColumn(MlpGenerationType::MLP_GENERATION, 'generation')->nullable();

            $table->unique(['season', 'episode']);
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->string('type', 3)->nullable();
            $table->string('preview', 1024)->nullable();
            $table->string('fullsize', 1024)->nullable();
            $table->string('label', 255)->nullable();
            $table->foreignId('requested_by')->nullable()->index()->constrained('users')->onDelete('restrict')->onUpdate('cascade');
            $table->timestampTz('requested_at', $this->ts_precision)->nullable();
            $table->foreignId('reserved_by')->nullable()->index()->constrained('users')->onDelete('restrict')->onUpdate('cascade');
            $table->timestampTz('reserved_at', $this->ts_precision)->nullable();
            $table->string('deviation_id', 7)->nullable();
            $table->boolean('lock')->default(false);
            $table->timestampTz('finished_at', $this->ts_precision)->nullable();
            $table->boolean('broken')->default(false);
            $table->foreignId('show_id')->index()->constrained('show')->onDelete('cascade')->onUpdate('cascade');
            $table->timestampTz('updated_at', $this->ts_precision)->nullable();
        });

        Schema::create('legacy_post_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('posts')->onDelete('cascade')->onUpdate('cascade');
            $table->unsignedBigInteger('old_id');
            $table->string('type', 11);

            $table->unique('post_id');
            $table->index(['old_id', 'type']);
        });

        Schema::create('broken_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->index()->constrained('posts')->onDelete('cascade')->onUpdate('cascade');
            $table->foreignId('reserved_by')->nullable();
            $table->integer('response_code')->nullable();
            $table->string('failing_url', 1024)->nullable();
            $table->timestampsTz($this->ts_precision);
        });

        Schema::create('locked_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->index()->constrained('posts')->onDelete('cascade')->onUpdate('cascade');
            $table->foreignId('user_id')->index()->constrained('users')->onDelete('restrict')->onUpdate('cascade');
            $table->timestampsTz($this->ts_precision);
        });

        Schema::create('related_appearances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained('appearances')->onDelete('cascade')->onUpdate('cascade');
            $table->foreignId('target_id')->constrained('appearances')->onDelete('cascade')->onUpdate('cascade');
            $table->unique(['source_id', 'target_id']);
        });

        Schema::create('show_appearances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('show_id')->constrained('show')->onDelete('cascade')->onUpdate('cascade');
            $table->foreignId('appearance_id')->constrained('appearances')->onDelete('cascade')->onUpdate('cascade');
            $table->unique(['show_id', 'appearance_id']);
        });

        Schema::create('show_videos', function (Blueprint $table) {
            $table->id();
            $table->char('provider_abbr', 2);
            $table->string('provider_id', 64);
            $table->integer('part')->default(1);
            $table->boolean('fullep')->default(true);
            $table->timestampsTz($this->ts_precision);
            $table->timestampTz('not_broken_at', $this->ts_precision)->nullable();
            $table->foreignId('show_id')->constrained('show')->onDelete('cascade')->onUpdate('cascade');
            $table->unique(['provider_abbr', 'part', 'show_id']);
        });

        Schema::create('show_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('restrict')->onUpdate('cascade');
            $table->smallInteger('vote');
            $table->foreignId('show_id')->constrained('show')->onDelete('cascade')->onUpdate('cascade');
            $table->unique(['show_id', 'user_id']);
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name', 30)->index();
            $table->string('title', 255)->nullable();
            $table->string('type', 4)->nullable();
            $table->bigInteger('uses')->default(0);
            $table->foreignId('synonym_of')->index()->nullable()->constrained('tags')->onDelete('set null')->onUpdate('cascade');
            $table->timestampsTz($this->ts_precision);
        });

        Schema::create('tag_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tag_id')->nullable()->index()->constrained('tags')->onDelete('set null')->onUpdate('cascade');
            $table->foreignId('appearance_id')->index()->constrained('appearances')->onDelete('cascade')->onUpdate('cascade');
            $table->foreignId('user_id')->index()->constrained('users')->onDelete('restrict')->onUpdate('cascade');
            $table->boolean('added');
            $table->string('tag_name', 30)->nullable();
            $table->timestampsTz($this->ts_precision);
        });

        Schema::create('tagged', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tag_id')->constrained('tags')->onDelete('cascade')->onUpdate('cascade');
            $table->foreignId('appearance_id')->constrained('appearances')->onDelete('cascade')->onUpdate('cascade');
            $table->unique(['tag_id', 'appearance_id']);
        });

        Schema::create('useful_links', function (Blueprint $table) {
            $table->id();
            $table->string('url', 255);
            $table->string('label', 40);
            $table->string('title', 255);
            $table->string('minrole', 10)->default('user');
            $table->integer('order')->nullable();
        });

        Schema::create('user_prefs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade')->onUpdate('cascade');
            $table->string('key', 50);
            $table->text('value')->nullable();

            $table->unique(['user_id', 'key']);
        });

        Schema::create('previous_usernames', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('user_id')->index()->constrained('deviantart_users')->onDelete('restrict')->onUpdate('cascade');
            $table->addColumn(CitextType::CITEXT, 'username');
        });

        Schema::create('failed_auth_attempts', function (Blueprint $table) {
            $table->id();
            $table->string('user_agent', 255)->nullable();
            $table->ipAddress('ip')->nullable();
            $table->timestampsTz($this->ts_precision);
        });

        Schema::create('logs', function (Blueprint $table) {
            $table->id();
            $table->string('entry_type', 20);
            $table->foreignId('initiator')->nullable()->index()->constrained('users')->onDelete('restrict')->onUpdate('cascade');
            $table->ipAddress('ip');
            $table->jsonb('data')->nullable();
            $table->timestampsTz($this->ts_precision);
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->index()->constrained('users')->onDelete('cascade')->onUpdate('cascade');
            $table->string('platform', 50);
            $table->string('browser_name', 50)->nullable();
            $table->string('browser_ver', 50)->nullable();
            $table->string('user_agent', 300)->nullable();
            $table->string('token', 64)->nullable();
            $table->timestampTz('created', $this->ts_precision)->useCurrent();
            $table->timestampTz('last_visit', $this->ts_precision)->useCurrent();
            $table->boolean('updating')->default(false);
            $table->jsonb('data')->nullable();

            $table->unique('token');
        });

        DB::statement(<<<SQL
          CREATE VIEW unread_notifications AS
          SELECT
            u.name AS "user",
            count(n.id) AS count
          FROM notifications n
          LEFT JOIN users u ON n.recipient_id = u.id
          WHERE n.read_at IS NULL
          GROUP BY u.name
          ORDER BY count(n.id) DESC;
        SQL);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        DB::statement(/** @lang PostgreSQL */ 'DROP VIEW unread_notifications');

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
        Schema::dropIfExists('broken_posts');
        Schema::dropIfExists('locked_posts');
        Schema::dropIfExists('legacy_post_mappings');
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
        Schema::dropIfExists('major_changes');
        Schema::dropIfExists('appearances');
        Schema::dropIfExists('name_changes');
        Schema::dropIfExists('previous_usernames');
        Schema::dropIfExists('deviantart_users');
        Schema::dropIfExists('failed_auth_attempts');
        Schema::dropIfExists('logs');

        DB::statement(sprintf(/** @lang PostgreSQL */ "DROP TYPE IF EXISTS %s", MlpGenerationType::MLP_GENERATION));
    }
}
