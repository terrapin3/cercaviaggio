<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/site_layout.php';
require_once __DIR__ . '/auth/config.php';
?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Profilo | Cercaviaggio</title>
  <meta name="description" content="Profilo utente Cercaviaggio.">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Source+Sans+3:wght@400;600;700&display=swap" rel="stylesheet">
  <?= cvRenderNamedAssetBundle('public-base-css') ?>
  <?= cvRenderNamedAssetBundle('public-app-css') ?>
</head>
<body>
  <div class="cv-page-bg"></div>
  <main class="container cv-shell py-4 py-lg-5" id="profilePage">
    <?= cvRenderSiteHeader() ?>

    <section class="cv-hero mb-4 mb-lg-5">
      <div class="cv-copy mb-3 mb-lg-4">
        <p class="cv-eyebrow mb-2">Area utente</p>
        <h1 class="cv-title mb-2">Profilo</h1>
        <p class="cv-subtitle mb-0">Gestione dati account Cercaviaggio.</p>
      </div>

      <div class="cv-profile-card">
        <div id="profileStateNote" class="alert alert-warning d-none mb-3" role="alert"></div>
        <div class="row g-3">
          <div class="col-12 col-lg-6">
            <div class="cv-profile-row">
              <span class="cv-profile-key">Nome</span>
              <span class="cv-profile-value" id="profileName">-</span>
            </div>
          </div>
          <div class="col-12 col-lg-6">
            <div class="cv-profile-row">
              <span class="cv-profile-key">Email</span>
              <span class="cv-profile-value" id="profileEmail">-</span>
            </div>
          </div>
          <div class="col-12 col-lg-6">
            <div class="cv-profile-row">
              <span class="cv-profile-key">Telefono</span>
              <span class="cv-profile-value" id="profilePhone">-</span>
            </div>
          </div>
          <div class="col-12 col-lg-6">
            <div class="cv-profile-row">
              <span class="cv-profile-key">Provincia di residenza</span>
              <span class="cv-profile-value" id="profileCity">-</span>
            </div>
          </div>
          <div class="col-12 col-lg-8">
            <div class="cv-profile-row">
              <div class="form-check form-switch mb-0 cv-checkbox">
                <input class="form-check-input" type="checkbox" role="switch" id="profileNewsletterSwitch" disabled>
                <label class="form-check-label" for="profileNewsletterSwitch">
                  Iscrivimi alla newsletter Cercaviaggio
                </label>
              </div>
            </div>
          </div>
          <div class="col-12 col-lg-4">
            <button type="button" class="btn cv-account-secondary w-100" id="profileNewsletterSaveBtn" disabled>
              Salva newsletter
            </button>
          </div>
          <div class="col-12 col-lg-4">
            <a href="./biglietti.php" class="btn cv-account-secondary w-100">
              I miei biglietti
            </a>
          </div>
          <div class="col-12 col-lg-4">
            <a href="./profilo-modifica.php" class="btn cv-account-secondary w-100">
              Modifica profilo
            </a>
          </div>
          <div class="col-12 col-lg-4">
            <button type="button" class="btn cv-modal-primary w-100" id="profileLogoutBtn">
              Logout
            </button>
          </div>
        </div>
      </div>
    </section>
  </main>

  <?= cvRenderSiteAuthModals() ?>
  <?= cvRenderSiteFooter('container cv-shell pb-4') ?>

  <script>
    window.CV_STOPS = [];
    window.CV_ROUTE_INDEX = {};
    window.CV_GOOGLE_CLIENT_ID = <?= json_encode((string) CV_GOOGLE_CLIENT_ID, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  </script>
  <?= cvRenderNamedAssetBundle('public-core-js') ?>
  <script src="https://accounts.google.com/gsi/client" async defer></script>
  <?= cvRenderNamedAssetBundle('public-app-js') ?>
</body>
</html>
