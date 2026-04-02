<?php
declare(strict_types=1);

if (!function_exists('cvAssistantDefaultQuickReplies')) {
    /**
     * @return array<int,string>
     */
    function cvAssistantDefaultQuickReplies(): array
    {
        return [
            'Controlla stato biglietto',
            'Scarica PDF',
            'Cambio biglietto',
            'Domande frequenti',
        ];
    }
}

if (!function_exists('cvAssistantDefaults')) {
    /**
     * @return array<string,mixed>
     */
    function cvAssistantDefaults(): array
    {
        return [
            'assistant_name' => 'Assistente Cercaviaggio',
            'assistant_badge' => 'Supporto viaggi',
            'welcome_message' => 'Ciao, sono l\'assistente di Cercaviaggio. Posso aiutarti con biglietti, PDF, cambi e domande frequenti. Come posso esserti utile oggi?',
            'fallback_message' => 'Posso aiutarti su stato del biglietto, PDF, cambio, recupero ticket e FAQ principali. Se vuoi verificare un viaggio, scrivimi il codice biglietto o incolla il QR.',
            'escalation_message' => 'Se rilevo un problema sul biglietto ti indico il numero corretto del provider che effettua il viaggio.',
            'quick_replies_json' => json_encode(cvAssistantDefaultQuickReplies(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'widget_enabled' => 1,
            'collect_logs' => 1,
            'learning_enabled' => 1,
            'feedback_enabled' => 1,
            'ticketing_enabled' => 1,
            'recovery_email_enabled' => 1,
            'operator_handoff_after_unresolved' => 4,
            'operator_handoff_label' => 'Chatta con un operatore',
            'operator_busy_timeout_minutes' => 6,
            'updated_by' => '',
        ];
    }
}

if (!function_exists('cvAssistantDefaultKnowledgeSeed')) {
    /**
     * @return array<int,array<string,mixed>>
     */
    function cvAssistantDefaultKnowledgeSeed(): array
    {
        return [
            [
                'title' => 'Cambio biglietto',
                'question_example' => 'Come posso cambiare il biglietto?',
                'keywords' => 'cambio,cambiare,modificare,data,spostare,corsa',
                'answer_text' => 'Posso aiutarti a verificare se il cambio e disponibile. Scrivimi prima il codice biglietto e ti indico lo stato del ticket e il percorso corretto per proseguire.',
                'provider_code' => '',
                'ticket_required' => 1,
                'priority' => 10,
                'active' => 1,
            ],
            [
                'title' => 'Scarico PDF',
                'question_example' => 'Come scarico il PDF del biglietto?',
                'keywords' => 'pdf,scarica,download,stampa,biglietto',
                'answer_text' => 'Se mi indichi il codice biglietto posso proporti subito il link per scaricare il PDF o aprire il dettaglio del ticket.',
                'provider_code' => '',
                'ticket_required' => 1,
                'priority' => 20,
                'active' => 1,
            ],
            [
                'title' => 'Acquisto da ospite',
                'question_example' => 'Posso acquistare senza registrarmi?',
                'keywords' => 'ospite,senza login,senza registrazione,account,registrarmi',
                'answer_text' => 'Si, su Cercaviaggio puoi acquistare anche da ospite. Dopo l\'acquisto puoi recuperare il biglietto online usando il codice del ticket.',
                'provider_code' => '',
                'ticket_required' => 0,
                'priority' => 30,
                'active' => 1,
            ],
            [
                'title' => 'Recupero biglietto',
                'question_example' => 'Come recupero un biglietto acquistato?',
                'keywords' => 'recupera,recupero,biglietto,codice,qr',
                'answer_text' => 'Puoi recuperare un biglietto dalla home o direttamente qui in chat: scrivimi il codice biglietto oppure incolla il testo del QR.',
                'provider_code' => '',
                'ticket_required' => 0,
                'priority' => 40,
                'active' => 1,
            ],
            [
                'title' => 'Contatto provider',
                'question_example' => 'Chi devo contattare se c e un problema?',
                'keywords' => 'contatto,telefono,assistenza,problema,provider,azienda',
                'answer_text' => 'Se il ticket ha un problema operativo posso indicarti il numero del provider corretto. Per farlo mi serve il codice biglietto.',
                'provider_code' => '',
                'ticket_required' => 0,
                'priority' => 50,
                'active' => 1,
            ],
        ];
    }
}

if (!function_exists('cvAssistantEnsureTables')) {
    function cvAssistantEnsureTables(mysqli $connection): bool
    {
        $queries = [
            <<<SQL
CREATE TABLE IF NOT EXISTS cv_assistant_settings (
  id_sett BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  assistant_name VARCHAR(120) NOT NULL DEFAULT '',
  assistant_badge VARCHAR(120) NOT NULL DEFAULT '',
  welcome_message TEXT NOT NULL,
  fallback_message TEXT NOT NULL,
  escalation_message TEXT NOT NULL,
  quick_replies_json TEXT DEFAULT NULL,
  widget_enabled TINYINT(1) NOT NULL DEFAULT 1,
  collect_logs TINYINT(1) NOT NULL DEFAULT 1,
  learning_enabled TINYINT(1) NOT NULL DEFAULT 1,
  feedback_enabled TINYINT(1) NOT NULL DEFAULT 1,
  ticketing_enabled TINYINT(1) NOT NULL DEFAULT 1,
  recovery_email_enabled TINYINT(1) NOT NULL DEFAULT 1,
  operator_handoff_after_unresolved INT NOT NULL DEFAULT 4,
  operator_handoff_label VARCHAR(120) NOT NULL DEFAULT 'Chatta con un operatore',
  operator_busy_timeout_minutes INT NOT NULL DEFAULT 6,
  updated_by VARCHAR(190) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id_sett)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,
            <<<SQL
CREATE TABLE IF NOT EXISTS cv_assistant_knowledge (
  id_knowledge BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  title VARCHAR(190) NOT NULL,
  question_example VARCHAR(255) NOT NULL DEFAULT '',
  keywords VARCHAR(255) NOT NULL DEFAULT '',
  answer_text MEDIUMTEXT NOT NULL,
  provider_code VARCHAR(50) NOT NULL DEFAULT '',
  ticket_required TINYINT(1) NOT NULL DEFAULT 0,
  priority INT NOT NULL DEFAULT 100,
  active TINYINT(1) NOT NULL DEFAULT 1,
  hits INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id_knowledge),
  KEY idx_cv_assistant_knowledge_active (active, priority),
  KEY idx_cv_assistant_knowledge_provider (provider_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,
            <<<SQL
CREATE TABLE IF NOT EXISTS cv_assistant_conversations (
  id_conversation BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_key VARCHAR(80) NOT NULL,
  channel VARCHAR(32) NOT NULL DEFAULT 'web',
  ticket_code VARCHAR(80) NOT NULL DEFAULT '',
  provider_code VARCHAR(50) NOT NULL DEFAULT '',
  status VARCHAR(32) NOT NULL DEFAULT 'open',
  messages_count INT NOT NULL DEFAULT 0,
  context_json MEDIUMTEXT DEFAULT NULL,
  client_ip_hash CHAR(64) NOT NULL DEFAULT '',
  user_agent VARCHAR(255) NOT NULL DEFAULT '',
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_message_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at DATETIME DEFAULT NULL,
  PRIMARY KEY (id_conversation),
  UNIQUE KEY uniq_cv_assistant_session (session_key),
  KEY idx_cv_assistant_conversations_last (last_message_at),
  KEY idx_cv_assistant_conversations_ticket (ticket_code),
  KEY idx_cv_assistant_conversations_provider (provider_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,
            <<<SQL
CREATE TABLE IF NOT EXISTS cv_assistant_messages (
  id_message BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_conversation BIGINT UNSIGNED NOT NULL,
  role VARCHAR(20) NOT NULL DEFAULT 'assistant',
  message_text MEDIUMTEXT NOT NULL,
  intent VARCHAR(64) NOT NULL DEFAULT '',
  confidence DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  meta_json MEDIUMTEXT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_message),
  KEY idx_cv_assistant_messages_conversation (id_conversation, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,
            <<<SQL
CREATE TABLE IF NOT EXISTS cv_assistant_feedback (
  id_feedback BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_message BIGINT UNSIGNED NOT NULL,
  session_key VARCHAR(80) NOT NULL,
  feedback TINYINT(1) NOT NULL DEFAULT 0,
  meta_json MEDIUMTEXT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id_feedback),
  UNIQUE KEY uq_cv_assistant_feedback_message_session (id_message, session_key),
  KEY idx_cv_assistant_feedback_message (id_message),
  KEY idx_cv_assistant_feedback_session (session_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,
            <<<SQL
CREATE TABLE IF NOT EXISTS cv_assistant_support_tickets (
  id_ticket BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_key VARCHAR(80) NOT NULL DEFAULT '',
  id_conversation BIGINT UNSIGNED NOT NULL DEFAULT 0,
  channel VARCHAR(32) NOT NULL DEFAULT 'web',
  status VARCHAR(32) NOT NULL DEFAULT 'open',
  subject VARCHAR(190) NOT NULL DEFAULT '',
  customer_name VARCHAR(190) NOT NULL DEFAULT '',
  customer_email VARCHAR(190) NOT NULL DEFAULT '',
  customer_phone VARCHAR(50) NOT NULL DEFAULT '',
  provider_code VARCHAR(50) NOT NULL DEFAULT '',
  ticket_code VARCHAR(80) NOT NULL DEFAULT '',
  created_by VARCHAR(190) NOT NULL DEFAULT '',
  last_message_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id_ticket),
  KEY idx_cv_assistant_support_status (status, last_message_at),
  KEY idx_cv_assistant_support_session (session_key),
  KEY idx_cv_assistant_support_provider (provider_code),
  KEY idx_cv_assistant_support_ticket (ticket_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,
            <<<SQL
CREATE TABLE IF NOT EXISTS cv_assistant_support_messages (
  id_ticket_message BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_ticket BIGINT UNSIGNED NOT NULL,
  sender_role VARCHAR(20) NOT NULL DEFAULT 'user',
  sender_name VARCHAR(190) NOT NULL DEFAULT '',
  message_text MEDIUMTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_ticket_message),
  KEY idx_cv_assistant_support_messages_ticket (id_ticket, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,
        ];

        foreach ($queries as $sql) {
            if (!$connection->query($sql)) {
                return false;
            }
        }

        if (!cvAssistantEnsureSchemaCompatibility($connection)) {
            return false;
        }

        if (!cvAssistantEnsureSettingsRow($connection)) {
            return false;
        }

        cvAssistantEnsureSeedKnowledge($connection);
        return true;
    }
}

if (!function_exists('cvAssistantTableHasColumn')) {
    function cvAssistantTableHasColumn(mysqli $connection, string $tableName, string $columnName): bool
    {
        $tableName = trim($tableName);
        $columnName = trim($columnName);
        if ($tableName === '' || $columnName === '') {
            return false;
        }

        $safeTable = str_replace('`', '``', $tableName);
        $safeColumn = str_replace('`', '``', $columnName);
        $result = $connection->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
        if (!$result instanceof mysqli_result) {
            return false;
        }

        $exists = $result->num_rows > 0;
        $result->free();
        return $exists;
    }
}

if (!function_exists('cvAssistantEnsureSchemaCompatibility')) {
    function cvAssistantEnsureSchemaCompatibility(mysqli $connection): bool
    {
        $alterStatements = [
            [
                'table' => 'cv_assistant_settings',
                'column' => 'feedback_enabled',
                'sql' => "ALTER TABLE cv_assistant_settings ADD COLUMN feedback_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER learning_enabled",
            ],
            [
                'table' => 'cv_assistant_settings',
                'column' => 'ticketing_enabled',
                'sql' => "ALTER TABLE cv_assistant_settings ADD COLUMN ticketing_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER feedback_enabled",
            ],
            [
                'table' => 'cv_assistant_settings',
                'column' => 'recovery_email_enabled',
                'sql' => "ALTER TABLE cv_assistant_settings ADD COLUMN recovery_email_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER ticketing_enabled",
            ],
            [
                'table' => 'cv_assistant_settings',
                'column' => 'operator_handoff_after_unresolved',
                'sql' => "ALTER TABLE cv_assistant_settings ADD COLUMN operator_handoff_after_unresolved INT NOT NULL DEFAULT 4 AFTER recovery_email_enabled",
            ],
            [
                'table' => 'cv_assistant_settings',
                'column' => 'operator_handoff_label',
                'sql' => "ALTER TABLE cv_assistant_settings ADD COLUMN operator_handoff_label VARCHAR(120) NOT NULL DEFAULT 'Chatta con un operatore' AFTER operator_handoff_after_unresolved",
            ],
            [
                'table' => 'cv_assistant_settings',
                'column' => 'operator_busy_timeout_minutes',
                'sql' => "ALTER TABLE cv_assistant_settings ADD COLUMN operator_busy_timeout_minutes INT NOT NULL DEFAULT 6 AFTER operator_handoff_label",
            ],
            [
                'table' => 'cv_assistant_feedback',
                'column' => 'meta_json',
                'sql' => "ALTER TABLE cv_assistant_feedback ADD COLUMN meta_json MEDIUMTEXT DEFAULT NULL AFTER feedback",
            ],
        ];

        foreach ($alterStatements as $alter) {
            if (cvAssistantTableHasColumn($connection, (string) $alter['table'], (string) $alter['column'])) {
                continue;
            }
            if (!(bool) $connection->query((string) $alter['sql'])) {
                return false;
            }
        }

        return true;
    }
}

if (!function_exists('cvAssistantEnsureSettingsRow')) {
    function cvAssistantEnsureSettingsRow(mysqli $connection): bool
    {
        $result = $connection->query('SELECT id_sett FROM cv_assistant_settings ORDER BY id_sett ASC LIMIT 1');
        if ($result instanceof mysqli_result) {
            $exists = $result->num_rows > 0;
            $result->free();
            if ($exists) {
                return true;
            }
        }

        $defaults = cvAssistantDefaults();
        $quickReplies = is_string($defaults['quick_replies_json'] ?? null) ? (string) $defaults['quick_replies_json'] : '[]';
        $sql = 'INSERT INTO cv_assistant_settings
                (assistant_name, assistant_badge, welcome_message, fallback_message, escalation_message, quick_replies_json, widget_enabled, collect_logs, learning_enabled, feedback_enabled, ticketing_enabled, recovery_email_enabled, operator_handoff_after_unresolved, operator_handoff_label, operator_busy_timeout_minutes, updated_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        $statement = $connection->prepare($sql);
        if (!$statement instanceof mysqli_stmt) {
            return false;
        }

        $assistantName = (string) $defaults['assistant_name'];
        $assistantBadge = (string) $defaults['assistant_badge'];
        $welcomeMessage = (string) $defaults['welcome_message'];
        $fallbackMessage = (string) $defaults['fallback_message'];
        $escalationMessage = (string) $defaults['escalation_message'];
        $widgetEnabled = (int) $defaults['widget_enabled'];
        $collectLogs = (int) $defaults['collect_logs'];
        $learningEnabled = (int) $defaults['learning_enabled'];
        $feedbackEnabled = (int) $defaults['feedback_enabled'];
        $ticketingEnabled = (int) $defaults['ticketing_enabled'];
        $recoveryEmailEnabled = (int) $defaults['recovery_email_enabled'];
        $operatorHandoffAfterUnresolved = max(1, (int) ($defaults['operator_handoff_after_unresolved'] ?? 4));
        $operatorHandoffLabel = trim((string) ($defaults['operator_handoff_label'] ?? 'Chatta con un operatore'));
        if ($operatorHandoffLabel === '') {
            $operatorHandoffLabel = 'Chatta con un operatore';
        }
        $operatorBusyTimeoutMinutes = max(1, (int) ($defaults['operator_busy_timeout_minutes'] ?? 6));
        $updatedBy = (string) $defaults['updated_by'];

        $statement->bind_param(
            'ssssssiiiiiiisis',
            $assistantName,
            $assistantBadge,
            $welcomeMessage,
            $fallbackMessage,
            $escalationMessage,
            $quickReplies,
            $widgetEnabled,
            $collectLogs,
            $learningEnabled,
            $feedbackEnabled,
            $ticketingEnabled,
            $recoveryEmailEnabled,
            $operatorHandoffAfterUnresolved,
            $operatorHandoffLabel,
            $operatorBusyTimeoutMinutes,
            $updatedBy
        );
        $ok = $statement->execute();
        $statement->close();
        return $ok;
    }
}

if (!function_exists('cvAssistantEnsureSeedKnowledge')) {
    function cvAssistantEnsureSeedKnowledge(mysqli $connection): void
    {
        $result = $connection->query('SELECT COUNT(*) AS total FROM cv_assistant_knowledge');
        if (!$result instanceof mysqli_result) {
            return;
        }

        $row = $result->fetch_assoc();
        $result->free();
        if (!is_array($row) || (int) ($row['total'] ?? 0) > 0) {
            return;
        }

        $sql = 'INSERT INTO cv_assistant_knowledge
                (title, question_example, keywords, answer_text, provider_code, ticket_required, priority, active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)';
        $statement = $connection->prepare($sql);
        if (!$statement instanceof mysqli_stmt) {
            return;
        }

        foreach (cvAssistantDefaultKnowledgeSeed() as $entry) {
            $title = trim((string) ($entry['title'] ?? ''));
            $questionExample = trim((string) ($entry['question_example'] ?? ''));
            $keywords = trim((string) ($entry['keywords'] ?? ''));
            $answerText = trim((string) ($entry['answer_text'] ?? ''));
            $providerCode = strtolower(trim((string) ($entry['provider_code'] ?? '')));
            $ticketRequired = !empty($entry['ticket_required']) ? 1 : 0;
            $priority = isset($entry['priority']) ? (int) $entry['priority'] : 100;
            $active = !array_key_exists('active', $entry) || !empty($entry['active']) ? 1 : 0;
            if ($title === '' || $answerText === '') {
                continue;
            }
            $statement->bind_param('sssssiii', $title, $questionExample, $keywords, $answerText, $providerCode, $ticketRequired, $priority, $active);
            $statement->execute();
        }

        $statement->close();
    }
}

if (!function_exists('cvAssistantSettings')) {
    /**
     * @return array<string,mixed>
     */
    function cvAssistantSettings(mysqli $connection): array
    {
        $settings = cvAssistantDefaults();
        if (!cvAssistantEnsureTables($connection)) {
            return $settings;
        }

        $result = $connection->query('SELECT * FROM cv_assistant_settings ORDER BY id_sett ASC LIMIT 1');
        if (!$result instanceof mysqli_result) {
            return $settings;
        }

        $row = $result->fetch_assoc();
        $result->free();
        if (!is_array($row)) {
            return $settings;
        }

        foreach ($settings as $key => $defaultValue) {
            if (!array_key_exists($key, $row)) {
                continue;
            }
            if (is_int($defaultValue)) {
                $settings[$key] = (int) $row[$key];
            } else {
                $settings[$key] = trim((string) $row[$key]);
            }
        }

        return $settings;
    }
}

if (!function_exists('cvAssistantQuickReplies')) {
    /**
     * @return array<int,string>
     */
    function cvAssistantQuickReplies(array $settings): array
    {
        $raw = trim((string) ($settings['quick_replies_json'] ?? ''));
        if ($raw === '') {
            return cvAssistantDefaultQuickReplies();
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return cvAssistantDefaultQuickReplies();
        }

        $values = [];
        foreach ($decoded as $value) {
            $text = trim((string) $value);
            if ($text !== '') {
                $values[$text] = $text;
            }
        }

        return count($values) > 0 ? array_values($values) : cvAssistantDefaultQuickReplies();
    }
}

if (!function_exists('cvAssistantSaveSettings')) {
    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    function cvAssistantSaveSettings(mysqli $connection, array $payload): array
    {
        if (!cvAssistantEnsureTables($connection)) {
            throw new RuntimeException('Tabelle assistente non disponibili.');
        }

        $current = cvAssistantSettings($connection);
        $next = $current;
        foreach ($current as $key => $defaultValue) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }
            if (is_int($defaultValue)) {
                $next[$key] = max(0, (int) $payload[$key]);
            } else {
                $next[$key] = trim((string) $payload[$key]);
            }
        }

        if ($next['assistant_name'] === '') {
            throw new RuntimeException('Nome assistente obbligatorio.');
        }
        if ($next['welcome_message'] === '') {
            throw new RuntimeException('Messaggio di benvenuto obbligatorio.');
        }
        if ($next['fallback_message'] === '') {
            throw new RuntimeException('Messaggio fallback obbligatorio.');
        }

        $quickReplies = cvAssistantNormalizeQuickRepliesInput((string) ($payload['quick_replies'] ?? ''), cvAssistantDefaultQuickReplies());
        $next['quick_replies_json'] = json_encode($quickReplies, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $next['widget_enabled'] = !empty($next['widget_enabled']) ? 1 : 0;
        $next['collect_logs'] = !empty($next['collect_logs']) ? 1 : 0;
        $next['learning_enabled'] = !empty($next['learning_enabled']) ? 1 : 0;
        $next['feedback_enabled'] = !empty($next['feedback_enabled']) ? 1 : 0;
        $next['ticketing_enabled'] = !empty($next['ticketing_enabled']) ? 1 : 0;
        $next['recovery_email_enabled'] = !empty($next['recovery_email_enabled']) ? 1 : 0;
        $next['operator_handoff_after_unresolved'] = max(1, min(12, (int) ($next['operator_handoff_after_unresolved'] ?? 4)));
        $next['operator_handoff_label'] = trim((string) ($next['operator_handoff_label'] ?? 'Chatta con un operatore'));
        if ($next['operator_handoff_label'] === '') {
            $next['operator_handoff_label'] = 'Chatta con un operatore';
        }
        $next['operator_busy_timeout_minutes'] = max(1, min(120, (int) ($next['operator_busy_timeout_minutes'] ?? 6)));

        $result = $connection->query('SELECT id_sett FROM cv_assistant_settings ORDER BY id_sett ASC LIMIT 1');
        if (!$result instanceof mysqli_result) {
            throw new RuntimeException('Impossibile leggere configurazione assistente.');
        }
        $row = $result->fetch_assoc();
        $result->free();
        if (!is_array($row)) {
            throw new RuntimeException('Configurazione assistente non trovata.');
        }

        $idSett = (int) ($row['id_sett'] ?? 0);
        $sql = 'UPDATE cv_assistant_settings
                SET assistant_name = ?, assistant_badge = ?, welcome_message = ?, fallback_message = ?, escalation_message = ?, quick_replies_json = ?, widget_enabled = ?, collect_logs = ?, learning_enabled = ?, feedback_enabled = ?, ticketing_enabled = ?, recovery_email_enabled = ?, operator_handoff_after_unresolved = ?, operator_handoff_label = ?, operator_busy_timeout_minutes = ?, updated_by = ?
                WHERE id_sett = ? LIMIT 1';
        $statement = $connection->prepare($sql);
        if (!$statement instanceof mysqli_stmt) {
            throw new RuntimeException('Impossibile preparare salvataggio assistente.');
        }

        $statement->bind_param(
            'ssssssiiiiiiisisi',
            $next['assistant_name'],
            $next['assistant_badge'],
            $next['welcome_message'],
            $next['fallback_message'],
            $next['escalation_message'],
            $next['quick_replies_json'],
            $next['widget_enabled'],
            $next['collect_logs'],
            $next['learning_enabled'],
            $next['feedback_enabled'],
            $next['ticketing_enabled'],
            $next['recovery_email_enabled'],
            $next['operator_handoff_after_unresolved'],
            $next['operator_handoff_label'],
            $next['operator_busy_timeout_minutes'],
            $next['updated_by'],
            $idSett
        );
        $ok = $statement->execute();
        $statement->close();
        if (!$ok) {
            throw new RuntimeException('Salvataggio configurazione assistente fallito.');
        }

        return cvAssistantSettings($connection);
    }
}

if (!function_exists('cvAssistantNormalizeQuickRepliesInput')) {
    /**
     * @param array<int,string> $fallback
     * @return array<int,string>
     */
    function cvAssistantNormalizeQuickRepliesInput(string $raw, array $fallback): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return $fallback;
        }

        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $values = [];
        foreach ($lines as $line) {
            $value = trim((string) $line);
            if ($value !== '') {
                $values[$value] = $value;
            }
        }

        return count($values) > 0 ? array_values($values) : $fallback;
    }
}

if (!function_exists('cvAssistantKnowledgeList')) {
    /**
     * @return array<int,array<string,mixed>>
     */
    function cvAssistantKnowledgeList(mysqli $connection): array
    {
        if (!cvAssistantEnsureTables($connection)) {
            return [];
        }

        $items = [];
        $result = $connection->query('SELECT * FROM cv_assistant_knowledge ORDER BY active DESC, priority ASC, title ASC');
        if (!$result instanceof mysqli_result) {
            return $items;
        }

        while ($row = $result->fetch_assoc()) {
            if (!is_array($row)) {
                continue;
            }
            $row['id_knowledge'] = (int) ($row['id_knowledge'] ?? 0);
            $row['ticket_required'] = (int) ($row['ticket_required'] ?? 0);
            $row['priority'] = (int) ($row['priority'] ?? 100);
            $row['active'] = (int) ($row['active'] ?? 0);
            $row['hits'] = (int) ($row['hits'] ?? 0);
            $items[] = $row;
        }
        $result->free();

        return $items;
    }
}

if (!function_exists('cvAssistantKnowledgeSave')) {
    /**
     * @param array<string,mixed> $payload
     */
    function cvAssistantKnowledgeSave(mysqli $connection, array $payload): int
    {
        if (!cvAssistantEnsureTables($connection)) {
            throw new RuntimeException('Tabelle assistente non disponibili.');
        }

        $idKnowledge = isset($payload['id_knowledge']) ? (int) $payload['id_knowledge'] : 0;
        $title = trim((string) ($payload['title'] ?? ''));
        $questionExample = trim((string) ($payload['question_example'] ?? ''));
        $keywords = trim((string) ($payload['keywords'] ?? ''));
        $answerText = trim((string) ($payload['answer_text'] ?? ''));
        $providerCode = strtolower(trim((string) ($payload['provider_code'] ?? '')));
        $ticketRequired = !empty($payload['ticket_required']) ? 1 : 0;
        $priority = isset($payload['priority']) ? (int) $payload['priority'] : 100;
        $active = !array_key_exists('active', $payload) || !empty($payload['active']) ? 1 : 0;

        if ($title === '') {
            throw new RuntimeException('Titolo knowledge obbligatorio.');
        }
        if ($answerText === '') {
            throw new RuntimeException('Risposta knowledge obbligatoria.');
        }

        if ($idKnowledge > 0) {
            $sql = 'UPDATE cv_assistant_knowledge
                    SET title = ?, question_example = ?, keywords = ?, answer_text = ?, provider_code = ?, ticket_required = ?, priority = ?, active = ?
                    WHERE id_knowledge = ? LIMIT 1';
            $statement = $connection->prepare($sql);
            if (!$statement instanceof mysqli_stmt) {
                throw new RuntimeException('Impossibile aggiornare knowledge.');
            }
            $statement->bind_param('sssssiiii', $title, $questionExample, $keywords, $answerText, $providerCode, $ticketRequired, $priority, $active, $idKnowledge);
            $ok = $statement->execute();
            $statement->close();
            if (!$ok) {
                throw new RuntimeException('Aggiornamento knowledge fallito.');
            }
            return $idKnowledge;
        }

        $sql = 'INSERT INTO cv_assistant_knowledge
                (title, question_example, keywords, answer_text, provider_code, ticket_required, priority, active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)';
        $statement = $connection->prepare($sql);
        if (!$statement instanceof mysqli_stmt) {
            throw new RuntimeException('Impossibile creare knowledge.');
        }
        $statement->bind_param('sssssiii', $title, $questionExample, $keywords, $answerText, $providerCode, $ticketRequired, $priority, $active);
        $ok = $statement->execute();
        $newId = $ok ? (int) $statement->insert_id : 0;
        $statement->close();
        if (!$ok || $newId <= 0) {
            throw new RuntimeException('Creazione knowledge fallita.');
        }

        return $newId;
    }
}

if (!function_exists('cvAssistantKnowledgeDelete')) {
    function cvAssistantKnowledgeDelete(mysqli $connection, int $idKnowledge): bool
    {
        if ($idKnowledge <= 0 || !cvAssistantEnsureTables($connection)) {
            return false;
        }

        $statement = $connection->prepare('DELETE FROM cv_assistant_knowledge WHERE id_knowledge = ? LIMIT 1');
        if (!$statement instanceof mysqli_stmt) {
            return false;
        }
        $statement->bind_param('i', $idKnowledge);
        $ok = $statement->execute();
        $statement->close();
        return $ok;
    }
}

if (!function_exists('cvAssistantNormalizeSessionKey')) {
    function cvAssistantNormalizeSessionKey(string $raw): string
    {
        $raw = strtolower(trim($raw));
        if ($raw !== '' && preg_match('/^[a-z0-9_-]{16,80}$/', $raw) === 1) {
            return $raw;
        }
        return '';
    }
}

if (!function_exists('cvAssistantConversationLoad')) {
    /**
     * @return array<string,mixed>|null
     */
    function cvAssistantConversationLoad(mysqli $connection, string $sessionKey): ?array
    {
        $sessionKey = cvAssistantNormalizeSessionKey($sessionKey);
        if ($sessionKey === '' || !cvAssistantEnsureTables($connection)) {
            return null;
        }

        $statement = $connection->prepare('SELECT * FROM cv_assistant_conversations WHERE session_key = ? LIMIT 1');
        if (!$statement instanceof mysqli_stmt) {
            return null;
        }
        $statement->bind_param('s', $sessionKey);
        if (!$statement->execute()) {
            $statement->close();
            return null;
        }
        $result = $statement->get_result();
        $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
        if ($result instanceof mysqli_result) {
            $result->free();
        }
        $statement->close();
        return is_array($row) ? $row : null;
    }
}

if (!function_exists('cvAssistantConversationEnsure')) {
    /**
     * @param array<string,mixed> $meta
     * @return array<string,mixed>
     */
    function cvAssistantConversationEnsure(mysqli $connection, string $sessionKey, array $meta = []): array
    {
        $sessionKey = cvAssistantNormalizeSessionKey($sessionKey);
        if ($sessionKey === '') {
            throw new RuntimeException('Session key assistente non valida.');
        }
        if (!cvAssistantEnsureTables($connection)) {
            throw new RuntimeException('Tabelle assistente non disponibili.');
        }

        $existing = cvAssistantConversationLoad($connection, $sessionKey);
        if (is_array($existing)) {
            return $existing;
        }

        $channel = trim((string) ($meta['channel'] ?? 'web')) ?: 'web';
        $ticketCode = strtoupper(trim((string) ($meta['ticket_code'] ?? '')));
        $providerCode = strtolower(trim((string) ($meta['provider_code'] ?? '')));
        $status = trim((string) ($meta['status'] ?? 'open')) ?: 'open';
        $contextJson = isset($meta['context_json']) && is_string($meta['context_json']) ? $meta['context_json'] : '{}';
        $clientIpHash = trim((string) ($meta['client_ip_hash'] ?? ''));
        $userAgent = trim((string) ($meta['user_agent'] ?? ''));

        $sql = 'INSERT INTO cv_assistant_conversations
                (session_key, channel, ticket_code, provider_code, status, messages_count, context_json, client_ip_hash, user_agent, started_at, last_message_at)
                VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)';
        $statement = $connection->prepare($sql);
        if (!$statement instanceof mysqli_stmt) {
            throw new RuntimeException('Impossibile creare conversazione assistente.');
        }
        $statement->bind_param('ssssssss', $sessionKey, $channel, $ticketCode, $providerCode, $status, $contextJson, $clientIpHash, $userAgent);
        $ok = $statement->execute();
        $statement->close();
        if (!$ok) {
            throw new RuntimeException('Creazione conversazione assistente fallita.');
        }

        $created = cvAssistantConversationLoad($connection, $sessionKey);
        if (!is_array($created)) {
            throw new RuntimeException('Conversazione assistente non riletta.');
        }

        return $created;
    }
}

if (!function_exists('cvAssistantConversationContext')) {
    /**
     * @return array<string,mixed>
     */
    function cvAssistantConversationContext(array $conversation): array
    {
        $raw = trim((string) ($conversation['context_json'] ?? ''));
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}

if (!function_exists('cvAssistantConversationUpdate')) {
    /**
     * @param array<string,mixed> $conversation
     * @param array<string,mixed> $patch
     * @return array<string,mixed>
     */
    function cvAssistantConversationUpdate(mysqli $connection, array $conversation, array $patch): array
    {
        $idConversation = isset($conversation['id_conversation']) ? (int) $conversation['id_conversation'] : 0;
        if ($idConversation <= 0) {
            return $conversation;
        }

        $nextTicketCode = array_key_exists('ticket_code', $patch)
            ? strtoupper(trim((string) $patch['ticket_code']))
            : strtoupper(trim((string) ($conversation['ticket_code'] ?? '')));
        $nextProviderCode = array_key_exists('provider_code', $patch)
            ? strtolower(trim((string) $patch['provider_code']))
            : strtolower(trim((string) ($conversation['provider_code'] ?? '')));
        $nextStatus = array_key_exists('status', $patch)
            ? trim((string) $patch['status'])
            : trim((string) ($conversation['status'] ?? 'open'));
        if ($nextStatus === '') {
            $nextStatus = 'open';
        }

        $context = cvAssistantConversationContext($conversation);
        $patchContext = isset($patch['context']) && is_array($patch['context']) ? $patch['context'] : [];
        foreach ($patchContext as $key => $value) {
            $context[(string) $key] = $value;
        }
        $contextJson = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($contextJson) || $contextJson === '') {
            $contextJson = '{}';
        }

        $statement = $connection->prepare('UPDATE cv_assistant_conversations
            SET ticket_code = ?, provider_code = ?, status = ?, context_json = ?, last_message_at = CURRENT_TIMESTAMP
            WHERE id_conversation = ? LIMIT 1');
        if (!$statement instanceof mysqli_stmt) {
            return $conversation;
        }
        $statement->bind_param('ssssi', $nextTicketCode, $nextProviderCode, $nextStatus, $contextJson, $idConversation);
        $statement->execute();
        $statement->close();

        $updated = $conversation;
        $updated['ticket_code'] = $nextTicketCode;
        $updated['provider_code'] = $nextProviderCode;
        $updated['status'] = $nextStatus;
        $updated['context_json'] = $contextJson;
        return $updated;
    }
}

if (!function_exists('cvAssistantLogMessage')) {
    function cvAssistantLogMessage(
        mysqli $connection,
        int $idConversation,
        string $role,
        string $messageText,
        string $intent = '',
        float $confidence = 0.0,
        array $meta = []
    ): bool {
        if ($idConversation <= 0 || trim($messageText) === '') {
            return false;
        }

        $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($metaJson)) {
            $metaJson = '{}';
        }

        $statement = $connection->prepare('INSERT INTO cv_assistant_messages
            (id_conversation, role, message_text, intent, confidence, meta_json, created_at)
            VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)');
        if (!$statement instanceof mysqli_stmt) {
            return false;
        }
        $statement->bind_param('isssds', $idConversation, $role, $messageText, $intent, $confidence, $metaJson);
        $ok = $statement->execute();
        $statement->close();
        if (!$ok) {
            return false;
        }

        $connection->query('UPDATE cv_assistant_conversations SET messages_count = messages_count + 1, last_message_at = CURRENT_TIMESTAMP WHERE id_conversation = ' . (int) $idConversation . ' LIMIT 1');
        return true;
    }
}

if (!function_exists('cvAssistantConversationMessages')) {
    /**
     * @return array<int,array<string,mixed>>
     */
    function cvAssistantConversationMessages(mysqli $connection, int $idConversation, int $limit = 30): array
    {
        $messages = [];
        if ($idConversation <= 0 || $limit <= 0) {
            return $messages;
        }

        $limit = max(1, min(100, $limit));
        $sql = 'SELECT * FROM (
                    SELECT id_message, id_conversation, role, message_text, intent, confidence, meta_json, created_at
                    FROM cv_assistant_messages
                    WHERE id_conversation = ?
                    ORDER BY id_message DESC
                    LIMIT ' . (int) $limit . '
                ) AS recent
                ORDER BY id_message ASC';
        $statement = $connection->prepare($sql);
        if (!$statement instanceof mysqli_stmt) {
            return $messages;
        }
        $statement->bind_param('i', $idConversation);
        if (!$statement->execute()) {
            $statement->close();
            return $messages;
        }
        $result = $statement->get_result();
        while ($result instanceof mysqli_result && ($row = $result->fetch_assoc())) {
            if (!is_array($row)) {
                continue;
            }
            $row['id_message'] = (int) ($row['id_message'] ?? 0);
            $row['id_conversation'] = (int) ($row['id_conversation'] ?? 0);
            $row['confidence'] = isset($row['confidence']) ? (float) $row['confidence'] : 0.0;
            $messages[] = $row;
        }
        if ($result instanceof mysqli_result) {
            $result->free();
        }
        $statement->close();
        return $messages;
    }
}

if (!function_exists('cvAssistantStats')) {
    /**
     * @return array<string,int>
     */
    function cvAssistantStats(mysqli $connection): array
    {
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
        if (!cvAssistantEnsureTables($connection)) {
            return $stats;
        }

        $queries = [
            'knowledge_total' => 'SELECT COUNT(*) AS total FROM cv_assistant_knowledge',
            'knowledge_active' => 'SELECT COUNT(*) AS total FROM cv_assistant_knowledge WHERE active = 1',
            'conversations_total' => 'SELECT COUNT(*) AS total FROM cv_assistant_conversations',
            'messages_total' => 'SELECT COUNT(*) AS total FROM cv_assistant_messages',
            'conversations_today' => 'SELECT COUNT(*) AS total FROM cv_assistant_conversations WHERE DATE(started_at) = CURDATE()',
            'feedback_positive' => 'SELECT COUNT(*) AS total FROM cv_assistant_feedback WHERE feedback = 1',
            'feedback_negative' => 'SELECT COUNT(*) AS total FROM cv_assistant_feedback WHERE feedback = -1',
            'support_tickets_open' => 'SELECT COUNT(*) AS total FROM cv_assistant_support_tickets WHERE status IN ("open", "pending")',
        ];
        foreach ($queries as $key => $sql) {
            $result = $connection->query($sql);
            if ($result instanceof mysqli_result) {
                $row = $result->fetch_assoc();
                $result->free();
                $stats[$key] = isset($row['total']) ? (int) $row['total'] : 0;
            }
        }

        return $stats;
    }
}

if (!function_exists('cvAssistantRecentConversations')) {
    /**
     * @return array<int,array<string,mixed>>
     */
    function cvAssistantRecentConversations(mysqli $connection, int $limit = 25): array
    {
        $items = [];
        if (!cvAssistantEnsureTables($connection)) {
            return $items;
        }

        $limit = max(1, min(100, $limit));
        $sql = 'SELECT c.*,
                       (
                         SELECT m.message_text
                         FROM cv_assistant_messages AS m
                         WHERE m.id_conversation = c.id_conversation
                         ORDER BY m.id_message DESC
                         LIMIT 1
                       ) AS last_message_text,
                       (
                         SELECT m.message_text
                         FROM cv_assistant_messages AS m
                         WHERE m.id_conversation = c.id_conversation
                           AND m.role = "user"
                         ORDER BY m.id_message DESC
                         LIMIT 1
                       ) AS last_user_message_text,
                       (
                         SELECT m.message_text
                         FROM cv_assistant_messages AS m
                         WHERE m.id_conversation = c.id_conversation
                           AND m.role = "assistant"
                         ORDER BY m.id_message DESC
                         LIMIT 1
                       ) AS last_assistant_message_text
                FROM cv_assistant_conversations AS c
                ORDER BY c.last_message_at DESC
                LIMIT ' . (int) $limit;
        $result = $connection->query($sql);
        if (!$result instanceof mysqli_result) {
            return $items;
        }

        while ($row = $result->fetch_assoc()) {
            if (!is_array($row)) {
                continue;
            }
            $row['id_conversation'] = (int) ($row['id_conversation'] ?? 0);
            $row['messages_count'] = (int) ($row['messages_count'] ?? 0);
            $items[] = $row;
        }
        $result->free();
        return $items;
    }
}

if (!function_exists('cvAssistantFeedbackNormalize')) {
    function cvAssistantFeedbackNormalize(int $feedback): int
    {
        if ($feedback > 0) {
            return 1;
        }
        if ($feedback < 0) {
            return -1;
        }
        return 0;
    }
}

if (!function_exists('cvAssistantFeedbackUpsert')) {
    function cvAssistantFeedbackUpsert(mysqli $connection, int $idMessage, string $sessionKey, int $feedback, array $meta = []): bool
    {
        if ($idMessage <= 0 || !cvAssistantEnsureTables($connection)) {
            return false;
        }

        $sessionKey = cvAssistantNormalizeSessionKey($sessionKey);
        $feedback = cvAssistantFeedbackNormalize($feedback);
        if ($sessionKey === '' || $feedback === 0) {
            return false;
        }

        $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($metaJson)) {
            $metaJson = '{}';
        }

        $sql = 'INSERT INTO cv_assistant_feedback (id_message, session_key, feedback, meta_json, created_at, updated_at)
                VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                ON DUPLICATE KEY UPDATE feedback = VALUES(feedback), meta_json = VALUES(meta_json), updated_at = CURRENT_TIMESTAMP';
        $statement = $connection->prepare($sql);
        if (!$statement instanceof mysqli_stmt) {
            return false;
        }
        $statement->bind_param('isis', $idMessage, $sessionKey, $feedback, $metaJson);
        $ok = $statement->execute();
        $statement->close();
        return $ok;
    }
}

if (!function_exists('cvAssistantFeedbackMapForConversation')) {
    /**
     * @return array<int,int>
     */
    function cvAssistantFeedbackMapForConversation(mysqli $connection, int $idConversation, string $sessionKey): array
    {
        $sessionKey = cvAssistantNormalizeSessionKey($sessionKey);
        if ($idConversation <= 0 || $sessionKey === '' || !cvAssistantEnsureTables($connection)) {
            return [];
        }

        $map = [];
        $sql = 'SELECT f.id_message, f.feedback
                FROM cv_assistant_feedback AS f
                INNER JOIN cv_assistant_messages AS m ON m.id_message = f.id_message
                WHERE m.id_conversation = ? AND f.session_key = ?';
        $statement = $connection->prepare($sql);
        if (!$statement instanceof mysqli_stmt) {
            return $map;
        }
        $statement->bind_param('is', $idConversation, $sessionKey);
        if (!$statement->execute()) {
            $statement->close();
            return $map;
        }
        $result = $statement->get_result();
        while ($result instanceof mysqli_result && ($row = $result->fetch_assoc())) {
            if (!is_array($row)) {
                continue;
            }
            $idMessage = (int) ($row['id_message'] ?? 0);
            if ($idMessage <= 0) {
                continue;
            }
            $map[$idMessage] = cvAssistantFeedbackNormalize((int) ($row['feedback'] ?? 0));
        }
        if ($result instanceof mysqli_result) {
            $result->free();
        }
        $statement->close();
        return $map;
    }
}

if (!function_exists('cvAssistantDeleteConversationsBefore')) {
    /**
     * @return array<string,int>
     */
    function cvAssistantDeleteConversationsBefore(mysqli $connection, string $beforeDate): array
    {
        $stats = [
            'messages_deleted' => 0,
            'feedback_deleted' => 0,
            'support_messages_deleted' => 0,
            'support_tickets_deleted' => 0,
            'conversations_deleted' => 0,
        ];

        $beforeDate = trim($beforeDate);
        if ($beforeDate === '' || !cvAssistantEnsureTables($connection)) {
            return $stats;
        }

        $date = DateTimeImmutable::createFromFormat('Y-m-d', $beforeDate);
        if (!$date instanceof DateTimeImmutable) {
            return $stats;
        }

        $limitDate = $date->format('Y-m-d 00:00:00');

        $feedbackSql = 'DELETE f
                        FROM cv_assistant_feedback AS f
                        INNER JOIN cv_assistant_messages AS m ON m.id_message = f.id_message
                        INNER JOIN cv_assistant_conversations AS c ON c.id_conversation = m.id_conversation
                        WHERE c.last_message_at < ?';
        $feedbackStmt = $connection->prepare($feedbackSql);
        if ($feedbackStmt instanceof mysqli_stmt) {
            $feedbackStmt->bind_param('s', $limitDate);
            if ($feedbackStmt->execute()) {
                $stats['feedback_deleted'] = (int) $feedbackStmt->affected_rows;
            }
            $feedbackStmt->close();
        }

        $supportMessagesSql = 'DELETE sm
                               FROM cv_assistant_support_messages AS sm
                               INNER JOIN cv_assistant_support_tickets AS st ON st.id_ticket = sm.id_ticket
                               INNER JOIN cv_assistant_conversations AS c ON c.id_conversation = st.id_conversation
                               WHERE c.last_message_at < ?';
        $supportMessagesStmt = $connection->prepare($supportMessagesSql);
        if ($supportMessagesStmt instanceof mysqli_stmt) {
            $supportMessagesStmt->bind_param('s', $limitDate);
            if ($supportMessagesStmt->execute()) {
                $stats['support_messages_deleted'] = (int) $supportMessagesStmt->affected_rows;
            }
            $supportMessagesStmt->close();
        }

        $supportTicketsSql = 'DELETE st
                              FROM cv_assistant_support_tickets AS st
                              INNER JOIN cv_assistant_conversations AS c ON c.id_conversation = st.id_conversation
                              WHERE c.last_message_at < ?';
        $supportTicketsStmt = $connection->prepare($supportTicketsSql);
        if ($supportTicketsStmt instanceof mysqli_stmt) {
            $supportTicketsStmt->bind_param('s', $limitDate);
            if ($supportTicketsStmt->execute()) {
                $stats['support_tickets_deleted'] = (int) $supportTicketsStmt->affected_rows;
            }
            $supportTicketsStmt->close();
        }

        $messagesSql = 'DELETE m
                        FROM cv_assistant_messages AS m
                        INNER JOIN cv_assistant_conversations AS c ON c.id_conversation = m.id_conversation
                        WHERE c.last_message_at < ?';
        $messagesStmt = $connection->prepare($messagesSql);
        if ($messagesStmt instanceof mysqli_stmt) {
            $messagesStmt->bind_param('s', $limitDate);
            if ($messagesStmt->execute()) {
                $stats['messages_deleted'] = (int) $messagesStmt->affected_rows;
            }
            $messagesStmt->close();
        }

        $conversationsSql = 'DELETE FROM cv_assistant_conversations WHERE last_message_at < ?';
        $conversationsStmt = $connection->prepare($conversationsSql);
        if ($conversationsStmt instanceof mysqli_stmt) {
            $conversationsStmt->bind_param('s', $limitDate);
            if ($conversationsStmt->execute()) {
                $stats['conversations_deleted'] = (int) $conversationsStmt->affected_rows;
            }
            $conversationsStmt->close();
        }

        return $stats;
    }
}

if (!function_exists('cvAssistantSupportTicketLoad')) {
    /**
     * @return array<string,mixed>|null
     */
    function cvAssistantSupportTicketLoad(mysqli $connection, int $idTicket): ?array
    {
        if ($idTicket <= 0 || !cvAssistantEnsureTables($connection)) {
            return null;
        }

        $statement = $connection->prepare('SELECT * FROM cv_assistant_support_tickets WHERE id_ticket = ? LIMIT 1');
        if (!$statement instanceof mysqli_stmt) {
            return null;
        }
        $statement->bind_param('i', $idTicket);
        if (!$statement->execute()) {
            $statement->close();
            return null;
        }
        $result = $statement->get_result();
        $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
        if ($result instanceof mysqli_result) {
            $result->free();
        }
        $statement->close();
        return is_array($row) ? $row : null;
    }
}

if (!function_exists('cvAssistantSupportTicketCreate')) {
    function cvAssistantSupportTicketCreate(mysqli $connection, array $payload): int
    {
        if (!cvAssistantEnsureTables($connection)) {
            throw new RuntimeException('Tabelle assistente non disponibili.');
        }

        $sessionKey = cvAssistantNormalizeSessionKey((string) ($payload['session_key'] ?? ''));
        $idConversation = (int) ($payload['id_conversation'] ?? 0);
        $channel = trim((string) ($payload['channel'] ?? 'web')) ?: 'web';
        $status = trim((string) ($payload['status'] ?? 'open')) ?: 'open';
        $subject = trim((string) ($payload['subject'] ?? 'Richiesta assistenza chat'));
        $customerName = trim((string) ($payload['customer_name'] ?? ''));
        $customerEmail = strtolower(trim((string) ($payload['customer_email'] ?? '')));
        $customerPhone = trim((string) ($payload['customer_phone'] ?? ''));
        $providerCode = strtolower(trim((string) ($payload['provider_code'] ?? '')));
        $ticketCode = strtoupper(trim((string) ($payload['ticket_code'] ?? '')));
        $createdBy = trim((string) ($payload['created_by'] ?? 'chat'));
        $firstMessage = trim((string) ($payload['message_text'] ?? ''));

        if ($customerName === '' || $firstMessage === '') {
            throw new RuntimeException('Dati ticket assistenza incompleti.');
        }

        $sql = 'INSERT INTO cv_assistant_support_tickets
                (session_key, id_conversation, channel, status, subject, customer_name, customer_email, customer_phone, provider_code, ticket_code, created_by, last_message_at, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)';
        $statement = $connection->prepare($sql);
        if (!$statement instanceof mysqli_stmt) {
            throw new RuntimeException('Impossibile creare ticket assistenza.');
        }
        $statement->bind_param(
            'sisssssssss',
            $sessionKey,
            $idConversation,
            $channel,
            $status,
            $subject,
            $customerName,
            $customerEmail,
            $customerPhone,
            $providerCode,
            $ticketCode,
            $createdBy
        );
        $ok = $statement->execute();
        $idTicket = $ok ? (int) $statement->insert_id : 0;
        $statement->close();
        if (!$ok || $idTicket <= 0) {
            throw new RuntimeException('Creazione ticket assistenza fallita.');
        }

        cvAssistantSupportTicketAddMessage($connection, $idTicket, 'user', $firstMessage, $customerName);
        return $idTicket;
    }
}

if (!function_exists('cvAssistantSupportTicketAddMessage')) {
    function cvAssistantSupportTicketAddMessage(mysqli $connection, int $idTicket, string $senderRole, string $messageText, string $senderName = ''): bool
    {
        if ($idTicket <= 0 || trim($messageText) === '' || !cvAssistantEnsureTables($connection)) {
            return false;
        }

        $senderRole = trim($senderRole) !== '' ? trim($senderRole) : 'user';
        $senderName = trim($senderName);

        $statement = $connection->prepare('INSERT INTO cv_assistant_support_messages
            (id_ticket, sender_role, sender_name, message_text, created_at)
            VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)');
        if (!$statement instanceof mysqli_stmt) {
            return false;
        }
        $statement->bind_param('isss', $idTicket, $senderRole, $senderName, $messageText);
        $ok = $statement->execute();
        $statement->close();
        if (!$ok) {
            return false;
        }

        $connection->query('UPDATE cv_assistant_support_tickets SET last_message_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id_ticket = ' . (int) $idTicket . ' LIMIT 1');
        return true;
    }
}

if (!function_exists('cvAssistantSupportTicketUpdate')) {
    function cvAssistantSupportTicketUpdate(mysqli $connection, int $idTicket, array $patch): bool
    {
        if ($idTicket <= 0 || !cvAssistantEnsureTables($connection)) {
            return false;
        }

        $current = cvAssistantSupportTicketLoad($connection, $idTicket);
        if (!is_array($current)) {
            return false;
        }

        $status = trim((string) ($patch['status'] ?? ($current['status'] ?? 'open')));
        if ($status === '') {
            $status = 'open';
        }
        $subject = trim((string) ($patch['subject'] ?? ($current['subject'] ?? '')));
        $sql = 'UPDATE cv_assistant_support_tickets SET status = ?, subject = ?, updated_at = CURRENT_TIMESTAMP WHERE id_ticket = ? LIMIT 1';
        $statement = $connection->prepare($sql);
        if (!$statement instanceof mysqli_stmt) {
            return false;
        }
        $statement->bind_param('ssi', $status, $subject, $idTicket);
        $ok = $statement->execute();
        $statement->close();
        return $ok;
    }
}

if (!function_exists('cvAssistantSupportTicketMessages')) {
    /**
     * @return array<int,array<string,mixed>>
     */
    function cvAssistantSupportTicketMessages(mysqli $connection, int $idTicket, int $limit = 50): array
    {
        $items = [];
        if ($idTicket <= 0 || $limit <= 0 || !cvAssistantEnsureTables($connection)) {
            return $items;
        }

        $limit = max(1, min(200, $limit));
        $sql = 'SELECT * FROM (
                    SELECT id_ticket_message, id_ticket, sender_role, sender_name, message_text, created_at
                    FROM cv_assistant_support_messages
                    WHERE id_ticket = ?
                    ORDER BY id_ticket_message DESC
                    LIMIT ' . (int) $limit . '
                ) AS recent
                ORDER BY id_ticket_message ASC';
        $statement = $connection->prepare($sql);
        if (!$statement instanceof mysqli_stmt) {
            return $items;
        }
        $statement->bind_param('i', $idTicket);
        if (!$statement->execute()) {
            $statement->close();
            return $items;
        }
        $result = $statement->get_result();
        while ($result instanceof mysqli_result && ($row = $result->fetch_assoc())) {
            if (is_array($row)) {
                $items[] = $row;
            }
        }
        if ($result instanceof mysqli_result) {
            $result->free();
        }
        $statement->close();
        return $items;
    }
}

if (!function_exists('cvAssistantSupportTicketList')) {
    /**
     * @return array<int,array<string,mixed>>
     */
    function cvAssistantSupportTicketList(mysqli $connection, string $status = 'all', int $limit = 25): array
    {
        $items = [];
        if (!cvAssistantEnsureTables($connection)) {
            return $items;
        }

        $limit = max(1, min(100, $limit));
        $status = strtolower(trim($status));
        $where = '';
        if ($status !== '' && $status !== 'all') {
            $safeStatus = $connection->real_escape_string($status);
            $where = "WHERE t.status = '{$safeStatus}'";
        }

        $sql = 'SELECT t.*,
                       (
                         SELECT sm.message_text
                         FROM cv_assistant_support_messages AS sm
                         WHERE sm.id_ticket = t.id_ticket
                         ORDER BY sm.id_ticket_message DESC
                         LIMIT 1
                       ) AS last_ticket_message
                FROM cv_assistant_support_tickets AS t
                ' . $where . '
                ORDER BY t.last_message_at DESC
                LIMIT ' . (int) $limit;
        $result = $connection->query($sql);
        if (!$result instanceof mysqli_result) {
            return $items;
        }
        while ($row = $result->fetch_assoc()) {
            if (is_array($row)) {
                $row['id_ticket'] = (int) ($row['id_ticket'] ?? 0);
                $items[] = $row;
            }
        }
        $result->free();
        return $items;
    }
}

if (!function_exists('cvAssistantSupportTicketLatestActiveBySession')) {
    /**
     * @return array<string,mixed>|null
     */
    function cvAssistantSupportTicketLatestActiveBySession(mysqli $connection, string $sessionKey): ?array
    {
        $sessionKey = cvAssistantNormalizeSessionKey($sessionKey);
        if ($sessionKey === '' || !cvAssistantEnsureTables($connection)) {
            return null;
        }

        $sql = 'SELECT t.*,
                       (
                         SELECT sm.message_text
                         FROM cv_assistant_support_messages AS sm
                         WHERE sm.id_ticket = t.id_ticket
                         ORDER BY sm.id_ticket_message DESC
                         LIMIT 1
                       ) AS last_ticket_message
                FROM cv_assistant_support_tickets AS t
                WHERE t.session_key = ? AND t.status IN ("open", "pending")
                ORDER BY t.last_message_at DESC, t.id_ticket DESC
                LIMIT 1';
        $statement = $connection->prepare($sql);
        if (!$statement instanceof mysqli_stmt) {
            return null;
        }
        $statement->bind_param('s', $sessionKey);
        if (!$statement->execute()) {
            $statement->close();
            return null;
        }
        $result = $statement->get_result();
        $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
        if ($result instanceof mysqli_result) {
            $result->free();
        }
        $statement->close();
        if (!is_array($row)) {
            return null;
        }
        $row['id_ticket'] = (int) ($row['id_ticket'] ?? 0);
        return $row;
    }
}

if (!function_exists('cvAssistantKnowledgeScore')) {
    function cvAssistantKnowledgeScore(string $message, array $entry): int
    {
        $haystack = cvAssistantNormalizeText($message);
        if ($haystack === '') {
            return 0;
        }

        $score = 0;
        $title = cvAssistantNormalizeText((string) ($entry['title'] ?? ''));
        $question = cvAssistantNormalizeText((string) ($entry['question_example'] ?? ''));
        foreach ([$title, $question] as $text) {
            if ($text !== '' && strpos($haystack, $text) !== false) {
                $score += 4;
            }
        }

        $keywords = preg_split('/[\r\n,;|]+/', (string) ($entry['keywords'] ?? '')) ?: [];
        foreach ($keywords as $keyword) {
            $normalizedKeyword = cvAssistantNormalizeText((string) $keyword);
            if ($normalizedKeyword !== '' && strpos($haystack, $normalizedKeyword) !== false) {
                $score += 3;
            }
        }

        return $score;
    }
}

if (!function_exists('cvAssistantMatchKnowledge')) {
    /**
     * @param array<int,array<string,mixed>> $knowledgeItems
     * @return array<string,mixed>|null
     */
    function cvAssistantMatchKnowledge(array $knowledgeItems, string $message, string $providerCode = '', bool $hasTicket = false): ?array
    {
        $providerCode = strtolower(trim($providerCode));
        $best = null;
        $bestScore = 0;

        foreach ($knowledgeItems as $item) {
            if (!is_array($item) || (int) ($item['active'] ?? 0) !== 1) {
                continue;
            }

            $itemProvider = strtolower(trim((string) ($item['provider_code'] ?? '')));
            if ($itemProvider !== '' && $itemProvider !== $providerCode) {
                continue;
            }

            if ((int) ($item['ticket_required'] ?? 0) === 1 && !$hasTicket) {
                continue;
            }

            $score = cvAssistantKnowledgeScore($message, $item);
            if ($score <= 0) {
                continue;
            }
            if ($best === null || $score > $bestScore || ($score === $bestScore && (int) ($item['priority'] ?? 100) < (int) ($best['priority'] ?? 100))) {
                $best = $item;
                $bestScore = $score;
            }
        }

        return $best;
    }
}

if (!function_exists('cvAssistantIncrementKnowledgeHit')) {
    function cvAssistantIncrementKnowledgeHit(mysqli $connection, int $idKnowledge): void
    {
        if ($idKnowledge <= 0) {
            return;
        }
        $connection->query('UPDATE cv_assistant_knowledge SET hits = hits + 1 WHERE id_knowledge = ' . (int) $idKnowledge . ' LIMIT 1');
    }
}

if (!function_exists('cvAssistantNormalizeText')) {
    function cvAssistantNormalizeText(string $value): string
    {
        if (function_exists('mb_strtolower')) {
            $value = trim((string) mb_strtolower($value, 'UTF-8'));
        } else {
            $value = trim(strtolower($value));
        }
        if ($value === '') {
            return '';
        }

        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($converted) && $converted !== '') {
            $value = $converted;
        }

        $value = preg_replace('/[^a-z0-9\s]+/', ' ', $value);
        $value = preg_replace('/\s+/', ' ', (string) $value);
        return trim((string) $value);
    }
}
