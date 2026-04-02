<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/conn.php';
require_once __DIR__ . '/includes/provider_quote.php';
require_once __DIR__ . '/includes/runtime_settings.php';
require_once __DIR__ . '/includes/error_log_tools.php';
require_once __DIR__ . '/includes/promotions_tools.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function cvCheckoutApiRespond(int $status, array $payload): void
{
    if (
        ($status >= 400 || (array_key_exists('success', $payload) && $payload['success'] === false))
        && isset($GLOBALS['connection'])
        && $GLOBALS['connection'] instanceof mysqli
        && function_exists('cvErrorLogWrite')
    ) {
        $actionName = strtolower(trim((string) ($_GET['action'] ?? '')));
        $eventCode = trim((string) ($payload['code'] ?? 'CHECKOUT_ERROR'));
        if ($eventCode === '') {
            $eventCode = 'CHECKOUT_ERROR';
        }
        $details = isset($payload['details']) && is_array($payload['details']) ? $payload['details'] : [];
        $message = trim((string) ($payload['message'] ?? 'Errore checkout.'));
        if ($message === '') {
            $message = 'Errore checkout.';
        }

        $providerCode = trim((string) ($details['provider_code'] ?? ($details['provider'] ?? '')));
        $requestId = trim((string) ($details['request_id'] ?? ''));
        $orderCode = trim((string) ($details['order_code'] ?? ($_POST['order_code'] ?? '')));
        $shopId = trim((string) ($details['shop_id'] ?? ($details['provider_shop_id'] ?? '')));

        cvErrorLogWrite($GLOBALS['connection'], [
            'source' => 'checkout_api',
            'event_code' => strtoupper($eventCode),
            'severity' => 'error',
            'message' => $message,
            'provider_code' => strtolower($providerCode),
            'request_id' => $requestId,
            'action_name' => $actionName,
            'order_code' => $orderCode,
            'shop_id' => $shopId,
            'context' => [
                'status' => $status,
                'payload' => $payload,
            ],
        ]);
    }

    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * @return array<string,mixed>
 */
function cvCheckoutApiReadJsonPayload(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function cvCheckoutApiOrderCode(): string
{
    try {
        $suffix = strtoupper(bin2hex(random_bytes(4)));
    } catch (Throwable $exception) {
        $suffix = strtoupper(substr(hash('sha256', uniqid('', true)), 0, 8));
    }

    return 'CV-' . date('Ymd-His') . '-' . $suffix;
}

function cvCheckoutApiJsonEncode($value): ?string
{
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return is_string($json) ? $json : null;
}

function cvCheckoutApiLog(string $event, array $context = []): void
{
    $payload = '';
    try {
        $encoded = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $payload = is_string($encoded) ? $encoded : '';
    } catch (Throwable $exception) {
        $payload = '';
    }

    error_log('cv_checkout|' . $event . ($payload !== '' ? '|' . $payload : ''));

    $logDir = __DIR__ . '/files/logs';
    if (!is_dir($logDir) && !@mkdir($logDir, 0775, true) && !is_dir($logDir)) {
        return;
    }

    $line = '[' . date('Y-m-d H:i:s') . '] cv_checkout|' . $event . ($payload !== '' ? '|' . $payload : '') . PHP_EOL;
    @file_put_contents($logDir . '/checkout_api.log', $line, FILE_APPEND | LOCK_EX);

    $eventLower = strtolower($event);
    $isNegativeEvent = (
        strpos($eventLower, 'fail') !== false
        || strpos($eventLower, 'error') !== false
        || strpos($eventLower, 'missing') !== false
        || strpos($eventLower, 'fallback') !== false
    );
    if (
        $isNegativeEvent
        && isset($GLOBALS['connection'])
        && $GLOBALS['connection'] instanceof mysqli
        && function_exists('cvErrorLogWrite')
    ) {
        $providerCode = '';
        if (isset($context['provider_code'])) {
            $providerCode = (string) $context['provider_code'];
        } elseif (isset($context['provider']) && is_string($context['provider'])) {
            $providerCode = (string) $context['provider'];
        }

        $message = '';
        if (isset($context['message']) && is_string($context['message']) && trim($context['message']) !== '') {
            $message = trim((string) $context['message']);
        } elseif (isset($context['error']) && is_string($context['error']) && trim($context['error']) !== '') {
            $message = trim((string) $context['error']);
        } else {
            $message = 'Evento checkout: ' . $event;
        }

        cvErrorLogWrite($GLOBALS['connection'], [
            'source' => 'checkout_api',
            'event_code' => strtoupper($event),
            'severity' => strpos($eventLower, 'fallback') !== false ? 'warning' : 'error',
            'message' => $message,
            'provider_code' => strtolower(trim($providerCode)),
            'request_id' => trim((string) ($context['request_id'] ?? '')),
            'action_name' => strtolower(trim((string) ($_GET['action'] ?? ''))),
            'order_code' => trim((string) ($context['order_code'] ?? '')),
            'shop_id' => trim((string) ($context['shop_id'] ?? '')),
            'context' => $context,
        ]);
    }
}

/**
 * @return array<string,mixed>
 */
function cvCheckoutApiJsonDecodeArray($value): array
{
    if (!is_string($value) || trim($value) === '') {
        return [];
    }

    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * @return array{nome:string,cognome:string}
 */
function cvCheckoutApiSplitFullName(string $fullName): array
{
    $fullName = preg_replace('/\s+/', ' ', trim($fullName));
    if (!is_string($fullName) || $fullName === '') {
        return ['nome' => 'Passeggero', 'cognome' => '-'];
    }

    $parts = explode(' ', $fullName);
    if (count($parts) === 1) {
        return ['nome' => $parts[0], 'cognome' => '-'];
    }

    $nome = (string) array_shift($parts);
    $cognome = trim(implode(' ', $parts));
    if ($cognome === '') {
        $cognome = '-';
    }

    return ['nome' => $nome, 'cognome' => $cognome];
}

function cvCheckoutApiBaseUrl(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '/checkout_api.php');
    $basePath = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
    if ($basePath === '' || $basePath === '.') {
        $basePath = '';
    }

    return $scheme . '://' . $host . $basePath;
}

function cvCheckoutApiDefaultFromEmail(): string
{
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? 'cercaviaggio.local'));
    $host = preg_replace('/:\d+$/', '', $host);
    if (!is_string($host) || $host === '') {
        $host = 'cercaviaggio.local';
    }

    $email = 'noreply@' . $host;
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : 'noreply@cercaviaggio.local';
}

/**
 * @return array<string,mixed>
 */
function cvCheckoutApiMailSettings(mysqli $connection): array
{
    $settings = [
        'from_email' => cvCheckoutApiDefaultFromEmail(),
        'from_name' => 'cercaviaggio',
        'smtp_host' => '',
        'smtp_port' => 0,
        'smtp_security' => '',
        'smtp_user' => '',
        'smtp_pass' => '',
        'subject_prefix' => 'cercaviaggio',
    ];

    $query = $connection->query('SELECT * FROM mail_sett ORDER BY id_sett ASC LIMIT 1');
    if (!$query instanceof mysqli_result) {
        return $settings;
    }

    $row = $query->fetch_assoc();
    $query->free();
    if (!is_array($row)) {
        return $settings;
    }

    $slot = 3;
    $emailField = 'email' . $slot;
    $userField = 'user' . $slot;
    $passField = 'pass' . $slot;
    $subjectField = 'oggetto' . $slot;

    $smtpSecurity = '';
    $securityCode = (int) ($row['smtpsecurity'] ?? 0);
    if ($securityCode === 1) {
        $smtpSecurity = 'ssl';
    } elseif ($securityCode === 2) {
        $smtpSecurity = 'tls';
    }

    $fromEmail = trim((string) ($row[$emailField] ?? ''));
    if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        $fromEmail = $settings['from_email'];
    }

    $subjectPrefix = trim((string) ($row[$subjectField] ?? ''));
    if ($subjectPrefix === '') {
        $subjectPrefix = 'cercaviaggio';
    }

    $settings['from_email'] = $fromEmail;
    $settings['from_name'] = $subjectPrefix;
    $settings['smtp_host'] = trim((string) ($row['smtp'] ?? ''));
    $settings['smtp_port'] = (int) ($row['smtpport'] ?? 0);
    $settings['smtp_security'] = $smtpSecurity;
    $settings['smtp_user'] = trim((string) ($row[$userField] ?? ''));
    $settings['smtp_pass'] = trim((string) ($row[$passField] ?? ''));
    $settings['subject_prefix'] = $subjectPrefix;

    return $settings;
}

function cvCheckoutApiLoadPhpMailer(): bool
{
    static $loaded = null;
    if ($loaded !== null) {
        return $loaded;
    }

    $base = __DIR__ . '/functions/PHPMailer/src';
    $required = [
        $base . '/Exception.php',
        $base . '/PHPMailer.php',
        $base . '/SMTP.php',
    ];

    foreach ($required as $file) {
        if (!is_file($file)) {
            $loaded = false;
            return false;
        }
    }

    require_once $base . '/Exception.php';
    require_once $base . '/PHPMailer.php';
    require_once $base . '/SMTP.php';

    $loaded = class_exists('\\PHPMailer\\PHPMailer\\PHPMailer');
    return $loaded;
}

/**
 * @param array<int,array{path:string,name:string}> $attachments
 */
function cvCheckoutApiSendMail(
    mysqli $connection,
    string $toEmail,
    string $toName,
    string $subject,
    string $htmlBody,
    string $plainBody = '',
    array $attachments = []
): bool {
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $settings = cvCheckoutApiMailSettings($connection);
    $fromEmail = trim((string) ($settings['from_email'] ?? cvCheckoutApiDefaultFromEmail()));
    $fromName = trim((string) ($settings['from_name'] ?? 'cercaviaggio'));
    $smtpHost = trim((string) ($settings['smtp_host'] ?? ''));
    $smtpPort = (int) ($settings['smtp_port'] ?? 0);
    $smtpSecurity = trim((string) ($settings['smtp_security'] ?? ''));
    $smtpUser = trim((string) ($settings['smtp_user'] ?? ''));
    $smtpPass = (string) ($settings['smtp_pass'] ?? '');

    if (cvCheckoutApiLoadPhpMailer()) {
        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->CharSet = 'UTF-8';
            $mail->Encoding = 'base64';
            $mail->setLanguage('it', __DIR__ . '/functions/PHPMailer/language/');
            if ($smtpHost !== '') {
                $mail->isSMTP();
                $mail->Host = $smtpHost;
                $mail->SMTPAuth = true;
                if ($smtpSecurity !== '') {
                    $mail->SMTPSecure = $smtpSecurity;
                }
                if ($smtpPort > 0) {
                    $mail->Port = $smtpPort;
                }
                $mail->Username = $smtpUser;
                $mail->Password = $smtpPass;
            } else {
                $mail->isMail();
            }
            $mail->setFrom($fromEmail, $fromName);
            $mail->addAddress($toEmail, $toName);
            $mail->addReplyTo($fromEmail, $fromName);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $htmlBody;
            $mail->AltBody = $plainBody !== '' ? $plainBody : strip_tags($htmlBody);

            foreach ($attachments as $attachment) {
                $path = isset($attachment['path']) ? (string) $attachment['path'] : '';
                $name = isset($attachment['name']) ? (string) $attachment['name'] : basename($path);
                if ($path !== '' && is_file($path)) {
                    $mail->addAttachment($path, $name);
                }
            }

            return $mail->send();
        } catch (Throwable $exception) {
            error_log('cv checkout smtp mail error: ' . $exception->getMessage());
        }
    }

    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $headers = [];
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/html; charset=UTF-8';
    $headers[] = 'From: ' . $fromName . ' <' . $fromEmail . '>';
    $headers[] = 'Reply-To: ' . $fromEmail;
    $headers[] = 'X-Mailer: PHP/' . PHP_VERSION;

    return @mail($toEmail, $encodedSubject, $htmlBody, implode("\r\n", $headers));
}

function cvCheckoutApiIsoToMysql(?string $value): ?string
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return null;
    }

    $timestamp = strtotime($raw);
    if (!is_int($timestamp) || $timestamp <= 0) {
        return null;
    }

    return date('Y-m-d H:i:s', $timestamp);
}

/**
 * @param array<mixed,mixed> $map
 * @return array<string,mixed>
 */
function cvCheckoutApiNormalizeStringKeyMap(array $map): array
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
 * @param array<string,mixed> $leg
 */
function cvCheckoutApiNormalizeLeg(array $leg, string $direction, int $legIndex): ?array
{
    $providerCode = strtolower(trim((string) ($leg['provider_code'] ?? '')));
    $tripExternalId = trim((string) ($leg['trip_external_id'] ?? ''));
    $fromStopId = trim((string) ($leg['from_stop_id'] ?? ''));
    $toStopId = trim((string) ($leg['to_stop_id'] ?? ''));
    $departureIso = trim((string) ($leg['departure_iso'] ?? ''));
    $arrivalIso = trim((string) ($leg['arrival_iso'] ?? ''));
    $fareId = trim((string) ($leg['fare_id'] ?? ''));

    if (
        $providerCode === '' ||
        $tripExternalId === '' ||
        $fromStopId === '' ||
        $toStopId === '' ||
        $departureIso === '' ||
        $arrivalIso === ''
    ) {
        return null;
    }

    if (!ctype_digit($fromStopId) || !ctype_digit($toStopId)) {
        return null;
    }

    $amount = isset($leg['amount']) && is_numeric($leg['amount']) ? (float) $leg['amount'] : 0.0;
    $baseAmount = isset($leg['base_amount']) && is_numeric($leg['base_amount'])
        ? (float) $leg['base_amount']
        : $amount;
    $quoteToken = trim((string) ($leg['quote_token'] ?? ''));
    $quoteId = trim((string) ($leg['quote_id'] ?? ''));
    $quoteExpiresAt = trim((string) ($leg['quote_expires_at'] ?? ''));
    $fareLabel = trim((string) ($leg['fare_label'] ?? ''));

    return [
        'direction' => $direction,
        'leg_index' => max(1, $legIndex),
        'provider_code' => $providerCode,
        'trip_external_id' => $tripExternalId,
        'from_stop_id' => $fromStopId,
        'to_stop_id' => $toStopId,
        'departure_iso' => $departureIso,
        'arrival_iso' => $arrivalIso,
        'fare_id' => $fareId,
        'fare_label' => $fareLabel,
        'amount' => max(0.0, $amount),
        'base_amount' => max(0.0, $baseAmount),
        'quote_token' => $quoteToken,
        'quote_id' => $quoteId,
        'quote_expires_at' => $quoteExpiresAt,
    ];
}

function cvCheckoutApiLegMapKey(string $direction, int $legIndex, string $providerCode = ''): string
{
    $direction = strtolower(trim($direction));
    if ($direction !== 'inbound' && $direction !== 'outbound') {
        $direction = 'outbound';
    }
    $legIndex = max(1, $legIndex);
    $providerCode = strtolower(trim($providerCode));
    return $direction . '|' . $legIndex . '|' . $providerCode;
}

/**
 * @param array<string,mixed> $item
 * @return array<string,mixed>
 */
function cvCheckoutApiNormalizeBaggageItem(array $item): array
{
    $checked = isset($item['checked_bags']) && is_numeric($item['checked_bags']) ? (int) $item['checked_bags'] : 0;
    $hand = isset($item['hand_bags']) && is_numeric($item['hand_bags']) ? (int) $item['hand_bags'] : 0;

    return [
        'checked_bags' => max(0, min(8, $checked)),
        'hand_bags' => max(0, min(8, $hand)),
    ];
}

/**
 * @param array<int,mixed> $rows
 * @param array<int,array<string,mixed>> $normalizedLegs
 * @return array<string,array<string,mixed>>
 */
function cvCheckoutApiNormalizeBaggageByLeg(array $rows, array $normalizedLegs): array
{
    $allowed = [];
    foreach ($normalizedLegs as $leg) {
        if (!is_array($leg)) {
            continue;
        }
        $key = cvCheckoutApiLegMapKey(
            (string) ($leg['direction'] ?? 'outbound'),
            (int) ($leg['leg_index'] ?? 1),
            (string) ($leg['provider_code'] ?? '')
        );
        if ($key === '') {
            continue;
        }
        $allowed[$key] = [
            'checked_bags' => 0,
            'hand_bags' => 0,
        ];
    }

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $key = cvCheckoutApiLegMapKey(
            (string) ($row['direction'] ?? 'outbound'),
            isset($row['leg_index']) && is_numeric($row['leg_index']) ? (int) $row['leg_index'] : 1,
            (string) ($row['provider_code'] ?? '')
        );
        if ($key === '' || !isset($allowed[$key])) {
            continue;
        }
        $allowed[$key] = cvCheckoutApiNormalizeBaggageItem($row);
    }

    return $allowed;
}

/**
 * @param array<string,mixed> $source
 * @param array<int,string> $keys
 */
function cvCheckoutApiExtractNumericByKeys(array $source, array $keys): ?float
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $source)) {
            continue;
        }
        $value = $source[$key];
        if (is_numeric($value)) {
            return (float) $value;
        }
    }

    foreach ($source as $value) {
        if (!is_array($value)) {
            continue;
        }
        $nested = cvCheckoutApiExtractNumericByKeys($value, $keys);
        if ($nested !== null) {
            return $nested;
        }
    }

    return null;
}

/**
 * @param array<string,mixed> $primary
 * @param array<string,mixed> $fallback
 */
function cvCheckoutApiResolveBaggageUnitPrice(array $primary, array $fallback, string $type): float
{
    $type = strtolower(trim($type));
    $keys = $type === 'hand'
        ? [
            'hand_bag_unit_price',
            'hand_bag_price',
            'hand_bags_price',
            'bag_price_hand',
            'cabin_bag_price',
            'cabin_bag_unit_price',
            'cabin_price',
            'prz_pacco_a',
            'prz_cabina',
        ]
        : [
            'checked_bag_unit_price',
            'checked_bag_price',
            'checked_bags_price',
            'bag_price_checked',
            'hold_bag_price',
            'hold_bag_unit_price',
            'stiva_price',
            'prz_pacco',
        ];

    $value = cvCheckoutApiExtractNumericByKeys($primary, $keys);
    if ($value === null) {
        $value = cvCheckoutApiExtractNumericByKeys($fallback, $keys);
    }
    if ($value === null) {
        return 0.0;
    }

    return round(max(0.0, (float) $value), 2);
}

/**
 * @param array<string,mixed> $primary
 * @param array<string,mixed> $fallback
 */
function cvCheckoutApiResolveBaggageScalar(array $primary, array $fallback, array $keys, float $default = 0.0): float
{
    $value = cvCheckoutApiExtractNumericByKeys($primary, $keys);
    if ($value === null) {
        $value = cvCheckoutApiExtractNumericByKeys($fallback, $keys);
    }
    if ($value === null) {
        return $default;
    }
    return max(0.0, (float) $value);
}

/**
 * @param array<string,mixed> $primary
 * @param array<string,mixed> $fallback
 */
function cvCheckoutApiResolveBaggageMaxQty(array $primary, array $fallback, string $type): int
{
    $keys = $type === 'hand'
        ? ['hand_bag_max_qty', 'max_qnt_hand', 'max_qnt']
        : ['checked_bag_max_qty', 'max_qnt_checked', 'max_qnt'];
    $value = cvCheckoutApiExtractNumericByKeys($primary, $keys);
    if ($value === null) {
        $value = cvCheckoutApiExtractNumericByKeys($fallback, $keys);
    }
    if ($value === null) {
        return 8;
    }
    $qty = (int) round($value);
    return max(1, min(20, $qty));
}

/**
 * @param array<string,mixed> $leg
 */
function cvCheckoutApiComputeCheckedBagAmount(int $count, array $leg): float
{
    $count = max(0, $count);
    if ($count === 0) {
        return 0.0;
    }
    $base = isset($leg['checked_bag_base_price']) && is_numeric($leg['checked_bag_base_price'])
        ? max(0.0, (float) $leg['checked_bag_base_price'])
        : 0.0;
    $increment = isset($leg['checked_bag_increment']) && is_numeric($leg['checked_bag_increment'])
        ? max(0.0, (float) $leg['checked_bag_increment'])
        : 0.0;
    $unit = isset($leg['checked_bag_unit_price']) && is_numeric($leg['checked_bag_unit_price'])
        ? max(0.0, (float) $leg['checked_bag_unit_price'])
        : 0.0;

    if ($base > 0.0 || $increment > 0.0) {
        if ($count === 1) {
            return round($base, 2);
        }
        return round($base + (($count - 1) * $increment), 2);
    }

    return round($count * $unit, 2);
}

/**
 * @param array<int,array<string,mixed>> $normalizedLegs
 * @param array<string,array<string,mixed>> $baggageByLeg
 * @param array<string,float> $providerCommissionMap
 * @return array<string,mixed>
 */
function cvCheckoutApiComputePreviewTotals(array $normalizedLegs, array $baggageByLeg, array $providerCommissionMap): array
{
    $baseTotal = 0.0;
    $baggageTotal = 0.0;
    $clientTotal = 0.0;
    $commissionTotal = 0.0;
    $legs = [];

    foreach ($normalizedLegs as $leg) {
        if (!is_array($leg)) {
            continue;
        }

        $providerCode = strtolower(trim((string) ($leg['provider_code'] ?? '')));
        $legIndex = (int) ($leg['leg_index'] ?? 1);
        $direction = (string) ($leg['direction'] ?? 'outbound');
        $baseAmount = max(0.0, (float) ($leg['base_amount'] ?? ($leg['amount'] ?? 0.0)));
        $key = cvCheckoutApiLegMapKey($direction, $legIndex, $providerCode);
        $baggage = isset($baggageByLeg[$key]) && is_array($baggageByLeg[$key]) ? $baggageByLeg[$key] : ['checked_bags' => 0, 'hand_bags' => 0];
        $checkedMaxQty = isset($leg['checked_bag_max_qty']) && is_numeric($leg['checked_bag_max_qty']) ? max(1, min(20, (int) $leg['checked_bag_max_qty'])) : 8;
        $handMaxQty = isset($leg['hand_bag_max_qty']) && is_numeric($leg['hand_bag_max_qty']) ? max(1, min(20, (int) $leg['hand_bag_max_qty'])) : 8;
        $checkedCount = isset($baggage['checked_bags']) && is_numeric($baggage['checked_bags']) ? max(0, min($checkedMaxQty, (int) $baggage['checked_bags'])) : 0;
        $handCount = isset($baggage['hand_bags']) && is_numeric($baggage['hand_bags']) ? max(0, min($handMaxQty, (int) $baggage['hand_bags'])) : 0;
        $checkedUnit = isset($leg['checked_bag_unit_price']) && is_numeric($leg['checked_bag_unit_price'])
            ? max(0.0, (float) $leg['checked_bag_unit_price'])
            : 0.0;
        $handUnit = isset($leg['hand_bag_unit_price']) && is_numeric($leg['hand_bag_unit_price'])
            ? max(0.0, (float) $leg['hand_bag_unit_price'])
            : 0.0;
        $checkedAmount = cvCheckoutApiComputeCheckedBagAmount($checkedCount, $leg);
        $legBaggageAmount = round($checkedAmount + ($handCount * $handUnit), 2);
        $commissionPercent = isset($providerCommissionMap[$providerCode]) ? (float) $providerCommissionMap[$providerCode] : 0.0;
        $grossAmount = round($baseAmount + $legBaggageAmount, 2);
        $commissionCalc = cvRuntimeApplyProviderCommission($grossAmount, $commissionPercent, 1);
        $legClientAmount = round((float) ($commissionCalc['client_amount'] ?? 0.0), 2);
        $legCommission = round((float) ($commissionCalc['commission_amount'] ?? 0.0), 2);

        $baseTotal += $baseAmount;
        $baggageTotal += $legBaggageAmount;
        $clientTotal += $legClientAmount;
        $commissionTotal += $legCommission;

        $legs[] = [
            'direction' => $direction,
            'leg_index' => $legIndex,
            'provider_code' => $providerCode,
            'amount' => $legClientAmount,
            'base_amount' => round($baseAmount, 2),
            'baggage_amount' => $legBaggageAmount,
            'gross_amount' => $grossAmount,
            'client_amount' => $legClientAmount,
            'checked_bags' => $checkedCount,
            'checked_bag_unit_price' => round($checkedUnit, 2),
            'checked_bag_base_price' => isset($leg['checked_bag_base_price']) && is_numeric($leg['checked_bag_base_price']) ? round((float) $leg['checked_bag_base_price'], 2) : round($checkedUnit, 2),
            'checked_bag_increment' => isset($leg['checked_bag_increment']) && is_numeric($leg['checked_bag_increment']) ? round((float) $leg['checked_bag_increment'], 2) : 0.0,
            'checked_bag_max_qty' => $checkedMaxQty,
            'hand_bags' => $handCount,
            'hand_bag_unit_price' => round($handUnit, 2),
            'hand_bag_max_qty' => $handMaxQty,
            'commission_percent' => round($commissionPercent, 4),
            'commission_amount' => $legCommission,
        ];
    }

    return [
        'base_total' => round($baseTotal, 2),
        'baggage_total' => round($baggageTotal, 2),
        'client_total' => round($clientTotal, 2),
        'commission_total' => round($commissionTotal, 2),
        'currency' => 'EUR',
        'legs' => $legs,
    ];
}

function cvCheckoutApiPromotionTravelDateIt(array $query, array $normalizedLegs): string
{
    $candidate = trim((string) ($query['dt1'] ?? ''));
    if ($candidate !== '') {
        return $candidate;
    }

    foreach ($normalizedLegs as $leg) {
        if (!is_array($leg)) {
            continue;
        }
        $departureIso = trim((string) ($leg['departure_iso'] ?? ''));
        if ($departureIso === '') {
            continue;
        }
        try {
            $date = new DateTimeImmutable($departureIso);
            return $date->format('d/m/Y');
        } catch (Throwable $exception) {
            continue;
        }
    }

    return '';
}

function cvCheckoutApiPromotionMatchesDateRange(array $promotion, string $travelDateIt): bool
{
    $travelDateIt = trim($travelDateIt);
    if ($travelDateIt === '') {
        return true;
    }

    $travelDate = DateTimeImmutable::createFromFormat('d/m/Y', $travelDateIt);
    if (!$travelDate instanceof DateTimeImmutable) {
        return false;
    }
    $travelDateMid = $travelDate->setTime(12, 0, 0);

    $validFromRaw = trim((string) ($promotion['valid_from'] ?? ''));
    if ($validFromRaw !== '') {
        $validFromTs = strtotime($validFromRaw);
        if (!is_int($validFromTs) || $validFromTs <= 0) {
            return false;
        }
        if ($travelDateMid->getTimestamp() < $validFromTs) {
            return false;
        }
    }

    $validToRaw = trim((string) ($promotion['valid_to'] ?? ''));
    if ($validToRaw !== '') {
        $validToTs = strtotime($validToRaw);
        if (!is_int($validToTs) || $validToTs <= 0) {
            return false;
        }
        if ($travelDateMid->getTimestamp() > $validToTs) {
            return false;
        }
    }

    return true;
}

function cvCheckoutApiPromotionMatchesWeekday(array $promotion, string $travelDateIt): bool
{
    $days = cvPromotionsNormalizeWeekdays((string) ($promotion['days_of_week'] ?? ''));
    if (count($days) === 0) {
        return true;
    }

    $weekday = cvPromotionsTravelDateWeekday($travelDateIt);
    if (!is_int($weekday)) {
        return false;
    }

    return in_array($weekday, $days, true);
}

/**
 * @param array<int,array<string,mixed>> $legs
 * @return array<string,mixed>
 */
function cvCheckoutApiPromotionResolveForTotals(
    mysqli $connection,
    array $legs,
    string $travelDateIt,
    string $promotionCode = ''
): array {
    $result = [
        'applied' => false,
        'source' => '',
        'promotion_id' => 0,
        'name' => '',
        'code' => '',
        'discount_percent' => 0.0,
        'discount_amount' => 0.0,
        'eligible_amount' => 0.0,
        'eligible_commission' => 0.0,
        'eligible_provider_codes' => [],
        'leg_discount_by_key' => [],
        'message' => '',
    ];

    if (!cvPromotionsEnsureTable($connection)) {
        return $result;
    }

    $promotionCode = strtoupper(trim($promotionCode));
    $sql = '';
    if ($promotionCode !== '') {
        $sql = "SELECT *
                FROM cv_promotions
                WHERE is_active = 1
                  AND (
                    (mode = 'auto' AND visibility = 'public')
                    OR (mode = 'code' AND UPPER(code) = ?)
                  )
                ORDER BY priority ASC, discount_percent DESC, id_promo DESC";
    } else {
        $sql = "SELECT *
                FROM cv_promotions
                WHERE is_active = 1
                  AND mode = 'auto'
                  AND visibility = 'public'
                ORDER BY priority ASC, discount_percent DESC, id_promo DESC";
    }

    $statement = $connection->prepare($sql);
    if (!$statement instanceof mysqli_stmt) {
        return $result;
    }

    if ($promotionCode !== '') {
        $statement->bind_param('s', $promotionCode);
    }
    if (!$statement->execute()) {
        $statement->close();
        return $result;
    }

    $queryResult = $statement->get_result();
    if (!$queryResult instanceof mysqli_result) {
        $statement->close();
        return $result;
    }

    $best = null;
    while ($row = $queryResult->fetch_assoc()) {
        if (!is_array($row)) {
            continue;
        }

        $mode = strtolower(trim((string) ($row['mode'] ?? 'code')));
        $code = strtoupper(trim((string) ($row['code'] ?? '')));
        if ($mode === 'code' && $promotionCode === '') {
            continue;
        }
        if ($mode === 'code' && $promotionCode !== '' && $code !== $promotionCode) {
            continue;
        }

        if (!cvCheckoutApiPromotionMatchesDateRange($row, $travelDateIt)) {
            continue;
        }
        if (!cvCheckoutApiPromotionMatchesWeekday($row, $travelDateIt)) {
            continue;
        }

        $providerFilter = cvPromotionsNormalizeProviderCodes((string) ($row['provider_codes'] ?? ''));
        $providerFilterMap = [];
        foreach ($providerFilter as $providerCode) {
            $providerFilterMap[$providerCode] = true;
        }

        $eligibleAmount = 0.0;
        $eligibleCommission = 0.0;
        $eligibleLegs = [];
        $eligibleProviderCodes = [];
        foreach ($legs as $leg) {
            if (!is_array($leg)) {
                continue;
            }
            $providerCode = strtolower(trim((string) ($leg['provider_code'] ?? '')));
            if ($providerCode === '') {
                continue;
            }
            if (count($providerFilterMap) > 0 && !isset($providerFilterMap[$providerCode])) {
                continue;
            }
            $legAmount = max(0.0, (float) ($leg['amount'] ?? ($leg['client_amount'] ?? 0.0)));
            $commissionAmount = max(0.0, (float) ($leg['commission_amount'] ?? 0.0));
            if ($commissionAmount <= 0.0 || $legAmount <= 0.0) {
                continue;
            }
            $eligibleAmount += $legAmount;
            $eligibleCommission += $commissionAmount;
            $eligibleProviderCodes[$providerCode] = $providerCode;
            $eligibleLegs[] = $leg;
        }

        $discountPercent = max(0.0, min(100.0, (float) ($row['discount_percent'] ?? 0.0)));
        $discountAmountByFare = round(($eligibleAmount * $discountPercent) / 100, 2);
        $discountAmount = min($discountAmountByFare, round($eligibleCommission, 2));
        if ($discountAmount <= 0.0 || $eligibleCommission <= 0.0 || $eligibleAmount <= 0.0) {
            continue;
        }

        $candidate = [
            'row' => $row,
            'mode' => $mode,
            'code' => $code,
            'discount_percent' => $discountPercent,
            'discount_amount' => $discountAmount,
            'eligible_amount' => round($eligibleAmount, 2),
            'eligible_commission' => round($eligibleCommission, 2),
            'eligible_legs' => $eligibleLegs,
            'eligible_provider_codes' => array_values($eligibleProviderCodes),
        ];

        if (!is_array($best)) {
            $best = $candidate;
            continue;
        }

        $bestDiscount = (float) ($best['discount_amount'] ?? 0.0);
        $bestPriority = isset($best['row']['priority']) ? (int) $best['row']['priority'] : 100;
        $candidatePriority = isset($row['priority']) ? (int) $row['priority'] : 100;
        if ($candidate['discount_amount'] > $bestDiscount || ($candidate['discount_amount'] === $bestDiscount && $candidatePriority < $bestPriority)) {
            $best = $candidate;
        }
    }

    $queryResult->free();
    $statement->close();

    if (!is_array($best)) {
        if ($promotionCode !== '') {
            $result['message'] = 'Codice promo non valido o non applicabile a questa soluzione.';
        }
        return $result;
    }

    $eligibleCommission = max(0.0, (float) ($best['eligible_commission'] ?? 0.0));
    $discountTotal = max(0.0, min((float) ($best['discount_amount'] ?? 0.0), $eligibleCommission));
    $eligibleLegs = isset($best['eligible_legs']) && is_array($best['eligible_legs']) ? $best['eligible_legs'] : [];
    $legDiscountMap = [];
    $remaining = $discountTotal;
    $eligibleCount = count($eligibleLegs);

    foreach ($eligibleLegs as $index => $leg) {
        if (!is_array($leg)) {
            continue;
        }
        $key = cvCheckoutApiLegMapKey(
            (string) ($leg['direction'] ?? 'outbound'),
            (int) ($leg['leg_index'] ?? 1),
            (string) ($leg['provider_code'] ?? '')
        );
        if ($key === '') {
            continue;
        }
        $commissionAmount = max(0.0, (float) ($leg['commission_amount'] ?? 0.0));
        $legDiscount = 0.0;
        if ($index === $eligibleCount - 1) {
            $legDiscount = round(max(0.0, min($remaining, $commissionAmount)), 2);
        } elseif ($eligibleCommission > 0.0) {
            $legDiscount = round(($discountTotal * $commissionAmount) / $eligibleCommission, 2);
            if ($legDiscount > $remaining) {
                $legDiscount = $remaining;
            }
            if ($legDiscount > $commissionAmount) {
                $legDiscount = $commissionAmount;
            }
        }
        $remaining = round($remaining - $legDiscount, 2);
        if ($legDiscount > 0.0) {
            $legDiscountMap[$key] = $legDiscount;
        }
    }

    $row = $best['row'];
    $result['applied'] = true;
    $result['source'] = (string) ($best['mode'] ?? 'code');
    $result['promotion_id'] = isset($row['id_promo']) ? (int) $row['id_promo'] : 0;
    $result['name'] = trim((string) ($row['name'] ?? ''));
    $result['code'] = strtoupper(trim((string) ($best['code'] ?? '')));
    $result['discount_percent'] = (float) ($best['discount_percent'] ?? 0.0);
    $result['discount_amount'] = round($discountTotal, 2);
    $result['eligible_amount'] = round((float) ($best['eligible_amount'] ?? 0.0), 2);
    $result['eligible_commission'] = round($eligibleCommission, 2);
    $result['eligible_provider_codes'] = isset($best['eligible_provider_codes']) && is_array($best['eligible_provider_codes'])
        ? $best['eligible_provider_codes']
        : [];
    $result['leg_discount_by_key'] = $legDiscountMap;
    $result['message'] = $promotionCode !== ''
        ? 'Codice promo applicato.'
        : 'Promo automatica applicata.';

    return $result;
}

/**
 * @param array<string,mixed> $promotionResult
 * @return array<string,mixed>
 */
function cvCheckoutApiApplyPromotionToTotals(array $totals, array $promotionResult): array
{
    $applied = !empty($promotionResult['applied']);
    $totals['commission_total_raw'] = isset($totals['commission_total']) ? round((float) $totals['commission_total'], 2) : 0.0;
    $totals['promotion_discount_total'] = 0.0;
    $totals['promotion'] = $promotionResult;

    $legs = isset($totals['legs']) && is_array($totals['legs']) ? $totals['legs'] : [];
    if (!$applied) {
        foreach ($legs as $idx => $leg) {
            if (!is_array($leg)) {
                continue;
            }
            $legs[$idx]['commission_amount_raw'] = isset($leg['commission_amount']) ? round((float) $leg['commission_amount'], 2) : 0.0;
            $legs[$idx]['promotion_discount_amount'] = 0.0;
        }
        $totals['legs'] = $legs;
        return $totals;
    }

    $discountTotal = round(max(0.0, (float) ($promotionResult['discount_amount'] ?? 0.0)), 2);
    if ($discountTotal <= 0.0) {
        $totals['legs'] = $legs;
        return $totals;
    }

    $discountMap = isset($promotionResult['leg_discount_by_key']) && is_array($promotionResult['leg_discount_by_key'])
        ? $promotionResult['leg_discount_by_key']
        : [];
    foreach ($legs as $idx => $leg) {
        if (!is_array($leg)) {
            continue;
        }
        $direction = (string) ($leg['direction'] ?? 'outbound');
        $legIndex = (int) ($leg['leg_index'] ?? 1);
        $providerCode = (string) ($leg['provider_code'] ?? '');
        $key = cvCheckoutApiLegMapKey($direction, $legIndex, $providerCode);
        $rawCommission = max(0.0, (float) ($leg['commission_amount'] ?? 0.0));
        $rawAmount = max(0.0, (float) ($leg['amount'] ?? 0.0));
        $legDiscount = isset($discountMap[$key]) ? max(0.0, (float) $discountMap[$key]) : 0.0;
        if ($legDiscount > $rawCommission) {
            $legDiscount = $rawCommission;
        }
        if ($legDiscount > $rawAmount) {
            $legDiscount = $rawAmount;
        }
        $legs[$idx]['amount_raw'] = round($rawAmount, 2);
        $legs[$idx]['amount'] = round($rawAmount - $legDiscount, 2);
        $legs[$idx]['commission_amount_raw'] = round($rawCommission, 2);
        $legs[$idx]['promotion_discount_amount'] = round($legDiscount, 2);
        $legs[$idx]['commission_amount'] = round($rawCommission - $legDiscount, 2);
    }

    $commissionRaw = round(max(0.0, (float) ($totals['commission_total_raw'] ?? 0.0)), 2);
    if ($discountTotal > $commissionRaw) {
        $discountTotal = $commissionRaw;
    }
    $totals['promotion_discount_total'] = $discountTotal;
    $totals['commission_total'] = round(max(0.0, $commissionRaw - $discountTotal), 2);
    $clientTotal = isset($totals['client_total']) ? (float) $totals['client_total'] : 0.0;
    $totals['client_total'] = round(max(0.0, $clientTotal - $discountTotal), 2);
    $totals['legs'] = $legs;
    return $totals;
}

/**
 * @return array<string,int>
 */
function cvCheckoutApiFetchAziendaIdsByCode(mysqli $connection): array
{
    $sql = "SELECT code, id_az FROM aziende WHERE stato = 1";
    $result = $connection->query($sql);
    if (!$result instanceof mysqli_result) {
        return [];
    }

    $map = [];
    while ($row = $result->fetch_assoc()) {
        if (!is_array($row)) {
            continue;
        }

        $providerCode = strtolower(trim((string) ($row['code'] ?? '')));
        $idAzienda = isset($row['id_az']) ? (int) $row['id_az'] : 0;
        if ($providerCode === '' || $idAzienda <= 0) {
            continue;
        }

        $map[$providerCode] = $idAzienda;
    }

    $result->free();
    return $map;
}

/**
 * @param array<string,string> $providerConfig
 * @return array<string,mixed>
 */
function cvCheckoutApiProviderRequest(array $providerConfig, string $endpoint, array $payload, string $idempotencyKey = ''): array
{
    $baseUrl = trim((string) ($providerConfig['base_url'] ?? ''));
    if ($baseUrl === '') {
        return [
            'ok' => false,
            'message' => 'Provider base_url missing',
            'http_status' => 500,
            'body' => null,
        ];
    }

    $endpointUrl = cvProviderBuildEndpointUrl($baseUrl, $endpoint);
    if ($endpointUrl === '') {
        return [
            'ok' => false,
            'message' => 'Provider endpoint URL not valid',
            'http_status' => 500,
            'body' => null,
        ];
    }

    $headers = [];
    if ($idempotencyKey !== '') {
        $headers[] = 'X-Idempotency-Key: ' . $idempotencyKey;
    }

    $response = cvProviderHttpPostJson(
        $endpointUrl,
        $payload,
        (string) ($providerConfig['api_key'] ?? ''),
        $headers
    );

    if (!$response['ok'] || !is_array($response['body'])) {
        return [
            'ok' => false,
            'message' => is_string($response['error']) && trim($response['error']) !== ''
                ? trim($response['error'])
                : 'Provider reserve failed',
            'http_status' => (int) ($response['status'] ?? 502),
            'body' => $response['body'] ?? null,
        ];
    }

    $body = $response['body'];
    $success = isset($body['success']) && (bool) $body['success'] === true;
    if (!$success) {
        $errorMessage = 'Provider reserve failed';
        if (isset($body['error']) && is_array($body['error']) && isset($body['error']['message'])) {
            $errorMessage = trim((string) $body['error']['message']) ?: $errorMessage;
        } elseif (isset($body['message'])) {
            $errorMessage = trim((string) $body['message']) ?: $errorMessage;
        }

        return [
            'ok' => false,
            'message' => $errorMessage,
            'http_status' => (int) ($response['status'] ?: 409),
            'body' => $body,
        ];
    }

    return [
        'ok' => true,
        'message' => '',
        'http_status' => (int) ($response['status'] ?: 200),
        'body' => $body,
    ];
}

/**
 * @param array<string,string> $providerConfig
 * @param array<string,mixed> $extraPayload
 * @return array<string,mixed>
 */
function cvCheckoutApiReserveLeg(array $providerConfig, string $quoteToken, string $idempotencyKey, array $extraPayload = []): array
{
    $payload = array_merge(['quote_token' => $quoteToken], $extraPayload);
    return cvCheckoutApiProviderRequest($providerConfig, 'reserve', $payload, $idempotencyKey);
}

function cvCheckoutApiLooksLikeExpiredQuote(array $reserveResponse): bool
{
    $message = strtolower(trim((string) ($reserveResponse['message'] ?? '')));
    if ($message !== '' && str_contains($message, 'quote') && str_contains($message, 'expired')) {
        return true;
    }

    $body = isset($reserveResponse['body']) && is_array($reserveResponse['body']) ? $reserveResponse['body'] : [];
    $error = isset($body['error']) && is_array($body['error']) ? $body['error'] : [];
    $errorMessage = strtolower(trim((string) ($error['message'] ?? '')));
    if ($errorMessage !== '' && str_contains($errorMessage, 'quote') && str_contains($errorMessage, 'expired')) {
        return true;
    }

    return false;
}

function cvCheckoutApiTravelDateItFromLeg(array $leg, array $query): string
{
    $direction = strtolower(trim((string) ($leg['direction'] ?? 'outbound')));
    $candidates = [];

    if ($direction === 'inbound' || $direction === 'return') {
        $candidates[] = (string) ($query['dt2'] ?? '');
        $candidates[] = (string) ($query['dt1'] ?? '');
    } else {
        $candidates[] = (string) ($query['dt1'] ?? '');
        $candidates[] = (string) ($query['date'] ?? '');
        $candidates[] = (string) ($query['dt2'] ?? '');
    }

    foreach ($candidates as $queryDateRaw) {
        $queryDate = trim($queryDateRaw);
        if ($queryDate === '') {
            continue;
        }
        $ts = strtotime(str_replace('/', '-', $queryDate));
        if (is_int($ts) && $ts > 0) {
            return date('d/m/Y', $ts);
        }
        return $queryDate;
    }

    $departureIso = trim((string) ($leg['departure_iso'] ?? ''));
    if ($departureIso !== '') {
        try {
            $departureDate = new DateTimeImmutable($departureIso);
            return $departureDate->format('d/m/Y');
        } catch (Throwable $exception) {
            // Ignore parse failures and return empty fallback below.
        }
    }

    return '';
}

/**
 * @return array<string,mixed>
 */
function cvCheckoutApiRefreshQuoteForLeg(array $providerConfig, array $leg, int $ad, int $bam, string $travelDateIt, string $codiceCamb = ''): array
{
    $baseUrl = trim((string) ($providerConfig['base_url'] ?? ''));
    if ($baseUrl === '' || $travelDateIt === '') {
        return ['ok' => false, 'message' => 'Impossibile rigenerare quote token'];
    }

    $queryParams = [
        'part' => trim((string) ($leg['from_stop_id'] ?? '')),
        'arr' => trim((string) ($leg['to_stop_id'] ?? '')),
        'id_corsa' => trim((string) ($leg['trip_external_id'] ?? '')),
        'ad' => (string) max(0, $ad),
        'bam' => (string) max(0, $bam),
        'dt1' => $travelDateIt,
    ];

    $fareId = trim((string) ($leg['fare_id'] ?? ''));
    if ($fareId !== '') {
        $queryParams['fare_id'] = $fareId;
    }
    $codiceCamb = trim($codiceCamb);
    if ($codiceCamb !== '') {
        $queryParams['codice_camb'] = $codiceCamb;
        $queryParams['cmb'] = $codiceCamb;
    }

    $quoteUrl = cvProviderBuildQuoteUrl($baseUrl, $queryParams);
    if ($quoteUrl === '') {
        return ['ok' => false, 'message' => 'URL quote provider non valida'];
    }

    $quoteResponse = cvProviderHttpGetJson($quoteUrl, (string) ($providerConfig['api_key'] ?? ''));
    if (!(bool) ($quoteResponse['ok'] ?? false) || !is_array($quoteResponse['body'] ?? null)) {
        return [
            'ok' => false,
            'message' => 'Quote provider non disponibile',
            'provider_error' => $quoteResponse['error'] ?? null,
            'http_status' => (int) ($quoteResponse['status'] ?? 0),
        ];
    }

    $body = $quoteResponse['body'];
    $success = isset($body['success']) && (bool) $body['success'] === true;
    $data = isset($body['data']) && is_array($body['data']) ? $body['data'] : [];
    $quoteToken = trim((string) ($data['quote_token'] ?? ''));
    if (!$success || $quoteToken === '') {
        $providerError = isset($body['error']) && is_array($body['error']) ? $body['error'] : null;
        return [
            'ok' => false,
            'message' => 'Rigenerazione quote token fallita',
            'provider_error' => $providerError,
            'http_status' => (int) ($quoteResponse['status'] ?? 409),
        ];
    }

    return [
        'ok' => true,
        'quote_token' => $quoteToken,
        'quote_id' => trim((string) ($data['quote_id'] ?? '')),
        'expires_at' => trim((string) ($data['expires_at'] ?? '')),
    ];
}

/**
 * @param array<string,string> $providerConfig
 * @param array<string,mixed> $payload
 * @return array<string,mixed>
 */
function cvCheckoutApiFinalizeLegBooking(array $providerConfig, array $payload, string $idempotencyKey): array
{
    return cvCheckoutApiProviderRequest($providerConfig, 'book', $payload, $idempotencyKey);
}

/**
 * @param array<string,string> $providerConfig
 * @return array<string,mixed>
 */
function cvCheckoutApiCancelLegReservation(array $providerConfig, string $shopId, string $codiceCamb = ''): array
{
    $payload = ['shop_id' => $shopId];
    if ($codiceCamb !== '') {
        $payload['codice_camb'] = $codiceCamb;
    }

    return cvCheckoutApiProviderRequest($providerConfig, 'cancel', $payload);
}

/**
 * @param array<string,mixed> $input
 * @return array<string,mixed>|null
 */
function cvCheckoutApiLoadOrderByCode(mysqli $connection, string $orderCode): ?array
{
    $orderCode = trim($orderCode);
    if ($orderCode === '') {
        return null;
    }

    $orderSql = "SELECT *
                 FROM cv_orders
                 WHERE order_code = ?
                 LIMIT 1";
    $orderStmt = $connection->prepare($orderSql);
    if (!$orderStmt instanceof mysqli_stmt) {
        return null;
    }

    $orderStmt->bind_param('s', $orderCode);
    if (!$orderStmt->execute()) {
        $orderStmt->close();
        return null;
    }

    $orderResult = $orderStmt->get_result();
    $orderRow = $orderResult instanceof mysqli_result ? $orderResult->fetch_assoc() : null;
    if ($orderResult instanceof mysqli_result) {
        $orderResult->free();
    }
    $orderStmt->close();

    if (!is_array($orderRow)) {
        return null;
    }

    $orderId = 0;
    if (isset($orderRow['id_order'])) {
        $orderId = (int) $orderRow['id_order'];
    } elseif (isset($orderRow['order_id'])) {
        $orderId = (int) $orderRow['order_id'];
    } elseif (isset($orderRow['id'])) {
        $orderId = (int) $orderRow['id'];
    }
    if ($orderId <= 0) {
        return null;
    }

    $legsSql = "SELECT
                    l.id AS id_order_leg,
                    l.id_az,
                    l.direction,
                    l.leg_index,
                    l.provider_shop_id,
                    l.provider_booking_code,
                    l.id_linea,
                    l.id_corsa,
                    l.id_sott1,
                    l.id_sott2,
                    l.departure_at,
                    l.arrival_at,
                    l.fare_code,
                    l.passengers_json,
                    l.amount,
                    l.commission_amount,
                    l.status,
                    l.raw_response,
                    COALESCE(a.code, '') AS provider_code
                FROM cv_order_legs AS l
                LEFT JOIN aziende AS a
                    ON a.id_az = l.id_az
                WHERE l.order_id = ?
                ORDER BY l.direction ASC, l.leg_index ASC";
    $legsStmt = $connection->prepare($legsSql);
    if (!$legsStmt instanceof mysqli_stmt) {
        return null;
    }
    $legsStmt->bind_param('i', $orderId);
    if (!$legsStmt->execute()) {
        $legsStmt->close();
        return null;
    }
    $legsResult = $legsStmt->get_result();
    $legs = [];
    if ($legsResult instanceof mysqli_result) {
        while ($row = $legsResult->fetch_assoc()) {
            if (!is_array($row)) {
                continue;
            }
            $row['provider_code'] = strtolower(trim((string) ($row['provider_code'] ?? '')));
            $row['amount'] = isset($row['amount']) ? (float) $row['amount'] : 0.0;
            $row['commission_amount'] = isset($row['commission_amount']) ? (float) $row['commission_amount'] : 0.0;
            $legs[] = $row;
        }
        $legsResult->free();
    }
    $legsStmt->close();

    $orderRow['legs'] = $legs;
    return $orderRow;
}

/**
 * @return array<string,array<string,mixed>>
 */
function cvCheckoutApiBuildProviderSplitMap(array $order): array
{
    $legs = isset($order['legs']) && is_array($order['legs']) ? $order['legs'] : [];
    $map = [];
    foreach ($legs as $leg) {
        if (!is_array($leg)) {
            continue;
        }

        $providerCode = strtolower(trim((string) ($leg['provider_code'] ?? '')));
        if ($providerCode === '') {
            continue;
        }

        $gross = max(0.0, (float) ($leg['amount'] ?? 0.0));
        $commission = max(0.0, (float) ($leg['commission_amount'] ?? 0.0));
        if ($commission > $gross) {
            $commission = $gross;
        }
        $net = max(0.0, $gross - $commission);
        $commissionInfo = max(0.0, (float) ($leg['commission_amount'] ?? 0.0));

        if (!isset($map[$providerCode])) {
            $map[$providerCode] = [
                'provider_code' => $providerCode,
                'gross' => 0.0,
                'commission' => 0.0,
                'commission_info' => 0.0,
                'net' => 0.0,
            ];
        }

        $map[$providerCode]['gross'] += $gross;
        $map[$providerCode]['commission'] += $commission;
        $map[$providerCode]['commission_info'] += $commissionInfo;
        $map[$providerCode]['net'] += $net;
    }

    return $map;
}

function cvCheckoutApiPlatformCommissionTotal(array $providerSplitMap): float
{
    $sum = 0.0;
    foreach ($providerSplitMap as $item) {
        if (!is_array($item)) {
            continue;
        }
        $sum += max(0.0, (float) ($item['commission'] ?? 0.0));
    }
    return round($sum, 2);
}

function cvCheckoutApiPaypalBaseUrl(string $env): string
{
    return strtolower($env) === 'live'
        ? 'https://api.paypal.com'
        : 'https://api.sandbox.paypal.com';
}

/**
 * @param array<int,string> $headers
 * @return array<string,mixed>
 */
function cvCheckoutApiCurlJson(string $method, string $url, array $headers = [], ?array $jsonPayload = null, bool $formEncoded = false): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'status' => 0, 'error' => 'curl_init_failed', 'body' => null, 'raw' => null];
    }

    $method = strtoupper(trim($method));
    $curlHeaders = $headers;
    $body = null;
    if ($jsonPayload !== null) {
        $body = $formEncoded ? http_build_query($jsonPayload) : json_encode($jsonPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!$formEncoded) {
            $curlHeaders[] = 'Content-Type: application/json';
        } else {
            $curlHeaders[] = 'Content-Type: application/x-www-form-urlencoded';
        }
    }

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 12);
    curl_setopt($ch, CURLOPT_TIMEOUT, 35);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if (!is_string($raw)) {
        return ['ok' => false, 'status' => $status, 'error' => ($error !== '' ? $error : 'empty_response'), 'body' => null, 'raw' => null];
    }

    $decoded = json_decode($raw, true);
    $ok = $status >= 200 && $status < 300;
    return [
        'ok' => $ok,
        'status' => $status,
        'error' => $ok ? null : ($error !== '' ? $error : 'http_' . $status),
        'body' => is_array($decoded) ? $decoded : null,
        'raw' => $raw,
    ];
}

function cvCheckoutApiPaypalAccessToken(array $paypalConfig): string
{
    $clientId = trim((string) ($paypalConfig['client_id'] ?? ''));
    $clientSecret = trim((string) ($paypalConfig['client_secret'] ?? ''));
    $env = trim((string) ($paypalConfig['env'] ?? 'sandbox'));
    if ($clientId === '' || $clientSecret === '') {
        return '';
    }

    $url = cvCheckoutApiPaypalBaseUrl($env) . '/v1/oauth2/token';
    $headers = [
        'Authorization: Basic ' . base64_encode($clientId . ':' . $clientSecret),
        'Accept: application/json',
    ];
    $response = cvCheckoutApiCurlJson('POST', $url, $headers, ['grant_type' => 'client_credentials'], true);
    if (!$response['ok'] || !is_array($response['body'])) {
        return '';
    }
    return trim((string) ($response['body']['access_token'] ?? ''));
}

/**
 * @param array<string,array<string,mixed>> $providerSplitMap
 * @return array<int,array<string,mixed>>
 */
function cvCheckoutApiBuildPaypalPurchaseUnits(
    array $providerSplitMap,
    array $providerPaypalMerchantIds,
    array $providerPaypalEmails,
    string $currency,
    string $orderCode,
    string $platformMerchantId,
    bool $usePlatformFees = false
): array {
    $platformMerchantId = strtoupper(trim($platformMerchantId));
    $units = [];
    foreach ($providerSplitMap as $providerCode => $splitData) {
        $grossAmount = round(max(0.0, (float) ($splitData['gross'] ?? 0.0)), 2);
        $commissionAmount = round(max(0.0, (float) ($splitData['commission'] ?? 0.0)), 2);
        $netAmount = round(max(0.0, (float) ($splitData['net'] ?? 0.0)), 2);
        $unitAmount = $usePlatformFees ? $grossAmount : $netAmount;
        if ($unitAmount <= 0.0) {
            continue;
        }

        $merchantId = strtoupper(trim((string) ($providerPaypalMerchantIds[$providerCode] ?? '')));
        $merchantEmail = trim((string) ($providerPaypalEmails[$providerCode] ?? ''));
        if ($merchantId === '' && $merchantEmail === '') {
            continue;
        }

        $payee = [];
        if ($merchantId !== '') {
            $payee['merchant_id'] = $merchantId;
        } else {
            $payee['email_address'] = $merchantEmail;
        }

        $unit = [
            'reference_id' => 'provider_' . $providerCode,
            'amount' => [
                'currency_code' => $currency,
                'value' => number_format($unitAmount, 2, '.', ''),
            ],
            'payee' => $payee,
        ];
        if ($usePlatformFees && $commissionAmount > 0.0 && $platformMerchantId !== '') {
            $unit['payment_instruction'] = [
                'platform_fees' => [
                    [
                        'amount' => [
                            'currency_code' => $currency,
                            'value' => number_format($commissionAmount, 2, '.', ''),
                        ],
                        'payee' => [
                            'merchant_id' => $platformMerchantId,
                        ],
                    ],
                ],
            ];
        }
        $units[] = $unit;
    }

    if ($usePlatformFees) {
        return $units;
    }

    $commissionTotal = cvCheckoutApiPlatformCommissionTotal($providerSplitMap);
    if ($commissionTotal > 0 && $platformMerchantId !== '') {
        $units[] = [
            'reference_id' => 'platform_fee_' . $orderCode,
            'amount' => [
                'currency_code' => $currency,
                'value' => number_format($commissionTotal, 2, '.', ''),
            ],
            'payee' => [
                'merchant_id' => $platformMerchantId,
            ],
        ];
    }

    return $units;
}

function cvCheckoutApiUpdateOrderStatus(mysqli $connection, string $orderCode, string $status): void
{
    $sql = "UPDATE cv_orders SET status = ? WHERE order_code = ? LIMIT 1";
    $stmt = $connection->prepare($sql);
    if (!$stmt instanceof mysqli_stmt) {
        return;
    }
    $stmt->bind_param('ss', $status, $orderCode);
    $stmt->execute();
    $stmt->close();

    $allowedLegStatuses = ['draft', 'reserved', 'paid', 'failed', 'cancelled', 'refunded'];
    if (!in_array($status, $allowedLegStatuses, true)) {
        return;
    }

    $legSql = "UPDATE cv_order_legs AS l
               INNER JOIN cv_orders AS o ON o.id = l.order_id
               SET l.status = ?
               WHERE o.order_code = ?";
    $legStmt = $connection->prepare($legSql);
    if (!$legStmt instanceof mysqli_stmt) {
        return;
    }
    $legStmt->bind_param('ss', $status, $orderCode);
    $legStmt->execute();
    $legStmt->close();
}

function cvCheckoutApiOrdersHasTypeColumn(mysqli $connection): bool
{
    static $cache = null;
    if (is_bool($cache)) {
        return $cache;
    }

    $result = $connection->query("SHOW COLUMNS FROM cv_orders LIKE 'type'");
    if (!$result instanceof mysqli_result) {
        $cache = false;
        return false;
    }
    $cache = $result->num_rows > 0;
    $result->free();
    return $cache;
}

function cvCheckoutApiSetOrderPaymentType(mysqli $connection, string $orderCode, int $type): void
{
    if (!cvCheckoutApiOrdersHasTypeColumn($connection)) {
        return;
    }

    $type = ($type === 2) ? 2 : 1;
    $sql = "UPDATE cv_orders SET type = ? WHERE order_code = ? LIMIT 1";
    $stmt = $connection->prepare($sql);
    if (!$stmt instanceof mysqli_stmt) {
        return;
    }
    $stmt->bind_param('is', $type, $orderCode);
    $stmt->execute();
    $stmt->close();
}

/**
 * @param array<string,array<string,mixed>> $providerSplitMap
 * @return array<int,string>
 */
function cvCheckoutApiProviderCodesFromSplitMap(array $providerSplitMap): array
{
    $codes = [];
    foreach ($providerSplitMap as $providerCode => $row) {
        $providerCode = strtolower(trim((string) $providerCode));
        if ($providerCode === '') {
            continue;
        }
        $codes[$providerCode] = $providerCode;
    }
    return array_values($codes);
}

function cvCheckoutApiOrderId(array $order): int
{
    if (isset($order['id'])) {
        return (int) $order['id'];
    }
    if (isset($order['id_order'])) {
        return (int) $order['id_order'];
    }
    if (isset($order['order_id'])) {
        return (int) $order['order_id'];
    }

    return 0;
}

/**
 * @return array<string,mixed>
 */
function cvCheckoutApiOrderSearchContext(array $order): array
{
    return cvCheckoutApiJsonDecodeArray(isset($order['search_context']) ? (string) $order['search_context'] : '');
}

/**
 * @return array<string,mixed>
 */
function cvCheckoutApiOrderLegRawResponse(array $orderLeg): array
{
    return cvCheckoutApiJsonDecodeArray(isset($orderLeg['raw_response']) ? (string) $orderLeg['raw_response'] : '');
}

/**
 * @param array<string,mixed> $rawPayload
 */
function cvCheckoutApiUpdateOrderLegAfterFinalize(
    mysqli $connection,
    int $legId,
    string $status,
    array $rawPayload,
    string $providerBookingCode = '',
    string $providerShopId = ''
): void {
    if ($legId <= 0) {
        return;
    }

    $rawJson = cvCheckoutApiJsonEncode($rawPayload);
    $sql = "UPDATE cv_order_legs
            SET status = ?, raw_response = ?, provider_booking_code = ?, provider_shop_id = ?
            WHERE id = ?
            LIMIT 1";
    $stmt = $connection->prepare($sql);
    if (!$stmt instanceof mysqli_stmt) {
        return;
    }

    $stmt->bind_param('ssssi', $status, $rawJson, $providerBookingCode, $providerShopId, $legId);
    $stmt->execute();
    $stmt->close();
}

function cvCheckoutApiFetchExistingPaymentTransactionId(
    mysqli $connection,
    int $orderId,
    string $gateway,
    string $transactionRef
): int {
    if ($orderId <= 0 || $gateway === '' || $transactionRef === '') {
        return 0;
    }

    $sql = "SELECT id
            FROM cv_payment_transactions
            WHERE order_id = ? AND gateway = ? AND transaction_ref = ?
            LIMIT 1";
    $stmt = $connection->prepare($sql);
    if (!$stmt instanceof mysqli_stmt) {
        return 0;
    }

    $stmt->bind_param('iss', $orderId, $gateway, $transactionRef);
    if (!$stmt->execute()) {
        $stmt->close();
        return 0;
    }

    $result = $stmt->get_result();
    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $stmt->close();

    return is_array($row) && isset($row['id']) ? (int) $row['id'] : 0;
}

function cvCheckoutApiUpsertPaymentTransaction(
    mysqli $connection,
    int $orderId,
    string $gateway,
    string $transactionRef,
    string $providerRef,
    float $amount,
    string $currency,
    string $status,
    ?string $rawRequest,
    ?string $rawResponse
): int {
    $existingId = cvCheckoutApiFetchExistingPaymentTransactionId($connection, $orderId, $gateway, $transactionRef);
    if ($existingId > 0) {
        $sql = "UPDATE cv_payment_transactions
                SET provider_ref = ?, amount = ?, currency = ?, status = ?, raw_request = ?, raw_response = ?
                WHERE id = ?
                LIMIT 1";
        $stmt = $connection->prepare($sql);
        if ($stmt instanceof mysqli_stmt) {
            $stmt->bind_param('sdssssi', $providerRef, $amount, $currency, $status, $rawRequest, $rawResponse, $existingId);
            $stmt->execute();
            $stmt->close();
        }

        return $existingId;
    }

    $sql = "INSERT INTO cv_payment_transactions
            (order_id, gateway, transaction_ref, provider_ref, amount, currency, status, raw_request, raw_response)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $connection->prepare($sql);
    if (!$stmt instanceof mysqli_stmt) {
        return 0;
    }

    $stmt->bind_param(
        'isssdssss',
        $orderId,
        $gateway,
        $transactionRef,
        $providerRef,
        $amount,
        $currency,
        $status,
        $rawRequest,
        $rawResponse
    );
    if (!$stmt->execute()) {
        $stmt->close();
        return 0;
    }

    $id = (int) $stmt->insert_id;
    $stmt->close();
    return $id;
}

function cvCheckoutApiReplacePaymentSplits(
    mysqli $connection,
    int $paymentTxId,
    array $order,
    array $providerSplitMap
): void {
    if ($paymentTxId <= 0) {
        return;
    }

    $deleteStmt = $connection->prepare('DELETE FROM cv_payment_splits WHERE payment_tx_id = ?');
    if ($deleteStmt instanceof mysqli_stmt) {
        $deleteStmt->bind_param('i', $paymentTxId);
        $deleteStmt->execute();
        $deleteStmt->close();
    }

    $providerIdMap = [];
    $legs = isset($order['legs']) && is_array($order['legs']) ? $order['legs'] : [];
    foreach ($legs as $leg) {
        if (!is_array($leg)) {
            continue;
        }
        $providerCode = strtolower(trim((string) ($leg['provider_code'] ?? '')));
        $idAz = isset($leg['id_az']) ? (int) $leg['id_az'] : 0;
        if ($providerCode !== '' && $idAz > 0 && !isset($providerIdMap[$providerCode])) {
            $providerIdMap[$providerCode] = $idAz;
        }
    }

    $insertSql = "INSERT INTO cv_payment_splits
        (payment_tx_id, id_az, split_type, amount, status, settled_at, meta_json)
        VALUES (?, ?, ?, ?, 'settled', NOW(), ?)";
    $insertStmt = $connection->prepare($insertSql);
    if (!$insertStmt instanceof mysqli_stmt) {
        throw new RuntimeException('Prepare cv_payment_splits failed.');
    }

    foreach ($providerSplitMap as $providerCode => $splitData) {
        if (!is_array($splitData)) {
            continue;
        }
        $net = round(max(0.0, (float) ($splitData['net'] ?? 0.0)), 2);
        if ($net <= 0) {
            continue;
        }

        $metaJson = cvCheckoutApiJsonEncode([
            'provider_code' => $providerCode,
            'gross' => round((float) ($splitData['gross'] ?? 0.0), 2),
            'commission' => round((float) ($splitData['commission'] ?? 0.0), 2),
        ]);
        $idAz = (int) ($providerIdMap[$providerCode] ?? 0);
        $splitType = 'provider_amount';
        $insertStmt->bind_param('iisds', $paymentTxId, $idAz, $splitType, $net, $metaJson);
        if (!$insertStmt->execute()) {
            $error = $insertStmt->error;
            $insertStmt->close();
            throw new RuntimeException('Insert provider split failed: ' . $error);
        }
    }

    $commissionTotal = round(cvCheckoutApiPlatformCommissionTotal($providerSplitMap), 2);
    if ($commissionTotal > 0) {
        $metaJson = cvCheckoutApiJsonEncode([
            'provider_codes' => array_values(cvCheckoutApiProviderCodesFromSplitMap($providerSplitMap)),
        ]);
        $splitType = 'platform_fee';
        $idAzNull = null;
        $insertStmt->bind_param('iisds', $paymentTxId, $idAzNull, $splitType, $commissionTotal, $metaJson);
        if (!$insertStmt->execute()) {
            $error = $insertStmt->error;
            $insertStmt->close();
            throw new RuntimeException('Insert platform split failed: ' . $error);
        }
    }

    $insertStmt->close();
}

/**
 * @return array<int,int>
 */
function cvCheckoutApiBuildRidMap(array $passengers, int $adultCount, int $childCount): array
{
    $total = count($passengers);
    $ridMap = array_fill(0, $total, 0);
    if ($total <= 0 || $childCount <= 0) {
        return $ridMap;
    }

    $assigned = 0;
    foreach ($passengers as $index => $passenger) {
        if (!is_array($passenger) || $assigned >= $childCount) {
            continue;
        }

        $typeRaw = strtolower(trim((string) ($passenger['passenger_type'] ?? '')));
        $isChildRaw = $passenger['is_child'] ?? null;
        $isChildFlag = false;
        if (in_array($typeRaw, ['child', 'bambino'], true)) {
            $isChildFlag = true;
        } elseif (in_array($typeRaw, ['adult', 'adulto'], true)) {
            $isChildFlag = false;
        } elseif ($isChildRaw !== null) {
            $isChildFlag = filter_var($isChildRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;
        }

        if ($isChildFlag) {
            $ridMap[(int) $index] = 1;
            $assigned++;
        }
    }

    $ages = [];
    foreach ($passengers as $index => $passenger) {
        if ((int) ($ridMap[(int) $index] ?? 0) === 1) {
            continue;
        }
        $birthDate = is_array($passenger) ? trim((string) ($passenger['birth_date'] ?? '')) : '';
        $timestamp = $birthDate !== '' ? strtotime($birthDate) : false;
        $ages[] = [
            'index' => (int) $index,
            'timestamp' => ($timestamp !== false) ? (int) $timestamp : 0,
        ];
    }

    usort($ages, static function (array $a, array $b): int {
        return $b['timestamp'] <=> $a['timestamp'];
    });

    foreach ($ages as $item) {
        if ($assigned >= $childCount) {
            break;
        }
        if ((int) $item['timestamp'] <= 0) {
            continue;
        }

        $ridMap[(int) $item['index']] = 1;
        $assigned++;
    }

    if ($assigned < $childCount) {
        for ($i = $adultCount; $i < $total && $assigned < $childCount; $i++) {
            $ridMap[$i] = 1;
            $assigned++;
        }
    }

    return $ridMap;
}

function cvCheckoutApiNormalizePassengerIdentityName(string $value): string
{
    $normalized = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    if ($normalized === '') {
        return '';
    }

    if (function_exists('mb_strtolower')) {
        return mb_strtolower($normalized, 'UTF-8');
    }

    return strtolower($normalized);
}

function cvCheckoutApiNormalizePassengerIdentityDate(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $timestamp = strtotime($value);
    if ($timestamp === false || $timestamp <= 0) {
        return '';
    }

    $normalized = date('Y-m-d', $timestamp);
    return $normalized === '1970-01-01' ? '' : $normalized;
}

/**
 * @return array<string,mixed>|null
 */
function cvCheckoutApiLoadChangePassengerLock(mysqli $connection, string $ticketCode): ?array
{
    $ticketCode = trim($ticketCode);
    if ($ticketCode === '') {
        return null;
    }

    $sql = "SELECT
                b.codice,
                b.rid,
                COALESCE(
                    NULLIF(TRIM(CONCAT_WS(' ', vgt.nome, vgt.cognome)), ''),
                    NULLIF(TRIM(CONCAT_WS(' ', vg.nome, vg.cognome)), ''),
                    ''
                ) AS passenger_name,
                COALESCE(
                    NULLIF(DATE_FORMAT(vgt.data_reg, '%Y-%m-%d'), '1970-01-01'),
                    NULLIF(DATE_FORMAT(vg.data, '%Y-%m-%d'), '1970-01-01'),
                    ''
                ) AS passenger_birth_date
            FROM biglietti AS b
            LEFT JOIN viaggiatori AS vg ON vg.id_vg = b.id_vg
            LEFT JOIN viaggiatori_temp AS vgt ON vgt.id_vgt = b.id_vgt
            WHERE b.codice = ?
            ORDER BY b.id_bg DESC
            LIMIT 1";
    $stmt = $connection->prepare($sql);
    if (!$stmt instanceof mysqli_stmt) {
        return null;
    }

    $stmt->bind_param('s', $ticketCode);
    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }

    $result = $stmt->get_result();
    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $stmt->close();

    if (!is_array($row)) {
        return null;
    }

    return [
        'ticket_code' => trim((string) ($row['codice'] ?? '')),
        'rid' => isset($row['rid']) ? (int) $row['rid'] : 0,
        'passenger_name' => trim((string) ($row['passenger_name'] ?? '')),
        'passenger_birth_date' => cvCheckoutApiNormalizePassengerIdentityDate((string) ($row['passenger_birth_date'] ?? '')),
    ];
}

function cvCheckoutApiValidateChangePassengerLock(array $submittedPassengers, array $lock): ?string
{
    if (count($submittedPassengers) !== 1) {
        return 'Il cambio biglietto deve mantenere il solo viaggiatore del titolo originario.';
    }

    $expectedName = cvCheckoutApiNormalizePassengerIdentityName((string) ($lock['passenger_name'] ?? ''));
    $expectedBirthDate = cvCheckoutApiNormalizePassengerIdentityDate((string) ($lock['passenger_birth_date'] ?? ''));
    if ($expectedName === '') {
        return 'Impossibile verificare il passeggero originario del biglietto da cambiare.';
    }

    $firstPassenger = isset($submittedPassengers[0]) && is_array($submittedPassengers[0]) ? $submittedPassengers[0] : [];
    $submittedName = cvCheckoutApiNormalizePassengerIdentityName((string) ($firstPassenger['full_name'] ?? ''));
    if ($submittedName !== $expectedName) {
        return 'Nel cambio biglietto il viaggiatore deve restare quello del titolo originario.';
    }

    if ($expectedBirthDate !== '') {
        $submittedBirthDate = cvCheckoutApiNormalizePassengerIdentityDate((string) ($firstPassenger['birth_date'] ?? ''));
        if ($submittedBirthDate !== $expectedBirthDate) {
            return 'La data di nascita del viaggiatore deve restare quella del biglietto originario.';
        }
    }

    return null;
}

function cvCheckoutApiApplyChangePassengerLock(array $submittedPassengers, array $lock): array
{
    if (!isset($submittedPassengers[0]) || !is_array($submittedPassengers[0])) {
        return $submittedPassengers;
    }

    $submittedPassengers[0]['full_name'] = trim((string) ($lock['passenger_name'] ?? ($submittedPassengers[0]['full_name'] ?? '')));
    $lockedBirthDate = cvCheckoutApiNormalizePassengerIdentityDate((string) ($lock['passenger_birth_date'] ?? ''));
    if ($lockedBirthDate !== '') {
        $submittedPassengers[0]['birth_date'] = $lockedBirthDate;
    }

    return $submittedPassengers;
}

function cvCheckoutApiEnsureLocalContact(mysqli $connection, array $contact): int
{
    $email = strtolower(trim((string) ($contact['email'] ?? '')));
    $phone = trim((string) ($contact['phone'] ?? ''));
    $fullName = trim((string) ($contact['full_name'] ?? ''));
    $nameParts = cvCheckoutApiSplitFullName($fullName);

    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $sql = "SELECT id_vg
                FROM viaggiatori
                WHERE email = ?
                ORDER BY id_vg ASC
                LIMIT 1";
        $stmt = $connection->prepare($sql);
        if ($stmt instanceof mysqli_stmt) {
            $stmt->bind_param('s', $email);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
                if ($result instanceof mysqli_result) {
                    $result->free();
                }
                $stmt->close();
                if (is_array($row) && isset($row['id_vg'])) {
                    $idVg = (int) $row['id_vg'];
                    $updateSql = "UPDATE viaggiatori
                                  SET nome = ?, cognome = ?, tel = ?, stato = 1
                                  WHERE id_vg = ?
                                  LIMIT 1";
                    $updateStmt = $connection->prepare($updateSql);
                    if ($updateStmt instanceof mysqli_stmt) {
                        $updateStmt->bind_param('sssi', $nameParts['nome'], $nameParts['cognome'], $phone, $idVg);
                        $updateStmt->execute();
                        $updateStmt->close();
                    }
                    return $idVg;
                }
            } else {
                $stmt->close();
            }
        }
    }

    $insertSql = "INSERT INTO viaggiatori
        (nome, cognome, email, pass, tel, data, stato, foto)
        VALUES (?, ?, ?, '-', ?, '1970-01-01', 1, 'default.jpg')";
    $insertStmt = $connection->prepare($insertSql);
    if (!$insertStmt instanceof mysqli_stmt) {
        return 0;
    }

    $emailValue = $email !== '' ? $email : '-';
    $insertStmt->bind_param('ssss', $nameParts['nome'], $nameParts['cognome'], $emailValue, $phone);
    if (!$insertStmt->execute()) {
        $insertStmt->close();
        return 0;
    }

    $id = (int) $insertStmt->insert_id;
    $insertStmt->close();
    return $id;
}

function cvCheckoutApiInsertLocalPassengerTemp(mysqli $connection, array $passenger, array $contact): int
{
    $fullName = trim((string) ($passenger['full_name'] ?? ''));
    $nameParts = cvCheckoutApiSplitFullName($fullName);
    $phone = trim((string) ($contact['phone'] ?? ''));
    $email = strtolower(trim((string) ($contact['email'] ?? '')));
    $birthDate = trim((string) ($passenger['birth_date'] ?? ''));
    $birthDateSql = '1970-01-01';
    if ($birthDate !== '') {
        $birthTs = strtotime($birthDate);
        if ($birthTs !== false) {
            $birthDateSql = date('Y-m-d', $birthTs);
        }
    }

    $sql = "INSERT INTO viaggiatori_temp
        (nome, cognome, tel, email, data_reg, stato)
        VALUES (?, ?, ?, ?, ?, 1)";
    $stmt = $connection->prepare($sql);
    if (!$stmt instanceof mysqli_stmt) {
        return 0;
    }

    $emailValue = $email !== '' ? $email : '-';
    $stmt->bind_param('sssss', $nameParts['nome'], $nameParts['cognome'], $phone, $emailValue, $birthDateSql);
    if (!$stmt->execute()) {
        $stmt->close();
        return 0;
    }

    $id = (int) $stmt->insert_id;
    $stmt->close();
    return $id;
}

function cvCheckoutApiFindExistingLocalTicketId(mysqli $connection, int $idAz, string $transactionId, string $code): int
{
    if ($idAz <= 0 || $transactionId === '' || $code === '') {
        return 0;
    }

    $sql = "SELECT id_bg
            FROM biglietti
            WHERE id_az = ? AND transaction_id = ? AND codice = ?
            ORDER BY id_bg ASC
            LIMIT 1";
    $stmt = $connection->prepare($sql);
    if (!$stmt instanceof mysqli_stmt) {
        return 0;
    }

    $stmt->bind_param('iss', $idAz, $transactionId, $code);
    if (!$stmt->execute()) {
        $stmt->close();
        return 0;
    }

    $result = $stmt->get_result();
    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $stmt->close();

    return is_array($row) && isset($row['id_bg']) ? (int) $row['id_bg'] : 0;
}

function cvCheckoutApiExistsLineId(mysqli $connection, int $lineId): bool
{
    static $cache = [];
    if ($lineId <= 0) {
        return false;
    }
    if (array_key_exists($lineId, $cache)) {
        return (bool) $cache[$lineId];
    }

    $sql = "SELECT id_linea FROM linee WHERE id_linea = ? LIMIT 1";
    $stmt = $connection->prepare($sql);
    if (!$stmt instanceof mysqli_stmt) {
        $cache[$lineId] = false;
        return false;
    }

    $stmt->bind_param('i', $lineId);
    if (!$stmt->execute()) {
        $stmt->close();
        $cache[$lineId] = false;
        return false;
    }

    $result = $stmt->get_result();
    $exists = $result instanceof mysqli_result && $result->num_rows > 0;
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $stmt->close();
    $cache[$lineId] = $exists;
    return $exists;
}

function cvCheckoutApiExistsTripId(mysqli $connection, int $tripId): bool
{
    static $cache = [];
    if ($tripId <= 0) {
        return false;
    }
    if (array_key_exists($tripId, $cache)) {
        return (bool) $cache[$tripId];
    }

    $sql = "SELECT id_corsa FROM corse WHERE id_corsa = ? LIMIT 1";
    $stmt = $connection->prepare($sql);
    if (!$stmt instanceof mysqli_stmt) {
        $cache[$tripId] = false;
        return false;
    }

    $stmt->bind_param('i', $tripId);
    if (!$stmt->execute()) {
        $stmt->close();
        $cache[$tripId] = false;
        return false;
    }

    $result = $stmt->get_result();
    $exists = $result instanceof mysqli_result && $result->num_rows > 0;
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $stmt->close();
    $cache[$tripId] = $exists;
    return $exists;
}

function cvCheckoutApiExistsStopId(mysqli $connection, int $stopId): bool
{
    static $cache = [];
    if ($stopId <= 0) {
        return false;
    }
    if (array_key_exists($stopId, $cache)) {
        return (bool) $cache[$stopId];
    }

    $sql = "SELECT id_sott FROM tratte_sottoc WHERE id_sott = ? LIMIT 1";
    $stmt = $connection->prepare($sql);
    if (!$stmt instanceof mysqli_stmt) {
        $cache[$stopId] = false;
        return false;
    }

    $stmt->bind_param('i', $stopId);
    if (!$stmt->execute()) {
        $stmt->close();
        $cache[$stopId] = false;
        return false;
    }

    $result = $stmt->get_result();
    $exists = $result instanceof mysqli_result && $result->num_rows > 0;
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $stmt->close();
    $cache[$stopId] = $exists;
    return $exists;
}

/**
 * @return array{name:string,lat:float|null,lon:float|null}
 */
function cvCheckoutApiFetchProviderStopMeta(mysqli $connection, string $providerCode, int $stopExternalId): array
{
    $providerCode = strtolower(trim($providerCode));
    if ($providerCode === '' || $stopExternalId <= 0) {
        return ['name' => '', 'lat' => null, 'lon' => null];
    }

    $externalId = (string) $stopExternalId;
    $sql = "SELECT s.name, s.lat, s.lon
            FROM cv_provider_stops AS s
            INNER JOIN cv_providers AS p
                ON p.id_provider = s.id_provider
            WHERE p.code = ? AND s.external_id = ?
            ORDER BY s.id DESC
            LIMIT 1";
    $stmt = $connection->prepare($sql);
    if (!$stmt instanceof mysqli_stmt) {
        return ['name' => '', 'lat' => null, 'lon' => null];
    }

    $stmt->bind_param('ss', $providerCode, $externalId);
    if (!$stmt->execute()) {
        $stmt->close();
        return ['name' => '', 'lat' => null, 'lon' => null];
    }

    $result = $stmt->get_result();
    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $stmt->close();

    if (!is_array($row)) {
        return ['name' => '', 'lat' => null, 'lon' => null];
    }

    $name = trim((string) ($row['name'] ?? ''));
    $lat = isset($row['lat']) && is_numeric($row['lat']) ? (float) $row['lat'] : null;
    $lon = isset($row['lon']) && is_numeric($row['lon']) ? (float) $row['lon'] : null;
    return ['name' => $name, 'lat' => $lat, 'lon' => $lon];
}

function cvCheckoutApiFindAziendaIdByCode(mysqli $connection, string $providerCode): int
{
    $providerCode = strtolower(trim($providerCode));
    if ($providerCode === '') {
        return 0;
    }

    $sql = "SELECT id_az FROM aziende WHERE stato = 1 AND code = ? LIMIT 1";
    $stmt = $connection->prepare($sql);
    if (!$stmt instanceof mysqli_stmt) {
        return 0;
    }
    $stmt->bind_param('s', $providerCode);
    if (!$stmt->execute()) {
        $stmt->close();
        return 0;
    }

    $result = $stmt->get_result();
    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $stmt->close();

    return is_array($row) && isset($row['id_az']) ? (int) $row['id_az'] : 0;
}

/**
 * @return array<string,array<string,mixed>>
 */
function cvCheckoutApiFetchTableColumnsMeta(mysqli $connection, string $table): array
{
    static $cache = [];
    $cacheKey = strtolower(trim($table));
    if ($cacheKey === '') {
        return [];
    }
    if (array_key_exists($cacheKey, $cache)) {
        return is_array($cache[$cacheKey]) ? $cache[$cacheKey] : [];
    }

    $meta = [];
    $sql = "SHOW COLUMNS FROM `" . str_replace('`', '``', $table) . "`";
    $result = $connection->query($sql);
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            if (!is_array($row) || !isset($row['Field'])) {
                continue;
            }
            $field = (string) $row['Field'];
            if ($field === '') {
                continue;
            }
            $meta[$field] = $row;
        }
        $result->free();
    }

    $cache[$cacheKey] = $meta;
    return $meta;
}

/**
 * @return int|null
 */
function cvCheckoutApiFindLocalStopIdByName(mysqli $connection, int $idAz, string $name): ?int
{
    $name = trim($name);
    if ($name === '') {
        return null;
    }

    $sqlByAz = "SELECT id_sott FROM tratte_sottoc WHERE id_az = ? AND nome = ? ORDER BY id_sott ASC LIMIT 1";
    $stmtByAz = $connection->prepare($sqlByAz);
    if ($stmtByAz instanceof mysqli_stmt) {
        $stmtByAz->bind_param('is', $idAz, $name);
        if ($stmtByAz->execute()) {
            $result = $stmtByAz->get_result();
            $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
            if ($result instanceof mysqli_result) {
                $result->free();
            }
            $stmtByAz->close();
            if (is_array($row) && isset($row['id_sott'])) {
                $id = (int) $row['id_sott'];
                if ($id > 0) {
                    return $id;
                }
            }
        } else {
            $stmtByAz->close();
        }
    }

    $sqlAny = "SELECT id_sott FROM tratte_sottoc WHERE nome = ? ORDER BY id_sott ASC LIMIT 1";
    $stmtAny = $connection->prepare($sqlAny);
    if ($stmtAny instanceof mysqli_stmt) {
        $stmtAny->bind_param('s', $name);
        if ($stmtAny->execute()) {
            $result = $stmtAny->get_result();
            $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
            if ($result instanceof mysqli_result) {
                $result->free();
            }
            $stmtAny->close();
            if (is_array($row) && isset($row['id_sott'])) {
                $id = (int) $row['id_sott'];
                if ($id > 0) {
                    return $id;
                }
            }
        } else {
            $stmtAny->close();
        }
    }

    $normalized = mb_strtolower($name);
    $normalized = preg_replace('/\s+/', ' ', (string) $normalized);
    if (!is_string($normalized) || trim($normalized) === '') {
        return null;
    }
    $normalized = trim($normalized);

    $sqlNormByAz = "SELECT id_sott, nome
                    FROM tratte_sottoc
                    WHERE id_az = ?
                    ORDER BY id_sott ASC";
    $stmtNormByAz = $connection->prepare($sqlNormByAz);
    if ($stmtNormByAz instanceof mysqli_stmt) {
        $stmtNormByAz->bind_param('i', $idAz);
        if ($stmtNormByAz->execute()) {
            $result = $stmtNormByAz->get_result();
            if ($result instanceof mysqli_result) {
                while ($row = $result->fetch_assoc()) {
                    if (!is_array($row) || !isset($row['id_sott'], $row['nome'])) {
                        continue;
                    }
                    $candidate = mb_strtolower(trim((string) $row['nome']));
                    $candidate = preg_replace('/\s+/', ' ', (string) $candidate);
                    if (!is_string($candidate) || trim($candidate) === '') {
                        continue;
                    }
                    $candidate = trim($candidate);
                    if ($candidate === $normalized) {
                        $id = (int) $row['id_sott'];
                        $result->free();
                        $stmtNormByAz->close();
                        return $id > 0 ? $id : null;
                    }
                }
                $result->free();
            }
        }
        $stmtNormByAz->close();
    }

    $sqlNormAny = "SELECT id_sott, nome FROM tratte_sottoc ORDER BY id_sott ASC";
    $stmtNormAny = $connection->prepare($sqlNormAny);
    if ($stmtNormAny instanceof mysqli_stmt) {
        if ($stmtNormAny->execute()) {
            $result = $stmtNormAny->get_result();
            if ($result instanceof mysqli_result) {
                while ($row = $result->fetch_assoc()) {
                    if (!is_array($row) || !isset($row['id_sott'], $row['nome'])) {
                        continue;
                    }
                    $candidate = mb_strtolower(trim((string) $row['nome']));
                    $candidate = preg_replace('/\s+/', ' ', (string) $candidate);
                    if (!is_string($candidate) || trim($candidate) === '') {
                        continue;
                    }
                    $candidate = trim($candidate);
                    if ($candidate === $normalized) {
                        $id = (int) $row['id_sott'];
                        $result->free();
                        $stmtNormAny->close();
                        return $id > 0 ? $id : null;
                    }
                }
                $result->free();
            }
        }
        $stmtNormAny->close();
    }

    return null;
}

/**
 * @param array<string,mixed> $columnMeta
 * @return int|float|string|null
 */
function cvCheckoutApiDefaultValueForRequiredColumn(array $columnMeta)
{
    $type = strtolower(trim((string) ($columnMeta['Type'] ?? '')));
    if ($type === '') {
        return '';
    }

    if (str_starts_with($type, 'enum(')) {
        if (preg_match("/^enum\\('([^']*)'/i", $type, $matches) === 1 && isset($matches[1])) {
            return (string) $matches[1];
        }
        return '';
    }

    if (
        str_contains($type, 'int') ||
        str_contains($type, 'decimal') ||
        str_contains($type, 'float') ||
        str_contains($type, 'double') ||
        str_contains($type, 'bit')
    ) {
        return 0;
    }

    if (str_contains($type, 'datetime') || str_contains($type, 'timestamp')) {
        return '1970-01-01 00:00:00';
    }
    if (str_contains($type, 'date')) {
        return '1970-01-01';
    }
    if (str_contains($type, 'time')) {
        return '00:00:00';
    }
    if (str_contains($type, 'year')) {
        return '1970';
    }

    return '-';
}

/**
 * @return int|null
 */
function cvCheckoutApiEnsureLocalStopId(
    mysqli $connection,
    int $idAz,
    int $stopId,
    string $providerCode,
    string $fallbackName = '',
    ?string &$errorOut = null
): ?int {
    $errorOut = null;
    if ($stopId <= 0 || $idAz <= 0) {
        $errorOut = 'invalid_stop_or_provider';
        return null;
    }

    if (cvCheckoutApiExistsStopId($connection, $stopId)) {
        return $stopId;
    }

    $meta = cvCheckoutApiFetchProviderStopMeta($connection, $providerCode, $stopId);
    $name = trim((string) ($meta['name'] ?? ''));
    if ($name === '') {
        $name = trim($fallbackName);
    }
    if ($name === '') {
        $name = 'Fermata ' . $stopId;
    }
    if (mb_strlen($name) > 120) {
        $name = mb_substr($name, 0, 120);
    }

    $columnsMeta = cvCheckoutApiFetchTableColumnsMeta($connection, 'tratte_sottoc');
    if (empty($columnsMeta)) {
        $errorOut = 'cannot_describe_tratte_sottoc';
        return cvCheckoutApiFindLocalStopIdByName($connection, $idAz, $name);
    }

    $candidateData = [
        'id_sott' => $stopId,
        'id_az' => $idAz,
        'nome' => $name,
        'descsott' => 'sync auto checkout provider ' . $providerCode,
        'lat' => (isset($meta['lat']) && is_float($meta['lat'])) ? $meta['lat'] : null,
        'lon' => (isset($meta['lon']) && is_float($meta['lon'])) ? $meta['lon'] : null,
        'stato' => 1,
        'localita' => 0,
        'sos_da' => '1970-01-01 00:00:00',
        'sos_a' => '1970-01-01 00:00:00',
        'indirizzo' => null,
        'comune' => null,
        'provincia' => null,
        'paese' => 'Italy',
        'country_code' => 'IT',
        'timezone' => 'Europe/Rome',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ];

    $insertData = [];
    foreach ($candidateData as $column => $value) {
        if (isset($columnsMeta[$column])) {
            $insertData[$column] = $value;
        }
    }

    foreach ($columnsMeta as $column => $columnMeta) {
        if (isset($insertData[$column])) {
            continue;
        }
        $extra = strtolower((string) ($columnMeta['Extra'] ?? ''));
        if (str_contains($extra, 'auto_increment')) {
            continue;
        }
        $allowsNull = strtoupper((string) ($columnMeta['Null'] ?? 'YES')) === 'YES';
        $hasDefault = array_key_exists('Default', $columnMeta) && $columnMeta['Default'] !== null;
        if ($allowsNull || $hasDefault) {
            continue;
        }
        $insertData[$column] = cvCheckoutApiDefaultValueForRequiredColumn($columnMeta);
    }

    if (empty($insertData)) {
        $errorOut = 'no_insertable_columns';
        return cvCheckoutApiFindLocalStopIdByName($connection, $idAz, $name);
    }

    $columnsSql = [];
    $valuesSql = [];
    foreach ($insertData as $column => $value) {
        $columnsSql[] = '`' . str_replace('`', '``', $column) . '`';
        if ($value === null) {
            $valuesSql[] = 'NULL';
        } elseif (is_int($value) || is_float($value)) {
            $valuesSql[] = (string) $value;
        } else {
            $valuesSql[] = "'" . $connection->real_escape_string((string) $value) . "'";
        }
    }
    $insertSql = "INSERT INTO tratte_sottoc (" . implode(', ', $columnsSql) . ") VALUES (" . implode(', ', $valuesSql) . ")";

    if (!$connection->query($insertSql) && !cvCheckoutApiExistsStopId($connection, $stopId)) {
        $firstError = $connection->error;

        $retryData = $insertData;
        unset($retryData['id_sott']);
        if (!empty($retryData)) {
            $retryCols = [];
            $retryVals = [];
            foreach ($retryData as $column => $value) {
                $retryCols[] = '`' . str_replace('`', '``', $column) . '`';
                if ($value === null) {
                    $retryVals[] = 'NULL';
                } elseif (is_int($value) || is_float($value)) {
                    $retryVals[] = (string) $value;
                } else {
                    $retryVals[] = "'" . $connection->real_escape_string((string) $value) . "'";
                }
            }
            if (!empty($retryCols) && !empty($retryVals)) {
                $retrySql = "INSERT INTO tratte_sottoc (" . implode(', ', $retryCols) . ") VALUES (" . implode(', ', $retryVals) . ")";
                $connection->query($retrySql);
            }
        }

        if (!cvCheckoutApiExistsStopId($connection, $stopId)) {
            $errorOut = $firstError . ' | retry=' . $connection->error;
            $matchedByName = cvCheckoutApiFindLocalStopIdByName($connection, $idAz, $name);
            if (is_int($matchedByName) && $matchedByName > 0) {
                return $matchedByName;
            }
            return null;
        }
    }

    return cvCheckoutApiExistsStopId($connection, $stopId) ? $stopId : cvCheckoutApiFindLocalStopIdByName($connection, $idAz, $name);
}

/**
 * @return array{from:int,to:int}
 */
function cvCheckoutApiResolveTripEdgeStops(mysqli $connection, int $idAz, int $tripId): array
{
    if ($idAz <= 0 || $tripId <= 0) {
        return ['from' => 0, 'to' => 0];
    }

    $sql = "SELECT id_sott
            FROM corse_fermate
            WHERE id_az = ? AND id_corsa = ? AND stato = 1
            ORDER BY ordine ASC, id_corse_f ASC";
    $stmt = $connection->prepare($sql);
    if (!$stmt instanceof mysqli_stmt) {
        return ['from' => 0, 'to' => 0];
    }
    $stmt->bind_param('ii', $idAz, $tripId);
    if (!$stmt->execute()) {
        $stmt->close();
        return ['from' => 0, 'to' => 0];
    }

    $result = $stmt->get_result();
    $from = 0;
    $to = 0;
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            if (!is_array($row) || !isset($row['id_sott'])) {
                continue;
            }
            $stopId = (int) $row['id_sott'];
            if ($stopId <= 0) {
                continue;
            }
            if ($from <= 0) {
                $from = $stopId;
            }
            $to = $stopId;
        }
        $result->free();
    }
    $stmt->close();
    return ['from' => $from, 'to' => $to];
}

/**
 * @param array<string,mixed> $snapshot
 */
function cvCheckoutApiInsertLocalTicketSnapshot(mysqli $connection, int $ticketId, array $snapshot): void
{
    $snapshotJson = cvCheckoutApiJsonEncode($snapshot);
    if ($ticketId <= 0 || $snapshotJson === null) {
        return;
    }

    $sql = "INSERT INTO biglietti_reg (id_bg, snapshot_json) VALUES (?, ?)";
    $stmt = $connection->prepare($sql);
    if (!$stmt instanceof mysqli_stmt) {
        return;
    }

    $stmt->bind_param('is', $ticketId, $snapshotJson);
    $stmt->execute();
    $stmt->close();
}

/**
 * @param array<string,mixed> $payload
 */
function cvCheckoutApiInsertLocalTicketLog(mysqli $connection, int $ticketId, string $operation, array $payload): void
{
    $payloadJson = cvCheckoutApiJsonEncode($payload);
    if ($ticketId <= 0 || $payloadJson === null) {
        return;
    }

    $sql = "INSERT INTO biglietti_log (id_bg, id_utop, operazione, payload) VALUES (?, 0, ?, ?)";
    $stmt = $connection->prepare($sql);
    if (!$stmt instanceof mysqli_stmt) {
        return;
    }

    $stmt->bind_param('iss', $ticketId, $operation, $payloadJson);
    $stmt->execute();
    $stmt->close();
}

/**
 * @param array<string,mixed> $order
 * @param array<int,array<string,mixed>> $finalizedLegs
 * @return array<int,array<string,mixed>>
 */
function cvCheckoutApiPersistMarketplaceTickets(
    mysqli $connection,
    array $order,
    array $finalizedLegs,
    int $paymentType,
    string $paymentRef
): array {
    $context = cvCheckoutApiOrderSearchContext($order);
    $contact = isset($context['contact']) && is_array($context['contact']) ? $context['contact'] : [];
    $query = isset($context['query']) && is_array($context['query']) ? $context['query'] : [];
    $promotionContext = isset($context['promotion']) && is_array($context['promotion']) ? $context['promotion'] : [];
    $promotionId = !empty($promotionContext['applied']) && isset($promotionContext['promotion_id'])
        ? max(0, (int) $promotionContext['promotion_id'])
        : 0;
    $adultCount = isset($query['ad']) && is_numeric($query['ad']) ? max(0, (int) $query['ad']) : 1;
    $childCount = isset($query['bam']) && is_numeric($query['bam']) ? (int) $query['bam'] : 0;

    $idVg = cvCheckoutApiEnsureLocalContact($connection, $contact);
    if ($idVg <= 0) {
        throw new RuntimeException('Impossibile creare/aggiornare il referente locale del checkout.');
    }
    $passengerTempMap = [];
    $persisted = [];
    $orderCode = trim((string) ($order['order_code'] ?? ''));

    foreach ($finalizedLegs as $finalizedLeg) {
        if (!is_array($finalizedLeg)) {
            continue;
        }

        $leg = isset($finalizedLeg['leg']) && is_array($finalizedLeg['leg']) ? $finalizedLeg['leg'] : [];
        $tickets = isset($finalizedLeg['tickets']) && is_array($finalizedLeg['tickets']) ? $finalizedLeg['tickets'] : [];
        if (empty($leg) || empty($tickets)) {
            continue;
        }

        $idAz = isset($leg['id_az']) ? (int) $leg['id_az'] : 0;
        $providerCode = strtolower(trim((string) ($leg['provider_code'] ?? '')));
        if ($idAz <= 0 && $providerCode !== '') {
            $idAz = cvCheckoutApiFindAziendaIdByCode($connection, $providerCode);
        }
        if ($idAz <= 0) {
            throw new RuntimeException('Provider senza id_az valido in persistenza checkout (provider=' . $providerCode . ').');
        }
        $providerShopId = trim((string) ($leg['provider_shop_id'] ?? ''));
        $legPassengers = cvCheckoutApiJsonDecodeArray(isset($leg['passengers_json']) ? (string) $leg['passengers_json'] : '');
        $passengerList = isset($legPassengers[0]) || empty($legPassengers) ? $legPassengers : [];
        $ridMap = cvCheckoutApiBuildRidMap($passengerList, $adultCount, $childCount);

        $ticketCount = count($tickets);
        $legCommission = round((float) ($leg['commission_amount'] ?? 0.0), 2);
        $perTicketCommission = $ticketCount > 0 ? round($legCommission / $ticketCount, 2) : 0.0;
        $legClientAmount = round(max(0.0, (float) ($leg['amount'] ?? 0.0)), 2);
        $perTicketClientAmount = $ticketCount > 0 ? round($legClientAmount / $ticketCount, 2) : 0.0;

        foreach ($tickets as $ticketIndex => $ticket) {
            if (!is_array($ticket)) {
                continue;
            }

            $code = trim((string) ($ticket['code'] ?? ''));
            $existingId = cvCheckoutApiFindExistingLocalTicketId($connection, $idAz, $providerShopId, $code);
            if ($existingId > 0) {
                $persisted[] = [
                    'id_bg' => $existingId,
                    'provider_code' => $providerCode,
                    'code' => $code,
                ];
                continue;
            }

            $passenger = isset($passengerList[$ticketIndex]) && is_array($passengerList[$ticketIndex]) ? $passengerList[$ticketIndex] : [];
            if (empty($passenger) && isset($ticket['passenger_name'])) {
                $passenger = ['full_name' => (string) $ticket['passenger_name']];
            }

            $passengerKey = strtolower(trim((string) ($passenger['full_name'] ?? ''))) . '|' . trim((string) ($passenger['birth_date'] ?? ''));
            if (!isset($passengerTempMap[$passengerKey])) {
                $passengerTempMap[$passengerKey] = cvCheckoutApiInsertLocalPassengerTemp($connection, $passenger, $contact);
                if ((int) $passengerTempMap[$passengerKey] <= 0) {
                    throw new RuntimeException('Impossibile salvare il passeggero locale del checkout.');
                }
            }
            $idVgt = (int) ($passengerTempMap[$passengerKey] ?? 0);
            $rid = isset($ridMap[$ticketIndex]) ? (int) $ridMap[$ticketIndex] : 0;

            $lineIdCandidate = isset($ticket['line_id']) ? (int) $ticket['line_id'] : (isset($leg['id_linea']) ? (int) $leg['id_linea'] : 0);
            $tripIdCandidate = isset($ticket['trip_id']) ? (int) $ticket['trip_id'] : (isset($leg['id_corsa']) ? (int) $leg['id_corsa'] : 0);
            $lineId = cvCheckoutApiExistsLineId($connection, $lineIdCandidate) ? $lineIdCandidate : null;
            $tripId = cvCheckoutApiExistsTripId($connection, $tripIdCandidate) ? $tripIdCandidate : null;
            $fromStopIdRaw = isset($leg['id_sott1']) ? (int) $leg['id_sott1'] : 0;
            if ($fromStopIdRaw <= 0) {
                $fromStopIdRaw = isset($ticket['from_stop_id']) ? (int) $ticket['from_stop_id'] : 0;
            }
            $toStopIdRaw = isset($leg['id_sott2']) ? (int) $leg['id_sott2'] : 0;
            if ($toStopIdRaw <= 0) {
                $toStopIdRaw = isset($ticket['to_stop_id']) ? (int) $ticket['to_stop_id'] : 0;
            }
            $fromStopName = trim((string) ($ticket['from_stop_name'] ?? ($leg['from_stop_name'] ?? '')));
            $toStopName = trim((string) ($ticket['to_stop_name'] ?? ($leg['to_stop_name'] ?? '')));
            $fromStopMapError = null;
            $toStopMapError = null;
            $fromStopId = cvCheckoutApiEnsureLocalStopId($connection, $idAz, $fromStopIdRaw, $providerCode, $fromStopName, $fromStopMapError);
            $toStopId = cvCheckoutApiEnsureLocalStopId($connection, $idAz, $toStopIdRaw, $providerCode, $toStopName, $toStopMapError);
            if (($fromStopId === null || $toStopId === null) && $tripIdCandidate > 0) {
                $edgeStops = cvCheckoutApiResolveTripEdgeStops($connection, $idAz, $tripIdCandidate);
                if ($fromStopId === null && (int) ($edgeStops['from'] ?? 0) > 0) {
                    $fallbackFromError = null;
                    $fromStopId = cvCheckoutApiEnsureLocalStopId(
                        $connection,
                        $idAz,
                        (int) $edgeStops['from'],
                        $providerCode,
                        $fromStopName,
                        $fallbackFromError
                    );
                    if ($fromStopId === null && $fallbackFromError !== null) {
                        $fromStopMapError = (string) $fromStopMapError . ' | trip_edge=' . $fallbackFromError;
                    }
                }
                if ($toStopId === null && (int) ($edgeStops['to'] ?? 0) > 0) {
                    $fallbackToError = null;
                    $toStopId = cvCheckoutApiEnsureLocalStopId(
                        $connection,
                        $idAz,
                        (int) $edgeStops['to'],
                        $providerCode,
                        $toStopName,
                        $fallbackToError
                    );
                    if ($toStopId === null && $fallbackToError !== null) {
                        $toStopMapError = (string) $toStopMapError . ' | trip_edge=' . $fallbackToError;
                    }
                }
            }
            if ($fromStopId === null || $toStopId === null) {
                throw new RuntimeException(
                    'Impossibile mappare fermate locali marketplace (provider=' . $providerCode
                    . ', id_az=' . $idAz
                    . ', trip=' . $tripIdCandidate
                    . ', from=' . $fromStopIdRaw . ', to=' . $toStopIdRaw
                    . ', from_err=' . (string) $fromStopMapError
                    . ', to_err=' . (string) $toStopMapError . ').'
                );
            }
            $price = $perTicketClientAmount;
            if ($ticketIndex === ($ticketCount - 1)) {
                $price = round($legClientAmount - ($perTicketClientAmount * max(0, $ticketCount - 1)), 2);
            }
            if ($price < 0.0) {
                $price = 0.0;
            }
            $departureAt = trim((string) ($ticket['departure_at'] ?? ($leg['departure_at'] ?? '')));
            $arrivalAt = trim((string) ($ticket['arrival_at'] ?? ($leg['arrival_at'] ?? '')));
            $changeCode = trim((string) ($ticket['change_code'] ?? '0'));
            if ($changeCode === '') {
                $changeCode = '0';
            }
            $changesUsed = isset($ticket['changes_used']) ? max(0, (int) $ticket['changes_used']) : 0;
            $seat = isset($ticket['seat']) ? (int) $ticket['seat'] : 0;
            $busNumber = isset($ticket['bus']) ? (int) $ticket['bus'] : 0;
            $note = 'order:' . $orderCode . ';provider:' . $providerCode;
            $providerPaymentRef = trim((string) ($leg['provider_transaction_ref'] ?? ''));
            if ($providerPaymentRef === '') {
                $providerPaymentRef = $paymentRef;
            }

            $commissionValue = $perTicketCommission;
            if ($ticketIndex === ($ticketCount - 1)) {
                $commissionValue = round($legCommission - ($perTicketCommission * max(0, $ticketCount - 1)), 2);
            }

            $codeEsc = $connection->real_escape_string($code);
            $changeCodeEsc = $connection->real_escape_string($changeCode);
            $providerShopIdEsc = $connection->real_escape_string($providerShopId);
            $noteEsc = $connection->real_escape_string($note);
            $paymentRefEsc = $connection->real_escape_string($providerPaymentRef);
            $departureAtEsc = $connection->real_escape_string($departureAt);
            $arrivalAtEsc = $connection->real_escape_string($arrivalAt);
            $lineIdSql = $lineId !== null ? (string) $lineId : 'NULL';
            $tripIdSql = $tripId !== null ? (string) $tripId : 'NULL';
            $busSql = $busNumber > 0 ? (string) $busNumber : '0';
            $promotionIdSql = (string) $promotionId;

            if ($changeCode !== '0' && $changesUsed > 0) {
                $connection->query(
                    "UPDATE biglietti
                     SET camb = GREATEST(camb, $changesUsed), stato = 0
                     WHERE id_az = $idAz AND codice = '$changeCodeEsc'"
                );
            }

            $insertSql = "INSERT INTO biglietti
                (id_az, id_ut, id_linea, id_corsa, id_r, id_sott1, id_sott2, prezzo, pen, camb, sospeso, rid,
                 pacco, pacco_a, prz_pacco, prz_pacco_a, prenotaz, codice, codice_camb, transaction_id, posto, prz_posto,
                 prz_comm, note, pos, id_vg, id_vgt, id_cod, id_codabbcarn_u, stato, rimborsato, controllato, pagato,
                 stampato, mz_dt, type, app, txn_id, attesa, data, data2, acquistato, data_sos, data_attesa, visto)
                VALUES
                ($idAz, 0, $lineIdSql, $tripIdSql, 0, $fromStopId, $toStopId, '$price', 0.00, $changesUsed, 0, $rid,
                 0, 0, 0.00, 0.00, 0, '$codeEsc', '$changeCodeEsc', '$providerShopIdEsc', $seat, 0.00,
                 '$commissionValue', '$noteEsc', 0, $idVg, $idVgt, $promotionIdSql, 0, 1, 0, 0, 1,
                 0, $busSql, $paymentType, 1, '$paymentRefEsc', 0, '$departureAtEsc', '$arrivalAtEsc', NOW(), '1970-01-01 00:00:00', '1970-01-01 00:00:00', '$departureAtEsc')";
            if (!$connection->query($insertSql)) {
                throw new RuntimeException('Impossibile salvare il biglietto locale marketplace: ' . $connection->error);
            }

            $idBg = (int) $connection->insert_id;

            $snapshot = [
                'id_bg' => $idBg,
                'order_code' => $orderCode,
                'provider_code' => $providerCode,
                'provider_shop_id' => $providerShopId,
                'provider_ticket' => $ticket,
                'leg' => $leg,
            ];
            cvCheckoutApiInsertLocalTicketSnapshot($connection, $idBg, $snapshot);
            cvCheckoutApiInsertLocalTicketLog($connection, $idBg, 'marketplace_paid_import', $snapshot);

            $persisted[] = [
                'id_bg' => $idBg,
                'provider_code' => $providerCode,
                'code' => $code,
            ];
        }
    }

    return $persisted;
}

/**
 * @return array{path:string,name:string}|null
 */
function cvCheckoutApiDownloadPdfAttachment(string $url, string $filename): ?array
{
    $url = trim($url);
    if ($url === '') {
        return null;
    }

    $ch = curl_init($url);
    if ($ch === false) {
        return null;
    }

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!is_string($raw) || $status < 200 || $status >= 300 || $raw === '') {
        return null;
    }

    $tmp = tempnam(sys_get_temp_dir(), 'cvpdf_');
    if (!is_string($tmp) || $tmp === '') {
        return null;
    }

    $pdfPath = $tmp . '.pdf';
    @rename($tmp, $pdfPath);
    if (@file_put_contents($pdfPath, $raw) === false) {
        @unlink($pdfPath);
        return null;
    }

    return ['path' => $pdfPath, 'name' => $filename];
}

/**
 * @param array<string,mixed> $ticket
 * @return array{path:string,name:string}|null
 */
function cvCheckoutApiGenerateLocalTicketPdfAttachment(
    array $ticket,
    string $providerCode
): ?array {
    $code = trim((string) ($ticket['code'] ?? ''));
    if ($code === '') {
        return null;
    }

    $baseUrl = rtrim(cvCheckoutApiBaseUrl(), '/');
    $localPdfUrl = $baseUrl . '/auth/api.php?action=ticket_pdf_download&public=1&ticket_code=' . rawurlencode($code);
    $filename = 'biglietto_' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $code) . '.pdf';

    $attachment = cvCheckoutApiDownloadPdfAttachment($localPdfUrl, $filename);
    if (is_array($attachment)) {
        return $attachment;
    }

    cvCheckoutApiLog('local_pdf_download_failed', [
        'provider_code' => $providerCode,
        'code' => $code,
        'url' => $localPdfUrl,
    ]);

    return null;
}

/**
 * @param array<int,array<string,mixed>> $finalizedLegs
 */
function cvCheckoutApiSendOrderConfirmation(mysqli $connection, array $order, array $finalizedLegs): bool
{
    $context = cvCheckoutApiOrderSearchContext($order);
    $contact = isset($context['contact']) && is_array($context['contact']) ? $context['contact'] : [];
    $toEmail = strtolower(trim((string) ($contact['email'] ?? '')));
    if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $toName = trim((string) ($contact['full_name'] ?? 'Cliente'));
    $orderCode = trim((string) ($order['order_code'] ?? ''));
    $rowsHtml = '';
    $rowsText = [];
    $attachments = [];
    foreach ($finalizedLegs as $finalizedLeg) {
        if (!is_array($finalizedLeg)) {
            continue;
        }
        $leg = isset($finalizedLeg['leg']) && is_array($finalizedLeg['leg']) ? $finalizedLeg['leg'] : [];
        $tickets = isset($finalizedLeg['tickets']) && is_array($finalizedLeg['tickets']) ? $finalizedLeg['tickets'] : [];
        $providerCode = strtoupper(trim((string) ($leg['provider_code'] ?? '')));
        $providerCodeKey = strtolower($providerCode);

        foreach ($tickets as $index => $ticket) {
            if (!is_array($ticket)) {
                continue;
            }
            $code = trim((string) ($ticket['code'] ?? ''));
            $passengerName = trim((string) ($ticket['passenger_name'] ?? 'Passeggero'));
            $fromName = trim((string) ($ticket['from_name'] ?? ''));
            $toNameTicket = trim((string) ($ticket['to_name'] ?? ''));
            $departureAt = trim((string) ($ticket['departure_at'] ?? ''));
            $price = round((float) ($ticket['price'] ?? 0.0), 2);
            $pdfUrl = trim((string) ($ticket['pdf_url'] ?? ''));

            $rowsHtml .= '<tr>'
                . '<td style="padding:10px;border-bottom:1px solid #e9eef6;">' . htmlspecialchars($providerCode, ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td style="padding:10px;border-bottom:1px solid #e9eef6;">' . htmlspecialchars($passengerName, ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td style="padding:10px;border-bottom:1px solid #e9eef6;">' . htmlspecialchars($fromName . ' → ' . $toNameTicket, ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td style="padding:10px;border-bottom:1px solid #e9eef6;">' . htmlspecialchars($departureAt, ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td style="padding:10px;border-bottom:1px solid #e9eef6;">' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td style="padding:10px;border-bottom:1px solid #e9eef6;">€ ' . number_format($price, 2, ',', '.') . '</td>'
                . '</tr>';

            $rowsText[] = $providerCode . ' | ' . $passengerName . ' | ' . $fromName . ' -> ' . $toNameTicket . ' | ' . $departureAt . ' | ' . $code . ' | € ' . number_format($price, 2, ',', '.');

            $attachment = cvCheckoutApiGenerateLocalTicketPdfAttachment(
                $ticket,
                $providerCodeKey
            );
            if (is_array($attachment)) {
                $attachments[] = $attachment;
                continue;
            }

            if ($pdfUrl !== '') {
                $fallbackAttachment = cvCheckoutApiDownloadPdfAttachment($pdfUrl, 'biglietto_' . ($code !== '' ? $code : ($providerCode . '_' . ($index + 1))) . '.pdf');
                if (is_array($fallbackAttachment)) {
                    $attachments[] = $fallbackAttachment;
                }
            }
        }
    }

    $baseUrl = cvCheckoutApiBaseUrl();
    $logoUrl = rtrim($baseUrl, '/') . '/assets/images/logo.svg';
    $safeLogoUrl = htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8');
    $safeOrderCode = htmlspecialchars($orderCode, ENT_QUOTES, 'UTF-8');
    $safeToName = htmlspecialchars($toName, ENT_QUOTES, 'UTF-8');
    $attachmentsCount = count($attachments);

    $htmlBody = '<html><body style="margin:0;padding:0;background:#f4f7fb;font-family:Arial,sans-serif;color:#1f2a44;">'
        . '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background:#f4f7fb;padding:24px 0;">'
        . '<tr><td align="center">'
        . '<table role="presentation" cellpadding="0" cellspacing="0" width="680" style="max-width:680px;width:100%;background:#ffffff;border:1px solid #dce6f5;border-radius:14px;overflow:hidden;">'
        . '<tr><td style="padding:18px 24px;border-bottom:1px solid #e8eef7;background:#ffffff;">'
        . '<img src="' . $safeLogoUrl . '" alt="cercaviaggio" style="height:38px;display:block;">'
        . '</td></tr>'
        . '<tr><td style="padding:22px 24px;">'
        . '<h2 style="margin:0 0 10px 0;font-size:24px;line-height:1.25;color:#15345c;">Prenotazione confermata</h2>'
        . '<p style="margin:0 0 8px 0;">Ciao <strong>' . $safeToName . '</strong>,</p>'
        . '<p style="margin:0 0 16px 0;">il tuo ordine <strong>' . $safeOrderCode . '</strong> è stato confermato con successo.</p>'
        . '<div style="margin:0 0 16px 0;padding:12px 14px;background:#f0f6ff;border:1px solid #d5e3f8;border-radius:10px;font-size:14px;">'
        . '<strong>Ordine:</strong> ' . $safeOrderCode . '<br>'
        . '<strong>Biglietti allegati:</strong> ' . $attachmentsCount
        . '</div>'
        . '<table role="presentation" style="width:100%;border-collapse:collapse;font-size:14px;">'
        . '<thead><tr>'
        . '<th align="left" style="padding:10px;border-bottom:2px solid #dce6f5;color:#27466f;">Vettore</th>'
        . '<th align="left" style="padding:10px;border-bottom:2px solid #dce6f5;color:#27466f;">Passeggero</th>'
        . '<th align="left" style="padding:10px;border-bottom:2px solid #dce6f5;color:#27466f;">Tratta</th>'
        . '<th align="left" style="padding:10px;border-bottom:2px solid #dce6f5;color:#27466f;">Partenza</th>'
        . '<th align="left" style="padding:10px;border-bottom:2px solid #dce6f5;color:#27466f;">Codice</th>'
        . '<th align="left" style="padding:10px;border-bottom:2px solid #dce6f5;color:#27466f;">Prezzo</th>'
        . '</tr></thead><tbody>' . $rowsHtml . '</tbody></table>'
        . '<p style="margin:16px 0 0 0;font-size:14px;">In allegato trovi i PDF dei biglietti (QR incluso).</p>'
        . '<p style="margin:20px 0 0 0;">Grazie,<br><strong>cercaviaggio</strong></p>'
        . '</td></tr>'
        . '</table>'
        . '</td></tr>'
        . '</table>'
        . '</body></html>';

    $plainBody = "Prenotazione confermata\nOrdine: " . $orderCode
        . "\nBiglietti allegati: " . $attachmentsCount
        . "\n\n" . implode("\n", $rowsText);
    $subject = 'Prenotazione confermata ' . $orderCode;

    $sent = cvCheckoutApiSendMail($connection, $toEmail, $toName, $subject, $htmlBody, $plainBody, $attachments);
    foreach ($attachments as $attachment) {
        if (isset($attachment['path']) && is_string($attachment['path']) && is_file($attachment['path'])) {
            @unlink($attachment['path']);
        }
    }

    return $sent;
}

/**
 * @param array<string,array<string,string>> $providers
 * @param array<string,mixed> $order
 * @return array<string,mixed>
 */
function cvCheckoutApiFinalizePaidOrder(
    mysqli $connection,
    array $providers,
    array $order,
    string $gateway,
    string $transactionRef,
    string $providerRef,
    array $providerTransactionRefs,
    ?string $rawRequest,
    ?string $rawResponse
): array {
    $orderId = cvCheckoutApiOrderId($order);
    $orderCode = trim((string) ($order['order_code'] ?? ''));
    if ($orderId <= 0 || $orderCode === '') {
        return ['ok' => false, 'message' => 'Ordine checkout non valido.'];
    }

    $context = cvCheckoutApiOrderSearchContext($order);
    $contact = isset($context['contact']) && is_array($context['contact']) ? $context['contact'] : [];
    $normalizedLegs = isset($context['normalized_legs']) && is_array($context['normalized_legs']) ? $context['normalized_legs'] : [];
    $baggageByLeg = isset($context['baggage_by_leg']) && is_array($context['baggage_by_leg']) ? $context['baggage_by_leg'] : [];
    $codiceRecupero = trim((string) ($context['codice'] ?? ''));
    $codiceCamb = trim((string) ($context['codice_camb'] ?? ''));

    $finalizedLegs = [];
    foreach ((array) ($order['legs'] ?? []) as $orderLeg) {
        if (!is_array($orderLeg)) {
            continue;
        }

        $providerCode = strtolower(trim((string) ($orderLeg['provider_code'] ?? '')));
        if ($providerCode === '' || !isset($providers[$providerCode])) {
            return ['ok' => false, 'message' => 'Provider non configurato in finalizzazione.', 'details' => ['provider_code' => $providerCode]];
        }

        $legRaw = cvCheckoutApiOrderLegRawResponse($orderLeg);
        $existingFinalized = [];
        if (isset($legRaw['finalize']) && is_array($legRaw['finalize'])) {
            $existingFinalized = $legRaw['finalize'];
        } elseif (isset($legRaw['data']) && is_array($legRaw['data']) && isset($legRaw['data']['tickets'])) {
            $existingFinalized = $legRaw;
        }

        $bookingBody = [];
        if (
            strtolower(trim((string) ($orderLeg['status'] ?? ''))) === 'paid' &&
            isset($existingFinalized['data']) &&
            is_array($existingFinalized['data']) &&
            !empty($existingFinalized['data']['tickets'])
        ) {
            $bookingBody = $existingFinalized;
        } else {
            $reserveBody = [];
            if (isset($legRaw['reserve']) && is_array($legRaw['reserve'])) {
                $reserveBody = $legRaw['reserve'];
            } elseif (isset($legRaw['body']) && is_array($legRaw['body'])) {
                $reserveBody = $legRaw['body'];
            }
            $reserveData = isset($reserveBody['data']) && is_array($reserveBody['data']) ? $reserveBody['data'] : [];

            $quoteToken = trim((string) ($legRaw['quote_token'] ?? ''));
            if ($quoteToken === '') {
                foreach ($normalizedLegs as $normalizedLeg) {
                    if (!is_array($normalizedLeg)) {
                        continue;
                    }
                    if (
                        strtolower(trim((string) ($normalizedLeg['provider_code'] ?? ''))) === $providerCode &&
                        trim((string) ($normalizedLeg['direction'] ?? '')) === trim((string) ($orderLeg['direction'] ?? '')) &&
                        (int) ($normalizedLeg['leg_index'] ?? 0) === (int) ($orderLeg['leg_index'] ?? 0)
                    ) {
                        $quoteToken = trim((string) ($normalizedLeg['quote_token'] ?? ''));
                        break;
                    }
                }
            }

            if ($quoteToken === '') {
                return [
                    'ok' => false,
                    'message' => 'Quote token mancante in finalizzazione.',
                    'details' => ['provider_code' => $providerCode, 'leg_index' => $orderLeg['leg_index'] ?? null],
                ];
            }

            $providerShopId = trim((string) ($orderLeg['provider_shop_id'] ?? ($reserveData['shop_id'] ?? '')));
            $providerTxnRef = trim((string) ($providerTransactionRefs[$providerCode] ?? ''));
            if ($providerTxnRef === '') {
                $providerTxnRef = $transactionRef;
            }
            $bookPayload = [
                'quote_token' => $quoteToken,
                'shop_id' => $providerShopId,
                'email' => trim((string) ($contact['email'] ?? '')),
                'nome' => trim((string) ($contact['full_name'] ?? '')),
                'txn_id' => $providerTxnRef,
                'payment_txn_id' => $providerTxnRef,
            ];
            $baggageKey = cvCheckoutApiLegMapKey(
                (string) ($orderLeg['direction'] ?? 'outbound'),
                (int) ($orderLeg['leg_index'] ?? 1),
                $providerCode
            );
            if ($baggageKey !== '' && isset($baggageByLeg[$baggageKey]) && is_array($baggageByLeg[$baggageKey])) {
                $bookPayload['baggage'] = cvCheckoutApiNormalizeBaggageItem($baggageByLeg[$baggageKey]);
            }
            if ($codiceRecupero !== '') {
                $bookPayload['codice'] = $codiceRecupero;
            }
            if ($codiceCamb !== '') {
                $bookPayload['codice_camb'] = $codiceCamb;
            }

            $bookIdempotency = substr(
                'cv-finalize-' . $gateway . '-' . $transactionRef . '-' . $providerCode . '-' . (string) ($orderLeg['direction'] ?? 'outbound') . '-' . (string) ($orderLeg['leg_index'] ?? '1'),
                0,
                120
            );
            $bookResponse = cvCheckoutApiFinalizeLegBooking($providers[$providerCode], $bookPayload, $bookIdempotency);
            if (!(bool) ($bookResponse['ok'] ?? false)) {
                $providerBody = is_array($bookResponse['body'] ?? null) ? $bookResponse['body'] : [];
                $providerData = isset($providerBody['data']) && is_array($providerBody['data']) ? $providerBody['data'] : [];
                $providerMessage = trim((string) ($bookResponse['message'] ?? ''));
                if ($providerMessage === '' && isset($providerData['message'])) {
                    $providerMessage = trim((string) $providerData['message']);
                }
                if ($providerMessage === '') {
                    $providerMessage = 'errore provider';
                }
                cvCheckoutApiLog('provider_finalize_failed', [
                    'order_code' => $orderCode,
                    'provider_code' => $providerCode,
                    'book_payload' => $bookPayload,
                    'provider_response' => $providerBody,
                    'provider_message' => $providerMessage,
                ]);
                return [
                    'ok' => false,
                    'message' => 'Finalizzazione provider fallita su ' . $providerCode . ': ' . $providerMessage,
                    'details' => [
                        'error' => $providerMessage,
                        'provider_code' => $providerCode,
                        'provider_shop_id' => $providerShopId,
                        'provider_response' => $providerBody,
                        'provider_message' => $providerMessage,
                    ],
                ];
            }

            $bookingBody = is_array($bookResponse['body']) ? $bookResponse['body'] : [];
            $bookingData = isset($bookingBody['data']) && is_array($bookingBody['data']) ? $bookingBody['data'] : [];
            $providerFinalizeMeta = isset($bookingData['provider_finalize']) && is_array($bookingData['provider_finalize'])
                ? $bookingData['provider_finalize']
                : [];
            if (!empty($providerFinalizeMeta['fallback_local'])) {
                cvCheckoutApiLog('provider_finalize_fallback_local', [
                    'provider_code' => $providerCode,
                    'order_code' => $orderCode,
                    'shop_id' => (string) ($orderLeg['provider_shop_id'] ?? ''),
                    'message' => (string) ($providerFinalizeMeta['message'] ?? 'fallback local usato'),
                    'provider_finalize' => $providerFinalizeMeta,
                ]);
            }
            if (empty($bookingData['tickets']) || !is_array($bookingData['tickets'])) {
                return [
                    'ok' => false,
                    'message' => 'Provider senza tickets dopo finalizzazione.',
                    'details' => ['provider_code' => $providerCode, 'provider_response' => $bookingBody],
                ];
            }
        }

        $bookingData = isset($bookingBody['data']) && is_array($bookingBody['data']) ? $bookingBody['data'] : [];
        $providerShopId = trim((string) ($orderLeg['provider_shop_id'] ?? ($bookingData['shop_id'] ?? '')));
        $providerBookingCode = trim((string) ($bookingData['booking_reference'] ?? ($orderLeg['provider_booking_code'] ?? '')));
        $providerTxnRef = trim((string) ($providerTransactionRefs[$providerCode] ?? ''));
        if ($providerTxnRef === '') {
            $providerTxnRef = $transactionRef;
        }
        $orderLeg['provider_shop_id'] = $providerShopId;
        $orderLeg['provider_booking_code'] = $providerBookingCode;
        $orderLeg['provider_transaction_ref'] = $providerTxnRef;

        $finalizedLegs[] = [
            'leg' => $orderLeg,
            'tickets' => isset($bookingData['tickets']) && is_array($bookingData['tickets']) ? $bookingData['tickets'] : [],
            'raw_payload' => [
                'reserve' => isset($legRaw['reserve']) && is_array($legRaw['reserve']) ? $legRaw['reserve'] : (isset($legRaw['body']) && is_array($legRaw['body']) ? $legRaw['body'] : null),
                'quote_token' => trim((string) ($legRaw['quote_token'] ?? '')),
                'finalize' => $bookingBody,
            ],
            'provider_booking_code' => $providerBookingCode,
            'provider_shop_id' => $providerShopId,
        ];
    }

    $providerSplitMap = cvCheckoutApiBuildProviderSplitMap($order);
    $paymentType = strtolower($gateway) === 'stripe' ? 2 : 1;

    try {
        $connection->begin_transaction();

        $paymentTxId = cvCheckoutApiUpsertPaymentTransaction(
            $connection,
            $orderId,
            $gateway,
            $transactionRef,
            $providerRef,
            round((float) ($order['total_amount'] ?? 0.0), 2),
            trim((string) ($order['currency'] ?? 'EUR')) !== '' ? (string) $order['currency'] : 'EUR',
            'captured',
            $rawRequest,
            $rawResponse
        );
        if ($paymentTxId <= 0) {
            throw new RuntimeException('Impossibile registrare la transazione di pagamento marketplace.');
        }

        cvCheckoutApiReplacePaymentSplits($connection, $paymentTxId, $order, $providerSplitMap);

        foreach ($finalizedLegs as $finalizedLeg) {
            $leg = isset($finalizedLeg['leg']) && is_array($finalizedLeg['leg']) ? $finalizedLeg['leg'] : [];
            $legId = isset($leg['id_order_leg']) ? (int) $leg['id_order_leg'] : 0;
            cvCheckoutApiUpdateOrderLegAfterFinalize(
                $connection,
                $legId,
                'paid',
                isset($finalizedLeg['raw_payload']) && is_array($finalizedLeg['raw_payload']) ? $finalizedLeg['raw_payload'] : [],
                (string) ($finalizedLeg['provider_booking_code'] ?? ''),
                (string) ($finalizedLeg['provider_shop_id'] ?? '')
            );
        }

        $persistedTickets = cvCheckoutApiPersistMarketplaceTickets($connection, $order, $finalizedLegs, $paymentType, $transactionRef);
        cvCheckoutApiUpdateOrderStatus($connection, $orderCode, 'paid');
        cvCheckoutApiSetOrderPaymentType($connection, $orderCode, $paymentType);

        $connection->commit();
    } catch (Throwable $exception) {
        $connection->rollback();
        cvCheckoutApiLog('marketplace_persist_failed', [
            'order_code' => $orderCode,
            'gateway' => $gateway,
            'transaction_ref' => $transactionRef,
            'provider_ref' => $providerRef,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
        return [
            'ok' => false,
            'message' => 'Errore persistenza checkout marketplace.',
            'details' => ['error' => $exception->getMessage()],
        ];
    }

    $freshOrder = cvCheckoutApiLoadOrderByCode($connection, $orderCode);
    $mailSent = cvCheckoutApiSendOrderConfirmation($connection, is_array($freshOrder) ? $freshOrder : $order, $finalizedLegs);

    return [
        'ok' => true,
        'order' => is_array($freshOrder) ? $freshOrder : $order,
        'finalized_legs' => $finalizedLegs,
        'email_sent' => $mailSent,
        'persisted_tickets' => $persistedTickets ?? [],
    ];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    cvCheckoutApiRespond(405, [
        'success' => false,
        'message' => 'Method not allowed',
    ]);
}

$input = cvCheckoutApiReadJsonPayload();
$action = strtolower(trim((string) ($_GET['action'] ?? ($input['action'] ?? 'create_order'))));
$allowedActions = ['create_order', 'preview_totals', 'paypal_create_order', 'paypal_capture', 'stripe_create_session', 'stripe_finalize', 'finalize_free'];
if (!in_array($action, $allowedActions, true)) {
    $action = 'create_order';
}

if ($action === 'preview_totals') {
    $selection = isset($input['selection']) && is_array($input['selection']) ? $input['selection'] : [];
    $query = isset($input['query']) && is_array($input['query']) ? $input['query'] : [];
    $baggageInput = isset($input['baggage']) && is_array($input['baggage']) ? $input['baggage'] : [];
    $promotionCode = strtoupper(trim((string) ($input['promotion_code'] ?? '')));

    $outbound = isset($selection['outbound']) && is_array($selection['outbound']) ? $selection['outbound'] : null;
    $inbound = isset($selection['return']) && is_array($selection['return']) ? $selection['return'] : null;
    $mode = strtolower(trim((string) ($query['mode'] ?? 'oneway')));
    if ($mode !== 'roundtrip') {
        $mode = 'oneway';
    }

    if (!is_array($outbound)) {
        cvCheckoutApiRespond(422, [
            'success' => false,
            'message' => 'Selezione andata mancante.',
        ]);
    }
    if ($mode === 'roundtrip' && !is_array($inbound)) {
        cvCheckoutApiRespond(422, [
            'success' => false,
            'message' => 'Selezione ritorno mancante.',
        ]);
    }

    $normalizedLegs = [];
    $outboundLegs = isset($outbound['legs']) && is_array($outbound['legs']) ? $outbound['legs'] : [];
    $outboundQuoteLegs = [];
    if (isset($outbound['_quoteValidation']) && is_array($outbound['_quoteValidation'])) {
        $outboundQuoteLegs = isset($outbound['_quoteValidation']['legs']) && is_array($outbound['_quoteValidation']['legs'])
            ? $outbound['_quoteValidation']['legs']
            : [];
    }
    foreach ($outboundLegs as $index => $leg) {
        if (!is_array($leg)) {
            continue;
        }
        $normalized = cvCheckoutApiNormalizeLeg($leg, 'outbound', $index + 1);
        if (!is_array($normalized)) {
            continue;
        }
        $quoteItem = isset($outboundQuoteLegs[$index]) && is_array($outboundQuoteLegs[$index]) ? $outboundQuoteLegs[$index] : [];
        if (count($quoteItem) > 0) {
            $normalized['amount'] = isset($quoteItem['amount']) && is_numeric($quoteItem['amount'])
                ? max(0.0, (float) $quoteItem['amount'])
                : $normalized['amount'];
            $normalized['base_amount'] = isset($quoteItem['base_amount']) && is_numeric($quoteItem['base_amount'])
                ? max(0.0, (float) $quoteItem['base_amount'])
                : (isset($quoteItem['provider_amount']) && is_numeric($quoteItem['provider_amount'])
                    ? max(0.0, (float) $quoteItem['provider_amount'])
                    : $normalized['base_amount']);
        }
        $normalized['checked_bag_unit_price'] = cvCheckoutApiResolveBaggageUnitPrice($quoteItem, $leg, 'checked');
        $normalized['checked_bag_base_price'] = cvCheckoutApiResolveBaggageScalar($quoteItem, $leg, ['checked_bag_base_price', 'bag_price_checked_base', 'prz_pacco'], 0.0);
        $normalized['checked_bag_increment'] = cvCheckoutApiResolveBaggageScalar($quoteItem, $leg, ['checked_bag_increment', 'checked_bag_increment_price', 'bag_price_checked_increment', 'incremento'], 0.0);
        $normalized['checked_bag_max_qty'] = cvCheckoutApiResolveBaggageMaxQty($quoteItem, $leg, 'checked');
        $normalized['hand_bag_unit_price'] = cvCheckoutApiResolveBaggageUnitPrice($quoteItem, $leg, 'hand');
        $normalized['hand_bag_max_qty'] = cvCheckoutApiResolveBaggageMaxQty($quoteItem, $leg, 'hand');
        $normalizedLegs[] = $normalized;
    }

    if ($mode === 'roundtrip' && is_array($inbound)) {
        $inboundLegs = isset($inbound['legs']) && is_array($inbound['legs']) ? $inbound['legs'] : [];
        $inboundQuoteLegs = [];
        if (isset($inbound['_quoteValidation']) && is_array($inbound['_quoteValidation'])) {
            $inboundQuoteLegs = isset($inbound['_quoteValidation']['legs']) && is_array($inbound['_quoteValidation']['legs'])
                ? $inbound['_quoteValidation']['legs']
                : [];
        }
        foreach ($inboundLegs as $index => $leg) {
            if (!is_array($leg)) {
                continue;
            }
            $normalized = cvCheckoutApiNormalizeLeg($leg, 'inbound', $index + 1);
            if (!is_array($normalized)) {
                continue;
            }
            $quoteItem = isset($inboundQuoteLegs[$index]) && is_array($inboundQuoteLegs[$index]) ? $inboundQuoteLegs[$index] : [];
            if (count($quoteItem) > 0) {
                $normalized['amount'] = isset($quoteItem['amount']) && is_numeric($quoteItem['amount'])
                    ? max(0.0, (float) $quoteItem['amount'])
                    : $normalized['amount'];
                $normalized['base_amount'] = isset($quoteItem['base_amount']) && is_numeric($quoteItem['base_amount'])
                    ? max(0.0, (float) $quoteItem['base_amount'])
                    : (isset($quoteItem['provider_amount']) && is_numeric($quoteItem['provider_amount'])
                        ? max(0.0, (float) $quoteItem['provider_amount'])
                        : $normalized['base_amount']);
            }
            $normalized['checked_bag_unit_price'] = cvCheckoutApiResolveBaggageUnitPrice($quoteItem, $leg, 'checked');
            $normalized['checked_bag_base_price'] = cvCheckoutApiResolveBaggageScalar($quoteItem, $leg, ['checked_bag_base_price', 'bag_price_checked_base', 'prz_pacco'], 0.0);
            $normalized['checked_bag_increment'] = cvCheckoutApiResolveBaggageScalar($quoteItem, $leg, ['checked_bag_increment', 'checked_bag_increment_price', 'bag_price_checked_increment', 'incremento'], 0.0);
            $normalized['checked_bag_max_qty'] = cvCheckoutApiResolveBaggageMaxQty($quoteItem, $leg, 'checked');
            $normalized['hand_bag_unit_price'] = cvCheckoutApiResolveBaggageUnitPrice($quoteItem, $leg, 'hand');
            $normalized['hand_bag_max_qty'] = cvCheckoutApiResolveBaggageMaxQty($quoteItem, $leg, 'hand');
            $normalizedLegs[] = $normalized;
        }
    }

    if (count($normalizedLegs) === 0) {
        cvCheckoutApiRespond(422, [
            'success' => false,
            'message' => 'Nessun segmento valido da prezzare.',
        ]);
    }

    $baggageByLeg = cvCheckoutApiNormalizeBaggageByLeg($baggageInput, $normalizedLegs);
    try {
        $connection = cvDbConnection();
        $providerCommissionMap = cvRuntimeProviderCommissionMap($connection);
    } catch (Throwable $exception) {
        cvCheckoutApiRespond(500, [
            'success' => false,
            'message' => 'Errore database durante il ricalcolo totale.',
        ]);
    }

    $totals = cvCheckoutApiComputePreviewTotals($normalizedLegs, $baggageByLeg, $providerCommissionMap);
    $travelDateIt = cvCheckoutApiPromotionTravelDateIt($query, $normalizedLegs);
    $promotion = cvCheckoutApiPromotionResolveForTotals($connection, isset($totals['legs']) && is_array($totals['legs']) ? $totals['legs'] : [], $travelDateIt, $promotionCode);
    $totals = cvCheckoutApiApplyPromotionToTotals($totals, $promotion);
    cvCheckoutApiRespond(200, [
        'success' => true,
        'message' => 'Totali aggiornati.',
        'data' => [
            'totals' => $totals,
            'promotion' => $promotion,
            'currency' => (string) ($totals['currency'] ?? 'EUR'),
        ],
    ]);
}

if ($action !== 'create_order') {
    try {
        $connection = cvDbConnection();
        $paymentConfig = cvRuntimeMarketplacePaymentConfig($connection);
        $providers = cvProviderConfigs($connection);
    } catch (Throwable $exception) {
        cvCheckoutApiRespond(500, [
            'success' => false,
            'message' => 'Errore configurazione pagamenti.',
            'details' => ['error' => $exception->getMessage()],
        ]);
    }

    $orderCode = trim((string) ($input['order_code'] ?? ''));
    $order = cvCheckoutApiLoadOrderByCode($connection, $orderCode);
    if (!is_array($order)) {
        cvCheckoutApiRespond(404, [
            'success' => false,
            'message' => 'Ordine checkout non trovato.',
        ]);
    }

    $orderStatus = strtolower(trim((string) ($order['status'] ?? '')));
    if ($orderStatus !== 'paid') {
        $expiresAt = trim((string) ($order['expires_at'] ?? ''));
        if ($expiresAt !== '') {
            $expiresTs = strtotime($expiresAt);
            if (is_int($expiresTs) && $expiresTs > 0 && $expiresTs <= time()) {
                foreach ((array) ($order['legs'] ?? []) as $orderLeg) {
                    if (!is_array($orderLeg)) {
                        continue;
                    }
                    $providerCode = strtolower(trim((string) ($orderLeg['provider_code'] ?? '')));
                    $providerShopId = trim((string) ($orderLeg['provider_shop_id'] ?? ''));
                    if ($providerCode === '' || $providerShopId === '' || !isset($providers[$providerCode])) {
                        continue;
                    }
                    cvCheckoutApiCancelLegReservation($providers[$providerCode], $providerShopId);
                }
                cvCheckoutApiUpdateOrderStatus($connection, $orderCode, 'expired');
                cvCheckoutApiRespond(409, [
                    'success' => false,
                    'message' => 'Ordine scaduto. Ripeti la ricerca e genera una nuova prenotazione.',
                ]);
            }
        }
    }

    $providerSplitMap = cvCheckoutApiBuildProviderSplitMap($order);
    $currency = trim((string) ($order['currency'] ?? 'EUR'));
    if ($currency === '') {
        $currency = 'EUR';
    }

    if ($action === 'finalize_free') {
        if ($orderStatus === 'paid') {
            cvCheckoutApiRespond(200, [
                'success' => true,
                'message' => 'Ordine gia finalizzato.',
                'data' => [
                    'order_code' => $orderCode,
                    'order' => $order,
                ],
            ]);
        }

        $amountTotal = isset($order['total_amount']) ? (float) $order['total_amount'] : 0.0;
        if ($amountTotal > 0.0001) {
            cvCheckoutApiRespond(422, [
                'success' => false,
                'message' => 'Il totale ordine non e gratuito: usa un metodo di pagamento.',
                'details' => [
                    'total_amount' => round($amountTotal, 2),
                ],
            ]);
        }

        cvCheckoutApiUpdateOrderStatus($connection, $orderCode, 'payment_pending');
        $freeRef = 'FREE-' . strtoupper($orderCode);
        $finalizeResult = cvCheckoutApiFinalizePaidOrder(
            $connection,
            $providers,
            $order,
            'free',
            $freeRef,
            $freeRef,
            [],
            cvCheckoutApiJsonEncode([
                'mode' => 'finalize_free',
                'order_code' => $orderCode,
            ]),
            cvCheckoutApiJsonEncode([
                'status' => 'ok',
                'mode' => 'finalize_free',
                'order_code' => $orderCode,
                'total_amount' => round($amountTotal, 2),
            ])
        );
        if (!(bool) ($finalizeResult['ok'] ?? false)) {
            cvCheckoutApiUpdateOrderStatus($connection, $orderCode, 'failed');
            cvCheckoutApiRespond(502, [
                'success' => false,
                'message' => (string) ($finalizeResult['message'] ?? 'Finalizzazione cambio gratuito fallita.'),
                'details' => $finalizeResult['details'] ?? null,
            ]);
        }

        cvCheckoutApiRespond(200, [
            'success' => true,
            'message' => 'Cambio gratuito confermato.',
            'data' => [
                'order_code' => $orderCode,
                'order' => $finalizeResult['order'] ?? cvCheckoutApiLoadOrderByCode($connection, $orderCode),
                'email_sent' => !empty($finalizeResult['email_sent']),
            ],
        ]);
    }

    if ($action === 'paypal_create_order') {
        $paypal = is_array($paymentConfig['paypal'] ?? null) ? $paymentConfig['paypal'] : [];
        if (empty($paypal['enabled'])) {
            cvCheckoutApiRespond(422, [
                'success' => false,
                'message' => 'PayPal non abilitato.',
            ]);
        }
        $providerMerchantIds = cvCheckoutApiNormalizeStringKeyMap(
            is_array($paymentConfig['provider_paypal_merchant_ids'] ?? null) ? $paymentConfig['provider_paypal_merchant_ids'] : []
        );
        $providerEmails = cvCheckoutApiNormalizeStringKeyMap(
            is_array($paymentConfig['provider_paypal_emails'] ?? null) ? $paymentConfig['provider_paypal_emails'] : []
        );
        $providerPaypalCheckoutEnabled = cvCheckoutApiNormalizeStringKeyMap(
            is_array($paymentConfig['provider_paypal_checkout_enabled'] ?? null) ? $paymentConfig['provider_paypal_checkout_enabled'] : []
        );
        $platformMerchantId = trim((string) ($paypal['merchant_id'] ?? ''));

        $providerCodes = cvCheckoutApiProviderCodesFromSplitMap($providerSplitMap);
        foreach ($providerCodes as $providerCode) {
            $isEnabled = ((int) ($providerPaypalCheckoutEnabled[$providerCode] ?? 0)) === 1;
            if (!$isEnabled) {
                cvCheckoutApiRespond(422, [
                    'success' => false,
                    'message' => 'PayPal non attivo sul provider ' . $providerCode . '.',
                ]);
            }

            $merchantId = trim((string) ($providerMerchantIds[$providerCode] ?? ''));
            $email = trim((string) ($providerEmails[$providerCode] ?? ''));
            if ($merchantId === '' && $email === '') {
                cvCheckoutApiRespond(422, [
                    'success' => false,
                    'message' => 'PayPal non configurato sul provider ' . $providerCode . '.',
                ]);
            }
        }

        $token = cvCheckoutApiPaypalAccessToken($paypal);
        if ($token === '') {
            cvCheckoutApiLog('paypal_access_token_failed', [
                'action' => 'paypal_create_order',
                'order_code' => $orderCode,
                'env' => (string) ($paypal['env'] ?? ''),
            ]);
            cvCheckoutApiRespond(422, [
                'success' => false,
                'message' => 'PayPal non configurato correttamente (client id/secret).',
            ]);
        }

        $commissionTotal = cvCheckoutApiPlatformCommissionTotal($providerSplitMap);
        $attemptSpecs = [
            [
                'mode' => 'net_split_context',
                'use_platform_fees' => false,
                'with_context' => true,
            ],
            [
                'mode' => 'net_split_minimal',
                'use_platform_fees' => false,
                'with_context' => false,
            ],
        ];
        if ($commissionTotal > 0.0 && trim($platformMerchantId) !== '') {
            $attemptSpecs[] = [
                'mode' => 'platform_fees_minimal',
                'use_platform_fees' => true,
                'with_context' => false,
            ];
        }

        $paypalCreate = null;
        $usedAttemptMode = '';
        $lastErrorMessage = 'Errore creazione ordine PayPal.';
        $lastErrorIssue = '';
        $lastErrorDebugId = '';
        $lastErrorStatus = 0;
        $lastErrorRaw = null;

        foreach ($attemptSpecs as $attemptIndex => $attemptSpec) {
            $attemptMode = (string) ($attemptSpec['mode'] ?? 'unknown');
            $attemptUsePlatformFees = !empty($attemptSpec['use_platform_fees']);
            $attemptWithContext = !empty($attemptSpec['with_context']);

            $attemptUnits = cvCheckoutApiBuildPaypalPurchaseUnits(
                $providerSplitMap,
                $providerMerchantIds,
                $providerEmails,
                $currency,
                $orderCode,
                $platformMerchantId,
                $attemptUsePlatformFees
            );
            if (count($attemptUnits) === 0) {
                continue;
            }

            $payload = [
                'intent' => 'CAPTURE',
                'purchase_units' => $attemptUnits,
            ];
            if ($attemptWithContext) {
                $payload['application_context'] = [
                    'shipping_preference' => 'NO_SHIPPING',
                    'user_action' => 'PAY_NOW',
                ];
            }

            cvCheckoutApiLog('paypal_create_order_request', [
                'order_code' => $orderCode,
                'attempt_mode' => $attemptMode,
                'attempt_index' => ((int) $attemptIndex) + 1,
                'purchase_units_count' => count($attemptUnits),
                'payload' => $payload,
                'env' => (string) ($paypal['env'] ?? ''),
                'provider_split_map' => $providerSplitMap,
                'provider_codes' => $providerCodes,
                'provider_merchant_ids' => $providerMerchantIds,
                'platform_merchant_id' => $platformMerchantId,
            ]);

            $attemptResponse = cvCheckoutApiCurlJson(
                'POST',
                cvCheckoutApiPaypalBaseUrl((string) ($paypal['env'] ?? 'sandbox')) . '/v2/checkout/orders',
                [
                    'Accept: application/json',
                    'Authorization: Bearer ' . $token,
                ],
                $payload
            );

            if (!empty($attemptResponse['ok']) && is_array($attemptResponse['body'])) {
                $paypalCreate = $attemptResponse;
                $usedAttemptMode = $attemptMode;
                break;
            }

            $attemptBody = is_array($attemptResponse['body']) ? $attemptResponse['body'] : [];
            $attemptMessage = trim((string) ($attemptBody['message'] ?? ''));
            $attemptIssue = '';
            if (isset($attemptBody['details'][0]) && is_array($attemptBody['details'][0])) {
                $detail = $attemptBody['details'][0];
                $attemptIssue = trim((string) ($detail['issue'] ?? ''));
                $detailDescription = trim((string) ($detail['description'] ?? ''));
                if ($detailDescription !== '') {
                    $attemptMessage = $detailDescription;
                }
            }
            if ($attemptMessage === '') {
                $attemptMessage = 'Errore creazione ordine PayPal.';
            }
            $attemptDebugId = trim((string) ($attemptBody['debug_id'] ?? ''));
            if ($attemptDebugId !== '') {
                $attemptMessage .= ' [debug_id: ' . $attemptDebugId . ']';
            }

            $lastErrorMessage = $attemptMessage;
            $lastErrorIssue = $attemptIssue;
            $lastErrorDebugId = $attemptDebugId;
            $lastErrorStatus = (int) ($attemptResponse['status'] ?? 0);
            $lastErrorRaw = $attemptResponse['raw'] ?? null;

            cvCheckoutApiLog('paypal_create_order_attempt_failed', [
                'order_code' => $orderCode,
                'attempt_mode' => $attemptMode,
                'attempt_index' => ((int) $attemptIndex) + 1,
                'http_status' => $lastErrorStatus,
                'raw' => (string) ($attemptResponse['raw'] ?? ''),
                'body' => $attemptBody,
                'purchase_units_count' => count($attemptUnits),
                'issue' => $attemptIssue,
                'debug_id' => $attemptDebugId,
            ]);
        }

        if (!is_array($paypalCreate) || empty($paypalCreate['ok']) || !is_array($paypalCreate['body'])) {
            cvCheckoutApiLog('paypal_create_order_failed', [
                'order_code' => $orderCode,
                'http_status' => $lastErrorStatus,
                'raw' => is_string($lastErrorRaw) ? $lastErrorRaw : '',
                'issue' => $lastErrorIssue,
                'debug_id' => $lastErrorDebugId,
            ]);
            cvCheckoutApiRespond(422, [
                'success' => false,
                'message' => $lastErrorMessage,
                'details' => [
                    'http_status' => $lastErrorStatus,
                    'raw' => $lastErrorRaw,
                    'issue' => $lastErrorIssue,
                    'debug_id' => $lastErrorDebugId,
                ],
            ]);
        }

        cvCheckoutApiLog('paypal_create_order_ok', [
            'order_code' => $orderCode,
            'paypal_order_id' => (string) ($paypalCreate['body']['id'] ?? ''),
            'status' => (string) ($paypalCreate['body']['status'] ?? ''),
            'attempt_mode' => $usedAttemptMode,
        ]);
        cvCheckoutApiRespond(200, [
            'success' => true,
            'message' => 'Ordine PayPal creato.',
            'data' => [
                'order_code' => $orderCode,
                'paypal_order_id' => (string) ($paypalCreate['body']['id'] ?? ''),
                'status' => (string) ($paypalCreate['body']['status'] ?? ''),
            ],
        ]);
    }

    if ($action === 'paypal_capture') {
        if ($orderStatus === 'paid') {
            cvCheckoutApiRespond(200, [
                'success' => true,
                'message' => 'Pagamento PayPal già acquisito.',
                'data' => [
                    'order_code' => $orderCode,
                    'order' => $order,
                ],
            ]);
        }

        $paypalOrderId = trim((string) ($input['paypal_order_id'] ?? ''));
        if ($paypalOrderId === '') {
            cvCheckoutApiRespond(422, ['success' => false, 'message' => 'paypal_order_id mancante.']);
        }

        $paypal = is_array($paymentConfig['paypal'] ?? null) ? $paymentConfig['paypal'] : [];
        if (empty($paypal['enabled'])) {
            cvCheckoutApiRespond(422, [
                'success' => false,
                'message' => 'PayPal non abilitato.',
            ]);
        }
        $token = cvCheckoutApiPaypalAccessToken($paypal);
        if ($token === '') {
            cvCheckoutApiLog('paypal_access_token_failed', [
                'action' => 'paypal_capture',
                'order_code' => $orderCode,
                'env' => (string) ($paypal['env'] ?? ''),
            ]);
            cvCheckoutApiRespond(422, [
                'success' => false,
                'message' => 'PayPal non configurato correttamente (client id/secret).',
            ]);
        }

        cvCheckoutApiLog('paypal_capture_request', [
            'order_code' => $orderCode,
            'paypal_order_id' => $paypalOrderId,
            'env' => (string) ($paypal['env'] ?? ''),
        ]);

        $capture = cvCheckoutApiCurlJson(
            'POST',
            cvCheckoutApiPaypalBaseUrl((string) ($paypal['env'] ?? 'sandbox')) . '/v2/checkout/orders/' . rawurlencode($paypalOrderId) . '/capture',
            [
                'Accept: application/json',
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token,
            ],
            null
        );
        $captureBody = is_array($capture['body']) ? $capture['body'] : [];
        $captureRaw = (string) ($capture['raw'] ?? '');
        if (!(bool) ($capture['ok'] ?? false)) {
            $paypalErrorMessage = trim((string) ($captureBody['message'] ?? ''));
            $paypalIssue = '';
            if (isset($captureBody['details']) && is_array($captureBody['details']) && isset($captureBody['details'][0]) && is_array($captureBody['details'][0])) {
                $detail0 = $captureBody['details'][0];
                $paypalIssue = trim((string) ($detail0['issue'] ?? ''));
                $detailDescription = trim((string) ($detail0['description'] ?? ''));
                if ($detailDescription !== '') {
                    $paypalErrorMessage = $detailDescription;
                }
            }

            // Caso idempotente: ordine già catturato su PayPal.
            if (strtoupper($paypalIssue) !== 'ORDER_ALREADY_CAPTURED') {
                if ($paypalErrorMessage === '') {
                    $paypalErrorMessage = 'Capture PayPal fallito.';
                }
                $debugId = trim((string) ($captureBody['debug_id'] ?? ''));
                if ($debugId !== '') {
                    $paypalErrorMessage .= ' [debug_id: ' . $debugId . ']';
                }

                cvCheckoutApiLog('paypal_capture_failed', [
                    'order_code' => $orderCode,
                    'paypal_order_id' => $paypalOrderId,
                    'http_status' => (int) ($capture['status'] ?? 0),
                    'body' => $captureBody,
                    'raw' => $captureRaw,
                ]);
                cvCheckoutApiRespond(422, [
                    'success' => false,
                    'message' => $paypalErrorMessage,
                    'details' => [
                        'http_status' => (int) ($capture['status'] ?? 0),
                        'raw' => $capture['raw'] ?? null,
                    ],
                ]);
            }

            $orderRead = cvCheckoutApiCurlJson(
                'GET',
                cvCheckoutApiPaypalBaseUrl((string) ($paypal['env'] ?? 'sandbox')) . '/v2/checkout/orders/' . rawurlencode($paypalOrderId),
                [
                    'Accept: application/json',
                    'Authorization: Bearer ' . $token,
                ]
            );
            if ((bool) ($orderRead['ok'] ?? false) && is_array($orderRead['body'])) {
                $captureBody = $orderRead['body'];
                $captureRaw = (string) ($orderRead['raw'] ?? $captureRaw);
            }
        }

        cvCheckoutApiUpdateOrderStatus($connection, $orderCode, 'payment_pending');
        cvCheckoutApiLog('paypal_capture_ok', [
            'order_code' => $orderCode,
            'paypal_order_id' => $paypalOrderId,
            'status' => (string) ($captureBody['status'] ?? ''),
            'body' => $captureBody,
        ]);
        $captureId = $paypalOrderId;
        $providerTransactionRefs = [];
        if (isset($captureBody['purchase_units']) && is_array($captureBody['purchase_units'])) {
            foreach ($captureBody['purchase_units'] as $purchaseUnit) {
                if (!is_array($purchaseUnit)) {
                    continue;
                }
                $referenceId = strtolower(trim((string) ($purchaseUnit['reference_id'] ?? '')));
                $payments = isset($purchaseUnit['payments']) && is_array($purchaseUnit['payments']) ? $purchaseUnit['payments'] : [];
                $captures = isset($payments['captures']) && is_array($payments['captures']) ? $payments['captures'] : [];
                if (!empty($captures[0]['id'])) {
                    $unitCaptureId = (string) $captures[0]['id'];
                    if ($captureId === $paypalOrderId) {
                        $captureId = $unitCaptureId;
                    }
                    if ($referenceId !== '' && strpos($referenceId, 'provider_') === 0) {
                        $providerCode = substr($referenceId, 9);
                        if ($providerCode !== '') {
                            $providerTransactionRefs[$providerCode] = $unitCaptureId;
                        }
                    }
                }
            }
        }

        $finalizeResult = cvCheckoutApiFinalizePaidOrder(
            $connection,
            $providers,
            $order,
            'paypal',
            $captureId,
            $paypalOrderId,
            $providerTransactionRefs,
            cvCheckoutApiJsonEncode(['paypal_order_id' => $paypalOrderId]),
            $captureRaw !== '' ? $captureRaw : ($capture['raw'] ?? null)
        );
        if (!(bool) ($finalizeResult['ok'] ?? false)) {
            cvCheckoutApiUpdateOrderStatus($connection, $orderCode, 'failed');
            cvCheckoutApiRespond(502, [
                'success' => false,
                'message' => (string) ($finalizeResult['message'] ?? 'Pagamento acquisito ma finalizzazione ordine fallita.'),
                'details' => $finalizeResult['details'] ?? null,
            ]);
        }

        cvCheckoutApiRespond(200, [
            'success' => true,
            'message' => 'Pagamento PayPal completato.',
            'data' => [
                'order_code' => $orderCode,
                'paypal_order_id' => $paypalOrderId,
                'capture' => $captureBody,
                'order' => $finalizeResult['order'] ?? cvCheckoutApiLoadOrderByCode($connection, $orderCode),
                'email_sent' => !empty($finalizeResult['email_sent']),
            ],
        ]);
    }

    if ($action === 'stripe_create_session') {
        $stripe = is_array($paymentConfig['stripe'] ?? null) ? $paymentConfig['stripe'] : [];
        if (empty($stripe['enabled'])) {
            cvCheckoutApiRespond(422, [
                'success' => false,
                'message' => 'Stripe non abilitato.',
            ]);
        }
        $secretKey = trim((string) ($stripe['secret_key'] ?? ''));
        if ($secretKey === '') {
            cvCheckoutApiRespond(422, [
                'success' => false,
                'message' => 'Stripe non configurato (secret key).',
            ]);
        }

        $providerStripeAccountIds = is_array($paymentConfig['provider_stripe_account_ids'] ?? null)
            ? $paymentConfig['provider_stripe_account_ids']
            : [];
        $providerCodes = cvCheckoutApiProviderCodesFromSplitMap($providerSplitMap);
        foreach ($providerCodes as $providerCode) {
            if (trim((string) ($providerStripeAccountIds[$providerCode] ?? '')) === '') {
                cvCheckoutApiRespond(422, [
                    'success' => false,
                    'message' => 'Stripe non attivo/configurato sul provider ' . $providerCode . '.',
                ]);
            }
        }

        $amountTotal = isset($order['total_amount']) ? (float) $order['total_amount'] : 0.0;
        $amountCents = (int) round(max(0.0, $amountTotal) * 100);
        if ($amountCents <= 0) {
            cvCheckoutApiRespond(422, [
                'success' => false,
                'message' => 'Totale ordine non valido per Stripe.',
            ]);
        }

        $baseUrl = trim((string) ($input['base_url'] ?? ''));
        if ($baseUrl === '') {
            $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
            $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
            $basePath = rtrim((string) dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/checkout_api.php')), '/');
            $baseUrl = $scheme . '://' . $host . $basePath;
        }
        $baseUrl = rtrim($baseUrl, '/');

        $sessionPayload = [
            'mode' => 'payment',
            'success_url' => $baseUrl . '/checkout.php?stripe_success=1&session_id={CHECKOUT_SESSION_ID}&order=' . rawurlencode($orderCode),
            'cancel_url' => $baseUrl . '/checkout.php?order=' . rawurlencode($orderCode),
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($currency),
                    'unit_amount' => $amountCents,
                    'product_data' => [
                        'name' => 'Cercaviaggio - prenotazione ' . $orderCode,
                    ],
                ],
            ]],
            'metadata' => [
                'order_code' => $orderCode,
            ],
        ];

        $stripeCreate = cvCheckoutApiCurlJson(
            'POST',
            'https://api.stripe.com/v1/checkout/sessions',
            [
                'Authorization: Bearer ' . $secretKey,
            ],
            $sessionPayload,
            true
        );
        if (!$stripeCreate['ok'] || !is_array($stripeCreate['body'])) {
            cvCheckoutApiRespond(422, [
                'success' => false,
                'message' => 'Errore creazione sessione Stripe.',
                'details' => [
                    'http_status' => (int) ($stripeCreate['status'] ?? 0),
                    'raw' => $stripeCreate['raw'] ?? null,
                ],
            ]);
        }

        cvCheckoutApiRespond(200, [
            'success' => true,
            'message' => 'Sessione Stripe creata.',
            'data' => [
                'order_code' => $orderCode,
                'session_id' => (string) ($stripeCreate['body']['id'] ?? ''),
                'url' => (string) ($stripeCreate['body']['url'] ?? ''),
            ],
        ]);
    }

    if ($action === 'stripe_finalize') {
        $stripe = is_array($paymentConfig['stripe'] ?? null) ? $paymentConfig['stripe'] : [];
        if (empty($stripe['enabled'])) {
            cvCheckoutApiRespond(422, [
                'success' => false,
                'message' => 'Stripe non abilitato.',
            ]);
        }
        $secretKey = trim((string) ($stripe['secret_key'] ?? ''));
        if ($secretKey === '') {
            cvCheckoutApiRespond(422, [
                'success' => false,
                'message' => 'Stripe non configurato (secret key).',
            ]);
        }

        $sessionId = trim((string) ($input['session_id'] ?? ''));
        if ($sessionId === '') {
            cvCheckoutApiRespond(422, ['success' => false, 'message' => 'session_id mancante.']);
        }

        if (strtolower(trim((string) ($order['status'] ?? ''))) === 'paid') {
            cvCheckoutApiRespond(200, [
                'success' => true,
                'message' => 'Ordine gia pagato.',
                'data' => ['order' => $order],
            ]);
        }

        $sessionResponse = cvCheckoutApiCurlJson(
            'GET',
            'https://api.stripe.com/v1/checkout/sessions/' . rawurlencode($sessionId),
            [
                'Authorization: Bearer ' . $secretKey,
            ]
        );
        if (!$sessionResponse['ok'] || !is_array($sessionResponse['body'])) {
            cvCheckoutApiRespond(422, [
                'success' => false,
                'message' => 'Errore lettura sessione Stripe.',
                'details' => ['raw' => $sessionResponse['raw'] ?? null],
            ]);
        }

        $sessionBody = $sessionResponse['body'];
        $paymentStatus = strtolower(trim((string) ($sessionBody['payment_status'] ?? '')));
        if ($paymentStatus !== 'paid') {
            cvCheckoutApiRespond(422, [
                'success' => false,
                'message' => 'Pagamento Stripe non ancora completato.',
            ]);
        }

        cvCheckoutApiUpdateOrderStatus($connection, $orderCode, 'payment_pending');
        $paymentIntentId = trim((string) ($sessionBody['payment_intent'] ?? ''));
        if ($paymentIntentId === '') {
            $paymentIntentId = $sessionId;
        }

        $finalizeResult = cvCheckoutApiFinalizePaidOrder(
            $connection,
            $providers,
            $order,
            'stripe',
            $paymentIntentId,
            $sessionId,
            [],
            cvCheckoutApiJsonEncode(['session_id' => $sessionId]),
            $sessionResponse['raw'] ?? null
        );
        if (!(bool) ($finalizeResult['ok'] ?? false)) {
            cvCheckoutApiUpdateOrderStatus($connection, $orderCode, 'failed');
            cvCheckoutApiRespond(502, [
                'success' => false,
                'message' => (string) ($finalizeResult['message'] ?? 'Pagamento Stripe acquisito ma finalizzazione ordine fallita.'),
                'details' => $finalizeResult['details'] ?? null,
            ]);
        }

        $providerStripeAccountIds = is_array($paymentConfig['provider_stripe_account_ids'] ?? null)
            ? $paymentConfig['provider_stripe_account_ids']
            : [];
        $transferGroup = 'CV-' . $orderCode;
        $transferResults = [];
        foreach ($providerSplitMap as $providerCode => $splitData) {
            $destination = trim((string) ($providerStripeAccountIds[$providerCode] ?? ''));
            $netAmountCents = (int) round(max(0.0, (float) ($splitData['net'] ?? 0.0)) * 100);
            if ($destination === '' || $netAmountCents <= 0) {
                continue;
            }

            $transferPayload = [
                'amount' => $netAmountCents,
                'currency' => strtolower($currency),
                'destination' => $destination,
                'transfer_group' => $transferGroup,
                'description' => 'Cercaviaggio split ' . strtoupper($providerCode) . ' ' . $orderCode,
            ];
            $transfer = cvCheckoutApiCurlJson(
                'POST',
                'https://api.stripe.com/v1/transfers',
                [
                    'Authorization: Bearer ' . $secretKey,
                ],
                $transferPayload,
                true
            );
            $transferResults[$providerCode] = [
                'ok' => (bool) ($transfer['ok'] ?? false),
                'status' => (int) ($transfer['status'] ?? 0),
                'raw' => $transfer['raw'] ?? null,
            ];
        }

        cvCheckoutApiRespond(200, [
            'success' => true,
            'message' => 'Pagamento Stripe confermato.',
            'data' => [
                'order' => $finalizeResult['order'] ?? cvCheckoutApiLoadOrderByCode($connection, $orderCode),
                'session_id' => $sessionId,
                'email_sent' => !empty($finalizeResult['email_sent']),
                'stripe_transfers' => $transferResults,
            ],
        ]);
    }
}

$selection = isset($input['selection']) && is_array($input['selection']) ? $input['selection'] : [];
$query = isset($input['query']) && is_array($input['query']) ? $input['query'] : [];
$contact = isset($input['contact']) && is_array($input['contact']) ? $input['contact'] : [];
$passengers = isset($input['passengers']) && is_array($input['passengers']) ? $input['passengers'] : [];
$baggageInput = isset($input['baggage']) && is_array($input['baggage']) ? $input['baggage'] : [];
$promotionCode = strtoupper(trim((string) ($input['promotion_code'] ?? '')));
$requestedPaymentMode = strtolower(trim((string) ($input['payment_mode'] ?? 'marketplace_split')));
$reserveMode = !isset($input['reserve']) || (bool) $input['reserve'] === true;

$outbound = isset($selection['outbound']) && is_array($selection['outbound']) ? $selection['outbound'] : null;
$inbound = isset($selection['return']) && is_array($selection['return']) ? $selection['return'] : null;
$mode = strtolower(trim((string) ($query['mode'] ?? 'oneway')));
if ($mode !== 'roundtrip') {
    $mode = 'oneway';
}

if (!is_array($outbound)) {
    cvCheckoutApiRespond(422, [
        'success' => false,
        'message' => 'Selezione andata mancante.',
    ]);
}

if ($mode === 'roundtrip' && !is_array($inbound)) {
    cvCheckoutApiRespond(422, [
        'success' => false,
        'message' => 'Selezione ritorno mancante.',
    ]);
}

$paymentMode = in_array($requestedPaymentMode, ['provider_direct', 'marketplace_split', 'marketplace_single'], true)
    ? $requestedPaymentMode
    : 'marketplace_split';
$codiceRecupero = trim((string) ($input['codice'] ?? ''));
$codiceCamb = trim((string) ($input['codice_camb'] ?? ''));

$normalizedLegs = [];

$outboundLegs = isset($outbound['legs']) && is_array($outbound['legs']) ? $outbound['legs'] : [];
$outboundQuoteLegs = [];
if (isset($outbound['_quoteValidation']) && is_array($outbound['_quoteValidation'])) {
    $outboundQuoteLegs = isset($outbound['_quoteValidation']['legs']) && is_array($outbound['_quoteValidation']['legs'])
        ? $outbound['_quoteValidation']['legs']
        : [];
}
foreach ($outboundLegs as $index => $leg) {
    if (!is_array($leg)) {
        continue;
    }
    $normalized = cvCheckoutApiNormalizeLeg($leg, 'outbound', $index + 1);
    if (!is_array($normalized)) {
        continue;
    }

    $quoteItem = [];
    if (isset($outboundQuoteLegs[$index]) && is_array($outboundQuoteLegs[$index])) {
        $quoteItem = $outboundQuoteLegs[$index];
        $normalized['quote_token'] = trim((string) ($quoteItem['quote_token'] ?? ($normalized['quote_token'] ?? '')));
        $normalized['quote_id'] = trim((string) ($quoteItem['quote_id'] ?? ($normalized['quote_id'] ?? '')));
        $normalized['quote_expires_at'] = trim((string) ($quoteItem['expires_at'] ?? ($normalized['quote_expires_at'] ?? '')));
        $normalized['amount'] = isset($quoteItem['amount']) && is_numeric($quoteItem['amount'])
            ? max(0.0, (float) $quoteItem['amount'])
            : $normalized['amount'];
        $normalized['base_amount'] = isset($quoteItem['base_amount']) && is_numeric($quoteItem['base_amount'])
            ? max(0.0, (float) $quoteItem['base_amount'])
            : (isset($quoteItem['provider_amount']) && is_numeric($quoteItem['provider_amount'])
                ? max(0.0, (float) $quoteItem['provider_amount'])
                : $normalized['base_amount']);
    }
    $normalized['checked_bag_unit_price'] = cvCheckoutApiResolveBaggageUnitPrice($quoteItem, $leg, 'checked');
    $normalized['checked_bag_base_price'] = cvCheckoutApiResolveBaggageScalar($quoteItem, $leg, ['checked_bag_base_price', 'bag_price_checked_base', 'prz_pacco'], 0.0);
    $normalized['checked_bag_increment'] = cvCheckoutApiResolveBaggageScalar($quoteItem, $leg, ['checked_bag_increment', 'checked_bag_increment_price', 'bag_price_checked_increment', 'incremento'], 0.0);
    $normalized['checked_bag_max_qty'] = cvCheckoutApiResolveBaggageMaxQty($quoteItem, $leg, 'checked');
    $normalized['hand_bag_unit_price'] = cvCheckoutApiResolveBaggageUnitPrice($quoteItem, $leg, 'hand');
    $normalized['hand_bag_max_qty'] = cvCheckoutApiResolveBaggageMaxQty($quoteItem, $leg, 'hand');

    $normalizedLegs[] = $normalized;
}

if ($mode === 'roundtrip' && is_array($inbound)) {
    $inboundLegs = isset($inbound['legs']) && is_array($inbound['legs']) ? $inbound['legs'] : [];
    $inboundQuoteLegs = [];
    if (isset($inbound['_quoteValidation']) && is_array($inbound['_quoteValidation'])) {
        $inboundQuoteLegs = isset($inbound['_quoteValidation']['legs']) && is_array($inbound['_quoteValidation']['legs'])
            ? $inbound['_quoteValidation']['legs']
            : [];
    }

    foreach ($inboundLegs as $index => $leg) {
        if (!is_array($leg)) {
            continue;
        }
        $normalized = cvCheckoutApiNormalizeLeg($leg, 'inbound', $index + 1);
        if (!is_array($normalized)) {
            continue;
        }

        $quoteItem = [];
        if (isset($inboundQuoteLegs[$index]) && is_array($inboundQuoteLegs[$index])) {
            $quoteItem = $inboundQuoteLegs[$index];
            $normalized['quote_token'] = trim((string) ($quoteItem['quote_token'] ?? ($normalized['quote_token'] ?? '')));
            $normalized['quote_id'] = trim((string) ($quoteItem['quote_id'] ?? ($normalized['quote_id'] ?? '')));
            $normalized['quote_expires_at'] = trim((string) ($quoteItem['expires_at'] ?? ($normalized['quote_expires_at'] ?? '')));
            $normalized['amount'] = isset($quoteItem['amount']) && is_numeric($quoteItem['amount'])
                ? max(0.0, (float) $quoteItem['amount'])
                : $normalized['amount'];
            $normalized['base_amount'] = isset($quoteItem['base_amount']) && is_numeric($quoteItem['base_amount'])
                ? max(0.0, (float) $quoteItem['base_amount'])
                : (isset($quoteItem['provider_amount']) && is_numeric($quoteItem['provider_amount'])
                    ? max(0.0, (float) $quoteItem['provider_amount'])
                    : $normalized['base_amount']);
        }
        $normalized['checked_bag_unit_price'] = cvCheckoutApiResolveBaggageUnitPrice($quoteItem, $leg, 'checked');
        $normalized['checked_bag_base_price'] = cvCheckoutApiResolveBaggageScalar($quoteItem, $leg, ['checked_bag_base_price', 'bag_price_checked_base', 'prz_pacco'], 0.0);
        $normalized['checked_bag_increment'] = cvCheckoutApiResolveBaggageScalar($quoteItem, $leg, ['checked_bag_increment', 'checked_bag_increment_price', 'bag_price_checked_increment', 'incremento'], 0.0);
        $normalized['checked_bag_max_qty'] = cvCheckoutApiResolveBaggageMaxQty($quoteItem, $leg, 'checked');
        $normalized['hand_bag_unit_price'] = cvCheckoutApiResolveBaggageUnitPrice($quoteItem, $leg, 'hand');
        $normalized['hand_bag_max_qty'] = cvCheckoutApiResolveBaggageMaxQty($quoteItem, $leg, 'hand');

        $normalizedLegs[] = $normalized;
    }
}

if (count($normalizedLegs) === 0) {
    cvCheckoutApiRespond(422, [
        'success' => false,
        'message' => 'Nessun segmento valido da prenotare.',
    ]);
}

$baggageByLeg = cvCheckoutApiNormalizeBaggageByLeg($baggageInput, $normalizedLegs);

$contactEmail = strtolower(trim((string) ($contact['email'] ?? '')));
$contactPhone = trim((string) ($contact['phone'] ?? ''));
$contactName = trim((string) ($contact['full_name'] ?? ''));

if ($contactEmail === '' || !filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
    cvCheckoutApiRespond(422, [
        'success' => false,
        'message' => 'Inserisci un indirizzo email valido per il checkout.',
    ]);
}

if ($contactName === '') {
    cvCheckoutApiRespond(422, [
        'success' => false,
        'message' => 'Inserisci nome e cognome del referente ordine.',
    ]);
}

$ad = isset($query['ad']) && is_numeric($query['ad']) ? max(0, (int) $query['ad']) : 1;
$bam = isset($query['bam']) && is_numeric($query['bam']) ? max(0, (int) $query['bam']) : 0;
$expectedPassengers = $ad + $bam;
if ($expectedPassengers <= 0) {
    cvCheckoutApiRespond(422, [
        'success' => false,
        'message' => 'Seleziona almeno un passeggero.',
    ]);
}

$normalizedPassengers = [];
foreach ($passengers as $index => $passenger) {
    if (!is_array($passenger)) {
        continue;
    }

    $fullName = trim((string) ($passenger['full_name'] ?? ''));
    $birthDate = trim((string) ($passenger['birth_date'] ?? ''));
    $phone = trim((string) ($passenger['phone'] ?? ''));
    $typeRaw = strtolower(trim((string) ($passenger['passenger_type'] ?? '')));
    $isChildRaw = $passenger['is_child'] ?? null;
    $isChildByType = null;
    if (in_array($typeRaw, ['child', 'bambino'], true)) {
        $isChildByType = true;
    } elseif (in_array($typeRaw, ['adult', 'adulto'], true)) {
        $isChildByType = false;
    }
    $isChildByPayload = null;
    if ($isChildRaw !== null) {
        $boolPayload = filter_var($isChildRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($boolPayload !== null) {
            $isChildByPayload = (bool) $boolPayload;
        }
    }
    $isChild = $isChildByType;
    if ($isChild === null) {
        $isChild = $isChildByPayload;
    }
    if ($isChild === null) {
        $isChild = ((int) $index >= $ad) && ((int) $index < ($ad + $bam));
    }
    $passengerType = $isChild ? 'child' : 'adult';

    if ($fullName === '') {
        cvCheckoutApiRespond(422, [
            'success' => false,
            'message' => 'Compila il nome completo del passeggero #' . ($index + 1) . '.',
        ]);
    }

    $normalizedPassengers[] = [
        'full_name' => $fullName,
        'birth_date' => $birthDate,
        'phone' => $phone,
        'passenger_type' => $passengerType,
        'is_child' => $isChild,
    ];
}
if (count($normalizedPassengers) !== $expectedPassengers) {
    cvCheckoutApiRespond(422, [
        'success' => false,
        'message' => 'Numero passeggeri non coerente con la ricerca. Attesi: ' . $expectedPassengers . '.',
    ]);
}

$idempotencyKey = trim((string) ($_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? ''));
if ($idempotencyKey === '') {
    $idempotencyKey = 'cv-' . hash('sha256', microtime(true) . '|' . $contactEmail . '|' . cvCheckoutApiOrderCode());
}
$idempotencyKey = substr($idempotencyKey, 0, 120);

try {
    $connection = cvDbConnection();
    $providers = cvProviderConfigs($connection);
    $providers = cvCheckoutApiNormalizeStringKeyMap($providers);
    $providerCommissionMap = cvRuntimeProviderCommissionMap($connection);
    $aziendaMap = cvCheckoutApiFetchAziendaIdsByCode($connection);
    $aziendaMap = cvCheckoutApiNormalizeStringKeyMap($aziendaMap);
} catch (Throwable $exception) {
    cvCheckoutApiRespond(500, [
        'success' => false,
        'message' => 'Errore database durante inizializzazione checkout.',
    ]);
}

if ($codiceCamb !== '') {
    $changePassengerLock = cvCheckoutApiLoadChangePassengerLock($connection, $codiceCamb);
    if (!is_array($changePassengerLock)) {
        cvCheckoutApiRespond(422, [
            'success' => false,
            'message' => 'Impossibile recuperare il passeggero del biglietto originario per il cambio.',
        ]);
    }

    $changeValidationError = cvCheckoutApiValidateChangePassengerLock($normalizedPassengers, $changePassengerLock);
    if ($changeValidationError !== null) {
        cvCheckoutApiRespond(422, [
            'success' => false,
            'message' => $changeValidationError,
        ]);
    }

    $normalizedPassengers = cvCheckoutApiApplyChangePassengerLock($normalizedPassengers, $changePassengerLock);
}

$missingProviders = [];
$missingInProviderConfigs = [];
$missingInAziende = [];
foreach ($normalizedLegs as $leg) {
    $providerCode = (string) $leg['provider_code'];
    if (!isset($providers[$providerCode])) {
        $missingProviders[$providerCode] = true;
        $missingInProviderConfigs[$providerCode] = true;
    }
    if (!isset($aziendaMap[$providerCode])) {
        $missingProviders[$providerCode] = true;
        $missingInAziende[$providerCode] = true;
    }
}

if (count($missingProviders) > 0) {
    $missingList = array_values(array_keys($missingProviders));
    $configuredProviderCodes = array_values(array_keys($providers));
    $configuredAziendaCodes = array_values(array_keys($aziendaMap));
    $missingProviderConfigList = array_values(array_keys($missingInProviderConfigs));
    $missingAziendeList = array_values(array_keys($missingInAziende));
    cvCheckoutApiLog('checkout_missing_provider_config', [
        'missing_providers' => $missingList,
        'missing_in_provider_configs' => $missingProviderConfigList,
        'missing_in_aziende' => $missingAziendeList,
        'configured_provider_codes' => $configuredProviderCodes,
        'configured_azienda_codes' => $configuredAziendaCodes,
    ]);
    cvCheckoutApiRespond(422, [
        'success' => false,
        'message' => 'Provider non configurato per il checkout: ' . implode(', ', $missingList) . '.',
        'details' => [
            'providers' => $missingList,
            'missing_in_provider_configs' => $missingProviderConfigList,
            'missing_in_aziende' => $missingAziendeList,
            'configured_provider_codes' => $configuredProviderCodes,
            'configured_azienda_codes' => $configuredAziendaCodes,
        ],
    ]);
}

$orderCode = cvCheckoutApiOrderCode();
$currency = 'EUR';
$totalAmount = 0.0;
$totalCommission = 0.0;
$orderLegRows = [];
$earliestExpiryAt = null;
$successfulReservations = [];

foreach ($normalizedLegs as $legIndex => $leg) {
    $providerCode = (string) $leg['provider_code'];
    $legMapKey = cvCheckoutApiLegMapKey(
        (string) ($leg['direction'] ?? 'outbound'),
        (int) ($leg['leg_index'] ?? ($legIndex + 1)),
        (string) ($leg['provider_code'] ?? '')
    );
    $legBaggage = isset($baggageByLeg[$legMapKey]) && is_array($baggageByLeg[$legMapKey])
        ? cvCheckoutApiNormalizeBaggageItem($baggageByLeg[$legMapKey])
        : ['checked_bags' => 0, 'hand_bags' => 0];
    $legBaseAmount = max(0.0, (float) ($leg['base_amount'] ?? ($leg['amount'] ?? 0.0)));
    $checkedUnit = isset($leg['checked_bag_unit_price']) && is_numeric($leg['checked_bag_unit_price'])
        ? max(0.0, (float) $leg['checked_bag_unit_price'])
        : 0.0;
    $handUnit = isset($leg['hand_bag_unit_price']) && is_numeric($leg['hand_bag_unit_price'])
        ? max(0.0, (float) $leg['hand_bag_unit_price'])
        : 0.0;
    $checkedMaxQty = isset($leg['checked_bag_max_qty']) && is_numeric($leg['checked_bag_max_qty']) ? max(1, min(20, (int) $leg['checked_bag_max_qty'])) : 8;
    $handMaxQty = isset($leg['hand_bag_max_qty']) && is_numeric($leg['hand_bag_max_qty']) ? max(1, min(20, (int) $leg['hand_bag_max_qty'])) : 8;
    $checkedCount = isset($legBaggage['checked_bags']) && is_numeric($legBaggage['checked_bags']) ? max(0, min($checkedMaxQty, (int) $legBaggage['checked_bags'])) : 0;
    $handCount = isset($legBaggage['hand_bags']) && is_numeric($legBaggage['hand_bags']) ? max(0, min($handMaxQty, (int) $legBaggage['hand_bags'])) : 0;
    $checkedAmount = cvCheckoutApiComputeCheckedBagAmount($checkedCount, $leg);
    $legBaggageAmount = round($checkedAmount + ($handCount * $handUnit), 2);
    $legGrossAmount = round($legBaseAmount + $legBaggageAmount, 2);
    $commissionPercent = isset($providerCommissionMap[$providerCode]) ? (float) $providerCommissionMap[$providerCode] : 0.0;
    $commissionCalc = cvRuntimeApplyProviderCommission($legGrossAmount, $commissionPercent, 1);
    $legAmount = round((float) ($commissionCalc['client_amount'] ?? 0.0), 2);
    $commissionAmount = round((float) ($commissionCalc['commission_amount'] ?? 0.0), 2);

    $departureAt = cvCheckoutApiIsoToMysql((string) ($leg['departure_iso'] ?? ''));
    $arrivalAt = cvCheckoutApiIsoToMysql((string) ($leg['arrival_iso'] ?? ''));
    if (!is_string($departureAt) || !is_string($arrivalAt)) {
        cvCheckoutApiRespond(422, [
            'success' => false,
            'message' => 'Date segmento non valide nel payload checkout.',
        ]);
    }

    if (!$reserveMode) {
        $quoteExpiry = cvCheckoutApiIsoToMysql((string) ($leg['quote_expires_at'] ?? ''));
        if (is_string($quoteExpiry)) {
            if ($earliestExpiryAt === null || strtotime($quoteExpiry) < strtotime($earliestExpiryAt)) {
                $earliestExpiryAt = $quoteExpiry;
            }
        }
    }

    $reserveResponse = null;
    $legStatus = 'draft';
    $providerShopId = null;
    $providerBookingCode = null;
    $quoteToken = trim((string) ($leg['quote_token'] ?? ''));

    if ($reserveMode) {
        if ($quoteToken === '') {
            cvCheckoutApiRespond(422, [
                'success' => false,
                'message' => 'Quote token mancante per riserva segmento ' . ($legIndex + 1) . '.',
            ]);
        }

        $reserveIdempotency = substr($idempotencyKey . '-' . $providerCode . '-' . $leg['direction'] . '-' . ($legIndex + 1), 0, 120);
        $reservePayload = [
            'contact' => [
                'full_name' => $contactName,
                'email' => $contactEmail,
                'phone' => $contactPhone,
            ],
            'passengers' => $normalizedPassengers,
            'baggage' => $legBaggage,
            'codice' => $codiceRecupero,
            'codice_camb' => $codiceCamb,
        ];
        $reserveResponse = cvCheckoutApiReserveLeg(
            $providers[$providerCode],
            $quoteToken,
            $reserveIdempotency,
            $reservePayload
        );

        if (!(bool) ($reserveResponse['ok'] ?? false) && cvCheckoutApiLooksLikeExpiredQuote($reserveResponse)) {
            $travelDateIt = cvCheckoutApiTravelDateItFromLeg($leg, $query);
            $refreshQuote = cvCheckoutApiRefreshQuoteForLeg(
                $providers[$providerCode],
                $leg,
                $ad,
                $bam,
                $travelDateIt,
                $codiceCamb
            );
            cvCheckoutApiLog('reserve_retry_on_expired_quote', [
                'provider_code' => $providerCode,
                'travel_date_it' => $travelDateIt,
                'refresh_ok' => (bool) ($refreshQuote['ok'] ?? false),
                'refresh_error' => (string) ($refreshQuote['message'] ?? ''),
            ]);

            if ((bool) ($refreshQuote['ok'] ?? false) && trim((string) ($refreshQuote['quote_token'] ?? '')) !== '') {
                $quoteToken = trim((string) $refreshQuote['quote_token']);
                $reserveResponse = cvCheckoutApiReserveLeg(
                    $providers[$providerCode],
                    $quoteToken,
                    $reserveIdempotency . '-r1',
                    $reservePayload
                );
            }
        }
        if (!(bool) ($reserveResponse['ok'] ?? false)) {
            foreach ($successfulReservations as $reservation) {
                if (!is_array($reservation)) {
                    continue;
                }
                $reservationProvider = trim((string) ($reservation['provider_code'] ?? ''));
                $reservationShopId = trim((string) ($reservation['shop_id'] ?? ''));
                if ($reservationProvider === '' || $reservationShopId === '' || !isset($providers[$reservationProvider])) {
                    continue;
                }
                cvCheckoutApiCancelLegReservation(
                    $providers[$reservationProvider],
                    $reservationShopId,
                    trim((string) ($reservation['codice_camb'] ?? ''))
                );
            }
            cvCheckoutApiRespond(409, [
                'success' => false,
                'message' => 'Riserva fallita su provider ' . $providerCode . ': ' . (string) ($reserveResponse['message'] ?? 'errore'),
                'details' => [
                    'provider_code' => $providerCode,
                    'http_status' => (int) ($reserveResponse['http_status'] ?? 409),
                    'provider_response' => $reserveResponse['body'] ?? null,
                ],
            ]);
        }

        $reserveBody = is_array($reserveResponse['body']) ? $reserveResponse['body'] : [];
        $reserveData = isset($reserveBody['data']) && is_array($reserveBody['data']) ? $reserveBody['data'] : [];
        $providerShopId = trim((string) ($reserveData['shop_id'] ?? ''));
        $providerBookingCode = trim((string) ($reserveData['reservation_id'] ?? ($reserveData['quote_id'] ?? '')));
        $reservationExpiry = cvCheckoutApiIsoToMysql((string) ($reserveData['expires_at'] ?? ''));
        if (is_string($reservationExpiry)) {
            if ($earliestExpiryAt === null || strtotime($reservationExpiry) < strtotime($earliestExpiryAt)) {
                $earliestExpiryAt = $reservationExpiry;
            }
        }
        $legStatus = 'reserved';
        if ($providerShopId !== '') {
            $successfulReservations[] = [
                'provider_code' => $providerCode,
                'shop_id' => $providerShopId,
                'codice_camb' => $codiceCamb,
            ];
        }
    }

    $totalAmount += $legAmount;
    $totalCommission += $commissionAmount;

    $orderLegRows[] = [
        'id_az' => $aziendaMap[$providerCode],
        'direction' => (string) $leg['direction'],
        'leg_index' => (int) $leg['leg_index'],
        'provider_shop_id' => $providerShopId,
        'provider_booking_code' => $providerBookingCode,
        'id_corsa' => ctype_digit((string) $leg['trip_external_id']) ? (int) $leg['trip_external_id'] : null,
        'id_sott1' => ctype_digit((string) $leg['from_stop_id']) ? (int) $leg['from_stop_id'] : 0,
        'id_sott2' => ctype_digit((string) $leg['to_stop_id']) ? (int) $leg['to_stop_id'] : 0,
        'departure_at' => $departureAt,
        'arrival_at' => $arrivalAt,
        'fare_code' => trim((string) ($leg['fare_id'] ?? '')),
        'passengers_json' => json_encode($normalizedPassengers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'amount' => round($legAmount, 2),
        'commission_amount' => $commissionAmount,
        'status' => $legStatus,
        'baggage' => isset($legBaggage) && is_array($legBaggage) ? $legBaggage : ['checked_bags' => 0, 'hand_bags' => 0],
        'raw_response' => cvCheckoutApiJsonEncode([
            'quote_token' => $quoteToken,
            'reserve' => is_array($reserveResponse) ? ($reserveResponse['body'] ?? null) : null,
            'baggage' => isset($legBaggage) && is_array($legBaggage) ? $legBaggage : ['checked_bags' => 0, 'hand_bags' => 0],
            'contact' => [
                'full_name' => $contactName,
                'email' => $contactEmail,
                'phone' => $contactPhone,
            ],
        ]),
        'provider_code' => $providerCode,
    ];
}

$expiresAt = $earliestExpiryAt ?: date('Y-m-d H:i:s', time() + 1800);
$orderStatus = $reserveMode ? 'reserved' : 'draft';
$totalCommissionRaw = round($totalCommission, 2);
$promotionResult = [
    'applied' => false,
    'source' => '',
    'promotion_id' => 0,
    'name' => '',
    'code' => '',
    'discount_percent' => 0.0,
    'discount_amount' => 0.0,
    'eligible_amount' => 0.0,
    'eligible_commission' => 0.0,
    'eligible_provider_codes' => [],
    'leg_discount_by_key' => [],
    'message' => '',
];
$travelDateIt = cvCheckoutApiPromotionTravelDateIt($query, $normalizedLegs);
$promoLegs = [];
foreach ($orderLegRows as $promoLeg) {
    if (!is_array($promoLeg)) {
        continue;
    }
    $promoLegs[] = [
        'direction' => (string) ($promoLeg['direction'] ?? 'outbound'),
        'leg_index' => (int) ($promoLeg['leg_index'] ?? 1),
        'provider_code' => (string) ($promoLeg['provider_code'] ?? ''),
        'amount' => (float) ($promoLeg['amount'] ?? 0.0),
        'commission_amount' => (float) ($promoLeg['commission_amount'] ?? 0.0),
    ];
}
if ($connection instanceof mysqli) {
    $promotionResult = cvCheckoutApiPromotionResolveForTotals(
        $connection,
        $promoLegs,
        $travelDateIt,
        $promotionCode
    );
}
$promotionDiscountTotal = round(max(0.0, (float) ($promotionResult['discount_amount'] ?? 0.0)), 2);
if (!empty($promotionResult['applied']) && $promotionDiscountTotal > 0.0) {
    $legDiscountMap = isset($promotionResult['leg_discount_by_key']) && is_array($promotionResult['leg_discount_by_key'])
        ? $promotionResult['leg_discount_by_key']
        : [];
    foreach ($orderLegRows as $idx => $orderLegRow) {
        if (!is_array($orderLegRow)) {
            continue;
        }
        $legKey = cvCheckoutApiLegMapKey(
            (string) ($orderLegRow['direction'] ?? 'outbound'),
            (int) ($orderLegRow['leg_index'] ?? 1),
            (string) ($orderLegRow['provider_code'] ?? '')
        );
        $legDiscount = isset($legDiscountMap[$legKey]) ? max(0.0, (float) $legDiscountMap[$legKey]) : 0.0;
        $legAmountRaw = max(0.0, (float) ($orderLegRow['amount'] ?? 0.0));
        $legCommissionRaw = max(0.0, (float) ($orderLegRow['commission_amount'] ?? 0.0));
        if ($legDiscount > $legCommissionRaw) {
            $legDiscount = $legCommissionRaw;
        }
        if ($legDiscount > $legAmountRaw) {
            $legDiscount = $legAmountRaw;
        }
        $orderLegRows[$idx]['amount_raw'] = round($legAmountRaw, 2);
        $orderLegRows[$idx]['amount'] = round($legAmountRaw - $legDiscount, 2);
        $orderLegRows[$idx]['commission_amount_raw'] = round($legCommissionRaw, 2);
        $orderLegRows[$idx]['promotion_discount_amount'] = round($legDiscount, 2);
        $orderLegRows[$idx]['commission_amount'] = round($legCommissionRaw - $legDiscount, 2);
    }
    $totalCommission = round(max(0.0, $totalCommissionRaw - $promotionDiscountTotal), 2);
    $totalAmount = round(max(0.0, $totalAmount - $promotionDiscountTotal), 2);
} else {
    $promotionDiscountTotal = 0.0;
}
$searchContext = json_encode([
    'query' => $query,
    'selection' => $selection,
    'contact' => [
        'full_name' => $contactName,
        'email' => $contactEmail,
        'phone' => $contactPhone,
    ],
    'passengers' => $normalizedPassengers,
    'normalized_legs' => $normalizedLegs,
    'baggage_by_leg' => $baggageByLeg,
    'promotion' => $promotionResult,
    'codice' => $codiceRecupero,
    'codice_camb' => $codiceCamb,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

try {
    $connection->begin_transaction();

    $orderSql = "INSERT INTO cv_orders
        (order_code, user_ref, currency, total_amount, payment_mode, status, idempotency_key, search_context, expires_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $orderStmt = $connection->prepare($orderSql);
    if (!$orderStmt instanceof mysqli_stmt) {
        throw new RuntimeException('Prepare cv_orders failed.');
    }

    $userRef = $contactEmail;
    $totalAmountRounded = round($totalAmount, 2);
    $orderStmt->bind_param(
        'sssdsssss',
        $orderCode,
        $userRef,
        $currency,
        $totalAmountRounded,
        $paymentMode,
        $orderStatus,
        $idempotencyKey,
        $searchContext,
        $expiresAt
    );

    if (!$orderStmt->execute()) {
        $error = $orderStmt->error;
        $orderStmt->close();
        throw new RuntimeException('Insert cv_orders failed: ' . $error);
    }
    $orderId = (int) $orderStmt->insert_id;
    $orderStmt->close();

    $legSql = "INSERT INTO cv_order_legs
        (order_id, id_az, direction, leg_index, provider_shop_id, provider_booking_code, id_linea, id_corsa, id_sott1, id_sott2, departure_at, arrival_at, fare_code, passengers_json, amount, commission_amount, status, raw_response)
        VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $legStmt = $connection->prepare($legSql);
    if (!$legStmt instanceof mysqli_stmt) {
        throw new RuntimeException('Prepare cv_order_legs failed.');
    }

    foreach ($orderLegRows as $legRow) {
        $orderIdBind = $orderId;
        $idAz = (int) $legRow['id_az'];
        $direction = (string) $legRow['direction'];
        $legIndexBind = (int) $legRow['leg_index'];
        $providerShopId = isset($legRow['provider_shop_id']) ? (string) $legRow['provider_shop_id'] : '';
        $providerBookingCode = isset($legRow['provider_booking_code']) ? (string) $legRow['provider_booking_code'] : '';
        $idCorsa = isset($legRow['id_corsa']) && is_int($legRow['id_corsa']) ? $legRow['id_corsa'] : null;
        $idSott1 = (int) $legRow['id_sott1'];
        $idSott2 = (int) $legRow['id_sott2'];
        $departureAt = (string) $legRow['departure_at'];
        $arrivalAt = (string) $legRow['arrival_at'];
        $fareCode = (string) $legRow['fare_code'];
        $passengersJson = is_string($legRow['passengers_json']) ? $legRow['passengers_json'] : '[]';
        $legAmountBind = (float) $legRow['amount'];
        $legCommissionBind = (float) $legRow['commission_amount'];
        $legStatusBind = (string) $legRow['status'];
        $rawResponse = isset($legRow['raw_response']) && is_string($legRow['raw_response']) ? $legRow['raw_response'] : null;

        $legStmt->bind_param(
            'iisissiiissssddss',
            $orderIdBind,
            $idAz,
            $direction,
            $legIndexBind,
            $providerShopId,
            $providerBookingCode,
            $idCorsa,
            $idSott1,
            $idSott2,
            $departureAt,
            $arrivalAt,
            $fareCode,
            $passengersJson,
            $legAmountBind,
            $legCommissionBind,
            $legStatusBind,
            $rawResponse
        );

        if (!$legStmt->execute()) {
            $error = $legStmt->error;
            $legStmt->close();
            throw new RuntimeException('Insert cv_order_legs failed: ' . $error);
        }
    }

    $legStmt->close();
    $connection->commit();
} catch (Throwable $exception) {
    if ($connection instanceof mysqli) {
        $connection->rollback();
    }

    foreach ($successfulReservations as $reservation) {
        if (!is_array($reservation)) {
            continue;
        }
        $reservationProvider = trim((string) ($reservation['provider_code'] ?? ''));
        $reservationShopId = trim((string) ($reservation['shop_id'] ?? ''));
        if ($reservationProvider === '' || $reservationShopId === '' || !isset($providers[$reservationProvider])) {
            continue;
        }
        cvCheckoutApiCancelLegReservation(
            $providers[$reservationProvider],
            $reservationShopId,
            trim((string) ($reservation['codice_camb'] ?? ''))
        );
    }

    cvCheckoutApiRespond(500, [
        'success' => false,
        'message' => 'Errore durante creazione ordine checkout.',
        'details' => ['error' => $exception->getMessage()],
    ]);
}

cvCheckoutApiRespond(200, [
    'success' => true,
    'message' => 'Ordine creato correttamente.',
    'data' => [
        'order_code' => $orderCode,
        'currency' => $currency,
        'total_amount' => round($totalAmount, 2),
        'promotion_discount_total' => round($promotionDiscountTotal, 2),
        'total_commission' => round($totalCommission, 2),
        'total_commission_raw' => round($totalCommissionRaw, 2),
        'provider_net' => round($totalAmount - $totalCommission, 2),
        'promotion' => $promotionResult,
        'status' => $orderStatus,
        'expires_at' => $expiresAt,
    ],
]);
