# HW3 Part 2 — Enriched Logging & Client Hints on Apache

Goal: the test vhost writes an access log that carries far more than COMBINED —
User-Agent Client Hints (device, platform, architecture, network) plus a stable
session id that will later join these log lines to the collector's database rows.

Everything here happens **on the droplet**. The only repo artifact is
[`deploy/apache/test.conf.sample`](../../deploy/apache/test.conf.sample), which is
the finished config this guide walks you through.

Deliverable: **`log-verify.jpg`** — a screenshot of a log snippet showing the
enriched fields.

> The sample config was syntax-checked and run against Apache 2.4 before being
> committed: the headers below are verified to render correctly on the wire, and the
> `LogFormat` is verified to emit populated hint columns and a stable `sid`.

---

## Background: how Client Hints actually work

Client Hints replace User-Agent string sniffing with a negotiation. The important
mechanics, because they explain every step below:

1. **The browser sends nothing extra by default.** Only three "low entropy" hints
   ship unrequested: `Sec-CH-UA`, `Sec-CH-UA-Mobile`, `Sec-CH-UA-Platform`. The
   interesting ones — OS version, CPU architecture, device model, viewport, network
   quality — are "high entropy" and withheld until the origin asks.

2. **The origin asks with `Accept-CH`.** A response header listing the hints it
   wants. The browser remembers this per-origin and attaches them to *subsequent*
   requests.

3. **"Subsequent" is the catch.** The request that carried the `Accept-CH` response
   is already over. A first-time visitor's landing request is logged without hints.
   `Critical-CH` fixes this: Chromium sees it, discards the response, and
   immediately retries the same request with the hints attached.

4. **Hints are origin-scoped.** `test.` asking for hints does nothing for the beacon
   sent to `collector.` — a different origin. `Permissions-Policy` delegation is
   what extends them across our vhosts.

5. **Secure context required.** No HTTPS, no hints. Certbot has us covered.

6. **Chromium only.** Firefox and Safari have not shipped UA Client Hints. Their
   log lines will show `-` in every hint column. This is expected — take the
   verification screenshot in Chrome or Edge.

---

## Step 0 — Two pre-existing bugs to fix first

Reading the live vhost turned up two problems that predate HW3. Both matter here,
and both pass `apachectl configtest` without complaint.

**1. Basic auth is not actually applied — the test site is open right now.**
The live config reads:

```apache
<Directory "var/www/test.ucsdwrestlingclub.com">   # <-- no leading slash
```

Apache resolves a relative `Directory` path against `ServerRoot`, so that block
governs `/etc/apache2/var/www/test.ucsdwrestlingclub.com`, which does not exist.
The `Require valid-user` inside it never governs anything. Verified against Apache
2.4 locally: with the relative path an anonymous `GET /` returns **200**; with the
leading slash restored it returns **401**, and `401 → 200` with credentials.

This also means HW1's README claim that all four vhosts are password-protected is
currently false for `test.`. Adding the slash fixes it.

**2. HTTP is never redirected to HTTPS.** Certbot's redirect block was copied from
the main site and still names it:

```apache
RewriteCond %{SERVER_NAME} =ucsdwrestlingclub.com     # <-- not test.
```

`=` is an exact string match, and `%{SERVER_NAME}` is the request's `Host:` header
(`UseCanonicalName` defaults to `Off`), so on this vhost it is always
`test.ucsdwrestlingclub.com` and the condition never fires. Verified locally:
`Host: test.example.com` → 200, `Host: example.com` → 301. Since Client Hints
require a secure context, every plain-HTTP visitor silently gets no hints at all.

Both fixes are marked `BUGFIX:` in `deploy/apache/test.conf.sample`.

## Step 1 — Enable the modules

```bash
sudo a2enmod headers usertrack
sudo systemctl restart apache2
apachectl -M | grep -E 'headers|usertrack'
```

Expect both `headers_module` and `usertrack_module`. `mod_headers` is likely
already on from HW1's spoofed `Server` header; `usertrack` almost certainly is not.

## Step 2 — Install the log format at server scope

The `combined_ch` nickname goes in `conf-available`, **not** in the vhost file:

```bash
sudo cp /path/to/repo/deploy/apache/cse135-logformat.conf.sample \
        /etc/apache2/conf-available/cse135-logformat.conf
sudo a2enconf cse135-logformat
sudo apachectl configtest && sudo systemctl reload apache2
```

This is not stylistic. `apache2.conf` parses `conf-enabled/` before
`sites-enabled/`, and within `sites-enabled` the glob sorts in C order — which puts
certbot's `test.ucsdwrestlingclub.com-le-ssl.conf` (`-` is `0x2D`) **before**
`test.ucsdwrestlingclub.com.conf` (`.` is `0x2E`). A `LogFormat` defined in the base
vhost file is therefore not yet defined when the SSL vhost that uses it is parsed.

The resulting failure is silent and easy to miss: Apache does not error on an
unknown nickname, it treats the nickname as a **literal format string**. Verified
locally — `configtest` prints `Syntax OK` and every access-log line becomes the
single word `combined_ch`. If you ever see that in a log, this is why.

## Step 3 — Merge the HW3 blocks into the vhosts

**Do not overwrite either vhost file.** `deploy/apache/test.conf.sample` is the live
config with the changes folded in and marked `HW3:` / `BUGFIX:`; diff against it.

```bash
cd /etc/apache2/sites-available
sudo cp test.ucsdwrestlingclub.com.conf{,.pre-hw3.bak}
sudo cp test.ucsdwrestlingclub.com-le-ssl.conf{,.pre-hw3.bak}
diff test.ucsdwrestlingclub.com.conf /path/to/repo/deploy/apache/test.conf.sample
```

**In the `:80` vhost** — apply both Step 0 bugfixes and switch to the per-vhost
`test_error.log` / `test_access.log` (it currently writes into the shared
`error.log` / `access.log` alongside all four vhosts, which makes both the
`log-verify` screenshot and any later parsing much harder than it needs to be).

**In the `-le-ssl.conf` `:443` vhost** — apply the same two bugfixes and the same
`CustomLog`, then paste in the Client Hints and `mod_usertrack` blocks from the
bottom of `test.conf.sample`. **This is the vhost that matters:** hints need a
secure context, and once the redirect is fixed the `:80` vhost serves nothing but
a 301. Putting the hint headers only in `:80` is a no-op.

```bash
sudo apachectl configtest
sudo systemctl reload apache2
```

`configtest` must print `Syntax OK`. The `LogFormat` uses backslash line
continuations; if you retyped it rather than copying, suspect that first.

If the diff shows the live vhost has drifted from the sample in other ways worth
keeping, update the sample in the repo — it is meant to be the checked-in record of
what the server actually runs.

## Step 4 — Verify the response headers

First confirm the two Step 0 bugfixes actually took:

```bash
# auth is now enforced: expect 401 then 200
curl -s -o /dev/null -w '%{http_code}\n' https://test.ucsdwrestlingclub.com/
curl -s -o /dev/null -w '%{http_code}\n' -u grader:PASSWORD https://test.ucsdwrestlingclub.com/

# http now redirects: expect 301
curl -s -o /dev/null -w '%{http_code}\n' http://test.ucsdwrestlingclub.com/
```

Then the headers. Note the `-u` — without credentials you get a 401, and while the
`Header always set` directives still apply to error responses, `mod_usertrack` runs
too late in the request cycle to stamp a cookie on one, so `Set-Cookie` would be
missing and look like a failure:

```bash
curl -sI -u grader:PASSWORD https://test.ucsdwrestlingclub.com/ \
  | grep -iE 'accept-ch|critical-ch|permissions-policy|set-cookie'
```

Expect four lines: the long `Accept-CH` list, the short `Critical-CH` list, the
`Permissions-Policy` delegation, and `Set-Cookie: sid=...; domain=.ucsdwrestlingclub.com`.

The `sid` value looks like `275cd3e.65aa019644a84` — `mod_usertrack`'s own format,
a random hex chunk followed by the request timestamp in hex microseconds. It is
opaque but unique, and Part 3's collector consumes it as-is.

## Step 5 — Verify the log

Client Hints do not appear under `curl`, which sends none. Use a real Chromium
browser, and log in when prompted — with auth now working you will actually get the
basic-auth dialog, and `%u` will read `grader` in the log rather than `-`.

```bash
sudo tail -f /var/log/apache2/test_access.log
```

Load `https://test.ucsdwrestlingclub.com/` in Chrome, then click through to
another page. Read the tail:

- The **landing request** should already show `platform_version`, `arch`, `model`
  populated — that is `Critical-CH` doing its job. If they are `-` on the first line
  but populated afterwards, `Critical-CH` is not being applied; the rest still works.
- **Every** line of the visit, including the CSS/JS/image subrequests, should carry
  the *same* `sid=` value. That stability is the whole point.
- `ect`, `downlink`, `rtt` are Chromium's network hints and may legitimately be `-`
  on a fast desktop connection.

Confirm the error log exists and is being written:

```bash
sudo tail -n 20 /var/log/apache2/test_error.log
```

The Wrecked Tech site references some assets that do not exist, so 404 noise here
is expected and is itself useful proof that error logging works.

## Step 6 — Capture `log-verify.jpg`

Screenshot a few lines of `test_access.log` with the hint columns and `sid`
populated. Widen the terminal or pipe through `sed 's/ /\n/g'` if the lines wrap
unreadably. Save into `docs/writeups/hw3/`.

---

## Before Part 3: basic auth and the collector vhost

HW1 put `Require valid-user` over the DocumentRoot of **all four** vhosts, not just
the team site. That is fine for three of them and **fatal for the collector**.

**Leave auth on `test.`** — it does not interfere with collection. `collector.js` is
a cross-origin subresource served from a different vhost, so the test site's auth
wall is irrelevant to it. Graders already have the credentials from the HW1 README,
and `%u` in the access log will helpfully read `grader` instead of `-`.

**The collector vhost must serve three paths anonymously.** If it does not:

- `<script src="https://collector.…/collector.js">` returns 401, and the browser
  will not prompt for credentials on a cross-origin subresource — Chrome suppresses
  those prompts deliberately. The script silently never loads and **nothing is
  collected at all.**
- `navigator.sendBeacon()` cannot carry an `Authorization` header and cannot prompt.
  Every beacon to `/log` gets a 401 and the payload is discarded.

This is not something the collector can work around, so the carve-out is required.
Part 4's `collector.conf.sample` will contain it; the shape is:

```apache
<Directory /var/www/collector.ucsdwrestlingclub.com>
    AuthType      Basic
    AuthName      "Restricted Content"
    AuthUserFile  /etc/apache2/.htpasswd
    Require       valid-user
</Directory>

# ...but the collection surface is public by necessity.
<Files "collector.js">
    Require all granted
</Files>
<Files "nojs.gif">
    Require all granted
</Files>
<Location "/log">
    Require all granted
</Location>
```

The vhost's `index.html` stays protected, so the "all vhosts are password
protected" claim from HW1 still holds for everything except the three endpoints
that are inherently public. Worth a sentence in the HW3 README — a collector
endpoint that requires a password is a collector endpoint that collects nothing.

**`reporting.` keeps its auth.** The REST API is reachable with `curl -u grader:…`
for the `REST.png` screenshot, and `api-test.html` is same-origin so the browser
sends credentials once you have authenticated to that vhost. HW5 replaces this with
real role-based auth anyway.

---

## Notes and gotchas

**GoAccess.** HW1's `report.html` parses COMBINED. `combined_ch` is a superset, so
GoAccess needs to be told the new shape or it will skip lines. Either point it at
the format explicitly with `--log-format`, or keep a second plain-`combined`
`CustomLog` alongside for GoAccess to chew on. The latter is less work.

**Do not enable `usertrack` globally.** Scope it to the test vhost. Stamping a
tracking cookie on every response of the main team site is not wanted and could
interfere with the HW2 PHP session demos.

**`CookieDomain` needs its leading dot.** `.ucsdwrestlingclub.com` — Apache rejects
the value without it, and the dot is what makes the cookie visible to the collector
vhost.

**Hint values arrive pre-quoted.** Client Hints are structured-header strings, so
`Sec-CH-UA-Platform` is literally `"macOS"` *including* the double quotes. Logged
inside our own quoted field they come out escaped:

```
platform="\"macOS\"" platform_version="\"15.6.0\"" arch="\"arm\""
```

That is correct, not a bug — but anything parsing these columns later must strip the
inner quotes. Booleans (`Sec-CH-UA-Mobile: ?0`) and numbers (`DPR: 2`) are unquoted.

**Hints are a moving target.** The `Accept-CH` list above reflects what Chromium
ships today. Anything the browser does not recognize is simply ignored, so an
over-broad list is harmless.

---

## Next

Part 3 builds `collector.js`, which reads the `sid` cookie this config mints and
sends it with every payload — closing the loop between these access-log lines and
the database rows.
