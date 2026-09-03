<?php
define('HW2_LIB', true);
require __DIR__ . '/lib.php';

page_top('Hello HTML — PHP');
?>
    <p>Hello from <strong><?= h(TEAM_NAME) ?></strong>.</p>

    <table class="kv">
      <caption>Response details</caption>
      <tbody>
        <tr><th scope="row">Language</th><td><?= h(LANG_NAME) ?> <?= h(PHP_VERSION) ?></td></tr>
        <tr><th scope="row">Generated at</th><td><?= h(now_iso()) ?></td></tr>
        <tr><th scope="row">Your IP address</th><td><?= h(client_ip()) ?></td></tr>
      </tbody>
    </table>

    <p class="note">
      Every value above is produced per request on the server. Reload and the
      timestamp changes — this is not a static file.
    </p>
<?php
page_bottom();
