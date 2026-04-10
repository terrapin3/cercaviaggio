<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/site_layout.php';
require_once __DIR__ . '/includes/conn.php';
require_once __DIR__ . '/includes/runtime_settings.php';

$seo = cvStaticSeoMeta('cookie.php', [
    'title' => 'Cookie Policy | Cercaviaggio',
    'description' => 'Cookie policy di Cercaviaggio.',
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
    <?= cvRenderSiteHeader() ?>

    <section class="cv-partner-card">
      <h1 class="cv-title mb-3">Cookie Policy</h1>
      <p class="mb-2">Ultimo aggiornamento: <?= date('d/m/Y') ?></p>
      <p>Questa informativa spiega come Cercaviaggio utilizza cookie e tecnologie similari (es. local storage) durante la navigazione del sito.</p>

      <h2 class="cv-section-title mt-4 mb-2">1. Cosa sono cookie e strumenti simili</h2>
      <p>I cookie sono piccoli file di testo salvati sul dispositivo dell'utente. Tecnologie equivalenti, come il local storage del browser, consentono di memorizzare preferenze tecniche utili al funzionamento del sito.</p>

      <h2 class="cv-section-title mt-4 mb-2">2. Tipologie utilizzate su Cercaviaggio</h2>
      <p>Attualmente il sito utilizza principalmente strumenti tecnici e di funzionalità.</p>

      <h3 class="mt-3 mb-2">2.1 Cookie tecnici necessari</h3>
      <p>Servono al funzionamento essenziale del sito (es. autenticazione utente, sicurezza delle sessioni, protezione da accessi non autorizzati). Senza questi strumenti il servizio non può funzionare correttamente.</p>

      <h3 class="mt-3 mb-2">2.2 Strumenti di preferenza</h3>
      <p>Per ricordare la scelta sul banner cookie viene utilizzata una voce nel browser local storage (`cv_cookie_consent_v1`). Questo dato salva solo la preferenza di consenso e non viene usato per profilazione.</p>

      <h3 class="mt-3 mb-2">2.3 Cookie di terze parti</h3>
      <p>Alcune funzionalità esterne possono installare cookie propri, ad esempio servizi di autenticazione di terze parti (es. login Google), in base alle loro policy. Tali soggetti operano come autonomi titolari per i dati trattati tramite i propri strumenti.</p>

      <h2 class="cv-section-title mt-4 mb-2">3. Base giuridica</h2>
      <p>I cookie tecnici necessari sono utilizzati sulla base del legittimo interesse e della necessità tecnica di erogare il servizio richiesto. Eventuali cookie non necessari (es. analitici non anonimizzati, marketing o profilazione) sono attivati solo previo consenso.</p>

      <h2 class="cv-section-title mt-4 mb-2">4. Durata di conservazione</h2>
      <p>I cookie possono essere:</p>
      <ul>
        <li>di sessione: cancellati alla chiusura del browser;</li>
        <li>persistenti: conservati fino alla scadenza impostata o fino a cancellazione manuale da parte dell'utente.</li>
      </ul>
      <p>La preferenza del banner è mantenuta nel browser finché non viene rimossa manualmente.</p>

      <h2 class="cv-section-title mt-4 mb-2">5. Come gestire o revocare il consenso</h2>
      <p>Puoi gestire i cookie in qualsiasi momento:</p>
      <ul>
        <li>dal browser, cancellando cookie e dati di navigazione;</li>
        <li>ripristinando il banner di consenso con il pulsante qui sotto.</li>
      </ul>
      <button type="button" class="btn cv-account-secondary btn-sm" id="resetCookieConsentBtn">Rivedi consenso cookie</button>

      <h2 class="cv-section-title mt-4 mb-2">6. Riferimenti normativi</h2>
      <p>La presente cookie policy è redatta in conformità alla normativa privacy applicabile, incluse le disposizioni del GDPR, della Direttiva ePrivacy e delle linee guida dell'Autorità Garante italiana sui cookie e altri strumenti di tracciamento.</p>
      <ul>
        <li><a href="https://www.garanteprivacy.it/home/docweb/-/docweb-display/docweb/9677876" target="_blank" rel="noopener noreferrer">Linee guida cookie e altri strumenti di tracciamento (Garante Privacy, 10 giugno 2021)</a></li>
        <li><a href="https://eur-lex.europa.eu/eli/reg/2016/679/art_13/oj/eng" target="_blank" rel="noopener noreferrer">Regolamento (UE) 2016/679 (GDPR)</a></li>
        <li><a href="https://eur-lex.europa.eu/eli/dir/2002/58/2006-05-03/eng" target="_blank" rel="noopener noreferrer">Direttiva 2002/58/CE (ePrivacy)</a></li>
        <li><a href="https://www.normattiva.it/uri-res/N2Ls?urn%3Anir%3Astato%3Adecreto.legislativo%3A2003-06-30%3B196~art122=" target="_blank" rel="noopener noreferrer">D.lgs. 196/2003, art. 122 (Normattiva)</a></li>
      </ul>
      <p class="mb-0">Per maggiori dettagli consulta anche la nostra <a href="./privacy.php">Privacy Policy</a>.</p>
    </section>

    <?= cvRenderSiteFooter() ?>
  </main>
  <?= cvRenderSiteAuthModals() ?>
  <?= cvRenderNamedAssetBundle('public-core-js') ?>
  <?= cvRenderNamedAssetBundle('public-app-js') ?>
  <script>
    (function () {
      var resetBtn = document.getElementById('resetCookieConsentBtn');
      if (!resetBtn) {
        return;
      }

      resetBtn.addEventListener('click', function () {
        try {
          window.localStorage.removeItem('cv_cookie_consent_v1');
        } catch (error) {
          // ignore storage errors
        }
        window.location.href = './';
      });
    })();
  </script>
</body>
</html>
