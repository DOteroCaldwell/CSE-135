-- CSE 135 HW4 — migration 006: remove rows created by the REST test console
--
--   sudo mysql < ~/cse135/sql/006-cleanup-test-rows.sql
--
-- ---------------------------------------------------------------------------
-- WHAT THIS CLEANS UP, AND WHY IT EXISTED
-- ---------------------------------------------------------------------------
--
-- Two page values were polluting the dashboard's charts with runaway labels:
--
--   '/updated-<epoch-ms>'
--       Written by api-test.html's PUT demo. Until this was fixed, the console
--       resolved its target with GET ?limit=1 against ORDER BY id DESC — the
--       newest REAL row — so PUT rewrote live analytics and DELETE destroyed it.
--       On /api/sessions that cascaded into every child row of a real visit.
--       Clicking through the console as the HW3 write-up instructs was silently
--       damaging the dataset it was meant to demonstrate.
--
--       api-test.html now creates its own sandbox session (is_synthetic = 1) and
--       only ever mutates rows it created, so no new rows of this shape appear.
--
--   '/Users/...'
--       A filesystem path, from a session where collector.js ran on a page opened
--       over file:// during local testing. Harmless but meaningless as a "page".
--
-- Idempotent: deleting nothing is the expected result on a clean database.
--
-- ---------------------------------------------------------------------------
-- The DELETEs are narrow ON PURPOSE.
--
-- They match page VALUES, never session ids, because these junk rows belong to
-- otherwise-real sessions. Deleting the sessions would take healthy pageviews
-- with them via ON DELETE CASCADE — trading a cosmetic problem for data loss.
-- ---------------------------------------------------------------------------

USE cse135;

-- Preview first. Read this output before trusting the counts below it.
SELECT 'performance' AS tbl, page, COUNT(*) AS rows_to_delete
  FROM performance
 WHERE page LIKE '/updated-%' OR page LIKE '/Users/%' OR page = '/api-test-updated'
 GROUP BY page
UNION ALL
SELECT 'static', page, COUNT(*) FROM `static`
 WHERE page LIKE '/updated-%' OR page LIKE '/Users/%' OR page = '/api-test-updated'
 GROUP BY page
UNION ALL
SELECT 'activity', page, COUNT(*) FROM activity
 WHERE page LIKE '/updated-%' OR page LIKE '/Users/%' OR page = '/api-test-updated'
 GROUP BY page
UNION ALL
SELECT 'resources', page, COUNT(*) FROM resources
 WHERE page LIKE '/updated-%' OR page LIKE '/Users/%' OR page = '/api-test-updated'
 GROUP BY page;

DELETE FROM performance
 WHERE page LIKE '/updated-%' OR page LIKE '/Users/%' OR page = '/api-test-updated';

DELETE FROM `static`
 WHERE page LIKE '/updated-%' OR page LIKE '/Users/%' OR page = '/api-test-updated';

DELETE FROM activity
 WHERE page LIKE '/updated-%' OR page LIKE '/Users/%' OR page = '/api-test-updated';

DELETE FROM resources
 WHERE page LIKE '/updated-%' OR page LIKE '/Users/%' OR page = '/api-test-updated';

-- Sandbox sessions the console created. Safe to cascade: everything hanging off
-- an 'apitest-%' session was created by the console too.
DELETE FROM sessions WHERE session_id LIKE 'apitest-%';

SELECT CONCAT('remaining junk rows: ', (
  SELECT COUNT(*) FROM performance
   WHERE page LIKE '/updated-%' OR page LIKE '/Users/%'
)) AS result;
