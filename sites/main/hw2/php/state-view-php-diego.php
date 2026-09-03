<?php
require __DIR__ . '/session.php';

/* Screen 2 of 3: a different URL reading the same server-side session. */
$saved = saved_data();

page_top('State: View — PHP');

if ($saved === []) {
    echo "    <p>Nothing is saved in this session yet.</p>\n";
} else {
    kv_table($saved, 'Data read back from the server-side session');
}
?>
    <p class="note">
      This page never received the values in the request — it looked them up
      server-side from the session ID in the cookie.
    </p>

    <ul>
      <li><a href="state-php-diego.php">Back to the save screen</a></li>
      <li><a href="state-clear-php-diego.php">Clear saved data</a></li>
    </ul>
<?php
page_bottom();
