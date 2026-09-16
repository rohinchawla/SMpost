# What can go wrong

You asked what breaks. This is the honest list, worst first, with what catches
each one and where that catch lives.

"Caught by" means there is a test or a constraint that actually fails. "Yours"
means it is a decision or a habit, and no amount of code will save you.

---

## 1. The backlog grows forever

**The arithmetic.** A1 produces 30-60 topics a week. A5 publishes 7. Even if you
approve only a third, and some fail quality gates, you still accumulate roughly
10-25 finished, image-generated posts a week that will never go out. After six
months that is several hundred packages, each of which cost image credits, tokens
and a slice of your attention.

Worse, content decays. A post packaged in March and published in November is a
seasonal-compliance post about the wrong quarter.

**Caught by:** `queue-depth`. When more than 21 approved posts are waiting, A1
skips the week and tells you. `expires_at_ist` puts a 45-day TTL on every package
and A5 refuses an expired one.

**Yours:** if you find yourself approving 7 topics a week rather than 40, say so
and I will move A1 to monthly. The brief's 30-60 is a good batch size attached to
the wrong period.

---

## 2. The human gate quietly disappears

The most dangerous failure in any approval pipeline is not that approval is
refused. It is that an agent decides to be helpful and processes unapproved work,
and nobody notices for a month because the output looks fine.

**Caught by:** the API physically cannot serve A2 an unapproved topic.
`topics-list` filters to `review_status='approved'` server-side and there is no
flag to widen it. Claiming a held topic returns 409. Tested in *"a topic on hold
is invisible to A2 and cannot be claimed"* and *"A2 is only ever handed approved
topics"*.

---

## 3. A fabricated statistic goes out under your name

The highest reputation risk here, and the one that looks perfectly healthy right
up until a CHRO comments publicly.

Three shapes: an invented number; a real number attributed to the wrong source;
and the sneakiest, a real US or UK number presented as an Indian one.

**Caught by:** `NUMERIC_TRACE` in `lib/validate.mjs`. Every numeral in a post must
appear verbatim in a quoted sentence from the source, or be a year, or be one of
your own signed-off figures. The rule is blunt on purpose: **no traceable source,
no number.** A post with no numbers is fine.

`qa-topic-checker` then does the part a machine cannot: it opens the page and
confirms the quote is really there, because a fabricated *quote* would pass every
downstream check.

Tested in *"an untraced statistic is refused"* and *"the same statistic passes
once it is quoted from the source"*.

---

## 4. Something discriminatory goes out

In Indian recruitment this is legal exposure, not just embarrassment. And the
danger is not slurs. It is normalised industry shorthand that reads as neutral to
whoever wrote it:

> "young, energetic team" - "freshers only" - "no career gaps" -
> "stable candidates" - "local candidates only" - "preferably male"

**Caught by:** a recruitment-specific blocklist in `lib/bans.mjs`, applied at A2,
re-applied by A4 on the stored bytes, and applied again by A5 on the exact bytes
about to be sent. Seven of these phrasings have a dedicated test each.
`qa-post-checker` adds the judgement layer - *could an Indian labour lawyer read
this as endorsing a discriminatory practice* - which catches what no regex will.

Phrases with legitimate uses, such as "cultural fit" or "stable candidates", are
flagged for your review rather than blocked outright.

**Yours:** the euphemisms rotate. Review `lib/bans.mjs` quarterly.

---

## 5. The same post goes out twice

The classic distributed-systems bug, with a public audience. A5 sends the
request, LinkedIn creates the post, the response times out, A5 sees a failure and
retries.

**Caught by** four independent layers:

1. A row lease. A second A5 gets `LEASE_HELD_BY_OTHER_RUN` and exits.
2. A deterministic idempotency key. A replayed result is a no-op.
3. `uq_posts_linkedin_urn` - a URN can be recorded exactly once, ever.
4. **Verify before retry.** On any ambiguous outcome A5 waits 60 seconds, lists
   the page's recent posts, matches on `commentary_sha256`, and publishes only if
   genuinely absent. After two ambiguous attempts it stops and asks you.

Tested in *"a post already live cannot be leased again"*, *"the queue goes quiet
once something has gone out today"* and *"replaying the same publish result does
not post twice"*.

---

## 6. You approve something, edit it, and the edit publishes unreviewed

Subtle and easy to miss. You approve a post, then tidy a sentence. The version
that goes out was never the version you approved.

**Caught by:** a content hash taken at approval. A5 re-computes it when it takes
the lease and refuses with `CONTENT_CHANGED_AFTER_APPROVAL`. Tested in *"a post
edited after approval will not publish until re-approved"*.

The same mechanism catches the reverse: you set something to hold at 07:55 and A5
honours it at 08:00, because the check happens at lease time rather than when the
queue was built.

---

## 7. An agent overwrites what you wrote

Your brief says agents must never change information you have given them. A
promise in a prompt is not a guarantee.

**Caught by:** the database. `original_*` columns are immutable after insert,
enforced by a trigger in both MySQL and SQLite. Agents write them once and can
never rewrite them; you write `final_*` through the web page. Tested by asserting
that an actual UPDATE throws.

---

## 8. The images betray you

An image with "RECRUTMENT SOLUTONS" garbled on a whiteboard, an invented
corporate logo on a laptop lid, or six fingers, tells every reader that a
recruitment firm outsourced its judgement to a machine.

**Handled structurally:** images in this system carry **no words at all**. That
removes the whole garbled-text class of failure rather than catching it case by
case. Any text you need is composited later by the web page in real fonts.

`qa-image-checker` also rejects logos, identifiable faces, anatomy defects, the
cliche set, and images that would fit any post about anything.

An image can also encode a bias the copy avoided - a post about experienced
leaders rendered entirely as older men. The checker assesses what the image
*implies about who belongs in the role*, not only what it depicts.

---

## 9. LinkedIn access, and the day the token expires

API approval is partner-gated and takes weeks. Worse, once it works, a refresh
token will eventually expire and **the system will go dark silently** - the
dashboard still looks fine, because it shows what exists rather than what is
missing.

**Handled:** dry-run mode means nothing blocks on approval, and `assisted` mode
survives a refusal. See `docs/LINKEDIN-API-SETUP.md`.

**Yours:** put token renewal on a named person's calendar. This is the single
most likely reason the system stops working six months from now, and no code in
this repo can prevent it.

---

## 10. Nothing fails, and nothing happens

The realistic long-run outcome of an unattended pipeline is not a dramatic
failure. It is that something stops and nobody notices for five weeks. A seed
site changes its markup and A1 starts returning four topics instead of forty. A
scheduled task is rebuilt without its schedule.

**Caught by:** every run writes an `agent_runs` row, **including a run that did
nothing**, with a reason. A run that completes with zero output is an alert, not a
success. Tested in *"a no-op run is recorded with a reason, so silence is
detectable"*.

**Still to build before you go live:** a daily watchdog asking "did today's post
go out, and did every expected agent run happen", pinging an external uptime
monitor so that the watchdog dying is itself detectable. Otherwise the alerting
system's own failure is silent.

---

## 11. The analytics quietly lie

Three ways. A metric the API did not return, stored as `0`, reads as "the post
died" and corrupts every daily delta after it. A partial read that regresses a
counter looks like a collapse in reach. And "views" is not one thing - LinkedIn
has renamed and redefined impressions, unique impressions and members reached
more than once.

**Caught by:** missing data is `NULL`, never `0`, with a `gap_reason`. Counters
never regress. Raw API field names and the API version are stored alongside every
value. Tested in *"a missing metric is stored as unknown, never as zero"* and
*"a partial read cannot make a post look like it died"*.

**Be aware:** day-by-day history for posts published before A6's first run may not
be retrievable at all. A6 builds the trend forward from daily snapshots and will
say so rather than inventing a curve.

---

## 12. Every post reads the same

Thirty topics a week on one subject will collide, and near-duplicates are worse
than exact ones because no single run can see them.

**Caught by:** `dedupe-check` against 365 days before upload; a batch must carry
at least six distinct themes with no post type above 30%; a theme cools off for
45 days; A4 enforces rotation when assigning dates. Tested in *"a batch that is
all one post type is refused"* and *"a batch on one theme is refused"*.

---

## 13. American HR commentary for an Indian CHRO

Six of the eight sources in your brief are UK or US. Left alone, A1 produces
technically correct, contextually useless content.

**Caught by:** an `india_relevance` score on every topic, at least half a batch
required at level 2, and Indian sources added to `config/sources.json`. A UK or US
source qualifies only when the one-liner explicitly reframes it for Indian
conditions. Tested in *"a batch of UK and US material is refused for an Indian
audience"*.

---

## 14. Cost

Order of magnitude at the briefed volume of 60 topics a week with two images
each: roughly 170 image generations a week, and 1.5 to 2.5 million tokens. Call it
₹10,000-30,000 a month, for content that is mostly never published.

**Handled:** per-run credit caps in config, and the absolute rule that an agent
hitting a credit wall **stops and tells you. It never buys more.** That is the "no
financial transaction" rule made operational rather than aspirational.

The queue ceiling in risk 1 is also the main cost control: not generating images
for posts that will never run is the largest single saving available.

---

## 15. Nobody answers the comments

Not one of the six agents, but it decides whether any of this produces revenue. A
CHRO comments "what are you seeing on notice-period buyouts in BFSI?" and gets
silence for four days. That is worse than not posting: it turns a warm inbound
signal into visible evidence that nobody is home.

**Deliberately not automated.** An AI reply to a prospect's technical question in
a relationship sale is all downside. The most an agent should ever do is tell you
a comment exists.

**Yours:** name a person and an SLA before going live. Four working hours is a
reasonable target.

---

## Two things worth deciding before launch

**Posting at 08:00.** You confirmed daily at 8am. At that hour most Indian
enterprise HR leaders are commuting or on the school run; this audience clusters
on LinkedIn around 09:30-11:00 and again in the evening. The time is configurable,
and once A6 has eight weeks of data it can answer this empirically instead of by
guesswork. It is the one question the system can settle about itself.

**Company page against your personal profile.** For Indian B2B recruitment, a
founder's personal profile typically out-reaches the company page by a large
multiple. Your brief specifies the page and that is what is built. Posting from
your profile and resharing to the page is the higher-reach shape, but it needs a
different LinkedIn permission and a different voice, so it is an architectural
choice rather than a setting.

---

## What is not covered here

The approval web page itself is Part 2, and your brief for it does not mention
authentication at all. **An unauthenticated approval page on the public internet
makes the human gate decorative** - anyone who finds the URL can approve content
that publishes to your company page. It also stores LinkedIn tokens, which are
the keys to the brand.

Login plus two-factor, encrypted token storage outside the database, CSRF
protection, parameterised queries, an immutable audit log and tested daily
backups go in from the start, not later.
