-- CSE 135 HW5 — migration 007: analyst section scoping and saved reports
--
--   sudo mysql < ~/cse135/sql/007-sections-and-saved-reports.sql
--
-- Applies on top of 003 (users). Requires DDL rights, so run it as root via
-- `sudo mysql`; cse135_app holds only DML and needs no new GRANT because the
-- existing grant is on cse135.*.
--
-- Idempotent: CREATE TABLE IF NOT EXISTS, and the backfill only touches analysts
-- that have no section rows yet. Re-running after an analyst has been deliberately
-- scoped leaves that scoping alone.

USE cse135;

/* ---------------------------------------------------------- user_sections -- */
--
-- HW5: "an analyst may look at a defined set of sections." One row per
-- (analyst, section). The rules the application enforces:
--
--   super_admin  ignores this table — sees everything, always
--   analyst      sees exactly the sections listed here; none listed => no live
--                reports, stated on screen rather than silently widened
--   viewer       never reaches a live report; this table is not consulted
--
-- The section names are owned by app/Sections.php, not by an ENUM here, so adding
-- a fourth report category is a code change and not a migration.

CREATE TABLE IF NOT EXISTS user_sections (
  user_id   INT UNSIGNED NOT NULL,
  section   VARCHAR(32)  NOT NULL,
  PRIMARY KEY (user_id, section),
  CONSTRAINT fk_us_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Backfill: every analyst that exists today keeps the access they had (all
-- sections). Only analysts with NO rows are touched, so this is safe to re-run.
INSERT IGNORE INTO user_sections (user_id, section)
SELECT u.id, s.section
  FROM users u
  JOIN (SELECT 'performance' AS section
        UNION ALL SELECT 'behaviour'
        UNION ALL SELECT 'audience') s
 WHERE u.role = 'analyst'
   AND NOT EXISTS (SELECT 1 FROM user_sections us WHERE us.user_id = u.id);

/* ---------------------------------------------------------- saved_reports -- */
--
-- A saved report is a titled, filtered view of one report page, with the rendered
-- HTML captured at save time. That makes it static by construction: a viewer
-- opening it next month sees what the analyst saw, not whatever the data has
-- become. `query` and `report` are kept so the live version is one click away.
--
-- created_by_name is denormalised on purpose: deleting the analyst must not delete
-- (or anonymise) the reports they published.

CREATE TABLE IF NOT EXISTS saved_reports (
  id                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  title             VARCHAR(160)  NOT NULL,
  section           VARCHAR(32)   NOT NULL,
  report            VARCHAR(64)   NOT NULL,   -- slug in app/Reports.php
  query             VARCHAR(1000) NOT NULL DEFAULT '',
  note              TEXT              NULL,   -- the analyst's comment at save time
  html              MEDIUMTEXT    NOT NULL,   -- rendered report body
  pdf_path          VARCHAR(255)      NULL,   -- outside every web root
  pdf_bytes         INT UNSIGNED      NULL,
  pdf_generated_at  DATETIME          NULL,
  created_by        INT UNSIGNED      NULL,
  created_by_name   VARCHAR(64)   NOT NULL,
  created_at        DATETIME      NOT NULL,
  PRIMARY KEY (id),
  KEY idx_sr_section (section),
  KEY idx_sr_created (created_at),
  CONSTRAINT fk_sr_user FOREIGN KEY (created_by)
    REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
