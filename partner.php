<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/auth/config.php';
require_once __DIR__ . '/includes/conn.php';
require_once __DIR__ . '/includes/runtime_settings.php';

$seo = cvStaticSeoMeta('partner.php', [
    'title' => 'Diventa Partner | Cercaviaggio',
    'description' => "Richiedi l'onboarding come azienda partner su Cercaviaggio.",
    'og_image' => '',
]);
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
    <?= cvRenderSiteHeader(['active' => 'partner']) ?>

    <section class="cv-hero mb-4 mb-lg-5">
      <div class="cv-copy mb-3 mb-lg-4">
        <p class="cv-eyebrow mb-2">Per aziende di trasporto</p>
        <h1 class="cv-title mb-2">Vendi su cercaviaggio</h1>
        <p class="cv-subtitle mb-0">
          Compila il form per avviare onboarding tecnico, commerciale e pubblicazione tratte.
        </p>
      </div>

      <div class="cv-partner-card">
        <form id="partnerLeadForm" class="row g-3 mt-1" novalidate>
          <div class="col-12 col-lg-6">
            <label for="partnerCompanyName" class="cv-label">Azienda</label>
            <input type="text" class="form-control cv-auth-input" id="partnerCompanyName" placeholder="Nome azienda">
            <div class="invalid-feedback">Inserisci il nome azienda.</div>
          </div>

          <div class="col-12 col-lg-6">
            <label for="partnerContactName" class="cv-label">Referente</label>
            <input type="text" class="form-control cv-auth-input" id="partnerContactName" placeholder="Nome referente">
            <div class="invalid-feedback">Inserisci il referente.</div>
          </div>

          <div class="col-12 col-lg-6">
            <label for="partnerEmail" class="cv-label">Email</label>
            <input type="email" class="form-control cv-auth-input" id="partnerEmail" placeholder="referente@azienda.it">
            <div class="invalid-feedback">Inserisci una email valida.</div>
          </div>

          <div class="col-12 col-lg-6">
            <label for="partnerPhone" class="cv-label">Telefono</label>
            <input type="text" class="form-control cv-auth-input" id="partnerPhone" placeholder="+39 ...">
          </div>

          <div class="col-12 col-lg-6">
            <label for="partnerWebsite" class="cv-label">Sito web</label>
            <input type="text" class="form-control cv-auth-input" id="partnerWebsite" placeholder="www.azienda.it">
            <div class="invalid-feedback">Inserisci un sito valido.</div>
          </div>

          <div class="col-12 col-lg-6">
            <label for="partnerCity" class="cv-label">Città</label>
            <input type="text" class="form-control cv-auth-input" id="partnerCity" placeholder="Es. Salerno">
          </div>

          <div class="col-12">
            <label for="partnerNotes" class="cv-label">Note</label>
            <textarea class="form-control cv-auth-input cv-textarea" id="partnerNotes" rows="4" placeholder="Tipologia linee, numero corse, esigenze integrazione..."></textarea>
          </div>

          <div class="col-12">
            <div class="form-check cv-checkbox">
              <input class="form-check-input" type="checkbox" id="partnerPrivacy">
              <label class="form-check-label" for="partnerPrivacy">
                Accetto l'informativa privacy per essere ricontattato.
              </label>
              <div class="invalid-feedback">Devi accettare l'informativa privacy.</div>
            </div>
          </div>

          <div class="col-12 col-lg-4">
            <button type="submit" class="btn cv-modal-primary w-100" id="partnerLeadSubmitBtn">
              Invia richiesta
            </button>
          </div>
        </form>
      </div>
    </section>
  </main>

  <?= cvRenderSiteAuthModals() ?>
  <?= cvRenderSiteFooter('container cv-shell pb-4') ?>

  <script>
    window.CV_STOPS = [];
    window.CV_ROUTE_INDEX = {};
  </script>
  <?= cvRenderNamedAssetBundle('public-core-js') ?>
  <?= cvRenderNamedAssetBundle('public-app-js') ?>
</body>
</html>
