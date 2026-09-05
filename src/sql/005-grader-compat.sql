-- CSE 135 HW4 — migration 005: HW3 grader compatibility account
--
--   sudo mysql < ~/cse135/sql/005-grader-compat.sql
--
-- ---------------------------------------------------------------------------
-- WHY THIS EXISTS
-- ---------------------------------------------------------------------------
--
-- Through HW3, /api/* was protected only by the reporting vhost's
-- `Require valid-user`, and the HW3 write-up tells graders to reach it with:
--
--     curl -u grader:cse135-shared-spider https://reporting.../api/static
--
-- HW4 moves authentication into the application, which authenticates against
-- this `users` table rather than /etc/apache2/.htpasswd. `grader` exists only in
-- the htpasswd file, so once the vhost's Basic auth is lifted that documented
-- command starts returning 401 — a submitted deliverable's instructions silently
-- stop being true, while the site stays live through HW5.
--
-- This adds `grader` as a real application user with the SAME password, so the
-- HW3 instructions keep working verbatim. It is a compatibility shim, not part
-- of the HW4 design.
--
-- ROLE: analyst, deliberately, not super_admin.
--   HW3 had no notion of user management, so nothing in its documented flow needs
--   it. `analyst` grants exactly what the HW3 path used — the API, the dashboard
--   and the reports — and leaves /users.php refusing with a 403. Granting admin
--   "to be safe" would hand a compatibility account more authority than the thing
--   it is compatible with ever had.
--
-- ---------------------------------------------------------------------------
-- CREDENTIAL REUSE — READ THIS BEFORE THE SITE GOES PUBLIC
-- ---------------------------------------------------------------------------
--
-- `cse135-shared-spider` is already the SSH password for the `grader` system
-- account AND the HTTP Basic password on the main and test vhosts. This migration
-- extends that one string to a third surface: the analytics application.
--
-- The reuse is inherited from the HW3 submission rather than created here, but it
-- is worse now, because a single published string reaches shell access, two
-- vhosts, and an app that can read every visitor's IP and user agent.
--
-- ucsdwrestlingclub.com becomes the club's real homepage after this course.
-- Before that happens:
--
--     DELETE FROM users WHERE username IN ('grader','grader-admin','grader-basic');
--
-- ...and rotate the SSH password and the htpasswd entry too. Deleting the rows
-- here does not undo the reuse elsewhere.
--
-- Create a real administrator first, or you will lock yourself out — users.php
-- refuses to remove the last super_admin.
-- ---------------------------------------------------------------------------
--
-- Re-running RESETS the password to the documented value, matching 004.

USE cse135;

INSERT INTO users (username, email, password_hash, role, created_at) VALUES
  ('grader', 'grader@ucsdwrestlingclub.com',
   '$2y$10$eW8lJeS8EGSDDWC2sE/0W.0wjaQK3WoYgA68necl/NPm0qnOMlKEi',
   'analyst', UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE
  password_hash = VALUES(password_hash),
  role          = VALUES(role),
  email         = VALUES(email),
  updated_at    = UTC_TIMESTAMP();
