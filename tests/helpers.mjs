import { spawn } from 'node:child_process';
import { randomUUID, createHash } from 'node:crypto';
import { rmSync, existsSync } from 'node:fs';
import { resolve } from 'node:path';

export const ROOT = resolve(import.meta.dirname, '..');
export const PORT = Number(process.env.TEST_PORT || 8899);

/**
 * Remote mode. With GO_API_BASE set, the suite runs against a real deployment -
 * the PHP app on a laptop, or the cPanel host - instead of spawning the Node
 * mock. That is what makes "the contract suite passes against your host" a real
 * acceptance gate rather than a claim.
 */
export const REMOTE = Boolean(process.env.GO_API_BASE);
export const BASE = process.env.GO_API_BASE || `http://127.0.0.1:${PORT}`;
export const TEST_TOKEN = process.env.GO_TEST_TOKEN || null;
export const DB = resolve(ROOT, 'work/test.db');

export const KEY = {
  A1: process.env.GO_KEY_A1 || 'go_test_a1_key',
  A2: process.env.GO_KEY_A2 || 'go_test_a2_key',
  A3: process.env.GO_KEY_A3 || 'go_test_a3_key',
  A4: process.env.GO_KEY_A4 || 'go_test_a4_key',
  A5: process.env.GO_KEY_A5 || 'go_test_a5_key',
  A6: process.env.GO_KEY_A6 || 'go_test_a6_key',
};

export const uuid = () => randomUUID();
export const sha256 = (b) => createHash('sha256').update(b).digest('hex');
export const istDate = (d = new Date()) =>
  new Date(d.getTime() + 5.5 * 3600 * 1000).toISOString().slice(0, 10);

let child;

export async function startServer() {
  if (REMOTE) {
    const r = await fetch(`${BASE}/__mock/reset`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Test-Token': TEST_TOKEN ?? '' },
    });
    if (!r.ok) throw new Error(`remote reset failed: ${r.status} ${await r.text()}`);
    return;
  }
  for (const suffix of ['', '-wal', '-shm']) {
    const f = DB + suffix;
    if (existsSync(f)) rmSync(f);
  }
  child = spawn(process.execPath, ['mock-api/server.mjs', '--fresh', '--port', String(PORT), '--db', 'work/test.db'],
    { cwd: ROOT, stdio: ['ignore', 'pipe', 'pipe'] });
  child.stderr.on('data', (d) => process.stderr.write(`[mock] ${d}`));
  const deadline = Date.now() + 15000;
  while (Date.now() < deadline) {
    try {
      const r = await fetch(`${BASE}/healthz`);
      if (r.ok) return;
    } catch { /* not up yet */ }
    await new Promise((r) => setTimeout(r, 150));
  }
  throw new Error('mock server did not start within 15s');
}

export async function stopServer() {
  if (REMOTE) return;
  if (child) { child.kill(); child = null; }
}

/** Thin API client. Returns { status, body } and never throws on HTTP errors. */
export async function api(method, path, opts = {}) {
  const headers = { 'Content-Type': 'application/json', ...(opts.headers || {}) };
  if (opts.agent) headers.Authorization = `Bearer ${KEY[opts.agent]}`;
  if (opts.idempotencyKey) headers['Idempotency-Key'] = opts.idempotencyKey;
  if (TEST_TOKEN) headers['X-Test-Token'] = TEST_TOKEN;
  const res = await fetch(`${BASE}${path}`, {
    method,
    headers,
    body: opts.body === undefined ? undefined : JSON.stringify(opts.body),
  });
  const text = await res.text();
  let body = null;
  if (text) { try { body = JSON.parse(text); } catch { body = text; } }
  return { status: res.status, body, headers: res.headers };
}

/** Opens a run for an agent, which every other endpoint requires. */
export async function openRun(agent, opts = {}) {
  const run_uid = uuid();
  const r = await api('POST', '/api/v1/agent/runs', {
    agent,
    body: {
      run_uid, agent_code: agent,
      business_date_ist: opts.date || istDate(),
      attempt: opts.attempt ?? 1,
      trigger_type: opts.trigger || 'manual',
      dry_run: opts.dryRun ?? false,
    },
  });
  if (r.status >= 300) throw new Error(`openRun ${agent} failed: ${JSON.stringify(r.body)}`);
  return run_uid;
}

/** A 1x1 PNG, enough to exercise the upload and hashing path. */
export const PNG_1PX = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
  'base64');
