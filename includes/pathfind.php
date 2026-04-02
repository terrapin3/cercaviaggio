<?php
declare(strict_types=1);

require_once __DIR__ . '/provider_quote.php';
require_once __DIR__ . '/place_tools.php';
require_once __DIR__ . '/runtime_settings.php';
require_once __DIR__ . '/pathfind_transfer_points.php';

if (!function_exists('cvPfParseDate')) {
    function cvPfParseDate(string $itDate): ?DateTimeImmutable
    {
        $clean = trim($itDate);
        if ($clean === '') {
            return null;
        }

        $tz = new DateTimeZone('Europe/Rome');
        $dt = DateTimeImmutable::createFromFormat('d/m/Y', $clean, $tz);
        if (!$dt instanceof DateTimeImmutable) {
            return null;
        }

        $errors = DateTimeImmutable::getLastErrors();
        if (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) {
            return null;
        }

        return $dt->setTime(0, 0, 0);
    }
}

if (!function_exists('cvPfNormalizeStopName')) {
    function cvPfNormalizeStopName(string $value): string
    {
        $value = trim(mb_strtolower($value, 'UTF-8'));
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/\s+/', ' ', $value) ?? $value;
        $value = strtr(
            $value,
            [
                'à' => 'a',
                'á' => 'a',
                'â' => 'a',
                'ä' => 'a',
                'è' => 'e',
                'é' => 'e',
                'ê' => 'e',
                'ë' => 'e',
                'ì' => 'i',
                'í' => 'i',
                'î' => 'i',
                'ï' => 'i',
                'ò' => 'o',
                'ó' => 'o',
                'ô' => 'o',
                'ö' => 'o',
                'ù' => 'u',
                'ú' => 'u',
                'û' => 'u',
                'ü' => 'u',
                '’' => '\'',
            ]
        );

        return trim($value);
    }
}

if (!function_exists('cvPfDebugEnabled')) {
    function cvPfDebugEnabled(): bool
    {
        return false;
    }
}

if (!function_exists('cvPfNoCacheRequested')) {
    function cvPfNoCacheRequested(): bool
    {
        return false;
    }
}

if (!function_exists('cvPfDebugLog')) {
    /**
     * @param array<string,mixed> $context
     */
    function cvPfDebugLog(string $event, array $context = []): void
    {
        return;
    }
}

if (!function_exists('cvPfSearchTuning')) {
    /**
     * Parametri conservativi per tenere basso il numero di combinazioni.
     *
     * @return array<string,mixed>
     */
    function cvPfSearchTuning(?mysqli $connection = null): array
    {
        $tuning = [
            'from_radius_km' => 0.0,
            'to_radius_km' => 0.0,
            'nearby_max_extras' => 12,
            'enable_auto_transfers' => true,
            'transfer_max_wait_minutes' => 120,
            'transfer_same_name_max_km' => 1.2,
            'transfer_nearby_max_km' => 0.4,
            'two_transfer_trigger_max_solutions' => 12,
            'all_rows_limit' => 5000,
        ];

        if ($connection instanceof mysqli) {
            $runtime = cvRuntimeSettings($connection);
            $maxDistanceKm = (float) ($runtime['pathfind_transfer_max_distance_km'] ?? $tuning['transfer_nearby_max_km']);
            $tuning['transfer_max_wait_minutes'] = (int) ($runtime['pathfind_transfer_max_wait_minutes'] ?? $tuning['transfer_max_wait_minutes']);
            $tuning['from_radius_km'] = max(0.0, $maxDistanceKm);
            $tuning['to_radius_km'] = max(0.0, $maxDistanceKm);
            $tuning['nearby_max_extras'] = $maxDistanceKm > 0.0 ? 12 : 0;
            // Evita di azzerare gli scali se la distanza runtime viene impostata a 0.
            // In quel caso manteniamo le soglie conservative di default per i cambi.
            if ($maxDistanceKm > 0.0) {
                $tuning['transfer_same_name_max_km'] = max(0.8, $maxDistanceKm);
                $tuning['transfer_nearby_max_km'] = $maxDistanceKm;
            }
            $tuning['two_transfer_trigger_max_solutions'] = (int) ($runtime['pathfind_two_transfer_trigger_max_solutions'] ?? $tuning['two_transfer_trigger_max_solutions']);
            $tuning['all_rows_limit'] = (int) ($runtime['pathfind_all_rows_limit'] ?? $tuning['all_rows_limit']);
            $tuning['nearby_max_extras'] = max(0, min(40, (int) $tuning['nearby_max_extras']));
            $tuning['two_transfer_trigger_max_solutions'] = max(0, min(30, (int) $tuning['two_transfer_trigger_max_solutions']));
            $tuning['all_rows_limit'] = max(1000, min(8000, (int) $tuning['all_rows_limit']));
        }

        return $tuning;
    }
}

if (!function_exists('cvPfSearchCacheTtlSeconds')) {
    function cvPfSearchCacheTtlSeconds(?mysqli $connection = null): int
    {
        $ttl = 600;
        if ($connection instanceof mysqli) {
            $runtime = cvRuntimeSettings($connection);
            $ttl = (int) ($runtime['pathfind_cache_ttl_seconds'] ?? $ttl);
        }
        return max(60, min(3600, $ttl));
    }
}

if (!function_exists('cvPfParseStopRef')) {
    /**
     * Supporta:
     * - "provider|stopExternalId"
     * - "stopExternalId"
     *
     * @return array{provider_code:?string,external_id:string}|null
     */
    function cvPfParseStopRef(string $rawRef): ?array
    {
        $ref = trim($rawRef);
        if ($ref === '') {
            return null;
        }

        // Optional compact token format: r~<base64url(provider|external_id)>
        if (strncmp($ref, 'r~', 2) === 0) {
            $encoded = substr($ref, 2);
            $encoded = strtr($encoded, '-_', '+/');
            $pad = strlen($encoded) % 4;
            if ($pad > 0) {
                $encoded .= str_repeat('=', 4 - $pad);
            }
            $decoded = base64_decode($encoded, true);
            if (is_string($decoded) && trim($decoded) !== '') {
                $ref = trim($decoded);
            }
        }

        $providerCode = null;
        $externalId = $ref;

        if (strpos($ref, '|') !== false) {
            [$providerCodeRaw, $externalRaw] = explode('|', $ref, 2);
            $providerCodeRaw = trim($providerCodeRaw);
            $externalRaw = trim($externalRaw);
            if ($providerCodeRaw !== '') {
                $providerCode = $providerCodeRaw;
            }
            $externalId = $externalRaw;
        }

        if ($externalId === '') {
            return null;
        }

        return [
            'provider_code' => $providerCode,
            'external_id' => $externalId,
        ];
    }
}

if (!function_exists('cvPfCatalogVersion')) {
    /**
     * @return array<string,string>
     */
    function cvPfProviderVersionMap(mysqli $connection): array
    {
        static $cache = null;
        if (is_array($cache)) {
            return $cache;
        }

        $sql = "SELECT code, UNIX_TIMESTAMP(last_sync_at) AS v
                FROM cv_providers
                WHERE is_active = 1
                ORDER BY code ASC";
        $result = $connection->query($sql);
        if (!$result instanceof mysqli_result) {
            $cache = [];
            return $cache;
        }

        $map = [];
        while ($row = $result->fetch_assoc()) {
            if (!is_array($row)) {
                continue;
            }

            $code = trim((string) ($row['code'] ?? ''));
            if ($code === '') {
                continue;
            }

            $map[$code] = isset($row['v']) ? (string) ((int) $row['v']) : '0';
        }

        $result->free();
        $cache = $map;
        return $cache;
    }

    /**
     * @param array<string,string> $providerVersions
     * @param array<int,string> $providerCodes
     */
    function cvPfProviderVersionToken(array $providerVersions, array $providerCodes = []): string
    {
        $codes = [];
        if (count($providerCodes) === 0) {
            $codes = array_keys($providerVersions);
        } else {
            foreach ($providerCodes as $providerCode) {
                $providerCode = trim((string) $providerCode);
                if ($providerCode === '') {
                    continue;
                }

                $codes[$providerCode] = $providerCode;
            }
            $codes = array_values($codes);
        }

        sort($codes, SORT_STRING);
        if (count($codes) === 0) {
            return '0';
        }

        $parts = [];
        foreach ($codes as $providerCode) {
            $parts[] = $providerCode . ':' . (string) ($providerVersions[$providerCode] ?? '0');
        }

        return hash('sha256', implode('|', $parts));
    }

    function cvPfCatalogVersion(mysqli $connection): string
    {
        return cvPfProviderVersionToken(cvPfProviderVersionMap($connection));
    }
}

if (!function_exists('cvPfProviderVersion')) {
    function cvPfProviderVersion(mysqli $connection, string $providerCode): string
    {
        $providerCode = trim($providerCode);
        if ($providerCode === '') {
            return '0';
        }

        $map = cvPfProviderVersionMap($connection);
        return (string) ($map[$providerCode] ?? '0');
    }
}

if (!function_exists('cvPfCacheDir')) {
    function cvPfCacheDir(): string
    {
        return dirname(__DIR__) . '/files/cache/pathfind';
    }
}

if (!function_exists('cvPfCacheRead')) {
    /**
     * @return array<string,mixed>|null
     */
    function cvPfCacheRead(string $key, int $ttlSeconds): ?array
    {
        $path = cvPfCacheDir() . '/' . $key . '.json';
        if (!is_file($path)) {
            return null;
        }

        $mtime = @filemtime($path);
        if (!is_int($mtime) || (time() - $mtime) > $ttlSeconds) {
            return null;
        }

        $raw = @file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }
}

if (!function_exists('cvPfCacheWrite')) {
    /**
     * @param array<string,mixed> $payload
     */
    function cvPfCacheWrite(string $key, array $payload): void
    {
        $dir = cvPfCacheDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        if (!is_dir($dir) || !is_writable($dir)) {
            return;
        }

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return;
        }

        @file_put_contents($dir . '/' . $key . '.json', $json, LOCK_EX);
    }
}

if (!function_exists('cvPfFetchStopCandidates')) {
    /**
     * @return array<int,array<string,mixed>>
     */
    function cvPfFetchStopCandidates(mysqli $connection, array $stopRef): array
    {
        $providerCode = isset($stopRef['provider_code']) ? (string) $stopRef['provider_code'] : '';
        $externalId = isset($stopRef['external_id']) ? (string) $stopRef['external_id'] : '';
        if ($externalId === '') {
            return [];
        }

        if ($providerCode === 'place') {
            $idPlace = (int) $externalId;
            if ($idPlace <= 0) {
                return [];
            }

            return cvPlacesExpandToProviderStops($connection, $idPlace, 24);
        }

        $sql = "SELECT
                  p.id_provider,
                  p.code AS provider_code,
                  p.name AS provider_name,
                  s.external_id,
                  s.name,
                  s.lat,
                  s.lon
                FROM cv_provider_stops s
                INNER JOIN cv_providers p
                  ON p.id_provider = s.id_provider
                WHERE p.is_active = 1
                  AND s.is_active = 1
                  AND s.external_id = ?";
        if ($providerCode !== '') {
            $sql .= " AND p.code = ?";
        }
        $sql .= " ORDER BY p.code ASC";

        $statement = $connection->prepare($sql);
        if (!$statement instanceof mysqli_stmt) {
            return [];
        }

        if ($providerCode !== '') {
            $statement->bind_param('ss', $externalId, $providerCode);
        } else {
            $statement->bind_param('s', $externalId);
        }

        if (!$statement->execute()) {
            $statement->close();
            return [];
        }

        $result = $statement->get_result();
        if (!$result instanceof mysqli_result) {
            $statement->close();
            return [];
        }

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            if (!is_array($row)) {
                continue;
            }

            $rows[] = [
                'id_provider' => (int) ($row['id_provider'] ?? 0),
                'provider_code' => (string) ($row['provider_code'] ?? ''),
                'provider_name' => (string) ($row['provider_name'] ?? ''),
                'external_id' => (string) ($row['external_id'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                'lat' => isset($row['lat']) ? (float) $row['lat'] : null,
                'lon' => isset($row['lon']) ? (float) $row['lon'] : null,
            ];
        }

        $statement->close();
        return $rows;
    }
}

if (!function_exists('cvPfBuildStopFilter')) {
    /**
     * @param array<int,array<string,mixed>> $stops
     * @param string $providerField
     * @param string $externalField
     * @return array{sql:string,types:string,params:array<int,mixed>}
     */
    function cvPfBuildStopFilter(array $stops, string $providerField, string $externalField): array
    {
        $parts = [];
        $types = '';
        $params = [];

        foreach ($stops as $stop) {
            $idProvider = (int) ($stop['id_provider'] ?? 0);
            $externalId = (string) ($stop['external_id'] ?? '');
            if ($idProvider <= 0 || $externalId === '') {
                continue;
            }

            $parts[] = "({$providerField} = ? AND {$externalField} = ?)";
            $types .= 'is';
            $params[] = $idProvider;
            $params[] = $externalId;
        }

        if (count($parts) === 0) {
            return ['sql' => '1 = 0', 'types' => '', 'params' => []];
        }

        return [
            'sql' => '(' . implode(' OR ', $parts) . ')',
            'types' => $types,
            'params' => $params,
        ];
    }
}

if (!function_exists('cvPfProviderStopKey')) {
    function cvPfProviderStopKey(int $idProvider, string $externalId): string
    {
        return (string) $idProvider . '|' . $externalId;
    }
}

if (!function_exists('cvPfBuildStopDistanceMap')) {
    /**
     * @param array<int,array<string,mixed>> $stops
     * @return array<string,float>
     */
    function cvPfBuildStopDistanceMap(array $stops): array
    {
        $map = [];
        foreach ($stops as $stop) {
            $idProvider = (int) ($stop['id_provider'] ?? 0);
            $externalId = (string) ($stop['external_id'] ?? '');
            if ($idProvider <= 0 || $externalId === '') {
                continue;
            }
            $distance = isset($stop['distance_from_requested_km']) ? (float) $stop['distance_from_requested_km'] : 0.0;
            $key = cvPfProviderStopKey($idProvider, $externalId);
            if (!isset($map[$key]) || $distance < $map[$key]) {
                $map[$key] = max(0.0, $distance);
            }
        }
        return $map;
    }
}

if (!function_exists('cvPfExpandNearbyStops')) {
    /**
     * Espande le fermate candidate includendo fermate vicine al punto richiesto.
     *
     * @param array<int,array<string,mixed>> $seedStops
     * @return array<int,array<string,mixed>>
     */
    function cvPfExpandNearbyStops(
        mysqli $connection,
        array $seedStops,
        float $radiusKm = 12.0,
        int $maxExtras = 40
    ): array {
        if (count($seedStops) === 0) {
            return [];
        }

        $maxExtras = max(0, min(400, $maxExtras));

        /** @var array<string,bool> $seedKeys */
        $seedKeys = [];
        $seedWithGeo = [];
        $expanded = [];

        foreach ($seedStops as $stop) {
            $idProvider = (int) ($stop['id_provider'] ?? 0);
            $externalId = (string) ($stop['external_id'] ?? '');
            if ($idProvider <= 0 || $externalId === '') {
                continue;
            }

            $key = cvPfProviderStopKey($idProvider, $externalId);
            $seedKeys[$key] = true;
            $expanded[] = array_merge($stop, [
                'distance_from_requested_km' => 0.0,
            ]);

            $lat = $stop['lat'] ?? null;
            $lon = $stop['lon'] ?? null;
            if (is_numeric($lat) && is_numeric($lon)) {
                $seedWithGeo[] = [
                    'lat' => (float) $lat,
                    'lon' => (float) $lon,
                ];
            }
        }

        if ($radiusKm <= 0.0 || $maxExtras === 0 || count($seedWithGeo) === 0) {
            return $expanded;
        }

        $radiusKm = min(50.0, $radiusKm);

        $sql = "SELECT
                  s.id_provider,
                  p.code AS provider_code,
                  p.name AS provider_name,
                  s.external_id,
                  s.name,
                  s.lat,
                  s.lon
                FROM cv_provider_stops s
                INNER JOIN cv_providers p
                  ON p.id_provider = s.id_provider
                WHERE s.is_active = 1
                  AND p.is_active = 1
                  AND s.lat IS NOT NULL
                  AND s.lon IS NOT NULL
                ORDER BY s.name ASC
                LIMIT 12000";

        $result = $connection->query($sql);
        if (!$result instanceof mysqli_result) {
            return $expanded;
        }

        $extras = [];
        while ($row = $result->fetch_assoc()) {
            if (!is_array($row)) {
                continue;
            }

            $idProvider = (int) ($row['id_provider'] ?? 0);
            $externalId = (string) ($row['external_id'] ?? '');
            if ($idProvider <= 0 || $externalId === '') {
                continue;
            }

            $candidateKey = cvPfProviderStopKey($idProvider, $externalId);
            if (isset($seedKeys[$candidateKey])) {
                continue;
            }

            $lat = $row['lat'] ?? null;
            $lon = $row['lon'] ?? null;
            if (!is_numeric($lat) || !is_numeric($lon)) {
                continue;
            }

            $candidateLat = (float) $lat;
            $candidateLon = (float) $lon;
            $bestDistance = INF;
            foreach ($seedWithGeo as $geo) {
                $distance = cvPfHaversineKm(
                    (float) $geo['lat'],
                    (float) $geo['lon'],
                    $candidateLat,
                    $candidateLon
                );
                if ($distance < $bestDistance) {
                    $bestDistance = $distance;
                }
            }

            if ($bestDistance > $radiusKm) {
                continue;
            }

            $extras[] = [
                'id_provider' => $idProvider,
                'provider_code' => (string) ($row['provider_code'] ?? ''),
                'provider_name' => (string) ($row['provider_name'] ?? ''),
                'external_id' => $externalId,
                'name' => (string) ($row['name'] ?? ''),
                'lat' => $candidateLat,
                'lon' => $candidateLon,
                'distance_from_requested_km' => (float) $bestDistance,
            ];
        }
        $result->free();

        if (count($extras) > 1) {
            usort(
                $extras,
                static function (array $a, array $b): int {
                    return ((float) ($a['distance_from_requested_km'] ?? 0.0)) <=> ((float) ($b['distance_from_requested_km'] ?? 0.0));
                }
            );
        }

        if (count($extras) > $maxExtras) {
            $extras = array_slice($extras, 0, $maxExtras);
        }

        return array_merge($expanded, $extras);
    }
}

if (!function_exists('cvPfBindDynamicParams')) {
    /**
     * @param array<int,mixed> $params
     */
    function cvPfBindDynamicParams(mysqli_stmt $statement, string $types, array $params): bool
    {
        if ($types === '') {
            return true;
        }

        $bindParams = [$types];
        foreach ($params as $index => $value) {
            $bindParams[] = &$params[$index];
        }

        return call_user_func_array([$statement, 'bind_param'], $bindParams);
    }
}

if (!function_exists('cvPfQuerySegments')) {
    /**
     * @param array<int,array<string,mixed>> $fromStops
     * @param array<int,array<string,mixed>> $toStops
     * @return array<int,array<string,mixed>>
     */
    function cvPfQuerySegments(mysqli $connection, array $fromStops, array $toStops, int $limit = 2000): array
    {
        $safeLimit = max(100, min(10000, $limit));

        $whereParts = [
            'sf.is_active = 1',
            'st.is_active = 1',
            'st.sequence_no > sf.sequence_no',
            't.is_active = 1',
            't.is_visible = 1',
            'p.is_active = 1',
            'sfrom.is_active = 1',
            'sto.is_active = 1',
        ];
        $types = '';
        $params = [];

        if (count($fromStops) > 0) {
            $fromFilter = cvPfBuildStopFilter($fromStops, 'sf.id_provider', 'sf.stop_external_id');
            $whereParts[] = $fromFilter['sql'];
            $types .= $fromFilter['types'];
            $params = array_merge($params, $fromFilter['params']);
        }

        if (count($toStops) > 0) {
            $toFilter = cvPfBuildStopFilter($toStops, 'st.id_provider', 'st.stop_external_id');
            $whereParts[] = $toFilter['sql'];
            $types .= $toFilter['types'];
            $params = array_merge($params, $toFilter['params']);
        }

        $whereSql = implode("\n  AND ", $whereParts);

        $sql = "SELECT
                  p.id_provider,
                  p.code AS provider_code,
                  p.name AS provider_name,
                  t.external_id AS trip_external_id,
                  t.tempo_acquisto AS trip_tempo_acquisto,
                  COALESCE(NULLIF(t.name, ''), NULLIF(l.name, ''), CONCAT('Corsa ', t.external_id)) AS trip_name,
                  sf.stop_external_id AS from_stop_id,
                  sfrom.name AS from_stop_name,
                  sfrom.lat AS from_lat,
                  sfrom.lon AS from_lon,
                  sf.sequence_no AS from_seq,
                  sf.time_local AS dep_time,
                  sf.day_offset AS dep_day_offset,
                  st.stop_external_id AS to_stop_id,
                  sto.name AS to_stop_name,
                  sto.lat AS to_lat,
                  sto.lon AS to_lon,
                  st.sequence_no AS to_seq,
                  st.time_local AS arr_time,
                  st.day_offset AS arr_day_offset,
                  f.fare_id,
                  f.amount,
                  f.currency
                FROM cv_provider_trip_stops sf
                INNER JOIN cv_provider_trip_stops st
                  ON st.id_provider = sf.id_provider
                 AND st.trip_external_id = sf.trip_external_id
                INNER JOIN cv_provider_trips t
                  ON t.id_provider = sf.id_provider
                 AND t.external_id = sf.trip_external_id
                INNER JOIN cv_providers p
                  ON p.id_provider = sf.id_provider
                LEFT JOIN cv_provider_lines l
                  ON l.id_provider = t.id_provider
                 AND l.external_id = t.line_external_id
                INNER JOIN cv_provider_stops sfrom
                  ON sfrom.id_provider = sf.id_provider
                 AND sfrom.external_id = sf.stop_external_id
                INNER JOIN cv_provider_stops sto
                  ON sto.id_provider = st.id_provider
                 AND sto.external_id = st.stop_external_id
                INNER JOIN (
                    SELECT
                      id_provider,
                      from_stop_external_id,
                      to_stop_external_id,
                      MIN(amount) AS amount,
                      MAX(currency) AS currency,
                      MIN(external_id) AS fare_id
                    FROM cv_provider_fares
                    WHERE is_active = 1
                    GROUP BY id_provider, from_stop_external_id, to_stop_external_id
                ) f
                  ON f.id_provider = sf.id_provider
                 AND f.from_stop_external_id = sf.stop_external_id
                 AND f.to_stop_external_id = st.stop_external_id
                WHERE {$whereSql}
                ORDER BY sf.id_provider ASC, sf.trip_external_id ASC, sf.sequence_no ASC, st.sequence_no ASC
                LIMIT {$safeLimit}";

        $statement = $connection->prepare($sql);
        if (!$statement instanceof mysqli_stmt) {
            return [];
        }

        if (!cvPfBindDynamicParams($statement, $types, $params)) {
            $statement->close();
            return [];
        }

        if (!$statement->execute()) {
            $statement->close();
            return [];
        }

        $result = $statement->get_result();
        if (!$result instanceof mysqli_result) {
            $statement->close();
            return [];
        }

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            if (!is_array($row)) {
                continue;
            }
            $rows[] = $row;
        }

        $statement->close();
        return $rows;
    }
}

if (!function_exists('cvPfComposeLegDateTime')) {
    function cvPfComposeLegDateTime(DateTimeImmutable $baseDate, ?string $timeLocal, int $dayOffset): ?DateTimeImmutable
    {
        if ($timeLocal === null || trim($timeLocal) === '') {
            return null;
        }

        $parts = explode(':', $timeLocal);
        if (count($parts) < 2) {
            return null;
        }

        $hours = (int) ($parts[0] ?? 0);
        $minutes = (int) ($parts[1] ?? 0);
        $seconds = (int) ($parts[2] ?? 0);

        if ($hours < 0 || $hours > 23 || $minutes < 0 || $minutes > 59 || $seconds < 0 || $seconds > 59) {
            return null;
        }

        return $baseDate
            ->modify(($dayOffset >= 0 ? '+' : '') . $dayOffset . ' day')
            ->setTime($hours, $minutes, $seconds);
    }
}

if (!function_exists('cvPfHaversineKm')) {
    function cvPfHaversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371.0;
        $latFrom = deg2rad($lat1);
        $lonFrom = deg2rad($lon1);
        $latTo = deg2rad($lat2);
        $lonTo = deg2rad($lon2);

        $latDelta = $latTo - $latFrom;
        $lonDelta = $lonTo - $lonFrom;

        $angle = 2 * asin(sqrt(pow(sin($latDelta / 2), 2) + cos($latFrom) * cos($latTo) * pow(sin($lonDelta / 2), 2)));
        return $earthRadius * $angle;
    }
}

if (!function_exists('cvPfBuildLeg')) {
    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>|null
     */
    function cvPfBuildLeg(array $row, DateTimeImmutable $baseDate): ?array
    {
        $dep = cvPfComposeLegDateTime(
            $baseDate,
            isset($row['dep_time']) ? (string) $row['dep_time'] : null,
            (int) ($row['dep_day_offset'] ?? 0)
        );
        $arr = cvPfComposeLegDateTime(
            $baseDate,
            isset($row['arr_time']) ? (string) $row['arr_time'] : null,
            (int) ($row['arr_day_offset'] ?? 0)
        );
        if (!$dep instanceof DateTimeImmutable || !$arr instanceof DateTimeImmutable) {
            return null;
        }

        if ($arr <= $dep) {
            $arr = $arr->modify('+1 day');
        }

        $amount = isset($row['amount']) ? (float) $row['amount'] : 0.0;
        $currency = trim((string) ($row['currency'] ?? 'EUR'));
        if ($currency === '') {
            $currency = 'EUR';
        }

        $fareId = trim((string) ($row['fare_id'] ?? ''));
        if ($fareId === '' || $amount <= 0.0) {
            return null;
        }

        $tempoAcquisto = (int) ($row['trip_tempo_acquisto'] ?? 30);
        if ($tempoAcquisto <= 0 || $tempoAcquisto > 300) {
            $tempoAcquisto = 30;
        }

        return [
            'provider_id' => (int) ($row['id_provider'] ?? 0),
            'provider_code' => (string) ($row['provider_code'] ?? ''),
            'provider_name' => (string) ($row['provider_name'] ?? ''),
            'trip_external_id' => (string) ($row['trip_external_id'] ?? ''),
            'tempo_acquisto' => $tempoAcquisto,
            'trip_name' => (string) ($row['trip_name'] ?? ''),
            'from_stop_id' => (string) ($row['from_stop_id'] ?? ''),
            'from_stop_name' => (string) ($row['from_stop_name'] ?? ''),
            'from_lat' => isset($row['from_lat']) ? (float) $row['from_lat'] : null,
            'from_lon' => isset($row['from_lon']) ? (float) $row['from_lon'] : null,
            'to_stop_id' => (string) ($row['to_stop_id'] ?? ''),
            'to_stop_name' => (string) ($row['to_stop_name'] ?? ''),
            'to_lat' => isset($row['to_lat']) ? (float) $row['to_lat'] : null,
            'to_lon' => isset($row['to_lon']) ? (float) $row['to_lon'] : null,
            'departure' => $dep,
            'arrival' => $arr,
            'fare_id' => $fareId,
            'amount' => $amount,
            'provider_amount' => $amount,
            'original_amount' => $amount,
            'discount_percent' => 0.0,
            'currency' => $currency,
        ];
    }
}

if (!function_exists('cvPfIsLegBookableNow')) {
    /**
     * @param array<string,mixed> $leg
     */
    function cvPfIsLegBookableNow(array $leg): bool
    {
        if (!isset($leg['departure']) || !($leg['departure'] instanceof DateTimeImmutable)) {
            return false;
        }

        /** @var DateTimeImmutable $departure */
        $departure = $leg['departure'];
        $tempoAcquisto = (int) ($leg['tempo_acquisto'] ?? 30);
        if ($tempoAcquisto <= 0 || $tempoAcquisto > 300) {
            $tempoAcquisto = 30;
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Rome'));
        $threshold = $now->modify('+' . $tempoAcquisto . ' minutes');

        return $departure->getTimestamp() > $threshold->getTimestamp();
    }
}

if (!function_exists('cvPfCsvTripIdSet')) {
    /**
     * @return array<int,bool>
     */
    function cvPfCsvTripIdSet(string $csv): array
    {
        $set = [];
        foreach (explode(',', $csv) as $token) {
            $id = (int) trim($token);
            if ($id > 0) {
                $set[$id] = true;
            }
        }
        return $set;
    }
}

if (!function_exists('cvPfDateTimeInRange')) {
    function cvPfDateTimeInRange(string $value, string $from, string $to): bool
    {
        if ($value === '' || $from === '' || $to === '') {
            return false;
        }

        return $value >= $from && $value <= $to;
    }
}

if (!function_exists('cvPfAnyTimeWindowMatches')) {
    /**
     * @param array<int,array{da:string,a:string}> $windows
     */
    function cvPfAnyTimeWindowMatches(array $windows, string $value): bool
    {
        foreach ($windows as $window) {
            $from = isset($window['da']) ? (string) $window['da'] : '';
            $to = isset($window['a']) ? (string) $window['a'] : '';
            if (cvPfDateTimeInRange($value, $from, $to)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('cvPfProviderSearchCacheDir')) {
    function cvPfProviderSearchCacheDir(): string
    {
        return dirname(__DIR__) . '/files/cache/provider_search';
    }
}

if (!function_exists('cvPfProviderSearchCacheRead')) {
    /**
     * @return array<string,mixed>|null
     */
    function cvPfProviderSearchCacheRead(string $key, int $ttlSeconds): ?array
    {
        $path = cvPfProviderSearchCacheDir() . '/' . $key . '.json';
        if (!is_file($path)) {
            return null;
        }

        $mtime = @filemtime($path);
        if (!is_int($mtime) || (time() - $mtime) > $ttlSeconds) {
            return null;
        }

        $raw = @file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }
}

if (!function_exists('cvPfProviderSearchCacheWrite')) {
    /**
     * @param array<string,mixed> $payload
     */
    function cvPfProviderSearchCacheWrite(string $key, array $payload): void
    {
        $dir = cvPfProviderSearchCacheDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        if (!is_dir($dir) || !is_writable($dir)) {
            return;
        }

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return;
        }

        @file_put_contents($dir . '/' . $key . '.json', $json, LOCK_EX);
    }
}

if (!function_exists('cvPfProviderSearchRequestKey')) {
    function cvPfProviderSearchRequestKey(
        string $providerCode,
        string $providerVersion,
        string $travelDateIt,
        int $adults,
        int $children,
        string $fromStopId,
        string $toStopId,
        string $codiceCamb = ''
    ): string {
        return hash(
            'sha256',
            implode('|', [
                $providerCode,
                $providerVersion,
                $travelDateIt,
                (string) max(0, $adults),
                (string) max(0, $children),
                $fromStopId,
                $toStopId,
                trim($codiceCamb),
            ])
        );
    }
}

if (!function_exists('cvPfProviderBuildSearchUrl')) {
    function cvPfProviderBuildSearchUrl(string $baseUrl, array $params): string
    {
        $parts = parse_url($baseUrl);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return '';
        }

        $query = [];
        if (isset($parts['query']) && is_string($parts['query'])) {
            parse_str($parts['query'], $query);
        }

        $query['rquest'] = 'search';
        foreach ($params as $key => $value) {
            $query[(string) $key] = (string) $value;
        }

        $path = isset($parts['path']) ? (string) $parts['path'] : '';
        if ($path === '') {
            $path = '/';
        }

        $url = (string) $parts['scheme'] . '://';
        if (isset($parts['user'])) {
            $url .= (string) $parts['user'];
            if (isset($parts['pass'])) {
                $url .= ':' . (string) $parts['pass'];
            }
            $url .= '@';
        }

        $url .= (string) $parts['host'];
        if (isset($parts['port'])) {
            $url .= ':' . (int) $parts['port'];
        }

        $url .= $path . '?' . http_build_query($query);
        return $url;
    }
}

if (!function_exists('cvPfProviderSearchRoute')) {
    /**
     * @return array{status:string,solutions:array<int,array<string,mixed>>,error:?string}
     */
    function cvPfProviderSearchRoute(
        mysqli $connection,
        string $providerCode,
        string $travelDateIt,
        int $adults,
        int $children,
        string $fromStopId,
        string $toStopId,
        string $codiceCamb = '',
        int $ttlSeconds = 120
    ): array {
        static $memory = [];
        static $providers = null;

        $providerCode = trim($providerCode);
        $travelDateIt = trim($travelDateIt);
        $fromStopId = trim($fromStopId);
        $toStopId = trim($toStopId);
        $codiceCamb = trim($codiceCamb);

        if ($providerCode === '' || $travelDateIt === '' || $fromStopId === '' || $toStopId === '') {
            return [
                'status' => 'skip',
                'solutions' => [],
                'error' => 'missing_provider_route_params',
            ];
        }

        if (!is_array($providers)) {
            $providers = cvProviderConfigs($connection);
        }

        if (!isset($providers[$providerCode]) || !is_array($providers[$providerCode])) {
            return [
                'status' => 'skip',
                'solutions' => [],
                'error' => 'provider_not_configured',
            ];
        }

        $providerVersion = cvPfProviderVersion($connection, $providerCode);
        $cacheKey = cvPfProviderSearchRequestKey(
            $providerCode,
            $providerVersion,
            $travelDateIt,
            $adults,
            $children,
            $fromStopId,
            $toStopId,
            $codiceCamb
        );
        $requestMeta = [
            'provider_code' => $providerCode,
            'provider_version' => $providerVersion,
            'travel_date_it' => $travelDateIt,
            'adults' => max(0, $adults),
            'children' => max(0, $children),
            'from_stop_id' => $fromStopId,
            'to_stop_id' => $toStopId,
            'codice_camb' => $codiceCamb,
        ];
        cvPfDebugLog('provider_search_route.start', $requestMeta);
        if (isset($memory[$cacheKey]) && is_array($memory[$cacheKey])) {
            cvPfDebugLog('provider_search_route.memory_hit', $requestMeta + ['cache_key' => $cacheKey]);
            return $memory[$cacheKey];
        }

        $cached = cvPfProviderSearchCacheRead($cacheKey, $ttlSeconds);
        if (is_array($cached)) {
            cvPfDebugLog('provider_search_route.file_cache_hit', $requestMeta + ['cache_key' => $cacheKey]);
            $memory[$cacheKey] = $cached;
            return $cached;
        }

        $provider = $providers[$providerCode];
        $searchUrl = cvPfProviderBuildSearchUrl(
            (string) ($provider['base_url'] ?? ''),
            [
                'part' => $fromStopId,
                'arr' => $toStopId,
                'dt1' => $travelDateIt,
                'ad' => (string) max(0, $adults),
                'bam' => (string) max(0, $children),
                'codice_camb' => $codiceCamb,
                'cmb' => $codiceCamb,
            ]
        );

        if ($searchUrl === '') {
            cvPfDebugLog('provider_search_route.invalid_url', $requestMeta);
            $payload = [
                'status' => 'skip',
                'solutions' => [],
                'error' => 'invalid_provider_base_url',
                'provider_code' => $providerCode,
                'provider_version' => $providerVersion,
                'request_meta' => $requestMeta,
            ];
            $memory[$cacheKey] = $payload;
            return $payload;
        }

        $response = cvProviderHttpGetJson($searchUrl, (string) ($provider['api_key'] ?? ''));
        if (!$response['ok'] || !is_array($response['body'])) {
            cvPfDebugLog('provider_search_route.http_error', $requestMeta + [
                'search_url' => $searchUrl,
                'http_ok' => (bool) ($response['ok'] ?? false),
                'http_error' => is_string($response['error'] ?? null) ? (string) $response['error'] : '',
            ]);
            $payload = [
                'status' => 'error',
                'solutions' => [],
                'error' => is_string($response['error']) ? $response['error'] : 'provider_search_failed',
                'provider_code' => $providerCode,
                'provider_version' => $providerVersion,
                'request_meta' => $requestMeta,
            ];
            $memory[$cacheKey] = $payload;
            return $payload;
        }

        $body = $response['body'];
        $success = isset($body['success']) && (bool) $body['success'] === true;
        $data = isset($body['data']) && is_array($body['data']) ? $body['data'] : null;
        $solutions = is_array($data) && isset($data['solutions']) && is_array($data['solutions'])
            ? $data['solutions']
            : null;

        if (!$success || !is_array($solutions)) {
            cvPfDebugLog('provider_search_route.invalid_payload', $requestMeta + [
                'search_url' => $searchUrl,
                'body_success' => (bool) $success,
                'has_data' => is_array($data),
                'has_solutions_array' => is_array($solutions),
            ]);
            $payload = [
                'status' => 'error',
                'solutions' => [],
                'error' => 'provider_search_invalid_payload',
                'provider_code' => $providerCode,
                'provider_version' => $providerVersion,
                'request_meta' => $requestMeta,
            ];
            $memory[$cacheKey] = $payload;
            return $payload;
        }

        $payload = [
            'status' => 'ok',
            'solutions' => $solutions,
            'error' => null,
            'provider_code' => $providerCode,
            'provider_version' => $providerVersion,
            'request_meta' => $requestMeta,
        ];
        cvPfDebugLog('provider_search_route.ok', $requestMeta + [
            'search_url' => $searchUrl,
            'solutions_count' => count($solutions),
        ]);
        cvPfProviderSearchCacheWrite($cacheKey, $payload);
        $memory[$cacheKey] = $payload;
        return $payload;
    }
}

if (!function_exists('cvPfProviderBestFareFromSolution')) {
    /**
     * @param array<string,mixed> $solution
     * @return array{fare_id:string,amount:float,base_amount:float,commission_percent:float,commission_amount:float,provider_amount:float,original_amount:float,discount_percent:float,currency:string}
     */
    function cvPfProviderBestFareFromSolution(array $solution, string $priceMode = 'discounted', float $commissionPercent = 0.0): array
    {
        $bestFareId = '';
        $bestProviderAmount = 0.0;
        $bestOriginalAmount = 0.0;
        $bestDiscountPercent = 0.0;
        $bestCurrency = 'EUR';
        $fares = isset($solution['fares']) && is_array($solution['fares']) ? $solution['fares'] : [];

        foreach ($fares as $fare) {
            if (!is_array($fare)) {
                continue;
            }

            $amount = isset($fare['amount']) ? (float) $fare['amount'] : 0.0;
            if ($amount <= 0.0) {
                continue;
            }

            if ($bestProviderAmount <= 0.0 || $amount < $bestProviderAmount) {
                $bestProviderAmount = $amount;
                $bestFareId = trim((string) ($fare['fare_id'] ?? ''));
                $bestOriginalAmount = isset($fare['original_amount']) ? (float) $fare['original_amount'] : 0.0;
                $bestDiscountPercent = isset($fare['discount_percent']) ? (float) $fare['discount_percent'] : 0.0;
                $bestCurrency = trim((string) ($fare['currency'] ?? 'EUR'));
                if ($bestCurrency === '') {
                    $bestCurrency = 'EUR';
                }
            }
        }

        $displayAmount = cvRuntimeResolveDisplayedAmount($priceMode, $bestProviderAmount, $bestOriginalAmount);
        $commissionCalc = cvRuntimeApplyProviderCommission($displayAmount, $commissionPercent, 1);
        $clientAmount = (float) ($commissionCalc['client_amount'] ?? 0.0);

        return [
            'fare_id' => $bestFareId,
            // In ricerca mostriamo il prezzo listino (full/discounted). Il netto commissione
            // viene applicato in checkout nei totali di pagamento.
            'amount' => $displayAmount,
            'base_amount' => $displayAmount,
            'client_amount' => $clientAmount,
            'commission_percent' => round($commissionPercent, 4),
            'commission_amount' => (float) ($commissionCalc['commission_amount'] ?? 0.0),
            'provider_amount' => $bestProviderAmount,
            'original_amount' => $bestOriginalAmount,
            'discount_percent' => $bestDiscountPercent,
            'currency' => $bestCurrency,
        ];
    }
}

if (!function_exists('cvPfResolveLegViaProviderSearch')) {
    /**
     * @param array<string,mixed> $leg
     * @return array{status:string,leg:?array}
     */
    function cvPfResolveLegViaProviderSearch(
        mysqli $connection,
        array $leg,
        int $adults = 1,
        int $children = 0
    ): array {
        $providerCode = trim((string) ($leg['provider_code'] ?? ''));
        $tripExternalId = trim((string) ($leg['trip_external_id'] ?? ''));
        $fromStopId = trim((string) ($leg['from_stop_id'] ?? ''));
        $toStopId = trim((string) ($leg['to_stop_id'] ?? ''));
        $departure = $leg['departure'] ?? null;
        $arrival = $leg['arrival'] ?? null;

        if (
            $providerCode === '' ||
            !ctype_digit($tripExternalId) ||
            !ctype_digit($fromStopId) ||
            !ctype_digit($toStopId) ||
            !$departure instanceof DateTimeImmutable ||
            !$arrival instanceof DateTimeImmutable
        ) {
            return [
                'status' => 'skip',
                'leg' => null,
            ];
        }

        $search = cvPfProviderSearchRoute(
            $connection,
            $providerCode,
            $departure->format('d/m/Y'),
            $adults,
            $children,
            $fromStopId,
            $toStopId
        );

        if (($search['status'] ?? '') !== 'ok') {
            return [
                'status' => 'skip',
                'leg' => null,
            ];
        }

        $departureHm = $departure->format('H:i');
        $arrivalHm = $arrival->format('H:i');
        $solutions = isset($search['solutions']) && is_array($search['solutions']) ? $search['solutions'] : [];

        foreach ($solutions as $solution) {
            if (!is_array($solution)) {
                continue;
            }

            $segments = isset($solution['segments']) && is_array($solution['segments']) ? $solution['segments'] : [];
            if (!isset($segments[0]) || !is_array($segments[0])) {
                continue;
            }

            $segment = $segments[0];
            $solutionTripId = trim((string) ($segment['corsa_id'] ?? ''));
            if ($solutionTripId === '' && isset($solution['provider_corsa_ids'][0])) {
                $solutionTripId = trim((string) $solution['provider_corsa_ids'][0]);
            }

            if (
                $solutionTripId !== $tripExternalId ||
                trim((string) ($segment['from_id'] ?? '')) !== $fromStopId ||
                trim((string) ($segment['to_id'] ?? '')) !== $toStopId
            ) {
                continue;
            }

            $segmentDeparture = trim((string) ($segment['departure_time'] ?? ''));
            if ($segmentDeparture !== '' && $segmentDeparture !== $departureHm) {
                continue;
            }

            $segmentArrival = trim((string) ($segment['arrival_time'] ?? ''));
            if ($segmentArrival !== '' && $segmentArrival !== $arrivalHm) {
                continue;
            }

            $priceMode = cvRuntimeProviderPriceMode($connection, $providerCode);
            $providerCommissionMap = cvRuntimeProviderCommissionMap($connection);
            $commissionPercent = isset($providerCommissionMap[$providerCode]) ? (float) $providerCommissionMap[$providerCode] : 0.0;
            $fare = cvPfProviderBestFareFromSolution($solution, $priceMode, $commissionPercent);
            $resolvedLeg = $leg;
            if ($fare['amount'] > 0.0) {
                $resolvedLeg['amount'] = $fare['amount'];
                $resolvedLeg['base_amount'] = (float) ($fare['base_amount'] ?? $fare['amount']);
                $resolvedLeg['commission_percent'] = (float) ($fare['commission_percent'] ?? 0.0);
                $resolvedLeg['commission_amount'] = (float) ($fare['commission_amount'] ?? 0.0);
                $resolvedLeg['provider_amount'] = $fare['provider_amount'];
                $resolvedLeg['original_amount'] = $fare['original_amount'] > 0.0 ? $fare['original_amount'] : $fare['amount'];
                $resolvedLeg['discount_percent'] = $fare['discount_percent'];
                $resolvedLeg['currency'] = $fare['currency'];
                if ($fare['fare_id'] !== '') {
                    $resolvedLeg['fare_id'] = $fare['fare_id'];
                }
            }

            return [
                'status' => 'matched',
                'leg' => $resolvedLeg,
            ];
        }

        return [
            'status' => 'rejected',
            'leg' => null,
        ];
    }
}

if (!function_exists('cvPfResolveVisibleLeg')) {
    /**
     * @param array<string,mixed> $leg
     * @return array<string,mixed>|null
     */
    function cvPfResolveVisibleLeg(
        mysqli $connection,
        array $leg,
        int $adults = 1,
        int $children = 0
    ): ?array {
        static $cache = [];

        $providerCode = trim((string) ($leg['provider_code'] ?? ''));
        $tripExternalId = trim((string) ($leg['trip_external_id'] ?? ''));
        $fromStopId = trim((string) ($leg['from_stop_id'] ?? ''));
        $toStopId = trim((string) ($leg['to_stop_id'] ?? ''));
        $departure = $leg['departure'] ?? null;
        $arrival = $leg['arrival'] ?? null;

        $cacheKey = implode('|', [
            $providerCode,
            $tripExternalId,
            $fromStopId,
            $toStopId,
            $departure instanceof DateTimeImmutable ? $departure->format('Y-m-d H:i:s') : '',
            $arrival instanceof DateTimeImmutable ? $arrival->format('Y-m-d H:i:s') : '',
            (string) max(0, $adults),
            (string) max(0, $children),
        ]);
        if (isset($cache[$cacheKey])) {
            return is_array($cache[$cacheKey]) ? $cache[$cacheKey] : null;
        }

        $providerResolved = cvPfResolveLegViaProviderSearch($connection, $leg, $adults, $children);
        if (($providerResolved['status'] ?? '') === 'matched' && isset($providerResolved['leg']) && is_array($providerResolved['leg'])) {
            $cache[$cacheKey] = $providerResolved['leg'];
            return $cache[$cacheKey];
        }

        if (($providerResolved['status'] ?? '') === 'rejected') {
            $cache[$cacheKey] = false;
            return null;
        }

        $cache[$cacheKey] = cvPfNativeLegPassesVisibilityRules($connection, $leg) ? $leg : false;
        return is_array($cache[$cacheKey]) ? $cache[$cacheKey] : null;
    }
}

if (!function_exists('cvPfResolveSolutionLegs')) {
    /**
     * @param array<int,array<string,mixed>> $legs
     * @return array<int,array<string,mixed>>|null
     */
    function cvPfResolveSolutionLegs(
        mysqli $connection,
        array $legs,
        int $adults = 1,
        int $children = 0
    ): ?array {
        $resolved = [];
        foreach ($legs as $leg) {
            $resolvedLeg = cvPfResolveVisibleLeg($connection, $leg, $adults, $children);
            if (!is_array($resolvedLeg)) {
                return null;
            }

            $resolved[] = $resolvedLeg;
        }

        return $resolved;
    }
}

if (!function_exists('cvPfSingleActiveProviderCode')) {
    function cvPfSingleActiveProviderCode(mysqli $connection): ?string
    {
        static $cache = null;
        if (is_string($cache) || $cache === false) {
            return is_string($cache) ? $cache : null;
        }

        $providers = cvProviderConfigs($connection);
        if (count($providers) !== 1) {
            $cache = false;
            return null;
        }

        $providerCode = (string) array_key_first($providers);
        if ($providerCode === '') {
            $cache = false;
            return null;
        }

        $cache = $providerCode;
        return $cache;
    }
}

if (!function_exists('cvPfSelectProviderSeedStop')) {
    /**
     * @param array<int,array<string,mixed>> $seedStops
     * @return array<string,mixed>|null
     */
    function cvPfSelectProviderSeedStop(array $seedStops, string $providerCode, string $preferredExternalId = ''): ?array
    {
        $providerCode = trim($providerCode);
        $preferredExternalId = trim($preferredExternalId);
        $fallback = null;

        foreach ($seedStops as $stop) {
            if (!is_array($stop)) {
                continue;
            }

            if (trim((string) ($stop['provider_code'] ?? '')) !== $providerCode) {
                continue;
            }

            if ($preferredExternalId !== '' && trim((string) ($stop['external_id'] ?? '')) === $preferredExternalId) {
                return $stop;
            }

            if (!is_array($fallback)) {
                $fallback = $stop;
            }
        }

        return is_array($fallback) ? $fallback : null;
    }
}

if (!function_exists('cvPfProviderSeedStopsForCode')) {
    /**
     * @param array<int,array<string,mixed>> $seedStops
     * @return array<int,array<string,mixed>>
     */
    function cvPfProviderSeedStopsForCode(array $seedStops, string $providerCode, string $preferredExternalId = '', int $limit = 12): array
    {
        $providerCode = trim($providerCode);
        $preferredExternalId = trim($preferredExternalId);
        $limit = max(1, min(32, $limit));
        $preferred = [];
        $others = [];
        $seen = [];

        foreach ($seedStops as $stop) {
            if (!is_array($stop)) {
                continue;
            }

            if (trim((string) ($stop['provider_code'] ?? '')) !== $providerCode) {
                continue;
            }

            $externalId = trim((string) ($stop['external_id'] ?? ''));
            if ($externalId === '' || isset($seen[$externalId])) {
                continue;
            }
            $seen[$externalId] = true;

            if ($preferredExternalId !== '' && $externalId === $preferredExternalId) {
                $preferred[] = $stop;
            } else {
                $others[] = $stop;
            }
        }

        $ordered = array_merge($preferred, $others);
        if (count($ordered) > $limit) {
            $ordered = array_slice($ordered, 0, $limit);
        }

        return $ordered;
    }
}

if (!function_exists('cvPfFetchProviderStopMap')) {
    /**
     * @param array<int,string> $externalIds
     * @return array<string,array<string,mixed>>
     */
    function cvPfFetchProviderStopMap(mysqli $connection, string $providerCode, array $externalIds): array
    {
        $providerCode = trim($providerCode);
        if ($providerCode === '') {
            return [];
        }

        $uniqueIds = [];
        foreach ($externalIds as $externalId) {
            $externalId = trim((string) $externalId);
            if ($externalId === '') {
                continue;
            }

            $uniqueIds[$externalId] = true;
        }

        if (count($uniqueIds) === 0) {
            return [];
        }

        $ids = array_keys($uniqueIds);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT
                  p.id_provider,
                  p.code AS provider_code,
                  p.name AS provider_name,
                  s.external_id,
                  s.name,
                  s.lat,
                  s.lon
                FROM cv_provider_stops s
                INNER JOIN cv_providers p
                  ON p.id_provider = s.id_provider
                WHERE p.is_active = 1
                  AND s.is_active = 1
                  AND p.code = ?
                  AND s.external_id IN ({$placeholders})";

        $statement = $connection->prepare($sql);
        if (!$statement instanceof mysqli_stmt) {
            return [];
        }

        $params = array_merge([$providerCode], $ids);
        $types = 's' . str_repeat('s', count($ids));
        if (!cvPfBindDynamicParams($statement, $types, $params) || !$statement->execute()) {
            $statement->close();
            return [];
        }

        $result = $statement->get_result();
        if (!$result instanceof mysqli_result) {
            $statement->close();
            return [];
        }

        $map = [];
        while ($row = $result->fetch_assoc()) {
            if (!is_array($row)) {
                continue;
            }

            $externalId = trim((string) ($row['external_id'] ?? ''));
            if ($externalId === '') {
                continue;
            }

            $map[$externalId] = [
                'id_provider' => (int) ($row['id_provider'] ?? 0),
                'provider_code' => (string) ($row['provider_code'] ?? ''),
                'provider_name' => (string) ($row['provider_name'] ?? ''),
                'external_id' => $externalId,
                'name' => (string) ($row['name'] ?? ''),
                'lat' => isset($row['lat']) ? (float) $row['lat'] : null,
                'lon' => isset($row['lon']) ? (float) $row['lon'] : null,
            ];
        }

        $result->free();
        $statement->close();
        return $map;
    }
}

if (!function_exists('cvPfParseProviderDateTime')) {
    function cvPfParseProviderDateTime(string $rawValue): ?DateTimeImmutable
    {
        $value = trim($rawValue);
        if ($value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable $exception) {
            return null;
        }
    }
}

if (!function_exists('cvPfShiftProviderTimeForward')) {
    function cvPfShiftProviderTimeForward(DateTimeImmutable $candidate, DateTimeImmutable $threshold): DateTimeImmutable
    {
        while ($candidate->getTimestamp() < $threshold->getTimestamp()) {
            $candidate = $candidate->modify('+1 day');
        }

        return $candidate;
    }
}

if (!function_exists('cvPfBuildProviderTransferDetails')) {
    /**
     * @param array<int,array<string,mixed>> $legs
     * @return array<int,array<string,mixed>>
     */
    function cvPfBuildProviderTransferDetails(array $legs): array
    {
        $transfers = [];
        $lastIndex = count($legs) - 1;

        for ($index = 0; $index < $lastIndex; $index++) {
            $currentLeg = $legs[$index];
            $nextLeg = $legs[$index + 1];
            $waitMinutes = 0;

            if (
                isset($currentLeg['arrival'], $nextLeg['departure']) &&
                $currentLeg['arrival'] instanceof DateTimeImmutable &&
                $nextLeg['departure'] instanceof DateTimeImmutable
            ) {
                $waitMinutes = max(
                    0,
                    (int) floor(($nextLeg['departure']->getTimestamp() - $currentLeg['arrival']->getTimestamp()) / 60)
                );
            }

            $distanceKm = 0.0;
            $fromLat = isset($currentLeg['to_lat']) ? (float) $currentLeg['to_lat'] : null;
            $fromLon = isset($currentLeg['to_lon']) ? (float) $currentLeg['to_lon'] : null;
            $toLat = isset($nextLeg['from_lat']) ? (float) $nextLeg['from_lat'] : null;
            $toLon = isset($nextLeg['from_lon']) ? (float) $nextLeg['from_lon'] : null;
            if (
                $fromLat !== null &&
                $fromLon !== null &&
                $toLat !== null &&
                $toLon !== null
            ) {
                $distanceKm = cvPfHaversineKm($fromLat, $fromLon, $toLat, $toLon);
            }

            $sameStop = trim((string) ($currentLeg['to_stop_id'] ?? '')) !== ''
                && trim((string) ($currentLeg['to_stop_id'] ?? '')) === trim((string) ($nextLeg['from_stop_id'] ?? ''));

            $transfers[] = [
                'wait_minutes' => $waitMinutes,
                'transfer_type' => $sameStop ? 'provider_same_stop' : 'provider_dynamic',
                'distance_km' => $distanceKm,
                'from_stop_name' => (string) ($currentLeg['to_stop_name'] ?? ''),
                'to_stop_name' => (string) ($nextLeg['from_stop_name'] ?? ''),
            ];
        }

        return $transfers;
    }
}

if (!function_exists('cvPfTransferDetailAllowed')) {
    /**
     * @param array<string,mixed> $transferDetail
     * @param array<string,mixed> $tuning
     */
    function cvPfTransferDetailAllowed(array $transferDetail, array $tuning): bool
    {
        $waitMinutes = max(0, (int) ($transferDetail['wait_minutes'] ?? 0));
        $maxWaitMinutes = max(10, (int) ($tuning['transfer_max_wait_minutes'] ?? 120));
        if ($waitMinutes > $maxWaitMinutes) {
            return false;
        }

        $type = trim((string) ($transferDetail['transfer_type'] ?? ''));
        if ($type === 'same_stop' || $type === 'manual_pair' || $type === 'manual_hub' || $type === 'provider_same_stop') {
            return true;
        }

        $distanceKm = max(0.0, (float) ($transferDetail['distance_km'] ?? 0.0));
        $maxDistanceKm = max(
            (float) ($tuning['transfer_same_name_max_km'] ?? 1.2),
            (float) ($tuning['transfer_nearby_max_km'] ?? 0.4)
        );

        return $distanceKm <= $maxDistanceKm;
    }
}

if (!function_exists('cvPfNormalizeProviderSearchSolution')) {
    /**
     * @param array<string,mixed> $solution
     * @param array<string,mixed> $providerStopMap
     * @return array<string,mixed>|null
     */
    function cvPfNormalizeProviderSearchSolution(
        array $solution,
        string $providerCode,
        array $providerStopMap,
        array $fromSeedStop,
        array $toSeedStop,
        int $maxTransfers = 2,
        ?mysqli $connection = null
    ): ?array {
        $segments = isset($solution['segments']) && is_array($solution['segments']) ? $solution['segments'] : [];
        if (count($segments) === 0) {
            return null;
        }

        $solutionDeparture = cvPfParseProviderDateTime((string) ($solution['departure_datetime'] ?? ''));
        $solutionArrival = cvPfParseProviderDateTime((string) ($solution['arrival_datetime'] ?? ''));
        if (!$solutionDeparture instanceof DateTimeImmutable || !$solutionArrival instanceof DateTimeImmutable) {
            return null;
        }

        $priceMode = 'discounted';
        if ($connection instanceof mysqli) {
            $priceMode = cvRuntimeProviderPriceMode($connection, $providerCode);
        }
        $commissionPercent = 0.0;
        if ($connection instanceof mysqli) {
            $providerCommissionMap = cvRuntimeProviderCommissionMap($connection);
            $commissionPercent = isset($providerCommissionMap[$providerCode]) ? (float) $providerCommissionMap[$providerCode] : 0.0;
        }
        $fare = cvPfProviderBestFareFromSolution($solution, $priceMode, $commissionPercent);
        $providerId = (int) ($fromSeedStop['id_provider'] ?? $toSeedStop['id_provider'] ?? 0);
        $providerName = trim((string) ($fromSeedStop['provider_name'] ?? $toSeedStop['provider_name'] ?? $providerCode));
        if ($providerName === '') {
            $providerName = $providerCode;
        }

        $legs = [];
        $previousArrival = null;
        $lastSegmentIndex = count($segments) - 1;

        foreach ($segments as $index => $segment) {
            if (!is_array($segment)) {
                return null;
            }

            $segmentProviderCode = trim((string) ($segment['provider'] ?? $providerCode));
            if ($segmentProviderCode === '') {
                $segmentProviderCode = $providerCode;
            }

            $fromStopId = trim((string) ($segment['from_id'] ?? ''));
            $toStopId = trim((string) ($segment['to_id'] ?? ''));
            if ($fromStopId === '' || $toStopId === '') {
                return null;
            }

            $fromMeta = $providerStopMap[$fromStopId] ?? null;
            $toMeta = $providerStopMap[$toStopId] ?? null;

            $departure = $index === 0 ? $solutionDeparture : null;
            if (!$departure instanceof DateTimeImmutable) {
                $departureDate = $previousArrival instanceof DateTimeImmutable
                    ? $previousArrival
                    : $solutionDeparture;
                $departure = cvPfComposeLegDateTime(
                    $departureDate->setTime(0, 0, 0),
                    isset($segment['departure_time']) ? (string) $segment['departure_time'] : null,
                    0
                );
                if (!$departure instanceof DateTimeImmutable) {
                    return null;
                }
                if ($previousArrival instanceof DateTimeImmutable) {
                    $departure = cvPfShiftProviderTimeForward($departure, $previousArrival);
                }
            }

            $arrival = $index === $lastSegmentIndex ? $solutionArrival : null;
            if (!$arrival instanceof DateTimeImmutable) {
                $arrival = cvPfComposeLegDateTime(
                    $departure->setTime(0, 0, 0),
                    isset($segment['arrival_time']) ? (string) $segment['arrival_time'] : null,
                    0
                );
                if (!$arrival instanceof DateTimeImmutable) {
                    return null;
                }
                $arrival = cvPfShiftProviderTimeForward($arrival, $departure);
            }

            if ($arrival->getTimestamp() < $departure->getTimestamp()) {
                return null;
            }

            $legFareAmount = ($index === 0) ? (float) ($fare['amount'] ?? 0.0) : 0.0;
            $legFareId = ($index === 0) ? (string) ($fare['fare_id'] ?? '') : '';
            $legCurrency = trim((string) ($fare['currency'] ?? 'EUR'));
            if ($legCurrency === '') {
                $legCurrency = 'EUR';
            }

            $legs[] = [
                'provider_id' => $providerId,
                'provider_code' => $segmentProviderCode,
                'provider_name' => (string) (($fromMeta['provider_name'] ?? $toMeta['provider_name'] ?? $providerName)),
                'trip_external_id' => trim((string) ($segment['corsa_id'] ?? '')),
                'trip_name' => 'Corsa ' . trim((string) ($segment['corsa_id'] ?? '')),
                'from_stop_id' => $fromStopId,
                'from_stop_name' => trim((string) ($fromMeta['name'] ?? $segment['from_name'] ?? '')),
                'from_lat' => isset($fromMeta['lat']) ? (float) $fromMeta['lat'] : null,
                'from_lon' => isset($fromMeta['lon']) ? (float) $fromMeta['lon'] : null,
                'to_stop_id' => $toStopId,
                'to_stop_name' => trim((string) ($toMeta['name'] ?? $segment['to_name'] ?? '')),
                'to_lat' => isset($toMeta['lat']) ? (float) $toMeta['lat'] : null,
                'to_lon' => isset($toMeta['lon']) ? (float) $toMeta['lon'] : null,
                'departure' => $departure,
                'arrival' => $arrival,
                'fare_id' => $legFareId,
                'amount' => $legFareAmount,
                'base_amount' => ($index === 0) ? (float) ($fare['base_amount'] ?? $legFareAmount) : 0.0,
                'commission_percent' => ($index === 0) ? (float) ($fare['commission_percent'] ?? 0.0) : 0.0,
                'commission_amount' => ($index === 0) ? (float) ($fare['commission_amount'] ?? 0.0) : 0.0,
                'provider_amount' => ($index === 0) ? (float) ($fare['provider_amount'] ?? $legFareAmount) : 0.0,
                'original_amount' => ($index === 0)
                    ? (float) (($fare['original_amount'] ?? 0.0) > 0.0 ? $fare['original_amount'] : $legFareAmount)
                    : 0.0,
                'discount_percent' => ($index === 0) ? (float) ($fare['discount_percent'] ?? 0.0) : 0.0,
                'currency' => $legCurrency,
            ];

            $previousArrival = $arrival;
        }

        if (count($legs) === 0 || (count($legs) - 1) > $maxTransfers) {
            return null;
        }

        $firstLeg = $legs[0];
        $lastLeg = $legs[count($legs) - 1];
        /** @var DateTimeImmutable $dep */
        $dep = $firstLeg['departure'];
        /** @var DateTimeImmutable $arr */
        $arr = $lastLeg['arrival'];

        $durationMinutes = (int) floor(($arr->getTimestamp() - $dep->getTimestamp()) / 60);
        if ($durationMinutes <= 0 || $durationMinutes > 3000) {
            return null;
        }

        $legsOutput = [];
        $identityParts = [];
        foreach ($legs as $leg) {
            $legsOutput[] = cvPfFormatLegOutput($leg);
            $identityParts[] = cvPfLegSignature($leg);
        }

        $transferDetails = cvPfBuildProviderTransferDetails($legs);
        $tuning = cvPfSearchTuning($connection);
        foreach ($transferDetails as $transferDetail) {
            if (!is_array($transferDetail) || !cvPfTransferDetailAllowed($transferDetail, $tuning)) {
                return null;
            }
        }

        $providerSolutionId = trim((string) ($solution['solution_id'] ?? ''));
        $solutionId = $providerSolutionId !== ''
            ? $providerCode . ':' . $providerSolutionId
            : hash('sha1', implode('>', $identityParts));
        $amount = (float) ($fare['amount'] ?? 0.0);
        if ($amount <= 0.0) {
            $amount = array_sum(array_map(
                static function (array $leg): float {
                    return (float) ($leg['amount'] ?? 0.0);
                },
                $legs
            ));
        }

        $currency = trim((string) ($fare['currency'] ?? 'EUR'));
        if ($currency === '') {
            $currency = 'EUR';
        }

        return [
            'solution_id' => $solutionId,
            'solution_ref' => substr(hash('sha256', $solutionId), 0, 20),
            'transfers' => max(0, count($legs) - 1),
            'departure_iso' => $dep->format(DateTimeInterface::ATOM),
            'arrival_iso' => $arr->format(DateTimeInterface::ATOM),
            'departure_hm' => $dep->format('H:i'),
            'arrival_hm' => $arr->format('H:i'),
            'duration_minutes' => $durationMinutes,
            'amount' => $amount,
            'currency' => $currency,
            'transfer_details' => $transferDetails,
            'legs' => $legsOutput,
            'access' => [
                'from_distance_km' => 0.0,
                'to_distance_km' => 0.0,
                'from_is_nearby' => false,
                'to_is_nearby' => false,
            ],
        ];
    }
}

if (!function_exists('cvPfSortSolutionsList')) {
    /**
     * @param array<int,array<string,mixed>> $solutions
     */
    function cvPfSortSolutionsList(array &$solutions): void
    {
        usort(
            $solutions,
            static function (array $a, array $b): int {
                $durationCmp = ((int) ($a['duration_minutes'] ?? 0)) <=> ((int) ($b['duration_minutes'] ?? 0));
                if ($durationCmp !== 0) {
                    return $durationCmp;
                }

                $amountCmp = ((float) ($a['amount'] ?? 0.0)) <=> ((float) ($b['amount'] ?? 0.0));
                if ($amountCmp !== 0) {
                    return $amountCmp;
                }

                $transfersCmp = ((int) ($a['transfers'] ?? 0)) <=> ((int) ($b['transfers'] ?? 0));
                if ($transfersCmp !== 0) {
                    return $transfersCmp;
                }

                return strcmp((string) ($a['departure_iso'] ?? ''), (string) ($b['departure_iso'] ?? ''));
            }
        );
    }
}

if (!function_exists('cvPfSearchSingleProviderAuthoritative')) {
    /**
     * @param array<int,array<string,mixed>> $fromSeedStops
     * @param array<int,array<string,mixed>> $toSeedStops
     * @return array<string,mixed>|null
     */
    function cvPfSearchSingleProviderAuthoritative(
        mysqli $connection,
        array $fromRef,
        array $toRef,
        string $fromRefRaw,
        string $toRefRaw,
        array $fromSeedStops,
        array $toSeedStops,
        string $travelDateIt,
        string $catalogVersion,
        int $adults = 1,
        int $children = 0,
        int $maxTransfers = 2,
        string $codiceCamb = ''
    ): ?array {
        $providerCode = cvPfSingleActiveProviderCode($connection);
        if (!is_string($providerCode) || $providerCode === '') {
            return null;
        }

        $baseMeta = [
            'cache' => 'miss',
            'catalog_version' => $catalogVersion,
            'max_transfers' => $maxTransfers,
            'from_seed_candidates' => count($fromSeedStops),
            'to_seed_candidates' => count($toSeedStops),
            'from_candidates' => count($fromSeedStops),
            'to_candidates' => count($toSeedStops),
            'direct_rows' => 0,
            'origin_rows' => 0,
            'destination_rows' => 0,
            'all_rows' => 0,
            'direct_legs_raw' => 0,
            'direct_legs_valid' => 0,
            'origin_legs_raw' => 0,
            'origin_legs_valid' => 0,
            'visibility_mode' => 'provider_search_authoritative',
            'nearby_radius_from_km' => 0.0,
            'nearby_radius_to_km' => 0.0,
            'nearby_max_extras' => 0,
            'two_transfer_enabled' => false,
            'engine' => 'provider_api',
            'provider_code' => $providerCode,
            'provider_version' => cvPfProviderVersion($connection, $providerCode),
        ];
        $debugTrace = [];

        $fromProviderSeeds = cvPfProviderSeedStopsForCode(
            $fromSeedStops,
            $providerCode,
            (string) ($fromRef['external_id'] ?? ''),
            12
        );
        $toProviderSeeds = cvPfProviderSeedStopsForCode(
            $toSeedStops,
            $providerCode,
            (string) ($toRef['external_id'] ?? ''),
            12
        );
        $fromSeedStop = count($fromProviderSeeds) > 0 ? $fromProviderSeeds[0] : null;
        $toSeedStop = count($toProviderSeeds) > 0 ? $toProviderSeeds[0] : null;
        if (cvPfDebugEnabled()) {
            $fromSeedIds = [];
            foreach ($fromProviderSeeds as $seed) {
                if (!is_array($seed)) {
                    continue;
                }
                $fromSeedIds[] = (string) ($seed['external_id'] ?? '');
            }
            $toSeedIds = [];
            foreach ($toProviderSeeds as $seed) {
                if (!is_array($seed)) {
                    continue;
                }
                $toSeedIds[] = (string) ($seed['external_id'] ?? '');
            }
            $debugTrace[] = [
                'step' => 'seed_candidates',
                'from_seed_ids' => $fromSeedIds,
                'to_seed_ids' => $toSeedIds,
                'from_seed_count' => count($fromSeedIds),
                'to_seed_count' => count($toSeedIds),
            ];
            cvPfDebugLog('single_provider.seed_candidates', [
                'provider_code' => $providerCode,
                'from_ref' => $fromRefRaw,
                'to_ref' => $toRefRaw,
                'from_seed_ids' => $fromSeedIds,
                'to_seed_ids' => $toSeedIds,
            ]);
        }
        if (!is_array($fromSeedStop) || !is_array($toSeedStop)) {
            return [
                'ok' => false,
                'error' => 'Le fermate richieste non sono disponibili per il provider attivo.',
                'meta' => $baseMeta + [
                    'provider_status' => 'invalid_seed_stops',
                    'debug_trace' => cvPfDebugEnabled() ? $debugTrace : [],
                ],
                'from' => [
                    'ref' => $fromRefRaw,
                    'label' => (string) ($fromSeedStops[0]['name'] ?? ''),
                    'candidates' => $fromSeedStops,
                ],
                'to' => [
                    'ref' => $toRefRaw,
                    'label' => (string) ($toSeedStops[0]['name'] ?? ''),
                    'candidates' => $toSeedStops,
                ],
                'travel_date_it' => $travelDateIt,
                'passengers' => [
                    'adults' => max(0, $adults),
                    'children' => max(0, $children),
                    'total' => max(1, max(0, $adults) + max(0, $children)),
                ],
                'solutions' => [],
            ];
        }

        $search = [
            'status' => 'error',
            'solutions' => [],
            'error' => 'provider_search_failed',
        ];
        $selectedFromSeed = $fromSeedStop;
        $selectedToSeed = $toSeedStop;
        $attemptedPairs = 0;
        $maxPairs = 20;
        $bestOkEmpty = null;

        foreach ($fromProviderSeeds as $fromCandidate) {
            if (!is_array($fromCandidate)) {
                continue;
            }

            $fromExternal = trim((string) ($fromCandidate['external_id'] ?? ''));
            if ($fromExternal === '') {
                continue;
            }

            foreach ($toProviderSeeds as $toCandidate) {
                if (!is_array($toCandidate)) {
                    continue;
                }

                $toExternal = trim((string) ($toCandidate['external_id'] ?? ''));
                if ($toExternal === '') {
                    continue;
                }

                $attemptedPairs++;
                $candidateSearch = cvPfProviderSearchRoute(
                    $connection,
                    $providerCode,
                    $travelDateIt,
                    $adults,
                    $children,
                    $fromExternal,
                    $toExternal,
                    $codiceCamb,
                    120
                );
                if (cvPfDebugEnabled()) {
                    $candidateSolutions = isset($candidateSearch['solutions']) && is_array($candidateSearch['solutions'])
                        ? $candidateSearch['solutions']
                        : [];
                    $debugTrace[] = [
                        'step' => 'provider_pair_attempt',
                        'attempt' => $attemptedPairs,
                        'from_external_id' => $fromExternal,
                        'to_external_id' => $toExternal,
                        'status' => (string) ($candidateSearch['status'] ?? 'unknown'),
                        'error' => (string) ($candidateSearch['error'] ?? ''),
                        'solutions_count' => count($candidateSolutions),
                    ];
                }

                if (($candidateSearch['status'] ?? '') === 'ok') {
                    $candidateSolutions = isset($candidateSearch['solutions']) && is_array($candidateSearch['solutions'])
                        ? $candidateSearch['solutions']
                        : [];
                    if (count($candidateSolutions) > 0) {
                        $search = $candidateSearch;
                        $selectedFromSeed = $fromCandidate;
                        $selectedToSeed = $toCandidate;
                        break 2;
                    }

                    if (!is_array($bestOkEmpty)) {
                        $bestOkEmpty = [
                            'search' => $candidateSearch,
                            'from' => $fromCandidate,
                            'to' => $toCandidate,
                        ];
                    }
                } else {
                    $search = $candidateSearch;
                    $selectedFromSeed = $fromCandidate;
                    $selectedToSeed = $toCandidate;
                }

                if ($attemptedPairs >= $maxPairs) {
                    break 2;
                }
            }
        }

        if (($search['status'] ?? '') !== 'ok' && is_array($bestOkEmpty)) {
            $search = (array) ($bestOkEmpty['search'] ?? []);
            $selectedFromSeed = is_array($bestOkEmpty['from'] ?? null) ? $bestOkEmpty['from'] : $selectedFromSeed;
            $selectedToSeed = is_array($bestOkEmpty['to'] ?? null) ? $bestOkEmpty['to'] : $selectedToSeed;
        }
        if (is_array($selectedFromSeed)) {
            $fromSeedStop = $selectedFromSeed;
        }
        if (is_array($selectedToSeed)) {
            $toSeedStop = $selectedToSeed;
        }
        if (cvPfDebugEnabled()) {
            $debugTrace[] = [
                'step' => 'provider_pair_selected',
                'attempted_pairs' => $attemptedPairs,
                'selected_from_external_id' => (string) ($fromSeedStop['external_id'] ?? ''),
                'selected_to_external_id' => (string) ($toSeedStop['external_id'] ?? ''),
                'selected_status' => (string) ($search['status'] ?? 'unknown'),
                'selected_error' => (string) ($search['error'] ?? ''),
            ];
            cvPfDebugLog('single_provider.pair_selected', [
                'provider_code' => $providerCode,
                'travel_date_it' => $travelDateIt,
                'attempted_pairs' => $attemptedPairs,
                'selected_from_external_id' => (string) ($fromSeedStop['external_id'] ?? ''),
                'selected_to_external_id' => (string) ($toSeedStop['external_id'] ?? ''),
                'selected_status' => (string) ($search['status'] ?? 'unknown'),
                'selected_error' => (string) ($search['error'] ?? ''),
            ]);
        }

        if (($search['status'] ?? '') !== 'ok') {
            $providerStatus = trim((string) ($search['status'] ?? 'error'));
            $providerError = trim((string) ($search['error'] ?? ''));
            $message = 'Ricerca temporaneamente non disponibile sul provider ' . strtoupper($providerCode) . '.';
            if ($providerStatus === 'skip') {
                $message = 'Provider attivo non configurato correttamente per la ricerca.';
            }

            return [
                'ok' => false,
                'error' => $message,
                'meta' => $baseMeta + [
                    'provider_seed_pairs_attempted' => $attemptedPairs,
                    'provider_status' => $providerStatus,
                    'provider_error' => $providerError,
                    'debug_trace' => cvPfDebugEnabled() ? $debugTrace : [],
                ],
                'from' => [
                    'ref' => $fromRefRaw,
                    'label' => (string) ($fromSeedStop['name'] ?? ''),
                    'candidates' => $fromSeedStops,
                ],
                'to' => [
                    'ref' => $toRefRaw,
                    'label' => (string) ($toSeedStop['name'] ?? ''),
                    'candidates' => $toSeedStops,
                ],
                'travel_date_it' => $travelDateIt,
                'passengers' => [
                    'adults' => max(0, $adults),
                    'children' => max(0, $children),
                    'total' => max(1, max(0, $adults) + max(0, $children)),
                ],
                'solutions' => [],
            ];
        }

        $rawSolutions = isset($search['solutions']) && is_array($search['solutions']) ? $search['solutions'] : [];
        $stopIds = [
            (string) ($fromSeedStop['external_id'] ?? ''),
            (string) ($toSeedStop['external_id'] ?? ''),
        ];
        foreach ($rawSolutions as $solution) {
            if (!is_array($solution)) {
                continue;
            }

            $segments = isset($solution['segments']) && is_array($solution['segments']) ? $solution['segments'] : [];
            foreach ($segments as $segment) {
                if (!is_array($segment)) {
                    continue;
                }

                $stopIds[] = trim((string) ($segment['from_id'] ?? ''));
                $stopIds[] = trim((string) ($segment['to_id'] ?? ''));
            }
        }

        $providerStopMap = cvPfFetchProviderStopMap($connection, $providerCode, $stopIds);
        $normalizedSolutions = [];
        foreach ($rawSolutions as $solution) {
            if (!is_array($solution)) {
                continue;
            }

            $normalized = cvPfNormalizeProviderSearchSolution(
                $solution,
                $providerCode,
                $providerStopMap,
                $fromSeedStop,
                $toSeedStop,
                $maxTransfers,
                $connection
            );
            if (!is_array($normalized)) {
                continue;
            }

            $normalizedSolutions[] = $normalized;
        }

        if (count($rawSolutions) > 0 && count($normalizedSolutions) === 0) {
            return [
                'ok' => false,
                'error' => 'Il provider ha restituito una risposta non utilizzabile per questa ricerca.',
                'meta' => $baseMeta + [
                    'provider_status' => 'invalid_payload',
                    'provider_raw_solutions' => count($rawSolutions),
                    'provider_normalized_solutions' => 0,
                ],
                'from' => [
                    'ref' => $fromRefRaw,
                    'label' => (string) ($fromSeedStop['name'] ?? ''),
                    'candidates' => $fromSeedStops,
                ],
                'to' => [
                    'ref' => $toRefRaw,
                    'label' => (string) ($toSeedStop['name'] ?? ''),
                    'candidates' => $toSeedStops,
                ],
                'travel_date_it' => $travelDateIt,
                'passengers' => [
                    'adults' => max(0, $adults),
                    'children' => max(0, $children),
                    'total' => max(1, max(0, $adults) + max(0, $children)),
                ],
                'solutions' => [],
            ];
        }

        cvPfSortSolutionsList($normalizedSolutions);
        if (count($normalizedSolutions) > 180) {
            $normalizedSolutions = array_slice($normalizedSolutions, 0, 180);
        }

        return [
            'ok' => true,
            'error' => null,
            'meta' => $baseMeta + [
                'provider_seed_pairs_attempted' => $attemptedPairs,
                'provider_raw_solutions' => count($rawSolutions),
                'provider_normalized_solutions' => count($normalizedSolutions),
                'provider_status' => 'ok',
                'debug_trace' => cvPfDebugEnabled() ? $debugTrace : [],
            ],
            'from' => [
                'ref' => $fromRefRaw,
                'label' => (string) ($fromSeedStop['name'] ?? ''),
                'candidates' => $fromSeedStops,
            ],
            'to' => [
                'ref' => $toRefRaw,
                'label' => (string) ($toSeedStop['name'] ?? ''),
                'candidates' => $toSeedStops,
            ],
            'travel_date_it' => $travelDateIt,
            'passengers' => [
                'adults' => max(0, $adults),
                'children' => max(0, $children),
                'total' => max(1, max(0, $adults) + max(0, $children)),
            ],
            'solutions' => $normalizedSolutions,
        ];
    }
}

if (!function_exists('cvPfManualTransferIndex')) {
    /**
     * @return array{
     *   stop_to_hub:array<string,array{min_transfer_minutes:int,distance_km:float,hub_key:string}>,
     *   pair_map:array<string,array{min_transfer_minutes:int,distance_km:float,transfer_type:string}>
     * }
     */
    function cvPfManualTransferIndex(): array
    {
        static $cache = null;
        if (is_array($cache)) {
            return $cache;
        }

        $config = cvPfManualTransferConfig();
        $index = [
            'stop_to_hub' => [],
            'pair_map' => [],
        ];

        $hubs = isset($config['hubs']) && is_array($config['hubs']) ? $config['hubs'] : [];
        foreach ($hubs as $hubKey => $hub) {
            if (!is_array($hub)) {
                continue;
            }

            $minutes = max(5, (int) ($hub['min_transfer_minutes'] ?? 25));
            $distanceKm = max(0.0, (float) ($hub['distance_km'] ?? 0.0));
            $stops = isset($hub['stops']) && is_array($hub['stops']) ? $hub['stops'] : [];
            foreach ($stops as $stopRef) {
                $stopKey = trim((string) $stopRef);
                if ($stopKey === '') {
                    continue;
                }

                $index['stop_to_hub'][$stopKey] = [
                    'min_transfer_minutes' => $minutes,
                    'distance_km' => $distanceKm,
                    'hub_key' => (string) $hubKey,
                ];
            }
        }

        $pairs = isset($config['pairs']) && is_array($config['pairs']) ? $config['pairs'] : [];
        foreach ($pairs as $pair) {
            if (!is_array($pair)) {
                continue;
            }

            $from = trim((string) ($pair['from'] ?? ''));
            $to = trim((string) ($pair['to'] ?? ''));
            if ($from === '' || $to === '') {
                continue;
            }

            $meta = [
                'min_transfer_minutes' => max(5, (int) ($pair['min_transfer_minutes'] ?? 25)),
                'distance_km' => max(0.0, (float) ($pair['distance_km'] ?? 0.0)),
                'transfer_type' => 'manual_pair',
            ];

            $index['pair_map'][$from . '>' . $to] = $meta;
            $bidirectional = !array_key_exists('bidirectional', $pair) || (bool) $pair['bidirectional'] === true;
            if ($bidirectional) {
                $index['pair_map'][$to . '>' . $from] = $meta;
            }
        }

        $cache = $index;
        return $cache;
    }
}

if (!function_exists('cvPfNativeProviderCompanyMap')) {
    /**
     * @return array<string,int>
     */
    function cvPfNativeProviderCompanyMap(mysqli $connection): array
    {
        static $cache = null;
        if (is_array($cache)) {
            return $cache;
        }

        $map = [];
        $result = $connection->query("SELECT code, id_az FROM aziende");
        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                if (!is_array($row)) {
                    continue;
                }

                $code = trim((string) ($row['code'] ?? ''));
                $idAz = (int) ($row['id_az'] ?? 0);
                if ($code !== '' && $idAz > 0) {
                    $map[$code] = $idAz;
                }
            }
            $result->free();
        }

        $cache = $map;
        return $cache;
    }
}

if (!function_exists('cvPfNativeRuleContext')) {
    /**
     * @return array<string,mixed>
     */
    function cvPfNativeRuleContext(mysqli $connection, DateTimeImmutable $baseDate): array
    {
        static $cache = [];

        $dateKey = $baseDate->format('Y-m-d');
        if (isset($cache[$dateKey]) && is_array($cache[$dateKey])) {
            return $cache[$dateKey];
        }

        $context = [
            'provider_company_map' => cvPfNativeProviderCompanyMap($connection),
            'stops' => [],
            'lines' => [],
            'trips' => [],
            'route_lines' => [],
            'trip_rules' => [],
            'line_rules' => [],
            'route_rules' => [],
        ];

        $result = $connection->query("SELECT id_az, id_sott, stato, sos_da, sos_a FROM tratte_sottoc");
        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                if (!is_array($row)) {
                    continue;
                }

                $idAz = (int) ($row['id_az'] ?? 0);
                $idSott = (int) ($row['id_sott'] ?? 0);
                if ($idAz <= 0 || $idSott <= 0) {
                    continue;
                }

                $context['stops'][$idAz][$idSott] = [
                    'stato' => (int) ($row['stato'] ?? 0),
                    'sos_da' => trim((string) ($row['sos_da'] ?? '')),
                    'sos_a' => trim((string) ($row['sos_a'] ?? '')),
                ];
            }
            $result->free();
        }

        $result = $connection->query("SELECT id_az, id_linea, stato FROM linee");
        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                if (!is_array($row)) {
                    continue;
                }

                $idAz = (int) ($row['id_az'] ?? 0);
                $idLinea = (int) ($row['id_linea'] ?? 0);
                if ($idAz <= 0 || $idLinea <= 0) {
                    continue;
                }

                $context['lines'][$idAz][$idLinea] = [
                    'stato' => (int) ($row['stato'] ?? 0),
                ];
            }
            $result->free();
        }

        $result = $connection->query("SELECT id_az, id_corsa, id_linea, stato, tempo_acquisto FROM corse");
        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                if (!is_array($row)) {
                    continue;
                }

                $idAz = (int) ($row['id_az'] ?? 0);
                $idCorsa = (int) ($row['id_corsa'] ?? 0);
                if ($idAz <= 0 || $idCorsa <= 0) {
                    continue;
                }

                $context['trips'][$idAz][$idCorsa] = [
                    'id_linea' => (int) ($row['id_linea'] ?? 0),
                    'stato' => (int) ($row['stato'] ?? 0),
                    'tempo_acquisto' => (int) ($row['tempo_acquisto'] ?? 30),
                ];
            }
            $result->free();
        }

        $result = $connection->query("SELECT id_r, id_az, id_sott1, id_sott2, id_linea FROM regole ORDER BY id_r ASC");
        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                if (!is_array($row)) {
                    continue;
                }

                $idAz = (int) ($row['id_az'] ?? 0);
                $fromId = (int) ($row['id_sott1'] ?? 0);
                $toId = (int) ($row['id_sott2'] ?? 0);
                $idLinea = (int) ($row['id_linea'] ?? 0);
                if ($idAz <= 0 || $fromId <= 0 || $toId <= 0 || $idLinea <= 0) {
                    continue;
                }

                $routeKey = $idAz . '|' . $fromId . '|' . $toId;
                if (!isset($context['route_lines'][$routeKey])) {
                    $context['route_lines'][$routeKey] = $idLinea;
                }
            }
            $result->free();
        }

        $dayStart = $dateKey . ' 00:00:00';
        $dayEnd = $dateKey . ' 23:59:59';
        $weekday = $baseDate->format('w');

        $statement = $connection->prepare(
            "SELECT id_az, id_sott, corse, da, a
             FROM regole_corse
             WHERE a >= ?
               AND da <= ?
               AND FIND_IN_SET(?, giorni_sett)"
        );
        if ($statement instanceof mysqli_stmt) {
            $statement->bind_param('sss', $dayStart, $dayEnd, $weekday);
            if ($statement->execute()) {
                $result = $statement->get_result();
                if ($result instanceof mysqli_result) {
                    while ($row = $result->fetch_assoc()) {
                        if (!is_array($row)) {
                            continue;
                        }

                        $idAz = (int) ($row['id_az'] ?? 0);
                        $idSott = (int) ($row['id_sott'] ?? 0);
                        if ($idAz <= 0 || $idSott <= 0) {
                            continue;
                        }

                        $window = [
                            'da' => trim((string) ($row['da'] ?? '')),
                            'a' => trim((string) ($row['a'] ?? '')),
                        ];
                        foreach (array_keys(cvPfCsvTripIdSet((string) ($row['corse'] ?? ''))) as $tripId) {
                            $context['trip_rules'][$idAz][$idSott][$tripId][] = $window;
                        }
                    }
                    $result->free();
                }
            }
            $statement->close();
        }

        $statement = $connection->prepare(
            "SELECT id_az, corse, da, a
             FROM regole_linee
             WHERE a >= ?
               AND da <= ?
               AND FIND_IN_SET(?, giorni_sett)"
        );
        if ($statement instanceof mysqli_stmt) {
            $statement->bind_param('sss', $dayStart, $dayEnd, $weekday);
            if ($statement->execute()) {
                $result = $statement->get_result();
                if ($result instanceof mysqli_result) {
                    while ($row = $result->fetch_assoc()) {
                        if (!is_array($row)) {
                            continue;
                        }

                        $idAz = (int) ($row['id_az'] ?? 0);
                        if ($idAz <= 0) {
                            continue;
                        }

                        $window = [
                            'da' => trim((string) ($row['da'] ?? '')),
                            'a' => trim((string) ($row['a'] ?? '')),
                        ];
                        foreach (array_keys(cvPfCsvTripIdSet((string) ($row['corse'] ?? ''))) as $tripId) {
                            $context['line_rules'][$idAz][$tripId][] = $window;
                        }
                    }
                    $result->free();
                }
            }
            $statement->close();
        }

        $statement = $connection->prepare(
            "SELECT id_az, id_sott1, id_sott2, impedite_permesse, corse
             FROM regole_tratta
             WHERE stato = 1
               AND ? BETWEEN da AND a"
        );
        if ($statement instanceof mysqli_stmt) {
            $statement->bind_param('s', $dateKey);
            if ($statement->execute()) {
                $result = $statement->get_result();
                if ($result instanceof mysqli_result) {
                    while ($row = $result->fetch_assoc()) {
                        if (!is_array($row)) {
                            continue;
                        }

                        $idAz = (int) ($row['id_az'] ?? 0);
                        $fromId = (int) ($row['id_sott1'] ?? 0);
                        $toId = (int) ($row['id_sott2'] ?? 0);
                        if ($idAz <= 0 || $fromId <= 0 || $toId <= 0) {
                            continue;
                        }

                        $routeKey = $idAz . '|' . $fromId . '|' . $toId;
                        if (!isset($context['route_rules'][$routeKey])) {
                            $context['route_rules'][$routeKey] = [
                                'block' => [],
                                'allow' => [],
                            ];
                        }

                        $bucket = ((int) ($row['impedite_permesse'] ?? 0) === 1) ? 'allow' : 'block';
                        foreach (array_keys(cvPfCsvTripIdSet((string) ($row['corse'] ?? ''))) as $tripId) {
                            $context['route_rules'][$routeKey][$bucket][$tripId] = true;
                        }
                    }
                    $result->free();
                }
            }
            $statement->close();
        }

        $cache[$dateKey] = $context;
        return $context;
    }
}

if (!function_exists('cvPfNativeStopIsAvailable')) {
    /**
     * @param array<string,mixed>|null $stop
     */
    function cvPfNativeStopIsAvailable(?array $stop, string $referenceDateTime): bool
    {
        if (!is_array($stop)) {
            return false;
        }

        if ((int) ($stop['stato'] ?? 0) !== 1) {
            return false;
        }

        $sosDa = trim((string) ($stop['sos_da'] ?? ''));
        $sosA = trim((string) ($stop['sos_a'] ?? ''));
        if ($sosDa !== '' && $sosA !== '' && cvPfDateTimeInRange($referenceDateTime, $sosDa, $sosA)) {
            return false;
        }

        return true;
    }
}

if (!function_exists('cvPfNativeLegPassesVisibilityRules')) {
    /**
     * Replica locale dei controlli Curcio (`verifica_data`) per escludere i leg
     * che non devono essere visibili nel pathfind.
     *
     * @param array<string,mixed> $leg
     */
    function cvPfNativeLegPassesVisibilityRules(mysqli $connection, array $leg): bool
    {
        static $cache = [];

        $providerCode = trim((string) ($leg['provider_code'] ?? ''));
        $tripExternalId = trim((string) ($leg['trip_external_id'] ?? ''));
        $fromStopIdRaw = trim((string) ($leg['from_stop_id'] ?? ''));
        $toStopIdRaw = trim((string) ($leg['to_stop_id'] ?? ''));
        $departure = $leg['departure'] ?? null;

        if ($providerCode === '' || !$departure instanceof DateTimeImmutable) {
            return false;
        }

        $providerMap = cvPfNativeProviderCompanyMap($connection);
        $companyId = (int) ($providerMap[$providerCode] ?? 0);
        if ($companyId <= 0) {
            return true;
        }

        if (!ctype_digit($tripExternalId) || !ctype_digit($fromStopIdRaw) || !ctype_digit($toStopIdRaw)) {
            return true;
        }

        $tripId = (int) $tripExternalId;
        $fromStopId = (int) $fromStopIdRaw;
        $toStopId = (int) $toStopIdRaw;
        if ($tripId <= 0 || $fromStopId <= 0 || $toStopId <= 0) {
            return false;
        }

        $cacheKey = implode('|', [
            $companyId,
            $tripId,
            $fromStopId,
            $toStopId,
            $departure->format('Y-m-d H:i:s'),
        ]);
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        $context = cvPfNativeRuleContext($connection, $departure);
        $departureSql = $departure->format('Y-m-d H:i:s');
        $midnightSql = $departure->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $routeKey = $companyId . '|' . $fromStopId . '|' . $toStopId;
        $result = 1;

        $tripRulesByStop = $context['trip_rules'][$companyId] ?? [];
        if (
            isset($tripRulesByStop[$fromStopId][$tripId]) &&
            cvPfAnyTimeWindowMatches($tripRulesByStop[$fromStopId][$tripId], $departureSql)
        ) {
            $result = 0;
        }
        if (
            $result === 1 &&
            isset($tripRulesByStop[$toStopId][$tripId]) &&
            cvPfAnyTimeWindowMatches($tripRulesByStop[$toStopId][$tripId], $departureSql)
        ) {
            $result = 0;
        }

        if ($result === 1) {
            $fromStop = $context['stops'][$companyId][$fromStopId] ?? null;
            $toStop = $context['stops'][$companyId][$toStopId] ?? null;
            if (
                !cvPfNativeStopIsAvailable(is_array($fromStop) ? $fromStop : null, $departureSql) ||
                !cvPfNativeStopIsAvailable(is_array($toStop) ? $toStop : null, $midnightSql)
            ) {
                $result = 0;
            }
        }

        if ($result === 1) {
            $routeLineId = (int) ($context['route_lines'][$routeKey] ?? 0);
            if ($routeLineId > 0) {
                $line = $context['lines'][$companyId][$routeLineId] ?? null;
                if (is_array($line) && (int) ($line['stato'] ?? 0) === 0) {
                    $result = 0;
                }
            }
        }

        $routeRules = $context['route_rules'][$routeKey] ?? null;
        if ($result === 1 && is_array($routeRules) && !empty($routeRules['block'][$tripId])) {
            $result = 0;
        }
        if ($result === 0 && is_array($routeRules) && !empty($routeRules['allow'][$tripId])) {
            $result = 1;
        }

        if ($result === 1) {
            $lineRules = $context['line_rules'][$companyId][$tripId] ?? [];
            if (cvPfAnyTimeWindowMatches($lineRules, $departureSql)) {
                $result = 0;
            }
        }

        if ($result === 1) {
            $trip = $context['trips'][$companyId][$tripId] ?? null;
            if (!is_array($trip) || (int) ($trip['stato'] ?? 0) !== 1) {
                $result = 0;
            }
        }

        if ($result === 1 && !cvPfIsLegBookableNow($leg)) {
            $result = 0;
        }

        $cache[$cacheKey] = ($result === 1);
        return $cache[$cacheKey];
    }
}

if (!function_exists('cvPfFilterFirstLegsByNativeRules')) {
    /**
     * I leg di partenza viaggiano sulla data richiesta, quindi possono essere
     * filtrati subito con i controlli locali del provider.
     *
     * @param array<int,array<string,mixed>> $legs
     * @return array<int,array<string,mixed>>
     */
    function cvPfFilterFirstLegsByNativeRules(
        mysqli $connection,
        array $legs,
        int $adults = 1,
        int $children = 0
    ): array
    {
        $filtered = [];
        foreach ($legs as $leg) {
            $visibleLeg = cvPfResolveVisibleLeg($connection, $leg, $adults, $children);
            if (!is_array($visibleLeg)) {
                continue;
            }

            $filtered[] = $visibleLeg;
        }

        return $filtered;
    }
}

if (!function_exists('cvPfConnectionMeta')) {
    /**
     * @param array<string,mixed> $leg1
     * @param array<string,mixed> $leg2
     * @return array{ok:bool,min_transfer_minutes:int,transfer_distance_km:float,transfer_type:string}
     */
    function cvPfConnectionMeta(array $leg1, array $leg2, ?mysqli $connection = null): array
    {
        $tuning = cvPfSearchTuning($connection);
        $sameNameMaxKm = (float) ($tuning['transfer_same_name_max_km'] ?? 2.0);
        $nearbyMaxKm = (float) ($tuning['transfer_nearby_max_km'] ?? 0.8);
        $enableAutoTransfers = !empty($tuning['enable_auto_transfers']);

        $sameProvider = (int) ($leg1['provider_id'] ?? 0) === (int) ($leg2['provider_id'] ?? 0);
        $sameStopId = $sameProvider && (string) ($leg1['to_stop_id'] ?? '') === (string) ($leg2['from_stop_id'] ?? '');

        if ($sameStopId) {
            return [
                'ok' => true,
                'min_transfer_minutes' => 10,
                'transfer_distance_km' => 0.0,
                'transfer_type' => 'same_stop',
            ];
        }

        $leg1StopKey = trim((string) ($leg1['provider_code'] ?? '')) . '|' . trim((string) ($leg1['to_stop_id'] ?? ''));
        $leg2StopKey = trim((string) ($leg2['provider_code'] ?? '')) . '|' . trim((string) ($leg2['from_stop_id'] ?? ''));
        $manualTransfers = cvPfManualTransferIndex();

        $pairKey = $leg1StopKey . '>' . $leg2StopKey;
        if ($leg1StopKey !== '|' && $leg2StopKey !== '|' && isset($manualTransfers['pair_map'][$pairKey])) {
            $pairMeta = $manualTransfers['pair_map'][$pairKey];
            return [
                'ok' => true,
                'min_transfer_minutes' => (int) ($pairMeta['min_transfer_minutes'] ?? 25),
                'transfer_distance_km' => (float) ($pairMeta['distance_km'] ?? 0.0),
                'transfer_type' => (string) ($pairMeta['transfer_type'] ?? 'manual_pair'),
            ];
        }

        $hubA = $manualTransfers['stop_to_hub'][$leg1StopKey] ?? null;
        $hubB = $manualTransfers['stop_to_hub'][$leg2StopKey] ?? null;
        if (
            is_array($hubA) &&
            is_array($hubB) &&
            (string) ($hubA['hub_key'] ?? '') !== '' &&
            (string) ($hubA['hub_key'] ?? '') === (string) ($hubB['hub_key'] ?? '')
        ) {
            return [
                'ok' => true,
                'min_transfer_minutes' => max(
                    (int) ($hubA['min_transfer_minutes'] ?? 25),
                    (int) ($hubB['min_transfer_minutes'] ?? 25)
                ),
                'transfer_distance_km' => max(
                    (float) ($hubA['distance_km'] ?? 0.0),
                    (float) ($hubB['distance_km'] ?? 0.0)
                ),
                'transfer_type' => 'manual_hub',
            ];
        }

        if (!$enableAutoTransfers) {
            return [
                'ok' => false,
                'min_transfer_minutes' => 0,
                'transfer_distance_km' => 0.0,
                'transfer_type' => 'none',
            ];
        }

        $nameA = cvPfNormalizeStopName((string) ($leg1['to_stop_name'] ?? ''));
        $nameB = cvPfNormalizeStopName((string) ($leg2['from_stop_name'] ?? ''));
        $sameName = $nameA !== '' && $nameA === $nameB;

        $distanceKm = INF;
        $aLat = $leg1['to_lat'] ?? null;
        $aLon = $leg1['to_lon'] ?? null;
        $bLat = $leg2['from_lat'] ?? null;
        $bLon = $leg2['from_lon'] ?? null;
        if (is_numeric($aLat) && is_numeric($aLon) && is_numeric($bLat) && is_numeric($bLon)) {
            $distanceKm = cvPfHaversineKm((float) $aLat, (float) $aLon, (float) $bLat, (float) $bLon);
        }

        if ($sameName && ($distanceKm === INF || $distanceKm <= $sameNameMaxKm)) {
            return [
                'ok' => true,
                'min_transfer_minutes' => 25,
                'transfer_distance_km' => $distanceKm === INF ? 0.0 : $distanceKm,
                'transfer_type' => 'same_name',
            ];
        }

        if ($distanceKm !== INF && $distanceKm <= $nearbyMaxKm) {
            return [
                'ok' => true,
                'min_transfer_minutes' => 20,
                'transfer_distance_km' => $distanceKm,
                'transfer_type' => 'nearby',
            ];
        }

        return [
            'ok' => false,
            'min_transfer_minutes' => 0,
            'transfer_distance_km' => $distanceKm === INF ? 0.0 : $distanceKm,
            'transfer_type' => 'none',
        ];
    }
}

if (!function_exists('cvPfFormatLegOutput')) {
    /**
     * @param array<string,mixed> $leg
     * @return array<string,mixed>
     */
    function cvPfFormatLegOutput(array $leg): array
    {
        /** @var DateTimeImmutable $dep */
        $dep = $leg['departure'];
        /** @var DateTimeImmutable $arr */
        $arr = $leg['arrival'];

        return [
            'provider_code' => (string) ($leg['provider_code'] ?? ''),
            'provider_name' => (string) ($leg['provider_name'] ?? ''),
            'trip_external_id' => (string) ($leg['trip_external_id'] ?? ''),
            'trip_name' => (string) ($leg['trip_name'] ?? ''),
            'from_stop_id' => (string) ($leg['from_stop_id'] ?? ''),
            'from_stop_name' => (string) ($leg['from_stop_name'] ?? ''),
            'from_lat' => isset($leg['from_lat']) ? (float) $leg['from_lat'] : null,
            'from_lon' => isset($leg['from_lon']) ? (float) $leg['from_lon'] : null,
            'to_stop_id' => (string) ($leg['to_stop_id'] ?? ''),
            'to_stop_name' => (string) ($leg['to_stop_name'] ?? ''),
            'to_lat' => isset($leg['to_lat']) ? (float) $leg['to_lat'] : null,
            'to_lon' => isset($leg['to_lon']) ? (float) $leg['to_lon'] : null,
            'departure_iso' => $dep->format(DateTimeInterface::ATOM),
            'arrival_iso' => $arr->format(DateTimeInterface::ATOM),
            'departure_hm' => $dep->format('H:i'),
            'arrival_hm' => $arr->format('H:i'),
            'duration_minutes' => (int) floor(($arr->getTimestamp() - $dep->getTimestamp()) / 60),
            'fare_id' => (string) ($leg['fare_id'] ?? ''),
            'amount' => (float) ($leg['amount'] ?? 0.0),
            'provider_amount' => (float) ($leg['provider_amount'] ?? ($leg['amount'] ?? 0.0)),
            'original_amount' => (float) ($leg['original_amount'] ?? ($leg['amount'] ?? 0.0)),
            'discount_percent' => (float) ($leg['discount_percent'] ?? 0.0),
            'currency' => (string) ($leg['currency'] ?? 'EUR'),
        ];
    }
}

if (!function_exists('cvPfLegSignature')) {
    /**
     * @param array<string,mixed> $leg
     */
    function cvPfLegSignature(array $leg): string
    {
        /** @var DateTimeImmutable $dep */
        $dep = $leg['departure'];
        /** @var DateTimeImmutable $arr */
        $arr = $leg['arrival'];

        return implode('|', [
            (string) ((int) ($leg['provider_id'] ?? 0)),
            (string) ($leg['trip_external_id'] ?? ''),
            (string) ($leg['from_stop_id'] ?? ''),
            (string) ($leg['to_stop_id'] ?? ''),
            (string) $dep->getTimestamp(),
            (string) $arr->getTimestamp(),
        ]);
    }
}

if (!function_exists('cvPfLegTripIdentity')) {
    /**
     * @param array<string,mixed> $leg
     */
    function cvPfLegTripIdentity(array $leg): string
    {
        return (string) ((int) ($leg['provider_id'] ?? 0)) . '|' . (string) ($leg['trip_external_id'] ?? '');
    }
}

if (!function_exists('cvPfBuildLegsFromRows')) {
    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    function cvPfBuildLegsFromRows(array $rows, DateTimeImmutable $baseDate): array
    {
        $legs = [];
        foreach ($rows as $row) {
            $leg = cvPfBuildLeg($row, $baseDate);
            if (!is_array($leg)) {
                continue;
            }

            /** @var DateTimeImmutable $dep */
            $dep = $leg['departure'];
            /** @var DateTimeImmutable $arr */
            $arr = $leg['arrival'];
            if ($arr <= $dep) {
                continue;
            }

            $legs[] = $leg;
        }
        return $legs;
    }
}

if (!function_exists('cvPfSortLegsByDeparture')) {
    /**
     * @param array<int,array<string,mixed>> $legs
     */
    function cvPfSortLegsByDeparture(array &$legs): void
    {
        usort(
            $legs,
            static function (array $a, array $b): int {
                /** @var DateTimeImmutable $aDep */
                $aDep = $a['departure'];
                /** @var DateTimeImmutable $bDep */
                $bDep = $b['departure'];
                return $aDep->getTimestamp() <=> $bDep->getTimestamp();
            }
        );
    }
}

if (!function_exists('cvPfIndexLegsByOrigin')) {
    /**
     * @param array<int,array<string,mixed>> $legs
     * @return array{
     *   by_exact:array<string,array<int,array<string,mixed>>>,
     *   by_name:array<string,array<int,array<string,mixed>>>,
     *   by_geo:array<string,array<int,array<string,mixed>>>,
     *   geo_cell_size:float
     * }
     */
    function cvPfIndexLegsByOrigin(array $legs): array
    {
        $byExact = [];
        $byName = [];
        $byGeo = [];
        $geoCellSize = cvPfGeoCellSizeDegrees();

        foreach ($legs as $leg) {
            $exactKey = (string) ((int) ($leg['provider_id'] ?? 0)) . '|' . (string) ($leg['from_stop_id'] ?? '');
            if (!isset($byExact[$exactKey])) {
                $byExact[$exactKey] = [];
            }
            $byExact[$exactKey][] = $leg;

            $nameKey = cvPfNormalizeStopName((string) ($leg['from_stop_name'] ?? ''));
            if ($nameKey !== '') {
                if (!isset($byName[$nameKey])) {
                    $byName[$nameKey] = [];
                }
                $byName[$nameKey][] = $leg;
            }

            $fromLat = $leg['from_lat'] ?? null;
            $fromLon = $leg['from_lon'] ?? null;
            if (is_numeric($fromLat) && is_numeric($fromLon)) {
                $geoKey = cvPfGeoCellKey((float) $fromLat, (float) $fromLon, $geoCellSize);
                if (!isset($byGeo[$geoKey])) {
                    $byGeo[$geoKey] = [];
                }
                $byGeo[$geoKey][] = $leg;
            }
        }

        foreach ($byExact as &$bucket) {
            cvPfSortLegsByDeparture($bucket);
        }
        unset($bucket);

        foreach ($byName as &$bucket) {
            cvPfSortLegsByDeparture($bucket);
        }
        unset($bucket);

        foreach ($byGeo as &$bucket) {
            cvPfSortLegsByDeparture($bucket);
        }
        unset($bucket);

        return [
            'by_exact' => $byExact,
            'by_name' => $byName,
            'by_geo' => $byGeo,
            'geo_cell_size' => $geoCellSize,
        ];
    }
}

if (!function_exists('cvPfGeoCellSizeDegrees')) {
    function cvPfGeoCellSizeDegrees(): float
    {
        return 0.03;
    }
}

if (!function_exists('cvPfGeoCellKey')) {
    function cvPfGeoCellKey(float $lat, float $lon, float $cellSize): string
    {
        $safeCellSize = max(0.005, $cellSize);
        $latBucket = (int) floor($lat / $safeCellSize);
        $lonBucket = (int) floor($lon / $safeCellSize);
        return $latBucket . '|' . $lonBucket;
    }
}

if (!function_exists('cvPfGeoNeighborCellKeys')) {
    /**
     * @return array<int,string>
     */
    function cvPfGeoNeighborCellKeys(float $lat, float $lon, float $maxDistanceKm, float $cellSize): array
    {
        $safeCellSize = max(0.005, $cellSize);
        $radiusDegrees = max(0.01, $maxDistanceKm / 111.0);
        $stepRadius = max(1, (int) ceil($radiusDegrees / $safeCellSize) + 1);

        $baseLatBucket = (int) floor($lat / $safeCellSize);
        $baseLonBucket = (int) floor($lon / $safeCellSize);
        $keys = [];

        for ($latStep = -$stepRadius; $latStep <= $stepRadius; $latStep++) {
            for ($lonStep = -$stepRadius; $lonStep <= $stepRadius; $lonStep++) {
                $key = ($baseLatBucket + $latStep) . '|' . ($baseLonBucket + $lonStep);
                $keys[$key] = $key;
            }
        }

        return array_values($keys);
    }
}

if (!function_exists('cvPfCandidateNextLegs')) {
    /**
     * @param array<string,mixed> $currentLeg
     * @param array{
     *   by_exact:array<string,array<int,array<string,mixed>>>,
     *   by_name:array<string,array<int,array<string,mixed>>>,
     *   by_geo:array<string,array<int,array<string,mixed>>>,
     *   geo_cell_size:float
     * } $indexes
     * @return array<int,array<string,mixed>>
     */
    function cvPfCandidateNextLegs(array $currentLeg, array $indexes, int $limit = 120, ?mysqli $connection = null): array
    {
        $result = [];
        $seen = [];
        $safeLimit = max(20, min(300, $limit));

        $exactKey = (string) ((int) ($currentLeg['provider_id'] ?? 0)) . '|' . (string) ($currentLeg['to_stop_id'] ?? '');
        if (isset($indexes['by_exact'][$exactKey]) && is_array($indexes['by_exact'][$exactKey])) {
            foreach ($indexes['by_exact'][$exactKey] as $leg) {
                $signature = cvPfLegSignature($leg);
                if (isset($seen[$signature])) {
                    continue;
                }
                $seen[$signature] = true;
                $result[] = $leg;
            }
        }

        $nameKey = cvPfNormalizeStopName((string) ($currentLeg['to_stop_name'] ?? ''));
        if ($nameKey !== '' && isset($indexes['by_name'][$nameKey]) && is_array($indexes['by_name'][$nameKey])) {
            foreach ($indexes['by_name'][$nameKey] as $leg) {
                $signature = cvPfLegSignature($leg);
                if (isset($seen[$signature])) {
                    continue;
                }
                $seen[$signature] = true;
                $result[] = $leg;
            }
        }

        $toLat = $currentLeg['to_lat'] ?? null;
        $toLon = $currentLeg['to_lon'] ?? null;
        $nearbyMaxKm = 0.0;
        if (is_numeric($toLat) && is_numeric($toLon)) {
            $tuning = cvPfSearchTuning($connection);
            $nearbyMaxKm = max(0.0, (float) ($tuning['transfer_nearby_max_km'] ?? 0.8));
        }

        if (
            $nearbyMaxKm > 0.0 &&
            count($result) < $safeLimit &&
            is_numeric($toLat) &&
            is_numeric($toLon) &&
            isset($indexes['by_geo']) &&
            is_array($indexes['by_geo'])
        ) {
            $cellSize = isset($indexes['geo_cell_size']) && is_numeric($indexes['geo_cell_size'])
                ? (float) $indexes['geo_cell_size']
                : cvPfGeoCellSizeDegrees();
            $neighborKeys = cvPfGeoNeighborCellKeys((float) $toLat, (float) $toLon, $nearbyMaxKm, $cellSize);

            foreach ($neighborKeys as $geoKey) {
                $bucket = $indexes['by_geo'][$geoKey] ?? null;
                if (!is_array($bucket)) {
                    continue;
                }

                foreach ($bucket as $leg) {
                    $signature = cvPfLegSignature($leg);
                    if (isset($seen[$signature])) {
                        continue;
                    }

                    $fromLat = $leg['from_lat'] ?? null;
                    $fromLon = $leg['from_lon'] ?? null;
                    if (!is_numeric($fromLat) || !is_numeric($fromLon)) {
                        continue;
                    }

                    $distanceKm = cvPfHaversineKm((float) $toLat, (float) $toLon, (float) $fromLat, (float) $fromLon);
                    if ($distanceKm > $nearbyMaxKm) {
                        continue;
                    }

                    $seen[$signature] = true;
                    $result[] = $leg;
                    if (count($result) >= $safeLimit) {
                        break 2;
                    }
                }
            }
        }

        if (count($result) > 1) {
            cvPfSortLegsByDeparture($result);
        }

        if (count($result) > $safeLimit) {
            $result = array_slice($result, 0, $safeLimit);
        }

        return $result;
    }
}

if (!function_exists('cvPfTryConnectLegs')) {
    /**
     * @param array<string,mixed> $fromLeg
     * @param array<string,mixed> $toLeg
     * @return array{leg:array<string,mixed>,transfer:array<string,mixed>}|null
     */
    function cvPfTryConnectLegs(
        array $fromLeg,
        array $toLeg,
        ?mysqli $connection = null,
        int $adults = 1,
        int $children = 0
    ): ?array
    {
        if (cvPfLegTripIdentity($fromLeg) === cvPfLegTripIdentity($toLeg)) {
            return null;
        }

        $connectionMeta = cvPfConnectionMeta($fromLeg, $toLeg, $connection);
        if (!$connectionMeta['ok']) {
            return null;
        }

        /** @var DateTimeImmutable $fromArrival */
        $fromArrival = $fromLeg['arrival'];
        /** @var DateTimeImmutable $toDeparture */
        $toDeparture = $toLeg['departure'];
        /** @var DateTimeImmutable $toArrival */
        $toArrival = $toLeg['arrival'];

        $requiredTs = $fromArrival->getTimestamp() + ((int) $connectionMeta['min_transfer_minutes'] * 60);
        $shiftDays = 0;
        while ($toDeparture->getTimestamp() < $requiredTs && $shiftDays < 2) {
            $toDeparture = $toDeparture->modify('+1 day');
            $toArrival = $toArrival->modify('+1 day');
            $shiftDays++;
        }

        if ($toDeparture->getTimestamp() < $requiredTs) {
            return null;
        }

        $tuning = cvPfSearchTuning($connection);
        $maxWaitMinutes = max(10, (int) ($tuning['transfer_max_wait_minutes'] ?? 120));
        $waitMinutes = (int) floor(($toDeparture->getTimestamp() - $fromArrival->getTimestamp()) / 60);
        if ($waitMinutes < (int) $connectionMeta['min_transfer_minutes'] || $waitMinutes > $maxWaitMinutes) {
            return null;
        }

        $adjustedLeg = array_merge(
            $toLeg,
            [
                'departure' => $toDeparture,
                'arrival' => $toArrival,
            ]
        );

        return [
            'leg' => $adjustedLeg,
            'transfer' => [
                'wait_minutes' => $waitMinutes,
                'transfer_type' => (string) ($connectionMeta['transfer_type'] ?? 'none'),
                'distance_km' => (float) ($connectionMeta['transfer_distance_km'] ?? 0.0),
                'from_stop_name' => (string) ($fromLeg['to_stop_name'] ?? ''),
                'to_stop_name' => (string) ($toLeg['from_stop_name'] ?? ''),
            ],
        ];
    }
}

if (!function_exists('cvPfBuildSolutionFromLegs')) {
    /**
     * @param array<int,array<string,mixed>> $legs
     * @param array<int,array<string,mixed>> $transfers
     * @return array<string,mixed>|null
     */
    function cvPfBuildSolutionFromLegs(array $legs, array $transfers = []): ?array
    {
        if (count($legs) === 0) {
            return null;
        }

        $firstLeg = $legs[0];
        $lastLeg = $legs[count($legs) - 1];

        /** @var DateTimeImmutable $dep */
        $dep = $firstLeg['departure'];
        /** @var DateTimeImmutable $arr */
        $arr = $lastLeg['arrival'];

        if (!cvPfIsLegBookableNow($firstLeg)) {
            return null;
        }

        $durationMinutes = (int) floor(($arr->getTimestamp() - $dep->getTimestamp()) / 60);
        if ($durationMinutes <= 0 || $durationMinutes > 3000) {
            return null;
        }

        $currency = '';
        $amount = 0.0;
        $legsOutput = [];
        $identityParts = [];

        foreach ($legs as $leg) {
            $legCurrency = trim((string) ($leg['currency'] ?? 'EUR'));
            if ($legCurrency === '') {
                $legCurrency = 'EUR';
            }
            if ($currency === '') {
                $currency = $legCurrency;
            } elseif ($currency !== $legCurrency) {
                return null;
            }

            $amount += (float) ($leg['amount'] ?? 0.0);
            $legsOutput[] = cvPfFormatLegOutput($leg);
            $identityParts[] = cvPfLegSignature($leg);
        }

        if ($currency === '') {
            $currency = 'EUR';
        }

        $solutionId = hash('sha1', implode('>', $identityParts));
        $solution = [
            'solution_id' => $solutionId,
            'solution_ref' => substr(hash('sha256', $solutionId), 0, 20),
            'transfers' => max(0, count($legs) - 1),
            'departure_iso' => $dep->format(DateTimeInterface::ATOM),
            'arrival_iso' => $arr->format(DateTimeInterface::ATOM),
            'departure_hm' => $dep->format('H:i'),
            'arrival_hm' => $arr->format('H:i'),
            'duration_minutes' => $durationMinutes,
            'amount' => $amount,
            'currency' => $currency,
            'transfer_details' => $transfers,
            'legs' => $legsOutput,
        ];

        $firstProviderId = (int) ($firstLeg['provider_id'] ?? 0);
        $firstFromStop = (string) ($firstLeg['from_stop_id'] ?? '');
        $lastProviderId = (int) ($lastLeg['provider_id'] ?? 0);
        $lastToStop = (string) ($lastLeg['to_stop_id'] ?? '');
        $solution['edge_keys'] = [
            'from' => cvPfProviderStopKey($firstProviderId, $firstFromStop),
            'to' => cvPfProviderStopKey($lastProviderId, $lastToStop),
        ];

        if (isset($transfers[0]) && is_array($transfers[0])) {
            $solution['transfer'] = $transfers[0];
        }

        return $solution;
    }
}

if (!function_exists('cvPfSolutionHasCoherentDirection')) {
    /**
     * Blocks loop-like paths that return to an already visited hub/stop.
     *
     * @param array<int,array<string,mixed>> $legs
     */
    function cvPfSolutionHasNoRepeatedStops(array $legs): bool
    {
        if (count($legs) <= 1) {
            return true;
        }

        $visitedStops = [];
        $geoLoopRadiusKm = 1.5;

        $registerStop = static function (array $leg, string $prefix) use (&$visitedStops): void {
            $providerId = (int) ($leg[$prefix . '_provider_id'] ?? 0);
            $externalId = trim((string) ($leg[$prefix . '_stop_id'] ?? ''));
            $name = trim((string) ($leg[$prefix . '_stop_name'] ?? ''));
            $lat = $leg[$prefix . '_lat'] ?? null;
            $lon = $leg[$prefix . '_lon'] ?? null;

            $visitedStops[] = [
                'key' => ($providerId > 0 && $externalId !== '') ? cvPfProviderStopKey($providerId, $externalId) : '',
                'name' => $name !== '' ? cvPfNormalizeStopName($name) : '',
                'lat' => is_numeric($lat) ? (float) $lat : null,
                'lon' => is_numeric($lon) ? (float) $lon : null,
            ];
        };

        $matchesVisitedStop = static function (array $leg, string $prefix) use (&$visitedStops, $geoLoopRadiusKm): bool {
            $providerId = (int) ($leg[$prefix . '_provider_id'] ?? 0);
            $externalId = trim((string) ($leg[$prefix . '_stop_id'] ?? ''));
            $name = trim((string) ($leg[$prefix . '_stop_name'] ?? ''));
            $lat = $leg[$prefix . '_lat'] ?? null;
            $lon = $leg[$prefix . '_lon'] ?? null;

            $currentKey = ($providerId > 0 && $externalId !== '') ? cvPfProviderStopKey($providerId, $externalId) : '';
            $currentName = $name !== '' ? cvPfNormalizeStopName($name) : '';
            $currentLat = is_numeric($lat) ? (float) $lat : null;
            $currentLon = is_numeric($lon) ? (float) $lon : null;

            foreach ($visitedStops as $visited) {
                $visitedKey = (string) ($visited['key'] ?? '');
                if ($currentKey !== '' && $visitedKey !== '' && $currentKey === $visitedKey) {
                    return true;
                }

                $visitedName = (string) ($visited['name'] ?? '');
                $visitedLat = isset($visited['lat']) && is_numeric($visited['lat']) ? (float) $visited['lat'] : null;
                $visitedLon = isset($visited['lon']) && is_numeric($visited['lon']) ? (float) $visited['lon'] : null;

                if (
                    $currentName !== ''
                    && $visitedName !== ''
                    && $currentName === $visitedName
                    && $currentLat !== null
                    && $currentLon !== null
                    && $visitedLat !== null
                    && $visitedLon !== null
                    && cvPfHaversineKm($currentLat, $currentLon, $visitedLat, $visitedLon) <= $geoLoopRadiusKm
                ) {
                    return true;
                }
            }

            return false;
        };

        $registerStop($legs[0], 'from');
        foreach ($legs as $leg) {
            if ($matchesVisitedStop($leg, 'to')) {
                return false;
            }
            $registerStop($leg, 'to');
        }

        return true;
    }

    /**
     * Rejects solutions that strongly move away from the final destination after
     * having made meaningful progress towards it.
     *
     * @param array<int,array<string,mixed>> $legs
     */
    function cvPfSolutionHasCoherentDirection(array $legs): bool
    {
        if (count($legs) <= 1) {
            return true;
        }

        $lastLeg = $legs[count($legs) - 1];
        if (!isset($lastLeg['to_lat'], $lastLeg['to_lon'])) {
            return true;
        }

        $destinationLat = is_numeric($lastLeg['to_lat']) ? (float) $lastLeg['to_lat'] : null;
        $destinationLon = is_numeric($lastLeg['to_lon']) ? (float) $lastLeg['to_lon'] : null;
        if ($destinationLat === null || $destinationLon === null) {
            return true;
        }

        $previousDistanceKm = null;
        $enteredDestinationArea = false;
        $destinationAreaKm = 20.0;

        foreach ($legs as $index => $leg) {
            $arrivalLat = isset($leg['to_lat']) && is_numeric($leg['to_lat']) ? (float) $leg['to_lat'] : null;
            $arrivalLon = isset($leg['to_lon']) && is_numeric($leg['to_lon']) ? (float) $leg['to_lon'] : null;
            if ($arrivalLat === null || $arrivalLon === null) {
                continue;
            }

            if ($index === 0) {
                $departureLat = isset($leg['from_lat']) && is_numeric($leg['from_lat']) ? (float) $leg['from_lat'] : null;
                $departureLon = isset($leg['from_lon']) && is_numeric($leg['from_lon']) ? (float) $leg['from_lon'] : null;
                if ($departureLat !== null && $departureLon !== null) {
                    $previousDistanceKm = cvPfHaversineKm($departureLat, $departureLon, $destinationLat, $destinationLon);
                }
            }

            $arrivalDistanceKm = cvPfHaversineKm($arrivalLat, $arrivalLon, $destinationLat, $destinationLon);
            if ($previousDistanceKm !== null) {
                $allowedRegressionKm = max(8.0, min(25.0, $previousDistanceKm * 0.025));
                if ($arrivalDistanceKm > ($previousDistanceKm + $allowedRegressionKm)) {
                    return false;
                }
            }

            if ($enteredDestinationArea && $arrivalDistanceKm > ($destinationAreaKm + 5.0)) {
                return false;
            }

            if ($arrivalDistanceKm <= $destinationAreaKm) {
                $enteredDestinationArea = true;
            }

            $previousDistanceKm = $arrivalDistanceKm;
        }

        return true;
    }
}

if (!function_exists('cvPfSearchSolutions')) {
    /**
     * @return array<string,mixed>
     */
    function cvPfSearchSolutions(
        mysqli $connection,
        string $fromRefRaw,
        string $toRefRaw,
        string $travelDateIt,
        int $adults = 1,
        int $children = 0,
        int $maxTransfers = 2,
        string $codiceCamb = ''
    ): array {
        cvPfDebugLog('search_solutions.start', [
            'from_ref_raw' => $fromRefRaw,
            'to_ref_raw' => $toRefRaw,
            'travel_date_it' => $travelDateIt,
            'adults' => $adults,
            'children' => $children,
            'max_transfers' => $maxTransfers,
            'codice_camb' => $codiceCamb,
        ]);
        $codiceCamb = trim($codiceCamb);
        $maxTransfers = max(0, min(3, $maxTransfers));
        $fromRef = cvPfParseStopRef($fromRefRaw);
        $toRef = cvPfParseStopRef($toRefRaw);
        $date = cvPfParseDate($travelDateIt);

        if (!is_array($fromRef) || !is_array($toRef) || !$date instanceof DateTimeImmutable) {
            cvPfDebugLog('search_solutions.invalid_params', [
                'from_ref_raw' => $fromRefRaw,
                'to_ref_raw' => $toRefRaw,
                'travel_date_it' => $travelDateIt,
            ]);
            return [
                'ok' => false,
                'error' => 'Parametri di ricerca non validi.',
                'solutions' => [],
            ];
        }

        $fromSeedStops = cvPfFetchStopCandidates($connection, $fromRef);
        $toSeedStops = cvPfFetchStopCandidates($connection, $toRef);
        cvPfDebugLog('search_solutions.seed_candidates', [
            'from_ref_raw' => $fromRefRaw,
            'to_ref_raw' => $toRefRaw,
            'from_seed_count' => count($fromSeedStops),
            'to_seed_count' => count($toSeedStops),
            'from_provider_codes' => array_values(array_unique(array_map(static function (array $s): string {
                return (string) ($s['provider_code'] ?? '');
            }, $fromSeedStops))),
            'to_provider_codes' => array_values(array_unique(array_map(static function (array $s): string {
                return (string) ($s['provider_code'] ?? '');
            }, $toSeedStops))),
        ]);
        if (count($fromSeedStops) === 0 || count($toSeedStops) === 0) {
            cvPfDebugLog('search_solutions.seed_missing', [
                'from_ref_raw' => $fromRefRaw,
                'to_ref_raw' => $toRefRaw,
                'from_seed_count' => count($fromSeedStops),
                'to_seed_count' => count($toSeedStops),
            ]);
            return [
                'ok' => false,
                'error' => 'Fermate non trovate nel catalogo sincronizzato.',
                'solutions' => [],
            ];
        }

        $tuning = cvPfSearchTuning($connection);
        $fromStops = cvPfExpandNearbyStops(
            $connection,
            $fromSeedStops,
            (float) ($tuning['from_radius_km'] ?? 5.0),
            (int) ($tuning['nearby_max_extras'] ?? 12)
        );
        $toStops = cvPfExpandNearbyStops(
            $connection,
            $toSeedStops,
            (float) ($tuning['to_radius_km'] ?? 5.0),
            (int) ($tuning['nearby_max_extras'] ?? 12)
        );
        $fromDistanceMap = cvPfBuildStopDistanceMap($fromStops);
        $toDistanceMap = cvPfBuildStopDistanceMap($toStops);

        $catalogVersion = cvPfCatalogVersion($connection);
        $settingsVersionToken = cvRuntimeSettingsVersionToken($connection);
        $singleProviderCode = cvPfSingleActiveProviderCode($connection);
        $cacheVersionToken = $catalogVersion . ':' . $settingsVersionToken;
        if (is_string($singleProviderCode) && $singleProviderCode !== '') {
            $cacheVersionToken = cvPfProviderVersionToken(
                cvPfProviderVersionMap($connection),
                [$singleProviderCode]
            ) . ':' . $settingsVersionToken;
        }
        $cacheKey = hash(
            'sha256',
            implode('|', [
                'v12',
                $cacheVersionToken,
                $fromRefRaw,
                $toRefRaw,
                $travelDateIt,
                (string) max(0, $adults),
                (string) max(0, $children),
                (string) $maxTransfers,
                $codiceCamb,
            ])
        );
        $cached = cvPfCacheRead($cacheKey, cvPfSearchCacheTtlSeconds($connection));
        if (is_array($cached)) {
            $cached['meta']['cache'] = 'hit';
            if (!isset($cached['meta']['max_transfers'])) {
                $cached['meta']['max_transfers'] = $maxTransfers;
            }
            return $cached;
        }

        $providerFirstResponse = cvPfSearchSingleProviderAuthoritative(
            $connection,
            $fromRef,
            $toRef,
            $fromRefRaw,
            $toRefRaw,
            $fromSeedStops,
            $toSeedStops,
            $travelDateIt,
            $catalogVersion,
            $adults,
            $children,
            $maxTransfers,
            $codiceCamb
        );
        if (is_array($providerFirstResponse)) {
            cvPfDebugLog('search_solutions.single_provider_response', [
                'ok' => (bool) ($providerFirstResponse['ok'] ?? false),
                'error' => (string) ($providerFirstResponse['error'] ?? ''),
                'provider_status' => (string) ($providerFirstResponse['meta']['provider_status'] ?? ''),
                'provider_error' => (string) ($providerFirstResponse['meta']['provider_error'] ?? ''),
                'solutions_count' => isset($providerFirstResponse['solutions']) && is_array($providerFirstResponse['solutions']) ? count($providerFirstResponse['solutions']) : 0,
            ]);
            if (($providerFirstResponse['ok'] ?? false) === true) {
                cvPfCacheWrite($cacheKey, $providerFirstResponse);
            }
            return $providerFirstResponse;
        }

        $directRows = cvPfQuerySegments($connection, $fromStops, $toStops, 3000);
        $originRows = cvPfQuerySegments($connection, $fromStops, [], 5000);
        $destinationRows = cvPfQuerySegments($connection, [], $toStops, 5000);
        $allRows = [];

        $directLegsRaw = cvPfBuildLegsFromRows($directRows, $date);
        $originLegsRaw = cvPfBuildLegsFromRows($originRows, $date);
        $directLegs = $directLegsRaw;
        $originLegs = $originLegsRaw;
        $destinationLegs = cvPfBuildLegsFromRows($destinationRows, $date);
        $allLegs = cvPfBuildLegsFromRows($allRows, $date);

        cvPfSortLegsByDeparture($directLegs);
        cvPfSortLegsByDeparture($originLegs);
        cvPfSortLegsByDeparture($destinationLegs);
        cvPfSortLegsByDeparture($allLegs);

        $destinationIndexes = cvPfIndexLegsByOrigin($destinationLegs);
        $allIndexes = cvPfIndexLegsByOrigin($allLegs);

        $solutions = [];
        $dedupe = [];
        $maxSolutions = 600;
        $hasExactDirectSolution = false;

        foreach ($directLegs as $leg) {
            $resolvedLegs = cvPfResolveSolutionLegs($connection, [$leg], $adults, $children);
            if (!is_array($resolvedLegs)) {
                continue;
            }

            $solution = cvPfBuildSolutionFromLegs($resolvedLegs, []);
            if (!is_array($solution)) {
                continue;
            }

            $edgeFromKey = isset($solution['edge_keys']['from']) ? (string) $solution['edge_keys']['from'] : '';
            $edgeToKey = isset($solution['edge_keys']['to']) ? (string) $solution['edge_keys']['to'] : '';
            $fromDistanceKm = isset($fromDistanceMap[$edgeFromKey]) ? (float) $fromDistanceMap[$edgeFromKey] : 0.0;
            $toDistanceKm = isset($toDistanceMap[$edgeToKey]) ? (float) $toDistanceMap[$edgeToKey] : 0.0;
            $solution['access'] = [
                'from_distance_km' => $fromDistanceKm,
                'to_distance_km' => $toDistanceKm,
                'from_is_nearby' => $fromDistanceKm > 0.05,
                'to_is_nearby' => $toDistanceKm > 0.05,
            ];
            if ($fromDistanceKm <= 0.05 && $toDistanceKm <= 0.05) {
                $hasExactDirectSolution = true;
            }

            $solutionId = (string) ($solution['solution_id'] ?? '');
            if ($solutionId === '' || isset($dedupe[$solutionId])) {
                continue;
            }
            unset($solution['edge_keys']);
            $dedupe[$solutionId] = true;
            $solutions[] = $solution;
        }

        $oneTransferCap = 4000;
        $oneTransferBuilt = 0;
        foreach ($originLegs as $leg1) {
            if ($oneTransferBuilt >= $oneTransferCap || count($solutions) >= $maxSolutions) {
                break;
            }

            $nextCandidates = cvPfCandidateNextLegs($leg1, $destinationIndexes, 80, $connection);
            foreach ($nextCandidates as $leg2Base) {
                if ($oneTransferBuilt >= $oneTransferCap || count($solutions) >= $maxSolutions) {
                    break;
                }

                $connectionLeg = cvPfTryConnectLegs($leg1, $leg2Base, $connection, $adults, $children);
                if (!is_array($connectionLeg)) {
                    continue;
                }

                $resolvedLegs = cvPfResolveSolutionLegs(
                    $connection,
                    [$leg1, $connectionLeg['leg']],
                    $adults,
                    $children
                );
                if (!is_array($resolvedLegs)) {
                    continue;
                }

                if (!cvPfSolutionHasNoRepeatedStops($resolvedLegs) || !cvPfSolutionHasCoherentDirection($resolvedLegs)) {
                    continue;
                }

                $solution = cvPfBuildSolutionFromLegs($resolvedLegs, [$connectionLeg['transfer']]);
                if (!is_array($solution)) {
                    continue;
                }

                $edgeFromKey = isset($solution['edge_keys']['from']) ? (string) $solution['edge_keys']['from'] : '';
                $edgeToKey = isset($solution['edge_keys']['to']) ? (string) $solution['edge_keys']['to'] : '';
                $fromDistanceKm = isset($fromDistanceMap[$edgeFromKey]) ? (float) $fromDistanceMap[$edgeFromKey] : 0.0;
                $toDistanceKm = isset($toDistanceMap[$edgeToKey]) ? (float) $toDistanceMap[$edgeToKey] : 0.0;
                $solution['access'] = [
                    'from_distance_km' => $fromDistanceKm,
                    'to_distance_km' => $toDistanceKm,
                    'from_is_nearby' => $fromDistanceKm > 0.05,
                    'to_is_nearby' => $toDistanceKm > 0.05,
                ];

                $solutionId = (string) ($solution['solution_id'] ?? '');
                if ($solutionId === '' || isset($dedupe[$solutionId])) {
                    continue;
                }
                unset($solution['edge_keys']);
                $dedupe[$solutionId] = true;
                $solutions[] = $solution;
                $oneTransferBuilt++;
            }
        }

        $runTwoTransfers = $maxTransfers >= 2
            && count($solutions) < (int) ($tuning['two_transfer_trigger_max_solutions'] ?? 12)
            && !$hasExactDirectSolution;

        if ($runTwoTransfers) {
            $allRows = cvPfQuerySegments($connection, [], [], (int) ($tuning['all_rows_limit'] ?? 6000));
            $allLegs = cvPfBuildLegsFromRows($allRows, $date);
            cvPfSortLegsByDeparture($allLegs);
            $allIndexes = cvPfIndexLegsByOrigin($allLegs);

            $twoTransferCap = 5000;
            $twoTransferBuilt = 0;

            foreach ($originLegs as $leg1) {
                if ($twoTransferBuilt >= $twoTransferCap || count($solutions) >= $maxSolutions) {
                    break;
                }

                $midCandidates = cvPfCandidateNextLegs($leg1, $allIndexes, 70, $connection);
                foreach ($midCandidates as $midLegBase) {
                    if ($twoTransferBuilt >= $twoTransferCap || count($solutions) >= $maxSolutions) {
                        break 2;
                    }

                    $connection1 = cvPfTryConnectLegs($leg1, $midLegBase, $connection, $adults, $children);
                    if (!is_array($connection1)) {
                        continue;
                    }

                    $leg2 = $connection1['leg'];
                    $trip1 = cvPfLegTripIdentity($leg1);
                    $trip2 = cvPfLegTripIdentity($leg2);
                    if ($trip1 === $trip2) {
                        continue;
                    }

                    $lastCandidates = cvPfCandidateNextLegs($leg2, $destinationIndexes, 70, $connection);
                    foreach ($lastCandidates as $leg3Base) {
                        if ($twoTransferBuilt >= $twoTransferCap || count($solutions) >= $maxSolutions) {
                            break 3;
                        }

                        $trip3Base = cvPfLegTripIdentity($leg3Base);
                        if ($trip3Base === $trip1 || $trip3Base === $trip2) {
                            continue;
                        }

                        $connection2 = cvPfTryConnectLegs($leg2, $leg3Base, $connection, $adults, $children);
                        if (!is_array($connection2)) {
                            continue;
                        }

                        $leg3 = $connection2['leg'];
                        $trip3 = cvPfLegTripIdentity($leg3);
                        if ($trip3 === $trip1 || $trip3 === $trip2) {
                            continue;
                        }

                        $resolvedLegs = cvPfResolveSolutionLegs(
                            $connection,
                            [$leg1, $leg2, $leg3],
                            $adults,
                            $children
                        );
                        if (!is_array($resolvedLegs)) {
                            continue;
                        }

                        if (!cvPfSolutionHasNoRepeatedStops($resolvedLegs) || !cvPfSolutionHasCoherentDirection($resolvedLegs)) {
                            continue;
                        }

                        $solution = cvPfBuildSolutionFromLegs(
                            $resolvedLegs,
                            [$connection1['transfer'], $connection2['transfer']]
                        );
                        if (!is_array($solution)) {
                            continue;
                        }

                        $edgeFromKey = isset($solution['edge_keys']['from']) ? (string) $solution['edge_keys']['from'] : '';
                        $edgeToKey = isset($solution['edge_keys']['to']) ? (string) $solution['edge_keys']['to'] : '';
                        $fromDistanceKm = isset($fromDistanceMap[$edgeFromKey]) ? (float) $fromDistanceMap[$edgeFromKey] : 0.0;
                        $toDistanceKm = isset($toDistanceMap[$edgeToKey]) ? (float) $toDistanceMap[$edgeToKey] : 0.0;
                        $solution['access'] = [
                            'from_distance_km' => $fromDistanceKm,
                            'to_distance_km' => $toDistanceKm,
                            'from_is_nearby' => $fromDistanceKm > 0.05,
                            'to_is_nearby' => $toDistanceKm > 0.05,
                        ];

                        $solutionId = (string) ($solution['solution_id'] ?? '');
                        if ($solutionId === '' || isset($dedupe[$solutionId])) {
                            continue;
                        }
                        unset($solution['edge_keys']);
                        $dedupe[$solutionId] = true;
                        $solutions[] = $solution;
                        $twoTransferBuilt++;
                    }
                }
            }
        }

        cvPfSortSolutionsList($solutions);

        if (count($solutions) > 180) {
            $solutions = array_slice($solutions, 0, 180);
        }

        $response = [
            'ok' => true,
            'error' => null,
            'meta' => [
                'cache' => 'miss',
                'catalog_version' => $catalogVersion,
                'max_transfers' => $maxTransfers,
                'from_seed_candidates' => count($fromSeedStops),
                'to_seed_candidates' => count($toSeedStops),
                'from_candidates' => count($fromStops),
                'to_candidates' => count($toStops),
                'direct_rows' => count($directRows),
                'origin_rows' => count($originRows),
                'destination_rows' => count($destinationRows),
                'all_rows' => count($allRows),
                'direct_legs_raw' => count($directLegsRaw),
                'direct_legs_valid' => count($directLegsRaw),
                'origin_legs_raw' => count($originLegsRaw),
                'origin_legs_valid' => count($originLegsRaw),
                'visibility_mode' => 'solution_segments_cached',
                'nearby_radius_from_km' => (float) ($tuning['from_radius_km'] ?? 5.0),
                'nearby_radius_to_km' => (float) ($tuning['to_radius_km'] ?? 5.0),
                'nearby_max_extras' => (int) ($tuning['nearby_max_extras'] ?? 12),
                'two_transfer_enabled' => $runTwoTransfers,
            ],
            'from' => [
                'ref' => $fromRefRaw,
                'label' => (string) ($fromSeedStops[0]['name'] ?? ''),
                'candidates' => $fromStops,
            ],
            'to' => [
                'ref' => $toRefRaw,
                'label' => (string) ($toSeedStops[0]['name'] ?? ''),
                'candidates' => $toStops,
            ],
            'travel_date_it' => $travelDateIt,
            'passengers' => [
                'adults' => max(0, $adults),
                'children' => max(0, $children),
                'total' => max(1, max(0, $adults) + max(0, $children)),
            ],
            'solutions' => $solutions,
        ];

        cvPfCacheWrite($cacheKey, $response);
        cvPfDebugLog('search_solutions.done', [
            'from_ref_raw' => $fromRefRaw,
            'to_ref_raw' => $toRefRaw,
            'travel_date_it' => $travelDateIt,
            'solutions_count' => count($solutions),
            'max_transfers' => $maxTransfers,
        ]);
        return $response;
    }
}
