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
$lines = [];

try {
    $connection = cvAccessoRequireConnection();
    $ctx = cvAccessoIntegrationResolveProvider($connection, $state);
    $provider = $ctx['provider'];
    $providers = $ctx['providers'];

    $idProvider = (int) ($provider['id_provider'] ?? 0);
    $maxLines = max(0, (int) ($provider['manual_max_lines'] ?? 0));

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!cvAccessoValidateCsrf()) {
            $state['errors'][] = 'Sessione non valida. Ricarica la pagina.';
        } else {
            $action = trim((string) ($_POST['action'] ?? ''));

            if ($action === 'add_line') {
                $name = trim((string) ($_POST['line_name'] ?? ''));
                $color = trim((string) ($_POST['line_color'] ?? ''));
                $isVisible = !empty($_POST['line_is_visible']) ? 1 : 0;

                if ($name === '') {
                    $state['errors'][] = 'Nome linea obbligatorio.';
                } else {
                    if ($maxLines > 0) {
                        $countRes = $connection->query("SELECT COUNT(*) AS cnt FROM cv_provider_lines WHERE id_provider = " . (int) $idProvider . " AND is_active = 1");
                        $cnt = 0;
                        if ($countRes instanceof mysqli_result && ($row = $countRes->fetch_assoc())) {
                            $cnt = (int) ($row['cnt'] ?? 0);
                        }
                        if ($countRes instanceof mysqli_result) {
                            $countRes->free();
                        }
                        if ($cnt >= $maxLines) {
                            $state['errors'][] = 'Limite linee raggiunto per questo provider.';
                            $name = '';
                        }
                    }
                }

                if ($name !== '') {
                    $externalId = cvAccessoIntegrationGenerateExternalId('line');
                    $rawJson = json_encode(['source' => 'manual'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $stmt = $connection->prepare(
                        "INSERT INTO cv_provider_lines (id_provider, external_id, name, color, is_active, is_visible, raw_json)
                         VALUES (?, ?, ?, NULLIF(?, ''), 1, ?, ?)"
                    );
                    if (!$stmt instanceof mysqli_stmt) {
                        $state['errors'][] = 'Prepare insert linea fallita.';
                    } else {
                        $stmt->bind_param('isssis', $idProvider, $externalId, $name, $color, $isVisible, $rawJson);
                        if (!$stmt->execute()) {
                            $state['errors'][] = 'Inserimento linea fallito: ' . $stmt->error;
                        } else {
                            $state['messages'][] = 'Linea aggiunta.';
                        }
                        $stmt->close();
                    }
                }
            } elseif ($action === 'update_line') {
                $externalId = trim((string) ($_POST['line_external_id'] ?? ''));
                $name = trim((string) ($_POST['line_name'] ?? ''));
                $color = trim((string) ($_POST['line_color'] ?? ''));
                $isActive = ((int) ($_POST['line_is_active'] ?? 1)) > 0 ? 1 : 0;
                $isVisible = !empty($_POST['line_is_visible']) ? 1 : 0;

                if ($externalId === '' || $name === '') {
                    $state['errors'][] = 'Dati linea non validi.';
                } else {
                    $stmt = $connection->prepare(
                        "UPDATE cv_provider_lines
                         SET name = ?, color = NULLIF(?, ''), is_active = ?, is_visible = ?
                         WHERE id_provider = ? AND external_id = ?
                         LIMIT 1"
                    );
                    if (!$stmt instanceof mysqli_stmt) {
                        $state['errors'][] = 'Prepare update linea fallita.';
                    } else {
                        $stmt->bind_param('ssiiis', $name, $color, $isActive, $isVisible, $idProvider, $externalId);
                        if (!$stmt->execute()) {
                            $state['errors'][] = 'Update linea fallito: ' . $stmt->error;
                        } else {
                            $state['messages'][] = 'Linea aggiornata.';
                        }
                        $stmt->close();
                    }
                }
            } elseif ($action === 'delete_line') {
                $externalId = trim((string) ($_POST['line_external_id'] ?? ''));
                if ($externalId === '') {
                    $state['errors'][] = 'Linea non valida.';
                } else {
                    $stmt = $connection->prepare(
                        "UPDATE cv_provider_lines
                         SET is_active = 0, is_visible = 0
                         WHERE id_provider = ? AND external_id = ?
                         LIMIT 1"
                    );
                    if ($stmt instanceof mysqli_stmt) {
                        $stmt->bind_param('is', $idProvider, $externalId);
                        if (!$stmt->execute()) {
                            $state['errors'][] = 'Disattivazione linea fallita: ' . $stmt->error;
                        } else {
                            $state['messages'][] = 'Linea disattivata.';
                        }
                        $stmt->close();
                    }
                }
            }
        }
    }

    $stmt = $connection->prepare(
        "SELECT external_id, name, color, is_active, is_visible
         FROM cv_provider_lines
         WHERE id_provider = ?
         ORDER BY name ASC"
    );
    if ($stmt instanceof mysqli_stmt) {
        $stmt->bind_param('i', $idProvider);
        if ($stmt->execute()) {
            $res = $stmt->get_result();
            if ($res instanceof mysqli_result) {
                while ($row = $res->fetch_assoc()) {
                    if (is_array($row)) {
                        $lines[] = $row;
                    }
                }
                $res->free();
            }
        }
        $stmt->close();
    }
} catch (Throwable $e) {
    $state['errors'][] = $e->getMessage();
}

cvAccessoRenderPageStart('Integrazione - Linee', 'integration-lines', $state);
?>
<div class="row">
    <div class="col-md-12">
        <p class="cv-page-intro">
            Gestione linee per provider <strong><?= cvAccessoH((string) ($provider['name'] ?? '')) ?></strong>
            (<?= cvAccessoH((string) ($provider['code'] ?? '')) ?>).
        </p>
        <?php cvAccessoIntegrationRenderProviderSelect($providers, (string) ($provider['code'] ?? '')); ?>
        <?php cvAccessoRenderMessages($state); ?>
    </div>
</div>

<div class="row">
    <div class="col-md-5">
        <div class="cv-panel-card">
            <h4>Aggiungi linea</h4>
            <form method="post">
                <input type="hidden" name="action" value="add_line">
                <?= cvAccessoCsrfField() ?>
                <div class="form-group">
                    <label>Nome</label>
                    <input type="text" class="form-control" name="line_name" required>
                </div>
                <div class="form-group">
                    <label>Colore (opzionale)</label>
                    <input type="text" class="form-control" name="line_color" placeholder="#0f76c6">
                </div>
                <div class="checkbox" style="margin-top:6px;">
                    <input id="lineVisibleNew" type="checkbox" name="line_is_visible" value="1" checked>
                    <label for="lineVisibleNew">Visibile</label>
                </div>
                <button type="submit" class="btn btn-primary" style="margin-top:8px;">Aggiungi</button>
                <div class="cv-muted" style="margin-top:10px;">
                    Limite linee: <?= (int) ($provider['manual_max_lines'] ?? 0) ?> (0 = illimitato)
                </div>
            </form>
        </div>
    </div>
    <div class="col-md-7">
        <div class="cv-panel-card">
            <h4>Linee</h4>
            <?php if (count($lines) === 0): ?>
                <div class="cv-empty">Nessuna linea ancora.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Nome</th>
                                <th style="width:140px;">Colore</th>
                                <th style="width:110px;">Stato</th>
                                <th style="width:120px;">Visibile</th>
                                <th style="width:240px;">Azioni</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($lines as $line): ?>
                                <?php
                                $ext = trim((string) ($line['external_id'] ?? ''));
                                $isActive = (int) ($line['is_active'] ?? 0) === 1;
                                $isVisible = (int) ($line['is_visible'] ?? 0) === 1;
                                ?>
                                <tr>
                                    <td>
                                        <form method="post" style="margin:0;">
                                            <input type="hidden" name="action" value="update_line">
                                            <input type="hidden" name="line_external_id" value="<?= cvAccessoH($ext) ?>">
                                            <?= cvAccessoCsrfField() ?>
                                            <input type="text" class="form-control" name="line_name" value="<?= cvAccessoH((string) ($line['name'] ?? '')) ?>" required>
                                    </td>
                                    <td><input type="text" class="form-control" name="line_color" value="<?= cvAccessoH((string) ($line['color'] ?? '')) ?>"></td>
                                    <td>
                                        <select class="form-control" name="line_is_active">
                                            <option value="1"<?= $isActive ? ' selected' : '' ?>>Attiva</option>
                                            <option value="0"<?= !$isActive ? ' selected' : '' ?>>Off</option>
                                        </select>
                                    </td>
                                    <td>
                                        <div class="checkbox" style="margin:0;">
                                            <input id="lineVisible_<?= cvAccessoH($ext) ?>" type="checkbox" name="line_is_visible" value="1"<?= $isVisible ? ' checked' : '' ?>>
                                            <label for="lineVisible_<?= cvAccessoH($ext) ?>"></label>
                                        </div>
                                    </td>
                                    <td>
                                        <button type="submit" class="btn btn-default btn-sm">Salva</button>
                                        </form>
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="action" value="delete_line">
                                            <input type="hidden" name="line_external_id" value="<?= cvAccessoH($ext) ?>">
                                            <?= cvAccessoCsrfField() ?>
                                            <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Disattivare questa linea?')">Disattiva</button>
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
