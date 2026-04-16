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

$q = trim((string) ($_GET['q'] ?? ''));
if ($q === '' || mb_strlen($q) < 3) {
    echo json_encode(['success' => true, 'items' => []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Simple per-session throttle (Nominatim requires fair use).
$now = microtime(true);
$last = isset($_SESSION['cv_geo_last_at']) ? (float) $_SESSION['cv_geo_last_at'] : 0.0;
if ($last > 0 && ($now - $last) < 0.8) {
    echo json_encode(['success' => true, 'items' => []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
$_SESSION['cv_geo_last_at'] = $now;

$cacheKey = 'cv_geo_' . md5(strtolower($q));
$cached = $_SESSION[$cacheKey] ?? null;
if (is_array($cached) && isset($cached['at'], $cached['items']) && is_array($cached['items'])) {
    $age = $now - (float) $cached['at'];
    if ($age >= 0 && $age < 600) {
        echo json_encode(['success' => true, 'items' => $cached['items']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

$items = cvAccessoGeoSuggest($q, 7);
$_SESSION[$cacheKey] = ['at' => $now, 'items' => $items];

echo json_encode(['success' => true, 'items' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
