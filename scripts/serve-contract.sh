#!/usr/bin/env bash
# (Re)starts Luna on :8766 against the luna_contract database in the testing environment. The built-in server keeps opcache
# between requests, so restart it after code changes.
cd "$(dirname "$0")/.."
fuser -k 8766/tcp >/dev/null 2>&1 || true
sleep 1
MAIL_MAILER=log DISCORD_SKIP_REVOKE=true RESPONSE_CACHE_ENABLED=false APP_ENV=testing DB_DATABASE=luna_contract setsid php artisan serve --port=8766 > "${TMPDIR:-/tmp}/luna-contract-serve.log" 2>&1 &
sleep 2
