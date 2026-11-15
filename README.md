# Ripe

**Tasks that ripen while you procrastinate.**

You write down something you keep putting off — "reply to the difficult client email", "plan the December trip" — and leave it for later. From then on, an AI agent running **locally on Ollama** works on it in small steps, saving every result. When you come back, you find a plan, a draft, a checklist or a first version — and a clear list of what still depends on you.

Built for the Hacktoberfest Open-Source AI Challenge, week 3: _Slow and Steady_.

## The flow

**Create → Put off → Ripen → Return → Review**

1. **Create** — one sentence in plain language. No forms.
2. **Put off** — say when you plan to come back ("tomorrow", "next week"). The agent starts.
3. **Ripen** — the agent interprets the request, splits it into steps, writes one piece per step and finally reviews the whole set.
4. **Return** — the task page shows what's done, what's happening right now, what's left and what the agent needs to know from you.
5. **Review** — every piece is editable, copyable, downloadable as `.md` and can be rewritten on request ("shorter", "more formal").

## The greenhouse

Ripe is drawn as a raised bed in a Victorian glasshouse, seen **in cross-section**: plants above the soil, roots below — and running through the soil, a glowing **mycelium network**. The mycelium is the agent: a local, open-weight model that feeds whichever plant it is working on. You can see a pulse of light travel from the mushrooms at the edge of the bed to that plant's roots. When Ollama is off, the network goes grey.

Every change in a plant comes from **persisted work**, never from the passage of time:

| What the agent finished              | What happens to the plant                                   |
| ------------------------------------ | ----------------------------------------------------------- |
| Interpreted the request              | The seed germinates: a taproot and two seed leaves          |
| Mapped out the plan                  | One lateral root and one bud on the stem per step           |
| Finished a step                      | That bud opens into a leafy branch you can click to read    |
| Finished the first piece             | The plant blossoms                                          |
| Finished most of the work            | Fruit sets and starts to colour                             |
| Reviewed everything                  | The fruit ripens and a paper review tag is tied to the stem |

A bud that needs an answer from you dries out and shows a blue drop. The species (persimmon, plum, lemon, fig — each with its own leaves, flowers and fruit) only appears once the agent understands what kind of result you want. The number of plants shapes the bed: a single task takes the whole stage; many tasks spread into terraces. The light through the glass follows your local time, from dawn to a night where the mycelium glows.

New tasks are sown from a **seed packet** stuck in the soil. Harvested tasks are preserved as jars in **the pantry**. Opening a plant shows its **specimen sheet**: a field journal spread with the plant drawn as a botanical plate — numbered parts keyed to the work they hold — next to the field notes, the live writing, your open questions and the reading room.

## Repository

Ripe is moving to a Laravel backend and a Next.js frontend. The migration happens in stages described in [docs/migration/plan.md](docs/migration/plan.md); until the frontend cut-over, the complete application, agent included, still runs from `frontend/`.

```
frontend/   the Next.js application: the greenhouse, the agent and its SQLite database
backend/    the Laravel 13 backend that will take over the agent, the queue and the API
docs/       the migration plan and the screenshots
compose.yaml  PostgreSQL 17 for backend development
```

## Running it

Requirements: Node 20.9+ and [Ollama](https://ollama.com).

```bash
ollama pull gemma4:e4b
ollama serve
```

```bash
cd frontend
npm install
npm run setup
npm run dev
```

Open http://localhost:3000. The model, the Ollama address and the agent's pace can be changed from the interface (click the mushrooms, or the mycelium chip at the top). Add `?sky=night` (or `dawn`, `dusk`) to the URL to preview another time of day. Any model installed in Ollama works; models with good structured JSON output give the best results.

To see a ripe task without waiting, click "open a ripe example" on the empty bed or run `npm run db:seed`. The example is marked as **illustrative** in the interface and is never processed by the agent.

### Environment variables (optional)

See `frontend/.env.example`.

| Variable       | Default                  |
| -------------- | ------------------------ |
| `DATABASE_URL` | `file:./prisma/ripe.db`  |
| `OLLAMA_URL`   | `http://127.0.0.1:11434` |
| `OLLAMA_MODEL` | `gemma4:e4b`             |

### Backend

Requirements: PHP 8.4, Composer and Docker.

```bash
docker compose up -d --wait postgres
cd backend
composer setup
composer check
```

`composer check` runs Pint, Larastan and the Pest suite against PostgreSQL. Tests are written only when they are indispensable; see [docs/testing.md](docs/testing.md) before adding one. `composer dev` starts the backend's development processes: the HTTP server on port 8000, the agent's queue worker and the scheduler, which runs `ripe:reconcile` every minute.

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

`php artisan test --group=live` runs the agent end to end against the live models that are available: local Ollama, and OpenAI when a key and a model are set.

The API lives under `/api`. The frontend does not use it yet; it switches over in stage 5.

| Variable                  | Meaning                                                         |
| ------------------------- | --------------------------------------------------------------- |
| `RIPE_MODE`               | `local` (settings editable from the interface) or `public` (configuration from the environment only) |
| `RIPE_VISITOR_COOKIE`, `RIPE_VISITOR_COOKIE_SECURE` | the visitor cookie: `__Host-ripe_visitor` and `Secure` by default; plain `ripe_visitor` over http for local development |
| `RIPE_ALLOWED_ORIGINS`    | origins allowed to send changes besides the API's own, comma separated |

The OpenAPI document is [`docs/api/openapi.json`](docs/api/openapi.json). After changing the API, run `composer openapi` in `backend/` (against a migrated database) and `npm run api:types` in `frontend/`. The test suite and CI fail when either is out of date.

## Architecture

```
frontend/src/
  agent/        the agent: Ollama client, prompts, schemas, queue, worker
  app/          Next.js routes (pages and API)
  components/
    flora/      procedural botanical illustration: species, anatomy, specimen
    greenhouse/ the bed: glasshouse, soil and mycelium, seed packet, pantry
    specimen/   the specimen sheet: plate key, growth series, status board
    task/       field notes: live writing, questions, reading room, journal
  hooks/        polling, time-of-day sky, live text animation
  lib/          domain: types, growth, queries, operations
frontend/prisma/  schema, migrations and the example seed
```

### The agent

- **Separate phases** — each task goes through distinct jobs: `interpret` (understand the request, point out what's missing), `plan` (split into 3–5 steps), `generate` (one piece per step), `review` (a critical review of the whole set) and `revise` (a rewrite you asked for).
- **Persistent queue** — every job lives in SQLite with an explicit state: `queued → running → done | failed | cancelled`. The worker runs inside the Next server itself (started from `instrumentation.ts`), takes one job at a time and saves the result before moving on.
- **Recoverable** — running jobs hold a lease. If the process dies mid-step, the job goes back to the queue on restart without losing anything already saved, and the journal records the resumption.
- **Honest** — the interface reads the real state from the database: the text you see being written is the model's streamed output, saved every ~700 ms. Nothing is simulated.
- **No making things up** — the agent marks missing information as `[[TBD: ...]]`, rendered as highlights in the text. Essential questions only hold back the final version; earlier steps move forward with markers.
- **Limits** — one step at a time; up to 5 steps per task; 3 attempts per step with growing back-off; 4 minutes per call; 24 runs per task; a configurable rest between steps (the "pace").
- **Ollama down** — the worker checks Ollama's health before taking a job. If it goes down, nothing is consumed: the job waits and the work continues on its own when Ollama is back.

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
| `npm run dev`          | Development server + agent                   |
| `npm run build`        | Production build                             |
| `npm run setup`        | Generates the Prisma client, runs migrations |
| `npm run db:seed`      | Creates the example task                     |
| `npm run db:reset`     | Drops and recreates the database             |
| `npm run typecheck`    | Generates route types and type-checks        |
| `npm run api:types`    | Generates the API types from the OpenAPI document |
| `npm run lint`         | ESLint                                       |
| `npm run format`       | Prettier                                     |
| `npm run format:check` | Prettier, without writing                    |

Backend, from `backend/`:

| Script             | What it does                                  |
| ------------------ | --------------------------------------------- |
| `composer setup`   | Installs dependencies, creates `.env`, migrates |
| `composer dev`     | Development processes                         |
| `composer lint`    | Pint, without writing                         |
| `composer format`  | Pint                                          |
| `composer analyse` | Larastan at the maximum level                 |
| `composer test`    | Pest                                          |
| `composer check`   | All of the above checks                       |
| `composer openapi` | Exports the OpenAPI document                  |

## Stack

Frontend: Next.js 16 (App Router, view transitions) · React 19 · TypeScript · Tailwind CSS 4 · Motion · Radix UI · Prisma 7 + SQLite · Zod · Ollama

Backend: Laravel 13 · PHP 8.4 · PostgreSQL 17 · Pest · Pint · Larastan

## License

[MIT](LICENSE)
