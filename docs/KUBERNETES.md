# Running Luna in the cluster

Images (`.github/workflows/images.yml`, every push to `main`, tags `sha-<sha7>` only): `ghcr.io/mlp-vectorclub/luna-fpm`
(php-fpm 8.5, www-data uid/gid 33, with node + svgo and the media optimizers; also used for Horizon, scheduler and migrations
with another command) and `ghcr.io/mlp-vectorclub/luna-web` (nginx, static `public/` only; nginx config lives in the cluster repo).

- Web pod: `web` (nginx :80) + `fpm` (php-fpm 127.0.0.1:9000), same `/var/www/html` paths. Health: `GET /health` (200 when Postgres and Valkey answer, else 503).
- Horizon: `php artisan horizon`, one replica; stop with SIGTERM or `php artisan horizon:terminate` (grace ~90 s).
- Scheduler: `php artisan schedule:work`, exactly one replica, Recreate strategy.
- Migration job: `php artisan migrate --force && php artisan commit:clear && php artisan responsecache:clear`.
- Volumes: `storage/app` persistent and shared (uploads), writable by gid 33 (`fsGroup: 33`); `storage/framework`, `storage/logs`, `storage/media-library` can be emptyDir. Logs: `LOG_CHANNEL=stderr`.
- Config comes from env at runtime (no `config:cache` in the image). Env var names: `.env.example`, `config/*.php`; Valkey ACL via `REDIS_USERNAME`/`REDIS_PASSWORD`, keys prefixed with `REDIS_PREFIX`, `CACHE_PREFIX`, `HORIZON_PREFIX`.
- DeviantArt: `DEVIANTART_PROXY_URL` proxies the public requests (oEmbed, short links, gallery check); OAuth sign-in/token calls go direct.
