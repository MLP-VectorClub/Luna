#!/usr/bin/env bash
# Rehearses adopting a Winterchilla-schema database (a copy of production, default template `prod_copy`) for Luna:
# Luna migrations for tables Winterchilla already owns are marked as run, the rest (tokens, media, activity log, schema alignment) are executed.
#
#   scripts/rehearse-cutover.sh [template_db] [target_db]
set -euo pipefail
cd "$(dirname "$0")/.."
TEMPLATE="${1:-prod_copy}"; TARGET="${2:-luna_rehearsal}"
set -a; source .env; set +a
export PGPASSWORD="${DB_PASSWORD}"
PSQL=(psql -h "${DB_HOST:-127.0.0.1}" -U "${DB_USERNAME}" -v ON_ERROR_STOP=1 -q)
"${PSQL[@]}" -d postgres -c "DROP DATABASE IF EXISTS $TARGET" -c "CREATE DATABASE $TARGET TEMPLATE $TEMPLATE"
export DB_DATABASE="$TARGET"
php artisan migrate:install >/dev/null
SKIP=(2014_10_00_000000_create_settings_table 2014_10_00_000001_add_group_column_on_settings_table 2014_10_12_000000_create_users_table
  2020_04_29_190000_import_old_schema 2020_12_20_161319_create_pinned_appearances_table 2021_02_13_132106_change_show_table_unique
  2021_03_23_213804_create_blocked_emails_table 2021_03_23_214040_create_email_verifications_table 2021_06_20_101227_cleanup_event_tables
  2025_12_07_074357_add_discord_members_display_name_column 2026_07_26_000000_widen_discord_members_token_columns)
for m in "${SKIP[@]}"; do "${PSQL[@]}" -d "$TARGET" -c "INSERT INTO migrations (migration, batch) VALUES ('$m', 1)"; done
php artisan migrate --force
