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
$editStop = null;

try {
    $connection = cvAccessoRequireConnection();
    $ctx = cvAccessoIntegrationResolveProvider($connection, $state);
    $provider = $ctx['provider'];
    $providers = $ctx['providers'];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!cvAccessoValidateCsrf()) {
            $state['errors'][] = 'Sessione non valida. Ricarica la pagina.';
        } else {
            $action = trim((string) ($_POST['action'] ?? ''));

            if ($action === 'add_stop') {
                $name = trim((string) ($_POST['stop_name'] ?? ''));
                $latRaw = trim((string) ($_POST['stop_lat'] ?? ''));
                $lonRaw = trim((string) ($_POST['stop_lon'] ?? ''));
                $latValue = $latRaw !== '' && is_numeric($latRaw) ? (string) $latRaw : '';
                $lonValue = $lonRaw !== '' && is_numeric($lonRaw) ? (string) $lonRaw : '';
                $geoAutofill = ((int) ($_POST['stop_geo_autofill'] ?? 0)) === 1;

                if ($name === '') {
                    $state['errors'][] = 'Nome fermata obbligatorio.';
                } else {
                    if ($geoAutofill && ($latValue === '' || $lonValue === '')) {
                        $lookup = cvAccessoGeoLookupLatLon($name);
                        if (is_array($lookup)) {
                            $latValue = (string) ($lookup['lat'] ?? $latValue);
                            $lonValue = (string) ($lookup['lon'] ?? $lonValue);
                            $state['messages'][] = 'Coordinate compilate automaticamente da OpenStreetMap.';
                        }
                    }
                    $externalId = cvAccessoIntegrationGenerateExternalId('stop');
                    $rawJson = json_encode(['source' => 'manual'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $stmt = $connection->prepare(
                        "INSERT INTO cv_provider_stops (id_provider, external_id, name, lat, lon, is_active, raw_json)
                         VALUES (?, ?, ?, NULLIF(?, ''), NULLIF(?, ''), 1, ?)"
                    );
                    if (!$stmt instanceof mysqli_stmt) {
                        $state['errors'][] = 'Prepare insert fermata fallita.';
                    } else {
                        $idProvider = (int) ($provider['id_provider'] ?? 0);
                        $stmt->bind_param('isssss', $idProvider, $externalId, $name, $latValue, $lonValue, $rawJson);
                        if (!$stmt->execute()) {
                            $state['errors'][] = 'Inserimento fermata fallito: ' . $stmt->error;
                        } else {
                            $state['messages'][] = 'Fermata aggiunta.';
                        }
                        $stmt->close();
                    }
                }
            } elseif ($action === 'update_stop') {
                $externalId = trim((string) ($_POST['stop_external_id'] ?? ''));
                $name = trim((string) ($_POST['stop_name'] ?? ''));
                $latRaw = trim((string) ($_POST['stop_lat'] ?? ''));
                $lonRaw = trim((string) ($_POST['stop_lon'] ?? ''));
                $isActive = ((int) ($_POST['stop_is_active'] ?? 1)) > 0 ? 1 : 0;
                $latValue = $latRaw !== '' && is_numeric($latRaw) ? (string) $latRaw : '';
                $lonValue = $lonRaw !== '' && is_numeric($lonRaw) ? (string) $lonRaw : '';
                $geoAutofill = ((int) ($_POST['stop_geo_autofill'] ?? 0)) === 1;

                if ($externalId === '' || $name === '') {
                    $state['errors'][] = 'Dati fermata non validi.';
                } else {
                    if ($geoAutofill) {
                        $lookup = cvAccessoGeoLookupLatLon($name);
                        if (is_array($lookup)) {
                            $latValue = (string) ($lookup['lat'] ?? $latValue);
                            $lonValue = (string) ($lookup['lon'] ?? $lonValue);
                            $state['messages'][] = 'Coordinate compilate automaticamente da OpenStreetMap.';
                        }
                    }
                    $stmt = $connection->prepare(
                        "UPDATE cv_provider_stops
                         SET name = ?, lat = NULLIF(?, ''), lon = NULLIF(?, ''), is_active = ?
                         WHERE id_provider = ? AND external_id = ?
                         LIMIT 1"
                    );
                    if (!$stmt instanceof mysqli_stmt) {
                        $state['errors'][] = 'Prepare update fermata fallita.';
                    } else {
                        $idProvider = (int) ($provider['id_provider'] ?? 0);
                        $stmt->bind_param('sssiis', $name, $latValue, $lonValue, $isActive, $idProvider, $externalId);
                        if (!$stmt->execute()) {
                            $state['errors'][] = 'Update fermata fallito: ' . $stmt->error;
                        } else {
                            $state['messages'][] = 'Fermata aggiornata.';
                        }
                        $stmt->close();
                    }
                }
            } elseif ($action === 'delete_stop') {
                $externalId = trim((string) ($_POST['stop_external_id'] ?? ''));
                if ($externalId === '') {
                    $state['errors'][] = 'Fermata non valida.';
                } else {
                    $stmt = $connection->prepare(
                        "UPDATE cv_provider_stops
                         SET is_active = 0
                         WHERE id_provider = ? AND external_id = ?
                         LIMIT 1"
                    );
                    if ($stmt instanceof mysqli_stmt) {
                        $idProvider = (int) ($provider['id_provider'] ?? 0);
                        $stmt->bind_param('is', $idProvider, $externalId);
                        if (!$stmt->execute()) {
                            $state['errors'][] = 'Eliminazione fermata fallita: ' . $stmt->error;
                        } else {
                            $state['messages'][] = 'Fermata disattivata.';
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
            "SELECT external_id, name, lat, lon, is_active
             FROM cv_provider_stops
             WHERE id_provider = ? AND external_id = ?
             LIMIT 1"
        );
        if ($stmt instanceof mysqli_stmt) {
            $idProvider = (int) ($provider['id_provider'] ?? 0);
            $stmt->bind_param('is', $idProvider, $editExternalId);
            if ($stmt->execute()) {
                $res = $stmt->get_result();
                if ($res instanceof mysqli_result) {
                    $row = $res->fetch_assoc();
                    if (is_array($row)) {
                        $editStop = $row;
                    }
                    $res->free();
                }
            }
            $stmt->close();
        }
    }

    $stmt = $connection->prepare(
        "SELECT external_id, name, lat, lon, is_active
         FROM cv_provider_stops
         WHERE id_provider = ?
         ORDER BY name ASC"
    );
    if ($stmt instanceof mysqli_stmt) {
        $idProvider = (int) ($provider['id_provider'] ?? 0);
        $stmt->bind_param('i', $idProvider);
        if ($stmt->execute()) {
            $res = $stmt->get_result();
            if ($res instanceof mysqli_result) {
                while ($row = $res->fetch_assoc()) {
                    if (is_array($row)) {
                        $stops[] = $row;
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

cvAccessoRenderPageStart('Integrazione - Fermate', 'integration-stops', $state);
?>
<div class="row">
    <div class="col-md-12">
        <p class="cv-page-intro">
            Gestione fermate per provider <strong><?= cvAccessoH((string) ($provider['name'] ?? '')) ?></strong>
            (<?= cvAccessoH((string) ($provider['code'] ?? '')) ?>).
        </p>
        <?php cvAccessoIntegrationRenderProviderSelect($providers, (string) ($provider['code'] ?? '')); ?>
        <?php cvAccessoRenderMessages($state); ?>
    </div>
</div>

<div class="row">
    <div class="col-md-5">
        <div class="cv-panel-card">
            <h4>Aggiungi fermata</h4>
            <form method="post">
                <input type="hidden" name="action" value="add_stop">
                <?= cvAccessoCsrfField() ?>
                <input type="hidden" name="stop_geo_autofill" value="1">
                <div class="form-group">
                    <label>Nome</label>
                    <div class="cv-typeahead">
                        <input id="stopNameInput" type="text" class="form-control" name="stop_name" autocomplete="off" required>
                        <div id="stopNameSuggest" class="cv-typeahead-menu d-none" role="listbox" aria-label="Suggerimenti fermata"></div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6 form-group">
                        <label>Lat</label>
                        <input id="stopLatInput" type="text" class="form-control" name="stop_lat" placeholder="40.8518">
                    </div>
                    <div class="col-md-6 form-group">
                        <label>Lon</label>
                        <input id="stopLonInput" type="text" class="form-control" name="stop_lon" placeholder="14.2681">
                    </div>
                </div>
                <div class="cv-muted" style="margin-bottom:10px;">
                    Se usi i suggerimenti, vengono compilati automaticamente Nome/Lat/Lon via OpenStreetMap.
                </div>
                <button type="submit" class="btn btn-primary">Aggiungi</button>
            </form>
        </div>
    </div>
    <div class="col-md-7">
        <div class="cv-panel-card">
            <h4>Fermate</h4>
            <?php if (is_array($editStop)): ?>
                <?php
                $editExt = trim((string) ($editStop['external_id'] ?? ''));
                $editActive = (int) ($editStop['is_active'] ?? 0) === 1;
                ?>
                <div class="alert alert-info">
                    Modifica fermata: <code><?= cvAccessoH($editExt) ?></code>
                </div>
                <form method="post" style="margin-bottom:14px;">
                    <input type="hidden" name="action" value="update_stop">
                    <input type="hidden" name="stop_external_id" value="<?= cvAccessoH($editExt) ?>">
                    <input type="hidden" id="editStopGeoAutofill" name="stop_geo_autofill" value="0">
                    <?= cvAccessoCsrfField() ?>
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label>Nome</label>
                            <div class="cv-typeahead">
                                <input id="editStopNameInput" type="text" class="form-control" name="stop_name" value="<?= cvAccessoH((string) ($editStop['name'] ?? '')) ?>" autocomplete="off" required>
                                <div id="editStopNameSuggest" class="cv-typeahead-menu d-none" role="listbox" aria-label="Suggerimenti fermata"></div>
                            </div>
                        </div>
                        <div class="col-md-3 form-group">
                            <label>Lat</label>
                            <input id="editStopLatInput" type="text" class="form-control" name="stop_lat" value="<?= cvAccessoH((string) ($editStop['lat'] ?? '')) ?>">
                        </div>
                        <div class="col-md-3 form-group">
                            <label>Lon</label>
                            <input id="editStopLonInput" type="text" class="form-control" name="stop_lon" value="<?= cvAccessoH((string) ($editStop['lon'] ?? '')) ?>">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-3 form-group">
                            <label>Stato</label>
                            <select class="form-control" name="stop_is_active">
                                <option value="1"<?= $editActive ? ' selected' : '' ?>>Attiva</option>
                                <option value="0"<?= !$editActive ? ' selected' : '' ?>>Off</option>
                            </select>
                        </div>
                        <div class="col-md-9 form-group cv-muted" style="margin-top:28px;">
                            Suggerimento: scegli un risultato dal dropdown per aggiornare automaticamente anche Lat/Lon.
                        </div>
                    </div>
                    <button type="submit" class="btn btn-default btn-sm">Salva</button>
                    <a class="btn btn-link btn-sm" href="<?= cvAccessoH(cvAccessoUrl('integrazione_fermate.php') . '?provider=' . urlencode((string) ($provider['code'] ?? ''))) ?>">Annulla</a>
                </form>
            <?php endif; ?>
            <?php if (count($stops) === 0): ?>
                <div class="cv-empty">Nessuna fermata ancora.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Nome</th>
                                <th style="width:110px;">Lat</th>
                                <th style="width:110px;">Lon</th>
                                <th style="width:110px;">Stato</th>
                                <th style="width:240px;">Azioni</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stops as $stop): ?>
                                <?php
                                $ext = trim((string) ($stop['external_id'] ?? ''));
                                $isActive = (int) ($stop['is_active'] ?? 0) === 1;
                                $editHref = cvAccessoUrl('integrazione_fermate.php') . '?provider=' . urlencode((string) ($provider['code'] ?? '')) . '&edit=' . urlencode($ext);
                                ?>
                                <tr>
                                    <td><?= cvAccessoH((string) ($stop['name'] ?? '')) ?></td>
                                    <td><?= cvAccessoH((string) ($stop['lat'] ?? '')) ?></td>
                                    <td><?= cvAccessoH((string) ($stop['lon'] ?? '')) ?></td>
                                    <td><?= $isActive ? 'Attiva' : 'Off' ?></td>
                                    <td>
                                        <a class="btn btn-default btn-sm" href="<?= cvAccessoH($editHref) ?>">Modifica</a>
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="action" value="delete_stop">
                                            <input type="hidden" name="stop_external_id" value="<?= cvAccessoH($ext) ?>">
                                            <?= cvAccessoCsrfField() ?>
                                            <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Disattivare questa fermata?')">Disattiva</button>
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

<script>
(function () {
    'use strict';

    var csrf = <?= json_encode(cvAccessoCsrfToken(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    function attachTypeahead(opts) {
        if (!opts || !opts.nameInput || !opts.menu) {
            return;
        }
        var nameInput = opts.nameInput;
        var latInput = opts.latInput || null;
        var lonInput = opts.lonInput || null;
        var menu = opts.menu;
        var geoAutofillInput = opts.geoAutofillInput || null;
        var timer = null;

	        function closeMenu() {
	            menu.classList.add('d-none');
	            menu.innerHTML = '';
	        }

        function escapeHtml(value) {
            return String(value || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function renderItems(items) {
            if (!Array.isArray(items) || items.length === 0) {
                closeMenu();
                return;
            }
            var html = '';
            for (var i = 0; i < items.length; i += 1) {
                var it = items[i] || {};
                var label = String(it.label || '');
                var lat = String(it.lat || '');
                var lon = String(it.lon || '');
                if (!label || !lat || !lon) {
                    continue;
                }
                html += '<button type="button" class="cv-typeahead-item" data-label="' + escapeHtml(label) + '" data-lat="' + escapeHtml(lat) + '" data-lon="' + escapeHtml(lon) + '">';
                html += escapeHtml(label);
                html += '<small>' + escapeHtml(lat + ', ' + lon) + '</small>';
                html += '</button>';
            }
            if (!html) {
                closeMenu();
                return;
            }
            menu.innerHTML = html;
            menu.classList.remove('d-none');
        }

        function fetchSuggest(q) {
            var url = <?= json_encode(cvAccessoUrl('api_geo.php'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
            url += '?q=' + encodeURIComponent(q) + '&csrf_token=' + encodeURIComponent(csrf);
            return fetch(url, { credentials: 'same-origin' })
                .then(function (r) { return r.json().catch(function () { return null; }); })
                .then(function (body) {
                    if (!body || body.success !== true) {
                        return [];
                    }
                    return Array.isArray(body.items) ? body.items : [];
                })
                .catch(function () { return []; });
        }

        nameInput.addEventListener('input', function () {
            var q = String(nameInput.value || '').trim();
            if (q.length < 3) {
                closeMenu();
                return;
            }
            window.clearTimeout(timer);
            timer = window.setTimeout(function () {
                fetchSuggest(q).then(function (items) {
                    if (String(nameInput.value || '').trim() !== q) {
                        return;
                    }
                    renderItems(items);
                });
            }, 700);
        });

        nameInput.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                closeMenu();
            }
        });

        document.addEventListener('click', function (e) {
            var target = e.target;
            if (!target || !(target instanceof Element)) {
                return;
            }
            if (nameInput.contains(target) || menu.contains(target)) {
                return;
            }
            closeMenu();
        });

        menu.addEventListener('click', function (e) {
            var target = e.target;
            if (!target || !(target instanceof Element)) {
                return;
            }
            var btn = target.closest('.cv-typeahead-item');
            if (!btn) {
                return;
            }
            var label = btn.getAttribute('data-label') || '';
            var lat = btn.getAttribute('data-lat') || '';
            var lon = btn.getAttribute('data-lon') || '';
            if (label) {
                nameInput.value = label;
            }
            if (latInput && lat) {
                latInput.value = lat;
            }
            if (lonInput && lon) {
                lonInput.value = lon;
            }
            if (geoAutofillInput) {
                geoAutofillInput.value = '1';
            }
            closeMenu();
        });
    }

    attachTypeahead({
        nameInput: document.getElementById('stopNameInput'),
        latInput: document.getElementById('stopLatInput'),
        lonInput: document.getElementById('stopLonInput'),
        menu: document.getElementById('stopNameSuggest'),
        geoAutofillInput: null
    });

    attachTypeahead({
        nameInput: document.getElementById('editStopNameInput'),
        latInput: document.getElementById('editStopLatInput'),
        lonInput: document.getElementById('editStopLonInput'),
        menu: document.getElementById('editStopNameSuggest'),
        geoAutofillInput: document.getElementById('editStopGeoAutofill')
    });
})();
</script>

<?php cvAccessoRenderPageEnd(); ?>
