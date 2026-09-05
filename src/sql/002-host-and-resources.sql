-- CSE 135 HW4 — migration 002: host dimension, synthetic quarantine, resource timing
--
--   sudo mysql < ~/cse135/sql/002-host-and-resources.sql
--
-- Applies on top of schema.sql (effectively migration 001). Requires DDL rights,
-- so run it as root via `sudo mysql` — the app account (cse135_app) deliberately
-- holds only SELECT/INSERT/UPDATE/DELETE.
--
-- Idempotent: every step is guarded, so re-running is a no-op rather than an error.
-- That matters because applying migrations is a manual step and manual steps get
-- repeated.

USE cse135;

/* ------------------------------------------------------- migration helpers -- */

DELIMITER $$

DROP PROCEDURE IF EXISTS cse135_add_column $$
CREATE PROCEDURE cse135_add_column(IN tbl VARCHAR(64), IN col VARCHAR(64), IN def TEXT)
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = tbl AND COLUMN_NAME = col
  ) THEN
    SET @s = CONCAT('ALTER TABLE `', tbl, '` ADD COLUMN `', col, '` ', def);
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
  END IF;
END $$

DROP PROCEDURE IF EXISTS cse135_add_index $$
CREATE PROCEDURE cse135_add_index(IN tbl VARCHAR(64), IN idx VARCHAR(64), IN cols TEXT)
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = tbl AND INDEX_NAME = idx
  ) THEN
    SET @s = CONCAT('ALTER TABLE `', tbl, '` ADD INDEX `', idx, '` (', cols, ')');
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
  END IF;
END $$

DELIMITER ;

/* ------------------------------------------------------------ host column -- */
--
-- collector.js has always sent `href` in the payload envelope; log.php simply never
-- stored it, so `page` (a bare path) was the only location dimension. That is fine
-- while exactly one site is instrumented and breaks the moment a second one is:
-- test.ucsdwrestlingclub.com/index.html and ucsdwrestlingclub.com/index.html are
-- the same `page`.
--
-- NULL means "recorded before this migration" — i.e. the test vhost. It is left
-- NULL rather than backfilled with a guess so that thin early data stays visibly
-- thin instead of being laundered into looking authoritative.

CALL cse135_add_column('sessions',    'entry_host', 'VARCHAR(255) NULL AFTER entry_page');
CALL cse135_add_column('static',      'host',       'VARCHAR(255) NULL AFTER page');
CALL cse135_add_column('performance', 'host',       'VARCHAR(255) NULL AFTER page');
CALL cse135_add_column('activity',    'host',       'VARCHAR(255) NULL AFTER page');

CALL cse135_add_index('static',      'idx_static_host', '`host`');
CALL cse135_add_index('performance', 'idx_perf_host',   '`host`');
CALL cse135_add_index('activity',    'idx_activity_host', '`host`');

/* --------------------------------------------------- synthetic quarantine -- */
--
-- Load-testing traffic must never blend into the real dataset. Once the club site
-- is live, a report that silently averages synthetic loads with real visitors is
-- worse than no report. Set server-side in log.php, never trusted from a
-- per-payload client field.

CALL cse135_add_column('sessions', 'is_synthetic', 'TINYINT(1) NOT NULL DEFAULT 0');
CALL cse135_add_index('sessions', 'idx_sessions_synthetic', '`is_synthetic`');

/* -------------------------------------------------------------- resources -- */
--
-- One row per subresource per pageview, from PerformanceResourceTiming.
--
-- Why this table exists: navigation timing can size the subresource window
-- (loadEventStart - domContentLoadedEventEnd) but cannot attribute it. Answering
-- "fix ONE thing" needs to name a file, not a phase.
--
-- Volume is the real design pressure here — this table grows ~20-100x faster than
-- `performance`. The collector caps entries per pageview; the indexes below are
-- the ones the aggregation queries actually use (group by name, filter by page or
-- pageview). `name` is indexed by 191-char prefix: enough to discriminate URLs
-- that differ in the path, and safely inside InnoDB's index-length limit.
--
-- transfer_size semantics worth remembering when reading reports:
--     0  with a non-zero decoded_body_size  => served from cache
--     0  with a zero    decoded_body_size   => cross-origin without Timing-Allow-Origin
-- The two are NOT the same thing, and delivery_type disambiguates when present.

CREATE TABLE IF NOT EXISTS resources (
  id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id             VARCHAR(64)  NOT NULL,
  pageview_id            VARCHAR(64)      NULL,
  page                   VARCHAR(512)     NULL,
  host                   VARCHAR(255)     NULL,
  name                   VARCHAR(1000)    NULL,   -- the resource URL
  initiator_type         VARCHAR(32)      NULL,   -- img, script, css, fetch, ...
  start_ms               DOUBLE           NULL,   -- relative to timeOrigin
  duration_ms            DOUBLE           NULL,
  transfer_size          BIGINT           NULL,   -- over the wire (0 => cache or opaque)
  encoded_body_size      BIGINT           NULL,
  decoded_body_size      BIGINT           NULL,
  next_hop_protocol      VARCHAR(32)      NULL,
  render_blocking_status VARCHAR(32)      NULL,   -- blocking | non-blocking
  delivery_type          VARCHAR(32)      NULL,   -- cache | navigational-prefetch | ''
  raw                    JSON             NULL,
  client_sent_at         BIGINT           NULL,
  server_ts              DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_res_session  (session_id),
  KEY idx_res_pageview (pageview_id),
  KEY idx_res_page     (page(191)),
  KEY idx_res_host     (host),
  KEY idx_res_name     (name(191)),
  KEY idx_res_server_ts (server_ts),
  CONSTRAINT fk_res_session FOREIGN KEY (session_id)
    REFERENCES sessions (session_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

/* ---------------------------------------------------------------- cleanup -- */

DROP PROCEDURE IF EXISTS cse135_add_column;
DROP PROCEDURE IF EXISTS cse135_add_index;
