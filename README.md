# Ripe

**Tasks that ripen while you procrastinate.**

You write down something you keep putting off — "reply to the difficult client email", "plan the December trip" — and leave it for later. From then on, an AI agent works on it in small steps, saving every result: locally on Ollama, or on a hosted model in the public demo. When you come back, you find a plan, a draft, a checklist or a first version — and a clear list of what still depends on you.

Built for the Hacktoberfest Open-Source AI Challenge, week 3: _Slow and Steady_.

## The flow

**Create → Put off → Ripen → Return → Review**

1. **Create** — one sentence in plain language. No forms.
2. **Put off** — say when you plan to come back ("tomorrow", "next week"). The agent starts.
3. **Ripen** — the agent interprets the request, splits it into steps, writes one piece per step and finally reviews the whole set.
4. **Return** — the task page shows what's done, what's happening right now, what's left and what the agent needs to know from you.
5. **Review** — every piece is editable, copyable, downloadable as `.md` and can be rewritten on request ("shorter", "more formal").

## The greenhouse

Ripe is drawn as a raised bed in a Victorian glasshouse, seen **in cross-section**: plants above the soil, roots below — and running through the soil, a glowing **mycelium network**. The mycelium is the agent: the model that feeds whichever plant it is working on. You can see a pulse of light travel from the mushrooms at the edge of the bed to that plant's roots. When the model can't be reached, the network goes grey.

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

New tasks are sown from a **seed packet** stuck in the soil. Harvested tasks are preserved as jars in **the pantry**. Opening a plant shows its **specimen sheet**: a field journal spread with the plant drawn as a botanical plate — numbered parts keyed to the work they hold — next to the field notes, the live writing, your open questions and the reading room.

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

To see a ripe task without waiting, click "open a ripe example" on the empty bed. The example is grown once by a scripted model, marked as **illustrative**, and never processed again.

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

### Backend

`composer check` runs Pint, Larastan and the Pest suite against PostgreSQL. Tests are written only when they are indispensable; see [docs/testing.md](docs/testing.md) before adding one.

| Command                  | What it does                                                               |
| ------------------------ | -------------------------------------------------------------------------- |
| `php artisan ripe:simulate` | Plants a task and ripens it through real queue workers with the fake model, then prints its journal |
| `php artisan ripe:reconcile` | Recovers expired leases, re-dispatches lost runs and reconciles idle tasks |
| `php artisan ripe:timing` | Checks that leases, model calls, the worker timeout and queue redelivery are ordered safely |

The agent is configured in `backend/.env`; the model is a matter of configuration alone:

| Variable                  | Meaning                                                         |
| ------------------------- | --------------------------------------------------------------- |
| `RIPE_INFERENCE_DRIVER`   | `ollama` (default, fully local), `openai` (the hosted demo) or `fake` |
| `OLLAMA_URL`, `OLLAMA_MODEL` | the local Ollama server and model                            |
| `OPENAI_API_KEY`, `OPENAI_MODEL` | the OpenAI project key and model                          |
| `OPENAI_REASONING_EFFORT` | reasoning effort for reasoning models (default `low`)            |
| `OPENAI_SERVICE_TIER`     | optional service tier, such as `flex`                           |
| `RIPE_PACE`               | pause between the agent's automatic runs: `calm` (30 s), `steady` (6 s, default), `brisk` (1 s) or `instant` |
| `RIPE_MODE`               | `local` (settings editable from the interface) or `public` (configuration from the environment only) |
| `RIPE_GATEWAY_SECRET`     | the secret shared with the site; API requests without it are refused |
| `RIPE_VISITOR_COOKIE`, `RIPE_VISITOR_COOKIE_SECURE` | the visitor cookie: `__Host-ripe_visitor` and `Secure` by default |
| `RIPE_ALLOWED_ORIGINS`    | origins allowed to send changes besides the API's own, comma separated; Reverb accepts connections from the same hosts |
| `BROADCAST_CONNECTION`    | `reverb` to follow changes as they happen, `null` to rely on polling alone |
| `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET` | the Reverb application; the key is public, and the secret is derived from `APP_KEY` when left empty |
| `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME` | where the API publishes to Reverb |

`php artisan test --group=live` runs the agent end to end against the live models that are available: local Ollama, and OpenAI when a key and a model are set.

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
  Orchestration/  the reconciler, leases, retries, the executor and live text
  Inference/      the OpenAI, Ollama and fake drivers on a supervised transport
  Agent/          prompts, schemas and structured output
  Http/           controllers, form requests, resources, the visitor cookie and the gateway check
  Realtime/       signals after commit, the relay to Reverb and its pause
  Broadcasting/   who may join each private channel
  Actions/        what visitors can do to tasks, questions and pieces
  Journal/        the audit of the task journal against the state
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

The agent writes in the same language as your request.

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
| `npm run test:e2e`     | Plants, ripens, opens and edits a task, and follows the greenhouse through a Reverb outage, through the whole stack (Playwright) |
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

Backend: Laravel 13 · PHP 8.4 · PostgreSQL 17 · OpenAI and Ollama · Scramble · Pest · Pint · Larastan

## License

[MIT](LICENSE)
