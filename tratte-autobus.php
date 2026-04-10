<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/site_layout.php';
require_once __DIR__ . '/includes/conn.php';
require_once __DIR__ . '/includes/runtime_settings.php';

$routes = [];
$baseUrl = rtrim(cvBaseUrl(), '/');
$seo = cvStaticSeoMeta('tratte-autobus.php', [
    'title' => 'Tratte autobus | Cercaviaggio',
    'description' => 'Elenco delle tratte autobus pubblicate su Cercaviaggio con guida dedicata.',
    'og_image' => '',
]);
try {
    $connection = cvDbConnection();
    $routes = cvRouteSeoFetchApprovedPages($connection, 400);
} catch (Throwable $exception) {
    error_log('tratte-autobus page warning: ' . $exception->getMessage());
}
?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($seo['title'], ENT_QUOTES, 'UTF-8') ?></title>
  <meta name="description" content="<?= htmlspecialchars($seo['description'], ENT_QUOTES, 'UTF-8') ?>">
  <?= cvRenderFaviconTags() ?>
  <?= cvRenderRobotsMetaTag() ?>
  <?= cvRenderOpenGraphMetaTags($seo['title'], $seo['description'], $seo['og_image']) ?>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Source+Sans+3:wght@400;600;700&display=swap" rel="stylesheet">
  <?= cvRenderNamedAssetBundle('public-base-css') ?>
  <?= cvRenderNamedAssetBundle('public-app-css') ?>
</head>
<body>
  <div class="cv-page-bg"></div>
  <main class="container cv-shell py-4 py-lg-5">
    <?= cvRenderSiteHeader(['active' => 'tratte-autobus']) ?>

    <section class="cv-hero mb-3">
      <h1 class="cv-title mb-2">Tratte autobus</h1>
      <p class="cv-subtitle mb-0">Guide tratta indicizzabili pubblicate su Cercaviaggio.</p>
    </section>

    <section class="row g-3 mt-1">
      <?php if (count($routes) === 0): ?>
        <div class="col-12">
          <div class="cv-empty">Nessuna tratta pubblicata al momento.</div>
        </div>
      <?php else: ?>
        <?php foreach ($routes as $route): ?>
          <div class="col-12 col-md-6 col-xl-4">
            <article class="cv-route-card">
              <?php $img = trim((string) ($route['hero_image_url'] ?? '')); ?>
              <div class="cv-route-media"<?= $img !== '' ? ' style="background-image:url(\'' . htmlspecialchars($img, ENT_QUOTES, 'UTF-8') . '\');"' : '' ?>></div>
              <div class="cv-route-body">
                <h2 class="cv-route-title"><?= htmlspecialchars((string) ($route['from_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?> <i class="bi bi-arrow-right"></i> <?= htmlspecialchars((string) ($route['to_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></h2>
                <p class="cv-route-meta mb-2"><i class="bi bi-currency-euro"></i> <?= htmlspecialchars((string) ($route['price_label'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
                <a class="btn cv-route-cta" href="<?= htmlspecialchars((string) ($route['public_url'] ?? '#'), ENT_QUOTES, 'UTF-8') ?>">Apri guida</a>
              </div>
            </article>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </section>

    <?= cvRenderSiteFooter('mt-4') ?>
  </main>
  <?= cvRenderSiteAuthModals() ?>
  <?= cvRenderNamedAssetBundle('public-core-js') ?>
  <?= cvRenderNamedAssetBundle('public-app-js') ?>
</body>
</html>
