<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';

$state = cvAccessoInit();
if (!$state['authenticated']) {
    cvAccessoRenderLoginPage($state);
    return;
}

$providers = [];
$actionResults = [];
$cacheBuckets = cvCacheDirectoryMap();

try {
    $connection = cvAccessoRequireConnection();
    $providers = cvAccessoFilterProviders($state, cvCacheFetchProviders($connection));

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        if (!cvAccessoValidateCsrf()) {
            $state['errors'][] = 'Sessione non valida. Ricarica la pagina.';
        } else {
            $selectedProviders = isset($_POST['providers']) && is_array($_POST['providers']) ? $_POST['providers'] : [];
            $selectedProviders = cvAccessoScopeProviderCodes($state, $selectedProviders);
            $selectedBuckets = isset($_POST['buckets']) && is_array($_POST['buckets']) ? $_POST['buckets'] : array_keys($cacheBuckets);

            if ($_POST['action'] === 'bump_versions') {
                if (count($selectedProviders) === 0) {
                    $state['errors'][] = 'Seleziona almeno un provider.';
                } else {
                    $result = cvCacheBumpProviders($connection, $selectedProviders);
                    $actionResults[] = [
                        'title' => 'Provider version bump',
                        'data' => $result,
                    ];
                    $state['messages'][] = 'Versione cache provider aggiornata.';
                }
            }

            if ($_POST['action'] === 'purge_provider_cache') {
                if (count($selectedProviders) === 0) {
                    $state['errors'][] = 'Seleziona almeno un provider.';
                } else {
                    $result = cvCachePurgeBuckets($selectedBuckets, $selectedProviders);
                    $actionResults[] = [
                        'title' => 'Selective cache purge',
                        'data' => [
                            'providers' => $selectedProviders,
                            'buckets' => $selectedBuckets,
                            'result' => $result,
                        ],
                    ];
                    $state['messages'][] = 'Purge selettiva completata.';
                }
            }

            if ($_POST['action'] === 'purge_all_cache') {
                if (!cvAccessoIsAdmin($state)) {
                    $state['errors'][] = 'Solo l’amministratore può eseguire il purge totale.';
                } else {
                    $result = cvCachePurgeBuckets($selectedBuckets, null);
                    $actionResults[] = [
                        'title' => 'Full cache purge',
                        'data' => [
                            'buckets' => $selectedBuckets,
                            'result' => $result,
                        ],
                    ];
                    $state['messages'][] = 'Purge totale completata.';
                }
            }
        }
    }
} catch (Throwable $exception) {
    $state['errors'][] = 'Errore sezione cache: ' . $exception->getMessage();
}

cvAccessoRenderPageStart('Cache', 'cache', $state);
?>
<div class="row">
    <div class="col-md-12">
        <p class="cv-page-intro">
            Invalidazione logica e pulizia fisica della cache. Le aziende operano solo sui provider assegnati; il purge totale resta amministrativo.
        </p>
    </div>
</div>

<div class="row">
    <div class="col-md-6">
        <div class="cv-panel-card">
            <h4>Force version bump</h4>
            <p class="cv-muted">Aggiorna `last_sync_at` dei provider selezionati per invalidare logicamente le cache.</p>
            <form method="post">
                <input type="hidden" name="action" value="bump_versions">
                <?= cvAccessoCsrfField() ?>

                <?php if (cvAccessoIsAdmin($state)): ?>
                    <div class="cv-checklist">
                        <?php foreach ($providers as $index => $provider): ?>
                            <?php if ((int) ($provider['is_active'] ?? 0) !== 1) { continue; } ?>
                            <?php $checkboxId = 'provider_bump_' . (string) $index; ?>
                            <div class="checkbox">
                                <input
                                    id="<?= cvAccessoH($checkboxId) ?>"
                                    type="checkbox"
                                    name="providers[]"
                                    value="<?= cvAccessoH((string) ($provider['code'] ?? '')) ?>"
                                >
                                <label for="<?= cvAccessoH($checkboxId) ?>">
                                    <?= cvAccessoH((string) ($provider['code'] ?? '')) ?> - <?= cvAccessoH((string) ($provider['name'] ?? '')) ?>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <?php foreach ($providers as $provider): ?>
                        <?php if ((int) ($provider['is_active'] ?? 0) !== 1) { continue; } ?>
                        <input type="hidden" name="providers[]" value="<?= cvAccessoH((string) ($provider['code'] ?? '')) ?>">
                        <div class="cv-provider-fixed">
                            <strong><?= cvAccessoH((string) ($provider['name'] ?? '')) ?></strong>
                            <div class="cv-muted"><?= cvAccessoH((string) ($provider['code'] ?? '')) ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <button class="btn btn-primary" type="submit">Aggiorna versione provider</button>
            </form>
        </div>
    </div>

    <div class="col-md-6">
        <div class="cv-panel-card">
            <h4>Purge selettiva</h4>
            <p class="cv-muted">Cancella fisicamente i file cache associati ai provider selezionati.</p>
            <form method="post">
                <input type="hidden" name="action" value="purge_provider_cache">
                <?= cvAccessoCsrfField() ?>

                <?php if (cvAccessoIsAdmin($state)): ?>
                    <div class="cv-checklist">
                        <?php foreach ($providers as $index => $provider): ?>
                            <?php if ((int) ($provider['is_active'] ?? 0) !== 1) { continue; } ?>
                            <?php $checkboxId = 'provider_purge_' . (string) $index; ?>
                            <div class="checkbox">
                                <input
                                    id="<?= cvAccessoH($checkboxId) ?>"
                                    type="checkbox"
                                    name="providers[]"
                                    value="<?= cvAccessoH((string) ($provider['code'] ?? '')) ?>"
                                >
                                <label for="<?= cvAccessoH($checkboxId) ?>">
                                    <?= cvAccessoH((string) ($provider['code'] ?? '')) ?> - <?= cvAccessoH((string) ($provider['name'] ?? '')) ?>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <?php foreach ($providers as $provider): ?>
                        <?php if ((int) ($provider['is_active'] ?? 0) !== 1) { continue; } ?>
                        <input type="hidden" name="providers[]" value="<?= cvAccessoH((string) ($provider['code'] ?? '')) ?>">
                        <div class="cv-provider-fixed">
                            <strong><?= cvAccessoH((string) ($provider['name'] ?? '')) ?></strong>
                            <div class="cv-muted"><?= cvAccessoH((string) ($provider['code'] ?? '')) ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <div class="cv-checklist">
                    <?php foreach ($cacheBuckets as $bucketKey => $bucket): ?>
                        <?php $checkboxId = 'bucket_purge_' . preg_replace('/[^a-z0-9_\\-]/i', '_', (string) $bucketKey); ?>
                        <div class="checkbox">
                            <input
                                id="<?= cvAccessoH($checkboxId) ?>"
                                type="checkbox"
                                name="buckets[]"
                                value="<?= cvAccessoH($bucketKey) ?>"
                                checked
                            >
                            <label for="<?= cvAccessoH($checkboxId) ?>">
                                <?= cvAccessoH($bucketKey) ?> · <?= cvAccessoH($bucket['label']) ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>

                <button class="btn btn-primary" type="submit">Esegui purge selettiva</button>
            </form>
        </div>
    </div>
</div>

<?php if (cvAccessoIsAdmin($state)): ?>
    <div class="row">
        <div class="col-md-12">
            <div class="cv-panel-card">
                <h4>Purge totale</h4>
                <p class="cv-muted">Cancella tutte le cache dei bucket selezionati.</p>
                <form method="post">
                    <input type="hidden" name="action" value="purge_all_cache">
                    <?= cvAccessoCsrfField() ?>

                    <div class="cv-checklist">
                        <?php foreach ($cacheBuckets as $bucketKey => $bucket): ?>
                            <?php $checkboxId = 'bucket_full_' . preg_replace('/[^a-z0-9_\\-]/i', '_', (string) $bucketKey); ?>
                            <div class="checkbox">
                                <input
                                    id="<?= cvAccessoH($checkboxId) ?>"
                                    type="checkbox"
                                    name="buckets[]"
                                    value="<?= cvAccessoH($bucketKey) ?>"
                                    checked
                                >
                                <label for="<?= cvAccessoH($checkboxId) ?>">
                                    <?= cvAccessoH($bucketKey) ?> · <?= cvAccessoH($bucket['label']) ?> · <code><?= cvAccessoH($bucket['path']) ?></code>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <button class="btn btn-danger" type="submit">Cancella tutte le cache selezionate</button>
                </form>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if (!empty($actionResults)): ?>
    <div class="row">
        <div class="col-md-12">
            <div class="cv-panel-card">
                <h4>Risultati operazioni</h4>
                <?php foreach ($actionResults as $result): ?>
                    <h5><?= cvAccessoH((string) ($result['title'] ?? 'Risultato')) ?></h5>
                    <pre class="cv-pre"><?= cvAccessoH((string) json_encode($result['data'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
<?php endif; ?>
<?php
cvAccessoRenderPageEnd();
