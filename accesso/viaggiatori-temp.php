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
    cvAccessoRenderPageStart('Pulizia viaggiatori temp', 'maintenance-travelers-temp', $state);
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

$dateFrom = trim((string) ($_GET['from'] ?? ''));
$dateTo = trim((string) ($_GET['to'] ?? ''));
$includeLinked = !empty($_GET['include_linked']);

$stats = [
    'total' => 0,
    'candidate' => 0,
    'created_min' => null,
    'created_max' => null,
];

function cvAccessoFormatDateOnly(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return '-';
    }
    try {
        $dt = new DateTimeImmutable($value);
        return $dt->format('d/m/Y');
    } catch (Throwable $e) {
        return '-';
    }
}

try {
    $connection = cvAccessoRequireConnection();

    $where = [];
    $params = [];
    $types = '';

    if ($dateFrom !== '' && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $dateFrom)) {
        $where[] = 'DATE(vt.created_at) >= ?';
        $params[] = $dateFrom;
        $types .= 's';
    } else {
        $dateFrom = '';
    }

    if ($dateTo !== '' && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $dateTo)) {
        $where[] = 'DATE(vt.created_at) <= ?';
        $params[] = $dateTo;
        $types .= 's';
    } else {
        $dateTo = '';
    }

    $whereSql = count($where) > 0 ? ('WHERE ' . implode(' AND ', $where)) : '';

    $totalSql = "SELECT COUNT(*) AS total,
                        MIN(vt.created_at) AS created_min,
                        MAX(vt.created_at) AS created_max
                 FROM viaggiatori_temp vt
                 {$whereSql}";
    $totalStmt = $connection->prepare($totalSql);
    if (!$totalStmt instanceof mysqli_stmt) {
        throw new RuntimeException('Prepare stats fallita.');
    }
    if ($types !== '') {
        $totalStmt->bind_param($types, ...$params);
    }
    if (!$totalStmt->execute()) {
        $totalStmt->close();
        throw new RuntimeException('Query stats fallita.');
    }
    $totalRes = $totalStmt->get_result();
    if ($totalRes instanceof mysqli_result) {
        $row = $totalRes->fetch_assoc();
        if (is_array($row)) {
            $stats['total'] = (int) ($row['total'] ?? 0);
            $stats['created_min'] = $row['created_min'] ?? null;
            $stats['created_max'] = $row['created_max'] ?? null;
        }
        $totalRes->free();
    }
    $totalStmt->close();

    $candidateWhere = $where;
    if (!$includeLinked) {
        $candidateWhere[] = 'b.id_vgt IS NULL';
    }
    $candidateWhereSql = count($candidateWhere) > 0 ? ('WHERE ' . implode(' AND ', $candidateWhere)) : '';
    $candidateSql = "SELECT COUNT(*) AS candidate
                     FROM viaggiatori_temp vt
                     LEFT JOIN biglietti b ON b.id_vgt = vt.id_vgt
                     {$candidateWhereSql}";
    $candidateStmt = $connection->prepare($candidateSql);
    if (!$candidateStmt instanceof mysqli_stmt) {
        throw new RuntimeException('Prepare conteggio fallita.');
    }
    if ($types !== '') {
        $candidateStmt->bind_param($types, ...$params);
    }
    if (!$candidateStmt->execute()) {
        $candidateStmt->close();
        throw new RuntimeException('Query conteggio fallita.');
    }
    $candRes = $candidateStmt->get_result();
    if ($candRes instanceof mysqli_result) {
        $row = $candRes->fetch_assoc();
        if (is_array($row)) {
            $stats['candidate'] = (int) ($row['candidate'] ?? 0);
        }
        $candRes->free();
    }
    $candidateStmt->close();

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ((string) ($_POST['action'] ?? '')) === 'delete') {
        if (!cvAccessoValidateCsrf()) {
            $state['errors'][] = 'Sessione non valida. Ricarica la pagina.';
        } else {
            $postFrom = trim((string) ($_POST['from'] ?? ''));
            $postTo = trim((string) ($_POST['to'] ?? ''));
            $postIncludeLinked = !empty($_POST['include_linked']);

            $deleteWhere = [];
            $deleteParams = [];
            $deleteTypes = '';

            if ($postFrom !== '' && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $postFrom)) {
                $deleteWhere[] = 'DATE(vt.created_at) >= ?';
                $deleteParams[] = $postFrom;
                $deleteTypes .= 's';
            }

            if ($postTo !== '' && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $postTo)) {
                $deleteWhere[] = 'DATE(vt.created_at) <= ?';
                $deleteParams[] = $postTo;
                $deleteTypes .= 's';
            }

            if (!$postIncludeLinked) {
                $deleteWhere[] = 'b.id_vgt IS NULL';
            }

            if ($postFrom === '' && $postTo === '') {
                $state['errors'][] = 'Imposta almeno una data (Da/A) prima di eseguire la cancellazione.';
            } else {
                $deleteWhereSql = 'WHERE ' . implode(' AND ', $deleteWhere);
                $deleteSql = "DELETE vt
                              FROM viaggiatori_temp vt
                              LEFT JOIN biglietti b ON b.id_vgt = vt.id_vgt
                              {$deleteWhereSql}";
                $deleteStmt = $connection->prepare($deleteSql);
                if (!$deleteStmt instanceof mysqli_stmt) {
                    throw new RuntimeException('Prepare delete fallita.');
                }
                if ($deleteTypes !== '') {
                    $deleteStmt->bind_param($deleteTypes, ...$deleteParams);
                }
                if (!$deleteStmt->execute()) {
                    $err = $deleteStmt->error;
                    $deleteStmt->close();
                    throw new RuntimeException('Delete fallita: ' . $err);
                }
                $deleted = (int) $deleteStmt->affected_rows;
                $deleteStmt->close();

                $state['messages'][] = 'Pulizia completata: ' . $deleted . ' record rimossi.';
                $includeLinked = $postIncludeLinked;
                $dateFrom = $postFrom;
                $dateTo = $postTo;

                $redirectQuery = http_build_query([
                    'from' => $dateFrom,
                    'to' => $dateTo,
                    'include_linked' => $includeLinked ? '1' : '0',
                ]);
                header('Location: ' . cvAccessoUrl('viaggiatori-temp.php') . ($redirectQuery !== '' ? ('?' . $redirectQuery) : ''));
                return;
            }
        }
    }
} catch (Throwable $exception) {
    $state['errors'][] = 'Errore pulizia viaggiatori_temp: ' . $exception->getMessage();
}

cvAccessoRenderPageStart('Pulizia viaggiatori temp', 'maintenance-travelers-temp', $state);
?>
<div class="row">
    <div class="col-md-12">
        <p class="cv-page-intro">
            Gestione e pulizia della tabella <code>viaggiatori_temp</code>. Per sicurezza, di default elimina solo i record non collegati a biglietti.
        </p>
    </div>
</div>

<div class="row">
    <div class="col-sm-3">
        <div class="cv-stat-card">
            <div class="cv-stat-value"><?= (int) $stats['total'] ?></div>
            <div class="cv-stat-label">Record (filtro data)</div>
        </div>
    </div>
    <div class="col-sm-3">
        <div class="cv-stat-card">
            <div class="cv-stat-value"><?= (int) $stats['candidate'] ?></div>
            <div class="cv-stat-label">Candidati eliminazione</div>
        </div>
    </div>
    <div class="col-sm-3">
        <div class="cv-stat-card">
            <div class="cv-stat-value"><?= cvAccessoH(cvAccessoFormatDateOnly(is_string($stats['created_min']) ? $stats['created_min'] : null)) ?></div>
            <div class="cv-stat-label">Created min</div>
        </div>
    </div>
    <div class="col-sm-3">
        <div class="cv-stat-card">
            <div class="cv-stat-value"><?= cvAccessoH(cvAccessoFormatDateOnly(is_string($stats['created_max']) ? $stats['created_max'] : null)) ?></div>
            <div class="cv-stat-label">Created max</div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-12">
        <div class="cv-panel-card">
            <h4>Filtro</h4>
            <form method="get">
                <div class="row" style="margin-bottom:12px;">
                    <div class="col-sm-6">
                        <div class="form-group" style="margin-bottom:0;">
                            <label for="f_from">Da</label>
                            <input id="f_from" name="from" type="text" class="form-control" value="<?= cvAccessoH($dateFrom) ?>" placeholder="GG/MM/AAAA" autocomplete="off">
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="form-group" style="margin-bottom:0;">
                            <label for="f_to">A</label>
                            <input id="f_to" name="to" type="text" class="form-control" value="<?= cvAccessoH($dateTo) ?>" placeholder="GG/MM/AAAA" autocomplete="off">
                        </div>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom:12px;">
                    <label>
                        <input type="checkbox" name="include_linked" value="1"<?= $includeLinked ? ' checked' : '' ?>>
                        Includi record collegati a biglietti (sconsigliato)
                    </label>
                    <div class="cv-muted">
                        Se elimini record collegati, sui biglietti potresti perdere il nome passeggero (FK con ON DELETE SET NULL).
                    </div>
                </div>
                <div class="cv-inline-actions">
                    <button type="submit" class="btn btn-default">Aggiorna conteggi</button>
                    <a class="btn btn-default" href="<?= cvAccessoH(cvAccessoUrl('viaggiatori-temp.php')) ?>">Reset</a>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-12">
        <div class="cv-panel-card">
            <h4>Elimina</h4>
            <p class="cv-muted">
                Verranno eliminati i record che rispettano il filtro e, se non selezionato diversamente, non risultano collegati a nessun biglietto.
            </p>
            <form method="post" onsubmit="return confirm('Confermi la cancellazione dei record selezionati?');">
                <input type="hidden" name="action" value="delete">
                <?= cvAccessoCsrfField() ?>
                <input type="hidden" name="from" value="<?= cvAccessoH($dateFrom) ?>">
                <input type="hidden" name="to" value="<?= cvAccessoH($dateTo) ?>">
                <input type="hidden" name="include_linked" value="<?= $includeLinked ? '1' : '0' ?>">

                <button type="submit" class="btn btn-danger"<?= $stats['candidate'] <= 0 ? ' disabled' : '' ?>>
                    Elimina (<?= (int) $stats['candidate'] ?>)
                </button>
            </form>
        </div>
    </div>
</div>

<?php
cvAccessoRenderPageEnd();

?>
<script>
(function () {
    'use strict';

    function initDatePicker(inputId) {
        var input = document.getElementById(inputId);
        if (!input || !window.flatpickr) {
            return;
        }

        if (window.flatpickr.l10ns && window.flatpickr.l10ns.it) {
            window.flatpickr.localize(window.flatpickr.l10ns.it);
        }

        window.flatpickr(input, {
            enableTime: false,
            dateFormat: 'Y-m-d',
            altInput: true,
            altFormat: 'd/m/Y',
            allowInput: true,
            disableMobile: true,
            monthSelectorType: 'static',
            prevArrow: '<i class="fa fa-angle-left"></i>',
            nextArrow: '<i class="fa fa-angle-right"></i>',
            onReady: function (selectedDates, dateStr, instance) {
                if (instance && instance.calendarContainer) {
                    instance.calendarContainer.classList.add('cv-flatpickr-popup');
                }
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initDatePicker('f_from');
        initDatePicker('f_to');
    });
}());
</script>
