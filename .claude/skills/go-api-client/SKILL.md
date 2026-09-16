---
name: go-api-client
description: How every Golden Opportunities agent talks to the approval web app - opening a run, the command list, retry and idempotency rules, and what each error code means. Load before making any API call.
---

# Talking to the approval app

Never hand-roll curl. Use the client, so that auth, idempotency keys, retry
policy and the IST business date behave identically for all six agents:

```bash
node lib/goapi.mjs <command> [args] [--flags]
node lib/goapi.mjs help
```

It reads `GO_API_BASE` (falling back to `config/pipeline.config.json`) and
`GO_API_KEY_<AGENT>`. In testing that is the mock on port 8787; in production it
is the cPanel app. **Nothing else in an agent changes between the two.**

## Always open a run first

Every other endpoint needs a `run_uid`, and the run is what makes a silent
failure visible later.

```bash
RUN=$(node lib/goapi.mjs run-open A1 | node -pe "JSON.parse(require('fs').readFileSync(0)).run_uid")
```

**Exit code 3 means `already_running`** - another instance owns today's slot.
That is not an error. Log it and exit 0.

Always close the run, including when there was nothing to do:

```bash
node lib/goapi.mjs run-close "$RUN" --agent A1 --status succeeded --in 47 --ok 47
node lib/goapi.mjs run-close "$RUN" --agent A2 --status skipped --reason no_approved_topics
```

A run that did nothing still writes a row. That is the point: absence has to be
an event, or a stopped pipeline goes unnoticed for weeks.

## Commands by agent

| Agent | Commands |
| --- | --- |
| all | `run-open` `run-close` `event` `config` `dead-letter` |
| A1 | `queue-depth` `batch-create` `dedupe-check` `topics-add` `batch-submit` |
| A2 | `topics-list` `topic-claim` `post-create` |
| A3 | `posts-list --state images_pending` `image-upload` |
| A4 | `posts-list --state images_ready` `submit-review` |
| A5 | `publish-next` `publish-lease` `publish-result` |
| A6 | `published` `metrics-upsert` |

A key that reaches for another agent's route gets **403 FORBIDDEN_SCOPE**. That
is by design: A1 cannot publish, A3 cannot touch copy, A6 holds no write scope on
LinkedIn at all.

## Retry rules

The client already applies these. You do not need to loop.

- Every error carries `retryable`. **Branch on that flag, not on the status code.**
- `retryable: true` (429, 5xx, network) is retried three times with backoff.
- `retryable: false` (400, 401, 403, 404, 409, 422) is returned immediately.
  **Do not retry it. Do not reword the request to get around it.** Fix the input,
  or record a dead letter and move on.

If five calls in a row fail as retryable, stop the whole run, close it `failed`,
and write one dead letter. Do not grind for an hour.

## Idempotency

Write commands send a deterministic `Idempotency-Key`, so a retry after an
ambiguous timeout replays the stored response rather than doing the work twice.
This is what stops a network blip becoming two posts on the company page.

The response header `Idempotency-Replayed: true` means it was a replay. Treat it
as success.

## Error codes worth recognising

| Code | Means | Do |
| --- | --- | --- |
| `DUPLICATE_RUN_SLOT` | another instance owns today's run | exit 0, log it |
| `TOPIC_NOT_APPROVED` | the owner has not approved it, or put it on hold | skip the topic |
| `LEASE_HELD_BY_OTHER_RUN` | someone else is working on this item | skip it |
| `WORD_COUNT_EXCEEDED` | the body is over 100 words | re-draft, never truncate |
| `LINK_IN_BODY` | a URL is in the post body | move it to `first_comment_text` |
| `SHA256_MISMATCH` | the image bytes arrived corrupt | re-download, do not retry blind |
| `POST_NOT_APPROVED` | not approved at gate 2 | do not publish |
| `NO_IMAGE_SELECTED` | the owner has not chosen an image | do not publish |
| `CONTENT_CHANGED_AFTER_APPROVAL` | edited after it was approved | do not publish; it needs re-approval |
| `ALREADY_POSTED` | it is already live | stop; never publish a second time |
| `POST_EXPIRED` | the package is older than 45 days | do not publish |

The last five are the ones that protect the company page. When you see them, the
correct action is always to stop, never to work around them.

## Recording a failure you cannot fix

```bash
node lib/goapi.mjs dead-letter --agent A3 --stage image_generation \
  --error-code HIGGSFIELD_EMPTY --error-message "no image after 2 retries" \
  --entity-type post --entity-uid "$POST_UID" --payload work/prompt.json
```

Repeat failures deduplicate into one row with a rising `attempts` count, so six
bad days are one item on the owner's ops list rather than six.
