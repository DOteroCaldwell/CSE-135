-- CSE 135 — migration 008: ucsdwrestlingclub.com -> tritonwrestling.com
--
--   sudo mysqldump cse135 > ~/cse135-pre-008-$(date +%F).sql      # back up first
--   sudo mysql < ~/cse135/sql/008-domain-rename.sql
--
-- Run AFTER the old domain's vhosts have been flipped to redirects, so no new
-- old-domain rows can arrive once this has run.
--
-- Rewrites the host dimension (and the few other columns that carry the domain)
-- so the dashboard's host filter shows one continuous host per site instead of
-- splitting history across two domains.
--
-- Deliberately NOT touched: the raw JSON payload columns (static.raw,
-- performance.nav_timing, activity.detail, resources.raw). They are the archival
-- record of what the browser actually sent.
--
-- Idempotent: every UPDATE is guarded by LIKE, so a re-run matches nothing.

USE cse135;

UPDATE sessions    SET entry_host = REPLACE(entry_host, 'ucsdwrestlingclub.com', 'tritonwrestling.com')
 WHERE entry_host LIKE '%ucsdwrestlingclub.com%';

UPDATE `static`    SET host = REPLACE(host, 'ucsdwrestlingclub.com', 'tritonwrestling.com')
 WHERE host LIKE '%ucsdwrestlingclub.com%';

UPDATE performance SET host = REPLACE(host, 'ucsdwrestlingclub.com', 'tritonwrestling.com')
 WHERE host LIKE '%ucsdwrestlingclub.com%';

UPDATE activity    SET host = REPLACE(host, 'ucsdwrestlingclub.com', 'tritonwrestling.com')
 WHERE host LIKE '%ucsdwrestlingclub.com%';

-- resources.host is the host the resource was fetched FROM (e.g. collector.*).
UPDATE resources   SET host = REPLACE(host, 'ucsdwrestlingclub.com', 'tritonwrestling.com')
 WHERE host LIKE '%ucsdwrestlingclub.com%';

UPDATE users       SET email = REPLACE(email, 'ucsdwrestlingclub.com', 'tritonwrestling.com')
 WHERE email LIKE '%ucsdwrestlingclub.com%';

-- Saved reports keep their filter query (may include host=...) and a rendered
-- HTML snapshot (footer names the reporting host). Already-generated PDFs under
-- /var/lib/cse135/exports are not rewritten; re-export if the footer matters.
UPDATE saved_reports SET query = REPLACE(query, 'ucsdwrestlingclub.com', 'tritonwrestling.com')
 WHERE query LIKE '%ucsdwrestlingclub.com%';

UPDATE saved_reports SET html = REPLACE(html, 'ucsdwrestlingclub.com', 'tritonwrestling.com')
 WHERE html LIKE '%ucsdwrestlingclub.com%';

-- Verification: every count should be 0.
SELECT 'sessions'    AS tbl, COUNT(*) AS remaining FROM sessions    WHERE entry_host LIKE '%ucsdwrestlingclub.com%'
UNION ALL SELECT 'static',        COUNT(*) FROM `static`      WHERE host  LIKE '%ucsdwrestlingclub.com%'
UNION ALL SELECT 'performance',   COUNT(*) FROM performance   WHERE host  LIKE '%ucsdwrestlingclub.com%'
UNION ALL SELECT 'activity',      COUNT(*) FROM activity      WHERE host  LIKE '%ucsdwrestlingclub.com%'
UNION ALL SELECT 'resources',     COUNT(*) FROM resources     WHERE host  LIKE '%ucsdwrestlingclub.com%'
UNION ALL SELECT 'users',         COUNT(*) FROM users         WHERE email LIKE '%ucsdwrestlingclub.com%'
UNION ALL SELECT 'saved_reports', COUNT(*) FROM saved_reports WHERE query LIKE '%ucsdwrestlingclub.com%'
                                                                 OR html  LIKE '%ucsdwrestlingclub.com%';
