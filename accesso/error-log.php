<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../includes/error_log_tools.php';

$state = cvAccessoInit();
if (!$state['authenticated']) {
    cvAccessoRenderLoginPage($state);
    return;
}

$providers = [];
$logs = [];
$totalRows = 0;
$totalPages = 1;
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 80;

$sourceFilter = strtolower(trim((string) ($_GET['source'] ?? '')));
$eventFilter = strtoupper(trim((string) ($_GET['event_code'] ?? '')));
$severityFilter = strtolower(trim((string) ($_GET['severity'] ?? 'all')));
$providerFilter = strtolower(trim((string) ($_GET['provider'] ?? '')));
$actionFilter = strtolower(trim((string) ($_GET['action_name'] ?? '')));
$searchFilter = trim((string) ($_GET['q'] ?? ''));
$dateFrom = trim((string) ($_GET['from'] ?? ''));
$dateTo = trim((string) ($_GET['to'] ?? ''));
$activeFiltersCount = 0;

try {
    $connection = cvAccessoRequireConnection();
    cvErrorLogEnsureTable($connection);

    $providers = cvAccessoFilterProviders($state, cvCacheFetchProviders($connection));
    usort($providers, static function (array $left, array $right): int {
        return strcasecmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
    });

    $allowedProviderCodes = [];
    foreach ($providers as $provider) {
        $code = strtolower(trim((string) ($provider['code'] ?? '')));
        if ($code !== '') {
            $allowedProviderCodes[$code] = true;
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        if (!cvAccessoValidateCsrf()) {
            $state['errors'][] = 'Sessione non valida. Ricarica la pagina.';
        } else {
            $postAction = trim((string) $_POST['action']);
            if ($postAction === 'purge_old') {
                if (!cvAccessoIsAdmin($state)) {
                    $state['errors'][] = 'Solo amministratore: operazione non consentita.';
                } else {
                    $retentionDays = (int) ($_POST['retention_days'] ?? 30);
                    $retentionDays = max(7, min(365, $retentionDays));
                    $sqlPurge = "DELETE FROM cv_error_log WHERE created_at < (NOW() - INTERVAL " . $retentionDays . " DAY)";
                    if ($connection->query($sqlPurge)) {
                        $state['messages'][] = 'Log più vecchi di ' . $retentionDays . ' giorni eliminati.';
                    } else {
                        $state['errors'][] = 'Errore purge log: ' . $connection->error;
                    }
                }
            }
        }
    }

    $where = ['1=1'];
    if (!cvAccessoIsAdmin($state)) {
        if (count($allowedProviderCodes) === 0) {
            $where[] = "(provider_code IS NULL OR provider_code = '')";
        } else {
            $providerIn = [];
            foreach (array_keys($allowedProviderCodes) as $code) {
                $providerIn[] = "'" . $connection->real_escape_string($code) . "'";
            }
            $where[] = "(provider_code IS NULL OR provider_code = '' OR LOWER(provider_code) IN (" . implode(', ', $providerIn) . '))';
        }
    }

    if ($sourceFilter !== '') {
        $where[] = "LOWER(source) = '" . $connection->real_escape_string($sourceFilter) . "'";
    }

    if ($eventFilter !== '') {
        $where[] = "UPPER(event_code) = '" . $connection->real_escape_string($eventFilter) . "'";
    }

    if ($severityFilter === 'error' || $severityFilter === 'warning') {
        $where[] = "severity = '" . $connection->real_escape_string($severityFilter) . "'";
    } else {
        $severityFilter = 'all';
    }

    if ($providerFilter !== '') {
        if (cvAccessoIsAdmin($state) || isset($allowedProviderCodes[$providerFilter])) {
            $where[] = "LOWER(provider_code) = '" . $connection->real_escape_string($providerFilter) . "'";
        } else {
            $providerFilter = '';
        }
    }

    if ($actionFilter !== '') {
        $where[] = "LOWER(action_name) = '" . $connection->real_escape_string($actionFilter) . "'";
    }

    if ($searchFilter !== '') {
        $escaped = $connection->real_escape_string($searchFilter);
        $where[] = "(message LIKE '%" . $escaped . "%' OR order_code LIKE '%" . $escaped . "%' OR shop_id LIKE '%" . $escaped . "%' OR request_id LIKE '%" . $escaped . "%')";
    }

    if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
        $where[] = "DATE(created_at) >= '" . $connection->real_escape_string($dateFrom) . "'";
    } else {
        $dateFrom = '';
    }

    if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        $where[] = "DATE(created_at) <= '" . $connection->real_escape_string($dateTo) . "'";
    } else {
        $dateTo = '';
    }

    $activeFiltersCount += $sourceFilter !== '' ? 1 : 0;
    $activeFiltersCount += $eventFilter !== '' ? 1 : 0;
    $activeFiltersCount += $severityFilter !== 'all' ? 1 : 0;
    $activeFiltersCount += $providerFilter !== '' ? 1 : 0;
    $activeFiltersCount += $actionFilter !== '' ? 1 : 0;
    $activeFiltersCount += $searchFilter !== '' ? 1 : 0;
    $activeFiltersCount += $dateFrom !== '' ? 1 : 0;
    $activeFiltersCount += $dateTo !== '' ? 1 : 0;

    $whereSql = implode(' AND ', $where);
    $countResult = $connection->query("SELECT COUNT(*) AS cnt FROM cv_error_log WHERE {$whereSql}");
    if ($countResult instanceof mysqli_result) {
        $countRow = $countResult->fetch_assoc();
        $totalRows = is_array($countRow) ? (int) ($countRow['cnt'] ?? 0) : 0;
        $countResult->free();
    }

    $totalPages = max(1, (int) ceil($totalRows / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;

    $sql = "SELECT
                id_error_log,
                source,
                event_code,
                severity,
                message,
                provider_code,
                request_id,
                action_name,
                order_code,
                shop_id,
                context_json,
                created_at
            FROM cv_error_log
            WHERE {$whereSql}
            ORDER BY id_error_log DESC
            LIMIT {$offset}, {$perPage}";
    $result = $connection->query($sql);
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            if (!is_array($row)) {
                continue;
            }
            $logs[] = $row;
        }
        $result->free();
    }
} catch (Throwable $exception) {
    $state['errors'][] = 'Errore sezione error log: ' . $exception->getMessage();
}

$queryParamsBase = $_GET;
unset($queryParamsBase['page']);

cvAccessoRenderPageStart('Error log', 'error-log', $state);
?>
<div class="row">
    <div class="col-md-12">
        <p class="cv-page-intro">
            Registro errori operativo (checkout, auth, soluzioni). I log vengono mantenuti automaticamente con retention periodica.
        </p>
    </div>
</div>

<div class="row">
    <div class="col-md-12">
        <div class="cv-panel-card">
            <div class="cv-panel-head">
                <div>
                    <h4>Filtri</h4>
                    <div class="cv-muted">
                        <?= $activeFiltersCount > 0 ? $activeFiltersCount . ' filtri attivi' : 'Nessun filtro attivo' ?>
                    </div>
                </div>
                <button
                    type="button"
                    class="btn btn-outline btn-primary cv-filter-trigger"
                    data-cv-drawer-toggle="error-log-filter-drawer"
                    aria-controls="error-log-filter-drawer"
                    aria-expanded="false"
                >
                    <i class="fa fa-cog"></i>
                </button>
            </div>
        </div>
    </div>
</div>

<div class="cv-side-drawer-backdrop" data-cv-drawer-close="error-log-filter-drawer"></div>
<aside id="error-log-filter-drawer" class="cv-side-drawer" aria-hidden="true">
    <div class="cv-side-drawer-head">
        <h4>Filtri error log</h4>
        <button type="button" class="btn btn-default btn-sm" data-cv-drawer-close="error-log-filter-drawer">
            <i class="fa fa-times"></i>
        </button>
    </div>

    <form method="get" class="cv-form-grid">
        <div class="form-group">
            <label for="f_source">Source</label>
            <input id="f_source" type="text" name="source" value="<?= cvAccessoH($sourceFilter) ?>" class="form-control" placeholder="checkout_api, auth_api...">
        </div>
        <div class="form-group">
            <label for="f_event_code">Event code</label>
            <input id="f_event_code" type="text" name="event_code" value="<?= cvAccessoH($eventFilter) ?>" class="form-control" placeholder="CHECKOUT_ERROR...">
        </div>
        <div class="form-group">
            <label for="f_severity">Severity</label>
            <select id="f_severity" name="severity" class="form-control">
                <option value="all"<?= $severityFilter === 'all' ? ' selected' : '' ?>>Tutte</option>
                <option value="error"<?= $severityFilter === 'error' ? ' selected' : '' ?>>Error</option>
                <option value="warning"<?= $severityFilter === 'warning' ? ' selected' : '' ?>>Warning</option>
            </select>
        </div>
        <div class="form-group">
            <label for="f_provider">Provider</label>
            <select id="f_provider" name="provider" class="form-control">
                <option value="">Tutti</option>
                <?php foreach ($providers as $provider): ?>
                    <?php $code = strtolower(trim((string) ($provider['code'] ?? ''))); ?>
                    <?php if ($code === '') { continue; } ?>
                    <option value="<?= cvAccessoH($code) ?>"<?= $providerFilter === $code ? ' selected' : '' ?>>
                        <?= cvAccessoH((string) ($provider['name'] ?? $code)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="f_action_name">Action</label>
            <input id="f_action_name" type="text" name="action_name" value="<?= cvAccessoH($actionFilter) ?>" class="form-control" placeholder="paypal_capture...">
        </div>
        <div class="form-group">
            <label for="f_q">Testo</label>
            <input id="f_q" type="text" name="q" value="<?= cvAccessoH($searchFilter) ?>" class="form-control" placeholder="messaggio, ordine, shop, request">
        </div>
        <div class="form-group">
            <label for="f_from">Dal</label>
            <input id="f_from" type="date" name="from" value="<?= cvAccessoH($dateFrom) ?>" class="form-control">
        </div>
        <div class="form-group">
            <label for="f_to">Al</label>
            <input id="f_to" type="date" name="to" value="<?= cvAccessoH($dateTo) ?>" class="form-control">
        </div>
        <div class="cv-inline-actions">
            <button type="submit" class="btn btn-primary">Applica filtri</button>
            <a href="<?= cvAccessoH(cvAccessoUrl('error-log.php')) ?>" class="btn btn-default">Reset</a>
        </div>
    </form>

    <?php if (cvAccessoIsAdmin($state)): ?>
        <hr>
        <form method="post" class="cv-form-grid">
            <?= cvAccessoCsrfField() ?>
            <input type="hidden" name="action" value="purge_old">
            <div class="form-group">
                <label for="f_retention_days">Pulisci log più vecchi di (giorni)</label>
                <input id="f_retention_days" type="number" name="retention_days" min="7" max="365" value="30" class="form-control">
            </div>
            <div class="cv-inline-actions">
                <button type="submit" class="btn btn-danger">Esegui purge log</button>
            </div>
        </form>
    <?php endif; ?>
</aside>

<div class="row">
    <div class="col-md-12">
        <div class="cv-panel-card">
            <h4>Error log (<?= (int) $totalRows ?>)</h4>
            <div class="table-responsive" style="margin-top:12px;">
                <table class="table table-striped table-bordered">
                    <thead>
                    <tr>
                        <th>Data</th>
                        <th>Source / Event</th>
                        <th>Severity</th>
                        <th>Provider</th>
                        <th>Messaggio</th>
                        <th>Riferimenti</th>
                        <th>Context</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (count($logs) === 0): ?>
                        <tr>
                            <td colspan="7" class="text-center cv-muted">Nessun log trovato con i filtri selezionati.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($logs as $row): ?>
                            <?php
                            $severity = strtolower(trim((string) ($row['severity'] ?? 'error')));
                            $labelClass = $severity === 'warning' ? 'label-warning' : 'label-danger';
                            $contextRaw = (string) ($row['context_json'] ?? '');
                            $contextPretty = '';
                            if ($contextRaw !== '') {
                                $decoded = json_decode($contextRaw, true);
                                if (is_array($decoded)) {
                                    $encodedPretty = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                                    $contextPretty = is_string($encodedPretty) ? $encodedPretty : $contextRaw;
                                } else {
                                    $contextPretty = $contextRaw;
                                }
                            }
                            ?>
                            <tr>
                                <td>
                                    <strong><?= cvAccessoH((string) ($row['created_at'] ?? '-')) ?></strong>
                                    <div class="cv-muted" style="font-size:11px;">ID: <?= (int) ($row['id_error_log'] ?? 0) ?></div>
                                </td>
                                <td>
                                    <div><strong><?= cvAccessoH((string) ($row['source'] ?? '-')) ?></strong></div>
                                    <div class="cv-muted" style="font-size:11px;"><?= cvAccessoH((string) ($row['event_code'] ?? '-')) ?></div>
                                </td>
                                <td>
                                    <span class="label <?= cvAccessoH($labelClass) ?>"><?= cvAccessoH(strtoupper($severity)) ?></span>
                                </td>
                                <td><?= cvAccessoH((string) ($row['provider_code'] ?? '-')) ?></td>
                                <td><?= cvAccessoH((string) ($row['message'] ?? '-')) ?></td>
                                <td>
                                    <div>Action: <strong><?= cvAccessoH((string) ($row['action_name'] ?? '-')) ?></strong></div>
                                    <div class="cv-muted" style="font-size:11px;">Order: <?= cvAccessoH((string) ($row['order_code'] ?? '-')) ?></div>
                                    <div class="cv-muted" style="font-size:11px;">Shop: <?= cvAccessoH((string) ($row['shop_id'] ?? '-')) ?></div>
                                    <div class="cv-muted" style="font-size:11px;">Request: <?= cvAccessoH((string) ($row['request_id'] ?? '-')) ?></div>
                                </td>
                                <td style="max-width:360px;">
                                    <?php if ($contextPretty !== ''): ?>
                                        <details>
                                            <summary>Apri</summary>
                                            <pre class="cv-pre" style="max-height:260px;overflow:auto;"><?= cvAccessoH($contextPretty) ?></pre>
                                        </details>
                                    <?php else: ?>
                                        <span class="cv-muted">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPages > 1): ?>
                <nav aria-label="Error log pagination" style="margin-top:12px;">
                    <ul class="pagination" style="margin:0;">
                        <?php
                        $prevPage = max(1, $page - 1);
                        $nextPage = min($totalPages, $page + 1);
                        $prevQs = http_build_query(array_merge($queryParamsBase, ['page' => $prevPage]));
                        $nextQs = http_build_query(array_merge($queryParamsBase, ['page' => $nextPage]));
                        ?>
                        <li class="<?= $page <= 1 ? 'disabled' : '' ?>">
                            <a href="<?= $page <= 1 ? '#' : cvAccessoH(cvAccessoUrl('error-log.php') . '?' . $prevQs) ?>" aria-label="Previous">
                                <span aria-hidden="true">&laquo;</span>
                            </a>
                        </li>
                        <li class="active"><a href="#"><?= (int) $page ?> / <?= (int) $totalPages ?></a></li>
                        <li class="<?= $page >= $totalPages ? 'disabled' : '' ?>">
                            <a href="<?= $page >= $totalPages ? '#' : cvAccessoH(cvAccessoUrl('error-log.php') . '?' . $nextQs) ?>" aria-label="Next">
                                <span aria-hidden="true">&raquo;</span>
                            </a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
cvAccessoRenderPageEnd();
