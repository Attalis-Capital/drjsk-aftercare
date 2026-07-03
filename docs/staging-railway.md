# Railway staging (James pilot) — deploy + reseed

Staging instance for the DrJSK AfterCare pilot sign-off (mission attalis-missions#1723). **Demo data only — never any real patient data on this instance.**

## What deploys

- Build: `Dockerfile.railway` (single container: php-fpm + nginx, selected via `railway.json`).
- Web: nginx serves `public/` and proxies PHP to php-fpm on 127.0.0.1:9000, listening on Railway's `$PORT`.
- Release (in `docker/railway-entrypoint.sh`, runs every deploy): `php artisan migrate --force`, then a **guarded** demo seed, config/route/view cache, storage symlink.
- **Upload limits:** `docker/uploads.ini` sets `upload_max_filesize=25M` / `post_max_size=26M` (dropped into `conf.d/`). The stock `php.ini-production` ships 2M/8M, which would reject a real phone wound photo *before* nginx's 50M limit ever applied — PHP is the first gate. 25M/26M sits above the app's own `max:20480` (20 MB) validation ceiling and under nginx's 50M.
- **Seed is idempotent at the deploy level:** DemoScenarioSeeder itself is NOT idempotent (blind `create()` for users/visits/observations/etc.), so the entrypoint only seeds when the DB has no `organizations` row. A fresh volume seeds once; every redeploy is a no-op, so James never sees a duplicated demo roster. To deliberately re-seed, use the reseed command below (which wipes first).
- Health check: `/up` (Laravel 12 default).
- Not indexable: `X-Robots-Tag: noindex, nofollow, noarchive` at the nginx edge AND via the `NoIndex` middleware on every non-production response AND a `<meta name="robots">` in the app + upload shells.

## Required Railway variables (set in the Railway service, never in the repo)

Core:
- `APP_KEY` (generate once with `php artisan key:generate --show`; store the value in Railway)
- `APP_ENV=staging`
- `APP_DEBUG=false`
- `APP_URL=<the Railway-generated https URL>`
- `DEMO_LOGIN_ENABLED=true`

Database (from the Railway PostgreSQL 17 plugin — use the reference vars):
- `DB_CONNECTION=pgsql`
- `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`

Triage (live round-trip through the gateway):
- `LITELLM_BASE_URL=https://litellm.attaliscapital.com`
- `LITELLM_API_KEY=<LiteLLM virtual key>`
- `TRIAGE_ENABLED=true` (default; operating point stays v3 — do not override the frozen values)

Disabled / prohibited:
- `LANGFUSE_ENABLED=false` (ingestion is a stub; cloud.langfuse.com is prohibited per #1393)

Session/proxy (Railway is behind a proxy; trustProxies is already `*`):
- `SESSION_SECURE_COOKIE=true`

## One-command reseed

Wipe and re-seed the demo scenarios (destroys all data — demo only):

```
railway run --service <app-service> php artisan migrate:fresh --seed --force
```

Seed only (keep schema/other rows):

```
railway run --service <app-service> php artisan db:seed --force
```

`db:seed` runs `DatabaseSeeder`, which calls `App\Services\Demo\DemoScenarioSeeder`.

## First deploy order

1. Merge this PR (John).
2. Provision the Railway project: app service (build from `Dockerfile.railway`) + PostgreSQL 17 plugin.
3. Set the variables above.
4. Deploy; the entrypoint migrates + seeds automatically.
5. Verify: `/up` 200; demo login; the three scenarios; and the wound-photo → live triage → doctor-dashboard alert loop from a phone viewport.

Deploy happens **after** merge, from `main` — never from the PR branch.
