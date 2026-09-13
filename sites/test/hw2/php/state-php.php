<?php
require __DIR__ . '/session.php';

/* Screen 1 of 3: enter data to save into the server-side session. */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_check();
    $_SESSION['data'] = [
        'nickname'  => trim((string) ($_POST['nickname'] ?? '')),
        'weight'    => trim((string) ($_POST['weight'] ?? '')),
        'note'      => trim((string) ($_POST['note'] ?? '')),
        'savedAt'   => now_iso(),
    ];
    // POST/redirect/GET so a refresh does not resubmit the form.
    header('Location: state-php.php?saved=1', true, 303);
    exit;
}

$saved = saved_data();

page_top('State: Save — PHP');
?>
<?php if (isset($_GET['saved'])): ?>
    <p class="note">Saved to the server-side session. Now open the view screen.</p>
<?php endif; ?>

    <p>
      Enter something below. It is stored in the session file on the server; your
      browser receives only an opaque session ID in the
      <code>HW2PHPSESS</code> cookie.
    </p>

    <form method="post" action="state-php.php">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <div class="field-row">
        <label for="nickname">Nickname</label>
        <input type="text" id="nickname" name="nickname" value="<?= h($saved['nickname'] ?? '') ?>">
      </div>
      <div class="field-row">
        <label for="weight">Weight class</label>
        <input type="text" id="weight" name="weight" value="<?= h($saved['weight'] ?? '') ?>">
      </div>
      <div class="field-row">
        <label for="note">Note</label>
        <input type="text" id="note" name="note" value="<?= h($saved['note'] ?? '') ?>">
      </div>
      <button type="submit">Save to session</button>
    </form>

    <h2>Other screens</h2>
    <ul>
      <li><a href="state-view-php.php">View saved data</a></li>
      <li><a href="state-clear-php.php">Clear saved data</a></li>
    </ul>

    <p class="note">Session ID: <code><?= h(substr(session_id(), 0, 8)) ?>…</code> (truncated)</p>
<?php
page_bottom();
