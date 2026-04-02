<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/site_layout.php';
require_once __DIR__ . '/includes/conn.php';

$stops = [];
$markers = [];

try {
    $connection = cvDbConnection();
    $stops = cvFetchActiveStops($connection, null);
} catch (Throwable $exception) {
    error_log('mappa-fermate load warning: ' . $exception->getMessage());
}

foreach ($stops as $stop) {
    if (!is_array($stop)) {
        continue;
    }
    $lat = isset($stop['lat']) ? (float) $stop['lat'] : 0.0;
    $lon = isset($stop['lon']) ? (float) $stop['lon'] : 0.0;
    if ($lat === 0.0 && $lon === 0.0) {
        continue;
    }
    $markers[] = [
        'name' => (string) ($stop['name'] ?? ''),
        'provider_name' => (string) ($stop['provider_name'] ?? ''),
        'provider_code' => (string) ($stop['provider_code'] ?? ''),
        'lat' => $lat,
        'lon' => $lon,
    ];
}
?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Mappa fermate | Cercaviaggio</title>
  <meta name="description" content="Mappa completa delle fermate attive presenti su Cercaviaggio.">
  <link rel="canonical" href="<?= htmlspecialchars(rtrim(cvBaseUrl(), '/') . '/mappa-fermate.php', ENT_QUOTES, 'UTF-8') ?>">
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">
  <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css">
  <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css">
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

    <section class="cv-hero mb-3">
      <h1 class="cv-title mb-2">Mappa delle fermate</h1>
      <p class="cv-subtitle mb-3">Visualizzazione completa delle fermate attive nel database Cercaviaggio.</p>
      <p class="cv-route-meta mb-3"><strong><?= count($markers) ?></strong> fermate con coordinate geografiche.</p>
    </section>
    <section class="cv-partner-card">
      <div id="cv-all-stops-map" class="cv-route-map-canvas cv-route-map-canvas-lg"></div>
    </section>

    <?= cvRenderSiteFooter('mt-4') ?>
  </main>

  <?= cvRenderSiteAuthModals() ?>
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
  <script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js"></script>
  <?= cvRenderNamedAssetBundle('public-core-js') ?>
  <?= cvRenderNamedAssetBundle('public-app-js') ?>
  <script>
    (function () {
      const points = <?= json_encode($markers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
      const el = document.getElementById('cv-all-stops-map');
      if (!el || typeof L === 'undefined') {
        return;
      }

      const map = L.map(el, {scrollWheelZoom: false});
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors'
      }).addTo(map);

      const cluster = L.markerClusterGroup({disableClusteringAtZoom: 14});
      const bounds = [];
      points.forEach(function (point) {
        const lat = Number(point.lat);
        const lon = Number(point.lon);
        if (!isFinite(lat) || !isFinite(lon)) {
          return;
        }
        const marker = L.marker([lat, lon]);
        const provider = point.provider_name ? ('<br><small>' + point.provider_name + '</small>') : '';
        marker.bindPopup('<strong>' + (point.name || 'Fermata') + '</strong>' + provider);
        cluster.addLayer(marker);
        bounds.push([lat, lon]);
      });

      map.addLayer(cluster);
      if (bounds.length === 1) {
        map.setView(bounds[0], 12);
      } else if (bounds.length > 1) {
        map.fitBounds(bounds, {padding: [24, 24]});
      } else {
        map.setView([41.9028, 12.4964], 5);
      }
    })();
  </script>
</body>
</html>
