/**
 * Loads the real Part 1 samples into the PHP app, through the agent API - 35
 * verified topics, 3 posts, 6 real Higgsfield images, and seven days of metrics
 * with one deliberate gap so the analytics screen has something honest to draw.
 *
 * For looking at the screens with real content in them. The contract suite
 * truncates the tables, so run this again after npm run test:remote.
 *
 *   npm run seed            (needs the app running with env=test)
 */
import { readFileSync, existsSync } from 'node:fs';
import { randomUUID, createHash } from 'node:crypto';

const BASE = process.env.GO_API_BASE || 'http://127.0.0.1:8081';
const TOKEN = process.env.GO_TEST_TOKEN || 'local-test-token';
const KEY = (a) => `go_test_${a.toLowerCase()}_key`;
const sha256 = (b) => createHash('sha256').update(b).digest('hex');
const ist = (d = new Date()) => new Date(d.getTime() + 5.5 * 3600e3).toISOString().slice(0, 10);

export async function api(method, path, { agent, body } = {}) {
  const h = { 'Content-Type': 'application/json', 'X-Test-Token': TOKEN };
  if (agent) h.Authorization = `Bearer ${KEY(agent)}`;
  const r = await fetch(BASE + path, { method, headers: h, body: body === undefined ? undefined : JSON.stringify(body) });
  const t = await r.text();
  return { status: r.status, body: t ? JSON.parse(t) : null };
}
const openRun = async (agent, date, extra = {}) => {
  const run_uid = randomUUID();
  const r = await api('POST', '/api/v1/agent/runs', { agent, body: { run_uid, agent_code: agent, business_date_ist: date, ...extra } });
  if (r.status >= 300) throw new Error(`${agent}: ${JSON.stringify(r.body)}`);
  return run_uid;
};

await api('POST', '/__mock/reset', { body: {} });
console.log('reset');

const topics = JSON.parse(readFileSync('samples/topics-2026-09-16.json', 'utf8'));
const a1 = await openRun('A1', ist());
const batch_uid = randomUUID();
await api('POST', '/api/v1/agent/topic-batches', { agent: 'A1', body: { batch_uid, run_uid: a1, business_date_ist: ist(), title: 'HR topics, Wed 16 Sep 2026' } });
const withUids = topics.map((t) => ({ topic_uid: randomUUID(), ...t }));
const add = await api('POST', `/api/v1/agent/topic-batches/${batch_uid}/topics`, { agent: 'A1', body: { run_uid: a1, topics: withUids } });
await api('POST', `/api/v1/agent/topic-batches/${batch_uid}/submit`, { agent: 'A1', body: {} });
await api('PATCH', `/api/v1/agent/runs/${a1}`, { agent: 'A1', body: { status: 'succeeded', items_in: topics.length, items_ok: add.body.data.accepted } });
console.log(`A1: ${add.body.data.accepted} topics uploaded and submitted`);

const posts = ['post1', 'post2', 'post3'].map((s) => JSON.parse(readFileSync(`samples/posts/${s}.json`, 'utf8')));
const wanted = ['storyboard18.com', 'hyring.com', 'kpmg.com'];
const chosen = wanted.map((d) => withUids.find((t) => t.source_domain === d));
for (const t of chosen) await api('POST', '/__mock/set-status', { body: { entity: 'topic', uid: t.topic_uid, review_status: 'approved' } });
let rejected = 0;
for (const t of withUids) {
  if (chosen.includes(t) || rejected >= 4) continue;
  await api('POST', '/__mock/set-status', { body: { entity: 'topic', uid: t.topic_uid, review_status: 'rejected' } });
  rejected++;
}
console.log('owner approved 3, rejected 4');

const a2 = await openRun('A2', ist());
const postUids = [];
for (const [i, t] of chosen.entries()) {
  await api('POST', `/api/v1/agent/topics/${t.topic_uid}/claim`, { agent: 'A2', body: { run_uid: a2 } });
  const p = posts[i], post_uid = randomUUID();
  const r = await api('POST', '/api/v1/agent/posts', { agent: 'A2', body: {
    post_uid, topic_uid: t.topic_uid, run_uid: a2, hook: p.hook, body: p.body,
    hashtags: p.hashtags, keywords: p.keywords, cta_text: p.cta_text ?? null, cta_target: p.cta_target ?? null,
    first_comment_text: p.first_comment_text, numbers_used: p.numbers_used, image_brief: p.image_brief,
    model_name: 'claude-opus-5' } });
  if (r.status >= 300) throw new Error(JSON.stringify(r.body));
  postUids.push({ post_uid, slug: p.slug });
}
await api('PATCH', `/api/v1/agent/runs/${a2}`, { agent: 'A2', body: { status: 'succeeded', items_in: 3, items_ok: 3 } });
console.log('A2: 3 posts written');

const a3 = await openRun('A3', ist());
for (const { post_uid, slug } of postUids) {
  for (const idx of [1, 2]) {
    const file = `samples/images/${slug}-opt${idx}-${idx === 1 ? 'recraft' : 'soulcinema'}.png`;
    if (!existsSync(file)) continue;
    const bytes = readFileSync(file);
    await api('POST', `/api/v1/agent/posts/${post_uid}/images`, { agent: 'A3', body: {
      image_uid: randomUUID(), option_index: idx, run_uid: a3, provider: 'higgsfield',
      provider_model: idx === 1 ? 'recraft_v4_1' : 'soul_cinematic',
      concept_label: idx === 1 ? 'literal subject, editorial still' : 'cinematic wide, atmospheric',
      prompt_text: `see samples/images/${slug}`, negative_prompt: 'text, letters, logo, watermark, faces',
      aspect_ratio: '4:3', data_base64: bytes.toString('base64'), sha256: sha256(bytes),
      mime_type: 'image/png', alt_text: `${slug} option ${idx}`, ocr_text: '' } });
  }
}
await api('PATCH', `/api/v1/agent/runs/${a3}`, { agent: 'A3', body: { status: 'succeeded', items_in: 3, items_ok: 3 } });
console.log('A3: 6 real images uploaded');

const a4 = await openRun('A4', ist());
for (const { post_uid } of postUids) {
  await api('POST', `/api/v1/agent/posts/${post_uid}/submit-for-review`, { agent: 'A4', body: { run_uid: a4 } });
}
await api('PATCH', `/api/v1/agent/runs/${a4}`, { agent: 'A4', body: { status: 'succeeded', items_in: 3, items_ok: 3 } });
console.log('A4: 3 packages waiting at gate 2');

// One already published, so the analytics screen has a real curve to draw.
await api('POST', '/__mock/approve-post', { body: { post_uid: postUids[0].post_uid, select_image: 1 } });
const a5 = await openRun('A5', ist());
const lease = await api('POST', `/api/v1/agent/posts/${postUids[0].post_uid}/publish-lease`, { agent: 'A5', body: { run_uid: a5 } });
const urn = `urn:li:share:${Date.now()}`;
await api('POST', `/api/v1/agent/posts/${postUids[0].post_uid}/publish-result`, { agent: 'A5', body: {
  run_uid: a5, lease_token: lease.body.data.lease_token, outcome: 'success', linkedin_urn: urn,
  linkedin_permalink: `https://www.linkedin.com/feed/update/${urn}/` } });
await api('PATCH', `/api/v1/agent/runs/${a5}`, { agent: 'A5', body: { status: 'succeeded', items_in: 1, items_ok: 1 } });

const a6 = await openRun('A6', ist());
const days = [[-6, 640, 4], [-5, 1180, 9], [-4, 1720, 13], [-2, 2410, 19], [-1, 2905, 24], [0, 3180, 27]];
for (const [off, imp, sh] of days) {
  await api('POST', '/api/v1/agent/metrics:bulk-upsert', { agent: 'A6', body: {
    run_uid: a6, metric_date_ist: ist(new Date(Date.now() + off * 86400e3)),
    items: [{ post_uid: postUids[0].post_uid, impressions: imp, shares: sh,
              reactions: Math.round(imp / 25), comments: Math.round(imp / 300), clicks: Math.round(imp / 40) }] } });
}
// One day deliberately unreadable. The chart must draw a gap, never a zero.
await api('POST', '/api/v1/agent/metrics:bulk-upsert', { agent: 'A6', body: {
  run_uid: a6, metric_date_ist: ist(new Date(Date.now() - 3 * 86400e3)),
  items: [{ post_uid: postUids[0].post_uid, impressions: null, shares: null,
            gap_reason: 'linkedin token refresh failed' }] } });
await api('PATCH', `/api/v1/agent/runs/${a6}`, { agent: 'A6', body: { status: 'succeeded', items_in: 1, items_ok: 1 } });
console.log('A5 published one; A6 wrote 7 days of readings with one deliberate gap');
console.log('\npost waiting at gate 2:', postUids[1].post_uid);
