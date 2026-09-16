---
name: go-house-style
description: How a Golden Opportunities LinkedIn post is built - hook, mobile formatting, hashtags, links, CTAs, credibility signals for an Indian CHRO, and the AI-slop ban list. Load before writing or reviewing any post copy.
---

# House style

You are writing for **one person**: an HR Head at an Indian enterprise with 500+
headcount, on a phone, at nine in the morning, who will give you about a second
and a half. Everything below follows from that.

## The hook is the whole game

LinkedIn shows roughly 140 characters on mobile before "see more". The tap to
expand is the highest-leverage event in this system: no expansion, no read.
Desktop is more generous and LinkedIn has moved the line before, so **budget 125
characters and you are safe everywhere**.

- Line 1 stands alone as a complete, specific statement.
- It contains **one of**: a number, a named cost, a named process, or a stated
  contradiction.
- The tension sits *inside* the visible window. Never put the interesting half
  after the fold.

**The shape that works for this audience is the named problem with a price tag.**

> Your best finalist accepted somewhere else. In the fifth week of notice.

A CHRO stops because that is a number on their own dashboard.

Second best is the specific counter-claim:

> Your offer-drop rate is not a compensation problem. It is a calendar problem.

Never open with: a greeting, a definition, "In today's...", a general question,
the company name, a hashtag, or an emoji.

## Formatting for a thumb

Assume a 390px screen held in one hand. A five-line paragraph is a grey brick.

- One idea per block. Blocks of one or two lines, never three or more.
- A blank line between every block. At least three blocks.
- Lines under 14 words, so they do not wrap three times.
- No ALL CAPS beyond an acronym. No bold-unicode hacks - they break screen
  readers and look like spam.
- No emoji as bullets. If a list is unavoidable at 100 words, three items, each a
  fragment, plain hyphen or nothing.
- Hashtags on the last line, after a blank line. Never woven into a sentence.

## Hashtags

Weak as a discovery signal in 2026 - LinkedIn classifies the text itself - but
still useful for topical classification, human scanning, and brand tagging.

- **3 to 5. Never more.** Above five reads as reach-chasing.
- One broad, two or three niche, one branded. Niche examples:
  `#TalentAcquisition`, `#HiringIndia`, `#GCCHiring`, `#Attrition`,
  `#WorkforcePlanning`.
- Bottom of the post, own line. Not in the hook - it costs characters in the only
  window that matters.
- Never hashtag a client, a competitor, or a trending non-HR topic.

Because hashtags are weak, the real classification lever is **the topic keywords
appearing naturally in the body**. That matters more than the tags.

## Links: zero in the body

A URL in the post body reliably suppresses reach. LinkedIn does not want to send
traffic off-platform.

**Rule: no http, no https, no bare www in the body. Ever.**

The link goes in `first_comment_text`, which A5 posts within two minutes of
publishing. That costs nothing, carries no penalty, and the comment is itself an
early engagement signal. `lib/validate.mjs` enforces this as a hard gate.

Never: "link in bio" (this is not Instagram), "comment INTEREST and I'll DM you",
or shortened URLs.

## Calls to action

The buyer is a CHRO with a procurement process. They will not "apply", "sign up"
or "book a demo" off a LinkedIn post. What a post can do is **make them remember
the name when the requisition lands**. Design for recall, not conversion.

- **At most 35% of posts carry a CTA.** Two in seven. A page that asks every day
  is a vendor; a page that gives six times and asks once is a peer.
- One CTA per post. Never two asks.
- Name the artefact and the time cost:

> We benchmark notice-period drop-off by sector. Happy to send yours: rc@gojobs.biz

- The contact is **rc@gojobs.biz**, with GOjobs.biz as the secondary. Both live in
  the first comment, never the body.
- Banned CTA forms: "Apply now" (wrong audience entirely), "DM me", "Tag someone
  who needs this", "Like if you agree", "Visit our website to learn more", and
  anything with an exclamation mark.
- **For the other 65% of posts the strongest CTA is none.** End on the practical
  move, with the brand attached.

## Credibility for an Indian CHRO

This reader has heard every generic line. Credibility comes from particulars they
can check against their own week.

**Every post carries at least one concrete, falsifiable particular.** Draw from:

- **Statutory**: Code on Wages, Social Security Code, PoSH committee obligations,
  EPFO and UAN portability, gratuity eligibility, state Shops and Establishments
  variation, contract labour regulation, background verification discrepancy rates.
- **Notice-period culture**: 30, 60 and 90-day bands by sector, buy-outs, the
  offer-to-join gap, the double-offer problem, ghosting at joining.
- **Tier-2 hiring**: Coimbatore, Indore, Kochi, Jaipur, Nagpur, Bhubaneswar -
  supply depth, salary arbitrage, retention against metros, relocation failure.
- **GCC hiring**: Hyderabad, Bengaluru and Pune ramp-ups, and the wage pressure
  they put on IT services and domestic enterprises.
- **Attrition**: by sector, early attrition in the first 90 days, regretted
  against unregretted.
- **Funnel**: TAT by level, interview-to-offer ratio, offer-drop percentage, cost
  per hire, panel no-show rate.

Name a sector and a headcount band rather than a company: *"a 2,400-person auto
ancillary near Pune"*. Use rupees, lakh and crore. Indian English spellings,
consistently.

## Voice

**Authoritative to a CHRO**: first-hand, specific, unhedged on the observation
and honest about the trade-off. Present tense. Second person. Short declaratives
of varying length. Willing to say something slightly unpopular. Sounds like
someone who has lost a candidate at offer stage and remembers it.

**Slop to a CHRO**: balanced, comprehensive, enthusiastic, symmetrical, and about
nothing in particular. Three-part lists. Every sentence the same length. A tidy
moral at the end.

- Write to one person, not "HR leaders".
- Never open with "As an HR leader, you know..." - it flatters and says nothing.
- No hedging stacks: "can often potentially help".
- No corporate abstractions as subjects. Use people and processes.
- Vary sentence length between 4 and 18 words. Two consecutive sentences of
  identical length is a smell.
- Take one position per post. Do not survey.

## The ban list

Enforced by `lib/validate.mjs`; the source of truth is `lib/bans.mjs`.

**Constructions**: "it is not X, it is Y" in any form (the loudest tell); more
than one em dash; "Here's the thing"; "Let that sink in"; "Read that again"; a
one-word paragraph for drama; closing engagement bait.

**Vocabulary**: delve, seamless, robust, leverage as a verb, landscape, navigate
the, in today's fast-paced world, ever-evolving, game-changer, unlock, elevate,
empower, testament, tapestry, crucial, pivotal, resonate, dive into, at the end
of the day, synergy, holistic, cutting-edge, revolutionise, transformative,
embark, realm, underscore, myriad, plethora, "it's important to note", "in
conclusion". Inflections count: "unlocking" fails on "unlock".

**Unsourced authority**: "studies show", "research suggests", "experts agree",
"most companies", "industry data indicates". Name the source or drop the claim.

## The 100-word cap

Body only. Hashtags and the CTA line sit outside the count.

Be honest about what it costs: the cap suits a contrarian take, a data point or a
myth-bust, and it fights a client-problem story, which wants setup and payoff.
**Its real virtue is that it is an anti-slop device** - at 100 words there is no
room for a preamble, a hedge or a tidy moral, and most of what makes AI writing
unbearable is padding.

When a story will not fit: cut the setup and open at the complication, keep
exactly one particular, and move the "how" into the first comment, which has no
limit and is where the interested reader goes anyway.

There is also a floor of 40 words. Below that it reads as a fragment.

## Variety across a batch

Thirty posts on one subject will collide. Every topic carries a `post_type`, and
A4 enforces rotation when it assigns dates.

| Type | Shape |
| --- | --- |
| `contrarian_take` | A widely held HR belief, and why it is wrong in Indian conditions |
| `data_point` | One sourced number, and what it means for the reader's quarter |
| `client_problem` | Anonymised situation, complication, what actually worked |
| `framework` | Three named steps, and the failure mode of skipping the second |
| `myth_bust` | "X does not cause Y", with the real mechanism |
| `seasonal_compliance` | Appraisal cycle, budget lock, festive attrition, campus cycle |

Rotation: no type twice in a row; in any rolling 7 published posts at least 5
distinct types, no type more than twice, and no more than 2 CTAs; a theme cools
off for 45 days after it is published.

## Before you hand anything on

```bash
node lib/validate.mjs post work/draft.json
```

Exit 0 or it is not finished. The gates it applies are listed with worked
examples in `tests/guardrails.test.mjs`.
