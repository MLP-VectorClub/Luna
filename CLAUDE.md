# Luna

Laravel API for the MLP Vector Club. Deployed by pushing `main` to the `deploy` remote (git-deploy-toolkit, see `deploy.conf`); `origin` is GitHub.

## Status (as of 2026-09-30)

Upgraded from Laravel 9.0.0-beta.1 to Laravel 12 on PHP 8.5 (`composer.json` requires `^8.2`). Production already ran PHP 8.5.

### Done
- Dependencies bumped, unused/abandoned packages removed (websockets, restcord, activitylog, ignition, php-cs-fixer, doctrine/dbal, nojacko email-validator, ongr dsl)
- DBAL is gone: `citext` / `mlp_generation` column types are registered as grammar macros in `AppServiceProvider`
- Elasticsearch moved to the ES 8 client (`mailerlite/laravel-elasticsearch`), queries in `ColorGuideHelper` are plain arrays. Prod runs ES 8.19, index `appearances` is created by Winterchilla
- OpenAPI JSON is pinned to `/generated/api-docs.json` (l5-swagger 9 serves the docs at the route itself, no trailing filename)
- Old migrations run on a fresh database again (`unsignedFloat` removed, activity_log no longer reads the removed package's config)
- Test suite: PHPUnit 11, 30 tests (email rules, about endpoints, docs URL, signin/token/signout)

### Left
- **Deploy `fce3f9f` (`expires_at` on `personal_access_tokens`).** Sanctum 4 writes that column on every token insert, without it every successful password/OAuth signin returns a 500. Production is affected until this is deployed (the deploy runs `migrate --force`). After deploying, confirm a real signin gets a token
- More tests: signup validation (422 cases), `AccountHelper::create` (first user becomes developer, later ones get 503), appearance detail and private appearances, search/autocomplete with the `Elasticsearch` facade mocked, user prefs, social signin
- `StrictEmail` is now `email:rfc,dns`, the old package also blocked disposable domains and that is gone. The rule's DNS part is untested (needs network)
- Untested against real data: sprite/cutie mark uploads (medialibrary 11), OAuth callbacks

## Local setup

- URL: `https://api.mlpvector.lc` (nginx vhost is `/etc/nginx/conf.d/luna-lc.conf`, not in the repo; reuses the `mlpvector.lc` cert, which already lists `api.mlpvector.lc`). `CDN_URL=https://api.mlpvector.lc/cdn` serves `storage/app/public`
- php-fpm: shared pool socket `/run/php-fpm/php-fpm.sock`, no dedicated pool. `ext-exif` is enabled in `/etc/php/php.ini` (medialibrary needs it)
- Elasticsearch: local service with `xpack.security.enabled: false`, single node, to match prod
- Tests use the `luna_test` database (`DB_DATABASE` is forced in `phpunit.xml`); `tests/TestCase.php` refuses to run against any database not ending in `_test`. Build it with `createdb luna_test`, `psql -f setup/create_extensions.pg.sql`, then `DB_DATABASE=luna_test php artisan migrate`

## Gotchas

- `GET /about/sleep` sleeps for an hour outside production, never hit it
- `AboutController::serverInfo` reads `$_SERVER` directly, tests have to set the superglobals themselves
- `valorin/pwned-validator` calls its API through curl and caches per hash prefix, seed `Cache` (`pwned:<sha1 prefix>`) in tests (`tests/Concerns/FakesPwnedPasswords.php`)
- Opcache revalidates every 180s locally, after deleting `bootstrap/cache/packages.php` or changing providers, php-fpm can keep serving the old one for a few minutes
- `vendor/` must be owned by the deploy user, not root (a stray `sudo composer` breaks installs)
- Regenerate docs with `php artisan l5-swagger:generate`, Celestia builds its API types from `/generated/api-docs.json`
