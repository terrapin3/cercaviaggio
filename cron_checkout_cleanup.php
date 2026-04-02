<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/conn.php';
require_once __DIR__ . '/includes/provider_quote.php';

header('Content-Type: application/json; charset=utf-8');

function cvCheckoutCleanupRespond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * @param array<mixed,mixed> $map
 * @return array<string,mixed>
 */
function cvCheckoutCleanupNormalizeStringKeyMap(array $map): array
{
    $normalized = [];
    foreach ($map as $key => $value) {
        $normalizedKey = strtolower(trim((string) $key));
        if ($normalizedKey === '') {
            continue;
        }
        $normalized[$normalizedKey] = $value;
    }

    return $normalized;
}

/**
 * @param array<string,mixed> $providerConfig
 * @return array<string,mixed>
 */
function cvCheckoutCleanupCancelReservation(array $providerConfig, string $shopId, string $idempotencyKey): array
{
    $baseUrl = trim((string) ($providerConfig['base_url'] ?? ''));
    if ($baseUrl === '') {
        return ['ok' => false, 'message' => 'Provider base_url missing'];
    }

    $url = cvProviderBuildEndpointUrl($baseUrl, 'cancel');
    if ($url === '') {
        return ['ok' => false, 'message' => 'Provider cancel endpoint invalid'];
    }

    $apiKey = trim((string) ($providerConfig['api_key'] ?? ''));
    $payload = ['shop_id' => $shopId];
    $response = cvProviderHttpPostJson($url, $payload, $apiKey, [
        'X-Idempotency-Key: ' . $idempotencyKey,
    ]);

    if (!(bool) ($response['ok'] ?? false) || !is_array($response['body'] ?? null)) {
        return [
            'ok' => false,
            'message' => 'Provider cancel request failed',
            'details' => [
                'status' => (int) ($response['status'] ?? 0),
                'error' => (string) ($response['error'] ?? ''),
                'body' => $response['body'] ?? null,
            ],
        ];
    }

    $body = $response['body'];
    if (!isset($body['success']) || !(bool) $body['success']) {
        return [
            'ok' => false,
            'message' => trim((string) ($body['message'] ?? 'Provider cancel failed')),
            'details' => $body,
        ];
    }

    return ['ok' => true, 'body' => $body];
}

$isCli = PHP_SAPI === 'cli';
$expectedToken = trim((string) getenv('CV_CHECKOUT_CRON_TOKEN'));
if (!$isCli) {
    if ($expectedToken === '') {
        cvCheckoutCleanupRespond(403, [
            'success' => false,
            'message' => 'Accesso negato. Imposta CV_CHECKOUT_CRON_TOKEN o esegui da CLI.',
        ]);
    }

    $providedToken = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
    if ($providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
        cvCheckoutCleanupRespond(403, [
            'success' => false,
            'message' => 'Token non valido.',
        ]);
    }
}

try {
    $connection = cvDbConnection();
} catch (Throwable $exception) {
    cvCheckoutCleanupRespond(500, [
        'success' => false,
        'message' => 'Errore connessione database.',
        'details' => ['error' => $exception->getMessage()],
    ]);
}

$providers = cvProviderConfigs($connection);
$providers = cvCheckoutCleanupNormalizeStringKeyMap($providers);

$expiredOrders = [];
$ordersResult = $connection->query("
    SELECT id, order_code
    FROM cv_orders
    WHERE status IN ('draft', 'reserved', 'payment_pending')
      AND expires_at IS NOT NULL
      AND expires_at <= NOW()
    ORDER BY expires_at ASC
    LIMIT 300
");
if ($ordersResult instanceof mysqli_result) {
    while ($row = $ordersResult->fetch_assoc()) {
        if (!is_array($row)) {
            continue;
        }
        $expiredOrders[] = [
            'id' => (int) ($row['id'] ?? 0),
            'order_code' => (string) ($row['order_code'] ?? ''),
        ];
    }
    $ordersResult->free();
}

if (count($expiredOrders) === 0) {
    cvCheckoutCleanupRespond(200, [
        'success' => true,
        'message' => 'Nessun ordine scaduto da pulire.',
        'data' => [
            'orders_checked' => 0,
            'orders_expired' => 0,
            'provider_cancel_attempts' => 0,
            'provider_cancel_success' => 0,
            'provider_cancel_failed' => 0,
        ],
    ]);
}

$legsStmt = $connection->prepare("
    SELECT
        l.id,
        l.provider_shop_id,
        l.status,
        COALESCE(a.code, '') AS provider_code
    FROM cv_order_legs AS l
    LEFT JOIN aziende AS a ON a.id_az = l.id_az
    WHERE l.order_id = ?
");
$setLegCancelledStmt = $connection->prepare("
    UPDATE cv_order_legs
    SET status = 'cancelled'
    WHERE id = ?
      AND status IN ('draft', 'reserved')
    LIMIT 1
");
$setOrderExpiredStmt = $connection->prepare("
    UPDATE cv_orders
    SET status = 'expired'
    WHERE id = ?
      AND status IN ('draft', 'reserved', 'payment_pending')
    LIMIT 1
");

if (
    !$legsStmt instanceof mysqli_stmt ||
    !$setLegCancelledStmt instanceof mysqli_stmt ||
    !$setOrderExpiredStmt instanceof mysqli_stmt
) {
    cvCheckoutCleanupRespond(500, [
        'success' => false,
        'message' => 'Errore preparazione statement cleanup checkout.',
    ]);
}

$providerCancelAttempts = 0;
$providerCancelSuccess = 0;
$providerCancelFailed = 0;
$orderExpiredCount = 0;
$providerErrors = [];

foreach ($expiredOrders as $order) {
    $orderId = (int) ($order['id'] ?? 0);
    $orderCode = trim((string) ($order['order_code'] ?? ''));
    if ($orderId <= 0 || $orderCode === '') {
        continue;
    }

    $legsStmt->bind_param('i', $orderId);
    if (!$legsStmt->execute()) {
        continue;
    }
    $legsResult = $legsStmt->get_result();
    $legs = [];
    if ($legsResult instanceof mysqli_result) {
        while ($leg = $legsResult->fetch_assoc()) {
            if (!is_array($leg)) {
                continue;
            }
            $legs[] = $leg;
        }
        $legsResult->free();
    }

    foreach ($legs as $leg) {
        $legId = isset($leg['id']) ? (int) $leg['id'] : 0;
        $legStatus = strtolower(trim((string) ($leg['status'] ?? '')));
        $providerCode = strtolower(trim((string) ($leg['provider_code'] ?? '')));
        $shopId = trim((string) ($leg['provider_shop_id'] ?? ''));

        if ($legId > 0 && in_array($legStatus, ['draft', 'reserved'], true)) {
            $setLegCancelledStmt->bind_param('i', $legId);
            $setLegCancelledStmt->execute();
        }

        if ($shopId === '' || $providerCode === '' || !isset($providers[$providerCode])) {
            continue;
        }

        if (!in_array($legStatus, ['draft', 'reserved'], true)) {
            continue;
        }

        $providerCancelAttempts++;
        $cancelResult = cvCheckoutCleanupCancelReservation(
            (array) $providers[$providerCode],
            $shopId,
            'cv-cleanup-' . $orderCode . '-' . $legId
        );
        if ((bool) ($cancelResult['ok'] ?? false)) {
            $providerCancelSuccess++;
            continue;
        }

        $providerCancelFailed++;
        $providerErrors[] = [
            'order_code' => $orderCode,
            'provider_code' => $providerCode,
            'shop_id' => $shopId,
            'message' => (string) ($cancelResult['message'] ?? 'Provider cancel failed'),
            'details' => $cancelResult['details'] ?? null,
        ];
    }

    $setOrderExpiredStmt->bind_param('i', $orderId);
    if ($setOrderExpiredStmt->execute() && $setOrderExpiredStmt->affected_rows > 0) {
        $orderExpiredCount++;
    }
}

$legsStmt->close();
$setLegCancelledStmt->close();
$setOrderExpiredStmt->close();

cvCheckoutCleanupRespond(200, [
    'success' => true,
    'message' => 'Cleanup checkout completato.',
    'data' => [
        'orders_checked' => count($expiredOrders),
        'orders_expired' => $orderExpiredCount,
        'provider_cancel_attempts' => $providerCancelAttempts,
        'provider_cancel_success' => $providerCancelSuccess,
        'provider_cancel_failed' => $providerCancelFailed,
        'provider_errors' => $providerErrors,
    ],
]);
