#!/usr/bin/env node
/**
 * Deterministic gates for the pipeline.
 *
 * Everything countable is checked here, in code, never by a model: word count,
 * hook length, hashtag count, banned phrases, URLs in the body, and whether each
 * number in a post traces to its source. That is roughly 70% of every rubric,
 * made free, fast and non-negotiable. The checker sub-agents run this first and
 * only apply judgement to what is left: audience fit, specificity, tone.
 *
 * Usage:
 *   node lib/validate.mjs post  <file.json>   # or - for stdin
 *   node lib/validate.mjs topic <file.json>
 *   node lib/validate.mjs batch <file.json>
 *
 * Exit code 0 = every hard gate passed. 1 = at least one failed.
 */
import { readFileSync } from 'node:fs';
import {
  SLOP_VOCABULARY, SLOP_CONSTRUCTIONS, ENGAGEMENT_BAIT, UNSOURCED_AUTHORITY,
  DISCRIMINATION, COMPETITORS, CANDIDATE_FACING,
} from './bans.mjs';

export const LIMITS = {
  maxBodyWords: 100, minBodyWords: 40, maxHookChars: 125,
  hashtagsMin: 3, hashtagsMax: 5, maxEmDashes: 1, maxEmoji: 1,
  maxLineWords: 14, minBlocks: 3, maxSentencesPerBlock: 2,
};

const EMOJI = /[\u{1F300}-\u{1FAFF}\u{2600}-\u{27BF}\u{FE0F}]/gu;

/** Hashtag-only lines are excluded: the owner's cap covers the body alone. */
export function bodyWordCount(body) {
  return String(body || '')
    .split('\n').filter((l) => !/^\s*#\S/.test(l.trim())).join(' ')
    .trim().split(/\s+/).filter(Boolean).length;
}

const pass = (id, note) => ({ id, pass: true, note });
const fail = (id, evidence, hint) => ({ id, pass: false, evidence, hint });

/**
 * Every numeral in the body must trace to a verbatim quote from the source, or
 * be a year, or be a Golden Opportunities figure the owner has signed off.
 * A post with no numbers is fine. A post with an unverifiable number is not.
 */
function numericTrace(body, numbersUsed = []) {
  const numerals = String(body).match(/\b\d[\d,.]*%?\b/g) || [];
  if (numerals.length === 0) return [pass('NUMERIC_TRACE', 'no numerals in the body')];

  const traced = numbersUsed.map((n) => ({
    value: String(n.value ?? '').trim(),
    quote: String(n.source_quote ?? ''),
    url: n.source_url,
    verified: n.provenance === 'go_verified_fact',
  }));

  const results = [];
  for (const raw of numerals) {
    const token = raw.trim();
    if (/^(19|20)\d{2}$/.test(token)) { continue; }              // a year is not a claim
    const bare = token.replace(/[,%]/g, '');
    const hit = traced.find((t) => {
      const q = t.quote.replace(/,/g, '');
      return t.value.replace(/[,%]/g, '') === bare && (t.verified || q.includes(bare));
    });
    if (!hit) {
      results.push(fail('NUMERIC_TRACE', `"${token}" appears in the body but not in numbers_used with a matching source quote`,
        'either quote the source sentence containing this exact figure, or remove the number'));
    }
  }
  return results.length ? results : [pass('NUMERIC_TRACE', `${numerals.length} numeral(s) all traced`)];
}

function scanList(text, list, gateId, hintPrefix) {
  const hits = list.filter((e) => e.re.test(text));
  return hits.length
    ? hits.map((h) => fail(gateId, h.id, h.hint || `${hintPrefix}: ${h.id}`))
    : [pass(gateId)];
}

/**
 * Word-boundary phrase search without building regexes at runtime.
 * Normalises punctuation to spaces so "delve," and "delve." both match "delve".
 */
function containsPhrase(text, phrase) {
  const norm = (s) => String(s).toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim();
  const p = norm(phrase);
  const words = norm(text).split(' ');
  // Single-word bans also catch their inflections, so "unlock" flags
  // "unlocking" and "empower" flags "empowerment".
  if (!p.includes(' ')) return words.some((w) => w === p || (w.startsWith(p) && w.length - p.length <= 4));
  return (' ' + words.join(' ') + ' ').includes(' ' + p + ' ');
}

/** Runs every hard gate over one A2 post. */
export function checkPost(post) {
  const body = String(post.body ?? '');
  const hook = String(post.hook ?? body.split('\n')[0] ?? '');
  const tags = post.hashtags ?? [];
  const g = [];

  const wc = bodyWordCount(body);
  if (wc > LIMITS.maxBodyWords) {
    g.push(fail('WC100', `${wc} words`, 'cut a whole idea; never truncate mid-sentence to fit'));
  } else if (wc < LIMITS.minBodyWords) {
    g.push(fail('WC_FLOOR', `${wc} words`, 'under 40 words reads as a fragment rather than a point'));
  } else {
    g.push(pass('WC100', `${wc} words`));
  }

  g.push(hook.length > LIMITS.maxHookChars
    ? fail('HOOK125', `${hook.length} chars`,
        'the feed truncates around 140; keep the whole tension visible before "see more"')
    : pass('HOOK125', `${hook.length} chars`));

  g.push(!body.startsWith(hook)
    ? fail('HOOK_IS_FIRST_LINE', 'hook is not the opening of the body',
        'the hook must be the first line of the post, verbatim')
    : pass('HOOK_IS_FIRST_LINE'));

  g.push(tags.length < LIMITS.hashtagsMin || tags.length > LIMITS.hashtagsMax
    ? fail('HASH', `${tags.length} hashtags`, 'use 3 to 5: one broad, two or three niche, one branded')
    : pass('HASH', `${tags.length} hashtags`));

  const malformed = tags.filter((t) => !/^#[A-Za-z][A-Za-z0-9]*$/.test(t));
  g.push(malformed.length
    ? fail('HASH_FORM', malformed.join(', '), 'hashtags are single words, no spaces or punctuation')
    : pass('HASH_FORM'));

  // A link in the body suppresses reach. It belongs in the first comment, which
  // A5 posts within two minutes of publishing.
  const link = body.match(/https?:\/\/\S+|\bwww\.\S+/i);
  g.push(link ? fail('NOLINK', link[0], 'move the URL to first_comment_text') : pass('NOLINK'));

  g.push(...numericTrace(body, post.numbers_used ?? []));

  const slopWords = SLOP_VOCABULARY.filter((w) => containsPhrase(body, w));
  g.push(slopWords.length
    ? fail('SLOP_VOCAB', slopWords.join(', '), 'say the plain thing instead')
    : pass('SLOP_VOCAB'));

  g.push(...scanList(body, SLOP_CONSTRUCTIONS, 'SLOP_SHAPE', 'AI sentence shape'));
  g.push(...scanList(body, ENGAGEMENT_BAIT, 'NOBAIT', 'engagement bait'));
  g.push(...scanList(body, UNSOURCED_AUTHORITY, 'NO_UNSOURCED', 'unsourced authority claim'));
  g.push(...scanList(body, CANDIDATE_FACING, 'EMPLOYER_AUDIENCE', 'candidate-facing language'));

  const discrimFails = DISCRIMINATION.filter((d) => d.severity === 'fail' && d.re.test(body));
  const discrimFlags = DISCRIMINATION.filter((d) => d.severity === 'review' && d.re.test(body));
  g.push(discrimFails.length
    ? fail('NODISCRIM', discrimFails.map((d) => `${d.id} (${d.why})`).join('; '),
        'rewrite without reference to any protected characteristic')
    : pass('NODISCRIM', discrimFlags.length
        ? `flagged for owner review: ${discrimFlags.map((d) => d.id).join(', ')}` : undefined));

  const comp = COMPETITORS.filter((c) => containsPhrase(body, c));
  g.push(comp.length
    ? fail('NO_COMPETITOR', comp.join(', '), 'never name a staffing competitor, favourably or otherwise')
    : pass('NO_COMPETITOR'));

  const dashes = (body.match(/\u2014/g) || []).length;
  g.push(dashes > LIMITS.maxEmDashes
    ? fail('EM_DASH', `${dashes} em dashes`, 'at most one; a full stop usually reads better')
    : pass('EM_DASH'));

  const emoji = (body.match(EMOJI) || []).length;
  g.push(emoji > LIMITS.maxEmoji
    ? fail('EMOJI', `${emoji} emoji`, 'at most one, and never as a bullet')
    : pass('EMOJI'));

  // Mobile formatting. A five-line paragraph is a grey brick and the thumb moves on.
  const blocks = body.split(/\n\s*\n/).map((b) => b.trim()).filter(Boolean);
  g.push(blocks.length < LIMITS.minBlocks
    ? fail('FORMAT_BLOCKS', `${blocks.length} blocks`,
        'break into at least 3 short blocks with a blank line between them')
    : pass('FORMAT_BLOCKS', `${blocks.length} blocks`));

  const longLine = body.split('\n')
    .find((l) => !/^\s*#\S/.test(l) && l.trim().split(/\s+/).filter(Boolean).length > LIMITS.maxLineWords);
  g.push(longLine
    ? fail('FORMAT_LINE', longLine.trim().slice(0, 60) + '...',
        'keep lines under 14 words so they do not wrap three times on a phone')
    : pass('FORMAT_LINE'));

  const ALLOWED_CAPS = ['GOJOBS', 'CHRO', 'BFSI', 'EPFO', 'NASSCOM', 'POSH', 'CTC', 'HRBP'];
  const shouty = (body.match(/\b[A-Z]{4,}\b/g) || []).filter((w) => !ALLOWED_CAPS.includes(w));
  g.push(shouty.length ? fail('NO_SHOUTING', shouty.join(', '), 'lower case it') : pass('NO_SHOUTING'));

  return g;
}

/** Hard gates for one A1 topic. A failing topic is replaced, not revised. */
export function checkTopic(topic) {
  const g = [];
  const title = String(topic.title ?? '');
  const oneLiner = String(topic.one_liner ?? '');

  g.push(title.length > 0 && title.length <= 300 ? pass('TITLE') : fail('TITLE', `${title.length} chars`, 'title is required, 300 chars max'));
  g.push(title.split(/\s+/).filter(Boolean).length <= 14
    ? pass('TITLE_LEN')
    : fail('TITLE_LEN', `${title.split(/\s+/).length} words`, 'name one specific problem in 14 words or fewer'));
  g.push(oneLiner.length > 0 && oneLiner.length <= 500
    ? pass('ONE_LINER') : fail('ONE_LINER', `${oneLiner.length} chars`, 'one_liner is required, 500 chars max'));

  g.push(/^https?:\/\//i.test(topic.source_url ?? '')
    ? pass('SOURCE_URL') : fail('SOURCE_URL', topic.source_url ?? 'missing', 'every topic needs a resolvable source URL'));

  // The quote is what every statistic in the eventual post will be traced to.
  g.push(topic.source_quote && String(topic.source_quote).trim().length >= 20
    ? pass('SOURCE_QUOTE')
    : fail('SOURCE_QUOTE', topic.source_quote ?? 'missing',
        'capture the sentence from the source that anchors the claim, verbatim'));

  if (topic.source_published_on) {
    const months = (Date.now() - Date.parse(topic.source_published_on)) / (86400000 * 30.4);
    g.push(months <= 18
      ? pass('FRESH', `${months.toFixed(1)} months old`)
      : fail('FRESH', `${months.toFixed(1)} months old`, 'source is over 18 months old; find something current'));
  } else {
    g.push(fail('FRESH', 'no publication date', 'record source_published_on so staleness can be checked'));
  }

  const relevance = Number(topic.india_relevance ?? 0);
  g.push(relevance >= 1
    ? pass('INDIA_RELEVANCE', `level ${relevance}`)
    : fail('INDIA_RELEVANCE', `level ${relevance}`,
        'a UK or US topic only qualifies if the one-liner reframes it for Indian conditions'));

  const text = `${title} ${oneLiner}`;
  g.push(...scanList(text, CANDIDATE_FACING, 'EMPLOYER_AUDIENCE', 'candidate-facing language'));
  const discrim = DISCRIMINATION.filter((d) => d.severity === 'fail' && d.re.test(text));
  g.push(discrim.length
    ? fail('NODISCRIM', discrim.map((d) => d.id).join('; '), 'drop this topic entirely')
    : pass('NODISCRIM'));

  return g;
}

/** Batch-level gates: variety, so 30 posts do not read like one post repeated. */
export function checkBatch(topics) {
  const g = [];
  const n = topics.length;
  g.push(n >= 30 && n <= 60 ? pass('BATCH_SIZE', `${n} topics`) : fail('BATCH_SIZE', `${n} topics`, 'the batch must hold 30 to 60 topics'));

  const themes = new Set(topics.map((t) => t.theme_tag));
  g.push(themes.size >= 6
    ? pass('THEME_SPREAD', `${themes.size} themes`)
    : fail('THEME_SPREAD', `${themes.size} themes`, 'at least 6 distinct themes, or the feed becomes a hobby horse'));

  const types = {};
  for (const t of topics) types[t.post_type] = (types[t.post_type] ?? 0) + 1;
  const overweight = Object.entries(types).filter(([, c]) => c / n > 0.30);
  g.push(overweight.length
    ? fail('TYPE_MIX', overweight.map(([k, c]) => `${k}=${Math.round((c / n) * 100)}%`).join(', '),
        'no single post type above 30% of a batch')
    : pass('TYPE_MIX', `${Object.keys(types).length} types`));

  const strongIndia = topics.filter((t) => Number(t.india_relevance) === 2).length;
  g.push(strongIndia / n >= 0.5
    ? pass('INDIA_MIX', `${Math.round((strongIndia / n) * 100)}% strongly India-relevant`)
    : fail('INDIA_MIX', `${Math.round((strongIndia / n) * 100)}%`,
        'at least half the batch must be strongly relevant to an Indian HR buyer'));

  const titles = new Set(topics.map((t) => String(t.title).toLowerCase().replace(/[^a-z0-9 ]/g, '').trim()));
  g.push(titles.size === n
    ? pass('NO_DUPES')
    : fail('NO_DUPES', `${n - titles.size} duplicate titles`, 'dedupe before uploading'));

  return g;
}

export const summarise = (gates) => ({
  verdict: gates.every((x) => x.pass) ? 'pass' : 'fail',
  failed: gates.filter((x) => !x.pass).map((x) => ({ gate: x.id, evidence: x.evidence, fix: x.hint })),
  passed: gates.filter((x) => x.pass).length,
  total: gates.length,
});

// --- CLI -------------------------------------------------------------------
const isMain = import.meta.filename === process.argv[1];
if (isMain) {
  const [kind, file] = process.argv.slice(2);
  if (!kind || !file) {
    console.error('usage: node lib/validate.mjs <post|topic|batch> <file.json|->');
    process.exit(2);
  }
  const raw = file === '-' ? readFileSync(0, 'utf8') : readFileSync(file, 'utf8');
  const data = JSON.parse(raw);
  const gates = kind === 'post' ? checkPost(data)
    : kind === 'topic' ? checkTopic(data)
    : checkBatch(Array.isArray(data) ? data : data.topics);
  const result = summarise(gates);
  console.log(JSON.stringify(result, null, 2));
  process.exit(result.verdict === 'pass' ? 0 : 1);
}
