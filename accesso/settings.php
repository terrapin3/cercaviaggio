<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';

$state = cvAccessoInit();
if (!$state['authenticated']) {
    cvAccessoRenderLoginPage($state);
    return;
}

if (!cvAccessoIsAdmin($state)) {
    http_response_code(403);
    cvAccessoRenderPageStart('Settings', 'settings-search', $state);
    ?>
    <div class="row">
        <div class="col-md-12">
            <div class="cv-panel-card">
                <div class="cv-empty">Questa sezione è disponibile solo per l’amministratore.</div>
            </div>
        </div>
    </div>
    <?php
    cvAccessoRenderPageEnd();
    return;
}

$specs = cvRuntimeGeneralSettingSpecs();
$settings = cvRuntimeSettingsDefaults();
$tableExists = false;

try {
    $connection = cvAccessoRequireConnection();
    $tableExists = cvRuntimeSettingsTableExists($connection);
    $settings = cvRuntimeSettings($connection);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_settings') {
        if (!cvAccessoValidateCsrf()) {
            $state['errors'][] = 'Sessione non valida. Ricarica la pagina.';
        } elseif (!$tableExists) {
            $state['errors'][] = 'La tabella cv_settings non esiste ancora nel database.';
        } else {
            $payload = [];

            foreach ($specs as $key => $spec) {
                if (
                    $key === 'homepage_popular_provider_codes' ||
                    $key === 'homepage_popular_provider_limits' ||
                    $key === 'provider_price_modes' ||
                    $key === 'ticket_pdf_provider_show_email_map' ||
                    $key === 'ticket_pdf_provider_show_site_map' ||
                    $key === 'ticket_pdf_provider_site_map'
                ) {
                    continue;
                }

                if ($key === 'pathfind_cache_ttl_seconds') {
                    $minutesRaw = array_key_exists('pathfind_cache_ttl_minutes', $_POST)
                        ? $_POST['pathfind_cache_ttl_minutes']
                        : ((int) ($settings[$key] ?? $spec['default']) / 60);
                    $payload[$key] = (int) round((float) $minutesRaw * 60);
                    continue;
                }

                $payload[$key] = array_key_exists($key, $_POST)
                    ? $_POST[$key]
                    : ($settings[$key] ?? $spec['default']);
            }

            $settings = cvRuntimeSaveSettings($connection, $payload);
            $state['messages'][] = 'Parametri ricerca aggiornati. Le nuove ricerche useranno subito i nuovi limiti.';
        }
    }
} catch (Throwable $exception) {
    $state['errors'][] = 'Errore sezione settings: ' . $exception->getMessage();
}

cvAccessoRenderPageStart('Settings', 'settings-search', $state);
?>
<div class="row">
    <div class="col-md-12">
        <p class="cv-page-intro">
            Sezione settings del backend. Parametri globali del motore di ricerca, letti dal database a runtime.
        </p>
    </div>
</div>

<?php if (!$tableExists): ?>
    <div class="row">
        <div class="col-md-12">
            <div class="cv-panel-card">
                <div class="alert alert-warning cv-alert" role="alert">
                    La tabella <code>cv_settings</code> non esiste ancora. Importa prima la query SQL, poi potrai modificare questi parametri dal backend.
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="row">
    <div class="col-md-8">
        <div class="cv-panel-card">
            <h4>Ricerca</h4>
            <form method="post" class="cv-form-grid">
                <input type="hidden" name="action" value="save_settings">
                <?= cvAccessoCsrfField() ?>

                <?php foreach ($specs as $key => $spec): ?>
                    <?php if (
                        $key === 'homepage_popular_provider_codes' ||
                        $key === 'homepage_popular_provider_limits' ||
                        $key === 'provider_price_modes' ||
                        $key === 'ticket_pdf_provider_show_email_map' ||
                        $key === 'ticket_pdf_provider_show_site_map' ||
                        $key === 'ticket_pdf_provider_site_map'
                    ) {
                        continue;
                    } ?>
                    <?php
                    $specType = (string) ($spec['type'] ?? 'string');
                    $isNumeric = ($specType === 'int' || $specType === 'float');
                    $isBinarySwitch = $key === 'pathfind_date_price_calendar_enabled';
                    $inputType = $isNumeric ? 'number' : 'text';
                    $value = $settings[$key] ?? ($spec['default'] ?? '');
                    $fieldName = $key;
                    $fieldId = $key;
                    $unit = trim((string) ($spec['unit'] ?? ''));
                    $min = isset($spec['min']) ? (string) $spec['min'] : null;
                    $max = isset($spec['max']) ? (string) $spec['max'] : null;
                    $step = isset($spec['step']) ? (string) $spec['step'] : null;
                    if ($key === 'pathfind_cache_ttl_seconds') {
                        $fieldName = 'pathfind_cache_ttl_minutes';
                        $fieldId = 'pathfind_cache_ttl_minutes';
                        $value = (string) max(1, (int) round(((float) $value) / 60));
                        $unit = 'minuti';
                        $min = '1';
                        $max = '1440';
                        $step = '1';
                    }
                    $rangeId = $fieldId . '_range';
                    ?>
                    <div class="form-group">
                        <label for="<?= cvAccessoH($fieldId) ?>"><?= cvAccessoH((string) ($spec['label'] ?? $key)) ?></label>
                        <?php if ($isBinarySwitch): ?>
                            <input type="hidden" name="<?= cvAccessoH($fieldName) ?>" value="0">
                            <label class="cv-assistant-live-switch" for="<?= cvAccessoH($fieldId) ?>">
                                <input
                                    id="<?= cvAccessoH($fieldId) ?>"
                                    name="<?= cvAccessoH($fieldName) ?>"
                                    type="checkbox"
                                    value="1"
                                    <?= ((int) $value) === 1 ? 'checked' : '' ?>
                                >
                                <span class="cv-assistant-live-switch-track" aria-hidden="true"></span>
                                <span class="cv-assistant-live-switch-text">
                                    <?= ((int) $value) === 1 ? 'Mostra prezzi nella riga date' : 'Nascondi prezzi nella riga date' ?>
                                </span>
                            </label>
                        <?php elseif ($isNumeric): ?>
                            <div class="row" style="margin:0;">
                                <div class="col-md-4" style="padding-left:0;">
                                    <input
                                        id="<?= cvAccessoH($fieldId) ?>"
                                        name="<?= cvAccessoH($fieldName) ?>"
                                        type="number"
                                        class="form-control cv-range-number"
                                        value="<?= cvAccessoH((string) $value) ?>"
                                        data-range-target="<?= cvAccessoH($rangeId) ?>"
                                        <?php if ($min !== null): ?>min="<?= cvAccessoH($min) ?>"<?php endif; ?>
                                        <?php if ($max !== null): ?>max="<?= cvAccessoH($max) ?>"<?php endif; ?>
                                        <?php if ($step !== null): ?>step="<?= cvAccessoH($step) ?>"<?php endif; ?>
                                    >
                                </div>
                                <div class="col-md-8" style="padding-right:0; padding-top:6px;">
                                    <input
                                        id="<?= cvAccessoH($rangeId) ?>"
                                        type="range"
                                        class="form-control cv-range-slider"
                                        value="<?= cvAccessoH((string) $value) ?>"
                                        data-number-target="<?= cvAccessoH($fieldId) ?>"
                                        <?php if ($min !== null): ?>min="<?= cvAccessoH($min) ?>"<?php endif; ?>
                                        <?php if ($max !== null): ?>max="<?= cvAccessoH($max) ?>"<?php endif; ?>
                                        <?php if ($step !== null): ?>step="<?= cvAccessoH($step) ?>"<?php endif; ?>
                                    >
                                </div>
                            </div>
                        <?php else: ?>
                            <input
                                id="<?= cvAccessoH($fieldId) ?>"
                                name="<?= cvAccessoH($fieldName) ?>"
                                type="<?= cvAccessoH($inputType) ?>"
                                class="form-control"
                                value="<?= cvAccessoH((string) $value) ?>"
                            >
                        <?php endif; ?>
                        <div class="cv-muted">
                            <?= cvAccessoH((string) ($spec['help'] ?? '')) ?>
                            <?php if ($unit !== ''): ?>
                                Valore espresso in <?= cvAccessoH($unit) ?>.
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>

                <div class="cv-inline-actions">
                    <button type="submit" class="btn btn-primary"<?= $tableExists ? '' : ' disabled' ?>>Salva parametri</button>
                </div>
            </form>
        </div>
    </div>

    <div class="col-md-4">
        <div class="cv-panel-card">
            <h4>Note operative</h4>
            <p class="cv-muted">L’attesa massima scalo elimina soluzioni con coincidenze troppo lunghe.</p>
            <p class="cv-muted">Le distanze limitano i cambi automatici tra fermate diverse ma vicine.</p>
            <p class="cv-muted">Il cambio valore invalida logicamente la cache ricerca: le nuove query non riuseranno risultati con parametri vecchi.</p>
            <p><a class="btn btn-default btn-sm" href="<?= cvAccessoH(cvAccessoUrl('mail-settings.php')) ?>">Apri Settings Mail</a></p>
            <p><a class="btn btn-default btn-sm" href="<?= cvAccessoH(cvAccessoUrl('providers.php')) ?>">Apri Provider</a></p>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var rangeSliders = document.querySelectorAll('.cv-range-slider[data-number-target]');
    for (var i = 0; i < rangeSliders.length; i += 1) {
        (function (slider) {
            var numberId = slider.getAttribute('data-number-target');
            if (!numberId) {
                return;
            }
            var numberInput = document.getElementById(numberId);
            if (!numberInput) {
                return;
            }
            slider.addEventListener('input', function () {
                numberInput.value = slider.value;
            });
            numberInput.addEventListener('input', function () {
                slider.value = numberInput.value;
            });
        })(rangeSliders[i]);
    }

    var rangeNumbers = document.querySelectorAll('.cv-range-number[data-range-target]');
    for (var j = 0; j < rangeNumbers.length; j += 1) {
        (function (numberInput) {
            var rangeId = numberInput.getAttribute('data-range-target');
            if (!rangeId) {
                return;
            }
            var slider = document.getElementById(rangeId);
            if (!slider) {
                return;
            }
            slider.addEventListener('input', function () {
                numberInput.value = slider.value;
            });
            numberInput.addEventListener('input', function () {
                slider.value = numberInput.value;
            });
        })(rangeNumbers[j]);
    }
});
</script>
<?php
cvAccessoRenderPageEnd();
