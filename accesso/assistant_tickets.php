<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../includes/assistant_tools.php';

$state = cvAccessoInit();
if (!$state['authenticated']) {
    cvAccessoRenderLoginPage($state);
    return;
}

if (!cvAccessoIsAdmin($state)) {
    http_response_code(403);
    cvAccessoRenderPageStart('Assistente · Ticket', 'assistant-tickets', $state);
    ?>
    <div class="row"><div class="col-md-12"><div class="cv-panel-card"><div class="cv-empty">Questa sezione e disponibile solo per l’amministratore.</div></div></div></div>
    <?php
    cvAccessoRenderPageEnd();
    return;
}

$supportTickets = [];
$supportTicketMessages = [];

try {
    $connection = cvAccessoRequireConnection();
    cvAssistantEnsureTables($connection);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = trim((string) ($_POST['action'] ?? ''));
        if (!cvAccessoValidateCsrf()) {
            $state['errors'][] = 'Sessione non valida. Ricarica la pagina.';
        } elseif ($action === 'support_ticket_reply') {
            $idTicket = (int) ($_POST['id_ticket'] ?? 0);
            $replyText = trim((string) ($_POST['reply_text'] ?? ''));
            $nextStatus = trim((string) ($_POST['status'] ?? 'open'));
            $ticket = cvAssistantSupportTicketLoad($connection, $idTicket);
            if (!is_array($ticket)) {
                $state['errors'][] = 'Ticket assistenza non trovato.';
            } elseif ($replyText === '') {
                $state['errors'][] = 'Scrivi una risposta per il ticket.';
            } else {
                cvAssistantSupportTicketAddMessage($connection, $idTicket, 'admin', $replyText, (string) ($state['current_user']['email'] ?? 'admin'));
                cvAssistantSupportTicketUpdate($connection, $idTicket, [
                    'status' => $nextStatus,
                    'subject' => (string) ($ticket['subject'] ?? ''),
                ]);
                $conversationId = (int) ($ticket['id_conversation'] ?? 0);
                if ($conversationId > 0) {
                    cvAssistantLogMessage(
                        $connection,
                        $conversationId,
                        'assistant',
                        'Aggiornamento assistenza Cercaviaggio: ' . $replyText,
                        'support_ticket_reply',
                        0.99,
                        ['support_ticket_id' => $idTicket, 'kind' => 'admin_reply']
                    );
                }
                $state['messages'][] = 'Risposta ticket salvata.';
            }
        } elseif ($action === 'support_ticket_close') {
            $idTicket = (int) ($_POST['id_ticket'] ?? 0);
            $ticket = cvAssistantSupportTicketLoad($connection, $idTicket);
            if (!is_array($ticket)) {
                $state['errors'][] = 'Ticket assistenza non trovato.';
            } else {
                cvAssistantSupportTicketUpdate($connection, $idTicket, ['status' => 'closed']);
                $conversationId = (int) ($ticket['id_conversation'] ?? 0);
                if ($conversationId > 0) {
                    $statement = $connection->prepare('UPDATE cv_assistant_conversations
                        SET status = "closed", resolved_at = COALESCE(resolved_at, CURRENT_TIMESTAMP), last_message_at = CURRENT_TIMESTAMP
                        WHERE id_conversation = ? LIMIT 1');
                    if ($statement instanceof mysqli_stmt) {
                        $statement->bind_param('i', $conversationId);
                        $statement->execute();
                        $statement->close();
                    }
                }
                $state['messages'][] = 'Conversazione chiusa.';
            }
        }
    }

    $supportTickets = cvAssistantSupportTicketList($connection, 'all', 50);
    foreach ($supportTickets as $supportTicket) {
        $supportId = (int) ($supportTicket['id_ticket'] ?? 0);
        if ($supportId > 0) {
            $supportTicketMessages[$supportId] = cvAssistantSupportTicketMessages($connection, $supportId, 20);
        }
    }
} catch (Throwable $exception) {
    $state['errors'][] = 'Errore sezione ticket assistenza: ' . $exception->getMessage();
}

cvAccessoRenderPageStart('Assistente · Ticket', 'assistant-tickets', $state);
?>
<div class="row">
    <div class="col-md-12">
        <p class="cv-page-intro">Assistenza clienti: ticket e messaggi con gestione manuale risposte.</p>
        <nav class="cv-assistant-subnav">
            <a href="<?= cvAccessoH(cvAccessoUrl('assistant.php')) ?>">Configurazione</a>
            <a class="is-active" href="<?= cvAccessoH(cvAccessoUrl('assistant_tickets.php')) ?>">Ticket e messaggi</a>
            <a href="<?= cvAccessoH(cvAccessoUrl('assistant_conversations.php')) ?>">Conversazioni recenti</a>
        </nav>
    </div>
</div>

<div class="row">
    <div class="col-md-12">
        <div class="cv-panel-card">
            <h4>Ticket assistenza</h4>
            <p class="cv-muted">Visualizza e gestisci in autonomia ticket e messaggi associati.</p>

            <?php foreach ($supportTickets as $supportTicket): ?>
                <?php
                $supportId = (int) ($supportTicket['id_ticket'] ?? 0);
                $ticketMessages = isset($supportTicketMessages[$supportId]) && is_array($supportTicketMessages[$supportId])
                    ? $supportTicketMessages[$supportId]
                    : [];
                ?>
                <details class="cv-assistant-thread" id="ticket-<?= $supportId ?>">
                    <summary>
                        <span class="cv-assistant-thread-title">Ticket #<?= $supportId ?> · <?= cvAccessoH((string) ($supportTicket['subject'] ?? 'Richiesta assistenza')) ?></span>
                        <span class="cv-assistant-thread-meta">
                            Stato: <strong><?= cvAccessoH((string) ($supportTicket['status'] ?? 'open')) ?></strong> |
                            Cliente: <strong><?= cvAccessoH((string) ($supportTicket['customer_name'] ?? '-')) ?></strong> |
                            Ultimo aggiornamento: <strong><?= cvAccessoH((string) ($supportTicket['last_message_at'] ?? '')) ?></strong>
                        </span>
                        <span class="cv-assistant-thread-preview">Ultimo messaggio: <?= cvAccessoH(trim((string) ($supportTicket['last_ticket_message'] ?? ''))) ?></span>
                    </summary>
                    <div class="cv-assistant-thread-body">
                        <div class="cv-muted mb-2">
                            Email: <strong><?= cvAccessoH((string) ($supportTicket['customer_email'] ?? '-')) ?></strong> |
                            Telefono: <strong><?= cvAccessoH((string) ($supportTicket['customer_phone'] ?? '-')) ?></strong> |
                            Ticket viaggio: <strong><?= cvAccessoH((string) ($supportTicket['ticket_code'] ?? '-')) ?></strong> |
                            Provider: <strong><?= cvAccessoH((string) ($supportTicket['provider_code'] ?? '-')) ?></strong>
                        </div>

                        <?php foreach ($ticketMessages as $ticketMessage): ?>
                            <div class="cv-assistant-admin-message cv-assistant-admin-message-<?= cvAccessoH((string) ($ticketMessage['sender_role'] ?? 'user')) ?>">
                                <div class="cv-assistant-admin-role"><?= cvAccessoH((string) ($ticketMessage['sender_role'] ?? 'user')) ?></div>
                                <div class="cv-assistant-admin-text"><?= nl2br(cvAccessoH((string) ($ticketMessage['message_text'] ?? ''))) ?></div>
                                <div class="cv-assistant-admin-date"><?= cvAccessoH((string) ($ticketMessage['created_at'] ?? '')) ?></div>
                            </div>
                        <?php endforeach; ?>

                        <form method="post" class="cv-form-grid" style="margin-top:12px;">
                            <input type="hidden" id="support_action_<?= $supportId ?>" name="action" value="support_ticket_reply">
                            <input type="hidden" name="id_ticket" value="<?= $supportId ?>">
                            <?= cvAccessoCsrfField() ?>
                            <div class="form-group">
                                <label for="support_reply_<?= $supportId ?>">Risposta manuale</label>
                                <textarea id="support_reply_<?= $supportId ?>" name="reply_text" class="form-control" rows="4"></textarea>
                            </div>
                            <div class="form-group">
                                <label for="support_status_<?= $supportId ?>">Nuovo stato</label>
                                <select id="support_status_<?= $supportId ?>" name="status" class="form-control">
                                    <?php foreach (['open' => 'open', 'pending' => 'pending', 'closed' => 'closed'] as $statusValue => $statusLabel): ?>
                                        <option value="<?= cvAccessoH($statusValue) ?>"<?= strtolower(trim((string) ($supportTicket['status'] ?? 'open'))) === $statusValue ? ' selected' : '' ?>><?= cvAccessoH($statusLabel) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="cv-inline-actions">
                                <button type="submit" class="btn btn-primary" onclick="document.getElementById('support_action_<?= $supportId ?>').value = 'support_ticket_reply';">Invia risposta</button>
                                <button type="submit" class="btn btn-default" onclick="document.getElementById('support_action_<?= $supportId ?>').value = 'support_ticket_close'; return confirm('Chiudere questa conversazione?');">Chiudi conversazione</button>
                            </div>
                        </form>
                    </div>
                </details>
            <?php endforeach; ?>

            <?php if (count($supportTickets) === 0): ?>
                <div class="cv-empty">Nessun ticket assistenza presente.</div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php
cvAccessoRenderPageEnd();
