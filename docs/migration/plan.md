# Ripe migration plan

Approved on 2026-11-15. Ripe moves to a Laravel 13 + PostgreSQL 17 backend and a Next.js 16 + React 19 frontend.

## 1. Goal and non-negotiables

- The Laravel backend is the source of truth for every domain state: tasks, steps, questions, artifacts, runs, budgets and history. The frontend renders that state and never runs jobs, drives the agent or decides growth.
- The botanical experience, the visual language and the ripening metaphor stay as they are.
- **Growth invariant:** no change in a plant happens without a corresponding persisted result. Timers, animations and transient model tokens never create progress.
- Laravel-native tools are used where they fit: Eloquent, migrations, transactions, queues, events, broadcasting, scheduler, policies, the service container and Pest.
- No Redis, no microservices and no infrastructure added for its own sake. PostgreSQL holds the domain, the queue (database driver) and the cache (database store).

### Conventions

- All code in English. No explanatory comments; PHPDoc appears only where static analysis needs type information it cannot infer (generics, array shapes).
- One branch and one pull request per stage, validated before the next stage starts.
- Conventional commit messages, without AI attribution.
- Only indispensable tests: each one protects a guarantee nothing else protects ([docs/testing.md](../testing.md)).

## 2. Assessment of the current implementation

**Kept as is (moved to `frontend/`):** the procedural flora (`components/flora`), the greenhouse, the specimen sheet, the task panels, `globals.css`, `useSky`, `useTypewriter`, `format.ts` and the interface copy. `presence.ts` and `labels.ts` are adapted to the new states.

**Ported to PHP, keeping the content:**
- The prompts (system prompt, output guide, five message builders), almost verbatim.
- The interpret, plan and review schemas, the tolerant JSON extraction, `cleanMarkdown`, the 15-word minimum and the `[[TBD: ...]]` markers.
- The limits: 5 steps, 4 questions, 24 runs per task, 3 attempts, `10s·3ⁿ` back-off, 4 minutes per call and `num_predict` per kind.
- The growth thresholds and maturity weights, and the deliverable-to-fruit mapping.
- The Ollama details: `think: false` for thinking models, `keep_alive`, `num_ctx`, health through `/api/tags`, the `completion` capability filter and `:latest` matching.
- The community garden example.

**Replaced:**
- The worker living inside the Next process, the imperative `advance()`, Prisma and SQLite.
- JSON stored in string columns, the single `previousContent`, free-text events, polling as the only channel, and global settings editable from the interface.

**Defects the migration must fix:**

1. `plan()` and `interpret()` commit their results in one transaction and the job is marked done in a separate write. A crash between the two re-runs the job and duplicates steps and questions.
2. `recover()` gives the attempt back on every interruption, so a job that crashes the process deterministically loops forever.
3. Results are written without checking that the worker still holds the lease.
4. Cancellation reaches a running job only through in-memory signals in the same process.
5. `logEvent` runs outside the transaction and swallows errors, so the journal can diverge from the state.
6. `needs` stores question ids in a JSON string without referential integrity, and statuses are unconstrained strings.
7. Only one previous artifact version survives, so a rewrite after an edit loses the agent's earlier text.
8. `PUT /api/agent` accepts any `baseUrl` (SSRF), and the agent status exposes the running task's title to everyone.
9. A `working` step grows its twig to 42% of its length before any result exists.
10. The pace is a global worker sleep, so a resting worker holds back every other task.

## 3. Target architecture

### 3.1 Processes

```
backend/  (Laravel 13, PHP 8.4)
  HTTP (php-fpm or artisan serve)   API and broadcasting auth
  queue:work --queue=agent          model calls, one run per worker
  queue:work --queue=default        broadcasts and light work
  schedule:work                     reconcile, prune
  reverb:start                      WebSocket server
frontend/ (Next.js 16, React 19)    renders API snapshots, Echo + polling fallback
PostgreSQL 17                       domain + jobs + cache
```

The database queue lives in the same PostgreSQL as the domain. As a result, dispatching a job inside a domain transaction is atomic with the state change: the `jobs` row commits or rolls back with it. In production a single origin (`ripe.marianacastro.dev`) routes `/api`, `/broadcasting` and the WebSocket path to Laravel and Reverb, and everything else to Next. Cookies are first-party and no CORS is needed.

### 3.2 Entities

| Table | Notes |
|---|---|
| `visitors` | `token_hash` (SHA-256 of the cookie token, unique), `last_seen_at`; authenticatable through a custom guard |
| `tasks` | `visitor_id` (null only for examples, enforced by CHECK), `status`, `language`, `context` and `assumptions` as `jsonb`, milestone timestamps, `version` |
| `steps` | `unique(task_id, position)`, `status ∈ {blocked, ready, done}` |
| `step_dependencies` | step → earlier step it builds on |
| `step_requirements` | step → essential question it cannot be written without |
| `questions` | `unique(task_id, position)`, `kind ∈ {optional, essential}`, `status`, `answer`, `answer_revision` |
| `artifacts` | `step_id` unique, partial `unique(task_id) WHERE kind = 'review'`, `current_version_id` |
| `artifact_versions` | immutable; `unique(artifact_id, number)`, `unique(run_id)`, `source`, `parent_version_id`, `inputs` (`jsonb` provenance), `content_hash` |
| `agent_runs` | `kind ∈ {interpret, plan, generate, revise, review}`, `status`, `available_at`, `deferred_reason`, `lease_token`, `lease_expires_at`, `cancel_requested_at`, `attempt_count`, `interruption_count`; partial `unique(task_id) WHERE status = 'running'` |
| `run_attempts` | one row per execution: worker, token, timings, TTFT, `outcome`, error; input, cached, output and reasoning tokens, whether usage was reported or estimated, cost, and the provider's request and response ids |
| `task_events` | append-only audit log, `unique(task_id, sequence)` (section 6) |
| `inference_budget_days` | `limit`, `reserved`, `spent` in micro-dollars |

Primary keys are UUIDv7 (`HasUuids`). Statuses are backed PHP enums mirrored by CHECK constraints.

### 3.3 States

```
Task:     dormant ⇄ ripening ⇄ waiting → ripe → harvested ⇄ (ripe | dormant)
                     ↘ stalled ↗ (retry)
Step:     blocked ⇄ ready → done         (working is derived from the active run)
Question: open → answered | skipped       (reopen → open)
AgentRun: queued → running → succeeded | failed | cancelled
                   running → queued       (retry or interruption)
Attempt:  succeeded | failed | timed_out | invalid_output | driver_unavailable
          | rate_limited | cancelled | lease_lost | interrupted
```

Every transition goes through an explicit state machine and writes its audit event in the same transaction. Illegal transitions throw.

### 3.4 Orchestration

**The reconciler.** `TaskOrchestrator::reconcile(Task)` is idempotent and runs under the task's row lock. It derives the task status from the persisted state, creates the next runnable `AgentRun` and dispatches its job in the same transaction. It runs after every human action and every committed result, and the scheduler calls it every minute as a safety net. Lock order is always task, then run.

**Execution.** `ExecuteRun(runId)` claims the run, calls the model under lease supervision (section 4) and hands the result to a per-kind `RunResultCommitter`. In one transaction the committer:
- checks the lease;
- applies the result idempotently (milestone guards, `unique(run_id)` on versions);
- writes the audit events;
- compares growth before and after;
- finishes the run;
- calls `reconcile`.

**Delivery semantics.** Jobs are delivered at least once. A duplicate delivery is a no-op at claim time, and a late result is rejected at commit. Model calls may repeat; every attempt's tokens are accounted for.

**Two retry layers.** Laravel's `tries` and `retry_after` only cover queue redelivery. Retry policy, back-off and attempt limits belong to the domain.

**Future execution.** Pace, back-off, `Retry-After` and budget resets all use the same `available_at` column. The pace is per task.

### 3.5 Failure policy

Every inference error carries a scope, and the scope alone decides what the orchestrator does. The orchestrator never inspects provider-specific codes.

| Scope | Examples | Attempt consumed | Next |
|---|---|---|---|
| transient | connection errors, 5xx, overload, rate limits | no | rescheduled at the provider's retry hint or with capped back-off; the mycelium shows offline while it lasts |
| attempt | timeouts, stalled stream, invalid or truncated structured output | yes | `10s·3ⁿ⁻¹` until `max_attempts` |
| run | a request the provider rejects, a refusal, a content filter | run fails | task stalled with the reason, retry available |
| driver | invalid credentials, unknown model, exhausted credit or spend limit | no | the whole agent halts and the greenhouse rests until an operator acts; no task fails |

Other cases:

| Failure | Attempt consumed | Next |
|---|---|---|
| Budget exhausted before the call | no | next budget window; the greenhouse rests |
| Worker died (lease expired) | `interruption_count`, max 3 | immediately |
| Attempts exhausted | run fails | task stalled, retry available |
| Cancellation | no | run cancelled, domain untouched |

### 3.6 Inference contract

```php
interface ModelDriver
{
    public function name(): string;
    public function health(): DriverHealth;
    public function generate(ModelRequest $request, ?StreamObserver $observer = null): ModelResponse;
}
```

- `ModelRequest` carries the instructions and messages, an optional named JSON schema, a visible-output budget, an optional reasoning effort, an optional temperature and an opaque safety identifier.
- `ModelResponse` carries the text, the finish reason (`stop | length | content_filter | refusal | aborted`), usage, model, the provider's request and response ids, TTFT and duration.
- `Usage` carries input, cached input, cache-write, output and reasoning tokens, plus whether the numbers were reported by the provider or estimated. A stream aborted by Ripe never receives the provider's final usage, so its cost is estimated and flagged as such.
- Errors are typed exceptions that each declare a scope (section 3.5): `ModelUnavailable`, `RateLimited` (with the retry hint), `ModelTimeout`, `InvalidModelOutput`, `ModelRequestRejected`, `ModelRefused`, `DriverMisconfigured` and `ProviderQuotaExhausted`.

Selecting a driver is configuration only (`RIPE_INFERENCE_DRIVER=openai|ollama|fake`). The orchestrator depends on the contract and on error scopes, never on a provider.

**OpenAIDriver**, the provider of the hosted demo, uses the official Responses API (`POST /v1/responses`) with `stream: true`:

- **Events.** It reads `response.output_text.delta` and `response.refusal.delta` for content, and finishes on `response.completed`, `response.incomplete` (`incomplete_details.reason`: `max_output_tokens` or `content_filter`), `response.failed` (with the response's error code) or `error`. A stream that ends without one of these is an error.
- **Structured outputs.** `text.format = {type: "json_schema", name, schema, strict: true}`. A refusal is a `refusal` content part, never parsed as data. An incomplete structured response is rejected even when its JSON happens to parse.
- **Token limits.** `max_output_tokens` covers reasoning and visible tokens. Each run kind sets a visible budget plus a reasoning allowance that follows the configured `reasoning.effort`.
- **Errors.**
  - 429 rate limits (`rate_limit_error`, `slow_down`) and 503 `server_is_overloaded` are transient. `Retry-After` is a minimum, otherwise `x-ratelimit-reset-*`, always with jitter.
  - 429 billing codes (`credit_balance_exhausted`, `organization_spend_limit_exceeded`, `project_spend_limit_exceeded`, `organization_usage_limit_exceeded`) and 401/403 are driver scope, because retrying cannot fix them.
  - A rejected request or prompt is run scope.
- **Privacy.** `store: false`, because the default keeps responses for 30 days. `safety_identifier` is an HMAC of the visitor id, never the id or the cookie.
- **Cost.** Usage comes from the final event: `input_tokens`, `input_tokens_details.cached_tokens` and `cache_write_tokens`, `output_tokens` (reasoning included) and `output_tokens_details.reasoning_tokens`. It is priced from a per-model table in configuration, which records the date the prices were checked.
- **Per-model capabilities.** Parameters a model does not accept, such as sampling settings on some reasoning models, are declared per model in configuration instead of being discovered by failed requests.
- **Logging.** `x-request-id` and the response id are kept on the attempt.

**OllamaDriver**, for fully local use:
- NDJSON with the `format` schema;
- `think: false` for thinking models;
- usage from `prompt_eval_count` and `eval_count`, at zero cost.

**FakeDriver** is scriptable and deterministic.

**Not in stage 2:**
- *Background mode.* It has resumable streaming (`starting_after`) and would let a new attempt reattach to a generation started by a dead worker, instead of paying for it again. It depends on the project's data-retention settings and adds polling. Attempts keep the response id, so stage 7 can evaluate it without schema changes.
- *`service_tier: "flex"`.* It is billed at Batch API rates in exchange for slower answers and occasional unavailability, which suits Ripe's pace. That is why timeouts are configured per driver and per tier.
- *A generic OpenAI-compatible driver.* Other providers' compatibility layers differ in events, errors and usage, and supporting them generically would weaken the guarantees above.

**Structured output and language.** Each structured output has one schema definition, written in the subset both providers accept in strict mode:
- every object closed with `additionalProperties: false`;
- every property required, with optional values as nullable unions;
- no composition keywords.

A test walks every schema and rejects anything outside that subset. Stricter rules (non-empty strings, item limits) are enforced by the PHP validator after decoding. A shared `StructuredOutput` service extracts, validates and hydrates DTOs. The language detected during interpretation is stored on the task and stated in every later prompt.

### 3.7 Growth invariant

`GrowthCalculator` is a pure function of committed rows: milestones, step statuses and the existence of a review. The API returns `growth` and, separately, `activity`. Activity only drives transient signals such as glow and mycelium pulses, never geometry.

Dedicated tests:
- Failed, timed-out, cancelled and lease-lost attempts leave growth untouched and create no versions.
- Every `plant.stage_changed` event shares its attempt with a result event.
- `$this->travel(30)->days()` without results changes nothing.
- Seeded random FakeDriver scripts keep the stage monotonic and always equal to a fresh recomputation.

### 3.8 Realtime

- **Domain changes.** After commit, `TaskChanged {taskId, version}` goes out on private `task.{id}` and `greenhouse.{visitor}` channels. The client refetches the snapshot when the version is newer than its own and never applies domain deltas itself.
- **Transient output.** `RunOutputStreamed {attemptId, offset, delta}` is sent with `ShouldBroadcastNow`, throttled to about four per second. The partial text is kept in the cache store, outside domain tables, so the client can resynchronise when offsets do not match.
- **Fallback.** Polling continues on a long interval and on reconnect or tab focus, so the interface converges without Reverb.
- **Scale.** A single Reverb instance is enough; horizontal Reverb scaling would need Redis and is out of scope.

### 3.9 Public demo

- **Identity.**
  - A `__Host-ripe_visitor` cookie (HttpOnly, Secure, SameSite=Lax) holds a 256-bit token; only its hash is stored.
  - The visitor row is created with the first seed, not on the first visit.
  - Policies use the visitor guard; another visitor's task answers 404.
  - Mutations require a same-origin `Origin` or `Sec-Fetch-Site`.
- **No leaks.** The agent status never exposes another visitor's task; the mycelium "feeds other greenhouses" instead.
- **Budget.**
  - A reservation is made before each call with `UPDATE … SET reserved = reserved + :max WHERE spent + reserved + :max <= limit`.
  - `:max` prices the estimated input plus the whole `max_output_tokens`, reasoning included.
  - The reservation is settled with the reported usage, or with the flagged estimate when Ripe aborted the stream.
  - Orphaned reservations are released by the reconciler.
  - Ripe's own daily budget stays below the spend limit configured on the OpenAI project, so the provider's limit is a backstop, not the mechanism.
- **Rest versus night.** The sky follows the visitor's clock; the mycelium shows the agent's state. Budget rest is a hibernating mycelium, distinct from night (light) and offline (grey). A driver-scope halt (credit or credentials) also rests the greenhouse.
- **Modes.**
  - `RIPE_MODE=local|public`. Local mode keeps today's settings and Ollama; public mode uses OpenAI and reads its configuration from the environment only.
  - The interface copy that calls the mycelium "an open model running on this machine" becomes mode-dependent: in public mode it names the hosted model.

### 3.10 Observability

- Attempts as the metric source.
- JSON logs with task, run and attempt context, plus queue event listeners.
- `php artisan ripe:inspect {task}`.
- A `/tasks/{id}/timeline` endpoint.
- A discreet "Growth rings" tab in the specimen sheet. It is not an admin dashboard.

## 4. Lease renewal during long model calls

### 4.1 Problem

PHP performs HTTP streaming synchronously. Guzzle's streaming path, and therefore Laravel's HTTP client, blocks in a read call between chunks. A model can stay silent for a long time:
- while loading weights;
- during prompt evaluation on a long context;
- while queued at a hosted provider;
- while thinking before the first token.

If renewals only happen when tokens arrive, a healthy worker loses its lease during silence. The reconciler then starts a second attempt, which wastes inference and creates races. Cancellation would also go unnoticed for as long as the model is silent.

### 4.2 Alternatives

| Option | Verdict |
|---|---|
| Lease as long as the longest call, no renewal | Dead workers are detected only after minutes; cancellation is still invisible during silence |
| Renew when a chunk arrives | Fails exactly during silence |
| `pcntl_alarm` / `SIGALRM` handler | Collides with the queue worker's own `--timeout` alarm; database writes inside signal handlers; interrupted system calls |
| Forked heartbeat process | Shared connections must be rebuilt, orphan and zombie handling, two processes per run |
| Child process for the call, supervised by the worker | Robust, but adds a process per call and result serialisation |
| **Supervised `curl_multi` loop** | **Chosen** |

### 4.3 Decision

The streaming transport drives ext-curl's multi interface itself. Each loop iteration:
1. `curl_multi_exec` transfers whatever is available and the write callback feeds a line parser that emits deltas.
2. `curl_multi_select($handle, $tick)` waits for at most `loop_tick` (250 ms) **whether or not bytes arrive**.
3. A `LeaseSupervisor` checks the clock-driven deadlines and renews the lease when due.

Renewal depends on the clock, not on tokens. Aborting is a matter of removing the handle, so cancellation and deadlines act within one tick. Health checks and other short calls keep using Laravel's HTTP client.

### 4.4 Parameters

| Parameter | Default |
|---|---|
| `lease_ttl` | 30 s |
| `renew_interval` | 5 s |
| `loop_tick` | 250 ms |
| `connect_timeout` | 10 s |
| `first_byte_timeout` | 150 s |
| `idle_timeout` | 60 s after the first byte |
| `call_timeout` | 240 s (Ollama), 300 s (OpenAI) |
| queue worker `--timeout` | 360 s |
| queue `retry_after` | 420 s |
| `max_interruptions` | 3 |

Two ordering constraints are asserted by a test:
- `renew_interval ≤ lease_ttl / 3`
- `call_timeout + lease_ttl < worker timeout < retry_after`

The supervisor always aborts first, the worker's alarm is a backstop, and queue redelivery never overlaps a live call. All lease timestamps come from the application clock, which makes them controllable in tests. The deployment has a single host, so there is a single clock.

`connect_timeout`, `first_byte_timeout`, `idle_timeout` and `call_timeout` are configured per driver. The values above suit Ollama and OpenAI's default tier. A slower tier such as flex needs minutes, and the worker timeout and `retry_after` follow from the ordering constraints, never the other way round.

### 4.5 Protocol

1. **Claim** (transaction, run row locked):
   - Requires `status = queued`, `available_at <= now`, an active task, no cancel request and no other running run in the task (also guarded by the partial unique index).
   - Sets `running`, a fresh `lease_token` and `lease_expires_at = now + ttl`, and inserts the attempt.
   - No transaction stays open during the call.
2. **Renew** (every `renew_interval`):
   ```sql
   UPDATE agent_runs
   SET lease_expires_at = :now + ttl, last_heartbeat_at = :now
   WHERE id = :run AND status = 'running' AND lease_token = :token
     AND lease_expires_at > :now AND cancel_requested_at IS NULL
   ```
   - One affected row means renewed. Zero rows means the supervisor reads the row and aborts with `Cancelled`, `LeaseLost` or `RunGone`.
   - A database error is retried on the next tick. If no renewal is confirmed by `lease_expires_at − safety_margin`, the worker fences itself (`LeaseUnconfirmed`) instead of streaming on without a lease.
3. **Commit:** task and run locked; the result is accepted if and only if `status = running ∧ lease_token = mine ∧ lease_expires_at > now ∧ cancel_requested_at IS NULL`. Otherwise only the attempt outcome is recorded.
4. **Expire** (reconciler): running runs with `lease_expires_at <= now` get an `interrupted` attempt. They become `cancelled` if cancellation was requested, `failed` after `max_interruptions`, and `queued` with `available_at = now` otherwise.
5. **Cancel** (in the action's transaction): queued runs become cancelled at once; running runs get `cancel_requested_at`. The worker observes it within `renew_interval + loop_tick`. If the worker is dead, step 4 completes the cancellation.

**Lease expiry is final.** No predicate renews or accepts an expired lease, so whether a late worker wins never depends on when the reconciler happens to run.

### 4.6 Deterministic outcomes

| Situation | Attempt | Run | Domain |
|---|---|---|---|
| Silence shorter than the timeouts | renewed | running | — |
| No first byte within `first_byte_timeout` | `timed_out` (first_byte) | retry or failed | unchanged |
| No bytes for `idle_timeout` | `timed_out` (idle) | retry or failed | unchanged |
| Beyond `call_timeout` | `timed_out` (total) | retry or failed | unchanged |
| Cancellation requested | `cancelled` | cancelled | unchanged |
| Token replaced or lease expired | `lease_lost` | owned by the reconciler | unchanged |
| Database unreachable past the margin | `lease_lost` (unconfirmed) | expired by the reconciler | unchanged |
| Worker killed | `interrupted` | queued, or failed after the cap | unchanged |
| Valid result | `succeeded` | succeeded | applied once |
| Same result committed again | no-op | — | applied once |

### 4.7 Tests

- **Supervisor unit tests** with a fake clock: renewal schedule, deadline classification, self-fencing when renewals fail, cancellation detection.
- **Transport tests over real sockets.** A fixture streaming server runs as `php -S` on a random port, with scenarios selected by path:
  - silence longer than `lease_ttl` while the lease is renewed (the call completes, renewals are counted);
  - cancellation during silence (abort within one renewal interval, no version created);
  - the token replaced mid-stream;
  - first-byte, idle and total timeouts;
  - server disconnect mid-stream.
- **Commit fencing:** a mismatched token, an expired lease without a reconciler pass, a pending cancellation, and a commit racing the reconciler from two processes (exactly one outcome).
- **Process level:** `kill -9` of a worker during silence, then recovery after the TTL; the first attempt's late commit is rejected.
- **Configuration:** the ordering constraints above.

## 5. Step dependencies and human input

The agent keeps producing useful work while information is missing. Four kinds of conditions are kept distinct.

| Kind | Meaning | Storage | Effect |
|---|---|---|---|
| Step dependency | A step builds on another step's piece | `step_dependencies` | The step waits until the earlier step is `done` |
| Execution requirement | A step cannot be written meaningfully, even with markers, without an answer | `step_requirements` (essential questions only) | Only that step and its dependants wait |
| Placeholder information | Names, dates, amounts, details | optional questions and `[[TBD: ...]]` markers | Never blocks; the gap is shown in the text |
| Completion condition | The work can be drafted, but the task cannot be considered finished without the answer | every essential question | Steps run with markers; the review and ripening wait |

### 5.1 Model output and validation

Interpretation returns questions with `kind` (`optional` or `essential`). Planning returns, for each step, `builds_on` (earlier steps) and `requires` (essential questions). The backend validates deterministically and never trusts the model's structure:
- `builds_on` may only reference earlier steps, so the graph is acyclic; invalid references are dropped.
- `requires` may only reference essential questions; anything else is dropped.
- A final step without `builds_on` builds on every earlier step.
- At least one step must be runnable immediately. If none is, the earliest step's requirements become completion conditions.

### 5.2 Scheduling

- A step is `ready` when its dependencies are done and its requirements are answered or skipped. Otherwise it is `blocked`, either waiting for a step or waiting for an answer.
- Ready steps run in position order, one run at a time per task.
- The review runs only when every step is done, no essential question is open, and no finalisation refresh is pending.
- The task is `waiting` when nothing is runnable or running and either some step is blocked or a completion condition is unmet. The persisted work is all there; only the next part waits.

### 5.3 Answers

- **Answer.** Marks the question answered and increments `answer_revision`. Blocked steps that now satisfy their requirements become ready.
  - For an essential question before the task ripens, every current version whose provenance shows that question unanswered receives a finalisation refresh (`revise`) before the review.
  - For an optional question, a refresh is offered, not run.
- **Skip.** Satisfies the requirement and the completion condition; the marker stays and the review lists it as missing.
- **Reopen or change an answer.** Affects only future scheduling. Persisted work never regresses, and a ripe task stays ripe. Versions built on the previous answer become stale and a refresh is offered.

### 5.4 Provenance and staleness

Every agent-produced version stores its inputs: the artifact versions it used as context and, for each question, its status and answer revision at generation time. The review stores the versions it reviewed. Staleness is derived, never stored, and it is shown and offered for refresh without regressing growth. This prevents a synthesis or a review from silently describing pieces that have since changed.

### 5.5 Examples

**"Reply to the difficult client email."**
- Q1 is essential ("Do you accept the new deadline?") and is required by the final message step. Q2 is optional (the client's name).
- Steps 1 (key points) and 2 (tone options) run. Step 3 (the reply) is blocked and the task waits.
- The answer unblocks step 3; then the review runs and the task ripens.

**"Plan the December trip."**
- The budget is essential, but no step requires it, so it is a completion condition.
- Every step is written with `[[TBD: budget]]` and the plant sets fruit. The task waits before the review.
- The answer triggers refreshes of the pieces written without it, then the review, then ripening.

### 5.6 Tests

- Independent steps advance while another step is blocked.
- Dependants of a blocked step wait for it.
- Optional questions never block.
- Completion conditions hold back only the review.
- Skip and reopen semantics as described above.
- Finalisation refreshes target exactly the versions whose provenance lacks the answer.
- The validator rejects cycles, forward references and requirements on optional questions, and guarantees a runnable step.
- When a task becomes ripe, its review's inputs cover every essential question at its current revision.

## 6. Role of `task_events`

### 6.1 Decision

The relational state is the source of truth. `task_events` is a structured audit log. Event sourcing with deterministic replay was rejected because it would require:
- every event to carry complete data, including artifact contents;
- versioned upcasters as the schema evolves;
- projections rebuilt from the log.

None of that buys anything Ripe needs. Artifact history is already preserved by immutable `artifact_versions`, and operational telemetry lives in `run_attempts`.

### 6.2 What the log guarantees

- **Atomicity:** each event is written in the same transaction as the state change it records, so the log never describes something that did not happen, and every recorded transition happened.
- **Order:** `sequence` is gap-free per task, allocated from `tasks.version` under the task lock, so `tasks.version = max(sequence)`.
- **Immutability:** rows are never updated; they are deleted only together with their task.
- **Attribution:** `actor` (`visitor`, `agent` or `system`) plus `run_id` and `attempt_id` where relevant.
- **Usefulness:** it backs the field notes, the Growth rings timeline, audit, and the version used for realtime invalidation.

### 6.3 What the log does not promise

The log does not rebuild the full state. Payloads hold identifiers and key facts (`from` and `to`, counts, reasons), not complete content.

### 6.4 Event types

- **Task:** `task.planted`, `task.deferred`, `task.paused`, `task.resumed`, `task.harvested`, `task.replanted`, `task.renamed`, `task.status_changed`, `task.interpreted`, `task.planned`.
- **Questions:** `question.asked`, `question.answered`, `question.skipped`, `question.reopened`.
- **Steps and artifacts:** `step.ready`, `step.blocked`, `step.completed`, `artifact.version_created`, `review.completed`.
- **Runs:** `run.scheduled`, `run.started`, `run.retry_scheduled`, `run.interrupted`, `run.succeeded`, `run.failed`, `run.cancelled`, `run.result_rejected`.
- **Plant and budget:** `plant.stage_changed`, `budget.deferred`.

### 6.5 Consistency checks

`php artisan ripe:audit {task?}` verifies agreement between the log and the persisted state:
- every artifact version has exactly one `artifact.version_created` event;
- every terminal attempt has a matching run event;
- the last `task.status_changed.to` equals `tasks.status`;
- each question's status equals its last question event;
- the count of `step.completed` events equals the steps done;
- the last `plant.stage_changed.to` equals the recomputed stage;
- the sequence is gap-free and `version` matches.

The same checks run as a Pest assertion at the end of every orchestration and resilience scenario.

## 7. Stages

The current application keeps working from `frontend/`, with its own Prisma backend and agent, until stage 5 replaces them.

| # | Stage | Done when | Size |
|---|---|---|---|
| 0 | Foundation | The Next app runs unchanged from `frontend/` (typecheck, lint, build and an agent run against local Ollama); Laravel 13 on PHP 8.4 in `backend/` uses PostgreSQL 17 from Docker Compose; Pest, Pint and Larastan pass; a CI workflow covers both apps; this plan is committed | S |
| 1 | Domain and persistence | Migrations for every entity run up and down on PostgreSQL; every constraint has a test (unique, partial unique, foreign key cascade, CHECK); state machines are tested for allowed and forbidden transitions; the journal writer allocates gap-free sequences under the task lock; `GrowthCalculator` reproduces the current table; the skeleton `User` gives way to `Visitor` | M |
| 2 | Inference | `ModelDriver` with OpenAI, Ollama and Fake drivers on the supervised `curl_multi` transport; one contract suite passes for all drivers on fixture streams (OpenAI SSE events, Ollama NDJSON); every documented OpenAI failure maps to its scope (rate limit versus credit and spend limits, overload, credentials, unknown model, rejected request, refusal, content filter, `max_output_tokens`, a stream without a terminal event), and so do Ollama's; usage and cost are computed from fixtures with cached and reasoning tokens, and estimated when a stream is aborted; every schema passes the strict-subset check; socket-level transport tests cover silence longer than the lease, every timeout and supervisor aborts; prompts and schemas are ported with snapshot tests; optional live groups pass against local Ollama and, with an API key, against OpenAI | M |
| 3 | Orchestration and resilience | Reconciler, `ExecuteRun`, the lease protocol, committers, retry policy, pace and cancellation are in place; `ripe:simulate` drives a task to ripe through real queue workers with the FakeDriver; green suites for resilience (redelivery, crash after commit, lease loss with a late result, `kill -9` during silence, back-off, interruption cap, cancellation mid-stream, concurrent reconcile), dependencies and human input (section 5.6) and the growth invariant; the journal consistency checks pass after every scenario | L |
| 4 | API, visitors and policies | Parity with the 20 current operations; visitor isolation tests; rate limits; OpenAPI generated and TypeScript types checked for drift in CI | M |
| 5 | Frontend cut-over (polling) | Next talks only to Laravel; Prisma, the agent and the API routes are removed; the working twig becomes a transient signal; completion-condition waiting is drawn on the fruit; the `docs/screens` scenarios are reproduced; the full flow is verified in the browser with local Ollama | L |
| 6 | Realtime | Reverb and Echo with version-based invalidation and offset-checked streaming; stopping Reverb mid-task lets the interface converge through polling; reconnecting restores the correct snapshot and live text | M |
| 7 | Public demo | The OpenAI model, reasoning effort and service tier are chosen with verified prices, together with the daily budget and the project spend limit; budget reservations never exceed the limit under concurrency; exhaustion rests the greenhouse (distinct from night and offline) and resumes automatically; per-visitor and global limits; retention decided and tested with pruning; "delete my greenhouse"; examples recorded from real runs, read-only and labelled | L |
| 8 | Observability | Structured logs with context; timeline endpoint and `ripe:inspect`; the Growth rings tab; `ripe:audit` passes for every task produced by the resilience suite and for the recorded examples; every attempt appears in the timeline; nothing claims or requires replaying state from events | M |
| 9 | Deploy | `ripe.marianacastro.dev` on a VPS with a production Compose file separate from development; TLS; supervised HTTP, queue, scheduler, Reverb and Next processes; daily PostgreSQL backups with a tested restore; an automated smoke test; a runbook | M |

## 8. Risks

| Risk | Mitigation |
|---|---|
| Synchronous streaming in PHP | Supervised `curl_multi` loop (section 4) with socket-level tests from stage 2 |
| `retry_after` shorter than a call | Ordering constraints asserted by a test; leases and fencing as the real guarantee |
| OpenAI retires a model or changes prices | A dated snapshot pinned in configuration; prices in configuration with the date they were checked |
| Account-level limits at OpenAI (credit, spend, usage) | Driver-scope errors halt the agent and rest the greenhouse instead of failing tasks; Ripe's budget stays below the project spend limit |
| Reasoning tokens exhausting `max_output_tokens` | A visible budget plus a reasoning allowance per run kind; incomplete structured output is rejected and counted |
| Structured output differences between OpenAI and Ollama | One schema subset accepted by both, checked by a test, and validation after decoding |
| Abuse and cost in the public demo | Budget reservations, per-visitor and hashed-IP limits, bounded prompts and outputs |
| Reverb behind the proxy | Polling stays as a permanent fallback |
| Visual parity at the cut-over | `docs/screens` as the reference set |
| Laravel 13 ecosystem compatibility | Checked in stage 0 and at each stage that adds a package |

## 9. Decisions

**Approved on 2026-11-15:**
- Hosting on a VPS with Docker Compose, keeping frontend and backend as separate applications.
- Inference (revised the same day, before stage 2):
  - the hosted demo uses the official OpenAI API;
  - Ollama stays as the fully local alternative, and the FakeDriver serves the tests;
  - the choice between OpenAI and Ollama is configuration only;
  - the model, reasoning effort, service tier and daily budget are chosen in stage 7.

  This replaces the earlier choice of an open-weight model on an OpenAI-compatible provider.
- Examples come from real agent runs, keeping their results and history, and are explicitly labelled as demonstrations.
- The partial growth of `working` twigs is replaced by transient activity signals.
- The existing local `ripe.db` is not migrated.
- Retention is decided in stage 7. A period longer than 14 days is considered, since tasks are meant to ripen while their owner is away; one option is measuring from the later of the last visit and the planned return date.
- Usage limits are defined in stage 7.

**Approved for stage 4 (2026-11-15):**
- One endpoint per action instead of a `PATCH` with an `action` field, so each action has its own validation, policy, rate limit and schema.
- Responses keep the current camelCase contract (`TaskSummary`, `TaskDetail`, `Garden`, `AgentStatus`), adapted to the new domain: step phases, question kinds, version history, field notes from the journal and a snapshot `version`.
- Errors use Laravel's envelope: 422 for validation, 409 for a change the state does not allow or a version conflict, 404 for another visitor's resource, 403 for a foreign origin or a read-only example, 429 for rate limits.
- `ArtifactVersion` stays immutable. Autosave writes a separate draft; keeping the draft creates a new version. Both use optimistic concurrency on `baseVersionId` and the draft revision, with 409 on conflict.
- Mutations require proof of the same origin; missing `Origin` and `Sec-Fetch-Site` are rejected. The visitor cookie is `HttpOnly`, `SameSite=Lax` and `Secure` (`__Host-` prefix) in production.
- Live text is kept in the cache per run and attempt, with a time to live; an older attempt can neither overwrite nor show over a newer one.
- `RIPE_MODE=local|public`: settings are editable only in local mode, and the model's address is never editable from the interface.
- Examples are shared and read-only, grown through the real pipeline with a scripted model until stage 7 records them from real runs.
- OpenAPI is generated with Scramble; TypeScript types are generated with `openapi-typescript`; both are checked for drift.

**Pending:**
- OpenAI model, reasoning effort, service tier and daily budget (stage 7).
- Whether to use OpenAI's background mode to resume generations after a worker dies (stage 7).
- Retention period (stage 7).
- Usage limits and invisible Turnstile on task creation (stage 7).
