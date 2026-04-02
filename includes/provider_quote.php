<?php
declare(strict_types=1);

require_once __DIR__ . '/runtime_settings.php';

if (!function_exists('cvProviderQuoteCacheDir')) {
    function cvProviderQuoteCacheDir(): string
    {
        return dirname(__DIR__) . '/files/cache/provider_quote';
    }
}

if (!function_exists('cvProviderQuoteCacheRead')) {
    /**
     * @return array<string,mixed>|null
     */
    function cvProviderQuoteCacheRead(string $key, int $ttlSeconds): ?array
    {
        $path = cvProviderQuoteCacheDir() . '/' . $key . '.json';
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

if (!function_exists('cvProviderQuoteCacheWrite')) {
    /**
     * @param array<string,mixed> $payload
     */
    function cvProviderQuoteCacheWrite(string $key, array $payload): void
    {
        $dir = cvProviderQuoteCacheDir();
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

if (!function_exists('cvProviderConfigs')) {
    /**
     * @return array<string,array<string,string>>
     */
    function cvProviderConfigs(mysqli $connection): array
    {
        $sql = "SELECT code, base_url, api_key
                FROM cv_providers
                WHERE is_active = 1";
        $result = $connection->query($sql);
        if (!$result instanceof mysqli_result) {
            return [];
        }

        $map = [];
        while ($row = $result->fetch_assoc()) {
            if (!is_array($row)) {
                continue;
            }

            $code = strtolower(trim((string) ($row['code'] ?? '')));
            $baseUrl = trim((string) ($row['base_url'] ?? ''));
            if ($code === '' || $baseUrl === '') {
                continue;
            }

            $map[$code] = [
                'base_url' => $baseUrl,
                'api_key' => trim((string) ($row['api_key'] ?? '')),
            ];
        }

        $result->free();
        return $map;
    }
}

if (!function_exists('cvProviderNormalizeFareId')) {
    function cvProviderNormalizeFareId(string $fareId): string
    {
        $normalized = strtoupper(trim($fareId));
        if ($normalized === '') {
            return '';
        }

        return preg_match('/^(PROMO-[0-9]+|STD)$/i', $normalized) === 1 ? $normalized : '';
    }
}

if (!function_exists('cvProviderBuildQuoteUrl')) {
    function cvProviderBuildQuoteUrl(string $baseUrl, array $params): string
    {
        $parts = parse_url($baseUrl);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return '';
        }

        $query = [];
        if (isset($parts['query']) && is_string($parts['query'])) {
            parse_str($parts['query'], $query);
        }

        $query['rquest'] = 'quote';
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

if (!function_exists('cvProviderBuildEndpointUrl')) {
    /**
     * @param array<string,string|int|float> $queryParams
     */
    function cvProviderBuildEndpointUrl(string $baseUrl, string $endpoint, array $queryParams = []): string
    {
        $parts = parse_url($baseUrl);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return '';
        }

        $query = [];
        if (isset($parts['query']) && is_string($parts['query'])) {
            parse_str($parts['query'], $query);
        }

        $endpoint = trim($endpoint);
        if ($endpoint === '') {
            return '';
        }

        $query['rquest'] = $endpoint;
        foreach ($queryParams as $key => $value) {
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

if (!function_exists('cvProviderHttpGetJson')) {
    /**
     * @return array{ok:bool,status:int,body:?array,error:?string}
     */
    function cvProviderHttpGetJson(string $url, ?string $apiKey = null): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'curl extension not available'];
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'curl init failed'];
        }

        $headers = [
            'Accept: application/json',
        ];

        if (is_string($apiKey) && trim($apiKey) !== '') {
            $headers[] = 'X-Api-Key: ' . trim($apiKey);
        }

        curl_setopt($ch, CURLOPT_HTTPGET, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 7);
        curl_setopt($ch, CURLOPT_TIMEOUT, 18);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if (!is_string($raw)) {
            return [
                'ok' => false,
                'status' => $status,
                'body' => null,
                'error' => $error !== '' ? $error : 'empty response',
            ];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'status' => $status, 'body' => null, 'error' => 'invalid json'];
        }

        return ['ok' => true, 'status' => $status, 'body' => $decoded, 'error' => null];
    }
}

if (!function_exists('cvProviderHttpPostJson')) {
    /**
     * @param array<string,mixed> $payload
     * @param array<int,string> $extraHeaders
     * @return array{ok:bool,status:int,body:?array,error:?string}
     */
    function cvProviderHttpPostJson(string $url, array $payload, ?string $apiKey = null, array $extraHeaders = []): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'curl extension not available'];
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'curl init failed'];
        }

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($body)) {
            curl_close($ch);
            return ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'json encode failed'];
        }

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
        ];

        if (is_string($apiKey) && trim($apiKey) !== '') {
            $headers[] = 'X-Api-Key: ' . trim($apiKey);
        }

        foreach ($extraHeaders as $header) {
            $header = trim((string) $header);
            if ($header === '') {
                continue;
            }
            $headers[] = $header;
        }

        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 7);
        curl_setopt($ch, CURLOPT_TIMEOUT, 18);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if (!is_string($raw)) {
            return [
                'ok' => false,
                'status' => $status,
                'body' => null,
                'error' => $error !== '' ? $error : 'empty response',
            ];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'status' => $status, 'body' => null, 'error' => 'invalid json'];
        }

        return ['ok' => true, 'status' => $status, 'body' => $decoded, 'error' => null];
    }
}

if (!function_exists('cvProviderQuoteRequestKey')) {
    function cvProviderQuoteRequestKey(
        string $providerCode,
        string $travelDateIt,
        int $ad,
        int $bam,
        string $idCorsa,
        string $part,
        string $arr,
        string $fareId = '',
        string $codiceCamb = ''
    ): string {
        return hash(
            'sha256',
            implode('|', [
                'bag-v1',
                $providerCode,
                $travelDateIt,
                (string) $ad,
                (string) $bam,
                $idCorsa,
                $part,
                $arr,
                cvProviderNormalizeFareId($fareId),
                trim($codiceCamb),
            ])
        );
    }
}

if (!function_exists('cvProviderQuoteLeg')) {
    /**
     * @param array<string,array<string,string>> $providers
     * @param array<string,mixed> $leg
     * @return array<string,mixed>
     */
    function cvProviderQuoteLeg(
        array $providers,
        array $leg,
        string $travelDateIt,
        int $ad,
        int $bam,
        int $ttlSeconds = 45
    ): array {
        $providerCode = trim((string) ($leg['provider_code'] ?? ''));
        $idCorsa = trim((string) ($leg['id_corsa'] ?? ''));
        $part = trim((string) ($leg['part'] ?? ''));
        $arr = trim((string) ($leg['arr'] ?? ''));
        $fareId = cvProviderNormalizeFareId((string) ($leg['fare_id'] ?? ''));
        $codiceCamb = trim((string) ($leg['codice_camb'] ?? $leg['camb'] ?? ''));

        if ($providerCode === '' || $idCorsa === '' || $part === '' || $arr === '' || $travelDateIt === '') {
            return [
                'ok' => false,
                'message' => 'Leg missing required fields',
                'http_status' => 400,
                'provider_code' => $providerCode,
                'provider_error' => null,
            ];
        }

        if (!isset($providers[$providerCode])) {
            return [
                'ok' => false,
                'message' => 'Provider not configured',
                'http_status' => 404,
                'provider_code' => $providerCode,
                'provider_error' => null,
            ];
        }

        $cacheKey = cvProviderQuoteRequestKey($providerCode, $travelDateIt, $ad, $bam, $idCorsa, $part, $arr, $fareId, $codiceCamb);
        $requestMeta = [
            'provider_code' => $providerCode,
            'travel_date_it' => $travelDateIt,
            'adults' => max(0, $ad),
            'children' => max(0, $bam),
            'id_corsa' => $idCorsa,
            'from_stop_id' => $part,
            'to_stop_id' => $arr,
            'fare_id' => $fareId,
            'codice_camb' => $codiceCamb,
        ];
        $cached = cvProviderQuoteCacheRead($cacheKey, $ttlSeconds);
        if (is_array($cached)) {
            $cached['cache'] = 'hit';
            return $cached;
        }

        $provider = $providers[$providerCode];
        $queryParams = [
            'part' => $part,
            'arr' => $arr,
            'id_corsa' => $idCorsa,
            'ad' => (string) max(0, $ad),
            'bam' => (string) max(0, $bam),
            'dt1' => $travelDateIt,
        ];

        if ($fareId !== '') {
            $queryParams['fare_id'] = $fareId;
        }
        if ($codiceCamb !== '') {
            $queryParams['codice_camb'] = $codiceCamb;
            $queryParams['cmb'] = $codiceCamb;
        }

        $quoteUrl = cvProviderBuildQuoteUrl((string) $provider['base_url'], $queryParams);
        if ($quoteUrl === '') {
            return [
                'ok' => false,
                'message' => 'Invalid provider base URL',
                'http_status' => 500,
                'provider_code' => $providerCode,
                'provider_error' => null,
            ];
        }

        $quoteResponse = cvProviderHttpGetJson($quoteUrl, (string) ($provider['api_key'] ?? ''));
        if (!$quoteResponse['ok'] || !is_array($quoteResponse['body'])) {
            return [
                'ok' => false,
                'message' => 'Live quote request failed',
                'http_status' => 502,
                'provider_code' => $providerCode,
                'provider_error' => [
                    'transport_error' => $quoteResponse['error'],
                    'http_status' => $quoteResponse['status'],
                ],
            ];
        }

        $body = $quoteResponse['body'];
        $isSuccess = isset($body['success']) && (bool) $body['success'] === true;
        $data = isset($body['data']) && is_array($body['data']) ? $body['data'] : null;
        $error = isset($body['error']) && is_array($body['error']) ? $body['error'] : null;

        if (!$isSuccess || !is_array($data) || !isset($data['pricing']) || !is_array($data['pricing'])) {
            $providerMessage = is_array($error) && isset($error['message']) ? (string) $error['message'] : '';
            $payload = [
                'ok' => false,
                'message' => $providerMessage !== '' ? $providerMessage : 'Solution is no longer valid according to provider rules',
                'http_status' => (int) ($quoteResponse['status'] ?: 409),
                'provider_code' => $providerCode,
                'request_meta' => $requestMeta,
                'provider_error' => $error,
                'cache' => 'miss',
            ];
            cvProviderQuoteCacheWrite($cacheKey, $payload);
            return $payload;
        }

        $pricing = $data['pricing'];
        $payload = [
            'ok' => true,
            'message' => '',
            'http_status' => (int) ($quoteResponse['status'] ?: 200),
            'provider_code' => $providerCode,
            'request_meta' => $requestMeta,
            'provider_error' => null,
            'amount' => isset($pricing['amount']) ? (float) $pricing['amount'] : 0.0,
            'original_amount' => isset($pricing['original_amount']) ? (float) $pricing['original_amount'] : 0.0,
            'discount_percent' => isset($pricing['discount_percent']) ? (float) $pricing['discount_percent'] : 0.0,
            'checked_bag_unit_price' => isset($pricing['checked_bag_unit_price']) ? (float) $pricing['checked_bag_unit_price'] : 0.0,
            'checked_bag_base_price' => isset($pricing['checked_bag_base_price']) ? (float) $pricing['checked_bag_base_price'] : 0.0,
            'checked_bag_increment' => isset($pricing['checked_bag_increment']) ? (float) $pricing['checked_bag_increment'] : 0.0,
            'checked_bag_max_qty' => isset($pricing['checked_bag_max_qty']) && is_numeric($pricing['checked_bag_max_qty']) ? (int) $pricing['checked_bag_max_qty'] : 5,
            'hand_bag_unit_price' => isset($pricing['hand_bag_unit_price']) ? (float) $pricing['hand_bag_unit_price'] : 0.0,
            'hand_bag_max_qty' => isset($pricing['hand_bag_max_qty']) && is_numeric($pricing['hand_bag_max_qty']) ? (int) $pricing['hand_bag_max_qty'] : 5,
            'checked_bag_conditions' => isset($pricing['checked_bag_conditions']) && is_array($pricing['checked_bag_conditions']) ? $pricing['checked_bag_conditions'] : [],
            'hand_bag_conditions' => isset($pricing['hand_bag_conditions']) && is_array($pricing['hand_bag_conditions']) ? $pricing['hand_bag_conditions'] : [],
            'currency' => trim((string) ($pricing['currency'] ?? 'EUR')) ?: 'EUR',
            'fare_id' => trim((string) ($pricing['fare_id'] ?? '')),
            'fare_label' => trim((string) ($pricing['label'] ?? '')),
            'quote_id' => trim((string) ($data['quote_id'] ?? '')),
            'quote_token' => trim((string) ($data['quote_token'] ?? '')),
            'expires_at' => trim((string) ($data['expires_at'] ?? '')),
            'cache' => 'miss',
        ];

        cvProviderQuoteCacheWrite($cacheKey, $payload);
        return $payload;
    }
}
