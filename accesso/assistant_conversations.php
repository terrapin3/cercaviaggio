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
    cvAccessoRenderPageStart('Assistente · Conversazioni', 'assistant-conversations', $state);
    ?>
    <div class="row"><div class="col-md-12"><div class="cv-panel-card"><div class="cv-empty">Questa sezione e disponibile solo per l’amministratore.</div></div></div></div>
    <?php
    cvAccessoRenderPageEnd();
    return;
}

$recentConversations = [];
$conversationMessages = [];

try {
    $connection = cvAccessoRequireConnection();
    cvAssistantEnsureTables($connection);
    $recentConversations = cvAssistantRecentConversations($connection, 40);
    foreach ($recentConversations as $conversation) {
        $conversationId = (int) ($conversation['id_conversation'] ?? 0);
        if ($conversationId > 0) {
            $conversationMessages[$conversationId] = cvAssistantConversationMessages($connection, $conversationId, 20);
        }
    }
} catch (Throwable $exception) {
    $state['errors'][] = 'Errore sezione conversazioni: ' . $exception->getMessage();
}

cvAccessoRenderPageStart('Assistente · Conversazioni', 'assistant-conversations', $state);
?>
<div class="row">
    <div class="col-md-12">
        <p class="cv-page-intro">Storico conversazioni chat per monitorare richieste e punti non coperti.</p>
        <nav class="cv-assistant-subnav">
            <a href="<?= cvAccessoH(cvAccessoUrl('assistant.php')) ?>">Configurazione</a>
            <a href="<?= cvAccessoH(cvAccessoUrl('assistant_tickets.php')) ?>">Ticket e messaggi</a>
            <a class="is-active" href="<?= cvAccessoH(cvAccessoUrl('assistant_conversations.php')) ?>">Conversazioni recenti</a>
        </nav>
    </div>
</div>

<div class="row">
    <div class="col-md-12">
        <div class="cv-panel-card">
            <h4>Conversazioni recenti</h4>
            <p class="cv-muted">Le chat aiutano a capire cosa aggiungere in knowledge e quali richieste restano scoperte.</p>

            <?php foreach ($recentConversations as $conversation): ?>
                <?php
                $conversationId = (int) ($conversation['id_conversation'] ?? 0);
                $messages = isset($conversationMessages[$conversationId]) && is_array($conversationMessages[$conversationId])
                    ? $conversationMessages[$conversationId]
                    : [];
                $lastUserMessageText = trim((string) ($conversation['last_user_message_text'] ?? ''));
                $lastAssistantMessageText = trim((string) ($conversation['last_assistant_message_text'] ?? ''));
                $previewText = $lastUserMessageText !== '' ? $lastUserMessageText : trim((string) ($conversation['last_message_text'] ?? ''));
                ?>
                <details class="cv-assistant-thread">
                    <summary>
                        <span class="cv-assistant-thread-title">Sessione <?= cvAccessoH((string) ($conversation['session_key'] ?? '')) ?></span>
                        <span class="cv-assistant-thread-meta">
                            Ticket: <strong><?= cvAccessoH((string) ($conversation['ticket_code'] ?? '-')) ?></strong> |
                            Provider: <strong><?= cvAccessoH((string) ($conversation['provider_code'] ?? '-')) ?></strong> |
                            Messaggi: <strong><?= (int) ($conversation['messages_count'] ?? 0) ?></strong> |
                            Ultimo aggiornamento: <strong><?= cvAccessoH((string) ($conversation['last_message_at'] ?? '')) ?></strong>
                        </span>
                        <?php if ($previewText !== ''): ?>
                            <span class="cv-assistant-thread-preview">Richiesta utente: <?= cvAccessoH($previewText) ?></span>
                        <?php endif; ?>
                    </summary>
                    <div class="cv-assistant-thread-body">
                        <?php if ($lastAssistantMessageText !== ''): ?>
                            <div class="cv-muted mb-2">Ultima risposta assistente: <?= cvAccessoH($lastAssistantMessageText) ?></div>
                        <?php endif; ?>
                        <?php foreach ($messages as $message): ?>
                            <div class="cv-assistant-admin-message cv-assistant-admin-message-<?= cvAccessoH((string) ($message['role'] ?? 'assistant')) ?>">
                                <div class="cv-assistant-admin-role">
                                    <?= cvAccessoH((string) ($message['role'] ?? 'assistant')) ?>
                                    <?php if (trim((string) ($message['intent'] ?? '')) !== ''): ?>
                                        <span class="cv-assistant-admin-intent"><?= cvAccessoH((string) ($message['intent'] ?? '')) ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="cv-assistant-admin-text"><?= nl2br(cvAccessoH((string) ($message['message_text'] ?? ''))) ?></div>
                                <div class="cv-assistant-admin-date"><?= cvAccessoH((string) ($message['created_at'] ?? '')) ?></div>
                            </div>
                        <?php endforeach; ?>
                        <?php if (count($messages) === 0): ?>
                            <div class="cv-empty">Nessun messaggio disponibile.</div>
                        <?php endif; ?>
                    </div>
                </details>
            <?php endforeach; ?>

            <?php if (count($recentConversations) === 0): ?>
                <div class="cv-empty">Nessuna conversazione registrata al momento.</div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php
cvAccessoRenderPageEnd();
