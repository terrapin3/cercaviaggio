<?php
declare(strict_types=1);

$scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/sync/web_cache.php'));
$scriptDir = rtrim(dirname($scriptName), '/');
$baseDir = preg_replace('#/sync$#', '', $scriptDir);
$target = ($baseDir !== '' && $baseDir !== '.') ? $baseDir . '/accesso/cache.php' : '/accesso/cache.php';

header('Location: ' . $target, true, 302);
exit;
