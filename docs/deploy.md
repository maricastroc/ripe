# Deploying and running Ripe

Ripe runs at **https://ripe.marianacastro.dev**.

```
browser ── https ──▶ Vercel: the Next.js site (ripe.marianacastro.dev)
   │                    └─ /api/* and server rendering ── https + gateway secret ──▶ Railway: backend
   └── wss ─────────────────────────────────────────────────────────────────────────▶ Railway: backend /app/*

Railway project "ripe" (US East)
  backend    one container, supervised: API (FrankenPHP), Reverb, agent worker,
             realtime worker, scheduler — backend-production-5fed3ade.up.railway.app
  Postgres   PostgreSQL 17 (Railway's postgres-ssl:17 image), private network only
  bucket     ripe-backups: database dumps, private
Outside: Groq (free plan, the model), Cloudflare Turnstile (planting check)
```

The browser only talks to the site's origin, except for the WebSocket to Reverb. The backend's public domain serves the API, which refuses anything without the gateway secret, and Reverb's `/app/*` path.

## Monthly cost

| | Measured | Cost |
|---|---|---|
| backend | about 250 MB, 0.03 vCPU | about $3.1 |
| Postgres | about 125 MB, under 0.01 vCPU, 4 MB volume | about $1.4 |
| bucket | a few MB | about $0 |
| Vercel (Hobby) | | $0 |

Railway Hobby costs $5 a month and includes $5 of usage across the whole workspace. Check usage with `railway metrics --all --since 1d` and `railway usage`.

## Configuration

Secrets live only in each platform's variables. They are set from stdin, and never in the repository or on a command line.

### Railway, service `backend`

| Variable | Value |
|---|---|
| `APP_KEY` | secret, `base64:` + 32 random bytes |
| `APP_URL` | `https://ripe.marianacastro.dev` |
| `DB_CONNECTION`, `DB_URL` | `pgsql`, `${{Postgres.DATABASE_URL}}` (a reference, over the private network) |
| `CACHE_STORE`, `QUEUE_CONNECTION`, `SESSION_DRIVER` | `database`, `database`, `array` |
| `RIPE_MODE`, `RIPE_INFERENCE_DRIVER` | `public`, `groq` |
| `GROQ_API_KEY` | secret |
| `RIPE_GATEWAY_SECRET` | secret, the same value as on Vercel |
| `RIPE_ALLOWED_ORIGINS` | `https://ripe.marianacastro.dev` |
| `BROADCAST_CONNECTION`, `REVERB_APP_ID` | `reverb`, `ripe` |
| `REVERB_APP_KEY`, `REVERB_APP_SECRET` | secret; the key is also on Vercel, where it is public by design |
| `TURNSTILE_SECRET_KEY` | secret, from the Cloudflare widget |
| `RIPE_LIMIT_PLANT_PER_DAY_PER_VISITOR`, `RIPE_LIMIT_PLANT_PER_DAY` | `3`, `12` |
| `RIPE_BACKUP_DISK` | `backups` |
| `BACKUP_ENDPOINT`, `BACKUP_REGION`, `BACKUP_BUCKET` | from `railway bucket credentials --bucket ripe-backups` |
| `BACKUP_ACCESS_KEY_ID`, `BACKUP_SECRET_ACCESS_KEY` | secrets, from the same command |

The image already sets production defaults: `APP_ENV=production`, JSON logs on stderr at `info`, and Reverb on internal port 8081.

**Service settings:**
- **Source:** GitHub `maricastroc/ripe`, root directory `/backend`, rebuilt only when `backend/**` changes. Railway builds `backend/Dockerfile`.
- **Pre-deploy command:** `sh -c 'php artisan ripe:backup && php artisan migrate --force && php artisan ripe:examples:install && php artisan ripe:timing'`
  - It backs up first, migrates without ever dropping anything (`migrate:fresh` is refused in production), installs or updates the examples idempotently, and checks that the timeouts are ordered safely.
  - If any step fails, the new version does not go live.
- **Health check:** `/up`, with a timeout of 300 s.
- **Draining:** 70 s. Supervisord gives the agent worker 60 s to finish its job. A run cut off by a deploy is recovered through its lease, as designed.
- **Restart policy:** on failure, at most 10 times.

### Vercel, project `ripe`

- **Root directory:** `frontend`, framework Next.js, connected to GitHub. Production deploys from `main`; previews of other branches are protected by Vercel's login.
- **Variables (production):**
  - `RIPE_API_ORIGIN`: `https://backend-production-5fed3ade.up.railway.app`
  - `RIPE_GATEWAY_SECRET`: the same as Railway's
  - `RIPE_REALTIME_KEY`: Railway's `REVERB_APP_KEY`
  - `RIPE_REALTIME_HOST`: the backend's domain
  - `RIPE_REALTIME_PORT`: `443`
  - `RIPE_REALTIME_SCHEME`: `https`
  - `RIPE_TURNSTILE_SITE_KEY`: from the Cloudflare widget
- **Domain:** `ripe.marianacastro.dev`. The `marianacastro.dev` zone is on Vercel DNS, so no record had to be added by hand.

### Cloudflare Turnstile

One widget for `ripe.marianacastro.dev`, in invisible mode. The site key goes to Vercel and the secret key to Railway. Without the secret, planting is not checked (development and tests).

## Updating

Merge into `main`. Vercel deploys the site and Railway deploys the backend when `backend/` changed. Each backend deploy first takes a backup, then migrates.

**Check the deploy** with the smoke test, which only reads:

```bash
node scripts/smoke.mjs https://ripe.marianacastro.dev https://backend-production-5fed3ade.up.railway.app <REVERB_APP_KEY>
```

It checks that:
- the site, the garden, the agent, and both examples with their timelines answer through the site;
- the backend is healthy;
- the API refuses requests without the secret and changes from other sites;
- planting without Turnstile is refused;
- Reverb accepts the site's WebSocket.

**Roll back** with `railway deployment list --service backend`, then redeploy the previous one from the dashboard. On Vercel, use `vercel rollback`.

**Manual deploys,** when needed:
- **Backend:** `railway api` with `serviceInstanceDeployV2` and a commit SHA, or **Redeploy** in the dashboard.
- **Site:** `vercel deploy --prod` from the repository root. `.vercelignore` keeps the backend out of the upload.

## Backups and restoring

`ripe:backup` writes `postgres/ripe-<UTC time>.dump`, in PostgreSQL's custom format, to the private bucket. It runs every day at 03:30 UTC and before every deploy, and keeps the latest 14.

**To restore,** for example into a new database to inspect it:

1. **Download the dump.** With the bucket credentials in the environment (`BACKUP_*`, from `railway bucket credentials --bucket ripe-backups`), run from `backend/`:

   ```bash
   php artisan tinker --execute '$f = Storage::disk("backups")->files("postgres"); rsort($f); file_put_contents("latest.dump", Storage::disk("backups")->get($f[0]));'
   ```

2. **Restore it** with a PostgreSQL 17 client into an empty database:

   ```bash
   docker run --rm -v "$PWD:/restore:ro" -e PGPASSWORD=… postgres:17-alpine pg_restore --no-owner --no-privileges --exit-on-error -h <host> -U <user> -d <empty database> /restore/latest.dump
   ```

3. **Check it** with `DB_DATABASE=<that database> php artisan ripe:audit`.

**To restore production itself:**
1. Open a temporary TCP proxy with `railway tcp-proxy create --service Postgres`.
2. Stop the backend.
3. Restore with `--clean --if-exists` into the production database.
4. Redeploy the backend, then delete the proxy.

A restore was tested on 2026-11-16: the first dump restored into an isolated PostgreSQL 17 database, and `ripe:audit` found no problems in its 3 tasks.

## Running commands in production

Register an SSH key once with `railway ssh keys add`. Then, from the linked project:

```bash
railway ssh --service backend -- php artisan ripe:inspect
```

The same works for `ripe:inspect <task>`, `ripe:audit` and `ripe:backup`.

Logs are in Railway's **Logs** tab or `railway logs --service backend`. They are JSON with a textual level, and every line written during a run carries `task_id`, `run_id` and `attempt_id`, so filter on them. Logs never contain prompts, answers, cookies or credentials.

## What to expect

- **Resting** is normal on the free plan. The daily quota carries about nine tasks. When the quota runs out, the greenhouse rests and resumes on its own, and short pauses for the per-minute limit happen during busy moments.
- **"Can't reach its model"** means the Groq key or model is wrong. Check `GROQ_API_KEY`; the halt lifts on its own once the model answers.
- **A stalled task** shows a fixed sentence to the visitor. `ripe:inspect <task>` shows the raw error.
- **Reverb down:** the site falls back to polling, so nothing is lost.

## Rotating secrets

- **Gateway secret:** set a new `RIPE_GATEWAY_SECRET` on Railway and Vercel together, then redeploy both.
- **Groq or Turnstile keys:** create the new key, set it, redeploy, then delete the old one.
- **`APP_KEY`:** rarely worth changing. Visitor cookies keep working, because their tokens are hashed without it, but the per-network limits start counting afresh and the anonymous identifier sent to the model changes.
