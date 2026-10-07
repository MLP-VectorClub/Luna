# DeviantArt lookups: queue, pauses, warming

Pages never wait for DeviantArt. `GET /posts/{id}/deviation` and `GET /events/{id}/finished-image` answer from the cache (`deviation:{provider}:{id}`, 30 days);
a miss queues `App\Jobs\RefreshDeviation` and answers `202 {pending, retryAfter}` until the job has filled the cache (the front end asks again).

- **Refusals pause everything.** 429 (honours `Retry-After`) or three distinct 403/5xx within two minutes make `DeviantArt::block()` stop all requests for
  5 minutes, doubling up to 2 hours while it keeps happening (reset by the next success). One denied submission is remembered for 30 minutes, a missing one (404) for a day.
  Only the start of a pause is logged (a warning, so one Discord message instead of one per request).
- **The job** is unique per submission, takes at most 20 requests a minute (`RateLimiter` key `deviantart-oembed`), waits out pauses by releasing itself and
  retries for up to 6 hours. A failed refresh keeps the old details.
- **Details older than a week** (`deviation-fresh:*`) are served as they are and refreshed in the background.
- **Warming:** `deviantart:warm-deviations` (scheduled every ten minutes) queues up to 40 finished posts whose details are missing or old, so the cache fills at a
  steady pace instead of when visitors scroll.
- **Needs a worker** when `QUEUE_CONNECTION` is not `sync`: `php artisan queue:work redis --queue=deviantart,default` or, with Horizon (installed, dashboard at `/horizon` for developers only, `php artisan horizon` under supervisor/systemd), `php artisan horizon` (supervisor/systemd). With `sync` (the current
  production setting) the job runs inside the request: pauses and the negative caches still apply, stale details are served without refreshing, and a fetch that fails
  answers 502. The scheduler (`schedule:run` every minute) must run for the warming.

**Service:** `setup/luna-horizon.service` (copy of the `when-horizon` unit's pattern). Install steps are in the file; `deploy.conf` restarts it on every deploy once it exists. Before enabling, pin `REDIS_PREFIX`, `CACHE_PREFIX` and `HORIZON_PREFIX` in `.env` (the Redis is shared with other apps) and set `QUEUE_CONNECTION=redis`.
- **fav.me links:** oEmbed answers 404 "not a deviation URL" for `fav.me` short links, so Luna first resolves `http://fav.me/ID` (only the http address redirects, https gives nothing) to the full deviation address, remembered for a month, and asks oEmbed with that, like Winterchilla.
- **Proxy:** set `OEMBED_PROXY_URL` (same variable as Winterchilla, e.g. `socks5h://127.0.0.1:40000` for the Cloudflare WARP proxy) to send the oEmbed requests through it; the club gallery check (DiFi) and image availability checks go direct.
