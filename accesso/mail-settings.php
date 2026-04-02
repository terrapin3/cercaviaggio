<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';

if (!function_exists('cvAccessoMailSettingsDefaults')) {
    /** @return array<string,mixed> */
    function cvAccessoMailSettingsDefaults(): array
    {
        return [
            'email1' => '',
            'user1' => '',
            'pass1' => '',
            'oggetto1' => 'Cercaviaggio Account',
            'email2' => '',
            'user2' => '',
            'pass2' => '',
            'oggetto2' => 'Cercaviaggio Newsletter',
            'email3' => '',
            'user3' => '',
            'pass3' => '',
            'oggetto3' => 'Cercaviaggio Ticket',
            'smtp' => '',
            'smtpport' => 0,
            'smtpsecurity' => 0,
        ];
    }
}

if (!function_exists('cvAccessoEnsureMailSettTable')) {
    function cvAccessoEnsureMailSettTable(mysqli $connection): bool
    {
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS mail_sett (
  id_sett INT NOT NULL AUTO_INCREMENT,
  email1 VARCHAR(190) NOT NULL DEFAULT '',
  user1 VARCHAR(190) NOT NULL DEFAULT '',
  pass1 VARCHAR(255) NOT NULL DEFAULT '',
  oggetto1 VARCHAR(190) NOT NULL DEFAULT '',
  email2 VARCHAR(190) NOT NULL DEFAULT '',
  user2 VARCHAR(190) NOT NULL DEFAULT '',
  pass2 VARCHAR(255) NOT NULL DEFAULT '',
  oggetto2 VARCHAR(190) NOT NULL DEFAULT '',
  email3 VARCHAR(190) NOT NULL DEFAULT '',
  user3 VARCHAR(190) NOT NULL DEFAULT '',
  pass3 VARCHAR(255) NOT NULL DEFAULT '',
  oggetto3 VARCHAR(190) NOT NULL DEFAULT '',
  smtp VARCHAR(190) NOT NULL DEFAULT '',
  smtpport INT NOT NULL DEFAULT 0,
  smtpsecurity INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id_sett)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL;

        if (!$connection->query($sql)) {
            return false;
        }

        $check = $connection->query('SELECT id_sett FROM mail_sett ORDER BY id_sett ASC LIMIT 1');
        if ($check instanceof mysqli_result && $check->num_rows > 0) {
            $check->free();
            return true;
        }
        if ($check instanceof mysqli_result) {
            $check->free();
        }

        return (bool) $connection->query('INSERT INTO mail_sett () VALUES ()');
    }
}

if (!function_exists('cvAccessoMailSettings')) {
    /** @return array<string,mixed> */
    function cvAccessoMailSettings(mysqli $connection): array
    {
        $settings = cvAccessoMailSettingsDefaults();
        if (!cvAccessoEnsureMailSettTable($connection)) {
            return $settings;
        }

        $query = $connection->query('SELECT * FROM mail_sett ORDER BY id_sett ASC LIMIT 1');
        if (!$query instanceof mysqli_result) {
            return $settings;
        }

        $row = $query->fetch_assoc();
        $query->free();
        if (!is_array($row)) {
            return $settings;
        }

        foreach (array_keys($settings) as $key) {
            if (!array_key_exists($key, $row)) {
                continue;
            }
            if ($key === 'smtpport' || $key === 'smtpsecurity') {
                $settings[$key] = (int) $row[$key];
            } else {
                $settings[$key] = trim((string) $row[$key]);
            }
        }

        if ($settings['smtpport'] < 0) {
            $settings['smtpport'] = 0;
        }
        if (!in_array((int) $settings['smtpsecurity'], [0, 1, 2], true)) {
            $settings['smtpsecurity'] = 0;
        }

        return $settings;
    }
}

if (!function_exists('cvAccessoSaveMailSettings')) {
    /** @param array<string,mixed> $payload */
    function cvAccessoSaveMailSettings(mysqli $connection, array $payload): array
    {
        if (!cvAccessoEnsureMailSettTable($connection)) {
            throw new RuntimeException('Tabella mail_sett non disponibile.');
        }

        $current = cvAccessoMailSettings($connection);
        $next = $current;

        foreach ($next as $key => $value) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }
            if ($key === 'smtpport' || $key === 'smtpsecurity') {
                $next[$key] = (int) $payload[$key];
            } else {
                $next[$key] = trim((string) $payload[$key]);
            }
        }

        if ($next['smtpport'] < 0) {
            $next['smtpport'] = 0;
        }
        if (!in_array((int) $next['smtpsecurity'], [0, 1, 2], true)) {
            $next['smtpsecurity'] = 0;
        }

        for ($slot = 1; $slot <= 3; $slot += 1) {
            $emailKey = 'email' . $slot;
            $emailValue = trim((string) ($next[$emailKey] ?? ''));
            if ($emailValue !== '' && !filter_var($emailValue, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Email mittente slot ' . $slot . ' non valida.');
            }
        }

        $rowRes = $connection->query('SELECT id_sett FROM mail_sett ORDER BY id_sett ASC LIMIT 1');
        if (!$rowRes instanceof mysqli_result) {
            throw new RuntimeException('Impossibile leggere mail_sett.');
        }
        $row = $rowRes->fetch_assoc();
        $rowRes->free();
        if (!is_array($row) || !isset($row['id_sett'])) {
            throw new RuntimeException('Riga configurazione mail non trovata.');
        }

        $idSett = (int) $row['id_sett'];
        $sql = 'UPDATE mail_sett
                SET email1 = ?, user1 = ?, pass1 = ?, oggetto1 = ?,
                    email2 = ?, user2 = ?, pass2 = ?, oggetto2 = ?,
                    email3 = ?, user3 = ?, pass3 = ?, oggetto3 = ?,
                    smtp = ?, smtpport = ?, smtpsecurity = ?
                WHERE id_sett = ?
                LIMIT 1';

        $statement = $connection->prepare($sql);
        if (!$statement instanceof mysqli_stmt) {
            throw new RuntimeException('Impossibile preparare salvataggio mail_sett.');
        }

        $statement->bind_param(
            'sssssssssssssiii',
            $next['email1'],
            $next['user1'],
            $next['pass1'],
            $next['oggetto1'],
            $next['email2'],
            $next['user2'],
            $next['pass2'],
            $next['oggetto2'],
            $next['email3'],
            $next['user3'],
            $next['pass3'],
            $next['oggetto3'],
            $next['smtp'],
            $next['smtpport'],
            $next['smtpsecurity'],
            $idSett
        );

        $ok = $statement->execute();
        $statement->close();

        if (!$ok) {
            throw new RuntimeException('Salvataggio mail_sett fallito.');
        }

        return cvAccessoMailSettings($connection);
    }
}

$state = cvAccessoInit();
if (!$state['authenticated']) {
    cvAccessoRenderLoginPage($state);
    return;
}

if (!cvAccessoIsAdmin($state)) {
    http_response_code(403);
    cvAccessoRenderPageStart('Settings Mail', 'settings-mail', $state);
    ?>
    <div class="row"><div class="col-md-12"><div class="cv-panel-card"><div class="cv-empty">Questa sezione è disponibile solo per l’amministratore.</div></div></div></div>
    <?php
    cvAccessoRenderPageEnd();
    return;
}

$mailSettings = cvAccessoMailSettingsDefaults();
$mailTableExists = false;

try {
    $connection = cvAccessoRequireConnection();
    $mailTableExists = cvAccessoEnsureMailSettTable($connection);
    if ($mailTableExists) {
        $mailSettings = cvAccessoMailSettings($connection);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'save_mail_settings') {
        if (!cvAccessoValidateCsrf()) {
            $state['errors'][] = 'Sessione non valida. Ricarica la pagina.';
        } elseif (!$mailTableExists) {
            $state['errors'][] = 'La tabella mail_sett non è disponibile.';
        } else {
            $payload = [
                'smtp' => (string) ($_POST['smtp'] ?? ''),
                'smtpport' => (int) ($_POST['smtpport'] ?? 0),
                'smtpsecurity' => (int) ($_POST['smtpsecurity'] ?? 0),
            ];

            for ($slot = 1; $slot <= 3; $slot += 1) {
                $payload['email' . $slot] = (string) ($_POST['email' . $slot] ?? '');
                $payload['user' . $slot] = (string) ($_POST['user' . $slot] ?? '');
                $payload['pass' . $slot] = (string) ($_POST['pass' . $slot] ?? '');
                $payload['oggetto' . $slot] = (string) ($_POST['oggetto' . $slot] ?? '');
            }

            $mailSettings = cvAccessoSaveMailSettings($connection, $payload);
            $state['messages'][] = 'Impostazioni mail aggiornate. SMTP in priorità, mail() in fallback.';
        }
    }
} catch (Throwable $exception) {
    $state['errors'][] = 'Errore sezione settings mail: ' . $exception->getMessage();
}

cvAccessoRenderPageStart('Settings Mail', 'settings-mail', $state);
?>
<div class="row"><div class="col-md-12"><p class="cv-page-intro">Parametri email per servizio: account, newsletter, biglietti.</p></div></div>

<div class="row">
    <div class="col-md-12">
        <div class="cv-panel-card">
            <h4>Mail servizi</h4>
            <form method="post">
                <input type="hidden" name="action" value="save_mail_settings">
                <?= cvAccessoCsrfField() ?>

                <div class="row">
                    <?php for ($slot = 1; $slot <= 3; $slot += 1): ?>
                        <?php
                        $slotTitle = 'Slot ' . $slot;
                        $slotHint = 'Servizio generico';
                        if ($slot === 1) {
                            $slotTitle = 'Slot 1 - Account';
                            $slotHint = 'Verifica registrazione, recupero password e comunicazioni profilo.';
                        } elseif ($slot === 2) {
                            $slotTitle = 'Slot 2 - Newsletter';
                            $slotHint = 'Conferme double opt-in e invii newsletter.';
                        } elseif ($slot === 3) {
                            $slotTitle = 'Slot 3 - Biglietti';
                            $slotHint = 'Conferme acquisto e invio ticket.';
                        }
                        ?>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="email<?= (int) $slot ?>"><?= cvAccessoH($slotTitle) ?> - Email mittente</label>
                                <input id="email<?= (int) $slot ?>" name="email<?= (int) $slot ?>" type="email" class="form-control" value="<?= cvAccessoH((string) ($mailSettings['email' . $slot] ?? '')) ?>" placeholder="noreply@dominio.it">
                            </div>
                            <div class="form-group">
                                <label for="user<?= (int) $slot ?>">Utente SMTP</label>
                                <input id="user<?= (int) $slot ?>" name="user<?= (int) $slot ?>" type="text" class="form-control" value="<?= cvAccessoH((string) ($mailSettings['user' . $slot] ?? '')) ?>">
                            </div>
                            <div class="form-group">
                                <label for="pass<?= (int) $slot ?>">Password SMTP</label>
                                <input id="pass<?= (int) $slot ?>" name="pass<?= (int) $slot ?>" type="password" class="form-control" value="<?= cvAccessoH((string) ($mailSettings['pass' . $slot] ?? '')) ?>" autocomplete="new-password">
                            </div>
                            <div class="form-group">
                                <label for="oggetto<?= (int) $slot ?>">Nome mittente / prefisso oggetto</label>
                                <input id="oggetto<?= (int) $slot ?>" name="oggetto<?= (int) $slot ?>" type="text" class="form-control" value="<?= cvAccessoH((string) ($mailSettings['oggetto' . $slot] ?? '')) ?>">
                            </div>
                            <div class="cv-muted" style="margin-bottom:16px;"><?= cvAccessoH($slotHint) ?></div>
                        </div>
                    <?php endfor; ?>
                </div>

                <hr>

                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label for="smtp">Server SMTP</label>
                            <input id="smtp" name="smtp" type="text" class="form-control" value="<?= cvAccessoH((string) ($mailSettings['smtp'] ?? '')) ?>" placeholder="smtp.dominio.it">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label for="smtpport">Porta SMTP</label>
                            <input id="smtpport" name="smtpport" type="number" min="0" class="form-control" value="<?= cvAccessoH((string) ((int) ($mailSettings['smtpport'] ?? 0))) ?>" placeholder="587">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label for="smtpsecurity">Sicurezza SMTP</label>
                            <select id="smtpsecurity" name="smtpsecurity" class="form-control">
                                <option value="0"<?= ((int) ($mailSettings['smtpsecurity'] ?? 0)) === 0 ? ' selected' : '' ?>>Nessuna</option>
                                <option value="2"<?= ((int) ($mailSettings['smtpsecurity'] ?? 0)) === 2 ? ' selected' : '' ?>>TLS</option>
                                <option value="1"<?= ((int) ($mailSettings['smtpsecurity'] ?? 0)) === 1 ? ' selected' : '' ?>>SSL</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="cv-inline-actions">
                    <button type="submit" class="btn btn-primary"<?= $mailTableExists ? '' : ' disabled' ?>>Salva impostazioni mail</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php
cvAccessoRenderPageEnd();
