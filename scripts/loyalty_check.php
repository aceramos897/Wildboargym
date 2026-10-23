<?php
/**
 * CLI / cron loyalty evaluator — php scripts/loyalty_check.php
 * Add to Hostinger cron (daily during early December, e.g. Dec 1 06:00):
 *   /usr/bin/php /home/USER/public_html/scripts/loyalty_check.php
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$r = run_loyalty_check(null);
echo json_encode($r) . "\n";