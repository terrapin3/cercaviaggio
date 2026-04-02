<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../includes/promotions_tools.php';

$state = cvAccessoInit();
if (!$state['authenticated']) {
    cvAccessoRenderLoginPage($state);
    return;
}

if (!cvAccessoIsAdmin($state)) {
    http_response_code(403);
    cvAccessoRenderPageStart('Promozioni', 'settings-promotions', $state);
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

$providers = [];
$rows = [];
$editRow = null;

$weekdayLabels = [
    0 => 'Domenica',
    1 => 'Lunedi',
    2 => 'Martedi',
    3 => 'Mercoledi',
    4 => 'Giovedi',
    5 => 'Venerdi',
    6 => 'Sabato',
];

/**
 * @return array<string,mixed>
 */
function cvPromoRowDefaults(): array
{
    return [
        'id_promo' => 0,
        'name' => '',
        'code' => '',
        'discount_percent' => '5.00',
        'mode' => 'code',
        'visibility' => 'hidden',
        'provider_codes' => '',
        'days_of_week' => '',
        'valid_from' => '',
        'valid_to' => '',
        'priority' => 100,
        'notes' => '',
        'is_active' => 1,
    ];
}

function cvPromoParseDateTimeLocal(?string $value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    $dt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $value);
    if (!$dt instanceof DateTimeImmutable) {
        return null;
    }
    return $dt->format('Y-m-d H:i:s');
}

function cvPromoFormatDateTimeLocal(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }
    $ts = strtotime($value);
    if (!is_int($ts) || $ts <= 0) {
        return '';
    }
    return date('Y-m-d\TH:i', $ts);
}

try {
    $connection = cvAccessoRequireConnection();
    if (!cvPromotionsEnsureTable($connection)) {
        throw new RuntimeException('Impossibile inizializzare la tabella promozioni.');
    }

    $providers = cvCacheFetchProviders($connection);
    $providerMap = [];
    foreach ($providers as $provider) {
        if (!is_array($provider)) {
            continue;
        }
        $code = strtolower(trim((string) ($provider['code'] ?? '')));
        if ($code === '') {
            continue;
        }
        $providerMap[$code] = trim((string) ($provider['name'] ?? $code));
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = trim((string) ($_POST['action'] ?? ''));
        if (!cvAccessoValidateCsrf()) {
            $state['errors'][] = 'Sessione non valida. Ricarica la pagina.';
        } elseif ($action === 'save_promo') {
            $idPromo = isset($_POST['id_promo']) ? (int) $_POST['id_promo'] : 0;
            $name = trim((string) ($_POST['name'] ?? ''));
            $mode = strtolower(trim((string) ($_POST['mode'] ?? 'code')));
            $mode = in_array($mode, ['auto', 'code'], true) ? $mode : 'code';
            $visibility = strtolower(trim((string) ($_POST['visibility'] ?? 'hidden')));
            $visibility = in_array($visibility, ['public', 'hidden'], true) ? $visibility : 'hidden';
            $code = strtoupper(trim((string) ($_POST['code'] ?? '')));
            $discountPercent = isset($_POST['discount_percent']) && is_numeric($_POST['discount_percent'])
                ? (float) $_POST['discount_percent']
                : 0.0;
            $discountPercent = max(0.0, min(100.0, $discountPercent));
            $providerCodesCsv = cvPromotionsProviderCodesToCsv(isset($_POST['provider_codes']) && is_array($_POST['provider_codes']) ? $_POST['provider_codes'] : []);
            $daysCsv = cvPromotionsWeekdaysToCsv(isset($_POST['days_of_week']) && is_array($_POST['days_of_week']) ? $_POST['days_of_week'] : []);
            $validFrom = cvPromoParseDateTimeLocal((string) ($_POST['valid_from'] ?? ''));
            $validTo = cvPromoParseDateTimeLocal((string) ($_POST['valid_to'] ?? ''));
            $priority = isset($_POST['priority']) && is_numeric($_POST['priority']) ? (int) $_POST['priority'] : 100;
            $priority = max(1, min(9999, $priority));
            $notes = trim((string) ($_POST['notes'] ?? ''));
            $isActive = !empty($_POST['is_active']) ? 1 : 0;

            if ($name === '') {
                $state['errors'][] = 'Inserisci un nome promozione.';
            } elseif ($discountPercent <= 0) {
                $state['errors'][] = 'Lo sconto deve essere maggiore di zero.';
            } elseif ($mode === 'code' && $code === '') {
                $state['errors'][] = 'Per le promo con codice devi inserire il codice.';
            } elseif ($validFrom !== null && $validTo !== null && strtotime($validFrom) > strtotime($validTo)) {
                $state['errors'][] = 'La data fine deve essere successiva alla data inizio.';
            } else {
                if ($idPromo > 0) {
                    $sql = "UPDATE cv_promotions
                            SET name = ?, code = ?, discount_percent = ?, mode = ?, visibility = ?, provider_codes = ?, days_of_week = ?, valid_from = ?, valid_to = ?, priority = ?, notes = ?, is_active = ?
                            WHERE id_promo = ?";
                    $stmt = $connection->prepare($sql);
                    if (!$stmt instanceof mysqli_stmt) {
                        throw new RuntimeException('Prepare update promozione fallita.');
                    }
                    $stmt->bind_param(
                        'ssdssssssissi',
                        $name,
                        $code,
                        $discountPercent,
                        $mode,
                        $visibility,
                        $providerCodesCsv,
                        $daysCsv,
                        $validFrom,
                        $validTo,
                        $priority,
                        $notes,
                        $isActive,
                        $idPromo
                    );
                    if (!$stmt->execute()) {
                        $error = $stmt->error;
                        $stmt->close();
                        throw new RuntimeException('Update promozione fallito: ' . $error);
                    }
                    $stmt->close();
                    $state['messages'][] = 'Promozione aggiornata.';
                } else {
                    $sql = "INSERT INTO cv_promotions
                            (name, code, discount_percent, mode, visibility, provider_codes, days_of_week, valid_from, valid_to, priority, notes, is_active)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    $stmt = $connection->prepare($sql);
                    if (!$stmt instanceof mysqli_stmt) {
                        throw new RuntimeException('Prepare insert promozione fallita.');
                    }
                    $stmt->bind_param(
                        'ssdssssssisi',
                        $name,
                        $code,
                        $discountPercent,
                        $mode,
                        $visibility,
                        $providerCodesCsv,
                        $daysCsv,
                        $validFrom,
                        $validTo,
                        $priority,
                        $notes,
                        $isActive
                    );
                    if (!$stmt->execute()) {
                        $error = $stmt->error;
                        $stmt->close();
                        throw new RuntimeException('Insert promozione fallito: ' . $error);
                    }
                    $stmt->close();
                    $state['messages'][] = 'Promozione creata.';
                }
            }
        } elseif ($action === 'toggle_status') {
            $idPromo = isset($_POST['id_promo']) ? (int) $_POST['id_promo'] : 0;
            $next = !empty($_POST['next']) ? 1 : 0;
            if ($idPromo > 0) {
                $stmt = $connection->prepare("UPDATE cv_promotions SET is_active = ? WHERE id_promo = ?");
                if ($stmt instanceof mysqli_stmt) {
                    $stmt->bind_param('ii', $next, $idPromo);
                    $stmt->execute();
                    $stmt->close();
                    $state['messages'][] = $next === 1 ? 'Promozione attivata.' : 'Promozione disattivata.';
                }
            }
        }
    }

    $editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
    if ($editId > 0) {
        $stmt = $connection->prepare("SELECT * FROM cv_promotions WHERE id_promo = ? LIMIT 1");
        if ($stmt instanceof mysqli_stmt) {
            $stmt->bind_param('i', $editId);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
                if (is_array($row)) {
                    $editRow = $row;
                }
                if ($result instanceof mysqli_result) {
                    $result->free();
                }
            }
            $stmt->close();
        }
    }

    $result = $connection->query("SELECT * FROM cv_promotions ORDER BY is_active DESC, priority ASC, id_promo DESC");
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }
        $result->free();
    }
} catch (Throwable $exception) {
    $state['errors'][] = 'Errore sezione promozioni: ' . $exception->getMessage();
}

$form = cvPromoRowDefaults();
if (is_array($editRow)) {
    foreach ($form as $key => $defaultValue) {
        if (array_key_exists($key, $editRow)) {
            $form[$key] = $editRow[$key];
        }
    }
}
$form['code'] = strtoupper(trim((string) ($form['code'] ?? '')));
$form['valid_from'] = cvPromoFormatDateTimeLocal((string) ($form['valid_from'] ?? ''));
$form['valid_to'] = cvPromoFormatDateTimeLocal((string) ($form['valid_to'] ?? ''));
$formProviderCodes = cvPromotionsNormalizeProviderCodes((string) ($form['provider_codes'] ?? ''));
$formWeekDays = cvPromotionsNormalizeWeekdays((string) ($form['days_of_week'] ?? ''));

cvAccessoRenderPageStart('Promozioni', 'settings-promotions', $state);
?>
<div class="row">
    <div class="col-md-12">
        <p class="cv-page-intro">
            Sconti Cercaviaggio applicati alla tua commissione (non ai prezzi base provider). Supporta promo automatiche o tramite codice.
        </p>
    </div>
</div>

<div class="row">
    <div class="col-md-5">
        <div class="cv-panel-card">
            <h4><?= (int) ($form['id_promo'] ?? 0) > 0 ? 'Modifica promozione' : 'Nuova promozione' ?></h4>
            <form method="post">
                <input type="hidden" name="action" value="save_promo">
                <input type="hidden" name="id_promo" value="<?= (int) ($form['id_promo'] ?? 0) ?>">
                <?= cvAccessoCsrfField() ?>

                <div class="form-group">
                    <label>Nome</label>
                    <input type="text" name="name" class="form-control" maxlength="190" value="<?= cvAccessoH((string) ($form['name'] ?? '')) ?>" required>
                </div>

                <div class="form-group">
                    <label>Tipo</label>
                    <select name="mode" class="form-control">
                        <option value="auto"<?= ((string) ($form['mode'] ?? '') === 'auto') ? ' selected' : '' ?>>Automatica (visibile in checkout)</option>
                        <option value="code"<?= ((string) ($form['mode'] ?? '') === 'code') ? ' selected' : '' ?>>Codice da inserire</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Visibilità</label>
                    <select name="visibility" class="form-control">
                        <option value="public"<?= ((string) ($form['visibility'] ?? '') === 'public') ? ' selected' : '' ?>>Pubblica</option>
                        <option value="hidden"<?= ((string) ($form['visibility'] ?? '') === 'hidden') ? ' selected' : '' ?>>Nascosta</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Codice (solo promo manuali)</label>
                    <input type="text" name="code" class="form-control" maxlength="40" value="<?= cvAccessoH((string) ($form['code'] ?? '')) ?>" placeholder="ES. CVWELCOME10">
                </div>

                <div class="form-group">
                    <label>Sconto % sulla commissione</label>
                    <input type="number" step="0.01" min="0" max="100" name="discount_percent" class="form-control" value="<?= cvAccessoH((string) ($form['discount_percent'] ?? '0')) ?>" required>
                </div>

                <div class="form-group">
                    <label>Provider inclusi (vuoto = tutti)</label>
                    <div class="cv-checklist">
                        <?php foreach ($providers as $provider): ?>
                            <?php if (!is_array($provider)) { continue; } ?>
                            <?php $code = strtolower(trim((string) ($provider['code'] ?? ''))); ?>
                            <?php if ($code === '') { continue; } ?>
                            <div class="checkbox">
                                <input type="checkbox" name="provider_codes[]" value="<?= cvAccessoH($code) ?>"<?= in_array($code, $formProviderCodes, true) ? ' checked' : '' ?>>
                                <label><?= cvAccessoH($code) ?> - <?= cvAccessoH((string) ($provider['name'] ?? $code)) ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="form-group">
                    <label>Giorni validi (vuoto = tutti)</label>
                    <div class="cv-checklist">
                        <?php foreach ($weekdayLabels as $weekdayValue => $weekdayLabel): ?>
                            <div class="checkbox">
                                <input type="checkbox" name="days_of_week[]" value="<?= (int) $weekdayValue ?>"<?= in_array((int) $weekdayValue, $formWeekDays, true) ? ' checked' : '' ?>>
                                <label><?= cvAccessoH($weekdayLabel) ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="form-group">
                    <label>Valida da</label>
                    <input type="datetime-local" name="valid_from" class="form-control" value="<?= cvAccessoH((string) ($form['valid_from'] ?? '')) ?>">
                </div>

                <div class="form-group">
                    <label>Valida fino a</label>
                    <input type="datetime-local" name="valid_to" class="form-control" value="<?= cvAccessoH((string) ($form['valid_to'] ?? '')) ?>">
                </div>

                <div class="form-group">
                    <label>Priorità (più basso = prima scelta)</label>
                    <input type="number" name="priority" min="1" max="9999" class="form-control" value="<?= (int) ($form['priority'] ?? 100) ?>">
                </div>

                <div class="form-group">
                    <label>Note</label>
                    <input type="text" name="notes" maxlength="255" class="form-control" value="<?= cvAccessoH((string) ($form['notes'] ?? '')) ?>">
                </div>

                <div class="checkbox">
                    <input id="promo_active" type="checkbox" name="is_active" value="1"<?= !empty($form['is_active']) ? ' checked' : '' ?>>
                    <label for="promo_active">Promozione attiva</label>
                </div>

                <div class="cv-inline-actions" style="margin-top:12px;">
                    <button type="submit" class="btn btn-primary">Salva promozione</button>
                    <a class="btn btn-default" href="<?= cvAccessoH(cvAccessoUrl('promozioni.php')) ?>">Nuova</a>
                </div>
            </form>
        </div>
    </div>

    <div class="col-md-7">
        <div class="cv-panel-card">
            <h4>Lista promozioni</h4>
            <div class="table-responsive">
                <table class="table table-striped table-bordered">
                    <thead>
                    <tr>
                        <th>Nome</th>
                        <th>Tipo</th>
                        <th>Codice</th>
                        <th>Sconto</th>
                        <th>Provider</th>
                        <th>Validità</th>
                        <th>Stato</th>
                        <th>Azioni</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (count($rows) === 0): ?>
                        <tr><td colspan="8" class="text-center cv-muted">Nessuna promozione disponibile.</td></tr>
                    <?php else: ?>
                        <?php foreach ($rows as $row): ?>
                            <?php
                            $idPromo = isset($row['id_promo']) ? (int) $row['id_promo'] : 0;
                            $providerCodes = cvPromotionsNormalizeProviderCodes((string) ($row['provider_codes'] ?? ''));
                            $providerLabel = count($providerCodes) === 0 ? 'Tutti' : implode(', ', $providerCodes);
                            $validFrom = trim((string) ($row['valid_from'] ?? ''));
                            $validTo = trim((string) ($row['valid_to'] ?? ''));
                            $validity = ($validFrom !== '' ? date('d/m/Y H:i', strtotime($validFrom)) : '-') . ' → ' . ($validTo !== '' ? date('d/m/Y H:i', strtotime($validTo)) : '-');
                            $isActive = !empty($row['is_active']);
                            ?>
                            <tr>
                                <td><?= cvAccessoH((string) ($row['name'] ?? '')) ?></td>
                                <td><?= cvAccessoH((string) ($row['mode'] ?? 'code')) ?> / <?= cvAccessoH((string) ($row['visibility'] ?? 'hidden')) ?></td>
                                <td><?= cvAccessoH((string) ($row['code'] ?? '-')) ?></td>
                                <td><?= number_format((float) ($row['discount_percent'] ?? 0), 2, ',', '.') ?>%</td>
                                <td><?= cvAccessoH($providerLabel) ?></td>
                                <td><?= cvAccessoH($validity) ?></td>
                                <td><?= $isActive ? 'Attiva' : 'Disattiva' ?></td>
                                <td>
                                    <a class="btn btn-default btn-xs" href="<?= cvAccessoH(cvAccessoUrl('promozioni.php?edit=' . $idPromo)) ?>">Modifica</a>
                                    <form method="post" style="display:inline-block;">
                                        <?= cvAccessoCsrfField() ?>
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="id_promo" value="<?= $idPromo ?>">
                                        <input type="hidden" name="next" value="<?= $isActive ? '0' : '1' ?>">
                                        <button type="submit" class="btn btn-default btn-xs"><?= $isActive ? 'Disattiva' : 'Attiva' ?></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php
cvAccessoRenderPageEnd();
