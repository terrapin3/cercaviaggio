<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';

$state = cvAccessoInit();
if (!$state['authenticated']) {
    cvAccessoRenderLoginPage($state);
    return;
}

$providers = [];
$activeProviders = 0;
$inactiveProviders = 0;
$lastSyncAt = '';
$lastSyncDisplay = '-';
$scopeProviderCodes = cvAccessoIsAdmin($state) ? null : cvAccessoAllowedProviderCodes($state);
$cacheCounts = cvAccessoCountCacheFiles($scopeProviderCodes);

try {
    $connection = cvAccessoRequireConnection();
    $providers = cvAccessoFilterProviders($state, cvCacheFetchProviders($connection));
    foreach ($providers as $provider) {
        if ((int) ($provider['is_active'] ?? 0) === 1) {
            $activeProviders++;
        } else {
            $inactiveProviders++;
        }

        $currentSync = trim((string) ($provider['last_sync_at'] ?? ''));
        if ($currentSync !== '' && $currentSync > $lastSyncAt) {
            $lastSyncAt = $currentSync;
        }
    }

    if ($lastSyncAt !== '') {
        $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $lastSyncAt);
        if ($date instanceof DateTimeImmutable) {
            $lastSyncDisplay = $date->format('d/m/Y H:i');
        } else {
            $lastSyncDisplay = $lastSyncAt;
        }
    }
} catch (Throwable $exception) {
    $state['errors'][] = 'Errore DB: ' . $exception->getMessage();
}

cvAccessoRenderPageStart('Dashboard', 'index', $state);
?>
<div class="row">
    <div class="col-md-12">
        <p class="cv-page-intro">
            Pannello unico per provider, sync e cache. Il profilo corrente lavora su:
            <strong><?= cvAccessoH(cvAccessoScopeLabel($state)) ?></strong>.
        </p>
    </div>
</div>

<div class="row">
    <div class="col-sm-6 col-lg-3">
        <div class="cv-stat-card">
            <div class="cv-stat-value"><?= (int) $activeProviders ?></div>
            <div class="cv-stat-label">Provider attivi nel tuo scope</div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="cv-stat-card">
            <div class="cv-stat-value"><?= (int) $inactiveProviders ?></div>
            <div class="cv-stat-label">Provider non attivi</div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="cv-stat-card">
            <div class="cv-stat-value"><?= (int) array_sum($cacheCounts) ?></div>
            <div class="cv-stat-label">File cache visibili</div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="cv-stat-card">
            <div class="cv-stat-value" style="font-size:20px;"><?= cvAccessoH($lastSyncDisplay) ?></div>
            <div class="cv-stat-label">Ultimo sync</div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-4">
        <div class="cv-panel-card">
            <h4>Provider</h4>
            <p class="cv-muted">Stato provider, ultimo sync e ultimi errori disponibili.</p>
            <a class="btn btn-primary" href="<?= cvAccessoH(cvAccessoUrl('providers.php')) ?>">Apri sezione</a>
        </div>
    </div>
    <div class="col-md-4">
        <div class="cv-panel-card">
            <h4>Vetrina home</h4>
            <p class="cv-muted">Selezione manuale delle tratte che ogni provider vuole mostrare nella home.</p>
            <a class="btn btn-primary" href="<?= cvAccessoH(cvAccessoUrl('homepage.php')) ?>">Apri sezione</a>
        </div>
    </div>
    <div class="col-md-4">
        <div class="cv-panel-card">
            <h4>Biglietti</h4>
            <p class="cv-muted">Monitoraggio ticket emessi, ordine, provider, stato pagamento e dati tratta.</p>
            <a class="btn btn-primary" href="<?= cvAccessoH(cvAccessoUrl('biglietti.php')) ?>">Apri sezione</a>
        </div>
    </div>
    <div class="col-md-4">
        <div class="cv-panel-card">
            <h4>Sync</h4>
            <p class="cv-muted">Import manuale subito pronto anche per l’uso via cronjob.</p>
            <a class="btn btn-primary" href="<?= cvAccessoH(cvAccessoUrl('sync.php')) ?>">Apri sezione</a>
        </div>
    </div>
    <div class="col-md-4">
        <div class="cv-panel-card">
            <h4>Cache</h4>
            <p class="cv-muted">Version bump logico e purge fisica selettiva per provider.</p>
            <a class="btn btn-primary" href="<?= cvAccessoH(cvAccessoUrl('cache.php')) ?>">Apri sezione</a>
        </div>
    </div>
    <div class="col-md-4">
        <div class="cv-panel-card">
            <h4>Statistiche</h4>
            <p class="cv-muted">Cruscotto andamento ricerche e ordini (in evoluzione).</p>
            <a class="btn btn-primary" href="<?= cvAccessoH(cvAccessoUrl('statistiche.php')) ?>">Apri sezione</a>
        </div>
    </div>
    <?php if (cvAccessoIsAdmin($state)): ?>
        <div class="col-md-4">
            <div class="cv-panel-card">
                <h4>Utenti</h4>
                <p class="cv-muted">Account backend, ruoli e assegnazioni provider.</p>
                <a class="btn btn-primary" href="<?= cvAccessoH(cvAccessoUrl('users.php')) ?>">Apri sezione</a>
            </div>
        </div>
        <div class="col-md-4">
            <div class="cv-panel-card">
                <h4>Assistente</h4>
                <p class="cv-muted">Configurazione chat pubblica, knowledge base FAQ e storico conversazioni salvate.</p>
                <a class="btn btn-primary" href="<?= cvAccessoH(cvAccessoUrl('assistant.php')) ?>">Apri sezione</a>
            </div>
        </div>
        <div class="col-md-4">
            <div class="cv-panel-card">
                <h4>Error log</h4>
                <p class="cv-muted">Errori tecnici API/checkout salvati su DB con filtri e retention.</p>
                <a class="btn btn-primary" href="<?= cvAccessoH(cvAccessoUrl('error-log.php')) ?>">Apri sezione</a>
            </div>
        </div>
        <div class="col-md-4">
            <div class="cv-panel-card">
                <h4>Manutenzione</h4>
                <p class="cv-muted">Pulizia tecnica centralizzata: cache, località obsolete e reset contatori.</p>
                <a class="btn btn-primary" href="<?= cvAccessoH(cvAccessoUrl('manutenzione.php')) ?>">Apri sezione</a>
            </div>
        </div>
        <div class="col-md-4">
            <div class="cv-panel-card">
                <h4>Frontend & SEO</h4>
                <p class="cv-muted">Bundle statici del frontend, cache asset e rigenerazione di sitemap.xml.</p>
                <a class="btn btn-primary" href="<?= cvAccessoH(cvAccessoUrl('frontend.php')) ?>">Apri sezione</a>
            </div>
        </div>
        <div class="col-md-4">
            <div class="cv-panel-card">
                <h4>Pagine tratte</h4>
                <p class="cv-muted">Bozze SEO generate dalle tratte più richieste, con approvazione manuale e pubblicazione in sitemap.</p>
                <a class="btn btn-primary" href="<?= cvAccessoH(cvAccessoUrl('tratte-seo.php')) ?>">Apri sezione</a>
            </div>
        </div>
        <div class="col-md-4">
            <div class="cv-panel-card">
                <h4>Promozioni</h4>
                <p class="cv-muted">Gestione codici sconto Cercaviaggio su commissione (auto o manuali).</p>
                <a class="btn btn-primary" href="<?= cvAccessoH(cvAccessoUrl('promozioni.php')) ?>">Apri sezione</a>
            </div>
        </div>
        <div class="col-md-4">
            <div class="cv-panel-card">
                <h4>Blog</h4>
                <p class="cv-muted">Gestione dinamica articoli con ordinamento manuale, stato pubblicazione e SEO in sitemap.</p>
                <a class="btn btn-primary" href="<?= cvAccessoH(cvAccessoUrl('blog.php')) ?>">Apri sezione</a>
            </div>
        </div>
        <div class="col-md-4">
            <div class="cv-panel-card">
                <h4>Settings</h4>
                <p class="cv-muted">Parametri dinamici del pathfind e configurazioni globali backend.</p>
                <a class="btn btn-primary" href="<?= cvAccessoH(cvAccessoUrl('settings.php')) ?>">Apri sezione</a>
            </div>
        </div>
    <?php endif; ?>
</div>

<div class="row">
    <div class="col-md-12">
        <div class="cv-panel-card">
            <h4>Bucket cache</h4>
            <div class="table-responsive">
                <table class="table cv-table">
                    <thead>
                    <tr>
                        <th>Bucket</th>
                        <th>File</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($cacheCounts as $bucketKey => $count): ?>
                        <tr>
                            <td><code><?= cvAccessoH($bucketKey) ?></code></td>
                            <td><?= (int) $count ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php
cvAccessoRenderPageEnd();
