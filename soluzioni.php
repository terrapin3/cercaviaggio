<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/site_layout.php';
require_once __DIR__ . '/includes/conn.php';
require_once __DIR__ . '/includes/pathfind.php';
require_once __DIR__ . '/includes/runtime_settings.php';
require_once __DIR__ . '/includes/error_log_tools.php';

function cvSolEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function cvSolDurationLabel(int $minutes): string
{
    $min = max(0, $minutes);
    $hours = intdiv($min, 60);
    $rest = $min % 60;
    if ($hours <= 0) {
        return $rest . ' min';
    }
    return $hours . 'h ' . str_pad((string) $rest, 2, '0', STR_PAD_LEFT) . 'm';
}

function cvSolLooksTechnicalRef(string $value): bool
{
    $trimmed = trim($value);
    if ($trimmed === '') {
        return false;
    }

    return strncmp($trimmed, 'r~', 2) === 0
        || strpos($trimmed, '|') !== false
        || preg_match('/^[0-9]+$/', $trimmed) === 1;
}

function cvSolResolveStopLabel(?mysqli $connection, string $rawRef, string $fallbackLabel): string
{
    $fallback = trim($fallbackLabel);
    if ($fallback !== '' && !cvSolLooksTechnicalRef($fallback)) {
        return $fallback;
    }

    $parsedRef = cvPfParseStopRef($rawRef);
    if (!is_array($parsedRef)) {
        if ($fallback !== '' && !cvSolLooksTechnicalRef($fallback)) {
            return $fallback;
        }
        return 'Fermata selezionata';
    }

    // When the ref is a logical place (macroarea/city), keep the place label
    // instead of replacing it with the first expanded provider stop.
    if (
        $connection instanceof mysqli
        && function_exists('cvSearchRouteResolveRefName')
        && ((string) ($parsedRef['provider_code'] ?? '')) === 'place'
    ) {
        $resolvedPlaceName = trim(cvSearchRouteResolveRefName($connection, $rawRef, $fallback));
        if ($resolvedPlaceName !== '' && !cvSolLooksTechnicalRef($resolvedPlaceName)) {
            return $resolvedPlaceName;
        }
    }

    if ($connection instanceof mysqli) {
        $candidates = cvPfFetchStopCandidates($connection, $parsedRef);
        if (count($candidates) > 0) {
            $name = trim((string) ($candidates[0]['name'] ?? ''));
            if ($name !== '') {
                return $name;
            }
        }
    }

    if ($fallback !== '' && !cvSolLooksTechnicalRef($fallback)) {
        return $fallback;
    }

    return 'Fermata selezionata';
}

function cvSolDatePickerLabel(string $dateIt, string $todayIt, bool $withTodayLabel = false): string
{
    $trimmed = trim($dateIt);
    if ($trimmed === '') {
        return $withTodayLabel ? 'Seleziona data' : '+ Ritorno';
    }

    if ($withTodayLabel && $trimmed === $todayIt) {
        return 'Oggi (' . $trimmed . ')';
    }

    return $trimmed;
}

function cvSolRawStopRef(string $rawRef): string
{
    $parsed = cvPfParseStopRef($rawRef);
    if (!is_array($parsed)) {
        return trim($rawRef);
    }

    $providerCode = trim((string) ($parsed['provider_code'] ?? ''));
    $externalId = trim((string) ($parsed['external_id'] ?? ''));
    if ($externalId === '') {
        return '';
    }

    return $providerCode !== '' ? ($providerCode . '|' . $externalId) : $externalId;
}

/**
 * @param array<string,mixed> $context
 */
function cvSolLogIssue(?mysqli $connection, string $eventCode, string $message, array $context = [], string $severity = 'warning'): void
{
    $logged = false;
    if ($connection instanceof mysqli && function_exists('cvErrorLogWrite')) {
        try {
            $logged = cvErrorLogWrite($connection, [
                'source' => 'soluzioni',
                'event_code' => strtoupper(trim($eventCode)) !== '' ? strtoupper(trim($eventCode)) : 'SOLUZIONI_WARNING',
                'severity' => strtolower(trim($severity)) === 'error' ? 'error' : 'warning',
                'message' => trim($message) !== '' ? trim($message) : 'Errore generico soluzioni.',
                'action_name' => 'search',
                'context' => $context,
            ]);
        } catch (Throwable $exception) {
            $logged = false;
        }
    }

    if (!$logged) {
        error_log('soluzioni.php ' . $eventCode . ': ' . $message);
    }
}

/**
 * @return array<string,string>
 */
function cvSolCollectProviderLogoMap(string $dirPath, string $assetPrefix): array
{
    if (!is_dir($dirPath)) {
        return [];
    }

    $paths = glob(rtrim($dirPath, '/') . '/*.{svg,png,webp,jpg,jpeg}', GLOB_BRACE);
    if (!is_array($paths)) {
        return [];
    }

    $map = [];
    foreach ($paths as $path) {
        if (!is_file($path)) {
            continue;
        }

        $filename = pathinfo($path, PATHINFO_FILENAME);
        $providerCode = strtolower((string) preg_replace('/^logo[_-]?/i', '', $filename));
        if ($providerCode === '') {
            continue;
        }

        $map[$providerCode] = cvAsset($assetPrefix . '/' . basename($path));
    }

    ksort($map);
    return $map;
}

/**
 * @return array<string,float>
 */
function cvSolCacheDatePrices(
    mysqli $connection,
    string $fromRefRaw,
    string $toRefRaw,
    string $baseDateIt,
    int $adults,
    int $children,
    int $maxTransfers,
    string $codiceCamb = '',
    int $rangeDays = 3,
    int $cacheTtlSeconds = 600
): array {
    if (!function_exists('cvPfCacheDir') || !is_dir(cvPfCacheDir())) {
        return [];
    }

    if (!function_exists('cvPfParseDate')) {
        return [];
    }

    $baseDate = cvPfParseDate($baseDateIt);
    if (!$baseDate instanceof DateTimeImmutable) {
        return [];
    }

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

    $adultCount = max(0, $adults);
    $childCount = max(0, $children);
    if (($adultCount + $childCount) <= 0) {
        $adultCount = 1;
    }
    $maxTransfers = max(0, min(3, $maxTransfers));

    $prices = [];
    $cacheVersions = ['v12', 'v11', 'v10', 'v9'];
    $rangeDays = max(2, min(10, $rangeDays));
    $cacheTtlSeconds = max(60, min(3600, $cacheTtlSeconds));

    for ($offset = -$rangeDays; $offset <= $rangeDays; $offset++) {
        $date = $baseDate->modify(($offset >= 0 ? '+' : '') . $offset . ' days');
        $dateIt = $date->format('d/m/Y');
        $cached = null;
        foreach ($cacheVersions as $cacheVersion) {
            $cacheKey = hash(
                'sha256',
                implode('|', [
                    $cacheVersion,
                    $cacheVersionToken,
                    $fromRefRaw,
                    $toRefRaw,
                    $dateIt,
                    (string) $adultCount,
                    (string) $childCount,
                    (string) $maxTransfers,
                    trim($codiceCamb),
                ])
            );

            $candidate = cvPfCacheRead($cacheKey, $cacheTtlSeconds);
            if (is_array($candidate)) {
                $cached = $candidate;
                break;
            }
        }

        if (!is_array($cached) || ($cached['ok'] ?? false) !== true) {
            continue;
        }

        $solutions = $cached['solutions'] ?? [];
        if (!is_array($solutions) || count($solutions) === 0) {
            continue;
        }

        $min = null;
        foreach ($solutions as $solution) {
            if (!is_array($solution)) {
                continue;
            }
            $amount = $solution['amount'] ?? null;
            if (!is_numeric($amount)) {
                continue;
            }
            $value = (float) $amount;
            if ($min === null || $value < $min) {
                $min = $value;
            }
        }

        if ($min !== null) {
            $prices[$dateIt] = $min;
        }
    }

    return $prices;
}

$part = trim((string) ($_GET['part'] ?? ''));
$arr = trim((string) ($_GET['arr'] ?? ''));
$todayIso = cvTodayIsoDate();
$todayIt = cvIsoToItDate($todayIso);
$dt1 = trim((string) ($_GET['dt1'] ?? cvIsoToItDate(cvTodayIsoDate())));
$dt2 = trim((string) ($_GET['dt2'] ?? ''));
$camb = trim((string) ($_GET['camb'] ?? ''));
$ad = max(0, (int) ($_GET['ad'] ?? 1));
$bam = max(0, (int) ($_GET['bam'] ?? 0));
if (($ad + $bam) <= 0) {
    $ad = 1;
}
$mode = trim((string) ($_GET['mode'] ?? 'oneway'));
$mode = $mode === 'roundtrip' ? 'roundtrip' : 'oneway';
$isAjax = trim((string) ($_GET['ajax'] ?? '')) === '1';
$deferredSearch = !$isAjax && trim((string) ($_GET['fast'] ?? '')) === '1';
$maxTransfers = 2;
$connection = null;

$outbound = [
    'ok' => false,
    'error' => 'Parametri ricerca mancanti.',
    'solutions' => [],
    'from' => ['label' => $part],
    'to' => ['label' => $arr],
    'meta' => ['cache' => '-'],
];

$return = [
    'ok' => false,
    'error' => '',
    'solutions' => [],
    'from' => ['label' => $arr],
    'to' => ['label' => $part],
    'meta' => ['cache' => '-'],
];
$datePriceMap = [
    'outbound' => [],
    'return' => [],
];
$datePriceCalendarEnabled = false;
$providerLogoMap = cvSolCollectProviderLogoMap(__DIR__ . '/assets/images/providers', 'images/providers');

if ($part !== '' && $arr !== '' && $dt1 !== '') {
    try {
        $connection = cvDbConnection();
        $runtimeSettings = cvRuntimeSettings($connection);
        if (isset($runtimeSettings['pathfind_max_transfers'])) {
            $maxTransfers = (int) $runtimeSettings['pathfind_max_transfers'];
        }
        $maxTransfers = max(0, min(3, $maxTransfers));
        $cacheTtlSeconds = max(60, min(3600, (int) ($runtimeSettings['pathfind_cache_ttl_seconds'] ?? 600)));
        $datePriceRangeDays = max(2, min(10, (int) ($runtimeSettings['pathfind_price_calendar_range_days'] ?? 3)));
        $datePriceCalendarEnabled = ((int) ($runtimeSettings['pathfind_date_price_calendar_enabled'] ?? 1)) === 1;

        if (!$deferredSearch) {
            $outbound = cvPfSearchSolutions($connection, $part, $arr, $dt1, $ad, $bam, $maxTransfers, $camb);

            if ($mode === 'roundtrip' && $dt2 !== '') {
                $return = cvPfSearchSolutions($connection, $arr, $part, $dt2, $ad, $bam, $maxTransfers, $camb);
            }
        }

        // Tracking popolarita: incremento gestito su "Seleziona soluzione" (validazione live OK),
        // non al semplice caricamento della pagina risultati.

        if ($datePriceCalendarEnabled) {
            $datePriceMap['outbound'] = cvSolCacheDatePrices(
                $connection,
                $part,
                $arr,
                $dt1,
                $ad,
                $bam,
                $maxTransfers,
                $camb,
                $datePriceRangeDays,
                $cacheTtlSeconds
            );
            if ($mode === 'roundtrip' && $dt2 !== '') {
                $datePriceMap['return'] = cvSolCacheDatePrices(
                    $connection,
                    $arr,
                    $part,
                    $dt2,
                    $ad,
                    $bam,
                    $maxTransfers,
                    $camb,
                    $datePriceRangeDays,
                    $cacheTtlSeconds
                );
            }
        }
    } catch (Throwable $exception) {
        $outbound = [
            'ok' => false,
            'error' => 'Errore durante la ricerca soluzioni. Riprova.',
            'solutions' => [],
            'from' => ['label' => $part],
            'to' => ['label' => $arr],
            'meta' => ['cache' => '-'],
        ];
        if ($mode === 'roundtrip') {
            $return = [
                'ok' => false,
                'error' => 'Errore durante la ricerca ritorno.',
                'solutions' => [],
                'from' => ['label' => $arr],
                'to' => ['label' => $part],
                'meta' => ['cache' => '-'],
            ];
        }
        cvSolLogIssue(
            $connection,
            'SEARCH_ERROR',
            $exception->getMessage(),
            [
                'part' => $part,
                'arr' => $arr,
                'dt1' => $dt1,
                'dt2' => $dt2,
                'mode' => $mode,
            ],
            'error'
        );
    }
}

$outboundSolutions = isset($outbound['solutions']) && is_array($outbound['solutions']) ? $outbound['solutions'] : [];
$returnSolutions = isset($return['solutions']) && is_array($return['solutions']) ? $return['solutions'] : [];

if (!empty($datePriceCalendarEnabled)) {
    $minOutbound = null;
    foreach ($outboundSolutions as $solution) {
        if (!is_array($solution)) {
            continue;
        }
        $amount = $solution['amount'] ?? null;
        if (!is_numeric($amount)) {
            continue;
        }
        $value = (float) $amount;
        if ($minOutbound === null || $value < $minOutbound) {
            $minOutbound = $value;
        }
    }
    if ($minOutbound !== null && trim($dt1) !== '') {
        $datePriceMap['outbound'][$dt1] = $minOutbound;
    }

    if ($mode === 'roundtrip') {
        $minReturn = null;
        foreach ($returnSolutions as $solution) {
            if (!is_array($solution)) {
                continue;
            }
            $amount = $solution['amount'] ?? null;
            if (!is_numeric($amount)) {
                continue;
            }
            $value = (float) $amount;
            if ($minReturn === null || $value < $minReturn) {
                $minReturn = $value;
            }
        }
        if ($minReturn !== null && trim($dt2) !== '') {
            $datePriceMap['return'][$dt2] = $minReturn;
        }
    }
}

if (!$connection instanceof mysqli && ($part !== '' || $arr !== '')) {
    try {
        $connection = cvDbConnection();
    } catch (Throwable $exception) {
        $connection = null;
    }
}

$fromLabelRaw = isset($outbound['from']['label']) ? (string) $outbound['from']['label'] : $part;
$toLabelRaw = isset($outbound['to']['label']) ? (string) $outbound['to']['label'] : $arr;
$fromLabel = cvSolResolveStopLabel($connection, $part, $fromLabelRaw);
$toLabel = cvSolResolveStopLabel($connection, $arr, $toLabelRaw);
$partRawRef = cvSolRawStopRef($part);
$arrRawRef = cvSolRawStopRef($arr);

$stops = [];
if (!$isAjax) {
    try {
        if (!$connection instanceof mysqli) {
            $connection = cvDbConnection();
        }
        $stops = cvFetchSearchEntries($connection);
    } catch (Throwable $exception) {
        cvSolLogIssue(
            $connection,
            'STOPS_LOAD_WARNING',
            $exception->getMessage(),
            [
                'part' => $part,
                'arr' => $arr,
                'mode' => $mode,
            ],
            'warning'
        );
    }
}

$stopsJson = json_encode($stops, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($stopsJson)) {
    $stopsJson = '[]';
}

$passengersSummary = '';
if ($ad > 0) {
    $passengersSummary = $ad . ' ' . ($ad === 1 ? 'adulto' : 'adulti');
}
if ($bam > 0) {
    $childPart = $bam . ' ' . ($bam === 1 ? 'bambino' : 'bambini');
    $passengersSummary = $passengersSummary !== '' ? ($passengersSummary . ', ' . $childPart) : $childPart;
}
if ($passengersSummary === '') {
    $passengersSummary = '1 adulto';
}

$departurePickerLabel = cvSolDatePickerLabel($dt1, $todayIt, true);
$returnPickerLabel = $mode === 'roundtrip'
    ? cvSolDatePickerLabel($dt2 !== '' ? $dt2 : $dt1, $todayIt, false)
    : '+ Ritorno';

$searchData = [
    'query' => [
        'part' => $part,
        'arr' => $arr,
        'dt1' => $dt1,
        'dt2' => $dt2,
        'ad' => $ad,
        'bam' => $bam,
        'mode' => $mode,
        'camb' => $camb,
    ],
    'labels' => [
        'from' => $fromLabel,
        'to' => $toLabel,
    ],
    'max_transfers' => $maxTransfers,
    'deferred' => $deferredSearch,
    'outbound' => [
        'ok' => (bool) ($outbound['ok'] ?? false),
        'error' => (string) ($outbound['error'] ?? ''),
        'cache' => (string) ($outbound['meta']['cache'] ?? '-'),
        'solutions' => $outboundSolutions,
    ],
    'return' => [
        'ok' => (bool) ($return['ok'] ?? false),
        'error' => (string) ($return['error'] ?? ''),
        'cache' => (string) ($return['meta']['cache'] ?? '-'),
        'solutions' => $returnSolutions,
    ],
];

$searchDataJson = json_encode(
    $searchData,
    JSON_UNESCAPED_UNICODE |
    JSON_UNESCAPED_SLASHES |
    JSON_HEX_TAG |
    JSON_HEX_AMP |
    JSON_HEX_APOS |
    JSON_HEX_QUOT
);
if (!is_string($searchDataJson)) {
    $searchDataJson = '{}';
}

if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    $ajaxPayload = [
        'success' => true,
        'searchData' => $searchData,
        'datePriceMap' => $datePriceMap,
    ];
    echo json_encode(
        $ajaxPayload,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_HEX_TAG |
        JSON_HEX_AMP |
        JSON_HEX_APOS |
        JSON_HEX_QUOT
    );
    exit;
}

?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Soluzioni viaggio | Cercaviaggio</title>
  <meta name="description" content="Confronta soluzioni, scali e prezzi per il tuo viaggio.">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Source+Sans+3:wght@400;600;700&display=swap" rel="stylesheet">
  <?= cvRenderNamedAssetBundle('public-base-css') ?>
  <?= cvRenderNamedAssetBundle('public-date-css') ?>
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="anonymous">
  <?= cvRenderNamedAssetBundle('public-app-css') ?>
</head>
<body>
  <div class="cv-page-bg"></div>
  <main class="container cv-shell py-4 py-lg-5">
    <?= cvRenderSiteHeader() ?>

    <div class="d-flex justify-content-end mb-3">
      <button
        type="button"
        class="btn cv-account-secondary"
        data-bs-toggle="collapse"
        data-bs-target="#cvInlineSearchPanel"
        aria-expanded="false"
        aria-controls="cvInlineSearchPanel"
      >
        <i class="bi bi-arrow-left-short"></i> Nuova ricerca
      </button>
    </div>

    <section class="collapse cv-inline-search-panel mb-4" id="cvInlineSearchPanel">
      <div class="cv-search-wrap">
        <div class="cv-inline-search-head">
          <div>
            <p class="cv-eyebrow mb-1">Nuova ricerca</p>
            <h2 class="cv-inline-search-title mb-0">Modifica la tratta senza uscire dai risultati</h2>
          </div>
        </div>

        <form id="searchForm" class="cv-search-form" novalidate>
          <input type="hidden" id="partId" name="part" value="<?= cvSolEscape($partRawRef) ?>">
          <input type="hidden" id="arrId" name="arr" value="<?= cvSolEscape($arrRawRef) ?>">
          <input type="hidden" id="adCount" name="ad" value="<?= (int) $ad ?>">
          <input type="hidden" id="bamCount" name="bam" value="<?= (int) $bam ?>">
          <input type="hidden" id="dt1Field" name="dt1" value="<?= cvSolEscape($dt1) ?>">
          <input type="hidden" id="dt2Field" name="dt2" value="<?= cvSolEscape($dt2) ?>">
          <input type="hidden" id="cambField" name="camb" value="<?= cvSolEscape($camb) ?>">
          <input type="hidden" id="tripModeField" name="trip_mode" value="<?= cvSolEscape($mode) ?>">

          <div class="row g-3 g-lg-4 align-items-end">
            <div class="col-12 col-lg-5">
              <label for="fromCity" class="cv-label">Partenza da</label>
              <div class="cv-input-shell cv-autocomplete cv-input-actions cv-input-actions-from">
                <i class="bi bi-geo-alt cv-input-icon"></i>
                <input type="text" class="form-control cv-input cv-stop-input" id="fromCity" placeholder="Seleziona partenza" autocomplete="off" value="<?= cvSolEscape($fromLabel) ?>">
                <button type="button" class="cv-input-action cv-clear-btn cv-clear-btn-from d-none" id="fromClearBtn" aria-label="Cancella partenza">
                  <i class="bi bi-x-lg"></i>
                </button>
                <button type="button" class="cv-input-action cv-geo-btn" id="geoLocateFromBtn" aria-label="Usa posizione attuale">
                  <i class="bi bi-crosshair"></i>
                </button>
                <ul id="fromSuggestions" class="cv-suggestions list-unstyled mb-0 d-none"></ul>
              </div>
              <div id="fromError" class="cv-field-error d-none"></div>
            </div>

            <div class="col-12 col-lg-2 d-none d-lg-flex justify-content-center">
              <button type="button" id="swapDesktopBtn" class="cv-swap-btn cv-swap-btn-desktop" aria-label="Inverti tratta">
                <i class="bi bi-arrow-left-right"></i>
              </button>
            </div>

            <div class="col-12 col-lg-5 position-relative">
              <button type="button" id="swapMobileBtn" class="cv-swap-btn cv-swap-btn-mobile d-lg-none" aria-label="Inverti tratta">
                <i class="bi bi-arrow-left-right"></i>
              </button>
              <label for="toCity" class="cv-label">Destinazione</label>
              <div class="cv-input-shell cv-autocomplete cv-input-actions">
                <i class="bi bi-signpost-2 cv-input-icon"></i>
                <input type="text" class="form-control cv-input cv-stop-input" id="toCity" placeholder="Seleziona destinazione" autocomplete="off" value="<?= cvSolEscape($toLabel) ?>">
                <button type="button" class="cv-input-action cv-clear-btn d-none" id="toClearBtn" aria-label="Cancella destinazione">
                  <i class="bi bi-x-lg"></i>
                </button>
                <ul id="toSuggestions" class="cv-suggestions list-unstyled mb-0 d-none"></ul>
              </div>
              <div id="toError" class="cv-field-error d-none"></div>
            </div>

            <div class="col-12 col-md-6 col-lg-3">
              <button type="button" id="departurePickerBtn" class="btn cv-picker-btn w-100" data-field="departure">
                <span class="cv-picker-label"><i class="bi bi-calendar3"></i> Data partenza</span>
                <span class="cv-picker-value" id="departurePickerValue"><?= cvSolEscape($departurePickerLabel) ?></span>
              </button>
            </div>

            <div class="col-12 col-md-6 col-lg-3">
              <button type="button" id="returnPickerBtn" class="btn cv-picker-btn w-100 <?= $mode === 'roundtrip' ? '' : 'cv-picker-btn-return-inactive' ?>" data-field="return">
                <span class="cv-picker-label"><i class="bi bi-calendar-check"></i> Ritorno</span>
                <span class="cv-picker-value" id="returnPickerValue"><?= cvSolEscape($returnPickerLabel) ?></span>
              </button>
              <div id="returnError" class="cv-field-error d-none"></div>
            </div>

            <div class="col-12 col-lg-3">
              <button type="button" id="passengersBtn" class="btn cv-picker-btn w-100" data-bs-toggle="modal" data-bs-target="#passengersModal">
                <span class="cv-picker-label"><i class="bi bi-people"></i> Passeggeri</span>
                <span class="cv-picker-value" id="passengersSummary"><?= cvSolEscape($passengersSummary) ?></span>
              </button>
            </div>

            <div class="col-12 col-lg-3">
              <button type="submit" class="btn cv-search-btn w-100">
                <i class="bi bi-search me-1"></i>
                Cerca
              </button>
            </div>
          </div>
        </form>
      </div>
    </section>

    <section class="cv-hero mb-3">
      <div class="cv-copy mb-3">
        <p class="cv-eyebrow mb-2">Risultati ricerca</p>
        <h1 class="cv-title mb-2">Soluzioni disponibili</h1>
        <p class="cv-subtitle mb-0">
          <strong><?= cvSolEscape($fromLabel) ?></strong> <i class="bi bi-arrow-right"></i>
          <strong><?= cvSolEscape($toLabel) ?></strong>
          il <strong><?= cvSolEscape($dt1) ?></strong>
          <?php if ($mode === 'roundtrip' && $dt2 !== ''): ?>
            <span class="ms-2">• ritorno il <strong><?= cvSolEscape($dt2) ?></strong></span>
          <?php endif; ?>
        </p>
      </div>
    </section>

    <div class="cv-results-layout mb-4" id="resultsLayout">
      <aside class="offcanvas-lg offcanvas-start cv-filters-sidebar" tabindex="-1" id="solutionsFilters" aria-labelledby="solutionsFiltersTitle">
        <div class="offcanvas-header">
          <h5 class="offcanvas-title" id="solutionsFiltersTitle">Filtri e ordinamento</h5>
          <button type="button" class="btn-close d-lg-none" data-bs-dismiss="offcanvas" data-bs-target="#solutionsFilters" aria-label="Chiudi"></button>
        </div>
        <div class="offcanvas-body cv-filters-sidebar-body">
          <section class="cv-filter-panel">
            <div class="cv-filter-block">
              <label for="sortSolutions" class="cv-label mb-2">Ordina</label>
              <select id="sortSolutions" class="form-select cv-auth-input cv-sort-select">
                <option value="duration">Durata</option>
                <option value="price">Prezzo</option>
                <option value="departure">Orario partenza</option>
              </select>
            </div>

            <div class="cv-filter-block">
              <label for="filterPriceMax" class="cv-label">Prezzo massimo</label>
              <input type="range" id="filterPriceMax" min="1" max="500" step="1" value="500" class="form-range">
              <div class="cv-filter-range-label" id="filterPriceMaxLabel">€ 500,00</div>
            </div>

            <div class="cv-filter-block">
              <label for="filterDepartFrom" class="cv-label">Partenza da</label>
              <input type="range" id="filterDepartFrom" min="0" max="23" step="1" value="0" class="form-range">
              <div class="cv-filter-range-label" id="filterDepartFromLabel">00:00</div>
            </div>

            <div class="cv-filter-block">
              <label for="filterDepartTo" class="cv-label">Partenza fino a</label>
              <input type="range" id="filterDepartTo" min="0" max="23" step="1" value="23" class="form-range">
              <div class="cv-filter-range-label" id="filterDepartToLabel">23:59</div>
            </div>

            <div class="cv-filter-block">
              <button type="button" class="btn cv-filter-disclosure" id="filterTransfersToggle" aria-expanded="false" aria-controls="filterTransfersMenu">
                <span class="cv-filter-disclosure-arrow"><i class="bi bi-chevron-down"></i></span>
                <span class="cv-filter-disclosure-text">Cambi</span>
              </button>
              <div id="filterTransfersMenu" class="cv-filter-transfer-menu d-none"></div>
            </div>

            <div class="cv-filter-block form-check cv-checkbox">
              <input type="checkbox" class="form-check-input" id="filterNearbyOnly">
              <label for="filterNearbyOnly" class="form-check-label">Solo partenze dalla fermata selezionata</label>
            </div>

            <button type="button" id="filterResetBtn" class="btn cv-cookie-btn-outline w-100">Reset filtri</button>
          </section>
        </div>
      </aside>

      <section class="cv-results-main">
        <section class="cv-solutions-wrap">
          <div class="cv-sol-toolbar">
            <div class="d-flex align-items-center gap-2 flex-wrap">
              <button class="btn cv-account-secondary d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#solutionsFilters" aria-controls="solutionsFilters">
                <i class="bi bi-sliders me-1"></i> Filtri
              </button>
              <button class="btn cv-account-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#cvTripSummaryCollapse" aria-expanded="false" aria-controls="cvTripSummaryCollapse">
                <i class="bi bi-receipt-cutoff me-1"></i> Riepilogo selezione
              </button>
            </div>
          </div>

          <div class="cv-date-tabs mb-3" id="dateTabsRow">
            <div class="cv-date-tabs-head">
              <span class="cv-date-tabs-title" id="dateTabsTitle">Date andata</span>
            </div>
            <div class="cv-date-tabs-shell">
              <button type="button" class="btn cv-date-nav-btn" id="dateTabsPrevBtn" aria-label="Date precedenti">
                <i class="bi bi-chevron-left"></i>
              </button>
              <div class="cv-date-tabs-track" id="dateTabsTrack"></div>
              <button type="button" class="btn cv-date-nav-btn" id="dateTabsNextBtn" aria-label="Date successive">
                <i class="bi bi-chevron-right"></i>
              </button>
            </div>
          </div>

          <div class="cv-solutions-meta" id="solutionsMetaRow">
            <span><i class="bi bi-ticket-perforated me-1"></i> <span id="solutionsCountLabel">0</span> soluzioni</span>
            <button
              type="button"
              class="btn cv-account-secondary cv-sol-passengers-btn"
              id="solutionsPassengersBtn"
              data-bs-toggle="modal"
              data-bs-target="#passengersModal"
            >
              <i class="bi bi-people me-1"></i>
              <span id="solutionsPassengersValue"><?= cvSolEscape($passengersSummary) ?></span>
            </button>
            <span><i class="bi bi-diagram-3 me-1"></i> max scali: <?= (int) $maxTransfers ?></span>
          </div>

          <section class="cv-selected-strip cv-selected-strip-inline collapse mb-3" id="cvTripSummaryCollapse">
            <div class="cv-selected-grid">
              <article class="cv-selected-card" id="selectedOutboundCard">
                <div class="cv-selected-head">
                  <span class="cv-selected-step cv-selected-step-active" id="stepOutbound">1</span>
                  <strong><?= $mode === 'roundtrip' ? 'Andata' : 'Viaggio' ?></strong>
                  <button type="button" class="btn btn-sm cv-route-cta d-none" id="editOutboundBtn">Modifica</button>
                </div>
                <div class="cv-selected-body" id="selectedOutboundBody"><?= $mode === 'roundtrip' ? 'Seleziona la soluzione di andata.' : 'Seleziona la soluzione di viaggio.' ?></div>
              </article>

              <article class="cv-selected-card <?= $mode === 'roundtrip' ? '' : 'd-none' ?>" id="selectedReturnCard">
                <div class="cv-selected-head">
                  <span class="cv-selected-step" id="stepReturn">2</span>
                  <strong>Ritorno</strong>
                  <button type="button" class="btn btn-sm cv-route-cta d-none" id="editReturnBtn">Modifica</button>
                </div>
                <div class="cv-selected-body" id="selectedReturnBody">Seleziona la soluzione di ritorno.</div>
              </article>

              <article class="cv-selected-total">
                <span class="cv-selected-total-label">Totale stimato</span>
                <strong id="selectedTotalPrice">-</strong>
                <button type="button" id="continueBookingBtn" class="btn cv-search-btn w-100 mt-2" disabled>
                  Continua
                </button>
              </article>
            </div>
          </section>

          <div class="cv-inline-progress d-none" id="solutionsProgressWrap" aria-live="polite">
            <div class="cv-inline-progress-head">
              <span id="solutionsProgressLabel">Caricamento soluzioni...</span>
              <span id="solutionsProgressCount">0%</span>
            </div>
            <div class="cv-inline-progress-track">
              <span id="solutionsProgressBar"></span>
            </div>
          </div>

          <div id="soluzioniInlineAlert" class="alert alert-warning d-none mb-3" role="alert"></div>
          <div id="solutionsEmptyState" class="alert alert-info d-none" role="alert">Nessuna soluzione disponibile con i filtri selezionati.</div>
          <div id="solutionsList" class="cv-solution-list cv-solution-list-v2"></div>
        </section>
      </section>
    </div>
  </main>

  <?= cvRenderSiteFooter('container cv-shell pb-4') ?>

  <div class="modal fade" id="dateModal" tabindex="-1" aria-labelledby="dateModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered cv-date-modal-dialog">
      <div class="modal-content cv-date-modal-content">
        <div class="modal-body cv-date-modal-body">
          <div class="cv-calendar-card">
            <div class="cv-calendar-head">
              <h5 class="cv-calendar-title" id="dateModalTitle">Seleziona data</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
            </div>
            <input type="text" id="calendarInput" class="d-none" aria-hidden="true">
            <div id="calendarWrap"></div>
            <div class="cv-calendar-actions">
              <button type="button" class="btn cv-calendar-close-btn" data-bs-dismiss="modal">Chiudi</button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade" id="passengersModal" tabindex="-1" aria-labelledby="passengersModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content cv-modal">
        <div class="modal-header">
          <h5 class="modal-title" id="passengersModalTitle">Passeggeri</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
        </div>
        <div class="modal-body">
          <div class="cv-passenger-row">
            <div>
              <p class="cv-passenger-title mb-0">Adulti</p>
              <small class="cv-passenger-note">Da 12 anni</small>
            </div>
            <div class="cv-stepper">
              <button type="button" class="btn cv-step-btn" data-action="minus" data-target="ad">-</button>
              <span id="adultsValue" class="cv-step-value"><?= (int) $ad ?></span>
              <button type="button" class="btn cv-step-btn" data-action="plus" data-target="ad">+</button>
            </div>
          </div>
          <div class="cv-passenger-row">
            <div>
              <p class="cv-passenger-title mb-0">Bambini</p>
              <small class="cv-passenger-note">Fino a 11 anni</small>
            </div>
            <div class="cv-stepper">
              <button type="button" class="btn cv-step-btn" data-action="minus" data-target="bam">-</button>
              <span id="childrenValue" class="cv-step-value"><?= (int) $bam ?></span>
              <button type="button" class="btn cv-step-btn" data-action="plus" data-target="bam">+</button>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn cv-cookie-btn-outline" data-bs-dismiss="modal">Chiudi</button>
          <button type="button" class="btn cv-modal-primary" id="applyPassengersBtn">Aggiorna risultati</button>
        </div>
      </div>
    </div>
  </div>

  <?= cvRenderSiteAuthModals() ?>

  <script>
    window.CV_LOADER_MESSAGE = 'Attendi';
    window.CV_STOPS = <?= $stopsJson ?>;
    window.CV_TODAY_ISO = <?= json_encode($todayIso, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    window.CV_TODAY_IT = <?= json_encode($todayIt, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    window.CV_SOLUTIONS_DATA = <?= $searchDataJson ?>;
    window.CV_DATE_PRICE_MAP = <?= json_encode($datePriceMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    window.CV_PROVIDER_LOGOS = <?= json_encode($providerLogoMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    window.CV_SOLUTIONS_CONFIG = {
      validateUrl: './soluzioni_quote.php',
      deferredUrl: './soluzioni.php',
      maxTransfers: <?= (int) $maxTransfers ?>
    };
  </script>
  <?= cvRenderNamedAssetBundle('public-core-js') ?>
  <?= cvRenderNamedAssetBundle('public-date-js') ?>
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin="anonymous"></script>
  <?= cvRenderNamedAssetBundle('public-app-js') ?>
  <?= cvRenderNamedAssetBundle('public-soluzioni-js') ?>
</body>
</html>
