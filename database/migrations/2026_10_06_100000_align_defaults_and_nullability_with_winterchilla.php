<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Winterchilla (ActiveRecord) leaves created_at / updated_at and the appearance token to the database defaults, and a few of its columns accept NULL
 * where Luna's tables did not. While both applications write to the same database Luna's tables need the same defaults and nullability, otherwise
 * Winterchilla's inserts fail (`appearances.token`) or store empty timestamps. Safe to run on a Luna built database and on an imported Winterchilla one.
 */
return new class extends Migration
{
    /** Columns whose default in Winterchilla's schema is CURRENT_TIMESTAMP */
    private const TIMESTAMP_DEFAULTS = [
        'appearances' => ['created_at'],
        'blocked_emails' => ['created_at'],
        'broken_posts' => ['created_at', 'updated_at'],
        'deviantart_users' => ['created_at'],
        'email_verifications' => ['created_at'],
        'event_entries' => ['created_at'],
        'events' => ['created_at'],
        'failed_auth_attempts' => ['created_at', 'updated_at'],
        'locked_posts' => ['created_at', 'updated_at'],
        'logs' => ['created_at'],
        'major_changes' => ['created_at', 'updated_at'],
        'notices' => ['created_at'],
        'notifications' => ['created_at'],
        'pcg_point_grants' => ['created_at'],
        'pcg_slot_history' => ['created_at'],
        'pinned_appearances' => ['created_at'],
        'sessions' => ['created', 'last_visit'],
        'settings' => ['created_at'],
        'show' => ['created_at'],
        'tag_changes' => ['created_at'],
        'tags' => ['created_at'],
        'users' => ['created_at'],
    ];

    /** Columns that are nullable in Winterchilla's schema */
    private const NULLABLE = [
        'deviantart_users' => ['user_id'],
        'locked_posts' => ['post_id', 'user_id'],
    ];

    public function up(): void
    {
        foreach (self::TIMESTAMP_DEFAULTS as $table => $columns) {
            foreach ($columns as $column) {
                if (Schema::hasColumn($table, $column)) {
                    DB::statement("ALTER TABLE \"$table\" ALTER COLUMN \"$column\" SET DEFAULT CURRENT_TIMESTAMP");
                }
            }
        }
        if (Schema::hasColumn('appearances', 'token')) {
            DB::statement('ALTER TABLE appearances ALTER COLUMN token SET DEFAULT gen_random_uuid()');
        }
        foreach (self::NULLABLE as $table => $columns) {
            foreach ($columns as $column) {
                if (Schema::hasColumn($table, $column)) {
                    DB::statement("ALTER TABLE \"$table\" ALTER COLUMN \"$column\" DROP NOT NULL");
                }
            }
        }
    }

    public function down(): void
    {
        // The defaults and nullability are what Winterchilla's schema has, there is nothing to put back
    }
};
