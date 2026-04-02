<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';

if (!function_exists('cvAccessoNewsletterEnsureCampaignTable')) {
    function cvAccessoNewsletterEnsureCampaignTable(mysqli $connection): bool
    {
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS cv_newsletter_campaigns (
  id_campaign BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  subject VARCHAR(190) NOT NULL,
  body_html MEDIUMTEXT NOT NULL,
  body_text TEXT NOT NULL,
  recipients_total INT NOT NULL DEFAULT 0,
  recipients_sent INT NOT NULL DEFAULT 0,
  recipients_failed INT NOT NULL DEFAULT 0,
  status VARCHAR(32) NOT NULL DEFAULT 'completed',
  created_by VARCHAR(190) NOT NULL DEFAULT '',
  fail_log MEDIUMTEXT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_campaign),
  KEY idx_news_campaign_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL;

        return (bool) $connection->query($sql);
    }
}

if (!function_exists('cvAccessoNewsletterMailSettings')) {
    /**
     * @return array<string,mixed>
     */
    function cvAccessoNewsletterMailSettings(mysqli $connection, int $slot = 2): array
    {
        $settings = [
            'from_email' => 'noreply@fillbus.it',
            'from_name' => 'Cercaviaggio Newsletter',
            'smtp_host' => '',
            'smtp_port' => 0,
            'smtp_security' => '',
            'smtp_user' => '',
            'smtp_pass' => '',
        ];

        $slot = max(1, min(3, $slot));
        $query = $connection->query('SELECT * FROM mail_sett ORDER BY id_sett ASC LIMIT 1');
        if (!$query instanceof mysqli_result) {
            return $settings;
        }

        $row = $query->fetch_assoc();
        $query->free();
        if (!is_array($row)) {
            return $settings;
        }

        $emailField = 'email' . $slot;
        $userField = 'user' . $slot;
        $passField = 'pass' . $slot;
        $subjectField = 'oggetto' . $slot;

        $smtpSecurity = '';
        $securityCode = (int) ($row['smtpsecurity'] ?? 0);
        if ($securityCode === 1) {
            $smtpSecurity = 'ssl';
        } elseif ($securityCode === 2) {
            $smtpSecurity = 'tls';
        }

        $fromEmail = trim((string) ($row[$emailField] ?? ''));
        if ($fromEmail !== '' && filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            $settings['from_email'] = $fromEmail;
        }

        $fromName = trim((string) ($row[$subjectField] ?? ''));
        if ($fromName !== '') {
            $settings['from_name'] = $fromName;
        }

        $settings['smtp_host'] = trim((string) ($row['smtp'] ?? ''));
        $settings['smtp_port'] = max(0, (int) ($row['smtpport'] ?? 0));
        $settings['smtp_security'] = $smtpSecurity;
        $settings['smtp_user'] = trim((string) ($row[$userField] ?? ''));
        $settings['smtp_pass'] = trim((string) ($row[$passField] ?? ''));

        return $settings;
    }
}

if (!function_exists('cvAccessoNewsletterLoadPhpMailer')) {
    function cvAccessoNewsletterLoadPhpMailer(): bool
    {
        static $loaded = null;
        if ($loaded !== null) {
            return $loaded;
        }

        $base = dirname(__DIR__) . '/functions/PHPMailer/src';
        $required = [
            $base . '/Exception.php',
            $base . '/PHPMailer.php',
            $base . '/SMTP.php',
        ];

        foreach ($required as $file) {
            if (!is_file($file)) {
                $loaded = false;
                return false;
            }
        }

        require_once $base . '/Exception.php';
        require_once $base . '/PHPMailer.php';
        require_once $base . '/SMTP.php';

        $loaded = class_exists('\\PHPMailer\\PHPMailer\\PHPMailer');
        return $loaded;
    }
}

if (!function_exists('cvAccessoNewsletterSendMail')) {
    function cvAccessoNewsletterSendMail(
        mysqli $connection,
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        string $plainBody = ''
    ): bool {
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $settings = cvAccessoNewsletterMailSettings($connection, 2);
        $fromEmail = trim((string) ($settings['from_email'] ?? 'noreply@fillbus.it'));
        $fromName = trim((string) ($settings['from_name'] ?? 'Cercaviaggio Newsletter'));

        if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            $fromEmail = 'noreply@fillbus.it';
        }
        if ($fromName === '') {
            $fromName = 'Cercaviaggio Newsletter';
        }

        $smtpHost = trim((string) ($settings['smtp_host'] ?? ''));
        $smtpPort = (int) ($settings['smtp_port'] ?? 0);
        $smtpSecurity = trim((string) ($settings['smtp_security'] ?? ''));
        $smtpUser = trim((string) ($settings['smtp_user'] ?? ''));
        $smtpPass = (string) ($settings['smtp_pass'] ?? '');

        if ($smtpHost !== '' && cvAccessoNewsletterLoadPhpMailer()) {
            try {
                $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
                $mail->CharSet = 'UTF-8';
                $mail->Encoding = 'base64';
                $mail->SetLanguage('it', dirname(__DIR__) . '/functions/PHPMailer/language/');
                $mail->isSMTP();
                $mail->Host = $smtpHost;
                $mail->SMTPAuth = true;
                if ($smtpSecurity !== '') {
                    $mail->SMTPSecure = $smtpSecurity;
                }
                if ($smtpPort > 0) {
                    $mail->Port = $smtpPort;
                }
                $mail->Username = $smtpUser;
                $mail->Password = $smtpPass;
                $mail->setFrom($fromEmail, $fromName);
                $mail->addAddress($toEmail, $toName);
                $mail->addReplyTo($fromEmail, $fromName);
                $mail->isHTML(true);
                $mail->Subject = $subject;
                $mail->Body = $htmlBody;
                $mail->AltBody = $plainBody !== '' ? $plainBody : trim(strip_tags($htmlBody));

                return $mail->send();
            } catch (Throwable $exception) {
                error_log('cv newsletter smtp error: ' . $exception->getMessage());
            }
        }

        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $headers = [];
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
        $headers[] = 'From: ' . $fromName . ' <' . $fromEmail . '>';
        $headers[] = 'Reply-To: ' . $fromEmail;
        $headers[] = 'X-Mailer: PHP/' . PHP_VERSION;

        return @mail($toEmail, $encodedSubject, $htmlBody, implode("\r\n", $headers));
    }
}

if (!function_exists('cvAccessoNewsletterRecipients')) {
    /**
     * @return array<int,string>
     */
    function cvAccessoNewsletterRecipients(mysqli $connection): array
    {
        $emails = [];

        $sqlUser = 'SELECT email FROM cv_newsletter_subscriptions WHERE subscribed = 1';
        $resultUser = $connection->query($sqlUser);
        if ($resultUser instanceof mysqli_result) {
            while ($row = $resultUser->fetch_assoc()) {
                $email = strtolower(trim((string) ($row['email'] ?? '')));
                if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $emails[$email] = $email;
                }
            }
            $resultUser->free();
        }

        $sqlGuest = 'SELECT email FROM cv_newsletter_guest_subscriptions WHERE subscribed = 1';
        $resultGuest = $connection->query($sqlGuest);
        if ($resultGuest instanceof mysqli_result) {
            while ($row = $resultGuest->fetch_assoc()) {
                $email = strtolower(trim((string) ($row['email'] ?? '')));
                if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $emails[$email] = $email;
                }
            }
            $resultGuest->free();
        }

        return array_values($emails);
    }
}

if (!function_exists('cvAccessoNewsletterWrapHtml')) {
    function cvAccessoNewsletterWrapHtml(string $bodyHtml): string
    {
        $safeBody = trim($bodyHtml);
        if ($safeBody === '') {
            $safeBody = '<p>Nessun contenuto.</p>';
        }

        $html = '<!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
        $html .= '<title>Newsletter Cercaviaggio</title>';
        $html .= '<style>body{margin:0;padding:0;background:#f1f5fb;font-family:Arial,sans-serif;color:#1a1a1a}.wrap{max-width:680px;margin:0 auto;padding:24px}.card{background:#fff;border:1px solid #dbe4f0;border-radius:14px;overflow:hidden}.head{background:#1f4f8a;color:#fff;padding:20px 24px;font-size:22px;font-weight:700}.content{padding:24px;line-height:1.6;font-size:15px}.foot{padding:16px 24px;background:#f7f9fc;border-top:1px solid #e4ebf4;color:#5e6b7a;font-size:12px}</style>';
        $html .= '</head><body><div class="wrap"><div class="card"><div class="head">Cercaviaggio</div><div class="content">' . $safeBody . '</div><div class="foot">Hai ricevuto questa email perché risulti iscritto alla newsletter Cercaviaggio.</div></div></div></body></html>';

        return $html;
    }
}

if (!function_exists('cvAccessoNewsletterStripBodyText')) {
    function cvAccessoNewsletterStripBodyText(string $bodyHtml): string
    {
        $text = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $bodyHtml)));
        return $text !== '' ? $text : 'Newsletter Cercaviaggio';
    }
}

$state = cvAccessoInit();
if (!$state['authenticated']) {
    cvAccessoRenderLoginPage($state);
    return;
}

if (!cvAccessoIsAdmin($state)) {
    http_response_code(403);
    cvAccessoRenderPageStart('Newsletter', 'newsletter', $state);
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

$subject = '';
$bodyHtmlRaw = '';
$testEmail = '';
$maxRecipients = 0;
$stats = [
    'subscribed_users' => 0,
    'subscribed_guests' => 0,
    'total' => 0,
];
$campaigns = [];

try {
    $connection = cvAccessoRequireConnection();
    cvAccessoNewsletterEnsureCampaignTable($connection);

    $resUsers = $connection->query('SELECT COUNT(*) AS total FROM cv_newsletter_subscriptions WHERE subscribed = 1');
    if ($resUsers instanceof mysqli_result) {
        $rowUsers = $resUsers->fetch_assoc();
        $stats['subscribed_users'] = (int) ($rowUsers['total'] ?? 0);
        $resUsers->free();
    }

    $resGuests = $connection->query('SELECT COUNT(*) AS total FROM cv_newsletter_guest_subscriptions WHERE subscribed = 1');
    if ($resGuests instanceof mysqli_result) {
        $rowGuests = $resGuests->fetch_assoc();
        $stats['subscribed_guests'] = (int) ($rowGuests['total'] ?? 0);
        $resGuests->free();
    }

    $stats['total'] = (int) count(cvAccessoNewsletterRecipients($connection));

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = trim((string) ($_POST['action'] ?? ''));
        $subject = trim((string) ($_POST['subject'] ?? ''));
        $bodyHtmlRaw = trim((string) ($_POST['body_html'] ?? ''));
        $testEmail = strtolower(trim((string) ($_POST['test_email'] ?? '')));
        $maxRecipients = max(0, (int) ($_POST['max_recipients'] ?? 0));

        if (!cvAccessoValidateCsrf()) {
            $state['errors'][] = 'Sessione non valida. Ricarica la pagina.';
        } elseif ($subject === '' || $bodyHtmlRaw === '') {
            $state['errors'][] = 'Compila oggetto e contenuto newsletter.';
        } else {
            $subject = substr($subject, 0, 190);
            $htmlBody = cvAccessoNewsletterWrapHtml($bodyHtmlRaw);
            $plainBody = cvAccessoNewsletterStripBodyText($bodyHtmlRaw);
            $adminEmail = strtolower(trim((string) ($state['current_user']['email'] ?? '')));

            if ($action === 'send_test') {
                if (!filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
                    $state['errors'][] = 'Inserisci una email test valida.';
                } else {
                    $ok = cvAccessoNewsletterSendMail($connection, $testEmail, '', $subject, $htmlBody, $plainBody);
                    if ($ok) {
                        $state['messages'][] = 'Email test inviata a ' . $testEmail . '.';
                    } else {
                        $state['errors'][] = 'Invio test fallito. Verifica SMTP in Settings > Mail.';
                    }
                }
            }

            if ($action === 'send_live') {
                $recipients = cvAccessoNewsletterRecipients($connection);
                if ($maxRecipients > 0) {
                    $recipients = array_slice($recipients, 0, $maxRecipients);
                }

                if (count($recipients) === 0) {
                    $state['errors'][] = 'Nessun destinatario iscritto disponibile.';
                } else {
                    $sent = 0;
                    $failed = 0;
                    $failLog = [];

                    foreach ($recipients as $email) {
                        $ok = cvAccessoNewsletterSendMail($connection, $email, '', $subject, $htmlBody, $plainBody);
                        if ($ok) {
                            $sent += 1;
                        } else {
                            $failed += 1;
                            if (count($failLog) < 100) {
                                $failLog[] = $email;
                            }
                        }
                    }

                    $status = 'completed';
                    if ($sent === 0) {
                        $status = 'failed';
                    } elseif ($failed > 0) {
                        $status = 'partial';
                    }

                    $failLogJson = '';
                    if (count($failLog) > 0) {
                        $encoded = json_encode($failLog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        $failLogJson = is_string($encoded) ? $encoded : '';
                    }

                    $statement = $connection->prepare(
                        'INSERT INTO cv_newsletter_campaigns
                        (subject, body_html, body_text, recipients_total, recipients_sent, recipients_failed, status, created_by, fail_log)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                    );
                    if ($statement instanceof mysqli_stmt) {
                        $total = count($recipients);
                        $statement->bind_param(
                            'sssiiisss',
                            $subject,
                            $bodyHtmlRaw,
                            $plainBody,
                            $total,
                            $sent,
                            $failed,
                            $status,
                            $adminEmail,
                            $failLogJson
                        );
                        $statement->execute();
                        $statement->close();
                    }

                    if ($status === 'completed') {
                        $state['messages'][] = 'Newsletter inviata con successo a ' . $sent . ' destinatari.';
                    } elseif ($status === 'partial') {
                        $state['errors'][] = 'Newsletter inviata parzialmente: ' . $sent . ' ok, ' . $failed . ' falliti.';
                    } else {
                        $state['errors'][] = 'Invio newsletter fallito su tutti i destinatari.';
                    }
                }
            }

            $stats['total'] = (int) count(cvAccessoNewsletterRecipients($connection));
        }
    }

    $resCampaigns = $connection->query(
        'SELECT id_campaign, subject, recipients_total, recipients_sent, recipients_failed, status, created_by, created_at
         FROM cv_newsletter_campaigns
         ORDER BY id_campaign DESC
         LIMIT 20'
    );
    if ($resCampaigns instanceof mysqli_result) {
        while ($row = $resCampaigns->fetch_assoc()) {
            if (!is_array($row)) {
                continue;
            }
            $campaigns[] = $row;
        }
        $resCampaigns->free();
    }
} catch (Throwable $exception) {
    $state['errors'][] = 'Errore sezione newsletter: ' . $exception->getMessage();
}

cvAccessoRenderPageStart('Newsletter', 'newsletter', $state);
?>
<div class="row">
    <div class="col-md-12">
        <p class="cv-page-intro">Invio newsletter Cercaviaggio con destinatari da profili registrati e guest confermati.</p>
    </div>
</div>

<div class="row">
    <div class="col-md-4">
        <div class="cv-panel-card">
            <h4>Destinatari attivi</h4>
            <p class="cv-muted">Utenti registrati: <strong><?= (int) ($stats['subscribed_users'] ?? 0) ?></strong></p>
            <p class="cv-muted">Guest confermati: <strong><?= (int) ($stats['subscribed_guests'] ?? 0) ?></strong></p>
            <p class="cv-muted">Totale email uniche: <strong><?= (int) ($stats['total'] ?? 0) ?></strong></p>
            <p><a class="btn btn-default btn-sm" href="<?= cvAccessoH(cvAccessoUrl('mail-settings.php')) ?>">Configura SMTP/Mittenti</a></p>
        </div>
    </div>

    <div class="col-md-8">
        <div class="cv-panel-card">
            <h4>Nuova campagna</h4>
            <form method="post">
                <?= cvAccessoCsrfField() ?>

                <div class="form-group">
                    <label for="subject">Oggetto</label>
                    <input id="subject" name="subject" type="text" class="form-control" maxlength="190" value="<?= cvAccessoH($subject) ?>" required>
                </div>

                <div class="form-group">
                    <label for="body_html">Contenuto HTML</label>
                    <textarea id="body_html" name="body_html" rows="12" class="form-control" required><?= cvAccessoH($bodyHtmlRaw) ?></textarea>
                    <div class="cv-muted">Puoi usare HTML semplice: paragrafi, titoli, link, elenchi.</div>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="test_email">Email test</label>
                            <input id="test_email" name="test_email" type="email" class="form-control" value="<?= cvAccessoH($testEmail) ?>" placeholder="nome@email.it">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="max_recipients">Limite invio (0 = tutti)</label>
                            <input id="max_recipients" name="max_recipients" type="number" min="0" class="form-control" value="<?= (int) $maxRecipients ?>">
                        </div>
                    </div>
                </div>

                <div class="cv-inline-actions" style="display:flex; gap:8px;">
                    <button type="submit" class="btn btn-default" name="action" value="send_test">Invia test</button>
                    <button type="submit" class="btn btn-primary" name="action" value="send_live">Invia newsletter</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-12">
        <div class="cv-panel-card">
            <h4>Ultime campagne</h4>
            <?php if (count($campaigns) === 0): ?>
                <div class="cv-empty">Nessuna campagna inviata.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Oggetto</th>
                                <th>Destinatari</th>
                                <th>Esito</th>
                                <th>Creato da</th>
                                <th>Data</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($campaigns as $campaign): ?>
                                <tr>
                                    <td><?= (int) ($campaign['id_campaign'] ?? 0) ?></td>
                                    <td><?= cvAccessoH((string) ($campaign['subject'] ?? '')) ?></td>
                                    <td>
                                        <?= (int) ($campaign['recipients_sent'] ?? 0) ?>/<?= (int) ($campaign['recipients_total'] ?? 0) ?>
                                        <?php if ((int) ($campaign['recipients_failed'] ?? 0) > 0): ?>
                                            <span class="text-danger">(<?= (int) ($campaign['recipients_failed'] ?? 0) ?> fail)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= cvAccessoH((string) ($campaign['status'] ?? '')) ?></td>
                                    <td><?= cvAccessoH((string) ($campaign['created_by'] ?? '')) ?></td>
                                    <td><?= cvAccessoH((string) ($campaign['created_at'] ?? '')) ?></td>
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
