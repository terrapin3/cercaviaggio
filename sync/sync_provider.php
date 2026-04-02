<?php

date_default_timezone_set('UTC');

function runSyncJob(array $config, string $providerCode, array $options = array()): array
{
    $singleEndpoint = isset($options['endpoint']) ? trim((string)$options['endpoint']) : '';
    $pageSizeOverride = isset($options['page_size']) ? (int)$options['page_size'] : 0;
    $forceFull = !empty($options['full']);

    if ($providerCode === '') {
        throw new RuntimeException('Missing provider code');
    }

    if (!isset($config['providers'][$providerCode])) {
        throw new RuntimeException("Unknown provider: {$providerCode}");
    }

    $providerCfg = $config['providers'][$providerCode];
    if (empty($providerCfg['enabled'])) {
        return array(
            'provider' => $providerCode,
            'status' => 'skipped',
            'message' => "Provider '{$providerCode}' is disabled in config.",
            'endpoints' => array(),
        );
    }

    $db = dbConnect($config['db']);
    $providerId = upsertProvider($db, $providerCode, $providerCfg);
    $runId = startRun($db, $providerId);

    $endpointOrder = array('sync_stops', 'sync_lines', 'sync_trips', 'sync_fares');
    if ($singleEndpoint !== '') {
        if (!in_array($singleEndpoint, $endpointOrder, true)) {
            markRunError($db, $runId, "Unsupported endpoint '{$singleEndpoint}'");
            updateProviderError($db, $providerId, "Unsupported endpoint '{$singleEndpoint}'");
            throw new RuntimeException("Supported endpoints: " . implode(', ', $endpointOrder));
        }
        $endpointOrder = array($singleEndpoint);
    }

    $baseUrl = rtrim((string)$providerCfg['base_url'], '?');
    $apiKey = isset($providerCfg['api_key']) ? $providerCfg['api_key'] : null;
    $timeout = isset($providerCfg['timeout']) ? (int)$providerCfg['timeout'] : 20;
    $pageSize = $pageSizeOverride > 0 ? $pageSizeOverride : (isset($providerCfg['page_size']) ? (int)$providerCfg['page_size'] : 500);
    if ($pageSize < 1) {
        $pageSize = 1;
    }
    if ($pageSize > 1000) {
        $pageSize = 1000;
    }

    $incrementalEnabled = !$forceFull && !empty($providerCfg['incremental']);
    $providerLastSync = getProviderLastSyncAt($db, $providerId);
    $updatedSince = $incrementalEnabled ? $providerLastSync : null;

    $summary = array(
        'provider' => $providerCode,
        'provider_id' => $providerId,
        'run_id' => $runId,
        'status' => 'running',
        'started_at' => gmdate('c'),
        'full_mode' => !$incrementalEnabled,
        'updated_since' => $updatedSince,
        'endpoints' => array(),
    );

    try {
        foreach ($endpointOrder as $endpoint) {
            $result = syncEndpoint(
                $db,
                $providerId,
                $runId,
                $baseUrl,
                $apiKey,
                $timeout,
                $endpoint,
                $pageSize,
                $updatedSince
            );
            $summary['endpoints'][$endpoint] = $result;
        }

        $summary['status'] = 'ok';
        $summary['ended_at'] = gmdate('c');
        markRunOk($db, $runId, $summary);
        updateProviderSyncSuccess($db, $providerId);
        return $summary;
    } catch (Throwable $e) {
        $summary['status'] = 'error';
        $summary['ended_at'] = gmdate('c');
        $summary['error'] = $e->getMessage();

        markRunError($db, $runId, $e->getMessage(), $summary);
        updateProviderError($db, $providerId, $e->getMessage());
        throw $e;
    }
}

function loadSyncConfig(): array
{
    $configFile = __DIR__ . '/config.php';
    if (!file_exists($configFile)) {
        throw new RuntimeException("Missing config file: {$configFile}");
    }
    $config = require $configFile;
    if (!is_array($config) || !isset($config['db'])) {
        throw new RuntimeException("Invalid sync config format");
    }

    $staticProviders = isset($config['providers']) && is_array($config['providers']) ? $config['providers'] : array();
    $config['providers'] = $staticProviders;

    $dbProviders = loadSyncProvidersFromDb($config['db']);
    if (count($dbProviders) > 0) {
        $mergedProviders = $staticProviders;

        foreach ($dbProviders as $providerCode => $dbProvider) {
            $staticProvider = isset($staticProviders[$providerCode]) && is_array($staticProviders[$providerCode])
                ? $staticProviders[$providerCode]
                : array();

            $mergedProviders[$providerCode] = array(
                'name' => isset($staticProvider['name']) && trim((string)$staticProvider['name']) !== ''
                    ? (string)$staticProvider['name']
                    : (string)$dbProvider['name'],
                'base_url' => isset($staticProvider['base_url']) && trim((string)$staticProvider['base_url']) !== ''
                    ? (string)$staticProvider['base_url']
                    : (string)$dbProvider['base_url'],
                'api_key' => array_key_exists('api_key', $staticProvider)
                    ? $staticProvider['api_key']
                    : $dbProvider['api_key'],
                'enabled' => array_key_exists('enabled', $staticProvider)
                    ? !empty($staticProvider['enabled'])
                    : !empty($dbProvider['enabled']),
                'page_size' => isset($staticProvider['page_size']) ? (int)$staticProvider['page_size'] : 500,
                'timeout' => isset($staticProvider['timeout']) ? (int)$staticProvider['timeout'] : 20,
                'incremental' => array_key_exists('incremental', $staticProvider)
                    ? !empty($staticProvider['incremental'])
                    : false,
            );
        }

        $config['providers'] = $mergedProviders;
    }

    return $config;
}

function loadSyncProvidersFromDb(array $dbCfg): array
{
    $providers = array();

    try {
        $db = dbConnect($dbCfg);
    } catch (Throwable $e) {
        return $providers;
    }

    $sql = "SELECT code, name, base_url, api_key, is_active
            FROM cv_providers
            WHERE code <> ''
              AND base_url <> ''";
    $result = $db->query($sql);
    if (!$result instanceof mysqli_result) {
        return $providers;
    }

    while ($row = $result->fetch_assoc()) {
        if (!is_array($row)) {
            continue;
        }

        $code = trim((string)($row['code'] ?? ''));
        $baseUrl = trim((string)($row['base_url'] ?? ''));
        if ($code === '' || $baseUrl === '') {
            continue;
        }

        $providers[$code] = array(
            'name' => trim((string)($row['name'] ?? $code)) ?: $code,
            'base_url' => $baseUrl,
            'api_key' => trim((string)($row['api_key'] ?? '')),
            'enabled' => ((int)($row['is_active'] ?? 0) === 1),
        );
    }

    $result->free();
    return $providers;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    $config = loadSyncConfig();
    $args = parseArgs($GLOBALS['argv']);

    $providerCode = isset($args['provider']) ? trim((string)$args['provider']) : '';
    if ($providerCode === '') {
        usageAndExit("Missing required --provider");
    }

    $options = array(
        'endpoint' => isset($args['endpoint']) ? trim((string)$args['endpoint']) : '',
        'page_size' => isset($args['page_size']) ? (int)$args['page_size'] : 0,
        'full' => isset($args['full']) && ((string)$args['full'] === '1' || strtolower((string)$args['full']) === 'true'),
    );

    try {
        $summary = runSyncJob($config, $providerCode, $options);
        if (isset($summary['status']) && $summary['status'] === 'skipped') {
            fwrite(STDOUT, "[INFO] {$summary['message']}\n");
            exit(0);
        }
        foreach ($summary['endpoints'] as $endpoint => $result) {
            fwrite(STDOUT, "[OK] {$endpoint}: {$result['items']} items, {$result['pages']} pages\n");
        }
        fwrite(STDOUT, "[DONE] provider={$providerCode} run_id={$summary['run_id']}\n");
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, "[ERROR] " . $e->getMessage() . "\n");
        exit(1);
    }
}

function usageAndExit($message, $code = 1)
{
    if ($message !== '') {
        fwrite(STDERR, $message . "\n");
    }
    fwrite(STDERR, "Usage:\n");
    fwrite(STDERR, "  php sync_provider.php --provider=curcio [--endpoint=sync_stops] [--page_size=500] [--full=1]\n");
    exit($code);
}

function parseArgs($argv)
{
    $args = array();
    foreach ($argv as $idx => $arg) {
        if ($idx === 0) {
            continue;
        }
        if (substr($arg, 0, 2) !== '--') {
            continue;
        }
        $eq = strpos($arg, '=');
        if ($eq === false) {
            $key = substr($arg, 2);
            $args[$key] = true;
        } else {
            $key = substr($arg, 2, $eq - 2);
            $val = substr($arg, $eq + 1);
            $args[$key] = $val;
        }
    }
    return $args;
}

function dbConnect($cfg)
{
    $mysqli = new mysqli(
        (string)$cfg['host'],
        (string)$cfg['user'],
        (string)$cfg['pass'],
        (string)$cfg['name'],
        (int)$cfg['port']
    );

    if ($mysqli->connect_error) {
        throw new RuntimeException("DB connection failed: " . $mysqli->connect_error);
    }

    $mysqli->set_charset('utf8mb4');
    return $mysqli;
}

function upsertProvider(mysqli $db, $code, $cfg)
{
    $sql = "INSERT INTO cv_providers (code, name, base_url, api_key, is_active)
            VALUES (?, ?, ?, ?, 1)
            ON DUPLICATE KEY UPDATE
              name = VALUES(name),
              base_url = VALUES(base_url),
              api_key = VALUES(api_key),
              is_active = VALUES(is_active)";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException("Prepare failed for provider upsert: " . $db->error);
    }

    $name = (string)$cfg['name'];
    $baseUrl = (string)$cfg['base_url'];
    $apiKey = isset($cfg['api_key']) ? (string)$cfg['api_key'] : null;

    $stmt->bind_param('ssss', $code, $name, $baseUrl, $apiKey);
    if (!$stmt->execute()) {
        throw new RuntimeException("Provider upsert failed: " . $stmt->error);
    }
    $stmt->close();

    $sel = $db->prepare("SELECT id_provider FROM cv_providers WHERE code = ? LIMIT 1");
    if (!$sel) {
        throw new RuntimeException("Prepare failed for provider select: " . $db->error);
    }
    $sel->bind_param('s', $code);
    if (!$sel->execute()) {
        throw new RuntimeException("Provider select failed: " . $sel->error);
    }
    $res = $sel->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $sel->close();

    if (!$row || !isset($row['id_provider'])) {
        throw new RuntimeException("Provider id not found after upsert");
    }
    return (int)$row['id_provider'];
}

function getProviderLastSyncAt(mysqli $db, $providerId)
{
    $stmt = $db->prepare("SELECT last_sync_at FROM cv_providers WHERE id_provider = ? LIMIT 1");
    if (!$stmt) {
        throw new RuntimeException("Prepare failed for provider last_sync_at: " . $db->error);
    }
    $stmt->bind_param('i', $providerId);
    if (!$stmt->execute()) {
        throw new RuntimeException("Provider last_sync_at select failed: " . $stmt->error);
    }
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$row || empty($row['last_sync_at'])) {
        return null;
    }
    $ts = strtotime($row['last_sync_at']);
    if ($ts === false) {
        return null;
    }
    return gmdate('c', $ts);
}

function startRun(mysqli $db, $providerId)
{
    $stmt = $db->prepare("INSERT INTO cv_sync_runs (id_provider, status, started_at) VALUES (?, 'running', NOW())");
    if (!$stmt) {
        throw new RuntimeException("Prepare failed for run insert: " . $db->error);
    }
    $stmt->bind_param('i', $providerId);
    if (!$stmt->execute()) {
        throw new RuntimeException("Run insert failed: " . $stmt->error);
    }
    $runId = (int)$db->insert_id;
    $stmt->close();
    return $runId;
}

function markRunOk(mysqli $db, $runId, array $details)
{
    $detailsJson = safeJson($details);
    $stmt = $db->prepare("UPDATE cv_sync_runs
                          SET status='ok', ended_at=NOW(), details_json=?, error_message=NULL
                          WHERE id_run=?");
    if (!$stmt) {
        throw new RuntimeException("Prepare failed for run ok update: " . $db->error);
    }
    $stmt->bind_param('si', $detailsJson, $runId);
    if (!$stmt->execute()) {
        throw new RuntimeException("Run ok update failed: " . $stmt->error);
    }
    $stmt->close();
}

function markRunError(mysqli $db, $runId, $errorMessage, ?array $details = null)
{
    $detailsJson = $details ? safeJson($details) : null;
    $stmt = $db->prepare("UPDATE cv_sync_runs
                          SET status='error', ended_at=NOW(), details_json=?, error_message=?
                          WHERE id_run=?");
    if (!$stmt) {
        throw new RuntimeException("Prepare failed for run error update: " . $db->error);
    }
    $stmt->bind_param('ssi', $detailsJson, $errorMessage, $runId);
    if (!$stmt->execute()) {
        throw new RuntimeException("Run error update failed: " . $stmt->error);
    }
    $stmt->close();
}

function updateProviderSyncSuccess(mysqli $db, $providerId)
{
    $stmt = $db->prepare("UPDATE cv_providers SET last_sync_at=NOW(), last_error=NULL WHERE id_provider=?");
    if (!$stmt) {
        throw new RuntimeException("Prepare failed for provider success update: " . $db->error);
    }
    $stmt->bind_param('i', $providerId);
    if (!$stmt->execute()) {
        throw new RuntimeException("Provider success update failed: " . $stmt->error);
    }
    $stmt->close();
}

function updateProviderError(mysqli $db, $providerId, $errorMessage)
{
    $stmt = $db->prepare("UPDATE cv_providers SET last_error=? WHERE id_provider=?");
    if (!$stmt) {
        throw new RuntimeException("Prepare failed for provider error update: " . $db->error);
    }
    $stmt->bind_param('si', $errorMessage, $providerId);
    if (!$stmt->execute()) {
        throw new RuntimeException("Provider error update failed: " . $stmt->error);
    }
    $stmt->close();
}

function syncEndpoint(mysqli $db, $providerId, $runId, $baseUrl, $apiKey, $timeout, $endpoint, $pageSize, $updatedSince)
{
    $pages = 0;
    $totalItems = 0;
    $warnings = array();
    $page = 1;

    while (true) {
        $params = array(
            'rquest' => $endpoint,
            'page' => $page,
            'page_size' => $pageSize,
        );
        if (!empty($updatedSince)) {
            $params['updated_since'] = $updatedSince;
        }

        $response = providerGet($baseUrl, $params, $apiKey, $timeout);
        if (!isset($response['success']) || $response['success'] !== true) {
            $errorMessage = 'Unknown provider error';
            if (isset($response['error']['message'])) {
                $errorMessage = $response['error']['message'];
            }
            throw new RuntimeException("Provider error on {$endpoint}: {$errorMessage}");
        }

        $data = isset($response['data']) && is_array($response['data']) ? $response['data'] : array();
        $items = isset($data['items']) && is_array($data['items']) ? $data['items'] : array();
        $hasMore = !empty($data['has_more']);
        $nextPage = isset($data['next_page']) ? (int)$data['next_page'] : 0;
        $pageWarnings = isset($data['warnings']) && is_array($data['warnings']) ? $data['warnings'] : array();
        if (!empty($pageWarnings)) {
            foreach ($pageWarnings as $w) {
                $warnings[] = $w;
            }
        }

        $db->begin_transaction();
        try {
            $imported = importItemsByEndpoint($db, $providerId, $runId, $endpoint, $items);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            throw $e;
        }

        $pages++;
        $totalItems += $imported;

        if (!$hasMore) {
            break;
        }

        if ($nextPage <= $page) {
            $page++;
        } else {
            $page = $nextPage;
        }
    }

    deactivateMissingByEndpoint($db, $providerId, $runId, $endpoint);

    return array(
        'pages' => $pages,
        'items' => $totalItems,
        'warnings' => array_values(array_unique($warnings)),
    );
}

function providerGet($baseUrl, array $params, $apiKey, $timeout)
{
    $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    $url = strpos($baseUrl, '?') === false ? ($baseUrl . '?' . $query) : ($baseUrl . '&' . $query);

    $headers = array(
        'Accept: application/json',
    );
    if (!empty($apiKey)) {
        $headers[] = 'X-Api-Key: ' . $apiKey;
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("HTTP request failed ({$url}): {$err}");
        }
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } else {
        $context = stream_context_create(array(
            'http' => array(
                'method' => 'GET',
                'timeout' => $timeout,
                'ignore_errors' => true,
                'header' => implode("\r\n", $headers),
            ),
        ));
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            throw new RuntimeException("HTTP request failed ({$url})");
        }
        $httpCode = 0;
        if (isset($http_response_header) && is_array($http_response_header) && !empty($http_response_header[0])) {
            if (preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
                $httpCode = (int)$m[1];
            }
        }
    }

    if ($httpCode !== 200) {
        throw new RuntimeException("Provider HTTP {$httpCode} on {$url}");
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        throw new RuntimeException("Invalid JSON from provider on {$url}");
    }
    return $decoded;
}

function importItemsByEndpoint(mysqli $db, $providerId, $runId, $endpoint, array $items)
{
    switch ($endpoint) {
        case 'sync_stops':
            return importStops($db, $providerId, $runId, $items);
        case 'sync_lines':
            return importLines($db, $providerId, $runId, $items);
        case 'sync_trips':
            return importTrips($db, $providerId, $runId, $items);
        case 'sync_fares':
            return importFares($db, $providerId, $runId, $items);
        default:
            throw new RuntimeException("Unsupported endpoint import handler: {$endpoint}");
    }
}

function importStops(mysqli $db, $providerId, $runId, array $items)
{
    $sql = "INSERT INTO cv_provider_stops
              (id_provider, external_id, name, lat, lon, is_active, source_updated_at, synced_at, last_run_id, raw_json)
            VALUES
              (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
              name=VALUES(name),
              lat=VALUES(lat),
              lon=VALUES(lon),
              is_active=VALUES(is_active),
              source_updated_at=VALUES(source_updated_at),
              synced_at=VALUES(synced_at),
              last_run_id=VALUES(last_run_id),
              raw_json=VALUES(raw_json)";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException("Prepare failed importStops: " . $db->error);
    }

    $syncedAt = gmdate('Y-m-d H:i:s');
    $idProvider = (int)$providerId;
    $externalId = '';
    $name = '';
    $lat = null;
    $lon = null;
    $isActive = 1;
    $sourceUpdatedAt = null;
    $lastRunId = (int)$runId;
    $rawJson = '{}';

    $stmt->bind_param(
        'issssissis',
        $idProvider,
        $externalId,
        $name,
        $lat,
        $lon,
        $isActive,
        $sourceUpdatedAt,
        $syncedAt,
        $lastRunId,
        $rawJson
    );

    $count = 0;
    foreach ($items as $item) {
        $externalId = trim((string)($item['external_id'] ?? ''));
        if ($externalId === '') {
            continue;
        }
        $name = trim((string)($item['name'] ?? ''));
        if ($name === '') {
            $name = 'N/D';
        }
        $lat = normalizeDecimalNullable(isset($item['lat']) ? $item['lat'] : null);
        $lon = normalizeDecimalNullable(isset($item['lon']) ? $item['lon'] : null);
        $isActive = !empty($item['is_active']) ? 1 : 0;
        $sourceUpdatedAt = normalizeDateTimeNullable(isset($item['updated_at']) ? $item['updated_at'] : null);
        $rawJson = safeJson($item);

        if (!$stmt->execute()) {
            throw new RuntimeException("importStops execute failed: " . $stmt->error);
        }
        $count++;
    }

    $stmt->close();
    return $count;
}

function importLines(mysqli $db, $providerId, $runId, array $items)
{
    $sql = "INSERT INTO cv_provider_lines
              (id_provider, external_id, name, color, is_active, is_visible, source_updated_at, synced_at, last_run_id, raw_json)
            VALUES
              (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
              name=VALUES(name),
              color=VALUES(color),
              is_active=VALUES(is_active),
              is_visible=VALUES(is_visible),
              source_updated_at=VALUES(source_updated_at),
              synced_at=VALUES(synced_at),
              last_run_id=VALUES(last_run_id),
              raw_json=VALUES(raw_json)";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException("Prepare failed importLines: " . $db->error);
    }

    $syncedAt = gmdate('Y-m-d H:i:s');
    $idProvider = (int)$providerId;
    $externalId = '';
    $name = '';
    $color = null;
    $isActive = 1;
    $isVisible = 1;
    $sourceUpdatedAt = null;
    $lastRunId = (int)$runId;
    $rawJson = '{}';

    $stmt->bind_param(
        'isssiissis',
        $idProvider,
        $externalId,
        $name,
        $color,
        $isActive,
        $isVisible,
        $sourceUpdatedAt,
        $syncedAt,
        $lastRunId,
        $rawJson
    );

    $count = 0;
    foreach ($items as $item) {
        $externalId = trim((string)($item['external_id'] ?? ''));
        if ($externalId === '') {
            continue;
        }
        $name = trim((string)($item['name'] ?? ''));
        if ($name === '') {
            $name = 'N/D';
        }
        $color = isset($item['color']) ? trim((string)$item['color']) : null;
        if ($color === '') {
            $color = null;
        }
        $isActive = !empty($item['is_active']) ? 1 : 0;
        $isVisible = !empty($item['is_visible']) ? 1 : 0;
        $sourceUpdatedAt = normalizeDateTimeNullable(isset($item['updated_at']) ? $item['updated_at'] : null);
        $rawJson = safeJson($item);

        if (!$stmt->execute()) {
            throw new RuntimeException("importLines execute failed: " . $stmt->error);
        }
        $count++;
    }

    $stmt->close();
    return $count;
}

function importTrips(mysqli $db, $providerId, $runId, array $items)
{
    $tripSql = "INSERT INTO cv_provider_trips
                  (id_provider, external_id, line_external_id, name, tempo_acquisto, direction_id, is_active, is_visible, source_updated_at, synced_at, last_run_id, raw_json)
                VALUES
                  (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                  line_external_id=VALUES(line_external_id),
                  name=VALUES(name),
                  tempo_acquisto=VALUES(tempo_acquisto),
                  direction_id=VALUES(direction_id),
                  is_active=VALUES(is_active),
                  is_visible=VALUES(is_visible),
                  source_updated_at=VALUES(source_updated_at),
                  synced_at=VALUES(synced_at),
                  last_run_id=VALUES(last_run_id),
                  raw_json=VALUES(raw_json)";
    $tripStmt = $db->prepare($tripSql);
    if (!$tripStmt) {
        throw new RuntimeException("Prepare failed importTrips(trips): " . $db->error);
    }

    $delStopsStmt = $db->prepare("DELETE FROM cv_provider_trip_stops WHERE id_provider=? AND trip_external_id=?");
    if (!$delStopsStmt) {
        throw new RuntimeException("Prepare failed importTrips(delete stops): " . $db->error);
    }

    $stopSql = "INSERT INTO cv_provider_trip_stops
                  (id_provider, trip_external_id, sequence_no, stop_external_id, time_local, day_offset, is_active, synced_at, last_run_id, raw_json)
                VALUES
                  (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                  stop_external_id=VALUES(stop_external_id),
                  time_local=VALUES(time_local),
                  day_offset=VALUES(day_offset),
                  is_active=VALUES(is_active),
                  synced_at=VALUES(synced_at),
                  last_run_id=VALUES(last_run_id),
                  raw_json=VALUES(raw_json)";
    $stopStmt = $db->prepare($stopSql);
    if (!$stopStmt) {
        throw new RuntimeException("Prepare failed importTrips(stops): " . $db->error);
    }

    $syncedAt = gmdate('Y-m-d H:i:s');
    $idProvider = (int)$providerId;
    $tripExternalId = '';
    $lineExternalId = null;
    $tripName = null;
    $tempoAcquisto = 30;
    $directionId = 0;
    $isActive = 1;
    $isVisible = 1;
    $sourceUpdatedAt = null;
    $lastRunId = (int)$runId;
    $tripRawJson = '{}';

    $tripStmt->bind_param(
        'isssiiiissis',
        $idProvider,
        $tripExternalId,
        $lineExternalId,
        $tripName,
        $tempoAcquisto,
        $directionId,
        $isActive,
        $isVisible,
        $sourceUpdatedAt,
        $syncedAt,
        $lastRunId,
        $tripRawJson
    );

    $sequenceNo = 0;
    $stopExternalId = '';
    $timeLocal = null;
    $dayOffset = 0;
    $stopIsActive = 1;
    $stopRawJson = '{}';

    $delStopsStmt->bind_param('is', $idProvider, $tripExternalId);
    $stopStmt->bind_param(
        'isissiisis',
        $idProvider,
        $tripExternalId,
        $sequenceNo,
        $stopExternalId,
        $timeLocal,
        $dayOffset,
        $stopIsActive,
        $syncedAt,
        $lastRunId,
        $stopRawJson
    );

    $count = 0;
    foreach ($items as $item) {
        $tripExternalId = trim((string)($item['external_id'] ?? ''));
        if ($tripExternalId === '') {
            continue;
        }

        $lineExternalId = isset($item['line_id']) ? trim((string)$item['line_id']) : null;
        if ($lineExternalId === '') {
            $lineExternalId = null;
        }
        $tripName = isset($item['name']) ? trim((string)$item['name']) : null;
        if ($tripName === '') {
            $tripName = null;
        }

        $tempoAcquisto = isset($item['tempo_acquisto']) ? max(0, (int)$item['tempo_acquisto']) : 30;
        $directionId = isset($item['direction_id']) ? (int)$item['direction_id'] : 0;
        $isActive = !empty($item['is_active']) ? 1 : 0;
        $isVisible = !empty($item['is_visible']) ? 1 : 0;
        $sourceUpdatedAt = normalizeDateTimeNullable(isset($item['updated_at']) ? $item['updated_at'] : null);
        $tripRawJson = safeJson($item);

        if (!$tripStmt->execute()) {
            throw new RuntimeException("importTrips execute(trip) failed: " . $tripStmt->error);
        }

        if (!$delStopsStmt->execute()) {
            throw new RuntimeException("importTrips delete(stops) failed: " . $delStopsStmt->error);
        }

        $stops = isset($item['stops']) && is_array($item['stops']) ? $item['stops'] : array();
        foreach ($stops as $stop) {
            $sequenceNo = isset($stop['sequence']) ? (int)$stop['sequence'] : 0;
            if ($sequenceNo <= 0) {
                continue;
            }
            $stopExternalId = isset($stop['stop_id']) ? trim((string)$stop['stop_id']) : '';
            if ($stopExternalId === '') {
                continue;
            }

            $timeLocal = normalizeTimeNullable(isset($stop['time']) ? $stop['time'] : null);
            $dayOffset = isset($stop['day_offset']) ? (int)$stop['day_offset'] : 0;
            $stopIsActive = !empty($stop['is_active']) ? 1 : 0;
            $stopRawJson = safeJson($stop);

            if (!$stopStmt->execute()) {
                throw new RuntimeException("importTrips execute(stop) failed: " . $stopStmt->error);
            }
        }

        $count++;
    }

    $tripStmt->close();
    $delStopsStmt->close();
    $stopStmt->close();
    return $count;
}

function importFares(mysqli $db, $providerId, $runId, array $items)
{
    $sql = "INSERT INTO cv_provider_fares
              (id_provider, external_id, from_stop_external_id, to_stop_external_id, amount, currency, is_active, source_updated_at, synced_at, last_run_id, raw_json)
            VALUES
              (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
              from_stop_external_id=VALUES(from_stop_external_id),
              to_stop_external_id=VALUES(to_stop_external_id),
              amount=VALUES(amount),
              currency=VALUES(currency),
              is_active=VALUES(is_active),
              source_updated_at=VALUES(source_updated_at),
              synced_at=VALUES(synced_at),
              last_run_id=VALUES(last_run_id),
              raw_json=VALUES(raw_json)";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException("Prepare failed importFares: " . $db->error);
    }

    $syncedAt = gmdate('Y-m-d H:i:s');
    $idProvider = (int)$providerId;
    $externalId = '';
    $fromStop = '';
    $toStop = '';
    $amount = '0.00';
    $currency = 'EUR';
    $isActive = 1;
    $sourceUpdatedAt = null;
    $lastRunId = (int)$runId;
    $rawJson = '{}';

    $stmt->bind_param(
        'isssssissis',
        $idProvider,
        $externalId,
        $fromStop,
        $toStop,
        $amount,
        $currency,
        $isActive,
        $sourceUpdatedAt,
        $syncedAt,
        $lastRunId,
        $rawJson
    );

    $count = 0;
    foreach ($items as $item) {
        $externalId = trim((string)($item['external_id'] ?? ''));
        if ($externalId === '') {
            continue;
        }
        $fromStop = isset($item['from_stop_id']) ? trim((string)$item['from_stop_id']) : '';
        $toStop = isset($item['to_stop_id']) ? trim((string)$item['to_stop_id']) : '';
        if ($fromStop === '' || $toStop === '') {
            continue;
        }

        $amount = normalizeDecimalNullable(isset($item['amount']) ? $item['amount'] : null);
        if ($amount === null) {
            $amount = '0.00';
        }
        $currency = isset($item['currency']) ? strtoupper(trim((string)$item['currency'])) : 'EUR';
        if ($currency === '') {
            $currency = 'EUR';
        }

        $isActive = !empty($item['is_active']) ? 1 : 0;
        $sourceUpdatedAt = normalizeDateTimeNullable(isset($item['updated_at']) ? $item['updated_at'] : null);
        $rawJson = safeJson($item);

        if (!$stmt->execute()) {
            throw new RuntimeException("importFares execute failed: " . $stmt->error);
        }
        $count++;
    }

    $stmt->close();
    return $count;
}

function deactivateMissingByEndpoint(mysqli $db, $providerId, $runId, $endpoint)
{
    $tableByEndpoint = array(
        'sync_stops' => array('cv_provider_stops'),
        'sync_lines' => array('cv_provider_lines'),
        'sync_trips' => array('cv_provider_trips', 'cv_provider_trip_stops'),
        'sync_fares' => array('cv_provider_fares'),
    );

    if (!isset($tableByEndpoint[$endpoint])) {
        return;
    }

    foreach ($tableByEndpoint[$endpoint] as $table) {
        deactivateMissingRows($db, $table, $providerId, $runId);
    }
}

function deactivateMissingRows(mysqli $db, $table, $providerId, $runId)
{
    $allowed = array(
        'cv_provider_stops',
        'cv_provider_lines',
        'cv_provider_trips',
        'cv_provider_trip_stops',
        'cv_provider_fares',
    );
    if (!in_array($table, $allowed, true)) {
        throw new RuntimeException("Table not allowed for deactivate: {$table}");
    }

    $sql = "UPDATE {$table}
            SET is_active = 0
            WHERE id_provider = ?
              AND (last_run_id IS NULL OR last_run_id <> ?)";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException("Prepare failed deactivate {$table}: " . $db->error);
    }
    $stmt->bind_param('ii', $providerId, $runId);
    if (!$stmt->execute()) {
        throw new RuntimeException("Deactivate failed on {$table}: " . $stmt->error);
    }
    $stmt->close();
}

function normalizeDateTimeNullable($value)
{
    if ($value === null) {
        return null;
    }
    $value = trim((string)$value);
    if ($value === '' || $value === '1970-01-01T00:00:00+00:00' || $value === '1970-01-01 00:00:00') {
        return null;
    }
    $ts = strtotime($value);
    if ($ts === false) {
        return null;
    }
    return gmdate('Y-m-d H:i:s', $ts);
}

function normalizeDecimalNullable($value)
{
    if ($value === null || $value === '') {
        return null;
    }
    if (!is_numeric($value)) {
        return null;
    }
    return (string)$value;
}

function normalizeTimeNullable($value)
{
    if ($value === null) {
        return null;
    }
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }

    $parts = explode(':', $value);
    if (count($parts) === 2) {
        $h = (int)$parts[0];
        $m = (int)$parts[1];
        if ($h < 0 || $h > 23 || $m < 0 || $m > 59) {
            return null;
        }
        return sprintf('%02d:%02d:00', $h, $m);
    }

    if (count($parts) === 3) {
        $h = (int)$parts[0];
        $m = (int)$parts[1];
        $s = (int)$parts[2];
        if ($h < 0 || $h > 23 || $m < 0 || $m > 59 || $s < 0 || $s > 59) {
            return null;
        }
        return sprintf('%02d:%02d:%02d', $h, $m, $s);
    }

    return null;
}

function safeJson($value)
{
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return '{}';
    }
    return $json;
}
