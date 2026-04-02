<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';

if (!function_exists('cvAccessoTicketPdfSafeText')) {
    function cvAccessoTicketPdfSafeText(string $value, int $limit = 120): string
    {
        $value = trim($value);
        if ($value === '') {
            return '-';
        }
        $value = preg_replace('/\s+/', ' ', $value);
        if (!is_string($value)) {
            return '-';
        }
        if (strlen($value) > $limit) {
            $value = substr($value, 0, $limit - 1) . '…';
        }
        return iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $value) ?: '-';
    }
}

if (!function_exists('cvAccessoTicketPdfFormatDateTime')) {
    function cvAccessoTicketPdfFormatDateTime(string $value): string
    {
        $raw = trim($value);
        if ($raw === '') {
            return '-';
        }
        $ts = strtotime($raw);
        if ($ts === false || $ts <= 0) {
            return cvAccessoTicketPdfSafeText($raw, 32);
        }
        return date('d/m/Y H:i', $ts);
    }
}

if (!function_exists('cvAccessoGenerateTicketPdfRaw')) {
    /**
     * @param array<string,mixed> $ticket
     * @param array<string,mixed> $providerCompany
     */
    function cvAccessoGenerateTicketPdfRaw(array $ticket, array $providerCompany): ?string
    {
        $fpdfPath = dirname(__DIR__) . '/fpdf/fpdf.php';
        $fpdiAutoloadPath = dirname(__DIR__) . '/fpdi/src/autoload.php';
        $qrLibPath = dirname(__DIR__) . '/functions/phpqrcode/qrlib.php';
        if (!is_file($fpdfPath) || !is_file($fpdiAutoloadPath) || !is_file($qrLibPath)) {
            return null;
        }

        require_once $fpdfPath;
        require_once $fpdiAutoloadPath;
        require_once $qrLibPath;

        if (!class_exists('\\setasign\\Fpdi\\Fpdi') || !class_exists('QRcode')) {
            return null;
        }

        $code = trim((string) ($ticket['code'] ?? ''));
        if ($code === '') {
            return null;
        }

        $tmpQr = tempnam(sys_get_temp_dir(), 'cvqr_');
        if (!is_string($tmpQr) || $tmpQr === '') {
            return null;
        }
        $qrPath = $tmpQr . '.png';
        @rename($tmpQr, $qrPath);
        QRcode::png($code, $qrPath, 'L', 6, 2);
        if (!is_file($qrPath)) {
            return null;
        }

        try {
            $pdf = new \setasign\Fpdi\Fpdi('P', 'mm', 'A4');
            $pdf->AddPage();

            $templatePath = dirname(__DIR__) . '/ticket.pdf';
            if (is_file($templatePath)) {
                $pdf->setSourceFile($templatePath);
                $tplIdx = $pdf->importPage(1);
                $pdf->useTemplate($tplIdx, 0, 0, 210);
            }

            $providerName = cvAccessoTicketPdfSafeText((string) ($providerCompany['nome'] ?? $ticket['provider_name'] ?? '-'), 70);
            $fromName = cvAccessoTicketPdfSafeText((string) ($ticket['from_name'] ?? ''), 70);
            $toName = cvAccessoTicketPdfSafeText((string) ($ticket['to_name'] ?? ''), 70);
            $passengerName = cvAccessoTicketPdfSafeText((string) ($ticket['passenger_name'] ?? 'Passeggero'), 70);
            $departureAt = cvAccessoTicketPdfFormatDateTime((string) ($ticket['departure_at'] ?? ''));
            $arrivalAt = cvAccessoTicketPdfFormatDateTime((string) ($ticket['arrival_at'] ?? ''));
            $shopId = cvAccessoTicketPdfSafeText((string) ($ticket['shop_id'] ?? ''), 60);
            $changeCode = cvAccessoTicketPdfSafeText((string) ($ticket['change_code'] ?? ''), 40);
            $price = round((float) ($ticket['price'] ?? 0.0), 2);
            $seat = (int) ($ticket['seat_number'] ?? 0);
            $busNum = (int) ($ticket['bus_number'] ?? 0);

            $pdf->SetTextColor(18, 38, 68);
            $pdf->SetFont('Arial', 'B', 13);
            $pdf->SetXY(12, 16);
            $pdf->Cell(120, 7, cvAccessoTicketPdfSafeText('Biglietto Cercaviaggio - ' . $providerName, 72), 0, 0, 'L');

            $pdf->SetFont('Arial', 'B', 11);
            $pdf->SetXY(12, 32);
            $pdf->Cell(120, 6, cvAccessoTicketPdfSafeText($fromName . ' -> ' . $toName, 72), 0, 0, 'L');

            $pdf->SetFont('Arial', '', 10);
            $pdf->SetXY(12, 40);
            $pdf->Cell(120, 6, cvAccessoTicketPdfSafeText('Partenza: ' . $departureAt, 72), 0, 0, 'L');
            $pdf->SetXY(12, 46);
            $pdf->Cell(120, 6, cvAccessoTicketPdfSafeText('Arrivo: ' . $arrivalAt, 72), 0, 0, 'L');

            $pdf->SetXY(12, 58);
            $pdf->Cell(120, 6, cvAccessoTicketPdfSafeText('Passeggero: ' . $passengerName, 72), 0, 0, 'L');
            $pdf->SetXY(12, 64);
            $pdf->Cell(120, 6, cvAccessoTicketPdfSafeText('Codice biglietto: ' . $code, 72), 0, 0, 'L');
            $pdf->SetXY(12, 70);
            $pdf->Cell(120, 6, cvAccessoTicketPdfSafeText('Shop ID: ' . $shopId, 72), 0, 0, 'L');
            $pdf->SetXY(12, 76);
            $pdf->Cell(120, 6, cvAccessoTicketPdfSafeText('Codice cambio: ' . $changeCode, 72), 0, 0, 'L');
            $pdf->SetXY(12, 82);
            $pdf->Cell(120, 6, cvAccessoTicketPdfSafeText('Bus/Posto: ' . $busNum . ' / ' . $seat, 72), 0, 0, 'L');

            $pdf->SetFont('Arial', 'B', 20);
            $pdf->SetXY(12, 94);
            $pdf->Cell(120, 10, number_format($price, 2, ',', '.') . ' EUR', 0, 0, 'L');

            $pdf->Image($qrPath, 150, 24, 45, 45);

            $footerY = 246;
            $pdf->SetDrawColor(205, 220, 238);
            $pdf->Line(12, $footerY - 4, 198, $footerY - 4);
            $pdf->SetTextColor(35, 58, 90);
            $pdf->SetFont('Arial', 'B', 9);
            $pdf->SetXY(12, $footerY - 2);
            $pdf->Cell(186, 5, cvAccessoTicketPdfSafeText('Servizio effettuato dal provider: ' . $providerName, 120), 0, 0, 'L');

            $pdf->SetFont('Arial', '', 8);
            $footerLine1 = 'Indirizzo: ' . cvAccessoTicketPdfSafeText((string) ($providerCompany['ind'] ?? '-'), 88)
                . ' | Tel: ' . cvAccessoTicketPdfSafeText((string) ($providerCompany['tel'] ?? '-'), 38)
                . ' | Email: ' . cvAccessoTicketPdfSafeText((string) ($providerCompany['email_pg'] ?? '-'), 44);
            $footerLine2 = 'P.IVA: ' . cvAccessoTicketPdfSafeText((string) ($providerCompany['pi'] ?? '-'), 40)
                . ' | Recapiti: ' . cvAccessoTicketPdfSafeText((string) ($providerCompany['recapiti'] ?? '-'), 108);
            $pdf->SetXY(12, $footerY + 2);
            $pdf->Cell(186, 4, cvAccessoTicketPdfSafeText($footerLine1, 140), 0, 0, 'L');
            $pdf->SetXY(12, $footerY + 6);
            $pdf->Cell(186, 4, cvAccessoTicketPdfSafeText($footerLine2, 140), 0, 0, 'L');

            $raw = $pdf->Output('S');
            @unlink($qrPath);
            return is_string($raw) && $raw !== '' ? $raw : null;
        } catch (Throwable $exception) {
            @unlink($qrPath);
            return null;
        }
    }
}

if (!function_exists('cvAccessoTicketParseAmount')) {
    function cvAccessoTicketParseAmount(string $raw): ?float
    {
        $normalized = trim(str_replace(' ', '', $raw));
        if ($normalized === '') {
            return null;
        }
        $normalized = str_replace(',', '.', $normalized);
        if (!preg_match('/^-?\d+(?:\.\d{1,2})?$/', $normalized)) {
            return null;
        }
        return round((float) $normalized, 2);
    }
}

if (!function_exists('cvAccessoTicketFindById')) {
    /**
     * @param array<string,bool> $allowedProviderCodes
     * @return array<string,mixed>|null
     */
    function cvAccessoTicketFindById(mysqli $connection, int $ticketId, bool $isAdmin, array $allowedProviderCodes): ?array
    {
        if ($ticketId <= 0) {
            return null;
        }

        $where = ["b.id_bg = " . $ticketId];
        if (!$isAdmin) {
            if (count($allowedProviderCodes) === 0) {
                return null;
            }
            $codes = [];
            foreach (array_keys($allowedProviderCodes) as $code) {
                $codes[] = "'" . $connection->real_escape_string($code) . "'";
            }
            $where[] = 'LOWER(COALESCE(a.code, "")) IN (' . implode(', ', $codes) . ')';
        }

        $sql = "SELECT
                    b.id_bg,
                    b.id_az,
                    b.codice,
                    b.id_mz,
                    b.mz_dt,
                    b.id_corsa,
                    b.posto,
                    b.prezzo,
                    b.prz_comm,
                    b.pagato,
                    b.stato,
                    b.rimborsato,
                    b.note,
                    b.data,
                    b.acquistato,
                    COALESCE(NULLIF(a.nome, ''), NULLIF(a.code, ''), '-') AS provider_name,
                    LOWER(COALESCE(a.code, '')) AS provider_code
                FROM biglietti AS b
                LEFT JOIN aziende AS a ON a.id_az = b.id_az
                WHERE " . implode(' AND ', $where) . "
                LIMIT 1";

        $result = $connection->query($sql);
        if (!$result instanceof mysqli_result) {
            return null;
        }
        $row = $result->fetch_assoc();
        $result->free();
        return is_array($row) ? $row : null;
    }
}

if (!function_exists('cvAccessoTicketInsertLog')) {
    /**
     * @param array<string,mixed> $payload
     */
    function cvAccessoTicketInsertLog(mysqli $connection, int $ticketId, int $operatorId, string $operation, array $payload): void
    {
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($payloadJson)) {
            return;
        }

        $sql = "INSERT INTO biglietti_log (id_bg, id_utop, operazione, payload) VALUES (?, ?, ?, ?)";
        $stmt = $connection->prepare($sql);
        if (!$stmt instanceof mysqli_stmt) {
            return;
        }
        $stmt->bind_param('iiss', $ticketId, $operatorId, $operation, $payloadJson);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('cvAccessoTicketInsertSnapshot')) {
    /**
     * @param array<string,mixed> $snapshot
     */
    function cvAccessoTicketInsertSnapshot(mysqli $connection, int $ticketId, array $snapshot): void
    {
        $snapshotJson = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($snapshotJson)) {
            return;
        }

        $sql = "INSERT INTO biglietti_reg (id_bg, snapshot_json) VALUES (?, ?)";
        $stmt = $connection->prepare($sql);
        if (!$stmt instanceof mysqli_stmt) {
            return;
        }
        $stmt->bind_param('is', $ticketId, $snapshotJson);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('cvAccessoTicketDecodePayload')) {
    /**
     * @return array<string,mixed>
     */
    function cvAccessoTicketDecodePayload(string $payload): array
    {
        $payload = trim($payload);
        if ($payload === '') {
            return [];
        }
        $decoded = json_decode($payload, true);
        return is_array($decoded) ? $decoded : [];
    }
}

if (!function_exists('cvAccessoTicketOperatorId')) {
    function cvAccessoTicketOperatorId(mysqli $connection, array $state): int
    {
        $email = strtolower(trim((string) ($state['current_user']['email'] ?? '')));
        if ($email === '') {
            return 0;
        }
        $stmt = $connection->prepare("SELECT id_user FROM cv_backend_users WHERE email = ? LIMIT 1");
        if (!$stmt instanceof mysqli_stmt) {
            return 0;
        }
        $stmt->bind_param('s', $email);
        if (!$stmt->execute()) {
            $stmt->close();
            return 0;
        }
        $result = $stmt->get_result();
        $idUser = 0;
        if ($result instanceof mysqli_result) {
            $row = $result->fetch_assoc();
            if (is_array($row)) {
                $idUser = (int) ($row['id_user'] ?? 0);
            }
            $result->free();
        }
        $stmt->close();
        return $idUser;
    }
}

$state = cvAccessoInit();
if (!$state['authenticated']) {
    cvAccessoRenderLoginPage($state);
    return;
}

$providers = [];
$tickets = [];
$totalRows = 0;
$ticketLogs = [];
$ticketForEdit = null;
$editTicketId = max(0, (int) ($_GET['edit_id'] ?? 0));

$providerFilter = strtolower(trim((string) ($_GET['provider'] ?? '')));
$orderFilter = trim((string) ($_GET['order_code'] ?? ''));
$ticketFilter = trim((string) ($_GET['ticket_code'] ?? ''));
$paidFilter = trim((string) ($_GET['paid'] ?? 'all'));
$dateFrom = trim((string) ($_GET['from'] ?? ''));
$dateTo = trim((string) ($_GET['to'] ?? ''));
$departureFrom = trim((string) ($_GET['dep_from'] ?? ''));
$departureTo = trim((string) ($_GET['dep_to'] ?? ''));
$activeFiltersCount = 0;

try {
    $connection = cvAccessoRequireConnection();
    $providers = cvAccessoFilterProviders($state, cvCacheFetchProviders($connection));
    usort($providers, static function (array $a, array $b): int {
        return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
    });

    $allowedProviderCodes = [];
    foreach ($providers as $provider) {
        $code = strtolower(trim((string) ($provider['code'] ?? '')));
        if ($code !== '') {
            $allowedProviderCodes[$code] = true;
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ((string) ($_POST['action'] ?? '')) === 'ticket_update') {
        if (!cvAccessoValidateCsrf()) {
            $state['errors'][] = 'Sessione non valida. Ricarica la pagina e riprova.';
        } else {
            $postedTicketId = max(0, (int) ($_POST['ticket_id'] ?? 0));
            $ticketForUpdate = cvAccessoTicketFindById(
                $connection,
                $postedTicketId,
                cvAccessoIsAdmin($state),
                $allowedProviderCodes
            );

            if ($ticketForUpdate === null) {
                $state['errors'][] = 'Biglietto non trovato o non modificabile con il tuo account.';
            } else {
                $seatNumber = max(0, (int) ($_POST['seat_number'] ?? 0));
                $busNumber = max(0, (int) ($_POST['bus_number'] ?? 0));
                $priceRaw = (string) ($_POST['price'] ?? '');
                $commissionRaw = (string) ($_POST['commission'] ?? '');
                $noteValue = trim((string) ($_POST['note'] ?? ''));
                if (strlen($noteValue) > 255) {
                    $noteValue = substr($noteValue, 0, 255);
                }

                $statusValue = (int) ($_POST['status'] ?? 1);
                $paidValue = (int) ($_POST['paid'] ?? 1);
                $refundedValue = (int) ($_POST['refunded'] ?? 0);
                $statusValue = $statusValue === 1 ? 1 : 0;
                $paidValue = $paidValue === 1 ? 1 : 0;
                $refundedValue = $refundedValue === 1 ? 1 : 0;

                $price = cvAccessoTicketParseAmount($priceRaw);
                $commission = cvAccessoTicketParseAmount($commissionRaw);

                if ($price === null || $commission === null) {
                    $state['errors'][] = 'Prezzo o commissione non validi. Usa formato numerico (es. 3.40).';
                } elseif ($price < 0 || $commission < 0) {
                    $state['errors'][] = 'Prezzo e commissione non possono essere negativi.';
                } elseif ($commission > $price) {
                    $state['errors'][] = 'La commissione non puó superare il prezzo totale.';
                } else {
                    $changes = [];
                    $before = [
                        'posto' => (int) ($ticketForUpdate['posto'] ?? 0),
                        'bus_number' => (int) (($ticketForUpdate['mz_dt'] ?? 0) ?: ($ticketForUpdate['id_mz'] ?? 0)),
                        'prezzo' => round((float) ($ticketForUpdate['prezzo'] ?? 0), 2),
                        'prz_comm' => round((float) ($ticketForUpdate['prz_comm'] ?? 0), 2),
                        'stato' => (int) ($ticketForUpdate['stato'] ?? 0),
                        'pagato' => (int) ($ticketForUpdate['pagato'] ?? 0),
                        'rimborsato' => (int) ($ticketForUpdate['rimborsato'] ?? 0),
                        'note' => trim((string) ($ticketForUpdate['note'] ?? '')),
                    ];
                    $after = [
                        'posto' => $seatNumber,
                        'bus_number' => $busNumber,
                        'prezzo' => $price,
                        'prz_comm' => $commission,
                        'stato' => $statusValue,
                        'pagato' => $paidValue,
                        'rimborsato' => $refundedValue,
                        'note' => $noteValue,
                    ];

                    foreach ($after as $field => $newValue) {
                        $oldValue = $before[$field] ?? null;
                        if ((string) $oldValue !== (string) $newValue) {
                            $changes[$field] = ['from' => $oldValue, 'to' => $newValue];
                        }
                    }

                    if (count($changes) === 0) {
                        $state['messages'][] = 'Nessuna modifica da salvare.';
                    } else {
                        $connection->begin_transaction();
                        try {
                            $sql = "UPDATE biglietti
                                    SET posto = ?,
                                        mz_dt = ?,
                                        id_mz = ?,
                                        prezzo = ?,
                                        prz_comm = ?,
                                        stato = ?,
                                        pagato = ?,
                                        rimborsato = ?,
                                        note = ?,
                                        updated_at = NOW()
                                    WHERE id_bg = ?
                                    LIMIT 1";
                            $stmt = $connection->prepare($sql);
                            if (!$stmt instanceof mysqli_stmt) {
                                throw new RuntimeException('Prepare update biglietto fallita.');
                            }
                            $stmt->bind_param(
                                'iiiddiiisi',
                                $seatNumber,
                                $busNumber,
                                $busNumber,
                                $price,
                                $commission,
                                $statusValue,
                                $paidValue,
                                $refundedValue,
                                $noteValue,
                                $postedTicketId
                            );
                            if (!$stmt->execute()) {
                                $error = $stmt->error;
                                $stmt->close();
                                throw new RuntimeException('Update biglietto fallita: ' . $error);
                            }
                            $stmt->close();

                            $operatorId = cvAccessoTicketOperatorId($connection, $state);
                            $operatorEmail = strtolower(trim((string) ($state['current_user']['email'] ?? '')));
                            cvAccessoTicketInsertSnapshot($connection, $postedTicketId, [
                                'source' => 'accesso_admin_edit',
                                'created_at' => date('Y-m-d H:i:s'),
                                'operator_id' => $operatorId,
                                'operator_email' => $operatorEmail,
                                'before' => $before,
                                'after' => $after,
                                'changes' => $changes,
                            ]);
                            cvAccessoTicketInsertLog($connection, $postedTicketId, $operatorId, 'admin_update', [
                                'source' => 'accesso_admin_edit',
                                'created_at' => date('Y-m-d H:i:s'),
                                'operator_id' => $operatorId,
                                'operator_email' => $operatorEmail,
                                'changes' => $changes,
                            ]);

                            $connection->commit();
                            $state['messages'][] = 'Biglietto aggiornato e storico registrato.';
                        } catch (Throwable $exception) {
                            $connection->rollback();
                            $state['errors'][] = $exception->getMessage();
                        }
                    }
                }
            }
            $editTicketId = $postedTicketId;
        }
    }

    $where = ['1=1'];
    if (!cvAccessoIsAdmin($state)) {
        if (count($allowedProviderCodes) === 0) {
            $where[] = '1=0';
        } else {
            $providerIn = [];
            foreach (array_keys($allowedProviderCodes) as $code) {
                $providerIn[] = "'" . $connection->real_escape_string($code) . "'";
            }
            $where[] = 'LOWER(a.code) IN (' . implode(', ', $providerIn) . ')';
        }
    }

    if ($providerFilter !== '') {
        if (cvAccessoIsAdmin($state) || isset($allowedProviderCodes[$providerFilter])) {
            $where[] = "LOWER(a.code) = '" . $connection->real_escape_string($providerFilter) . "'";
        }
    }

    if ($orderFilter !== '') {
        $escaped = $connection->real_escape_string($orderFilter);
        $where[] = "(b.note LIKE '%order:" . $escaped . "%' OR b.transaction_id LIKE '%" . $escaped . "%' OR b.txn_id LIKE '%" . $escaped . "%')";
    }

    if ($ticketFilter !== '') {
        $escaped = $connection->real_escape_string($ticketFilter);
        $where[] = "b.codice LIKE '%" . $escaped . "%'";
    }

    if ($paidFilter === '1' || $paidFilter === '0') {
        $where[] = 'b.pagato = ' . (int) $paidFilter;
    } else {
        $paidFilter = 'all';
    }

    if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
        $where[] = "DATE(b.acquistato) >= '" . $connection->real_escape_string($dateFrom) . "'";
    } else {
        $dateFrom = '';
    }

    if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        $where[] = "DATE(b.acquistato) <= '" . $connection->real_escape_string($dateTo) . "'";
    } else {
        $dateTo = '';
    }

    if ($departureFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $departureFrom)) {
        $where[] = "DATE(b.data) >= '" . $connection->real_escape_string($departureFrom) . "'";
    } else {
        $departureFrom = '';
    }

    if ($departureTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $departureTo)) {
        $where[] = "DATE(b.data) <= '" . $connection->real_escape_string($departureTo) . "'";
    } else {
        $departureTo = '';
    }

    $activeFiltersCount += $providerFilter !== '' ? 1 : 0;
    $activeFiltersCount += $orderFilter !== '' ? 1 : 0;
    $activeFiltersCount += $ticketFilter !== '' ? 1 : 0;
    $activeFiltersCount += $paidFilter !== 'all' ? 1 : 0;
    $activeFiltersCount += $dateFrom !== '' ? 1 : 0;
    $activeFiltersCount += $dateTo !== '' ? 1 : 0;
    $activeFiltersCount += $departureFrom !== '' ? 1 : 0;
    $activeFiltersCount += $departureTo !== '' ? 1 : 0;

    $whereSql = implode(' AND ', $where);
    $sql = "SELECT
                b.id_bg,
                b.codice,
                b.transaction_id,
                b.txn_id,
                b.id_mz,
                b.mz_dt,
                b.id_corsa,
                b.posto,
                b.codice_camb,
                b.camb,
                b.prezzo,
                b.prz_comm,
                b.pagato,
                b.stato,
                b.data,
                b.data2,
                b.acquistato,
                b.note,
                v.email AS user_email,
                COALESCE(
                    NULLIF(TRIM(CONCAT(vgt.nome, ' ', vgt.cognome)), ''),
                    NULLIF(TRIM(CONCAT(v.nome, ' ', v.cognome)), ''),
                    '-'
                ) AS passenger_name,
                a.code AS provider_code,
                a.nome AS provider_name,
                s1.nome AS from_name,
                s2.nome AS to_name
            FROM biglietti AS b
            LEFT JOIN aziende AS a ON a.id_az = b.id_az
            LEFT JOIN tratte_sottoc AS s1 ON s1.id_sott = b.id_sott1
            LEFT JOIN tratte_sottoc AS s2 ON s2.id_sott = b.id_sott2
            LEFT JOIN viaggiatori AS v ON v.id_vg = b.id_vg
            LEFT JOIN viaggiatori_temp AS vgt ON vgt.id_vgt = b.id_vgt
            WHERE {$whereSql}
            ORDER BY b.acquistato DESC, b.id_bg DESC
            LIMIT 500";

    $result = $connection->query($sql);
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            if (!is_array($row)) {
                continue;
            }
            $tickets[] = $row;
        }
        $totalRows = count($tickets);
        $result->free();
    }

    if ($editTicketId > 0) {
        $ticketForEdit = cvAccessoTicketFindById(
            $connection,
            $editTicketId,
            cvAccessoIsAdmin($state),
            $allowedProviderCodes
        );
        if ($ticketForEdit === null) {
            $state['errors'][] = 'Biglietto selezionato non disponibile.';
            $editTicketId = 0;
        } else {
            $logSql = "SELECT id_b_log, id_utop, operazione, payload, created_at
                       FROM biglietti_log
                       WHERE id_bg = " . $editTicketId . "
                       ORDER BY created_at DESC, id_b_log DESC
                       LIMIT 200";
            $logResult = $connection->query($logSql);
            if ($logResult instanceof mysqli_result) {
                while ($logRow = $logResult->fetch_assoc()) {
                    if (!is_array($logRow)) {
                        continue;
                    }
                    $payload = cvAccessoTicketDecodePayload((string) ($logRow['payload'] ?? ''));
                    $ticketLogs[] = [
                        'id_b_log' => (int) ($logRow['id_b_log'] ?? 0),
                        'id_utop' => (int) ($logRow['id_utop'] ?? 0),
                        'operazione' => (string) ($logRow['operazione'] ?? ''),
                        'created_at' => (string) ($logRow['created_at'] ?? ''),
                        'payload' => $payload,
                    ];
                }
                $logResult->free();
            }
        }
    }
} catch (Throwable $exception) {
    $state['errors'][] = 'Errore sezione biglietti: ' . $exception->getMessage();
}

$purchaseRangeValue = '';
if ($dateFrom !== '' && $dateTo !== '') {
    $purchaseRangeValue = $dateFrom . ' to ' . $dateTo;
} elseif ($dateFrom !== '') {
    $purchaseRangeValue = $dateFrom;
} elseif ($dateTo !== '') {
    $purchaseRangeValue = $dateTo;
}

$departureRangeValue = '';
if ($departureFrom !== '' && $departureTo !== '') {
    $departureRangeValue = $departureFrom . ' to ' . $departureTo;
} elseif ($departureFrom !== '') {
    $departureRangeValue = $departureFrom;
} elseif ($departureTo !== '') {
    $departureRangeValue = $departureTo;
}

cvAccessoRenderPageStart('Storico biglietti', 'tickets-history', $state);
?>
<div class="row">
    <div class="col-md-12">
        <p class="cv-page-intro">
            Storico biglietti Cercaviaggio con filtri avanzati. Cerca il ticket e consulta/modifica i cambi con tracciatura completa.
        </p>
    </div>
</div>

<?php if ($ticketForEdit !== null): ?>
    <?php
    $currentBus = (int) (($ticketForEdit['mz_dt'] ?? 0) ?: ($ticketForEdit['id_mz'] ?? 0));
    $editContextQuery = http_build_query([
        'provider' => $providerFilter,
        'order_code' => $orderFilter,
        'ticket_code' => $ticketFilter,
        'paid' => $paidFilter,
        'from' => $dateFrom,
        'to' => $dateTo,
        'dep_from' => $departureFrom,
        'dep_to' => $departureTo,
        'edit_id' => $editTicketId,
    ]);
    ?>
    <div class="row">
        <div class="col-md-12">
            <div class="cv-panel-card">
                <div class="cv-panel-head">
                    <div>
                        <h4>Modifica biglietto #<?= (int) ($ticketForEdit['id_bg'] ?? 0) ?> - <?= cvAccessoH((string) ($ticketForEdit['codice'] ?? '-')) ?></h4>
                        <div class="cv-muted">
                            Provider: <?= cvAccessoH((string) ($ticketForEdit['provider_name'] ?? '-')) ?>
                            | Acquisto: <?= cvAccessoH((string) ($ticketForEdit['acquistato'] ?? '-')) ?>
                            | Partenza: <?= cvAccessoH((string) ($ticketForEdit['data'] ?? '-')) ?>
                        </div>
                    </div>
                    <a class="btn btn-default btn-sm" href="<?= cvAccessoH(cvAccessoUrl('biglietti-storico.php') . '?' . $editContextQuery) ?>">
                        Aggiorna
                    </a>
                </div>
                <form method="post" class="cv-form-grid" style="margin-top:12px;">
                    <?= cvAccessoCsrfField() ?>
                    <input type="hidden" name="action" value="ticket_update">
                    <input type="hidden" name="ticket_id" value="<?= (int) ($ticketForEdit['id_bg'] ?? 0) ?>">
                    <div class="row">
                        <div class="col-md-2 form-group">
                            <label for="edit_bus_number">Numero bus</label>
                            <input id="edit_bus_number" type="number" min="0" class="form-control" name="bus_number" value="<?= $currentBus ?>">
                        </div>
                        <div class="col-md-2 form-group">
                            <label for="edit_seat_number">Posto</label>
                            <input id="edit_seat_number" type="number" min="0" class="form-control" name="seat_number" value="<?= (int) ($ticketForEdit['posto'] ?? 0) ?>">
                        </div>
                        <div class="col-md-2 form-group">
                            <label for="edit_price">Prezzo totale</label>
                            <input id="edit_price" type="text" class="form-control" name="price" value="<?= number_format((float) ($ticketForEdit['prezzo'] ?? 0), 2, '.', '') ?>">
                        </div>
                        <div class="col-md-2 form-group">
                            <label for="edit_commission">Commissione CV</label>
                            <input id="edit_commission" type="text" class="form-control" name="commission" value="<?= number_format((float) ($ticketForEdit['prz_comm'] ?? 0), 2, '.', '') ?>">
                        </div>
                        <div class="col-md-2 form-group">
                            <label for="edit_status">Stato ticket</label>
                            <select id="edit_status" class="form-control" name="status">
                                <option value="1"<?= (int) ($ticketForEdit['stato'] ?? 0) === 1 ? ' selected' : '' ?>>Attivo</option>
                                <option value="0"<?= (int) ($ticketForEdit['stato'] ?? 0) !== 1 ? ' selected' : '' ?>>Annullato</option>
                            </select>
                        </div>
                        <div class="col-md-2 form-group">
                            <label for="edit_paid">Pagamento</label>
                            <select id="edit_paid" class="form-control" name="paid">
                                <option value="1"<?= (int) ($ticketForEdit['pagato'] ?? 0) === 1 ? ' selected' : '' ?>>Pagato</option>
                                <option value="0"<?= (int) ($ticketForEdit['pagato'] ?? 0) !== 1 ? ' selected' : '' ?>>Non pagato</option>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-2 form-group">
                            <label for="edit_refunded">Rimborsato</label>
                            <select id="edit_refunded" class="form-control" name="refunded">
                                <option value="0"<?= (int) ($ticketForEdit['rimborsato'] ?? 0) !== 1 ? ' selected' : '' ?>>No</option>
                                <option value="1"<?= (int) ($ticketForEdit['rimborsato'] ?? 0) === 1 ? ' selected' : '' ?>>Sì</option>
                            </select>
                        </div>
                        <div class="col-md-10 form-group">
                            <label for="edit_note">Note operative</label>
                            <input id="edit_note" type="text" maxlength="255" class="form-control" name="note" value="<?= cvAccessoH((string) ($ticketForEdit['note'] ?? '')) ?>" placeholder="Motivo modifica, rimborso, variazione posto...">
                        </div>
                    </div>
                    <div class="cv-inline-actions">
                        <button type="submit" class="btn btn-primary">Salva modifica</button>
                        <a class="btn btn-default" href="<?= cvAccessoH(cvAccessoUrl('biglietti-storico.php')) ?>">Chiudi modifica</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-12">
            <div class="cv-panel-card">
                <h4>Storico operazioni biglietto</h4>
                <div class="table-responsive" style="margin-top:12px;">
                    <table class="table table-striped table-bordered">
                        <thead>
                        <tr>
                            <th>Quando</th>
                            <th>Operazione</th>
                            <th>Operatore</th>
                            <th>Dettaglio</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (count($ticketLogs) === 0): ?>
                            <tr>
                                <td colspan="4" class="text-center cv-muted">Nessuna operazione registrata per questo biglietto.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($ticketLogs as $logEntry): ?>
                                <?php
                                $payload = isset($logEntry['payload']) && is_array($logEntry['payload']) ? $logEntry['payload'] : [];
                                $operatorEmail = trim((string) ($payload['operator_email'] ?? ''));
                                $changes = isset($payload['changes']) && is_array($payload['changes']) ? $payload['changes'] : [];
                                ?>
                                <tr>
                                    <td><?= cvAccessoH((string) ($logEntry['created_at'] ?? '-')) ?></td>
                                    <td><?= cvAccessoH((string) ($logEntry['operazione'] ?? '-')) ?></td>
                                    <td><?= cvAccessoH($operatorEmail !== '' ? $operatorEmail : ('ID ' . (int) ($logEntry['id_utop'] ?? 0))) ?></td>
                                    <td>
                                        <?php if (count($changes) > 0): ?>
                                            <?php foreach ($changes as $field => $change): ?>
                                                <div>
                                                    <strong><?= cvAccessoH((string) $field) ?></strong>:
                                                    <?= cvAccessoH((string) ($change['from'] ?? '-')) ?>
                                                    →
                                                    <?= cvAccessoH((string) ($change['to'] ?? '-')) ?>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <span class="cv-muted">Nessun dettaglio strutturato</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

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
                    data-cv-drawer-toggle="tickets-filter-drawer"
                    aria-controls="tickets-filter-drawer"
                    aria-expanded="false"
                >
                    <i class="fa fa-cog"></i>
                </button>
            </div>
        </div>
    </div>
</div>

<div class="cv-side-drawer-backdrop" data-cv-drawer-close="tickets-filter-drawer"></div>
<aside id="tickets-filter-drawer" class="cv-side-drawer" aria-hidden="true">
    <div class="cv-side-drawer-head">
        <h4>Filtri biglietti</h4>
        <button type="button" class="btn btn-default btn-sm" data-cv-drawer-close="tickets-filter-drawer">
            <i class="fa fa-times"></i>
        </button>
    </div>

    <form method="get" class="cv-form-grid">
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
            <label for="f_order_code">Order code</label>
            <input id="f_order_code" type="text" name="order_code" value="<?= cvAccessoH($orderFilter) ?>" class="form-control" placeholder="CV-...">
        </div>
        <div class="form-group">
            <label for="f_ticket_code">Codice ticket</label>
            <input id="f_ticket_code" type="text" name="ticket_code" value="<?= cvAccessoH($ticketFilter) ?>" class="form-control" placeholder="ABC123">
        </div>
        <div class="form-group">
            <label for="f_paid">Pagamento</label>
            <select id="f_paid" name="paid" class="form-control">
                <option value="all"<?= $paidFilter === 'all' ? ' selected' : '' ?>>Tutti</option>
                <option value="1"<?= $paidFilter === '1' ? ' selected' : '' ?>>Pagati</option>
                <option value="0"<?= $paidFilter === '0' ? ' selected' : '' ?>>Non pagati</option>
            </select>
        </div>
        <div class="form-group">
            <label for="f_purchase_range">Range acquisto</label>
            <input id="f_purchase_range" type="text" value="<?= cvAccessoH($purchaseRangeValue) ?>" class="form-control" placeholder="Seleziona intervallo acquisto" autocomplete="off">
            <input id="f_from" type="hidden" name="from" value="<?= cvAccessoH($dateFrom) ?>">
            <input id="f_to" type="hidden" name="to" value="<?= cvAccessoH($dateTo) ?>">
        </div>
        <div class="form-group">
            <label for="f_departure_range">Range partenza</label>
            <input id="f_departure_range" type="text" value="<?= cvAccessoH($departureRangeValue) ?>" class="form-control" placeholder="Seleziona intervallo partenza" autocomplete="off">
            <input id="f_dep_from" type="hidden" name="dep_from" value="<?= cvAccessoH($departureFrom) ?>">
            <input id="f_dep_to" type="hidden" name="dep_to" value="<?= cvAccessoH($departureTo) ?>">
        </div>
        <div class="cv-inline-actions">
            <button type="submit" class="btn btn-primary">Applica filtri</button>
            <a href="<?= cvAccessoH(cvAccessoUrl('biglietti-storico.php')) ?>" class="btn btn-default">Reset</a>
        </div>
    </form>
</aside>

<div class="row">
    <div class="col-md-12">
        <div class="cv-panel-card">
            <h4>Biglietti (<?= (int) $totalRows ?>)</h4>
            <div class="table-responsive" style="margin-top:12px;">
                <table class="table table-striped table-bordered">
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>Codice</th>
                        <th>Provider</th>
                        <th>Tratta</th>
                        <th>Partenza</th>
                        <th>Acquisto</th>
                        <th>Bus</th>
                        <th>Posto</th>
                        <th>Prezzo totale</th>
                        <th>Commissione CV</th>
                        <th>Ordine</th>
                        <th>Riferimenti</th>
                        <th>Passeggero</th>
                        <th>Cambi</th>
                        <th>Stato ticket</th>
                        <th>Utente</th>
                        <th>Pagamento</th>
                        <th>Azioni</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($totalRows === 0): ?>
                        <tr>
                            <td colspan="18" class="text-center cv-muted">Nessun biglietto trovato con i filtri selezionati.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($tickets as $ticket): ?>
                            <?php
                            $note = (string) ($ticket['note'] ?? '');
                            $orderCode = '-';
                            if ($note !== '' && preg_match('/order:([^;\\s]+)/i', $note, $m) === 1) {
                                $orderCode = strtoupper(trim((string) $m[1]));
                            }
                            $editLinkQuery = http_build_query([
                                'provider' => $providerFilter,
                                'order_code' => $orderFilter,
                                'ticket_code' => $ticketFilter,
                                'paid' => $paidFilter,
                                'from' => $dateFrom,
                                'to' => $dateTo,
                                'dep_from' => $departureFrom,
                                'dep_to' => $departureTo,
                                'edit_id' => (int) ($ticket['id_bg'] ?? 0),
                            ]);
                            ?>
                            <tr>
                                <td><?= (int) ($ticket['id_bg'] ?? 0) ?></td>
                                <td>
                                    <strong><?= cvAccessoH((string) ($ticket['codice'] ?? '-')) ?></strong>
                                    <div class="cv-muted" style="font-size:11px;">Cod. cambio: <?= cvAccessoH((string) ($ticket['codice_camb'] ?? '-')) ?></div>
                                </td>
                                <td><?= cvAccessoH((string) ($ticket['provider_name'] ?? $ticket['provider_code'] ?? '-')) ?></td>
                                <td><?= cvAccessoH((string) ($ticket['from_name'] ?? '-')) ?> → <?= cvAccessoH((string) ($ticket['to_name'] ?? '-')) ?></td>
                                <td><?= cvAccessoH((string) ($ticket['data'] ?? '-')) ?></td>
                                <td><?= cvAccessoH((string) ($ticket['acquistato'] ?? '-')) ?></td>
                                <?php $busNumber = (int) (($ticket['mz_dt'] ?? 0) ?: ($ticket['id_mz'] ?? 0)); ?>
                                <td><strong><?= $busNumber > 0 ? $busNumber : '-' ?></strong></td>
                                <td><strong><?= (int) ($ticket['posto'] ?? 0) > 0 ? (int) ($ticket['posto'] ?? 0) : '-' ?></strong></td>
                                <td>€ <?= number_format((float) ($ticket['prezzo'] ?? 0), 2, ',', '.') ?></td>
                                <td>€ <?= number_format((float) ($ticket['prz_comm'] ?? 0), 2, ',', '.') ?></td>
                                <td><?= cvAccessoH($orderCode) ?></td>
                                <td>
                                    <div>Shop ID: <strong><?= cvAccessoH((string) ($ticket['transaction_id'] ?? '-')) ?></strong></div>
                                    <div class="cv-muted" style="font-size:11px;">Txn gateway: <?= cvAccessoH((string) ($ticket['txn_id'] ?? '-')) ?></div>
                                </td>
                                <td><?= cvAccessoH((string) ($ticket['passenger_name'] ?? '-')) ?></td>
                                <td><?= max(0, (int) ($ticket['camb'] ?? 0)) ?></td>
                                <td>
                                    <?php if ((int) ($ticket['stato'] ?? 0) === 1): ?>
                                        <span class="label label-success">Attivo</span>
                                    <?php else: ?>
                                        <span class="label label-danger">Annullato</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= cvAccessoH((string) ($ticket['user_email'] ?? '-')) ?></td>
                                <td>
                                    <?php if ((int) ($ticket['pagato'] ?? 0) === 1): ?>
                                        <span class="label label-success">Pagato</span>
                                    <?php else: ?>
                                        <span class="label label-warning">Non pagato</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a class="btn btn-xs btn-primary" href="<?= cvAccessoH(cvAccessoUrl('biglietti-storico.php') . '?' . $editLinkQuery) ?>">
                                        Modifica
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    function formatYmd(date) {
        if (!(date instanceof Date) || Number.isNaN(date.getTime())) {
            return '';
        }
        var y = date.getFullYear();
        var m = String(date.getMonth() + 1).padStart(2, '0');
        var d = String(date.getDate()).padStart(2, '0');
        return y + '-' + m + '-' + d;
    }

    function initRangePicker(inputId, fromId, toId) {
        var input = document.getElementById(inputId);
        var fromEl = document.getElementById(fromId);
        var toEl = document.getElementById(toId);
        if (!input || !fromEl || !toEl || !window.flatpickr) {
            return;
        }

        if (window.flatpickr.l10ns && window.flatpickr.l10ns.it) {
            window.flatpickr.localize(window.flatpickr.l10ns.it);
        }

        window.flatpickr(input, {
            mode: 'range',
            dateFormat: 'Y-m-d',
            disableMobile: true,
            monthSelectorType: 'static',
            prevArrow: '<i class="fa fa-angle-left"></i>',
            nextArrow: '<i class="fa fa-angle-right"></i>',
            onReady: function (selectedDates, dateStr, instance) {
                if (instance && instance.calendarContainer) {
                    instance.calendarContainer.classList.add('cv-flatpickr-popup');
                }
            },
            onChange: function (selectedDates) {
                var start = selectedDates[0] || null;
                var end = selectedDates.length > 1 ? selectedDates[1] : null;
                fromEl.value = formatYmd(start);
                toEl.value = formatYmd(end);
            },
            onClose: function (selectedDates) {
                var start = selectedDates[0] || null;
                var end = selectedDates.length > 1 ? selectedDates[1] : null;
                fromEl.value = formatYmd(start);
                toEl.value = formatYmd(end);
            }
        });

        input.addEventListener('keydown', function (event) {
            if (event.key === 'Backspace' || event.key === 'Delete') {
                fromEl.value = '';
                toEl.value = '';
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initRangePicker('f_purchase_range', 'f_from', 'f_to');
        initRangePicker('f_departure_range', 'f_dep_from', 'f_dep_to');
    });
}());
</script>

<?php
cvAccessoRenderPageEnd();
