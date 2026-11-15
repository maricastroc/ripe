# Stage 6 report: realtime

Branch `migration/stage-6-realtime`, 2026-11-15.

## What changed

```
backend/app/Realtime/        signals after commit, the relay to Reverb and its pause
backend/app/Broadcasting/    who may join private-task.{id} and private-greenhouse.{visitor}
backend/app/Jobs/PublishSignal.php
backend/routes/channels.php
backend/config/reverb.php, broadcasting.php
frontend/src/lib/realtime.ts       the Echo client, authorized through the gateway
frontend/src/lib/live.ts           live text reconciliation, as pure functions
frontend/src/hooks/useRealtime.ts  connection and channel state
frontend/src/hooks/usePolling.ts   monotonic snapshots and the realtime interval
frontend/e2e/realtime.spec.ts      push, outage, polling and reconnection
```

- **Packages:** `laravel/reverb` 1.13 in the backend, and `laravel-echo` 2.6.3 with `pusher-js` 8.6.0 in the frontend, pinned exactly.
- **Processes:** `composer dev` also starts Reverb on port 8080 and a worker for the new `realtime` queue, when `BROADCAST_CONNECTION=reverb`. Without Reverb configured, nothing is queued and the site polls as before.
- **Routes:** `POST /api/broadcasting/auth`, the 23rd route, goes through the gateway like any other call and is documented in OpenAPI. The garden snapshot now names the visitor's greenhouse channel, because the cookie is HttpOnly and the browser cannot know its own visitor id.
- **Reverb:**
  - private channels only, with client events off;
  - allowed origins come from the hosts in `RIPE_ALLOWED_ORIGINS`;
  - the secret is derived from `APP_KEY` when `REVERB_APP_SECRET` is empty, so nothing secret sits in the repository;
  - the app key the browser uses is public by design.
- **Settings at request time:** the site reads `RIPE_REALTIME_*` when it renders, not at build time, so the same build works on any host.

Two choices differ from the approved plan's wording, both because of adjustment 3:
- signals use their own `realtime` queue instead of `default`;
- live text is queued like every other signal instead of being broadcast from the executor with `ShouldBroadcastNow`.

## Signals only after the commit (adjustment 1)

- **Registering a change:** `TaskEvent::record`, the only place that raises `tasks.version`, registers its task's change with `DB::afterCommit`. Laravel runs those callbacks only for transactions that actually commit, savepoints included, and drops them on rollback.
- **Sending:** the changes are merged per task, keeping the highest version. The batch goes out on `TransactionCommitted`, which Laravel fires after the callbacks. Each task gets one `task.changed {taskId, version, deleted}` with its final committed version.
- **Deletion:** `DeleteTask` registers the deletion with the visitor and version taken from the model before the row is gone. The signal is built from that data, never from a query after the delete.
- **No signal on rollback:** a rolled-back transaction, or a rolled-back deletion, sends nothing.
- **Failures:** an error while queueing a signal is logged and swallowed, so a committed change never reports an error to its caller.

## Live text reconciliation (adjustment 2)

The snapshot's `tasks.version` decides which snapshot is newer, and a snapshot older than the one on screen is never applied. Live text has its own rules, in `lib/live.ts`:

- **The attempt that counts** is the one the latest snapshot names. A running attempt with no text saved yet now appears with empty text, so the browser knows it from the start.
- **A delta** is appended only when it belongs to that attempt and its offset matches the length on screen:
  - an overlapping delta adds only its new part;
  - a duplicate adds nothing;
  - a gap, or a delta for an attempt the snapshot has not named yet, makes the browser refetch instead of guessing.
- **A snapshot** of the same attempt replaces the text only when it is longer. A snapshot with the same task version but shorter or missing text, fetched before newer deltas arrived, leaves the text alone. Only a strictly newer version can clear it.
- **A new attempt** in a newer snapshot replaces the previous attempt's text.
- **Offsets** count characters as PHP's `mb_strlen` does; the browser counts code points, so accents and emoji agree.

## Reverb off the critical path (adjustment 3)

- **The executor never talks to Reverb.** Each 0.25 s save of partial text also queues the new part. That is a database insert, like the save itself. The API queues `task.changed` the same way.
- **One worker publishes.** Each send has a one-second timeout. After a failure the worker pauses (15 s by default) and drops what arrives in the meantime; signals that have gone stale (5 s for text, 60 s for changes) are dropped too.
- **Logs:** an outage writes one warning and the recovery one info line, with the count of dropped signals. Nothing goes to `failed_jobs`.
- **The browser detects a server that hangs** through the WebSocket ping, now within about 20 s instead of 60. It then polls at the usual intervals.

## Channel authorization (adjustment 4)

- **Policy:** `private-task.{id}` follows the task's `view` policy, so its owner and, for examples, any visitor may join. `private-greenhouse.{visitor}` only lets that visitor join.
- **Refused with 403:** another visitor; a browser without a cookie; an unknown task id; a malformed id, which never reaches PostgreSQL; a wrong-case id; extra segments; and any other channel. An unknown task and another visitor's task get the same refusal.
- **Private names only:** Laravel matches channels after removing both `private-` and `presence-`, so a `presence-task.{id}` request would have been signed for the owner. The request now only accepts `private-` names (422 otherwise).
- **Unchanged protections:** the stage 4 origin check and the stage 5 gateway apply to this route like any other: cross-site requests and requests without the secret are refused.

## Polling, kept

- **While subscribed:** the page polls every 30 s. "Subscribed" means the socket is connected and the channel joined. A refused subscription, or a lost connection, brings back the usual intervals at once.
- **On reconnect:** the subscription is reset when the connection drops. After reconnecting, the page re-subscribes and refetches the snapshot, which restores the version and the live text.
- **Request coalescing:** signals ask for a refresh that is coalesced over 150 ms. Saving a snapshot from an action cancels the poll in flight.
- **Reduced motion:** the live text no longer animates its reveal under reduced motion.

## Verification

| Check | Result |
|---|---|
| Backend: Pint, Larastan at the maximum level, Pest | pass; 350 tests |
| Frontend: Prettier, ESLint, TypeScript, production build, API types | pass |
| End to end (Playwright, Chromium) | 2 scenarios, 3 repetitions in a row with no failure |

**New backend tests:**
- **Signal timing (2 tests):** one signal per task, after the commit, with the committed version; nothing for a full rollback; the final version after a savepoint rollback; a deletion signalled without its row; nothing for a rolled-back deletion.
- **Channel isolation (1 scenario):** both channels, with every case above.
- **Live text (2 tests):**
  - the executor only queues text, and offsets leave no gap, with accents and an emoji;
  - over real sockets, a Reverb that hangs costs one bounded attempt per pause across several pauses; one that refuses costs nothing; publishing resumes when it is back; stale signals are dropped.
- **Takeover:** the existing takeover test now also checks that a superseded attempt relays nothing more.

**Mutations confirmed the tests catch real mistakes:**
- sending signals before the commit fails the signal tests;
- removing the Pusher request timeout makes the outage test wait 30 s and fail.

**End to end:**
- With the channel live, a task planted from the same visitor appears within 3 s. The next poll is 30 s away, so only a push can explain it.
- With Reverb stopped, the page switches to polling and still sees the next task ripen.
- With Reverb back, it goes live again and pushes resume.

**Browser, local Ollama (`gemma4:e4b`):** a newsletter task streamed while Reverb was disturbed in three ways. A monitor compared the browser's live text with the server's once a second, 223 times, and all 223 agreed: no regression, no duplication and no loss.

| Event | What happened |
|---|---|
| Normal streaming | text arrived over the socket; the page made 5 requests in 39 s |
| Reverb killed mid-stream | the page polled at once and every 1.3 s after that; the same attempt kept growing from snapshots (0 → 1492 characters) |
| Reverb down for longer | the relay logged one warning, queued nothing, failed nothing |
| Reverb restarted | pusher-js reconnected after its own delay (22 s), authorized through the gateway, refetched the snapshot and went back to 30 s polling; the attempt went on to 2896 characters, then the next attempt replaced it |
| Reverb frozen (SIGSTOP) | the executor carried on: a rewrite finished and the next one started; the relay paused after one 1 s timeout; the browser noticed after about 60 s and caught up from 692 to 2763 characters of the same attempt. With the shorter ping settings, detection took 22 s; after the resume, the page went live again at once |

## Problems found and fixed

1. **The Pusher client's own timeout.** The Pusher PHP SDK sends `timeout` (30 s by default) with every request, which overrides Guzzle's. A Reverb that accepts connections but never answers would have held each publish for 30 s. The timeout is now set in both places, and the outage test runs against a real socket that never answers.
2. **Presence names.** Described under adjustment 4.
3. **"Live" without a channel.** With no channel name yet, `null === null` made the page think it was subscribed as soon as the socket connected.
4. **A stale subscription after reconnecting.** The page counted as live again as soon as the socket reconnected, before re-subscribing. The first end-to-end run failed on it; the subscription is now cleared when the connection drops.
5. **Hand-written response types.** `Garden` and `TaskPage` were still declared by hand, so the new `channel` field would have been missed. They now come from the OpenAPI operations.
6. **Channels belong to the broadcaster that was default at boot.** In tests the default is `null`, so the channel test registers the channels again after switching to Reverb. Production boots with Reverb.
7. **Planting before hydration.** The first version of the realtime scenario clicked the seed packet before React had hydrated, and the form submitted natively. That scenario now plants through `fetch`; the other one still covers the seed packet.

## Pending

- **Stage 7:** Reverb's per-connection rate limits and maximum connections for the public demo.
- **Stage 8:**
  - expected rejections still go to the log at error level;
  - the test suite and the end-to-end backend write to the same `laravel.log` as development, which made one log line misleading here.
- **Stage 9:**
  - Reverb on its own Railway host behind TLS (`wss`), with `RIPE_REALTIME_*` on Vercel pointing to it;
  - `REVERB_APP_KEY` and `REVERB_APP_SECRET` set explicitly;
  - the API publishing to Reverb over the private network.
