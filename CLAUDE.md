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
- Test suite: PHPUnit 11, now 168 tests (see the Winterchilla contract section)
- Deployed to production (`ffd40e0`), including the `expires_at` fix that unblocks successful signins (password login confirmed working on production)

### Left
- More tests: signup validation (422 cases), `AccountHelper::create` (first user becomes developer, later ones get 503), appearance detail and private appearances, search/autocomplete with the `Elasticsearch` facade mocked, user prefs, social signin
- `StrictEmail` is now `email:rfc,dns`, the old package also blocked disposable domains and that is gone. The rule's DNS part is untested (needs network)
- Untested against real data: sprite/cutie mark uploads (medialibrary 11), OAuth callbacks

## Winterchilla contract (as of 2026-10-01)

Luna is being built to implement Winterchilla's `/api/v0` contract (its `public/dist/api.json`) so Celestia can replace Winterchilla's Twig front end. The plan, decisions, findings and
progress log are in `docs/winterchilla-contract-plan.md`. Nothing from this work is deployed, and no migration has run on production.

### Built (all 130 contract operations that `scripts/diff-contract.php` counts)
- Schema alignment migration `2026_10_01_000000_align_schema_with_winterchilla` (drops `show_videos` and `show.generation`, restores `UNIQUE(season, episode)`, discriminator smallint, FK and timestamp fixes, dead rows)
- Foundation: `GET /config`, `role:` and `optional.auth` middleware, `{message}` error bodies (`ConflictException` adds extra fields to a 409), `POST /test/login/{id}` (APP_ENV=testing only), `/users/me` as `{user, sessionUpdating}`
- Settings, notices, useful links, user preferences (+ `PUT /users/{id}/role`), tags, color groups, appearances (reads and all management except cutie marks), sprites, shows (incl. votes, next, latest, prefill), admin logs, notification read, color guide export and reindex, personal guide (slots, points, history), post lists, post write flows (create, edit, reserve, finish, approve, delete, change image, unbreak, direct reservations), user profile and contributions, event reads, the disabled event writes (501 like Winterchilla), cutie marks (`App\Http\Controllers\CutieMarksController`, stored as medialibrary files holding the sanitized SVG) and SVG sanitizing (`App\Utils\SvgHelper`, enshrined/svg-sanitize, minified by the sanitizer, no svgo), Discord sync and unlink (`DiscordController`, Laravel `Http` against discord.com, needs `DISCORD_CLIENT_ID/SECRET/BOT_TOKEN/SERVER_ID`)
- Image links go through `App\Utils\ImageProvider` and `DeviantArt` (oEmbed, Derpibooru, Imgur, Lightshot, club gallery check), always via Laravel's `Http` client so tests fake them
- `App\Utils\AppearanceIndex` keeps the shared ElasticSearch `appearances` index in sync; `LogWriter` writes the shared `logs` table
- 167 Luna tests (`php artisan test`)

### Left
- Cutie mark gaps: no svgo, colors are not tokenized (the file is stored sanitized, `sanitize-svg` only warns about colors missing from the `Cutie Mark` color group), attribution by username/deviation needs the DeviantArt user to exist already (no DeviantArt API lookup to create unknown users), the `preview` HTML of Winterchilla is not returned
- Event entries: `GET|PUT|DELETE /event-entries/{entryid}` are implemented (`EventEntriesController`, PUT answers `{}` without Winterchilla's `entryHtml` UI field); `GET|PUT|DELETE /events/{id}/entries` are placeholders that answer 404 for signed in users like Winterchilla (its route never gets an entry id)
- Not done and not in the contract: notes cross references (`#id`, episode ids) stay plain text, `GET /appearances/{id}/preview` (internal), the real ElasticSearch reindex is untested
- Before Winterchilla can use Luna's database: the migration rehearsal passed (`scripts/rehearse-cutover.sh`, see the plan; point `LARAVEL_STORAGE_PATH` at a scratch folder when rehearsing `fs:migrate`). `fs:migrate` was rehearsed with the production `fs/` (146 cutie marks and 110 sprites imported). `fs:migrate` skips files without a database record. Still open: the user decides how to load/adopt the production data

### Self-contained without Winterchilla
Luna keeps copies of what it needs from Winterchilla so the repo works if Winterchilla is deleted: the contract (`docs/contract/api.json`, used by `scripts/diff-contract.php` when `../Winterchilla` is missing), the contract seed and cutie mark fixture (`tests/fixtures`), the page route list (`tests/fixtures/winterchilla/pages.php`, used by the page inventory test when `../Winterchilla` is missing), the image goldens and the fonts/graphics. Only the `scripts/generate-winterchilla-*-goldens.php` regenerators and Winterchilla's own Pest contract tests need the Winterchilla repo. Refresh the copies when the contract changes.

### Winterchilla page coverage
`tests/Feature/WinterchillaPagesTest.php` accounts for every GET page route in Winterchilla's `config/routes/pages.php` (88 patterns, read from `../Winterchilla` when it sits next to Luna): Luna endpoints that are smoke tested, legacy redirects, Celestia-only pages (client side tools such as the picker and blending calculator, not built in Celestia yet), OAuth (not smoke tested, needs provider tokens) and test-only routes. Pages that used to be gaps (appearance exports, cutie mark download, staff list of personal guide appearances, profile by DeviantArt UUID, tag change history) now have endpoints in the contract and in Luna, so no page is left without one. A new Winterchilla page fails the inventory test until it is classified.

### Generated images match Winterchilla
`App\Utils\AppearanceImages` (preview SVG, facing SVG, sprite tracing + sprite SVG, swatch JSON and GPL) and `App\Utils\PaletteImage` (palette PNG, GD + the two fonts in `resources/fonts`) are ports of Winterchilla's code, and `AppearanceImagesTest` / `PaletteImageTest` compare their output byte for byte with files that Winterchilla's own code produced (`tests/fixtures/winterchilla`, regenerated with `scripts/generate-winterchilla-*-goldens.php`, which need `../Winterchilla` with composer install and, for the palette and swatches, its `prod_copy` DB). Known Winterchilla quirks that are reproduced on purpose: the sprite tracing continues a line into the next color when that color's first pixel directly follows the previous color's last pixel (that pixel's color is lost). Winterchilla's palette image used to miss the sprite because it looked for it at `<id>.png<id>.png`; that is fixed there (368625c4) and Luna draws the sprite next to the colors, the goldens are rendered by the fixed code.

### Scheduled commands (ports of Winterchilla's cron scripts)
`gdpr:anonymize-logged-ips` (daily: `logs.ip` older than 3 months becomes `127.168.80.82`, failed auth attempts older than 3 months are deleted) and `gdpr:prune-email-verifications` (hourly: entries older than 24 hours), both registered in `App\Console\Kernel`. They only run if the server runs `php artisan schedule:run` every minute (cron), check that before Winterchilla's `cron.daily` stops. Not ported on purpose: `access_token_refresher` (Winterchilla's DeviantArt session tokens, Luna uses Sanctum), `clear_redis_keys` (deploy helper), `generate_api_schema` (l5-swagger does it), `export_color_guide` (Luna serves `GET /color-guide/export` on demand, the static `mlpvc-colorguide.json` URL of Winterchilla goes away), `convert_nutshell_names` (data for the 2020 `cg_nutshell` joke preference, see the plan).

### Running the contract suite against Luna
1. `scripts/load-contract-seed.sh` builds the `luna_contract` DB from `tests/fixtures/contract-seed.sql` (a copy of Winterchilla's `scripts/dump-contract-seed.sh` output, do not run that script against the shared test DB; pass another path to use a fresher dump) and attaches `tests/fixtures/cutiemark.svg` to the seeded cutie marks
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
