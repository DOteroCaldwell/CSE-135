-- CSE 135 HW4 — migration 004: grader accounts
--
--   sudo mysql < ~/cse135/sql/004-seed-users.sql
--
-- These two accounts are the ones named in README.md / GRADER.md, so their
-- passwords are intentionally public. They exist to be handed to a grader.
--
-- Re-running RESETS both passwords to the documented values. That is deliberate:
-- if an account is changed during grading, replaying this file restores the state
-- the submission describes.
--
-- Hashes are password_hash(..., PASSWORD_DEFAULT) — bcrypt on PHP 8.3. The
-- plaintext is never stored, and Auth::attempt() transparently re-hashes on login
-- if the default algorithm or cost later changes.
--
--   grader-admin / Wrestl3-Admin-2026   role super_admin  (sees /users.php)
--   grader-basic / Wrestl3-Basic-2026   role analyst      (dashboard + reports only)

-- ---------------------------------------------------------------------------
-- BEFORE THIS SITE GOES PUBLIC: DELETE THESE ACCOUNTS.
--
-- ucsdwrestlingclub.com becomes the club's real homepage after the course. Two
-- administrator accounts whose passwords are published in a README are fine for
-- a graded prototype and are a standing compromise of a live site.
--
--   DELETE FROM users WHERE username IN ('grader-admin','grader-basic');
--
-- Create a real administrator first, or you will lock yourself out — users.php
-- refuses to remove the last super_admin.
-- ---------------------------------------------------------------------------

USE cse135;

INSERT INTO users (username, email, password_hash, role, created_at) VALUES
  ('grader-admin', 'grader-admin@ucsdwrestlingclub.com',
   '$2y$10$VFUb16k8EOz4kCtavm2Erey85BlBCRdiYYeK49jN0nNpVlIFYEUSG',
   'super_admin', UTC_TIMESTAMP()),
  ('grader-basic', 'grader-basic@ucsdwrestlingclub.com',
   '$2y$10$J47RxaXEaW19HGvwbEzdRO1bIVGWYIbWCxcubaypdbclAfojF2vl6',
   'analyst', UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE
  password_hash = VALUES(password_hash),
  role          = VALUES(role),
  email         = VALUES(email),
  updated_at    = UTC_TIMESTAMP();
