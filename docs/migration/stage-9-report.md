# Stage 9 report: deploy

Branch `migration/stage-9-deploy`, 2026-11-16. Ripe is live at **https://ripe.marianacastro.dev**.

## What changed

```
backend/Dockerfile, backend/docker/        one supervised production container
backend/app/Console/Commands/BackupDatabaseCommand.php   ripe:backup
backend/app/Http/Visitors/Turnstile.php    the planting check, confirmed with Cloudflare
backend/app/Logging/LevelledJsonFormatter.php
frontend/src/lib/turnstile.ts              the invisible widget on the seed packet
scripts/smoke.mjs                          a read-only check of a deployment
docs/deploy.md                             the runbook
```

- **No migrations.** One route field was added: `turnstile` on planting.
- **New dependency:** `league/flysystem-aws-s3-v3`, for the bucket.
- **Scope.** It was simplified before implementation:
  - no Infrastructure as Code;
  - no GitHub workflows for backups or smoke tests;
  - no encryption pipeline or custom API domain.

## Hosting

- **The choice.** The site stays on Vercel; the backend and PostgreSQL are on Railway. Hosting everything on Railway was compared on cost, memory and simplicity. It would have added about $1–2 a month, and the per-network limit would have had to trust Railway's `X-Real-IP`, which Railway does not document as protected against spoofing. So the cheaper option is also the safer one.
- **One container** runs the API (FrankenPHP), Reverb, the agent and realtime workers and the scheduler under supervisord, as `www-data`.
  - FrankenPHP forwards Reverb's `/app/*` WebSocket path, so one public port serves both.
  - Supervisord restarts a process that dies, and stops everything on SIGTERM.
  - The agent worker gets a minute to finish its job, and Railway's draining is set to 70 s. A run cut short is recovered by its lease, as designed.
- **The scheduler.** Scheduled commands now run `onOneServer()`, so the old and new containers overlapping during a deploy cannot both run them.
- **PostgreSQL 17,** like development and CI. Railway's template provisions 18, so the empty service was switched to Railway's `postgres-ssl:17` image with a fresh data directory, before any data existed.

## Security

- **Turnstile** guards planting. The seed packet renders an invisible widget, shown only if Cloudflare asks for an interaction. The backend confirms the token with Cloudflare, passing the address the gateway reported. A missing or refused token is a 422 and nothing is planted. Without keys nothing changes, which keeps development and the tests as they were.
- **The daily limits now count only plantings that succeeded.** Until now they counted every request. With 12 plantings a day for everyone, a dozen empty or unverified requests would have locked the greenhouse for the day. The visitor, network and overall daily limits now count only 201s. The limits start at 3 per visitor and 12 overall, set through environment variables.
- **Unchanged:**
  - HTTPS everywhere, and the `__Host-` cookie, `Secure` and `HttpOnly`; production refuses to issue it without `Secure`;
  - the gateway secret, the origin check and visitor isolation;
  - private channel authorization;
  - the Groq quota gate.

## Backups

- **What runs.** `ripe:backup` runs `pg_dump` in custom format, with the password in the environment rather than on the command line. It uploads the dump to the private Railway bucket `ripe-backups` and keeps the latest 14.
- **When.** The scheduler runs it daily at 03:30 UTC, and the pre-deploy command runs it before migrating, so every deploy has a fresh dump behind it.
- **A real restore test.** The first production dump (107 KB) was downloaded from the bucket and restored with PostgreSQL 17's `pg_restore` into an isolated database. `ripe:audit` checked its 3 tasks with no problems. The restored data was the 2 examples and the live test task, 157 journal events, 21 attempts and all 23 migrations.

## The deploy

All of it was done through the official CLIs. You only logged in to Railway and created the Turnstile widget.

- **Railway:**
  - created the project and PostgreSQL 17, the bucket, and the backend from GitHub;
  - service settings through Railway's API: root directory, watched paths, pre-deploy command, health check, draining and restart policy;
  - variables set from stdin, secrets included;
  - a Railway domain for the backend.
- **Vercel:**
  - a project with `frontend` as its root, connected to GitHub;
  - production variables from stdin;
  - a production deploy;
  - `ripe.marianacastro.dev` attached; the zone is on Vercel DNS, so no record was added by hand.
- **Secrets** went from local files straight into the platforms. None was printed, written to a command line or committed.

## Verification

| Check | Result |
|---|---|
| Backend: Pint, Larastan at the maximum level, Pest | pass; 383 tests |
| Frontend: Prettier, ESLint, TypeScript, production build, API types | pass |
| End to end, on an empty database | 2 scenarios |
| Each commit on a clean clone, end to end on an empty database | pass |
| Smoke test against production | 9 of 9 |

**New tests:**
- **Turnstile:**
  - planting is refused without a token and with a refused one, and accepted with a confirmed one;
  - Cloudflare receives the gateway's address;
  - two refusals leave an allowance of one planting intact.
- **Limits:** a full bed (409) and an invalid prompt (422) consume no daily allowance, while the network and overall limits still hold with cleared cookies.
- **Backups:** the dump uses custom format with no credentials on the command line, and pruning keeps only the latest dumps and never other files.

**Mutations confirmed the tests catch real mistakes:**
- counting every request toward the overall limit;
- accepting any Turnstile token.

**The image, run locally against PostgreSQL with the fake model:**
- the pre-deploy steps pass, and `migrate:fresh` is refused;
- the smoke test passes 9 of 9;
- a task planted through the Turnstile widget ripened in about eight seconds, with realtime through the web server;
- a killed worker was respawned within a second;
- every process stopped cleanly on SIGTERM;
- it used 220 MiB at rest with 4 PHP threads.

**Production, in Chrome:**
- The home page rendered on the server, naming `openai/gpt-oss-120b`.
- A task planted through the real invisible Turnstile was interpreted, planned, written in three pieces and reviewed by Groq within about two minutes. It paused once for the per-minute token quota, as expected.
- The visitor cookie is invisible to JavaScript.
- The browser opened a `wss` connection to Reverb and received `pusher:connection_established`.
- The Growth rings showed six rings of saved work with Groq's real timings.
- Production logs are JSON with `task_id`, `run_id` and `attempt_id` on every line of a run.

**Measured on Railway after the deploy:**

| Service | CPU | Memory | Volume |
|---|---|---|---|
| Backend | 0.03 vCPU | 251 MB | |
| PostgreSQL | under 0.01 vCPU | 125 MB | 4.2 MB |

That is about $4.5 a month at Railway's prices. Railway Hobby includes $5 a month for the whole workspace, which already holds other projects. Vercel costs $0.

## Problems found and fixed

1. **CI's end-to-end job had failed since stage 5.** The cause was ordering: Playwright starts the web servers before global setup migrates, and local runs hid it because their database was already migrated. It was fixed separately in PR #10, and every clean-clone check now runs end to end on an empty database.
2. **Refused requests consumed the daily allowance.** See Security.
3. **`php artisan optimize` failed:** this API has no views folder. The container caches configuration, routes and events instead.
4. **Caddy could not write its storage folder** as `www-data`. The image now gives it `/data` and `/config`.
5. **FrankenPHP started 20 PHP threads.** Four are enough for the demo, and that saves memory.
6. **Railway logged every line as an error,** because the JSON formatter writes `level` as a number. A small formatter now writes `info`, `warn` or `error`, and the workers run `--quiet`, so a RUNNING/DONE pair per job no longer drowns the agent's lines.
7. **Railway's PostgreSQL template is version 18;** see Hosting.
8. **A Vercel CLI deploy tried to upload over 15,000 files,** the backend's `vendor` included. `.vercelignore` now keeps the upload to the site. `vercel link` added a repository-wide `.env*` ignore, which was anchored to the root instead.
9. **Two test details:**
   - the backup test's first check for a password on the command line was meaningless, because the test password equals the user name;
   - the architecture preset forbids `tempnam`, so the dump uses a random file name.

## After merging

- **Point the backend at `main`:** `railway service source connect --repo maricastroc/ripe --branch main --service backend`. Today it deploys from this branch, which is what made validating before the merge possible.
- **Optionally,** register an SSH key with `railway ssh keys add` to run `ripe:inspect` and `ripe:audit` in production.
