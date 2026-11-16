# Stage 8 report: observability

Branch `migration/stage-8-observability`, 2026-11-15.

## What changed

```
backend/app/Observability/AgentLog.php        operational log lines, written after the commit
backend/app/Observability/PublicError.php     the fixed sentence a visitor sees for each kind of failure
backend/app/Http/Middleware/AssignRequestContext.php
backend/app/Console/Commands/AuditJournalCommand.php   ripe:audit
backend/app/Console/Commands/InspectCommand.php        ripe:inspect
backend/app/Http/Controllers/Api/Tasks/TaskTimelineController.php
backend/app/Http/Resources/Timeline*.php
frontend/src/components/task/GrowthRings.tsx
frontend/src/components/task/Journal.tsx      field notes and growth rings as two tabs
```

- **Routes:** `GET /api/tasks/{task}/timeline`, the 25th route. The OpenAPI document and the frontend's types are regenerated.
- **No migrations.** Everything reads what earlier stages already store.
- **Unchanged by design:** the growth rule, the journal and the audit's checks.

## Structured logs

- **Context.** Laravel's `Context` carries the task, run and attempt through each run, and a request id and route through each API request. The request id also goes back as `X-Request-Id`. The context lands under `extra`, so a JSON line from inside a run can be found by any of the three ids.
- **What is logged.** Operation only, each line written after its transaction commits, so a rolled-back change is never logged as if it happened:

| Line | Level |
|---|---|
| attempt started (kind, attempt number, model, how long the run waited after it was due) | info |
| attempt finished (outcome, duration, time to first token, tokens, error class) | info when saved or stopped, warning otherwise |
| run deferred for quota or budget, once per episode | info |
| model quota blocked by a 429, with `retry-after` | warning |
| agent halted (with the error class) and resumed | warning, info |
| reconciler recovered work (expired leases, re-dispatched runs, settled reservations) | warning when leases expired, info otherwise |
| task stalled | warning |
| agent job failed for good (queue listener) | error |
| request refused (409, 403, 422) | info, without stack trace |

- **What is not logged:** the journal, which is already in the database.
- **Format:** production writes JSON to stderr with settings that already existed; development keeps the text file; tests write no log; the end-to-end backend logs to stderr.

## Sanitized data (refinement 3)

- **Logs** carry no prompts, answers, cookies, credentials or provider details:
  - error lines carry the exception class, never its message, because messages can embed a model's refusal or a provider's reply;
  - a failed query is logged with its SQL, SQLSTATE and where it came from, but without its values. Laravel's default report includes the bindings, and a binding can be a prompt.
- **What visitors see.** Errors are fixed sentences, chosen by outcome and error class: in the timeline, in `lastError` and in the stall reason.
  - Before this stage, `lastError` and the stall reason carried the raw message. That could be a model's refusal or, for an unexpected failure, a database error with the values of the query.
  - The raw message stays on the attempt, readable with `ripe:inspect`.
- **The timeline** exposes an allow-list of fields. Lease token, worker, queue job, provider ids, budget columns and error classes stay out.
- **Access** follows the task's `view` policy: the owner reads it, any visitor reads an example's, and another visitor gets the same 404 as for the task.

## Audit and inspection

- **`ripe:audit {task?}`** runs `JournalAudit` against each task, one read-only repeatable-read transaction per task, so a task changing mid-audit cannot raise a false problem. It prints each disagreement and exits with an error.
- **In the suite:** `expectJournalConsistent`, which every orchestration and resilience scenario calls, now goes through this command. The two recorded examples, installed from their committed fixtures, pass it too.
- **On the development database** it found one real disagreement: a task at version 46 with 44 events. On 2026-11-15, during an earlier browser check, that task was edited by hand with `psql` to show the waiting state, and each edit raised the version without an event. That is exactly what the audit is for, and the task was left as it is.
- **`ripe:inspect` without a task** reads attempts as the metric source:
  - the agent's mode and halt reason;
  - the quota used against its limits, over the last minute and the last 24 hours;
  - tasks by status, the queue and stalled tasks;
  - attempts by outcome over the last 24 hours;
  - per kind of run, the median and 95th percentile of time to first token and of duration, plus tokens.
- **`ripe:inspect {task}`** adds its steps, runs and attempts with their raw errors, the latest journal events (`--events=N`) and the audit result.

## Timeline and Growth rings (refinements 1 and 2)

- **The timeline:**
  - each run with every attempt, oldest first, from `agent_runs` and `run_attempts` only;
  - for each attempt: `savedWork` (true only when it succeeded, which is when its result was committed), the wait since the run was due or since the previous attempt, duration, time to first token, tokens and whether they were estimated, driver, model, cost and a fixed error sentence;
  - totals: attempts, saved work, time working and waiting, and tokens.
- **Growth rings** is a tab beside the field notes, loaded only when opened and refreshed when the task's version changes.
  - **Attempts versus growth.** The trunk only widens with saved work: one ring per attempt that saved its work, oldest at the pith. Attempts that saved nothing are marks on the growth line at the moment they happened, without adding wood:
    - a crack for a failure;
    - a break for an interruption;
    - a knot for an attempt that was stopped;
    - a bud for one still running.

    The caption says it plainly: the plant only grows from saved work. The plant's own growth rule is untouched.
  - **A controlled scale.** Ring width follows duration on a logarithmic scale between 4 and 10 units, so a two-minute call is at most two and a half times as wide as a quick one. When many rings would overflow, they shrink together. The drawing is framed to the trunk, so a young trunk is still readable. Exact durations are in the list.
  - **Not only colour.** Each mark has its own shape. The list names every outcome in words ("saved its work", "nothing saved · The work stopped halfway…"), screen readers get a sentence for each shape, and a legend repeats the shapes.
  - **The look.**
    - Ring outlines wobble slightly, seeded by the attempt's id, so the same task always draws the same trunk.
    - The bark, earlywood and latewood use the sheet's wood and kraft tones.
    - Captions are typewritten.
    - Hovering or focusing a row darkens its ring, and the figure stays in view beside a long list.

## Verification

| Check | Result |
|---|---|
| Backend: Pint, Larastan at the maximum level, Pest | pass; 380 tests |
| Frontend: Prettier, ESLint, TypeScript, production build, API types | pass |
| End to end (Playwright, Chromium) | 2 scenarios; the first now opens the growth rings |
| Each commit on a clean clone | see the pull request |

**New backend tests:**
- **Logs (1):** a task that fails once and then ripens through the queue.
  - Every attempt is logged, with the task, run and attempt in `extra`.
  - A refused request is logged at `info`, with the same request id the response returned.
  - A failed query is logged without its values.
  - No line contains the prompt, the model's answer or the visitor's token.
- **Audit and inspection (1):**
  - `ripe:audit` passes for a ripened task and both recorded examples;
  - it names the problem and fails when the task's status is changed behind the journal;
  - it refuses an unknown task;
  - both forms of `ripe:inspect` run, one of them with a failed attempt.
- **Timeline (1):** a task with an interrupted, an invalid and several saved attempts.
  - Every stored attempt appears, with exactly the allowed fields.
  - `savedWork` is true only where the attempt succeeded.
  - Errors are the fixed sentences, and the raw model output and the worker name never appear.
- **Isolation:** the timeline joins the routes another visitor cannot reach, and the example's timeline is open to any visitor.
- **Changed:** the refusal scenario now expects the public stall reason, and checks that the model's refusal is kept on the attempt.

**Mutations confirmed the tests catch real mistakes:**
- logging failed queries the default way, with their values;
- dropping the run context;
- exposing the raw error in the timeline.

**Browser, local Ollama (`gemma4:e4b`).** A real task was disturbed to produce every mark:
- paused during interpretation (stopped);
- Ollama stopped mid-write (failed);
- the agent worker killed mid-rewrite, so the reconciler found the lease expired (interrupted).

The sheet then showed 7 rings of saved work and 3 marks, each with its own shape and words. Every attempt was in the list with its exact duration, and hovering a row darkened its ring. At 375 px wide the figure stacks above the list, with no horizontal scroll and no console errors.

## Problems found and fixed

1. **Raw errors reached visitors.** `lastError` and the stall reason carried exception messages. One test expected a model's refusal text as the stall reason; it now expects the public sentence and finds the refusal on the attempt.
2. **The journal was said to back the timeline.** Section 6.2 of the plan named the Growth rings among the journal's uses. The timeline reads runs and attempts, and the plan now says so.
3. **Literal error sentences in the API types.** Scramble inferred the fixed sentences as an enum, which would tie the frontend's types to the wording. They are declared as plain strings.
4. **A drawing too small for young trunks.** With a fixed frame, a task with a few rings filled a third of the figure. The frame now follows the trunk, with a minimum size.

## Pending

- **Stage 9:**
  - `LOG_CHANNEL=stderr`, `LOG_STDERR_FORMATTER=Monolog\Formatter\JsonFormatter` and `LOG_LEVEL=info` on Railway;
  - `ripe:audit` in the post-deploy smoke test;
  - invisible Turnstile on task creation.
