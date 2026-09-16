# LinkedIn API setup

**Start this first.** It is the longest lead time in the project and nothing else
depends on your time the way this does. Everything else can be built, tested and
finished while LinkedIn reviews your application.

Until it is approved, A5 and A6 run in **dry run**: they do their full job,
record the exact request they would have sent, and mark nothing as posted. The
day approval lands, you change one flag.

## What you are applying for

| You need | To do | Product |
| --- | --- | --- |
| `w_organization_social` | Publish to the company page | Community Management API |
| `rw_organization_admin` | Read page analytics (impressions, shares) | Community Management API |

Both come from the same product, so it is one application.

## Before you apply

1. **You must be an admin of the company page.** Check at
   linkedin.com/company/golden-opportunities-pvt-ltd → Admin tools. If you are
   not, an existing admin has to add you.
2. **The page should be verified.** LinkedIn asks for this and it also helps the
   page generally.
3. **Have these ready.** LinkedIn asks for all of them:
   - the legal entity name, Golden Opportunities Pvt Ltd
   - the registered address
   - a business email on your own domain, not Gmail
   - the company website (GOjobs.biz)
   - **a privacy policy at a public URL.** If GOjobs.biz does not have one, this
     is the thing most likely to stall you, so sort it before you start.

## The steps

1. Go to **developer.linkedin.com** and sign in with the LinkedIn account that
   administers the page.
2. **Create an app.** Associate it with the Golden Opportunities page. LinkedIn
   verifies that association through a page admin, so it fails if step 1 above is
   not done.
3. **Products → request Community Management API.** You land in **Development
   Tier** first: low call volume, test pages only. Enough to prove the
   integration works end to end.
4. **Apply for Standard Tier.** This is the real gate. It needs a **screencast
   demonstrating each use case you declared** — record the approval screen, a post
   going out, and the analytics coming back. The dry-run mode exists partly so you
   can film this before you have live access.
5. **Save the credentials** into environment variables on the server, never into
   the database or a file in the web root:
   ```
   LINKEDIN_CLIENT_ID=...
   LINKEDIN_CLIENT_SECRET=...
   LINKEDIN_ACCESS_TOKEN=...
   LINKEDIN_REFRESH_TOKEN=...
   LINKEDIN_ORG_URN=urn:li:organization:<numeric id>
   ```

## Find your organisation URN

The config ships with a placeholder. The real value is numeric, not the page
slug. With a token that has org access:

```
GET https://api.linkedin.com/rest/organizationAcls?q=roleAssignee&role=ADMINISTRATOR
```

Put the numeric id into `config/pipeline.config.json` under
`company.linkedinOrgUrn`, and into `app_settings.linkedin_org_urn` in the
database.

## Going live

One flag, in `config/pipeline.config.json`:

```json
"linkedin": { "dryRun": false, "mode": "api" }
```

Nothing else changes. A5 and A6 have been running their full logic all along, so
there is no new code path to discover on the first live morning.

Run one post on a quiet day first and watch it land.

## If Standard Tier is refused or delayed

Set `"mode": "assisted"`. A5 then prepares the post, picks up your approved image
and text, and notifies you to publish it with one tap. You keep the research, the
copy, the images, the quality gates, the scheduling and the records — only the
final tap becomes manual. That is roughly 95% of the value, and it costs you
about thirty seconds a day.

For analytics in that mode, export the CSV from LinkedIn's own analytics screen
and upload it; A6 accepts `source: "manual_csv"`.

## The token will expire, and the system will go dark quietly

This is the most likely way this system dies six months from now.

Access tokens are short-lived; refresh tokens last around a year. When a refresh
token expires, every call starts returning 401 and **the dashboard still looks
fine**, because it shows what exists rather than what is missing.

So:

- store `expires_at` with the token;
- alert at 14, 7, 3 and 1 days before;
- treat a 401 as an incident, not something to retry;
- **put the renewal in someone's calendar**, with a named owner. If it is not on
  a calendar it will not happen.

## Never automate the website

Do not drive linkedin.com through a browser to post or to scrape analytics. It
contravenes LinkedIn's user agreement and risks restriction or loss of the
company page **and your personal account**. For a recruitment firm the LinkedIn
presence is a core business asset, not a marketing channel you can rebuild.

If the API is unavailable, the fallback is assisted mode and a manual CSV export.
Slower, duller, and it cannot cost you the page.

## What the analytics actually give you

`organizationalEntityShareStatistics` returns impressions, unique impressions,
clicks, likes, comments, shares and engagement rate, over a rolling 12-month
window. Your brief asks for "views", which maps to **impressions**; A6 records it
under both names so the web page can label it your way.

One thing to expect: for posts published **before** this system existed, per-post
day-by-day history may not be retrievable — LinkedIn's per-share statistics are
essentially lifetime-to-date totals. A6 writes a dated snapshot every day and
derives the daily movement from consecutive snapshots, so the trend builds
forward from its first run. Where the API does return a daily series it will be
backfilled. A6 records which case applied, and it will never invent a curve to
fill the gap.

## Rate limits

Your volume is trivial: one post, one comment and roughly 120 analytics reads a
day. Limits are per application, reset at midnight UTC, and you read your actual
numbers in the Developer Portal's Analytics tab.

Rate limits are not a throughput problem here. They are a **bug** problem: a
runaway retry loop can burn a day's quota in minutes. Both A5 and A6 cap their
calls per run for exactly that reason.
