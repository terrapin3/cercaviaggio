<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/site_layout.php';
require_once __DIR__ . '/includes/conn.php';
require_once __DIR__ . '/includes/runtime_settings.php';

$slug = trim((string) ($_GET['slug'] ?? ''));
$page = null;
$draftPage = null;
$pageTitle = 'Tratta autobus | Cercaviaggio';
$metaDescription = 'Cerca collegamenti autobus multi-provider su Cercaviaggio.';
$canonicalUrl = rtrim(cvBaseUrl(), '/') . '/';
$searchUrl = rtrim(cvBaseUrl(), '/') . '/';
$robotsContent = 'index,follow';
$technical = [];
$fromStops = [];
$toStops = [];

try {
    $connection = cvDbConnection();
    $page = cvRouteSeoFetchPublicPageBySlug($connection, $slug);
    if (!is_array($page) && function_exists('cvRouteSeoFetchPageAnyStatusBySlug')) {
        $draftPage = cvRouteSeoFetchPageAnyStatusBySlug($connection, $slug);
    }
} catch (Throwable $exception) {
    error_log('tratta.php load warning: ' . $exception->getMessage());
}

if (is_array($page)) {
    $pageTitle = (string) ($page['effective_title'] ?? $pageTitle);
    $metaDescription = (string) ($page['effective_meta_description'] ?? $metaDescription);
    $canonicalUrl = (string) ($page['public_url'] ?? $canonicalUrl);
    $searchUrl = (string) ($page['search_url'] ?? $searchUrl);
    if (isset($connection) && $connection instanceof mysqli && function_exists('cvRouteSeoTechnicalSnapshot')) {
        $technical = cvRouteSeoTechnicalSnapshot($connection, $page);
    }
    if (isset($connection) && $connection instanceof mysqli && function_exists('cvRouteSeoStopsForRef')) {
        $fromStops = cvRouteSeoStopsForRef($connection, (string) ($page['from_ref'] ?? ''), 12);
        $toStops = cvRouteSeoStopsForRef($connection, (string) ($page['to_ref'] ?? ''), 12);
    }
} elseif (is_array($draftPage)) {
    $page = $draftPage;
    $pageTitle = (string) ($page['effective_title'] ?? $pageTitle);
    $metaDescription = (string) ($page['effective_meta_description'] ?? $metaDescription);
    $canonicalUrl = (string) ($page['public_url'] ?? $canonicalUrl);
    $searchUrl = (string) ($page['search_url'] ?? $searchUrl);
    $robotsContent = 'noindex,nofollow';
    if (isset($connection) && $connection instanceof mysqli && function_exists('cvRouteSeoTechnicalSnapshot')) {
        $technical = cvRouteSeoTechnicalSnapshot($connection, $page);
    }
    if (isset($connection) && $connection instanceof mysqli && function_exists('cvRouteSeoStopsForRef')) {
        $fromStops = cvRouteSeoStopsForRef($connection, (string) ($page['from_ref'] ?? ''), 12);
        $toStops = cvRouteSeoStopsForRef($connection, (string) ($page['to_ref'] ?? ''), 12);
    }
} else {
    http_response_code(404);
    $robotsContent = 'noindex,nofollow';
}

if (cvSeoDiscourageIndexing($connection ?? null)) {
    $robotsContent = 'noindex,nofollow';
}
?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
  <meta name="description" content="<?= htmlspecialchars($metaDescription, ENT_QUOTES, 'UTF-8') ?>">
  <?= cvRenderFaviconTags() ?>
  <meta name="robots" content="<?= htmlspecialchars($robotsContent, ENT_QUOTES, 'UTF-8') ?>">
  <link rel="canonical" href="<?= htmlspecialchars($canonicalUrl, ENT_QUOTES, 'UTF-8') ?>">
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Source+Sans+3:wght@400;600;700&display=swap" rel="stylesheet">
  <?= cvRenderNamedAssetBundle('public-base-css') ?>
  <?= cvRenderNamedAssetBundle('public-app-css') ?>
</head>
<body>
  <div class="cv-page-bg"></div>
  <main class="container cv-shell py-4 py-lg-5">
    <?= cvRenderSiteHeader() ?>

    <?php if (!is_array($page)): ?>
      <section class="cv-partner-card">
        <span class="cv-doc-badge">404</span>
        <h1 class="cv-title mt-3 mb-2">Pagina tratta non disponibile</h1>
        <p class="cv-subtitle mb-4">
          Questa guida tratta non è stata trovata oppure non è ancora stata approvata per la pubblicazione.
        </p>
        <div class="d-flex flex-wrap gap-2">
          <a href="./" class="btn cv-account-btn">Torna alla home</a>
          <a href="./faq.php" class="btn cv-account-secondary">Apri FAQ</a>
        </div>
      </section>
    <?php else: ?>
      <?php
      $heroStyle = '';
      $heroImageUrl = trim((string) ($page['hero_image_url'] ?? ''));
      if ($heroImageUrl !== '') {
          $heroStyle = " style=\"background-image:url('" . htmlspecialchars($heroImageUrl, ENT_QUOTES, 'UTF-8') . "');\"";
      }
      $lastRequestedAt = trim((string) ($page['last_requested_at'] ?? ''));
      $providerNames = isset($technical['provider_names']) && is_array($technical['provider_names']) ? $technical['provider_names'] : [];
      $providerCount = isset($technical['provider_count']) ? (int) $technical['provider_count'] : 0;
      $offersCount = isset($technical['offers_count']) ? (int) $technical['offers_count'] : 0;
      $priceLabel = cvRouteSeoPriceLabel(
          isset($technical['min_amount']) ? (float) $technical['min_amount'] : (isset($page['min_amount']) ? (float) $page['min_amount'] : null),
          (string) ($technical['currency'] ?? ($page['currency'] ?? 'EUR'))
      );
      $searchCountSnapshot = isset($technical['search_count_snapshot']) ? (int) $technical['search_count_snapshot'] : (int) ($page['search_count_snapshot'] ?? 0);
      $effectiveStatus = (string) ($page['status'] ?? 'draft');
      ?>
      <?php if ($effectiveStatus !== 'approved'): ?>
        <section class="cv-partner-card cv-route-seo-draft-banner">
          <strong>Bozza non pubblicata</strong>
          <p class="mb-0">Questa pagina è in stato <code><?= htmlspecialchars($effectiveStatus, ENT_QUOTES, 'UTF-8') ?></code> e non viene indicizzata finché non la imposti su <strong>Pubblica</strong> dal backend admin.</p>
        </section>
      <?php endif; ?>
      <section class="cv-route-seo-hero<?= $heroImageUrl !== '' ? ' has-image' : '' ?>"<?= $heroStyle ?>>
        <div class="cv-route-seo-hero-overlay"></div>
        <div class="cv-route-seo-hero-body">
          <span class="cv-doc-badge">Guida tratta autobus</span>
          <h1 class="cv-title mt-3 mb-2"><?= htmlspecialchars((string) ($page['from_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?> - <?= htmlspecialchars((string) ($page['to_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></h1>
          <p class="cv-subtitle mb-0 cv-route-seo-price-headline">
            Prezzi indicativi <?= htmlspecialchars(strtolower($priceLabel), ENT_QUOTES, 'UTF-8') ?>. Confronto multi-vettore su disponibilità aggiornata.
          </p>
        </div>
      </section>

      <section class="row g-4">
        <div class="col-lg-8">
          <article class="cv-partner-card">
            <h2 class="cv-section-title mb-3">Panoramica della tratta</h2>
            <div class="cv-route-seo-copy">
              <?= cvRouteSeoTextToHtml((string) ($page['effective_intro'] ?? '')) ?>
            </div>
            <div class="d-flex flex-wrap gap-2 mt-4">
              <a href="<?= htmlspecialchars($searchUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn cv-account-btn">Cerca questa tratta</a>
              <a href="./" class="btn cv-account-secondary">Nuova ricerca</a>
            </div>
          </article>

          <article class="cv-partner-card mt-4">
            <h2 class="cv-section-title mb-3">Offerta e dettagli operativi</h2>
            <div class="cv-route-seo-copy">
              <ul class="cv-route-seo-list">
                <li>Confronto in tempo reale tra operatori disponibili sulla tratta selezionata.</li>
                <li>Prezzo mostrato come indicativo, con verifica live prima della conferma.</li>
                <li>Ricerca ottimizzata con filtri su data, passeggeri e preferenze di viaggio.</li>
                <li>Riepilogo segmenti con indicazione vettore per ciascun tratto.</li>
              </ul>
            </div>
          </article>

          <article class="cv-partner-card mt-4">
            <h2 class="cv-section-title mb-3">Informazioni utili sulla tratta</h2>
            <div class="cv-route-seo-copy">
              <?= cvRouteSeoTextToHtml((string) ($page['effective_body'] ?? '')) ?>
            </div>
          </article>

          <article class="cv-partner-card mt-4">
            <h2 class="cv-section-title mb-3">Domande frequenti</h2>
            <div class="cv-route-seo-faq">
              <details>
                <summary>Come trovare la soluzione migliore per <?= htmlspecialchars((string) ($page['from_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?> → <?= htmlspecialchars((string) ($page['to_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>?</summary>
                <p>Seleziona data e passeggeri e avvia la ricerca: il motore confronta i vettori disponibili e ordina le soluzioni per utilità, prezzo e tempi.</p>
              </details>
              <details>
                <summary>Il prezzo mostrato è definitivo?</summary>
                <p>No, è indicativo. Il valore finale viene confermato sul segmento selezionato durante la verifica live della disponibilità.</p>
              </details>
              <details>
                <summary>Chi gestisce operativamente il viaggio?</summary>
                <p>Il servizio di trasporto è erogato dal vettore del segmento selezionato. Cercaviaggio aggrega e ottimizza la proposta.</p>
              </details>
            </div>
          </article>

          <article class="cv-partner-card mt-4">
            <h2 class="cv-section-title mb-3">Fermate autobus</h2>
            <p class="cv-route-meta mb-3">Le fermate mostrate sono collegate alla tratta selezionata. Gli indirizzi definitivi sono confermati nel dettaglio corsa.</p>
            <ul class="nav nav-pills cv-route-map-tabs mb-3" role="tablist">
              <li class="nav-item" role="presentation">
                <button class="nav-link active" id="from-stops-tab" data-bs-toggle="tab" data-bs-target="#from-stops-pane" type="button" role="tab" aria-controls="from-stops-pane" aria-selected="true">
                  Partenza
                </button>
              </li>
              <li class="nav-item" role="presentation">
                <button class="nav-link" id="to-stops-tab" data-bs-toggle="tab" data-bs-target="#to-stops-pane" type="button" role="tab" aria-controls="to-stops-pane" aria-selected="false">
                  Arrivo
                </button>
              </li>
            </ul>

            <div class="tab-content">
              <div class="tab-pane fade show active" id="from-stops-pane" role="tabpanel" aria-labelledby="from-stops-tab">
                <h3 class="cv-doc-subtitle">Fermate di partenza: <?= htmlspecialchars((string) ($page['from_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></h3>
                <?php if (count($fromStops) === 0): ?>
                  <div class="cv-empty">Nessuna fermata disponibile in elenco per questa origine.</div>
                <?php else: ?>
                  <div id="cv-route-map-from" class="cv-route-map-canvas"></div>
                  <div class="cv-route-stops-list mt-3">
                    <?php foreach ($fromStops as $stop): ?>
                      <?php
                      $stopName = trim((string) ($stop['stop_name'] ?? ''));
                      $providerName = trim((string) ($stop['provider_name'] ?? ''));
                      ?>
                      <div class="cv-route-stop-card">
                        <strong><?= htmlspecialchars($stopName, ENT_QUOTES, 'UTF-8') ?></strong>
                        <?php if ($providerName !== ''): ?>
                          <div class="cv-route-meta">Operatore: <?= htmlspecialchars($providerName, ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </div>

              <div class="tab-pane fade" id="to-stops-pane" role="tabpanel" aria-labelledby="to-stops-tab">
                <h3 class="cv-doc-subtitle">Fermate di arrivo: <?= htmlspecialchars((string) ($page['to_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></h3>
                <?php if (count($toStops) === 0): ?>
                  <div class="cv-empty">Nessuna fermata disponibile in elenco per questa destinazione.</div>
                <?php else: ?>
                  <div id="cv-route-map-to" class="cv-route-map-canvas"></div>
                  <div class="cv-route-stops-list mt-3">
                    <?php foreach ($toStops as $stop): ?>
                      <?php
                      $stopName = trim((string) ($stop['stop_name'] ?? ''));
                      $providerName = trim((string) ($stop['provider_name'] ?? ''));
                      ?>
                      <div class="cv-route-stop-card">
                        <strong><?= htmlspecialchars($stopName, ENT_QUOTES, 'UTF-8') ?></strong>
                        <?php if ($providerName !== ''): ?>
                          <div class="cv-route-meta">Operatore: <?= htmlspecialchars($providerName, ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </article>
        </div>

        <div class="col-lg-4">
          <aside class="cv-partner-card">
            <h2 class="cv-doc-subtitle">Riepilogo rapido</h2>
            <div class="cv-route-seo-facts">
              <div class="cv-route-seo-fact">
                <span class="cv-route-seo-fact-label">Tratta</span>
                <strong><?= htmlspecialchars((string) ($page['from_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?> → <?= htmlspecialchars((string) ($page['to_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong>
              </div>
              <div class="cv-route-seo-fact">
                <span class="cv-route-seo-fact-label">Prezzo indicativo</span>
                <strong><?= htmlspecialchars($priceLabel, ENT_QUOTES, 'UTF-8') ?></strong>
              </div>
              <div class="cv-route-seo-fact">
                <span class="cv-route-seo-fact-label">Vettori disponibili</span>
                <strong><?= $providerCount > 0 ? (string) $providerCount : 'n.d.' ?></strong>
              </div>
              <div class="cv-route-seo-fact">
                <span class="cv-route-seo-fact-label">Offerte rilevate</span>
                <strong><?= $offersCount > 0 ? (string) $offersCount : 'n.d.' ?></strong>
              </div>
              <div class="cv-route-seo-fact">
                <span class="cv-route-seo-fact-label">Interesse rotta</span>
                <strong><?= $searchCountSnapshot > 0 ? $searchCountSnapshot . ' ricerche' : 'In monitoraggio' ?></strong>
              </div>
              <?php if ($lastRequestedAt !== ''): ?>
                <div class="cv-route-seo-fact">
                  <span class="cv-route-seo-fact-label">Ultima richiesta rilevata</span>
                  <strong><?= htmlspecialchars($lastRequestedAt, ENT_QUOTES, 'UTF-8') ?></strong>
                </div>
              <?php endif; ?>
            </div>
            <?php if (count($providerNames) > 0): ?>
              <div class="cv-route-seo-providers">
                <span class="cv-route-seo-fact-label">Operatori</span>
                <div class="cv-route-seo-provider-tags">
                  <?php foreach ($providerNames as $providerName): ?>
                    <span><?= htmlspecialchars((string) $providerName, ENT_QUOTES, 'UTF-8') ?></span>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endif; ?>
          </aside>

          <aside class="cv-partner-card mt-4">
            <h2 class="cv-doc-subtitle">Nota operativa</h2>
            <p class="mb-0">
              Cercaviaggio organizza e ottimizza la proposta delle soluzioni disponibili. La responsabilità del servizio, dell’esecuzione della corsa e del titolo di viaggio resta in capo al vettore che opera il segmento selezionato.
            </p>
          </aside>
        </div>
      </section>
    <?php endif; ?>

    <?= cvRenderSiteFooter('mt-4') ?>
  </main>
  <?= cvRenderSiteAuthModals() ?>
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
  <?= cvRenderNamedAssetBundle('public-core-js') ?>
  <?= cvRenderNamedAssetBundle('public-app-js') ?>
  <?php if (is_array($page)): ?>
    <?php
    $fromMapPoints = [];
    foreach ($fromStops as $stop) {
        $lat = isset($stop['lat']) ? (float) $stop['lat'] : 0.0;
        $lon = isset($stop['lon']) ? (float) $stop['lon'] : 0.0;
        if ($lat === 0.0 && $lon === 0.0) {
            continue;
        }
        $fromMapPoints[] = [
            'name' => (string) ($stop['stop_name'] ?? ''),
            'provider' => (string) ($stop['provider_name'] ?? ''),
            'lat' => $lat,
            'lon' => $lon,
        ];
    }
    $toMapPoints = [];
    foreach ($toStops as $stop) {
        $lat = isset($stop['lat']) ? (float) $stop['lat'] : 0.0;
        $lon = isset($stop['lon']) ? (float) $stop['lon'] : 0.0;
        if ($lat === 0.0 && $lon === 0.0) {
            continue;
        }
        $toMapPoints[] = [
            'name' => (string) ($stop['stop_name'] ?? ''),
            'provider' => (string) ($stop['provider_name'] ?? ''),
            'lat' => $lat,
            'lon' => $lon,
        ];
    }
    ?>
    <script>
      (function () {
        const fromPoints = <?= json_encode($fromMapPoints, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const toPoints = <?= json_encode($toMapPoints, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

        function renderRouteMap(containerId, points) {
          const el = document.getElementById(containerId);
          if (!el || !Array.isArray(points) || points.length === 0 || typeof L === 'undefined') {
            return null;
          }

          const map = L.map(el, {scrollWheelZoom: false});
          L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap contributors'
          }).addTo(map);

          const bounds = [];
          points.forEach(function (point) {
            const lat = Number(point.lat);
            const lon = Number(point.lon);
            if (!isFinite(lat) || !isFinite(lon)) {
              return;
            }
            const marker = L.marker([lat, lon]).addTo(map);
            const title = (point.name || 'Fermata');
            const provider = point.provider ? ('<br><small>' + point.provider + '</small>') : '';
            marker.bindPopup('<strong>' + title + '</strong>' + provider);
            bounds.push([lat, lon]);
          });

          if (bounds.length === 1) {
            map.setView(bounds[0], 13);
          } else if (bounds.length > 1) {
            map.fitBounds(bounds, {padding: [20, 20]});
          }
          return map;
        }

        const fromMap = renderRouteMap('cv-route-map-from', fromPoints);
        const toMap = renderRouteMap('cv-route-map-to', toPoints);

        document.querySelectorAll('[data-bs-toggle="tab"]').forEach(function (tabBtn) {
          tabBtn.addEventListener('shown.bs.tab', function () {
            window.setTimeout(function () {
              if (fromMap) { fromMap.invalidateSize(); }
              if (toMap) { toMap.invalidateSize(); }
            }, 120);
          });
        });
      })();
    </script>
  <?php endif; ?>
</body>
</html>
