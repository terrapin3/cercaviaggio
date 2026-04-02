<?php
declare(strict_types=1);

if (!function_exists('cvPlaceNormalizeLookup')) {
    function cvPlaceNormalizeLookup(string $value): string
    {
        $value = trim(mb_strtolower($value, 'UTF-8'));
        if ($value === '') {
            return '';
        }

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

        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }
}

if (!function_exists('cvPlaceSlugify')) {
    function cvPlaceSlugify(string $value): string
    {
        $normalized = cvPlaceNormalizeLookup($value);
        if ($normalized === '') {
            return '';
        }

        return trim(str_replace(' ', '-', $normalized), '-');
    }
}

if (!function_exists('cvPlaceDisplayLabel')) {
    function cvPlaceDisplayLabel(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $lower = mb_strtolower($value, 'UTF-8');
        $parts = preg_split('/\s+/u', $lower) ?: [$lower];
        $parts = array_map(
            static function (string $part): string {
                return mb_convert_case($part, MB_CASE_TITLE, 'UTF-8');
            },
            $parts
        );

        return implode(' ', $parts);
    }
}

if (!function_exists('cvPlaceTypeLabel')) {
    function cvPlaceTypeLabel(string $type): string
    {
        switch ($type) {
            case 'macroarea':
                return 'Macroarea';
            case 'province':
                return 'Provincia';
            case 'city':
                return 'Citta';
            case 'district':
                return 'Zona';
            case 'station_group':
                return 'Nodo';
            default:
                return 'Luogo';
        }
    }
}

if (!function_exists('cvPlaceTypeOrderSql')) {
    function cvPlaceTypeOrderSql(string $field = 'pl.place_type'): string
    {
        return "CASE {$field}
            WHEN 'city' THEN 1
            WHEN 'station_group' THEN 2
            WHEN 'district' THEN 3
            WHEN 'macroarea' THEN 4
            WHEN 'province' THEN 5
            ELSE 9
        END";
    }
}

if (!function_exists('cvPlaceTypeOrderValue')) {
    function cvPlaceTypeOrderValue(string $type): int
    {
        switch ($type) {
            case 'city':
                return 1;
            case 'station_group':
                return 2;
            case 'district':
                return 3;
            case 'macroarea':
                return 4;
            case 'province':
                return 5;
            default:
                return 9;
        }
    }
}

if (!function_exists('cvPlaceSuggestionDisplayName')) {
    function cvPlaceSuggestionDisplayName(string $name, string $placeType): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }

        $label = cvPlaceDisplayLabel($name);
        if (!in_array($placeType, ['macroarea', 'province'], true)) {
            return $label;
        }

        $clean = preg_replace(
            [
                '/^Area\s+/iu',
                '/^Provincia\s+(?:di|del|della|dell[\'’]|dei|degli)\s+/iu',
                '/^Provincia\s+/iu',
                '/^Citt[aà]\s+Metropolitana\s+(?:di|del|della|dell[\'’]|dei|degli)\s+/iu',
                '/^Citt[aà]\s+Metropolitana\s+/iu',
            ],
            '',
            $name
        );
        $clean = is_string($clean) ? trim(preg_replace('/\s+/u', ' ', $clean) ?? $clean) : '';
        if ($clean === '') {
            return $label;
        }

        $normalizedClean = cvPlaceNormalizeLookup($clean);
        if (mb_strlen($normalizedClean, 'UTF-8') <= 3 && strtoupper($clean) === strtoupper(trim($clean))) {
            return $label;
        }

        return cvPlaceDisplayLabel($clean);
    }
}

if (!function_exists('cvPlaceSuggestionContextLabel')) {
    function cvPlaceSuggestionContextLabel(string $displayName, string $provinceCode, string $countryCode): string
    {
        $provinceLabel = cvPlaceSuggestionDisplayName($provinceCode, 'province');
        if ($provinceLabel !== '' && cvPlaceNormalizeLookup($provinceLabel) !== cvPlaceNormalizeLookup($displayName)) {
            return $provinceLabel;
        }

        $countryCode = strtoupper(trim($countryCode));
        if ($countryCode !== '' && $countryCode !== 'IT') {
            return $countryCode;
        }

        return '';
    }
}

if (!function_exists('cvPlacesTablesExist')) {
    function cvPlacesTablesExist(mysqli $connection): bool
    {
        static $cache = null;
        if (is_bool($cache)) {
            return $cache;
        }

        $sql = "SELECT COUNT(*) AS total
                FROM information_schema.tables
                WHERE table_schema = DATABASE()
                  AND table_name IN ('cv_places', 'cv_place_stops', 'cv_place_aliases', 'cv_place_metrics')";
        $result = $connection->query($sql);
        if (!$result instanceof mysqli_result) {
            $cache = false;
            return $cache;
        }

        $row = $result->fetch_assoc();
        $result->free();
        $cache = isset($row['total']) && (int) $row['total'] === 4;
        return $cache;
    }
}

if (!function_exists('cvPlacesGenerationRunsTableExists')) {
    function cvPlacesGenerationRunsTableExists(mysqli $connection): bool
    {
        static $cache = null;
        if (is_bool($cache)) {
            return $cache;
        }

        $result = $connection->query("SHOW TABLES LIKE 'cv_place_generation_runs'");
        if (!$result instanceof mysqli_result) {
            $cache = false;
            return $cache;
        }

        $cache = $result->num_rows > 0;
        $result->free();
        return $cache;
    }
}

if (!function_exists('cvPlaceNameOverridesTableExists')) {
    function cvPlaceNameOverridesTableExists(mysqli $connection): bool
    {
        static $cache = null;
        if (is_bool($cache)) {
            return $cache;
        }

        $result = $connection->query("SHOW TABLES LIKE 'cv_place_name_overrides'");
        if (!$result instanceof mysqli_result) {
            $cache = false;
            return $cache;
        }

        $cache = $result->num_rows > 0;
        $result->free();
        return $cache;
    }
}

if (!function_exists('cvPlacesCountActiveEntries')) {
    function cvPlacesCountActiveEntries(mysqli $connection): int
    {
        if (!cvPlacesTablesExist($connection)) {
            return 0;
        }

        $result = $connection->query("SELECT COUNT(*) AS total FROM cv_places WHERE is_active = 1");
        if (!$result instanceof mysqli_result) {
            return 0;
        }

        $row = $result->fetch_assoc();
        $result->free();
        return isset($row['total']) ? (int) $row['total'] : 0;
    }
}

if (!function_exists('cvPlaceFetchActiveStopsDataset')) {
    /**
     * @return array<int,array<string,mixed>>
     */
    function cvPlaceFetchActiveStopsDataset(mysqli $connection): array
    {
        $sql = "SELECT
                    s.id,
                    s.external_id,
                    s.name,
                    s.lat,
                    s.lon,
                    s.raw_json,
                    p.id_provider,
                    p.code AS provider_code,
                    p.name AS provider_name
                FROM cv_provider_stops s
                INNER JOIN cv_providers p
                    ON p.id_provider = s.id_provider
                WHERE s.is_active = 1
                  AND p.is_active = 1
                ORDER BY p.code ASC, s.name ASC";
        $result = $connection->query($sql);
        if (!$result instanceof mysqli_result) {
            return [];
        }

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            if (!is_array($row)) {
                continue;
            }

            $rows[] = [
                'id_stop' => (int) ($row['id'] ?? 0),
                'id_provider' => (int) ($row['id_provider'] ?? 0),
                'provider_code' => (string) ($row['provider_code'] ?? ''),
                'provider_name' => (string) ($row['provider_name'] ?? ''),
                'external_id' => (string) ($row['external_id'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                'lat' => isset($row['lat']) ? (float) $row['lat'] : null,
                'lon' => isset($row['lon']) ? (float) $row['lon'] : null,
                'raw_json' => (string) ($row['raw_json'] ?? ''),
            ];
        }

        $result->free();
        return $rows;
    }
}

if (!function_exists('cvPlaceDecodeStopPayload')) {
    /**
     * @return array<string,mixed>
     */
    function cvPlaceDecodeStopPayload(string $rawJson): array
    {
        if (trim($rawJson) === '') {
            return [];
        }

        $decoded = json_decode($rawJson, true);
        return is_array($decoded) ? $decoded : [];
    }
}

if (!function_exists('cvPlaceArrayRead')) {
    /**
     * @param array<string,mixed> $payload
     * @param array<int,string> $path
     */
    function cvPlaceArrayRead(array $payload, array $path): ?string
    {
        $cursor = $payload;
        foreach ($path as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return null;
            }
            $cursor = $cursor[$segment];
        }

        if (is_scalar($cursor)) {
            $value = trim((string) $cursor);
            return $value !== '' ? $value : null;
        }

        return null;
    }
}

if (!function_exists('cvPlaceDeriveFallbackLocality')) {
    function cvPlaceDeriveFallbackLocality(string $stopName): string
    {
        $stopName = trim($stopName);
        if ($stopName === '') {
            return '';
        }

        $segments = preg_split('/\s*[-,\/]\s*|\s*\(\s*/u', $stopName) ?: [$stopName];
        foreach ($segments as $segment) {
            $segment = trim((string) $segment, " \t\n\r\0\x0B)");
            if (mb_strlen($segment, 'UTF-8') >= 3) {
                return $segment;
            }
        }

        return $stopName;
    }
}

if (!function_exists('cvPlaceExtractStopContext')) {
    /**
     * @param array<string,mixed> $stop
     * @return array<string,string|bool>
     */
    function cvPlaceExtractStopContext(array $stop): array
    {
        $payload = cvPlaceDecodeStopPayload((string) ($stop['raw_json'] ?? ''));

        $locality = '';
        $localityPaths = [
            ['address', 'comune'],
            ['comune'],
            ['city'],
            ['town'],
            ['municipality'],
            ['municipio'],
            ['locality_name'],
            ['localita_name'],
        ];
        foreach ($localityPaths as $path) {
            $candidate = cvPlaceArrayRead($payload, $path);
            if ($candidate !== null) {
                $locality = $candidate;
                break;
            }
        }

        $province = '';
        $provincePaths = [
            ['address', 'provincia'],
            ['provincia'],
            ['province'],
            ['province_code'],
            ['sigla_provincia'],
            ['prov'],
        ];
        foreach ($provincePaths as $path) {
            $candidate = cvPlaceArrayRead($payload, $path);
            if ($candidate !== null) {
                $province = $candidate;
                break;
            }
        }

        $country = cvPlaceArrayRead($payload, ['country_code']);
        if ($country === null) {
            $country = cvPlaceArrayRead($payload, ['address', 'country_code']);
        }
        $country = $country !== null ? strtoupper($country) : 'IT';

        $fallback = cvPlaceDeriveFallbackLocality((string) ($stop['name'] ?? ''));
        $displayName = $locality !== '' ? $locality : $fallback;
        $normalized = cvPlaceNormalizeLookup($displayName);

        return [
            'display_name' => cvPlaceDisplayLabel($displayName),
            'normalized_name' => $normalized,
            'fallback_name' => cvPlaceDisplayLabel($fallback),
            'fallback_normalized' => cvPlaceNormalizeLookup($fallback),
            'province_code' => strtoupper(trim($province)),
            'country_code' => $country,
            'has_locality' => $locality !== '',
        ];
    }
}

if (!function_exists('cvPlaceDistanceKm')) {
    function cvPlaceDistanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(max(0.0, 1.0 - $a)));
        return $earthRadius * $c;
    }
}

if (!function_exists('cvPlaceCentroid')) {
    /**
     * @param array<int,array<string,mixed>> $stops
     * @return array{lat:?float,lon:?float,radius_km:float}
     */
    function cvPlaceCentroid(array $stops): array
    {
        $sumLat = 0.0;
        $sumLon = 0.0;
        $count = 0;

        foreach ($stops as $stop) {
            $lat = isset($stop['lat']) ? (float) $stop['lat'] : null;
            $lon = isset($stop['lon']) ? (float) $stop['lon'] : null;
            if ($lat === null || $lon === null) {
                continue;
            }

            $sumLat += $lat;
            $sumLon += $lon;
            $count++;
        }

        if ($count === 0) {
            return ['lat' => null, 'lon' => null, 'radius_km' => 1.0];
        }

        $lat = $sumLat / $count;
        $lon = $sumLon / $count;
        $maxDistance = 0.0;

        foreach ($stops as $stop) {
            $stopLat = isset($stop['lat']) ? (float) $stop['lat'] : null;
            $stopLon = isset($stop['lon']) ? (float) $stop['lon'] : null;
            if ($stopLat === null || $stopLon === null) {
                continue;
            }

            $distance = cvPlaceDistanceKm($lat, $lon, $stopLat, $stopLon);
            if ($distance > $maxDistance) {
                $maxDistance = $distance;
            }
        }

        return [
            'lat' => $lat,
            'lon' => $lon,
            'radius_km' => max(1.0, min(25.0, $maxDistance + 0.35)),
        ];
    }
}

if (!function_exists('cvPlaceAutoCode')) {
    function cvPlaceAutoCode(string $type, string $name, string $scope = ''): string
    {
        $slug = cvPlaceSlugify($name);
        $scopeSlug = cvPlaceSlugify($scope);
        $parts = ['auto', $type];
        if ($scopeSlug !== '') {
            $parts[] = $scopeSlug;
        }
        if ($slug !== '') {
            $parts[] = $slug;
        }

        return implode('-', $parts);
    }
}

if (!function_exists('cvPlaceMacroareaName')) {
    function cvPlaceMacroareaName(string $provinceCode): string
    {
        $provinceCode = trim($provinceCode);
        if ($provinceCode === '') {
            return 'Macroarea diffusa';
        }

        if (mb_strlen($provinceCode, 'UTF-8') <= 3 && strtoupper($provinceCode) === $provinceCode) {
            return 'Provincia ' . strtoupper($provinceCode);
        }

        return 'Area ' . cvPlaceDisplayLabel($provinceCode);
    }
}

if (!function_exists('cvPlaceFetchNameOverrideMap')) {
    /**
     * @return array<string,array<string,string>>
     */
    function cvPlaceFetchNameOverrideMap(mysqli $connection): array
    {
        if (!cvPlaceNameOverridesTableExists($connection)) {
            return [];
        }

        $result = $connection->query(
            "SELECT place_code, manual_name, notes
             FROM cv_place_name_overrides
             WHERE is_active = 1"
        );
        if (!$result instanceof mysqli_result) {
            return [];
        }

        $map = [];
        while ($row = $result->fetch_assoc()) {
            if (!is_array($row)) {
                continue;
            }

            $code = trim((string) ($row['place_code'] ?? ''));
            if ($code === '') {
                continue;
            }

            $map[$code] = [
                'manual_name' => trim((string) ($row['manual_name'] ?? '')),
                'notes' => trim((string) ($row['notes'] ?? '')),
            ];
        }

        $result->free();
        return $map;
    }
}

if (!function_exists('cvPlaceSaveNameOverride')) {
    function cvPlaceSaveNameOverride(mysqli $connection, string $placeCode, string $manualName, string $notes = ''): bool
    {
        $placeCode = trim($placeCode);
        $manualName = trim($manualName);
        $notes = trim($notes);
        if ($placeCode === '' || $manualName === '' || !cvPlaceNameOverridesTableExists($connection)) {
            return false;
        }

        $statement = $connection->prepare(
            "INSERT INTO cv_place_name_overrides (place_code, manual_name, notes, is_active)
             VALUES (?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE
                manual_name = VALUES(manual_name),
                notes = VALUES(notes),
                is_active = 1,
                updated_at = NOW()"
        );
        if (!$statement instanceof mysqli_stmt) {
            return false;
        }

        $statement->bind_param('sss', $placeCode, $manualName, $notes);
        $ok = $statement->execute();
        $statement->close();
        return $ok;
    }
}

if (!function_exists('cvPlaceUpdateNameLive')) {
    function cvPlaceUpdateNameLive(mysqli $connection, int $idPlace, string $manualName): bool
    {
        if ($idPlace <= 0 || !cvPlacesTablesExist($connection)) {
            return false;
        }

        $manualName = trim($manualName);
        if ($manualName === '') {
            return false;
        }

        $normalizedName = cvPlaceNormalizeLookup($manualName);
        $statement = $connection->prepare(
            "UPDATE cv_places
             SET name = ?, normalized_name = ?, updated_at = NOW()
             WHERE id_place = ?
             LIMIT 1"
        );
        if (!$statement instanceof mysqli_stmt) {
            return false;
        }

        $statement->bind_param('ssi', $manualName, $normalizedName, $idPlace);
        $ok = $statement->execute();
        $statement->close();
        return $ok;
    }
}

if (!function_exists('cvPlaceStartGenerationRun')) {
    /**
     * @param array<string,mixed> $meta
     */
    function cvPlaceStartGenerationRun(mysqli $connection, array $meta): int
    {
        if (!cvPlacesGenerationRunsTableExists($connection)) {
            return 0;
        }

        $algorithmVersion = (string) ($meta['algorithm_version'] ?? 'v2');
        $sourceStopsCount = (int) ($meta['source_stops_count'] ?? 0);

        $statement = $connection->prepare(
            "INSERT INTO cv_place_generation_runs (status, algorithm_version, source_stops_count, started_at)
             VALUES ('running', ?, ?, NOW())"
        );
        if (!$statement instanceof mysqli_stmt) {
            return 0;
        }

        $statement->bind_param('si', $algorithmVersion, $sourceStopsCount);
        if (!$statement->execute()) {
            $statement->close();
            return 0;
        }

        $runId = (int) $statement->insert_id;
        $statement->close();
        return $runId;
    }
}

if (!function_exists('cvPlaceFinishGenerationRun')) {
    /**
     * @param array<string,mixed> $summary
     */
    function cvPlaceFinishGenerationRun(mysqli $connection, int $runId, string $status, array $summary): void
    {
        if ($runId <= 0 || !cvPlacesGenerationRunsTableExists($connection)) {
            return;
        }

        $generatedPlacesCount = (int) ($summary['generated_places_count'] ?? 0);
        $generatedLinksCount = (int) ($summary['generated_links_count'] ?? 0);
        $notes = isset($summary['notes']) ? (string) $summary['notes'] : null;

        $statement = $connection->prepare(
            "UPDATE cv_place_generation_runs
             SET status = ?, generated_places_count = ?, generated_links_count = ?, notes = ?, finished_at = NOW()
             WHERE id_run = ?
             LIMIT 1"
        );
        if (!$statement instanceof mysqli_stmt) {
            return;
        }

        $statement->bind_param('siisi', $status, $generatedPlacesCount, $generatedLinksCount, $notes, $runId);
        $statement->execute();
        $statement->close();
    }
}

if (!function_exists('cvPlacesGenerate')) {
    /**
     * @return array<string,mixed>
     */
    function cvPlacesGenerate(mysqli $connection): array
    {
        if (!cvPlacesTablesExist($connection)) {
            throw new RuntimeException('Le tabelle cv_places* non sono disponibili.');
        }

        $stops = cvPlaceFetchActiveStopsDataset($connection);
        $nameOverrides = cvPlaceFetchNameOverrideMap($connection);
        $runId = cvPlaceStartGenerationRun($connection, [
            'algorithm_version' => 'v2',
            'source_stops_count' => count($stops),
        ]);

        $cityGroups = [];
        foreach ($stops as $stop) {
            $idStop = (int) ($stop['id_stop'] ?? 0);
            if ($idStop <= 0) {
                continue;
            }

            $context = cvPlaceExtractStopContext($stop);
            $normalizedName = (string) ($context['normalized_name'] ?? '');
            if ($normalizedName === '') {
                continue;
            }

            $provinceCode = (string) ($context['province_code'] ?? '');
            $countryCode = (string) ($context['country_code'] ?? 'IT');
            $placeType = !empty($context['has_locality']) ? 'city' : 'station_group';
            $groupKey = $placeType . '|' . $countryCode . '|' . $provinceCode . '|' . $normalizedName;

            if (!isset($cityGroups[$groupKey])) {
                $cityGroups[$groupKey] = [
                    'place_type' => $placeType,
                    'name' => (string) ($context['display_name'] ?? ''),
                    'normalized_name' => $normalizedName,
                    'province_code' => $provinceCode,
                    'country_code' => $countryCode,
                    'aliases' => [],
                    'provider_codes' => [],
                    'stops' => [],
                ];
            }

            $cityGroups[$groupKey]['provider_codes'][(string) ($stop['provider_code'] ?? '')] = true;
            $cityGroups[$groupKey]['stops'][] = $stop;

            $fallbackName = trim((string) ($context['fallback_name'] ?? ''));
            if ($fallbackName !== '' && cvPlaceNormalizeLookup($fallbackName) !== $normalizedName) {
                $cityGroups[$groupKey]['aliases'][$fallbackName] = $fallbackName;
            }
        }

        $macroGroups = [];
        foreach ($cityGroups as $groupKey => $group) {
            $centroid = cvPlaceCentroid((array) ($group['stops'] ?? []));
            $cityGroups[$groupKey]['lat'] = $centroid['lat'];
            $cityGroups[$groupKey]['lon'] = $centroid['lon'];
            $cityGroups[$groupKey]['radius_km'] = $centroid['radius_km'];

            usort(
                $cityGroups[$groupKey]['stops'],
                static function (array $left, array $right) use ($centroid): int {
                    $leftDistance = 99999.0;
                    $rightDistance = 99999.0;

                    if ($centroid['lat'] !== null && $centroid['lon'] !== null) {
                        if (isset($left['lat'], $left['lon'])) {
                            $leftDistance = cvPlaceDistanceKm(
                                (float) $centroid['lat'],
                                (float) $centroid['lon'],
                                (float) $left['lat'],
                                (float) $left['lon']
                            );
                        }
                        if (isset($right['lat'], $right['lon'])) {
                            $rightDistance = cvPlaceDistanceKm(
                                (float) $centroid['lat'],
                                (float) $centroid['lon'],
                                (float) $right['lat'],
                                (float) $right['lon']
                            );
                        }
                    }

                    if (abs($leftDistance - $rightDistance) > 0.0001) {
                        return $leftDistance <=> $rightDistance;
                    }

                    return strcmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
                }
            );

            $provinceCode = (string) ($group['province_code'] ?? '');
            if ($provinceCode === '') {
                continue;
            }

            $macroKey = 'macroarea|' . (string) ($group['country_code'] ?? 'IT') . '|' . $provinceCode;
            if (!isset($macroGroups[$macroKey])) {
                $macroGroups[$macroKey] = [
                    'place_type' => 'macroarea',
                    'name' => cvPlaceMacroareaName($provinceCode),
                    'normalized_name' => cvPlaceNormalizeLookup(cvPlaceMacroareaName($provinceCode)),
                    'province_code' => $provinceCode,
                    'country_code' => (string) ($group['country_code'] ?? 'IT'),
                    'aliases' => [$provinceCode => $provinceCode],
                    'provider_codes' => [],
                    'stops' => [],
                    'children' => [],
                ];
            }

            $macroGroups[$macroKey]['children'][] = $groupKey;
            foreach ((array) ($group['provider_codes'] ?? []) as $providerCode => $_unused) {
                $macroGroups[$macroKey]['provider_codes'][$providerCode] = true;
            }
            foreach ((array) ($group['stops'] ?? []) as $stop) {
                $macroGroups[$macroKey]['stops'][] = $stop;
            }
        }

        foreach ($macroGroups as $macroKey => $group) {
            $centroid = cvPlaceCentroid((array) ($group['stops'] ?? []));
            $macroGroups[$macroKey]['lat'] = $centroid['lat'];
            $macroGroups[$macroKey]['lon'] = $centroid['lon'];
            $macroGroups[$macroKey]['radius_km'] = max(2.0, $centroid['radius_km']);
        }

        $connection->begin_transaction();

        try {
            $connection->query("DELETE FROM cv_places WHERE is_auto = 1");

            $placeStatement = $connection->prepare(
                "INSERT INTO cv_places (
                    code, name, normalized_name, place_type, parent_id_place, province_code, region_name, country_code,
                    lat, lon, radius_km, search_weight, demand_score, is_active, is_auto, source_updated_at
                 ) VALUES (
                    ?, ?, ?, ?, NULLIF(?, 0), NULLIF(?, ''), NULLIF(?, ''), ?,
                    NULLIF(?, ''), NULLIF(?, ''), ?, ?, ?, 1, 1, NULLIF(?, '')
                 )"
            );
            $aliasStatement = $connection->prepare(
                "INSERT INTO cv_place_aliases (id_place, alias, normalized_alias, search_weight, is_active)
                 VALUES (?, ?, ?, ?, 1)
                 ON DUPLICATE KEY UPDATE search_weight = VALUES(search_weight), is_active = 1, updated_at = NOW()"
            );
            $placeStopStatement = $connection->prepare(
                "INSERT INTO cv_place_stops (id_place, id_stop, match_type, distance_km, priority, is_primary)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    match_type = VALUES(match_type),
                    distance_km = VALUES(distance_km),
                    priority = VALUES(priority),
                    is_primary = VALUES(is_primary),
                    updated_at = NOW()"
            );
            $metricStatement = $connection->prepare(
                "INSERT INTO cv_place_metrics (id_place, departures_count, arrivals_count, searches_count, bookings_count, popularity_score, refreshed_at)
                 VALUES (?, ?, ?, 0, 0, ?, NOW())
                 ON DUPLICATE KEY UPDATE
                    departures_count = VALUES(departures_count),
                    arrivals_count = VALUES(arrivals_count),
                    popularity_score = VALUES(popularity_score),
                    refreshed_at = VALUES(refreshed_at),
                    updated_at = NOW()"
            );

            if (
                !$placeStatement instanceof mysqli_stmt
                || !$aliasStatement instanceof mysqli_stmt
                || !$placeStopStatement instanceof mysqli_stmt
                || !$metricStatement instanceof mysqli_stmt
            ) {
                throw new RuntimeException('Impossibile preparare le query di generazione macroaree.');
            }

            $insertPlace = static function (array $group, int $parentId = 0) use (
                $nameOverrides,
                $placeStatement,
                $aliasStatement,
                $placeStopStatement,
                $metricStatement
            ): int {
                $code = cvPlaceAutoCode(
                    (string) ($group['place_type'] ?? 'city'),
                    (string) ($group['name'] ?? ''),
                    (string) ($group['province_code'] ?? '')
                );
                $name = (string) ($group['name'] ?? '');
                $normalizedName = (string) ($group['normalized_name'] ?? '');
                if (isset($nameOverrides[$code]['manual_name']) && trim((string) $nameOverrides[$code]['manual_name']) !== '') {
                    $name = trim((string) $nameOverrides[$code]['manual_name']);
                    $normalizedName = cvPlaceNormalizeLookup($name);
                }
                $placeType = (string) ($group['place_type'] ?? 'city');
                $provinceCode = (string) ($group['province_code'] ?? '');
                $regionName = '';
                $countryCode = (string) ($group['country_code'] ?? 'IT');
                $lat = isset($group['lat']) && $group['lat'] !== null ? number_format((float) $group['lat'], 7, '.', '') : '';
                $lon = isset($group['lon']) && $group['lon'] !== null ? number_format((float) $group['lon'], 7, '.', '') : '';
                $radiusKm = number_format((float) ($group['radius_km'] ?? 2.0), 2, '.', '');
                $searchWeightBase = $placeType === 'macroarea' ? 1400 : ($placeType === 'station_group' ? 2300 : 3000);
                $stopCount = count((array) ($group['stops'] ?? []));
                $providerCount = count((array) ($group['provider_codes'] ?? []));
                $searchWeight = $searchWeightBase + ($stopCount * 40) + ($providerCount * 70);
                $demandScore = number_format(($stopCount * 10) + ($providerCount * 15), 2, '.', '');
                $sourceUpdatedAt = '';

                $placeStatement->bind_param(
                    'ssssissssssiss',
                    $code,
                    $name,
                    $normalizedName,
                    $placeType,
                    $parentId,
                    $provinceCode,
                    $regionName,
                    $countryCode,
                    $lat,
                    $lon,
                    $radiusKm,
                    $searchWeight,
                    $demandScore,
                    $sourceUpdatedAt
                );
                if (!$placeStatement->execute()) {
                    throw new RuntimeException('Insert place fallita: ' . $placeStatement->error);
                }

                $idPlace = (int) $placeStatement->insert_id;
                if ($idPlace <= 0) {
                    throw new RuntimeException('Insert place senza id.');
                }

                $aliases = (array) ($group['aliases'] ?? []);
                foreach ($aliases as $alias) {
                    $alias = trim((string) $alias);
                    $normalizedAlias = cvPlaceNormalizeLookup($alias);
                    if ($alias === '' || $normalizedAlias === '' || $normalizedAlias === $normalizedName) {
                        continue;
                    }

                    $aliasWeight = $placeType === 'macroarea' ? 140 : 180;
                    $aliasStatement->bind_param('issi', $idPlace, $alias, $normalizedAlias, $aliasWeight);
                    if (!$aliasStatement->execute()) {
                        throw new RuntimeException('Insert alias fallita: ' . $aliasStatement->error);
                    }
                }

                $centroidLat = isset($group['lat']) ? $group['lat'] : null;
                $centroidLon = isset($group['lon']) ? $group['lon'] : null;
                $stops = (array) ($group['stops'] ?? []);
                foreach ($stops as $index => $stop) {
                    $idStop = (int) ($stop['id_stop'] ?? 0);
                    if ($idStop <= 0) {
                        continue;
                    }

                    $distanceKm = '0.000';
                    if ($centroidLat !== null && $centroidLon !== null && isset($stop['lat'], $stop['lon'])) {
                        $distanceKm = number_format(
                            cvPlaceDistanceKm(
                                (float) $centroidLat,
                                (float) $centroidLon,
                                (float) $stop['lat'],
                                (float) $stop['lon']
                            ),
                            3,
                            '.',
                            ''
                        );
                    }

                    $matchType = $placeType === 'macroarea' ? 'nearby' : 'auto_cluster';
                    $priority = $index + 1;
                    $isPrimary = $index === 0 ? 1 : 0;

                    $placeStopStatement->bind_param('iissii', $idPlace, $idStop, $matchType, $distanceKm, $priority, $isPrimary);
                    if (!$placeStopStatement->execute()) {
                        throw new RuntimeException('Insert place stop fallita: ' . $placeStopStatement->error);
                    }
                }

                $departuresCount = $stopCount;
                $arrivalsCount = $stopCount;
                $popularityScore = number_format(($stopCount * 5) + ($providerCount * 15), 2, '.', '');

                $metricStatement->bind_param('iiis', $idPlace, $departuresCount, $arrivalsCount, $popularityScore);
                if (!$metricStatement->execute()) {
                    throw new RuntimeException('Insert metrics fallita: ' . $metricStatement->error);
                }

                return $idPlace;
            };

            $macroIds = [];
            foreach ($macroGroups as $macroKey => $group) {
                $macroIds[$macroKey] = $insertPlace($group, 0);
            }

            $generatedLinksCount = 0;
            foreach ($cityGroups as $groupKey => $group) {
                $macroKey = 'macroarea|' . (string) ($group['country_code'] ?? 'IT') . '|' . (string) ($group['province_code'] ?? '');
                $parentId = (int) ($macroIds[$macroKey] ?? 0);
                $insertPlace($group, $parentId);
                $generatedLinksCount += count((array) ($group['stops'] ?? []));
            }
            foreach ($macroGroups as $group) {
                $generatedLinksCount += count((array) ($group['stops'] ?? []));
            }

            $placeStatement->close();
            $aliasStatement->close();
            $placeStopStatement->close();
            $metricStatement->close();

            $connection->commit();

            $summary = [
                'run_id' => $runId,
                'source_stops_count' => count($stops),
                'generated_city_count' => count($cityGroups),
                'generated_macroarea_count' => count($macroGroups),
                'generated_places_count' => count($cityGroups) + count($macroGroups),
                'generated_links_count' => $generatedLinksCount,
                'notes' => 'Rigenerazione automatica completata.',
            ];
            cvPlaceFinishGenerationRun($connection, $runId, 'completed', $summary);
            return $summary;
        } catch (Throwable $exception) {
            $connection->rollback();
            cvPlaceFinishGenerationRun($connection, $runId, 'failed', [
                'generated_places_count' => 0,
                'generated_links_count' => 0,
                'notes' => $exception->getMessage(),
            ]);
            throw $exception;
        }
    }
}

if (!function_exists('cvPlacesFetchAdminSummary')) {
    /**
     * @return array<string,mixed>
     */
    function cvPlacesFetchAdminSummary(mysqli $connection): array
    {
        $summary = [
            'tables_exist' => cvPlacesTablesExist($connection),
            'total_places' => 0,
            'total_macroareas' => 0,
            'total_cities' => 0,
            'total_station_groups' => 0,
            'total_links' => 0,
            'providers_covered' => 0,
            'last_run' => null,
        ];

        if (!$summary['tables_exist']) {
            return $summary;
        }

        $result = $connection->query(
            "SELECT place_type, COUNT(*) AS total
             FROM cv_places
             WHERE is_active = 1
             GROUP BY place_type"
        );
        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                if (!is_array($row)) {
                    continue;
                }
                $type = (string) ($row['place_type'] ?? '');
                $total = (int) ($row['total'] ?? 0);
                $summary['total_places'] += $total;
                if ($type === 'macroarea') {
                    $summary['total_macroareas'] = $total;
                } elseif ($type === 'city') {
                    $summary['total_cities'] = $total;
                } elseif ($type === 'station_group') {
                    $summary['total_station_groups'] = $total;
                }
            }
            $result->free();
        }

        $result = $connection->query(
            "SELECT
                COUNT(*) AS total_links,
                COUNT(DISTINCT s.id_provider) AS providers_covered
             FROM cv_place_stops ps
             INNER JOIN cv_provider_stops s
                 ON s.id = ps.id_stop
             INNER JOIN cv_places pl
                 ON pl.id_place = ps.id_place
             WHERE pl.is_active = 1"
        );
        if ($result instanceof mysqli_result) {
            $row = $result->fetch_assoc();
            $result->free();
            $summary['total_links'] = isset($row['total_links']) ? (int) $row['total_links'] : 0;
            $summary['providers_covered'] = isset($row['providers_covered']) ? (int) $row['providers_covered'] : 0;
        }

        if (cvPlacesGenerationRunsTableExists($connection)) {
            $result = $connection->query(
                "SELECT id_run, status, algorithm_version, source_stops_count, generated_places_count, generated_links_count, started_at, finished_at
                 FROM cv_place_generation_runs
                 ORDER BY id_run DESC
                 LIMIT 1"
            );
            if ($result instanceof mysqli_result) {
                $row = $result->fetch_assoc();
                $result->free();
                $summary['last_run'] = is_array($row) ? $row : null;
            }
        }

        return $summary;
    }
}

if (!function_exists('cvPlacesFetchAdminRows')) {
    /**
     * @return array<int,array<string,mixed>>
     */
    function cvPlacesFetchAdminRows(mysqli $connection, array $filters = [], int $limit = 60): array
    {
        if (!cvPlacesTablesExist($connection)) {
            return [];
        }

        $safeLimit = max(1, min(200, $limit));
        $search = trim((string) ($filters['q'] ?? ''));
        $type = trim((string) ($filters['type'] ?? ''));
        $province = strtoupper(trim((string) ($filters['province'] ?? '')));
        $onlyEdited = !empty($filters['edited_only']) && cvPlaceNameOverridesTableExists($connection);

        $conditions = ['pl.is_active = 1'];
        if ($search !== '') {
            $escaped = $connection->real_escape_string($search);
            $conditions[] = "(pl.name LIKE '%{$escaped}%' OR parent.name LIKE '%{$escaped}%' OR pl.code LIKE '%{$escaped}%')";
        }
        if ($type !== '') {
            $escaped = $connection->real_escape_string($type);
            $conditions[] = "pl.place_type = '{$escaped}'";
        }
        if ($province !== '') {
            $escaped = $connection->real_escape_string($province);
            $conditions[] = "pl.province_code = '{$escaped}'";
        }
        if ($onlyEdited) {
            $conditions[] = "n.place_code IS NOT NULL";
        }

        $sql = "SELECT
                    pl.id_place,
                    pl.code,
                    pl.name,
                    pl.place_type,
                    pl.province_code,
                    pl.search_weight,
                    pl.updated_at,
                    parent.name AS parent_name,
                    n.manual_name,
                    COUNT(ps.id_place_stop) AS stop_count,
                    COUNT(DISTINCT s.id_provider) AS provider_count
                FROM cv_places pl
                LEFT JOIN cv_places parent
                    ON parent.id_place = pl.parent_id_place
                " . (cvPlaceNameOverridesTableExists($connection)
                    ? "LEFT JOIN cv_place_name_overrides n
                        ON n.place_code = pl.code
                       AND n.is_active = 1"
                    : "LEFT JOIN (SELECT NULL AS place_code, NULL AS manual_name) n ON 1 = 0") . "
                LEFT JOIN cv_place_stops ps
                    ON ps.id_place = pl.id_place
                LEFT JOIN cv_provider_stops s
                    ON s.id = ps.id_stop
                WHERE " . implode(' AND ', $conditions) . "
                GROUP BY
                    pl.id_place,
                    pl.code,
                    pl.name,
                    pl.place_type,
                    pl.province_code,
                    pl.search_weight,
                    pl.updated_at,
                    parent.name,
                    n.manual_name
                ORDER BY " . cvPlaceTypeOrderSql('pl.place_type') . " ASC,
                    stop_count DESC,
                    pl.search_weight DESC,
                    pl.name ASC
                LIMIT {$safeLimit}";
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

if (!function_exists('cvPlacesFetchProvinceOptions')) {
    /**
     * @return array<int,string>
     */
    function cvPlacesFetchProvinceOptions(mysqli $connection): array
    {
        if (!cvPlacesTablesExist($connection)) {
            return [];
        }

        $result = $connection->query(
            "SELECT DISTINCT province_code
             FROM cv_places
             WHERE is_active = 1
               AND province_code <> ''
             ORDER BY province_code ASC"
        );
        if (!$result instanceof mysqli_result) {
            return [];
        }

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            if (!is_array($row)) {
                continue;
            }
            $provinceCode = trim((string) ($row['province_code'] ?? ''));
            if ($provinceCode !== '') {
                $rows[] = $provinceCode;
            }
        }
        $result->free();
        return $rows;
    }
}

if (!function_exists('cvPlacesFetchPlaceById')) {
    /**
     * @return array<string,mixed>|null
     */
    function cvPlacesFetchPlaceById(mysqli $connection, int $idPlace): ?array
    {
        if ($idPlace <= 0 || !cvPlacesTablesExist($connection)) {
            return null;
        }

        $sql = "SELECT
                    pl.id_place,
                    pl.code,
                    pl.name,
                    pl.normalized_name,
                    pl.place_type,
                    pl.province_code,
                    pl.country_code,
                    pl.search_weight,
                    pl.demand_score,
                    pl.updated_at,
                    parent.name AS parent_name,
                    parent.id_place AS parent_id_place,
                    n.manual_name,
                    n.notes,
                    COUNT(ps.id_place_stop) AS stop_count,
                    COUNT(DISTINCT s.id_provider) AS provider_count
                FROM cv_places pl
                LEFT JOIN cv_places parent
                    ON parent.id_place = pl.parent_id_place
                " . (cvPlaceNameOverridesTableExists($connection)
                    ? "LEFT JOIN cv_place_name_overrides n
                        ON n.place_code = pl.code
                       AND n.is_active = 1"
                    : "LEFT JOIN (SELECT NULL AS place_code, NULL AS manual_name, NULL AS notes) n ON 1 = 0") . "
                LEFT JOIN cv_place_stops ps
                    ON ps.id_place = pl.id_place
                LEFT JOIN cv_provider_stops s
                    ON s.id = ps.id_stop
                WHERE pl.id_place = ?
                  AND pl.is_active = 1
                GROUP BY
                    pl.id_place,
                    pl.code,
                    pl.name,
                    pl.normalized_name,
                    pl.place_type,
                    pl.province_code,
                    pl.country_code,
                    pl.search_weight,
                    pl.demand_score,
                    pl.updated_at,
                    parent.name,
                    parent.id_place,
                    n.manual_name,
                    n.notes
                LIMIT 1";
        $statement = $connection->prepare($sql);
        if (!$statement instanceof mysqli_stmt) {
            return null;
        }

        $statement->bind_param('i', $idPlace);
        if (!$statement->execute()) {
            $statement->close();
            return null;
        }

        $result = $statement->get_result();
        if (!$result instanceof mysqli_result) {
            $statement->close();
            return null;
        }

        $row = $result->fetch_assoc();
        $result->free();
        $statement->close();

        return is_array($row) ? $row : null;
    }
}

if (!function_exists('cvPlacesFetchSearchEntries')) {
    /**
     * @return array<int,array<string,mixed>>
     */
    function cvPlacesFetchSearchEntries(mysqli $connection): array
    {
        if (!cvPlacesTablesExist($connection) || cvPlacesCountActiveEntries($connection) === 0) {
            return [];
        }

        $sql = "SELECT
                    pl.id_place,
                    pl.name,
                    pl.place_type,
                    pl.province_code,
                    pl.country_code,
                    pl.lat,
                    pl.lon,
                    pl.search_weight,
                    COALESCE(pm.popularity_score, pl.demand_score) AS popularity_score,
                    COUNT(ps.id_place_stop) AS stop_count,
                    COUNT(DISTINCT s.id_provider) AS provider_count
                FROM cv_places pl
                LEFT JOIN cv_place_metrics pm
                    ON pm.id_place = pl.id_place
                LEFT JOIN cv_place_stops ps
                    ON ps.id_place = pl.id_place
                LEFT JOIN cv_provider_stops s
                    ON s.id = ps.id_stop
                WHERE pl.is_active = 1
                GROUP BY
                    pl.id_place,
                    pl.name,
                    pl.place_type,
                    pl.province_code,
                    pl.country_code,
                    pl.lat,
                    pl.lon,
                    pl.search_weight,
                    pm.popularity_score,
                    pl.demand_score
                ORDER BY " . cvPlaceTypeOrderSql('pl.place_type') . " ASC,
                    popularity_score DESC,
                    pl.search_weight DESC,
                    pl.name ASC";
        $result = $connection->query($sql);
        if (!$result instanceof mysqli_result) {
            return [];
        }

        $entries = [];
        $seenKeys = [];
        while ($row = $result->fetch_assoc()) {
            if (!is_array($row)) {
                continue;
            }

            $placeType = (string) ($row['place_type'] ?? 'city');
            $displayName = cvPlaceSuggestionDisplayName((string) ($row['name'] ?? ''), $placeType);
            if ($displayName === '') {
                $displayName = cvPlaceDisplayLabel((string) ($row['name'] ?? ''));
            }
            $provinceCode = trim((string) ($row['province_code'] ?? ''));
            $countryCode = strtoupper(trim((string) ($row['country_code'] ?? 'IT')));
            $provinceScope = cvPlaceNormalizeLookup(cvPlaceSuggestionDisplayName($provinceCode, 'province'));
            $displayKey = implode('|', [
                $countryCode,
                $provinceScope,
                cvPlaceNormalizeLookup($displayName),
            ]);
            if ($displayKey !== '||' && isset($seenKeys[$displayKey])) {
                continue;
            }
            $seenKeys[$displayKey] = true;

            $stopCount = isset($row['stop_count']) ? (int) $row['stop_count'] : 0;
            $providerCount = isset($row['provider_count']) ? (int) $row['provider_count'] : 0;
            $entries[] = [
                'provider_code' => 'place',
                'provider_name' => '',
                'id' => 'place|' . (string) ($row['id_place'] ?? ''),
                'external_id' => (string) ($row['id_place'] ?? ''),
                'name' => $displayName,
                'original_name' => (string) ($row['name'] ?? ''),
                'lat' => isset($row['lat']) ? (float) $row['lat'] : null,
                'lon' => isset($row['lon']) ? (float) $row['lon'] : null,
                'place_type' => $placeType,
                'province_code' => $provinceCode,
                'country_code' => $countryCode,
                'stop_count' => $stopCount,
                'provider_count' => $providerCount,
                'search_weight' => isset($row['search_weight']) ? (int) $row['search_weight'] : 0,
                'popularity_score' => isset($row['popularity_score']) ? (float) $row['popularity_score'] : 0.0,
                'place_type_order' => cvPlaceTypeOrderValue($placeType),
                'normalized_display_name' => cvPlaceNormalizeLookup($displayName),
            ];
        }

        $result->free();

        $nameOccurrences = [];
        foreach ($entries as $entry) {
            $nameKey = (string) ($entry['normalized_display_name'] ?? '');
            if ($nameKey === '') {
                continue;
            }
            $nameOccurrences[$nameKey] = ($nameOccurrences[$nameKey] ?? 0) + 1;
        }

        $rows = [];
        foreach ($entries as $entry) {
            $nameKey = (string) ($entry['normalized_display_name'] ?? '');
            $subtitle = '';
            if (($nameOccurrences[$nameKey] ?? 0) > 1) {
                $subtitle = cvPlaceSuggestionContextLabel(
                    (string) ($entry['name'] ?? ''),
                    (string) ($entry['province_code'] ?? ''),
                    (string) ($entry['country_code'] ?? 'IT')
                );
            }

            unset(
                $entry['province_code'],
                $entry['country_code'],
                $entry['search_weight'],
                $entry['popularity_score'],
                $entry['place_type_order'],
                $entry['normalized_display_name']
            );
            $entry['provider_name'] = $subtitle;
            $entry['search_text'] = trim(implode(' ', array_filter([
                (string) ($entry['name'] ?? ''),
                (string) ($entry['original_name'] ?? ''),
                (string) ($subtitle ?? ''),
            ])));
            unset($entry['original_name']);
            $rows[] = $entry;
        }

        return $rows;
    }
}

if (!function_exists('cvPlacesExpandToProviderStops')) {
    /**
     * @return array<int,array<string,mixed>>
     */
    function cvPlacesExpandToProviderStops(mysqli $connection, int $idPlace, int $limit = 24): array
    {
        if ($idPlace <= 0 || !cvPlacesTablesExist($connection)) {
            return [];
        }

        $safeLimit = max(1, min(80, $limit));
        $sql = "SELECT
                    s.id_provider,
                    p.code AS provider_code,
                    p.name AS provider_name,
                    s.external_id,
                    s.name,
                    s.lat,
                    s.lon
                FROM cv_place_stops ps
                INNER JOIN cv_places pl
                    ON pl.id_place = ps.id_place
                INNER JOIN cv_provider_stops s
                    ON s.id = ps.id_stop
                INNER JOIN cv_providers p
                    ON p.id_provider = s.id_provider
                WHERE ps.id_place = ?
                  AND pl.is_active = 1
                  AND s.is_active = 1
                  AND p.is_active = 1
                ORDER BY ps.is_primary DESC, ps.priority ASC, ps.distance_km ASC, p.code ASC, s.name ASC
                LIMIT {$safeLimit}";
        $statement = $connection->prepare($sql);
        if (!$statement instanceof mysqli_stmt) {
            return [];
        }

        $statement->bind_param('i', $idPlace);
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

        $result->free();
        $statement->close();
        return $rows;
    }
}
