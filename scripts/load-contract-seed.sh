#!/usr/bin/env bash
# Builds the `luna_contract` database from Winterchilla's seeded contract-test data, so its tests/Browser/Api suite can run against Luna.
#
#   scripts/load-contract-seed.sh [/path/to/contract-seed.sql]   (default: tests/fixtures/contract-seed.sql, a copy of what Winterchilla's scripts/dump-contract-seed.sh produced, so
#   Luna does not need Winterchilla to build the contract test database)
#
# Run Luna against it with: APP_ENV=testing DB_DATABASE=luna_contract php artisan serve --port=8766
# and Winterchilla's suite with: CONTRACT_BASE_URL=http://127.0.0.1:8766 CONTRACT_API_PATH= CONTRACT_AUTH=bearer CONTRACT_LOGIN_URL=/test/login/{id}
set -euo pipefail
cd "$(dirname "$0")/.."
SEED="${1:-tests/fixtures/contract-seed.sql}"
set -a; source .env; set +a
export PGPASSWORD="${DB_PASSWORD}"
PSQL=(psql -h "${DB_HOST:-127.0.0.1}" -U "${DB_USERNAME}" -v ON_ERROR_STOP=1 -q)

"${PSQL[@]}" -d postgres -c 'DROP DATABASE IF EXISTS luna_contract' -c 'CREATE DATABASE luna_contract'
"${PSQL[@]}" -d luna_contract -c 'CREATE EXTENSION IF NOT EXISTS citext'
DB_DATABASE=luna_contract php artisan migrate --force
# Winterchilla's test seed has DeviantArt users without an avatar, production never does
"${PSQL[@]}" -d luna_contract -c 'ALTER TABLE deviantart_users ALTER COLUMN avatar_url DROP NOT NULL'
"${PSQL[@]}" -d luna_contract -f "$SEED"

# Winterchilla's seeder puts the cutie mark SVG of the seeded cutie marks (IDs 900000 and up) on disk instead of in the dump,
# attach the same fixture to them here
DB_DATABASE=luna_contract php artisan tinker --execute='
$svg = App\Utils\SvgHelper::sanitize(file_get_contents(base_path("tests/fixtures/cutiemark.svg")));
foreach (App\Models\CutieMark::where("id", ">=", 900000)->get() as $cm) {
    if ($cm->vectorFile() === null) {
        $cm->addMediaFromString($svg)->usingFileName(sha1($svg.$cm->id).".svg")->toMediaCollection(App\Models\CutieMark::CUTIEMARKS_COLLECTION);
    }
}
echo "Attached the fixture to ".App\Models\CutieMark::where("id", ">=", 900000)->count()." seeded cutie marks\n";
'

# Winterchilla keeps its sessions in `sessions`; Luna's are in `luna_sessions`. The seeded session of the regular user (another browser of
# theirs) becomes one there too, so the account page has a session of someone else's to list
"${PSQL[@]}" -d luna_contract -c "INSERT INTO luna_sessions (id, user_id, ip_address, user_agent, payload, last_activity, created_at)
  SELECT 'seeded-session-' || id, user_id, '127.0.0.1', user_agent, '', EXTRACT(EPOCH FROM last_visit)::int, created FROM sessions"

# The seed was dumped some time ago, the Discord member that "was synced just now" has to be in its cooldown again
"${PSQL[@]}" -d luna_contract -c "UPDATE discord_members SET last_synced = now() WHERE last_synced IS NOT NULL"
