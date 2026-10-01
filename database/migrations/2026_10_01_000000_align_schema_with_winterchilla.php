<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Brings the schema in line with what Winterchilla's production database looks like, so both applications can run
 * on the same data. Every statement is idempotent: it works on a database built by Luna's own migrations as well as
 * on one imported from Winterchilla.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Dead table (Winterchilla dropped it in 2022)
        DB::statement(/** @lang PostgreSQL */ 'DROP TABLE IF EXISTS show_videos');

        // Winterchilla dropped show.generation in 2024-11. Dropping the column also drops the (season, episode, generation)
        // unique index, which Winterchilla never recreated, so (season, episode) is made unique again here.
        DB::statement(/** @lang PostgreSQL */ 'ALTER TABLE show DROP COLUMN IF EXISTS generation');
        DB::statement(/** @lang PostgreSQL */ 'DROP TYPE IF EXISTS mlp_generation');
        DB::statement(/** @lang PostgreSQL */ 'DROP INDEX IF EXISTS show_season_episode_generation_unique');
        DB::statement(/** @lang PostgreSQL */ <<<'SQL'
            DO $$
            BEGIN
              IF NOT EXISTS (
                SELECT 1 FROM pg_index i
                  JOIN pg_class c ON c.oid = i.indrelid
                  WHERE c.relname = 'show' AND i.indisunique AND NOT i.indisprimary
                    AND (SELECT array_agg(a.attname::text ORDER BY a.attname::text)
                           FROM pg_attribute a WHERE a.attrelid = c.oid AND a.attnum = ANY (i.indkey)) = ARRAY['episode', 'season']
              ) THEN
                CREATE UNIQUE INDEX show_season_episode_unique ON show (season, episode);
              END IF;
            END
            $$
            SQL);

        // Winterchilla stores the Discord discriminator as a number
        DB::statement(/** @lang PostgreSQL */ <<<'SQL'
            DO $$
            BEGIN
              IF (SELECT data_type FROM information_schema.columns
                    WHERE table_name = 'discord_members' AND column_name = 'discriminator') = 'character' THEN
                ALTER TABLE discord_members ALTER COLUMN discriminator TYPE smallint USING trim(discriminator)::smallint;
              END IF;
            END
            $$
            SQL);

        // Deleting a DeviantArt user must not silently delete the cutie marks they contributed
        DB::statement(/** @lang PostgreSQL */ <<<'SQL'
            DO $$
            DECLARE fk text;
            BEGIN
              FOR fk IN SELECT con.conname FROM pg_constraint con
                  JOIN pg_class c ON c.oid = con.conrelid
                  WHERE c.relname = 'cutiemarks' AND con.contype = 'f'
                    AND con.confrelid = 'deviantart_users'::regclass
                    AND con.conkey = ARRAY[(SELECT attnum FROM pg_attribute WHERE attrelid = c.oid AND attname = 'contributor_id')]
              LOOP
                EXECUTE format('ALTER TABLE cutiemarks DROP CONSTRAINT %I', fk);
              END LOOP;
              ALTER TABLE cutiemarks ADD CONSTRAINT cutiemarks_contributor_id_foreign
                FOREIGN KEY (contributor_id) REFERENCES deviantart_users (id) ON UPDATE CASCADE ON DELETE RESTRICT;
            END
            $$
            SQL);

        DB::statement(/** @lang PostgreSQL */ "ALTER TABLE pinned_appearances ALTER COLUMN created_at TYPE timestamptz USING created_at AT TIME ZONE 'UTC'");
        DB::statement(/** @lang PostgreSQL */ "ALTER TABLE pinned_appearances ALTER COLUMN updated_at TYPE timestamptz USING updated_at AT TIME ZONE 'UTC'");

        DB::statement(/** @lang PostgreSQL */ 'ALTER TABLE email_verifications ADD COLUMN IF NOT EXISTS created_at timestamptz NULL');
        DB::statement(/** @lang PostgreSQL */ 'ALTER TABLE email_verifications ADD COLUMN IF NOT EXISTS updated_at timestamptz NULL');

        // Dead preferences and notifications removed from Winterchilla's data
        DB::statement(/** @lang PostgreSQL */ "DELETE FROM user_prefs WHERE key IN ('discord_token', 'ep_hidesynopses')");
        DB::statement(/** @lang PostgreSQL */ "DELETE FROM notifications WHERE type = 'sprite-colors'");
    }

    public function down(): void
    {
        // Intentionally one-way: the dropped table, column and rows are dead in Winterchilla as well
    }
};
