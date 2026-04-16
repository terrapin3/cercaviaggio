<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';

/**
 * @return array{
 *   id_provider:int,
 *   code:string,
 *   name:string,
 *   integration_mode:string,
 *   manual_max_lines:int,
 *   manual_max_trips:int
 * }
 */
function cvAccessoIntegrationLoadProvider(mysqli $connection, string $providerCode): array
{
    $providerCode = strtolower(trim($providerCode));
    if ($providerCode === '') {
        return [];
    }

    $stmt = $connection->prepare(
        "SELECT id_provider, code, name, integration_mode, manual_max_lines, manual_max_trips
         FROM cv_providers
         WHERE code = ?
         LIMIT 1"
    );
    if (!$stmt instanceof mysqli_stmt) {
        return [];
    }

    $stmt->bind_param('s', $providerCode);
    if (!$stmt->execute()) {
        $stmt->close();
        return [];
    }

    $res = $stmt->get_result();
    $row = $res instanceof mysqli_result ? $res->fetch_assoc() : null;
    if ($res instanceof mysqli_result) {
        $res->free();
    }
    $stmt->close();

    if (!is_array($row)) {
        return [];
    }

    return [
        'id_provider' => (int) ($row['id_provider'] ?? 0),
        'code' => strtolower(trim((string) ($row['code'] ?? ''))),
        'name' => trim((string) ($row['name'] ?? '')),
        'integration_mode' => strtolower(trim((string) ($row['integration_mode'] ?? 'api'))),
        'manual_max_lines' => max(0, (int) ($row['manual_max_lines'] ?? 0)),
        'manual_max_trips' => max(0, (int) ($row['manual_max_trips'] ?? 0)),
    ];
}

/**
 * @return array<int,array{code:string,name:string}>
 */
function cvAccessoIntegrationManualProviders(mysqli $connection, array $state): array
{
    $allowed = cvAccessoIsAdmin($state) ? null : cvAccessoAllowedProviderCodes($state);
    $allowed = $allowed !== null ? cvCacheNormalizeProviderCodes($allowed) : null;

    $where = ["integration_mode = 'manual'"];
    if (is_array($allowed) && count($allowed) > 0 && !in_array('*', $allowed, true)) {
        $esc = [];
        foreach ($allowed as $code) {
            $code = strtolower(trim((string) $code));
            if ($code === '') {
                continue;
            }
            $esc[] = "'" . $connection->real_escape_string($code) . "'";
        }
        if (count($esc) > 0) {
            $where[] = "code IN (" . implode(',', $esc) . ")";
        }
    }

    $sql = "SELECT code, name
            FROM cv_providers
            WHERE " . implode(' AND ', $where) . "
            ORDER BY name ASC, code ASC";
    $res = $connection->query($sql);
    if (!$res instanceof mysqli_result) {
        return [];
    }

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        if (!is_array($row)) {
            continue;
        }
        $code = strtolower(trim((string) ($row['code'] ?? '')));
        if ($code === '') {
            continue;
        }
        $rows[] = [
            'code' => $code,
            'name' => trim((string) ($row['name'] ?? $code)) ?: $code,
        ];
    }
    $res->free();
    return $rows;
}

function cvAccessoIntegrationGenerateExternalId(string $prefix): string
{
    $prefix = strtolower(trim($prefix));
    if ($prefix === '') {
        $prefix = 'm';
    }

    try {
        $rand = bin2hex(random_bytes(6));
    } catch (Throwable $e) {
        $rand = substr(md5((string) microtime(true) . ':' . mt_rand()), 0, 12);
    }

    return $prefix . '_' . date('YmdHis') . '_' . $rand;
}

/**
 * Cerca coordinate lat/lon tramite OpenStreetMap (Nominatim).
 * Ritorna null se non trova risultati o se la richiesta fallisce.
 *
 * @return array{lat:string,lon:string}|null
 */
function cvAccessoGeoLookupLatLon(string $query): ?array
{
    $query = trim($query);
    if ($query === '') {
        return null;
    }

    $queries = [$query, $query . ', Italia'];
    foreach ($queries as $q) {
        $url = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&addressdetails=0&q=' . rawurlencode($q);

        $response = null;
        if (function_exists('curl_init')) {
            $ch = curl_init();
            if ($ch !== false) {
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
                curl_setopt($ch, CURLOPT_TIMEOUT, 7);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Accept: application/json',
                    'Accept-Language: it-IT,it;q=0.9,en;q=0.6',
                    'User-Agent: Cercaviaggio/1.0 (https://cercaviaggio.it)',
                    'Referer: https://cercaviaggio.it',
                ]);
                $response = curl_exec($ch);
                curl_close($ch);
                if (!is_string($response) || $response === '') {
                    $response = null;
                }
            }
        } else {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 7,
                    'header' => implode("\r\n", [
                        'Accept: application/json',
                        'Accept-Language: it-IT,it;q=0.9,en;q=0.6',
                        'User-Agent: Cercaviaggio/1.0 (https://cercaviaggio.it)',
                        'Referer: https://cercaviaggio.it',
                    ]),
                ],
            ]);
            $raw = @file_get_contents($url, false, $context);
            if (is_string($raw) && $raw !== '') {
                $response = $raw;
            }
        }

        if (!is_string($response) || $response === '') {
            continue;
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded) || count($decoded) === 0 || !is_array($decoded[0])) {
            continue;
        }

        $lat = trim((string) ($decoded[0]['lat'] ?? ''));
        $lon = trim((string) ($decoded[0]['lon'] ?? ''));
        if ($lat === '' || $lon === '') {
            continue;
        }

        return ['lat' => $lat, 'lon' => $lon];
    }

    return null;
}

/**
 * Suggerimenti indirizzo/luogo (autocomplete) tramite OpenStreetMap Nominatim.
 *
 * @return array<int,array{label:string,lat:string,lon:string}>
 */
function cvAccessoGeoSuggest(string $query, int $limit = 6): array
{
    $query = trim($query);
    if ($query === '') {
        return [];
    }

    $limit = max(1, min(10, $limit));
    $url = 'https://nominatim.openstreetmap.org/search?format=jsonv2&addressdetails=1&limit=' . $limit . '&q=' . rawurlencode($query);

    $response = null;
    if (function_exists('curl_init')) {
        $ch = curl_init();
        if ($ch !== false) {
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
            curl_setopt($ch, CURLOPT_TIMEOUT, 7);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Accept: application/json',
                'Accept-Language: it-IT,it;q=0.9,en;q=0.6',
                'User-Agent: Cercaviaggio/1.0 (https://cercaviaggio.it)',
                'Referer: https://cercaviaggio.it',
            ]);
            $response = curl_exec($ch);
            curl_close($ch);
            if (!is_string($response) || $response === '') {
                $response = null;
            }
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 7,
                'header' => implode("\r\n", [
                    'Accept: application/json',
                    'Accept-Language: it-IT,it;q=0.9,en;q=0.6',
                    'User-Agent: Cercaviaggio/1.0 (https://cercaviaggio.it)',
                    'Referer: https://cercaviaggio.it',
                ]),
            ],
        ]);
        $raw = @file_get_contents($url, false, $context);
        if (is_string($raw) && $raw !== '') {
            $response = $raw;
        }
    }

    if (!is_string($response) || $response === '') {
        return [];
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        return [];
    }

    $out = [];
    foreach ($decoded as $row) {
        if (!is_array($row)) {
            continue;
        }
        $lat = trim((string) ($row['lat'] ?? ''));
        $lon = trim((string) ($row['lon'] ?? ''));
        if ($lat === '' || $lon === '') {
            continue;
        }
        $label = trim((string) ($row['display_name'] ?? ''));
        if ($label === '') {
            $label = $query;
        }
        $out[] = ['label' => $label, 'lat' => $lat, 'lon' => $lon];
    }

    return $out;
}

/**
 * @return array{provider:array<string,mixed>, providers:array<int,array{code:string,name:string}>}
 */
function cvAccessoIntegrationResolveProvider(mysqli $connection, array $state): array
{
    $providers = cvAccessoIntegrationManualProviders($connection, $state);
    if (count($providers) === 0) {
        throw new RuntimeException('Nessun provider configurato con Integrazione = Interfaccia nel tuo scope.');
    }

    $requested = strtolower(trim((string) ($_GET['provider'] ?? '')));
    if ($requested === '') {
        $requested = $providers[0]['code'];
    }

    $allowed = array_fill_keys(array_map(static fn(array $p): string => $p['code'], $providers), true);
    if (!isset($allowed[$requested])) {
        $requested = $providers[0]['code'];
    }

    $provider = cvAccessoIntegrationLoadProvider($connection, $requested);
    if (count($provider) === 0 || (int) ($provider['id_provider'] ?? 0) <= 0) {
        throw new RuntimeException('Provider non valido per integrazione.');
    }
    if (strtolower((string) ($provider['integration_mode'] ?? 'api')) !== 'manual') {
        throw new RuntimeException('Questo provider non è configurato con Integrazione = Interfaccia.');
    }

    return ['provider' => $provider, 'providers' => $providers];
}

function cvAccessoIntegrationRenderProviderSelect(array $providers, string $currentCode): void
{
    if (count($providers) <= 1) {
        return;
    }
    ?>
    <form method="get" style="margin-bottom:10px;">
        <div class="row">
            <div class="col-md-4 form-group">
                <label>Provider</label>
                <select class="form-control" name="provider" onchange="this.form.submit()">
                    <?php foreach ($providers as $p): ?>
                        <option value="<?= cvAccessoH($p['code']) ?>"<?= $p['code'] === $currentCode ? ' selected' : '' ?>>
                            <?= cvAccessoH($p['name']) ?> (<?= cvAccessoH($p['code']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </form>
    <?php
}
