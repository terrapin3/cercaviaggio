<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';

$state = cvAccessoInit();
if (!$state['authenticated']) {
    cvAccessoRenderLoginPage($state);
    return;
}

if (!cvAccessoIsAdmin($state)) {
    cvAccessoRenderPageStart('Statistiche provider', 'statistics-provider', $state);
    ?>
    <div class="row">
        <div class="col-md-12">
            <div class="alert alert-warning cv-alert" role="alert">
                Sezione disponibile solo per amministratore.
            </div>
        </div>
    </div>
    <?php
    cvAccessoRenderPageEnd();
    return;
}

function cvStatsProviderNormalizeHtmlDate(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $value);
    if (!$dt instanceof DateTimeImmutable) {
        return '';
    }

    $errors = DateTimeImmutable::getLastErrors();
    if (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) {
        return '';
    }

    return $dt->format('Y-m-d');
}

$filterDateFrom = cvStatsProviderNormalizeHtmlDate((string) ($_GET['date_from'] ?? ''));
$filterDateTo = cvStatsProviderNormalizeHtmlDate((string) ($_GET['date_to'] ?? ''));
$filterProviderCode = strtolower(trim((string) ($_GET['provider_code'] ?? '')));
$exportCsv = ((string) ($_GET['export'] ?? '')) === 'csv';

if ($filterDateFrom !== '' && $filterDateTo !== '' && $filterDateFrom > $filterDateTo) {
    $state['errors'][] = 'Intervallo date non valido: "Dal" deve essere precedente o uguale ad "Al".';
    $filterDateFrom = '';
    $filterDateTo = '';
}

$providers = [];
$providersByCode = [];
$rows = [];
$ticketRows = [];
$totals = [
    'provider_net_paid' => 0.0,
    'tickets_paid' => 0,
];

try {
    $connection = cvAccessoRequireConnection();
    $providers = cvCacheFetchProviders($connection);
    foreach ($providers as $provider) {
        if (!is_array($provider)) {
            continue;
        }
        $code = strtolower(trim((string) ($provider['code'] ?? '')));
        if ($code === '') {
            continue;
        }
        $providersByCode[$code] = [
            'code' => $code,
            'name' => trim((string) ($provider['name'] ?? $code)),
        ];
    }

    if ($filterProviderCode !== '' && !isset($providersByCode[$filterProviderCode])) {
        $state['errors'][] = 'Provider selezionato non valido.';
        $filterProviderCode = '';
    }

    $whereParts = ['b.pagato = 1'];
    if ($filterProviderCode !== '') {
        $whereParts[] = "LOWER(COALESCE(a.code, '')) = '" . $connection->real_escape_string($filterProviderCode) . "'";
    }
    if ($filterDateFrom !== '') {
        $whereParts[] = "b.acquistato >= '" . $connection->real_escape_string($filterDateFrom . ' 00:00:00') . "'";
    }
    if ($filterDateTo !== '') {
        $whereParts[] = "b.acquistato <= '" . $connection->real_escape_string($filterDateTo . ' 23:59:59') . "'";
    }
    $whereSql = implode(' AND ', $whereParts);

    $result = $connection->query(
        "SELECT
            LOWER(COALESCE(a.code, '')) AS provider_code,
            COALESCE(NULLIF(a.nome, ''), NULLIF(a.code, ''), 'Provider') AS provider_name,
            COUNT(*) AS tickets_paid,
            COALESCE(SUM(b.prezzo - b.prz_comm), 0) AS provider_net_paid,
            COALESCE(SUM(b.prz_comm), 0) AS commission_paid,
            COALESCE(SUM(b.prezzo), 0) AS gross_paid
         FROM biglietti AS b
         LEFT JOIN aziende AS a ON a.id_az = b.id_az
         WHERE {$whereSql}
         GROUP BY provider_code, provider_name
         ORDER BY provider_net_paid DESC, provider_name ASC"
    );
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            if (!is_array($row)) {
                continue;
            }
            $rows[] = [
                'provider_code' => (string) ($row['provider_code'] ?? ''),
                'provider_name' => (string) ($row['provider_name'] ?? 'Provider'),
                'tickets_paid' => (int) ($row['tickets_paid'] ?? 0),
                'provider_net_paid' => (float) ($row['provider_net_paid'] ?? 0),
                'commission_paid' => (float) ($row['commission_paid'] ?? 0),
                'gross_paid' => (float) ($row['gross_paid'] ?? 0),
            ];
        }
        $result->free();
    }

    foreach ($rows as $r) {
        $totals['tickets_paid'] += (int) ($r['tickets_paid'] ?? 0);
        $totals['provider_net_paid'] += (float) ($r['provider_net_paid'] ?? 0);
    }
    $totals['provider_net_paid'] = round((float) $totals['provider_net_paid'], 2);

    $ticketsResult = $connection->query(
        "SELECT
            b.id_bg,
            b.codice,
            b.acquistato,
            b.prezzo,
            b.prz_comm,
            (b.prezzo - b.prz_comm) AS provider_net,
            COALESCE(NULLIF(a.nome, ''), NULLIF(a.code, ''), 'Provider') AS provider_name,
            LOWER(COALESCE(a.code, '')) AS provider_code
         FROM biglietti AS b
         LEFT JOIN aziende AS a ON a.id_az = b.id_az
         WHERE {$whereSql}
         ORDER BY b.acquistato DESC, b.id_bg DESC
         LIMIT 1000"
    );
    if ($ticketsResult instanceof mysqli_result) {
        while ($row = $ticketsResult->fetch_assoc()) {
            if (!is_array($row)) {
                continue;
            }
            $ticketRows[] = [
                'id_bg' => (int) ($row['id_bg'] ?? 0),
                'codice' => (string) ($row['codice'] ?? ''),
                'acquistato' => (string) ($row['acquistato'] ?? ''),
                'prezzo' => (float) ($row['prezzo'] ?? 0),
                'prz_comm' => (float) ($row['prz_comm'] ?? 0),
                'provider_net' => (float) ($row['provider_net'] ?? 0),
                'provider_name' => (string) ($row['provider_name'] ?? 'Provider'),
                'provider_code' => (string) ($row['provider_code'] ?? ''),
            ];
        }
        $ticketsResult->free();
    }
} catch (Throwable $exception) {
    $state['errors'][] = 'Errore sezione statistiche provider: ' . $exception->getMessage();
}

if ($exportCsv) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="cercaviaggio_provider_net_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    if (is_resource($out)) {
        fputcsv($out, ['provider_code', 'provider_name', 'tickets_paid', 'gross_paid', 'commission_paid', 'provider_net_paid']);
        foreach ($rows as $r) {
            fputcsv($out, [
                (string) ($r['provider_code'] ?? ''),
                (string) ($r['provider_name'] ?? ''),
                (int) ($r['tickets_paid'] ?? 0),
                number_format((float) ($r['gross_paid'] ?? 0), 2, '.', ''),
                number_format((float) ($r['commission_paid'] ?? 0), 2, '.', ''),
                number_format((float) ($r['provider_net_paid'] ?? 0), 2, '.', ''),
            ]);
        }
        fclose($out);
    }
    exit;
}

cvAccessoRenderPageStart('Statistiche provider', 'statistics-provider', $state);
?>
<div class="row">
    <div class="col-md-12">
        <p class="cv-page-intro">
            Totali per provider sui biglietti pagati: <strong>netto provider</strong> = <code>prezzo - prz_comm</code>.
        </p>
    </div>
</div>

<div class="row">
    <div class="col-md-12">
        <div class="cv-panel-card">
            <h4>Filtri</h4>
            <form method="get" class="cv-form-grid" style="margin-top:12px;">
                <div class="row">
                    <div class="col-md-3 form-group">
                        <label>Dal</label>
                        <input type="date" class="form-control" name="date_from" value="<?= cvAccessoH($filterDateFrom) ?>">
                    </div>
                    <div class="col-md-3 form-group">
                        <label>Al</label>
                        <input type="date" class="form-control" name="date_to" value="<?= cvAccessoH($filterDateTo) ?>">
                    </div>
                    <div class="col-md-4 form-group">
                        <label>Provider</label>
                        <select class="form-control" name="provider_code">
                            <option value="">Tutti</option>
                            <?php foreach ($providersByCode as $code => $provider): ?>
                                <option value="<?= cvAccessoH($code) ?>"<?= $filterProviderCode === $code ? ' selected' : '' ?>>
                                    <?= cvAccessoH((string) ($provider['name'] ?? $code)) ?> (<?= cvAccessoH(strtoupper($code)) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2 form-group">
                        <label>&nbsp;</label>
                        <button type="submit" class="btn btn-primary" style="width:100%;">Applica</button>
                    </div>
                </div>
            </form>
            <div class="cv-inline-actions" style="margin-top:10px;">
                <?php
                $qs = $_GET;
                $qs['export'] = 'csv';
                $href = cvAccessoUrl('statistiche-provider.php') . '?' . http_build_query($qs);
                ?>
                <a class="btn btn-default" href="<?= cvAccessoH($href) ?>">Esporta CSV</a>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-12">
        <div class="cv-panel-card">
            <h4>Totali</h4>
            <div class="row">
                <div class="col-md-6">
                    <div class="cv-muted">Biglietti pagati</div>
                    <div style="font-size:20px;"><strong><?= (int) ($totals['tickets_paid'] ?? 0) ?></strong></div>
                </div>
                <div class="col-md-6">
                    <div class="cv-muted">Netto provider totale</div>
                    <div style="font-size:20px;"><strong>€ <?= number_format((float) ($totals['provider_net_paid'] ?? 0), 2, ',', '.') ?></strong></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-12">
        <div class="cv-panel-card">
            <h4>Riepilogo per provider</h4>
            <?php if (count($rows) === 0): ?>
                <div class="cv-empty">Nessun dato nel periodo selezionato.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead>
                        <tr>
                            <th>Provider</th>
                            <th>Codice</th>
                            <th class="text-right">Biglietti pagati</th>
                            <th class="text-right">Lordo</th>
                            <th class="text-right">Commissione (prz_comm)</th>
                            <th class="text-right">Netto provider</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><?= cvAccessoH((string) ($row['provider_name'] ?? 'Provider')) ?></td>
                                <td><?= cvAccessoH(strtoupper((string) ($row['provider_code'] ?? ''))) ?></td>
                                <td class="text-right"><?= (int) ($row['tickets_paid'] ?? 0) ?></td>
                                <td class="text-right">€ <?= number_format((float) ($row['gross_paid'] ?? 0), 2, ',', '.') ?></td>
                                <td class="text-right">€ <?= number_format((float) ($row['commission_paid'] ?? 0), 2, ',', '.') ?></td>
                                <td class="text-right"><strong>€ <?= number_format((float) ($row['provider_net_paid'] ?? 0), 2, ',', '.') ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-12">
        <div class="cv-panel-card">
            <h4>Dettaglio biglietti (max 1000)</h4>
            <?php if (count($ticketRows) === 0): ?>
                <div class="cv-empty">Nessun biglietto nel periodo selezionato.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead>
                        <tr>
                            <th>ID</th>
                            <th>Codice</th>
                            <th>Acquistato</th>
                            <th>Provider</th>
                            <th class="text-right">Prezzo</th>
                            <th class="text-right">prz_comm</th>
                            <th class="text-right">Netto provider</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($ticketRows as $ticket): ?>
                            <tr>
                                <td><?= (int) ($ticket['id_bg'] ?? 0) ?></td>
                                <td><?= cvAccessoH((string) ($ticket['codice'] ?? '')) ?></td>
                                <td><?= cvAccessoH((string) ($ticket['acquistato'] ?? '')) ?></td>
                                <td><?= cvAccessoH((string) ($ticket['provider_name'] ?? 'Provider')) ?></td>
                                <td class="text-right">€ <?= number_format((float) ($ticket['prezzo'] ?? 0), 2, ',', '.') ?></td>
                                <td class="text-right">€ <?= number_format((float) ($ticket['prz_comm'] ?? 0), 2, ',', '.') ?></td>
                                <td class="text-right"><strong>€ <?= number_format((float) ($ticket['provider_net'] ?? 0), 2, ',', '.') ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
cvAccessoRenderPageEnd();

