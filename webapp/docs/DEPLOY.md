# Deploying the approval app on cPanel

Written for someone who is comfortable in cPanel and is not a developer. Follow
the steps in order; each one ends with something you can see working before you
move on. Allow an hour the first time.

Nothing here touches LinkedIn. The app publishes nothing until `linkedin.dryRun`
is turned off, which is the last item on the checklist and happens weeks later,
after LinkedIn approves the API app.

## What you need in front of you

- cPanel login for the hosting account.
- The app folder, zipped. Build it with `npm run package`, which writes
  `dist/gojobs-webapp/` - the same code with the local test leftovers removed:
  the config file holding a laptop database password, the `installed.lock`
  that would make the installer refuse to run, the test images, and the test
  harness routes. Zip the **contents** of that folder. Do not upload `webapp/`
  straight from the project; it is a working directory.
- A password manager. The installer shows six API keys **once** and an owner
  password that cannot be reset by email.
- 30 spare minutes without interruption. The installer keeps its place in a
  browser session, so closing the tab halfway means starting the steps again.

---

## Step 1 - Create the subdomain

cPanel &rarr; **Domains** &rarr; **Create A Domain**.

| Field | Value |
| --- | --- |
| Domain | `approve.gojobs.biz` (any subdomain you like) |
| Document Root | `/home/<cpanel-user>/apps/go-approval/webapp/public` |

**The document root must end in `/webapp/public`.** That folder is the only one
the web is allowed to see. Everything else - the application code, the database
password, the downloaded images - sits one level up where a browser cannot reach
it. If you point the document root at `webapp` instead, `config/config.php` is
one URL away from anybody.

cPanel will not create the folder until it exists, so you may need to make
`apps/go-approval` in File Manager first and come back.

Then cPanel &rarr; **SSL/TLS Status** &rarr; tick the new subdomain &rarr; **Run
AutoSSL**. Wait for the padlock before going further. The agents send bearer
tokens to this host on every call.

## Step 2 - Create the database and its user

cPanel &rarr; **MySQL&reg; Databases**.

1. **Create New Database**: `linkedin`. cPanel saves it as
   `<cpanel-user>_linkedin`. Write down the full prefixed name.
2. **Add New User**: `appuser`, and use the **Password Generator**. cPanel saves
   it as `<cpanel-user>_appuser`. Write down the full name and the password.
3. **Add User To Database**: pick both, then tick **ALL PRIVILEGES** and Make
   Changes.

ALL PRIVILEGES matters for one specific reason. The schema installs a trigger
that makes the `original_*` columns physically unwritable after they are first
set - the database itself refuses to let an agent re-run and overwrite something
Rohin already approved. Creating a trigger needs the `TRIGGER` grant, which is
not in the default set on some hosts. If your host denies it, the installer says
so and lets you continue; the app then enforces the same rule in code, and you
have one fewer safety net.

Do **not** run `CREATE DATABASE` yourself in phpMyAdmin. On shared hosting it
fails, and cPanel needs to know about the database to attach the user to it.

## Step 3 - Upload and extract

cPanel &rarr; **File Manager** &rarr; navigate to `apps/go-approval` &rarr;
**Upload** &rarr; `webapp.zip` &rarr; back in File Manager, right-click the zip
&rarr; **Extract**.

Then, still in File Manager:

1. **Settings** (top right) &rarr; tick **Show Hidden Files (dotfiles)**. Leave
   it on. Several files that must be there start with a dot and you will
   otherwise think they failed to upload.
2. Confirm `webapp/public/.htaccess` and `webapp/public/.user.ini` both exist.
   Without the first one, every URL in the app returns 404 and every agent call
   returns 401.
3. Select `webapp/storage` &rarr; **Permissions** &rarr; `0755`, and tick
   **Recurse into subdirectories**. The app writes downloaded images and its
   error log there.
4. Select `webapp/config` &rarr; **Permissions** &rarr; `0755`. The installer
   writes `config.php` into it.
5. Delete `webapp.zip`.

## Step 4 - Run preflight

Open `https://approve.gojobs.biz/preflight.php`.

This is a single self-contained file. It uses no database and no configuration,
and it writes nothing. It reports what this host actually gives PHP, including
three things no hosting feature list will tell you:

- whether `.htaccess` rewriting is switched on,
- whether the `Authorization` header reaches PHP,
- whether a URL containing a colon reaches PHP.

Every failure on that page prints the exact cPanel remedy. **Fix everything red
before you go on.** All three of those failures produce symptoms later that look
like a broken application rather than a host setting, and you will spend an
afternoon on each.

If you are still choosing a host, upload `preflight.php` on its own to a trial
account and run it there. That is what it is for.

## Step 5 - Run the installer

Open `https://approve.gojobs.biz/install.php`.

**First it asks for a token.** There is no account and no database yet, so the
only way to prove you are the owner and not a passer-by is filesystem access.
The installer writes a random value to `webapp/storage/install-token.txt`. Open
that file in File Manager (**Edit** or **View**), copy the 64 characters, paste
them in. The file is deleted the moment it is accepted, and it is never
reachable over the web.

If someone finds a freshly uploaded folder before you get to it, this is what
stops them installing the app against their own database and walking away with
six working API keys. It is not theatre.

Then six steps:

| Step | What it does | What you do |
| --- | --- | --- |
| 1 Environment | Reads back the effective PHP settings, checks the folders are writable, probes the rewrite rule | Fix anything red, press Re-check |
| 2 Database | Connects, prints the server version, proves CREATE / INSERT / TRIGGER with a scratch table | Enter the **full prefixed** database name and user from step 2, and the password |
| 3 Schema | Imports `sql/schema.mysql.sql` then `sql/schema.part2.sql` and reports the statement count | Press Import. If the tables are already there, press Skip |
| 4 Owner | Benchmarks password hashing on this host, then creates your login | Email, and a password of at least 12 characters. Four unrelated words beats one clever word |
| 5 API keys | Mints one key per agent, A1 to A6 | **Copy all six now.** Use the Download button, put the file in your password manager, tick the box |
| 6 Finish | Writes `config/config.php` and `config/installed.lock`, runs a self-test, deletes itself | Read the result table |

MySQL 8 and MariaDB 10.4 or newer are both fine. Most cPanel hosts run MariaDB;
that is expected and nothing is missing when you see it.

The six keys are shown once and never again. Only a 12-character prefix and a
hash of each key are stored, so nobody - including the installer, including
Rohin - can recover them later. If you lose one, re-run the installer's key step
before finishing, or revoke and re-issue that agent's key from the app.

## Step 6 - Delete the installer

The installer deletes itself on the last step and tells you whether it managed
it. If it says it could not:

File Manager &rarr; `webapp/public/install.php` &rarr; **Delete**.

Delete `webapp/public/preflight.php` as well. It is harmless to run but it
publishes your PHP configuration to anyone who finds the URL.

The installer refuses to run a second time in any case: `config/installed.lock`
exists now, and every request to `install.php` answers `410 Gone` before it
reads anything at all.

## Step 7 - Point the agents at the app

This part happens on the **machine that runs the agents**, not on the web host.
The keys must never be stored on the server that validates them.

Set seven environment variables there:

```bash
export GO_API_BASE=https://approve.gojobs.biz/api/v1
export GO_API_KEY_A1=go_prod_a1_...
export GO_API_KEY_A2=go_prod_a2_...
export GO_API_KEY_A3=go_prod_a3_...
export GO_API_KEY_A4=go_prod_a4_...
export GO_API_KEY_A5=go_prod_a5_...
export GO_API_KEY_A6=go_prod_a6_...
```

On Windows, use **System Properties &rarr; Environment Variables**, or paste the
downloaded `go-agents.env` next to the agent code as `.env`, whichever the agent
host expects. Either way the file belongs somewhere backed up and private.

One key per agent is deliberate. A5 is the only agent that can write to
LinkedIn; if anything ever looks wrong, revoking A5 stops publishing within
seconds and leaves the other five researching, writing and packaging as normal.

## Post-install checklist

- [ ] `https://approve.gojobs.biz/healthz` returns JSON with `"ok": true`.
- [ ] You can sign in with the owner account created in step 4.
- [ ] `public/install.php` is gone. `public/preflight.php` is gone.
- [ ] `config/config.php` is `0600`. If the host forced it wider, the installer
      says so - `config/.htaccess` still denies it over the web, but tighten it
      in File Manager if you can.
- [ ] The six keys are in the password manager, and nowhere on the web host.
- [ ] Settings screen: replace `linkedin_org_urn`. The schema ships it as
      `urn:li:organization:REPLACE_WITH_NUMERIC_ORG_ID`, which is not a real
      organisation and will fail on the first publish attempt.
- [ ] Settings screen: check `company_website` and `company_email`.
- [ ] Run one agent end to end against the live host in dry run - A1 is the safe
      one, it only researches and uploads topics - and confirm the batch appears
      on the review screen.
- [ ] `linkedin.dryRun` in `config/pipeline.config.json` stays `true` until
      LinkedIn approves the Community Management API app. A5 and A6 run their
      full logic in dry run and publish nothing. See `docs/LINKEDIN-API-SETUP.md`
      for what to submit. **Flip it to `false` only after the approval email
      arrives**, and watch the first publish at 08:00 IST the next morning.

---

## Troubleshooting

### A 500 error, or a blank white page

Look at `webapp/storage/logs/php-error.log` in File Manager - open it and read
the **last** lines. The app never prints an error to the browser on purpose: a
PHP stack trace in a response body would carry the database password out to
whoever triggered it.

Common causes, in the order they occur:

- `config/` or `storage/` is not writable (`0755`, owned by your cPanel user).
- PHP was switched back to 7.x in MultiPHP Manager. The app needs 8.1+.
- Someone added `php_value` lines to `.htaccess`. Under LSAPI and PHP-FPM, which
  is what cPanel EA-PHP runs, that is an immediate 500. PHP settings go in
  `public/.user.ini`, which is where the app's already are.

If the log file does not exist at all, PHP could not create it: fix the
permissions on `storage/logs` first, then reproduce the error.

### Every agent call returns 401, but sign-in works

This is the `Authorization` header, and it is the single most common deployment
failure for a bearer-token API on shared hosting. CGI, FastCGI and LSAPI all
drop the header before PHP sees it, so the app never receives the key and
correctly refuses the request. It works perfectly on a laptop, which is what
makes it confusing.

Check it: `https://approve.gojobs.biz/preflight.php?probe=auth` with the header
attached -

```bash
curl -i -H "Authorization: Bearer test" \
  "https://approve.gojobs.biz/preflight.php?probe=auth"
```

The reply must contain `server=yes` or `redirect=yes`. If it says `server=no
redirect=no`, the header is being stripped. The fix is already in
`public/.htaccess`, so first confirm that file was actually uploaded:

```apache
RewriteEngine On
RewriteCond %{HTTP:Authorization} .
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]

SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1
```

Remember File Manager hides dotfiles unless you turn them on.

### One route returns an HTML 403, the rest work

The route is `/api/v1/agent/metrics:bulk-upsert`, and the colon in it is what
some ModSecurity rulesets object to. A6 gets an HTML error page where it expects
JSON, every morning at 09:00 IST, and nothing else in the app misbehaves.

Confirm it first:

```bash
curl -i "https://approve.gojobs.biz/preflight.php/go:path?probe=colon"
```

A line starting `GO-PROBE-COLON` means colons are fine and the problem is
elsewhere. A `403` with an HTML body means ModSecurity.

To fix it, find the rule that fired: cPanel &rarr; **Security** &rarr;
**ModSecurity Tools** &rarr; **Hit List**. Find the entry for that URL and note
its **rule ID**. Either disable that one rule for this domain from that screen,
or put this in `public/.htaccess` with the real ID:

```apache
<IfModule mod_security2.c>
  # Replace 123456 with the rule ID from the ModSecurity hit list.
  SecRuleRemoveById 123456
</IfModule>
```

If your host allows neither, ask support to whitelist that one URL for this
domain. Do not rename the route to dodge the rule: the agents and the API
contract both hard-code it, and renaming it here breaks both.

### A PHP setting you just changed has not taken effect

`public/.user.ini` is read by PHP once and then cached for
`user_ini.cache_ttl` seconds - **five minutes** on a default cPanel build. A
value you edited a moment ago is not live yet, which looks exactly like the
setting being ignored.

Wait five minutes, or open the file in File Manager and re-save it to change its
timestamp, then reload. `preflight.php` and the installer's first step both read
the **effective** value back with `ini_get()`, so what they show is what the app
gets, not what the file says.

Again: PHP settings do not go in `.htaccess` on this host. `php_value` is a 500
under LSAPI and PHP-FPM.

### The installer says "Already installed" and you have not installed it

`config/installed.lock` exists. Either someone got there first - in which case
check the `users` table in phpMyAdmin for an account you do not recognise, and
change the database password - or a previous attempt finished further than you
thought.

To start over deliberately: delete `config/installed.lock` and
`config/config.php`, drop and re-create the database in cPanel, re-upload
`install.php` from the zip, and run it again.

### "The database is unreachable" after installing

The database password was changed in cPanel and `config/config.php` still has
the old one. Edit `config/config.php` in File Manager and update the `pass`
line. It is a plain PHP array; change only what is between the quotes.
