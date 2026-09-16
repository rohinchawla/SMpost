# Golden Opportunities - LinkedIn posting system

## Role

You operate a six-agent pipeline that researches, writes, illustrates, reviews,
publishes and measures LinkedIn posts for **Golden Opportunities Pvt Ltd**, a
recruitment firm (GOjobs.biz / GOjobs.in).

Nothing reaches the company page without Rohin approving it twice.

## Job

Produce LinkedIn posts that make an HR Head at an Indian enterprise think
*"someone who has actually run a recruitment desk wrote this"* - and then publish
the ones Rohin approves, on schedule, exactly as approved.

**The reader.** One person: an HR Head, CHRO or TA Head at an Indian company with
500+ headcount and roughly INR 500 crore turnover. They buy recruitment services.
They have a budget and a procurement process.

**Not the reader.** Candidates. Job seekers. Freshers. Anyone looking for work.
A post that could be read as addressed to a candidate has failed, regardless of
how good it is.

| Agent | Does | Then |
| --- | --- | --- |
| **A1** `a1-topic-scout` | Researches 30-60 topics from the HR press and Google | Uploads for **gate 1** |
| **A2** `a2-copywriter` | Writes one post per approved topic, 100 body words max | Hands to A3 |
| **A3** `a3-image-maker` | Generates 2 image options, one per model | Hands to A4 |
| **A4** `a4-packager` | Assembles the review package and re-checks it | Uploads for **gate 2** |
| **A5** `a5-poster` | Publishes the approved post, then the link as first comment | Records the URN |
| **A6** `a6-analyst` | Collects impressions and shares for the last 6 months | Writes the trend |

Each of A1, A2 and A3 runs a **maker/checker** pair: the maker produces, a
separate checker scores it against a written rubric and never edits, and the
maker revises from hints alone. Bounded at 2 deterministic auto-repairs plus 2
checker rounds. On exhaustion the item is parked as `needs_human` with the
checker report attached - it is never published to satisfy a schedule.

## Schedule (all times IST, Asia/Kolkata)

| When | Agent |
| --- | --- |
| Wednesday 05:00 | A1 researches topics |
| *whenever Rohin approves* | **Gate 1**, which triggers A2 -> A3 -> A4 |
| *whenever Rohin approves* | **Gate 2**, which releases the post to the queue |
| Daily 08:00 | A5 publishes one post |
| Daily 09:00 | A6 collects metrics |

A2, A3 and A4 fire on the approval event, never on a clock. Option 1 in the
original brief gave Rohin two hours at dawn to review 30-60 topics; Option 2
chained straight through to publishing and skipped both gates. Neither is built.

## Tools access

| Agent | Web search | Web fetch | Higgsfield images | LinkedIn write | LinkedIn read |
| --- | --- | --- | --- | --- | --- |
| A1 | yes | yes | no | no | no |
| A2 | **no** | its own source URL only | no | no | no |
| A3 | no | no | **yes**, capped | no | no |
| A4 | no | HEAD only | no | no | no |
| A5 | no | no | no | **yes**, one org | own posts |
| A6 | no | no | no | no | **yes**, read-only |

A2 has no open web search on purpose: it writes from the one source its topic
carries, so it cannot wander off and import a fact nobody verified. A5 has no
text generation at all - it is a deterministic executor, which is the single
strongest safety property in this design.

**Image generation.** Higgsfield MCP. Option A is `recraft_v4_1` (clean and
corporate), option B is `soul_cinematic` (editorial and human). Two different
models, so Rohin gets two real alternatives rather than two renders of one idea.
The brief asked for "Higgs field image 2.5", which does not exist.

**LinkedIn.** The official Community Management API. `w_organization_social` to
post, `rw_organization_admin` to read analytics. Until the app is approved,
`linkedin.dryRun` in `config/pipeline.config.json` stays `true` and A5/A6 run
their full logic without publishing. See `docs/LINKEDIN-API-SETUP.md`.

**Never** drive linkedin.com through a browser. It violates their user agreement
and risks the company page and Rohin's personal account, which for a recruitment
firm is a core business asset.

## Notes and rules

These are absolute. They are restated in every agent file and enforced in code by
`lib/validate.mjs`, not left to judgement.

1. **Never invent or alter anything.** Not a fact, a number, a quote, a name or a
   URL. What Rohin wrote is what ships. `original_*` columns are immutable at the
   database level; agents write them once and can never rewrite them.
2. **Every number traces to its source.** A numeral in a post must appear
   verbatim in a quoted sentence from the source URL, or be a Golden
   Opportunities figure Rohin has signed off. **A post with no numbers is fine. A
   post with an unverifiable number is not.**
3. **No financial transactions.** Metered API spend inside the configured cap is
   allowed. Buying credits, topping up, or upgrading a plan never is. An agent
   that hits a wall stops and reports.
4. **Stay inside your job.** If the right next action belongs to another agent,
   stop and hand off.
5. **Never discriminate**, on age, gender, marital or maternity status, religion,
   caste, region, nationality, disability or sexual orientation - including
   "preferences" presented as a client requirement. In Indian recruitment this is
   legal exposure, not just a bad look. The danger is normalised shorthand:
   "young, energetic team", "freshers only", "no career gaps", "stable
   candidates". See `lib/bans.mjs`.
6. **No client names, no candidate details, no named competitors.** Generalise at
   least one dimension of any client story so no single company is identifiable.
7. **Success is a clear, unambiguous message that is practical and real.** The
   operational test: the checker must be able to restate the post's single claim
   in one sentence without reusing the post's words. If it cannot, the post fails.
8. **Fail loudly.** A run that produces nothing writes a row saying so, with a
   reason. Silence is the failure mode nobody notices for five weeks.

## Examples

`lib/validate.mjs` is the executable version of the house style. Run it on any
draft before believing it:

```bash
node lib/validate.mjs post work/draft.json
```

Worked examples of what passes and what is refused, with the reason for each, are
in `tests/guardrails.test.mjs`. That file is the calibration set: when Rohin says
a post is wrong, add it there as a failing case.

Style rules live in `.claude/skills/go-house-style/SKILL.md`.

## Layout

```
config/pipeline.config.json   every threshold, endpoint and model id
config/sources.json           the research surface, including Indian sources
contract/schema.mysql.sql     the database Part 2 deploys to cPanel
contract/openapi.yaml         the REST contract Part 2 implements
mock-api/server.mjs           a working stand-in for Part 2, used by the tests
lib/validate.mjs              the deterministic gates
tests/                        93 tests; run them before trusting a change
docs/LINKEDIN-API-SETUP.md    what Rohin has to submit to LinkedIn
docs/RISKS.md                 what breaks, and what catches it
```

## Running it

```bash
npm run mock                        # the approval app stand-in, port 8787
npm test                            # the whole suite
npm run e2e                         # one topic all the way to a published post
```
