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
$stops = [];
$trips = [];
$currentTrip = null;
$currentTripStops = [];

try {
    $connection = cvAccessoRequireConnection();
    $ctx = cvAccessoIntegrationResolveProvider($connection, $state);
    $provider = $ctx['provider'];
    $providers = $ctx['providers'];
    $idProvider = (int) ($provider['id_provider'] ?? 0);
    $maxTrips = max(0, (int) ($provider['manual_max_trips'] ?? 0));

    $lineRes = $connection->query(
        "SELECT external_id, name
         FROM cv_provider_lines
         WHERE id_provider = " . (int) $idProvider . " AND is_active = 1
         ORDER BY name ASC"
    );
    if ($lineRes instanceof mysqli_result) {
        while ($row = $lineRes->fetch_assoc()) {
            if (is_array($row)) {
                $lines[] = $row;
            }
        }
        $lineRes->free();
    }

    $stopRes = $connection->query(
        "SELECT external_id, name
         FROM cv_provider_stops
         WHERE id_provider = " . (int) $idProvider . " AND is_active = 1
         ORDER BY name ASC"
    );
    if ($stopRes instanceof mysqli_result) {
        while ($row = $stopRes->fetch_assoc()) {
            if (is_array($row)) {
                $stops[] = $row;
            }
        }
        $stopRes->free();
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!cvAccessoValidateCsrf()) {
            $state['errors'][] = 'Sessione non valida. Ricarica la pagina.';
        } else {
            $action = trim((string) ($_POST['action'] ?? ''));

            if ($action === 'add_trip') {
                $name = trim((string) ($_POST['trip_name'] ?? ''));
                $lineId = trim((string) ($_POST['trip_line'] ?? ''));
                $tempoAcquisto = max(0, (int) ($_POST['trip_tempo_acquisto'] ?? 30));
                $isVisible = !empty($_POST['trip_is_visible']) ? 1 : 0;

                if ($lineId === '' || $name === '') {
                    $state['errors'][] = 'Linea e nome corsa sono obbligatori.';
                }

                if ($maxTrips > 0) {
                    $countRes = $connection->query("SELECT COUNT(*) AS cnt FROM cv_provider_trips WHERE id_provider = " . (int) $idProvider . " AND is_active = 1");
                    $cnt = 0;
                    if ($countRes instanceof mysqli_result && ($row = $countRes->fetch_assoc())) {
                        $cnt = (int) ($row['cnt'] ?? 0);
                    }
                    if ($countRes instanceof mysqli_result) {
                        $countRes->free();
                    }
                    if ($cnt >= $maxTrips) {
                        $state['errors'][] = 'Limite corse raggiunto per questo provider.';
                        $name = '';
                    }
                }

                $externalId = '';
                if ($name !== '' && $lineId !== '') {
                    $externalId = cvAccessoIntegrationGenerateExternalId('trip');
                    $rawJson = json_encode(['source' => 'manual'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $stmt = $connection->prepare(
                        "INSERT INTO cv_provider_trips (id_provider, external_id, line_external_id, name, tempo_acquisto, direction_id, is_active, is_visible, raw_json)
                         VALUES (?, ?, ?, ?, ?, 0, 1, ?, ?)"
                    );
                    if (!$stmt instanceof mysqli_stmt) {
                        $state['errors'][] = 'Prepare insert corsa fallita.';
                    } else {
                        $stmt->bind_param('isssiis', $idProvider, $externalId, $lineId, $name, $tempoAcquisto, $isVisible, $rawJson);
                        if (!$stmt->execute()) {
                            $state['errors'][] = 'Inserimento corsa fallito: ' . $stmt->error;
                        } else {
                            $state['messages'][] = 'Corsa aggiunta.';
                        }
                        $stmt->close();
                    }
                }
            } elseif ($action === 'update_trip') {
                $externalId = trim((string) ($_POST['trip_external_id'] ?? ''));
                $name = trim((string) ($_POST['trip_name'] ?? ''));
                $lineId = trim((string) ($_POST['trip_line'] ?? ''));
                $tempoAcquisto = max(0, (int) ($_POST['trip_tempo_acquisto'] ?? 30));
                $isActive = ((int) ($_POST['trip_is_active'] ?? 1)) > 0 ? 1 : 0;
                $isVisible = !empty($_POST['trip_is_visible']) ? 1 : 0;

                if ($externalId === '' || $lineId === '' || $name === '') {
                    $state['errors'][] = 'Dati corsa non validi (linea e nome obbligatori).';
                } else {
                    $stmt = $connection->prepare(
                        "UPDATE cv_provider_trips
                         SET line_external_id = ?, name = ?, tempo_acquisto = ?, direction_id = 0, is_active = ?, is_visible = ?
                         WHERE id_provider = ? AND external_id = ?
                         LIMIT 1"
                    );
                    if (!$stmt instanceof mysqli_stmt) {
                        $state['errors'][] = 'Prepare update corsa fallita.';
                    } else {
                        $stmt->bind_param('ssiiiis', $lineId, $name, $tempoAcquisto, $isActive, $isVisible, $idProvider, $externalId);
                        if (!$stmt->execute()) {
                            $state['errors'][] = 'Update corsa fallito: ' . $stmt->error;
                        } else {
                            $state['messages'][] = 'Corsa aggiornata.';
                        }
                        $stmt->close();
                    }
                }
            } elseif ($action === 'delete_trip') {
                $externalId = trim((string) ($_POST['trip_external_id'] ?? ''));
                if ($externalId === '') {
                    $state['errors'][] = 'Corsa non valida.';
                } else {
                    $stmt = $connection->prepare(
                        "UPDATE cv_provider_trips
                         SET is_active = 0, is_visible = 0
                         WHERE id_provider = ? AND external_id = ?
                         LIMIT 1"
                    );
                    if ($stmt instanceof mysqli_stmt) {
                        $stmt->bind_param('is', $idProvider, $externalId);
                        if (!$stmt->execute()) {
                            $state['errors'][] = 'Disattivazione corsa fallita: ' . $stmt->error;
                        } else {
                            $state['messages'][] = 'Corsa disattivata.';
                        }
                        $stmt->close();
                    }
                }
            } elseif ($action === 'add_trip_stop') {
                $tripExternalId = trim((string) ($_POST['trip_external_id'] ?? ''));
                $stopExternalId = trim((string) ($_POST['stop_external_id'] ?? ''));
                $timeLocal = trim((string) ($_POST['time_local'] ?? ''));
                $dayOffset = (int) ($_POST['day_offset'] ?? 0);

                if ($tripExternalId === '' || $stopExternalId === '') {
                    $state['errors'][] = 'Seleziona corsa e fermata.';
                } else {
                    if ($timeLocal === '') {
                        $state['errors'][] = 'Orario obbligatorio per la fermata.';
                    } elseif (!preg_match('/^\\d{2}:\\d{2}(:\\d{2})?$/', $timeLocal)) {
                        $state['errors'][] = 'Orario non valido (HH:MM).';
                    } else {
                        $seq = 1;
                        $seqRes = $connection->query(
                            "SELECT COALESCE(MAX(sequence_no), 0) + 1 AS next_seq
                             FROM cv_provider_trip_stops
                             WHERE id_provider = " . (int) $idProvider . "
                               AND trip_external_id = '" . $connection->real_escape_string($tripExternalId) . "'"
                        );
                        if ($seqRes instanceof mysqli_result && ($row = $seqRes->fetch_assoc())) {
                            $seq = max(1, (int) ($row['next_seq'] ?? 1));
                        }
                        if ($seqRes instanceof mysqli_result) {
                            $seqRes->free();
                        }

                        $rawJson = json_encode(['source' => 'manual'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        $stmt = $connection->prepare(
                            "INSERT INTO cv_provider_trip_stops (id_provider, trip_external_id, sequence_no, stop_external_id, time_local, day_offset, is_active, raw_json)
                             VALUES (?, ?, ?, ?, NULLIF(?, ''), ?, 1, ?)"
                        );
                        if (!$stmt instanceof mysqli_stmt) {
                            $state['errors'][] = 'Prepare insert fermata corsa fallita.';
                        } else {
                            $stmt->bind_param('isissis', $idProvider, $tripExternalId, $seq, $stopExternalId, $timeLocal, $dayOffset, $rawJson);
                            if (!$stmt->execute()) {
                                $state['errors'][] = 'Inserimento fermata corsa fallito: ' . $stmt->error;
                            } else {
                                $state['messages'][] = 'Fermata aggiunta alla corsa.';
                            }
                            $stmt->close();
                        }
                    }
                }
            } elseif ($action === 'delete_trip_stop') {
                $tripExternalId = trim((string) ($_POST['trip_external_id'] ?? ''));
                $seq = (int) ($_POST['sequence_no'] ?? 0);
                if ($tripExternalId === '' || $seq <= 0) {
                    $state['errors'][] = 'Fermata corsa non valida.';
                } else {
                    $stmt = $connection->prepare(
                        "UPDATE cv_provider_trip_stops
                         SET is_active = 0
                         WHERE id_provider = ? AND trip_external_id = ? AND sequence_no = ?
                         LIMIT 1"
                    );
                    if ($stmt instanceof mysqli_stmt) {
                        $stmt->bind_param('isi', $idProvider, $tripExternalId, $seq);
                        if (!$stmt->execute()) {
                            $state['errors'][] = 'Disattivazione fermata corsa fallita: ' . $stmt->error;
                        } else {
                            $state['messages'][] = 'Fermata corsa disattivata.';
                        }
                        $stmt->close();
                    }
                }
            } elseif ($action === 'update_trip_stop') {
                $tripExternalId = trim((string) ($_POST['trip_external_id'] ?? ''));
                $seq = (int) ($_POST['sequence_no'] ?? 0);
                $stopExternalId = trim((string) ($_POST['stop_external_id'] ?? ''));
                $timeLocal = trim((string) ($_POST['time_local'] ?? ''));
                $dayOffset = (int) ($_POST['day_offset'] ?? 0);

                if ($tripExternalId === '' || $seq <= 0 || $stopExternalId === '') {
                    $state['errors'][] = 'Dati fermata corsa non validi.';
                } else {
                    if ($timeLocal === '') {
                        $state['errors'][] = 'Orario obbligatorio per la fermata.';
                    } elseif (!preg_match('/^\\d{2}:\\d{2}(:\\d{2})?$/', $timeLocal)) {
                        $state['errors'][] = 'Orario non valido (HH:MM).';
                    } else {
                        $stmt = $connection->prepare(
                            "UPDATE cv_provider_trip_stops
                             SET stop_external_id = ?, time_local = NULLIF(?, ''), day_offset = ?
                             WHERE id_provider = ? AND trip_external_id = ? AND sequence_no = ?
                             LIMIT 1"
                        );
                        if (!$stmt instanceof mysqli_stmt) {
                            $state['errors'][] = 'Prepare update fermata corsa fallita.';
                        } else {
                            $stmt->bind_param('ssiisi', $stopExternalId, $timeLocal, $dayOffset, $idProvider, $tripExternalId, $seq);
                            if (!$stmt->execute()) {
                                $state['errors'][] = 'Update fermata corsa fallito: ' . $stmt->error;
                            } else {
                                $state['messages'][] = 'Fermata corsa aggiornata.';
                            }
                            $stmt->close();
                        }
                    }
                }
            } elseif ($action === 'move_trip_stop') {
                $tripExternalId = trim((string) ($_POST['trip_external_id'] ?? ''));
                $seq = (int) ($_POST['sequence_no'] ?? 0);
                $direction = trim((string) ($_POST['direction'] ?? ''));
                if ($tripExternalId === '' || $seq <= 0 || ($direction !== 'up' && $direction !== 'down')) {
                    $state['errors'][] = 'Spostamento non valido.';
                } else {
                    $cmp = $direction === 'up' ? '<' : '>';
                    $order = $direction === 'up' ? 'DESC' : 'ASC';
                    $stmt = $connection->prepare(
                        "SELECT id, sequence_no
                         FROM cv_provider_trip_stops
                         WHERE id_provider = ? AND trip_external_id = ? AND is_active = 1 AND sequence_no {$cmp} ?
                         ORDER BY sequence_no {$order}
                         LIMIT 1"
                    );
                    $neighbor = null;
                    if ($stmt instanceof mysqli_stmt) {
                        $stmt->bind_param('isi', $idProvider, $tripExternalId, $seq);
                        if ($stmt->execute()) {
                            $res = $stmt->get_result();
                            if ($res instanceof mysqli_result) {
                                $neighbor = $res->fetch_assoc();
                                $res->free();
                            }
                        }
                        $stmt->close();
                    }

                    if (!is_array($neighbor)) {
                        // already at boundary
                    } else {
                        $neighborSeq = (int) ($neighbor['sequence_no'] ?? 0);
                        if ($neighborSeq <= 0) {
                            // no-op
                        } else {
                            $connection->begin_transaction();
                            try {
                                $maxRes = $connection->query(
                                    "SELECT COALESCE(MAX(sequence_no), 0) AS mx
                                     FROM cv_provider_trip_stops
                                     WHERE id_provider = " . (int) $idProvider . "
                                       AND trip_external_id = '" . $connection->real_escape_string($tripExternalId) . "'"
                                );
                                $mx = 0;
                                if ($maxRes instanceof mysqli_result && ($row = $maxRes->fetch_assoc())) {
                                    $mx = (int) ($row['mx'] ?? 0);
                                }
                                if ($maxRes instanceof mysqli_result) {
                                    $maxRes->free();
                                }
                                $temp = $mx + 1000;

                                $s1 = $connection->prepare(
                                    "UPDATE cv_provider_trip_stops
                                     SET sequence_no = ?
                                     WHERE id_provider = ? AND trip_external_id = ? AND sequence_no = ?
                                     LIMIT 1"
                                );
                                $s2 = $connection->prepare(
                                    "UPDATE cv_provider_trip_stops
                                     SET sequence_no = ?
                                     WHERE id_provider = ? AND trip_external_id = ? AND sequence_no = ?
                                     LIMIT 1"
                                );
                                $s3 = $connection->prepare(
                                    "UPDATE cv_provider_trip_stops
                                     SET sequence_no = ?
                                     WHERE id_provider = ? AND trip_external_id = ? AND sequence_no = ?
                                     LIMIT 1"
                                );
                                if (!$s1 instanceof mysqli_stmt || !$s2 instanceof mysqli_stmt || !$s3 instanceof mysqli_stmt) {
                                    throw new RuntimeException('Prepare swap fallita.');
                                }

                                $s1->bind_param('iisi', $temp, $idProvider, $tripExternalId, $seq);
                                if (!$s1->execute()) {
                                    throw new RuntimeException('Swap step 1 fallito: ' . $s1->error);
                                }
                                $s1->close();

                                $s2->bind_param('iisi', $seq, $idProvider, $tripExternalId, $neighborSeq);
                                if (!$s2->execute()) {
                                    throw new RuntimeException('Swap step 2 fallito: ' . $s2->error);
                                }
                                $s2->close();

                                $s3->bind_param('iisi', $neighborSeq, $idProvider, $tripExternalId, $temp);
                                if (!$s3->execute()) {
                                    throw new RuntimeException('Swap step 3 fallito: ' . $s3->error);
                                }
                                $s3->close();

                                $connection->commit();
                                $state['messages'][] = 'Ordine fermate aggiornato.';
                            } catch (Throwable $e) {
                                $connection->rollback();
                                $state['errors'][] = 'Spostamento fallito: ' . $e->getMessage();
                            }
                        }
                    }
                }
            }
        }
    }

    $tripSql = "SELECT t.external_id, t.name, t.line_external_id, t.tempo_acquisto, t.is_active, t.is_visible,
                       COALESCE(NULLIF(l.name, ''), '-') AS line_name
                FROM cv_provider_trips t
                LEFT JOIN cv_provider_lines l
                  ON l.id_provider = t.id_provider AND l.external_id = t.line_external_id
                WHERE t.id_provider = " . (int) $idProvider . "
                ORDER BY t.is_active DESC, line_name ASC, t.external_id ASC";
    $tripRes = $connection->query($tripSql);
    if ($tripRes instanceof mysqli_result) {
        while ($row = $tripRes->fetch_assoc()) {
            if (is_array($row)) {
                $trips[] = $row;
            }
        }
        $tripRes->free();
    }

    $currentTripId = trim((string) ($_GET['trip'] ?? ''));
    if ($currentTripId !== '') {
        $stmt = $connection->prepare(
            "SELECT external_id, name, line_external_id, tempo_acquisto, is_active, is_visible
             FROM cv_provider_trips
             WHERE id_provider = ? AND external_id = ?
             LIMIT 1"
        );
        if ($stmt instanceof mysqli_stmt) {
            $stmt->bind_param('is', $idProvider, $currentTripId);
            if ($stmt->execute()) {
                $res = $stmt->get_result();
                if ($res instanceof mysqli_result) {
                    $row = $res->fetch_assoc();
                    if (is_array($row)) {
                        $currentTrip = $row;
                    }
                    $res->free();
                }
            }
            $stmt->close();
        }

        if (is_array($currentTrip)) {
            $stopSql = "SELECT ts.sequence_no, ts.stop_external_id, ts.time_local, ts.day_offset, ts.is_active,
                               s.name AS stop_name
                        FROM cv_provider_trip_stops ts
                        LEFT JOIN cv_provider_stops s
                          ON s.id_provider = ts.id_provider AND s.external_id = ts.stop_external_id
                        WHERE ts.id_provider = " . (int) $idProvider . "
                          AND ts.trip_external_id = '" . $connection->real_escape_string($currentTripId) . "'
                        ORDER BY ts.sequence_no ASC";
            $res = $connection->query($stopSql);
            if ($res instanceof mysqli_result) {
                while ($row = $res->fetch_assoc()) {
                    if (is_array($row)) {
                        $currentTripStops[] = $row;
                    }
                }
                $res->free();
            }
        }
    }
} catch (Throwable $e) {
    $state['errors'][] = $e->getMessage();
}

cvAccessoRenderPageStart('Integrazione - Corse', 'integration-trips', $state);
?>
<?php $hasCurrentTrip = is_array($currentTrip); ?>
<div class="row">
    <div class="col-md-12">
        <p class="cv-page-intro">
            Gestione corse per provider <strong><?= cvAccessoH((string) ($provider['name'] ?? '')) ?></strong>
            (<?= cvAccessoH((string) ($provider['code'] ?? '')) ?>).
        </p>
        <?php cvAccessoIntegrationRenderProviderSelect($providers, (string) ($provider['code'] ?? '')); ?>
        <?php cvAccessoRenderMessages($state); ?>

        <?php if (count($trips) > 0): ?>
            <form method="get" style="margin-top:10px;">
                <input type="hidden" name="provider" value="<?= cvAccessoH((string) ($provider['code'] ?? '')) ?>">
                <div class="row">
                    <div class="col-md-5 form-group">
                        <label>Seleziona corsa da gestire</label>
                        <select class="form-control" name="trip" onchange="this.form.submit()">
                            <option value="">-- Seleziona --</option>
                            <?php foreach ($trips as $tripRow): ?>
                                <?php
                                $tripId = trim((string) ($tripRow['external_id'] ?? ''));
                                if ($tripId === '') {
                                    continue;
                                }
                                $label = trim((string) ($tripRow['name'] ?? ''));
                                if ($label === '') {
                                    $label = $tripId;
                                }
                                $lineLabel = trim((string) ($tripRow['line_name'] ?? ''));
                                ?>
                                <option value="<?= cvAccessoH($tripId) ?>"<?= is_array($currentTrip) && (string) ($currentTrip['external_id'] ?? '') === $tripId ? ' selected' : '' ?>>
                                    <?= cvAccessoH($label) ?><?= $lineLabel !== '' && $lineLabel !== '-' ? (' — ' . cvAccessoH($lineLabel)) : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="cv-muted" style="margin-top:6px;">
                            Dopo la selezione puoi associare fermate, riordinarle e impostare gli orari.
                        </div>
                    </div>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="row">
    <div class="col-md-5"<?= $hasCurrentTrip ? ' style="display:none;"' : '' ?>>
        <div class="cv-panel-card">
            <h4>Aggiungi corsa</h4>
            <form method="post">
                <input type="hidden" name="action" value="add_trip">
                <?= cvAccessoCsrfField() ?>
                <div class="form-group">
                    <label>Linea</label>
                    <select class="form-control" name="trip_line" required>
                        <?php foreach ($lines as $line): ?>
                            <option value="<?= cvAccessoH((string) ($line['external_id'] ?? '')) ?>"><?= cvAccessoH((string) ($line['name'] ?? '')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Nome corsa</label>
                    <input type="text" class="form-control" name="trip_name" placeholder="Corsa Napoli → Roma" required>
                </div>
                <div class="row">
                    <div class="col-md-6 form-group">
                        <label>Tempo acquisto (min)</label>
                        <input type="number" class="form-control" name="trip_tempo_acquisto" min="0" step="1" value="30">
                    </div>
                    <div class="col-md-6 form-group cv-muted" style="margin-top:28px;">
                        Tempo oltre il quale non si acquista più.
                    </div>
                </div>
                <div class="checkbox" style="margin-top:6px;">
                    <input id="tripVisibleNew" type="checkbox" name="trip_is_visible" value="1" checked>
                    <label for="tripVisibleNew">Visibile</label>
                </div>
                <button type="submit" class="btn btn-primary" style="margin-top:8px;">Aggiungi</button>
                <div class="cv-muted" style="margin-top:10px;">
                    Limite corse: <?= (int) ($provider['manual_max_trips'] ?? 0) ?> (0 = illimitato)
                </div>
            </form>
        </div>

        <?php if (is_array($currentTrip)): ?>
            <?php
            $tripExt = (string) ($currentTrip['external_id'] ?? '');
            $tripIsActive = (int) ($currentTrip['is_active'] ?? 0) === 1;
            $tripIsVisible = (int) ($currentTrip['is_visible'] ?? 0) === 1;
            ?>
            <div class="cv-panel-card" style="margin-top:14px;">
                <h4>Modifica corsa</h4>
                <form method="post">
                    <input type="hidden" name="action" value="update_trip">
                    <input type="hidden" name="trip_external_id" value="<?= cvAccessoH($tripExt) ?>">
                    <?= cvAccessoCsrfField() ?>
                    <div class="form-group">
                        <label>Linea</label>
                        <select class="form-control" name="trip_line">
                            <?php foreach ($lines as $line): ?>
                                <?php $lid = (string) ($line['external_id'] ?? ''); ?>
                                <option value="<?= cvAccessoH($lid) ?>"<?= $lid === (string) ($currentTrip['line_external_id'] ?? '') ? ' selected' : '' ?>>
                                    <?= cvAccessoH((string) ($line['name'] ?? '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Nome corsa</label>
                        <input type="text" class="form-control" name="trip_name" value="<?= cvAccessoH((string) ($currentTrip['name'] ?? '')) ?>" required>
                    </div>
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label>Tempo acquisto (min)</label>
                            <input type="number" class="form-control" name="trip_tempo_acquisto" min="0" step="1" value="<?= cvAccessoH((string) ($currentTrip['tempo_acquisto'] ?? 30)) ?>">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>&nbsp;</label>
                            <div class="cv-muted" style="margin-top:8px;">
                                Tempo oltre il quale non si acquista più.
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label>Stato</label>
                            <select class="form-control" name="trip_is_active">
                                <option value="1"<?= $tripIsActive ? ' selected' : '' ?>>Attiva</option>
                                <option value="0"<?= !$tripIsActive ? ' selected' : '' ?>>Off</option>
                            </select>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Visibile</label>
                            <select class="form-control" name="trip_is_visible">
                                <option value="1"<?= $tripIsVisible ? ' selected' : '' ?>>Si</option>
                                <option value="0"<?= !$tripIsVisible ? ' selected' : '' ?>>No</option>
                            </select>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-default btn-sm">Salva</button>
                    <a class="btn btn-link btn-sm" href="<?= cvAccessoH(cvAccessoUrl('integrazione_corse.php') . '?provider=' . urlencode((string) ($provider['code'] ?? ''))) ?>">Chiudi</a>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <div class="<?= $hasCurrentTrip ? 'col-md-12' : 'col-md-7' ?>">
        <div class="cv-panel-card">
            <h4>Corse</h4>
            <?php if (count($trips) === 0): ?>
                <div class="cv-empty">Nessuna corsa ancora.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Corsa</th>
                                <th>Linea</th>
                                <th style="width:90px;">Stato</th>
                                <th style="width:240px;">Azioni</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($trips as $trip): ?>
                                <?php
                                $ext = trim((string) ($trip['external_id'] ?? ''));
                                $tripHref = cvAccessoUrl('integrazione_corse.php') . '?provider=' . urlencode((string) ($provider['code'] ?? '')) . '&trip=' . urlencode($ext);
                                $tripEditHref = $tripHref;
                                $active = (int) ($trip['is_active'] ?? 0) === 1;
                                ?>
                                <tr>
                                    <td><?= cvAccessoH((string) ($trip['name'] ?? '')) !== '' ? cvAccessoH((string) ($trip['name'] ?? '')) : ('#' . cvAccessoH($ext)) ?></td>
                                    <td><?= cvAccessoH((string) ($trip['line_name'] ?? '-')) ?></td>
                                    <td><?= $active ? 'Attiva' : 'Off' ?></td>
                                    <td>
                                        <a class="btn btn-default btn-sm" href="<?= cvAccessoH($tripEditHref) ?>">Gestisci fermate</a>
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="action" value="delete_trip">
                                            <input type="hidden" name="trip_external_id" value="<?= cvAccessoH($ext) ?>">
                                            <?= cvAccessoCsrfField() ?>
                                            <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Disattivare questa corsa?')">Disattiva</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!is_array($currentTrip)): ?>
            <div class="alert alert-info" style="margin-top:14px;">
                Seleziona una corsa (menu a tendina sopra o “Gestisci fermate”) per associare le fermate e impostare gli orari.
            </div>
        <?php else: ?>
            <div class="cv-panel-card" style="margin-top:14px;">
                <div class="d-flex align-items-center justify-content-between" style="gap:10px; flex-wrap:wrap;">
                    <h4 style="margin:0;">Corsa selezionata</h4>
                    <a class="btn btn-default btn-sm" href="<?= cvAccessoH(cvAccessoUrl('integrazione_corse.php') . '?provider=' . urlencode((string) ($provider['code'] ?? ''))) ?>">Cambia corsa</a>
                </div>
                <?php
                $tripExt = (string) ($currentTrip['external_id'] ?? '');
                $tripIsActive = (int) ($currentTrip['is_active'] ?? 0) === 1;
                $tripIsVisible = (int) ($currentTrip['is_visible'] ?? 0) === 1;
                ?>
                <form method="post" style="margin-top:12px;">
                    <input type="hidden" name="action" value="update_trip">
                    <input type="hidden" name="trip_external_id" value="<?= cvAccessoH($tripExt) ?>">
                    <?= cvAccessoCsrfField() ?>
                    <div class="row">
                        <div class="col-md-4 form-group">
                            <label>Linea</label>
                            <select class="form-control" name="trip_line" required>
                                <?php foreach ($lines as $line): ?>
                                    <?php $lid = (string) ($line['external_id'] ?? ''); ?>
                                    <option value="<?= cvAccessoH($lid) ?>"<?= $lid === (string) ($currentTrip['line_external_id'] ?? '') ? ' selected' : '' ?>>
                                        <?= cvAccessoH((string) ($line['name'] ?? '')) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Nome corsa</label>
                            <input type="text" class="form-control" name="trip_name" value="<?= cvAccessoH((string) ($currentTrip['name'] ?? '')) ?>" required>
                        </div>
                        <div class="col-md-2 form-group">
                            <label>Tempo acquisto (min)</label>
                            <input type="number" class="form-control" name="trip_tempo_acquisto" min="0" step="1" value="<?= cvAccessoH((string) ($currentTrip['tempo_acquisto'] ?? 30)) ?>">
                        </div>
                        <div class="col-md-2 form-group">
                            <label>Visibile</label>
                            <select class="form-control" name="trip_is_visible">
                                <option value="1"<?= $tripIsVisible ? ' selected' : '' ?>>Si</option>
                                <option value="0"<?= !$tripIsVisible ? ' selected' : '' ?>>No</option>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-3 form-group">
                            <label>Stato</label>
                            <select class="form-control" name="trip_is_active">
                                <option value="1"<?= $tripIsActive ? ' selected' : '' ?>>Attiva</option>
                                <option value="0"<?= !$tripIsActive ? ' selected' : '' ?>>Off</option>
                            </select>
                        </div>
                        <div class="col-md-9 form-group cv-muted" style="margin-top:28px;">
                            Prima di andare avanti assicurati che tutte le fermate della corsa abbiano un orario.
                        </div>
                    </div>
                    <button type="submit" class="btn btn-default btn-sm">Salva corsa</button>
                </form>
            </div>

            <?php $tripExt = (string) ($currentTrip['external_id'] ?? ''); ?>
            <div class="cv-panel-card" style="margin-top:14px;">
                <h4>Fermate corsa</h4>
                <?php if (count($stops) === 0): ?>
                    <div class="alert alert-warning">Aggiungi le fermate prima di costruire la corsa.</div>
                <?php else: ?>
                    <form method="post" style="margin-bottom:12px;">
                        <input type="hidden" name="action" value="add_trip_stop">
                        <input type="hidden" name="trip_external_id" value="<?= cvAccessoH($tripExt) ?>">
                        <?= cvAccessoCsrfField() ?>
                        <div class="row">
                            <div class="col-md-5 form-group">
                                <label>Fermata</label>
                                <select class="form-control" name="stop_external_id" required>
                                    <?php foreach ($stops as $stop): ?>
                                        <option value="<?= cvAccessoH((string) ($stop['external_id'] ?? '')) ?>"><?= cvAccessoH((string) ($stop['name'] ?? '')) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3 form-group">
                                <label>Ora (HH:MM)</label>
                                <input type="text" class="form-control" name="time_local" placeholder="08:30">
                            </div>
                            <div class="col-md-2 form-group">
                                <label>Giorno +</label>
                                <input type="number" class="form-control" name="day_offset" step="1" value="0">
                            </div>
                            <div class="col-md-2 form-group" style="margin-top:26px;">
                                <button type="submit" class="btn btn-primary btn-sm">Aggiungi</button>
                            </div>
                        </div>
                    </form>
                <?php endif; ?>

                <?php if (count($currentTripStops) === 0): ?>
                    <div class="cv-empty">Nessuna fermata associata a questa corsa.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th style="width:70px;">Seq</th>
                                    <th>Fermata</th>
                                    <th style="width:110px;">Ora</th>
                                    <th style="width:90px;">Day+</th>
                                    <th style="width:90px;">Stato</th>
                                    <th style="width:260px;">Azioni</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($currentTripStops as $ts): ?>
                                    <?php
                                    $seq = (int) ($ts['sequence_no'] ?? 0);
                                    $active = (int) ($ts['is_active'] ?? 0) === 1;
                                    ?>
                                    <tr>
                                        <td><?= (int) $seq ?></td>
                                        <td>
                                            <form method="post" style="margin:0;">
                                                <input type="hidden" name="action" value="update_trip_stop">
                                                <input type="hidden" name="trip_external_id" value="<?= cvAccessoH($tripExt) ?>">
                                                <input type="hidden" name="sequence_no" value="<?= (int) $seq ?>">
                                                <?= cvAccessoCsrfField() ?>
                                                <select class="form-control" name="stop_external_id"<?= $active ? '' : ' disabled' ?>>
                                                    <?php foreach ($stops as $stop): ?>
                                                        <?php $sid = (string) ($stop['external_id'] ?? ''); ?>
                                                        <option value="<?= cvAccessoH($sid) ?>"<?= $sid === (string) ($ts['stop_external_id'] ?? '') ? ' selected' : '' ?>>
                                                            <?= cvAccessoH((string) ($stop['name'] ?? '')) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                        </td>
                                        <td><input type="text" class="form-control" name="time_local" value="<?= cvAccessoH((string) ($ts['time_local'] ?? '')) ?>" placeholder="08:30"<?= $active ? '' : ' disabled' ?>></td>
                                        <td><input type="number" class="form-control" name="day_offset" value="<?= (int) ($ts['day_offset'] ?? 0) ?>" step="1"<?= $active ? '' : ' disabled' ?>></td>
                                        <td><?= $active ? 'Attiva' : 'Off' ?></td>
                                        <td>
                                                <button type="submit" class="btn btn-default btn-sm"<?= $active ? '' : ' disabled' ?>>Salva</button>
                                            </form>
                                            <form method="post" style="display:inline;">
                                                <input type="hidden" name="action" value="move_trip_stop">
                                                <input type="hidden" name="trip_external_id" value="<?= cvAccessoH($tripExt) ?>">
                                                <input type="hidden" name="sequence_no" value="<?= (int) $seq ?>">
                                                <input type="hidden" name="direction" value="up">
                                                <?= cvAccessoCsrfField() ?>
                                                <button type="submit" class="btn btn-default btn-sm"<?= $active ? '' : ' disabled' ?>>↑</button>
                                            </form>
                                            <form method="post" style="display:inline;">
                                                <input type="hidden" name="action" value="move_trip_stop">
                                                <input type="hidden" name="trip_external_id" value="<?= cvAccessoH($tripExt) ?>">
                                                <input type="hidden" name="sequence_no" value="<?= (int) $seq ?>">
                                                <input type="hidden" name="direction" value="down">
                                                <?= cvAccessoCsrfField() ?>
                                                <button type="submit" class="btn btn-default btn-sm"<?= $active ? '' : ' disabled' ?>>↓</button>
                                            </form>
                                            <form method="post" style="display:inline;">
                                                <input type="hidden" name="action" value="delete_trip_stop">
                                                <input type="hidden" name="trip_external_id" value="<?= cvAccessoH($tripExt) ?>">
                                                <input type="hidden" name="sequence_no" value="<?= (int) $seq ?>">
                                                <?= cvAccessoCsrfField() ?>
                                                <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Disattivare questa fermata nella corsa?')">Disattiva</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
                <div class="cv-muted" style="margin-top:10px;">
                    Nota: per far apparire soluzioni in ricerca servono anche le <strong>tariffe</strong> per le coppie di fermate (menu Tariffe).
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php cvAccessoRenderPageEnd(); ?>

<script>
(function () {
    'use strict';

    function checkTimes(form) {
        if (!form) {
            return true;
        }
        var time = form.querySelector('input[name="time_local"]');
        if (!time) {
            return true;
        }
        var value = String(time.value || '').trim();
        if (!value) {
            if (typeof window.showMsg === 'function') {
                window.showMsg('Orario obbligatorio (HH:MM).', 0);
            }
            time.focus();
            return false;
        }
        return true;
    }

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || !(form instanceof HTMLFormElement)) {
            return;
        }
        var action = form.querySelector('input[name="action"]');
        var actionValue = action ? String(action.value || '') : '';
        if (actionValue !== 'add_trip_stop' && actionValue !== 'update_trip_stop') {
            return;
        }
        if (!checkTimes(form)) {
            e.preventDefault();
            e.stopPropagation();
        }
    }, true);
})();
</script>
