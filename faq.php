<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/site_layout.php';
?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>FAQ | Cercaviaggio</title>
  <meta name="description" content="Domande frequenti su ricerca, prezzi, provider e funzionamento di Cercaviaggio.">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Source+Sans+3:wght@400;600;700&display=swap" rel="stylesheet">
  <?= cvRenderNamedAssetBundle('public-base-css') ?>
  <?= cvRenderNamedAssetBundle('public-app-css') ?>
</head>
<body>
  <div class="cv-page-bg"></div>
  <main class="container cv-shell py-4 py-lg-5">
    <?= cvRenderSiteHeader(['active' => 'faq', 'contact_button' => true]) ?>

    <section class="cv-hero mb-4">
      <div class="cv-copy mb-3 mb-lg-4">
        <p class="cv-eyebrow mb-2">Supporto rapido</p>
        <h1 class="cv-title mb-2">FAQ</h1>
        <p class="cv-subtitle mb-0">
          Una prima raccolta di domande frequenti su ricerca tratte, prezzi, provider e funzionamento di Cercaviaggio.
        </p>
      </div>
    </section>

    <section class="cv-partner-card">
      <div class="accordion" id="cvFaqAccordion">
        <div class="accordion-item">
          <h2 class="accordion-header" id="faqHeadingOne">
            <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#faqCollapseOne" aria-expanded="true" aria-controls="faqCollapseOne">
              Cos'e Cercaviaggio?
            </button>
          </h2>
          <div id="faqCollapseOne" class="accordion-collapse collapse show" aria-labelledby="faqHeadingOne" data-bs-parent="#cvFaqAccordion">
            <div class="accordion-body">
              Cercaviaggio e un motore di ricerca multi-azienda che confronta tratte, orari e prezzi in un'unica schermata, mantenendo separate le integrazioni dei diversi provider.
            </div>
          </div>
        </div>

        <div class="accordion-item">
          <h2 class="accordion-header" id="faqHeadingResponsibility">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faqCollapseResponsibility" aria-expanded="false" aria-controls="faqCollapseResponsibility">
              Di chi e la responsabilita del viaggio, del biglietto e del servizio?
            </button>
          </h2>
          <div id="faqCollapseResponsibility" class="accordion-collapse collapse" aria-labelledby="faqHeadingResponsibility" data-bs-parent="#cvFaqAccordion">
            <div class="accordion-body">
              La responsabilita del servizio di trasporto, dell'esecuzione della corsa, dell'emissione del titolo di viaggio e delle condizioni commerciali resta in capo al vettore/provider che opera la tratta.
              Cercaviaggio si limita a raccogliere, organizzare, confrontare e ottimizzare le soluzioni disponibili tramite le integrazioni tecniche attive, per aiutare l'utente a trovare piu rapidamente l'opzione piu adatta.
            </div>
          </div>
        </div>

        <div class="accordion-item">
          <h2 class="accordion-header" id="faqHeadingTwo">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faqCollapseTwo" aria-expanded="false" aria-controls="faqCollapseTwo">
              I prezzi mostrati sono sempre finali?
            </button>
          </h2>
          <div id="faqCollapseTwo" class="accordion-collapse collapse" aria-labelledby="faqHeadingTwo" data-bs-parent="#cvFaqAccordion">
            <div class="accordion-body">
              Il prezzo definitivo dipende sempre dalla verifica live della corsa e dalla tariffa disponibile in quel momento. In home possiamo mostrare prezzi indicativi solo se la tratta e presente in cache o nei dati sincronizzati.
            </div>
          </div>
        </div>

        <div class="accordion-item">
          <h2 class="accordion-header" id="faqHeadingThree">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faqCollapseThree" aria-expanded="false" aria-controls="faqCollapseThree">
              Come vengono trovate le soluzioni con scalo?
            </button>
          </h2>
          <div id="faqCollapseThree" class="accordion-collapse collapse" aria-labelledby="faqHeadingThree" data-bs-parent="#cvFaqAccordion">
            <div class="accordion-body">
              Cercaviaggio combina i segmenti dei provider compatibili, applica regole su attesa, distanza dallo scalo, numero massimo di scali e coerenza del percorso verso la destinazione finale.
            </div>
          </div>
        </div>

        <div class="accordion-item">
          <h2 class="accordion-header" id="faqHeadingFour">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faqCollapseFour" aria-expanded="false" aria-controls="faqCollapseFour">
              Quali aziende possono aderire a Cercaviaggio?
            </button>
          </h2>
          <div id="faqCollapseFour" class="accordion-collapse collapse" aria-labelledby="faqHeadingFour" data-bs-parent="#cvFaqAccordion">
            <div class="accordion-body">
              Possono aderire aziende di trasporto che espongono gli endpoint richiesti dal contratto tecnico Cercaviaggio. La base attuale e descritta nella pagina <a href="./documentazione-endpoint.php">Endpoint / Documentazione</a>.
            </div>
          </div>
        </div>

        <div class="accordion-item">
          <h2 class="accordion-header" id="faqHeadingFive">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faqCollapseFive" aria-expanded="false" aria-controls="faqCollapseFive">
              Posso rifare una ricerca senza lasciare la pagina delle soluzioni?
            </button>
          </h2>
          <div id="faqCollapseFive" class="accordion-collapse collapse" aria-labelledby="faqHeadingFive" data-bs-parent="#cvFaqAccordion">
            <div class="accordion-body">
              Si. Il frontend e stato impostato per permettere una nuova ricerca anche dalla pagina soluzioni, senza obbligare l'utente a tornare ogni volta in home.
            </div>
          </div>
        </div>

        <div class="accordion-item">
          <h2 class="accordion-header" id="faqHeadingSix">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faqCollapseSix" aria-expanded="false" aria-controls="faqCollapseSix">
              Dove posso richiedere l'onboarding come partner?
            </button>
          </h2>
          <div id="faqCollapseSix" class="accordion-collapse collapse" aria-labelledby="faqHeadingSix" data-bs-parent="#cvFaqAccordion">
            <div class="accordion-body">
              Puoi usare la pagina <a href="./partner.php">Diventa partner</a> per aprire il contatto commerciale e tecnico.
            </div>
          </div>
        </div>
      </div>
    </section>

    <?= cvRenderSiteFooter('mt-4') ?>
  </main>
  <?= cvRenderSiteAuthModals() ?>
  <?= cvRenderNamedAssetBundle('public-core-js') ?>
  <?= cvRenderNamedAssetBundle('public-app-js') ?>
</body>
</html>
