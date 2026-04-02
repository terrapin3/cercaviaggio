<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/site_layout.php';

$updatedAt = (new DateTime('now', new DateTimeZone('Europe/Rome')))->format('d/m/Y');
$contractVersion = 'v1';

/**
 * @param array<string,mixed> $payload
 */
function cvDocPrettyJson(array $payload): string
{
    $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded)) {
        return '{}';
    }

    return $encoded;
}

$syncEndpoints = [
    [
        'name' => 'health',
        'method' => 'GET',
        'params' => 'nessuno',
        'summary' => 'Controllo rapido disponibilita provider e versione contratto.',
    ],
    [
        'name' => 'sync_stops',
        'method' => 'GET',
        'params' => 'page, page_size, updated_since(opz.)',
        'summary' => 'Esporta fermate e metadati geografici/stato.',
    ],
    [
        'name' => 'sync_lines',
        'method' => 'GET',
        'params' => 'page, page_size, updated_since(opz.)',
        'summary' => 'Esporta linee attive/visibili del provider.',
    ],
    [
        'name' => 'sync_trips',
        'method' => 'GET',
        'params' => 'page, page_size, updated_since(opz.)',
        'summary' => 'Esporta corse e fermate ordinate per ogni corsa.',
    ],
    [
        'name' => 'sync_fares',
        'method' => 'GET',
        'params' => 'page, page_size, updated_since(opz.)',
        'summary' => 'Esporta tariffe base fermata-fermata. Copertura attuale: base_route_fares_only.',
    ],
];

$runtimeEndpoints = [
    [
        'name' => 'search',
        'method' => 'GET',
        'params' => 'part, arr, ad, bam, dt1, dt2(opz.)',
        'summary' => 'Restituisce le soluzioni del provider con segmenti e fares disponibili.',
    ],
    [
        'name' => 'verifica_corse',
        'method' => 'GET',
        'params' => 'stessi parametri di search',
        'summary' => 'Alias di compatibilita storica. Deve comportarsi come search.',
    ],
    [
        'name' => 'quote',
        'method' => 'GET o POST',
        'params' => 'part, arr, id_corsa, ad, bam, dt1, fare_id/id_promo(opz.), direction(opz.)',
        'summary' => 'Congela prezzo e disponibilita del segmento e restituisce quote_token.',
    ],
    [
        'name' => 'locations',
        'method' => 'GET',
        'params' => 'indicator, query(opz.), departure_id se indicator=2',
        'summary' => 'Helper opzionale per autocomplete/legacy app. Non e il cuore del contratto Cercaviaggio.',
    ],
];

$draftCheckoutEndpoints = [
    [
        'name' => 'reserve',
        'method' => 'POST',
        'params' => 'header X-Idempotency-Key, body quote_token',
        'summary' => 'Bozza attuale per riserva breve del segmento. Da considerare evolutiva.',
    ],
    [
        'name' => 'book',
        'method' => 'POST',
        'params' => 'header X-Idempotency-Key, body quote_token, reservation_token(opz.), shop_id(opz.)',
        'summary' => 'Bozza attuale per finalizzazione/acquisto. Sara estesa quando chiudiamo il checkout end-to-end.',
    ],
];

$responseEnvelopeExample = [
    'success' => true,
    'contract_version' => $contractVersion,
    'provider' => 'provider_code',
    'request_id' => 'cv_65f6f3e0f1d2e4.12345678',
    'data' => [
        'status' => 'ok',
    ],
    'error' => null,
];

$syncStopItemExample = [
    'external_id' => '118',
    'stop_id' => 118,
    'name' => 'SALERNO - P.za Montpellier - P.co Pinocchio',
    'description' => 'Terminal autobus',
    'lat' => 40.6773,
    'lon' => 14.7676,
    'localita' => 1,
    'address' => [
        'indirizzo' => 'Piazza Montpellier',
        'comune' => 'Salerno',
        'provincia' => 'SA',
        'paese' => 'Italia',
    ],
    'country_code' => 'IT',
    'timezone' => 'Europe/Rome',
    'sospensione' => [
        'da' => null,
        'a' => null,
    ],
    'is_active' => true,
    'updated_at' => null,
];

$syncTripItemExample = [
    'external_id' => '4123',
    'trip_id' => 4123,
    'line_id' => 3,
    'name' => 'SALERNO-ROMA',
    'tempo_acquisto' => 30,
    'gruppo' => '',
    'recapiti' => '',
    'transitoria' => 0,
    'direction_id' => 1,
    'is_active' => true,
    'is_visible' => true,
    'stops' => [
        [
            'stop_id' => 118,
            'sequence' => 1,
            'time' => '04:35',
            'day_offset' => 0,
            'distance' => 0,
            'is_active' => true,
            'gtfs' => [
                'gtfs' => 0,
                'gtfs2' => 0,
                'gtfs3' => 0,
            ],
            'lat' => 40.6773,
            'lon' => 14.7676,
        ],
        [
            'stop_id' => 149,
            'sequence' => 5,
            'time' => '07:40',
            'day_offset' => 0,
            'distance' => 267.4,
            'is_active' => true,
            'gtfs' => [
                'gtfs' => 0,
                'gtfs2' => 0,
                'gtfs3' => 0,
            ],
            'lat' => 41.9096,
            'lon' => 12.5307,
        ],
    ],
    'updated_at' => null,
];

$searchSolutionExample = [
    'solution_id' => 'c05f64f7a1a147f0e7a2d845',
    'direction' => 'outbound',
    'departure_datetime' => '2026-03-21T04:35:00+01:00',
    'arrival_datetime' => '2026-03-21T07:40:00+01:00',
    'duration_minutes' => 185,
    'provider_corsa_ids' => [4123],
    'segments' => [
        [
            'provider' => 'provider_code',
            'corsa_id' => 4123,
            'from_id' => 118,
            'from_name' => 'SALERNO - P.za Montpellier - P.co Pinocchio',
            'to_id' => 149,
            'to_name' => 'ROMA - Stazione Tiburtina',
            'departure_time' => '04:35',
            'arrival_time' => '07:40',
        ],
    ],
    'fares' => [
        [
            'fare_id' => 'PROMO-7',
            'label' => 'Promo Web',
            'amount' => 19.9,
            'original_amount' => 24.9,
            'discount_percent' => 20,
            'seats_available' => 12,
            'change_allowed' => true,
        ],
    ],
];

$quoteRequestExample = [
    'part' => 118,
    'arr' => 149,
    'id_corsa' => 4123,
    'ad' => 1,
    'bam' => 0,
    'dt1' => '21/03/2026',
    'fare_id' => 'PROMO-7',
    'direction' => 'outbound',
];

$quoteResponseExample = [
    'quote_id' => '6fd2f9a2a1b7cc11ef3d6a88',
    'quote_token' => 'eyJ2IjoxLCJwcm92aWRlciI6InByb3ZpZGVyX2NvZGUifQ.signed',
    'status' => 'confirmed',
    'issued_at' => '2026-03-17T10:45:00+00:00',
    'expires_at' => '2026-03-17T10:55:00+00:00',
    'ttl_seconds' => 600,
    'trip' => [
        'provider' => 'provider_code',
        'corsa_id' => 4123,
        'direction' => 'outbound',
        'from_id' => 118,
        'from_name' => 'SALERNO - P.za Montpellier - P.co Pinocchio',
        'to_id' => 149,
        'to_name' => 'ROMA - Stazione Tiburtina',
        'departure_datetime' => '2026-03-21T04:35:00+01:00',
        'arrival_datetime' => '2026-03-21T07:40:00+01:00',
        'duration_minutes' => 185,
    ],
    'passengers' => [
        'ad' => 1,
        'bam' => 0,
        'total' => 1,
    ],
    'pricing' => [
        'fare_id' => 'PROMO-7',
        'label' => 'Promo Web',
        'amount' => 19.9,
        'original_amount' => 24.9,
        'discount_percent' => 20,
        'seats_available' => 12,
        'change_allowed' => true,
        'currency' => 'EUR',
    ],
];
?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Documentazione Endpoint Provider | Cercaviaggio</title>
  <meta name="description" content="Contratto tecnico degli endpoint provider richiesti da Cercaviaggio.">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Source+Sans+3:wght@400;600;700&display=swap" rel="stylesheet">
  <?= cvRenderNamedAssetBundle('public-base-css') ?>
  <?= cvRenderNamedAssetBundle('public-app-css') ?>
</head>
<body>
  <div class="cv-page-bg"></div>
  <main class="container cv-shell py-4 py-lg-5">
    <?= cvRenderSiteHeader(['active' => 'partner', 'contact_button' => true]) ?>

    <section class="cv-hero mb-4">
      <div class="cv-copy mb-3 mb-lg-4">
        <p class="cv-eyebrow mb-2">Contratto tecnico provider Cercaviaggio</p>
        <h1 class="cv-title mb-2">Endpoint / documentazione integrazione</h1>
        <p class="cv-subtitle mb-0">
          Questa pagina descrive gli endpoint oggi compatibili con le integrazioni attive in produzione.
          Partiamo subito con il contratto stabile <strong><?= htmlspecialchars($contractVersion, ENT_QUOTES, 'UTF-8') ?></strong> e teniamo la parte checkout in una sezione separata, marcata come evolutiva.
        </p>
      </div>
    </section>

    <section class="cv-partner-card mb-4">
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
        <div>
          <h2 class="cv-section-title mb-2">Panoramica contratto</h2>
          <p class="mb-0 text-secondary">
            Ultimo aggiornamento: <?= htmlspecialchars($updatedAt, ENT_QUOTES, 'UTF-8') ?>.
            Gli endpoint real-time obbligatori per Cercaviaggio sono <code>search</code> e <code>quote</code>.
            Gli endpoint <code>sync_*</code> sono necessari per importare fermate, linee, corse e tariffe base.
          </p>
        </div>
        <span class="cv-doc-badge">Contratto stabile <?= htmlspecialchars($contractVersion, ENT_QUOTES, 'UTF-8') ?></span>
      </div>

      <div class="cv-doc-note mb-3">
        <strong>Routing endpoint:</strong>
        la reference implementation attuale usa <code>api2.php?rquest=nome_endpoint</code>.
        Esempio: <code>https://provider.example.com/rest/cercaviaggio/api2.php?rquest=search</code>.
      </div>

      <div class="cv-doc-note mb-0">
        <strong>Nota di versione:</strong>
        quando aggiungiamo nuovi vincoli o nuovi flussi, aggiorniamo questa pagina e incrementiamo il contratto.
      </div>
    </section>

    <section class="cv-partner-card mb-4">
      <h2 class="cv-section-title mb-3">Formato risposta standard</h2>
      <p class="text-secondary">
        Tutti gli endpoint devono rispondere in JSON con envelope uniforme. In caso di errore <code>success</code> vale <code>false</code> e il dettaglio va in <code>error</code>.
      </p>
      <pre class="cv-doc-code"><code><?= htmlspecialchars(cvDocPrettyJson($responseEnvelopeExample), ENT_QUOTES, 'UTF-8') ?></code></pre>
    </section>

    <section class="cv-partner-card mb-4">
      <h2 class="cv-section-title mb-3">Endpoint obbligatori di sync</h2>
      <div class="table-responsive">
        <table class="table cv-doc-table align-middle mb-0">
          <thead>
            <tr>
              <th>Endpoint</th>
              <th>Metodo</th>
              <th>Parametri</th>
              <th>Uso</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($syncEndpoints as $endpoint): ?>
              <tr>
                <td><code><?= htmlspecialchars($endpoint['name'], ENT_QUOTES, 'UTF-8') ?></code></td>
                <td><?= htmlspecialchars($endpoint['method'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($endpoint['params'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($endpoint['summary'], ENT_QUOTES, 'UTF-8') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="row g-4 mt-1">
        <div class="col-12 col-xl-6">
          <h3 class="cv-doc-subtitle">Esempio item <code>sync_stops</code></h3>
          <pre class="cv-doc-code"><code><?= htmlspecialchars(cvDocPrettyJson($syncStopItemExample), ENT_QUOTES, 'UTF-8') ?></code></pre>
        </div>
        <div class="col-12 col-xl-6">
          <h3 class="cv-doc-subtitle">Esempio item <code>sync_trips</code></h3>
          <pre class="cv-doc-code"><code><?= htmlspecialchars(cvDocPrettyJson($syncTripItemExample), ENT_QUOTES, 'UTF-8') ?></code></pre>
        </div>
      </div>

      <div class="cv-doc-note mt-3 mb-0">
        <strong>Regole sync:</strong>
        <code>page_size</code> massimo 1000, identificativi stabili nel tempo, risposta con <code>items</code>, <code>page</code>, <code>page_size</code>, <code>total</code>, <code>has_more</code>, <code>next_page</code>, <code>synced_at</code>.
        Il parametro <code>updated_since</code> e gia previsto, ma nelle reference implementation attuali puo essere ignorato se il database sorgente non espone un <code>updated_at</code> affidabile.
      </div>
    </section>

    <section class="cv-partner-card mb-4">
      <h2 class="cv-section-title mb-3">Endpoint real-time obbligatori</h2>
      <div class="table-responsive">
        <table class="table cv-doc-table align-middle mb-0">
          <thead>
            <tr>
              <th>Endpoint</th>
              <th>Metodo</th>
              <th>Parametri</th>
              <th>Uso</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($runtimeEndpoints as $endpoint): ?>
              <tr>
                <td><code><?= htmlspecialchars($endpoint['name'], ENT_QUOTES, 'UTF-8') ?></code></td>
                <td><?= htmlspecialchars($endpoint['method'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($endpoint['params'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($endpoint['summary'], ENT_QUOTES, 'UTF-8') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="row g-4 mt-1">
        <div class="col-12 col-xl-6">
          <h3 class="cv-doc-subtitle">Struttura minima <code>search</code></h3>
          <p class="text-secondary mb-2">
            Input minimo: <code>part</code>, <code>arr</code>, <code>ad</code>, <code>bam</code>, <code>dt1</code> in formato <code>dd/mm/YYYY</code>.
            <code>dt2</code> e opzionale per ritorno.
          </p>
          <pre class="cv-doc-code"><code><?= htmlspecialchars(cvDocPrettyJson($searchSolutionExample), ENT_QUOTES, 'UTF-8') ?></code></pre>
        </div>
        <div class="col-12 col-xl-6">
          <h3 class="cv-doc-subtitle">Struttura minima <code>quote</code></h3>
          <p class="text-secondary mb-2">
            <code>quote</code> deve accettare GET o POST e restituire token firmato, prezzo scelto, prezzo originario e disponibilita.
          </p>
          <pre class="cv-doc-code"><code><?= htmlspecialchars(cvDocPrettyJson($quoteRequestExample), ENT_QUOTES, 'UTF-8') ?></code></pre>
          <pre class="cv-doc-code cv-doc-code-tight"><code><?= htmlspecialchars(cvDocPrettyJson($quoteResponseExample), ENT_QUOTES, 'UTF-8') ?></code></pre>
        </div>
      </div>

      <div class="cv-doc-note mt-3 mb-0">
        <strong>Prezzi:</strong>
        per essere compatibili con Cercaviaggio il provider deve restituire nelle fares almeno
        <code>amount</code>, <code>original_amount</code>, <code>discount_percent</code>, <code>seats_available</code> e <code>change_allowed</code>.
        In questo modo possiamo decidere lato piattaforma se mostrare prezzo intero o scontato.
      </div>
    </section>

    <section class="cv-partner-card mb-4">
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
        <h2 class="cv-section-title mb-0">Endpoint checkout in evoluzione</h2>
        <span class="cv-doc-badge cv-doc-badge-warn">Bozza attuale</span>
      </div>
      <p class="text-secondary">
        Questi endpoint esistono gia nelle integrazioni di riferimento, ma il flusso definitivo di checkout/biglietteria sara completato in un passaggio successivo.
        Li pubblichiamo ora solo come base tecnica, non come contratto immutabile.
      </p>

      <div class="table-responsive">
        <table class="table cv-doc-table align-middle mb-0">
          <thead>
            <tr>
              <th>Endpoint</th>
              <th>Metodo</th>
              <th>Parametri</th>
              <th>Uso</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($draftCheckoutEndpoints as $endpoint): ?>
              <tr>
                <td><code><?= htmlspecialchars($endpoint['name'], ENT_QUOTES, 'UTF-8') ?></code></td>
                <td><?= htmlspecialchars($endpoint['method'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($endpoint['params'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($endpoint['summary'], ENT_QUOTES, 'UTF-8') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="cv-doc-note mt-3 mb-0">
        <strong>Nota importante:</strong>
        quando definiremo in modo finale checkout, emissione ticket, annulli e cambi, questa pagina verra aggiornata e versionata.
        Conviene comunque iniziare oggi con il contratto stabile di sync/search/quote, che e la parte gia sicura.
      </div>
    </section>

    <section class="cv-partner-card mb-4">
      <h2 class="cv-section-title mb-3">Regole minime di implementazione</h2>
      <ul class="cv-doc-list mb-0">
        <li>Risposta sempre JSON UTF-8 con envelope uniforme e campo <code>provider</code> stabile.</li>
        <li>Supporto a <code>OPTIONS</code>, <code>GET</code> e <code>POST</code> dove previsto dal contratto.</li>
        <li>Fermate, linee, corse e tariffe con ID esterni stabili e riutilizzabili nei refresh successivi.</li>
        <li>Date input per <code>search</code> e <code>quote</code> in formato <code>dd/mm/YYYY</code>; datetime in output in ISO 8601.</li>
        <li>Valuta standard <code>EUR</code>.</li>
        <li><code>quote</code> deve restituire un token con TTL e prezzo ricalcolato live sul segmento richiesto.</li>
        <li>Le reference implementation attuali usano il parametro <code>rquest</code> per la selezione endpoint.</li>
      </ul>
    </section>

    <?= cvRenderSiteFooter() ?>
  </main>
  <?= cvRenderSiteAuthModals() ?>
  <?= cvRenderNamedAssetBundle('public-core-js') ?>
  <?= cvRenderNamedAssetBundle('public-app-js') ?>
</body>
</html>
