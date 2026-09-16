# Parity with the Node reference

`mock-api/server.mjs` is the specification. This app is a port of it, function by
function, and this table is how you check that without running either.

Every PHP method carries the mock's own name and an `@mock server.mjs:NN` tag, so
"read `H.createPost` beside `Posts::createPost`" is a five-minute job rather than
an archaeology project.

**The acceptance gate is not this document.** It is `tests/contract.test.mjs`,
44 tests, run against the deployed app:

```bash
GO_API_BASE=https://app.gojobs.biz GO_TEST_TOKEN=... npm run test:remote
```

## Route handlers

| Mock | Route | PHP | Status |
| --- | --- | --- | --- |
| `H.createRun` | `POST /runs` | `Runs::createRun` | ok |
| `H.patchRun` | `PATCH /runs/:runUid` | `Runs::patchRun` | ok |
| `H.postEvents` | `POST /runs/:runUid/events` | `Runs::postEvents` | ok |
| `H.deadLetter` | `POST /dead-letters` | `Runs::deadLetter` | ok |
| `H.getConfig` | `GET /config` | `Meta::getConfig` | ok |
| `H.queueDepth` | `GET /queue-depth` | `Meta::queueDepth` | ok |
| `H.createBatch` | `POST /topic-batches` | `Topics::createBatch` | ok |
| `H.dedupeCheck` | `POST /topics/dedupe-check` | `Topics::dedupeCheck` | ok |
| `H.addTopics` | `POST /topic-batches/:batchUid/topics` | `Topics::addTopics` | ok |
| `H.submitBatch` | `POST /topic-batches/:batchUid/submit` | `Topics::submitBatch` | ok |
| `H.listTopics` | `GET /topics` | `Topics::listTopics` | ok |
| `H.claimTopic` | `POST /topics/:topicUid/claim` | `Topics::claimTopic` | ok |
| `H.createPost` | `POST /posts` | `Posts::createPost` | ok |
| `H.listPosts` | `GET /posts` | `Posts::listPosts` | ok |
| `H.uploadImage` | `POST /posts/:postUid/images` | `Images::uploadImage` | ok |
| `H.submitForReview` | `POST /posts/:postUid/submit-for-review` | `Posts::submitForReview` | ok |
| `H.publishQueueNext` | `GET /publish-queue/next` | `Publish::publishQueueNext` | ok |
| `H.publishLease` | `POST /posts/:postUid/publish-lease` | `Publish::publishLease` | ok |
| `H.publishResult` | `POST /posts/:postUid/publish-result` | `Publish::publishResult` | ok |
| `H.publishedPosts` | `GET /posts/published` | `Metrics::publishedPosts` | ok |
| `H.metricsUpsert` | `POST /metrics:bulk-upsert` | `Metrics::metricsUpsert` | ok |

## Helpers

| Mock | PHP | Note |
| --- | --- | --- |
| `sha256` | `hash('sha256', ...)` | |
| `nowUtc` | `Dt::nowIso()` | and `Dt::nowDb()` for binding |
| `istDate` | `Ist::date()` | UTC+5:30, no DST |
| `bodyWordCount` | `Words::bodyWordCount` | ECMAScript whitespace class, not PCRE `\s` |
| `dedupeHash` | `DedupeHash::of` | lowercase, strip, collapse, trim, hash |
| `SCOPES` / `KEYS` | `ApiKeys::SCOPES` + the `api_keys` table | the mock hardcodes six strings |
| `ApiError` | `ApiError` | `retryable = status === 429 \|\| status >= 500` |
| `sendJson` / `sendError` | `Http::json` / `Http::error` | |
| `readBody` | `Http::readJsonBody` | plus a 413 for a body over `post_max_size` |
| `authenticate` | `AgentAuth::authenticate` | table lookup, constant-time |
| `idempotencyLookup` / `Store` | `Idempotency::begin` / `complete` / `abandon` | reservation row, not read-then-write |
| `applyFault` | `Harness::applyFault` | test builds only |
| `matchRoute` | `Router::match` | same comparator, same sort |
| `runView` / `postStateView` / `imageView` | `Views::run` / `postState` / `image` | |
| `/__mock/*` | `Harness` over `ReviewService` | the UI calls the same methods |

## Where the PHP deliberately differs, and why

Every one of these is a decision, not an accident.

1. **`approved_content_hash` is composed once, not twice.** The mock builds this
   string at approval from a JavaScript array and re-builds it at publish from the
   raw stored column. SQLite returns those bytes unchanged so the mock is green.
   MySQL normalises JSON columns and returns `["#a", "#b"]` where PHP wrote
   `["#a","#b"]`, so a naive port would fail **every** publish with
   `CONTENT_CHANGED_AFTER_APPROVAL` — a silent, total outage. `ContentHash` has one
   private composition and both call sites decode first. See `ContentHash.php`.

2. **A negative `age_days` is stored as null.** A metrics reading dated before the
   post went out yields a negative age. SQLite stores it; a `SMALLINT UNSIGNED`
   column under strict mode rejects it and the whole upsert becomes a 500. Null is
   the honest value: the age of a post on a day before it existed is unanswerable,
   not zero. Caught by the contract suite on the first run against MariaDB.

3. **Locks where the mock reads then writes.** The publish lease, the topic claim
   and the batch submit use a conditional `UPDATE` whose affected-row count is the
   lock; approve-post, the metrics upsert and the dead-letter dedupe use
   `SELECT ... FOR UPDATE`. On a single-threaded Node mock the race cannot happen.
   On a real server two A5 runs could otherwise both lease the same post and
   publish it twice.

4. **`/media/**` requires a session, a bearer key or a signature.** The mock serves
   images to anyone. `publishLease` hands A5 a signed URL, so no agent changed.

5. **A missing `run_uid` on `publishResult` and `metricsUpsert` is a 404, not a
   500.** The mock passes `null` into a `NOT NULL` column, which MySQL rejects as
   an internal error — and `retryable: true` would tell the agent to keep trying a
   permanently bogus id. 404 is not retryable, which is the correct signal.

6. **`publishResult` validates `outcome` against the enum** before insert, so a
   malformed body is a 422 rather than a strict-mode 500.

7. **An empty request body hashes as `[]`, not `{}`.** Internal to the idempotency
   cache and never compared across implementations.

8. **A second request holding an in-flight idempotency key gets 503.** The mock has
   no such state because it cannot have one. `SERVICE_UNAVAILABLE` is retryable, so
   the agent backs off and then receives the replay.

9. **JSON is pretty-printed with four spaces**, not two. Cosmetically different,
   byte-for-byte identical as JSON.

## Schema changes made in Part 2

- The `app_settings` seed uses literal JSON text instead of `JSON_QUOTE()` and
  `CAST(... AS JSON)`. MariaDB has no `CAST(... AS JSON)` and most cPanel hosts run
  MariaDB, so the import would have failed outright. Valid on MySQL 8, MariaDB and
  SQLite alike.
- `schema.part2.sql` adds `users`, `ui_sessions` and `login_attempts`. Nothing in
  the frozen contract was altered.

## The human surface has no counterpart in the mock

`/api/v1/ui/**` is in the contract; the mock never implemented it, and every
human action in the Node suite is a `/__mock/` helper. So there is nothing to be
in parity *with* for these, and three behaviours exist only in PHP:

- **A refused form post is a page, not a JSON envelope.** `UiApp::dispatch`
  catches any `ApiError` under 500 raised by a POST, flashes the message, stashes
  the submitted fields in the session and redirects back (post/redirect/get).
  Going over the 100-word cap returns the review screen with the error at the top
  and the 117 words still in the box. Agents are unaffected: `AgentApi` has its
  own dispatcher and still answers `422 WORD_COUNT_EXCEEDED`.
- **Carriage returns are stripped at the UI boundary.** A browser submits a
  textarea with CRLF line endings; everything A2 writes uses LF. Left alone, one
  human edit rewrites every newline in the post and the bytes that go to LinkedIn
  stop matching the bytes that were reviewed. `Screens::text()` removes them on
  the way in. The agent API does not do this - an agent's bytes are its own.
- **The fold is drawn by the markup, not by JavaScript.** The body is split at
  character 140 in PHP and a block-level rule is emitted at the cut, so the
  preview is correct with scripting switched off.
