<?php
declare(strict_types=1);

require_once __DIR__ . '/integrazione_common.php';

$state = cvAccessoInit();
if (!$state['authenticated']) {
    cvAccessoRenderLoginPage($state);
    return;
}

$provider = [];
$providers = [];
$stops = [];
$fares = [];
$editFare = null;
$addExistingFare = null;

try {
    $connection = cvAccessoRequireConnection();
    $ctx = cvAccessoIntegrationResolveProvider($connection, $state);
    $provider = $ctx['provider'];
    $providers = $ctx['providers'];
    $idProvider = (int) ($provider['id_provider'] ?? 0);

    $stopRes = $connection->query(
        "SELECT external_id, name
         FROM cv_provider_stops
         WHERE id_provider = " . (int) $idProvider . " AND is_active = 1
         ORDER BY name ASC"
    );
    if ($stopRes instanceof mysqli_result) {
        while ($row = $stopRes->fetch_assoc()) {
            if (!is_array($row)) {
                continue;
            }
            $ext = trim((string) ($row['external_id'] ?? ''));
            if ($ext === '') {
                continue;
            }
            $stops[] = $row;
        }
        $stopRes->free();
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!cvAccessoValidateCsrf()) {
            $state['errors'][] = 'Sessione non valida. Ricarica la pagina.';
        } else {
            $action = trim((string) ($_POST['action'] ?? ''));

            if ($action === 'add_fare') {
                $fromId = trim((string) ($_POST['fare_from'] ?? ''));
                $toId = trim((string) ($_POST['fare_to'] ?? ''));
                $amountRaw = trim((string) ($_POST['fare_amount'] ?? ''));
                $currency = strtoupper(trim((string) ($_POST['fare_currency'] ?? 'EUR')));
                if ($currency === '') {
                    $currency = 'EUR';
                }

                if ($fromId === '' || $toId === '' || $fromId === $toId) {
                    $state['errors'][] = 'Seleziona due fermate diverse.';
                } elseif ($amountRaw === '' || !is_numeric($amountRaw) || (float) $amountRaw <= 0) {
                    $state['errors'][] = 'Importo non valido.';
                } else {
                    $existingStmt = $connection->prepare(
                        "SELECT external_id, from_stop_external_id, to_stop_external_id, amount, currency
                         FROM cv_provider_fares
                         WHERE id_provider = ?
                           AND is_active = 1
                           AND (
                                (from_stop_external_id = ? AND to_stop_external_id = ?)
                             OR (from_stop_external_id = ? AND to_stop_external_id = ?)
                           )
                         LIMIT 1"
                    );
                    if ($existingStmt instanceof mysqli_stmt) {
                        $existingStmt->bind_param('issss', $idProvider, $fromId, $toId, $toId, $fromId);
                        if ($existingStmt->execute()) {
                            $res = $existingStmt->get_result();
                            if ($res instanceof mysqli_result) {
                                $row = $res->fetch_assoc();
                                if (is_array($row)) {
                                    $addExistingFare = $row;
                                }
                                $res->free();
                            }
                        }
                        $existingStmt->close();
                    }

                    if (is_array($addExistingFare)) {
                        $state['errors'][] = 'Tariffa già presente per questa tratta (anche inversa). Usa “Modifica” per aggiornarla.';
                    } else {
                        $externalId = cvAccessoIntegrationGenerateExternalId('fare');
                        $amount = round((float) $amountRaw, 2);
                        $rawJson = json_encode(['source' => 'manual'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        $stmt = $connection->prepare(
                            "INSERT INTO cv_provider_fares (id_provider, external_id, from_stop_external_id, to_stop_external_id, amount, currency, is_active, raw_json)
                             VALUES (?, ?, ?, ?, ?, ?, 1, ?)"
                        );
                        if (!$stmt instanceof mysqli_stmt) {
                            $state['errors'][] = 'Prepare insert tariffa fallita.';
                        } else {
                            $stmt->bind_param('isssdss', $idProvider, $externalId, $fromId, $toId, $amount, $currency, $rawJson);
                            if (!$stmt->execute()) {
                                $state['errors'][] = 'Inserimento tariffa fallito: ' . $stmt->error;
                            } else {
                                $state['messages'][] = 'Tariffa aggiunta.';
                            }
                            $stmt->close();
                        }
                    }
                }
            } elseif ($action === 'update_fare') {
                $externalId = trim((string) ($_POST['fare_external_id'] ?? ''));
                $fromId = trim((string) ($_POST['fare_from'] ?? ''));
                $toId = trim((string) ($_POST['fare_to'] ?? ''));
                $amountRaw = trim((string) ($_POST['fare_amount'] ?? ''));
                $currency = strtoupper(trim((string) ($_POST['fare_currency'] ?? 'EUR')));
                $isActive = ((int) ($_POST['fare_is_active'] ?? 1)) > 0 ? 1 : 0;

                if ($currency === '') {
                    $currency = 'EUR';
                }
                if ($externalId === '' || $fromId === '' || $toId === '' || $fromId === $toId) {
                    $state['errors'][] = 'Dati tariffa non validi.';
                } elseif ($amountRaw === '' || !is_numeric($amountRaw) || (float) $amountRaw <= 0) {
                    $state['errors'][] = 'Importo non valido.';
                } else {
                    $dupStmt = $connection->prepare(
                        "SELECT external_id
                         FROM cv_provider_fares
                         WHERE id_provider = ?
                           AND is_active = 1
                           AND external_id <> ?
                           AND (
                                (from_stop_external_id = ? AND to_stop_external_id = ?)
                             OR (from_stop_external_id = ? AND to_stop_external_id = ?)
                           )
                         LIMIT 1"
                    );
                    $dup = null;
                    if ($dupStmt instanceof mysqli_stmt) {
                        $dupStmt->bind_param('isssss', $idProvider, $externalId, $fromId, $toId, $toId, $fromId);
                        if ($dupStmt->execute()) {
                            $res = $dupStmt->get_result();
                            if ($res instanceof mysqli_result) {
                                $dup = $res->fetch_assoc();
                                $res->free();
                            }
                        }
                        $dupStmt->close();
                    }

                    if (is_array($dup)) {
                        $state['errors'][] = 'Esiste già una tariffa attiva per questa tratta (anche inversa).';
                    } else {
                        $amount = round((float) $amountRaw, 2);
                        $stmt = $connection->prepare(
                            "UPDATE cv_provider_fares
                             SET from_stop_external_id = ?, to_stop_external_id = ?, amount = ?, currency = ?, is_active = ?
                             WHERE id_provider = ? AND external_id = ?
                             LIMIT 1"
                        );
                        if (!$stmt instanceof mysqli_stmt) {
                            $state['errors'][] = 'Prepare update tariffa fallita.';
                        } else {
                            $stmt->bind_param('ssdsiis', $fromId, $toId, $amount, $currency, $isActive, $idProvider, $externalId);
                            if (!$stmt->execute()) {
                                $state['errors'][] = 'Update tariffa fallito: ' . $stmt->error;
                            } else {
                                $state['messages'][] = 'Tariffa aggiornata.';
                            }
                            $stmt->close();
                        }
                    }
                }
            } elseif ($action === 'delete_fare') {
                $externalId = trim((string) ($_POST['fare_external_id'] ?? ''));
                if ($externalId === '') {
                    $state['errors'][] = 'Tariffa non valida.';
                } else {
                    $stmt = $connection->prepare(
                        "UPDATE cv_provider_fares
                         SET is_active = 0
                         WHERE id_provider = ? AND external_id = ?
                         LIMIT 1"
                    );
                    if ($stmt instanceof mysqli_stmt) {
                        $stmt->bind_param('is', $idProvider, $externalId);
                        if (!$stmt->execute()) {
                            $state['errors'][] = 'Disattivazione tariffa fallita: ' . $stmt->error;
                        } else {
                            $state['messages'][] = 'Tariffa disattivata.';
                        }
                        $stmt->close();
                    }
                }
            }
        }
    }

    $editExternalId = trim((string) ($_GET['edit'] ?? ''));
    if ($editExternalId !== '') {
        $stmt = $connection->prepare(
            "SELECT external_id, from_stop_external_id, to_stop_external_id, amount, currency, is_active
             FROM cv_provider_fares
             WHERE id_provider = ? AND external_id = ?
             LIMIT 1"
        );
        if ($stmt instanceof mysqli_stmt) {
            $stmt->bind_param('is', $idProvider, $editExternalId);
            if ($stmt->execute()) {
                $res = $stmt->get_result();
                if ($res instanceof mysqli_result) {
                    $row = $res->fetch_assoc();
                    if (is_array($row)) {
                        $editFare = $row;
                    }
                    $res->free();
                }
            }
            $stmt->close();
        }
    }

    $fareSql = "SELECT f.external_id, f.from_stop_external_id, f.to_stop_external_id, f.amount, f.currency, f.is_active,
                       fs.name AS from_name,
                       ts.name AS to_name
                FROM cv_provider_fares f
                LEFT JOIN cv_provider_stops fs
                  ON fs.id_provider = f.id_provider AND fs.external_id = f.from_stop_external_id
                LEFT JOIN cv_provider_stops ts
                  ON ts.id_provider = f.id_provider AND ts.external_id = f.to_stop_external_id
                WHERE f.id_provider = " . (int) $idProvider . "
                ORDER BY f.is_active DESC, fs.name ASC, ts.name ASC";
    $fareRes = $connection->query($fareSql);
    if ($fareRes instanceof mysqli_result) {
        while ($row = $fareRes->fetch_assoc()) {
            if (is_array($row)) {
                $fares[] = $row;
            }
        }
        $fareRes->free();
    }
} catch (Throwable $e) {
    $state['errors'][] = $e->getMessage();
}

cvAccessoRenderPageStart('Integrazione - Tariffe', 'integration-fares', $state);
?>
<div class="row">
    <div class="col-md-12">
        <p class="cv-page-intro">
            Gestione tariffe per provider <strong><?= cvAccessoH((string) ($provider['name'] ?? '')) ?></strong>
            (<?= cvAccessoH((string) ($provider['code'] ?? '')) ?>).
        </p>
        <?php cvAccessoIntegrationRenderProviderSelect($providers, (string) ($provider['code'] ?? '')); ?>
        <?php cvAccessoRenderMessages($state); ?>
    </div>
</div>

<div class="row">
    <div class="col-md-5">
        <div class="cv-panel-card">
            <h4>Aggiungi tariffa</h4>
            <?php if (count($stops) < 2): ?>
                <div class="alert alert-warning">Aggiungi almeno 2 fermate prima di inserire tariffe.</div>
            <?php else: ?>
                <form method="post">
                    <input type="hidden" name="action" value="add_fare">
                    <?= cvAccessoCsrfField() ?>
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label>Da</label>
                            <select class="form-control" name="fare_from" id="fareFromSelect" required>
                                <?php foreach ($stops as $stop): ?>
                                    <option value="<?= cvAccessoH((string) ($stop['external_id'] ?? '')) ?>"><?= cvAccessoH((string) ($stop['name'] ?? '')) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>A</label>
                            <select class="form-control" name="fare_to" id="fareToSelect" required>
                                <?php foreach ($stops as $stop): ?>
                                    <option value="<?= cvAccessoH((string) ($stop['external_id'] ?? '')) ?>"><?= cvAccessoH((string) ($stop['name'] ?? '')) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label>Importo</label>
                            <input type="number" class="form-control" name="fare_amount" id="fareAmountInput" min="0.01" step="0.01" required>
                            <div class="cv-muted" id="fareAmountHint" style="margin-top:6px;"></div>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Valuta</label>
                            <input type="text" class="form-control" name="fare_currency" id="fareCurrencyInput" value="EUR" maxlength="8">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">Aggiungi</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-md-7">
        <div class="cv-panel-card">
            <h4>Tariffe</h4>
            <?php if (is_array($editFare)): ?>
                <div class="alert alert-info">
                    Modifica tariffa: <code><?= cvAccessoH((string) ($editFare['external_id'] ?? '')) ?></code>
                </div>
                <form method="post" style="margin-bottom:12px;">
                    <input type="hidden" name="action" value="update_fare">
                    <input type="hidden" name="fare_external_id" value="<?= cvAccessoH((string) ($editFare['external_id'] ?? '')) ?>">
                    <?= cvAccessoCsrfField() ?>
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label>Da</label>
                            <select class="form-control" name="fare_from" required>
                                <?php foreach ($stops as $stop): ?>
                                    <?php $sid = (string) ($stop['external_id'] ?? ''); ?>
                                    <option value="<?= cvAccessoH($sid) ?>"<?= $sid === (string) ($editFare['from_stop_external_id'] ?? '') ? ' selected' : '' ?>>
                                        <?= cvAccessoH((string) ($stop['name'] ?? '')) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>A</label>
                            <select class="form-control" name="fare_to" required>
                                <?php foreach ($stops as $stop): ?>
                                    <?php $sid = (string) ($stop['external_id'] ?? ''); ?>
                                    <option value="<?= cvAccessoH($sid) ?>"<?= $sid === (string) ($editFare['to_stop_external_id'] ?? '') ? ' selected' : '' ?>>
                                        <?= cvAccessoH((string) ($stop['name'] ?? '')) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4 form-group">
                            <label>Importo</label>
                            <input type="number" class="form-control" name="fare_amount" min="0.01" step="0.01" value="<?= cvAccessoH((string) ($editFare['amount'] ?? '0.00')) ?>" required>
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Valuta</label>
                            <input type="text" class="form-control" name="fare_currency" value="<?= cvAccessoH((string) ($editFare['currency'] ?? 'EUR')) ?>" maxlength="8">
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Stato</label>
                            <?php $isActive = (int) ($editFare['is_active'] ?? 0) === 1; ?>
                            <select class="form-control" name="fare_is_active">
                                <option value="1"<?= $isActive ? ' selected' : '' ?>>Attiva</option>
                                <option value="0"<?= !$isActive ? ' selected' : '' ?>>Off</option>
                            </select>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-default btn-sm">Salva</button>
                    <a class="btn btn-link btn-sm" href="<?= cvAccessoH(cvAccessoUrl('integrazione_tariffe.php') . '?provider=' . urlencode((string) ($provider['code'] ?? ''))) ?>">Annulla</a>
                </form>
            <?php endif; ?>
            <?php if (count($fares) === 0): ?>
                <div class="cv-empty">Nessuna tariffa ancora.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Da</th>
                                <th>A</th>
                                <th style="width:120px;">Importo</th>
                                <th style="width:90px;">Valuta</th>
                                <th style="width:110px;">Stato</th>
                                <th style="width:240px;">Azioni</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($fares as $fare): ?>
                                <?php
                                $ext = trim((string) ($fare['external_id'] ?? ''));
                                $isActive = (int) ($fare['is_active'] ?? 0) === 1;
                                $editHref = cvAccessoUrl('integrazione_tariffe.php') . '?provider=' . urlencode((string) ($provider['code'] ?? '')) . '&edit=' . urlencode($ext);
                                ?>
                                <tr>
                                    <td><?= cvAccessoH((string) ($fare['from_name'] ?? $fare['from_stop_external_id'] ?? '')) ?></td>
                                    <td><?= cvAccessoH((string) ($fare['to_name'] ?? $fare['to_stop_external_id'] ?? '')) ?></td>
                                    <td><?= cvAccessoH(number_format((float) ($fare['amount'] ?? 0), 2, ',', '.')) ?></td>
                                    <td><?= cvAccessoH((string) ($fare['currency'] ?? 'EUR')) ?></td>
                                    <td><?= $isActive ? 'Attiva' : 'Off' ?></td>
                                    <td>
                                        <a class="btn btn-default btn-sm" href="<?= cvAccessoH($editHref) ?>">Modifica</a>
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="action" value="delete_fare">
                                            <input type="hidden" name="fare_external_id" value="<?= cvAccessoH($ext) ?>">
                                            <?= cvAccessoCsrfField() ?>
                                            <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Disattivare questa tariffa?')">Disattiva</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php cvAccessoRenderPageEnd(); ?>

<script>
(function () {
    'use strict';

    var fromEl = document.getElementById('fareFromSelect');
    var toEl = document.getElementById('fareToSelect');
    var amountEl = document.getElementById('fareAmountInput');
    var currencyEl = document.getElementById('fareCurrencyInput');
    var hintEl = document.getElementById('fareAmountHint');
    if (!fromEl || !toEl || !amountEl || !currencyEl || !hintEl) {
        return;
    }

    var csrf = <?= json_encode(cvAccessoCsrfToken(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var providerCode = <?= json_encode((string) ($provider['code'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var timer = null;

    function fetchExisting(fromId, toId) {
        var url = <?= json_encode(cvAccessoUrl('api_tariffe.php'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        url += '?provider=' + encodeURIComponent(providerCode);
        url += '&from=' + encodeURIComponent(fromId);
        url += '&to=' + encodeURIComponent(toId);
        url += '&csrf_token=' + encodeURIComponent(csrf);
        return fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.json().catch(function () { return null; }); })
            .then(function (body) {
                if (!body || body.success !== true) {
                    return null;
                }
                return body.fare || null;
            })
            .catch(function () { return null; });
    }

    function maybeSuggest() {
        hintEl.textContent = '';
        var fromId = String(fromEl.value || '');
        var toId = String(toEl.value || '');
        if (!fromId || !toId || fromId === toId) {
            return;
        }

        window.clearTimeout(timer);
        timer = window.setTimeout(function () {
            fetchExisting(fromId, toId).then(function (fare) {
                if (!fare) {
                    return;
                }
                var amount = String(fare.amount || '');
                var currency = String(fare.currency || 'EUR');
                if (amount) {
                    hintEl.textContent = 'Tariffa già presente: ' + amount + ' ' + currency + ' (valida anche al contrario).';
                    if (String(amountEl.value || '').trim() === '') {
                        amountEl.value = amount;
                    }
                    if (String(currencyEl.value || '').trim() === '') {
                        currencyEl.value = currency;
                    }
                }
            });
        }, 250);
    }

    fromEl.addEventListener('change', maybeSuggest);
    toEl.addEventListener('change', maybeSuggest);
    amountEl.addEventListener('input', function () { hintEl.textContent = ''; });
})();
</script>
