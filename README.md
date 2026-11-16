# Ripe

**Tasks that ripen while you procrastinate.** Live at **https://ripe.marianacastro.dev**.

You write down something you keep putting off — "reply to the difficult client email", "plan the December trip" — and leave it for later. From then on, an AI agent works on it in small steps, saving every result: locally on Ollama, or on Groq's free plan in the public demo. When you come back, you find a plan, a draft, a checklist or a first version — and a clear list of what still depends on you.

Built for the Hacktoberfest Open-Source AI Challenge, week 3: _Slow and Steady_.

## The flow

**Create → Put off → Ripen → Return → Review**

1. **Create** — one sentence in plain language. No forms.
2. **Put off** — say when you plan to come back ("tomorrow", "next week"). The agent starts.
3. **Ripen** — the agent interprets the request, splits it into steps, writes one piece per step and finally reviews the whole set.
4. **Return** — the task page shows what's done, what's happening right now, what's left and what the agent needs to know from you.
5. **Review** — every piece is editable, copyable, downloadable as `.md` and can be rewritten on request ("shorter", "more formal").

## The greenhouse

Ripe is drawn as a raised bed in a Victorian glasshouse, seen **in cross-section**: plants above the soil, roots below — and running through the soil, a glowing **mycelium network**. The mycelium is the agent: the model that feeds whichever plant it is working on. You can see a pulse of light travel from the mushrooms at the edge of the bed to that plant's roots. When the model can't be reached, the network goes grey; when the free model needs a pause or has used its allowance for the day, it hibernates in cool colours and picks up again on its own.

Every change in a plant comes from **persisted work**, never from the passage of time:

| What the agent finished              | What happens to the plant                                   |
| ------------------------------------ | ----------------------------------------------------------- |
| Interpreted the request              | The seed germinates: a taproot and two seed leaves          |
| Mapped out the plan                  | One lateral root and one bud on the stem per step           |
| Finished a step                      | That bud opens into a leafy branch you can click to read    |
| Is writing a step                    | The bud stirs; the branch only grows once the piece is saved |
| Finished the first piece             | The plant blossoms                                          |
| Finished most of the work            | Fruit sets and starts to colour                             |
| Reviewed everything                  | The fruit ripens and a paper review tag is tied to the stem |

A bud that needs an answer from you dries out and shows a blue drop. When everything is written but the review waits for an essential answer, the young fruit carries a paper tag that says so. The species (persimmon, plum, lemon, fig — each with its own leaves, flowers and fruit) only appears once the agent understands what kind of result you want. The number of plants shapes the bed: a single task takes the whole stage; many tasks spread into terraces. The light through the glass follows your local time, from dawn to a night where the mycelium glows.

New tasks are sown from a **seed packet** stuck in the soil. Harvested tasks are preserved as jars in **the pantry**. Opening a plant shows its **specimen sheet**: a field journal spread with the plant drawn as a botanical plate — numbered parts keyed to the work they hold — next to the field notes, the live writing, your open questions and the reading room. The journal's **growth rings** show every attempt the agent made, as a trunk in cross-section: only an attempt that saved its work lays down a ring, and the ones that saved nothing are cracks, breaks or knots on the growth line.

## Repository

```
frontend/     Next.js 16: the greenhouse, the specimen sheet and the gateway to the API
backend/      Laravel 13: the agent, the queue, the scheduler and the API
docs/         the migration plan, the API document and the screenshots
compose.yaml  PostgreSQL 17 for development
```

The migration that split Ripe into these two applications is described in [docs/migration/plan.md](docs/migration/plan.md), with one report per stage.

## Running it

Requirements: PHP 8.4, Composer, Node 20.9+, Docker and [Ollama](https://ollama.com).

```bash
ollama pull gemma4:e4b
ollama serve
```

```bash
docker compose up -d --wait postgres
cd backend
composer setup
composer dev
```

```bash
cd frontend
npm install
npm run dev
```

`composer dev` starts the API on port 8000, the agent's worker, the scheduler, Reverb on port 8080 and the worker that relays realtime signals. `npm run dev` starts the site on port 3000 (`npm run dev -- --port 3001` picks another). Open the site, not the API.

Both applications share one secret, which the site uses to prove to the API that a request came through it:
- **`backend/.env`:** set `RIPE_GATEWAY_SECRET` to a long random string (`php -r 'echo bin2hex(random_bytes(32));'`). Over plain http, also set `RIPE_VISITOR_COOKIE=ripe_visitor` and `RIPE_VISITOR_COOKIE_SECURE=false`, because browsers only keep `__Host-` cookies over https.
- **`frontend/.env.local`:** set `RIPE_GATEWAY_SECRET` to the same value, and `RIPE_API_ORIGIN` if the API is not on `http://127.0.0.1:8000`. Copy the `RIPE_REALTIME_*` lines from `.env.example` to follow changes as they happen; without them the site polls.

The model and the pace can be changed from the interface (click the mushrooms, or the mycelium chip at the top). Add `?sky=night` (or `dawn`, `dusk`) to the URL to preview another time of day. Any model installed in Ollama works; models with good structured JSON output give the best results.

To run the agent on Groq instead, set `RIPE_INFERENCE_DRIVER=groq` and `GROQ_API_KEY` in `backend/.env` (a key from [console.groq.com](https://console.groq.com/keys); the free plan is enough).

To see a ripe task without waiting, open one of the examples on the empty bed. They were grown by the real agent on Groq and saved with everything they produced; each names the model and the day it was recorded, is read-only, and never calls a model when you open it. `composer setup` installs them.

### How the site reaches the API

```
browser ── /api/* ──▶ Next proxy (src/proxy.ts) ──▶ Laravel /api
                         signs the request with the shared secret
                         and the client address taken from the platform
server render ───────▶ Laravel /api, same headers, with the visitor's cookie
```

The browser only ever talks to the site's own origin, so the visitor cookie is first-party and CORS stays off.
- Laravel refuses API requests that do not carry the secret.
- Laravel never trusts an address a client sent: it uses the one the gateway reports, which on Vercel comes from the platform's own header.

The same path runs in development and in production.

### How changes reach the browser

```
browser ══ WebSocket ═════════════════════════▶ Reverb (its own host)
   └─ /api/broadcasting/auth ─▶ gateway ─▶ Laravel: may this visitor join the channel?

commit ─────────▶ realtime queue ─▶ relay worker ─▶ Reverb ─▶ the browser refetches the snapshot
model stream ───▶ realtime queue ─▶ relay worker ─▶ Reverb ─▶ the browser appends the text at its offset
```

- **Signals, not state.** After a commit, each changed task sends one signal with its committed version, on `private-task.{id}` and `private-greenhouse.{visitor}`. The browser refetches the snapshot when the version is newer than its own; it never applies a change by itself. A snapshot older than the one on screen is never applied.
- **Live text** arrives as deltas with an offset in characters. A delta that leaves a gap, or belongs to an attempt the snapshot has not named yet, makes the browser refetch instead of guessing. A snapshot never shortens the text of the attempt on screen, and a new attempt replaces the old one.
- **Private channels only.** Joining one needs a signature from Laravel, which the browser only gets through the gateway with its own cookie. Clients cannot send events.
- **Reverb is never on the critical path.** The agent's executor only queues signals. A separate worker publishes them with a one-second timeout; after a failure it pauses for a few seconds and drops what would be stale.
- **Polling stays.** While the channel is subscribed, the page polls every 30 seconds; otherwise it polls as often as before. It also refetches on reconnect and when the tab gets focus.

### Deploying

The site runs on Vercel and the backend on Railway, as one supervised container next to PostgreSQL 17. [docs/deploy.md](docs/deploy.md) is the runbook: the architecture, every setting and variable, updating, the smoke test (`node scripts/smoke.mjs`), backups and restoring, and day-to-day operation.

### The public demo

The public demo runs on Groq's free plan, so it costs nothing to run, and it is shared by everyone who visits:

- **Quotas.** Before each call, Ripe checks Groq's limits (requests and tokens per minute and per day, and calls in flight) against its own ledger, under a lock, so several workers can never go over them together. When Groq answers 429, every worker waits for as long as Groq says.
- **Resting.** When the limits are reached, the work waits instead of failing: no attempt is spent, nothing finished is redone, and it picks up again on its own. The greenhouse says whether it is a short pause or the day's allowance, and only shows a time when it knows one. A wrong key or model is shown differently: the greenhouse cannot reach its model.
- **Limits.** Per day, 8 new tasks per visitor, 30 per network and 400 in all, and 20 rewrites per visitor; at most 6 plants growing at once. Clearing the cookie does not reset the network and overall limits.
- **Retention.** A task is kept for 30 days after the later of your last visit and the day you said you would come back, and never more than 90 days after it was planted. Each task shows the date.
- **Delete my greenhouse.** The link at the foot of the bed deletes every plant and jar, stops the work in progress and forgets the visitor.
- **No paid fallback.** OpenAI stays available as a driver, with its own money budget, but public mode refuses it unless `RIPE_ALLOW_PAID_INFERENCE=true`, and nothing switches to it on its own.

### Backend

`composer check` runs Pint, Larastan and the Pest suite against PostgreSQL. Tests are written only when they are indispensable; see [docs/testing.md](docs/testing.md) before adding one.

| Command                  | What it does                                                               |
| ------------------------ | -------------------------------------------------------------------------- |
| `php artisan ripe:simulate` | Plants a task and ripens it through real queue workers with the fake model, then prints its journal |
| `php artisan ripe:reconcile` | Recovers expired leases, re-dispatches lost runs and reconciles idle tasks |
| `php artisan ripe:timing` | Checks that leases, model calls, the worker timeout and queue redelivery are ordered safely |
| `php artisan ripe:audit {task?}` | Checks that every task's journal agrees with its persisted state; exits with an error when one does not |
| `php artisan ripe:inspect {task?}` | Without a task: the agent, its quota and the last day of attempts. With one: its steps, runs, attempts, latest journal events and the audit |
| `php artisan ripe:backup` | Dumps the database to the private backup bucket and keeps the latest 14; runs daily at 03:30 UTC and before every deploy |
| `php artisan ripe:prune` | Deletes tasks past their retention date and idle visitors without tasks; the scheduler runs it every hour |
| `php artisan ripe:examples:install` | Installs the recorded examples from `database/examples/`; unchanged ones are left alone |
| `php artisan ripe:examples:record` | Grows the examples again through the real agent with the configured model, saves them as fixtures and installs them |

The agent is configured in `backend/.env`; the model is a matter of configuration alone:

| Variable                  | Meaning                                                         |
| ------------------------- | --------------------------------------------------------------- |
| `RIPE_INFERENCE_DRIVER`   | `ollama` (default, fully local), `groq` (the public demo), `openai` (optional, paid) or `fake` |
| `OLLAMA_URL`, `OLLAMA_MODEL` | the local Ollama server and model                            |
| `GROQ_API_KEY`, `GROQ_MODEL` | the Groq key and model (default `openai/gpt-oss-120b`)        |
| `GROQ_REASONING_EFFORT`   | reasoning effort (default `low`)                                 |
| `GROQ_REQUESTS_PER_MINUTE`, `GROQ_REQUESTS_PER_DAY`, `GROQ_TOKENS_PER_MINUTE`, `GROQ_TOKENS_PER_DAY`, `GROQ_CONCURRENCY` | the free plan's limits Ripe keeps below (defaults 30, 1000, 8000, 200000 and 1) |
| `OPENAI_API_KEY`, `OPENAI_MODEL` | the OpenAI project key and model                          |
| `OPENAI_REASONING_EFFORT` | reasoning effort for reasoning models (default `low`)            |
| `OPENAI_SERVICE_TIER`     | optional service tier, such as `flex`                           |
| `RIPE_ALLOW_PAID_INFERENCE` | `true` lets a paid driver run in public mode; off by default   |
| `RIPE_DAILY_BUDGET_USD`, `RIPE_VISITOR_BUDGET_SHARE` | the money budget for paid drivers: per day, and the share one visitor may use |
| `RIPE_RETENTION_DAYS`, `RIPE_RETENTION_MAXIMUM_DAYS` | days a task is kept after the later of the last visit and the planned return (30), and at most after planting (90) |
| `TURNSTILE_SECRET_KEY`    | Cloudflare Turnstile's secret; when set, planting needs a token the backend confirms (the site needs `RIPE_TURNSTILE_SITE_KEY`) |
| `RIPE_BACKUP_DISK`, `BACKUP_*` | the S3-compatible bucket `ripe:backup` writes to; without it, backups are skipped |
| `RIPE_LIMIT_PLANT_PER_DAY_PER_VISITOR`, `RIPE_LIMIT_PLANT_PER_DAY_PER_NETWORK`, `RIPE_LIMIT_PLANT_PER_DAY`, `RIPE_LIMIT_PLANTS_IN_BED`, `RIPE_LIMIT_REVISIONS_PER_DAY_PER_VISITOR` | daily limits on new tasks (8 per visitor, 30 per network, 400 in all), unharvested plants (6) and rewrites (20 per visitor) |
| `RIPE_PACE`               | pause between the agent's automatic runs: `calm` (30 s), `steady` (6 s, default), `brisk` (1 s) or `instant` |
| `RIPE_MODE`               | `local` (settings editable from the interface) or `public` (configuration from the environment only) |
| `RIPE_GATEWAY_SECRET`     | the secret shared with the site; API requests without it are refused |
| `RIPE_VISITOR_COOKIE`, `RIPE_VISITOR_COOKIE_SECURE` | the visitor cookie: `__Host-ripe_visitor` and `Secure` by default |
| `RIPE_ALLOWED_ORIGINS`    | origins allowed to send changes besides the API's own, comma separated; Reverb accepts connections from the same hosts |
| `BROADCAST_CONNECTION`    | `reverb` to follow changes as they happen, `null` to rely on polling alone |
| `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET` | the Reverb application; the key is public, and the secret is derived from `APP_KEY` when left empty |
| `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME` | where the API publishes to Reverb |

`php artisan test --group=live` runs the agent end to end against the live models that are available: local Ollama, Groq when `GROQ_API_KEY` is set, and OpenAI when a key and a model are set (`--filter=groq` runs one only).

**Logs.** Lines written while a run executes carry its task, run and attempt, and API requests carry a request id, also returned as `X-Request-Id`. They record operation (attempts, deferrals, recoveries, halts) and never prompts, answers, cookies or credentials. In production, set `LOG_CHANNEL=stderr`, `LOG_STDERR_FORMATTER=Monolog\Formatter\JsonFormatter` and `LOG_LEVEL=info` for one JSON object per line.

The OpenAPI document is [`docs/api/openapi.json`](docs/api/openapi.json). After changing the API, run `composer openapi` in `backend/` (against a migrated database) and `npm run api:types` in `frontend/`. The test suite and CI fail when either is out of date.

## Architecture

```
frontend/src/
  app/          pages, rendered on the server from the API
  proxy.ts      the gateway from the site's /api to Laravel
  components/
    flora/      procedural botanical illustration: species, anatomy, specimen
    greenhouse/ the bed: glasshouse, soil and mycelium, seed packet, pantry
    specimen/   the specimen sheet: plate key, growth series, status board
    task/       field notes: live writing, questions, reading room, journal
  hooks/        polling, realtime channels, drafts, time-of-day sky, live text animation
  lib/          the API client, the gateway headers, live text reconciliation and the types generated from OpenAPI
frontend/e2e/   two end-to-end scenarios through the whole stack

backend/app/
  Orchestration/  the reconciler, leases, retries, the executor, live text, the quota gate and the money budget
  Inference/      the Groq, OpenAI, Ollama and fake drivers on a supervised transport
  Agent/          prompts, schemas and structured output
  Http/           controllers, form requests, resources, the visitor cookie and the gateway check
  Realtime/       signals after commit, the relay to Reverb and its pause
  Broadcasting/   who may join each private channel
  Actions/        what visitors can do to tasks, questions and pieces
  Journal/        the audit of the task journal against the state
  Observability/  operational logs and the fixed sentences visitors see for errors
  Retention/      when a task is forgotten
  Examples/       recording, exporting and installing the examples
```

### The agent

- **Separate phases** — each task goes through distinct runs: `interpret` (understand the request, point out what's missing), `plan` (split into steps), `generate` (one piece per step), `review` (a critical review of the whole set) and `revise` (a rewrite you asked for).
- **Persistent queue** — every run lives in PostgreSQL with an explicit state and is dispatched in the same transaction as the change that asks for it. A worker takes one run at a time and saves the result before moving on.
- **Recoverable** — a running run holds a lease, renewed on the clock even while the model is silent. If the worker dies, the lease expires and the run goes back to the queue; a late result from the dead attempt is refused.
- **Honest** — the interface reads the real state from the API: the text you see being written is the current attempt's streamed output, pushed as it arrives. Nothing is simulated.
- **No making things up** — the agent marks missing information as `[[TBD: ...]]`, rendered as highlights in the text. Essential questions only hold back the work that needs them; the rest moves forward with markers.
- **Your edits** — editing a piece saves a draft as you type; the draft becomes a new version when you stop. Versions are never changed, and if the agent rewrites a piece while you edit it, your draft is kept until you choose.
- **Limits** — one step at a time; up to 5 steps per task; 3 attempts per step with growing back-off; 24 runs per task; a configurable rest between steps (the "pace").
- **Model down** — runs wait instead of failing, and the work continues on its own when the model is back.
- **Out of quota** — when the free model's limits are reached, runs wait without spending an attempt and resume when there is room.

The agent writes in the same language as your request.

### Languages

The interface is in English and Brazilian Portuguese. The site picks one from your choice in the header (stored in a `ripe_locale` cookie), then from the browser's language, then English. The gateway forwards that choice to Laravel, so the API's messages, the field notes and the examples follow it too. The language of a task's content is separate: it always follows the request.

### Task states

| State       | Meaning                                          |
| ----------- | ------------------------------------------------ |
| `dormant`   | Planted or paused; nothing runs                  |
| `ripening`  | Queued or being worked on                        |
| `waiting`   | Did everything it could; needs an answer from you |
| `ripe`      | Everything written and reviewed                  |
| `stalled`   | Failed after its attempts; can be retried        |
| `harvested` | You took it; it sits in the pantry               |

## Scripts

Frontend, from `frontend/`:

| Script                 | What it does                                 |
| ---------------------- | -------------------------------------------- |
| `npm run dev`          | Development server                           |
| `npm run build`        | Production build                             |
| `npm run typecheck`    | Generates route types and type-checks        |
| `npm run api:types`    | Generates the API types from the OpenAPI document |
| `npm run test:e2e`     | Plants, ripens, opens and edits a task, reads its growth rings, and follows the greenhouse through a Reverb outage, through the whole stack (Playwright) |
| `npm run lint`         | ESLint                                       |
| `npm run format`       | Prettier                                     |
| `npm run format:check` | Prettier, without writing                    |

Backend, from `backend/`:

| Script             | What it does                                  |
| ------------------ | --------------------------------------------- |
| `composer setup`   | Installs dependencies, creates `.env`, migrates |
| `composer dev`     | API, agent worker, scheduler, Reverb and the realtime worker |
| `composer lint`    | Pint, without writing                         |
| `composer format`  | Pint                                          |
| `composer analyse` | Larastan at the maximum level                 |
| `composer test`    | Pest                                          |
| `composer check`   | All of the above checks                       |
| `composer openapi` | Exports the OpenAPI document                  |

## Stack

Frontend: Next.js 16 (App Router, view transitions) · React 19 · TypeScript · Tailwind CSS 4 · Motion · Radix UI · types generated from OpenAPI · Playwright

Backend: Laravel 13 · PHP 8.4 · PostgreSQL 17 · Groq, OpenAI and Ollama · Scramble · Pest · Pint · Larastan

## License

[MIT](LICENSE)
