# Stage 7 report: public demo

Branch `migration/stage-7-public-demo`, 2026-11-15.

## The provider changed during the stage

The stage was first approved with OpenAI: `gpt-6-luna` on Flex with `low` effort, a $0.30 daily budget, 10% of it per visitor, and a $10 monthly limit on the project. The money budget was built for that plan. Before anything was committed, the public demo moved to Groq's free plan, so it costs nothing in inference.

The money budget stays, for OpenAI only, and it is kept apart from the quota controls the demo uses. No benchmark or comparison between providers was run.

## What changed

```
backend/app/Inference/Drivers/Groq/   the Groq driver: requests, stream, errors, usage, model catalogue
backend/app/Inference/Contracts/QuotaLimited.php, QuotaLimits.php
backend/app/Orchestration/Quota/      admission against the provider's quotas, settlement, blocking on 429
backend/app/Orchestration/Budget/     the money budget for metered drivers
backend/app/Retention/                when a task is forgotten
backend/app/Actions/Tasks/ForgetTask.php, Actions/Visitors/   deleting a task, a greenhouse, pruning
backend/app/Examples/                 recording, exporting and installing examples
backend/database/examples/            the catalogue and the recorded fixtures
frontend/src/components/greenhouse/ForgetGreenhouse.tsx
```

- **Migrations:** three, all additive.
  - Money settlement columns on `run_attempts` and `inference_budget_days`, plus `visitor_budget_days`.
  - `inference_quotas` and the `inference_calls` ledger, plus `quota` as a deferral reason.
  - The `examples` table.
- **Routes:** `DELETE /api/greenhouse` is new, and `GET /api/examples` replaces `POST /api/examples`, for 24 routes in all. The OpenAPI document and the frontend's types are regenerated.
- **Commands:**
  - `ripe:prune`, scheduled every hour;
  - `ripe:examples:record` and `ripe:examples:install`; `composer setup` now installs the examples.
- **Reverb**, pending from stage 6: at most 500 connections by default, and its per-connection rate limiting is on.

## The Groq driver, checked against real responses

The driver was written from Groq's documentation, then checked against real calls once the key was available. Every assumption that the calls contradicted was fixed, and the test fixtures for the plain and streamed answers are now real captures.

| Assumption | What Groq did |
|---|---|
| Strict structured output does not stream | Documented that way. A streamed strict request was accepted, but nothing says whether it is validated, so interpret, plan and review keep one plain request. Only generate and revise stream, and they are the only runs shown live. |
| Usage at the end of a stream is somewhere | It is on the last chunk, both at the top level and under `x_groq.usage`. The driver reads either. |
| `x-request-id` is sent | On every answer, errors included. |
| Reasoning stays out of the answer with `include_reasoning: false` | Confirmed; at `low` effort reasoning used 5 to 48 tokens per call. |
| A cut structured answer ends with `finish_reason: length` | Strict mode answers HTTP 400 `json_validate_failed` instead. It was mapped to a rejected request; it is now invalid output, which retries. |
| A refused request costs no quota | True for 401, 404 and 429. A 400 `json_validate_failed` did cost tokens, so a request Groq processed without reporting usage now keeps its reservation. |
| 429 carries `retry-after` | Yes, in whole seconds, and the message names the limit (`requests per minute (RPM)`). It also names the Groq organization and offers an upgrade; both are now removed before the message is stored, because a visitor sees the last error of their runs. |
| The health check can ask for `/models/{id}` with the id encoded | Groq answers 404 for `openai%2Fgpt-oss-120b`. The slash is now kept. |
| `max_completion_tokens` above the per-minute limit is refused | It was accepted. Ripe caps its own reservation at the minute limit anyway. |

The 429 was captured from a burst against `openai/gpt-oss-20b`, which has its own quota, so the demo model's allowance was not touched. The live test (`php artisan test --group=live --filter=groq`) interpreted, planned, wrote and reviewed a request in Portuguese in 5.9 s.

## Quotas instead of money

- **Configuration:** the limits are configuration (`GROQ_REQUESTS_PER_MINUTE`, `_PER_DAY`, `GROQ_TOKENS_PER_MINUTE`, `_PER_DAY`, `GROQ_CONCURRENCY`), defaulting to the free plan's 30, 1,000, 8,000 and 200,000, and one call at a time.
- **Admission:** before a call, the executor locks the scope's row in `inference_quotas` and counts the ledger: requests and tokens over the last minute and the last 24 hours, and calls in flight. The call is admitted only if every limit still holds. It reserves the estimated input plus its whole `max_completion_tokens`, capped at the minute limit. Admission and the attempt are created in one savepoint, so a refusal leaves nothing behind.
- **Settlement:** the attempt's end turns the reservation into what Groq reported (input minus cached, plus output). An estimate never counts less than the reservation, a request Groq refused counts nothing, and an unknown outcome keeps the reservation. The reconciler closes calls whose attempt is gone, at their reservation, and prunes the ledger after two days.
- **429:** the scope is blocked for every worker until `retry-after`. No worker calls in between and nothing retries on its own clock.
- **Deletion:** the ledger outlives tasks, so deleting tasks does not free quota.

## The money budget, for paid drivers only

- **Reservation:** before each call to a metered driver, one conditional `UPDATE` for the day and one for the visitor's share, inside the same savepoint as the quota.
- **Settlement:** when the attempt finishes, exactly once (`settled_at`), on the day the reservation was made. Each reservation is charged on one basis:
  - `reported`: confirmed usage;
  - `estimated`: Ripe aborted the stream, so the usage is an estimate;
  - `unbilled`: OpenAI refused the request;
  - `reservation`: the outcome is unknown, so the whole reservation is charged.

  Confirmed and estimated amounts are kept in separate columns.
- **Orphans:** the reconciler settles orphaned reservations conservatively.
- **OpenAI:** prices are kept per model and per tier. A model or tier without a price is refused before any call.
- **Public mode** refuses a metered driver unless `RIPE_ALLOW_PAID_INFERENCE=true`. Nothing falls back from Groq to OpenAI.

## Resting

- A refused admission defers the run without creating an attempt: no attempt is spent, nothing done is redone, and the run resumes on its own.
- The agent status says `resting`, with a reason and, when it is known, `resumesAt`:

| Reason | When | What the greenhouse says |
|---|---|---|
| `capacity` | a per-minute limit or concurrency | a short pause, back at a time when it is known |
| `daily` | a daily limit | resting until the model has room, with a time only if Groq gave one; the journal records it once per episode |
| `budget` | a paid driver's money budget | resting until tomorrow's budget |

- A wrong key or model halts instead, and the greenhouse says it cannot reach its model.
- Resting is drawn as a hibernating mycelium in cool colours, distinct from night and from the grey of offline. Its slow pulse stops under reduced motion.

## Limits, retention and deletion

- **Limits per day:**
  - 8 tasks per visitor, 30 per network (the address the gateway reports) and 400 in all;
  - 20 rewrites per visitor and 60 per network;
  - at most 6 unharvested plants per visitor.

  The network and overall limits hold when the cookie is cleared. The bed cap is checked under a lock on the visitor, so two requests at once cannot both pass it.
- **Retention:**
  - a task is forgotten 30 days after the later of its owner's last visit and its planned return, and never later than 90 days after it was planted;
  - one class computes that date for the API (`expiresAt`, shown on the specimen sheet) and writes the same rule as SQL for `ripe:prune`;
  - visitors without tasks are forgotten after 30 idle days, and examples are never pruned.
- **Delete my greenhouse:** a dialog at the foot of the bed calls `DELETE /api/greenhouse`. That:
  - cancels the visitor's runs, so a late result is fenced;
  - settles open reservations and signals each deletion;
  - deletes the visitor and clears the cookie.

  Deleting a task, a greenhouse and pruning share one action, `ForgetTask`.

## Examples

- **Two examples, both recorded with Groq** (`openai/gpt-oss-120b`) through the real executor on 2026-11-16 (UTC):
  - `lisbon-trip`: a four-day plan in five pieces, from 8 calls and 20,887 tokens. Its questions were all optional, so it ripened with them still open.
  - `late-order-reply`: a reply to a client asking for a refund, in four pieces, from 7 calls and 11,948 tokens. It asked two essential questions, the client's name and the order, and got the matching answers from the catalogue.
- **Recording:**
  - The recorder plays the visitor. It answers essential questions from the catalogue, matching each question to an answer by topic, and fails if nothing matches.
  - It waits whenever the quota asks.
  - It runs in its own database, so no other worker can claim the runs.
- **Archive:**
  - each example keeps every row of its task: steps, dependencies, questions, pieces, versions, runs, attempts and the journal;
  - journal events leave their database id behind, because that id comes from a sequence; their order is the task's own `sequence`;
  - it names the driver, the model and the date;
  - the recording visitor and the recording task are deleted afterwards.
- **Install:**
  - idempotent by checksum, under an advisory lock;
  - a changed fixture replaces the installed example;
  - an unchanged one is left alone.
- **Viewing:** `GET /api/examples` lists the examples, and opening one never calls a model. The specimen sheet says "Recorded from a real run by {model} on {date}".

## What the free plan carries

The Lisbon example used 8 calls and 20,887 tokens in about four minutes, three of them spent waiting. Each call reserves its whole completion budget against the 8,000 tokens a minute, so large calls go out about one a minute.

At that size, the 200,000 tokens a day carry roughly nine tasks a day for everyone. The daily quota, not the task limits, is what bounds the demo. When the quota runs out, the greenhouse rests until the next day.

## Verification

| Check | Result |
|---|---|
| Backend: Pint, Larastan at the maximum level, Pest | pass; 376 tests |
| Frontend: Prettier, ESLint, TypeScript, production build, API types | pass |
| End to end (Playwright, Chromium) | 2 scenarios |
| Live, against Groq | interpret, plan, generate and review pass |
| Browser, local, Groq driver | see below |
| Each commit on a clean clone | see the pull request |

**New backend tests:**
- **Groq driver (18):**
  - the request it sends;
  - real plain and streamed answers, with usage in either place;
  - a cut answer;
  - every error status, including what each costs in quota;
  - the health check on the real model id.
- **Quotas (3):**
  - eight workers racing for a quota that admits fewer;
  - exhaustion that spends no attempt and resumes without redoing work;
  - a 429 that blocks every worker for as long as Groq asks.
- **Money budget (3):**
  - workers racing for the day and for a visitor's share;
  - settlement across midnight on all four bases;
  - the paid-driver guard in public mode.
- **Public demo (3):**
  - the limits with cleared cookies;
  - retention on the announced date, with the 90-day cap and examples kept;
  - deleting a greenhouse during a run.
- **Examples:** recording, export, idempotent install and replacement.

**Browser, against the local stack with the Groq driver:**
- **Resting:** one call was already recorded in the ledger and the daily allowance was set to 1, so admission refused without calling Groq.
  - The greenhouse chip and the task's card said it was resting, and the specimen sheet explained that the free model had used its allowance, with no time promised.
  - The run waited with 0 attempts.
- **A daily allowance of 0** is refused as configuration. The greenhouse showed "can't reach its model" instead of resting.
- **Retention:** the footer stated it, and the sheet showed "kept until Dec 16": 30 days after the planned return, which was later than the visit.
- **Delete my greenhouse:**
  - "Keep it" closed the dialog and kept everything.
  - Through the site, the delete answered 204 and cleared the cookie. The old cookie then saw nothing, and every other visitor's tasks remained.
- **Examples:** both appeared, credited to `openai/gpt-oss-120b` with the day they were recorded.

**Mutations confirmed the tests catch real mistakes:**
- without the `FOR UPDATE` on the quota row, all eight racers got in;
- without the error-code mapping, `json_validate_failed` stays a rejected request;
- with the model id encoded again, the health check fails;
- with journal event ids exported again, planting a task after installing into a fresh database fails on a duplicate key.

## Problems found and fixed

1. **The visitor share would have broken every reservation.** PostgreSQL typed the share's parameter as an integer, which broke the visitor's limit. The race test found it, and the share is now cast to `numeric`.
2. **What the real Groq calls contradicted**, described above: `json_validate_failed`, the quota it costs, the organization in error messages and the encoded model id.
3. **Examples would have broken planting in production.** The archive carried each journal event's database id. Installing them into the development database collided with existing events; installing them into a fresh database would have left the sequence behind, so the first task planted after `composer setup` would have failed. The tests had missed it because they record and install in the same database. Event ids are no longer exported, and the test now installs into a database whose sequence starts at 1.
4. **The example banner** still said it was grown by a scripted model.
5. **Prepared answers given out in order.** The first recording of the reply example would have answered "What is the order number?" with the signature. Answers are now matched by topic.
6. **Stale workers.** An exited dev server had left its workers running with Ollama against the development database; they were stopped, and the examples were recorded in a database of their own.
7. **A comparison that missed a model.** In the OpenAI comparison that preceded the provider change, `gpt-5-nano` was left out. It was cheaper, though deprecated. The comparison no longer decides anything, but the omission is recorded here.
8. **One unexplained timeout.** A subprocess in `ConcurrentJournalTest` timed out once in a full run. It did not happen again in the runs that followed.

## Pending

- **Stage 8:** expected rejections still log at error level; tests and development share `laravel.log`.
- **Stage 9:**
  - `GROQ_API_KEY` set on Railway, with the scheduler running `ripe:prune` and the examples installed on deploy;
  - invisible Turnstile on task creation, with the production site key;
  - the Reverb items carried over from stage 6.
