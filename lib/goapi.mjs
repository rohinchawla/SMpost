#!/usr/bin/env node
/**
 * Command-line client for the approval app.
 *
 * Agents call this rather than hand-rolling curl, so that auth, idempotency keys,
 * retry policy and the IST business date are decided in one place and behave the
 * same for all six. Every command prints JSON on stdout and exits non-zero on a
 * hard failure.
 *
 *   node lib/goapi.mjs <command> [args] [--flag value]
 *   node lib/goapi.mjs help
 *
 * Reads GO_API_BASE and GO_API_KEY_<AGENT> from the environment, falling back to
 * config/pipeline.config.json and the mock's test keys.
 */
import { readFileSync } from 'node:fs';
import { createHash, randomUUID } from 'node:crypto';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const CONFIG = JSON.parse(readFileSync(resolve(ROOT, 'config/pipeline.config.json'), 'utf8'));
const BASE = process.env.GO_API_BASE || CONFIG.api.baseUrl;

const argv = process.argv.slice(2);
const cmd = argv[0];
const positional = argv.slice(1).filter((a) => !a.startsWith('--'));
const flag = (name, dflt = null) => {
  const i = argv.indexOf(`--${name}`);
  if (i < 0) return dflt;
  const next = argv[i + 1];
  return next && !next.startsWith('--') ? next : true;
};

export const istDate = (d = new Date()) =>
  new Date(d.getTime() + 5.5 * 3600 * 1000).toISOString().slice(0, 10);

const keyFor = (agent) =>
  process.env[`GO_API_KEY_${agent}`] || `go_test_${agent.toLowerCase()}_key`;

const sha256 = (s) => createHash('sha256').update(s).digest('hex');

const RETRYABLE = new Set([429, 500, 502, 503, 504]);
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

/**
 * One HTTP call with the shared retry policy. Retries only what the server said
 * is retryable, so a 422 stops immediately instead of burning three attempts.
 */
async function call(method, path, { agent, body, idempotencyKey, attempts = 3 } = {}) {
  const headers = { 'Content-Type': 'application/json' };
  if (agent) headers.Authorization = `Bearer ${keyFor(agent)}`;
  if (idempotencyKey) headers['Idempotency-Key'] = idempotencyKey;

  const backoff = [1000, 4000, 12000];
  let last;
  for (let i = 0; i < attempts; i++) {
    try {
      const res = await fetch(`${BASE}${path}`, {
        method, headers,
        body: body === undefined ? undefined : JSON.stringify(body),
        signal: AbortSignal.timeout(CONFIG.api.timeoutMs),
      });
      const text = await res.text();
      const parsed = text ? JSON.parse(text) : null;
      if (res.ok || res.status === 204) return { status: res.status, body: parsed };
      last = { status: res.status, body: parsed };
      const retryable = parsed?.error?.retryable ?? RETRYABLE.has(res.status);
      if (!retryable) return last;
    } catch (e) {
      last = { status: 0, body: { error: { code: 'NETWORK', message: e.message, retryable: true } } };
    }
    if (i < attempts - 1) await sleep(backoff[i] + Math.floor(Math.random() * 500));
  }
  return last;
}

const readJson = (p) => JSON.parse(p === '-' ? readFileSync(0, 'utf8') : readFileSync(p, 'utf8'));
const out = (v) => { console.log(JSON.stringify(v, null, 2)); };
const die = (v, code = 1) => { console.log(JSON.stringify(v, null, 2)); process.exit(code); };

const COMMANDS = {
  /** Opens a run. A 409 means another instance already owns today's slot. */
  async 'run-open'([agent]) {
    const run_uid = randomUUID();
    const r = await call('POST', '/api/v1/agent/runs', {
      agent,
      body: {
        run_uid, agent_code: agent,
        business_date_ist: flag('date') || istDate(),
        attempt: Number(flag('attempt', 1)),
        trigger_type: flag('trigger', 'schedule'),
        dry_run: flag('dry-run') ? true : false,
      },
    });
    if (r.status === 409) die({ ok: false, reason: 'already_running', detail: r.body.error }, 3);
    if (r.status >= 300) die({ ok: false, error: r.body?.error }, 1);
    out({ ok: true, run_uid, config: r.body.data.config ?? null });
  },

  async 'run-close'([runUid]) {
    const r = await call('PATCH', `/api/v1/agent/runs/${runUid}`, {
      agent: flag('agent'),
      body: {
        status: flag('status', 'succeeded'),
        skip_reason: flag('reason') || null,
        items_in: Number(flag('in', 0)), items_ok: Number(flag('ok', 0)),
        items_failed: Number(flag('failed', 0)), items_skipped: Number(flag('skipped', 0)),
        error_code: flag('error-code') || null,
        error_message: flag('error-message') || null,
      },
    });
    r.status >= 300 ? die({ ok: false, error: r.body?.error }) : out({ ok: true, run: r.body.data });
  },

  async event([runUid]) {
    const r = await call('POST', `/api/v1/agent/runs/${runUid}/events`, {
      agent: flag('agent'),
      body: {
        level: flag('level', 'info'), event_code: flag('code', 'note'),
        message: flag('message') || null,
        entity_type: flag('entity-type', 'none'), entity_uid: flag('entity-uid') || null,
      },
    });
    out({ ok: r.status < 300, ...r.body?.data });
  },

  /** Back-pressure check. A1 calls this first and skips the week if told to. */
  async 'queue-depth'() {
    const r = await call('GET', '/api/v1/agent/queue-depth', { agent: flag('agent', 'A1') });
    r.status >= 300 ? die({ ok: false, error: r.body?.error }) : out(r.body.data);
  },

  async config() {
    const r = await call('GET', '/api/v1/agent/config', { agent: flag('agent', 'A1') });
    out(r.body?.data);
  },

  async 'dead-letter'() {
    const r = await call('POST', '/api/v1/agent/dead-letters', {
      agent: flag('agent'),
      body: {
        stage: flag('stage'), error_code: flag('error-code', 'UNKNOWN'),
        error_message: flag('error-message', ''),
        entity_type: flag('entity-type', 'none'), entity_uid: flag('entity-uid') || null,
        payload: flag('payload') ? readJson(flag('payload')) : {},
      },
    });
    out({ ok: r.status < 300, ...r.body?.data });
  },

  // --- A1 ------------------------------------------------------------------
  async 'batch-create'([runUid]) {
    const batch_uid = randomUUID();
    const date = flag('date') || istDate();
    const r = await call('POST', '/api/v1/agent/topic-batches', {
      agent: 'A1', idempotencyKey: sha256(`A1:batch:${date}`),
      body: { batch_uid, run_uid: runUid, business_date_ist: date,
              title: flag('title') || `HR topics ${date}`,
              sources: flag('sources') ? readJson(flag('sources')) : null },
    });
    r.status >= 300 ? die({ ok: false, error: r.body?.error }) : out({ ok: true, batch_uid: r.body.data.batch_uid });
  },

  /** Ask which of these titles were already proposed in the last year. */
  async 'dedupe-check'([file]) {
    const titles = readJson(file);
    const r = await call('POST', '/api/v1/agent/topics/dedupe-check', {
      agent: 'A1', body: { titles: Array.isArray(titles) ? titles : titles.titles, days: Number(flag('days', 365)) },
    });
    out(r.body?.data);
  },

  async 'topics-add'([batchUid, runUid, file]) {
    const topics = readJson(file);
    const r = await call('POST', `/api/v1/agent/topic-batches/${batchUid}/topics`, {
      agent: 'A1',
      body: { run_uid: runUid, topics: (Array.isArray(topics) ? topics : topics.topics).map((t) => ({ topic_uid: t.topic_uid || randomUUID(), ...t })) },
    });
    r.status >= 300 ? die({ ok: false, error: r.body?.error }) : out(r.body.data);
  },

  async 'batch-submit'([batchUid]) {
    const r = await call('POST', `/api/v1/agent/topic-batches/${batchUid}/submit`, { agent: 'A1', body: {} });
    r.status >= 300 ? die({ ok: false, error: r.body?.error }) : out({ ok: true, ...r.body.data });
  },
};

// --- A2 --------------------------------------------------------------------
Object.assign(COMMANDS, {
  /** Only ever returns topics the owner approved. There is no flag to widen it. */
  async 'topics-list'() {
    const r = await call('GET', `/api/v1/agent/topics?limit=${Number(flag('limit', 50))}`, { agent: 'A2' });
    r.status >= 300 ? die({ ok: false, error: r.body?.error }) : out(r.body);
  },

  async 'topic-claim'([topicUid, runUid]) {
    const r = await call('POST', `/api/v1/agent/topics/${topicUid}/claim`, {
      agent: 'A2', body: { run_uid: runUid, lease_seconds: Number(flag('seconds', 1800)) },
    });
    if (r.status === 409) die({ ok: false, reason: 'not_claimable', detail: r.body.error }, 3);
    r.status >= 300 ? die({ ok: false, error: r.body?.error }) : out({ ok: true, ...r.body.data });
  },

  async 'post-create'([runUid, file]) {
    const p = readJson(file);
    const post_uid = p.post_uid || randomUUID();
    const r = await call('POST', '/api/v1/agent/posts', {
      agent: 'A2', idempotencyKey: sha256(`A2:post:${p.topic_uid}:${p.revision ?? 1}`),
      body: { ...p, post_uid, run_uid: runUid },
    });
    r.status >= 300 ? die({ ok: false, error: r.body?.error }) : out({ ok: true, post_uid, ...r.body.data });
  },

  // --- A3 / A4 -------------------------------------------------------------
  async 'posts-list'() {
    const state = flag('state', 'images_pending');
    const agent = flag('agent', 'A3');
    const r = await call('GET', `/api/v1/agent/posts?lifecycle_state=${state}`, { agent });
    r.status >= 300 ? die({ ok: false, error: r.body?.error }) : out(r.body);
  },

  /**
   * Uploads one image option. The bytes are hashed locally and the server
   * re-hashes what it received, so a truncated download fails here rather than
   * showing up as a grey box in the review screen a week later.
   */
  async 'image-upload'([postUid, runUid, metaFile]) {
    const meta = readJson(metaFile);
    const bytes = readFileSync(flag('file'));
    const image_uid = meta.image_uid || randomUUID();
    const r = await call('POST', `/api/v1/agent/posts/${postUid}/images`, {
      agent: 'A3', idempotencyKey: sha256(`A3:image:${postUid}:${meta.option_index}`),
      body: {
        ...meta, image_uid, run_uid: runUid,
        data_base64: bytes.toString('base64'),
        sha256: sha256(bytes),
        bytes: bytes.length,
      },
    });
    r.status >= 300 ? die({ ok: false, error: r.body?.error }) : out({ ok: true, ...r.body.data });
  },

  async 'submit-review'([postUid, runUid]) {
    const r = await call('POST', `/api/v1/agent/posts/${postUid}/submit-for-review`, {
      agent: 'A4', body: { run_uid: runUid },
    });
    r.status >= 300 ? die({ ok: false, error: r.body?.error }) : out({ ok: true, ...r.body.data });
  },

  // --- A5 ------------------------------------------------------------------
  async 'publish-next'() {
    const r = await call('GET', `/api/v1/agent/publish-queue/next?date=${flag('date') || istDate()}`, { agent: 'A5' });
    if (r.status === 204) { out({ ok: true, empty: true, reason: 'nothing due today' }); return; }
    r.status >= 300 ? die({ ok: false, error: r.body?.error }) : out({ ok: true, ...r.body.data });
  },

  async 'publish-lease'([postUid, runUid]) {
    const r = await call('POST', `/api/v1/agent/posts/${postUid}/publish-lease`, {
      agent: 'A5', body: { run_uid: runUid, lease_seconds: Number(flag('seconds', 900)) },
    });
    if (r.status >= 400 && r.status < 500) die({ ok: false, refused: true, error: r.body?.error }, 3);
    r.status >= 300 ? die({ ok: false, error: r.body?.error }) : out({ ok: true, ...r.body.data });
  },

  async 'publish-result'([postUid, runUid, file]) {
    const payload = readJson(file);
    const r = await call('POST', `/api/v1/agent/posts/${postUid}/publish-result`, {
      agent: 'A5', body: { ...payload, run_uid: runUid },
    });
    r.status >= 300 ? die({ ok: false, error: r.body?.error }) : out({ ok: true, ...r.body.data });
  },

  // --- A6 ------------------------------------------------------------------
  async published() {
    const since = flag('since') || istDate(new Date(Date.now() - CONFIG.limits.metricsWindowDays * 86400000));
    const r = await call('GET', `/api/v1/agent/posts/published?since=${since}`, { agent: 'A6' });
    r.status >= 300 ? die({ ok: false, error: r.body?.error }) : out(r.body);
  },

  async 'metrics-upsert'([runUid, file]) {
    const payload = readJson(file);
    const r = await call('POST', '/api/v1/agent/metrics:bulk-upsert', {
      agent: 'A6',
      body: {
        run_uid: runUid,
        metric_date_ist: payload.metric_date_ist || istDate(),
        source: payload.source || 'linkedin_api',
        api_version: payload.api_version || CONFIG.linkedin.apiVersion,
        items: payload.items || payload,
      },
    });
    r.status >= 300 ? die({ ok: false, error: r.body?.error }) : out(r.body.data);
  },

  help() {
    out({ base: BASE, commands: Object.keys(COMMANDS).sort() });
  },
});

if (!cmd || !COMMANDS[cmd]) {
  console.error(`unknown command: ${cmd ?? '(none)'}`);
  console.error(`available: ${Object.keys(COMMANDS).sort().join(', ')}`);
  process.exit(2);
}
await COMMANDS[cmd](positional);
