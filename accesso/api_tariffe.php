<?php
declare(strict_types=1);

require_once __DIR__ . '/integrazione_common.php';

header('Content-Type: application/json; charset=utf-8');

$state = cvAccessoInit();
if (!$state['authenticated']) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Non autenticato.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$sessionToken = isset($_SESSION['cv_accesso_csrf']) ? (string) $_SESSION['cv_accesso_csrf'] : '';
$token = isset($_GET['csrf_token']) ? (string) $_GET['csrf_token'] : '';
if ($sessionToken === '' || $token === '' || !hash_equals($sessionToken, $token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF non valido.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$providerCode = strtolower(trim((string) ($_GET['provider'] ?? '')));
$fromId = trim((string) ($_GET['from'] ?? ''));
$toId = trim((string) ($_GET['to'] ?? ''));
if ($providerCode === '' || $fromId === '' || $toId === '' || $fromId === $toId) {
    echo json_encode(['success' => true, 'fare' => null], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $connection = cvAccessoRequireConnection();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Errore DB.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$allowed = cvAccessoIsAdmin($state) ? ['*'] : cvAccessoAllowedProviderCodes($state);
$allowed = cvCacheNormalizeProviderCodes(is_array($allowed) ? $allowed : []);
if (!in_array('*', $allowed, true) && !in_array($providerCode, $allowed, true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Provider non consentito.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$provider = cvAccessoIntegrationLoadProvider($connection, $providerCode);
if (count($provider) === 0 || (int) ($provider['id_provider'] ?? 0) <= 0) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Provider non trovato.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if (strtolower((string) ($provider['integration_mode'] ?? 'api')) !== 'manual') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Provider non in modalità Interfaccia.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$stmt = $connection->prepare(
    "SELECT external_id, from_stop_external_id, to_stop_external_id, amount, currency, is_active
     FROM cv_provider_fares
     WHERE id_provider = ?
       AND is_active = 1
       AND (
            (from_stop_external_id = ? AND to_stop_external_id = ?)
         OR (from_stop_external_id = ? AND to_stop_external_id = ?)
       )
     LIMIT 1"
);
if (!$stmt instanceof mysqli_stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Prepare fallita.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$idProvider = (int) ($provider['id_provider'] ?? 0);
$stmt->bind_param('issss', $idProvider, $fromId, $toId, $toId, $fromId);
if (!$stmt->execute()) {
    $stmt->close();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Query fallita.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$res = $stmt->get_result();
$row = $res instanceof mysqli_result ? $res->fetch_assoc() : null;
if ($res instanceof mysqli_result) {
    $res->free();
}
$stmt->close();

if (!is_array($row)) {
    echo json_encode(['success' => true, 'fare' => null], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

echo json_encode([
    'success' => true,
    'fare' => [
        'external_id' => (string) ($row['external_id'] ?? ''),
        'from' => (string) ($row['from_stop_external_id'] ?? ''),
        'to' => (string) ($row['to_stop_external_id'] ?? ''),
        'amount' => (string) ($row['amount'] ?? ''),
        'currency' => (string) ($row['currency'] ?? 'EUR'),
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

