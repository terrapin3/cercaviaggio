<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../includes/assistant_tools.php';

$state = cvAccessoInit();
if (!$state['authenticated']) {
    if (isset($_GET['ajax'])) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Sessione non autenticata.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return;
    }
    cvAccessoRenderLoginPage($state);
    return;
}

if (!cvAccessoIsAdmin($state)) {
    if (isset($_GET['ajax'])) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Accesso riservato all’amministratore.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return;
    }
    http_response_code(403);
    cvAccessoRenderPageStart('Assistente', 'assistant', $state);
    ?>
    <div class="row">
        <div class="col-md-12">
            <div class="cv-panel-card">
                <div class="cv-empty">Questa sezione e disponibile solo per l’amministratore.</div>
            </div>
        </div>
    </div>
    <?php
    cvAccessoRenderPageEnd();
    return;
}

if (!function_exists('cvAssistantDefaultOperatorGreeting')) {
    function cvAssistantDefaultOperatorGreeting(int $idTicket = 0): string
    {
        $names = ['Luca', 'Giulia', 'Marco', 'Sara'];
        $index = $idTicket > 0 ? ($idTicket % count($names)) : 0;
        $name = $names[$index] ?? 'Luca';
        return 'Salve, sono ' . $name . ' del supporto Cercaviaggio. Come posso esserle utile?';
    }
}

if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $connection = cvAccessoRequireConnection();
        cvAssistantEnsureTables($connection);
        $ajaxAction = strtolower(trim((string) ($_GET['ajax'] ?? '')));

        if ($ajaxAction === 'support_poll') {
            $afterId = isset($_GET['after_id']) ? max(0, (int) $_GET['after_id']) : 0;
            $assistantSettings = cvAssistantSettings($connection);
            $freshMinutes = max(1, (int) ($assistantSettings['operator_busy_timeout_minutes'] ?? 6));
            $freshSeconds = $freshMinutes * 60;
            $tickets = cvAssistantSupportTicketList($connection, 'all', 30);
            $openItems = [];
            $maxTicketId = $afterId;
            $unreadCount = 0;
            $openCount = 0;
            foreach ($tickets as $ticket) {
                $status = strtolower(trim((string) ($ticket['status'] ?? 'open')));
                if (!in_array($status, ['open', 'pending'], true)) {
                    continue;
                }
                $idTicket = (int) ($ticket['id_ticket'] ?? 0);
                if ($idTicket <= 0) {
                    continue;
                }
                if ($idTicket > $maxTicketId) {
                    $maxTicketId = $idTicket;
                }
                if ($idTicket > $afterId) {
                    $unreadCount++;
                }
                $lastMessageAtRaw = trim((string) ($ticket['last_message_at'] ?? ''));
                $isFresh = false;
                if ($lastMessageAtRaw !== '') {
                    $lastTs = strtotime($lastMessageAtRaw);
                    if (is_int($lastTs) && $lastTs > 0) {
                        $isFresh = (time() - $lastTs) <= $freshSeconds;
                    }
                }
                if ($isFresh) {
                    $openCount++;
                }
                if ($isFresh && count($openItems) < 8) {
                    $openItems[] = [
                        'id_ticket' => $idTicket,
                        'status' => $status,
                        'subject' => trim((string) ($ticket['subject'] ?? 'Richiesta assistenza')),
                        'customer_name' => trim((string) ($ticket['customer_name'] ?? '')),
                        'provider_code' => trim((string) ($ticket['provider_code'] ?? '')),
                        'last_message_at' => trim((string) ($ticket['last_message_at'] ?? '')),
                        'last_ticket_message' => trim((string) ($ticket['last_ticket_message'] ?? '')),
                    ];
                }
            }

            echo json_encode([
                'success' => true,
                'data' => [
                    'max_ticket_id' => $maxTicketId,
                    'unread_count' => $unreadCount,
                    'open_count' => $openCount,
                    'items' => $openItems,
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }

        if ($ajaxAction === 'support_close') {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !cvAccessoValidateCsrf()) {
                http_response_code(422);
                echo json_encode([
                    'success' => false,
                    'message' => 'Sessione non valida.',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                return;
            }
            $idTicket = max(0, (int) ($_POST['id_ticket'] ?? 0));
            $ticket = cvAssistantSupportTicketLoad($connection, $idTicket);
            if (!is_array($ticket)) {
                http_response_code(404);
                echo json_encode([
                    'success' => false,
                    'message' => 'Ticket non trovato.',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                return;
            }
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

            echo json_encode([
                'success' => true,
                'message' => 'Conversazione chiusa.',
                'data' => ['id_ticket' => $idTicket],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }

        if ($ajaxAction === 'support_thread') {
            $idTicket = max(0, (int) ($_GET['id_ticket'] ?? 0));
            $ticket = cvAssistantSupportTicketLoad($connection, $idTicket);
            if (!is_array($ticket)) {
                http_response_code(404);
                echo json_encode([
                    'success' => false,
                    'message' => 'Ticket non trovato.',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                return;
            }
            $messages = cvAssistantSupportTicketMessages($connection, $idTicket, 50);
            $hasAdminMessage = false;
            foreach ($messages as $msg) {
                if (strtolower(trim((string) ($msg['sender_role'] ?? ''))) === 'admin') {
                    $hasAdminMessage = true;
                    break;
                }
            }
            echo json_encode([
                'success' => true,
                'data' => [
                    'ticket' => [
                        'id_ticket' => (int) ($ticket['id_ticket'] ?? 0),
                        'status' => (string) ($ticket['status'] ?? 'open'),
                        'subject' => (string) ($ticket['subject'] ?? ''),
                        'customer_name' => (string) ($ticket['customer_name'] ?? ''),
                    ],
                    'messages' => $messages,
                    'has_admin_message' => $hasAdminMessage,
                    'default_greeting' => cvAssistantDefaultOperatorGreeting((int) ($ticket['id_ticket'] ?? 0)),
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }

        if ($ajaxAction === 'support_send') {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !cvAccessoValidateCsrf()) {
                http_response_code(422);
                echo json_encode([
                    'success' => false,
                    'message' => 'Sessione non valida.',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                return;
            }

            $idTicket = max(0, (int) ($_POST['id_ticket'] ?? 0));
            $accept = isset($_POST['accept']) ? (int) $_POST['accept'] === 1 : false;
            $replyText = trim((string) ($_POST['reply_text'] ?? ''));
            $ticket = cvAssistantSupportTicketLoad($connection, $idTicket);
            if (!is_array($ticket)) {
                http_response_code(404);
                echo json_encode([
                    'success' => false,
                    'message' => 'Ticket non trovato.',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                return;
            }

            if ($accept && $replyText === '') {
                $replyText = cvAssistantDefaultOperatorGreeting($idTicket);
            }
            if ($replyText === '') {
                http_response_code(422);
                echo json_encode([
                    'success' => false,
                    'message' => 'Scrivi un messaggio da inviare.',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                return;
            }

            cvAssistantSupportTicketAddMessage(
                $connection,
                $idTicket,
                'admin',
                $replyText,
                (string) ($state['current_user']['email'] ?? 'admin')
            );
            cvAssistantSupportTicketUpdate($connection, $idTicket, [
                'status' => 'pending',
                'subject' => (string) ($ticket['subject'] ?? ''),
            ]);

            $conversationId = (int) ($ticket['id_conversation'] ?? 0);
            if ($conversationId > 0) {
                cvAssistantLogMessage(
                    $connection,
                    $conversationId,
                    'assistant',
                    'Operatore: ' . $replyText,
                    'support_ticket_reply',
                    0.99,
                    ['support_ticket_id' => $idTicket, 'kind' => 'admin_reply']
                );
            }

            $messages = cvAssistantSupportTicketMessages($connection, $idTicket, 50);
            echo json_encode([
                'success' => true,
                'message' => 'Messaggio inviato.',
                'data' => [
                    'id_ticket' => $idTicket,
                    'sent_text' => $replyText,
                    'messages' => $messages,
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }

        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Azione AJAX non valida.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return;
    } catch (Throwable $exception) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Errore interno: ' . $exception->getMessage(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return;
    }
}

$assistantSettings = cvAssistantDefaults();
$quickRepliesText = implode("\n", cvAssistantDefaultQuickReplies());
$knowledgeItems = [];
$stats = [
    'knowledge_total' => 0,
    'knowledge_active' => 0,
    'conversations_total' => 0,
    'messages_total' => 0,
    'conversations_today' => 0,
    'feedback_positive' => 0,
    'feedback_negative' => 0,
    'support_tickets_open' => 0,
];
$providerOptions = [];
$editingKnowledge = [
    'id_knowledge' => 0,
    'title' => '',
    'question_example' => '',
    'keywords' => '',
    'answer_text' => '',
    'provider_code' => '',
    'ticket_required' => 0,
    'priority' => 100,
    'active' => 1,
];

try {
    $connection = cvAccessoRequireConnection();
    cvAssistantEnsureTables($connection);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = trim((string) ($_POST['action'] ?? ''));
        if (!cvAccessoValidateCsrf()) {
            $state['errors'][] = 'Sessione non valida. Ricarica la pagina.';
        } elseif ($action === 'save_assistant_settings') {
            $assistantSettings = cvAssistantSaveSettings($connection, [
                'assistant_name' => (string) ($_POST['assistant_name'] ?? ''),
                'assistant_badge' => (string) ($_POST['assistant_badge'] ?? ''),
                'welcome_message' => (string) ($_POST['welcome_message'] ?? ''),
                'fallback_message' => (string) ($_POST['fallback_message'] ?? ''),
                'escalation_message' => (string) ($_POST['escalation_message'] ?? ''),
                'quick_replies' => (string) ($_POST['quick_replies'] ?? ''),
                'widget_enabled' => isset($_POST['widget_enabled']) ? 1 : 0,
                'collect_logs' => isset($_POST['collect_logs']) ? 1 : 0,
                'learning_enabled' => isset($_POST['learning_enabled']) ? 1 : 0,
                'feedback_enabled' => isset($_POST['feedback_enabled']) ? 1 : 0,
                'ticketing_enabled' => isset($_POST['ticketing_enabled']) ? 1 : 0,
                'recovery_email_enabled' => isset($_POST['recovery_email_enabled']) ? 1 : 0,
                'operator_handoff_after_unresolved' => (int) ($_POST['operator_handoff_after_unresolved'] ?? 4),
                'operator_handoff_label' => (string) ($_POST['operator_handoff_label'] ?? ''),
                'operator_busy_timeout_minutes' => (int) ($_POST['operator_busy_timeout_minutes'] ?? 6),
                'updated_by' => (string) ($state['current_user']['email'] ?? ''),
            ]);
            $state['messages'][] = 'Configurazione assistente aggiornata.';
        } elseif ($action === 'save_knowledge') {
            $savedId = cvAssistantKnowledgeSave($connection, [
                'id_knowledge' => (int) ($_POST['id_knowledge'] ?? 0),
                'title' => (string) ($_POST['title'] ?? ''),
                'question_example' => (string) ($_POST['question_example'] ?? ''),
                'keywords' => (string) ($_POST['keywords'] ?? ''),
                'answer_text' => (string) ($_POST['answer_text'] ?? ''),
                'provider_code' => (string) ($_POST['provider_code'] ?? ''),
                'ticket_required' => isset($_POST['ticket_required']) ? 1 : 0,
                'priority' => (int) ($_POST['priority'] ?? 100),
                'active' => isset($_POST['active']) ? 1 : 0,
            ]);
            $state['messages'][] = $savedId > 0 ? 'Voce knowledge salvata.' : 'Knowledge aggiornata.';
            $editingKnowledge['id_knowledge'] = 0;
        } elseif ($action === 'delete_knowledge') {
            $deleteId = (int) ($_POST['id_knowledge'] ?? 0);
            if ($deleteId <= 0 || !cvAssistantKnowledgeDelete($connection, $deleteId)) {
                $state['errors'][] = 'Eliminazione knowledge non riuscita.';
            } else {
                $state['messages'][] = 'Voce knowledge eliminata.';
            }
        } elseif ($action === 'cleanup_conversations_before') {
            $beforeDate = trim((string) ($_POST['before_date'] ?? ''));
            $cleanup = cvAssistantDeleteConversationsBefore($connection, $beforeDate);
            $state['messages'][] = 'Pulizia conversazioni completata. Conversazioni: ' . (int) ($cleanup['conversations_deleted'] ?? 0)
                . ', messaggi: ' . (int) ($cleanup['messages_deleted'] ?? 0)
                . ', feedback: ' . (int) ($cleanup['feedback_deleted'] ?? 0)
                . ', ticket assistenza: ' . (int) ($cleanup['support_tickets_deleted'] ?? 0)
                . ', messaggi assistenza: ' . (int) ($cleanup['support_messages_deleted'] ?? 0) . '.';
        }
    }

    $assistantSettings = cvAssistantSettings($connection);
    $quickRepliesText = implode("\n", cvAssistantQuickReplies($assistantSettings));
    $knowledgeItems = cvAssistantKnowledgeList($connection);
    $stats = cvAssistantStats($connection);

    $providerResult = $connection->query('SELECT code, nome FROM aziende WHERE TRIM(COALESCE(code, "")) <> "" ORDER BY nome ASC');
    if ($providerResult instanceof mysqli_result) {
        while ($row = $providerResult->fetch_assoc()) {
            if (!is_array($row)) {
                continue;
            }
            $code = strtolower(trim((string) ($row['code'] ?? '')));
            if ($code === '') {
                continue;
            }
            $providerOptions[$code] = trim((string) ($row['nome'] ?? $code));
        }
        $providerResult->free();
    }

    $editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
    if ($editId > 0) {
        foreach ($knowledgeItems as $item) {
            if ((int) ($item['id_knowledge'] ?? 0) === $editId) {
                $editingKnowledge = $item;
                break;
            }
        }
    }

} catch (Throwable $exception) {
    $state['errors'][] = 'Errore sezione assistente: ' . $exception->getMessage();
}

cvAccessoRenderPageStart('Assistente', 'assistant', $state);
?>
<div class="row">
    <div class="col-md-12">
        <p class="cv-page-intro">
            Backend assistente conversazionale Cercaviaggio. Qui gestisci benvenuto, risposte FAQ, memoria conoscenza e storico chat.
        </p>
        <nav class="cv-assistant-subnav">
            <a class="is-active" href="<?= cvAccessoH(cvAccessoUrl('assistant.php')) ?>">Configurazione</a>
            <a href="<?= cvAccessoH(cvAccessoUrl('assistant_tickets.php')) ?>">Ticket e messaggi</a>
            <a href="<?= cvAccessoH(cvAccessoUrl('assistant_conversations.php')) ?>">Conversazioni recenti</a>
        </nav>
    </div>
</div>

<div class="row">
    <div class="col-sm-6 col-lg-3">
        <div class="cv-stat-card">
            <div class="cv-stat-value"><?= (int) ($stats['knowledge_active'] ?? 0) ?></div>
            <div class="cv-stat-label">Knowledge attive</div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="cv-stat-card">
            <div class="cv-stat-value"><?= (int) ($stats['conversations_total'] ?? 0) ?></div>
            <div class="cv-stat-label">Conversazioni salvate</div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="cv-stat-card">
            <div class="cv-stat-value"><?= (int) ($stats['messages_total'] ?? 0) ?></div>
            <div class="cv-stat-label">Messaggi loggati</div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="cv-stat-card">
            <div class="cv-stat-value"><?= (int) ($stats['conversations_today'] ?? 0) ?></div>
            <div class="cv-stat-label">Chat avviate oggi</div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="cv-stat-card">
            <div class="cv-stat-value"><?= (int) ($stats['support_tickets_open'] ?? 0) ?></div>
            <div class="cv-stat-label">Ticket assistenza aperti</div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="cv-stat-card">
            <div class="cv-stat-value"><?= (int) ($stats['feedback_positive'] ?? 0) ?>/<?= (int) ($stats['feedback_negative'] ?? 0) ?></div>
            <div class="cv-stat-label">Feedback utile / non utile</div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-7">
        <div class="cv-panel-card">
            <h4>Configurazione assistente</h4>
            <form method="post" class="cv-form-grid">
                <input type="hidden" name="action" value="save_assistant_settings">
                <?= cvAccessoCsrfField() ?>

                <div class="form-group">
                    <label for="assistant_name">Nome assistente</label>
                    <input id="assistant_name" name="assistant_name" type="text" class="form-control" value="<?= cvAccessoH((string) ($assistantSettings['assistant_name'] ?? '')) ?>">
                </div>

                <div class="form-group">
                    <label for="assistant_badge">Badge widget</label>
                    <input id="assistant_badge" name="assistant_badge" type="text" class="form-control" value="<?= cvAccessoH((string) ($assistantSettings['assistant_badge'] ?? '')) ?>">
                </div>

                <div class="form-group">
                    <label for="welcome_message">Messaggio di benvenuto</label>
                    <textarea id="welcome_message" name="welcome_message" class="form-control" rows="4"><?= cvAccessoH((string) ($assistantSettings['welcome_message'] ?? '')) ?></textarea>
                    <div class="cv-muted">Primo messaggio mostrato all’apertura della chat.</div>
                </div>

                <div class="form-group">
                    <label for="fallback_message">Messaggio fallback</label>
                    <textarea id="fallback_message" name="fallback_message" class="form-control" rows="4"><?= cvAccessoH((string) ($assistantSettings['fallback_message'] ?? '')) ?></textarea>
                    <div class="cv-muted">Risposta quando la domanda non trova ticket o FAQ compatibili.</div>
                </div>

                <div class="form-group">
                    <label for="escalation_message">Messaggio escalation</label>
                    <textarea id="escalation_message" name="escalation_message" class="form-control" rows="3"><?= cvAccessoH((string) ($assistantSettings['escalation_message'] ?? '')) ?></textarea>
                    <div class="cv-muted">Testo usato quando la chat deve indirizzare il cliente al provider corretto.</div>
                </div>

                <div class="form-group">
                    <label for="quick_replies">Opzioni rapide iniziali</label>
                    <textarea id="quick_replies" name="quick_replies" class="form-control" rows="5"><?= cvAccessoH($quickRepliesText) ?></textarea>
                    <div class="cv-muted">Una opzione per riga. La chat le mostra al primo avvio e dopo le risposte generiche.</div>
                </div>

                <div class="form-group">
                    <label class="cv-checkbox-inline">
                        <input type="checkbox" name="widget_enabled" value="1"<?= !empty($assistantSettings['widget_enabled']) ? ' checked' : '' ?>>
                        Widget chat pubblico attivo
                    </label>
                </div>

                <div class="form-group">
                    <label class="cv-checkbox-inline">
                        <input type="checkbox" name="collect_logs" value="1"<?= !empty($assistantSettings['collect_logs']) ? ' checked' : '' ?>>
                        Salva conversazioni e messaggi
                    </label>
                </div>

                <div class="form-group">
                    <label class="cv-checkbox-inline">
                        <input type="checkbox" name="learning_enabled" value="1"<?= !empty($assistantSettings['learning_enabled']) ? ' checked' : '' ?>>
                        Modalita apprendimento attiva
                    </label>
                    <div class="cv-muted">L’apprendimento avviene tramite knowledge base e revisione delle chat salvate.</div>
                </div>

                <div class="form-group">
                    <label class="cv-checkbox-inline">
                        <input type="checkbox" name="feedback_enabled" value="1"<?= !empty($assistantSettings['feedback_enabled']) ? ' checked' : '' ?>>
                        Feedback manina su/giu attivo
                    </label>
                </div>

                <div class="form-group">
                    <label class="cv-checkbox-inline">
                        <input type="checkbox" name="ticketing_enabled" value="1"<?= !empty($assistantSettings['ticketing_enabled']) ? ' checked' : '' ?>>
                        Ticket assistenza da chat attivi
                    </label>
                    <div class="cv-muted">Se la chat non basta, il cliente puo aprire un ticket che trovi qui sotto nel backend.</div>
                </div>

                <div class="form-group">
                    <label for="operator_handoff_after_unresolved">Mostra "Chatta con operatore" dopo N risposte non risolte</label>
                    <input id="operator_handoff_after_unresolved" name="operator_handoff_after_unresolved" type="number" min="1" max="12" class="form-control" value="<?= (int) ($assistantSettings['operator_handoff_after_unresolved'] ?? 4) ?>">
                </div>

                <div class="form-group">
                    <label for="operator_handoff_label">Testo bottone operatore</label>
                    <input id="operator_handoff_label" name="operator_handoff_label" type="text" maxlength="120" class="form-control" value="<?= cvAccessoH((string) ($assistantSettings['operator_handoff_label'] ?? 'Chatta con un operatore')) ?>">
                </div>

                <div class="form-group">
                    <label for="operator_busy_timeout_minutes">Messaggio "operatori occupati" dopo N minuti senza risposta admin</label>
                    <input id="operator_busy_timeout_minutes" name="operator_busy_timeout_minutes" type="number" min="1" max="120" class="form-control" value="<?= (int) ($assistantSettings['operator_busy_timeout_minutes'] ?? 6) ?>">
                    <div class="cv-muted">Se un ticket resta in attesa, la chat avvisa automaticamente il cliente.</div>
                </div>

                <div class="form-group">
                    <label class="cv-checkbox-inline">
                        <input type="checkbox" name="recovery_email_enabled" value="1"<?= !empty($assistantSettings['recovery_email_enabled']) ? ' checked' : '' ?>>
                        Recupero sicuro via email attivo
                    </label>
                    <div class="cv-muted">Nel recupero senza codice la chat non mostra il ticket in chiaro: invia un link all’email del viaggiatore quando disponibile.</div>
                </div>

                <div class="cv-inline-actions">
                    <button type="submit" class="btn btn-primary">Salva configurazione</button>
                </div>
            </form>
        </div>
    </div>

    <div class="col-md-5">
        <div class="cv-panel-card">
            <h4>Pulizia conversazioni</h4>
            <form method="post" class="cv-form-grid" style="margin-bottom:18px;">
                <input type="hidden" name="action" value="cleanup_conversations_before">
                <?= cvAccessoCsrfField() ?>
                <div class="form-group">
                    <label for="assistant_cleanup_before">Cancella chat con ultimo messaggio precedente al</label>
                    <input id="assistant_cleanup_before" name="before_date" type="date" class="form-control" value="<?= cvAccessoH(date('Y-m-d')) ?>">
                </div>
                <div class="cv-inline-actions">
                    <button type="submit" class="btn btn-danger" onclick="return confirm('Eliminare definitivamente le conversazioni precedenti alla data indicata?');">Esegui pulizia</button>
                    <a class="btn btn-default" href="<?= cvAccessoH(cvAccessoUrl('manutenzione.php')) ?>">Apri Manutenzione</a>
                </div>
            </form>
        </div>

        <div class="cv-panel-card">
            <h4><?= (int) ($editingKnowledge['id_knowledge'] ?? 0) > 0 ? 'Modifica knowledge' : 'Nuova knowledge' ?></h4>
            <form method="post" class="cv-form-grid">
                <input type="hidden" name="action" value="save_knowledge">
                <input type="hidden" name="id_knowledge" value="<?= (int) ($editingKnowledge['id_knowledge'] ?? 0) ?>">
                <?= cvAccessoCsrfField() ?>

                <div class="form-group">
                    <label for="knowledge_title">Titolo</label>
                    <input id="knowledge_title" name="title" type="text" class="form-control" value="<?= cvAccessoH((string) ($editingKnowledge['title'] ?? '')) ?>">
                </div>

                <div class="form-group">
                    <label for="knowledge_question_example">Esempio domanda</label>
                    <input id="knowledge_question_example" name="question_example" type="text" class="form-control" value="<?= cvAccessoH((string) ($editingKnowledge['question_example'] ?? '')) ?>">
                </div>

                <div class="form-group">
                    <label for="knowledge_keywords">Keyword</label>
                    <input id="knowledge_keywords" name="keywords" type="text" class="form-control" value="<?= cvAccessoH((string) ($editingKnowledge['keywords'] ?? '')) ?>" placeholder="es. cambio,cambiare,data">
                </div>

                <div class="form-group">
                    <label for="knowledge_provider_code">Provider</label>
                    <select id="knowledge_provider_code" name="provider_code" class="form-control">
                        <option value="">Tutti i provider</option>
                        <?php foreach ($providerOptions as $providerCode => $providerName): ?>
                            <option value="<?= cvAccessoH($providerCode) ?>"<?= strtolower(trim((string) ($editingKnowledge['provider_code'] ?? ''))) === $providerCode ? ' selected' : '' ?>>
                                <?= cvAccessoH($providerName) ?> (<?= cvAccessoH($providerCode) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="knowledge_answer_text">Risposta</label>
                    <textarea id="knowledge_answer_text" name="answer_text" class="form-control" rows="6"><?= cvAccessoH((string) ($editingKnowledge['answer_text'] ?? '')) ?></textarea>
                </div>

                <div class="form-group">
                    <label for="knowledge_priority">Priorita</label>
                    <input id="knowledge_priority" name="priority" type="number" min="1" class="form-control" value="<?= cvAccessoH((string) ((int) ($editingKnowledge['priority'] ?? 100))) ?>">
                </div>

                <div class="form-group">
                    <label class="cv-checkbox-inline">
                        <input type="checkbox" name="ticket_required" value="1"<?= !empty($editingKnowledge['ticket_required']) ? ' checked' : '' ?>>
                        Richiede ticket gia noto
                    </label>
                </div>

                <div class="form-group">
                    <label class="cv-checkbox-inline">
                        <input type="checkbox" name="active" value="1"<?= !array_key_exists('active', $editingKnowledge) || !empty($editingKnowledge['active']) ? ' checked' : '' ?>>
                        Voce attiva
                    </label>
                </div>

                <div class="cv-inline-actions">
                    <button type="submit" class="btn btn-primary">Salva knowledge</button>
                    <?php if ((int) ($editingKnowledge['id_knowledge'] ?? 0) > 0): ?>
                        <a class="btn btn-default" href="<?= cvAccessoH(cvAccessoUrl('assistant.php')) ?>">Nuova voce</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-12">
        <div class="cv-panel-card">
            <h4>Knowledge base</h4>
            <div class="table-responsive">
                <table class="table cv-table">
                    <thead>
                    <tr>
                        <th>Titolo</th>
                        <th>Provider</th>
                        <th>Priorita</th>
                        <th>Hit</th>
                        <th>Stato</th>
                        <th>Azioni</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($knowledgeItems as $item): ?>
                        <?php $knowledgeId = (int) ($item['id_knowledge'] ?? 0); ?>
                        <tr>
                            <td>
                                <strong><?= cvAccessoH((string) ($item['title'] ?? '')) ?></strong><br>
                                <span class="cv-muted"><?= cvAccessoH((string) ($item['question_example'] ?? '')) ?></span>
                            </td>
                            <td><?= cvAccessoH((string) ($item['provider_code'] ?? 'tutti')) ?></td>
                            <td><?= (int) ($item['priority'] ?? 100) ?></td>
                            <td><?= (int) ($item['hits'] ?? 0) ?></td>
                            <td><?= (int) ($item['active'] ?? 0) === 1 ? 'Attiva' : 'Off' ?></td>
                            <td>
                                <a class="btn btn-default btn-sm" href="<?= cvAccessoH(cvAccessoUrl('assistant.php?edit=' . $knowledgeId)) ?>">Modifica</a>
                                <form method="post" style="display:inline-block; margin-left:6px;">
                                    <input type="hidden" name="action" value="delete_knowledge">
                                    <input type="hidden" name="id_knowledge" value="<?= $knowledgeId ?>">
                                    <?= cvAccessoCsrfField() ?>
                                    <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Eliminare questa voce knowledge?');">Elimina</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (count($knowledgeItems) === 0): ?>
                        <tr>
                            <td colspan="6" class="cv-empty">Nessuna voce knowledge disponibile.</td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php
cvAccessoRenderPageEnd();
