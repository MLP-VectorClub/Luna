# Importing Winterchilla's data into Luna

Luna's own migrations define the schema (`migrate:fresh`), Winterchilla's data is loaded into it as a data-only dump, then the cutie mark and sprite files are copied. The schemas are compatible
(the only table Winterchilla has and Luna does not is its `phinxlog`; Luna adds `migrations`, `media`, `personal_access_tokens`, `luna_sessions`, `activity_log`, `failed_jobs`, `password_resets`),
so no conversion is needed. `scripts/import-winterchilla-data.sh` does all of it and was rehearsed on 2026-10-06 on the local copy of production (`prod_copy` into a scratch database: row counts of
users, shows, posts, appearances, tags, logs, events, cutie marks and colors identical, 146 cutie marks and 104 sprites copied, Luna answers `/show`, `/appearances`, `/tags` and `/events` from it).

```
scripts/import-winterchilla-data.sh <winterchilla_db> <target_db> [winterchilla_fs_folder uploader_user_id]
```

It drops and recreates the target database, so never aim it at the database Luna is serving. It ignores the cached config and stops unless Laravel really points at the target (on 2026-10-06 the first production run did not have that check: the server's cached config ignored `DB_DATABASE` and `migrate:fresh` rebuilt the live `luna` database; the data was then loaded into it from Winterchilla by hand, see the log in the plan). It needs a PostgreSQL superuser (the foreign keys are not checked while loading, `tags` has a circular one).

## On the server (build next to the live database, swap with a rename, keep the old one as the way back)

1. Build: as the postgres superuser, `scripts/import-winterchilla-data.sh mlpvc-rr luna_next /var/www/Winterchilla/fs 1` from the Luna directory (the database names and the `fs` folder are the production ones; the
   files land in Luna's storage, which must be writable by the user that runs it and then readable by php-fpm).
2. Check: serve Luna against `luna_next` on another port (`DB_DATABASE=luna_next php artisan serve --port=8780`) and look at a few endpoints, for example `/show/152` (The Last Problem: season 9, episode 25, 2 parts).
3. Swap, in a quiet moment: `php artisan down`, then `ALTER DATABASE luna RENAME TO luna_before_import` and `ALTER DATABASE luna_next RENAME TO luna` (nobody may be connected: stop the php-fpm workers or
   wait a moment), `php artisan up`, clear the response cache (`php artisan cache:clear`). The name Luna connects with does not change, so no `.env` edit is needed.
4. Way back: rename the two databases again. `luna_before_import` can be dropped once the new data is accepted.

## What the import replaces

- Everything in the database Luna served, including what Luna holds itself: Sanctum tokens and browser sessions (everyone is signed out once), notifications and anything created through Luna or Celestia since the last import.
- Winterchilla keeps getting writes, so the copy is only as fresh as the import: repeat it at the cutover.
- Imported DeviantArt tokens are Winterchilla's live ones. Luna must not refresh them (`DEVIANTART_TOKEN_SYNC` stays off until the cutover), a refresh token works only once.
- The Elasticsearch `appearances` index is shared and keyed by the same ids, nothing to rebuild for an unchanged import.
