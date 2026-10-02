# Implementing Winterchilla's `/api/v0` contract in Luna (plan, 2026-10-01)

Source of truth: Winterchilla `public/dist/api.json` (deployed as `3ec4e41c`), `tests/Browser/Api/*`, `docs/api-path-alignment.md`,
and the "Database cutover to Luna" section of its `CLAUDE.md`. **This is a plan only**: nothing here is implemented, deployed or
migrated, and the prod `luna` DB has not been touched.

## 1. Gap analysis

Re-run against Winterchilla `1073e4fb` (api.json regenerated 2026-10-01 14:10): 146 method+path pairs, of which 17 are `x-internal` (Winterchilla UI/session
details, Luna does not implement them), leaving **129 contract operations**. 20 of them match Luna's routes today:

`GET /about/connection|members`, `GET /appearances`, `/appearances/autocomplete|full|pinned|{id}|{id}/locate|{id}/sprite|{id}/color-groups`,
`GET /color-guide`, `/color-guide/major-changes`, `GET /show`, `GET /useful-links/sidebar`, `GET /user-prefs/me`,
`GET /users`, `/users/me`, `/users/{id}`, `/users/da/{username}`, `POST /users/signout`.

**Missing: 109 operations.** By tag: appearances 21, posts 16, events 13, shows 11, tags 9, admin 7, notices 6, users 5, color guide 4, color groups 4,
personal color guide 4, personal guide 2, settings 2, discord 2, and one each for useful links, `GET /config`, notifications.

Path or shape mismatches on endpoints Luna *has*:

| Contract | Luna today | Action |
|---|---|---|
| `GET /appearances/full` | same | renamed from `all` on the Winterchilla side, nothing to do |
| `GET /show/{id}`, `/show/next`, `/show/{id}/posts` | only `GET /show` | add |
| `GET /users` is staff only | any signed-in user | add role middleware |
| `GET /users/{id}/preferences/{key}`, `PUT` | only `GET /user-prefs/me` | add; keep `/user-prefs/me` (it is in the contract too) |
| sessions, e-mail change/verify, password | tokens, `/users/me`, signed-link verify + resend | decided: Luna's flows win, the Winterchilla ones are `x-internal` |
| `GET /appearances/{id}` | exists | gained `relatedAppearances`, `relatedShows`; add them |
| `POST /posts`, `/posts/reservations` | n/a | return an int `id` |
| `/show` payload `generation` | Luna has it | remove (contract: shows have no generation) |
| `/appearances/{id}` etc. read shapes | already aligned per Winterchilla | verify by running the read contract tests (step 0), do not assume |
| `securitySchemes` `SessionCookie` / `CSRFToken` | Sanctum | Luna's own OpenAPI keeps a Sanctum scheme; Celestia's types do not depend on it |

Luna also has endpoints not in the contract (signin/signup/OAuth, `/users/tokens`, `/users/email/*`, `/about/server-info`, `/api/oauth2-callback`).
Keep them: they are the Sanctum-flavoured replacement for Winterchilla's DeviantArt session login.

Missing tables/models: every table already exists (`posts`, `events`, `event_entries`, `event_entry_votes`, `notices`, `notifications`,
`pcg_*`, `show_votes`, `tags`, `tag_changes`, `tagged`, `logs`, `settings`...) but Luna has models for only 14 of them. Needed: `Event`,
`EventEntry`, `EventEntryVote`, `Notice`, `Notification`, `Log`, `Setting`, `ShowVote`, `ShowAppearance`, `RelatedAppearance`, `TaggedPivot`,
`TagChange`, `PcgPointGrant`, `PcgSlotHistory`, `BrokenPost`, `LockedPost`, `PreviousUsername`, `FailedAuthAttempt`.

## 2. Auth mapping (Sanctum)

Winterchilla's own session cookie, `test-login`, `CSRF_TOKEN` echo and the 419 case are out of scope.

| Contract says | Luna |
|---|---|
| public | no middleware |
| public but visitor-aware (`canEdit`, `canManage`, private personal guides, `/useful-links/sidebar` `[]` for guests) | new `optional-auth` middleware: `Auth::shouldUse('sanctum')` so `$request->user()` is filled when a token/stateful cookie is sent, never 401 |
| signed in | `auth:sanctum` (JSON 401 already) |
| member / staff / developer | `auth:sanctum` + new `role:<Role>` middleware over `Permission::sufficient` → 403 `{message}` |
| owner-or-staff (event entries, posts, notifications, preferences) | in-controller check through Laravel Policies (`PostPolicy`, `EventEntryPolicy`, ...) so the rule lives in one place and is unit-testable |
| per-user capability prefs (`a_postreq`, `a_postres`, `a_reserve`, `a_pcgmake`, ...) | policies read `UserPref`; 403 on denied |

Transport: bearer token for tests and non-browser clients, stateful cookie for Celestia (same-origin proxy, set `FRONTEND_URL` / `SANCTUM_STATEFUL_DOMAINS`
and let `/sanctum/csrf-cookie` stay). No CSRF handling is needed for bearer calls. Error envelope: make `Handler` render `{message}` / `{message, errors:{field:[…]}}`
with 401/403/404/409/422/429/501/502/503; keep one field error per 422 only if a contract test asserts it (check `AppearanceApiTest`, `PostApiTest`).
Throttles: the contract does not specify them, keep Luna's `throttle:60,1` groups but exempt the test environment.

## 3. DB cutover items (from Winterchilla CLAUDE.md)

New Luna migrations, each idempotent against both a Luna-built DB and an imported Winterchilla DB (`Schema::hasColumn` / `hasTable` guards):

1. Drop `show_videos`.
2. Drop `show.generation` and the `mlp_generation` type, rebuild the unique key on `show` without it (`2021_02_13_132106_change_show_table_unique`
   currently includes `generation`). Delete `MlpGeneration`, `MlpGenerationType`, the AppServiceProvider macro and the `generation` field in `/show`.
3. `discord_members.discriminator` → `smallint` (cast with `discriminator::smallint`, trimming the space padding).
4. `cutiemarks.contributor_id` FK → `ON DELETE RESTRICT`; `pinned_appearances.created_at/updated_at` → `timestamptz`.
5. Data cleanup: delete `user_prefs` rows with key `discord_token` (8 on prod) and `ep_hidesynopses`; delete `notifications` of type `sprite-colors`.
   Remove `Episode_HideSynopses` from `UserPrefKey` and add handling so unknown keys never make `UserPref::all()` throw (defensive).
6. `email_verifications.created_at/updated_at`: add to Luna's table definition (prod has them, table is empty).
7. Commit the already-prepared uncommitted `fs:migrate` fixes (JPEG accepted in the `sprites` collection, extension from real mime type) and make the
   command skip-and-report instead of aborting on the first bad file.
8. Fix the `Post::getCreatedAtColumn()` "Undefined property" warning before posts are served.

**Cutover method.** Winterchilla's own CLAUDE.md recommends keeping the Winterchilla-schema DB as source of truth with Luna's `migrations` table pre-seeded.
This plan prefers the opposite for the *final* state: both apps run on one DB shaped by the migrations in section 3, because Winterchilla must keep
working until Celestia ships, and Luna needs `media`, `personal_access_tokens`, `activity_log` which Winterchilla ignores. So:

Before Winterchilla can point at Luna's database:
- [ ] the migrations above are written, tested on a fresh DB **and** on `luna_import` (prod rehearsal copy);
- [ ] Winterchilla has been run against the post-migration schema (its suites pass with `DB_NAME` pointed at it): dropping `show.generation` / `show_videos`
      is already Winterchilla's own state, `discriminator` as smallint is Winterchilla's own, so it should be a no-op, but verify;
- [ ] `fs:migrate <fs> <uid>` was re-run on `luna_import` using the cleaned `fs/` (the 6 orphan `cm_source` files are already deleted on prod; the cutiemark
      fix still needs deploying on the Winterchilla side or orphans reappear);
- [ ] decision (user) between loading into the empty prod `luna` DB vs adopting `mlpvc-rr`; the dump contains e-mails and sessions, so keep it local and delete it;
- [ ] decision on Luna-owned data in prod `luna` (currently empty besides the 16 migrations per the rehearsal notes, so nothing to preserve, re-check first);
- [ ] Winterchilla `phinxlog` is untouched and Luna's `migrations` table contains every Luna migration (so neither tool tries to re-run history);
- [ ] user go-ahead, both for the data load and for any prod migration. Not part of this task.

## 4. Order of work and test strategy

**Step 0 – harness (before any endpoint).**
- A script (`scripts/diff-contract.php`, or a PHPUnit test) that loads Winterchilla's `api.json` and Luna's `/generated/api-docs.json` and fails on missing
  operations / operation IDs / required response properties. This becomes the progress meter (109 → 0).
- Run Winterchilla's contract tests against Luna. `Tests\Browser\Helpers\ApiClient` is hard-wired to cookie jar + `/test-login/{id}` + `CSRF_TOKEN` + `BASE_URL`.
  Proposal (Winterchilla side, see questions): make it configurable by env, `API_BASE_URL`, and `API_AUTH=bearer` where `loggedInAs($id)` calls a Luna-only
  `POST /api/v0/test/login/{id}` returning `{token}` and sends `Authorization: Bearer`. CSRF echo is skipped in bearer mode.
- Luna side: that route exists only when `APP_ENV=testing` (same guard idea as Winterchilla's TEST_MODE) and creates a Sanctum token for seeded users 9001–9006.
- **Seeding:** do not rewrite `TestSeeder` in PHP/Eloquent. Dump Winterchilla's seeded test DB (`reset-test-db.sh` output, fixed IDs from `TestSeederConstants`) and load it
  into `luna_test`, then run Luna's pending migrations. This doubles as a cutover rehearsal on every run and keeps IDs identical so the tests apply unchanged.
  Luna's PHPUnit suite keeps using its own `luna_test` DB with factories for unit-level tests; the cross-implementation run uses a second DB (`luna_contract`).
- Network seams the contract tests rely on that Luna needs equivalents for: Redis-seeded deviations (post image checks), the fake OAuth provider, club-gallery
  marker files, Discord. Plan: Laravel `Http::fake()` bound by a test-environment service provider, driven by the same fixture URLs.
- ElasticSearch for `/appearances` and `/tags/autocomplete`: local ES service exists; the contract DB needs the `appearances` index built (Winterchilla's `POST /color-guide/reindex` equivalent → artisan command).

**Step 1 – cross-cutting.** Error envelope, `optional-auth` and `role:` middleware, policies, pagination resource (`currentPage/totalPages/totalItems/itemsPerPage`),
permission flags helper, `Log` model + writer (every write endpoint logs like Winterchilla), `GET /config`, Setting model, `/users/*` gaps (related fields, preferences).

**Step 2 – DB migrations from section 3** (needed before `/show` changes and shows writes).

**Step 3 – read endpoints in descending Celestia value**, each with the matching contract file run against Luna: shows (`/show/{id}`, `next`, `posts`, `appearances` GET),
posts reads (`/posts`, `{id}`, `location`, `reload`), tags, events, user profile/contributions/point history/personal guide, notices current, admin logs.

**Step 4 – writes** in dependency order: settings, notices, useful links, tags → color groups → appearances (+ sprite/cutie marks/relations/tags/pin; uses medialibrary, the
riskiest because it is untested against real data) → shows → posts (reserve / finish / approve flow, locking, breaking) → events/entries/votes → users (roles, password,
email, prefs, sessions) → notifications → personal guide (slots, points) → Discord (link/unlink/sync, `Http::fake`d in tests).

**Step 5 – close out.** Docs regenerated (`l5-swagger:generate`), `diff-contract` at zero, full contract run green, Celestia's type generation checked against Luna's own
document, then the cutover checklist in section 3 goes to the user.

Each step ships with Luna Feature tests (existing PHPUnit 11 setup, 30 tests today) *and* is exercised through the Winterchilla contract files. Rule of thumb: Luna tests assert
Luna-specific behavior (policies, Sanctum, throttles); the contract tests assert the shared shapes.

## 5. Decisions from Winterchilla (answered 2026-10-01, changes on its origin/main `1073e4fb`, not deployed)

- Sessions (`GET /users/session/status`, `DELETE /users/sessions/{id}`), `POST /users/email/verify`, `/users/{id}/email-changes`, `POST /users/me/password`, `DELETE /users/{id}/contributions/cache`
  and the HTML-fragment endpoints (`/about/upcoming`, `/cg/full`, `*/lazyload`, `/posts/{id}/reload`, `/show/{id}/posts`, `/posts/requests/suggestion`, `/notifications`
  list, `/users/{id}/avatar-wrap`, `/admin/stat-cache`) are `x-internal`: **not implemented in Luna**. Password/e-mail endpoints are staff-gated in Winterchilla only while the feature is under test there.
- The "135 operations" was an old snapshot; the document is one operation per method+path pair.
- `ApiClient` now reads `CONTRACT_BASE_URL`, `CONTRACT_API_PATH`, `CONTRACT_AUTH=bearer`, `CONTRACT_LOGIN_URL` (with `{id}`), `CONTRACT_LOGIN_METHOD`; with `CONTRACT_BASE_URL` set the Pest bootstrap
  neither resets the DB nor starts its own server. Seed: `scripts/dump-contract-seed.sh > contract-seed.sql` (plain INSERTs). Step 0 below uses exactly this.
- Redis/OAuth/deviation fixtures are not reusable: **Luna writes its own tests** for post images, finishing with a deviation, approval, Discord sync, OAuth.
- The write API accepts `application/json` as well as form bodies (scalar lists as comma lists, nested values as JSON). Luna should accept JSON; the contract tests may send either, so
  Luna must also parse form-encoded and multipart bodies and the comma-list convention.
- Shared schemas no longer inherit a `status`/`pagination` envelope.

## 6. Still open

- Login seam (confirmed): Luna serves `POST /api/v0/test/login/{id}` → `{"token": "..."}` (testing env only); run the contract suite with `CONTRACT_AUTH=bearer CONTRACT_LOGIN_URL=/test/login/{id}`. `ApiClient` reads `json['token']`, sends `Authorization: Bearer`, no CSRF echo.
- Does the dump load cleanly before Luna's migrations (uses Winterchilla schema incl. `show.generation`)? Plan assumes load, then `artisan migrate`; verify in step 0.

## 7. Progress log

Run `php artisan l5-swagger:generate && php scripts/diff-contract.php --by-tag` for the live count.

**Running Winterchilla's contract suite against Luna** (needs Winterchilla's `contract-seed.sql`, a Redis-less run, nothing touches production):

```
scripts/load-contract-seed.sh /path/to/contract-seed.sql   # builds the luna_contract DB: migrate, relax avatar_url, load seed
scripts/serve-contract.sh                                   # Luna on :8766, APP_ENV=testing, response cache off (restart after code changes)
cd ../Winterchilla && env CONTRACT_BASE_URL=http://127.0.0.1:8766 CONTRACT_API_PATH= CONTRACT_AUTH=bearer \
  'CONTRACT_LOGIN_URL=/test/login/{id}' vendor/bin/pest tests/Browser/Api/TagApiTest.php
```

- 2026-10-01: schema alignment migration, foundation (`/config`, middleware, `{message}` errors, test login, `/users/me`), settings, notices, useful links, user preferences, tags (with
  ElasticSearch index updates through `AppearanceIndex`), color groups, appearance management (create / update / delete / pin / relations / tags / shows / order / template / selective clear /
  sprites), shows (details, create, update, delete, votes, next, latest, prefill, appearance links), admin logs, notification read, color guide export and reindex, personal guide
  (appearances, slots, points, point history, recalculation) and `PUT /users/{id}/role`. **91 of 127 operations done, 36 left.** Luna's own suite has 92 tests.
- Left: posts (16), events and entries (13), user profile and contributions (2, they need the post mapping), Discord link/sync (2), cutie marks and SVG sanitizing (3).
  The post and event flows call external image providers (DeviantArt, Derpibooru and others) and the DeviantArt API, which Luna has no client for yet; that is the main design question.
- Contract tests that still fail for Winterchilla-UI reasons (not Luna's to fix): `goto`/`message`/`url`/`cgs`/`notes`/`newhtml`/`section`/`html` fields, tests of `x-internal` endpoints,
  the default-sprite fallback and the staff-only cutie mark file checks.
- Decision needed before cutie marks / sprites: Winterchilla sanitizes uploaded SVG with the `svgo` Node binary (`/appearances/{id}/sanitize-svg`, cutie mark upload). Luna has no
  equivalent; options are shipping svgo as a dependency of Luna's deploy, or sanitizing in PHP (e.g. `enshrined/svg-sanitize`) which would not minify.
- 2026-10-02: `vectorApps` in `/config`, partial update semantics on `PUT /appearances/{id}`, cutie marks (`GET|PUT /appearances/{id}/cutie-marks`) and `POST /appearances/{id}/sanitize-svg`
  (decision: sanitize in PHP with enshrined/svg-sanitize and its own minifier, no svgo; stored file is the sanitized SVG, colors are not tokenized), Discord sync and unlink.
  **121 of 127 operations done**; the 6 left are the event entry operations (disabled in Winterchilla, skipped). Luna's suite has 131 tests.
  Discord calls go through `Http` (discord.com API v10); `DISCORD_SKIP_REVOKE=true` (set by `scripts/serve-contract.sh`) skips the revoke call so the contract test can unlink seeded users.
  Seed caveat: `last_synced` of the seeded synced Discord user (9004) is time based, load the seed shortly before running `DiscordApiTest`, otherwise the sync hits Discord and unlinks the user.

## 8. Things the migration found that Winterchilla (or Luna) had missed

- Winterchilla dropped the unique key on `show (season, episode)` when it dropped `show.generation`; Luna's migration restores it.
- Luna's `UsefulLink` cast `minrole` to the `Role` enum, but production has 4 rows with `guest`, so the sidebar would have thrown for any signed-in user. Fixed.
- Luna's sidebar response lacked `minRole` and exposed `order`; the contract wants `{id, label, url, title, minRole}`. Fixed.
- `dev_role_label` default was `staff` in Luna and is `developer` in Winterchilla (production stores `staff` explicitly, so nothing changes there). Aligned to `developer`.
- Luna's `Show` model still listed a removed `synopsis_last_checked` column. Removed.
- Username rule: tightened in Luna to Winterchilla's `[A-Za-z\-\d]{1,20}` (no underscores). Existing accounts with `_` keep working, only new signups are checked.
- Contract oddities to settle with Winterchilla: `Pagination.currentPage` description in api.json contains a leaked docblock; `GET /tags/autocomplete?action=synon` answers 409
  (a GET that reports state through an error) and prefixes `type` with `typ-` when searching; `Tag.synonymOf` is an id while `TagListItem.synonymOf` is `{id, name}`;
  `TagListItem.type` enum lacks `warn`; `DELETE /tags/{id}/synonym` answers 200 or 204 depending on whether the tag was a synonym.
