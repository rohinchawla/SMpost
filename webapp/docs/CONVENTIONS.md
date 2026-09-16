# Conventions every handler follows

Read this before writing or reviewing any PHP in `app/`.

## The shape of a handler

```php
/** @mock mock-api/server.mjs:NNN-NNN (mockFunctionName) */
public static function mockFunctionName(array $ctx): array
{
    // ... returns [int $status, array|null $payload]
}
```

`$ctx` carries exactly:

| key | type | notes |
| --- | --- | --- |
| `agent` | string | `A1`..`A6`, from the bearer key. Never from the body |
| `body` | array | decoded JSON, `[]` when empty |
| `query` | array | `$_GET` |
| `params` | array | path parameters, e.g. `['postUid' => '...']` |

Return `[204, null]` for no content. Everything else returns
`[$status, ['data' => ...]]`, plus `'replayed' => true` where the mock sets it.

## Naming

**PHP method names are the mock's own names**, so the two can be diffed function
by function: `createRun`, `patchRun`, `postEvents`, `deadLetter`, `getConfig`,
`queueDepth`, `createBatch`, `dedupeCheck`, `addTopics`, `submitBatch`,
`listTopics`, `claimTopic`, `createPost`, `listPosts`, `uploadImage`,
`submitForReview`, `publishQueueNext`, `publishLease`, `publishResult`,
`publishedPosts`, `metricsUpsert`.

Every method opens with `@mock mock-api/server.mjs:NN-NN` naming the function it
ports.

## The classes that already exist. Use them; do not re-implement.

| Class | Use for |
| --- | --- |
| `ApiError` | every failure. `::notFound`, `::validation`, `::conflict($code,$msg,$details)`, `::unprocessable($code,$msg,$details)`, `::forbidden`. The property is `$errorCode`, not `$code` |
| `Db` | `::row`, `::all`, `::one`, `::exec`, `::limit($sql,$params,$limit)`, `::tx(fn)`, `::isDuplicate($pdoException)`, `::pdo()` |
| `Dt` | `::nowDb()` for binding into DATETIME(3); `::nowIso()` and `::iso($dbValue)` for JSON; `::plusSecondsDb($s)` |
| `Ist` | `::date()`, `::datePlusDays($n)`, `::daysBetween($a,$b)` |
| `Canon` | `::encode`, `::decode($json,$default)`, `::tags($array)` |
| `Words` | `::bodyWordCount`, `::hasUrl`, `::commentary($body,$tags)` |
| `DedupeHash` | `::of($title)` |
| `ContentHash` | `::atApproval($body,$imageId,$tags)`, `::atLease($postRow)` |
| `Settings` | `::int`, `::str`, `::configPayload()` |
| `Views` | `::run($row)`, `::postState($row)`, `::image($row)`, `::requireRun($uid)` |
| `Uuid` | `::v4()` |
| `Js` | `::trim`, `::words` — JavaScript-compatible, for anything the mock does with `\s` |

## Rules that are not negotiable

1. **Every SQL statement is a prepared statement with bound parameters.** No
   string interpolation into SQL, ever, including for `ORDER BY`.
2. **A `LIMIT` must be bound as an int** (`Db::limit`), because prepares are real
   and MySQL rejects `LIMIT '50'`.
3. **Timestamps**: bind `Dt::nowDb()`, render `Dt::iso()`. MySQL `DATETIME(3)`
   rejects ISO-8601 `...Z` under strict mode, which is on.
4. **Null-safe SQL equality is `<=>`**, not `IS ?`.
5. **Error codes and messages are copied from the mock verbatim**, including the
   wording, because the contract tests read `error.code` and agents branch on it.
6. **`details` on an error is an array**; `Http` casts it to an object so an empty
   one serialises as `{}` and not `[]`.
7. **Validation order matters** where the mock has one. `createPost` checks word
   count, then URL, then hashtag count. A test asserts the first failure.
8. Every file starts with `<?php declare(strict_types=1);` and the line
   `if (!defined('GO_BOOT')) { http_response_code(404); exit; }`, and ends
   **without** a closing `?>`.

## Concurrency

The mock reads then writes, which is a race on a real server. Where the mock does
that, use one of:

- a **conditional `UPDATE`** whose `rowCount()` is the lock (topic claim, publish
  lease, batch submit), or
- `SELECT ... FOR UPDATE` inside `Db::tx()` (approve post, metrics upsert,
  dead-letter dedupe), or
- let a **unique index** be the arbiter and catch `Db::isDuplicate()` (run slot,
  publish attempt, image option).

`Db::connect` sets `MYSQL_ATTR_FOUND_ROWS`, so `rowCount()` is rows *matched* —
which is what a conditional lock needs.

## Comments

Explain why, not what. A comment earns its place when it records a decision, a
trap, or something that looks wrong and is not. Do not narrate the code.
