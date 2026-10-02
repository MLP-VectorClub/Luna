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
- Test suite: PHPUnit 11, now 135 tests (see the Winterchilla contract section)
- Deployed to production (`ffd40e0`), including the `expires_at` fix that unblocks successful signins (password login confirmed working on production)

### Left
- More tests: signup validation (422 cases), `AccountHelper::create` (first user becomes developer, later ones get 503), appearance detail and private appearances, search/autocomplete with the `Elasticsearch` facade mocked, user prefs, social signin
- `StrictEmail` is now `email:rfc,dns`, the old package also blocked disposable domains and that is gone. The rule's DNS part is untested (needs network)
- Untested against real data: sprite/cutie mark uploads (medialibrary 11), OAuth callbacks

## Winterchilla contract (as of 2026-10-01)

Luna is being built to implement Winterchilla's `/api/v0` contract (its `public/dist/api.json`) so Celestia can replace Winterchilla's Twig front end. The plan, decisions, findings and
progress log are in `docs/winterchilla-contract-plan.md`. Nothing from this work is deployed, and no migration has run on production.

### Built (121 of 127 contract operations)
- Schema alignment migration `2026_10_01_000000_align_schema_with_winterchilla` (drops `show_videos` and `show.generation`, restores `UNIQUE(season, episode)`, discriminator smallint, FK and timestamp fixes, dead rows)
- Foundation: `GET /config`, `role:` and `optional.auth` middleware, `{message}` error bodies (`ConflictException` adds extra fields to a 409), `POST /test/login/{id}` (APP_ENV=testing only), `/users/me` as `{user, sessionUpdating}`
- Settings, notices, useful links, user preferences (+ `PUT /users/{id}/role`), tags, color groups, appearances (reads and all management except cutie marks), sprites, shows (incl. votes, next, latest, prefill), admin logs, notification read, color guide export and reindex, personal guide (slots, points, history), post lists, post write flows (create, edit, reserve, finish, approve, delete, change image, unbreak, direct reservations), user profile and contributions, event reads, the disabled event writes (501 like Winterchilla), cutie marks (`App\Http\Controllers\CutieMarksController`, stored as medialibrary files holding the sanitized SVG) and SVG sanitizing (`App\Utils\SvgHelper`, enshrined/svg-sanitize, minified by the sanitizer, no svgo), Discord sync and unlink (`DiscordController`, Laravel `Http` against discord.com, needs `DISCORD_CLIENT_ID/SECRET/BOT_TOKEN/SERVER_ID`)
- Image links go through `App\Utils\ImageProvider` and `DeviantArt` (oEmbed, Derpibooru, Imgur, Lightshot, club gallery check), always via Laravel's `Http` client so tests fake them
- `App\Utils\AppearanceIndex` keeps the shared ElasticSearch `appearances` index in sync; `LogWriter` writes the shared `logs` table
- 135 Luna tests (`php artisan test`)

### Left (6 operations)
- Cutie mark gaps: no svgo, colors are not tokenized (the file is stored sanitized, `sanitize-svg` only warns about colors missing from the `Cutie Mark` color group), attribution by username/deviation needs the DeviantArt user to exist already (no DeviantArt API lookup to create unknown users), the `preview` HTML of Winterchilla is not returned
- Event entries: `GET|PUT|DELETE /event-entries/{entryid}` are implemented (`EventEntriesController`, PUT answers `{}` without Winterchilla's `entryHtml` UI field); `GET|PUT|DELETE /events/{id}/entries` are placeholders that answer 404 for signed in users like Winterchilla (its route never gets an entry id)
- Not done and not in the contract: notes cross references (`#id`, episode ids) stay plain text, `GET /appearances/{id}/preview` (internal), the real ElasticSearch reindex is untested
- Before Winterchilla can use Luna's database: the migration rehearsal passed (`scripts/rehearse-cutover.sh`, see the plan; point `LARAVEL_STORAGE_PATH` at a scratch folder when rehearsing `fs:migrate`). `fs:migrate` was rehearsed with the production `fs/` (146 cutie marks and 110 sprites imported). `fs:migrate` skips files without a database record. Still open: the user decides how to load/adopt the production data

### Winterchilla page coverage
`tests/Feature/WinterchillaPagesTest.php` accounts for every GET page route in Winterchilla's `config/routes/pages.php` (88 patterns, read from `../Winterchilla` when it sits next to Luna): 42 are fed by Luna endpoints that are smoke tested, 13 are legacy redirects, 15 are Celestia-only (client side tools such as the picker and blending calculator, not built in Celestia yet), 5 are OAuth (not smoke tested, needs provider tokens), 7 are test-only, and 6 have no Luna endpoint. One is an unfinished placeholder that stays a placeholder (tag change history: "TODO Finish feature" in Winterchilla, its page answers 404; a TODO is not a drop, the `tag_changes` data is kept and the route stays reserved). The others are open gaps awaiting a decision, since the goal is a full reimplementation: appearance exports (swatch downloads for Illustrator/Inkscape, palette PNG, preview and facing SVG), cutie mark download, staff list of personal guide appearances (`/admin/pcg-appearances`, linked from the admin dashboard), profile by DeviantArt UUID (developer-only). Celestia's CLAUDE.md lists most of them as "dropped on purpose", that was not the user's decision. They show up as a skipped test with the list. A new Winterchilla page fails the inventory test until it is classified.

### Running the contract suite against Luna
1. `scripts/load-contract-seed.sh <contract-seed.sql>` builds the `luna_contract` DB (the seed comes from Winterchilla's `scripts/dump-contract-seed.sh`, do not run that against the shared test DB)
2. `scripts/serve-contract.sh` serves Luna on :8766 (APP_ENV=testing, response cache off, restart after code changes)
3. In Winterchilla: `env CONTRACT_BASE_URL=http://127.0.0.1:8766 CONTRACT_API_PATH= CONTRACT_AUTH=bearer 'CONTRACT_LOGIN_URL=/test/login/{id}' vendor/bin/pest tests/Browser/Api/<File>.php`
- Remaining failures are tests that assert Winterchilla UI fields (`li`, `url`, `goto`, `cgs`, `newhtml`...), tests of `x-internal` endpoints, and Winterchilla-only file checks
- `php artisan l5-swagger:generate && php scripts/diff-contract.php [--by-tag]` lists the contract operations Luna still lacks

## Local setup

- URL: `https://api.mlpvector.lc` (nginx vhost is `/etc/nginx/conf.d/luna-lc.conf`, not in the repo; reuses the `mlpvector.lc` cert, which already lists `api.mlpvector.lc`). `CDN_URL=https://api.mlpvector.lc/cdn` serves `storage/app/public`
- php-fpm: shared pool socket `/run/php-fpm/php-fpm.sock`, no dedicated pool. `ext-exif` is enabled in `/etc/php/php.ini` (medialibrary needs it)
- Elasticsearch: local service with `xpack.security.enabled: false`, single node, to match prod
- Tests use the `luna_test` database (`DB_DATABASE` is forced in `phpunit.xml`); `tests/TestCase.php` refuses to run against any database not ending in `_test`. Build it with `createdb luna_test`, `psql -f setup/create_extensions.pg.sql`, then `DB_DATABASE=luna_test php artisan migrate`

## Gotchas

- Test models: `User::factory()->create()` leaves `role` null until refreshed, pass `['role' => Role::User]`
- `php artisan serve` keeps opcache between requests, use `scripts/serve-contract.sh` to restart after edits
- `Http::fake()` calls stack and the first match wins: reset the factory between fakes (see `tests/Feature/PostManagementTest.php`), and let `cloudflare.com` requests through

- `GET /about/sleep` sleeps for an hour outside production, never hit it
- `AboutController::serverInfo` reads `$_SERVER` directly, tests have to set the superglobals themselves
- `valorin/pwned-validator` calls its API through curl and caches per hash prefix, seed `Cache` (`pwned:<sha1 prefix>`) in tests (`tests/Concerns/FakesPwnedPasswords.php`)
- Opcache revalidates every 180s locally, after deleting `bootstrap/cache/packages.php` or changing providers, php-fpm can keep serving the old one for a few minutes
- `vendor/` must be owned by the deploy user, not root (a stray `sudo composer` breaks installs)
- Regenerate docs with `php artisan l5-swagger:generate`, Celestia builds its API types from `/generated/api-docs.json`
