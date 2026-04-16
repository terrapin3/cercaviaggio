<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';

/**
 * @return array{
 *   providers:array<int,array<string,mixed>>,
 *   aziende_by_code:array<string,array<string,mixed>>,
 *   provider_commission_percents:array<string,float>,
 *   payment_settings:array<string,mixed>,
 *   home_popular_provider_codes:array<int,string>,
 *   home_popular_provider_limits:array<string,int>,
 *   provider_price_modes:array<string,string>,
 *   ticket_pdf_show_email_map:array<string,int>,
 *   ticket_pdf_show_site_map:array<string,int>,
 *   ticket_pdf_site_url_map:array<string,string>,
 *   default_home_popular_per_provider:int
 * }
 */
function cvProvidersLoadDataset(mysqli $connection, array $state): array
{
    $providers = cvAccessoFilterProviders($state, cvCacheFetchProviders($connection));
    usort($providers, static function (array $left, array $right): int {
        return strcasecmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
    });

    if (cvAccessoIsAdmin($state) && count($providers) > 0) {
        $valuesSql = [];
        foreach ($providers as $provider) {
            $code = strtolower(trim((string) ($provider['code'] ?? '')));
            $name = trim((string) ($provider['name'] ?? $code));
            if ($code === '' || $name === '') {
                continue;
            }
            $isActive = (int) ($provider['is_active'] ?? 0) === 1 ? 1 : 0;
            $valuesSql[] = sprintf(
                "('%s','%s',%d)",
                $connection->real_escape_string($code),
                $connection->real_escape_string($name),
                $isActive
            );
        }

        if (count($valuesSql) > 0) {
            $syncSql = "INSERT INTO aziende (code, nome, stato) VALUES " . implode(',', $valuesSql) . "
                        ON DUPLICATE KEY UPDATE
                            nome = VALUES(nome),
                            stato = VALUES(stato)";
            $connection->query($syncSql);
        }
    }

    $providerCodeMap = [];
    foreach ($providers as $provider) {
        $code = strtolower(trim((string) ($provider['code'] ?? '')));
        if ($code !== '') {
            $providerCodeMap[$code] = true;
        }
    }

    $aziendeByCode = [];
    $aziendeSql = "SELECT id_az, code, nome, stato, ind, tel, email_pg, pi, recapiti, updated_at
                   FROM aziende
                   ORDER BY id_az ASC";
    $aziendeResult = $connection->query($aziendeSql);
    if ($aziendeResult instanceof mysqli_result) {
        while ($row = $aziendeResult->fetch_assoc()) {
            if (!is_array($row)) {
                continue;
            }
            $rowCode = strtolower(trim((string) ($row['code'] ?? '')));
            if ($rowCode === '') {
                continue;
            }
            if (!cvAccessoIsAdmin($state) && !isset($providerCodeMap[$rowCode])) {
                continue;
            }
            $aziendeByCode[$rowCode] = $row;
        }
        $aziendeResult->free();
    }

    $paymentSettings = cvRuntimePaymentSettings($connection);
    $providerCommissionPercents = cvRuntimeSettingJsonFloatMap($paymentSettings['checkout_provider_commission_percent'] ?? '');
    $generalSettings = cvRuntimeSettings($connection);
    $homePopularProviderCodes = cvRuntimeSettingCsvList($generalSettings['homepage_popular_provider_codes'] ?? '');
    $homePopularProviderLimits = cvRuntimeSettingJsonMap($generalSettings['homepage_popular_provider_limits'] ?? '');
    $providerPriceModes = cvRuntimeSettingProviderPriceModes($generalSettings['provider_price_modes'] ?? '');
    $ticketPdfShowEmailMap = cvRuntimeSettingJsonIntMap($generalSettings['ticket_pdf_provider_show_email_map'] ?? '');
    $ticketPdfShowSiteMap = cvRuntimeSettingJsonIntMap($generalSettings['ticket_pdf_provider_show_site_map'] ?? '');
    $ticketPdfSiteUrlMap = cvRuntimeSettingJsonStringMap($generalSettings['ticket_pdf_provider_site_map'] ?? '');
    $defaultHomePopularPerProvider = isset($generalSettings['homepage_popular_per_provider'])
        ? max(0, (int) $generalSettings['homepage_popular_per_provider'])
        : 4;

    return [
        'providers' => $providers,
        'aziende_by_code' => $aziendeByCode,
        'provider_commission_percents' => $providerCommissionPercents,
        'payment_settings' => $paymentSettings,
        'home_popular_provider_codes' => $homePopularProviderCodes,
        'home_popular_provider_limits' => $homePopularProviderLimits,
        'provider_price_modes' => $providerPriceModes,
        'ticket_pdf_show_email_map' => $ticketPdfShowEmailMap,
        'ticket_pdf_show_site_map' => $ticketPdfShowSiteMap,
        'ticket_pdf_site_url_map' => $ticketPdfSiteUrlMap,
        'default_home_popular_per_provider' => $defaultHomePopularPerProvider,
    ];
}

/**
 * Allinea (upsert) la tabella aziende sul provider.
 * Mantiene eventuali altri campi già presenti senza sovrascriverli.
 */
function cvProvidersSyncAziendaFromProvider(
    mysqli $connection,
    string $providerCode,
    string $providerName,
    int $providerIsActive,
    string $oldProviderCode = '',
    array $aziendaDetails = []
): array {
    $providerCode = strtolower(trim($providerCode));
    $providerName = trim($providerName);
    $oldProviderCode = strtolower(trim($oldProviderCode));
    $providerIsActive = $providerIsActive === 1 ? 1 : 0;
    $aziendaInd = trim((string) ($aziendaDetails['ind'] ?? ''));
    $aziendaTel = trim((string) ($aziendaDetails['tel'] ?? ''));
    $aziendaEmailPg = trim((string) ($aziendaDetails['email_pg'] ?? ''));
    $aziendaPi = trim((string) ($aziendaDetails['pi'] ?? ''));
    $aziendaRecapiti = trim((string) ($aziendaDetails['recapiti'] ?? ''));

    if ($aziendaInd === '') {
        $aziendaInd = '-';
    }
    if ($aziendaTel === '') {
        $aziendaTel = '-';
    }
    if ($aziendaEmailPg === '') {
        $aziendaEmailPg = '-';
    }
    if ($aziendaPi === '') {
        $aziendaPi = '-';
    }
    if ($aziendaRecapiti === '') {
        $aziendaRecapiti = '-';
    }

    if ($providerCode === '' || $providerName === '') {
        return ['ok' => false, 'error' => 'Code/nome provider non validi per sync aziende.'];
    }

    $idAzienda = 0;
    $lookupByNew = $connection->prepare("SELECT id_az FROM aziende WHERE code = ? LIMIT 1");
    if ($lookupByNew instanceof mysqli_stmt) {
        $lookupByNew->bind_param('s', $providerCode);
        if ($lookupByNew->execute()) {
            $res = $lookupByNew->get_result();
            if ($res instanceof mysqli_result && ($row = $res->fetch_assoc())) {
                $idAzienda = (int) ($row['id_az'] ?? 0);
            }
            if ($res instanceof mysqli_result) {
                $res->free();
            }
        }
        $lookupByNew->close();
    }

    if ($idAzienda <= 0 && $oldProviderCode !== '' && $oldProviderCode !== $providerCode) {
        $lookupByOld = $connection->prepare("SELECT id_az FROM aziende WHERE code = ? LIMIT 1");
        if ($lookupByOld instanceof mysqli_stmt) {
            $lookupByOld->bind_param('s', $oldProviderCode);
            if ($lookupByOld->execute()) {
                $res = $lookupByOld->get_result();
                if ($res instanceof mysqli_result && ($row = $res->fetch_assoc())) {
                    $idAzienda = (int) ($row['id_az'] ?? 0);
                }
                if ($res instanceof mysqli_result) {
                    $res->free();
                }
            }
            $lookupByOld->close();
        }
    }

    if ($idAzienda > 0) {
        $update = $connection->prepare(
            "UPDATE aziende
             SET code = ?, nome = ?, stato = ?, ind = ?, tel = ?, email_pg = ?, pi = ?, recapiti = ?
             WHERE id_az = ?
             LIMIT 1"
        );
        if (!$update instanceof mysqli_stmt) {
            return ['ok' => false, 'error' => 'Prepare update aziende fallito.'];
        }
        $update->bind_param(
            'ssisssssi',
            $providerCode,
            $providerName,
            $providerIsActive,
            $aziendaInd,
            $aziendaTel,
            $aziendaEmailPg,
            $aziendaPi,
            $aziendaRecapiti,
            $idAzienda
        );
        $ok = $update->execute();
        $error = $update->error;
        $update->close();
        if (!$ok) {
            return ['ok' => false, 'error' => 'Update aziende fallito: ' . $error];
        }
        return ['ok' => true, 'action' => 'update', 'id_az' => $idAzienda];
    }

    $insert = $connection->prepare(
        "INSERT INTO aziende (code, nome, stato, ind, tel, email_pg, pi, recapiti)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );
    if (!$insert instanceof mysqli_stmt) {
        return ['ok' => false, 'error' => 'Prepare insert aziende fallito.'];
    }
    $insert->bind_param(
        'ssisssss',
        $providerCode,
        $providerName,
        $providerIsActive,
        $aziendaInd,
        $aziendaTel,
        $aziendaEmailPg,
        $aziendaPi,
        $aziendaRecapiti
    );
    $ok = $insert->execute();
    $newId = (int) $insert->insert_id;
    $error = $insert->error;
    $insert->close();
    if (!$ok) {
        return ['ok' => false, 'error' => 'Insert aziende fallito: ' . $error];
    }

    return ['ok' => true, 'action' => 'insert', 'id_az' => $newId];
}

$state = cvAccessoInit();
if (!$state['authenticated']) {
    cvAccessoRenderLoginPage($state);
    return;
}

$dataset = [
    'providers' => [],
    'aziende_by_code' => [],
    'provider_commission_percents' => [],
    'payment_settings' => [],
    'home_popular_provider_codes' => [],
    'home_popular_provider_limits' => [],
    'provider_price_modes' => [],
    'ticket_pdf_show_email_map' => [],
    'ticket_pdf_show_site_map' => [],
    'ticket_pdf_site_url_map' => [],
    'default_home_popular_per_provider' => 4,
];

try {
    $connection = cvAccessoRequireConnection();
    $dataset = cvProvidersLoadDataset($connection, $state);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!cvAccessoValidateCsrf()) {
            $state['errors'][] = 'Sessione non valida. Ricarica la pagina.';
        } elseif (!cvAccessoIsAdmin($state)) {
            $state['errors'][] = 'Solo l’amministratore può modificare provider e aziende.';
        } else {
            $action = trim((string) ($_POST['action'] ?? ''));

            if ($action === 'add_provider') {
                $code = strtolower(trim((string) ($_POST['provider_code_new'] ?? '')));
                $name = trim((string) ($_POST['provider_name_new'] ?? ''));
                $baseUrl = trim((string) ($_POST['provider_base_url_new'] ?? ''));
                $isActive = ((int) ($_POST['provider_is_active_new'] ?? 1)) > 0 ? 1 : 0;
                $integrationMode = strtolower(trim((string) ($_POST['provider_integration_mode_new'] ?? 'api')));
                if ($integrationMode !== 'manual') {
                    $integrationMode = 'api';
                }
                $manualMaxLines = max(0, (int) ($_POST['provider_manual_max_lines_new'] ?? 0));
                $manualMaxTrips = max(0, (int) ($_POST['provider_manual_max_trips_new'] ?? 0));
                $aziendaDetails = [
                    'ind' => trim((string) ($_POST['provider_company_ind_new'] ?? '')),
                    'tel' => trim((string) ($_POST['provider_company_tel_new'] ?? '')),
                    'email_pg' => trim((string) ($_POST['provider_company_email_new'] ?? '')),
                    'pi' => trim((string) ($_POST['provider_company_pi_new'] ?? '')),
                    'recapiti' => trim((string) ($_POST['provider_company_recapiti_new'] ?? '')),
                ];

                if ($integrationMode === 'manual') {
                    $baseUrl = '';
                }

                if ($code === '' || $name === '' || ($integrationMode === 'api' && $baseUrl === '')) {
                    $state['errors'][] = $integrationMode === 'api'
                        ? 'Code, nome ed endpoint API provider sono obbligatori.'
                        : 'Code e nome provider sono obbligatori.';
                } else {
                    $insertProvider = $connection->prepare(
                        "INSERT INTO cv_providers (code, name, base_url, api_key, integration_mode, manual_max_lines, manual_max_trips, is_active)
                         VALUES (?, ?, ?, '', ?, ?, ?, ?)"
                    );
                    if (!$insertProvider instanceof mysqli_stmt) {
                        $state['errors'][] = 'Provider: errore prepare INSERT.';
                    } else {
                        $insertProvider->bind_param('ssssiii', $code, $name, $baseUrl, $integrationMode, $manualMaxLines, $manualMaxTrips, $isActive);
                        if (!$insertProvider->execute()) {
                            $state['errors'][] = 'Provider: inserimento fallito (' . $insertProvider->error . ').';
                        } else {
                            $syncResult = cvProvidersSyncAziendaFromProvider($connection, $code, $name, $isActive, '', $aziendaDetails);
                            if (!(bool) ($syncResult['ok'] ?? false)) {
                                $state['errors'][] = 'Provider creato ma sync aziende fallito: ' . (string) ($syncResult['error'] ?? 'errore sconosciuto');
                            } else {
                                $state['messages'][] = 'Provider/azienda aggiunto e allineato: ' . $code . '.';
                            }
                        }
                        $insertProvider->close();
                    }
                }
            } elseif ($action === 'save_provider') {
                $providerId = (int) ($_POST['provider_id'] ?? 0);
                $code = strtolower(trim((string) ($_POST['provider_code'] ?? '')));
                $name = trim((string) ($_POST['provider_name'] ?? ''));
                $baseUrl = trim((string) ($_POST['provider_base_url'] ?? ''));
                $isActive = ((int) ($_POST['provider_is_active'] ?? 0)) > 0 ? 1 : 0;
                $integrationMode = strtolower(trim((string) ($_POST['provider_integration_mode'] ?? 'api')));
                if ($integrationMode !== 'manual') {
                    $integrationMode = 'api';
                }
                $manualMaxLines = max(0, (int) ($_POST['provider_manual_max_lines'] ?? 0));
                $manualMaxTrips = max(0, (int) ($_POST['provider_manual_max_trips'] ?? 0));
                $aziendaDetails = [
                    'ind' => trim((string) ($_POST['provider_company_ind'] ?? '')),
                    'tel' => trim((string) ($_POST['provider_company_tel'] ?? '')),
                    'email_pg' => trim((string) ($_POST['provider_company_email'] ?? '')),
                    'pi' => trim((string) ($_POST['provider_company_pi'] ?? '')),
                    'recapiti' => trim((string) ($_POST['provider_company_recapiti'] ?? '')),
                ];

                if ($integrationMode === 'manual') {
                    $baseUrl = '';
                }

                if ($providerId <= 0 || $code === '' || $name === '' || ($integrationMode === 'api' && $baseUrl === '')) {
                    $state['errors'][] = 'Dati provider non validi.';
                } else {
                    $oldCode = '';
                    $providerCodeStmt = $connection->prepare(
                        "SELECT code
                         FROM cv_providers
                         WHERE id_provider = ?
                         LIMIT 1"
                    );
                    if ($providerCodeStmt instanceof mysqli_stmt) {
                        $providerCodeStmt->bind_param('i', $providerId);
                        if ($providerCodeStmt->execute()) {
                            $res = $providerCodeStmt->get_result();
                            if ($res instanceof mysqli_result && ($row = $res->fetch_assoc())) {
                                $oldCode = strtolower(trim((string) ($row['code'] ?? '')));
                            }
                            if ($res instanceof mysqli_result) {
                                $res->free();
                            }
                        }
                        $providerCodeStmt->close();
                    }

                    $updateProvider = $connection->prepare(
                        "UPDATE cv_providers
                         SET code = ?, name = ?, base_url = ?, integration_mode = ?, manual_max_lines = ?, manual_max_trips = ?, is_active = ?
                         WHERE id_provider = ?
                         LIMIT 1"
                    );
                    if (!$updateProvider instanceof mysqli_stmt) {
                        $state['errors'][] = 'Provider: errore prepare UPDATE.';
                    } else {
                        $updateProvider->bind_param('ssssiiii', $code, $name, $baseUrl, $integrationMode, $manualMaxLines, $manualMaxTrips, $isActive, $providerId);
                        if (!$updateProvider->execute()) {
                            $state['errors'][] = 'Provider: update fallito (' . $updateProvider->error . ').';
                        } else {
                            $syncResult = cvProvidersSyncAziendaFromProvider($connection, $code, $name, $isActive, $oldCode, $aziendaDetails);
                            if (!(bool) ($syncResult['ok'] ?? false)) {
                                $state['errors'][] = 'Provider aggiornato ma sync aziende fallito: ' . (string) ($syncResult['error'] ?? 'errore sconosciuto');
                            } else {
                                $state['messages'][] = 'Provider/azienda aggiornato: ' . $code . '.';
                            }
                        }
                        $updateProvider->close();
                    }
                }
            } elseif ($action === 'purge_manual_provider_content') {
                $providerId = (int) ($_POST['provider_id'] ?? 0);
                $providerCode = strtolower(trim((string) ($_POST['provider_code'] ?? '')));
                $confirm = strtoupper(trim((string) ($_POST['confirm_token'] ?? '')));

                if ($providerId <= 0 || $providerCode === '') {
                    $state['errors'][] = 'Provider non valido per pulizia contenuti.';
                } elseif ($confirm !== 'ELIMINA') {
                    $state['errors'][] = 'Conferma non valida. Scrivi ELIMINA per procedere.';
                } else {
                    $stmt = $connection->prepare(
                        "SELECT id_provider, code, integration_mode
                         FROM cv_providers
                         WHERE id_provider = ? AND code = ?
                         LIMIT 1"
                    );
                    $row = null;
                    if ($stmt instanceof mysqli_stmt) {
                        $stmt->bind_param('is', $providerId, $providerCode);
                        if ($stmt->execute()) {
                            $res = $stmt->get_result();
                            if ($res instanceof mysqli_result) {
                                $row = $res->fetch_assoc();
                                $res->free();
                            }
                        }
                        $stmt->close();
                    }

                    $mode = is_array($row) ? strtolower(trim((string) ($row['integration_mode'] ?? 'api'))) : 'api';
                    if (!is_array($row) || $mode !== 'manual') {
                        $state['errors'][] = 'La pulizia è disponibile solo per provider con Integrazione = Interfaccia.';
                    } else {
                        $connection->begin_transaction();
                        try {
                            $tables = [
                                'cv_provider_trip_stops',
                                'cv_provider_trips',
                                'cv_provider_fares',
                                'cv_provider_lines',
                                'cv_provider_stops',
                            ];

                            foreach ($tables as $table) {
                                $sql = "DELETE FROM {$table} WHERE id_provider = " . (int) $providerId;
                                if (!$connection->query($sql)) {
                                    throw new RuntimeException('Errore pulizia tabella ' . $table . ': ' . $connection->error);
                                }
                            }

                            $connection->commit();
                            $state['messages'][] = 'Contenuti provider eliminati (stops/linee/corse/tariffe).';
                        } catch (Throwable $e) {
                            $connection->rollback();
                            $state['errors'][] = 'Pulizia fallita: ' . $e->getMessage();
                        }
                    }
                }
            } elseif ($action === 'save_provider_commission') {
                $providerCode = strtolower(trim((string) ($_POST['provider_code'] ?? '')));
                $value = isset($_POST['commission_percent']) && is_numeric($_POST['commission_percent'])
                    ? (float) $_POST['commission_percent']
                    : 0.0;

                if ($providerCode === '') {
                    $state['errors'][] = 'Commissione provider: codice non valido.';
                } else {
                    $nextMap = cvRuntimeSettingJsonFloatMap($dataset['payment_settings']['checkout_provider_commission_percent'] ?? '');
                    foreach ($dataset['providers'] as $providerRow) {
                        $code = strtolower(trim((string) ($providerRow['code'] ?? '')));
                        if ($code === '' || isset($nextMap[$code])) {
                            continue;
                        }
                        $nextMap[$code] = 0.0;
                    }
                    $nextMap[$providerCode] = max(0.0, min(100.0, round($value, 4)));
                    $dataset['payment_settings']['checkout_provider_commission_percent'] = json_encode($nextMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $dataset['payment_settings'] = cvRuntimeSavePaymentSettings($connection, $dataset['payment_settings']);
                    $state['messages'][] = 'Commissione aggiornata per provider ' . $providerCode . '.';
                }
            } elseif ($action === 'save_provider_home_settings') {
                $providerCode = strtolower(trim((string) ($_POST['provider_code'] ?? '')));
                if ($providerCode === '') {
                    $state['errors'][] = 'Provider non valido per i settaggi home/prezzi.';
                } else {
                    $providerExists = false;
                    foreach ($dataset['providers'] as $providerRow) {
                        $code = strtolower(trim((string) ($providerRow['code'] ?? '')));
                        if ($code === $providerCode) {
                            $providerExists = true;
                            break;
                        }
                    }

                    if (!$providerExists) {
                        $state['errors'][] = 'Provider non trovato: ' . $providerCode . '.';
                    } else {
                        $generalSettings = cvRuntimeSettings($connection);
                        $selectedCodes = cvRuntimeSettingCsvList($generalSettings['homepage_popular_provider_codes'] ?? '');
                        $selectedMap = [];
                        foreach ($selectedCodes as $selectedCode) {
                            $selectedCode = strtolower(trim((string) $selectedCode));
                            if ($selectedCode === '') {
                                continue;
                            }
                            $selectedMap[$selectedCode] = $selectedCode;
                        }

                        $isHomeEnabled = !empty($_POST['homepage_popular_provider_enabled']);
                        if ($isHomeEnabled) {
                            $selectedMap[$providerCode] = $providerCode;
                        } else {
                            unset($selectedMap[$providerCode]);
                        }

                        $limit = isset($_POST['homepage_popular_provider_limit']) && is_numeric($_POST['homepage_popular_provider_limit'])
                            ? (int) round((float) $_POST['homepage_popular_provider_limit'])
                            : ((int) ($dataset['default_home_popular_per_provider'] ?? 4));
                        $limit = max(0, min(200, $limit));

                        $limitsMap = cvRuntimeSettingJsonMap($generalSettings['homepage_popular_provider_limits'] ?? '');
                        $limitsMap[$providerCode] = $limit;

                        $priceModesMap = cvRuntimeSettingProviderPriceModes($generalSettings['provider_price_modes'] ?? '');
                        $priceMode = cvRuntimeNormalizeProviderPriceMode((string) ($_POST['provider_price_mode'] ?? 'discounted'));
                        $priceModesMap[$providerCode] = $priceMode;

                        $pdfShowEmailMap = cvRuntimeSettingJsonIntMap($generalSettings['ticket_pdf_provider_show_email_map'] ?? '');
                        $pdfShowSiteMap = cvRuntimeSettingJsonIntMap($generalSettings['ticket_pdf_provider_show_site_map'] ?? '');
                        $pdfSiteMap = cvRuntimeSettingJsonStringMap($generalSettings['ticket_pdf_provider_site_map'] ?? '');
                        $pdfShowEmailMap[$providerCode] = !empty($_POST['ticket_pdf_show_email']) ? 1 : 0;
                        $pdfShowSiteMap[$providerCode] = !empty($_POST['ticket_pdf_show_site']) ? 1 : 0;

                        $siteUrl = trim((string) ($_POST['ticket_pdf_site_url'] ?? ''));
                        if ($siteUrl !== '' && !preg_match('#^https?://#i', $siteUrl)) {
                            $siteUrl = 'https://' . $siteUrl;
                        }
                        if ($siteUrl !== '' && filter_var($siteUrl, FILTER_VALIDATE_URL) === false) {
                            $state['errors'][] = 'URL sito non valida per provider ' . $providerCode . '.';
                            $siteUrl = '';
                        }
                        $pdfSiteMap[$providerCode] = $siteUrl;

                        $generalSettings['homepage_popular_provider_codes'] = cvRuntimeSettingCsvSerialize(array_values($selectedMap));
                        $generalSettings['homepage_popular_provider_limits'] = (string) json_encode($limitsMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        $generalSettings['provider_price_modes'] = (string) json_encode($priceModesMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        $generalSettings['ticket_pdf_provider_show_email_map'] = (string) json_encode($pdfShowEmailMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        $generalSettings['ticket_pdf_provider_show_site_map'] = (string) json_encode($pdfShowSiteMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        $generalSettings['ticket_pdf_provider_site_map'] = (string) json_encode($pdfSiteMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                        cvRuntimeSaveSettings($connection, $generalSettings);
                        $state['messages'][] = 'Settaggi home/prezzi aggiornati per provider ' . $providerCode . '.';
                    }
                }
            }
        }

        $dataset = cvProvidersLoadDataset($connection, $state);
    }
} catch (Throwable $exception) {
    $state['errors'][] = 'Errore sezione provider: ' . $exception->getMessage();
}

$providers = is_array($dataset['providers']) ? $dataset['providers'] : [];
$aziendeByCode = is_array($dataset['aziende_by_code']) ? $dataset['aziende_by_code'] : [];
$providerCommissionPercents = is_array($dataset['provider_commission_percents']) ? $dataset['provider_commission_percents'] : [];
$homePopularProviderCodes = is_array($dataset['home_popular_provider_codes']) ? $dataset['home_popular_provider_codes'] : [];
$homePopularProviderLimits = is_array($dataset['home_popular_provider_limits']) ? $dataset['home_popular_provider_limits'] : [];
$providerPriceModes = is_array($dataset['provider_price_modes']) ? $dataset['provider_price_modes'] : [];
$ticketPdfShowEmailMap = is_array($dataset['ticket_pdf_show_email_map']) ? $dataset['ticket_pdf_show_email_map'] : [];
$ticketPdfShowSiteMap = is_array($dataset['ticket_pdf_show_site_map']) ? $dataset['ticket_pdf_show_site_map'] : [];
$ticketPdfSiteUrlMap = is_array($dataset['ticket_pdf_site_url_map']) ? $dataset['ticket_pdf_site_url_map'] : [];
$defaultHomePopularPerProvider = isset($dataset['default_home_popular_per_provider']) ? (int) $dataset['default_home_popular_per_provider'] : 4;

cvAccessoRenderPageStart('Provider', 'providers', $state);
?>
<div class="row">
    <div class="col-md-12">
        <p class="cv-page-intro">
            In Cercaviaggio, <strong>provider API</strong> e <strong>azienda</strong> sono gestiti come un’unica entità logica.
            Questa pagina mantiene automaticamente allineate le tabelle <code>cv_providers</code> e <code>aziende</code> sullo stesso <code>code</code>.
        </p>
    </div>
</div>

<div class="row">
    <div class="col-md-12">
        <div class="cv-panel-card">
            <div class="cv-panel-head">
                <div>
                    <h4>Provider / Aziende</h4>
                    <div class="cv-muted">
                        <?= count($providers) ?> elementi nel perimetro di questo account.
                    </div>
                </div>
                <div class="cv-inline-actions">
                    <?php if (cvAccessoIsAdmin($state)): ?>
                        <button
                            type="button"
                            class="btn btn-primary btn-sm"
                            data-cv-drawer-toggle="providers-add-drawer"
                            aria-controls="providers-add-drawer"
                            aria-expanded="false"
                        >
                            <i class="fa fa-plus"></i> Aggiungi
                        </button>
                    <?php endif; ?>
                    <button
                        type="button"
                        class="btn btn-outline btn-primary cv-filter-trigger"
                        data-cv-drawer-toggle="providers-filter-drawer"
                        aria-controls="providers-filter-drawer"
                        aria-expanded="false"
                    >
                        <i class="fa fa-cog"></i>
                    </button>
                </div>
            </div>

            <?php if (count($providers) === 0): ?>
                <div class="cv-empty cv-provider-accordion-empty">Nessun provider disponibile.</div>
            <?php else: ?>
                <div id="providerAccordion" class="cv-provider-accordion">
                    <?php foreach ($providers as $index => $provider): ?>
                        <?php
                        $providerCode = strtolower(trim((string) ($provider['code'] ?? '')));
                        $providerName = trim((string) ($provider['name'] ?? $providerCode));
                        $providerId = (int) ($provider['id_provider'] ?? 0);
                        $providerIsActive = (int) ($provider['is_active'] ?? 0) === 1;
                        $providerBaseUrl = trim((string) ($provider['base_url'] ?? ''));
                        $providerIntegrationMode = strtolower(trim((string) ($provider['integration_mode'] ?? 'api'))) === 'manual' ? 'manual' : 'api';
                        $providerManualMaxLines = max(0, (int) ($provider['manual_max_lines'] ?? 0));
                        $providerManualMaxTrips = max(0, (int) ($provider['manual_max_trips'] ?? 0));
                        $mappedAzienda = $providerCode !== '' && isset($aziendeByCode[$providerCode]) ? $aziendeByCode[$providerCode] : null;
                        $mapped = is_array($mappedAzienda);
                        $commissionValue = isset($providerCommissionPercents[$providerCode]) ? (float) $providerCommissionPercents[$providerCode] : 0.0;
                        $isHomeEnabled = in_array($providerCode, $homePopularProviderCodes, true);
                        $homeLimitValue = isset($homePopularProviderLimits[$providerCode])
                            ? (int) $homePopularProviderLimits[$providerCode]
                            : $defaultHomePopularPerProvider;
                        $priceModeValue = cvRuntimeNormalizeProviderPriceMode((string) ($providerPriceModes[$providerCode] ?? 'discounted'));
                        $homeCheckboxId = 'provider_home_enabled_' . $providerId;
                        $homeLimitId = 'provider_home_limit_' . $providerId;
                        $homePriceModeId = 'provider_price_mode_' . $providerId;
                        $pdfShowEmailId = 'provider_pdf_email_' . $providerId;
                        $pdfShowSiteId = 'provider_pdf_site_' . $providerId;
                        $pdfSiteUrlId = 'provider_pdf_site_url_' . $providerId;
                        $integrationModeId = 'provider_integration_mode_' . $providerId;
                        $manualLimitsRowId = 'provider_manual_limits_' . $providerId;
                        $apiEndpointRowId = 'provider_api_endpoint_' . $providerId;
                        $pdfShowEmail = isset($ticketPdfShowEmailMap[$providerCode]) ? ((int) $ticketPdfShowEmailMap[$providerCode] === 1) : false;
                        $pdfShowSite = isset($ticketPdfShowSiteMap[$providerCode]) ? ((int) $ticketPdfShowSiteMap[$providerCode] === 1) : false;
                        $pdfSiteUrl = isset($ticketPdfSiteUrlMap[$providerCode]) ? trim((string) $ticketPdfSiteUrlMap[$providerCode]) : '';
                        $searchBlob = strtolower(trim($providerName . ' ' . $providerCode . ' ' . ($mapped ? (string) ($mappedAzienda['nome'] ?? '') : '')));
                        ?>
                        <details class="cv-provider-accordion-item" data-provider-card data-search="<?= cvAccessoH($searchBlob) ?>" <?= $index === 0 ? 'open' : '' ?>>
                            <summary class="cv-provider-accordion-toggle">
                                <span>
                                    <strong><?= cvAccessoH($providerName) ?></strong>
                                    <span class="cv-muted">(<?= cvAccessoH($providerCode) ?>)</span>
                                </span>
                                <span class="cv-provider-accordion-meta">
                                    <?php if ($providerIsActive): ?>
                                        <span class="cv-badge-active">Attivo</span>
                                    <?php else: ?>
                                        <span class="cv-badge-inactive">Disattivo</span>
                                    <?php endif; ?>
                                    <?php if ($providerIntegrationMode === 'manual'): ?>
                                        <span class="cv-badge-active">Interfaccia</span>
                                    <?php else: ?>
                                        <span class="cv-badge-inactive">API</span>
                                    <?php endif; ?>
                                    <?php if ($mapped): ?>
                                        <span class="cv-badge-active">Azienda allineata</span>
                                    <?php else: ?>
                                        <span class="cv-badge-inactive">Azienda non allineata</span>
                                    <?php endif; ?>
                                    <span class="cv-provider-chevron"><i class="fa fa-chevron-down"></i></span>
                                </span>
                            </summary>

                            <div class="cv-provider-accordion-body">
                                <div class="row">
                                    <div class="col-md-12">
                                        <h5>Configurazione principale</h5>
                                        <form method="post" class="cv-form-grid">
                                            <input type="hidden" name="action" value="save_provider">
                                            <input type="hidden" name="provider_id" value="<?= $providerId ?>">
                                            <?= cvAccessoCsrfField() ?>
                                            <div class="row">
                                                <div class="col-md-2 form-group">
                                                    <label>Code</label>
                                                    <input type="text" class="form-control" name="provider_code" value="<?= cvAccessoH((string) ($provider['code'] ?? '')) ?>"<?= cvAccessoIsAdmin($state) ? '' : ' readonly' ?>>
                                                </div>
                                                <div class="col-md-3 form-group">
                                                    <label>Nome</label>
                                                    <input type="text" class="form-control" name="provider_name" value="<?= cvAccessoH((string) ($provider['name'] ?? '')) ?>"<?= cvAccessoIsAdmin($state) ? '' : ' readonly' ?>>
                                                </div>
                                                <div class="col-md-2 form-group">
                                                    <label>Integrazione</label>
                                                    <select
                                                        id="<?= cvAccessoH($integrationModeId) ?>"
                                                        class="form-control"
                                                        name="provider_integration_mode"
                                                        data-cv-integration-mode
                                                        data-cv-target="<?= cvAccessoH($manualLimitsRowId) ?>"
                                                        data-cv-endpoint-target="<?= cvAccessoH($apiEndpointRowId) ?>"
                                                        <?= cvAccessoIsAdmin($state) ? '' : ' disabled' ?>
                                                    >
                                                        <option value="api"<?= $providerIntegrationMode === 'api' ? ' selected' : '' ?>>API</option>
                                                        <option value="manual"<?= $providerIntegrationMode === 'manual' ? ' selected' : '' ?>>Interfaccia</option>
                                                    </select>
                                                </div>
                                                <div class="col-md-3 form-group" id="<?= cvAccessoH($apiEndpointRowId) ?>"<?= $providerIntegrationMode === 'manual' ? ' style="display:none;"' : '' ?>>
                                                    <label>Endpoint API provider (solo API)</label>
                                                    <input type="text" class="form-control" name="provider_base_url" value="<?= cvAccessoH($providerBaseUrl) ?>"<?= cvAccessoIsAdmin($state) ? '' : ' readonly' ?>>
                                                    <?php if ($providerBaseUrl !== ''): ?>
                                                        <div class="cv-muted" style="margin-top:6px;">
                                                            URL attuale:
                                                            <a href="<?= cvAccessoH($providerBaseUrl) ?>" target="_blank" rel="noopener noreferrer">
                                                                apri endpoint
                                                            </a>
                                                        </div>
                                                    <?php else: ?>
                                                        <div class="cv-muted" style="margin-top:6px;">
                                                            Endpoint non configurato (ok per Interfaccia).
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="col-md-2 form-group">
                                                    <label>Stato</label>
                                                    <select class="form-control" name="provider_is_active"<?= cvAccessoIsAdmin($state) ? '' : ' disabled' ?>>
                                                        <option value="1"<?= $providerIsActive ? ' selected' : '' ?>>Attivo</option>
                                                        <option value="0"<?= !$providerIsActive ? ' selected' : '' ?>>Disattivo</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="row" id="<?= cvAccessoH($manualLimitsRowId) ?>"<?= $providerIntegrationMode === 'manual' ? '' : ' style="display:none;"' ?>>
                                                <div class="col-md-3 form-group">
                                                    <label>Limite linee (0 = illimitato)</label>
                                                    <input type="number" class="form-control" name="provider_manual_max_lines" min="0" step="1" value="<?= cvAccessoH((string) $providerManualMaxLines) ?>"<?= cvAccessoIsAdmin($state) ? '' : ' readonly' ?>>
                                                </div>
                                                <div class="col-md-3 form-group">
                                                    <label>Limite corse (0 = illimitato)</label>
                                                    <input type="number" class="form-control" name="provider_manual_max_trips" min="0" step="1" value="<?= cvAccessoH((string) $providerManualMaxTrips) ?>"<?= cvAccessoIsAdmin($state) ? '' : ' readonly' ?>>
                                                </div>
                                                <div class="col-md-6 form-group cv-muted" style="margin-top:28px;">
                                                    Limiti usati solo se <strong>Integrazione = Interfaccia</strong>.
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-4 form-group">
                                                    <label>Indirizzo azienda</label>
                                                    <input type="text" class="form-control" name="provider_company_ind" value="<?= cvAccessoH((string) ($mappedAzienda['ind'] ?? '')) ?>"<?= cvAccessoIsAdmin($state) ? '' : ' readonly' ?>>
                                                </div>
                                                <div class="col-md-2 form-group">
                                                    <label>Telefono</label>
                                                    <input type="text" class="form-control" name="provider_company_tel" value="<?= cvAccessoH((string) ($mappedAzienda['tel'] ?? '')) ?>"<?= cvAccessoIsAdmin($state) ? '' : ' readonly' ?>>
                                                </div>
                                                <div class="col-md-3 form-group">
                                                    <label>Email azienda</label>
                                                    <input type="email" class="form-control" name="provider_company_email" value="<?= cvAccessoH((string) ($mappedAzienda['email_pg'] ?? '')) ?>"<?= cvAccessoIsAdmin($state) ? '' : ' readonly' ?>>
                                                </div>
                                                <div class="col-md-3 form-group">
                                                    <label>P. IVA</label>
                                                    <input type="text" class="form-control" name="provider_company_pi" value="<?= cvAccessoH((string) ($mappedAzienda['pi'] ?? '')) ?>"<?= cvAccessoIsAdmin($state) ? '' : ' readonly' ?>>
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-12 form-group">
                                                    <label>Recapiti aggiuntivi</label>
                                                    <input type="text" class="form-control" name="provider_company_recapiti" value="<?= cvAccessoH((string) ($mappedAzienda['recapiti'] ?? '')) ?>"<?= cvAccessoIsAdmin($state) ? '' : ' readonly' ?>>
                                                </div>
                                            </div>
                                            <?php if (cvAccessoIsAdmin($state)): ?>
                                                <div class="cv-inline-actions">
                                                    <button type="submit" class="btn btn-default btn-sm">Salva configurazione</button>
                                                </div>
                                            <?php endif; ?>
                                        </form>
                                    </div>
                                </div>

                                <?php if ($providerIntegrationMode === 'manual'): ?>
                                    <div class="row" style="margin-top:10px;">
                                        <div class="col-md-12">
                                            <h5>Integrazione (Interfaccia)</h5>
                                            <div class="cv-muted">
                                                Gestisci contenuti manuali (fermate/linee/corse/tariffe) usati in ricerca.
                                            </div>
                                            <div class="cv-inline-actions" style="margin-top:8px;">
                                                <a class="btn btn-default btn-sm" href="<?= cvAccessoH(cvAccessoUrl('integrazione_fermate.php') . '?provider=' . urlencode($providerCode)) ?>">Fermate</a>
                                                <a class="btn btn-default btn-sm" href="<?= cvAccessoH(cvAccessoUrl('integrazione_linee.php') . '?provider=' . urlencode($providerCode)) ?>">Linee</a>
                                                <a class="btn btn-default btn-sm" href="<?= cvAccessoH(cvAccessoUrl('integrazione_corse.php') . '?provider=' . urlencode($providerCode)) ?>">Corse</a>
                                                <a class="btn btn-default btn-sm" href="<?= cvAccessoH(cvAccessoUrl('integrazione_tariffe.php') . '?provider=' . urlencode($providerCode)) ?>">Tariffe</a>
                                            </div>

                                            <div class="alert alert-warning" style="margin-top:12px;">
                                                <strong>Zona pericolosa:</strong> pulisce tutti i contenuti manuali del provider.
                                            </div>
                                            <form method="post" class="cv-form-grid">
                                                <input type="hidden" name="action" value="purge_manual_provider_content">
                                                <input type="hidden" name="provider_id" value="<?= (int) $providerId ?>">
                                                <input type="hidden" name="provider_code" value="<?= cvAccessoH($providerCode) ?>">
                                                <?= cvAccessoCsrfField() ?>
                                                <div class="row">
                                                    <div class="col-md-4 form-group">
                                                        <label>Conferma</label>
                                                        <input type="text" class="form-control" name="confirm_token" placeholder="Scrivi ELIMINA">
                                                    </div>
                                                    <div class="col-md-4 form-group" style="margin-top:26px;">
                                                        <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Confermi la pulizia contenuti manuali?')">Pulisci contenuti</button>
                                                    </div>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <div class="row" style="margin-top:8px;">
                                    <div class="col-md-4">
                                        <h5>Commissione Cercaviaggio</h5>
                                        <?php if (cvAccessoIsAdmin($state)): ?>
                                            <form method="post" class="cv-form-grid">
                                                <input type="hidden" name="action" value="save_provider_commission">
                                                <input type="hidden" name="provider_code" value="<?= cvAccessoH($providerCode) ?>">
                                                <?= cvAccessoCsrfField() ?>
                                                <div class="row">
                                                    <div class="col-md-6 form-group">
                                                        <label>Commissione %</label>
                                                        <input type="number" min="0" max="100" step="0.01" class="form-control" name="commission_percent" value="<?= cvAccessoH(number_format($commissionValue, 2, '.', '')) ?>">
                                                    </div>
                                                </div>
                                                <div class="cv-inline-actions">
                                                    <button type="submit" class="btn btn-default btn-sm">Salva commissione</button>
                                                </div>
                                            </form>
                                        <?php else: ?>
                                            <div class="cv-provider-fixed"><?= cvAccessoH(number_format($commissionValue, 2, ',', '.')) ?>%</div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="col-md-4">
                                        <h5>Home + Prezzi</h5>
                                        <?php if (cvAccessoIsAdmin($state)): ?>
                                            <form method="post" class="cv-form-grid">
                                                <input type="hidden" name="action" value="save_provider_home_settings">
                                                <input type="hidden" name="provider_code" value="<?= cvAccessoH($providerCode) ?>">
                                                <?= cvAccessoCsrfField() ?>

                                                <div class="checkbox" style="margin-top:4px;">
                                                    <input
                                                        id="<?= cvAccessoH($homeCheckboxId) ?>"
                                                        type="checkbox"
                                                        name="homepage_popular_provider_enabled"
                                                        value="1"
                                                        <?= $isHomeEnabled ? 'checked' : '' ?>
                                                    >
                                                    <label for="<?= cvAccessoH($homeCheckboxId) ?>">Provider in evidenza (home)</label>
                                                </div>

                                                <label for="<?= cvAccessoH($homeLimitId) ?>" class="control-label">Max tratte in evidenza</label>
                                                <input
                                                    id="<?= cvAccessoH($homeLimitId) ?>"
                                                    type="number"
                                                    name="homepage_popular_provider_limit"
                                                    class="form-control cv-range-number"
                                                    min="0"
                                                    max="200"
                                                    step="1"
                                                    value="<?= cvAccessoH((string) $homeLimitValue) ?>"
                                                    data-range-target="<?= cvAccessoH($homeLimitId . '_range') ?>"
                                                >
                                                <input
                                                    id="<?= cvAccessoH($homeLimitId . '_range') ?>"
                                                    type="range"
                                                    class="form-control cv-range-slider"
                                                    min="0"
                                                    max="200"
                                                    step="1"
                                                    value="<?= cvAccessoH((string) $homeLimitValue) ?>"
                                                    data-number-target="<?= cvAccessoH($homeLimitId) ?>"
                                                    style="margin-top:6px;"
                                                >

                                                <label for="<?= cvAccessoH($homePriceModeId) ?>" class="control-label" style="margin-top:8px;">Prezzo di vendita</label>
                                                <select
                                                    id="<?= cvAccessoH($homePriceModeId) ?>"
                                                    name="provider_price_mode"
                                                    class="form-control"
                                                >
                                                    <option value="discounted"<?= $priceModeValue === 'discounted' ? ' selected' : '' ?>>Prezzo scontato</option>
                                                    <option value="full"<?= $priceModeValue === 'full' ? ' selected' : '' ?>>Prezzo intero</option>
                                                </select>

                                                <label class="control-label" style="margin-top:10px;">PDF biglietto (per questo provider)</label>
                                                <div class="checkbox" style="margin-top:4px;">
                                                    <input
                                                        id="<?= cvAccessoH($pdfShowEmailId) ?>"
                                                        type="checkbox"
                                                        name="ticket_pdf_show_email"
                                                        value="1"
                                                        <?= $pdfShowEmail ? 'checked' : '' ?>
                                                    >
                                                    <label for="<?= cvAccessoH($pdfShowEmailId) ?>">Mostra email provider nel PDF</label>
                                                </div>
                                                <div class="checkbox" style="margin-top:4px;">
                                                    <input
                                                        id="<?= cvAccessoH($pdfShowSiteId) ?>"
                                                        type="checkbox"
                                                        name="ticket_pdf_show_site"
                                                        value="1"
                                                        <?= $pdfShowSite ? 'checked' : '' ?>
                                                    >
                                                    <label for="<?= cvAccessoH($pdfShowSiteId) ?>">Mostra sito provider nel PDF</label>
                                                </div>
                                                <label for="<?= cvAccessoH($pdfSiteUrlId) ?>" class="control-label" style="margin-top:8px;">URL sito provider</label>
                                                <input
                                                    id="<?= cvAccessoH($pdfSiteUrlId) ?>"
                                                    type="text"
                                                    name="ticket_pdf_site_url"
                                                    class="form-control"
                                                    placeholder="https://www.provider.it"
                                                    value="<?= cvAccessoH($pdfSiteUrl) ?>"
                                                >

                                                <div class="cv-inline-actions">
                                                    <button type="submit" class="btn btn-default btn-sm">Salva home + prezzi</button>
                                                </div>
                                            </form>
                                        <?php else: ?>
                                            <div class="cv-provider-fixed">
                                                Home: <strong><?= $isHomeEnabled ? 'in evidenza' : 'non in evidenza' ?></strong><br>
                                                Max tratte: <strong><?= (int) $homeLimitValue ?></strong><br>
                                                Prezzo: <strong><?= $priceModeValue === 'full' ? 'intero' : 'scontato' ?></strong><br>
                                                PDF email provider: <strong><?= $pdfShowEmail ? 'visibile' : 'nascosta' ?></strong><br>
                                                PDF sito provider: <strong><?= $pdfShowSite ? 'visibile' : 'nascosto' ?></strong>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="col-md-4">
                                        <h5>Stato sincronizzazione azienda</h5>
                                        <?php if ($mapped): ?>
                                            <div class="cv-provider-fixed">
                                                ID azienda: <strong>#<?= (int) ($mappedAzienda['id_az'] ?? 0) ?></strong><br>
                                                Nome: <strong><?= cvAccessoH((string) ($mappedAzienda['nome'] ?? '-')) ?></strong><br>
                                                Stato: <strong><?= ((int) ($mappedAzienda['stato'] ?? 0) === 1) ? 'Attivo' : 'Disattivo' ?></strong><br>
                                                Telefono: <strong><?= cvAccessoH((string) ($mappedAzienda['tel'] ?? '-')) ?></strong><br>
                                                Email: <strong><?= cvAccessoH((string) ($mappedAzienda['email_pg'] ?? '-')) ?></strong>
                                            </div>
                                        <?php else: ?>
                                            <div class="cv-empty">Azienda non allineata. Salva la configurazione del provider per forzare la sincronizzazione.</div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </details>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="cv-side-drawer-backdrop" data-cv-drawer-close="providers-filter-drawer"></div>
<aside id="providers-filter-drawer" class="cv-side-drawer" aria-hidden="true">
    <div class="cv-side-drawer-head">
        <h4>Filtri provider</h4>
        <button type="button" class="btn btn-default btn-sm" data-cv-drawer-close="providers-filter-drawer">
            <i class="fa fa-times"></i>
        </button>
    </div>

    <div class="form-group">
        <label for="providerFilterInput">Filtra provider</label>
        <input id="providerFilterInput" type="text" class="form-control cv-provider-list-filter" placeholder="Nome o code">
    </div>
    <div class="cv-inline-actions">
        <button type="button" id="providerFilterReset" class="btn btn-default btn-sm">Reset filtro</button>
    </div>
</aside>

<?php if (cvAccessoIsAdmin($state)): ?>
    <div class="cv-side-drawer-backdrop" data-cv-drawer-close="providers-add-drawer"></div>
	    <aside id="providers-add-drawer" class="cv-side-drawer" aria-hidden="true">
        <div class="cv-side-drawer-head">
            <h4>Aggiungi provider / azienda</h4>
            <button type="button" class="btn btn-default btn-sm" data-cv-drawer-close="providers-add-drawer">
                <i class="fa fa-times"></i>
            </button>
        </div>
        <p class="cv-muted cv-provider-tools-note">
            Viene creata la configurazione provider e viene sincronizzata automaticamente anche la tabella <code>aziende</code>.
        </p>
	        <form method="post" class="cv-form-grid">
            <input type="hidden" name="action" value="add_provider">
            <?= cvAccessoCsrfField() ?>
            <div class="form-group">
                <label>Code</label>
                <input type="text" class="form-control" name="provider_code_new" placeholder="leonetti">
            </div>
            <div class="form-group">
                <label>Nome</label>
                <input type="text" class="form-control" name="provider_name_new" placeholder="TTI Leonetti">
            </div>
	            <div class="form-group">
	                <label>Integrazione</label>
	                <select
	                    id="provider_integration_mode_new"
	                    class="form-control"
	                    name="provider_integration_mode_new"
	                    data-cv-integration-mode
	                    data-cv-target="provider_manual_limits_new"
	                    data-cv-endpoint-target="provider_api_endpoint_new"
	                >
	                    <option value="api" selected>API</option>
	                    <option value="manual">Interfaccia</option>
	                </select>
	            </div>
            <div class="form-group" id="provider_api_endpoint_new">
                <label>Endpoint API provider (solo API)</label>
                <input type="text" class="form-control" name="provider_base_url_new" placeholder="https://.../rest/cercaviaggio/api2.php">
                <div class="cv-muted" style="margin-top:6px;">Se scegli Interfaccia puoi lasciarlo vuoto.</div>
            </div>
            <div class="form-group">
                <label>Stato</label>
                <select class="form-control" name="provider_is_active_new">
                    <option value="1" selected>Attivo</option>
                    <option value="0">Disattivo</option>
                </select>
            </div>
	            <div id="provider_manual_limits_new" style="display:none;">
	                <div class="form-group">
	                    <label>Limite linee (0 = illimitato)</label>
	                    <input type="number" class="form-control" name="provider_manual_max_lines_new" min="0" step="1" value="0">
	                </div>
	                <div class="form-group">
	                    <label>Limite corse (0 = illimitato)</label>
	                    <input type="number" class="form-control" name="provider_manual_max_trips_new" min="0" step="1" value="0">
	                </div>
	                <div class="cv-muted" style="margin-top:6px;">Limiti usati solo con Integrazione = Interfaccia.</div>
	            </div>
            <div class="form-group">
                <label>Indirizzo azienda</label>
                <input type="text" class="form-control" name="provider_company_ind_new" placeholder="Via Roma 1, Napoli">
            </div>
            <div class="form-group">
                <label>Telefono</label>
                <input type="text" class="form-control" name="provider_company_tel_new" placeholder="+39 ...">
            </div>
            <div class="form-group">
                <label>Email azienda</label>
                <input type="email" class="form-control" name="provider_company_email_new" placeholder="info@azienda.it">
            </div>
            <div class="form-group">
                <label>P. IVA</label>
                <input type="text" class="form-control" name="provider_company_pi_new" placeholder="IT...">
            </div>
            <div class="form-group">
                <label>Recapiti aggiuntivi</label>
                <input type="text" class="form-control" name="provider_company_recapiti_new" placeholder="Orari assistenza, note contatto...">
            </div>
            <div class="cv-inline-actions">
                <button type="submit" class="btn btn-primary">Aggiungi</button>
            </div>
        </form>
    </aside>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    function toggleManualLimits(selectEl) {
        if (!selectEl) {
            return;
        }
        var targetId = selectEl.getAttribute('data-cv-target');
        if (!targetId) {
            return;
        }
        var target = document.getElementById(targetId);
        if (!target) {
            return;
        }
        var endpointTargetId = selectEl.getAttribute('data-cv-endpoint-target');
        var endpointTarget = endpointTargetId ? document.getElementById(endpointTargetId) : null;
        var isManual = String(selectEl.value || '') === 'manual';
        target.style.display = isManual ? '' : 'none';
        if (endpointTarget) {
            endpointTarget.style.display = isManual ? 'none' : '';
        }
    }

    var integrationSelects = document.querySelectorAll('select[data-cv-integration-mode][data-cv-target]');
    for (var i = 0; i < integrationSelects.length; i += 1) {
        (function (selectEl) {
            toggleManualLimits(selectEl);
            selectEl.addEventListener('change', function () {
                toggleManualLimits(selectEl);
            });
        })(integrationSelects[i]);
    }

    var rangeSliders = document.querySelectorAll('.cv-range-slider[data-number-target]');
    for (var r = 0; r < rangeSliders.length; r += 1) {
        (function (slider) {
            var numberId = slider.getAttribute('data-number-target');
            if (!numberId) {
                return;
            }
            var numberInput = document.getElementById(numberId);
            if (!numberInput) {
                return;
            }
            slider.addEventListener('input', function () {
                numberInput.value = slider.value;
            });
            numberInput.addEventListener('input', function () {
                slider.value = numberInput.value;
            });
        })(rangeSliders[r]);
    }

    var rangeNumbers = document.querySelectorAll('.cv-range-number[data-range-target]');
    for (var n = 0; n < rangeNumbers.length; n += 1) {
        (function (numberInput) {
            var rangeId = numberInput.getAttribute('data-range-target');
            if (!rangeId) {
                return;
            }
            var slider = document.getElementById(rangeId);
            if (!slider) {
                return;
            }
            slider.addEventListener('input', function () {
                numberInput.value = slider.value;
            });
            numberInput.addEventListener('input', function () {
                slider.value = numberInput.value;
            });
        })(rangeNumbers[n]);
    }

    var toggles = document.querySelectorAll('.cv-provider-accordion-toggle');
    Array.prototype.forEach.call(toggles, function (summary) {
        summary.addEventListener('click', function (event) {
            event.preventDefault();
            var item = summary.parentElement;
            if (!item || !item.classList.contains('cv-provider-accordion-item')) {
                return;
            }

            var shouldOpen = !item.hasAttribute('open');
            var allItems = document.querySelectorAll('.cv-provider-accordion-item');
            Array.prototype.forEach.call(allItems, function (node) {
                node.removeAttribute('open');
            });

            if (shouldOpen) {
                item.setAttribute('open', 'open');
            }
        });
    });

    var filterInput = document.getElementById('providerFilterInput');
    var resetButton = document.getElementById('providerFilterReset');
    var cards = document.querySelectorAll('[data-provider-card]');

    if (!filterInput || cards.length === 0) {
        return;
    }

    var normalize = function (value) {
        return String(value || '').toLowerCase().trim();
    };

    var applyFilter = function () {
        var query = normalize(filterInput.value);
        for (var i = 0; i < cards.length; i += 1) {
            var card = cards[i];
            var blob = normalize(card.getAttribute('data-search'));
            var visible = (query === '') || (blob.indexOf(query) !== -1);
            card.style.display = visible ? '' : 'none';
            if (!visible && card.hasAttribute('open')) {
                card.removeAttribute('open');
            }
        }
    };

    filterInput.addEventListener('input', applyFilter);
    if (resetButton) {
        resetButton.addEventListener('click', function () {
            filterInput.value = '';
            applyFilter();
            filterInput.focus();
        });
    }
});
</script>
<?php
cvAccessoRenderPageEnd();
