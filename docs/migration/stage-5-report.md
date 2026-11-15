# Stage 5 report: frontend cut-over

Branch `migration/stage-5-frontend`, 2026-11-15.

## What changed

**Removed from the frontend:** Prisma and SQLite (`prisma/`, `prisma.config.ts`, the generated client), the agent (`src/agent`), the API routes (`src/app/api`), the worker started from `instrumentation.ts`, the server-side queries and operations, and the dependencies and scripts that served them (`@prisma/*`, `better-sqlite3`, `zod`, `tsx`, `setup`, `db:seed`, `db:reset`). The frontend now holds no domain state and runs no jobs.

```
frontend/src/
├── proxy.ts               the gateway: the site's /api forwarded to Laravel
├── lib/gateway.ts         the headers both paths sign: secret, client address, cookie
├── lib/server/api.ts      the API client for server rendering
├── lib/api.ts             the browser client, reading Laravel's error envelope
├── lib/types.ts           aliases over the types generated from OpenAPI
├── hooks/useDraft.ts      autosave and consolidation of a piece's draft
└── app/, components/      the pages and components on the new contract
frontend/e2e/              one Playwright scenario through the whole stack
backend/app/Http/Middleware/ThroughGateway.php
```

## Routing for development and production (adjustment 1)

The production target is now Vercel for the site and Railway for the API, the agent worker, the scheduler, Reverb and PostgreSQL. The same path runs locally.

```
browser ── /api/* ──▶ Next proxy.ts ──▶ Laravel /api
                        removes any forwarding or gateway header the client sent
                        adds X-Ripe-Gateway (shared secret) and X-Ripe-Client-Ip
server render ──────▶ Laravel /api with the same signature and the visitor's cookie
```

- **First-party cookies:**
  - The browser only talks to the site's origin. The visitor cookie Laravel sets passes back through the proxy and belongs to the site's host, so it keeps the `__Host-` prefix in production.
  - CORS stays off.
- **Server rendering:** `serverApi()` reads the incoming cookie and calls Laravel directly with the same signature. Requests are deduplicated per render with React's `cache`, and they are never cached across requests.
- **Visitor isolation:** nothing about the visitor lives in Next. Every read and change goes to Laravel with the visitor's own cookie, and the policies from stage 4 decide.
- **Client addresses:**
  - Laravel never reads `X-Forwarded-For` (no trusted proxies). It uses `X-Ripe-Client-Ip` only when the request carries the gateway secret.
  - The gateway fills that header only on Vercel, from `x-vercel-forwarded-for`, which Vercel overwrites to prevent spoofing. Locally the address is the one of the connection.
  - A client that calls Laravel directly, or forges the header, is refused without the secret.
- **One test** covers the gateway: requests without the secret, or with a wrong one, are refused; the per-network limit follows the address the gateway reports; a forged `X-Forwarded-For` is ignored.

The proxy is the production routing, so stage 9 does not replace it. Stage 9 sets the secret on both platforms.

## Contract

- The frontend's types are aliases over `src/generated/api.ts`, generated from the OpenAPI document. There are no hand-written domain types left.
- Writing the frontend exposed fields the document did not type precisely: field notes, `context`, `assumptions`, step dependencies, the task number and the run budget. They are now precise, and the generated types have no `unknown` left in responses.
- The components follow the new names:
  - step phases;
  - question kinds;
  - piece versions and drafts;
  - `activity.live`;
  - agent modes, including `halted` and `elsewhere`.
- Mutations use one endpoint per action, and errors use Laravel's `message` and `errors`.
- The agent settings dialog only exists in local mode, and the model's address is no longer editable from the interface.
- Copy that depended on the old architecture now depends on the mode: the mycelium is "an open model running on this machine" only with local Ollama.

## Drafts in the editor (adjustment 2)

`useDraft` separates autosave from consolidation:

- **Autosave:**
  - 800 ms after typing stops, the text is written to the draft (`PUT …/draft`) with the base version and the draft revision.
  - Saves go through a queue, one at a time, so revisions stay in order.
  - When the page is hidden, the last text is saved with `keepalive`.
- **Consolidation:**
  - A new version is created (`POST …/draft/keep`) when the visitor leaves editing, switches piece, asks for a rewrite or leaves the page, or after 30 s without typing.
  - Every edit restarts the 30 s timer.
  - Consolidation first flushes the pending autosave and waits for the queue, so the last autosave always finishes before the version is created.
- **Conflicts:**
  - A 409 stops autosave and consolidation and keeps the draft on the server.
  - The editor says what happened: the agent wrote a new version, or another tab saved.
  - The visitor chooses between keeping their edits (rebased onto the new version, then kept) and taking the other text.
- **Immutability:** versions are never changed. Every outcome is a new version.

## The plant

- **The step being written** is drawn as the same bud as a step ready to start, with a slow pulse (`bud-pulse`), switched off under reduced motion. It no longer grows a twig on a timer. A branch only appears once the piece is saved, and growth comes from the API.
- **Waiting on the fruit:** when every step is written but the review waits for an essential answer, the young fruit carries a paper tag, "needs you", tied to its stalk. Clicking it leads to the questions.

## Verification

| Check | Result |
|---|---|
| Backend: Pint, Larastan at the maximum level, Pest | pass; 345 tests |
| Frontend: Prettier, ESLint, TypeScript, production build | pass; the build lists the proxy |
| End to end (Playwright, Chromium) | 1 scenario in about 18 s: an empty greenhouse, a task planted from the seed packet, ripened by the agent worker with the simulated model, the specimen sheet opened, a piece edited, the draft persisted on the server, kept as a version when leaving editing, still there after a reload |
| Browser, simulated model | the full flow; autosave sends one request per pause; leaving the page keeps the draft in order (`PUT` before `POST …/keep`); a rewrite requested from another tab produces the conflict notice, and "keep my edits" leaves versions `generate → user_edit → revise → user_edit` |
| Browser, local Ollama (`gemma4:e4b`) | the four `docs/screens` scenarios, below |

### The four scenarios with local Ollama

Three tasks planted from the interface, close to the ones in the screenshots: a reply to a client whose order arrived late, a code review process and a trip to Lisbon.

- **Greenhouse at night:** three plants under the night sky, one needing an answer with dry buds and blue drops, one writing a step while the mycelium feeds it, one in the queue, and the pantry below.
- **Question:** the specimen sheet of the reply stopped at "Answer below to keep it growing", with the essential question marked. Answering it from the interface sent the steps that needed it back to work, and the reply ripened. All three tasks ripened with the local model.
- **Specimen sheet:** the code review ripened as a persimmon at "fig. 6 · Ripe": a ripe fruit with its glow, the review tag, numbered branches, "Agent's work: Finished", three optional answers, four pieces to read and "Your turn".
- **Reading room:** with a piece open, the plant stays fixed beside the reader, the pieces sit in tabs with the agent's review last, and the text is on ruled paper with the gaps the agent marked.
- **The waiting tag:** the model never stopped on this exact state on its own, so the ripe task was put into it by hand in the local database, then restored: every step written, the review waiting on an essential answer. The young fruit carried the "needs you" tag, the status board said "1 answer needed", and clicking the tag led to the questions.

Observations that do not block this stage:

- `gemma4:e4b` sometimes names steps like identifiers (`Review_Criteria`). The title is stored as the model wrote it.
- Expected rejections (a version conflict, a task already ripe) are written to the log at error level with a stack trace. Stage 8 owns the structured logs and should report them at a lower level.

## Problems found and fixed

1. **A path no test reached.** Every test interpretation had empty context and assumptions, so the code that maps them never ran under test. While those fields were being typed for OpenAPI, a `Collection::filter(is_string(...))` threw on the first real task in the browser: a collection passes the key as well, and `is_string` refuses a second argument. The suite had stayed green. The test fixture now carries a fact and an assumption, so every test that reads a task covers that path.
2. **Imprecise types in the OpenAPI document.** Scramble does not infer through `app()` or `array_map`, so some fields came out as `string` or `unknown[]`. The resource now uses typed calls, and the generated types are exact.
3. **Generic calls TypeScript cannot check.** The seed packet still expected `{ id }` from planting, a mismatch an explicit generic hides. Every call was reviewed against the new contract.
4. **End-to-end setup.** The worker's pid file needed its folder created first. The first failed run left a worker behind, which was stopped by hand; teardown now stops it.
5. **A hidden browser pane** does not run `requestAnimationFrame`, so the time of day only applied after the hook's one-minute fallback. This is not an app bug.

## Pending

- **Stage 6:** realtime with Reverb. On Railway, the WebSocket connection goes to Reverb's own host, and `/broadcasting/auth` goes through the gateway like any other API call.
- **Stage 8:** expected domain rejections reported below error level.
- **Stage 9:** the gateway secret and `RIPE_ALLOWED_ORIGINS` on Vercel and Railway, the cookie as `__Host-` over https, and a check that `x-vercel-forwarded-for` reaches the proxy as documented.
