<?php
declare(strict_types=1);

/**
 * CSE 135 HW4 Part 1 — logout confirmation.
 *
 * The assignment asks for a page confirming the user is signed out, so this
 * renders rather than bouncing straight to the login form.
 *
 * Reached by a GET link from the nav. That is a deliberate simplification and it
 * is worth being honest about the tradeoff: a GET logout can be triggered by a
 * third-party page embedding <img src=".../logout.php">, so it is technically
 * CSRF-able. The consequence is being signed out — an annoyance, not a breach —
 * and the alternative (a POST form in the nav of every page) costs more than it
 * buys here. Logging out is also the one action whose forged version leaves the
 * user strictly safer than not performing it.
 */

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/View/layout.php';

$was = Auth::user();
Auth::logout();

layout_bare_header('Signed out');
?>
<h1>You have successfully logged out</h1>
<?php if ($was !== null): ?>
<p>Signed out of <strong><?= e($was['username']) ?></strong>. Your session has been destroyed on the server.</p>
<?php else: ?>
<p>You were not signed in.</p>
<?php endif; ?>
<p><a class="btn" href="/login.php">Sign in again</a></p>
<?php
layout_bare_footer();
