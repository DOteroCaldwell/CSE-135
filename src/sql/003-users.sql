-- CSE 135 HW4 — migration 003: application users and login throttling
--
--   sudo mysql < ~/cse135/sql/003-users.sql
--
-- Seed accounts live in 004-seed-users.sql, generated separately, because hashes
-- are environment-specific and credentials churn independently of schema.
--
-- The existing grant is on `cse135.*` at the database level, so these tables are
-- covered automatically — cse135_app needs no new GRANT.

USE cse135;

/* ------------------------------------------------------------------ users -- */
--
-- HW4 asks for "username, email, hashed password, and an admin boolean". HW5 then
-- replaces that boolean with three roles (super admin / analyst / viewer).
--
-- Storing `role` as the source of truth and deriving `is_admin` as a STORED
-- generated column satisfies both at once: HW4's grid gets a real admin boolean
-- column it can read and sort on, HW5's roles are already present, and there is no
-- migration between them. The derived column cannot drift out of sync with role
-- because MySQL computes it -- an UPDATE that sets role='viewer' flips is_admin to
-- 0 with no application code involved.
--
-- Role mapping for HW4's two-tier grading accounts:
--     admin account  -> super_admin  (sees /users)
--     basic account  -> analyst      (sees dashboard + reports, not /users)

CREATE TABLE IF NOT EXISTS users (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username       VARCHAR(64)  NOT NULL,
  email          VARCHAR(255) NOT NULL,
  password_hash  VARCHAR(255) NOT NULL,   -- password_hash(), algorithm-tagged
  role           ENUM('super_admin','analyst','viewer') NOT NULL DEFAULT 'viewer',
  is_admin       TINYINT(1) GENERATED ALWAYS AS (role = 'super_admin') STORED,
  created_at     DATETIME     NOT NULL,
  updated_at     DATETIME         NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

/* -------------------------------------------------------- login throttling -- */
--
-- Keyed on the identifier AS TYPED, not on a resolved user id, so that attempts
-- against usernames that do not exist are throttled too. Enumeration probes spend
-- most of their time on names that miss; throttling only real accounts would leave
-- exactly the wrong half of the attack unthrottled.
--
-- Rows are also the audit trail for "was this account attacked", so failures are
-- kept rather than deleted on success.

CREATE TABLE IF NOT EXISTS login_attempts (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  identifier    VARCHAR(255) NOT NULL,
  client_ip     VARCHAR(45)      NULL,
  succeeded     TINYINT(1)   NOT NULL DEFAULT 0,
  attempted_at  DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_la_identifier (identifier, attempted_at),
  KEY idx_la_ip (client_ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
