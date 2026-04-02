<?php
declare(strict_types=1);

if (!function_exists('cvPathfindWarmupEnsureTable')) {
    function cvPathfindWarmupEnsureTable(mysqli $connection): bool
    {
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS cv_pathfind_warmup_queue (
  id_warmup BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  warmup_key CHAR(64) NOT NULL,
  from_ref VARCHAR(190) NOT NULL,
  to_ref VARCHAR(190) NOT NULL,
  travel_date_it VARCHAR(10) NOT NULL,
  adults SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  children SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  max_transfers TINYINT UNSIGNED NOT NULL DEFAULT 2,
  priority INT NOT NULL DEFAULT 0,
  source VARCHAR(120) NOT NULL DEFAULT 'manual',
  status ENUM('pending','running','done','error') NOT NULL DEFAULT 'pending',
  attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  last_error TEXT NULL,
  next_run_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at DATETIME NULL,
  finished_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id_warmup),
  UNIQUE KEY uq_cv_warmup_key (warmup_key),
  KEY idx_cv_warmup_status_next (status, next_run_at, priority),
  KEY idx_cv_warmup_route_date (from_ref, to_ref, travel_date_it)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

        return $connection->query($sql) === true;
    }
}

if (!function_exists('cvPathfindWarmupKey')) {
    function cvPathfindWarmupKey(
        string $fromRef,
        string $toRef,
        string $travelDateIt,
        int $adults,
        int $children,
        int $maxTransfers
    ): string {
        return hash('sha256', implode('|', [
            trim($fromRef),
            trim($toRef),
            trim($travelDateIt),
            (string) max(0, $adults),
            (string) max(0, $children),
            (string) max(0, min(3, $maxTransfers)),
        ]));
    }
}

if (!function_exists('cvPathfindWarmupDateIt')) {
    function cvPathfindWarmupDateIt(DateTimeImmutable $date): string
    {
        return $date->format('d/m/Y');
    }
}

if (!function_exists('cvPathfindWarmupDateSeries')) {
    /**
     * @return array<int,string>
     */
    function cvPathfindWarmupDateSeries(int $daysAhead): array
    {
        $safeDaysAhead = max(0, min(30, $daysAhead));
        $today = new DateTimeImmutable('today', new DateTimeZone('Europe/Rome'));
        $dates = [];
        for ($i = 0; $i <= $safeDaysAhead; $i++) {
            $dates[] = cvPathfindWarmupDateIt($today->modify('+' . $i . ' day'));
        }

        return $dates;
    }
}

if (!function_exists('cvPathfindWarmupResolveRouteRefs')) {
    /**
     * @param array<string,mixed> $route
     * @return array{from_ref:string,to_ref:string}
     */
    function cvPathfindWarmupResolveRouteRefs(array $route): array
    {
        $fromRef = trim((string) ($route['from_ref'] ?? ''));
        $toRef = trim((string) ($route['to_ref'] ?? ''));

        if ($fromRef === '') {
            $providerCode = trim((string) ($route['from_provider_code'] ?? ''));
            $stopId = trim((string) ($route['from_id'] ?? ($route['from_stop_external_id'] ?? '')));
            if ($providerCode !== '' && $stopId !== '') {
                $fromRef = $providerCode . '|' . $stopId;
            }
        }
        if ($toRef === '') {
            $providerCode = trim((string) ($route['to_provider_code'] ?? ''));
            $stopId = trim((string) ($route['to_id'] ?? ($route['to_stop_external_id'] ?? '')));
            if ($providerCode !== '' && $stopId !== '') {
                $toRef = $providerCode . '|' . $stopId;
            }
        }

        return [
            'from_ref' => $fromRef,
            'to_ref' => $toRef,
        ];
    }
}

if (!function_exists('cvPathfindWarmupEnqueueRow')) {
    function cvPathfindWarmupEnqueueRow(
        mysqli $connection,
        string $fromRef,
        string $toRef,
        string $travelDateIt,
        int $adults,
        int $children,
        int $maxTransfers,
        int $priority,
        string $source = 'manual'
    ): bool {
        $fromRef = trim($fromRef);
        $toRef = trim($toRef);
        $travelDateIt = trim($travelDateIt);
        if ($fromRef === '' || $toRef === '' || $travelDateIt === '') {
            return false;
        }

        $adults = max(0, $adults);
        $children = max(0, $children);
        if (($adults + $children) <= 0) {
            $adults = 1;
        }
        $maxTransfers = max(0, min(3, $maxTransfers));
        $priority = max(0, min(10000, $priority));
        $source = trim($source) !== '' ? trim($source) : 'manual';
        $warmupKey = cvPathfindWarmupKey($fromRef, $toRef, $travelDateIt, $adults, $children, $maxTransfers);

        $sql = <<<SQL
INSERT INTO cv_pathfind_warmup_queue
  (warmup_key, from_ref, to_ref, travel_date_it, adults, children, max_transfers, priority, source, status, next_run_at)
VALUES
  (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
ON DUPLICATE KEY UPDATE
  priority = GREATEST(priority, VALUES(priority)),
  source = VALUES(source),
  status = IF(status = 'running', status, 'pending'),
  next_run_at = NOW(),
  last_error = NULL,
  finished_at = NULL
SQL;

        $statement = $connection->prepare($sql);
        if (!$statement instanceof mysqli_stmt) {
            return false;
        }
        $statement->bind_param(
            'ssssiiiss',
            $warmupKey,
            $fromRef,
            $toRef,
            $travelDateIt,
            $adults,
            $children,
            $maxTransfers,
            $priority,
            $source
        );
        $ok = $statement->execute();
        $statement->close();

        return $ok;
    }
}

if (!function_exists('cvPathfindWarmupEnqueueFromTopRoutes')) {
    /**
     * @return array<string,int>
     */
    function cvPathfindWarmupEnqueueFromTopRoutes(
        mysqli $connection,
        int $routesLimit = 50,
        int $daysAhead = 3,
        int $adults = 1,
        int $children = 0,
        int $maxTransfers = 2,
        int $priority = 100,
        string $source = 'manual'
    ): array {
        $summary = [
            'routes_scanned' => 0,
            'dates_per_route' => 0,
            'jobs_requested' => 0,
            'jobs_enqueued' => 0,
        ];

        if (!cvPathfindWarmupEnsureTable($connection)) {
            return $summary;
        }

        $safeLimit = max(1, min(300, $routesLimit));
        if (function_exists('cvFetchMostRequestedRoutes')) {
            $routes = cvFetchMostRequestedRoutes($connection, $safeLimit);
        } else {
            $routes = [];
            $sql = <<<SQL
SELECT
  from_ref,
  to_ref,
  from_provider_code,
  from_stop_external_id,
  to_provider_code,
  to_stop_external_id
FROM cv_search_route_stats
ORDER BY search_count DESC, last_requested_at DESC
LIMIT {$safeLimit}
SQL;
            $queryResult = $connection->query($sql);
            if ($queryResult instanceof mysqli_result) {
                while ($row = $queryResult->fetch_assoc()) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $routes[] = $row;
                }
                $queryResult->free();
            }
        }
        $dates = cvPathfindWarmupDateSeries($daysAhead);

        $summary['routes_scanned'] = count($routes);
        $summary['dates_per_route'] = count($dates);

        foreach ($routes as $route) {
            if (!is_array($route)) {
                continue;
            }

            $refs = cvPathfindWarmupResolveRouteRefs($route);
            $fromRef = $refs['from_ref'];
            $toRef = $refs['to_ref'];
            if ($fromRef === '' || $toRef === '') {
                continue;
            }

            foreach ($dates as $travelDateIt) {
                $summary['jobs_requested']++;
                if (cvPathfindWarmupEnqueueRow(
                    $connection,
                    $fromRef,
                    $toRef,
                    $travelDateIt,
                    $adults,
                    $children,
                    $maxTransfers,
                    $priority,
                    $source
                )) {
                    $summary['jobs_enqueued']++;
                }
            }
        }

        return $summary;
    }
}

if (!function_exists('cvPathfindWarmupQueueStats')) {
    /**
     * @return array<string,int>
     */
    function cvPathfindWarmupQueueStats(mysqli $connection): array
    {
        $stats = [
            'pending' => 0,
            'running' => 0,
            'done' => 0,
            'error' => 0,
            'total' => 0,
        ];
        if (!cvPathfindWarmupEnsureTable($connection)) {
            return $stats;
        }

        $sql = "SELECT status, COUNT(*) AS c FROM cv_pathfind_warmup_queue GROUP BY status";
        $result = $connection->query($sql);
        if (!$result instanceof mysqli_result) {
            return $stats;
        }

        while ($row = $result->fetch_assoc()) {
            if (!is_array($row)) {
                continue;
            }
            $status = (string) ($row['status'] ?? '');
            $count = (int) ($row['c'] ?? 0);
            if (isset($stats[$status])) {
                $stats[$status] = $count;
            }
            $stats['total'] += $count;
        }
        $result->free();

        return $stats;
    }
}

if (!function_exists('cvPathfindWarmupFetchNextPending')) {
    /**
     * @return array<string,mixed>|null
     */
    function cvPathfindWarmupFetchNextPending(mysqli $connection): ?array
    {
        if (!cvPathfindWarmupEnsureTable($connection)) {
            return null;
        }

        $sql = <<<SQL
SELECT id_warmup, from_ref, to_ref, travel_date_it, adults, children, max_transfers, attempt_count
FROM cv_pathfind_warmup_queue
WHERE status = 'pending'
  AND next_run_at <= NOW()
ORDER BY priority DESC, next_run_at ASC, id_warmup ASC
LIMIT 1
SQL;
        $result = $connection->query($sql);
        if (!$result instanceof mysqli_result) {
            return null;
        }
        $row = $result->fetch_assoc();
        $result->free();

        return is_array($row) ? $row : null;
    }
}

if (!function_exists('cvPathfindWarmupRecentJobs')) {
    /**
     * @return array<int,array<string,mixed>>
     */
    function cvPathfindWarmupRecentJobs(mysqli $connection, int $limit = 30): array
    {
        if (!cvPathfindWarmupEnsureTable($connection)) {
            return [];
        }

        $safeLimit = max(1, min(200, $limit));
        $sql = <<<SQL
SELECT
  id_warmup,
  from_ref,
  to_ref,
  travel_date_it,
  adults,
  children,
  max_transfers,
  priority,
  source,
  status,
  attempt_count,
  last_error,
  next_run_at,
  started_at,
  finished_at,
  created_at,
  updated_at
FROM cv_pathfind_warmup_queue
ORDER BY updated_at DESC, id_warmup DESC
LIMIT {$safeLimit}
SQL;

        $result = $connection->query($sql);
        if (!$result instanceof mysqli_result) {
            return [];
        }

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            if (!is_array($row)) {
                continue;
            }
            $rows[] = $row;
        }
        $result->free();

        return $rows;
    }
}

if (!function_exists('cvPathfindWarmupTryLockRow')) {
    function cvPathfindWarmupTryLockRow(mysqli $connection, int $idWarmup): bool
    {
        $sql = "UPDATE cv_pathfind_warmup_queue
                SET status = 'running', started_at = NOW(), updated_at = NOW(), attempt_count = attempt_count + 1
                WHERE id_warmup = ? AND status = 'pending'";
        $statement = $connection->prepare($sql);
        if (!$statement instanceof mysqli_stmt) {
            return false;
        }
        $statement->bind_param('i', $idWarmup);
        $ok = $statement->execute();
        $affected = $ok ? (int) $statement->affected_rows : 0;
        $statement->close();

        return $ok && $affected > 0;
    }
}

if (!function_exists('cvPathfindWarmupMarkDone')) {
    function cvPathfindWarmupMarkDone(mysqli $connection, int $idWarmup): void
    {
        $sql = "UPDATE cv_pathfind_warmup_queue
                SET status = 'done', finished_at = NOW(), last_error = NULL, updated_at = NOW()
                WHERE id_warmup = ?";
        $statement = $connection->prepare($sql);
        if ($statement instanceof mysqli_stmt) {
            $statement->bind_param('i', $idWarmup);
            $statement->execute();
            $statement->close();
        }
    }
}

if (!function_exists('cvPathfindWarmupMarkError')) {
    function cvPathfindWarmupMarkError(
        mysqli $connection,
        int $idWarmup,
        string $error,
        int $attemptCount,
        int $retryLimit = 2,
        int $retryDelayMinutes = 15
    ): void {
        $safeError = mb_substr(trim($error), 0, 2000, 'UTF-8');
        $retryLimit = max(0, min(10, $retryLimit));
        $retryDelayMinutes = max(1, min(180, $retryDelayMinutes));

        if ($attemptCount <= $retryLimit) {
            $sql = "UPDATE cv_pathfind_warmup_queue
                    SET status = 'pending',
                        last_error = ?,
                        next_run_at = DATE_ADD(NOW(), INTERVAL ? MINUTE),
                        updated_at = NOW()
                    WHERE id_warmup = ?";
            $statement = $connection->prepare($sql);
            if ($statement instanceof mysqli_stmt) {
                $statement->bind_param('sii', $safeError, $retryDelayMinutes, $idWarmup);
                $statement->execute();
                $statement->close();
            }
            return;
        }

        $sql = "UPDATE cv_pathfind_warmup_queue
                SET status = 'error', finished_at = NOW(), last_error = ?, updated_at = NOW()
                WHERE id_warmup = ?";
        $statement = $connection->prepare($sql);
        if ($statement instanceof mysqli_stmt) {
            $statement->bind_param('si', $safeError, $idWarmup);
            $statement->execute();
            $statement->close();
        }
    }
}

if (!function_exists('cvPathfindWarmupRunBatch')) {
    /**
     * @return array<string,mixed>
     */
    function cvPathfindWarmupRunBatch(
        mysqli $connection,
        int $maxJobs = 20,
        int $sleepMs = 200,
        int $retryLimit = 2
    ): array {
        $summary = [
            'processed' => 0,
            'done' => 0,
            'error' => 0,
            'empty' => 0,
            'started_at' => date('c'),
            'jobs' => [],
        ];

        $maxJobs = max(1, min(500, $maxJobs));
        $sleepMs = max(0, min(3000, $sleepMs));
        $runtime = cvRuntimeSettings($connection);
        $maxTransfersDefault = max(0, min(3, (int) ($runtime['pathfind_max_transfers'] ?? 2)));

        for ($i = 0; $i < $maxJobs; $i++) {
            $job = cvPathfindWarmupFetchNextPending($connection);
            if (!is_array($job)) {
                break;
            }

            $idWarmup = (int) ($job['id_warmup'] ?? 0);
            if ($idWarmup <= 0) {
                continue;
            }
            if (!cvPathfindWarmupTryLockRow($connection, $idWarmup)) {
                continue;
            }

            $fromRef = trim((string) ($job['from_ref'] ?? ''));
            $toRef = trim((string) ($job['to_ref'] ?? ''));
            $travelDateIt = trim((string) ($job['travel_date_it'] ?? ''));
            $adults = max(0, (int) ($job['adults'] ?? 1));
            $children = max(0, (int) ($job['children'] ?? 0));
            if (($adults + $children) <= 0) {
                $adults = 1;
            }
            $maxTransfers = max(0, min(3, (int) ($job['max_transfers'] ?? $maxTransfersDefault)));
            $attemptCount = max(1, (int) ($job['attempt_count'] ?? 0) + 1);

            $summary['processed']++;
            $jobResult = [
                'id_warmup' => $idWarmup,
                'from_ref' => $fromRef,
                'to_ref' => $toRef,
                'travel_date_it' => $travelDateIt,
                'status' => 'done',
                'solutions_count' => 0,
            ];

            try {
                $response = cvPfSearchSolutions(
                    $connection,
                    $fromRef,
                    $toRef,
                    $travelDateIt,
                    $adults,
                    $children,
                    $maxTransfers,
                    ''
                );

                $ok = (bool) ($response['ok'] ?? false);
                $solutions = isset($response['solutions']) && is_array($response['solutions']) ? $response['solutions'] : [];
                $jobResult['solutions_count'] = count($solutions);

                if (!$ok) {
                    $error = trim((string) ($response['error'] ?? 'warmup_failed'));
                    cvPathfindWarmupMarkError($connection, $idWarmup, $error, $attemptCount, $retryLimit);
                    $summary['error']++;
                    $jobResult['status'] = 'error';
                    $jobResult['error'] = $error;
                } else {
                    cvPathfindWarmupMarkDone($connection, $idWarmup);
                    $summary['done']++;
                    if (count($solutions) === 0) {
                        $summary['empty']++;
                    }
                }
            } catch (Throwable $exception) {
                $error = $exception->getMessage();
                cvPathfindWarmupMarkError($connection, $idWarmup, $error, $attemptCount, $retryLimit);
                $summary['error']++;
                $jobResult['status'] = 'error';
                $jobResult['error'] = $error;
            }

            $summary['jobs'][] = $jobResult;

            if ($sleepMs > 0 && $i < ($maxJobs - 1)) {
                usleep($sleepMs * 1000);
            }
        }

        $summary['finished_at'] = date('c');
        return $summary;
    }
}
