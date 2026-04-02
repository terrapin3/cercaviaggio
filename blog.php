<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/site_layout.php';
require_once __DIR__ . '/includes/conn.php';

$posts = [];
$baseUrl = rtrim(cvBaseUrl(), '/');
try {
    $connection = cvDbConnection();
    $posts = cvBlogFetchPublishedPosts($connection, 300);
} catch (Throwable $exception) {
    error_log('blog page warning: ' . $exception->getMessage());
}
?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Blog | Cercaviaggio</title>
  <meta name="description" content="Articoli e approfondimenti viaggio del blog Cercaviaggio.">
  <link rel="canonical" href="<?= htmlspecialchars($baseUrl . '/blog', ENT_QUOTES, 'UTF-8') ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Source+Sans+3:wght@400;600;700&display=swap" rel="stylesheet">
  <?= cvRenderNamedAssetBundle('public-base-css') ?>
  <?= cvRenderNamedAssetBundle('public-app-css') ?>
</head>
<body>
<div class="cv-page-bg"></div>
<main class="container cv-shell py-4 py-lg-5">
  <?= cvRenderSiteHeader(['active' => 'blog']) ?>

  <section class="cv-hero mb-3">
    <h1 class="cv-title mb-2">Blog Cercaviaggio</h1>
    <p class="cv-subtitle mb-0">Approfondimenti su tratte, consigli utili e novità dal network.</p>
  </section>
  <section class="row g-3">
    <?php if (count($posts) === 0): ?>
      <div class="col-12"><div class="cv-empty">Nessun articolo pubblicato.</div></div>
    <?php else: ?>
      <?php foreach ($posts as $post): ?>
        <div class="col-12 col-md-6 col-xl-4">
          <article class="cv-route-card">
            <?php $img = trim((string) ($post['hero_image_url'] ?? '')); ?>
            <div class="cv-route-media"<?= $img !== '' ? ' style="background-image:url(\'' . htmlspecialchars($img, ENT_QUOTES, 'UTF-8') . '\');"' : '' ?>></div>
            <div class="cv-route-body">
              <h2 class="cv-route-title"><?= htmlspecialchars((string) ($post['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?></h2>
              <p class="cv-route-meta mb-2"><?= htmlspecialchars((string) ($post['excerpt'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
              <a class="btn cv-route-cta" href="<?= htmlspecialchars($baseUrl . '/blog/' . rawurlencode((string) ($post['slug'] ?? '')), ENT_QUOTES, 'UTF-8') ?>">Leggi articolo</a>
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
