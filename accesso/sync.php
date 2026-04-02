<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';
require_once dirname(__DIR__) . '/sync/sync_provider.php';

$state = cvAccessoInit();
if (!$state['authenticated']) {
    cvAccessoRenderLoginPage($state);
    return;
}

$runResults = [];
$enabledProviders = [];

try {
    $syncConfig = loadSyncConfig();
    $providers = isset($syncConfig['providers']) && is_array($syncConfig['providers']) ? $syncConfig['providers'] : [];
    $allowedProviderSet = array_fill_keys(cvAccessoAllowedProviderCodes($state), true);

    foreach ($providers as $code => $cfg) {
        if (empty($cfg['enabled'])) {
            continue;
        }
        if (!cvAccessoIsAdmin($state) && !isset($allowedProviderSet[$code])) {
            continue;
        }
        $enabledProviders[$code] = $cfg;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'run_sync') {
        if (!cvAccessoValidateCsrf()) {
            $state['errors'][] = 'Sessione non valida. Ricarica la pagina.';
        } else {
            $runAll = !empty($_POST['run_all']);
            $selectedProviders = [];

            if ($runAll) {
                $selectedProviders = array_keys($enabledProviders);
            } else {
                $selectedProviders = isset($_POST['providers']) && is_array($_POST['providers']) ? $_POST['providers'] : [];
                $selectedProviders = cvAccessoScopeProviderCodes($state, $selectedProviders);
                $selectedProviders = array_values(array_filter(
                    $selectedProviders,
                    static fn (string $providerCode): bool => isset($enabledProviders[$providerCode])
                ));
            }

            if (count($selectedProviders) === 0) {
                $state['errors'][] = 'Seleziona almeno un provider.';
            } else {
                $endpoint = trim((string) ($_POST['endpoint'] ?? ''));
                $pageSize = max(1, min(1000, (int) ($_POST['page_size'] ?? 500)));
                $validEndpoints = ['', 'sync_stops', 'sync_lines', 'sync_trips', 'sync_fares'];

                if (!in_array($endpoint, $validEndpoints, true)) {
                    $state['errors'][] = 'Endpoint non valido.';
                } else {
                    $options = [
                        'endpoint' => $endpoint,
                        'page_size' => $pageSize,
                        'full' => !empty($_POST['full']),
                    ];

                    foreach ($selectedProviders as $providerCode) {
                        try {
                            $summary = runSyncJob($syncConfig, $providerCode, $options);
                            $runResults[] = [
                                'provider' => $providerCode,
                                'status' => (string) ($summary['status'] ?? 'ok'),
                                'summary' => $summary,
                            ];
                        } catch (Throwable $exception) {
                            $runResults[] = [
                                'provider' => $providerCode,
                                'status' => 'error',
                                'error' => $exception->getMessage(),
                            ];
                        }
                    }

                    if (count($runResults) > 0) {
                        $state['messages'][] = 'Sync completato.';
                    }
                }
            }
        }
    }
} catch (Throwable $exception) {
    $state['errors'][] = 'Errore bootstrap sync: ' . $exception->getMessage();
}

cvAccessoRenderPageStart('Sync', 'sync', $state);
?>
<div class="row">
    <div class="col-md-12">
        <p class="cv-page-intro">
            Import provider dal backend unico. L’amministratore può lanciare più provider; l’azienda lavora solo sul proprio.
        </p>
    </div>
</div>

<div class="row">
    <div class="col-md-12">
        <div class="cv-panel-card">
            <form method="post">
                <input type="hidden" name="action" value="run_sync">
                <?= cvAccessoCsrfField() ?>

                <div class="row">
                    <div class="col-md-6">
                        <h4>Provider</h4>
                        <?php if (count($enabledProviders) === 0): ?>
                            <div class="cv-empty">Nessun provider abilitato per questo account.</div>
                        <?php elseif (cvAccessoIsAdmin($state)): ?>
                            <div class="cv-checklist">
                                <?php foreach ($enabledProviders as $index => $cfg): ?>
                                    <?php $code = (string) $index; ?>
                                    <?php $checkboxId = 'sync_provider_' . preg_replace('/[^a-z0-9_\\-]/i', '_', $code); ?>
                                    <div class="checkbox">
                                        <input
                                            id="<?= cvAccessoH($checkboxId) ?>"
                                            type="checkbox"
                                            name="providers[]"
                                            value="<?= cvAccessoH($code) ?>"
                                            checked
                                        >
                                        <label for="<?= cvAccessoH($checkboxId) ?>">
                                            <?= cvAccessoH($code) ?> - <?= cvAccessoH((string) ($cfg['name'] ?? $code)) ?>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <?php foreach ($enabledProviders as $code => $cfg): ?>
                                <input type="hidden" name="providers[]" value="<?= cvAccessoH($code) ?>">
                                <div class="cv-provider-fixed">
                                    <strong><?= cvAccessoH((string) ($cfg['name'] ?? $code)) ?></strong>
                                    <div class="cv-muted"><?= cvAccessoH($code) ?></div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <div class="col-md-6">
                        <div class="cv-form-grid">
                            <div class="form-group">
                                <label for="endpoint">Endpoint</label>
                                <select id="endpoint" name="endpoint" class="form-control">
                                    <option value="">All endpoints</option>
                                    <option value="sync_stops">sync_stops</option>
                                    <option value="sync_lines">sync_lines</option>
                                    <option value="sync_trips">sync_trips</option>
                                    <option value="sync_fares">sync_fares</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="page_size">Page size</label>
                                <input id="page_size" type="number" min="1" max="1000" name="page_size" value="500" class="form-control">
                            </div>

                            <label class="checkbox-inline">
                                <input type="checkbox" name="full" value="1" checked> Full import
                            </label>

                            <?php if (cvAccessoIsAdmin($state)): ?>
                                <label class="checkbox-inline">
                                    <input type="checkbox" name="run_all" value="1"> Ignora la selezione e importa tutti i provider visibili
                                </label>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="cv-inline-actions m-t-md">
                    <button class="btn btn-primary" type="submit">Esegui sync</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if (!empty($runResults)): ?>
    <div class="row">
        <div class="col-md-12">
            <div class="cv-panel-card">
                <h4>Risultati</h4>
                <?php foreach ($runResults as $result): ?>
                    <h5><?= cvAccessoH((string) ($result['provider'] ?? '')) ?> · <?= cvAccessoH((string) ($result['status'] ?? '')) ?></h5>
                    <pre class="cv-pre"><?php
                        if (isset($result['summary'])) {
                            echo cvAccessoH((string) json_encode($result['summary'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                        } else {
                            echo cvAccessoH((string) ($result['error'] ?? 'Errore sconosciuto'));
                        }
                    ?></pre>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
<?php endif; ?>
<?php
cvAccessoRenderPageEnd();
