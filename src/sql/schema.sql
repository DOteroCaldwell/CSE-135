-- CSE 135 HW3 — analytics schema (MySQL 8 / InnoDB)
--
--   mysql -u root -p < schema.sql
--
-- Four tables mirroring the three data categories the assignment names, plus a
-- sessions table they all hang off. session_id is the join key, and it is the
-- SAME value mod_usertrack writes into the test vhost's access log as %{cookie}n
-- — that is what ties collector data back to server logs.

CREATE DATABASE IF NOT EXISTS cse135
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE cse135;

-- ---------------------------------------------------------------- sessions --
CREATE TABLE IF NOT EXISTS sessions (
  session_id      VARCHAR(64)  NOT NULL,
  -- 'server'  = read from the mod_usertrack cookie (the normal path)
  -- 'client'  = collector.js minted a UUID because the cookie was missing
  -- 'client-nocookie' = minted, but the cookie could not be stored
  sid_source      VARCHAR(32)      NULL,
  first_seen      DATETIME     NOT NULL,
  last_seen       DATETIME     NOT NULL,
  entry_page      VARCHAR(512)     NULL,
  client_ip       VARCHAR(45)      NULL,   -- 45 = max INET6_ADDRSTRLEN
  user_agent      VARCHAR(512)     NULL,
  payload_count   INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (session_id),
  KEY idx_sessions_first_seen (first_seen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------------ static --
-- One row per pageview. The eight fields the spec enumerates get real columns;
-- everything else the collector gathers is kept in `raw` so nothing is lost.
CREATE TABLE IF NOT EXISTS `static` (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id        VARCHAR(64)  NOT NULL,
  pageview_id       VARCHAR(64)      NULL,
  page              VARCHAR(512)     NULL,
  user_agent        TEXT             NULL,
  language          VARCHAR(64)      NULL,
  cookies_enabled   TINYINT(1)       NULL,
  js_enabled        TINYINT(1)       NULL,
  images_enabled    TINYINT(1)       NULL,
  css_enabled       TINYINT(1)       NULL,
  screen_width      INT              NULL,
  screen_height     INT              NULL,
  window_width      INT              NULL,
  window_height     INT              NULL,
  connection_type   VARCHAR(32)      NULL,
  raw               JSON             NULL,
  client_sent_at    BIGINT           NULL,   -- JS Date.now(), milliseconds
  server_ts         DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_static_session (session_id),
  KEY idx_static_server_ts (server_ts),
  CONSTRAINT fk_static_session FOREIGN KEY (session_id)
    REFERENCES sessions (session_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------- performance --
CREATE TABLE IF NOT EXISTS performance (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id        VARCHAR(64)  NOT NULL,
  pageview_id       VARCHAR(64)      NULL,
  page              VARCHAR(512)     NULL,
  -- relative to timeOrigin; load_start_ms is 0 by definition of the nav entry
  load_start_ms     DOUBLE           NULL,
  load_end_ms       DOUBLE           NULL,
  -- the same two moments as wall-clock epoch ms, which is what reports want
  load_start_epoch  BIGINT           NULL,
  load_end_epoch    BIGINT           NULL,
  total_load_ms     BIGINT           NULL,   -- computed by the collector
  timing_source     VARCHAR(48)      NULL,
  nav_timing        JSON             NULL,   -- the whole timing object
  client_sent_at    BIGINT           NULL,
  server_ts         DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_perf_session (session_id),
  KEY idx_perf_server_ts (server_ts),
  CONSTRAINT fk_perf_session FOREIGN KEY (session_id)
    REFERENCES sessions (session_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------- activity --
-- One row per event. The columns that reports filter and aggregate on are broken
-- out; the per-event remainder stays in `detail` so new event types added in HW4
-- or HW5 need no migration.
CREATE TABLE IF NOT EXISTS activity (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id        VARCHAR(64)  NOT NULL,
  pageview_id       VARCHAR(64)      NULL,
  page              VARCHAR(512)     NULL,
  event_type        VARCHAR(32)  NOT NULL,
  occurred_at       BIGINT           NULL,   -- JS epoch ms
  pos_x             INT              NULL,
  pos_y             INT              NULL,
  scroll_x          INT              NULL,
  scroll_y          INT              NULL,
  mouse_button      TINYINT          NULL,
  key_name          VARCHAR(32)      NULL,   -- masked for editable fields
  idle_duration_ms  BIGINT           NULL,
  error_message     VARCHAR(1000)    NULL,
  detail            JSON             NULL,
  client_sent_at    BIGINT           NULL,
  server_ts         DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_activity_session (session_id),
  KEY idx_activity_type (event_type),
  KEY idx_activity_occurred (occurred_at),
  KEY idx_activity_server_ts (server_ts),
  CONSTRAINT fk_activity_session FOREIGN KEY (session_id)
    REFERENCES sessions (session_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
