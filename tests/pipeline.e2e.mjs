#!/usr/bin/env node
/**
 * One topic, all the way from research to a published post and its first day of
 * analytics, against a fresh mock. Prints a readable trace rather than test
 * output, so you can watch each gate do its job.
 *
 *   npm run e2e
 *
 * This is deterministic and makes no external calls. The live run - real web
 * research and real Higgsfield images - is a separate exercise.
 */
import { startServer, stopServer, api, openRun, uuid, sha256, istDate, PNG_1PX } from './helpers.mjs';

const step = (n, title) => console.log(`\n${'-'.repeat(72)}\n${n}. ${title}\n${'-'.repeat(72)}`);
const ok = (msg) => console.log(`   ok    ${msg}`);
const info = (msg) => console.log(`   .     ${msg}`);
const bad = (msg) => { console.error(`   FAIL  ${msg}`); process.exitCode = 1; };
const expect = (cond, msg) => (cond ? ok(msg) : bad(msg));

const TOPIC = {
  title: 'Long notice periods are costing you your best senior hires',
  one_liner: 'Senior finalists in BFSI and GCC roles hold two or three live offers, so a long serving window is time for a faster competitor to close them.',
  source_url: 'https://www.ere.net/articles/example',
  source_domain: 'ere.net',
  source_quote: 'Median time to accept has fallen sharply over the past two years.',
  source_published_on: '2026-06-01',
  post_type: 'contrarian_take',
  theme_tag: 'notice_period',
  india_relevance: 2,
  cta_flag: true,
  cta_type: 'email',
  cta_text: 'Ask us for the sector benchmark',
  cta_target: 'rc@gojobs.biz',
};

const POST_BODY = [
  'Your best finalist accepted somewhere else. In the fifth week of notice.',
  '',
  'For senior BFSI mandates, the person you picked is holding two other offers.',
  '',
  'A long notice window is not a formality.',
  'It is time for a faster competitor to close them.',
  '',
  'What works: a shorter structured process.',
  'Buyback risk raised in round two.',
  'A named onboarding contact from day one.',
  '',
  'Where does your offer-to-join gap actually open up?',
].join('\n');

await startServer();
try {
  // ---------------------------------------------------------------- A1
  step(1, 'A1 researches topics and uploads a batch');
  const a1 = await openRun('A1', { date: '2026-04-01' });
  ok(`run opened`);

  const depth = await api('GET', '/api/v1/agent/queue-depth', { agent: 'A1' });
  expect(depth.body.data.should_skip === false, `queue has room (${depth.body.data.approved_posts_waiting}/${depth.body.data.ceiling})`);

  const batch_uid = uuid();
  await api('POST', '/api/v1/agent/topic-batches', {
    agent: 'A1', body: { batch_uid, run_uid: a1, business_date_ist: '2026-04-01', title: 'E2E batch' },
  });

  const topics = [{ topic_uid: uuid(), ...TOPIC }];
  for (let i = 1; i < 30; i++) {
    topics.push({
      ...TOPIC, topic_uid: uuid(),
      title: `Filler topic number ${i} for the batch minimum`,
      post_type: ['data_point', 'myth_bust', 'framework', 'client_problem', 'seasonal_compliance', 'contrarian_take'][i % 6],
      theme_tag: ['attrition', 'gcc_hiring', 'compliance', 'tier2_hiring', 'time_to_hire', 'cost_per_hire'][i % 6],
      cta_flag: false, cta_type: 'none', cta_text: null, cta_target: null,
    });
  }
  const added = await api('POST', `/api/v1/agent/topic-batches/${batch_uid}/topics`, {
    agent: 'A1', body: { run_uid: a1, topics },
  });
  expect(added.body.data.accepted === 30, `${added.body.data.accepted} topics accepted`);

  const submitted = await api('POST', `/api/v1/agent/topic-batches/${batch_uid}/submit`, { agent: 'A1', body: {} });
  expect(submitted.body.data.state === 'submitted', 'batch submitted and now visible to the owner');
  await api('PATCH', `/api/v1/agent/runs/${a1}`, { agent: 'A1', body: { status: 'succeeded', items_in: 30, items_ok: 30 } });

  // ---------------------------------------------------------------- gate 1
  step(2, 'GATE 1 - nothing moves until the owner approves');
  const beforeGate = await api('GET', '/api/v1/agent/topics', { agent: 'A2' });
  expect(beforeGate.body.data.length === 0, 'A2 sees nothing while the batch is unapproved');

  const approved = await api('POST', '/__mock/approve-topics', { body: { batch_uid, count: 1, edit_first_title: true } });
  const topic_uid = approved.body.data.topics[0].topic_uid;
  ok(`owner approved 1 topic, and edited its title`);

  const afterGate = await api('GET', '/api/v1/agent/topics', { agent: 'A2' });
  const handed = afterGate.body.data.find((t) => t.topic_uid === topic_uid);
  expect(handed.title.endsWith('(edited by owner)'), 'A2 is handed the owner version');
  expect(handed.provenance.original_title === TOPIC.title, "A1's original is preserved untouched");

  // ---------------------------------------------------------------- A2
  step(3, 'A2 writes the post');
  const a2 = await openRun('A2', { date: '2026-04-02' });
  await api('POST', `/api/v1/agent/topics/${topic_uid}/claim`, { agent: 'A2', body: { run_uid: a2 } });
  ok('topic claimed on a 30-minute lease');

  const overLong = await api('POST', '/api/v1/agent/posts', {
    agent: 'A2',
    body: { post_uid: uuid(), topic_uid, run_uid: a2, hook: 'x', body: 'word '.repeat(120).trim(),
            hashtags: ['#A', '#B', '#C'] },
  });
  expect(overLong.status === 422 && overLong.body.error.code === 'WORD_COUNT_EXCEEDED',
    'a 120-word draft is refused rather than truncated');

  const linked = await api('POST', '/api/v1/agent/posts', {
    agent: 'A2',
    body: { post_uid: uuid(), topic_uid, run_uid: a2, hook: 'x',
            body: 'A short post that links to https://www.GOjobs.biz in the body.',
            hashtags: ['#A', '#B', '#C'] },
  });
  expect(linked.status === 422 && linked.body.error.code === 'LINK_IN_BODY',
    'a URL in the body is refused, because it suppresses reach');

  const post_uid = uuid();
  const created = await api('POST', '/api/v1/agent/posts', {
    agent: 'A2',
    body: {
      post_uid, topic_uid, run_uid: a2,
      hook: POST_BODY.split('\n')[0],
      body: POST_BODY,
      hashtags: ['#TalentAcquisition', '#HRLeadership', '#HiringIndia', '#BFSI'],
      keywords: ['notice period', 'offer decline', 'time to hire'],
      cta_text: TOPIC.cta_text, cta_target: TOPIC.cta_target,
      first_comment_text: 'Sector benchmarks on request: rc@gojobs.biz',
      numbers_used: [],
      image_brief: { subject: 'An HR leader reviewing a hiring pipeline', mood: 'considered under time pressure' },
    },
  });
  expect(created.status === 201, `post created, ${created.body.data.word_count} body words`);
  await api('PATCH', `/api/v1/agent/runs/${a2}`, { agent: 'A2', body: { status: 'succeeded', items_in: 1, items_ok: 1 } });

  // ---------------------------------------------------------------- A3
  step(4, 'A3 generates two image options');
  const a3 = await openRun('A3', { date: '2026-04-03' });

  const corrupt = await api('POST', `/api/v1/agent/posts/${post_uid}/images`, {
    agent: 'A3',
    body: { image_uid: uuid(), option_index: 1, run_uid: a3, prompt_text: 'x',
            data_base64: PNG_1PX.toString('base64'), sha256: 'a'.repeat(64), mime_type: 'image/png' },
  });
  expect(corrupt.status === 422 && corrupt.body.error.code === 'SHA256_MISMATCH',
    'a corrupt download is caught at the boundary, not in the review screen');

  for (const [idx, model, concept] of [[1, 'recraft_v4_1', 'boardroom pipeline review'], [2, 'soul_cinematic', 'abstract hiring funnel']]) {
    const r = await api('POST', `/api/v1/agent/posts/${post_uid}/images`, {
      agent: 'A3',
      body: { image_uid: uuid(), option_index: idx, run_uid: a3, provider_model: model, concept_label: concept,
              prompt_text: `${concept}, editorial, no text`, negative_prompt: 'text, logo, watermark',
              data_base64: PNG_1PX.toString('base64'), sha256: sha256(PNG_1PX), mime_type: 'image/png',
              alt_text: concept, ocr_text: '' },
    });
    expect(r.status === 201, `option ${idx} stored (${model})`);
  }
  await api('PATCH', `/api/v1/agent/runs/${a3}`, { agent: 'A3', body: { status: 'succeeded', items_in: 1, items_ok: 1 } });

  // ---------------------------------------------------------------- A4
  step(5, 'A4 packages it for review');
  const a4 = await openRun('A4', { date: '2026-04-04' });
  const pkg = await api('POST', `/api/v1/agent/posts/${post_uid}/submit-for-review`, { agent: 'A4', body: { run_uid: a4 } });
  expect(pkg.body.data.image_count === 2, 'both images attached');
  expect(pkg.body.data.degraded_flags.length === 0, 'no degraded flags');
  ok(`package expires ${pkg.body.data.expires_at_ist}, so it cannot go out stale`);
  await api('PATCH', `/api/v1/agent/runs/${a4}`, { agent: 'A4', body: { status: 'succeeded', items_in: 1, items_ok: 1 } });

  // ---------------------------------------------------------------- gate 2
  step(6, 'GATE 2 - nothing publishes until the owner approves again');
  const queueBefore = await api('GET', '/api/v1/agent/publish-queue/next', { agent: 'A5' });
  expect(queueBefore.status === 204, 'an unapproved package is not publishable');

  const gate2 = await api('POST', '/__mock/approve-post', {
    body: { post_uid, select_image: 2, replace_hashtag: '#GCCHiring', scheduled_date_ist: istDate() },
  });
  ok(`owner picked image ${gate2.body.data.selected_option}, swapped a hashtag, dated it ${gate2.body.data.scheduled_date_ist}`);

  // ---------------------------------------------------------------- A5
  step(7, 'A5 publishes, in dry run');
  const a5 = await openRun('A5', { date: '2026-04-05', dryRun: true });
  const due = await api('GET', '/api/v1/agent/publish-queue/next', { agent: 'A5' });
  expect(due.body.data.post_uid === post_uid, `queue offers it, reason: ${due.body.data.reason}`);

  const lease = await api('POST', `/api/v1/agent/posts/${post_uid}/publish-lease`, { agent: 'A5', body: { run_uid: a5 } });
  expect(lease.status === 200, 'lease taken, every precondition re-checked');
  info(`commentary hash ${lease.body.data.commentary_sha256.slice(0, 16)}...`);
  expect(/#GCCHiring/.test(lease.body.data.commentary), "the owner's hashtag edit is in the payload");
  expect(!/https?:\/\//.test(lease.body.data.commentary.replace(/#\w+/g, '')), 'the body carries no URL');
  expect(Boolean(lease.body.data.first_comment_text), 'the link is in the first comment instead');

  const dry = await api('POST', `/api/v1/agent/posts/${post_uid}/publish-result`, {
    agent: 'A5', body: { run_uid: a5, lease_token: lease.body.data.lease_token, outcome: 'dry_run' },
  });
  expect(dry.body.data.dry_run === true && dry.body.data.lifecycle_state === 'ready',
    'dry run built the payload and published nothing');
  await api('PATCH', `/api/v1/agent/runs/${a5}`, {
    agent: 'A5', body: { status: 'succeeded', items_in: 1, items_ok: 1, skip_reason: 'dry_run' },
  });

  step(8, 'A5 publishes for real');
  const a5b = await openRun('A5', { date: '2026-04-06' });
  const lease2 = await api('POST', `/api/v1/agent/posts/${post_uid}/publish-lease`, { agent: 'A5', body: { run_uid: a5b } });
  const urn = `urn:li:share:${Date.now()}`;
  const live = await api('POST', `/api/v1/agent/posts/${post_uid}/publish-result`, {
    agent: 'A5',
    body: { run_uid: a5b, lease_token: lease2.body.data.lease_token, outcome: 'success',
            linkedin_urn: urn, linkedin_permalink: `https://www.linkedin.com/feed/update/${urn}/`,
            first_comment_urn: `urn:li:comment:${Date.now()}` },
  });
  expect(live.body.data.lifecycle_state === 'posted', `posted, tracked until ${live.body.data.metrics_watch_until_ist}`);

  const second = await api('POST', `/api/v1/agent/posts/${post_uid}/publish-lease`, { agent: 'A5', body: { run_uid: a5b } });
  expect(second.status === 409 && second.body.error.code === 'ALREADY_POSTED', 'it cannot be published twice');

  const quiet = await api('GET', '/api/v1/agent/publish-queue/next', { agent: 'A5' });
  expect(quiet.status === 204, 'the queue goes quiet for the rest of the day');
  await api('PATCH', `/api/v1/agent/runs/${a5b}`, { agent: 'A5', body: { status: 'succeeded', items_in: 1, items_ok: 1 } });

  // ---------------------------------------------------------------- A6
  step(9, 'A6 collects the numbers');
  const a6 = await openRun('A6', { date: '2026-04-07' });
  const published = await api('GET', '/api/v1/agent/posts/published', { agent: 'A6' });
  expect(published.body.data.some((p) => p.linkedin_urn === urn), 'the post is findable by the URN A5 recorded');

  const d1 = await api('POST', '/api/v1/agent/metrics:bulk-upsert', {
    agent: 'A6', body: { run_uid: a6, metric_date_ist: '2026-04-08',
                         items: [{ post_uid, impressions: 1842, shares: 21, reactions: 64, comments: 5, clicks: 37 }] },
  });
  expect(d1.body.data.inserted === 1, 'day one recorded');

  const d2 = await api('POST', '/api/v1/agent/metrics:bulk-upsert', {
    agent: 'A6', body: { run_uid: a6, metric_date_ist: '2026-04-09',
                         items: [{ post_uid, impressions: 2610, shares: 33 }] },
  });
  expect(d2.body.data.results[0].delta_impressions === 768, 'day two movement derived from the two snapshots');

  const rerun = await api('POST', '/api/v1/agent/metrics:bulk-upsert', {
    agent: 'A6', body: { run_uid: a6, metric_date_ist: '2026-04-09',
                         items: [{ post_uid, impressions: 40, shares: 2 }] },
  });
  expect(rerun.body.data.inserted === 0 && rerun.body.data.updated === 1, 're-running the same day updates one row');
  expect(rerun.body.data.results[0].impressions === 2610, 'a partial read cannot make the post look like it died');

  const gap = await api('POST', '/api/v1/agent/metrics:bulk-upsert', {
    agent: 'A6', body: { run_uid: a6, metric_date_ist: '2026-04-10',
                         items: [{ post_uid, impressions: 3100, shares: null, gap_reason: 'not returned by API' }] },
  });
  expect(gap.body.data.results[0].shares === null, 'a metric the API did not return stays unknown, never zero');
  await api('PATCH', `/api/v1/agent/runs/${a6}`, { agent: 'A6', body: { status: 'succeeded', items_in: 1, items_ok: 1 } });

  // ---------------------------------------------------------------- summary
  step(10, 'Final state');
  const state = await api('GET', '/__mock/state', {});
  console.log('   runs:');
  for (const r of state.body.data.runs) {
    console.log(`     ${r.agent_code}  ${r.status.padEnd(10)} in=${r.items_in} ok=${r.items_ok}${r.skip_reason ? ` (${r.skip_reason})` : ''}`);
  }
  console.log('   posts:', JSON.stringify(state.body.data.post_counts));
  console.log('   images:', state.body.data.images, '| metric rows:', state.body.data.metrics_rows);
  console.log('   dead letters:', state.body.data.dead_letters.length);

  console.log(process.exitCode ? '\nE2E FAILED\n' : '\nE2E passed: research to published post to first analytics.\n');
} finally {
  await stopServer();
}
