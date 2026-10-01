#!/usr/bin/env bash
# Builds the `luna_contract` database from Winterchilla's seeded contract-test data, so its tests/Browser/Api suite can run against Luna.
#
#   scripts/load-contract-seed.sh /path/to/contract-seed.sql   (produced by Winterchilla's scripts/dump-contract-seed.sh)
#
# Run Luna against it with: APP_ENV=testing DB_DATABASE=luna_contract php artisan serve --port=8766
# and Winterchilla's suite with: CONTRACT_BASE_URL=http://127.0.0.1:8766 CONTRACT_API_PATH= CONTRACT_AUTH=bearer CONTRACT_LOGIN_URL=/test/login/{id}
set -euo pipefail
cd "$(dirname "$0")/.."
SEED="${1:?path to contract-seed.sql}"
set -a; source .env; set +a
export PGPASSWORD="${DB_PASSWORD}"
PSQL=(psql -h "${DB_HOST:-127.0.0.1}" -U "${DB_USERNAME}" -v ON_ERROR_STOP=1 -q)

"${PSQL[@]}" -d postgres -c 'DROP DATABASE IF EXISTS luna_contract' -c 'CREATE DATABASE luna_contract'
"${PSQL[@]}" -d luna_contract -c 'CREATE EXTENSION IF NOT EXISTS citext'
DB_DATABASE=luna_contract php artisan migrate --force
# Winterchilla's test seed has DeviantArt users without an avatar, production never does
"${PSQL[@]}" -d luna_contract -c 'ALTER TABLE deviantart_users ALTER COLUMN avatar_url DROP NOT NULL'
"${PSQL[@]}" -d luna_contract -f "$SEED"
