/**
 * Contract tests for the approval API.
 *
 * These run green against the mock today. Part 2 (the PHP + MySQL app) is DONE
 * when the same suite runs green against the cloud host: point TEST_PORT and the
 * base URL at it and nothing else changes.
 *
 *   node --test tests/contract.test.mjs
 */
import { test, before, after, describe } from 'node:test';
import assert from 'node:assert/strict';
import {
  startServer, stopServer, api, openRun, uuid, sha256, istDate, PNG_1PX,
} from './helpers.mjs';

before(startServer);
after(stopServer);

// uq_runs_slot deliberately allows one run per agent per business date, so every
// synthetic batch in this suite needs its own date.
let dateCounter = 0;
const nextDate = () => {
  dateCounter += 1;
  return new Date(Date.UTC(2026, 3, 1) + dateCounter * 86400000).toISOString().slice(0, 10);
};

/** Builds a batch of `n` topics and returns its uid. */
async function seedBatch(n = 30) {
  const runUid = await openRun('A1', { date: nextDate() });
  const batch_uid = uuid();
  await api('POST', '/api/v1/agent/topic-batches', {
    agent: 'A1',
    body: { batch_uid, run_uid: runUid, business_date_ist: istDate(), title: `Test batch ${batch_uid.slice(0, 8)}` },
  });
  const topics = Array.from({ length: n }, (_, i) => ({
    topic_uid: uuid(),
    title: `Notice periods are costing you your best hires ${uuid().slice(0, 8)}`,
    one_liner: 'Senior BFSI finalists hold two or three live offers, so a long serving window hands them to a faster competitor.',
    source_url: 'https://www.ere.net/example',
    source_domain: 'ere.net',
    source_quote: 'Median time to accept fell sharply this year.',
    source_published_on: '2026-08-01',
    post_type: ['data_point', 'contrarian_take', 'myth_bust'][i % 3],
    theme_tag: ['notice_period', 'attrition', 'gcc_hiring'][i % 3],
    india_relevance: 2,
    cta_flag: i === 0,
    cta_type: i === 0 ? 'email' : 'none',
    cta_text: i === 0 ? 'Ask us for the benchmark' : null,
    cta_target: i === 0 ? 'rc@gojobs.biz' : null,
  }));
  const add = await api('POST', `/api/v1/agent/topic-batches/${batch_uid}/topics`, {
    agent: 'A1', body: { run_uid: runUid, topics },
  });
  assert.equal(add.status, 207);
  return { batch_uid, runUid, topics, added: add.body.data };
}

const goodPost = (over = {}) => ({
  hook: 'Your finalist accepted somewhere else. On day 38.',
  body: 'Your finalist accepted somewhere else. On day 38.\n\nFor senior BFSI mandates the person you picked is holding two other offers.\n\nA long notice window is not a formality. It is time for someone faster to close them.\n\nRun a shorter structured process and name an onboarding contact at offer.',
  cta_text: 'Ask us for the sector benchmark',
  cta_target: 'rc@gojobs.biz',
  hashtags: ['#TalentAcquisition', '#HRLeadership', '#HiringIndia', '#BFSI'],
  keywords: ['notice period', 'offer decline', 'time to hire'],
  first_comment_text: 'Sector benchmarks: rc@gojobs.biz or https://www.GOjobs.biz',
  numbers_used: [],
  image_brief: { subject: 'HR leader reviewing a hiring pipeline', mood: 'considered' },
  ...over,
});

describe('auth and scopes', () => {
  test('no bearer token is 401', async () => {
    const r = await api('GET', '/api/v1/agent/topics');
    assert.equal(r.status, 401);
    assert.equal(r.body.error.code, 'UNAUTHORIZED');
  });

  test('an unknown key is 401', async () => {
    const r = await api('GET', '/api/v1/agent/topics', { headers: { Authorization: 'Bearer nope' } });
    assert.equal(r.status, 401);
  });

  test('a key without the scope is 403, not 401', async () => {
    const r = await api('POST', '/api/v1/agent/metrics:bulk-upsert', { agent: 'A1', body: { items: [] } });
    assert.equal(r.status, 403);
    assert.equal(r.body.error.code, 'FORBIDDEN_SCOPE');
  });

  test('errors always carry a retryable flag for the agent to branch on', async () => {
    const r = await api('GET', '/api/v1/agent/topics');
    assert.equal(typeof r.body.error.retryable, 'boolean');
    assert.equal(r.body.error.retryable, false);
  });
});

describe('run lifecycle', () => {
  test('a double-fired cron gets 409, not two runs', async () => {
    const date = '2026-01-15';
    await openRun('A1', { date });
    const second = await api('POST', '/api/v1/agent/runs', {
      agent: 'A1',
      body: { run_uid: uuid(), agent_code: 'A1', business_date_ist: date, attempt: 1 },
    });
    assert.equal(second.status, 409);
    assert.equal(second.body.error.code, 'DUPLICATE_RUN_SLOT');
  });

  test('a key cannot open a run for another agent', async () => {
    const r = await api('POST', '/api/v1/agent/runs', {
      agent: 'A1',
      body: { run_uid: uuid(), agent_code: 'A5', business_date_ist: '2026-01-16' },
    });
    assert.equal(r.status, 403);
  });

  test('a no-op run is recorded with a reason, so silence is detectable', async () => {
    const runUid = await openRun('A2', { date: '2026-01-17' });
    const r = await api('PATCH', `/api/v1/agent/runs/${runUid}`, {
      agent: 'A2', body: { status: 'skipped', skip_reason: 'no_approved_topics', items_in: 0 },
    });
    assert.equal(r.status, 200);
    assert.equal(r.body.data.status, 'skipped');
    assert.equal(r.body.data.skip_reason, 'no_approved_topics');
  });
});

describe('idempotency', () => {
  test('same key and same body replays the stored response', async () => {
    const runUid = await openRun('A1', { date: '2026-01-18' });
    const key = `idem-${uuid()}`;
    const body = { batch_uid: uuid(), run_uid: runUid, business_date_ist: '2026-01-18', title: 'Idem batch' };
    const first = await api('POST', '/api/v1/agent/topic-batches', { agent: 'A1', body, idempotencyKey: key });
    const second = await api('POST', '/api/v1/agent/topic-batches', { agent: 'A1', body, idempotencyKey: key });
    assert.equal(first.status, 201);
    assert.equal(second.status, 201);
    assert.equal(second.headers.get('idempotency-replayed'), 'true');
    assert.deepEqual(second.body, first.body);
  });

  test('same key with a different body is a conflict', async () => {
    const runUid = await openRun('A1', { date: '2026-01-19' });
    const key = `idem-${uuid()}`;
    await api('POST', '/api/v1/agent/topic-batches', {
      agent: 'A1', idempotencyKey: key,
      body: { batch_uid: uuid(), run_uid: runUid, business_date_ist: '2026-01-19', title: 'A' },
    });
    const clash = await api('POST', '/api/v1/agent/topic-batches', {
      agent: 'A1', idempotencyKey: key,
      body: { batch_uid: uuid(), run_uid: runUid, business_date_ist: '2026-01-19', title: 'B' },
    });
    assert.equal(clash.status, 409);
    assert.equal(clash.body.error.code, 'IDEMPOTENCY_KEY_REUSED_WITH_DIFFERENT_BODY');
  });
});

describe('A1 topic batches', () => {
  test('a batch under the 30-topic minimum cannot be submitted', async () => {
    const { batch_uid } = await seedBatch(5);
    const r = await api('POST', `/api/v1/agent/topic-batches/${batch_uid}/submit`, { agent: 'A1', body: {} });
    assert.equal(r.status, 422);
    assert.equal(r.body.error.code, 'VALIDATION_FAILED');
    assert.equal(r.body.error.details.topic_count, 5);
  });

  test('a full batch submits and becomes visible to the owner', async () => {
    const { batch_uid } = await seedBatch(30);
    const r = await api('POST', `/api/v1/agent/topic-batches/${batch_uid}/submit`, { agent: 'A1', body: {} });
    assert.equal(r.status, 200);
    assert.equal(r.body.data.state, 'submitted');
    assert.equal(r.body.data.topic_count, 30);
  });

  test('a duplicate title inside a batch is dropped, the rest still land', async () => {
    const runUid = await openRun('A1', { date: '2026-01-20' });
    const batch_uid = uuid();
    await api('POST', '/api/v1/agent/topic-batches', {
      agent: 'A1', body: { batch_uid, run_uid: runUid, business_date_ist: '2026-01-20', title: 'Dupe batch' },
    });
    const dup = { title: 'The same exact topic', one_liner: 'x'.repeat(50) };
    const r = await api('POST', `/api/v1/agent/topic-batches/${batch_uid}/topics`, {
      agent: 'A1',
      body: { run_uid: runUid, topics: [
        { topic_uid: uuid(), ...dup },
        { topic_uid: uuid(), ...dup },
        { topic_uid: uuid(), title: 'A different topic entirely', one_liner: 'y'.repeat(50) },
      ] },
    });
    assert.equal(r.status, 207);
    assert.equal(r.body.data.accepted, 2);
    assert.equal(r.body.data.duplicates, 1);
  });

  test('a malformed topic is rejected without losing its siblings', async () => {
    const runUid = await openRun('A1', { date: '2026-01-21' });
    const batch_uid = uuid();
    await api('POST', '/api/v1/agent/topic-batches', {
      agent: 'A1', body: { batch_uid, run_uid: runUid, business_date_ist: '2026-01-21', title: 'Mixed batch' },
    });
    const r = await api('POST', `/api/v1/agent/topic-batches/${batch_uid}/topics`, {
      agent: 'A1',
      body: { run_uid: runUid, topics: [
        { topic_uid: uuid(), title: 'Valid topic here', one_liner: 'ok'.repeat(10) },
        { topic_uid: uuid(), title: 'No one liner' },
        { topic_uid: uuid(), title: 'Too long one liner', one_liner: 'z'.repeat(600) },
      ] },
    });
    assert.equal(r.body.data.accepted, 1);
    assert.equal(r.body.data.rejected, 2);
  });
});

describe('the human gate is real', () => {
  test('A2 is only ever handed approved topics', async () => {
    const { batch_uid } = await seedBatch(30);
    await api('POST', `/api/v1/agent/topic-batches/${batch_uid}/submit`, { agent: 'A1', body: {} });

    const beforeApproval = await api('GET', '/api/v1/agent/topics', { agent: 'A2' });
    const initial = beforeApproval.body.data.length;

    await api('POST', '/__mock/approve-topics', { body: { batch_uid, count: 3 } });
    const afterApproval = await api('GET', '/api/v1/agent/topics', { agent: 'A2' });
    assert.equal(afterApproval.body.data.length, initial + 3);
  });

  test('a topic on hold is invisible to A2 and cannot be claimed', async () => {
    const { batch_uid } = await seedBatch(30);
    await api('POST', `/api/v1/agent/topic-batches/${batch_uid}/submit`, { agent: 'A1', body: {} });
    const appr = await api('POST', '/__mock/approve-topics', { body: { batch_uid, count: 1 } });
    const uid = appr.body.data.topics[0].topic_uid;

    await api('POST', '/__mock/set-status', { body: { entity: 'topic', uid, review_status: 'hold' } });

    const list = await api('GET', '/api/v1/agent/topics', { agent: 'A2' });
    assert.ok(!list.body.data.some((t) => t.topic_uid === uid), 'held topic must not be listed');

    const runUid = await openRun('A2', { date: '2026-02-01' });
    const claim = await api('POST', `/api/v1/agent/topics/${uid}/claim`, { agent: 'A2', body: { run_uid: runUid } });
    assert.equal(claim.status, 409);
    assert.equal(claim.body.error.code, 'TOPIC_NOT_APPROVED');
  });

  test('an owner edit survives and the agent original is preserved', async () => {
    const { batch_uid, topics } = await seedBatch(30);
    await api('POST', `/api/v1/agent/topic-batches/${batch_uid}/submit`, { agent: 'A1', body: {} });
    const appr = await api('POST', '/__mock/approve-topics', { body: { batch_uid, count: 1, edit_first_title: true } });
    const edited = appr.body.data.topics[0];
    assert.ok(edited.edited, 'the mock should have edited the title');

    const list = await api('GET', '/api/v1/agent/topics', { agent: 'A2' });
    const row = list.body.data.find((t) => t.topic_uid === edited.topic_uid);
    // A2 writes from the owner's version...
    assert.equal(row.title, edited.title);
    assert.match(row.title, /\(edited by owner\)$/);
    // ...while A1's original is still on the record, unchanged.
    const original = topics.find((t) => t.topic_uid === edited.topic_uid).title;
    assert.equal(row.provenance.original_title, original);
    assert.notEqual(row.provenance.original_title, row.title);
    assert.equal(row.was_edited, true);
  });

  test('two runs cannot claim the same topic', async () => {
    const { batch_uid } = await seedBatch(30);
    await api('POST', `/api/v1/agent/topic-batches/${batch_uid}/submit`, { agent: 'A1', body: {} });
    const appr = await api('POST', '/__mock/approve-topics', { body: { batch_uid, count: 1 } });
    const uid = appr.body.data.topics[0].topic_uid;

    const runA = await openRun('A2', { date: '2026-02-02' });
    const runB = await openRun('A2', { date: '2026-02-02', attempt: 2 });
    const first = await api('POST', `/api/v1/agent/topics/${uid}/claim`, { agent: 'A2', body: { run_uid: runA } });
    const second = await api('POST', `/api/v1/agent/topics/${uid}/claim`, { agent: 'A2', body: { run_uid: runB } });
    assert.equal(first.status, 200);
    assert.equal(second.status, 409);
    assert.equal(second.body.error.code, 'LEASE_HELD_BY_OTHER_RUN');
  });
});

describe('A2 copy gates', () => {
  /** Approves one topic and returns its uid plus an open A2 run. */
  async function approvedTopic() {
    const { batch_uid } = await seedBatch(30);
    await api('POST', `/api/v1/agent/topic-batches/${batch_uid}/submit`, { agent: 'A1', body: {} });
    const appr = await api('POST', '/__mock/approve-topics', { body: { batch_uid, count: 1 } });
    const runUid = await openRun('A2', { date: nextDate() });
    return { topic_uid: appr.body.data.topics[0].topic_uid, runUid };
  }

  test('a body over 100 words is refused, not silently truncated', async () => {
    const { topic_uid, runUid } = await approvedTopic();
    const r = await api('POST', '/api/v1/agent/posts', {
      agent: 'A2',
      body: { post_uid: uuid(), topic_uid, run_uid: runUid, ...goodPost({ body: 'word '.repeat(105).trim() }) },
    });
    assert.equal(r.status, 422);
    assert.equal(r.body.error.code, 'WORD_COUNT_EXCEEDED');
    assert.equal(r.body.error.details.word_count, 105);
  });

  test('a URL in the body is refused, because it suppresses reach', async () => {
    const { topic_uid, runUid } = await approvedTopic();
    const r = await api('POST', '/api/v1/agent/posts', {
      agent: 'A2',
      body: { post_uid: uuid(), topic_uid, run_uid: runUid,
              ...goodPost({ body: 'A short post that wrongly links to https://www.GOjobs.biz inside the body.' }) },
    });
    assert.equal(r.status, 422);
    assert.equal(r.body.error.code, 'LINK_IN_BODY');
  });

  test('hashtag count is enforced at both ends of the range', async () => {
    const { topic_uid, runUid } = await approvedTopic();
    for (const tags of [['#One', '#Two'], ['#a', '#b', '#c', '#d', '#e', '#f']]) {
      const r = await api('POST', '/api/v1/agent/posts', {
        agent: 'A2', body: { post_uid: uuid(), topic_uid, run_uid: runUid, ...goodPost({ hashtags: tags }) },
      });
      assert.equal(r.status, 422, `expected refusal for ${tags.length} hashtags`);
    }
  });

  test('hashtag lines do not count toward the 100 body words', async () => {
    const { topic_uid, runUid } = await approvedTopic();
    const body = `${'word '.repeat(98).trim()}\n\n#TalentAcquisition #HRLeadership #HiringIndia #BFSI`;
    const r = await api('POST', '/api/v1/agent/posts', {
      agent: 'A2', body: { post_uid: uuid(), topic_uid, run_uid: runUid, ...goodPost({ body }) },
    });
    assert.equal(r.status, 201);
    assert.equal(r.body.data.word_count, 98);
  });
});

/** Walks a topic all the way to a post sitting at gate 2 with two images. */
async function packagedPost() {
  const { batch_uid } = await seedBatch(30);
  await api('POST', `/api/v1/agent/topic-batches/${batch_uid}/submit`, { agent: 'A1', body: {} });
  const appr = await api('POST', '/__mock/approve-topics', { body: { batch_uid, count: 1 } });
  const topic_uid = appr.body.data.topics[0].topic_uid;

  const a2 = await openRun('A2', { date: nextDate() });
  const post_uid = uuid();
  const created = await api('POST', '/api/v1/agent/posts', {
    agent: 'A2', body: { post_uid, topic_uid, run_uid: a2, ...goodPost() },
  });
  assert.equal(created.status, 201);

  const a3 = await openRun('A3', { date: nextDate() });
  for (const [i, model] of [['1', 'recraft_v4_1'], ['2', 'soul_cinematic']]) {
    const r = await api('POST', `/api/v1/agent/posts/${post_uid}/images`, {
      agent: 'A3',
      body: {
        image_uid: uuid(), option_index: Number(i), run_uid: a3,
        provider: 'higgsfield', provider_model: model,
        prompt_text: 'HR leader reviewing a hiring pipeline, no text',
        data_base64: PNG_1PX.toString('base64'), sha256: sha256(PNG_1PX),
        mime_type: 'image/png', concept_label: model, alt_text: 'test image', ocr_text: '',
      },
    });
    assert.equal(r.status, 201, JSON.stringify(r.body));
  }
  const a4 = await openRun('A4', { date: nextDate() });
  const sub = await api('POST', `/api/v1/agent/posts/${post_uid}/submit-for-review`, {
    agent: 'A4', body: { run_uid: a4 },
  });
  assert.equal(sub.status, 200);
  return { post_uid, topic_uid };
}

describe('A3 images', () => {
  test('a corrupt download is caught at the boundary', async () => {
    const { batch_uid } = await seedBatch(30);
    await api('POST', `/api/v1/agent/topic-batches/${batch_uid}/submit`, { agent: 'A1', body: {} });
    const appr = await api('POST', '/__mock/approve-topics', { body: { batch_uid, count: 1 } });
    const a2 = await openRun('A2', { date: nextDate() });
    const post_uid = uuid();
    await api('POST', '/api/v1/agent/posts', {
      agent: 'A2', body: { post_uid, topic_uid: appr.body.data.topics[0].topic_uid, run_uid: a2, ...goodPost() },
    });
    const a3 = await openRun('A3', { date: nextDate() });
    const r = await api('POST', `/api/v1/agent/posts/${post_uid}/images`, {
      agent: 'A3',
      body: { image_uid: uuid(), option_index: 1, run_uid: a3, prompt_text: 'x',
              data_base64: PNG_1PX.toString('base64'), sha256: 'deadbeef'.repeat(8), mime_type: 'image/png' },
    });
    assert.equal(r.status, 422);
    assert.equal(r.body.error.code, 'SHA256_MISMATCH');
  });

  test('two options are stored and served', async () => {
    const { post_uid } = await packagedPost();
    const list = await api('GET', '/api/v1/agent/posts?lifecycle_state=in_review', { agent: 'A4' });
    const row = list.body.data.find((p) => p.post_uid === post_uid);
    assert.equal(row.image_count, 2);
  });

  test('A3 cannot reach the publish endpoints', async () => {
    const r = await api('GET', '/api/v1/agent/publish-queue/next', { agent: 'A3' });
    assert.equal(r.status, 403);
  });
});

describe('A5 publishing', () => {
  test('nothing publishes until the owner approves at gate 2', async () => {
    await packagedPost();
    const q = await api('GET', '/api/v1/agent/publish-queue/next', { agent: 'A5' });
    assert.equal(q.status, 204, 'an unapproved post must not be publishable');
  });

  test('an approved post is offered, leased and published', async () => {
    const { post_uid } = await packagedPost();
    const appr = await api('POST', '/__mock/approve-post', {
      body: { post_uid, select_image: 2, replace_hashtag: '#GCCHiring', scheduled_date_ist: istDate() },
    });
    assert.equal(appr.body.data.selected_option, 2);
    assert.equal(appr.body.data.lifecycle_state, 'scheduled');

    const q = await api('GET', '/api/v1/agent/publish-queue/next', { agent: 'A5' });
    assert.equal(q.status, 200);
    assert.equal(q.body.data.post_uid, post_uid);
    assert.equal(q.body.data.reason, 'scheduled_for_today');

    const a5 = await openRun('A5', { date: nextDate() });
    const lease = await api('POST', `/api/v1/agent/posts/${post_uid}/publish-lease`, { agent: 'A5', body: { run_uid: a5 } });
    assert.equal(lease.status, 200);
    assert.ok(lease.body.data.lease_token);
    // The server composes the exact string that goes to LinkedIn.
    assert.match(lease.body.data.commentary, /#GCCHiring/);
    assert.equal(lease.body.data.commentary_sha256.length, 64);
    assert.ok(lease.body.data.first_comment_text, 'the link belongs in the first comment');

    const res = await api('POST', `/api/v1/agent/posts/${post_uid}/publish-result`, {
      agent: 'A5',
      body: { run_uid: a5, lease_token: lease.body.data.lease_token, outcome: 'success',
              linkedin_urn: `urn:li:share:${Date.now()}`, http_status: 201 },
    });
    assert.equal(res.body.data.lifecycle_state, 'posted');
    assert.ok(res.body.data.metrics_watch_until_ist, 'A6 needs a watch window');
  });
});

describe('A5 cannot double-post', () => {
  /** Approves a packaged post and publishes it, returning its uid. */
  async function published(dateIst) {
    const { post_uid } = await packagedPost();
    await api('POST', '/__mock/approve-post', { body: { post_uid, select_image: 1, scheduled_date_ist: dateIst } });
    const a5 = await openRun('A5', { date: nextDate() });
    const lease = await api('POST', `/api/v1/agent/posts/${post_uid}/publish-lease`, { agent: 'A5', body: { run_uid: a5 } });
    await api('POST', `/api/v1/agent/posts/${post_uid}/publish-result`, {
      agent: 'A5',
      body: { run_uid: a5, lease_token: lease.body.data.lease_token, outcome: 'success',
              linkedin_urn: `urn:li:share:${uuid()}` },
    });
    return post_uid;
  }

  test('a post already live cannot be leased again', async () => {
    const post_uid = await published(istDate());
    const a5 = await openRun('A5', { date: nextDate() });
    const again = await api('POST', `/api/v1/agent/posts/${post_uid}/publish-lease`, { agent: 'A5', body: { run_uid: a5 } });
    assert.equal(again.status, 409);
    assert.equal(again.body.error.code, 'ALREADY_POSTED');
  });

  test('the queue goes quiet once something has gone out today', async () => {
    await published(istDate());
    const q = await api('GET', `/api/v1/agent/publish-queue/next?date=${istDate()}`, { agent: 'A5' });
    assert.equal(q.status, 204, 'only one post a day');
  });

  test('replaying the same publish result does not post twice', async () => {
    const { post_uid } = await packagedPost();
    await api('POST', '/__mock/approve-post', { body: { post_uid, select_image: 1 } });
    const a5 = await openRun('A5', { date: nextDate() });
    const lease = await api('POST', `/api/v1/agent/posts/${post_uid}/publish-lease`, { agent: 'A5', body: { run_uid: a5 } });
    const payload = {
      run_uid: a5, lease_token: lease.body.data.lease_token, outcome: 'success',
      linkedin_urn: `urn:li:share:${uuid()}`, idempotency_key: sha256(`fixed-${post_uid}`),
    };
    const first = await api('POST', `/api/v1/agent/posts/${post_uid}/publish-result`, { agent: 'A5', body: payload });
    const second = await api('POST', `/api/v1/agent/posts/${post_uid}/publish-result`, { agent: 'A5', body: payload });
    assert.equal(first.body.data.lifecycle_state, 'posted');
    assert.equal(second.body.data.replayed, true);
  });
});

describe('the owner always wins', () => {
  test('hold at 07:55 beats approval at 08:00', async () => {
    const { post_uid } = await packagedPost();
    await api('POST', '/__mock/approve-post', { body: { post_uid, select_image: 1 } });
    await api('POST', '/__mock/set-status', { body: { entity: 'post', uid: post_uid, review_status: 'hold' } });
    const a5 = await openRun('A5', { date: nextDate() });
    const lease = await api('POST', `/api/v1/agent/posts/${post_uid}/publish-lease`, { agent: 'A5', body: { run_uid: a5 } });
    assert.equal(lease.status, 422);
    assert.equal(lease.body.error.code, 'POST_NOT_APPROVED');
  });

  test('a post edited after approval will not publish until re-approved', async () => {
    const { post_uid } = await packagedPost();
    await api('POST', '/__mock/approve-post', { body: { post_uid, select_image: 1 } });
    await api('POST', '/__mock/edit-post', {
      body: { post_uid, final_body: 'A different body that the owner never approved in this form.' },
    });
    const a5 = await openRun('A5', { date: nextDate() });
    const lease = await api('POST', `/api/v1/agent/posts/${post_uid}/publish-lease`, { agent: 'A5', body: { run_uid: a5 } });
    assert.equal(lease.status, 409);
    assert.equal(lease.body.error.code, 'CONTENT_CHANGED_AFTER_APPROVAL');
  });

  test('a post dated for next week waits until next week', async () => {
    const { post_uid } = await packagedPost();
    const future = istDate(new Date(Date.now() + 7 * 86400000));
    await api('POST', '/__mock/approve-post', { body: { post_uid, select_image: 1, scheduled_date_ist: future } });

    const tomorrow = istDate(new Date(Date.now() + 86400000));
    const early = await api('GET', `/api/v1/agent/publish-queue/next?date=${tomorrow}`, { agent: 'A5' });
    const offeredEarly = early.status === 200 && early.body.data.post_uid === post_uid;
    assert.equal(offeredEarly, false, 'a future-dated post must not be offered early');

    const onTheDay = await api('GET', `/api/v1/agent/publish-queue/next?date=${future}`, { agent: 'A5' });
    assert.equal(onTheDay.status, 200);
    assert.equal(onTheDay.body.data.post_uid, post_uid);
  });

  test('dry run builds the payload but does not mark anything posted', async () => {
    const { post_uid } = await packagedPost();
    await api('POST', '/__mock/approve-post', { body: { post_uid, select_image: 1 } });
    const a5 = await openRun('A5', { date: nextDate(), dryRun: true });
    const lease = await api('POST', `/api/v1/agent/posts/${post_uid}/publish-lease`, { agent: 'A5', body: { run_uid: a5 } });
    const res = await api('POST', `/api/v1/agent/posts/${post_uid}/publish-result`, {
      agent: 'A5', body: { run_uid: a5, lease_token: lease.body.data.lease_token, outcome: 'dry_run' },
    });
    assert.equal(res.body.data.dry_run, true);
    assert.equal(res.body.data.lifecycle_state, 'ready');
    assert.equal(res.body.data.posted_date_ist, null);
  });
});

describe('A6 analytics', () => {
  /** Publishes a post so there is something to measure. */
  async function livePost() {
    const { post_uid } = await packagedPost();
    await api('POST', '/__mock/approve-post', { body: { post_uid, select_image: 1 } });
    const a5 = await openRun('A5', { date: nextDate() });
    const lease = await api('POST', `/api/v1/agent/posts/${post_uid}/publish-lease`, { agent: 'A5', body: { run_uid: a5 } });
    await api('POST', `/api/v1/agent/posts/${post_uid}/publish-result`, {
      agent: 'A5',
      body: { run_uid: a5, lease_token: lease.body.data.lease_token, outcome: 'success', linkedin_urn: `urn:li:share:${uuid()}` },
    });
    return post_uid;
  }

  test('published posts carry the URN A6 needs to query them', async () => {
    const post_uid = await livePost();
    const r = await api('GET', '/api/v1/agent/posts/published', { agent: 'A6' });
    const row = r.body.data.find((p) => p.post_uid === post_uid);
    assert.ok(row, 'the post should be in the 6-month window');
    assert.match(row.linkedin_urn, /^urn:li:share:/);
  });

  test('running A6 twice on one day makes one row, not two', async () => {
    const post_uid = await livePost();
    const a6 = await openRun('A6', { date: nextDate() });
    const day = '2026-06-01';
    const payload = { run_uid: a6, metric_date_ist: day, items: [{ post_uid, impressions: 1200, shares: 14 }] };
    const first = await api('POST', '/api/v1/agent/metrics:bulk-upsert', { agent: 'A6', body: payload });
    const second = await api('POST', '/api/v1/agent/metrics:bulk-upsert', { agent: 'A6', body: payload });
    assert.equal(first.body.data.inserted, 1);
    assert.equal(second.body.data.inserted, 0);
    assert.equal(second.body.data.updated, 1);
  });

  test('a missing metric is stored as unknown, never as zero', async () => {
    const post_uid = await livePost();
    const a6 = await openRun('A6', { date: nextDate() });
    const r = await api('POST', '/api/v1/agent/metrics:bulk-upsert', {
      agent: 'A6',
      body: { run_uid: a6, metric_date_ist: '2026-06-02',
              items: [{ post_uid, impressions: 900, shares: null, gap_reason: 'not returned by API' }] },
    });
    const row = r.body.data.results[0];
    assert.equal(row.impressions, 900);
    assert.equal(row.shares, null, 'shares must stay null, not become 0');
    assert.notEqual(row.shares, 0);
  });

  test('a partial read cannot make a post look like it died', async () => {
    const post_uid = await livePost();
    const a6 = await openRun('A6', { date: nextDate() });
    const day = '2026-06-03';
    await api('POST', '/api/v1/agent/metrics:bulk-upsert', {
      agent: 'A6', body: { run_uid: a6, metric_date_ist: day, items: [{ post_uid, impressions: 812, shares: 30 }] },
    });
    const regressed = await api('POST', '/api/v1/agent/metrics:bulk-upsert', {
      agent: 'A6', body: { run_uid: a6, metric_date_ist: day, items: [{ post_uid, impressions: 40, shares: 2 }] },
    });
    assert.equal(regressed.body.data.results[0].impressions, 812, 'the higher value must survive');
    assert.ok(regressed.body.data.skipped_regression >= 1);
  });

  test('day-over-day movement is derived from consecutive snapshots', async () => {
    const post_uid = await livePost();
    const a6 = await openRun('A6', { date: nextDate() });
    await api('POST', '/api/v1/agent/metrics:bulk-upsert', {
      agent: 'A6', body: { run_uid: a6, metric_date_ist: '2026-07-01', items: [{ post_uid, impressions: 1000, shares: 10 }] },
    });
    const day2 = await api('POST', '/api/v1/agent/metrics:bulk-upsert', {
      agent: 'A6', body: { run_uid: a6, metric_date_ist: '2026-07-02', items: [{ post_uid, impressions: 1450, shares: 17 }] },
    });
    assert.equal(day2.body.data.results[0].delta_impressions, 450);
  });

  test('an unknown post uid is reported, not silently dropped', async () => {
    const a6 = await openRun('A6', { date: nextDate() });
    const r = await api('POST', '/api/v1/agent/metrics:bulk-upsert', {
      agent: 'A6', body: { run_uid: a6, items: [{ post_uid: uuid(), impressions: 5 }] },
    });
    assert.equal(r.body.data.unknown_post_uids.length, 1);
  });

  test('A6 holds no write scope on LinkedIn', async () => {
    const r = await api('GET', '/api/v1/agent/publish-queue/next', { agent: 'A6' });
    assert.equal(r.status, 403);
  });
});

describe('injected faults reach the agent as retryable', () => {
  test('a 503 is flagged retryable so the agent backs off rather than dead-letters', async () => {
    const r = await api('GET', '/api/v1/agent/config', { agent: 'A1', headers: { 'X-Mock-Fault': 'http_503' } });
    assert.equal(r.status, 503);
    assert.equal(r.body.error.retryable, true);
  });

  test('a 422 is flagged not retryable so the agent stops instead of grinding', async () => {
    const { batch_uid } = await seedBatch(3);
    const r = await api('POST', `/api/v1/agent/topic-batches/${batch_uid}/submit`, { agent: 'A1', body: {} });
    assert.equal(r.status, 422);
    assert.equal(r.body.error.retryable, false);
  });
});

describe('approval releases work, in one action', () => {
  // The live run caught this: a topic could be marked approved and still be
  // invisible to A2, because approval and the pipeline handover were separate
  // steps. In the real UI they are one click, so they must be one action here.
  test('approving a topic makes it visible to A2 immediately', async () => {
    const { batch_uid } = await seedBatch(30);
    await api('POST', `/api/v1/agent/topic-batches/${batch_uid}/submit`, { agent: 'A1', body: {} });

    const list = await api('GET', '/api/v1/agent/topics', { agent: 'A2' });
    const uid = (await api('GET', '/__mock/state', {})).body.data && null;
    // Approve one specific topic through the status route, not the bulk helper.
    const pending = await api('GET', `/api/v1/agent/topics`, { agent: 'A2' });
    const beforeCount = pending.body.data.length;

    const anyTopic = await api('POST', '/__mock/approve-topics', { body: { batch_uid, count: 1 } });
    const topicUid = anyTopic.body.data.topics[0].topic_uid;

    await api('POST', '/__mock/set-status', { body: { entity: 'topic', uid: topicUid, review_status: 'hold' } });
    const held = await api('GET', '/api/v1/agent/topics', { agent: 'A2' });
    assert.ok(!held.body.data.some((t) => t.topic_uid === topicUid), 'held topic is withdrawn from A2');

    await api('POST', '/__mock/set-status', { body: { entity: 'topic', uid: topicUid, review_status: 'approved' } });
    const back = await api('GET', '/api/v1/agent/topics', { agent: 'A2' });
    assert.ok(back.body.data.some((t) => t.topic_uid === topicUid),
      're-approving must hand the topic back to A2, not leave it approved and invisible');
    assert.equal(back.body.data.length, beforeCount + 1);
  });

  test('rejecting a topic takes it out of the pipeline for good', async () => {
    const { batch_uid } = await seedBatch(30);
    await api('POST', `/api/v1/agent/topic-batches/${batch_uid}/submit`, { agent: 'A1', body: {} });
    const appr = await api('POST', '/__mock/approve-topics', { body: { batch_uid, count: 1 } });
    const uid = appr.body.data.topics[0].topic_uid;

    await api('POST', '/__mock/set-status', { body: { entity: 'topic', uid, review_status: 'rejected' } });
    const list = await api('GET', '/api/v1/agent/topics', { agent: 'A2' });
    assert.ok(!list.body.data.some((t) => t.topic_uid === uid));

    const runUid = await openRun('A2', { date: nextDate() });
    const claim = await api('POST', `/api/v1/agent/topics/${uid}/claim`, { agent: 'A2', body: { run_uid: runUid } });
    assert.equal(claim.status, 409, 'a rejected topic can never be claimed');
  });
});
