# Stage 4 report: API, visitors and policies

Branch `migration/stage-4-api`, 2026-11-15.

## What changed

```
backend/app/
├── Http/
│   ├── Controllers/Api/        garden, tasks, agent and examples; one controller per action
│   │   ├── Tasks/              defer, resume, pause, harvest, replant, refresh
│   │   ├── Questions/          answer, skip, reopen
│   │   └── Artifacts/          draft (save, discard), keep the draft, restore, revise
│   ├── Requests/               one form request per input
│   ├── Resources/              task summary and detail, activity, agent status
│   ├── Middleware/EnsureSameOrigin
│   └── Visitors/               cookie and resolver behind the `visitor` guard
├── Policies/                   task, question and artifact policies
├── Snapshots/                  loading, task state, staleness and field notes
├── Actions/Artifacts/          save, keep and discard drafts; restore a version
├── Actions/Agent/              update the local agent settings
├── Actions/Tasks/              rename and delete join the existing actions
├── Orchestration/Output/       live text per attempt
├── Orchestration/              agent status, preferences and worker heartbeat
└── Examples/                   the community garden, grown through the real pipeline
backend/routes/api.php          22 routes for the 20 operations
docs/api/openapi.json           the OpenAPI document
frontend/src/generated/api.ts   TypeScript types generated from it
```

Migrations:
- `agent_settings`: a single row with the local pace, pause and Ollama model.
- `artifact_drafts`: one editable draft per piece, tied to the version it started from.
- `agent_runs.deferred_reason` accepts `paused`.

Packages: `dedoc/scramble` 0.13 in the backend (supports Laravel 13) and `openapi-typescript` 7.13 in the frontend, a development dependency.

## The 20 operations

| Next today | Laravel |
|---|---|
| `GET /api/garden` | `GET /api/garden`: the visitor's tasks and the agent status |
| `POST /api/tasks` | `POST /api/tasks`: creates the visitor and the cookie with the first seed |
| `GET /api/tasks/:id` | `GET /api/tasks/{task}` |
| `PATCH tasks`: `defer`, `resume`, `pause`, `harvest`, `replant`, `refresh` | `POST /api/tasks/{task}/{action}` |
| `PATCH tasks`: `rename` | `PATCH /api/tasks/{task}` |
| `DELETE tasks` | `DELETE /api/tasks/{task}` |
| `PATCH questions`: `answer`, `skip`, `reopen` | `POST /api/questions/{question}/{action}` |
| `PATCH artifacts`: `edit` | `PUT /api/artifacts/{artifact}/draft`, `POST …/draft/keep`, `DELETE …/draft` |
| `PATCH artifacts`: `restore`, `revise` | `POST /api/artifacts/{artifact}/restore`, `POST …/revise` |
| `GET` and `PUT /api/agent` | the same |
| `POST /api/examples` | the same |

Mutations answer with the task snapshot, and creation with 201. Deleting a task or discarding a draft answers 204.

## How the decisions were implemented

**Visitors.**
- A `visitor` guard resolves the visitor from the cookie, and the database stores only the token's SHA-256 hash.
- The token is 256 random bits, written as 64 hex characters. Anything else is ignored without a query.
- The visitor is created with the first seed, in the same transaction as the task, so a rejected seed leaves no visitor behind.
- `last_seen_at` is refreshed at most once an hour, with a conditional `UPDATE`.

**Cookie attributes.**
- The cookie is `HttpOnly`, `SameSite=Lax`, `Path=/`, has no `Domain`, and lasts 400 days (the browser cap).
- By default it is `__Host-ripe_visitor` and `Secure`. Local development over http uses `ripe_visitor` without `Secure`.
- Issuing the cookie fails loudly if:
  - a `__Host-` or `__Secure-` name lacks `Secure`, which browsers would drop;
  - production runs without `Secure`;
  - the name is not a valid cookie name.

**Same origin (adjustment 2).** `EnsureSameOrigin` runs on every unsafe method, before the rate limit, so forged requests cannot spend a visitor's quota.

| `Sec-Fetch-Site` | `Origin` | Result |
|---|---|---|
| `same-origin` | absent, allowed or the API's own | accepted |
| `same-origin` | anything else | 403 |
| `same-site` | allowed | accepted |
| `same-site` | absent or anything else | 403 |
| `cross-site` or `none` | anything | 403 |
| absent | allowed or the API's own | accepted |
| absent | absent, `null` or anything else | 403 |

- No CSRF token is used: `SameSite=Lax` plus this check covers it.
- CORS is off (`config/cors.php` has no paths), because Ripe is served from a single origin.

**Policies before validation.**
- Policies run as route middleware (`can:`), after model binding and before form validation.
- Another visitor's resource therefore always answers 404, even with an invalid body. A 422 would reveal that the id exists.
- Examples are visible to everyone; changing one answers 403.
- Route parameters must be UUIDs, so a malformed id answers 404 instead of reaching PostgreSQL.

**Drafts (adjustment 1).** Versions are never modified.
- `PUT …/draft` writes the visitor's working copy to `artifact_drafts`. Autosave creates no version, no journal event and no new task version.
- `POST …/draft/keep` consolidates the draft into a new immutable `user_edit` version, through the same `addVersion` path as everything else.
- Every draft call carries `baseVersionId` and the draft revision it last saw:
  - a stale base answers 409 because the piece changed underneath;
  - a stale revision answers 409 because another tab saved since.

  The response carries `currentVersionId` and `draftRevision`, so the client can rebase.
- The agent never reads drafts.
- A visitor revision first consolidates a pending draft, so the agent rewrites what the visitor sees. A draft the agent has since overtaken must be rebased or discarded first.
- Restoring creates a `restore` version and refuses to run over pending edits.

**Live text (adjustment 3).**
- The executor gives the driver a writer that stores the partial text under the attempt's own cache key, with a ten-minute time to live. Only prose runs (generate and revise) have one.
- A per-run pointer holds the newest attempt number. An older attempt's writes are refused, and it stops writing.
- The snapshot only shows text for the attempt that holds the run's current lease, checked against the database.
- The key is removed when the attempt ends. A dead worker's text expires on its own.

**Snapshots.**
- Responses keep the current camelCase contract, adapted to the new domain:
  - leaf and step phases (`blocked`, `ready`, `working`, `done`), with `working` derived from the running generate run;
  - `waitingFor` (`step` or `answer`);
  - dependencies and requirements, and question kinds and answer revisions;
  - for each piece: its versions, its draft and whether it is stale (derived from provenance, never stored).
- Field notes are written from the journal, newest first, and the snapshot `version` is ready for stage 6.

**Agent settings and status.**
- In `public` mode the settings are locked (403).
- In `local` mode the operator can change:
  - the pace;
  - the pause, which defers claims and, when lifted, wakes paused runs at once;
  - the Ollama model, which must be installed. A long-running worker switches models without a restart, because the inference manager rebuilds the driver when the preferred model changes.
- `baseUrl` is prohibited by validation, which closes the SSRF defect.
- The status never names another visitor's task. It only says the agent works `elsewhere`.
- Operator messages (halt reasons, Ollama addresses) are shown only in local mode.
- `stopped` comes from a worker heartbeat on the agent queue's `Looping` event.
- Driver health is cached for ten seconds.

**Examples.**
- `ExampleGarden` plants the community garden prompt for a throwaway visitor and runs it through the real executor with a scripted model (the original example's content). It then skips one question, marks the task as an example and deletes the visitor.
- The result has real runs, attempts, versions and a consistent journal.
- A cache lock makes it idempotent under concurrent requests.

**Rate limits.** The limits are named Laravel rate limiters, keyed by visitor or by an HMAC of the IP. Stage 7 tunes the values.

| Limiter | Default |
|---|---|
| plant | 10 per hour per visitor and 30 per hour per network |
| changes | 60 per minute |
| reads | 120 per minute |

**OpenAPI.**
- Scramble infers the document from routes, form requests and resources: 22 operations, typed resources and enums.
- `openapi-typescript` turns it into `frontend/src/generated/api.ts`.
- A Pest test exports the document and compares it byte for byte with the committed file, and also checks that every API route is documented.
- CI regenerates the TypeScript types and fails on any difference.

## Verification

| Check | Result |
|---|---|
| Pint, Larastan at the maximum level | pass |
| Default suite | 619 tests: Unit 104, Feature 409, Inference 95, Integration 11 |
| Frontend | Prettier, ESLint and TypeScript pass with the generated types |
| Smoke test over real HTTP (`artisan serve`) | a cross-site seed is refused; a same-origin seed returns `Set-Cookie: __Host-ripe_visitor=…; secure; httponly; samesite=lax`; the cookie opens the garden; no CORS headers |

The stage's own suites (128 tests):

| Suite | What it proves |
|---|---|
| Parity (17) | each of the 20 operations, its response shape and its effect, with the journal audited |
| Isolation (38) | every resource route with another visitor's cookie, with an invalid body and with no cookie answers 404 and changes nothing; the example is readable by all and writable by none; unknown, malformed and injected ids answer 404 |
| Same origin (18) | every row of the table above, reads without fetch metadata, the refusal message, the check running before the rate limit, no CORS |
| Visitor identity (11) | cookie attributes and expiry, hash-only storage, reuse, unknown and malformed tokens, hourly `last_seen_at`, refused cookie settings |
| Drafts (11) and draft conflict (1) | autosave leaves versions untouched; keeping creates one version; two tabs; a draft overtaken by an agent finalisation, rebased and kept; restore; revise consolidates; two processes racing to keep the same draft produce exactly one version |
| Rate limits (4) | per visitor, per network, per minute for changes and reads, with reset |
| Errors (5) | 422 by field, 409 for state and version conflicts, no internals with debug off, `baseUrl` refused |
| Agent (5) and settings (6) | another visitor's work stays hidden; local and public modes; pause and wake-up; the model switch in a long-running worker; heartbeat |
| Live text (6) | text of the running attempt, none for structured runs, cleared at the end, an older attempt fenced off, expiry, a superseded writer stops |
| Examples (3) | grown through the pipeline, read-only, idempotent, nothing left for the queue |
| Deleting during a run (1) | the worker stops quietly and nothing is left |
| OpenAPI (2) | the document matches the code and covers every route |

## Problems found and fixed

1. **The test client sent no cookies.** Laravel's test client drops cookies on JSON requests unless `withCredentials()` is used. The guard also kept a visitor resolved by an earlier request in the same test. Both made isolation tests pass for the wrong reason. The API helper now sends the cookie and resets the guards before every request.
2. **Objects in the cache.** The database cache store only unserializes allowed classes, so the cached driver health came back as `__PHP_Incomplete_Class` and the garden answered 500 without a cookie. The array store used in tests does not serialize, so only the smoke test caught it. Health is now cached as plain data, and the test array store serializes like the real one.
3. **Existence leak.** Validation ran before authorization, so another visitor's id with an invalid body answered 422 instead of 404. Policies now run as route middleware.
4. **CORS.** Laravel's default sent `Access-Control-Allow-Origin: *`. CORS is now off.
5. **`latestOfMany` on UUID keys.** It aggregates `max(id)`, which PostgreSQL does not define for UUIDs. The latest attempt is now an ordered `hasOne`.
6. **Lazy loading in `owningTask()`.** It loaded the task lazily, which strict mode forbids for models loaded in a collection. It now queries the task explicitly when the relation is missing.
7. **The executor and deleted tasks.** It now ends quietly when the task disappears during a call, instead of failing the job.
8. **Field note order.** The `events` relation already orders by sequence, so the newest-first order needed `reorder()`.
9. **Architecture preset.** Controllers may only have resourceful methods, so each action got its own invokable controller.
10. **A timing-sensitive stage 3 test.** It compared `updated_at` with a delay computed a moment earlier, and failed once at 6999.7 ms. It now freezes the clock.

## Pending

- **Frontend cut-over (stage 5).** Next still uses its own API. Its `api()` helper will read Laravel's `message` and `errors`, and the editor will move to draft, keep and rebase.
- **409 bodies in OpenAPI.** Scramble cannot see domain exceptions rendered by the handler, so conflict bodies (`message`, `currentVersionId`, `draftRevision`) are described here, not in the document.
- **Behind a proxy (stage 9).** Trusted proxies must be configured so that the per-network limit and the own-origin check see the client's address and the public host.
- **Limits, budget and Turnstile (stage 7).** These values are defaults to tune once the hosted model and budget are chosen.
- **Examples (stage 7).** The community garden is grown with a scripted model and labelled as illustrative until examples are recorded from real runs.
