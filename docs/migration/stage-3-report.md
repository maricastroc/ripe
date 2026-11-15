# Stage 3 report: orchestration and resilience

Branch `migration/stage-3-orchestration`, 2026-11-15.

## What changed

```
backend/app/
├── Orchestration/
│   ├── TaskOrchestrator        the reconciler: decides the next run under the task lock
│   ├── RunDispatcher, NextRun  schedules a run and dispatches its job in the same transaction
│   ├── Leases, LeaseSupervisor clock-driven renewal plugged into the transport's CallSupervisor
│   ├── Sweeper                 expired leases, lost jobs, idle tasks, lifting a halt
│   ├── AgentHalt, RunBudget, RunCancellation, AgentSettings, TimingConstraints
│   └── Execution/
│       ├── RunExecutor         claim → call → commit or record the failure, fenced by the lease
│       ├── RunRequests         builds each request and the provenance of what it read
│       ├── ResultCommitter     validates a result and applies it to the domain
│       └── FailurePolicy       error scope → retry, back-off, halt or fail
├── Jobs/ExecuteRun             the queue job: one delivery, one attempt at most
├── Actions/Tasks/              plant, defer and resume, pause, harvest, replant, answer, revise
├── Journal/JournalAudit        consistency checks between the journal and the state
├── Agent/Simulation/           the scripted model behind the fake driver
├── Database/                   a Postgres connection whose bindings keep microseconds
└── Console/Commands/           ripe:simulate, ripe:reconcile, ripe:timing
backend/config/ripe.php         pace, attempts, back-off, budget, lease and worker timings
```

Migrations:
- `agent_runs` gains `origin` (`orchestrator` or `visitor`) and `dispatched_at`.
- Every domain timestamp moves to `timestamptz(6)`.

The `agent` queue connection is the database queue with `after_commit` off, so a job is written in the same transaction as the run it executes. `composer dev` now also starts the agent worker and the scheduler, and the scheduler runs `ripe:reconcile` every minute.

## How the main decisions were implemented

**One reconciler, under one lock.**
- `TaskOrchestrator::reconcile` runs inside the transaction of whatever changed the task (a committed result, an answer, a pause, a sweep) while holding the task row lock. Locks are always taken task first, run second.
- It refreshes step readiness, then picks the next piece of work: interpret, plan, the first ready step, a finalisation, then the review.
- It also derives the status: `ripening` while a run is active; `ripe` once every step is done, the review exists and no essential question is open; `waiting` otherwise.
- At most one automatic run is active per task, which the partial unique index also guarantees. Four processes reconciling the same task at once create exactly one run and one job.

**Atomic dispatch.** A run and its job are inserted in the same transaction, so a rollback leaves neither. A job whose run is already finished, cancelled or held by someone else does nothing. Redelivery and duplicate jobs are therefore harmless.

**Leases (refinement 1).**
- *Claim*: takes the run and task locks, checks the run is due and the task active, starts a 30 s lease with a fresh token and opens a `run_attempts` row.
- *Renewal*: `LeaseSupervisor` runs on every tick of the transport (at most 250 ms apart, whether or not tokens arrive). Every 5 s it renews with a conditional `UPDATE` that requires the run to be running, the token to match, the lease not to have expired and no cancellation to be requested.
  - Zero rows means the call aborts as `cancelled` or `lease_lost`.
  - A database error is retried on the next tick. If no renewal is confirmed before `lease_expires_at − 5 s`, the worker fences itself (`lease_unconfirmed`).
- *Commit*: the result is applied only if the lease is still held at commit time, under the same locks. An expired lease is final: no predicate renews or accepts it, with or without a sweep.
- *Recovery*: `Sweeper::expireLeases` marks the open attempt interrupted. The run then returns to the queue, or fails and stalls the task after 3 interruptions, or is cancelled if cancellation was requested.

**Retry policy.** Each error scope from stage 2 maps to one decision:
- **transient** (rate limit, overload): wait for the provider's hint or 15 s, clamped to 1–300 s, plus up to 10 % of deterministic jitter, without spending an attempt.
- **attempt** (timeout, broken stream, invalid output, a piece under 15 words): back off 10 s, then 30 s, then fail the run and stall the task.
- **run** (refusal, rejected request): fail at once.
- **driver** (bad credentials, exhausted quota): halt the whole agent through a cache flag. Every run is deferred instead of failed, and the sweeper lifts the halt when the driver is healthy again.

Laravel's `tries` only covers redelivery. Attempts, back-off and failure live in the domain and in the journal (`run.retry_scheduled`, `run.failed`).

**Pace.**
- Automatic runs are delayed by `RIPE_PACE`: `calm` 30 s, `steady` 6 s, `brisk` 1 s, `instant` 0.
- Interpretation and the visitor's own requests (revisions) are never delayed.
- Delays use `available_at` on the run and a delayed job. A job that arrives early dispatches itself again instead of being dropped.

**Cancellation.**
- Pausing or harvesting cancels queued runs at once and sets `cancel_requested_at` on running ones.
- The worker notices at the next renewal and the call stops mid-stream. Nothing is written and growth does not move.
- If the worker is dead, lease expiry completes the cancellation.

**Dependencies and human input (refinement 2).**
- Steps become ready or blocked from their dependencies and from the open essential questions they require.
- Reopening a question takes back a queued run for a step that is blocked again.
- Every agent-written version records in `inputs` the artifact versions it read and the status and revision of every question.
- Once an essential question is answered, every agent-written piece that saw it unanswered gets a finalisation revision before the review. A piece the visitor edited is left alone.
- `RequestRevision::refreshWithAnswers` rewrites, on request, every piece that still has `[[TBD]]` gaps.

**Growth invariant.**
- Growth is computed from persisted milestones only.
- The executor computes it before and after applying a result. A stage change is recorded as `plant.stage_changed`, tied to the successful attempt, in the transaction that stored the result.
- Failures, rejected results and the passage of time cannot write a version, complete a step or record a stage.

**Journal audit (refinement 3).** `JournalAudit::problems` checks that:
- the sequence has no gaps and matches the task version;
- every version is recorded exactly once;
- every attempt has its start and an outcome-specific event;
- every run has been scheduled and has exactly one terminal event;
- the status, every question, the completed steps and the plant stage match the last event about them.

Every orchestration scenario ends with this check.

**Timing constraints.** `ripe:timing` checks the ordering from plan section 4.4 against the shipped configuration, for every driver:
- `renew_interval ≤ ttl / 3`;
- the safety margin leaves time to confirm a renewal;
- `call timeout + ttl < worker timeout < retry_after < redispatch_after`.

## Verification

| Check | Result |
|---|---|
| Pint, Larastan at the maximum level | pass |
| Default suite | 491 tests, 1294 assertions: Unit 104, Feature 281, Inference 95, Integration 11 |
| `ripe:simulate` | a task planted for a new visitor ripens through real `queue:work` processes with the fake model: 42 events, 3 of 3 steps, stage ripe, in about half a second |
| `ripe:timing` | the shipped configuration is ordered safely |

The stage's own suites (101 tests):

| Suite | What it proves |
|---|---|
| Ripening (4) | the full cycle in order, the pace delay, a rolled-back dispatch leaves neither run nor job, `ripe:simulate` |
| Human input (7) | independent steps advance while others wait; optional questions never block; the completion condition; finalisation refreshes only agent-written pieces; skipping releases; reopening withdraws a queued run |
| Resilience (15) | duplicate delivery; redelivery after a crash between commit and acknowledgement; a late result after the lease passed on, with and without a sweep; an early job; back-off 10 s and 30 s then stall and resume; rate limit without spending an attempt; driver halt and recovery across tasks; refusal; the interruption cap; cancellation mid-stream; lease lost mid-stream; four processes reconciling at once; a commit racing the sweeper across processes; a real worker kept alive by renewals through silence longer than its lease, then killed with `SIGKILL` and recovered |
| Lease supervisor (6) | renewal on the clock during silence; cancellation noticed at the next renewal; a lease that passed on; a lease that expired while the worker was frozen; self-fencing while the database is unreachable; recovery when it comes back before the margin |
| Failure policy (10) | waits, jitter bounds, clamping, back-off, failure and halt per scope |
| Growth invariant (25) | eleven kinds of failure leave growth untouched; every stage change sits next to the success that caused it; thirty days of sweeps change nothing; twelve seeded random failure scripts (timeouts, broken streams, invalid and short output, rate limits, overloads, lease losses and pauses mid-stream) always end ripe, with growth that never goes back and only versions from successful runs |
| Timing constraints (8) | the shipped configuration, and each violation detected |
| Task actions (16) | plant, pause, resume, harvest, replant and revise, with every rejection |
| Journal audit (9) | a clean task passes; a status, version, task version, attempt outcome, run status, question, step or growth changed behind the journal is detected |

## Problems found and fixed

1. **Fractional seconds rounded up.** `timestamptz(0)` rounds rather than truncates, so a run due now could be stored half a second in the future. The worker then refused it and the job was consumed. Every domain timestamp now keeps microseconds, new columns default to precision 6, and a job that arrives early dispatches itself again.
2. **Bindings without microseconds.** Laravel formats dates in query bindings as `Y-m-d H:i:s`, so the lease renewal and the `lease_expires_at > now` comparison worked to the second, giving an expired lease up to a second of grace. A Postgres connection with a grammar that binds `Y-m-d H:i:s.uP` fixes it; the lease supervisor tests found the problem.
3. **A reopened question did not take back a queued run.** The reconciler now cancels the queued run of a step that becomes blocked again (`run.cancelled`, reason `requirements_changed`).
4. **Test memory.** Pest's architecture preset parses the whole codebase, so the suite starts near 100 MB and the new suites crossed PHP's default 128 MB. `phpunit.xml` sets the limit to 512 MB.
5. **Laravel architecture preset.** Commands need the `Command` suffix, and the lease renewal result is an enum, so it lives in `App\Enums`.

Two expectations were wrong in the tests, not in the code:
- runs created in the same microsecond needed a stable order;
- a plan where the final step builds on every earlier step by default.

## Decisions to review

- **Worker timeout and `retry_after`.** The plan's table has 300 s and 360 s. Stage 2 set OpenAI's call timeout to 300 s, so the ordering constraint (`call + lease < worker < retry_after`) moves them to 360 s and 420 s. Lost queued runs are re-dispatched after 600 s, after the queue's own redelivery. A slower service tier, chosen in stage 7, moves these again, and `ripe:timing` will say so.
- **Halt flag in the cache.** The halt is shared across workers through the cache store (the database store by default). Tests use the array store.

## Pending

- **API (stage 4):** the actions are ready to sit behind controllers, policies and rate limits.
- **Budget (stage 7):** the daily inference budget and `budget.deferred` are not wired in yet. The hosted demo's model, tier and timings are chosen then.
- **Audit command (stage 8):** `ripe:audit` and the timeline will run `JournalAudit` over every task.
- **Production workers (stage 9):** the worker command must use `--timeout` equal to `RIPE_WORKER_TIMEOUT`, and the deploy should run `ripe:timing` before starting it.
