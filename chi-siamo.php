<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/site_layout.php';
require_once __DIR__ . '/includes/conn.php';
require_once __DIR__ . '/includes/runtime_settings.php';

$seo = cvStaticSeoMeta('chi-siamo.php', [
    'title' => 'Chi siamo | Cercaviaggio',
    'description' => 'Scopri il progetto Cercaviaggio, la sua visione e come ottimizza la ricerca di tratte multi-provider.',
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
    <?= cvRenderSiteHeader(['active' => 'chi-siamo', 'contact_button' => true]) ?>

    <section class="cv-hero mb-4 mb-lg-5">
      <div class="cv-copy mb-3 mb-lg-4">
        <p class="cv-eyebrow mb-2">Il progetto</p>
        <h1 class="cv-title mb-2">Chi siamo</h1>
        <p class="cv-subtitle mb-0">
          Cercaviaggio nasce per rendere piu leggibile e veloce la ricerca di viaggi autobus tra aziende diverse, mettendo ordine su tratte, scali, orari e disponibilita.
        </p>
      </div>
    </section>

    <section class="cv-story-grid mb-4 mb-lg-5">
      <article class="cv-story-card">
        <span class="cv-story-chip">01</span>
        <h2 class="cv-section-title mb-2">Perche esiste</h2>
        <p class="mb-0">
          Molte tratte reali non vivono dentro un solo vettore. Cercaviaggio serve a confrontare piu sorgenti, combinare segmenti compatibili e ridurre il tempo necessario per capire se un viaggio esiste davvero.
        </p>
      </article>

      <article class="cv-story-card">
        <span class="cv-story-chip">02</span>
        <h2 class="cv-section-title mb-2">Cosa fa</h2>
        <p class="mb-0">
          Il sistema sincronizza fermate, corse e tariffe dei provider, calcola soluzioni dirette e con scalo, e presenta risultati chiari senza costringere l'utente a controllare piu siti separati.
        </p>
      </article>

      <article class="cv-story-card">
        <span class="cv-story-chip">03</span>
        <h2 class="cv-section-title mb-2">Come cresce</h2>
        <p class="mb-0">
          La piattaforma e pensata per integrare nuovi vettori tramite endpoint standardizzati, con regole tecniche comuni e controllo puntuale delle condizioni commerciali per ogni azienda.
        </p>
      </article>
    </section>

    <section class="cv-partner-card mb-4 mb-lg-5">
      <h2 class="cv-section-title mb-3">Il nostro approccio</h2>
      <div class="cv-story-columns">
        <div>
          <h3 class="cv-story-heading">Ricerca piu pulita</h3>
          <p>
            L'obiettivo non e riempire la pagina di risultati casuali, ma proporre percorsi sensati, leggibili e coerenti con la destinazione richiesta.
          </p>
        </div>
        <div>
          <h3 class="cv-story-heading">Tecnologia al servizio del viaggio</h3>
          <p>
            Lavoriamo su cache, indicizzazione, pathfinder e contratti API per far crescere il progetto senza dipendere da integrazioni fragili o gestioni manuali ripetitive.
          </p>
        </div>
        <div>
          <h3 class="cv-story-heading">Ruoli chiari</h3>
          <p>
            Cercaviaggio organizza e ottimizza la proposta delle soluzioni. L'operativita del viaggio, il servizio e le condizioni del biglietto restano in capo al vettore che esegue la corsa.
          </p>
        </div>
      </div>
    </section>

    <section class="cv-partner-card">
      <h2 class="cv-section-title mb-3">A chi parliamo</h2>
      <ul class="cv-story-list mb-4">
        <li>Utenti che vogliono trovare velocemente la combinazione giusta tra piu aziende.</li>
        <li>Vettori che vogliono distribuire meglio le proprie tratte e aumentare la visibilita online.</li>
        <li>Partner tecnici e commerciali che vogliono aderire a un contratto API chiaro e progressivo.</li>
      </ul>
      <div class="d-flex flex-wrap gap-2">
        <a href="./partner.php" class="btn cv-modal-primary">Diventa partner</a>
        <a href="./documentazione-endpoint.php" class="btn cv-account-secondary">Vedi documentazione endpoint</a>
      </div>
    </section>

    <?= cvRenderSiteFooter('mt-4') ?>
  </main>
  <?= cvRenderSiteAuthModals() ?>
  <?= cvRenderNamedAssetBundle('public-core-js') ?>
  <?= cvRenderNamedAssetBundle('public-app-js') ?>
</body>
</html>
