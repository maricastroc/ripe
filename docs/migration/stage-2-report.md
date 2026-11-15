# Stage 2 report: inference

Branch `migration/stage-2-inference`, 2026-11-15.

## What changed

```
backend/app/
├── Agent/                      prompts, schemas and results the agent uses
│   ├── Prompts/PromptBook      the original prompts, ported word for word
│   ├── Schemas/                interpretation, plan and review: JSON Schema plus validation
│   ├── Results/                typed results (Interpretation, Plan, Review)
│   ├── Text/Markdown           gap markers, word counts, fences, clipping
│   ├── Brief, AgentRequests, StructuredOutput, SafetyIdentifier
├── Inference/
│   ├── Contracts/              ModelDriver, StreamObserver, CallSupervisor
│   ├── Drivers/OpenAI          Responses API: stream state, error mapping, retry hints
│   ├── Drivers/Ollama          /api/chat NDJSON stream, health, capabilities
│   ├── Drivers/Fake            scripted replies for tests
│   ├── Transport/              supervised curl_multi transport
│   ├── Streaming/              incremental SSE and NDJSON parsers
│   ├── Pricing/                integer cost accounting
│   └── InferenceManager        Laravel Manager: the driver is configuration
├── Enums/Inference/            ErrorScope, FinishReason, ReasoningEffort, TimeoutKind…
├── Exceptions/Inference/       one exception per failure, each with its scope
└── Facades/Inference           Inference::fake() for tests
backend/config/inference.php    driver, budgets, temperatures, per-driver timeouts
```

`run_attempts` gains columns for cached input tokens, cache-write tokens and reasoning tokens, a flag for estimated usage, and the provider's request and response ids.

## How the main decisions were implemented

**Supervised transport (plan, section 4).** `CurlStreamTransport` drives `curl_multi` itself. Every loop iteration waits at most 250 ms whether or not bytes arrive. It then enforces the first-byte, idle and total deadlines on a monotonic clock and calls the `CallSupervisor`. Stage 3's lease supervisor plugs in here; renewal never depends on tokens arriving.

**Error scopes.** Each failure is an exception with a scope:
- transient: `ModelUnavailable`, `RateLimited`;
- attempt: `ModelTimeout`, `ModelCallFailed`, `InvalidModelOutput`;
- run: `ModelRequestRejected`, `ModelRefused`;
- driver: `DriverMisconfigured`, `ProviderQuotaExhausted`.

Failures carry the usage and cost already incurred and the provider's request id. `CallAborted` (cancellation or lease loss) is not a provider error, but it carries the same details.

**OpenAI.**
- Uses the official Responses API, streamed, with `store: false`.
- Sends a `safety_identifier` (an HMAC of the visitor id), plus `reasoning.effort` only for reasoning models and `temperature` only for models that accept it.
- `max_output_tokens` is the visible budget plus a reasoning allowance per effort.
- Structured outputs use strict `json_schema`.
- The final text comes from `response.completed`, while deltas feed the observer.
- `response.incomplete`, `response.failed`, `error` events, refusals and streams without a terminal event each map to their scope.
- 429 rate limits are told apart from credit, spend and usage limits.
- Retry hints come from `Retry-After` and the `x-ratelimit-reset-*` durations.

**Costs.**
- Prices are integers (micro-dollars per million tokens), so there is no floating-point error.
- Cached and cache-written input have their own rates, and reasoning is priced as output.
- `maximumCost()` gives a true ceiling before the call by counting input bytes, an upper bound on tokens, plus the whole `max_output_tokens`. Stage 7's budget reservations will use it.

**Ollama.** Keeps the original behaviour: system message, `format`, `think: false` for thinking models, `keep_alive`, `num_ctx`, and health from `/api/tags` with `:latest` matching. It reports its token counts at zero cost.

**Configuration only.** `InferenceManager` extends Laravel's `Manager`, so `RIPE_INFERENCE_DRIVER=openai|ollama|fake` selects the driver and the rest of the code depends on `ModelDriver`.

**Agent prompts and schemas.**
- The original prompts are ported unchanged, with three intended additions:
  - the request language is stated in every later prompt;
  - questions carry `kind` (`optional` or `essential`);
  - the plan prompt numbers the open essential questions and asks for `buildsOn` and `requires`.
- `PlanSchema` enforces the dependency policy deterministically:
  - steps build only on earlier steps;
  - only open essential questions can hold a step back;
  - a final step without dependencies builds on every earlier step;
  - at least one step can always start.
- Every schema stays inside the subset both providers accept in strict mode, checked by `StrictSchema`.

## Verification

| Check | Result |
|---|---|
| Pint, Larastan at the maximum level | pass |
| Default suite | 390 tests, 836 assertions: Unit 104, Feature 180, Inference 95, Integration 11 |
| Contract suite | 27 cases: the three drivers return the same text, the same structured output and the same failures with the same scopes |
| OpenAI driver | 35 cases, including every documented HTTP error (rate limits versus credit, spend and usage limits, credentials, region, unknown model, configuration parameters, oversized input, server errors, overload) and every stream outcome |
| Socket-level transport | real HTTP server: silence longer than a lease keeps being supervised (no gap above 0.6 s), an abort during silence stops in under 1.6 s, first-byte, idle and total timeouts, refused connection, failing body handler |
| Drivers over real HTTP | the OpenAI and Ollama drivers read fixture streams served over a socket |
| Prompt parity with the TypeScript original | `frontend/src/agent/prompts.ts` run with `tsx` on the same brief: the system prompt is identical, and every other difference is one of the intended additions |
| Live run against local Ollama (`gemma4:e4b`) | interpretation (language `pt`, 3 questions), a 3-step plan, a 178-word first piece with 2 `[[TBD]]` markers, a review with verdict `almost` |
| Live run against OpenAI | skipped: no API key in this environment |

Fixtures follow the event and error shapes in OpenAI's documentation: `obfuscation` fields on deltas, `usage` that is null on failed and incomplete events, `cache_write_tokens` in the usage details. They are reproducible with `php tests/Fixtures/generate.php tests/Fixtures`.

## Problems found and fixed

1. **Fixture generator.** It merged arrays with `+`, which keeps the left-hand keys, so the final events lost their output and usage. It now uses `array_replace`.
2. **Test server queueing.** `php -S` serves one request at a time and keeps sleeping after a client gives up, so a test that aborted during a long silence delayed the next one into a false timeout. Enabling `PHP_CLI_SERVER_WORKERS` fixed that but left orphaned workers holding the test runner's pipe, which hung the run. Each test now gets its own server, started with its output discarded and stopped immediately.
3. **Over-strict review parsing.** One incomplete issue rejected the whole review. Incomplete issues are now dropped instead.
4. **Laravel architecture preset.** Pest's preset requires enums in `App\Enums` and exceptions in `App\Exceptions`, so the inference enums and exceptions moved to `App\Enums\Inference` and `App\Exceptions\Inference`.
5. **Pest datasets.** Datasets are evaluated before the application boots, so the live test checks driver availability inside the test.

Two failing expectations turned out to be wrong in the test, not in the code:
- a cut point in the middle of the opening events, where no text had been streamed yet;
- a plan where the code correctly applied the "at least one step can start" rule.

## Pending

- **OpenAI live run:** needs an API key and a model. It runs with `php artisan test --group=live` once `OPENAI_API_KEY` and `OPENAI_MODEL` are set.
- **OpenAI configuration (stage 7):** the model catalogue (`inference.drivers.openai.models`, with prices and capabilities) is empty until stage 7 chooses the model. Unlisted models are treated conservatively (reasoning enabled, no temperature, no price, so no cost and no ceiling). The reasoning allowance per effort is a configurable default to tune in stage 7.
- **Lease supervisor (stage 3):** the database-backed supervisor arrives in stage 3, plugged into the `CallSupervisor` hook that this stage already exercises.
