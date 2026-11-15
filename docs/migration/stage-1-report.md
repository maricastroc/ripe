# Stage 1 report: domain and persistence

Branch `migration/stage-1-domain`, 2026-11-15.

## What changed

```
backend/app/
├── Casts/JsonObject.php         jsonb maps that are always stored as JSON objects
├── Enums/                       16 backed enums: statuses, kinds, outcomes, event types, stages
├── Exceptions/                  InvalidTransition, ImmutableRecord, TransactionRequired
├── Growth/                      Growth value object and GrowthCalculator
├── Models/                      Visitor, Task, Step, Question, Artifact, ArtifactVersion,
│   │                            AgentRun, RunAttempt, TaskEvent, InferenceBudgetDay
│   └── Concerns/                BelongsToTask, GuardsStatusTransitions, Immutable
├── States/                      StateMachine contract and ChecksTransitions
└── Support/Transactions.php
backend/database/migrations/     13 migrations for the domain
backend/database/factories/      a factory per model, with domain states
```

The skeleton `User`, its factory and the `users`, `password_reset_tokens` and `sessions` tables are gone. Authentication is configured against `Visitor`, which stage 4 will put behind its own cookie guard. Other configuration changes:
- the session driver defaults to `array`;
- the PostgreSQL connection runs in UTC;
- Eloquent runs in strict mode outside production;
- destructive database commands are blocked in production.

## Schema

There are 12 domain tables:
- `visitors`, `tasks`, `steps`, `questions`;
- `step_dependencies`, `step_requirements`;
- `artifacts`, `artifact_versions`;
- `agent_runs`, `run_attempts`;
- `task_events`, `inference_budget_days`.

| Integrity mechanism | Count | Examples |
|---|---|---|
| CHECK constraints | 59 | milestone order, status ↔ timestamp consistency, run target per kind, lease presence while running, agent versions tied to a run |
| Foreign keys | 21 | |
| Composite foreign keys | 12 | a dependency, requirement, artifact, run or event can only point at rows of the same task; a current, parent or base version must belong to the same artifact; an event's attempt must belong to the event's run |
| Unique constraints | 24 | step and question positions, one artifact per step, one version per run |
| Partial unique indexes | 6 | one running run per task; one active run per unit of work (task-level kinds, step, artifact); one review per task; one open attempt per run |
| Partial indexes | 4 | active tasks, examples, due runs, running leases |

Two rules from the plan's dependency policy are enforced by the database itself, not only by code:

- **Only essential questions can hold a step back.** `step_requirements` references `questions(id, task_id, kind)` and checks `question_kind = 'essential'`. A requirement on an optional question is rejected, and so is turning a required question optional afterwards.
- **Dependencies stay inside one task.** `step_dependencies` references `steps(id, task_id)` on both sides.

Building only on earlier steps (which keeps the graph acyclic) is enforced by `Step::dependOn()`.

## Domain behaviour

**State machines**
- `TaskStatus`, `StepStatus`, `QuestionStatus` and `RunStatus` each declare their transitions in one `match`.
- Every Eloquent save that changes `status` is validated against that map (`GuardsStatusTransitions`), whichever code path makes the change.
- Conditional SQL updates, such as the run claims in stage 3, encode their own `from` state.

**Transitions with consequences**
- `Task::transitionTo()`:
  - dates the harvest and clears it on replanting;
  - keeps the stall reason only while stalled;
  - refuses to ripen without a review, and remembers the first time the task ripened.
- `Step::transitionTo()` dates the completion.
- `Question::answer()` / `skip()` / `reopen()` keep a revision counter that never goes back, so later stages can tell which answer a piece was written with.

**Journal**
- `TaskEvent::record()` allocates the next sequence with `update tasks set version = version + 1 … returning version` inside the caller's transaction.
- The row lock serialises writers, and a rollback releases the number, so sequences are gap-free.
- Recording outside a transaction throws `TransactionRequired`.
- Events and artifact versions are immutable at the model level.

**Lock order**
- Every mutation of a step, question or artifact locks its task row first. This matches the plan's rule (task first, then the rest) and rules out the step→task / task→step deadlock the orchestrator could otherwise hit in stage 3.

**Artifact versions**
- `Artifact::addVersion()` locks the artifact, numbers the version, links it to the previous one and moves the current pointer.
- `restore()` adds a new version instead of rewriting history.
- `unique(run_id)` makes a repeated result from the same run impossible to persist twice.

**Growth**
- `GrowthCalculator::calculate()` is a pure function of milestones and step counts.
- `forTask()` reads only committed rows.

## Verification

| Check | Result |
|---|---|
| Migrations up, down and up again on PostgreSQL 17 | pass, also covered by a test |
| Pint, Larastan at the maximum level | pass |
| Pest | 221 tests, 403 assertions, about 10 s, all passing in repeated runs |
| Constraint suite | 72 cases, each asserting the exact constraint that rejected the write |
| Enum ↔ database parity | every enum column's CHECK allows exactly the enum's values; every event type matches the format check |
| Transition matrices | each state machine is compared with an explicit expected matrix |
| Journal under concurrency | 4 processes × 25 events behind a common start: sequences 1–100 without gaps, `version = 100`, and the writers interleaved (99 switches in a local run) |
| Growth parity with the TypeScript original | `computeGrowth` from `frontend/src/lib/growth.ts`, run with `tsx` on 360 combinations, matches the PHP port in stage and maturity (within 1e-12) |
| Cascades | deleting a task, or a visitor, removes its whole graph (9 kinds of rows) and nothing from neighbouring tasks |

## Problems found and fixed

1. **Empty map stored as a list.** PHP serialises an empty map `[]` as a JSON list, so `inputs` and `payload` broke their `jsonb_typeof(...) = 'object'` checks on the first write. A `JsonObject` cast now stores maps as objects and rejects lists.
2. **Name collision with Eloquent.** A static `TaskEvent::append()` shadowed Eloquent's `Model::append()`. Larastan reported the incompatible signature; the method is now `record()`.
3. **Re-answering treated as a transition.** `Question::answer()` validated `answered → answered` when the answer changed. Changing an answer is a new revision, not a transition.
4. **Larastan ignored `casts()`.** It reads the `casts()` method only with `parseModelCastsMethod: true`, now enabled.
5. **Pest's Laravel architecture preset** requires `App\Enums` to contain only enums. The state machine contract and trait moved to `App\States`.
6. **Feature tests no longer run inside a wrapping transaction.** `RefreshDatabase` made `DB::transactionLevel()` at least 1 in every test, so the transaction requirement could never fail there, and other processes could not see test data. Feature tests use `DatabaseTruncation`. Because that trait does not disconnect, each test now closes its connections explicitly; before that fix the suite exhausted PostgreSQL's 100 connections.
7. **Lock order.** Step, question and artifact mutations now lock the owning task first (see above).
8. **Constraint names.** Explicit check names collided with the CHECKs Laravel generates for `enum` columns (`{table}_{column}_check`), so two of them were renamed after the rule they verify.

## Pending

- Stage 2 adds the inference contract and drivers; stage 3 the orchestrator, which will use these models, transitions and the journal.
- Lock order is enforced by construction but not yet exercised by a concurrent test. Stage 3's resilience suite covers it when real runs compete for a task.
- `ripe:audit` (journal ↔ state consistency) arrives with the orchestration scenarios that produce real histories.
