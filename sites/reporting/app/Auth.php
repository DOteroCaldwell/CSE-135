<?php
declare(strict_types=1);
defined('CSE135_APP') || exit;

/**
 * Session-based authentication and authorisation.
 *
 * Sessions are server-side PHP sessions: the browser holds an opaque id, every
 * fact about the user lives on the server. That is a course requirement and also
 * the right call — nothing role-related is client-modifiable.
 */
final class Auth
{
    /** Roles, ordered least to most privileged. See sections() for what each may open. */
    public const ROLES = ['viewer', 'analyst', 'super_admin'];

    private const SESSION_KEY  = 'uid';
    private const THROTTLE_MAX = 8;    // failures per identifier
    private const THROTTLE_IP  = 25;   // failures per client IP
    private const THROTTLE_MIN = 15;   // rolling window, minutes

    /**
     * A valid bcrypt hash of a value nobody can supply, used to keep the failure
     * path's cost identical whether or not the account exists. Without this, "no
     * such user" returns measurably faster than "wrong password" and the login form
     * becomes a user-enumeration oracle.
     */
    private const DUMMY_HASH = '$2y$10$XFA2fOqb72BIFkdh5pYUweCCeJt60U.A.sG2KLWPcz6J2iyI4buNu';

    private static ?array $cached = null;
    private static bool $loaded = false;

    /** @var string[]|null sections the current user may view, memoised */
    private static ?array $sections = null;

    /* ------------------------------------------------------------- lookup -- */

    /**
     * The signed-in user, or null.
     *
     * Re-read from the database on each request rather than cached into the
     * session. It costs one indexed primary-key lookup and buys correctness that
     * matters: when an admin deletes a user or drops their role, it takes effect on
     * that user's very next request instead of whenever they happen to log in
     * again. A stale role cached in $_SESSION is a privilege that outlives its
     * revocation.
     */
    public static function user(): ?array
    {
        if (self::$loaded) {
            return self::$cached;
        }
        self::$loaded = true;

        $uid = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_int($uid)) {
            return self::$cached = null;
        }

        $row = Db::one(
            'SELECT id, username, email, role, is_admin, created_at
               FROM users WHERE id = ? LIMIT 1',
            [$uid]
        );

        if ($row === null) {
            // The account went away mid-session. Drop the session rather than
            // leaving a half-authenticated request in flight.
            self::logout();
            return self::$cached = null;
        }

        return self::$cached = $row;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function isAdmin(): bool
    {
        $u = self::user();
        return $u !== null && (int) $u['is_admin'] === 1;
    }

    public static function role(): ?string
    {
        $u = self::user();
        return $u === null ? null : (string) $u['role'];
    }

    public static function isViewer(): bool
    {
        return self::role() === 'viewer';
    }

    public static function isAnalyst(): bool
    {
        return self::role() === 'analyst';
    }

    /**
     * Who may create saved reports and exports: anyone whose job is analysis.
     * A viewer's whole definition is that they consume what analysts publish.
     */
    public static function canPublish(): bool
    {
        return self::isAdmin() || self::isAnalyst();
    }

    /**
     * Authenticate a request WITHOUT a session — used by the REST API's HTTP Basic
     * path so the section check that follows sees the same identity the
     * dashboard would. Nothing is written to $_SESSION; the identity lives for
     * this request only.
     */
    public static function actAs(array $user): void
    {
        self::$cached   = $user;
        self::$loaded   = true;
        self::$sections = null;
    }

    /* ---------------------------------------------------------- sections -- */

    /**
     * The sections this account may open live reports for.
     *
     *   super_admin  every section, with no lookup: user management is theirs, so
     *                a table that could lock them out of a report is not consulted
     *   analyst      exactly the rows in user_sections. Empty means empty. An
     *                analyst with no sections sees a clear message, not a quietly
     *                widened scope.
     *   viewer       none. Viewers read saved reports, which are checked by the
     *                saved report's own section rather than by this list.
     *
     * @return string[]
     */
    public static function sections(): array
    {
        if (self::$sections !== null) {
            return self::$sections;
        }
        $u = self::user();
        if ($u === null || $u['role'] === 'viewer') {
            return self::$sections = [];
        }
        if ((int) $u['is_admin'] === 1) {
            return self::$sections = Sections::keys();
        }
        return self::$sections = self::sectionsFor((int) $u['id']);
    }

    /** Sections granted to one analyst, straight from the table. @return string[] */
    public static function sectionsFor(int $userId): array
    {
        $rows = Db::all('SELECT section FROM user_sections WHERE user_id = ?', [$userId]);
        return Sections::normalise(array_column($rows, 'section'));
    }

    public static function canViewSection(string $section): bool
    {
        return in_array($section, self::sections(), true);
    }

    /**
     * Whether this account may open a particular SAVED report. Admin: any.
     * Analyst: those in their sections. Viewer: every saved report — that is the
     * one thing a viewer is for.
     */
    public static function canViewSaved(string $section): bool
    {
        if (!self::check()) {
            return false;
        }
        return self::isViewer() || self::canViewSection($section);
    }

    /* --------------------------------------------------------------- gates -- */

    /** Send anonymous visitors to the login form, preserving where they were going. */
    public static function requireLogin(): void
    {
        if (self::check()) {
            return;
        }
        $next = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        redirect('/login.php?next=' . urlencode($next));
    }

    /**
     * 403 rather than a redirect: the visitor IS authenticated, they simply are not
     * allowed here. Redirecting them to a login form they have already passed would
     * be a confusing lie about what went wrong.
     */
    public static function requireAdmin(): void
    {
        self::requireLogin();
        if (!self::isAdmin()) {
            render_error_page(
                403,
                'Not allowed',
                'User management is restricted to administrators. You are signed in, '
                . 'but this account does not have that permission.'
            );
        }
    }

    /**
     * Gate for a live report page. Anonymous visitors go to the login form; a
     * signed-in account outside the section gets a 403 that says WHY, because the
     * two reasons call for different actions: a viewer should go to the saved
     * reports, an unassigned analyst should ask an administrator.
     */
    public static function requireSection(string $section): void
    {
        self::requireLogin();
        if (self::canViewSection($section)) {
            return;
        }
        $label = Sections::label($section);
        if (self::isViewer()) {
            render_error_page(
                403,
                'Saved reports only',
                'This account is a viewer. Viewers open reports that an analyst has '
                . 'saved, not the live ' . $label . ' section. Use the Saved reports '
                . 'link in the navigation.'
            );
        }
        render_error_page(
            403,
            'Not your section',
            'You are signed in, but this account is not assigned to the ' . $label
            . ' section. An administrator can change that from User management.'
        );
    }

    /** Gate for actions that create saved reports or exports. */
    public static function requirePublisher(): void
    {
        self::requireLogin();
        if (!self::canPublish()) {
            render_error_page(
                403,
                'Not allowed',
                'Saving and exporting reports is an analyst action. This account is a viewer.'
            );
        }
    }

    /* ------------------------------------------------------ authentication -- */

    /**
     * Verify credentials. Returns the user row on success, null on failure.
     *
     * The identifier field accepts a username OR an email, which is what the
     * assignment asks for and what most sites do. One column each, both unique, so
     * a single OR-matched query resolves it with no ambiguity about which the user
     * meant.
     */
    public static function attempt(string $identifier, string $password): ?array
    {
        $identifier = trim($identifier);

        $row = Db::one(
            'SELECT id, username, email, password_hash, role, is_admin
               FROM users
              WHERE username = ? OR email = ?
              LIMIT 1',
            [$identifier, $identifier]
        );

        // Always verify something, so the timing of a miss matches the timing of a
        // wrong password. See DUMMY_HASH.
        $hash = $row['password_hash'] ?? self::DUMMY_HASH;
        $ok   = password_verify($password, $hash) && $row !== null;

        self::logAttempt($identifier, $ok);

        if (!$ok) {
            return null;
        }

        // Transparent upgrade if the configured cost or algorithm has moved on.
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $new = password_hash($password, PASSWORD_DEFAULT);
            Db::conn()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                      ->execute([$new, $row['id']]);
        }

        unset($row['password_hash']);
        return $row;
    }

    /** Establish the session for a verified user. */
    public static function login(array $user): void
    {
        // Fixation defence: the id the visitor arrived holding is discarded, so a
        // pre-seeded session cookie cannot survive into the authenticated session.
        session_regenerate_id(true);

        $_SESSION[self::SESSION_KEY] = (int) $user['id'];
        $_SESSION['login_at'] = time();

        self::$loaded   = false;
        self::$cached   = null;
        self::$sections = null;
    }

    public static function logout(): void
    {
        $_SESSION = [];

        // Clearing the array alone leaves the cookie pointing at a live session
        // file; expire the cookie too.
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $p['path'],
                'domain'   => $p['domain'],
                'secure'   => $p['secure'],
                'httponly' => $p['httponly'],
                'samesite' => $p['samesite'] ?? 'Lax',
            ]);
        }

        session_destroy();
        self::$loaded   = true;
        self::$cached   = null;
        self::$sections = null;
    }

    /* ---------------------------------------------------------- throttling -- */

    /**
     * Keyed on the identifier as typed AND on the client IP.
     *
     * The identifier limit protects one account from being ground down. The IP
     * limit is the one that matters against enumeration, because a scan spends most
     * of its attempts on names that do not exist — those have no account to
     * throttle, so without an IP bucket they would be unlimited.
     */
    public static function throttled(string $identifier): bool
    {
        $ip = self::clientIp();

        $byId = Db::one(
            'SELECT COUNT(*) AS n FROM login_attempts
              WHERE identifier = ? AND succeeded = 0
                AND attempted_at > (UTC_TIMESTAMP() - INTERVAL ? MINUTE)',
            [trim($identifier), self::THROTTLE_MIN]
        );
        if ((int) ($byId['n'] ?? 0) >= self::THROTTLE_MAX) {
            return true;
        }

        if ($ip !== null) {
            $byIp = Db::one(
                'SELECT COUNT(*) AS n FROM login_attempts
                  WHERE client_ip = ? AND succeeded = 0
                    AND attempted_at > (UTC_TIMESTAMP() - INTERVAL ? MINUTE)',
                [$ip, self::THROTTLE_MIN]
            );
            if ((int) ($byIp['n'] ?? 0) >= self::THROTTLE_IP) {
                return true;
            }
        }

        return false;
    }

    public static function throttleWindowMinutes(): int
    {
        return self::THROTTLE_MIN;
    }

    /** Successes are kept alongside failures: together they are the audit trail. */
    private static function logAttempt(string $identifier, bool $ok): void
    {
        Db::conn()->prepare(
            'INSERT INTO login_attempts (identifier, client_ip, succeeded, attempted_at)
             VALUES (?, ?, ?, UTC_TIMESTAMP())'
        )->execute([mb_substr($identifier, 0, 255), self::clientIp(), $ok ? 1 : 0]);
    }

    private static function clientIp(): ?string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        return $ip === '' ? null : mb_substr($ip, 0, 45);
    }
}
