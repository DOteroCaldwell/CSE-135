<?php
require __DIR__ . '/session.php';

/* Screen 3 of 3: destroy the session. POST-only, so a link preview or a
   prefetch cannot wipe the data. */
$cleared = false;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_check();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'],
        ]);
    }
    session_destroy();
    $cleared = true;
}

page_top('State: Clear — PHP');
?>
<?php if ($cleared): ?>
    <p class="note">Session destroyed. The server-side file is gone and the cookie is expired.</p>
<?php else: ?>
    <form method="post" action="state-clear-php.php">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <p>This clears everything stored in the current server-side session.</p>
      <button type="submit">Clear session</button>
    </form>
<?php endif; ?>

    <ul>
      <li><a href="state-php.php">Back to the save screen</a></li>
      <li><a href="state-view-php.php">View saved data</a></li>
    </ul>
<?php
page_bottom();
