<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../includes/place_tools.php';
require_once __DIR__ . '/../includes/assistant_tools.php';

$state = cvAccessoInit();
if (!$state['authenticated']) {
    cvAccessoRenderLoginPage($state);
    return;
}

if (!cvAccessoIsAdmin($state)) {
    http_response_code(403);
    cvAccessoRenderPageStart('Manutenzione', 'maintenance', $state);
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

$cacheBuckets = cvCacheDirectoryMap();
$actionResults = [];
$stats = [
    'tracked_routes' => 0,
    'search_volume' => 0,
    'active_places' => 0,
    'assistant_conversations' => 0,
];
$cacheCounts = cvAccessoCountCacheFiles(null);

try {
    $connection = cvAccessoRequireConnection();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = trim((string) ($_POST['action'] ?? ''));
        if (!cvAccessoValidateCsrf()) {
            $state['errors'][] = 'Sessione non valida. Ricarica la pagina.';
        } elseif ($action === 'purge_cache') {
            $selectedBuckets = isset($_POST['buckets']) && is_array($_POST['buckets']) ? $_POST['buckets'] : [];
            $selectedBuckets = array_values(array_filter(
                $selectedBuckets,
                static fn(string $bucket): bool => isset($cacheBuckets[$bucket])
            ));
            if (count($selectedBuckets) === 0) {
                $state['errors'][] = 'Seleziona almeno un bucket cache.';
            } else {
                $result = cvCachePurgeBuckets($selectedBuckets, null);
                $actionResults[] = [
                    'title' => 'Cache purge',
                    'data' => [
                        'buckets' => $selectedBuckets,
                        'result' => $result,
                    ],
                ];
                $state['messages'][] = 'Pulizia cache completata.';
                $cacheCounts = cvAccessoCountCacheFiles(null);
            }
        } elseif ($action === 'cleanup_stale_place_stats') {
            $statsTableExistsResult = $connection->query("SHOW TABLES LIKE 'cv_search_route_stats'");
            $statsTableExists = $statsTableExistsResult instanceof mysqli_result && $statsTableExistsResult->num_rows > 0;
            if ($statsTableExistsResult instanceof mysqli_result) {
                $statsTableExistsResult->free();
            }

            if (!$statsTableExists) {
                $state['errors'][] = 'Tabella cv_search_route_stats non disponibile.';
            } else {
                $sql = <<<SQL
DELETE s
FROM cv_search_route_stats s
WHERE
    (
        s.from_provider_code = 'place'
        AND NOT EXISTS (
            SELECT 1
            FROM cv_places p
            WHERE p.id_place = CAST(s.from_stop_external_id AS UNSIGNED)
              AND p.is_active = 1
        )
    )
    OR
    (
        s.to_provider_code = 'place'
        AND NOT EXISTS (
            SELECT 1
            FROM cv_places p
            WHERE p.id_place = CAST(s.to_stop_external_id AS UNSIGNED)
              AND p.is_active = 1
        )
    )
SQL;
                $ok = $connection->query($sql);
                if ($ok === true) {
                    $state['messages'][] = 'Pulizia località obsolete completata: ' . (int) $connection->affected_rows . ' rotta/e rimossa/e.';
                } else {
                    $state['errors'][] = 'Impossibile completare la pulizia località obsolete.';
                }
            }
        } elseif ($action === 'reset_route_stats') {
            $statsTableExistsResult = $connection->query("SHOW TABLES LIKE 'cv_search_route_stats'");
            $statsTableExists = $statsTableExistsResult instanceof mysqli_result && $statsTableExistsResult->num_rows > 0;
            if ($statsTableExistsResult instanceof mysqli_result) {
                $statsTableExistsResult->free();
            }

            if (!$statsTableExists) {
                $state['errors'][] = 'Tabella cv_search_route_stats non disponibile.';
            } else {
                $ok = $connection->query('TRUNCATE TABLE cv_search_route_stats');
                if ($ok === true) {
                    $state['messages'][] = 'Contatore richieste azzerato.';
                } else {
                    $state['errors'][] = 'Impossibile azzerare il contatore richieste.';
                }
            }
        } elseif ($action === 'cleanup_assistant_conversations') {
            $beforeDate = trim((string) ($_POST['assistant_before_date'] ?? ''));
            $cleanup = cvAssistantDeleteConversationsBefore($connection, $beforeDate);
            $state['messages'][] = 'Pulizia assistente completata. Conversazioni: ' . (int) ($cleanup['conversations_deleted'] ?? 0)
                . ', messaggi: ' . (int) ($cleanup['messages_deleted'] ?? 0)
                . ', feedback: ' . (int) ($cleanup['feedback_deleted'] ?? 0)
                . ', ticket assistenza: ' . (int) ($cleanup['support_tickets_deleted'] ?? 0)
                . ', messaggi assistenza: ' . (int) ($cleanup['support_messages_deleted'] ?? 0) . '.';
        }
    }

    $routeStatsResult = $connection->query(
        "SELECT COUNT(*) AS tracked_routes, COALESCE(SUM(search_count), 0) AS search_volume
         FROM cv_search_route_stats"
    );
    if ($routeStatsResult instanceof mysqli_result) {
        $row = $routeStatsResult->fetch_assoc();
        if (is_array($row)) {
            $stats['tracked_routes'] = (int) ($row['tracked_routes'] ?? 0);
            $stats['search_volume'] = (int) ($row['search_volume'] ?? 0);
        }
        $routeStatsResult->free();
    }

    if (cvPlacesTablesExist($connection)) {
        $stats['active_places'] = cvPlacesCountActiveEntries($connection);
    }

    $assistantStats = cvAssistantStats($connection);
    $stats['assistant_conversations'] = (int) ($assistantStats['conversations_total'] ?? 0);
} catch (Throwable $exception) {
    $state['errors'][] = 'Errore sezione manutenzione: ' . $exception->getMessage();
}

cvAccessoRenderPageStart('Manutenzione', 'maintenance', $state);
?>
<div class="row">
    <div class="col-md-12">
        <p class="cv-page-intro">
            Area tecnica per operazioni di pulizia. Qui centralizzi cache, località obsolete e reset contatori.
        </p>
    </div>
</div>

<div class="row">
    <div class="col-sm-4">
        <div class="cv-stat-card">
            <div class="cv-stat-value"><?= (int) $stats['tracked_routes'] ?></div>
            <div class="cv-stat-label">Rotte tracciate</div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="cv-stat-card">
            <div class="cv-stat-value"><?= (int) $stats['search_volume'] ?></div>
            <div class="cv-stat-label">Volume ricerche</div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="cv-stat-card">
            <div class="cv-stat-value"><?= (int) $stats['active_places'] ?></div>
            <div class="cv-stat-label">Località attive</div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="cv-stat-card">
            <div class="cv-stat-value"><?= (int) $stats['assistant_conversations'] ?></div>
            <div class="cv-stat-label">Conversazioni assistente</div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-7">
        <div class="cv-panel-card">
            <h4>Pulizia cache</h4>
            <p class="cv-muted">Cancella i bucket selezionati. Operazione globale su tutti i provider.</p>
            <form method="post">
                <input type="hidden" name="action" value="purge_cache">
                <?= cvAccessoCsrfField() ?>
                <div class="cv-checklist">
                    <?php foreach ($cacheBuckets as $bucketKey => $bucket): ?>
                        <?php $checkboxId = 'bucket_maint_' . preg_replace('/[^a-z0-9_\\-]/i', '_', (string) $bucketKey); ?>
                        <div class="checkbox">
                            <input
                                id="<?= cvAccessoH($checkboxId) ?>"
                                type="checkbox"
                                name="buckets[]"
                                value="<?= cvAccessoH($bucketKey) ?>"
                                checked
                            >
                            <label for="<?= cvAccessoH($checkboxId) ?>">
                                <?= cvAccessoH($bucketKey) ?> · <?= cvAccessoH((string) ($bucket['label'] ?? '')) ?>
                                (file: <?= (int) ($cacheCounts[$bucketKey] ?? 0) ?>)
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button type="submit" class="btn btn-danger">Esegui pulizia cache</button>
            </form>
        </div>
    </div>
    <div class="col-md-5">
        <div class="cv-panel-card">
            <h4>Località e contatori</h4>
            <p class="cv-muted">Usa queste azioni dopo rigenerazioni macroaree importanti.</p>
            <form method="post" style="margin-bottom:8px;">
                <input type="hidden" name="action" value="cleanup_stale_place_stats">
                <?= cvAccessoCsrfField() ?>
                <button type="submit" class="btn btn-default">Pulisci località obsolete da richieste</button>
            </form>
            <form method="post" onsubmit="return confirm('Azzerare tutto lo storico richieste?');">
                <input type="hidden" name="action" value="reset_route_stats">
                <?= cvAccessoCsrfField() ?>
                <button type="submit" class="btn btn-danger">Azzera contatore richieste</button>
            </form>
            <div class="cv-inline-actions" style="margin-top:12px;">
                <a class="btn btn-default" href="<?= cvAccessoH(cvAccessoUrl('places.php')) ?>">Vai a Macroaree</a>
                <a class="btn btn-default" href="<?= cvAccessoH(cvAccessoUrl('cache.php')) ?>">Vai a Cache avanzata</a>
            </div>
        </div>

        <div class="cv-panel-card">
            <h4>Assistente</h4>
            <p class="cv-muted">Pulizia veloce delle conversazioni vecchie della chat pubblica.</p>
            <form method="post" onsubmit="return confirm('Eliminare definitivamente le conversazioni assistente precedenti alla data indicata?');">
                <input type="hidden" name="action" value="cleanup_assistant_conversations">
                <?= cvAccessoCsrfField() ?>
                <div class="form-group">
                    <label for="assistant_before_date">Data limite</label>
                    <input id="assistant_before_date" name="assistant_before_date" type="date" class="form-control" value="<?= cvAccessoH(date('Y-m-d')) ?>">
                </div>
                <div class="cv-inline-actions">
                    <button type="submit" class="btn btn-danger">Pulisci conversazioni assistente</button>
                    <a class="btn btn-default" href="<?= cvAccessoH(cvAccessoUrl('assistant.php')) ?>">Apri Assistente</a>
                </div>
            </form>
        </div>

    </div>
</div>

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
