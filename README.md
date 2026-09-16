# GO Social Media Posting

A six-agent pipeline that researches, writes, illustrates, reviews, publishes and
measures LinkedIn posts for **Golden Opportunities Pvt Ltd**.

Nothing reaches the company page without Rohin approving it twice.

**Part 1 (the six agents) and Part 2 (the approval web app) are both built and tested.**

## Start here

| If you want to | Read |
| --- | --- |
| Understand the whole system | [CLAUDE.md](CLAUDE.md) |
| See what the pipeline actually produced | [samples/README.md](samples/README.md) |
| Know what can go wrong | [docs/RISKS.md](docs/RISKS.md) |
| Get LinkedIn access started | [docs/LINKEDIN-API-SETUP.md](docs/LINKEDIN-API-SETUP.md) |
| Deploy the web app | [webapp/docs/DEPLOY.md](webapp/docs/DEPLOY.md) |
| Check the PHP against the reference | [webapp/docs/PARITY.md](webapp/docs/PARITY.md) |
| Read the frozen contract | [contract/](contract/) |

## Try it

No dependencies to install. SQLite ships inside Node 22+.

```bash
npm test
```

99 tests. Then watch one topic go from research to a published post and its first
day of analytics:

```bash
npm run e2e
```

Check any draft against the house rules:

```bash
node lib/validate.mjs post samples/posts/post1.json
```

## The web app

`webapp/` is the approval site: PHP 8.1 + MySQL, no Composer, no build step.
Upload the folder, point the document root at `webapp/public`, open
`install.php`, and it checks the environment, imports the schema, creates your
account and mints the six agent API keys.

It is the same contract the agents were built against, so the acceptance test is
the Part 1 suite pointed at the real host:

```bash
GO_API_BASE=https://app.gojobs.biz GO_TEST_TOKEN=... npm run test:remote
```

44 tests. They pass locally against PHP 8.2 and MariaDB 10.4, through Apache with
the real `.htaccess`, and through PHP's built-in server.

## The pipeline

```
A1 Topic Scout ──> [ GATE 1: you approve topics ]
                            │
                            ▼
                   A2 Copywriter ──> A3 Image Maker ──> A4 Packager
                            │
                            ▼
                   [ GATE 2: you edit, pick an image, approve, set a date ]
                            │
                            ▼
                   A5 Poster (daily 08:00 IST) ──> LinkedIn + first comment
                            │
                            ▼
                   A6 Analyst (daily 09:00 IST) ──> views and shares back
```

A1 runs Wednesday 05:00 IST. A2, A3 and A4 fire when you approve, not on a clock.
A5 and A6 run daily.

## What is where

```
CLAUDE.md               the brief, the rules, the tool matrix
.claude/agents/         6 agents + 3 independent checkers
.claude/skills/         guardrails, house style, API client
config/                 every threshold and endpoint, in one place
contract/               MySQL schema + REST contract, frozen for Part 2
mock-api/server.mjs     a working stand-in for the Part 2 web app
lib/validate.mjs        the deterministic gates
lib/goapi.mjs           the API client every agent uses
tests/                  99 tests, including the guardrail suite
samples/                real output from the live test run
docs/                   LinkedIn setup, and the risk register
```

## Before this goes live

1. **Start the LinkedIn application today.** It is the longest lead time in the
   project and everything else can be finished while it is reviewed. Until it is
   approved, A5 and A6 run in dry run and publish nothing.
2. Put the numeric organisation URN into `config/pipeline.config.json`.
3. Build Part 2 against `contract/openapi.yaml`. It is done when
   `npm test` passes against the real host instead of the mock.
4. Put LinkedIn token renewal on a named person's calendar. A silently expired
   token is the most likely way this stops working six months from now.
