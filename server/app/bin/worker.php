<?php
// Scheduler entry point. Run from cron / Windows Task Scheduler:  php server/app/bin/worker.php --once
// Options: --once (default, one tick), --loop=SECONDS (repeat until stopped), --max-jobs=N
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
require dirname(__DIR__) . '/bootstrap.php';
$opts = getopt('', ['once', 'loop::', 'max-jobs::']); $max = (int)($opts['max-jobs'] ?? 50); $loop = isset($opts['loop']) ? max(5, (int)$opts['loop']) : 0;
do { $r = LTI\Worker::tick('cli', $max, 50); echo gmdate('c') . ' ' . json_encode($r) . "\n"; if ($loop) sleep($loop); } while ($loop);
