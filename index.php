<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/site_layout.php';
require_once __DIR__ . '/includes/conn.php';
require_once __DIR__ . '/includes/runtime_settings.php';
require_once __DIR__ . '/auth/config.php';

$todayIso = cvTodayIsoDate();
$todayIt = cvIsoToItDate($todayIso);

$stops = [];
$requestedPopularRoutes = [];
$providerPopularRoutes = [];
$homeImages = [];
$popularImages = [];
$providerLogos = [];
$providerNameMap = [];
$seoRouteUrls = [];
$connection = null;

/**
 * @return array<int,string>
 */
function cvCollectLocalImages(string $dirPath, string $assetPrefix): array
{
    if (!is_dir($dirPath)) {
        return [];
    }

    $paths = glob(rtrim($dirPath, '/') . '/*.{jpg,jpeg,png,webp}', GLOB_BRACE);
    if (!is_array($paths)) {
        return [];
    }

    $images = [];
    foreach ($paths as $path) {
        if (!is_file($path)) {
            continue;
        }
        $images[] = cvAsset($assetPrefix . '/' . basename($path));
    }

    return $images;
}

/**
 * @param array<string,string> $providerNameMap
 * @return array<int,array<string,string>>
 */
function cvCollectProviderLogos(string $dirPath, string $assetPrefix, array $providerNameMap = []): array
{
    if (!is_dir($dirPath)) {
        return [];
    }

    $paths = glob(rtrim($dirPath, '/') . '/*.{svg,png,webp,jpg,jpeg}', GLOB_BRACE);
    if (!is_array($paths)) {
        return [];
    }

    sort($paths, SORT_NATURAL | SORT_FLAG_CASE);
    $logos = [];
    foreach ($paths as $path) {
        if (!is_file($path)) {
            continue;
        }

        $filename = pathinfo($path, PATHINFO_FILENAME);
        $providerCode = strtolower((string) preg_replace('/^logo[_-]?/i', '', $filename));
        $providerName = $providerNameMap[$providerCode] ?? ucwords(str_replace(['-', '_'], ' ', $providerCode));

        $logos[] = [
            'code' => $providerCode,
            'name' => trim($providerName) !== '' ? trim($providerName) : 'Partner',
            'src' => cvAsset($assetPrefix . '/' . basename($path)),
        ];
    }

    return $logos;
}

function cvPopularDemandLabel(int $searchCount, int $index, int $total, int $maxCount, int $minCount): string
{
    if ($total <= 1) {
        return 'Tra le più richieste';
    }

    if ($maxCount <= $minCount) {
        if ($index === 0) {
            return 'Tra le più richieste';
        }
        if ($index < max(2, (int) ceil($total / 3))) {
            return 'Interesse alto';
        }
        if ($index < max(4, (int) ceil(($total * 2) / 3))) {
            return 'Richiesta spesso';
        }

        return 'In evidenza oggi';
    }

    $ratio = ($searchCount - $minCount) / max(1, $maxCount - $minCount);
    if ($ratio >= 0.82) {
        return 'Tra le più richieste';
    }
    if ($ratio >= 0.56) {
        return 'Interesse alto';
    }
    if ($ratio >= 0.3) {
        return 'Richiesta spesso';
    }

    return 'In crescita';
}

function cvPopularLooksTechnicalLabel(string $value): bool
{
    $trimmed = trim($value);
    if ($trimmed === '') {
        return true;
    }
    return preg_match('/^[0-9]+$/', $trimmed) === 1 || strpos($trimmed, '|') !== false || strncmp($trimmed, 'r~', 2) === 0;
}

function cvPopularResolveStopName(?mysqli $connection, string $providerCode, string $stopId, string $fallback): string
{
    $fallback = trim($fallback);
    if ($fallback !== '' && !cvPopularLooksTechnicalLabel($fallback)) {
        return $fallback;
    }
    $providerCode = strtolower(trim($providerCode));
    $stopId = trim($stopId);
    if (!$connection instanceof mysqli || $providerCode === '' || $stopId === '') {
        return $fallback !== '' ? $fallback : 'Fermata selezionata';
    }

    static $cache = [];
    $cacheKey = $providerCode . '|' . $stopId;
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $name = '';
    $sql = "SELECT s.name
            FROM cv_provider_stops s
            INNER JOIN cv_providers p ON p.id_provider = s.id_provider
            WHERE p.code = ?
              AND s.is_active = 1
              AND (BINARY s.external_id = BINARY ? OR s.id = CAST(? AS UNSIGNED))
            ORDER BY (BINARY s.external_id = BINARY ?) DESC
            LIMIT 1";
    $stmt = $connection->prepare($sql);
    if ($stmt instanceof mysqli_stmt) {
        $stmt->bind_param('ssss', $providerCode, $stopId, $stopId, $stopId);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            if ($result instanceof mysqli_result) {
                $row = $result->fetch_assoc();
                if (is_array($row)) {
                    $name = trim((string) ($row['name'] ?? ''));
                }
                $result->free();
            }
        }
        $stmt->close();
    }

    if ($name === '') {
        $name = $fallback !== '' ? $fallback : 'Fermata selezionata';
    }
    $cache[$cacheKey] = $name;
    return $name;
}

function cvPopularLooksUnresolvedLabel(string $value): bool
{
    $trimmed = trim($value);
    if ($trimmed === '') {
        return true;
    }
    if (cvPopularLooksTechnicalLabel($trimmed)) {
        return true;
    }

    $normalized = function_exists('cvPlaceNormalizeLookup')
        ? cvPlaceNormalizeLookup($trimmed)
        : strtolower($trimmed);

    return $normalized === 'fermata selezionata';
}

/**
 * @return array<string,string>
 */
function cvPopularConvertPlaceRefsToProviderRefs(
    ?mysqli $connection,
    string $fromRef,
    string $toRef,
    string $providerHint = ''
): array {
    $fromRef = trim($fromRef);
    $toRef = trim($toRef);
    if (!$connection instanceof mysqli || $fromRef === '' || $toRef === '') {
        return ['from_ref' => $fromRef, 'to_ref' => $toRef, 'from_name' => '', 'to_name' => ''];
    }

    $fromParsed = cvSearchRouteParseRef($fromRef);
    $toParsed = cvSearchRouteParseRef($toRef);
    if (($fromParsed['provider_code'] ?? '') !== 'place' || ($toParsed['provider_code'] ?? '') !== 'place') {
        return ['from_ref' => $fromRef, 'to_ref' => $toRef, 'from_name' => '', 'to_name' => ''];
    }
    if (!function_exists('cvPlacesTablesExist') || !cvPlacesTablesExist($connection)) {
        return ['from_ref' => $fromRef, 'to_ref' => $toRef, 'from_name' => '', 'to_name' => ''];
    }
    if (!function_exists('cvPlacesExpandToProviderStops')) {
        return ['from_ref' => $fromRef, 'to_ref' => $toRef, 'from_name' => '', 'to_name' => ''];
    }

    $fromPlaceId = (int) ($fromParsed['stop_external_id'] ?? '0');
    $toPlaceId = (int) ($toParsed['stop_external_id'] ?? '0');
    if ($fromPlaceId <= 0 || $toPlaceId <= 0) {
        return ['from_ref' => $fromRef, 'to_ref' => $toRef, 'from_name' => '', 'to_name' => ''];
    }

    $fromCandidates = cvPlacesExpandToProviderStops($connection, $fromPlaceId, 40);
    $toCandidates = cvPlacesExpandToProviderStops($connection, $toPlaceId, 40);
    if (count($fromCandidates) === 0 || count($toCandidates) === 0) {
        return ['from_ref' => $fromRef, 'to_ref' => $toRef, 'from_name' => '', 'to_name' => ''];
    }

    $fromByProvider = [];
    foreach ($fromCandidates as $candidate) {
        if (!is_array($candidate)) {
            continue;
        }
        $providerCode = strtolower(trim((string) ($candidate['provider_code'] ?? '')));
        $externalId = trim((string) ($candidate['external_id'] ?? ''));
        if ($providerCode === '' || $externalId === '') {
            continue;
        }
        if (!isset($fromByProvider[$providerCode])) {
            $fromByProvider[$providerCode] = $candidate;
        }
    }

    $toByProvider = [];
    foreach ($toCandidates as $candidate) {
        if (!is_array($candidate)) {
            continue;
        }
        $providerCode = strtolower(trim((string) ($candidate['provider_code'] ?? '')));
        $externalId = trim((string) ($candidate['external_id'] ?? ''));
        if ($providerCode === '' || $externalId === '') {
            continue;
        }
        if (!isset($toByProvider[$providerCode])) {
            $toByProvider[$providerCode] = $candidate;
        }
    }

    $commonProviders = array_values(array_intersect(array_keys($fromByProvider), array_keys($toByProvider)));
    if (count($commonProviders) === 0) {
        return ['from_ref' => $fromRef, 'to_ref' => $toRef, 'from_name' => '', 'to_name' => ''];
    }

    $providerHint = strtolower(trim($providerHint));
    $chosenProvider = in_array($providerHint, $commonProviders, true) ? $providerHint : $commonProviders[0];
    $fromCandidate = $fromByProvider[$chosenProvider] ?? null;
    $toCandidate = $toByProvider[$chosenProvider] ?? null;
    if (!is_array($fromCandidate) || !is_array($toCandidate)) {
        return ['from_ref' => $fromRef, 'to_ref' => $toRef, 'from_name' => '', 'to_name' => ''];
    }

    $fromExternalId = trim((string) ($fromCandidate['external_id'] ?? ''));
    $toExternalId = trim((string) ($toCandidate['external_id'] ?? ''));
    if ($fromExternalId === '' || $toExternalId === '') {
        return ['from_ref' => $fromRef, 'to_ref' => $toRef, 'from_name' => '', 'to_name' => ''];
    }

    return [
        'from_ref' => $chosenProvider . '|' . $fromExternalId,
        'to_ref' => $chosenProvider . '|' . $toExternalId,
        'from_name' => trim((string) ($fromCandidate['name'] ?? '')),
        'to_name' => trim((string) ($toCandidate['name'] ?? '')),
    ];
}

/**
 * @param array<string,mixed> $route
 * @return array<string,mixed>|null
 */
function cvPopularNormalizeRoute(?mysqli $connection, array $route): ?array
{
    $providerCode = strtolower(trim((string) ($route['provider_code'] ?? '')));
    $fromId = trim((string) ($route['from_id'] ?? ''));
    $toId = trim((string) ($route['to_id'] ?? ''));
    $fromRef = trim((string) ($route['from_ref'] ?? ''));
    $toRef = trim((string) ($route['to_ref'] ?? ''));

    if ($fromRef === '') {
        $fromRef = ($providerCode !== '' && $fromId !== '') ? ($providerCode . '|' . $fromId) : $fromId;
    }
    if ($toRef === '') {
        $toRef = ($providerCode !== '' && $toId !== '') ? ($providerCode . '|' . $toId) : $toId;
    }
    if ($fromRef === '' || $toRef === '') {
        return null;
    }

    $fromName = trim((string) ($route['from_name'] ?? ''));
    $toName = trim((string) ($route['to_name'] ?? ''));

    $converted = cvPopularConvertPlaceRefsToProviderRefs($connection, $fromRef, $toRef, $providerCode);
    if (($converted['from_ref'] ?? '') !== '' && ($converted['to_ref'] ?? '') !== '') {
        $fromRef = (string) ($converted['from_ref'] ?? $fromRef);
        $toRef = (string) ($converted['to_ref'] ?? $toRef);
        if (trim((string) ($converted['from_name'] ?? '')) !== '') {
            $fromName = trim((string) $converted['from_name']);
        }
        if (trim((string) ($converted['to_name'] ?? '')) !== '') {
            $toName = trim((string) $converted['to_name']);
        }
    }

    $fromParsed = cvSearchRouteParseRef($fromRef);
    $toParsed = cvSearchRouteParseRef($toRef);
    $fromProviderCode = trim((string) ($fromParsed['provider_code'] ?? ''));
    $toProviderCode = trim((string) ($toParsed['provider_code'] ?? ''));
    $fromStopId = trim((string) ($fromParsed['stop_external_id'] ?? $fromId));
    $toStopId = trim((string) ($toParsed['stop_external_id'] ?? $toId));

    if (cvPopularLooksUnresolvedLabel($fromName)) {
        if ($connection instanceof mysqli) {
            $fromName = cvSearchRouteResolveRefName($connection, $fromRef, $fromName !== '' ? $fromName : $fromStopId);
        }
    }
    if (cvPopularLooksUnresolvedLabel($toName)) {
        if ($connection instanceof mysqli) {
            $toName = cvSearchRouteResolveRefName($connection, $toRef, $toName !== '' ? $toName : $toStopId);
        }
    }
    if (cvPopularLooksUnresolvedLabel($fromName)) {
        $fromName = cvPopularResolveStopName($connection, $fromProviderCode, $fromStopId, $fromName);
    }
    if (cvPopularLooksUnresolvedLabel($toName)) {
        $toName = cvPopularResolveStopName($connection, $toProviderCode, $toStopId, $toName);
    }
    if (cvPopularLooksUnresolvedLabel($fromName) || cvPopularLooksUnresolvedLabel($toName)) {
        return null;
    }

    $route['from_ref'] = $fromRef;
    $route['to_ref'] = $toRef;
    $route['from_name'] = $fromName;
    $route['to_name'] = $toName;
    $route['from_id'] = $fromStopId;
    $route['to_id'] = $toStopId;
    return $route;
}

/**
 * @param array<int,array<string,mixed>> $routes
 * @return array<int,array<string,mixed>>
 */
function cvPopularNormalizeRouteList(?mysqli $connection, array $routes): array
{
    $normalized = [];
    foreach ($routes as $route) {
        if (!is_array($route)) {
            continue;
        }
        $item = cvPopularNormalizeRoute($connection, $route);
        if (is_array($item)) {
            $normalized[] = $item;
        }
    }
    return $normalized;
}

try {
    $connection = cvDbConnection();
    $stops = cvFetchSearchEntries($connection);

    $settings = cvRuntimeSettings($connection);
    $selectedPopularProviderCodes = cvRuntimeSettingCsvList($settings['homepage_popular_provider_codes'] ?? '');
    $popularProviderLimits = cvRuntimeSettingJsonMap($settings['homepage_popular_provider_limits'] ?? '');
    $providerPriceModes = cvRuntimeProviderPriceModeMap($connection);
    $requestedPopularRoutes = cvFetchMostRequestedRoutes($connection, 10, $providerPriceModes);
    $popularPerProvider = isset($settings['homepage_popular_per_provider'])
        ? (int) $settings['homepage_popular_per_provider']
        : 4;
    $filteredPopularProviderCodes = [];

    $activeProviderCodes = [];
    $providersResult = $connection->query("SELECT code, name FROM cv_providers WHERE is_active = 1");
    if ($providersResult instanceof mysqli_result) {
        while ($providerRow = $providersResult->fetch_assoc()) {
            if (!is_array($providerRow)) {
                continue;
            }

            $providerCode = strtolower(trim((string) ($providerRow['code'] ?? '')));
            if ($providerCode !== '') {
                $activeProviderCodes[$providerCode] = $providerCode;
                $providerNameMap[$providerCode] = trim((string) ($providerRow['name'] ?? ''));
            }
        }
        $providersResult->free();
    }

    if (count($selectedPopularProviderCodes) > 0) {
        foreach ($selectedPopularProviderCodes as $providerCode) {
            if (isset($activeProviderCodes[$providerCode])) {
                $filteredPopularProviderCodes[] = $providerCode;
            }
        }
    }

    $providerCodesForPopular = count($filteredPopularProviderCodes) > 0
        ? $filteredPopularProviderCodes
        : array_values($activeProviderCodes);

    $providerLimits = [];
    foreach ($providerCodesForPopular as $providerCode) {
        $limit = $popularPerProvider;
        if (isset($popularProviderLimits[$providerCode])) {
            $limit = (int) $popularProviderLimits[$providerCode];
        }
        $providerLimits[$providerCode] = $limit;
    }

    $providerPopularRoutes = cvFetchHomepageProviderFeaturedRoutes($connection, $providerLimits, $providerPriceModes);
    $requestedPopularRoutes = cvPopularNormalizeRouteList($connection, $requestedPopularRoutes);
    $providerPopularRoutes = cvPopularNormalizeRouteList($connection, $providerPopularRoutes);

    if (function_exists('cvRouteSeoApprovedUrlMapByRefs')) {
        $routePairs = [];
        foreach ($requestedPopularRoutes as $route) {
            if (!is_array($route)) {
                continue;
            }
            $fromRef = trim((string) ($route['from_ref'] ?? ''));
            $toRef = trim((string) ($route['to_ref'] ?? ''));
            if ($fromRef !== '' && $toRef !== '') {
                $routePairs[] = ['from_ref' => $fromRef, 'to_ref' => $toRef];
            }
        }
        foreach ($providerPopularRoutes as $route) {
            if (!is_array($route)) {
                continue;
            }
            $fromRef = trim((string) ($route['from_ref'] ?? ''));
            $toRef = trim((string) ($route['to_ref'] ?? ''));
            if ($fromRef !== '' && $toRef !== '') {
                $routePairs[] = ['from_ref' => $fromRef, 'to_ref' => $toRef];
            }
        }
        if (count($routePairs) > 0) {
            $seoRouteUrls = cvRouteSeoApprovedUrlMapByRefs($connection, $routePairs);
        }
    }
} catch (Throwable $exception) {
    error_log('cercaviaggio homepage load warning: ' . $exception->getMessage());
}

$homeImages = cvCollectLocalImages(__DIR__ . '/assets/images/home', 'images/home');
$popularImages = cvCollectLocalImages(__DIR__ . '/assets/images/popular', 'images/popular');
$providerLogos = cvCollectProviderLogos(__DIR__ . '/assets/images/providers', 'images/providers', $providerNameMap);

$stopsJson = json_encode($stops, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($stopsJson)) {
    $stopsJson = '[]';
}
?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Cercaviaggio</title>
  <meta name="description" content="Motore di ricerca viaggi in autobus multi-azienda.">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Source+Sans+3:wght@400;600;700&display=swap" rel="stylesheet">
  <?= cvRenderNamedAssetBundle('public-base-css') ?>
  <?= cvRenderNamedAssetBundle('public-date-css') ?>
  <?= cvRenderNamedAssetBundle('public-app-css') ?>
</head>
<body>
  <div class="cv-page-bg"></div>
  <section class="cv-hero-stage" id="ricerca">
    <?php if (count($homeImages) > 0): ?>
      <div class="cv-hero-media">
        <?php if (count($homeImages) === 1): ?>
          <div class="cv-hero-media-item" style="background-image:url('<?= htmlspecialchars($homeImages[0], ENT_QUOTES, 'UTF-8') ?>');"></div>
        <?php else: ?>
          <div id="homeHeroCarousel" class="carousel slide" data-bs-ride="carousel">
            <div class="carousel-inner">
              <?php foreach ($homeImages as $index => $image): ?>
                <div class="carousel-item<?= $index === 0 ? ' active' : '' ?>">
                  <div class="cv-hero-media-item" style="background-image:url('<?= htmlspecialchars($image, ENT_QUOTES, 'UTF-8') ?>');"></div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>
        <div class="cv-hero-overlay"></div>
      </div>
    <?php endif; ?>

    <div class="container cv-shell py-4 py-lg-5">
      <?= cvRenderSiteHeader(['active' => 'home']) ?>

      <div class="cv-hero">
        <div class="cv-copy mb-3 mb-lg-4">
          <p class="cv-eyebrow mb-2">Motore di ricerca autobus</p>
          <h1 class="cv-title mb-2">Trova il viaggio giusto in pochi secondi</h1>
          <p class="cv-subtitle mb-0">Confronta aziende, orari e prezzi in un'unica schermata.</p>
        </div>

        <div class="cv-search-wrap">
          <form id="searchForm" class="cv-search-form" novalidate>
            <input type="hidden" id="partId" name="part" value="">
            <input type="hidden" id="arrId" name="arr" value="">
            <input type="hidden" id="adCount" name="ad" value="1">
            <input type="hidden" id="bamCount" name="bam" value="0">
            <input type="hidden" id="dt1Field" name="dt1" value="<?= htmlspecialchars($todayIt, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" id="dt2Field" name="dt2" value="">
            <input type="hidden" id="tripModeField" name="trip_mode" value="oneway">

            <div class="row g-3 g-lg-4 align-items-end">
              <div class="col-12 col-lg-5">
                <label for="fromCity" class="cv-label">Partenza da</label>
                <div class="cv-input-shell cv-autocomplete cv-input-actions cv-input-actions-from">
                  <i class="bi bi-geo-alt cv-input-icon"></i>
                  <input type="text" class="form-control cv-input cv-stop-input" id="fromCity" placeholder="Seleziona partenza" autocomplete="off">
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
                  <input type="text" class="form-control cv-input cv-stop-input" id="toCity" placeholder="Seleziona destinazione" autocomplete="off">
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
                  <span class="cv-picker-value" id="departurePickerValue">Oggi (<?= htmlspecialchars($todayIt, ENT_QUOTES, 'UTF-8') ?>)</span>
                </button>
              </div>

              <div class="col-12 col-md-6 col-lg-3">
                <button type="button" id="returnPickerBtn" class="btn cv-picker-btn w-100 cv-picker-btn-return-inactive" data-field="return">
                  <span class="cv-picker-label"><i class="bi bi-calendar-check"></i> Ritorno</span>
                  <span class="cv-picker-value" id="returnPickerValue">+ Ritorno</span>
                </button>
                <div id="returnError" class="cv-field-error d-none"></div>
              </div>

              <div class="col-12 col-lg-3">
                <button type="button" id="passengersBtn" class="btn cv-picker-btn w-100" data-bs-toggle="modal" data-bs-target="#passengersModal">
                  <span class="cv-picker-label"><i class="bi bi-people"></i> Passeggeri</span>
                  <span class="cv-picker-value" id="passengersSummary">1 adulto</span>
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
      </div>
    </div>
  </section>

  <main class="container cv-shell pb-4 pb-lg-5">

    <section class="cv-popular" id="viaggi-popolari">
      <div class="mb-3 mb-lg-4">
        <h2 class="cv-section-title mb-0">Viaggi popolari</h2>
      </div>

      <?php if (count($requestedPopularRoutes) > 0): ?>
        <?php
        $requestedPopularCounts = array_map(
            static fn(array $route): int => isset($route['search_count']) ? (int) $route['search_count'] : 0,
            $requestedPopularRoutes
        );
        $requestedPopularMax = count($requestedPopularCounts) > 0 ? max($requestedPopularCounts) : 0;
        $requestedPopularMin = count($requestedPopularCounts) > 0 ? min($requestedPopularCounts) : 0;
        ?>
        <div class="mb-2">
          <h3 class="cv-subtitle mb-1"><strong>Più richiesti</strong></h3>
          <p class="cv-route-meta mb-0">Ordinate in base all'interesse reale registrato nelle ricerche.</p>
        </div>
        <div class="row g-3 g-lg-4 mb-2 mb-lg-3">
          <?php foreach ($requestedPopularRoutes as $routeIndex => $route): ?>
            <?php
            $image = '';
            if (count($popularImages) > 0) {
                $image = $popularImages[array_rand($popularImages)];
            } elseif (count($homeImages) > 0) {
                $image = $homeImages[0];
            }
            $fromName = (string) ($route['from_name'] ?? '');
            $toName = (string) ($route['to_name'] ?? '');
            $fromId = (string) ($route['from_id'] ?? '');
            $toId = (string) ($route['to_id'] ?? '');
            $providerCode = (string) ($route['provider_code'] ?? '');
            $providerName = (string) ($route['provider_name'] ?? '');
            $fromRef = trim((string) ($route['from_ref'] ?? ''));
            $toRef = trim((string) ($route['to_ref'] ?? ''));
            if ($fromRef === '') {
                $fromRef = ($providerCode !== '' && $fromId !== '') ? ($providerCode . '|' . $fromId) : $fromId;
            }
            if ($toRef === '') {
                $toRef = ($providerCode !== '' && $toId !== '') ? ($providerCode . '|' . $toId) : $toId;
            }
            $fromName = cvPopularResolveStopName($connection, $providerCode, $fromId, $fromName);
            $toName = cvPopularResolveStopName($connection, $providerCode, $toId, $toName);
            $seoUrl = isset($seoRouteUrls[$fromRef . '|' . $toRef]) ? (string) $seoRouteUrls[$fromRef . '|' . $toRef] : '';
            $soluzioniUrl = './soluzioni.php?' . http_build_query([
                'part' => $fromRef,
                'arr' => $toRef,
                'frm' => $fromName,
                'arm' => $toName,
                'ad' => 1,
                'bam' => 0,
                'mode' => 'oneway',
                'trip_mode' => 'oneway',
                'dt1' => $todayIt,
                'fast' => 1,
            ]);
            $minAmount = isset($route['display_amount']) ? (float) $route['display_amount'] : (isset($route['min_amount']) ? (float) $route['min_amount'] : 0.0);
            $searchCount = isset($route['search_count']) ? (int) $route['search_count'] : 0;
            $demandLabel = cvPopularDemandLabel($searchCount, (int) $routeIndex, count($requestedPopularRoutes), $requestedPopularMax, $requestedPopularMin);
            ?>
            <div class="col-12 col-md-6 col-xl-3">
              <article class="cv-route-card">
                <div class="cv-route-media"<?= $image !== '' ? ' style="background-image:url(\'' . htmlspecialchars($image, ENT_QUOTES, 'UTF-8') . '\');"' : '' ?>></div>
                <div class="cv-route-body">
                  <h3 class="cv-route-title"><?= htmlspecialchars($fromName, ENT_QUOTES, 'UTF-8') ?> <i class="bi bi-arrow-right"></i> <?= htmlspecialchars($toName, ENT_QUOTES, 'UTF-8') ?></h3>
                  <p class="cv-route-meta cv-route-interest mb-1"><i class="bi bi-graph-up-arrow"></i> <?= htmlspecialchars($demandLabel, ENT_QUOTES, 'UTF-8') ?></p>
                  <?php if ($providerName !== ''): ?>
                    <p class="cv-route-meta mb-1"><i class="bi bi-bus-front"></i> <?= htmlspecialchars($providerName, ENT_QUOTES, 'UTF-8') ?></p>
                  <?php endif; ?>
                  <?php if ($minAmount > 0): ?>
                    <p class="cv-route-meta mb-2"><i class="bi bi-currency-euro"></i> da <?= htmlspecialchars(cvFormatEuro($minAmount), ENT_QUOTES, 'UTF-8') ?></p>
                  <?php else: ?>
                    <p class="cv-route-meta mb-2"><i class="bi bi-search"></i> Prezzo disponibile in fase di ricerca</p>
                  <?php endif; ?>
                  <?php if ($seoUrl !== ''): ?>
                    <p class="cv-route-meta mb-2"><i class="bi bi-journal-text"></i> Guida tratta</p>
                  <?php endif; ?>
                  <div class="d-flex align-items-center justify-content-end">
                    <a class="btn cv-route-cta" href="<?= htmlspecialchars($soluzioniUrl, ENT_QUOTES, 'UTF-8') ?>">Viaggia</a>
                  </div>
                </div>
              </article>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if (count($providerPopularRoutes) > 0): ?>
        <div class="mb-2 mt-3 mt-lg-4">
          <h3 class="cv-subtitle mb-1"><strong>In evidenza per provider</strong></h3>
          <p class="cv-route-meta mb-0">Tratte scelte direttamente dai provider e mostrate entro i limiti definiti da backend.</p>
        </div>
        <div class="row g-3 g-lg-4">
          <?php foreach ($providerPopularRoutes as $route): ?>
            <?php
            $image = '';
            if (count($popularImages) > 0) {
                $image = $popularImages[array_rand($popularImages)];
            } elseif (count($homeImages) > 0) {
                $image = $homeImages[0];
            }
            $fromName = (string) ($route['from_name'] ?? '');
            $toName = (string) ($route['to_name'] ?? '');
            $fromId = (string) ($route['from_id'] ?? '');
            $toId = (string) ($route['to_id'] ?? '');
            $providerCode = (string) ($route['provider_code'] ?? '');
            $providerName = (string) ($route['provider_name'] ?? '');
            $fromRef = trim((string) ($route['from_ref'] ?? ''));
            $toRef = trim((string) ($route['to_ref'] ?? ''));
            if ($fromRef === '') {
                $fromRef = ($providerCode !== '' && $fromId !== '') ? ($providerCode . '|' . $fromId) : $fromId;
            }
            if ($toRef === '') {
                $toRef = ($providerCode !== '' && $toId !== '') ? ($providerCode . '|' . $toId) : $toId;
            }
            $fromName = cvPopularResolveStopName($connection, $providerCode, $fromId, $fromName);
            $toName = cvPopularResolveStopName($connection, $providerCode, $toId, $toName);
            $seoUrl = isset($seoRouteUrls[$fromRef . '|' . $toRef]) ? (string) $seoRouteUrls[$fromRef . '|' . $toRef] : '';
            $soluzioniUrl = './soluzioni.php?' . http_build_query([
                'part' => $fromRef,
                'arr' => $toRef,
                'frm' => $fromName,
                'arm' => $toName,
                'ad' => 1,
                'bam' => 0,
                'mode' => 'oneway',
                'trip_mode' => 'oneway',
                'dt1' => $todayIt,
                'fast' => 1,
            ]);
            $minAmount = isset($route['display_amount']) ? (float) $route['display_amount'] : (isset($route['min_amount']) ? (float) $route['min_amount'] : 0.0);
            ?>
            <div class="col-12 col-md-6 col-xl-3">
              <article class="cv-route-card">
                <div class="cv-route-media"<?= $image !== '' ? ' style="background-image:url(\'' . htmlspecialchars($image, ENT_QUOTES, 'UTF-8') . '\');"' : '' ?>></div>
                <div class="cv-route-body">
                  <h3 class="cv-route-title"><?= htmlspecialchars($fromName, ENT_QUOTES, 'UTF-8') ?> <i class="bi bi-arrow-right"></i> <?= htmlspecialchars($toName, ENT_QUOTES, 'UTF-8') ?></h3>
                  <?php if ($providerName !== ''): ?>
                    <p class="cv-route-meta mb-1"><i class="bi bi-bus-front"></i> <?= htmlspecialchars($providerName, ENT_QUOTES, 'UTF-8') ?></p>
                  <?php endif; ?>
                  <?php if ($minAmount > 0): ?>
                    <p class="cv-route-meta mb-2"><i class="bi bi-currency-euro"></i> da <?= htmlspecialchars(cvFormatEuro($minAmount), ENT_QUOTES, 'UTF-8') ?></p>
                  <?php else: ?>
                    <p class="cv-route-meta mb-2"><i class="bi bi-search"></i> Prezzo disponibile in fase di ricerca</p>
                  <?php endif; ?>
                  <?php if ($seoUrl !== ''): ?>
                    <p class="cv-route-meta mb-2"><i class="bi bi-journal-text"></i> Guida tratta</p>
                  <?php endif; ?>
                  <div class="d-flex align-items-center justify-content-end">
                    <a class="btn cv-route-cta" href="<?= htmlspecialchars($soluzioniUrl, ENT_QUOTES, 'UTF-8') ?>">Viaggia</a>
                  </div>
                </div>
              </article>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if (count($requestedPopularRoutes) === 0 && count($providerPopularRoutes) === 0): ?>
        <div class="row g-3 g-lg-4">
          <div class="col-12">
            <div class="cv-panel-card">
              <div class="cv-empty">Nessun viaggio popolare disponibile al momento.</div>
            </div>
          </div>
        </div>
      <?php endif; ?>
    </section>

    <section class="mt-4 mt-lg-5 mb-4">
      <div class="row g-3 g-lg-4">
        <div class="col-12 col-lg-6">
          <article class="cv-panel-card h-100">
            <h3 class="cv-subtitle mb-2"><strong>Recupera biglietto</strong></h3>
            <form method="get" action="./biglietti.php" class="row g-2 align-items-end">
              <div class="col-12 col-md-8">
                <label for="ticketLookupCode" class="cv-label">Codice biglietto</label>
                <input
                  type="text"
                  class="form-control cv-input"
                  id="ticketLookupCode"
                  name="code"
                  maxlength="80"
                  pattern="[A-Za-z0-9_.:-]{3,80}"
                  placeholder="Es. 64060A4826A3"
                  autocomplete="off"
                >
              </div>
              <div class="col-12 col-md-4">
                <label class="cv-label cv-label-placeholder d-none d-md-block" aria-hidden="true">&nbsp;</label>
                <button type="submit" class="btn cv-cookie-btn-outline cv-input-height-btn w-100">
                  <i class="bi bi-qr-code-scan me-1"></i>
                  Recupera
                </button>
              </div>
            </form>
          </article>
        </div>

        <div class="col-12 col-lg-6">
          <article class="cv-panel-card h-100">
            <h3 class="cv-subtitle mb-2"><strong>Iscriviti alla newsletter</strong></h3>
            <form id="guestNewsletterForm" class="row g-2 align-items-end" novalidate>
              <div class="col-12 col-md-8">
                <label for="guestNewsletterEmail" class="cv-label">Email</label>
                <input
                  type="email"
                  class="form-control cv-input"
                  id="guestNewsletterEmail"
                  maxlength="190"
                  placeholder="nome@email.it"
                  autocomplete="email"
                  required
                >
              </div>
              <div class="col-12 col-md-4">
                <label class="cv-label cv-label-placeholder d-none d-md-block" aria-hidden="true">&nbsp;</label>
                <button type="submit" class="btn cv-cookie-btn-outline cv-input-height-btn w-100">
                  <i class="bi bi-envelope-check me-1"></i>
                  Iscrivimi
                </button>
              </div>
              <div class="col-12">
                <div id="guestNewsletterFeedback" class="cv-route-meta mb-0" aria-live="polite"></div>
              </div>
            </form>
          </article>
        </div>
      </div>
    </section>

    <?php if (count($providerLogos) > 0): ?>
      <section class="cv-partners-strip-section" aria-label="Partner integrati">
        <div class="cv-partners-strip">
          <div class="cv-partners-strip-copy">
            <span class="cv-partners-strip-kicker">I nostri partner</span>
            <p class="cv-partners-strip-text mb-0">
              Le aziende gia integrate su Cercaviaggio, confrontabili in un'unica ricerca.
            </p>
          </div>
          <div class="cv-partners-logo-list" role="list">
            <?php foreach ($providerLogos as $logo): ?>
              <div class="cv-partners-logo-pill" role="listitem" aria-label="<?= htmlspecialchars($logo['name'], ENT_QUOTES, 'UTF-8') ?>">
                <img
                  src="<?= htmlspecialchars($logo['src'], ENT_QUOTES, 'UTF-8') ?>"
                  alt="<?= htmlspecialchars($logo['name'], ENT_QUOTES, 'UTF-8') ?>"
                  class="cv-partners-logo"
                  loading="lazy"
                >
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </section>
    <?php endif; ?>

  </main>

  <?= cvRenderSiteAuthModals() ?>

  <footer class="container cv-shell pb-4">
    <div class="cv-site-footer">
      <span>&copy; <?= date('Y') ?> <a href="https://fillbus.it" target="_blank" rel="noopener noreferrer">fillbus.it</a></span>
      <div class="cv-site-footer-links">
        <a href="./privacy.php">Privacy Policy</a>
        <a href="./cookie.php">Cookie Policy</a>
        <a href="./faq.php">FAQ</a>
        <a href="./chi-siamo.php">Chi siamo</a>
        <a href="./mappa-fermate.php">Mappa fermate</a>
        <a href="./blog">Blog</a>
        <a href="./documentazione-endpoint.php">Endpoint / Documentazione</a>
        <a href="./partner.php">Diventa partner</a>
      </div>
    </div>
  </footer>

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
              <span id="adultsValue" class="cv-step-value">1</span>
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
              <span id="childrenValue" class="cv-step-value">0</span>
              <button type="button" class="btn cv-step-btn" data-action="plus" data-target="bam">+</button>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn cv-modal-primary" data-bs-dismiss="modal">Conferma</button>
        </div>
      </div>
    </div>
  </div>

  <script>
    window.CV_LOADER_MESSAGE = 'Attendi';
    window.CV_STOPS = <?= $stopsJson ?>;
    window.CV_TODAY_ISO = <?= json_encode($todayIso, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    window.CV_TODAY_IT = <?= json_encode($todayIt, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    window.CV_GOOGLE_CLIENT_ID = <?= json_encode((string) CV_GOOGLE_CLIENT_ID, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    (function () {
      var ticketForm = document.querySelector('form[action="./biglietti.php"]');
      var ticketInput = document.getElementById('ticketLookupCode');
      if (ticketForm && ticketInput) {
        ticketForm.addEventListener('submit', function (event) {
          var code = String(ticketInput.value || '').trim();
          if (!/^[A-Za-z0-9_.:-]{3,80}$/.test(code)) {
            event.preventDefault();
            ticketInput.classList.add('is-invalid');
            ticketInput.setAttribute('aria-invalid', 'true');
            if (typeof window.showMsg === 'function') {
              window.showMsg('Inserisci codice biglietto.', 0);
            }
            if (typeof ticketInput.focus === 'function') {
              ticketInput.focus();
            }
            return;
          }
          ticketInput.value = code;
          ticketInput.classList.remove('is-invalid');
          ticketInput.removeAttribute('aria-invalid');
        });

        ticketInput.addEventListener('input', function () {
          ticketInput.classList.remove('is-invalid');
          ticketInput.removeAttribute('aria-invalid');
        });
      }

      var form = document.getElementById('guestNewsletterForm');
      var emailInput = document.getElementById('guestNewsletterEmail');
      var feedback = document.getElementById('guestNewsletterFeedback');
      if (!form || !emailInput || !feedback) {
        return;
      }

      function setFeedback(message, ok) {
        feedback.textContent = String(message || '');
        feedback.style.color = ok ? '#1d5f2f' : '#9a2a2a';
      }

      form.addEventListener('submit', function (event) {
        event.preventDefault();
        var email = String(emailInput.value || '').trim().toLowerCase();
        if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
          setFeedback('Inserisci una email valida.', false);
          if (typeof window.showMsg === 'function') {
            window.showMsg('Inserisci una email valida.', 0);
          }
          return;
        }

        setFeedback('Invio richiesta in corso...', true);

        fetch('./auth/api.php?action=newsletter_guest_subscribe', {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
          },
          body: JSON.stringify({ email: email })
        })
          .then(function (response) {
            return response.json().catch(function () { return null; });
          })
          .then(function (payload) {
            if (!payload || payload.success !== true) {
              var message = payload && payload.message ? String(payload.message) : 'Impossibile completare la richiesta newsletter.';
              setFeedback(message, false);
              if (typeof window.showMsg === 'function') {
                window.showMsg(message, 0);
              }
              return;
            }

            setFeedback(String(payload.message || 'Controlla la tua email per confermare l’iscrizione.'), true);
            if (typeof window.showMsg === 'function') {
              window.showMsg(String(payload.message || 'Controlla la tua email per confermare l’iscrizione.'), 1);
            }
            emailInput.value = '';
          })
          .catch(function () {
            setFeedback('Errore di rete. Riprova tra poco.', false);
            if (typeof window.showMsg === 'function') {
              window.showMsg('Errore di rete. Riprova tra poco.', 0);
            }
          });
      });
    })();
  </script>
  <?= cvRenderNamedAssetBundle('public-core-js') ?>
  <?= cvRenderNamedAssetBundle('public-date-js') ?>
  <script src="https://accounts.google.com/gsi/client" async defer></script>
  <?= cvRenderNamedAssetBundle('public-app-js') ?>
</body>
</html>
