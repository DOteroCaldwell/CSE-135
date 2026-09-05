# HW4 — Deploying the Analytics Platform

Takes the reporting vhost from "HW3 REST API behind HTTP Basic" to "authenticated
dashboard, user management, and detailed report", plus the collector and schema
changes HW4 depends on.

Repo artifacts:

- [`src/sql/002-host-and-resources.sql`](../../src/sql/002-host-and-resources.sql) — host column, synthetic flag, `resources` table
- [`src/sql/003-users.sql`](../../src/sql/003-users.sql) — `users` + `login_attempts`
- [`src/sql/004-seed-users.sql`](../../src/sql/004-seed-users.sql) — grader accounts
- [`deploy/apache/reporting.conf.sample`](../../deploy/apache/reporting.conf.sample) — the target vhost
- [`deploy/apache/collector.conf.sample`](../../deploy/apache/collector.conf.sample) — adds `Timing-Allow-Origin`
- [`src/tools/devstack/`](../../src/tools/devstack/) — local stack, for rehearsing any of this

> Both vhost configs in this guide were checked with `apache2ctl configtest` against
> Apache **2.4.58** on Ubuntu 24.04 before being committed. Results are quoted below.

---

## The one thing that must not go wrong

Through HW3, `/api/*` was protected by **exactly one thing**: the vhost's
`Require valid-user`. HW4 has to remove it, because a login form behind HTTP Basic
is a login form nobody can reach.

The moment that directive comes off, an unguarded `/api/*` serves the entire
analytics database — every session, user agent and IP the collector has recorded —
to anonymous callers. The application therefore grew its own guard first
(`api/index.php` → `requireApiAuth()`), and the deploy order exists to make sure
that guard is live and verified **before** Apache stops doing the job.

```
1. migrations          schema first; code that queries missing columns 500s
2. application code    deploys behind the still-active Basic auth — safe
3. VERIFY the guard    while Basic auth is still the backstop
4. vhost cutover       only now does Basic auth come off
5. VERIFY anonymously  confirm the app is actually holding the door
```

Reversing 3 and 4 leaves a window in which the API is public. Everything else in
this guide is ordinary; this part is not.

---

## Step 0 — Preflight

Run these on the droplet first. Each one has a decision attached, so do not skip to
Step 1 if any answer surprises you.

```bash
apache2 -v                       # need 2.4.13+ for CGIPassAuth
php -v                           # expect 8.3.x
a2query -m headers               # must be enabled (Step 6 fails without it)
a2query -m rewrite               # /api routing depends on it
php -i | grep session.save_path   # then: ls -ld <that path> — www-data must write to it
sudo mysql -e "SELECT VERSION();"
sudo mysql -e "SHOW GRANTS FOR 'cse135_app'@'localhost';"
```

| Check | Expected | If not |
| --- | --- | --- |
| `apache2 -v` | 2.4.13 or newer | `CGIPassAuth` is unavailable — see Troubleshooting for the `SetEnvIf` fallback |
| `php -v` | 8.3.x | The app uses `readonly` promoted properties (8.1+) and `never` (8.1+); 8.0 will not parse it |
| `a2query -m headers` | `headers (enabled...)` | `sudo a2enmod headers` — Step 6 will not pass configtest otherwise |
| `session.save_path` | exists, `www-data` can write (Ubuntu default `/var/lib/php/sessions`) | Login will appear to succeed and then immediately forget you |
| `SHOW GRANTS` | `... ON \`cse135\`.* TO ...` | A **database-level** grant covers the new tables automatically. If the grant is per-table, you must add `users`, `login_attempts` and `resources` by hand |

That last row is the quiet one. HW3 granted `SELECT, INSERT, UPDATE, DELETE ON
cse135.*`, and a database-level grant picks up tables created later with no action
needed. Confirm it says `cse135.*` and not a list of table names.

---

## Step 1 — Apply the migrations

The deploy workflow syncs `src/sql/` to `~/cse135/sql/` but deliberately never runs
it. Applying DDL automatically on every push is how you lose a database.

Migrations need DDL rights, so they run as root — `cse135_app` holds only
`SELECT/INSERT/UPDATE/DELETE` by design.

```bash
cd ~/cse135/sql
sudo mysql < 002-host-and-resources.sql
sudo mysql < 003-users.sql
sudo mysql < 004-seed-users.sql
```

All three are **idempotent** — 002 guards every `ALTER` behind an
`information_schema` check, 003 uses `CREATE TABLE IF NOT EXISTS`, and 004 is an
`ON DUPLICATE KEY UPDATE` upsert. Re-running them is a no-op, not an error, which
matters because these are manual steps and manual steps get repeated.

Verify:

```bash
sudo mysql -e "USE cse135; SHOW TABLES;"
# expect: activity, login_attempts, performance, resources, sessions, static, users

sudo mysql -e "USE cse135; SELECT id, username, email, role, is_admin FROM users;"
# expect: grader-admin (super_admin, 1) and grader-basic (analyst, 0)

sudo mysql -e "USE cse135;
  SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA='cse135' AND COLUMN_NAME IN ('host','entry_host','is_synthetic');"
# expect host on static/performance/activity/resources, entry_host + is_synthetic on sessions
```

`is_admin` is a **generated column** derived from `role`. Confirm it tracks:

```bash
sudo mysql -e "USE cse135;
  UPDATE users SET role='viewer' WHERE username='grader-basic';
  SELECT username, role, is_admin FROM users WHERE username='grader-basic';
  UPDATE users SET role='analyst' WHERE username='grader-basic';"
# is_admin must read 0 in the middle line, with no application code involved
```

---

## Step 2 — Deploy the application code

```bash
git push origin main          # GitHub Actions rsyncs each sites/<vhost>/ to its web root
```

Watch the run under the Actions tab. Then on the droplet:

```bash
ls /var/www/reporting.ucsdwrestlingclub.com/
# expect: api/ app/ assets/ reports/ api-test.html index.php login.php logout.php users.php
```

**`index.html` must be gone.** The HW1 placeholder was deleted from the repo and
`rsync --delete` removes it server-side. This matters more than it looks: Ubuntu's
default `DirectoryIndex` lists `index.html` *before* `index.php`, so a leftover stub
would shadow the entire dashboard and serve "CSE 135 HW1 Lives!" to graders. Step 4
pins `DirectoryIndex` explicitly as a second line of defence.

Also confirm the private directory arrived with its `.htaccess`:

```bash
ls -la /var/www/reporting.ucsdwrestlingclub.com/app/.htaccess
```

Note that `src/tools/` is **not** deployed — the workflow syncs only `sites/` and
`src/sql/`. The fixture generator and bias test stay local by design; see Step 7 if
you want them on the droplet.

---

## Step 3 — Verify the guard *before* removing Basic auth

Basic auth is still active, so everything needs `-u grader:...`. That is the point:
you are testing the application's own guard while Apache is still the backstop.

```bash
B=https://reporting.ucsdwrestlingclub.com
G=grader:cse135-shared-spider          # HW1 Basic auth, still in force

# The login page renders (Basic auth challenges first; that is expected here)
curl -s -u "$G" -o /dev/null -w "login page: %{http_code}\n" $B/login.php

# The API still answers for an authorised caller
curl -s -u "$G" -o /dev/null -w "api: %{http_code}\n" $B/api/static
```

Then open `https://reporting.ucsdwrestlingclub.com/login.php` in a browser (you will
get the Basic auth dialog first, then the app's own login form) and **sign in as
`grader-admin`**. Confirm:

- the dashboard renders with charts, not a PHP error
- the **Users** link appears in the navigation
- the load cost report opens

If the dashboard 500s here, stop. Check `/var/log/apache2/reporting_error.log` for
`[cse135/app]` lines — the most likely causes are a missed migration or
`/etc/cse135/db.ini` not being readable by `www-data`.

Do not proceed until a browser session works end to end.

---

## Step 4 — Cut the reporting vhost over

Edit `/etc/apache2/sites-available/reporting.ucsdwrestlingclub.com-le-ssl.conf`.
Back it up first:

```bash
sudo cp /etc/apache2/sites-available/reporting.ucsdwrestlingclub.com-le-ssl.conf \
        ~/reporting-le-ssl.conf.hw3.bak
```

Three edits. Everything else in the file — `ServerName`, logging, the `/api`
rewrite, the certbot `Include` and cert paths — stays exactly as it is.

**(a) Replace the auth block inside `<Directory>`:**

```apache
    <Directory "/var/www/reporting.ucsdwrestlingclub.com">
        Options -Indexes +FollowSymLinks
        AllowOverride All

-       AuthType     Basic
-       AuthName     "Restricted Content"
-       AuthUserFile /etc/apache2/.htpasswd
-       Require      valid-user
+       # Authentication is now the application's job (app/Auth.php,
+       # api/index.php -> requireApiAuth()).
+       Require all granted
+
+       # php-fpm does not receive the Authorization header unless it is passed
+       # explicitly. Without this, HTTP Basic against /api silently never
+       # authenticates: PHP_AUTH_USER is unset and `curl -u` gets 401 no matter
+       # what password is supplied. Apache 2.4.13+.
+       CGIPassAuth On
    </Directory>
```

**(b) Add a deny for the application directory**, immediately after that block:

```apache
    # Application code, not web content. The deploy rsyncs sites/reporting/ into
    # the web root wholesale, so app/ lands under DocumentRoot regardless. It
    # carries its own .htaccess saying the same thing; this is the copy that does
    # not depend on AllowOverride still being set.
    <Directory "/var/www/reporting.ucsdwrestlingclub.com/app">
        Require all denied
    </Directory>
```

**(c) Pin the directory index**, anywhere inside `<VirtualHost>`:

```apache
    DirectoryIndex index.php index.html
```

Optionally also add compression — a dashboard about page weight should not ship
uncompressed:

```apache
    <IfModule mod_deflate.c>
        AddOutputFilterByType DEFLATE text/html text/css application/javascript application/json
    </IfModule>
```

`deploy/apache/reporting.conf.sample` is the finished article if you would rather
diff against a complete file than apply three edits by hand.

Then:

```bash
sudo apache2ctl configtest      # must print: Syntax OK
sudo systemctl reload apache2
```

> Verified: the sample config returns `Syntax OK` on Apache 2.4.58 with `rewrite`,
> `headers`, `deflate` and `ssl` enabled.

---

## Step 5 — Verify anonymously

This is the step that proves the cutover was safe. **Every one of these must pass.**

```bash
B=https://reporting.ucsdwrestlingclub.com

curl -s -o /dev/null -w "anon dashboard : %{http_code} -> %{redirect_url}\n" $B/
# expect 302 -> .../login.php?next=%2F

curl -s -o /dev/null -w "anon api       : %{http_code}\n" $B/api/static
# expect 401  <-- the important one

curl -s -o /dev/null -w "anon report    : %{http_code}\n" $B/reports/page-load-cost.php
# expect 302 -> login

curl -s -o /dev/null -w "anon users     : %{http_code}\n" $B/users.php
# expect 302 -> login

curl -s -o /dev/null -w "app/ source    : %{http_code}\n" $B/app/Auth.php
# expect 403

curl -s -o /dev/null -w "basic-auth api : %{http_code}\n" \
  -u grader-admin:Wrestl3-Admin-2026 $B/api/resources
# expect 200  <-- proves CGIPassAuth is working
```

If `anon api` returns **200**, the guard is not running. Restore the backup from
Step 4 and reload Apache immediately, then work out why before trying again.

If `basic-auth api` returns **401** while a browser session works, `CGIPassAuth` is
not taking effect — see Troubleshooting.

Then in a browser, signed out, confirm you cannot reach any report. Sign in as
`grader-basic` and confirm `/users.php` returns a styled **403**, not a redirect —
that account *is* authenticated, it simply is not allowed, and saying "log in"
would be a lie about what went wrong.

**Also re-check the JS-off path**, since it is a graded requirement: disable
JavaScript entirely and repeat login → dashboard → report → users CRUD. Nothing
should change, because the application ships no JavaScript at all.

---

## Step 6 — Collector vhost: make `collector.js` measurable

`PerformanceResourceTiming` zeroes `transferSize` and every timing detail for a
cross-origin resource unless the serving origin opts in. `collector.js` is served
from the collector vhost and executed on the test site, so without this header it
reports 0 bytes and 0 ms in its own resource-weight report.

That would be a self-serving blind spot: `collector.js` is a synchronous,
render-blocking `<script>` in every instrumented page's `<head>`, so it is a
legitimate candidate answer to "what should we fix".

Add to `/etc/apache2/sites-available/collector.ucsdwrestlingclub.com-le-ssl.conf`,
after the `/log` rewrite:

```apache
    <Files "collector.js">
        Header set Timing-Allow-Origin "https://test.ucsdwrestlingclub.com, https://ucsdwrestlingclub.com"
    </Files>
```

Scoped to the script only. `/log` and `px.gif` are deliberately **not** opted in —
they are instrumentation side-effects rather than page weight, and `collector.js`
filters them out of its own reporting for the same reason.

```bash
sudo apache2ctl configtest && sudo systemctl reload apache2
curl -sI https://collector.ucsdwrestlingclub.com/collector.js | grep -i timing-allow
# expect: Timing-Allow-Origin: https://test.ucsdwrestlingclub.com, https://...
```

> **This is the step that fails if you skipped the `mod_headers` preflight.**
> Verified failure mode, exactly:
> ```
> AH00526: Syntax error on line 93 of .../collector-le-ssl.conf:
> Invalid command 'Header', perhaps misspelled or defined by a module not
> included in the server configuration
> ```
> Fix with `sudo a2enmod headers` and re-run configtest.

---

## Step 7 — Get data into the dashboard

After the migrations the dashboard queries the same `performance` rows as before,
so it will render — but the live database holds only a few dozen real pageviews
from one machine, and `resources` starts empty because resource timing is new.

**Preferred: generate real traffic.** Browse
`https://test.ucsdwrestlingclub.com/` across a few browsers and a phone, clicking
between pages and scrolling. To produce *cold-cache* loads — the ones the report
cares about most — use a private window per visit, or DevTools → Network → Disable
cache. The test vhost still has HW1 Basic auth, but the collector is anonymous by
design, so collection works normally once you are past the dialog.

Confirm it is landing:

```bash
sudo mysql -e "USE cse135;
  SELECT COUNT(*) resources FROM resources;
  SELECT host, COUNT(*) n FROM performance GROUP BY host;"
# resources > 0, and host populated (NULL = rows recorded before this deploy)
```

**Optional: generated traffic at volume.** `src/tools/` is not deployed, so copy the
seeder up if you want it:

```bash
scp src/tools/seed-fixtures/seed.php grader@165.22.133.154:~/
ssh grader@165.22.133.154 'php ~/seed.php --profile=balanced --sessions=25'
```

Every row it writes is flagged `is_synthetic = 1` and tagged in the user agent, so
it can never be mistaken for a real visitor, the dashboard's *Generated traffic*
filter excludes it in one click, and the coverage badge on every panel says it is
included. Use it to demonstrate the platform at volume — not to make the numbers
look better than the evidence supports.

The bias test (`src/tools/verify/bias-test.sh`) **purges synthetic data and reseeds
three times**, so run it against the local devstack, never the droplet.

---

## Rollback

The cutover is a single file, and nothing in Steps 1–3 breaks HW3.

```bash
sudo cp ~/reporting-le-ssl.conf.hw3.bak \
        /etc/apache2/sites-available/reporting.ucsdwrestlingclub.com-le-ssl.conf
sudo apache2ctl configtest && sudo systemctl reload apache2
```

That restores `Require valid-user` over everything, which re-protects the API
immediately. The HW4 application code can stay deployed — it simply sits behind
Basic auth again, exactly as it did during Step 3.

The migrations are additive (new columns, new tables) and nothing in HW3's code
paths reads them, so there is no schema rollback to perform. If you must:

```sql
DROP TABLE resources; DROP TABLE login_attempts; DROP TABLE users;
ALTER TABLE performance DROP COLUMN host;   -- and static, activity
ALTER TABLE sessions DROP COLUMN entry_host, DROP COLUMN is_synthetic;
```

---

## Post-deploy checklist

- [ ] All seven tables present; `grader-admin` and `grader-basic` exist with correct roles
- [ ] `index.html` gone from the reporting web root
- [ ] `configtest` clean, Apache reloaded
- [ ] Anonymous: `/` → 302, `/api/static` → **401**, `/app/Auth.php` → 403
- [ ] `curl -u grader-admin:... /api/resources` → 200
- [ ] Login works by **username** *and* by **email**
- [ ] `grader-basic` gets a 403 on `/users.php`; no Users link in the nav
- [ ] Admin can create → edit → delete a user, with the delete confirmation step
- [ ] Logout renders the confirmation page and the session is actually gone
- [ ] `Timing-Allow-Origin` present on `collector.js`
- [ ] `resources` table receiving rows after real browsing
- [ ] Every page passes <https://validator.w3.org/nu/>
- [ ] Whole flow repeated with **JavaScript disabled**
- [ ] Homepage links to the dashboard, report, login and users pages

---

## Troubleshooting

| Symptom | Cause | Fix |
| --- | --- | --- |
| `curl -u ... /api/...` returns 401 but a browser session works | php-fpm is not receiving the `Authorization` header | Confirm `CGIPassAuth On` is inside the `<Directory>` block and Apache is 2.4.13+. On older Apache use `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1` instead — `basicCredentials()` already reads `HTTP_AUTHORIZATION` and `REDIRECT_HTTP_AUTHORIZATION` for this case |
| Dashboard shows "CSE 135 HW1 Lives!" | Stale `index.html` shadowing `index.php` | `sudo rm /var/www/reporting.ucsdwrestlingclub.com/index.html`; confirm `DirectoryIndex index.php index.html` |
| `configtest`: `Invalid command 'Header'` | `mod_headers` disabled | `sudo a2enmod headers` |
| Login succeeds then immediately bounces back to the form | PHP cannot write its session files | `php -i \| grep session.save_path`, then check that directory is writable by `www-data` |
| Every page 500s, `[cse135/app] cannot read /etc/cse135/db.ini` | Permissions on the credentials file | `sudo chmod 640 /etc/cse135/db.ini && sudo chown root:www-data /etc/cse135/db.ini` |
| `SQLSTATE[42S02] ... 'cse135.users' doesn't exist` | Migration 003 not applied | Re-run Step 1; it is idempotent |
| `SQLSTATE[42S22] Unknown column 'host'` | Migration 002 not applied | Re-run Step 1 |
| Charts render as plain tables | `assets/charts.min.css` missing or 404 | Confirm it deployed; check for a stray `Require` blocking `/assets` |
| Dashboard says "No performance data yet" | Filters exclude everything | Check the *Generated traffic* filter; click **Reset** |
| `resources` stays empty after browsing | Old `collector.js` cached in the browser | Hard-reload the test site; confirm the new collector is live with `curl -s https://collector.ucsdwrestlingclub.com/collector.js \| grep -c "getEntriesByType('resource')"` — expect 1 |
| Anonymous `/api/static` returns **200** | The guard is not running | **Roll back now** (see above), then check that `api/index.php` contains `requireApiAuth();` and that `app/bootstrap.php` deployed |

---

## Rehearsing any of this

`src/tools/devstack/` brings up MySQL 8 + PHP 8.3 + the application locally:

```bash
./src/tools/devstack/up.sh        # start, migrate, seed
./src/tools/devstack/up.sh --down
```

It emulates the `/api` rewrite and the `app/` deny in `router.php` because PHP's
built-in server has neither `mod_rewrite` nor `.htaccess`. That makes it good for
rehearsing Steps 1, 3, 5 and 7 — and **not** a test of the Apache configuration.
Anything touching `deploy/apache/*.conf.sample` has to be verified on the droplet
with `configtest`.
