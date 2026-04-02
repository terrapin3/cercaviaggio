<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/site_layout.php';
require_once __DIR__ . '/includes/conn.php';

$slug = trim((string) ($_GET['slug'] ?? ''));
$post = null;
$title = 'Articolo | Cercaviaggio';
$description = 'Approfondimento dal blog Cercaviaggio.';
$baseUrl = rtrim(cvBaseUrl(), '/');
$canonical = $baseUrl . '/blog';

try {
    $connection = cvDbConnection();
    $post = cvBlogFetchPublishedPostBySlug($connection, $slug);
} catch (Throwable $exception) {
    error_log('articolo page warning: ' . $exception->getMessage());
}

if (is_array($post)) {
    $title = trim((string) ($post['title'] ?? $title)) . ' | Blog Cercaviaggio';
    $excerpt = trim((string) ($post['excerpt'] ?? ''));
    if ($excerpt !== '') {
        $description = $excerpt;
    }
    $canonical = $baseUrl . '/blog/' . rawurlencode((string) ($post['slug'] ?? ''));
} else {
    http_response_code(404);
}
?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
  <meta name="description" content="<?= htmlspecialchars($description, ENT_QUOTES, 'UTF-8') ?>">
  <meta name="robots" content="<?= is_array($post) ? 'index,follow' : 'noindex,nofollow' ?>">
  <link rel="canonical" href="<?= htmlspecialchars($canonical, ENT_QUOTES, 'UTF-8') ?>">
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

  <nav aria-label="breadcrumb" class="cv-breadcrumb-wrap mb-3">
    <ol class="breadcrumb cv-breadcrumb mb-0">
      <li class="breadcrumb-item">
        <a href="<?= htmlspecialchars($baseUrl . '/blog', ENT_QUOTES, 'UTF-8') ?>">Blog</a>
      </li>
      <li class="breadcrumb-item active" aria-current="page">
        <?= is_array($post)
          ? htmlspecialchars((string) ($post['title'] ?? 'Articolo'), ENT_QUOTES, 'UTF-8')
          : 'Articolo' ?>
      </li>
    </ol>
  </nav>

  <?php if (!is_array($post)): ?>
    <div class="cv-empty">Articolo non disponibile.</div>
  <?php else: ?>
    <section class="cv-hero mb-3">
      <h1 class="cv-title mb-2"><?= htmlspecialchars((string) ($post['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?></h1>
      <?php if (trim((string) ($post['excerpt'] ?? '')) !== ''): ?>
        <p class="cv-subtitle"><?= htmlspecialchars((string) ($post['excerpt'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
      <?php endif; ?>
    </section>
    <article class="cv-partner-card">
      <?php if (trim((string) ($post['hero_image_url'] ?? '')) !== ''): ?>
        <div class="cv-route-media mb-3" style="height:280px;background-image:url('<?= htmlspecialchars((string) ($post['hero_image_url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>');"></div>
      <?php endif; ?>
      <div class="cv-route-seo-copy">
        <?php
        $blocks = cvBlogDecodeBlocks((string) ($post['content_blocks_json'] ?? ''));
        if (count($blocks) > 0) {
            echo cvBlogRenderBlocksHtml($blocks);
        } else {
            echo cvRouteSeoTextToHtml((string) ($post['content_html'] ?? ''));
        }
        ?>
      </div>
    </article>
  <?php endif; ?>

  <?= cvRenderSiteFooter('mt-4') ?>
</main>
<?= cvRenderSiteAuthModals() ?>
<?= cvRenderNamedAssetBundle('public-core-js') ?>
<?= cvRenderNamedAssetBundle('public-app-js') ?>
<div class="modal fade" id="cvBlogGalleryModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content cv-blog-gallery-modal-content">
      <div class="modal-body cv-blog-gallery-modal-body">
        <button type="button" class="btn-close cv-blog-gallery-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
        <div class="cv-blog-gallery-frame">
          <button type="button" class="cv-blog-gallery-arrow cv-blog-gallery-arrow-prev" id="cvBlogGalleryPrev" aria-label="Immagine precedente">
            <i class="bi bi-chevron-left"></i>
          </button>
          <img id="cvBlogGalleryImage" class="cv-blog-gallery-image" src="" alt="">
          <button type="button" class="cv-blog-gallery-arrow cv-blog-gallery-arrow-next" id="cvBlogGalleryNext" aria-label="Immagine successiva">
            <i class="bi bi-chevron-right"></i>
          </button>
        </div>
        <div id="cvBlogGalleryCounter" class="cv-blog-gallery-counter"></div>
      </div>
    </div>
  </div>
</div>
<script>
  (function () {
    const modalEl = document.getElementById('cvBlogGalleryModal');
    const imageEl = document.getElementById('cvBlogGalleryImage');
    const counterEl = document.getElementById('cvBlogGalleryCounter');
    const prevBtn = document.getElementById('cvBlogGalleryPrev');
    const nextBtn = document.getElementById('cvBlogGalleryNext');
    if (!modalEl || !imageEl || !counterEl || !prevBtn || !nextBtn || typeof bootstrap === 'undefined') {
      return;
    }

    const modal = new bootstrap.Modal(modalEl);
    let currentImages = [];
    let currentIndex = 0;

    const render = () => {
      if (!Array.isArray(currentImages) || currentImages.length === 0) {
        imageEl.setAttribute('src', '');
        counterEl.textContent = '';
        return;
      }
      if (currentIndex < 0) currentIndex = 0;
      if (currentIndex >= currentImages.length) currentIndex = currentImages.length - 1;
      imageEl.setAttribute('src', String(currentImages[currentIndex] || ''));
      counterEl.textContent = (currentIndex + 1) + ' / ' + currentImages.length;
      prevBtn.disabled = currentImages.length <= 1;
      nextBtn.disabled = currentImages.length <= 1;
    };

    document.querySelectorAll('.cv-blog-image-row-open').forEach((button) => {
      button.addEventListener('click', () => {
        const raw = button.getAttribute('data-images') || '[]';
        let images = [];
        try {
          const parsed = JSON.parse(raw);
          if (Array.isArray(parsed)) {
            images = parsed.map((item) => String(item || '')).filter(Boolean);
          }
        } catch (e) {
          images = [];
        }
        if (images.length === 0) return;
        currentImages = images;
        currentIndex = Number(button.getAttribute('data-index') || '0');
        render();
        modal.show();
      });
    });

    prevBtn.addEventListener('click', () => {
      if (currentImages.length <= 1) return;
      currentIndex = (currentIndex - 1 + currentImages.length) % currentImages.length;
      render();
    });

    nextBtn.addEventListener('click', () => {
      if (currentImages.length <= 1) return;
      currentIndex = (currentIndex + 1) % currentImages.length;
      render();
    });
  })();
</script>
</body>
</html>
