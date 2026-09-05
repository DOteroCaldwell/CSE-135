<?php
declare(strict_types=1);

/**
 * CSE 135 HW4 Part 1 — login.
 *
 * One identifier field taking a username OR an email, plus a password. Plain form
 * POST, so it works with JavaScript disabled; there is no JavaScript on this page
 * at all.
 */

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/View/layout.php';

// Already signed in? Nothing to do here.
if (Auth::check()) {
    redirect('/');
}

/**
 * Where to land after a successful login.
 *
 * Only same-site absolute paths are honoured. Without this check, ?next= turns the
 * login form into an open redirect: an attacker sends a victim a link to OUR login
 * page that bounces to theirs after a real, successful login, which is exactly the
 * sequence that makes a phishing page credible.
 *
 * A leading "//" is rejected too — "//evil.example" is a protocol-relative URL, not
 * a local path, and it starts with the "/" a naive check would accept.
 */
function safe_next(string $raw): string
{
    if ($raw === '' || $raw[0] !== '/' || str_starts_with($raw, '//')) {
        return '/';
    }
    if (str_contains($raw, "\r") || str_contains($raw, "\n")) {
        return '/';
    }
    return $raw;
}

$next  = safe_next((string) ($_GET['next'] ?? $_POST['next'] ?? ''));
$error = null;
$identifier = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    Csrf::require();

    $identifier = trim((string) ($_POST['identifier'] ?? ''));
    $password   = (string) ($_POST['password'] ?? '');

    if ($identifier === '' || $password === '') {
        $error = 'Enter your username or email and your password.';
    } elseif (Auth::throttled($identifier)) {
        // Distinct from the credentials message: this one is actionable, and it is
        // not an enumeration leak because it is reachable with any identifier.
        $error = 'Too many failed attempts. Wait '
               . Auth::throttleWindowMinutes() . ' minutes and try again.';
    } else {
        $user = Auth::attempt($identifier, $password);
        if ($user === null) {
            // One message for "no such account" and for "wrong password". Saying
            // which would confirm to a stranger that an account exists.
            $error = 'Those credentials did not match an account.';
        } else {
            Auth::login($user);
            redirect($next);
        }
    }
}

layout_bare_header('Sign in');
?>
<h1>CSE 135 Analytics</h1>

<?php if ($error !== null): ?>
<p class="notice notice-error"><?= e($error) ?></p>
<?php endif; ?>

<form class="stack" method="post" action="/login.php">
  <?= Csrf::field() ?>
  <input type="hidden" name="next" value="<?= e($next) ?>">

  <div>
    <label for="identifier">Username or email</label>
    <input type="text" id="identifier" name="identifier" required
           autocomplete="username" autocapitalize="none" spellcheck="false"
           value="<?= e($identifier) ?>">
  </div>

  <div>
    <label for="password">Password</label>
    <input type="password" id="password" name="password" required
           autocomplete="current-password">
  </div>

  <div><button class="btn" type="submit">Sign in</button></div>
</form>
<?php
layout_bare_footer();
