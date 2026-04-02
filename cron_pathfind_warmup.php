<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

require_once __DIR__ . '/includes/conn.php';
require_once __DIR__ . '/includes/runtime_settings.php';
require_once __DIR__ . '/includes/pathfind.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/pathfind_warmup_tools.php';

$opts = getopt('', ['max-jobs::', 'sleep-ms::', 'retry-limit::']);
$maxJobs = isset($opts['max-jobs']) ? (int) $opts['max-jobs'] : 20;
$sleepMs = isset($opts['sleep-ms']) ? (int) $opts['sleep-ms'] : 250;
$retryLimit = isset($opts['retry-limit']) ? (int) $opts['retry-limit'] : 2;

$lockPath = __DIR__ . '/files/cache/pathfind_warmup.lock';
$lockDir = dirname($lockPath);
if (!is_dir($lockDir)) {
    @mkdir($lockDir, 0775, true);
}

$lockHandle = @fopen($lockPath, 'c+');
if (!is_resource($lockHandle)) {
    fwrite(STDERR, "Cannot open lock file: {$lockPath}\n");
    exit(1);
}
if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, "Warmup already running\n");
    fclose($lockHandle);
    exit(0);
}

try {
    $connection = cvDbConnection();
    if (!cvPathfindWarmupEnsureTable($connection)) {
        throw new RuntimeException('Cannot ensure cv_pathfind_warmup_queue table.');
    }

    $summary = cvPathfindWarmupRunBatch($connection, $maxJobs, $sleepMs, $retryLimit);
    echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, '[warmup-error] ' . $exception->getMessage() . PHP_EOL);
    exit(1);
} finally {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
}

