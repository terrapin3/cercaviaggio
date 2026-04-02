<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../includes/place_tools.php';

$state = cvAccessoInit();
if (!$state['authenticated']) {
    cvAccessoRenderLoginPage($state);
    return;
}

if (!cvAccessoIsAdmin($state)) {
    http_response_code(403);
    cvAccessoRenderPageStart('Macroaree', 'settings-places', $state);
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

$summary = [
    'tables_exist' => false,
    'total_places' => 0,
    'total_macroareas' => 0,
    'total_cities' => 0,
    'total_station_groups' => 0,
    'total_links' => 0,
    'providers_covered' => 0,
    'last_run' => null,
];
$placeRows = [];
$provinceOptions = [];
$lastGenerationResult = null;
$selectedPlace = null;

$filters = [
    'q' => trim((string) ($_GET['q'] ?? '')),
    'type' => trim((string) ($_GET['type'] ?? '')),
    'province' => strtoupper(trim((string) ($_GET['province'] ?? ''))),
    'edited_only' => !empty($_GET['edited_only']) ? 1 : 0,
];
$selectedPlaceId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;

$buildPlacesUrl = static function (array $extra = [], array $drop = []) use ($filters, &$selectedPlaceId): string {
    $params = array_merge(
        [
            'q' => $filters['q'],
            'type' => $filters['type'],
            'province' => $filters['province'],
            'edited_only' => $filters['edited_only'] ? '1' : '',
            'edit' => $selectedPlaceId > 0 ? (string) $selectedPlaceId : '',
        ],
        $extra
    );

    foreach ($drop as $key) {
        unset($params[$key]);
    }

    $params = array_filter(
        $params,
        static fn ($value): bool => !($value === '' || $value === null || $value === 0)
    );

    $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    return cvAccessoUrl('places.php') . ($query !== '' ? '?' . $query : '');
};

try {
    $connection = cvAccessoRequireConnection();
    $requiredPlaceFunctions = [
        'cvPlacesGenerate',
        'cvPlacesFetchAdminSummary',
        'cvPlacesFetchAdminRows',
        'cvPlacesFetchProvinceOptions',
        'cvPlacesFetchPlaceById',
        'cvPlaceNameOverridesTableExists',
        'cvPlaceUpdateNameLive',
        'cvPlaceSaveNameOverride',
    ];
    foreach ($requiredPlaceFunctions as $functionName) {
        if (!function_exists($functionName)) {
            throw new RuntimeException('File macroaree non aggiornato: manca la funzione ' . $functionName . ' in includes/place_tools.php');
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = trim((string) ($_POST['action'] ?? ''));
        if ($action === 'generate_places') {
            if (!cvAccessoValidateCsrf()) {
                $state['errors'][] = 'Sessione non valida. Ricarica la pagina.';
            } elseif (!cvPlacesTablesExist($connection)) {
                $state['errors'][] = 'Le tabelle cv_places* non esistono ancora nel database.';
            } else {
                $lastGenerationResult = cvPlacesGenerate($connection);
                $state['messages'][] = 'Macroaree rigenerate: '
                    . (int) ($lastGenerationResult['generated_places_count'] ?? 0)
                    . ' luoghi e '
                    . (int) ($lastGenerationResult['generated_links_count'] ?? 0)
                    . ' collegamenti fermata.';
            }
        } elseif ($action === 'save_place_name') {
            if (!cvAccessoValidateCsrf()) {
                $state['errors'][] = 'Sessione non valida. Ricarica la pagina.';
            } else {
                $idPlace = (int) ($_POST['id_place'] ?? 0);
                $manualName = trim((string) ($_POST['manual_name'] ?? ''));
                $notes = trim((string) ($_POST['override_notes'] ?? ''));
                $selectedPlaceId = $idPlace;

                if ($idPlace <= 0 || $manualName === '') {
                    $state['errors'][] = 'Seleziona un luogo valido e inserisci un nome.';
                } else {
                    $place = cvPlacesFetchPlaceById($connection, $idPlace);
                    if (!is_array($place)) {
                        $state['errors'][] = 'Luogo non trovato.';
                    } else {
                        $savedLive = cvPlaceUpdateNameLive($connection, $idPlace, $manualName);
                        if (!$savedLive) {
                            $state['errors'][] = 'Impossibile aggiornare il nome del luogo.';
                        } else {
                            if (cvPlaceNameOverridesTableExists($connection)) {
                                if (cvPlaceSaveNameOverride($connection, (string) ($place['code'] ?? ''), $manualName, $notes)) {
                                    $state['messages'][] = 'Nome luogo aggiornato e reso persistente per le prossime rigenerazioni.';
                                } else {
                                    $state['errors'][] = 'Il nome e stato aggiornato, ma l’override persistente non e stato salvato.';
                                }
                            } else {
                                $state['messages'][] = 'Nome luogo aggiornato. Per renderlo persistente anche dopo Rigenera importa la tabella override dei nomi.';
                            }
                        }
                    }
                }
            }
        }
    }

    $summary = cvPlacesFetchAdminSummary($connection);
    $provinceOptions = cvPlacesFetchProvinceOptions($connection);
    $selectedPlace = $selectedPlaceId > 0 ? cvPlacesFetchPlaceById($connection, $selectedPlaceId) : null;
    $placeRows = cvPlacesFetchAdminRows($connection, $filters, 120);
} catch (Throwable $exception) {
    $state['errors'][] = 'Errore sezione macroaree: ' . $exception->getMessage();
}

cvAccessoRenderPageStart('Macroaree', 'settings-places', $state);
?>
<div class="row">
    <div class="col-md-12">
        <p class="cv-page-intro">
            Generazione dinamica dei luoghi di ricerca. Qui puoi rigenerare i cluster, filtrare i risultati e correggere manualmente i nomi che non ti convincono.
        </p>
    </div>
</div>

<?php if (!$summary['tables_exist']): ?>
    <div class="row">
        <div class="col-md-12">
            <div class="cv-panel-card">
                <div class="alert alert-warning cv-alert" role="alert">
                    Le tabelle <code>cv_places</code>, <code>cv_place_stops</code>, <code>cv_place_aliases</code> e <code>cv_place_metrics</code> non risultano presenti.
                    Importa prima lo schema SQL macroaree <code>schema_cercaviaggio_places_v1.sql</code>. Se vuoi il log dei run importa anche <code>schema_cercaviaggio_places_v2.sql</code>. Per rendere persistenti i rename manuali importa <code>schema_cercaviaggio_places_v3.sql</code>.
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="row">
    <div class="col-md-8">
        <div class="cv-panel-card">
            <h4>Rigenerazione</h4>
            <p class="cv-muted">
                La rigenerazione ricostruisce i luoghi automatici a partire dalle fermate attive. I rename manuali restano applicati se hai importato la tabella override dedicata.
            </p>
            <form method="post">
                <input type="hidden" name="action" value="generate_places">
                <?= cvAccessoCsrfField() ?>
                <div class="cv-inline-actions">
                    <button type="submit" class="btn btn-primary"<?= $summary['tables_exist'] ? '' : ' disabled' ?>>Rigenera macroaree</button>
                    <a class="btn btn-default" href="<?= cvAccessoH($buildPlacesUrl([], ['q', 'type', 'province', 'edited_only', 'edit'])) ?>">Azzera filtri</a>
                    <span class="cv-muted">Usa questa azione dopo un sync importante o dopo l’ingresso di un nuovo vettore.</span>
                </div>
            </form>
            <div class="cv-inline-actions" style="margin-top:12px;">
                <a class="btn btn-default" href="<?= cvAccessoH(cvAccessoUrl('manutenzione.php')) ?>">Apri manutenzione</a>
                <span class="cv-muted">Pulizia cache/località e reset contatori sono stati centralizzati in Manutenzione.</span>
            </div>
            <?php if (is_array($lastGenerationResult)): ?>
                <div class="cv-provider-fixed" style="margin-top:16px;">
                    <strong>Ultima esecuzione corrente</strong><br>
                    Sorgenti lette: <?= (int) ($lastGenerationResult['source_stops_count'] ?? 0) ?> fermate<br>
                    Luoghi generati: <?= (int) ($lastGenerationResult['generated_places_count'] ?? 0) ?><br>
                    Collegamenti creati: <?= (int) ($lastGenerationResult['generated_links_count'] ?? 0) ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-md-4">
        <div class="cv-panel-card">
            <h4>Stato</h4>
            <p class="cv-muted">Totale luoghi attivi: <strong><?= (int) ($summary['total_places'] ?? 0) ?></strong></p>
            <p class="cv-muted">Macroaree: <strong><?= (int) ($summary['total_macroareas'] ?? 0) ?></strong></p>
            <p class="cv-muted">Citta: <strong><?= (int) ($summary['total_cities'] ?? 0) ?></strong></p>
            <p class="cv-muted">Nodi: <strong><?= (int) ($summary['total_station_groups'] ?? 0) ?></strong></p>
            <p class="cv-muted">Link fermata: <strong><?= (int) ($summary['total_links'] ?? 0) ?></strong></p>
            <p class="cv-muted">Provider coperti: <strong><?= (int) ($summary['providers_covered'] ?? 0) ?></strong></p>
            <?php if (is_array($summary['last_run'] ?? null)): ?>
                <hr>
                <p class="cv-muted">Ultimo run: <strong>#<?= (int) ($summary['last_run']['id_run'] ?? 0) ?></strong></p>
                <p class="cv-muted">Stato: <strong><?= cvAccessoH((string) ($summary['last_run']['status'] ?? '')) ?></strong></p>
                <p class="cv-muted">Algoritmo: <strong><?= cvAccessoH((string) ($summary['last_run']['algorithm_version'] ?? '')) ?></strong></p>
                <p class="cv-muted">Avvio: <strong><?= cvAccessoH((string) ($summary['last_run']['started_at'] ?? '')) ?></strong></p>
                <p class="cv-muted">Fine: <strong><?= cvAccessoH((string) ($summary['last_run']['finished_at'] ?? '')) ?></strong></p>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-5 col-lg-4">
        <div class="cv-panel-card">
            <h4><?= is_array($selectedPlace) ? 'Dettaglio luogo' : 'Dettaglio' ?></h4>
            <?php if (!is_array($selectedPlace)): ?>
                <div class="cv-empty">Seleziona un luogo dalla tabella per leggere i dettagli e correggere il nome.</div>
            <?php else: ?>
                <div class="cv-provider-fixed">
                    <strong><?= cvAccessoH((string) ($selectedPlace['name'] ?? '')) ?></strong><br>
                    Tipo: <?= cvAccessoH(cvPlaceTypeLabel((string) ($selectedPlace['place_type'] ?? ''))) ?><br>
                    Codice: <?= cvAccessoH((string) ($selectedPlace['code'] ?? '')) ?><br>
                    Padre: <?= cvAccessoH((string) ($selectedPlace['parent_name'] ?? '-')) ?><br>
                    Provincia: <?= cvAccessoH((string) ($selectedPlace['province_code'] ?? '-')) ?><br>
                    Fermate: <?= (int) ($selectedPlace['stop_count'] ?? 0) ?> · Provider: <?= (int) ($selectedPlace['provider_count'] ?? 0) ?>
                </div>

                <form method="post" class="cv-form-grid">
                    <input type="hidden" name="action" value="save_place_name">
                    <input type="hidden" name="id_place" value="<?= (int) ($selectedPlace['id_place'] ?? 0) ?>">
                    <?= cvAccessoCsrfField() ?>

                    <div class="form-group">
                        <label for="manual_name">Nome consultabile</label>
                        <input
                            id="manual_name"
                            type="text"
                            name="manual_name"
                            class="form-control"
                            value="<?= cvAccessoH((string) (($selectedPlace['manual_name'] ?? '') !== '' ? $selectedPlace['manual_name'] : ($selectedPlace['name'] ?? ''))) ?>"
                            required
                        >
                        <div class="cv-muted">Questo nome viene mostrato in backend e nel frontend ricerca.</div>
                    </div>

                    <div class="form-group">
                        <label for="override_notes">Note admin</label>
                        <textarea id="override_notes" name="override_notes" class="form-control" rows="3"><?= cvAccessoH((string) ($selectedPlace['notes'] ?? '')) ?></textarea>
                    </div>

                    <div class="cv-inline-actions">
                        <button type="submit" class="btn btn-primary">Salva nome</button>
                        <a class="btn btn-default" href="<?= cvAccessoH($buildPlacesUrl([], ['edit'])) ?>">Chiudi dettaglio</a>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-md-7 col-lg-8">
        <div class="cv-panel-card">
            <div class="cv-panel-head">
                <div>
                    <h4>Luoghi generati</h4>
                    <div class="cv-muted">
                        <?= count($placeRows) ?> risultati
                        <?php if ($filters['q'] !== '' || $filters['type'] !== '' || $filters['province'] !== '' || $filters['edited_only']): ?>
                            con filtri attivi
                        <?php endif; ?>
                    </div>
                </div>
                <button
                    type="button"
                    class="btn btn-outline btn-primary cv-filter-trigger"
                    data-cv-drawer-toggle="places-filter-drawer"
                    aria-controls="places-filter-drawer"
                    aria-expanded="false"
                >
                    <i class="fa fa-cog"></i>
                </button>
            </div>

            <?php if (count($placeRows) === 0): ?>
                <div class="cv-empty">Nessun luogo trovato con i filtri correnti.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table cv-table">
                        <thead>
                        <tr>
                            <th>Nome</th>
                            <th>Tipo</th>
                            <th>Padre</th>
                            <th>Provincia</th>
                            <th>Fermate</th>
                            <th>Provider</th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($placeRows as $row): ?>
                            <?php
                            $isSelected = (int) ($row['id_place'] ?? 0) === (int) $selectedPlaceId;
                            $rowUrl = $buildPlacesUrl(['edit' => (string) ((int) ($row['id_place'] ?? 0))]);
                            ?>
                            <tr class="<?= $isSelected ? 'cv-row-selected' : '' ?>">
                                <td>
                                    <strong><?= cvAccessoH((string) ($row['name'] ?? '')) ?></strong>
                                    <?php if (trim((string) ($row['manual_name'] ?? '')) !== ''): ?>
                                        <span class="cv-pill">manuale</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= cvAccessoH(cvPlaceTypeLabel((string) ($row['place_type'] ?? ''))) ?></td>
                                <td><?= cvAccessoH((string) ($row['parent_name'] ?? '-')) ?></td>
                                <td><?= cvAccessoH((string) ($row['province_code'] ?? '-')) ?></td>
                                <td><?= (int) ($row['stop_count'] ?? 0) ?></td>
                                <td><?= (int) ($row['provider_count'] ?? 0) ?></td>
                                <td class="text-right">
                                    <a class="btn btn-xs btn-default" href="<?= cvAccessoH($rowUrl) ?>">Apri</a>
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

<div class="cv-side-drawer-backdrop" data-cv-drawer-close="places-filter-drawer"></div>
<aside id="places-filter-drawer" class="cv-side-drawer" aria-hidden="true">
    <div class="cv-side-drawer-head">
        <h4>Filtri Macroaree</h4>
        <button type="button" class="btn btn-default btn-sm" data-cv-drawer-close="places-filter-drawer">
            <i class="fa fa-times"></i>
        </button>
    </div>

    <form method="get" action="<?= cvAccessoH(cvAccessoUrl('places.php')) ?>" class="cv-form-grid">
        <?php if ($selectedPlaceId > 0): ?>
            <input type="hidden" name="edit" value="<?= (int) $selectedPlaceId ?>">
        <?php endif; ?>

        <div class="form-group">
            <label for="filter_q">Cerca</label>
            <input id="filter_q" type="text" name="q" class="form-control" value="<?= cvAccessoH($filters['q']) ?>" placeholder="Nome, codice, padre">
        </div>

        <div class="form-group">
            <label for="filter_type">Tipo</label>
            <select id="filter_type" name="type" class="form-control">
                <option value="">Tutti</option>
                <option value="macroarea"<?= $filters['type'] === 'macroarea' ? ' selected' : '' ?>>Macroarea</option>
                <option value="city"<?= $filters['type'] === 'city' ? ' selected' : '' ?>>Citta</option>
                <option value="station_group"<?= $filters['type'] === 'station_group' ? ' selected' : '' ?>>Nodo</option>
                <option value="district"<?= $filters['type'] === 'district' ? ' selected' : '' ?>>Zona</option>
                <option value="province"<?= $filters['type'] === 'province' ? ' selected' : '' ?>>Provincia</option>
            </select>
        </div>

        <div class="form-group">
            <label for="filter_province">Provincia</label>
            <select id="filter_province" name="province" class="form-control">
                <option value="">Tutte</option>
                <?php foreach ($provinceOptions as $provinceCode): ?>
                    <option value="<?= cvAccessoH($provinceCode) ?>"<?= $filters['province'] === $provinceCode ? ' selected' : '' ?>><?= cvAccessoH($provinceCode) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="checkbox checkbox-primary">
            <input id="edited_only" type="checkbox" name="edited_only" value="1"<?= $filters['edited_only'] ? ' checked' : '' ?>>
            <label for="edited_only">Solo nomi modificati</label>
        </div>

        <div class="cv-inline-actions">
            <button type="submit" class="btn btn-primary">Filtra</button>
            <a class="btn btn-default" href="<?= cvAccessoH($buildPlacesUrl([], ['q', 'type', 'province', 'edited_only'])) ?>">Reset</a>
        </div>
    </form>
</aside>
<?php
cvAccessoRenderPageEnd();
