#!/usr/bin/env bash
# Replaces the contents of a Luna database with a fresh copy of Winterchilla's data (the data-only way, so Luna's own migrations define the schema):
#   1. creates the target database empty and runs `php artisan migrate:fresh`
#   2. loads a data-only dump of the Winterchilla database (without its phinxlog) with the foreign keys not checked while loading
#   3. moves every id sequence past the highest id
#   4. optionally copies the cutie mark and sprite files (`php artisan fs:migrate`) so the media table matches
#
#   scripts/import-winterchilla-data.sh <winterchilla_db> <target_db> [winterchilla_fs_folder uploader_user_id]
#
# The target database is dropped first if it exists, so build a NEW database and swap it in (see docs/winterchilla-data-import.md), never point this at
# the database Luna is serving. Creating databases, the extensions and the foreign key bypass (`session_replication_role`) need a PostgreSQL superuser:
# either run it with PGUSER / PGHOST / PGPASSWORD of one (default: Luna's .env), or let those steps run through the postgres OS user, which is how
# the server has it: PG_ADMIN="sudo -n -u postgres" TARGET_OWNER=luna (the database role Luna connects with, it must own the new database).
set -euo pipefail
cd "$(dirname "$0")/.."
SOURCE="${1:?Winterchilla database name}"; TARGET="${2:?target database name}"
FS_FOLDER="${3:-}"; UPLOADER="${4:-1}"

if [ -f .env ]; then set -a; source .env; set +a; fi
export PGHOST="${PGHOST:-${DB_HOST:-127.0.0.1}}" PGUSER="${PGUSER:-${DB_USERNAME:-postgres}}" PGPASSWORD="${PGPASSWORD:-${DB_PASSWORD:-}}"
PSQL=(psql -v ON_ERROR_STOP=1 -q -At)
# The steps that need superuser rights
read -r -a ADMIN <<< "${PG_ADMIN:-}"
APSQL=("${ADMIN[@]}" "${PSQL[@]}")
APGDUMP=("${ADMIN[@]}" pg_dump)
DUMP="$(mktemp --suffix=.sql)"; trap 'rm -f "$DUMP"' EXIT

echo "== Creating $TARGET"
"${APSQL[@]}" -d postgres -c "DROP DATABASE IF EXISTS \"$TARGET\"" -c "CREATE DATABASE \"$TARGET\"${TARGET_OWNER:+ OWNER \"$TARGET_OWNER\"}"
[ -f setup/create_extensions.pg.sql ] && "${APSQL[@]}" -d "$TARGET" -f "$PWD/setup/create_extensions.pg.sql" >/dev/null 2>&1 || true

echo "== Building Luna's schema (migrate:fresh)"
DB_DATABASE="$TARGET" php artisan migrate:fresh --force | tail -2

echo "== Loading the data of $SOURCE"
"${APGDUMP[@]}" -d "$SOURCE" --data-only --no-owner --exclude-table=phinxlog > "$DUMP" 2> >(grep -v -E 'circular foreign-key|detail: tags|hint:' >&2 || true)
( echo "SET session_replication_role = replica;"; cat "$DUMP" ) | "${APSQL[@]}" -d "$TARGET" >/dev/null

echo "== Moving the id sequences past the highest ids"
"${APSQL[@]}" -d "$TARGET" -c "
DO \$\$ DECLARE r record; BEGIN
  FOR r IN SELECT c.table_name, c.column_name FROM information_schema.columns c
           WHERE c.table_schema = 'public' AND pg_get_serial_sequence(quote_ident(c.table_name), c.column_name) IS NOT NULL LOOP
    EXECUTE format('SELECT setval(pg_get_serial_sequence(%L, %L), COALESCE((SELECT max(%I) FROM %I), 0) + 1, false)', r.table_name, r.column_name, r.column_name, r.table_name);
  END LOOP;
END \$\$;" >/dev/null

if [ -n "$FS_FOLDER" ]; then
  echo "== Copying the cutie mark and sprite files from $FS_FOLDER (uploader #$UPLOADER)"
  DB_DATABASE="$TARGET" php artisan fs:migrate "$FS_FOLDER" "$UPLOADER" --wipe
fi

echo "== Row counts (source / target)"
for t in users deviantart_users discord_members show posts appearances tags logs events cutiemarks colors; do
  a=$("${APSQL[@]}" -d "$SOURCE" -c "SELECT count(*) FROM $t"); b=$("${APSQL[@]}" -d "$TARGET" -c "SELECT count(*) FROM $t")
  printf '%-18s %8s %8s %s\n' "$t" "$a" "$b" "$([ "$a" = "$b" ] && echo ok || echo DIFFERENT)"
done
echo "Done. $TARGET holds a fresh copy of $SOURCE."
