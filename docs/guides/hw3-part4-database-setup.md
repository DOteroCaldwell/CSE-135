# HW3 Part 4 — Database & the `/log` Ingestion Endpoint

Stands up MySQL, creates the schema, and wires `collector.js` to it through a PHP
endpoint at `/log` on the collector vhost.

Repo artifacts:

- [`src/hw3/sql/schema.sql`](../../src/hw3/sql/schema.sql) — the four tables
- [`sites/collector/log.php`](../../sites/collector/log.php) — the endpoint
- [`src/hw3/config/db.ini.example`](../../src/hw3/config/db.ini.example) — credentials template
- [`deploy/apache/collector.conf.sample`](../../deploy/apache/collector.conf.sample) — `/log` rewrite + auth carve-out

Deliverable: **`database-verify.jpg`** — a screenshot of collector data in a table.

> The schema and `log.php` were tested against a real MySQL 8 before being
> committed, by replaying payloads captured from a live headless-Chrome session.
> Numbers quoted below come from that run.

---

## The shape of the data

One `sessions` row per visit; the other three tables mirror the three categories
the assignment names, all keyed by `session_id`.

```
sessions ──┬── static       (1 row per pageview: the 8 required fields + raw JSON)
           ├── performance  (1 row per pageview: whole timing object + total ms)
           └── activity     (1 row per event: hot columns + detail JSON)
```

`session_id` is the crux of the assignment's "Challenging Point". It is not a value
the collector invents — it is the **same string `mod_usertrack` wrote into the test
vhost's access log** as `%{cookie}n` in Part 2. So a log line and a database row
carrying `sid="srv1a06b55d18a"` are provably the same visit, and reconciling
server-side logs with client-side collection is a plain equality join.

Two design notes worth repeating in the write-up:

- **Hot columns plus a JSON column.** The fields reports filter on get real,
  indexed columns; the rest of each payload is kept in `raw` / `detail` / `nav_timing`.
  Nothing the collector gathers is discarded, and new event types in HW4/HW5 need
  no migration.
- **`ON DELETE CASCADE`** on all three child tables, so HW5's CRUD can delete a
  session without orphaning rows. Verified: deleting one session removed all 35
  dependent rows.

## Step 1 — MySQL

HW1 already installed and secured MySQL. Confirm:

```bash
systemctl status mysql --no-pager
mysql --version
```

## Step 2 — Schema and a least-privilege user

`schema.sql` creates the database and tables but deliberately creates **no user**,
so no password is ever committed.

```bash
mysql -u root -p < /path/to/repo/src/hw3/sql/schema.sql
mysql -u root -p -e "SHOW TABLES IN cse135;"
```

Expect `activity`, `performance`, `sessions`, `static`.

The app account gets only what the endpoint and the API actually need — no DDL, no
access to other schemas:

```sql
CREATE USER 'cse135_app'@'localhost' IDENTIFIED BY 'a-strong-password-here';
GRANT SELECT, INSERT, UPDATE, DELETE ON cse135.* TO 'cse135_app'@'localhost';
FLUSH PRIVILEGES;
```

`UPDATE` and `DELETE` are for Part 5's `PUT`/`DELETE` routes.

## Step 3 — Credentials, outside the repo and outside the docroot

```bash
sudo mkdir -p /etc/cse135
sudo cp /path/to/repo/src/hw3/config/db.ini.example /etc/cse135/db.ini
sudo nano /etc/cse135/db.ini          # set pass, confirm host/name/user
sudo chown root:www-data /etc/cse135/db.ini
sudo chmod 640 /etc/cse135/db.ini
```

`640 root:www-data` means Apache can read it and nothing else can. It lives outside
every document root, so it is unreachable over HTTP no matter how the vhost is
configured.

## Step 4 — PHP on the collector vhost

`.php` handling is normally global via `conf-enabled/php*-fpm.conf`, so nothing
vhost-specific is needed. Confirm the driver is present — `pdo_mysql` is a separate
package from PHP itself and its absence is the most common failure here:

```bash
php -m | grep -i pdo_mysql || sudo apt install php-mysql
sudo systemctl reload apache2
```

## Step 5 — Deploy and wire up `/log`

`log.php` deploys with the rest of `sites/collector/` on push to `main`. The vhost
needs the `/log` rewrite and the auth carve-out from
`deploy/apache/collector.conf.sample` — in particular **both** `<Files "log.php">`
and `<Location "/log">`, since `<Location>` is matched after `mod_rewrite` runs and
stops matching once `/log` becomes `/log.php`.

## Step 6 — Verify

Endpoint behaviour, before involving a browser:

```bash
# 405 — only POST is accepted
curl -s -o /dev/null -w '%{http_code}\n' https://collector.ucsdwrestlingclub.com/log

# 400 — rejects a body that is not a known payload
curl -s -o /dev/null -w '%{http_code}\n' -X POST -d '{"type":"evil"}' \
     https://collector.ucsdwrestlingclub.com/log

# 204 — accepted
curl -s -o /dev/null -w '%{http_code}\n' -X POST \
     -H 'Content-Type: text/plain;charset=UTF-8' \
     -d '{"type":"static","sid":"manual-test","data":{"language":"en-US"}}' \
     https://collector.ucsdwrestlingclub.com/log
```

Then browse the test site in Chrome and check the rows:

```sql
USE cse135;
SELECT session_id, sid_source, entry_page, payload_count FROM sessions
  ORDER BY first_seen DESC LIMIT 5;

SELECT page, language, cookies_enabled, js_enabled, images_enabled, css_enabled,
       CONCAT(screen_width,'x',screen_height) AS screen,
       CONCAT(window_width,'x',window_height) AS win, connection_type
  FROM `static` ORDER BY id DESC LIMIT 5;

SELECT event_type, COUNT(*) FROM activity GROUP BY event_type ORDER BY 2 DESC;
```

The last one is a good `database-verify.jpg`: it shows the whole activity taxonomy
at once. A healthy single-visit run looks roughly like `mousemove` ≫ `keydown` /
`keyup` > `idle` > `click` / `pageenter` / `pageleave`.

**The join that answers the Challenging Point** — the same id on both sides:

```bash
sudo grep -o 'sid="[^"]*"' /var/log/apache2/test_access.log | sort -u | tail -5
```

```sql
SELECT s.session_id, COUNT(DISTINCT st.id) static_rows,
       COUNT(DISTINCT p.id) perf_rows, COUNT(DISTINCT a.id) activity_rows
FROM sessions s
LEFT JOIN `static` st ON st.session_id = s.session_id
LEFT JOIN performance p ON p.session_id = s.session_id
LEFT JOIN activity a ON a.session_id = s.session_id
GROUP BY s.session_id ORDER BY s.first_seen DESC LIMIT 5;
```

## Troubleshooting

Everything the endpoint rejects or fails on is written to
`/var/log/apache2/collector_error.log`, prefixed `[cse135/log]`. The client only
ever sees a bare status code — SQL and config detail are never returned.

```bash
sudo tail -f /var/log/apache2/collector_error.log
```

| Symptom | Cause |
| --- | --- |
| `401` on `/log` | auth carve-out missing; needs `<Files "log.php">`, not just `<Location "/log">` |
| `500`, log says `cannot read /etc/cse135/db.ini` | wrong path, or not readable by `www-data` |
| `500`, log says `db connect failed` | wrong host/password, or `php-mysql` not installed |
| `400 no session id` | the `sid` cookie is missing — check `mod_usertrack` on the *test* vhost |
| `204` but no rows | check `sid_source`; `client-nocookie` means cookies are being blocked |
| Rows appear with `sid_source='client'` | `mod_usertrack` is not stamping; the collector fell back to a minted UUID |

## Next

Part 5 exposes these four tables over REST on the reporting vhost, reusing the same
`/etc/cse135/db.ini` and the same `cse135_app` account.
