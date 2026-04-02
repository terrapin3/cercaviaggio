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
  <title>Modifica Profilo | Cercaviaggio</title>
  <meta name="description" content="Modifica dati profilo Cercaviaggio.">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Source+Sans+3:wght@400;600;700&display=swap" rel="stylesheet">
  <?= cvRenderNamedAssetBundle('public-base-css') ?>
  <?= cvRenderNamedAssetBundle('public-app-css') ?>
</head>
<body>
  <div class="cv-page-bg"></div>
  <main class="container cv-shell py-4 py-lg-5" id="profileEditPage">
    <?= cvRenderSiteHeader() ?>

    <section class="cv-hero mb-4 mb-lg-5">
      <div class="cv-copy mb-3 mb-lg-4">
        <p class="cv-eyebrow mb-2">Area utente</p>
        <h1 class="cv-title mb-2">Modifica profilo</h1>
        <p class="cv-subtitle mb-0">Aggiorna i dati anagrafici, telefono e provincia di residenza.</p>
      </div>

      <div class="cv-profile-card">
        <div id="profileEditStateNote" class="alert alert-warning d-none mb-3" role="alert"></div>
        <form id="profileEditForm" novalidate>
          <div class="row g-3">
            <div class="col-12 col-lg-6">
              <label for="editName" class="cv-label">Nome</label>
              <input type="text" id="editName" class="form-control cv-auth-input" autocomplete="given-name" placeholder="Nome">
              <div class="invalid-feedback">Inserisci il nome.</div>
            </div>
            <div class="col-12 col-lg-6">
              <label for="editSurname" class="cv-label">Cognome</label>
              <input type="text" id="editSurname" class="form-control cv-auth-input" autocomplete="family-name" placeholder="Cognome">
              <div class="invalid-feedback">Inserisci il cognome.</div>
            </div>
            <div class="col-12 col-lg-6">
              <label for="editEmail" class="cv-label">Email</label>
              <input type="email" id="editEmail" class="form-control cv-auth-input" disabled>
            </div>
            <div class="col-12 col-lg-6">
              <label for="editPhone" class="cv-label">Telefono</label>
              <input type="text" id="editPhone" class="form-control cv-auth-input" autocomplete="tel" placeholder="+39 333 1234567">
              <div class="invalid-feedback">Inserisci un numero valido.</div>
            </div>
            <div class="col-12 col-lg-6">
              <label for="editProvince" class="cv-label">Provincia di residenza</label>
              <select id="editProvince" class="form-select cv-auth-input cv-province-select" data-selected="">
                <option value="">Seleziona provincia</option>
              </select>
              <div class="invalid-feedback">Seleziona la provincia.</div>
            </div>
            <div class="col-12 col-lg-8">
              <div class="cv-profile-row">
                <div class="form-check form-switch mb-0 cv-checkbox">
                  <input class="form-check-input" type="checkbox" role="switch" id="editNewsletterSwitch">
                  <label class="form-check-label" for="editNewsletterSwitch">
                    Iscrivimi alla newsletter Cercaviaggio
                  </label>
                </div>
              </div>
            </div>
            <div class="col-12 col-lg-4 d-grid">
              <button type="submit" class="btn cv-modal-primary" id="profileEditSaveBtn">Salva modifiche</button>
            </div>
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
    window.CV_GOOGLE_CLIENT_ID = <?= json_encode((string) CV_GOOGLE_CLIENT_ID, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  </script>
  <?= cvRenderNamedAssetBundle('public-core-js') ?>
  <script src="https://accounts.google.com/gsi/client" async defer></script>
  <?= cvRenderNamedAssetBundle('public-app-js') ?>
</body>
</html>
