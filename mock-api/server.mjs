#!/usr/bin/env node
/**
 * Mock of the Part 2 approval web app.
 *
 * Part 2 is a PHP + MySQL app on cPanel. It does not exist yet, but four of the
 * six agents talk to it, so they cannot be tested without it. This file is a
 * faithful, stateful stand-in: same routes, same JSON, same state machine, same
 * rejections. When Part 2 is built it implements this contract and the agents
 * keep working unchanged.
 *
 * Zero dependencies: node:http + the SQLite that ships inside Node 22+.
 *   node mock-api/server.mjs [--port 8787] [--db mock-api/mock.db] [--fresh]
 *
 * Routes under /__mock/ stand in for the human at the two approval gates and do
 * NOT exist in the real API.
 */
import { createServer } from 'node:http';
import { DatabaseSync } from 'node:sqlite';
import { randomUUID, createHash } from 'node:crypto';
import { readFileSync, writeFileSync, mkdirSync, existsSync, rmSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(HERE, '..');

const argv = process.argv.slice(2);
const argOf = (name, dflt) => {
  const i = argv.indexOf(name);
  return i >= 0 && argv[i + 1] ? argv[i + 1] : dflt;
};
const PORT = Number(argOf('--port', 8787));
const DB_PATH = resolve(ROOT, argOf('--db', 'mock-api/mock.db'));
const MEDIA_DIR = join(HERE, 'media');
const FRESH = argv.includes('--fresh');

// ---------------------------------------------------------------------------
// Database
// ---------------------------------------------------------------------------
if (FRESH && existsSync(DB_PATH)) rmSync(DB_PATH);
mkdirSync(dirname(DB_PATH), { recursive: true });
mkdirSync(MEDIA_DIR, { recursive: true });

const freshDb = !existsSync(DB_PATH);
const db = new DatabaseSync(DB_PATH);
db.exec('PRAGMA journal_mode = WAL');
db.exec('PRAGMA foreign_keys = ON');
if (freshDb) db.exec(readFileSync(join(ROOT, 'contract/schema.sqlite.sql'), 'utf8'));

const run = (sql, ...p) => db.prepare(sql).run(...p);
const get = (sql, ...p) => db.prepare(sql).get(...p);
const all = (sql, ...p) => db.prepare(sql).all(...p);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
const sha256 = (s) => createHash('sha256').update(s).digest('hex');
const nowUtc = () => new Date().toISOString().replace('Z', 'Z');

/** IST business date. India is UTC+5:30 with no DST, ever. */
const istDate = (d = new Date()) =>
  new Date(d.getTime() + 5.5 * 3600 * 1000).toISOString().slice(0, 10);

const J = (v) => (v == null ? null : JSON.stringify(v));
const P = (v, dflt = null) => { try { return v == null ? dflt : JSON.parse(v); } catch { return dflt; } };

/** Body word count. Hashtags and the CTA line are excluded by the owner's rule. */
function bodyWordCount(body) {
  return String(body || '')
    .split('\n')
    .filter((l) => !/^\s*#\S/.test(l.trim()))
    .join(' ')
    .trim()
    .split(/\s+/)
    .filter(Boolean).length;
}

const dedupeHash = (title) =>
  sha256(String(title).toLowerCase().replace(/[^a-z0-9 ]/g, '').replace(/\s+/g, ' ').trim());

// Per-agent keys and scopes. One key per agent means A5 (the only agent that can
// write to LinkedIn) can be revoked without stopping the other five.
const SCOPES = {
  A1: ['runs:write', 'topics:write', 'deadletters:write'],
  A2: ['runs:write', 'topics:read', 'topics:claim', 'posts:write', 'deadletters:write'],
  A3: ['runs:write', 'posts:read', 'images:write', 'deadletters:write'],
  A4: ['runs:write', 'posts:read', 'posts:submit', 'deadletters:write'],
  A5: ['runs:write', 'posts:read', 'publish:write', 'deadletters:write'],
  A6: ['runs:write', 'posts:read', 'metrics:write', 'deadletters:write'],
};
const KEYS = Object.fromEntries(Object.keys(SCOPES).map((a) => [`go_test_${a.toLowerCase()}_key`, a]));

let faults = {}; // { routeSubstring: { mode, count } }

class ApiError extends Error {
  constructor(status, code, message, extra = {}) {
    super(message);
    this.status = status; this.code = code; this.extra = extra;
    this.retryable = status === 429 || status >= 500;
  }
}

// ---------------------------------------------------------------------------
// HTTP plumbing
// ---------------------------------------------------------------------------
function sendJson(res, status, payload, headers = {}) {
  const body = JSON.stringify(payload, null, 2);
  res.writeHead(status, {
    'Content-Type': 'application/json; charset=utf-8',
    'Content-Length': Buffer.byteLength(body),
    ...headers,
  });
  res.end(body);
}

function sendError(res, err) {
  const status = err.status || 500;
  sendJson(res, status, {
    error: {
      code: err.code || 'INTERNAL',
      message: err.message,
      retryable: err.retryable ?? status >= 500,
      details: err.extra || {},
    },
    request_id: 'req_' + randomUUID().slice(0, 12),
    server_time: nowUtc(),
  });
}

function readBody(req) {
  return new Promise((resolve, reject) => {
    const chunks = [];
    let size = 0;
    req.on('data', (c) => {
      size += c.length;
      if (size > 32 * 1024 * 1024) { reject(new ApiError(413, 'PAYLOAD_TOO_LARGE', 'body over 32MB')); req.destroy(); return; }
      chunks.push(c);
    });
    req.on('end', () => {
      const raw = Buffer.concat(chunks).toString('utf8');
      if (!raw) return resolve({});
      try { resolve(JSON.parse(raw)); }
      catch { reject(new ApiError(400, 'VALIDATION_FAILED', 'body is not valid JSON')); }
    });
    req.on('error', reject);
  });
}

/** Bearer auth plus a scope check. The agent API never accepts cookies. */
function authenticate(req, needScope) {
  const header = req.headers.authorization || '';
  const token = header.startsWith('Bearer ') ? header.slice(7).trim() : null;
  if (!token) throw new ApiError(401, 'UNAUTHORIZED', 'missing bearer token');
  const agent = KEYS[token];
  if (!agent) throw new ApiError(401, 'UNAUTHORIZED', 'unknown API key');
  if (needScope && !SCOPES[agent].includes(needScope)) {
    throw new ApiError(403, 'FORBIDDEN_SCOPE',
      `agent ${agent} does not hold scope ${needScope}`, { agent, required: needScope });
  }
  return agent;
}

/**
 * Idempotency. Same key and same body replays the stored response; same key with
 * a different body is a conflict. This is what makes a retry after an ambiguous
 * timeout safe rather than duplicating work.
 */
function idempotencyLookup(req, agent, body) {
  const key = req.headers['idempotency-key'];
  if (!key) return null;
  const keyHash = sha256(`${agent}:${key}`);
  const reqHash = sha256(JSON.stringify(body ?? {}));
  const existing = get('SELECT * FROM idempotency_keys WHERE key_hash = ?', keyHash);
  if (existing) {
    if (existing.request_hash !== reqHash) {
      throw new ApiError(409, 'IDEMPOTENCY_KEY_REUSED_WITH_DIFFERENT_BODY',
        'this Idempotency-Key was already used with a different body');
    }
    return { replay: true, status: existing.response_status, body: P(existing.response_body) };
  }
  return { replay: false, keyHash, reqHash };
}

function idempotencyStore(ctx, agent, method, path, status, payload) {
  if (!ctx || ctx.replay) return;
  const expires = new Date(Date.now() + 72 * 3600 * 1000).toISOString();
  run(`INSERT INTO idempotency_keys
         (key_hash, agent_code, method, path, request_hash, response_status, response_body, expires_at)
       VALUES (?,?,?,?,?,?,?,?)`,
    ctx.keyHash, agent, method, path, ctx.reqHash, status, JSON.stringify(payload), expires);
}

/** Deliberate failures, so the agents' retry paths are exercised for real. */
function applyFault(req, pathname) {
  const header = req.headers['x-mock-fault'];
  const sticky = Object.entries(faults).find(([route]) => pathname.includes(route));
  const mode = header || (sticky && sticky[1].count > 0 ? sticky[1].mode : null);
  if (!mode) return;
  if (sticky && !header) {
    sticky[1].count -= 1;
    if (sticky[1].count <= 0) delete faults[sticky[0]];
  }
  if (mode === 'http_503') throw new ApiError(503, 'SERVICE_UNAVAILABLE', 'injected fault: upstream unavailable');
  if (mode === 'http_429') throw new ApiError(429, 'RATE_LIMITED', 'injected fault: rate limited');
  if (mode === 'http_500') throw new ApiError(500, 'INTERNAL', 'injected fault: internal error');
}

// ---------------------------------------------------------------------------
// Route handlers
// ---------------------------------------------------------------------------
const H = {};

// --- shared: run lifecycle -------------------------------------------------

/**
 * Open a run. uq_runs_slot makes a double-fired cron a 409 rather than two runs.
 * The agent treats that 409 as "someone else is already doing this" and exits 0.
 */
H.createRun = ({ agent, body }) => {
  const required = ['run_uid', 'agent_code', 'business_date_ist'];
  for (const f of required) if (!body[f]) throw new ApiError(400, 'VALIDATION_FAILED', `${f} is required`, { field: f });
  if (body.agent_code !== agent) {
    throw new ApiError(403, 'FORBIDDEN_SCOPE', `key belongs to ${agent}, cannot open a run for ${body.agent_code}`);
  }
  const existing = get('SELECT * FROM agent_runs WHERE run_uid = ?', body.run_uid);
  if (existing) return { status: 200, payload: { data: runView(existing), replayed: true } };

  const clash = get('SELECT * FROM agent_runs WHERE agent_code=? AND business_date_ist=? AND attempt=?',
    body.agent_code, body.business_date_ist, body.attempt ?? 1);
  if (clash) {
    throw new ApiError(409, 'DUPLICATE_RUN_SLOT',
      `${body.agent_code} already has a run for ${body.business_date_ist} attempt ${body.attempt ?? 1}`,
      { existing_run_uid: clash.run_uid, existing_status: clash.status });
  }
  run(`INSERT INTO agent_runs (run_uid, agent_code, agent_version, trigger_type, business_date_ist, attempt, dry_run, started_at)
       VALUES (?,?,?,?,?,?,?,?)`,
    body.run_uid, body.agent_code, body.agent_version ?? 'v1', body.trigger_type ?? 'schedule',
    body.business_date_ist, body.attempt ?? 1, body.dry_run ? 1 : 0, nowUtc());
  const row = get('SELECT * FROM agent_runs WHERE run_uid = ?', body.run_uid);
  return { status: 201, payload: { data: { ...runView(row), config: configPayload() } } };
};

const runView = (r) => ({
  run_uid: r.run_uid, agent_code: r.agent_code, status: r.status,
  business_date_ist: r.business_date_ist, attempt: r.attempt,
  dry_run: !!r.dry_run, started_at: r.started_at, finished_at: r.finished_at,
  items_in: r.items_in, items_ok: r.items_ok, items_failed: r.items_failed, items_skipped: r.items_skipped,
  skip_reason: r.skip_reason, error_code: r.error_code,
});

H.patchRun = ({ body, params }) => {
  const r = get('SELECT * FROM agent_runs WHERE run_uid = ?', params.runUid);
  if (!r) throw new ApiError(404, 'NOT_FOUND', 'run not found');
  const finished = ['succeeded', 'partial', 'failed', 'skipped', 'timed_out'].includes(body.status);
  run(`UPDATE agent_runs SET status=?, skip_reason=?, items_in=?, items_ok=?, items_failed=?, items_skipped=?,
         error_code=?, error_message=?, metrics_json=?, heartbeat_at=?, finished_at=?, duration_ms=?
       WHERE run_uid=?`,
    body.status ?? r.status, body.skip_reason ?? r.skip_reason,
    body.items_in ?? r.items_in, body.items_ok ?? r.items_ok,
    body.items_failed ?? r.items_failed, body.items_skipped ?? r.items_skipped,
    body.error_code ?? r.error_code, body.error_message ?? r.error_message,
    J(body.metrics_json) ?? r.metrics_json, nowUtc(),
    finished ? nowUtc() : r.finished_at,
    finished ? Date.now() - Date.parse(r.started_at) : r.duration_ms,
    params.runUid);
  return { status: 200, payload: { data: runView(get('SELECT * FROM agent_runs WHERE run_uid = ?', params.runUid)) } };
};

H.postEvents = ({ body, params }) => {
  const r = get('SELECT id FROM agent_runs WHERE run_uid = ?', params.runUid);
  if (!r) throw new ApiError(404, 'NOT_FOUND', 'run not found');
  const events = Array.isArray(body.events) ? body.events : [body];
  let seq = (get('SELECT COALESCE(MAX(seq),0) s FROM run_events WHERE run_id=?', r.id).s) || 0;
  for (const e of events) {
    seq += 1;
    run(`INSERT INTO run_events (run_id, seq, level, event_code, entity_type, entity_uid, message, data_json, created_at)
         VALUES (?,?,?,?,?,?,?,?,?)`,
      r.id, seq, e.level ?? 'info', e.event_code ?? 'note',
      e.entity_type ?? 'none', e.entity_uid ?? null, e.message ?? null, J(e.data), nowUtc());
  }
  return { status: 201, payload: { data: { accepted: events.length, last_seq: seq } } };
};

H.deadLetter = ({ agent, body }) => {
  const existing = get(`SELECT * FROM dead_letters WHERE agent_code=? AND stage=? AND error_code=? AND entity_uid IS ?`,
    agent, body.stage, body.error_code, body.entity_uid ?? null);
  if (existing) {
    run('UPDATE dead_letters SET attempts = attempts + 1, last_seen_at = ? WHERE id = ?', nowUtc(), existing.id);
    return { status: 200, payload: { data: { dl_uid: existing.dl_uid, attempts: existing.attempts + 1, deduped: true } } };
  }
  const uid = body.dl_uid ?? randomUUID();
  run(`INSERT INTO dead_letters (dl_uid, agent_code, entity_type, entity_uid, stage, error_code, error_message, payload_json)
       VALUES (?,?,?,?,?,?,?,?)`,
    uid, agent, body.entity_type ?? 'none', body.entity_uid ?? null,
    body.stage, body.error_code, body.error_message ?? '', J(body.payload ?? {}));
  return { status: 201, payload: { data: { dl_uid: uid, attempts: 1, deduped: false } } };
};

const setting = (k) => P(get('SELECT setting_value v FROM app_settings WHERE setting_key=?', k)?.v);
const configPayload = () => ({
  timezone: setting('timezone'),
  max_body_words: Number(setting('max_body_words')),
  topics_per_batch_min: Number(setting('topics_per_batch_min')),
  topics_per_batch_max: Number(setting('topics_per_batch_max')),
  approved_queue_ceiling: Number(setting('approved_queue_ceiling')),
  images_per_post: Number(setting('images_per_post')),
  metrics_window_days: Number(setting('metrics_window_days')),
  package_ttl_days: Number(setting('package_ttl_days')),
  company_website: setting('company_website'),
  company_email: setting('company_email'),
  linkedin_org_urn: setting('linkedin_org_urn'),
});

H.getConfig = () => ({ status: 200, payload: { data: configPayload() } });

// --- A1: topic scouting ----------------------------------------------------

/**
 * Back-pressure. A1 produces 30-60 topics a week; A5 publishes 7. If A1 ran
 * unconditionally the approved queue would grow by 10-25 a week forever and
 * posts would go out stale. Above the ceiling, A1 skips the week.
 */
H.queueDepth = () => {
  const approved = get(`SELECT COUNT(*) c FROM posts
     WHERE review_status='approved' AND lifecycle_state IN ('ready','scheduled')`).c;
  const pendingTopics = get(`SELECT COUNT(*) c FROM topics WHERE review_status='pending'`).c;
  const oldestPending = get(`SELECT MIN(created_at) d FROM topics WHERE review_status='pending'`).d;
  const ceiling = Number(setting('approved_queue_ceiling'));
  return { status: 200, payload: { data: {
    approved_posts_waiting: approved,
    pending_topics: pendingTopics,
    oldest_pending_topic_at: oldestPending,
    ceiling,
    should_skip: approved >= ceiling,
    runway_days: approved,
  } } };
};

H.createBatch = ({ body }) => {
  const r = get('SELECT id FROM agent_runs WHERE run_uid=?', body.run_uid);
  if (!r) throw new ApiError(404, 'NOT_FOUND', 'run not found');
  const existing = get('SELECT * FROM topic_batches WHERE batch_uid=?', body.batch_uid);
  if (existing) return { status: 200, payload: { data: { batch_uid: existing.batch_uid, state: existing.state }, replayed: true } };
  run(`INSERT INTO topic_batches (batch_uid, run_id, business_date_ist, title, sources_json)
       VALUES (?,?,?,?,?)`,
    body.batch_uid, r.id, body.business_date_ist, body.title ?? `Topics ${body.business_date_ist}`, J(body.sources));
  return { status: 201, payload: { data: { batch_uid: body.batch_uid, state: 'open' } } };
};

/** A1 asks this before uploading, so a topic proposed in the last year is dropped. */
H.dedupeCheck = ({ body }) => {
  const days = Number(body.days ?? 365);
  const cutoff = new Date(Date.now() - days * 86400000).toISOString();
  const results = (body.titles || []).map((title) => {
    const h = dedupeHash(title);
    const hit = get(`SELECT topic_uid, final_title, created_at FROM topics
                     WHERE dedupe_hash=? AND created_at >= ? ORDER BY created_at DESC LIMIT 1`, h, cutoff);
    return hit
      ? { title, is_duplicate: true, matched_topic_uid: hit.topic_uid, matched_title: hit.final_title, matched_on: hit.created_at.slice(0, 10) }
      : { title, is_duplicate: false };
  });
  return { status: 200, payload: { data: results } };
};

/** Bulk insert, per-item results: one malformed topic must not lose the other 49. */
H.addTopics = ({ body, params }) => {
  const batch = get('SELECT * FROM topic_batches WHERE batch_uid=?', params.batchUid);
  if (!batch) throw new ApiError(404, 'NOT_FOUND', 'batch not found');
  if (batch.state !== 'open') throw new ApiError(409, 'ILLEGAL_TRANSITION', 'batch is already submitted');
  const r = get('SELECT id FROM agent_runs WHERE run_uid=?', body.run_uid);
  if (!r) throw new ApiError(404, 'NOT_FOUND', 'run not found');

  let pos = get('SELECT COALESCE(MAX(position),0) p FROM topics WHERE batch_id=?', batch.id).p;
  const results = [];
  let accepted = 0, duplicates = 0, rejected = 0;

  for (const t of body.topics || []) {
    try {
      if (!t.title || !t.one_liner) throw new ApiError(422, 'VALIDATION_FAILED', 'title and one_liner are required');
      if (String(t.one_liner).length > 500) throw new ApiError(422, 'VALIDATION_FAILED', 'one_liner exceeds 500 chars');
      if (String(t.title).length > 300) throw new ApiError(422, 'VALIDATION_FAILED', 'title exceeds 300 chars');

      const dup = get('SELECT topic_uid FROM topics WHERE batch_id=? AND dedupe_hash=?', batch.id, dedupeHash(t.title));
      if (dup) { duplicates++; results.push({ topic_uid: t.topic_uid, status: 'duplicate_in_batch', existing_topic_uid: dup.topic_uid }); continue; }

      const exists = get('SELECT topic_uid FROM topics WHERE topic_uid=?', t.topic_uid);
      if (exists) { results.push({ topic_uid: t.topic_uid, status: 'replayed' }); accepted++; continue; }

      pos += 1;
      run(`INSERT INTO topics (
             topic_uid, batch_id, created_by_run_id, position,
             original_title, original_one_liner, original_cta_flag, original_cta_type, original_cta_text, original_cta_target,
             source_url, source_domain, source_title, source_published_on, source_type, source_quote,
             post_type, theme_tag, india_relevance,
             final_title, final_one_liner, final_cta_flag, final_cta_type, final_cta_text, final_cta_target,
             dedupe_hash, created_at, updated_at)
           VALUES (?,?,?,?, ?,?,?,?,?,?, ?,?,?,?,?,?, ?,?,?, ?,?,?,?,?,?, ?,?,?)`,
        t.topic_uid, batch.id, r.id, pos,
        t.title, t.one_liner, t.cta_flag ? 1 : 0, t.cta_type ?? 'none', t.cta_text ?? null, t.cta_target ?? null,
        t.source_url ?? null, t.source_domain ?? null, t.source_title ?? null, t.source_published_on ?? null,
        t.source_type ?? 'blog', t.source_quote ?? null,
        t.post_type ?? 'data_point', t.theme_tag ?? 'workforce_planning', t.india_relevance ?? 1,
        t.title, t.one_liner, t.cta_flag ? 1 : 0, t.cta_type ?? 'none', t.cta_text ?? null, t.cta_target ?? null,
        dedupeHash(t.title), nowUtc(), nowUtc());
      accepted++;
      results.push({ topic_uid: t.topic_uid, status: 'created', position: pos });
    } catch (e) {
      rejected++;
      results.push({ topic_uid: t.topic_uid, status: 'rejected', error: { code: e.code || 'INTERNAL', message: e.message } });
    }
  }
  run('UPDATE topic_batches SET topic_count = (SELECT COUNT(*) FROM topics WHERE batch_id=?), updated_at=? WHERE id=?',
    batch.id, nowUtc(), batch.id);
  return { status: 207, payload: { data: { accepted, duplicates, rejected, results } } };
};

/** Submitting closes the batch and makes it visible to the owner at gate 1. */
H.submitBatch = ({ params }) => {
  const batch = get('SELECT * FROM topic_batches WHERE batch_uid=?', params.batchUid);
  if (!batch) throw new ApiError(404, 'NOT_FOUND', 'batch not found');
  if (batch.state === 'submitted') return { status: 200, payload: { data: { batch_uid: batch.batch_uid, state: 'submitted', topic_count: batch.topic_count }, replayed: true } };
  const min = Number(setting('topics_per_batch_min'));
  if (batch.topic_count < min) {
    throw new ApiError(422, 'VALIDATION_FAILED',
      `batch has ${batch.topic_count} topics, minimum is ${min}`,
      { topic_count: batch.topic_count, minimum: min });
  }
  run(`UPDATE topic_batches SET state='submitted', submitted_at=?, updated_at=? WHERE id=?`, nowUtc(), nowUtc(), batch.id);
  run(`UPDATE topics SET pipeline_state='new' WHERE batch_id=?`, batch.id);
  return { status: 200, payload: { data: { batch_uid: batch.batch_uid, state: 'submitted', topic_count: batch.topic_count } } };
};

// --- A2: copywriting -------------------------------------------------------

/**
 * A2 only ever sees approved topics. It cannot reach pending or held rows even
 * by asking: if it could, the human gate would quietly stop existing.
 */
H.listTopics = ({ query }) => {
  const limit = Math.min(Number(query.limit ?? 50), 200);
  const rows = all(`SELECT t.*, b.batch_uid FROM topics t JOIN topic_batches b ON b.id = t.batch_id
     WHERE t.review_status='approved' AND t.pipeline_state='awaiting_copy' AND b.state='submitted'
     ORDER BY t.priority, t.id LIMIT ?`, limit);
  return { status: 200, payload: {
    data: rows.map((t) => ({
      topic_uid: t.topic_uid, batch_uid: t.batch_uid, position: t.position,
      // final_* is what A2 writes about. The owner's edit always wins.
      title: t.final_title, one_liner: t.final_one_liner,
      source_url: t.source_url, source_quote: t.source_quote, source_domain: t.source_domain,
      source_published_on: t.source_published_on,
      post_type: t.post_type, theme_tag: t.theme_tag, india_relevance: t.india_relevance,
      cta: { required: !!t.final_cta_flag, type: t.final_cta_type, text: t.final_cta_text, target: t.final_cta_target },
      was_edited: !!t.was_edited,
      provenance: { original_title: t.original_title, original_one_liner: t.original_one_liner },
      approved_at: t.reviewed_at,
    })),
    page: { limit, has_more: rows.length === limit },
    constraints: {
      max_body_words: Number(setting('max_body_words')),
      brand_website: setting('company_website'),
      brand_email: setting('company_email'),
      audience: 'HR Head / CHRO at Indian companies with 500+ headcount and about INR 500 Cr turnover',
      forbidden: ['candidate-facing language', 'any claim about caste, religion, gender, age, marital status or nationality',
                  'statistics that do not appear verbatim in the source', 'financial advice', 'URLs in the post body'],
    },
  } };
};

/** A 30-minute lease, so a crashed A2 does not strand the topic forever. */
H.claimTopic = ({ body, params }) => {
  const t = get('SELECT * FROM topics WHERE topic_uid=?', params.topicUid);
  if (!t) throw new ApiError(404, 'NOT_FOUND', 'topic not found');
  if (t.review_status !== 'approved') {
    throw new ApiError(409, 'TOPIC_NOT_APPROVED',
      `topic is '${t.review_status}' and cannot be claimed`, { review_status: t.review_status });
  }
  const held = t.claim_expires_at && Date.parse(t.claim_expires_at) > Date.now();
  const r = get('SELECT id FROM agent_runs WHERE run_uid=?', body.run_uid);
  if (held && t.claimed_by_run_id !== r?.id) {
    throw new ApiError(409, 'LEASE_HELD_BY_OTHER_RUN', 'another run holds this topic');
  }
  const expires = new Date(Date.now() + (body.lease_seconds ?? 1800) * 1000).toISOString();
  run(`UPDATE topics SET pipeline_state='copy_in_progress', claimed_by_run_id=?, claim_expires_at=?, updated_at=? WHERE id=?`,
    r.id, expires, nowUtc(), t.id);
  return { status: 200, payload: { data: { topic_uid: t.topic_uid, claim_expires_at: expires } } };
};

H.createPost = ({ body }) => {
  const existing = get('SELECT * FROM posts WHERE post_uid=?', body.post_uid);
  if (existing) return { status: 200, payload: { data: postStateView(existing), replayed: true } };

  const t = get('SELECT * FROM topics WHERE topic_uid=?', body.topic_uid);
  if (!t) throw new ApiError(404, 'NOT_FOUND', 'topic not found');
  if (t.review_status !== 'approved') throw new ApiError(409, 'TOPIC_NOT_APPROVED', 'topic is not approved');
  const r = get('SELECT id FROM agent_runs WHERE run_uid=?', body.run_uid);
  if (!r) throw new ApiError(404, 'NOT_FOUND', 'run not found');

  // The word cap is enforced at the boundary, not trusted from the agent.
  const wc = bodyWordCount(body.body);
  const max = Number(setting('max_body_words'));
  if (wc > max) {
    throw new ApiError(422, 'WORD_COUNT_EXCEEDED',
      `body is ${wc} words, limit is ${max}. Re-draft rather than truncating.`, { word_count: wc, limit: max });
  }
  // A link in the body suppresses reach, so the contract refuses one outright.
  if (/https?:\/\/|www\./i.test(body.body)) {
    throw new ApiError(422, 'LINK_IN_BODY',
      'the post body must not contain a URL; put it in first_comment_text instead');
  }
  const tags = body.hashtags || [];
  if (tags.length < 3 || tags.length > 5) {
    throw new ApiError(422, 'VALIDATION_FAILED', `expected 3-5 hashtags, got ${tags.length}`, { hashtags: tags.length });
  }
  run(`INSERT INTO posts (
         post_uid, topic_id, revision, copy_run_id,
         original_hook, original_body, original_cta_text, original_cta_target,
         original_hashtags, original_keywords, original_word_count,
         first_comment_text, numbers_used, image_brief, model_name, generation_notes,
         final_hook, final_body, final_cta_text, final_cta_target,
         final_hashtags, final_keywords, final_word_count,
         lifecycle_state, created_at, updated_at)
       VALUES (?,?,?,?, ?,?,?,?, ?,?,?, ?,?,?,?,?, ?,?,?,?, ?,?,?, ?,?,?)`,
    body.post_uid, t.id, body.revision ?? 1, r.id,
    body.hook, body.body, body.cta_text ?? null, body.cta_target ?? null,
    J(tags), J(body.keywords ?? []), wc,
    body.first_comment_text ?? null, J(body.numbers_used ?? []), J(body.image_brief ?? {}),
    body.model_name ?? null, J(body.generation_notes ?? {}),
    body.hook, body.body, body.cta_text ?? null, body.cta_target ?? null,
    J(tags), J(body.keywords ?? []), wc,
    'images_pending', nowUtc(), nowUtc());

  run(`UPDATE topics SET pipeline_state='copy_done', claim_expires_at=NULL, updated_at=? WHERE id=?`, nowUtc(), t.id);
  return { status: 201, payload: { data: postStateView(get('SELECT * FROM posts WHERE post_uid=?', body.post_uid)) } };
};

const postStateView = (p) => ({
  post_uid: p.post_uid, lifecycle_state: p.lifecycle_state, review_status: p.review_status,
  word_count: p.final_word_count, revision: p.revision,
});

// --- A3 / A4: images and packaging ----------------------------------------

H.listPosts = ({ query }) => {
  const state = query.lifecycle_state || 'images_pending';
  const limit = Math.min(Number(query.limit ?? 50), 200);
  const rows = all(`SELECT p.*, t.final_title, t.source_url, t.source_quote, t.post_type, t.theme_tag
     FROM posts p JOIN topics t ON t.id = p.topic_id
     WHERE p.lifecycle_state = ? ORDER BY p.id LIMIT ?`, state, limit);
  return { status: 200, payload: {
    data: rows.map((p) => ({
      post_uid: p.post_uid, topic_title: p.final_title,
      hook: p.final_hook, body: p.final_body, word_count: p.final_word_count,
      hashtags: P(p.final_hashtags, []), keywords: P(p.final_keywords, []),
      cta_text: p.final_cta_text, cta_target: p.final_cta_target,
      first_comment_text: p.first_comment_text,
      image_brief: P(p.image_brief, {}), numbers_used: P(p.numbers_used, []),
      post_type: p.post_type, theme_tag: p.theme_tag, source_url: p.source_url,
      lifecycle_state: p.lifecycle_state, review_status: p.review_status,
      image_count: get('SELECT COUNT(*) c FROM post_images WHERE post_id=?', p.id).c,
    })),
    page: { limit, has_more: rows.length === limit },
  } };
};

/**
 * Image upload. Base64 in JSON rather than multipart: it is trivial on both
 * sides, and PHP handles it identically in Part 2. The server re-hashes the
 * bytes it actually received, so a truncated download from Higgsfield is caught
 * here instead of surfacing as a grey box in the preview a week later.
 */
H.uploadImage = ({ body, params }) => {
  const p = get('SELECT * FROM posts WHERE post_uid=?', params.postUid);
  if (!p) throw new ApiError(404, 'NOT_FOUND', 'post not found');
  const r = get('SELECT id FROM agent_runs WHERE run_uid=?', body.run_uid);
  if (!r) throw new ApiError(404, 'NOT_FOUND', 'run not found');

  const idx = Number(body.option_index);
  if (![1, 2].includes(idx)) throw new ApiError(422, 'VALIDATION_FAILED', 'option_index must be 1 or 2');

  const dup = get('SELECT * FROM post_images WHERE image_uid=?', body.image_uid);
  if (dup) return { status: 200, payload: { data: imageView(dup), replayed: true } };

  if (!body.data_base64) throw new ApiError(422, 'VALIDATION_FAILED', 'data_base64 is required');
  const bytes = Buffer.from(body.data_base64, 'base64');
  const actual = createHash('sha256').update(bytes).digest('hex');
  if (body.sha256 && body.sha256 !== actual) {
    throw new ApiError(422, 'SHA256_MISMATCH',
      'received bytes do not match the declared sha256; the download was corrupt',
      { declared: body.sha256, actual, bytes: bytes.length });
  }
  if (bytes.length === 0) throw new ApiError(422, 'VALIDATION_FAILED', 'image is zero bytes');

  const ext = (body.mime_type || 'image/png').includes('jpeg') ? 'jpg' : 'png';
  const rel = `images/${istDate()}/${body.image_uid}.${ext}`;
  mkdirSync(join(MEDIA_DIR, dirname(rel)), { recursive: true });
  writeFileSync(join(MEDIA_DIR, rel), bytes);

  run(`INSERT INTO post_images (
         image_uid, post_id, option_index, generated_by_run_id,
         provider, provider_model, provider_job_id, prompt_text, negative_prompt, seed, aspect_ratio,
         source_url, source_url_fetched_at, storage_path, public_url, mime_type,
         width_px, height_px, bytes, sha256, alt_text, concept_label, ocr_text, status, verified_at, created_at)
       VALUES (?,?,?,?, ?,?,?,?,?,?,?, ?,?,?,?,?, ?,?,?,?,?,?,?,?,?,?)`,
    body.image_uid, p.id, idx, r.id,
    body.provider ?? 'higgsfield', body.provider_model ?? null, body.provider_job_id ?? null,
    body.prompt_text ?? '', body.negative_prompt ?? null, body.seed ?? null, body.aspect_ratio ?? null,
    body.source_url ?? null, nowUtc(), rel, `/media/${rel}`, body.mime_type ?? 'image/png',
    body.width_px ?? null, body.height_px ?? null, bytes.length, actual,
    body.alt_text ?? null, body.concept_label ?? null, body.ocr_text ?? null, 'stored', nowUtc(), nowUtc());

  const count = get('SELECT COUNT(*) c FROM post_images WHERE post_id=?', p.id).c;
  if (count >= 1 && p.lifecycle_state === 'images_pending') {
    run(`UPDATE posts SET lifecycle_state='images_ready', image_run_id=?, updated_at=? WHERE id=?`, r.id, nowUtc(), p.id);
  }
  return { status: 201, payload: { data: { ...imageView(get('SELECT * FROM post_images WHERE image_uid=?', body.image_uid)), image_count: count } } };
};

const imageView = (i) => ({
  image_uid: i.image_uid, option_index: i.option_index, public_url: i.public_url,
  provider_model: i.provider_model, concept_label: i.concept_label, alt_text: i.alt_text,
  bytes: i.bytes, sha256: i.sha256, status: i.status,
});

/** A4 hands the package to gate 2. One image is allowed but flagged degraded. */
H.submitForReview = ({ body, params }) => {
  const p = get('SELECT * FROM posts WHERE post_uid=?', params.postUid);
  if (!p) throw new ApiError(404, 'NOT_FOUND', 'post not found');
  if (p.lifecycle_state === 'in_review') {
    return { status: 200, payload: { data: postStateView(p), replayed: true } };
  }
  if (!['images_ready', 'images_pending'].includes(p.lifecycle_state)) {
    throw new ApiError(409, 'ILLEGAL_TRANSITION', `cannot submit from '${p.lifecycle_state}'`);
  }
  const images = all('SELECT * FROM post_images WHERE post_id=? ORDER BY option_index', p.id);
  if (images.length === 0) throw new ApiError(422, 'VALIDATION_FAILED', 'no images attached; cannot submit for review');

  // Re-run A2's countable gates on the stored bytes, to catch corruption in transit.
  const wc = bodyWordCount(p.final_body);
  if (wc > Number(setting('max_body_words'))) throw new ApiError(422, 'WORD_COUNT_EXCEEDED', `stored body is ${wc} words`);
  if (/https?:\/\/|www\./i.test(p.final_body)) throw new ApiError(422, 'LINK_IN_BODY', 'stored body contains a URL');

  const degraded = images.length < 2 ? ['single_image'] : [];
  const r = get('SELECT id FROM agent_runs WHERE run_uid=?', body.run_uid);
  const ttl = Number(setting('package_ttl_days'));
  const expires = new Date(Date.now() + ttl * 86400000);
  run(`UPDATE posts SET lifecycle_state='in_review', review_status='pending', submit_run_id=?,
         degraded_flags=?, expires_at_ist=?, updated_at=? WHERE id=?`,
    r?.id ?? null, J(degraded), istDate(expires), nowUtc(), p.id);

  return { status: 200, payload: { data: {
    post_uid: p.post_uid, lifecycle_state: 'in_review', review_status: 'pending',
    image_count: images.length, degraded_flags: degraded, expires_at_ist: istDate(expires),
    review_url: `/review/posts/${p.post_uid}`,
  } } };
};

// --- A5: publishing --------------------------------------------------------

/**
 * Selection order, decided server-side so A5 has no discretion:
 *   1. yesterday's failure, retried first
 *   2. anything the owner dated for today or earlier, earliest first
 *   3. otherwise the oldest approved post with no date
 * A future-dated post is simply not returned. No timer, no scheduler table.
 */
H.publishQueueNext = ({ query }) => {
  const today = query.date || istDate();
  const base = `SELECT p.*, t.final_title FROM posts p JOIN topics t ON t.id=p.topic_id
                WHERE p.review_status='approved' AND p.selected_image_id IS NOT NULL`;
  const notExpired = `AND (p.expires_at_ist IS NULL OR p.expires_at_ist >= ?)`;

  const postedToday = get('SELECT COUNT(*) c FROM posts WHERE posted_date_ist = ?', today).c;
  if (postedToday > 0) return { status: 204, payload: null };

  let row = get(`${base} AND p.lifecycle_state='failed' AND p.publish_attempts < 5 ${notExpired} ORDER BY p.id LIMIT 1`, today);
  let reason = 'retry_previous_failure';
  if (!row) {
    row = get(`${base} AND p.lifecycle_state='scheduled' AND p.scheduled_date_ist <= ? ${notExpired} ORDER BY p.scheduled_date_ist, p.id LIMIT 1`, today, today);
    reason = 'scheduled_for_today';
  }
  if (!row) {
    row = get(`${base} AND p.lifecycle_state='ready' AND p.scheduled_date_ist IS NULL ${notExpired} ORDER BY p.id LIMIT 1`, today);
    reason = 'oldest_approved';
  }
  if (!row) return { status: 204, payload: null };

  return { status: 200, payload: { data: {
    post_uid: row.post_uid, reason, scheduled_date_ist: row.scheduled_date_ist,
    publish_attempts: row.publish_attempts, topic_title: row.final_title,
  } } };
};

/**
 * Taking the lease is the moment every publish precondition is re-checked. If
 * the owner put the post on hold at 07:55, hold wins at 08:00.
 */
H.publishLease = ({ body, params }) => {
  const p = get(`SELECT p.*, t.final_title FROM posts p JOIN topics t ON t.id=p.topic_id WHERE p.post_uid=?`, params.postUid);
  if (!p) throw new ApiError(404, 'NOT_FOUND', 'post not found');
  if (p.lifecycle_state === 'posted') {
    throw new ApiError(409, 'ALREADY_POSTED', 'this post is already live on LinkedIn',
      { linkedin_urn: p.linkedin_urn, posted_at: p.posted_at_utc });
  }
  if (p.review_status !== 'approved') {
    throw new ApiError(422, 'POST_NOT_APPROVED', `post is '${p.review_status}', not approved`, { review_status: p.review_status });
  }
  if (!p.selected_image_id) throw new ApiError(422, 'NO_IMAGE_SELECTED', 'the owner has not chosen an image');
  if (p.expires_at_ist && p.expires_at_ist < istDate()) {
    throw new ApiError(422, 'POST_EXPIRED', `this package expired on ${p.expires_at_ist}`);
  }
  const hash = sha256(`${p.final_body}|${p.selected_image_id}|${p.final_hashtags}`);
  if (p.approved_content_hash && p.approved_content_hash !== hash) {
    throw new ApiError(409, 'CONTENT_CHANGED_AFTER_APPROVAL',
      'the post was edited after approval; re-approve before publishing');
  }
  const held = p.publish_lease_expires_at && Date.parse(p.publish_lease_expires_at) > Date.now();
  if (held) throw new ApiError(409, 'LEASE_HELD_BY_OTHER_RUN', 'another run is publishing this post');

  const r = get('SELECT id FROM agent_runs WHERE run_uid=?', body.run_uid);
  const token = randomUUID();
  const expires = new Date(Date.now() + (body.lease_seconds ?? 900) * 1000).toISOString();
  run(`UPDATE posts SET lifecycle_state='publishing', publish_lease_token=?, publish_lease_expires_at=?,
         publish_run_id=?, updated_at=? WHERE id=?`, token, expires, r?.id ?? null, nowUtc(), p.id);

  const img = get('SELECT * FROM post_images WHERE id=?', p.selected_image_id);
  const tags = P(p.final_hashtags, []);
  // The server composes the exact string sent to LinkedIn, so it is decided in
  // one place and hashed once for the audit record.
  const commentary = [p.final_body, '', tags.join(' ')].join('\n').trim();

  return { status: 200, payload: { data: {
    post_uid: p.post_uid,
    lease_token: token, lease_expires_at: expires,
    attempt_no: p.publish_attempts + 1,
    linkedin: { organization_urn: setting('linkedin_org_urn'), visibility: 'PUBLIC', media_category: 'IMAGE' },
    commentary,
    commentary_sha256: sha256(commentary),
    first_comment_text: p.first_comment_text,
    image: { image_uid: img.image_uid, public_url: img.public_url, sha256: img.sha256,
             mime_type: img.mime_type, bytes: img.bytes, alt_text: img.alt_text },
    idempotency_key: sha256(`A5:publish:${p.post_uid}:${istDate()}`),
  } } };
};

H.publishResult = ({ body, params }) => {
  const p = get('SELECT * FROM posts WHERE post_uid=?', params.postUid);
  if (!p) throw new ApiError(404, 'NOT_FOUND', 'post not found');
  if (body.lease_token && p.publish_lease_token && body.lease_token !== p.publish_lease_token) {
    throw new ApiError(409, 'LEASE_HELD_BY_OTHER_RUN', 'lease token does not match');
  }
  const r = get('SELECT id FROM agent_runs WHERE run_uid=?', body.run_uid);
  const attempt = p.publish_attempts + 1;
  const idem = body.idempotency_key || sha256(`A5:publish:${p.post_uid}:${istDate()}:${attempt}`);

  try {
    run(`INSERT INTO publish_attempts (post_id, run_id, attempt_no, idempotency_key, outcome, http_status,
           linkedin_urn, error_code, error_message, request_snapshot)
         VALUES (?,?,?,?,?,?,?,?,?,?)`,
      p.id, r?.id ?? null, attempt, idem, body.outcome, body.http_status ?? null,
      body.linkedin_urn ?? null, body.error_code ?? null, body.error_message ?? null, J(body.request_snapshot ?? {}));
  } catch (e) {
    // A replayed result for an attempt already recorded is a no-op, not a second post.
    if (String(e.message).includes('UNIQUE')) {
      return { status: 200, payload: { data: { post_uid: p.post_uid, lifecycle_state: p.lifecycle_state, replayed: true } } };
    }
    throw e;
  }

  if (body.outcome === 'success' || body.outcome === 'dry_run') {
    const live = body.outcome === 'success';
    const watch = new Date(Date.now() + Number(setting('metrics_window_days')) * 86400000);
    run(`UPDATE posts SET lifecycle_state=?, posted_at_utc=?, posted_date_ist=?, linkedin_urn=?,
           linkedin_permalink=?, first_comment_urn=?, metrics_watch_until_ist=?, publish_attempts=?,
           publish_lease_token=NULL, publish_lease_expires_at=NULL, updated_at=? WHERE id=?`,
      live ? 'posted' : 'ready',
      live ? (body.posted_at ?? nowUtc()) : null,
      live ? istDate() : null,
      live ? (body.linkedin_urn ?? null) : null,
      live ? (body.linkedin_permalink ?? null) : null,
      live ? (body.first_comment_urn ?? null) : null,
      live ? istDate(watch) : null,
      attempt, nowUtc(), p.id);
    return { status: 200, payload: { data: {
      post_uid: p.post_uid,
      lifecycle_state: live ? 'posted' : 'ready',
      dry_run: !live,
      posted_date_ist: live ? istDate() : null,
      metrics_watch_until_ist: live ? istDate(watch) : null,
    } } };
  }

  run(`UPDATE posts SET lifecycle_state='failed', publish_attempts=?, last_error_code=?, last_error_message=?,
         publish_lease_token=NULL, publish_lease_expires_at=NULL, updated_at=? WHERE id=?`,
    attempt, body.error_code ?? null, body.error_message ?? null, nowUtc(), p.id);
  return { status: 200, payload: { data: {
    post_uid: p.post_uid, lifecycle_state: 'failed', publish_attempts: attempt, will_retry: attempt < 5,
  } } };
};

// --- A6: analytics ---------------------------------------------------------

H.publishedPosts = ({ query }) => {
  const since = query.since || istDate(new Date(Date.now() - 183 * 86400000));
  const rows = all(`SELECT p.*, t.final_title FROM posts p JOIN topics t ON t.id=p.topic_id
     WHERE p.lifecycle_state='posted' AND p.posted_date_ist >= ? ORDER BY p.posted_date_ist DESC`, since);
  return { status: 200, payload: { data: rows.map((p) => {
    const last = get('SELECT * FROM post_metrics_daily WHERE post_id=? ORDER BY metric_date_ist DESC LIMIT 1', p.id);
    return {
      post_uid: p.post_uid, topic_title: p.final_title,
      linkedin_urn: p.linkedin_urn, linkedin_permalink: p.linkedin_permalink,
      posted_date_ist: p.posted_date_ist,
      age_days: Math.round((Date.parse(istDate()) - Date.parse(p.posted_date_ist)) / 86400000),
      last_metric_date_ist: last?.metric_date_ist ?? null,
      last_impressions: last?.impressions ?? null,
      last_shares: last?.shares ?? null,
    };
  }), page: { count: rows.length, window_start_ist: since } } };
};

/**
 * One row per post per day, so re-running A6 updates that row rather than
 * inserting a second one. Two rules carry real weight here:
 *
 *  - a metric the API did not return is stored NULL, never 0. A zero reads as
 *    "the post died" rather than "we could not look", and it would corrupt every
 *    day-over-day delta computed after it.
 *  - cumulative counters never regress. A partial read returning 40 views for a
 *    post that already showed 812 keeps 812 and counts the anomaly instead.
 */
H.metricsUpsert = ({ body }) => {
  const date = body.metric_date_ist || istDate();
  const r = get('SELECT id FROM agent_runs WHERE run_uid=?', body.run_uid);
  let inserted = 0, updated = 0, skippedRegression = 0;
  const unknown = [];
  const results = [];

  for (const it of body.items || []) {
    const p = get('SELECT id, posted_date_ist FROM posts WHERE post_uid=?', it.post_uid);
    if (!p) { unknown.push(it.post_uid); continue; }

    const prev = get(`SELECT * FROM post_metrics_daily WHERE post_id=? AND metric_date_ist < ?
                      ORDER BY metric_date_ist DESC LIMIT 1`, p.id, date);
    const existing = get('SELECT * FROM post_metrics_daily WHERE post_id=? AND metric_date_ist=?', p.id, date);

    const keep = (incoming, current) => {
      if (incoming == null) return current ?? null;
      if (current == null) return incoming;
      if (incoming < current) { skippedRegression++; return current; }
      return incoming;
    };
    const impressions = keep(it.impressions ?? null, existing?.impressions ?? null);
    const shares      = keep(it.shares ?? null, existing?.shares ?? null);
    const reactions   = keep(it.reactions ?? null, existing?.reactions ?? null);
    const comments    = keep(it.comments ?? null, existing?.comments ?? null);
    const clicks      = keep(it.clicks ?? null, existing?.clicks ?? null);
    const uniq        = keep(it.unique_impressions ?? null, existing?.unique_impressions ?? null);

    const dImp = impressions != null && prev?.impressions != null ? impressions - prev.impressions : null;
    const dShr = shares != null && prev?.shares != null ? shares - prev.shares : null;
    const age = Math.round((Date.parse(date) - Date.parse(p.posted_date_ist)) / 86400000);

    if (existing) {
      run(`UPDATE post_metrics_daily SET impressions=?, unique_impressions=?, shares=?, reactions=?, comments=?,
             clicks=?, delta_impressions=?, delta_shares=?, age_days=?, source=?, raw_field_map=?, api_version=?,
             gap_reason=?, collected_by_run_id=?, collected_at=?, revision=revision+1
           WHERE post_id=? AND metric_date_ist=?`,
        impressions, uniq, shares, reactions, comments, clicks, dImp, dShr, age,
        body.source ?? 'linkedin_api', J(it.raw_field_map ?? null), body.api_version ?? null,
        it.gap_reason ?? null, r?.id ?? null, nowUtc(), p.id, date);
      updated++;
      results.push({ post_uid: it.post_uid, action: 'updated', impressions, shares, delta_impressions: dImp });
    } else {
      run(`INSERT INTO post_metrics_daily (post_id, metric_date_ist, impressions, unique_impressions, shares,
             reactions, comments, clicks, delta_impressions, delta_shares, age_days, source, raw_field_map,
             api_version, gap_reason, collected_by_run_id, collected_at)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)`,
        p.id, date, impressions, uniq, shares, reactions, comments, clicks, dImp, dShr, age,
        body.source ?? 'linkedin_api', J(it.raw_field_map ?? null), body.api_version ?? null,
        it.gap_reason ?? null, r?.id ?? null, nowUtc());
      inserted++;
      results.push({ post_uid: it.post_uid, action: 'inserted', impressions, shares, delta_impressions: dImp });
    }
  }
  return { status: 200, payload: { data: {
    metric_date_ist: date, inserted, updated, skipped_regression: skippedRegression,
    unknown_post_uids: unknown, results,
  } } };
};

// ---------------------------------------------------------------------------
// /__mock/ : stands in for the human at the two approval gates.
// These routes do NOT exist in the real API. Part 2 implements them as the
// authenticated /api/v1/ui/* screens the owner actually clicks.
// ---------------------------------------------------------------------------
const M = {};

M.state = () => ({ status: 200, payload: { data: {
  runs: all('SELECT agent_code, status, business_date_ist, items_in, items_ok, items_failed, skip_reason FROM agent_runs ORDER BY id'),
  batches: all('SELECT batch_uid, state, topic_count, business_date_ist FROM topic_batches ORDER BY id'),
  topic_counts: all('SELECT review_status, pipeline_state, COUNT(*) n FROM topics GROUP BY 1,2'),
  post_counts: all('SELECT review_status, lifecycle_state, COUNT(*) n FROM posts GROUP BY 1,2'),
  images: get('SELECT COUNT(*) c FROM post_images').c,
  metrics_rows: get('SELECT COUNT(*) c FROM post_metrics_daily').c,
  dead_letters: all('SELECT agent_code, stage, error_code, attempts, status FROM dead_letters ORDER BY id'),
} } });

/** Gate 1. Approves the first N topics of a batch, optionally editing a title. */
M.approveTopics = ({ body }) => {
  const rows = all(`SELECT t.* FROM topics t JOIN topic_batches b ON b.id=t.batch_id
     WHERE (? IS NULL OR b.batch_uid = ?) AND t.review_status='pending' ORDER BY t.id LIMIT ?`,
    body.batch_uid ?? null, body.batch_uid ?? null, Number(body.count ?? 3));
  const touched = [];
  for (const [i, t] of rows.entries()) {
    let title = t.final_title;
    if (body.edit_first_title && i === 0) {
      title = `${t.final_title} (edited by owner)`;
      run(`INSERT INTO field_edits (entity_type, entity_uid, field_name, old_value, new_value, actor_type, actor_name)
           VALUES ('topic',?,?,?,?,'human','owner')`, t.topic_uid, 'final_title', t.final_title, title);
    }
    run(`UPDATE topics SET final_title=?, review_status='approved', pipeline_state='awaiting_copy',
           reviewed_at=?, reviewed_by='owner', updated_at=? WHERE id=?`, title, nowUtc(), nowUtc(), t.id);
    run(`INSERT INTO review_actions (entity_type, entity_uid, from_status, to_status, actor_type, actor_name)
         VALUES ('topic',?,?,'approved','human','owner')`, t.topic_uid, t.review_status);
    touched.push({ topic_uid: t.topic_uid, title, edited: title !== t.final_title });
  }
  return { status: 200, payload: { data: { approved: touched.length, topics: touched } } };
};

/** Gate 2. Picks an image, optionally edits a hashtag, approves, sets a date. */
M.approvePost = ({ body }) => {
  const p = body.post_uid
    ? get('SELECT * FROM posts WHERE post_uid=?', body.post_uid)
    : get(`SELECT * FROM posts WHERE lifecycle_state='in_review' ORDER BY id LIMIT 1`);
  if (!p) throw new ApiError(404, 'NOT_FOUND', 'no post awaiting review');

  const img = get('SELECT * FROM post_images WHERE post_id=? AND option_index=?', p.id, Number(body.select_image ?? 1));
  if (!img) throw new ApiError(422, 'NO_IMAGE_SELECTED', `image option ${body.select_image ?? 1} does not exist`);

  let tags = P(p.final_hashtags, []);
  if (body.replace_hashtag) {
    const old = JSON.stringify(tags);
    tags = [...tags.slice(0, -1), body.replace_hashtag];
    run(`INSERT INTO field_edits (entity_type, entity_uid, field_name, old_value, new_value, actor_type, actor_name)
         VALUES ('post',?,'final_hashtags',?,?,'human','owner')`, p.post_uid, old, JSON.stringify(tags));
  }
  const dateIst = body.scheduled_date_ist ?? null;
  const lifecycle = dateIst ? 'scheduled' : 'ready';
  // The hash is taken at approval time. If the content changes afterwards, the
  // publish-lease check will refuse it.
  const hash = sha256(`${p.final_body}|${img.id}|${JSON.stringify(tags)}`);

  run(`UPDATE posts SET final_hashtags=?, selected_image_id=?, review_status='approved',
         lifecycle_state=?, scheduled_date_ist=?, approved_content_hash=?, reviewed_at=?,
         reviewed_by='owner', updated_at=? WHERE id=?`,
    JSON.stringify(tags), img.id, lifecycle, dateIst, hash, nowUtc(), nowUtc(), p.id);
  run(`INSERT INTO review_actions (entity_type, entity_uid, from_status, to_status, actor_type, actor_name)
       VALUES ('post',?,?,'approved','human','owner')`, p.post_uid, p.review_status);

  return { status: 200, payload: { data: {
    post_uid: p.post_uid, lifecycle_state: lifecycle, selected_image_uid: img.image_uid,
    selected_option: img.option_index, hashtags: tags, scheduled_date_ist: dateIst,
  } } };
};

M.setStatus = ({ body }) => {
  const table = body.entity === 'post' ? 'posts' : 'topics';
  const col = body.entity === 'post' ? 'post_uid' : 'topic_uid';
  const row = get(`SELECT * FROM ${table} WHERE ${col}=?`, body.uid);
  if (!row) throw new ApiError(404, 'NOT_FOUND', `${body.entity} not found`);
  run(`UPDATE ${table} SET review_status=?, reviewed_at=?, reviewed_by='owner', updated_at=? WHERE ${col}=?`,
    body.review_status, nowUtc(), nowUtc(), body.uid);

  // Approving a topic also releases it to A2, and rejecting it takes it out of
  // the pipeline. The real UI does both in one action, so the stand-in must too,
  // or a topic can sit "approved" and invisible.
  if (body.entity === 'topic') {
    const next = body.review_status === 'approved' ? 'awaiting_copy'
      : body.review_status === 'rejected' ? 'discarded'
      : null;
    if (next && ['new', 'awaiting_copy', 'discarded'].includes(row.pipeline_state)) {
      run('UPDATE topics SET pipeline_state=? WHERE topic_uid=?', next, body.uid);
    }
  }
  run(`INSERT INTO review_actions (entity_type, entity_uid, from_status, to_status, actor_type, actor_name)
       VALUES (?,?,?,?,'human','owner')`, body.entity, body.uid, row.review_status, body.review_status);
  return { status: 200, payload: { data: { uid: body.uid, review_status: body.review_status } } };
};

M.editPost = ({ body }) => {
  const p = get('SELECT * FROM posts WHERE post_uid=?', body.post_uid);
  if (!p) throw new ApiError(404, 'NOT_FOUND', 'post not found');
  const newBody = body.final_body ?? p.final_body;
  run(`UPDATE posts SET final_body=?, final_word_count=?, updated_at=? WHERE id=?`,
    newBody, bodyWordCount(newBody), nowUtc(), p.id);
  run(`INSERT INTO field_edits (entity_type, entity_uid, field_name, old_value, new_value, actor_type, actor_name)
       VALUES ('post',?,'final_body',?,?,'human','owner')`, p.post_uid, p.final_body, newBody);
  return { status: 200, payload: { data: { post_uid: p.post_uid, word_count: bodyWordCount(newBody) } } };
};

M.fault = ({ body }) => {
  if (body.clear) { faults = {}; return { status: 200, payload: { data: { cleared: true } } }; }
  faults[body.route] = { mode: body.mode, count: Number(body.count ?? 1) };
  return { status: 200, payload: { data: { faults } } };
};

// ---------------------------------------------------------------------------
// Router
// ---------------------------------------------------------------------------
const AGENT = '/api/v1/agent';
const ROUTES = [
  ['POST',  `${AGENT}/runs`,                         'runs:write',        H.createRun],
  ['PATCH', `${AGENT}/runs/:runUid`,                 'runs:write',        H.patchRun],
  ['POST',  `${AGENT}/runs/:runUid/events`,          'runs:write',        H.postEvents],
  ['POST',  `${AGENT}/dead-letters`,                 'deadletters:write', H.deadLetter],
  ['GET',   `${AGENT}/config`,                        null,               H.getConfig],
  ['GET',   `${AGENT}/queue-depth`,                   null,               H.queueDepth],

  ['POST',  `${AGENT}/topic-batches`,                 'topics:write',     H.createBatch],
  ['POST',  `${AGENT}/topics/dedupe-check`,           'topics:write',     H.dedupeCheck],
  ['POST',  `${AGENT}/topic-batches/:batchUid/topics`,'topics:write',     H.addTopics],
  ['POST',  `${AGENT}/topic-batches/:batchUid/submit`,'topics:write',     H.submitBatch],

  ['GET',   `${AGENT}/topics`,                        'topics:read',      H.listTopics],
  ['POST',  `${AGENT}/topics/:topicUid/claim`,        'topics:claim',     H.claimTopic],
  ['POST',  `${AGENT}/posts`,                         'posts:write',      H.createPost],

  ['GET',   `${AGENT}/posts`,                         'posts:read',       H.listPosts],
  ['POST',  `${AGENT}/posts/:postUid/images`,         'images:write',     H.uploadImage],
  ['POST',  `${AGENT}/posts/:postUid/submit-for-review`, 'posts:submit',  H.submitForReview],

  ['GET',   `${AGENT}/publish-queue/next`,            'publish:write',    H.publishQueueNext],
  ['POST',  `${AGENT}/posts/:postUid/publish-lease`,  'publish:write',    H.publishLease],
  ['POST',  `${AGENT}/posts/:postUid/publish-result`, 'publish:write',    H.publishResult],

  ['GET',   `${AGENT}/posts/published`,               'posts:read',       H.publishedPosts],
  ['POST',  `${AGENT}/metrics:bulk-upsert`,           'metrics:write',    H.metricsUpsert],
];

const MOCK_ROUTES = [
  ['GET',  '/__mock/state',           M.state],
  ['POST', '/__mock/approve-topics',  M.approveTopics],
  ['POST', '/__mock/approve-post',    M.approvePost],
  ['POST', '/__mock/set-status',      M.setStatus],
  ['POST', '/__mock/edit-post',       M.editPost],
  ['POST', '/__mock/fault',           M.fault],
];

/** Literal segments must match; :name segments capture. */
function matchRoute(list, method, pathname) {
  for (const entry of list) {
    const [m, pattern] = entry;
    if (m !== method) continue;
    const pSeg = pattern.split('/');
    const aSeg = pathname.split('/');
    if (pSeg.length !== aSeg.length) continue;
    const params = {};
    let ok = true;
    for (let i = 0; i < pSeg.length; i++) {
      if (pSeg[i].startsWith(':')) params[pSeg[i].slice(1)] = decodeURIComponent(aSeg[i]);
      else if (pSeg[i] !== aSeg[i]) { ok = false; break; }
    }
    if (ok) return { entry, params };
  }
  return null;
}

// Longest literal prefix first, so /posts/published is not eaten by /posts/:postUid.
ROUTES.sort((a, b) => b[1].split(':')[0].length - a[1].split(':')[0].length);

const server = createServer(async (req, res) => {
  const url = new URL(req.url, 'http://localhost');
  const pathname = url.pathname;
  const query = Object.fromEntries(url.searchParams);

  try {
    if (pathname === '/healthz') return sendJson(res, 200, { ok: true, db: DB_PATH, time_ist: istDate() });

    if (pathname.startsWith('/media/')) {
      const file = join(MEDIA_DIR, pathname.slice('/media/'.length));
      if (!file.startsWith(MEDIA_DIR) || !existsSync(file)) throw new ApiError(404, 'NOT_FOUND', 'media not found');
      const bytes = readFileSync(file);
      res.writeHead(200, { 'Content-Type': file.endsWith('.jpg') ? 'image/jpeg' : 'image/png', 'Content-Length': bytes.length });
      return res.end(bytes);
    }

    const mock = matchRoute(MOCK_ROUTES.map(([m, p, h]) => [m, p, null, h]), req.method, pathname);
    if (mock) {
      const body = req.method === 'GET' ? {} : await readBody(req);
      const { status, payload } = mock.entry[3]({ body, query, params: mock.params });
      return sendJson(res, status, payload);
    }

    const hit = matchRoute(ROUTES, req.method, pathname);
    if (!hit) throw new ApiError(404, 'NOT_FOUND', `no route for ${req.method} ${pathname}`);

    const [, , scope, handler] = hit.entry;
    const agent = authenticate(req, scope);
    applyFault(req, pathname);

    const body = req.method === 'GET' ? {} : await readBody(req);
    const idem = idempotencyLookup(req, agent, body);
    if (idem?.replay) return sendJson(res, idem.status, idem.body, { 'Idempotency-Replayed': 'true' });

    const { status, payload } = handler({ agent, body, query, params: hit.params });
    if (status === 204) { res.writeHead(204); return res.end(); }
    idempotencyStore(idem, agent, req.method, pathname, status, payload);
    return sendJson(res, status, payload);
  } catch (err) {
    if (!(err instanceof ApiError)) console.error('[mock] unhandled:', err);
    return sendError(res, err instanceof ApiError ? err : new ApiError(500, 'INTERNAL', err.message));
  }
});

server.listen(PORT, '127.0.0.1', () => {
  console.log(`mock approval API on http://127.0.0.1:${PORT}`);
  console.log(`  db     ${DB_PATH}`);
  console.log(`  media  ${MEDIA_DIR}`);
  console.log(`  keys   ${Object.keys(KEYS).join(', ')}`);
});
