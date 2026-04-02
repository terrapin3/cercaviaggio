<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';

$state = cvAccessoInit();
if (!$state['authenticated']) {
    cvAccessoRenderLoginPage($state);
    return;
}

/**
 * Valida una data HTML (YYYY-mm-dd).
 */
function cvStatsNormalizeHtmlDate(string $value): string
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

$filterDateFrom = cvStatsNormalizeHtmlDate((string) ($_GET['date_from'] ?? ''));
$filterDateTo = cvStatsNormalizeHtmlDate((string) ($_GET['date_to'] ?? ''));
$filterProviderCode = strtolower(trim((string) ($_GET['provider_code'] ?? '')));
$filterPaymentStatus = strtolower(trim((string) ($_GET['payment_status'] ?? 'paid')));
if (!in_array($filterPaymentStatus, ['paid', 'all', 'unpaid'], true)) {
    $filterPaymentStatus = 'paid';
}

if ($filterDateFrom !== '' && $filterDateTo !== '' && $filterDateFrom > $filterDateTo) {
    $state['errors'][] = 'Intervallo date non valido: "Dal" deve essere precedente o uguale ad "Al".';
    $filterDateFrom = '';
    $filterDateTo = '';
}

$stats = [
    'tracked_routes' => 0,
    'search_volume' => 0,
    'tickets_total' => 0,
    'tickets_paid' => 0,
    'gross_paid' => 0.0,
    'commission_paid' => 0.0,
    'provider_net_paid' => 0.0,
];
$providerBreakdown = [];
$detailsRows = [];
$providers = [];

$invoicePreview = [
    'provider_label' => 'Tutti i provider (aggregato)',
    'imponibile' => 0.0,
    'bollo' => 0.0,
    'totale' => 0.0,
    'note' => 'OPERAZIONI SENZA ADDEBITO DI IMPOSTA - REGIME FORFAIT ART.1, C.54-89 L.190/2014',
];

try {
    $connection = cvAccessoRequireConnection();

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

    $providers = cvAccessoFilterProviders($state, cvCacheFetchProviders($connection));
    $providersByCode = [];
    foreach ($providers as $provider) {
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
        $state['errors'][] = 'Provider selezionato non disponibile per il tuo account.';
        $filterProviderCode = '';
    }

    $whereParts = ['1=1'];

    if (!cvAccessoIsAdmin($state)) {
        if (count($providersByCode) === 0) {
            $whereParts[] = '1=0';
        } else {
            $providerIn = [];
            foreach (array_keys($providersByCode) as $code) {
                $providerIn[] = "'" . $connection->real_escape_string($code) . "'";
            }
            $whereParts[] = 'LOWER(COALESCE(a.code, \"\")) IN (' . implode(', ', $providerIn) . ')';
        }
    }

    if ($filterProviderCode !== '') {
        $whereParts[] = "LOWER(COALESCE(a.code, '')) = '" . $connection->real_escape_string($filterProviderCode) . "'";
    }

    if ($filterDateFrom !== '') {
        $whereParts[] = "b.acquistato >= '" . $connection->real_escape_string($filterDateFrom . ' 00:00:00') . "'";
    }
    if ($filterDateTo !== '') {
        $whereParts[] = "b.acquistato <= '" . $connection->real_escape_string($filterDateTo . ' 23:59:59') . "'";
    }

    if ($filterPaymentStatus === 'paid') {
        $whereParts[] = 'b.pagato = 1';
    } elseif ($filterPaymentStatus === 'unpaid') {
        $whereParts[] = 'b.pagato <> 1';
    }

    $whereSql = implode(' AND ', $whereParts);

    $ticketsResult = $connection->query(
        "SELECT
            COUNT(*) AS tickets_total,
            COALESCE(SUM(CASE WHEN b.pagato = 1 THEN 1 ELSE 0 END), 0) AS tickets_paid,
            COALESCE(SUM(CASE WHEN b.pagato = 1 THEN b.prezzo ELSE 0 END), 0) AS gross_paid,
            COALESCE(SUM(CASE WHEN b.pagato = 1 THEN b.prz_comm ELSE 0 END), 0) AS commission_paid,
            COALESCE(SUM(CASE WHEN b.pagato = 1 THEN (b.prezzo - b.prz_comm) ELSE 0 END), 0) AS provider_net_paid
         FROM biglietti AS b
         LEFT JOIN aziende AS a ON a.id_az = b.id_az
         WHERE {$whereSql}"
    );
    if ($ticketsResult instanceof mysqli_result) {
        $row = $ticketsResult->fetch_assoc();
        if (is_array($row)) {
            $stats['tickets_total'] = (int) ($row['tickets_total'] ?? 0);
            $stats['tickets_paid'] = (int) ($row['tickets_paid'] ?? 0);
            $stats['gross_paid'] = (float) ($row['gross_paid'] ?? 0);
            $stats['commission_paid'] = (float) ($row['commission_paid'] ?? 0);
            $stats['provider_net_paid'] = (float) ($row['provider_net_paid'] ?? 0);
        }
        $ticketsResult->free();
    }

    $providerResult = $connection->query(
        "SELECT
            COALESCE(NULLIF(a.nome, ''), NULLIF(a.code, ''), 'Provider') AS provider_name,
            LOWER(COALESCE(a.code, '')) AS provider_code,
            COUNT(*) AS tickets_total,
            COALESCE(SUM(CASE WHEN b.pagato = 1 THEN 1 ELSE 0 END), 0) AS tickets_paid,
            COALESCE(SUM(CASE WHEN b.pagato = 1 THEN b.prezzo ELSE 0 END), 0) AS gross_paid,
            COALESCE(SUM(CASE WHEN b.pagato = 1 THEN b.prz_comm ELSE 0 END), 0) AS commission_paid,
            COALESCE(SUM(CASE WHEN b.pagato = 1 THEN (b.prezzo - b.prz_comm) ELSE 0 END), 0) AS provider_net_paid
         FROM biglietti AS b
         LEFT JOIN aziende AS a ON a.id_az = b.id_az
         WHERE {$whereSql}
         GROUP BY provider_code, provider_name
         ORDER BY gross_paid DESC, provider_name ASC"
    );
    if ($providerResult instanceof mysqli_result) {
        while ($row = $providerResult->fetch_assoc()) {
            if (!is_array($row)) {
                continue;
            }
            $providerBreakdown[] = [
                'provider_name' => (string) ($row['provider_name'] ?? 'Provider'),
                'provider_code' => (string) ($row['provider_code'] ?? ''),
                'tickets_total' => (int) ($row['tickets_total'] ?? 0),
                'tickets_paid' => (int) ($row['tickets_paid'] ?? 0),
                'gross_paid' => (float) ($row['gross_paid'] ?? 0),
                'commission_paid' => (float) ($row['commission_paid'] ?? 0),
                'provider_net_paid' => (float) ($row['provider_net_paid'] ?? 0),
            ];
        }
        $providerResult->free();
    }

    $detailsResult = $connection->query(
        "SELECT
            b.id_bg,
            b.codice,
            b.acquistato,
            b.pagato,
            b.prezzo,
            b.prz_comm,
            (b.prezzo - b.prz_comm) AS provider_net,
            COALESCE(NULLIF(a.nome, ''), NULLIF(a.code, ''), 'Provider') AS provider_name,
            LOWER(COALESCE(a.code, '')) AS provider_code
         FROM biglietti AS b
         LEFT JOIN aziende AS a ON a.id_az = b.id_az
         WHERE {$whereSql}
         ORDER BY b.acquistato DESC, b.id_bg DESC
         LIMIT 500"
    );
    if ($detailsResult instanceof mysqli_result) {
        while ($row = $detailsResult->fetch_assoc()) {
            if (!is_array($row)) {
                continue;
            }
            $detailsRows[] = [
                'id_bg' => (int) ($row['id_bg'] ?? 0),
                'codice' => (string) ($row['codice'] ?? ''),
                'acquistato' => (string) ($row['acquistato'] ?? ''),
                'pagato' => (int) ($row['pagato'] ?? 0),
                'prezzo' => (float) ($row['prezzo'] ?? 0),
                'prz_comm' => (float) ($row['prz_comm'] ?? 0),
                'provider_net' => (float) ($row['provider_net'] ?? 0),
                'provider_name' => (string) ($row['provider_name'] ?? 'Provider'),
                'provider_code' => (string) ($row['provider_code'] ?? ''),
            ];
        }
        $detailsResult->free();
    }

    $invoicePreview['imponibile'] = round((float) ($stats['commission_paid'] ?? 0), 2);
    $invoicePreview['bollo'] = $invoicePreview['imponibile'] > 0 ? 2.0 : 0.0;
    $invoicePreview['totale'] = round($invoicePreview['imponibile'] + $invoicePreview['bollo'], 2);

    if ($filterProviderCode !== '') {
        $providerName = isset($providersByCode[$filterProviderCode]['name']) ? (string) $providersByCode[$filterProviderCode]['name'] : $filterProviderCode;
        $invoicePreview['provider_label'] = $providerName . ' (' . strtoupper($filterProviderCode) . ')';
    }
} catch (Throwable $exception) {
    $state['errors'][] = 'Errore sezione statistiche: ' . $exception->getMessage();
}

cvAccessoRenderPageStart('Statistiche', 'statistics', $state);
?>
<div class="row">
    <div class="col-md-12">
        <p class="cv-page-intro">
            Panorama biglietti, commissioni e netto provider con filtri per periodo/provider/stato.
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
                    <div class="col-md-3 form-group">
                        <label>Provider</label>
                        <select class="form-control" name="provider_code">
                            <option value="">Tutti</option>
                            <?php foreach ($providers as $provider): ?>
                                <?php
                                $code = strtolower(trim((string) ($provider['code'] ?? '')));
                                $name = trim((string) ($provider['name'] ?? $code));
                                if ($code === '') {
                                    continue;
                                }
                                ?>
                                <option value="<?= cvAccessoH($code) ?>"<?= $filterProviderCode === $code ? ' selected' : '' ?>>
                                    <?= cvAccessoH($name) ?> (<?= cvAccessoH(strtoupper($code)) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3 form-group">
                        <label>Stato pagamento</label>
                        <select class="form-control" name="payment_status">
                            <option value="paid"<?= $filterPaymentStatus === 'paid' ? ' selected' : '' ?>>Solo pagati</option>
                            <option value="all"<?= $filterPaymentStatus === 'all' ? ' selected' : '' ?>>Tutti</option>
                            <option value="unpaid"<?= $filterPaymentStatus === 'unpaid' ? ' selected' : '' ?>>Solo non pagati</option>
                        </select>
                    </div>
                </div>
                <div class="cv-inline-actions">
                    <button type="submit" class="btn btn-primary btn-sm">Applica filtri</button>
                    <a href="statistiche.php" class="btn btn-default btn-sm">Reset</a>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-sm-6 col-lg-3">
        <div class="cv-stat-card">
            <div class="cv-stat-value"><?= (int) $stats['tracked_routes'] ?></div>
            <div class="cv-stat-label">Rotte tracciate</div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="cv-stat-card">
            <div class="cv-stat-value"><?= (int) $stats['search_volume'] ?></div>
            <div class="cv-stat-label">Volume ricerche</div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="cv-stat-card">
            <div class="cv-stat-value"><?= (int) $stats['tickets_total'] ?></div>
            <div class="cv-stat-label">Biglietti nel filtro</div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="cv-stat-card">
            <div class="cv-stat-value"><?= (int) $stats['tickets_paid'] ?></div>
            <div class="cv-stat-label">Biglietti pagati</div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="cv-stat-card">
            <div class="cv-stat-value">€ <?= number_format((float) $stats['gross_paid'], 2, ',', '.') ?></div>
            <div class="cv-stat-label">Incasso totale cliente</div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="cv-stat-card">
            <div class="cv-stat-value">€ <?= number_format((float) $stats['commission_paid'], 2, ',', '.') ?></div>
            <div class="cv-stat-label"><?= cvAccessoIsAdmin($state) ? 'Commissioni Cercaviaggio' : 'Le tue commissioni' ?></div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="cv-stat-card">
            <div class="cv-stat-value">€ <?= number_format((float) $stats['provider_net_paid'], 2, ',', '.') ?></div>
            <div class="cv-stat-label">Netto al provider</div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-12">
        <div class="cv-panel-card">
            <h4>Bozza fattura provvigioni</h4>
            <p class="cv-muted" style="margin-top:8px;">
                <?= cvAccessoH($invoicePreview['note']) ?>
            </p>
            <div class="table-responsive" style="margin-top:10px;">
                <table class="table table-bordered">
                    <tbody>
                    <tr>
                        <th style="width:35%;">Destinatario</th>
                        <td><?= cvAccessoH($invoicePreview['provider_label']) ?></td>
                    </tr>
                    <tr>
                        <th>Imponibile provvigioni</th>
                        <td>€ <?= number_format((float) $invoicePreview['imponibile'], 2, ',', '.') ?></td>
                    </tr>
                    <tr>
                        <th>Bollo</th>
                        <td>€ <?= number_format((float) $invoicePreview['bollo'], 2, ',', '.') ?></td>
                    </tr>
                    <tr>
                        <th>Totale fattura</th>
                        <td><strong>€ <?= number_format((float) $invoicePreview['totale'], 2, ',', '.') ?></strong></td>
                    </tr>
                    </tbody>
                </table>
            </div>
            <?php if ($filterProviderCode === ''): ?>
                <div class="cv-muted">Per la fattura verso una singola azienda, seleziona prima il provider nei filtri.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-12">
        <div class="cv-panel-card">
            <h4>Ripartizione per provider</h4>
            <div class="table-responsive" style="margin-top:12px;">
                <table class="table table-striped table-bordered">
                    <thead>
                    <tr>
                        <th>Provider</th>
                        <th>Biglietti</th>
                        <th>Pagati</th>
                        <th>Totale cliente</th>
                        <th>Commissione CV</th>
                        <th>Netto provider</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (count($providerBreakdown) === 0): ?>
                        <tr>
                            <td colspan="6" class="text-center cv-muted">Nessun dato disponibile.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($providerBreakdown as $row): ?>
                            <tr>
                                <td><?= cvAccessoH((string) $row['provider_name']) ?></td>
                                <td><?= (int) ($row['tickets_total'] ?? 0) ?></td>
                                <td><?= (int) ($row['tickets_paid'] ?? 0) ?></td>
                                <td>€ <?= number_format((float) ($row['gross_paid'] ?? 0), 2, ',', '.') ?></td>
                                <td>€ <?= number_format((float) ($row['commission_paid'] ?? 0), 2, ',', '.') ?></td>
                                <td>€ <?= number_format((float) ($row['provider_net_paid'] ?? 0), 2, ',', '.') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-12">
        <div class="cv-panel-card">
            <h4>Dettaglio biglietti (max 500 righe)</h4>
            <div class="table-responsive" style="margin-top:12px;">
                <table class="table table-striped table-bordered">
                    <thead>
                    <tr>
                        <th>Acquisto</th>
                        <th>Codice</th>
                        <th>Provider</th>
                        <th>Stato</th>
                        <th>Totale cliente</th>
                        <th>Commissione CV</th>
                        <th>Netto provider</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (count($detailsRows) === 0): ?>
                        <tr>
                            <td colspan="7" class="text-center cv-muted">Nessun biglietto nel filtro corrente.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($detailsRows as $row): ?>
                            <?php
                            $acquistatoLabel = trim((string) ($row['acquistato'] ?? ''));
                            if ($acquistatoLabel !== '') {
                                $ts = strtotime($acquistatoLabel);
                                if (is_int($ts) && $ts > 0) {
                                    $acquistatoLabel = date('d/m/Y H:i', $ts);
                                }
                            }
                            ?>
                            <tr>
                                <td><?= cvAccessoH($acquistatoLabel) ?></td>
                                <td><?= cvAccessoH((string) ($row['codice'] ?? '')) ?></td>
                                <td><?= cvAccessoH((string) ($row['provider_name'] ?? 'Provider')) ?></td>
                                <td><?= ((int) ($row['pagato'] ?? 0) === 1) ? 'Pagato' : 'Da pagare' ?></td>
                                <td>€ <?= number_format((float) ($row['prezzo'] ?? 0), 2, ',', '.') ?></td>
                                <td>€ <?= number_format((float) ($row['prz_comm'] ?? 0), 2, ',', '.') ?></td>
                                <td>€ <?= number_format((float) ($row['provider_net'] ?? 0), 2, ',', '.') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php
cvAccessoRenderPageEnd();
