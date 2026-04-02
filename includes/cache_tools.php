<?php
declare(strict_types=1);

if (!function_exists('cvCacheDirectoryMap')) {
    /**
     * @return array<string,array{label:string,path:string}>
     */
    function cvCacheDirectoryMap(): array
    {
        $base = dirname(__DIR__) . '/files/cache';
        return [
            'pathfind' => [
                'label' => 'Risultati soluzioni',
                'path' => $base . '/pathfind',
            ],
            'provider_search' => [
                'label' => 'Search provider',
                'path' => $base . '/provider_search',
            ],
            'provider_quote' => [
                'label' => 'Quote provider',
                'path' => $base . '/provider_quote',
            ],
        ];
    }
}

if (!function_exists('cvCacheNormalizeProviderCodes')) {
    /**
     * @param array<int,string> $providerCodes
     * @return array<int,string>
     */
    function cvCacheNormalizeProviderCodes(array $providerCodes): array
    {
        $normalized = [];
        foreach ($providerCodes as $providerCode) {
            $providerCode = trim((string) $providerCode);
            if ($providerCode === '') {
                continue;
            }

            $normalized[$providerCode] = $providerCode;
        }

        return array_values($normalized);
    }
}

if (!function_exists('cvCacheReadJsonFile')) {
    /**
     * @return array<string,mixed>|null
     */
    function cvCacheReadJsonFile(string $path): ?array
    {
        if (!is_file($path)) {
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

if (!function_exists('cvCachePayloadProviderCodes')) {
    /**
     * @param array<string,mixed> $payload
     * @return array<int,string>
     */
    function cvCachePayloadProviderCodes(string $bucket, array $payload): array
    {
        $providers = [];

        if ($bucket === 'provider_quote') {
            $providerCode = trim((string) ($payload['provider_code'] ?? ''));
            if ($providerCode !== '') {
                $providers[$providerCode] = $providerCode;
            }
            return array_values($providers);
        }

        if ($bucket === 'provider_search') {
            $providerCode = trim((string) ($payload['provider_code'] ?? ''));
            if ($providerCode !== '') {
                $providers[$providerCode] = $providerCode;
            }

            $solutions = isset($payload['solutions']) && is_array($payload['solutions']) ? $payload['solutions'] : [];
            foreach ($solutions as $solution) {
                if (!is_array($solution)) {
                    continue;
                }

                $segments = isset($solution['segments']) && is_array($solution['segments']) ? $solution['segments'] : [];
                foreach ($segments as $segment) {
                    if (!is_array($segment)) {
                        continue;
                    }

                    $segmentProvider = trim((string) ($segment['provider'] ?? ''));
                    if ($segmentProvider !== '') {
                        $providers[$segmentProvider] = $segmentProvider;
                    }
                }
            }

            return array_values($providers);
        }

        $meta = isset($payload['meta']) && is_array($payload['meta']) ? $payload['meta'] : [];
        $metaProvider = trim((string) ($meta['provider_code'] ?? ''));
        if ($metaProvider !== '') {
            $providers[$metaProvider] = $metaProvider;
        }

        $metaProviders = isset($meta['providers_used']) && is_array($meta['providers_used']) ? $meta['providers_used'] : [];
        foreach ($metaProviders as $providerCode) {
            $providerCode = trim((string) $providerCode);
            if ($providerCode !== '') {
                $providers[$providerCode] = $providerCode;
            }
        }

        $solutions = isset($payload['solutions']) && is_array($payload['solutions']) ? $payload['solutions'] : [];
        foreach ($solutions as $solution) {
            if (!is_array($solution)) {
                continue;
            }

            $legs = isset($solution['legs']) && is_array($solution['legs']) ? $solution['legs'] : [];
            foreach ($legs as $leg) {
                if (!is_array($leg)) {
                    continue;
                }

                $providerCode = trim((string) ($leg['provider_code'] ?? ''));
                if ($providerCode !== '') {
                    $providers[$providerCode] = $providerCode;
                }
            }
        }

        return array_values($providers);
    }
}

if (!function_exists('cvCachePurgeBuckets')) {
    /**
     * @param array<int,string> $bucketKeys
     * @param array<int,string>|null $providerCodes
     * @return array<string,array<string,mixed>>
     */
    function cvCachePurgeBuckets(array $bucketKeys, ?array $providerCodes = null): array
    {
        $directories = cvCacheDirectoryMap();
        $providerCodes = $providerCodes !== null ? cvCacheNormalizeProviderCodes($providerCodes) : null;
        $providerFilter = $providerCodes !== null && count($providerCodes) > 0
            ? array_fill_keys($providerCodes, true)
            : null;

        $results = [];
        foreach ($bucketKeys as $bucketKey) {
            $bucketKey = trim((string) $bucketKey);
            if ($bucketKey === '' || !isset($directories[$bucketKey])) {
                continue;
            }

            $dir = $directories[$bucketKey]['path'];
            $bucketResult = [
                'label' => $directories[$bucketKey]['label'],
                'path' => $dir,
                'scanned' => 0,
                'deleted' => 0,
                'matched' => 0,
                'errors' => [],
            ];

            if (!is_dir($dir)) {
                $results[$bucketKey] = $bucketResult;
                continue;
            }

            $files = glob($dir . '/*.json');
            if (!is_array($files)) {
                $results[$bucketKey] = $bucketResult;
                continue;
            }

            foreach ($files as $filePath) {
                $bucketResult['scanned']++;

                $shouldDelete = false;
                if ($providerFilter === null) {
                    $shouldDelete = true;
                } else {
                    $payload = cvCacheReadJsonFile($filePath);
                    if (is_array($payload)) {
                        $payloadProviders = cvCachePayloadProviderCodes($bucketKey, $payload);
                        foreach ($payloadProviders as $providerCode) {
                            if (isset($providerFilter[$providerCode])) {
                                $shouldDelete = true;
                                $bucketResult['matched']++;
                                break;
                            }
                        }
                    }
                }

                if (!$shouldDelete) {
                    continue;
                }

                if (!@unlink($filePath)) {
                    $bucketResult['errors'][] = basename($filePath);
                    continue;
                }

                $bucketResult['deleted']++;
            }

            $results[$bucketKey] = $bucketResult;
        }

        return $results;
    }
}

if (!function_exists('cvCacheBindDynamicParams')) {
    /**
     * @param array<int,mixed> $params
     */
    function cvCacheBindDynamicParams(mysqli_stmt $statement, string $types, array $params): bool
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

if (!function_exists('cvCacheFetchProviders')) {
    /**
     * @return array<int,array<string,mixed>>
     */
    function cvCacheFetchProviders(mysqli $connection): array
    {
        $sql = "SELECT id_provider, code, name, base_url, api_key, is_active, last_sync_at, last_error
                FROM cv_providers
                ORDER BY is_active DESC, code ASC";
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
                'id_provider' => isset($row['id_provider']) ? (int) $row['id_provider'] : 0,
                'code' => (string) ($row['code'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                'base_url' => (string) ($row['base_url'] ?? ''),
                'api_key' => (string) ($row['api_key'] ?? ''),
                'is_active' => isset($row['is_active']) ? (int) $row['is_active'] : 0,
                'last_sync_at' => (string) ($row['last_sync_at'] ?? ''),
                'last_error' => (string) ($row['last_error'] ?? ''),
            ];
        }

        $result->free();
        return $rows;
    }
}

if (!function_exists('cvCacheBumpProviders')) {
    /**
     * @param array<int,string> $providerCodes
     * @return array<string,mixed>
     */
    function cvCacheBumpProviders(mysqli $connection, array $providerCodes): array
    {
        $providerCodes = cvCacheNormalizeProviderCodes($providerCodes);
        if (count($providerCodes) === 0) {
            return [
                'updated' => 0,
                'providers' => [],
            ];
        }

        $placeholders = implode(',', array_fill(0, count($providerCodes), '?'));
        $sql = "UPDATE cv_providers
                SET last_sync_at = NOW()
                WHERE code IN ({$placeholders})";
        $statement = $connection->prepare($sql);
        if (!$statement instanceof mysqli_stmt) {
            throw new RuntimeException('Prepare failed for cache bump.');
        }

        $types = str_repeat('s', count($providerCodes));
        if (!cvCacheBindDynamicParams($statement, $types, $providerCodes) || !$statement->execute()) {
            $error = $statement->error;
            $statement->close();
            throw new RuntimeException('Provider cache bump failed: ' . $error);
        }

        $updated = $statement->affected_rows;
        $statement->close();

        return [
            'updated' => (int) $updated,
            'providers' => $providerCodes,
        ];
    }
}
