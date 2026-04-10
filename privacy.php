<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/site_layout.php';
require_once __DIR__ . '/includes/conn.php';
require_once __DIR__ . '/includes/runtime_settings.php';

$seo = cvStaticSeoMeta('privacy.php', [
    'title' => 'Privacy Policy | Cercaviaggio',
    'description' => 'Informativa privacy di Cercaviaggio.',
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
      <h1 class="cv-title mb-3">Privacy Policy</h1>
      <p class="mb-2">Ultimo aggiornamento: <?= date('d/m/Y') ?></p>
      <p>La presente informativa descrive come Cercaviaggio tratta i dati personali degli utenti che navigano sul sito, creano un account, richiedono assistenza, si iscrivono alla newsletter o inviano una richiesta commerciale tramite il form partner.</p>

      <h2 class="cv-section-title mt-4 mb-2">1. Titolare del trattamento</h2>
      <p>Il titolare del trattamento è il soggetto che gestisce il progetto Cercaviaggio.</p>
      <p class="mb-1"><strong>Dati da completare prima della messa online definitiva:</strong></p>
      <p class="mb-1">Ragione sociale: [INSERIRE]</p>
      <p class="mb-1">Sede legale: [INSERIRE]</p>
      <p class="mb-1">Partita IVA/C.F.: [INSERIRE]</p>
      <p>Email privacy: [INSERIRE]</p>

      <h2 class="cv-section-title mt-4 mb-2">2. Categorie di dati trattati</h2>
      <p>I dati personali trattati possono includere:</p>
      <ul>
        <li>dati identificativi e di contatto (nome, cognome, email);</li>
        <li>dati account (credenziali cifrate, stato account, preferenze newsletter);</li>
        <li>dati inseriti nei form (es. richiesta partner: azienda, referente, telefono, note);</li>
        <li>dati tecnici di navigazione (indirizzo IP, log tecnici, dati di sessione);</li>
        <li>dati relativi a richieste di viaggio e prenotazione, quando disponibili nel servizio.</li>
      </ul>

      <h2 class="cv-section-title mt-4 mb-2">3. Finalità e basi giuridiche del trattamento</h2>
      <p>I dati sono trattati per le seguenti finalità:</p>
      <ul>
        <li>registrazione, autenticazione e gestione dell'account utente (base giuridica: esecuzione di misure precontrattuali/contrattuali, art. 6.1.b GDPR);</li>
        <li>erogazione del servizio richiesto dall'utente e supporto clienti (art. 6.1.b GDPR);</li>
        <li>adempimenti amministrativi, contabili e obblighi di legge (art. 6.1.c GDPR);</li>
        <li>sicurezza del sito, prevenzione abusi e difesa di diritti (legittimo interesse, art. 6.1.f GDPR);</li>
        <li>invio newsletter e comunicazioni promozionali, solo con consenso esplicito (art. 6.1.a GDPR).</li>
      </ul>

      <h2 class="cv-section-title mt-4 mb-2">4. Modalità del trattamento</h2>
      <p>Il trattamento avviene con strumenti informatici e misure tecniche/organizzative adeguate per garantire riservatezza, integrità e disponibilità dei dati, nel rispetto dei principi di minimizzazione e limitazione delle finalità.</p>

      <h2 class="cv-section-title mt-4 mb-2">5. Destinatari dei dati</h2>
      <p>I dati possono essere comunicati, nei limiti delle finalità sopra indicate, a:</p>
      <ul>
        <li>fornitori tecnici (hosting, manutenzione applicativa, servizi infrastrutturali);</li>
        <li>fornitori coinvolti nell'erogazione del servizio richiesto dall'utente (es. operatori di trasporto, quando necessario);</li>
        <li>consulenti e soggetti obbligati per legge (fiscale, legale, autorità competenti).</li>
      </ul>
      <p>I soggetti esterni che trattano dati per conto del titolare sono nominati, ove previsto, responsabili del trattamento ai sensi dell'art. 28 GDPR.</p>

      <h2 class="cv-section-title mt-4 mb-2">6. Trasferimenti extra UE/SEE</h2>
      <p>Se alcuni servizi tecnici comportano trasferimenti verso Paesi non appartenenti allo Spazio Economico Europeo, tali trasferimenti avvengono nel rispetto della normativa applicabile e con garanzie adeguate (es. decisioni di adeguatezza o clausole contrattuali standard).</p>

      <h2 class="cv-section-title mt-4 mb-2">7. Tempi di conservazione</h2>
      <p>I dati sono conservati per il tempo strettamente necessario alle finalità indicate e, in particolare:</p>
      <ul>
        <li>dati account: fino alla cancellazione dell'account o per il periodo richiesto dalla normativa;</li>
        <li>dati amministrativo-contabili: per i termini previsti dalla legge;</li>
        <li>dati newsletter: fino a revoca del consenso (disiscrizione);</li>
        <li>dati tecnici/log: per tempi proporzionati alle esigenze di sicurezza e diagnosi tecnica.</li>
      </ul>

      <h2 class="cv-section-title mt-4 mb-2">8. Diritti dell'interessato</h2>
      <p>L'interessato può esercitare i diritti previsti dagli artt. 15-22 GDPR, tra cui accesso, rettifica, cancellazione, limitazione, opposizione e portabilità, nonché revocare il consenso in qualsiasi momento senza pregiudicare la liceità del trattamento effettuato prima della revoca.</p>
      <p>È inoltre possibile proporre reclamo all'Autorità Garante per la protezione dei dati personali: <a href="https://www.garanteprivacy.it" target="_blank" rel="noopener noreferrer">www.garanteprivacy.it</a>.</p>

      <h2 class="cv-section-title mt-4 mb-2">9. Minori</h2>
      <p>Il sito non è rivolto intenzionalmente a minori di 14 anni. Qualora vengano rilevati dati conferiti da minori in violazione delle condizioni applicabili, il titolare potrà procedere alla cancellazione.</p>

      <h2 class="cv-section-title mt-4 mb-2">10. Contatti per richieste privacy</h2>
      <p>Per esercitare i diritti o richiedere chiarimenti è possibile scrivere a: <strong>[INSERIRE EMAIL PRIVACY UFFICIALE]</strong>.</p>

      <h2 class="cv-section-title mt-4 mb-2">11. Riferimenti normativi principali</h2>
      <ul class="mb-0">
        <li><a href="https://eur-lex.europa.eu/eli/reg/2016/679/art_13/oj/eng" target="_blank" rel="noopener noreferrer">Regolamento (UE) 2016/679 (GDPR)</a></li>
        <li><a href="https://www.garanteprivacy.it/home/docweb/-/docweb-display/docweb/9677876" target="_blank" rel="noopener noreferrer">Linee guida cookie e altri strumenti di tracciamento (Garante Privacy)</a></li>
      </ul>
    </section>

    <?= cvRenderSiteFooter() ?>
  </main>
  <?= cvRenderSiteAuthModals() ?>
  <?= cvRenderNamedAssetBundle('public-core-js') ?>
  <?= cvRenderNamedAssetBundle('public-app-js') ?>
</body>
</html>
