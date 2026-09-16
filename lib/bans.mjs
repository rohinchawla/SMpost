/**
 * Ban lists, kept in one place so A2, A4 and A5 all check the same thing and so
 * they can be reviewed quarterly without hunting through agent prompts.
 *
 * Language rots. Industry euphemisms rotate. Treat this file as a living
 * document, not a fixture.
 */

/** Words and phrases that make a post read as machine-written to a CHRO. */
export const SLOP_VOCABULARY = [
  'delve', 'seamless', 'seamlessly', 'robust', 'landscape', 'navigate the',
  'in today\'s fast-paced', 'in today\'s world', 'ever-evolving', 'ever evolving',
  'game-changer', 'game changer', 'unlock', 'elevate', 'empower', 'testament',
  'tapestry', 'crucial', 'pivotal', 'resonate', 'dive into', 'deep dive',
  'at the end of the day', 'synergy', 'holistic', 'cutting-edge', 'cutting edge',
  'revolutionize', 'revolutionise', 'transformative', 'embark', 'realm',
  'underscore', 'myriad', 'plethora', 'it\'s important to note',
  'in conclusion', 'paradigm', 'best-in-class', 'world-class', 'supercharge',
  'unleash', 'harness the power', 'take it to the next level', 'moving forward',
];

/** Sentence shapes that give an LLM away regardless of vocabulary. */
export const SLOP_CONSTRUCTIONS = [
  { id: 'not-x-but-y', re: /\bis\s+not\s+(?:just\s+)?[^.,;]{2,45},\s*(?:it|that|this)(?:'s| is)\b/i,
    hint: 'the "it is not X, it is Y" construction is the loudest AI tell; state the thing directly' },
  { id: 'isnt-just', re: /\bis\s?n[o']t\s+just\s+[^.,;]{2,40}(?:,|\u2014|-)\s*it(?:'s| is)\b/i,
    hint: 'same shape as "not X but Y"; rewrite as a plain claim' },
  { id: 'heres-the-thing', re: /\bhere(?:'s| is) the thing\b/i, hint: 'drop the throat-clearing and open on the claim' },
  { id: 'let-that-sink-in', re: /\blet that sink in\b/i, hint: 'influencer cadence; delete' },
  { id: 'read-that-again', re: /\bread that again\b/i, hint: 'influencer cadence; delete' },
  { id: 'buckle-up', re: /\bbuckle up\b/i, hint: 'influencer cadence; delete' },
];

/** Engagement bait. Cheap on the feed and wrong for a relationship sale. */
export const ENGAGEMENT_BAIT = [
  { id: 'thoughts', re: /\bthoughts\?/i },
  { id: 'agree', re: /\bagree\?/i },
  { id: 'what-do-you-think', re: /\bwhat do you think\?/i },
  { id: 'comment-below', re: /\bcomment below\b/i },
  { id: 'tag-someone', re: /\btag (?:someone|a friend|your)\b/i },
  { id: 'like-if', re: /\blike if\b/i },
  { id: 'dm-me', re: /\bdm me\b/i },
  { id: 'link-in-bio', re: /\blink in (?:bio|comments below)\b/i },
  { id: 'comment-keyword', re: /\bcomment ["']?\w+["']? and (?:i|we)(?:'ll| will)\b/i },
];

/** Unsourced authority. If it cannot be traced it cannot be said. */
export const UNSOURCED_AUTHORITY = [
  { id: 'studies-show', re: /\bstudies show\b/i },
  { id: 'research-suggests', re: /\bresearch (?:suggests|shows|indicates)\b/i },
  { id: 'experts-agree', re: /\bexperts (?:agree|say)\b/i },
  { id: 'most-companies', re: /\bmost (?:companies|organisations|organizations|employers)\b/i },
  { id: 'industry-data', re: /\bindustry data (?:shows|indicates|suggests)\b/i },
  { id: 'everyone-knows', re: /\b(?:everyone|we all) knows?\b/i },
];

/**
 * Recruitment discrimination, written for the Indian market specifically.
 *
 * The risk here is almost never a slur. It is normalised industry shorthand
 * that a labour lawyer would read as endorsing a discriminatory hiring
 * practice. Relevant frameworks: Code on Wages 2019, Rights of Persons with
 * Disabilities Act 2016, Transgender Persons (Protection of Rights) Act 2019,
 * Maternity Benefit Act, PoSH Act 2013.
 *
 * severity 'fail' blocks the post outright. severity 'review' is surfaced to the
 * owner on the approval card rather than blocked, because the phrase has
 * legitimate uses.
 */
export const DISCRIMINATION = [
  { id: 'young-energetic', re: /\byoung[,\s]+(?:and\s+)?(?:energetic|dynamic|vibrant)\b/i, severity: 'fail',
    why: 'age preference dressed as culture' },
  { id: 'age-limit', re: /\b(?:age|aged)\s*(?:below|under|less than|not more than|max(?:imum)?)\s*\d{2}\b/i, severity: 'fail',
    why: 'explicit age bar' },
  { id: 'freshers-only', re: /\bfreshers?\s+only\b/i, severity: 'fail', why: 'age proxy' },
  { id: 'no-career-gaps', re: /\bno (?:career |employment )?gaps?\b/i, severity: 'fail',
    why: 'screens out carers and women returning from maternity' },
  { id: 'gender-preference', re: /\b(?:male|female|men|women|boys|girls)\s+(?:candidates?|applicants?|only|preferred|required)\b/i,
    severity: 'fail', why: 'gender preference' },
  { id: 'preferred-gender', re: /\bprefer(?:ably|red)?\s+(?:male|female|men|women)\b/i, severity: 'fail', why: 'gender preference' },
  { id: 'marital-status', re: /\b(?:un)?married\s+(?:candidates?|applicants?|only|preferred)\b/i, severity: 'fail',
    why: 'marital status is a protected characteristic' },
  { id: 'maternity-screen', re: /\b(?:not|no)\s+planning\s+(?:a\s+)?famil(?:y|ies)\b/i, severity: 'fail', why: 'maternity screening' },
  { id: 'caste', re: /\b(?:caste|community|sub-caste)\s+(?:preference|based|specific|no bar)\b/i, severity: 'fail',
    why: 'caste reference in a hiring context' },
  { id: 'religion', re: /\b(?:hindu|muslim|christian|sikh|jain|parsi|buddhist)\s+(?:candidates?|only|preferred)\b/i,
    severity: 'fail', why: 'religious preference' },
  { id: 'mother-tongue', re: /\bmother tongue\s+(?:must|should|preferred|only)\b/i, severity: 'fail',
    why: 'usually a proxy for region or community' },
  { id: 'regional-preference', re: /\b(?:local|native)\s+candidates?\s+(?:only|preferred)\b/i, severity: 'fail',
    why: 'regional preference, often a community proxy' },
  { id: 'nationality', re: /\b(?:indian|foreign)\s+nationals?\s+only\b/i, severity: 'fail', why: 'nationality bar' },
  { id: 'physically-fit', re: /\bphysically fit\b/i, severity: 'review',
    why: 'lawful only where it is a genuine occupational requirement' },
  { id: 'cultural-fit', re: /\bcultural(?:ly)? fit\b/i, severity: 'review',
    why: 'frequently used as a proxy; make the actual criterion explicit' },
  { id: 'stable-candidates', re: /\bstable candidates?\b/i, severity: 'review',
    why: 'often reads as a maternity or carer proxy' },
];

/** Named staffing competitors. Never mentioned, favourably or otherwise. */
export const COMPETITORS = [
  'naukri', 'foundit', 'monster india', 'abc consultants', 'randstad', 'adecco',
  'manpowergroup', 'michael page', 'korn ferry', 'heidrick', 'teamlease services',
  'quess corp', 'ciel hr', 'antal', 'hays',
];

/** Candidate-facing language. Every post addresses the employer, not the hire. */
export const CANDIDATE_FACING = [
  { id: 'apply-now', re: /\bapply now\b/i },
  { id: 'send-resume', re: /\b(?:send|share|drop) (?:your|us your) (?:cv|resume)\b/i },
  { id: 'we-are-hiring', re: /\bwe(?:'re| are) hiring\b/i },
  { id: 'job-seekers', re: /\bjob ?seekers?\b/i },
  { id: 'looking-for-a-job', re: /\blooking for a (?:job|new role)\b/i },
  { id: 'vacancy', re: /\bvacanc(?:y|ies)\b/i },
  { id: 'walk-in', re: /\bwalk[- ]in (?:interview|drive)\b/i },
];
