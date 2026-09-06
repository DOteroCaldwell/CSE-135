<?php
declare(strict_types=1);

/**
 * CSE 135 HW5 — the audience report, live.
 *
 * Everything about the report lives in app/Reports/audience.php; this file is the
 * URL. See app/View/report.php for what report_page() does.
 */

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/View/report.php';

report_page('audience');
