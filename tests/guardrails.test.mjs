/**
 * The tests that matter most.
 *
 * Everything else in this suite protects the plumbing. These protect the brand
 * and, in a recruitment context, the company's legal position. If one of these
 * ever goes red, the pipeline must not run.
 */
import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { checkPost, checkTopic, checkBatch, summarise, bodyWordCount } from '../lib/validate.mjs';

const gate = (gates, id) => gates.filter((g) => g.id === id);
const failed = (gates, id) => gate(gates, id).some((g) => !g.pass);

/** A clean post that every gate should accept. */
const CLEAN = {
  hook: 'Your best finalist accepted somewhere else. In the fifth week of notice.',
  body: [
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
  ].join('\n'),
  hashtags: ['#TalentAcquisition', '#HRLeadership', '#HiringIndia', '#BFSI'],
  numbers_used: [],
};

const withBody = (body, over = {}) => ({ ...CLEAN, hook: body.split('\n')[0], body, ...over });

describe('a clean post passes', () => {
  test('every hard gate accepts it', () => {
    const r = summarise(checkPost(CLEAN));
    assert.equal(r.verdict, 'pass', JSON.stringify(r.failed, null, 2));
  });
});

describe('discrimination is refused, not softened', () => {
  const cases = [
    ['age dressed as culture', 'We help you build a young, energetic team that scales fast across every region you operate in today.'],
    ['an explicit age bar', 'Most mandates we see still ask for candidates aged below 30, and that is the first thing we push back on hard.'],
    ['freshers only', 'Plants that insist on freshers only are competing for the same shrinking pool every single hiring season now.'],
    ['career-gap screening', 'Screens that demand no career gaps quietly remove half your senior shortlist before anyone reads a CV properly.'],
    ['gender preference', 'Briefs that specify male candidates for plant roles shrink the pool and rarely survive a compliance review later.'],
    ['marital status', 'Asking for unmarried candidates is still common in some briefs, and it is the fastest way to lose good people.'],
    ['regional proxy', 'Insisting on local candidates only is usually a community preference wearing a geography costume for the brief.'],
  ];
  for (const [label, body] of cases) {
    test(`refuses ${label}`, () => {
      assert.ok(failed(checkPost(withBody(body)), 'NODISCRIM'),
        `NODISCRIM should have failed for: ${label}`);
    });
  }

  test('the refusal explains why, so the maker can actually fix it', () => {
    const g = checkPost(withBody('We build a young, energetic team for every client we take on across the country right now.'));
    const f = gate(g, 'NODISCRIM').find((x) => !x.pass);
    assert.match(f.evidence, /age preference/);
    assert.ok(f.hint.length > 10);
  });

  test('a topic carrying discrimination is dropped before any copy is written', () => {
    const g = checkTopic({
      title: 'Why freshers only policies work', one_liner: 'x'.repeat(40),
      source_url: 'https://example.com/a', source_quote: 'A sentence long enough to count as a quote.',
      source_published_on: new Date().toISOString().slice(0, 10), india_relevance: 2,
    });
    assert.ok(failed(g, 'NODISCRIM'));
  });
});

describe('numbers must trace to their source', () => {
  test('an untraced statistic is refused', () => {
    const body = withBody([
      'Offer declines are climbing across senior BFSI hiring this year.',
      '',
      'Around 73% of enterprises now report a finalist dropping out after signing.',
      '',
      'That number moves your whole quarter, not just one requisition on the board.',
      '',
      'Plan for it before the offer goes out, not after it is declined.',
    ].join('\n'));
    assert.ok(failed(checkPost(body), 'NUMERIC_TRACE'));
  });

  test('the same statistic passes once it is quoted from the source', () => {
    const body = [
      'Offer declines are climbing across senior BFSI hiring this year.',
      '',
      'Around 73% of enterprises now report a finalist dropping out after signing.',
      '',
      'That number moves your whole quarter, not just one requisition on the board.',
      '',
      'Plan for it before the offer goes out, not after it is declined.',
    ].join('\n');
    const g = checkPost(withBody(body, {
      numbers_used: [{
        value: '73%',
        source_quote: 'In our 2026 survey, 73% of enterprises reported at least one finalist dropping out after signing.',
        source_url: 'https://www.ere.net/example',
      }],
    }));
    assert.ok(!failed(g, 'NUMERIC_TRACE'), JSON.stringify(summarise(g).failed));
  });

  test('a year is not treated as a claim needing a citation', () => {
    const body = withBody([
      'The 2026 appraisal cycle lands right on top of your notice-period backlog.',
      '',
      'Most senior offers you make this quarter will land after the cycle closes.',
      '',
      'That is a sequencing problem, not a compensation problem, and it is fixable.',
      '',
      'Move the leadership mandates ahead of the cycle, not after it.',
    ].join('\n'));
    assert.ok(!failed(checkPost(body), 'NUMERIC_TRACE'));
  });

  test('unsourced authority phrasing is refused even with no numbers', () => {
    const body = withBody([
      'Studies show that structured interviews beat unstructured ones every time.',
      '',
      'The mechanism is simple: the same questions make candidates comparable.',
      '',
      'Without that, your panel is comparing impressions rather than evidence.',
      '',
      'Fix the scorecard before you widen the funnel any further.',
    ].join('\n'));
    assert.ok(failed(checkPost(body), 'NO_UNSOURCED'));
  });
});

describe('the post is written for the buyer, not the candidate', () => {
  const cases = [
    ['apply now', 'Apply now if you want to work with a plant team that actually plans its hiring ahead of the quarter.'],
    ['we are hiring', 'We are hiring across three plant locations and the pipeline is moving faster than last year already.'],
    ['job seekers', 'Job seekers in tier-two cities are far more willing to relocate than most enterprise briefs assume today.'],
    ['send your CV', 'Send us your CV if this sounds like the kind of manufacturing mandate you have run before now.'],
  ];
  for (const [label, body] of cases) {
    test(`refuses "${label}"`, () => {
      assert.ok(failed(checkPost(withBody(body)), 'EMPLOYER_AUDIENCE'));
    });
  }
});

describe('it must not read as machine-written', () => {
  test('the "it is not X, it is Y" construction is refused', () => {
    const body = withBody([
      'Attrition in your first ninety days is not a pay problem, it is an onboarding problem.',
      '',
      'The signal shows up long before the resignation letter does.',
      '',
      'Watch the first two weeks, not the exit interview.',
      '',
      'That is where the cost actually sits.',
    ].join('\n'));
    assert.ok(failed(checkPost(body), 'SLOP_SHAPE'));
  });

  test('slop vocabulary is refused, including inflections', () => {
    const g = checkPost(withBody([
      'Unlocking seamless hiring is a robust way to elevate your talent landscape.',
      '',
      'That sentence means nothing, which is exactly the problem here.',
      '',
      'Say the specific thing your reader can act on this quarter instead.',
      '',
      'Otherwise the post is just noise in a busy feed.',
    ].join('\n')));
    const f = gate(g, 'SLOP_VOCAB').find((x) => !x.pass);
    assert.ok(f, 'slop vocabulary should have been caught');
    for (const w of ['unlock', 'seamless', 'robust', 'elevate', 'landscape']) {
      assert.match(f.evidence, new RegExp(w));
    }
  });

  test('engagement bait is refused', () => {
    for (const bait of ['Thoughts?', 'Agree?', 'Comment below.', 'Tag someone who needs this.']) {
      const body = withBody([
        'Notice periods are the quiet tax on every senior mandate you run this year.',
        '',
        'The cost lands twice: once at offer stage and once again at joining.',
        '',
        'Shorten the process before you widen the funnel any further.',
        '',
        bait,
      ].join('\n'));
      assert.ok(failed(checkPost(body), 'NOBAIT'), `should refuse: ${bait}`);
    }
  });

  test('a named competitor is refused', () => {
    const body = withBody([
      'Most enterprises still run senior mandates through Naukri and hope for the best.',
      '',
      'Volume platforms are built for a different problem than leadership hiring.',
      '',
      'The shortlist you need does not answer job adverts at all.',
      '',
      'That is a sourcing decision, not a budget decision.',
    ].join('\n'));
    assert.ok(failed(checkPost(body), 'NO_COMPETITOR'));
  });
});

describe('the mechanical limits', () => {
  test('hashtag-only lines are excluded from the 100 words', () => {
    const body = `${'word '.repeat(100).trim()}\n\n#TalentAcquisition #HRLeadership`;
    assert.equal(bodyWordCount(body), 100);
  });

  test('101 words is refused', () => {
    assert.ok(failed(checkPost(withBody('word '.repeat(101).trim())), 'WC100'));
  });

  test('a hook that would be cut off by "see more" is refused', () => {
    const longHook = 'This opening line runs well past the point where the LinkedIn feed stops showing it and hides the interesting half behind see more entirely.';
    assert.ok(failed(checkPost(withBody(`${longHook}\n\nsecond block here now\n\nthird block here now`)), 'HOOK125'));
  });

  test('a URL in the body is refused', () => {
    const body = withBody([
      'Notice periods are the quiet tax on every senior mandate you run this year.',
      '',
      'Full sector benchmarks are at https://www.GOjobs.biz for anyone who wants them.',
      '',
      'The cost lands twice, at offer and again at joining time.',
      '',
      'Shorten the process before widening the funnel.',
    ].join('\n'));
    assert.ok(failed(checkPost(body), 'NOLINK'));
  });

  test('a wall of text is refused', () => {
    const wall = Array.from({ length: 6 }, () => 'This is one more long sentence in a single unbroken block of text.').join(' ');
    assert.ok(failed(checkPost(withBody(wall)), 'FORMAT_BLOCKS'));
  });
});

describe('batch variety', () => {
  const topic = (over) => ({
    title: 'A topic title', one_liner: 'x'.repeat(40), post_type: 'data_point',
    theme_tag: 'attrition', india_relevance: 2, ...over,
  });

  test('a batch that is all one post type is refused', () => {
    const topics = Array.from({ length: 30 }, (_, i) => topic({ title: `Topic ${i}` }));
    assert.ok(failed(checkBatch(topics), 'TYPE_MIX'));
  });

  test('a batch on one theme is refused', () => {
    const types = ['data_point', 'contrarian_take', 'myth_bust', 'framework', 'client_problem', 'seasonal_compliance'];
    const topics = Array.from({ length: 30 }, (_, i) => topic({ title: `Topic ${i}`, post_type: types[i % 6] }));
    assert.ok(failed(checkBatch(topics), 'THEME_SPREAD'));
  });

  test('a batch of UK and US material is refused for an Indian audience', () => {
    const types = ['data_point', 'contrarian_take', 'myth_bust', 'framework', 'client_problem', 'seasonal_compliance'];
    const themes = ['attrition', 'gcc_hiring', 'notice_period', 'compliance', 'tier2_hiring', 'time_to_hire'];
    const topics = Array.from({ length: 30 }, (_, i) =>
      topic({ title: `Topic ${i}`, post_type: types[i % 6], theme_tag: themes[i % 6], india_relevance: 1 }));
    assert.ok(failed(checkBatch(topics), 'INDIA_MIX'));
  });

  test('a varied, India-weighted batch passes', () => {
    const types = ['data_point', 'contrarian_take', 'myth_bust', 'framework', 'client_problem', 'seasonal_compliance'];
    const themes = ['attrition', 'gcc_hiring', 'notice_period', 'compliance', 'tier2_hiring', 'time_to_hire'];
    const topics = Array.from({ length: 36 }, (_, i) =>
      topic({ title: `Topic number ${i}`, post_type: types[i % 6], theme_tag: themes[i % 6], india_relevance: 2 }));
    const r = summarise(checkBatch(topics));
    assert.equal(r.verdict, 'pass', JSON.stringify(r.failed));
  });
});
