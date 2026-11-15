# Testing

A test is written only when it is indispensable. Every test must protect a guarantee that would hurt if it broke, and that nothing else already protects. Coverage of every small feature is not a goal: tests that repeat each other or check trivia slow the suite down, hide the tests that matter, and make every change more expensive.

## Write a test for

- **Invariants:** no plant growth without a persisted result, immutable versions, a gap-free journal.
- **Leases, fencing and delivery:** expired leases stay expired, late results are rejected, duplicate jobs do nothing.
- **Concurrency:** races between processes, workers that die, commits that race the sweeper.
- **Security boundaries:** visitor isolation, the origin policy, cookie attributes, rate limits.
- **Data integrity the code relies on:** one running run per task, one version per run, references that never cross tasks.
- **External contracts:** how each provider's responses and errors map to scopes, and how money is counted.
- **The public contract:** each API operation, its response shape and its effect.
- **A bug that actually happened:** the regression test stays.

## Do not write a test for

- Framework or library behaviour, such as routing, validation rules or the health endpoint.
- Trivial validation, getters, configuration mapping or the existence of tables and files.
- Test doubles such as the fake driver.
- Behaviour already proven at another layer. Operations are tested through the API. An action gets its own test only for rules the API cannot reach.
- Dataset rows that take the same code path as another row. Each row must hit a different branch.

## How to write it

- Prefer one scenario that asserts several facts over many small tests with the same setup.
- Test through the outermost layer that proves the guarantee.
- Use real processes only for races and crashes.
- End every orchestration scenario with `expectJournalConsistent()`.

## Before adding a test

Ask: if this test did not exist, what would break, and who would notice? If the answer is "another test would catch it" or "nothing that matters", do not add it. When reviewing a change, removing a redundant test is as welcome as adding a necessary one.

The whole backend suite should stay under two minutes.
