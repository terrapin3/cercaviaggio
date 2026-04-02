<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/conn.php';
require_once __DIR__ . '/includes/provider_quote.php';
require_once __DIR__ . '/includes/runtime_settings.php';
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

function cvSqRespond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function cvSqReadInput(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }

    return $decoded;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    cvSqRespond(405, [
        'success' => false,
        'message' => 'Method not allowed',
    ]);
}

$input = cvSqReadInput();
$travelDateIt = trim((string) ($input['travel_date_it'] ?? ''));
$ad = max(1, (int) ($input['ad'] ?? 1));
$bam = max(0, (int) ($input['bam'] ?? 0));
$codiceCamb = trim((string) ($input['camb'] ?? $input['codice_camb'] ?? ''));
$routeFromRef = trim((string) ($input['route_from_ref'] ?? ''));
$routeToRef = trim((string) ($input['route_to_ref'] ?? ''));
$routeMode = trim((string) ($input['route_mode'] ?? 'oneway'));
$legs = isset($input['legs']) && is_array($input['legs']) ? $input['legs'] : [];

if ($travelDateIt === '' || count($legs) === 0) {
    cvSqRespond(400, [
        'success' => false,
        'message' => 'Invalid payload for quote validation',
    ]);
}

try {
    $connection = cvDbConnection();
    $providers = cvProviderConfigs($connection);
    $providerPriceModes = cvRuntimeProviderPriceModeMap($connection);
    $providerCommissionMap = cvRuntimeProviderCommissionMap($connection);
} catch (Throwable $exception) {
    cvSqRespond(500, [
        'success' => false,
        'message' => 'Database error during provider lookup',
    ]);
}

if (count($providers) === 0) {
    cvSqRespond(503, [
        'success' => false,
        'message' => 'No active providers configured',
    ]);
}

$totalAmount = 0.0;
$currency = 'EUR';
$legResults = [];

foreach ($legs as $index => $leg) {
    if (!is_array($leg)) {
        cvSqRespond(400, [
            'success' => false,
            'message' => 'Invalid leg format',
            'details' => ['index' => $index],
        ]);
    }

    $providerCode = trim((string) ($leg['provider_code'] ?? ''));
    $idCorsa = trim((string) ($leg['id_corsa'] ?? ''));
    $part = trim((string) ($leg['part'] ?? ''));
    $arr = trim((string) ($leg['arr'] ?? ''));
    $fareId = trim((string) ($leg['fare_id'] ?? ''));
    $legCodiceCamb = trim((string) ($leg['camb'] ?? $leg['codice_camb'] ?? $codiceCamb));

    if ($providerCode === '' || $idCorsa === '' || $part === '' || $arr === '') {
        cvSqRespond(400, [
            'success' => false,
            'message' => 'Leg missing required fields',
            'details' => ['index' => $index],
        ]);
    }

    if (!isset($providers[$providerCode])) {
        cvSqRespond(404, [
            'success' => false,
            'message' => 'Provider not configured',
            'details' => ['provider' => $providerCode],
        ]);
    }

    $legTravelDateIt = $travelDateIt;
    $departureIso = trim((string) ($leg['departure_iso'] ?? ''));
    if ($departureIso !== '') {
        try {
            $departureDate = new DateTimeImmutable($departureIso);
            $legTravelDateIt = $departureDate->format('d/m/Y');
        } catch (Throwable $exception) {
            // Fallback: keep default travel_date_it from payload.
        }
    }

    $quoteResponse = cvProviderQuoteLeg(
        $providers,
        [
            'provider_code' => $providerCode,
            'id_corsa' => $idCorsa,
            'part' => $part,
            'arr' => $arr,
            'fare_id' => $fareId,
            'codice_camb' => $legCodiceCamb,
        ],
        $legTravelDateIt,
        $ad,
        $bam,
        15
    );

    if (!(bool) ($quoteResponse['ok'] ?? false)) {
        $httpStatus = (int) ($quoteResponse['http_status'] ?? 502);
        if ($httpStatus < 400) {
            $httpStatus = 409;
        }
        cvSqRespond($httpStatus, [
            'success' => false,
            'message' => (string) ($quoteResponse['message'] ?? 'Live quote request failed'),
            'details' => [
                'provider' => $providerCode,
                'request_meta' => $quoteResponse['request_meta'] ?? null,
                'provider_error' => $quoteResponse['provider_error'] ?? null,
                'http_status' => $httpStatus,
            ],
        ]);
    }

    $providerAmount = isset($quoteResponse['amount']) ? (float) $quoteResponse['amount'] : 0.0;
    $originalAmount = isset($quoteResponse['original_amount']) ? (float) $quoteResponse['original_amount'] : 0.0;
    $priceMode = cvRuntimeNormalizeProviderPriceMode($providerPriceModes[$providerCode] ?? 'discounted');
    $baseDisplayAmount = cvRuntimeResolveDisplayedAmount($priceMode, $providerAmount, $originalAmount);
    $commissionPercent = isset($providerCommissionMap[$providerCode]) ? (float) $providerCommissionMap[$providerCode] : 0.0;
    $commissionCalc = cvRuntimeApplyProviderCommission($baseDisplayAmount, $commissionPercent, 1);
    // In quote validation manteniamo l'importo listino; il netto commissione viene applicato lato checkout.
    $legAmount = $baseDisplayAmount;
    $legCurrency = trim((string) ($quoteResponse['currency'] ?? 'EUR'));
    if ($legCurrency === '') {
        $legCurrency = 'EUR';
    }

    if ($index === 0) {
        $currency = $legCurrency;
    } elseif ($currency !== $legCurrency) {
        cvSqRespond(409, [
            'success' => false,
            'message' => 'Mixed currencies are not supported in one solution',
            'details' => [
                'expected' => $currency,
                'current' => $legCurrency,
                'provider' => $providerCode,
            ],
        ]);
    }

    $totalAmount += $legAmount;

    $legResults[] = [
        'provider_code' => $providerCode,
        'id_corsa' => $idCorsa,
        'part' => $part,
        'arr' => $arr,
        'amount' => $legAmount,
        'base_amount' => $baseDisplayAmount,
        'client_amount' => (float) ($commissionCalc['client_amount'] ?? 0.0),
        'commission_percent' => round($commissionPercent, 4),
        'commission_amount' => (float) ($commissionCalc['commission_amount'] ?? 0.0),
        'provider_amount' => $providerAmount,
        'original_amount' => $originalAmount > 0.0 ? $originalAmount : $providerAmount,
        'discount_percent' => isset($quoteResponse['discount_percent']) ? (float) $quoteResponse['discount_percent'] : 0.0,
        'checked_bag_unit_price' => isset($quoteResponse['checked_bag_unit_price']) ? (float) $quoteResponse['checked_bag_unit_price'] : 0.0,
        'checked_bag_base_price' => isset($quoteResponse['checked_bag_base_price']) ? (float) $quoteResponse['checked_bag_base_price'] : 0.0,
        'checked_bag_increment' => isset($quoteResponse['checked_bag_increment']) ? (float) $quoteResponse['checked_bag_increment'] : 0.0,
        'checked_bag_max_qty' => isset($quoteResponse['checked_bag_max_qty']) ? (int) $quoteResponse['checked_bag_max_qty'] : 5,
        'hand_bag_unit_price' => isset($quoteResponse['hand_bag_unit_price']) ? (float) $quoteResponse['hand_bag_unit_price'] : 0.0,
        'hand_bag_max_qty' => isset($quoteResponse['hand_bag_max_qty']) ? (int) $quoteResponse['hand_bag_max_qty'] : 5,
        'checked_bag_conditions' => isset($quoteResponse['checked_bag_conditions']) && is_array($quoteResponse['checked_bag_conditions']) ? $quoteResponse['checked_bag_conditions'] : [],
        'hand_bag_conditions' => isset($quoteResponse['hand_bag_conditions']) && is_array($quoteResponse['hand_bag_conditions']) ? $quoteResponse['hand_bag_conditions'] : [],
        'price_mode' => $priceMode,
        'currency' => $legCurrency,
        'fare_id' => (string) ($quoteResponse['fare_id'] ?? ''),
        'fare_label' => (string) ($quoteResponse['fare_label'] ?? ''),
        'quote_id' => (string) ($quoteResponse['quote_id'] ?? ''),
        'quote_token' => (string) ($quoteResponse['quote_token'] ?? ''),
        'expires_at' => (string) ($quoteResponse['expires_at'] ?? ''),
    ];
}

if ($routeFromRef !== '' && $routeToRef !== '') {
    try {
        cvTrackRouteSearchRequest(
            $connection,
            $routeFromRef,
            $routeToRef,
            $travelDateIt,
            $ad,
            $bam,
            $routeMode
        );
    } catch (Throwable $trackingException) {
        error_log('soluzioni_quote tracking warning: ' . $trackingException->getMessage());
    }
}

cvSqRespond(200, [
    'success' => true,
    'total_amount' => $totalAmount,
    'currency' => $currency,
    'legs' => $legResults,
]);
