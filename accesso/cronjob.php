<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../includes/runtime_settings.php';
require_once __DIR__ . '/../includes/pathfind.php';
require_once __DIR__ . '/../includes/pathfind_warmup_tools.php';

$state = cvAccessoInit();
$isAjaxStatus = trim((string) ($_GET['ajax'] ?? '')) === 'warmup_status';

/**
 * @param array<int,array<string,mixed>> $jobs
 * @return array<int,array<string,mixed>>
 */
function cvCronjobNormalizeJobs(array $jobs): array
{
    $rows = [];
    foreach ($jobs as $job) {
        if (!is_array($job)) {
            continue;
        }
        $rows[] = [
            'id_warmup' => (int) ($job['id_warmup'] ?? 0),
            'from_ref' => (string) ($job['from_ref'] ?? ''),
            'to_ref' => (string) ($job['to_ref'] ?? ''),
            'travel_date_it' => (string) ($job['travel_date_it'] ?? ''),
            'adults' => (int) ($job['adults'] ?? 0),
            'children' => (int) ($job['children'] ?? 0),
            'max_transfers' => (int) ($job['max_transfers'] ?? 0),
            'priority' => (int) ($job['priority'] ?? 0),
            'source' => (string) ($job['source'] ?? ''),
            'status' => (string) ($job['status'] ?? ''),
            'attempt_count' => (int) ($job['attempt_count'] ?? 0),
            'last_error' => (string) ($job['last_error'] ?? ''),
            'next_run_at' => (string) ($job['next_run_at'] ?? ''),
            'started_at' => (string) ($job['started_at'] ?? ''),
            'finished_at' => (string) ($job['finished_at'] ?? ''),
            'created_at' => (string) ($job['created_at'] ?? ''),
            'updated_at' => (string) ($job['updated_at'] ?? ''),
        ];
    }

    return $rows;
}

if (!$state['authenticated']) {
    if ($isAjaxStatus) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Autenticazione richiesta.']);
        return;
    }
    cvAccessoRenderLoginPage($state);
    return;
}

if (!cvAccessoIsAdmin($state)) {
    if ($isAjaxStatus) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Accesso negato.']);
        return;
    }
    http_response_code(403);
    cvAccessoRenderPageStart('Cronjob', 'settings-cronjob', $state);
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

$warmupQueueStats = [
    'pending' => 0,
    'running' => 0,
    'done' => 0,
    'error' => 0,
    'total' => 0,
];
$warmupRecentJobs = [];

try {
    $connection = cvAccessoRequireConnection();
    cvPathfindWarmupEnsureTable($connection);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = trim((string) ($_POST['action'] ?? ''));
        if (!cvAccessoValidateCsrf()) {
            $state['errors'][] = 'Sessione non valida. Ricarica la pagina.';
        } elseif ($action === 'enqueue_pathfind_warmup') {
            $routesLimit = max(1, min(300, (int) ($_POST['warmup_routes_limit'] ?? 50)));
            $daysAhead = max(0, min(30, (int) ($_POST['warmup_days_ahead'] ?? 3)));
            $adults = max(0, (int) ($_POST['warmup_adults'] ?? 1));
            $children = max(0, (int) ($_POST['warmup_children'] ?? 0));
            if (($adults + $children) <= 0) {
                $adults = 1;
            }
            $priority = max(0, min(10000, (int) ($_POST['warmup_priority'] ?? 100)));

            $runtimeSettings = cvRuntimeSettings($connection);
            $maxTransfers = max(0, min(3, (int) ($runtimeSettings['pathfind_max_transfers'] ?? 2)));

            $enqueue = cvPathfindWarmupEnqueueFromTopRoutes(
                $connection,
                $routesLimit,
                $daysAhead,
                $adults,
                $children,
                $maxTransfers,
                $priority,
                'manual_maintenance'
            );

            $state['messages'][] = 'Warmup pathfind in coda: '
                . (int) ($enqueue['jobs_enqueued'] ?? 0)
                . ' job (richiesti ' . (int) ($enqueue['jobs_requested'] ?? 0)
                . ', tratte analizzate ' . (int) ($enqueue['routes_scanned'] ?? 0)
                . ', giorni per tratta ' . (int) ($enqueue['dates_per_route'] ?? 0) . ').';
        }
    }

    $warmupQueueStats = cvPathfindWarmupQueueStats($connection);
    $warmupRecentJobs = cvCronjobNormalizeJobs(cvPathfindWarmupRecentJobs($connection, 25));

    if ($isAjaxStatus) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => true,
            'stats' => $warmupQueueStats,
            'jobs' => $warmupRecentJobs,
            'server_time' => date('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return;
    }
} catch (Throwable $exception) {
    if ($isAjaxStatus) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => $exception->getMessage(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return;
    }
    $state['errors'][] = 'Errore sezione cronjob: ' . $exception->getMessage();
}

cvAccessoRenderPageStart('Cronjob', 'settings-cronjob', $state);
?>
<div class="row">
    <div class="col-md-12">
        <p class="cv-page-intro">
            Pianificazione e preriscaldamento cache pathfind. Usa questa sezione per preparare job batch senza impattare il frontend.
        </p>
    </div>
</div>

<div class="row">
    <div class="col-md-8">
        <div class="cv-panel-card">
            <h4>Warmup pathfind</h4>
            <p class="cv-muted">Precarica la cache delle tratte piu richieste con giorni futuri selezionati.</p>
            <p class="cv-muted">
                Coda: pending <strong id="warmupStatPending"><?= (int) ($warmupQueueStats['pending'] ?? 0) ?></strong> ·
                running <strong id="warmupStatRunning"><?= (int) ($warmupQueueStats['running'] ?? 0) ?></strong> ·
                done <strong id="warmupStatDone"><?= (int) ($warmupQueueStats['done'] ?? 0) ?></strong> ·
                error <strong id="warmupStatError"><?= (int) ($warmupQueueStats['error'] ?? 0) ?></strong>
            </p>
            <p class="cv-muted">Ultimo aggiornamento monitor: <strong id="warmupMonitorTime"><?= cvAccessoH(date('Y-m-d H:i:s')) ?></strong></p>
            <form method="post">
                <input type="hidden" name="action" value="enqueue_pathfind_warmup">
                <?= cvAccessoCsrfField() ?>
                <div class="row">
                    <div class="col-md-6 form-group">
                        <label for="warmup_routes_limit">Top tratte</label>
                        <input id="warmup_routes_limit" name="warmup_routes_limit" type="number" min="1" max="300" step="1" class="form-control" value="50">
                    </div>
                    <div class="col-md-6 form-group">
                        <label for="warmup_days_ahead">Giorni futuri</label>
                        <input id="warmup_days_ahead" name="warmup_days_ahead" type="number" min="0" max="30" step="1" class="form-control" value="3">
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-4 form-group">
                        <label for="warmup_adults">Adulti</label>
                        <input id="warmup_adults" name="warmup_adults" type="number" min="0" max="9" step="1" class="form-control" value="1">
                    </div>
                    <div class="col-md-4 form-group">
                        <label for="warmup_children">Bambini</label>
                        <input id="warmup_children" name="warmup_children" type="number" min="0" max="9" step="1" class="form-control" value="0">
                    </div>
                    <div class="col-md-4 form-group">
                        <label for="warmup_priority">Priorita</label>
                        <input id="warmup_priority" name="warmup_priority" type="number" min="0" max="10000" step="10" class="form-control" value="100">
                    </div>
                </div>
                <div class="cv-inline-actions">
                    <button type="submit" class="btn btn-primary">Metti in coda warmup</button>
                    <a class="btn btn-default" href="<?= cvAccessoH(cvAccessoUrl('manutenzione.php')) ?>">Apri Manutenzione</a>
                </div>
            </form>
            <hr>
            <h5>Ultimi job coda</h5>
            <div class="table-responsive">
                <table class="table table-striped table-bordered table-condensed" id="warmupJobsTable">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Stato</th>
                            <th>Tratta</th>
                            <th>Data</th>
                            <th>Pax</th>
                            <th>Tent.</th>
                            <th>Aggiornato</th>
                            <th>Errore</th>
                        </tr>
                    </thead>
                    <tbody id="warmupJobsBody">
                        <?php foreach ($warmupRecentJobs as $job): ?>
                            <tr>
                                <td><?= (int) ($job['id_warmup'] ?? 0) ?></td>
                                <td><?= cvAccessoH((string) ($job['status'] ?? '')) ?></td>
                                <td><?= cvAccessoH((string) ($job['from_ref'] ?? '')) ?> → <?= cvAccessoH((string) ($job['to_ref'] ?? '')) ?></td>
                                <td><?= cvAccessoH((string) ($job['travel_date_it'] ?? '')) ?></td>
                                <td><?= (int) ($job['adults'] ?? 0) ?>/<?= (int) ($job['children'] ?? 0) ?></td>
                                <td><?= (int) ($job['attempt_count'] ?? 0) ?></td>
                                <td><?= cvAccessoH((string) ($job['updated_at'] ?? '')) ?></td>
                                <td><?= cvAccessoH((string) ($job['last_error'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="cv-panel-card">
            <h4>Cron consigliato</h4>
            <p class="cv-muted">Test veloce ogni 10 minuti:</p>
            <pre class="cv-pre">*/10 * * * * /usr/bin/php /percorso/cercaviaggio/cron_pathfind_warmup.php --max-jobs=25 --sleep-ms=250 --retry-limit=2</pre>
            <p class="cv-muted" style="margin-top:10px;">
                Nota: il percorso deve essere quello reale del server dove gira il cron.
                Il path locale Mac (<code>/Users/...</code>) vale solo in ambiente di prova locale.
            </p>
            <p class="cv-muted">Versione con log su file:</p>
            <pre class="cv-pre">15 2 * * * /usr/bin/php /PERCORSO_REALE_SERVER/cercaviaggio/cron_pathfind_warmup.php --max-jobs=300 --sleep-ms=250 --retry-limit=2 >> /PERCORSO_REALE_SERVER/cercaviaggio/files/logs/pathfind_warmup_cron.log 2&gt;&amp;1
25 2 * * * /usr/bin/php /PERCORSO_REALE_SERVER/cercaviaggio/cron_pathfind_warmup.php --max-jobs=300 --sleep-ms=250 --retry-limit=2 >> /PERCORSO_REALE_SERVER/cercaviaggio/files/logs/pathfind_warmup_cron.log 2&gt;&amp;1
*/20 7-23 * * * /usr/bin/php /PERCORSO_REALE_SERVER/cercaviaggio/cron_pathfind_warmup.php --max-jobs=25 --sleep-ms=300 --retry-limit=1 >> /PERCORSO_REALE_SERVER/cercaviaggio/files/logs/pathfind_warmup_cron.log 2&gt;&amp;1</pre>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var pendingEl = document.getElementById('warmupStatPending');
    var runningEl = document.getElementById('warmupStatRunning');
    var doneEl = document.getElementById('warmupStatDone');
    var errorEl = document.getElementById('warmupStatError');
    var timeEl = document.getElementById('warmupMonitorTime');
    var jobsBodyEl = document.getElementById('warmupJobsBody');

    if (!pendingEl || !runningEl || !doneEl || !errorEl || !timeEl || !jobsBodyEl) {
        return;
    }

    function esc(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function renderJobs(rows) {
        if (!Array.isArray(rows) || rows.length === 0) {
            jobsBodyEl.innerHTML = '<tr><td colspan="8" class="text-center">Nessun job in coda.</td></tr>';
            return;
        }

        var html = '';
        for (var i = 0; i < rows.length; i += 1) {
            var j = rows[i] || {};
            html += '<tr>';
            html += '<td>' + esc(j.id_warmup) + '</td>';
            html += '<td>' + esc(j.status) + '</td>';
            html += '<td>' + esc(j.from_ref) + ' → ' + esc(j.to_ref) + '</td>';
            html += '<td>' + esc(j.travel_date_it) + '</td>';
            html += '<td>' + esc(j.adults) + '/' + esc(j.children) + '</td>';
            html += '<td>' + esc(j.attempt_count) + '</td>';
            html += '<td>' + esc(j.updated_at) + '</td>';
            html += '<td>' + esc(j.last_error) + '</td>';
            html += '</tr>';
        }
        jobsBodyEl.innerHTML = html;
    }

    function refreshStatus() {
        fetch('./cronjob.php?ajax=warmup_status', {
            method: 'GET',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        }).then(function (response) {
            return response.json().catch(function () { return { success: false }; });
        }).then(function (data) {
            if (!data || !data.success) {
                return;
            }

            var stats = data.stats || {};
            pendingEl.textContent = String(stats.pending || 0);
            runningEl.textContent = String(stats.running || 0);
            doneEl.textContent = String(stats.done || 0);
            errorEl.textContent = String(stats.error || 0);
            timeEl.textContent = String(data.server_time || '');
            renderJobs(data.jobs || []);
        }).catch(function () {
            // no-op
        });
    }

    refreshStatus();
    window.setInterval(refreshStatus, 8000);
});
</script>
<?php
cvAccessoRenderPageEnd();
?>
