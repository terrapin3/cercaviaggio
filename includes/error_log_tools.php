<?php
declare(strict_types=1);

if (!function_exists('cvErrorLogEnsureTable')) {
    function cvErrorLogEnsureTable(mysqli $connection): bool
    {
        static $checked = false;
        static $ok = false;

        if ($checked) {
            return $ok;
        }
        $checked = true;

        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS cv_error_log (
  id_error_log BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source VARCHAR(64) NOT NULL,
  event_code VARCHAR(120) NOT NULL,
  severity ENUM('warning','error') NOT NULL DEFAULT 'error',
  message VARCHAR(500) NOT NULL,
  provider_code VARCHAR(64) DEFAULT NULL,
  request_id VARCHAR(80) DEFAULT NULL,
  action_name VARCHAR(80) DEFAULT NULL,
  order_code VARCHAR(80) DEFAULT NULL,
  shop_id VARCHAR(80) DEFAULT NULL,
  context_json LONGTEXT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_error_log),
  KEY idx_cv_error_log_created (created_at),
  KEY idx_cv_error_log_event (source, event_code, created_at),
  KEY idx_cv_error_log_provider (provider_code, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

        $ok = (bool) $connection->query($sql);
        return $ok;
    }
}

if (!function_exists('cvErrorLogNormalizeSeverity')) {
    function cvErrorLogNormalizeSeverity(string $value): string
    {
        $value = strtolower(trim($value));
        return $value === 'warning' ? 'warning' : 'error';
    }
}

if (!function_exists('cvErrorLogMaybePurgeOld')) {
    function cvErrorLogMaybePurgeOld(mysqli $connection, bool $force = false, int $retentionDays = 30): void
    {
        static $executed = false;
        if ($executed) {
            return;
        }
        $executed = true;

        $retentionDays = max(7, min(365, $retentionDays));
        if (!$force) {
            try {
                $sample = random_int(1, 200);
            } catch (Throwable $exception) {
                $sample = 200;
            }
            if ($sample !== 1) {
                return;
            }
        }

        $connection->query("DELETE FROM cv_error_log WHERE created_at < (NOW() - INTERVAL " . (int) $retentionDays . " DAY)");
    }
}

if (!function_exists('cvErrorLogWrite')) {
    /**
     * @param array<string,mixed> $payload
     */
    function cvErrorLogWrite(mysqli $connection, array $payload): bool
    {
        if (!cvErrorLogEnsureTable($connection)) {
            return false;
        }

        cvErrorLogMaybePurgeOld($connection, false, 30);

        $source = trim((string) ($payload['source'] ?? 'unknown'));
        if ($source === '') {
            $source = 'unknown';
        }
        $eventCode = trim((string) ($payload['event_code'] ?? 'UNSPECIFIED'));
        if ($eventCode === '') {
            $eventCode = 'UNSPECIFIED';
        }
        $severity = cvErrorLogNormalizeSeverity((string) ($payload['severity'] ?? 'error'));
        $message = trim((string) ($payload['message'] ?? 'Errore non specificato.'));
        if ($message === '') {
            $message = 'Errore non specificato.';
        }

        $providerCode = trim((string) ($payload['provider_code'] ?? ''));
        $requestId = trim((string) ($payload['request_id'] ?? ''));
        $actionName = trim((string) ($payload['action_name'] ?? ''));
        $orderCode = trim((string) ($payload['order_code'] ?? ''));
        $shopId = trim((string) ($payload['shop_id'] ?? ''));

        $context = $payload['context'] ?? null;
        $contextJson = null;
        if ($context !== null) {
            try {
                $encoded = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (is_string($encoded) && $encoded !== '') {
                    $contextJson = $encoded;
                }
            } catch (Throwable $exception) {
                $contextJson = null;
            }
        }

        $statement = $connection->prepare(
            'INSERT INTO cv_error_log
             (source, event_code, severity, message, provider_code, request_id, action_name, order_code, shop_id, context_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$statement instanceof mysqli_stmt) {
            return false;
        }

        $providerCode = $providerCode !== '' ? $providerCode : null;
        $requestId = $requestId !== '' ? $requestId : null;
        $actionName = $actionName !== '' ? $actionName : null;
        $orderCode = $orderCode !== '' ? $orderCode : null;
        $shopId = $shopId !== '' ? $shopId : null;

        $statement->bind_param(
            'ssssssssss',
            $source,
            $eventCode,
            $severity,
            $message,
            $providerCode,
            $requestId,
            $actionName,
            $orderCode,
            $shopId,
            $contextJson
        );

        $ok = $statement->execute();
        $statement->close();
        return (bool) $ok;
    }
}

