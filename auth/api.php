<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/conn.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../includes/error_log_tools.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/runtime_settings.php';
require_once __DIR__ . '/../includes/provider_quote.php';
require_once __DIR__ . '/../includes/pathfind.php';
require_once __DIR__ . '/../includes/assistant_tools.php';

if (function_exists('date_default_timezone_set')) {
    date_default_timezone_set('Europe/Rome');
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

cvAuthStartSession();

try {
    $connection = cvDbConnection();
} catch (Throwable $exception) {
    cvAuthResponse(false, 'Connessione database non disponibile.', [], 'DB_CONNECTION_ERROR', 500);
}

$action = cvAuthAction();

switch ($action) {
    case 'me':
        $user = cvAuthCurrentUser();
        if ($user === null) {
            cvAuthResponse(true, 'Sessione ospite.', ['user' => null]);
        }
        cvAuthResponse(true, 'Sessione attiva.', ['user' => $user]);
        break;

    case 'provinces':
        cvAuthHandleProvinceList($connection);
        break;

    case 'profile_get':
        cvAuthHandleProfileGet($connection);
        break;

    case 'tickets':
        cvAuthHandleTickets($connection);
        break;

    case 'ticket_lookup_public':
        cvAuthHandlePublicTicketLookup($connection);
        break;

    case 'ticket_chat_support':
        cvAuthHandleTicketChatSupport($connection);
        break;

    case 'ticket_chat_feedback':
        cvAuthHandleTicketChatFeedback($connection);
        break;

    case 'ticket_pdf_download':
        cvAuthHandleTicketPdfDownload($connection);
        break;

    case 'ticket_recovery_confirm':
        cvAuthHandleTicketRecoveryConfirm($connection);
        break;

    case 'ticket_change_precheck':
        cvAuthHandleTicketChangePrecheck($connection);
        break;

    case 'profile_update':
        cvAuthHandleProfileUpdate($connection);
        break;

    case 'newsletter_get':
        cvAuthHandleNewsletterGet($connection);
        break;

    case 'newsletter_set':
        cvAuthHandleNewsletterSet($connection);
        break;

    case 'newsletter_guest_subscribe':
        cvAuthHandleNewsletterGuestSubscribe($connection);
        break;

    case 'newsletter_confirm':
        cvAuthHandleNewsletterConfirm($connection);
        break;

    case 'logout':
        cvAuthLogout();
        cvAuthResponse(true, 'Logout effettuato.', ['user' => null]);
        break;

    case 'login':
        cvAuthHandleLogin($connection);
        break;

    case 'register':
        cvAuthHandleRegister($connection);
        break;

    case 'verify_email':
        cvAuthHandleVerifyEmail($connection);
        break;

    case 'forgot_password':
        cvAuthHandleForgotPassword($connection);
        break;

    case 'reset_password':
        cvAuthHandleResetPassword($connection);
        break;

    case 'google':
        cvAuthHandleGoogle($connection);
        break;

    case 'partner_lead':
        cvAuthHandlePartnerLead($connection);
        break;

    default:
        cvAuthResponse(false, 'Azione non valida.', [], 'INVALID_ACTION', 400);
}

function cvAuthAction(): string
{
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    return strtolower(trim((string) $action));
}

function cvAuthResponse(bool $success, string $message, array $data = [], string $code = 'OK', int $status = 200): void
{
    if (
        !$success
        && isset($GLOBALS['connection'])
        && $GLOBALS['connection'] instanceof mysqli
        && function_exists('cvErrorLogWrite')
    ) {
        $action = cvAuthAction();
        $eventCode = strtoupper(trim($code)) !== '' ? strtoupper(trim($code)) : 'AUTH_ERROR';
        $shouldIgnoreNoise = ($eventCode === 'UNAUTHORIZED' && $action === 'me' && $status === 401);
        if (!$shouldIgnoreNoise) {
            cvErrorLogWrite($GLOBALS['connection'], [
                'source' => 'auth_api',
                'event_code' => $eventCode,
                'severity' => 'error',
                'message' => trim($message) !== '' ? trim($message) : 'Errore auth.',
                'provider_code' => '',
                'request_id' => '',
                'action_name' => $action,
                'order_code' => '',
                'shop_id' => '',
                'context' => [
                    'status' => $status,
                    'data' => $data,
                ],
            ]);
        }
    }

    http_response_code($status);
    echo json_encode(
        [
            'success' => $success,
            'code' => $code,
            'message' => $message,
            'data' => $data,
        ],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function cvAuthStartSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $isHttps = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
    if (!$isHttps) {
        $forwardedProto = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
        if ($forwardedProto === 'https') {
            $isHttps = true;
        }
    }
    $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '/');
    $appPath = str_replace('\\', '/', dirname($scriptName, 2));
    $appPath = trim($appPath, '/');
    $cookiePath = trim((string) CV_AUTH_SESSION_PATH) !== ''
        ? (string) CV_AUTH_SESSION_PATH
        : ($appPath === '' ? '/' : '/' . $appPath . '/');
    if ($cookiePath[0] !== '/') {
        $cookiePath = '/' . $cookiePath;
    }
    if (substr($cookiePath, -1) !== '/') {
        $cookiePath .= '/';
    }
    $cookieDomain = trim((string) CV_AUTH_SESSION_DOMAIN);

    // Harden session handling and keep server-side lifetime aligned with cookie TTL.
    @ini_set('session.use_only_cookies', '1');
    @ini_set('session.use_strict_mode', '1');
    @ini_set('session.gc_maxlifetime', (string) max(900, (int) CV_AUTH_SESSION_TTL));

    // Cleanup old cookie path from previous auth versions.
    $legacyPath = rtrim($cookiePath, '/') . '/auth/';
    if ($legacyPath !== $cookiePath) {
        setcookie(CV_AUTH_SESSION_NAME, '', time() - 42000, $legacyPath);
    }

    session_name(CV_AUTH_SESSION_NAME);
    session_set_cookie_params(
        [
            'lifetime' => (int) CV_AUTH_SESSION_TTL,
            'path' => $cookiePath,
            'domain' => $cookieDomain,
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]
    );
    session_start();

    // Enforce idle timeout server-side and refresh activity timestamp for active sessions.
    $now = time();
    $lastAuthAt = isset($_SESSION['cv_auth_at']) ? (int) $_SESSION['cv_auth_at'] : 0;
    if ($lastAuthAt > 0 && ($now - $lastAuthAt) > (int) CV_AUTH_SESSION_TTL) {
        $_SESSION = [];
        session_regenerate_id(true);
        return;
    }

    if (isset($_SESSION['cv_user']) && is_array($_SESSION['cv_user'])) {
        $_SESSION['cv_auth_at'] = $now;
    }
}

function cvAuthRequestData(): array
{
    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    if (strpos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    return is_array($_POST) ? $_POST : [];
}

function cvAuthNormalizeEmail(string $email): string
{
    return strtolower(trim($email));
}

function cvAuthNormalizePhone(string $phone): string
{
    $clean = trim((string) preg_replace('/\s+/', ' ', $phone));
    if ($clean === '') {
        return '-';
    }

    $clean = substr($clean, 0, 30);
    if (!preg_match('/^[0-9+\s().-]{6,30}$/', $clean)) {
        return '';
    }

    return $clean;
}

function cvAuthSplitFullName(string $fullName): array
{
    $clean = trim(preg_replace('/\s+/', ' ', $fullName) ?? '');
    if ($clean === '') {
        return ['', ''];
    }

    $parts = explode(' ', $clean);
    $first = (string) array_shift($parts);
    $last = trim(implode(' ', $parts));
    if ($last === '') {
        $last = '-';
    }

    return [$first, $last];
}

function cvAuthFetchProvinceById(mysqli $connection, int $idProv): ?array
{
    if ($idProv <= 0) {
        return null;
    }

    $tableCheck = $connection->query("SHOW TABLES LIKE 'provReg'");
    if (!$tableCheck instanceof mysqli_result || $tableCheck->num_rows === 0) {
        return null;
    }
    $tableCheck->free();

    $statement = $connection->prepare('SELECT id_prov, provincia, regione FROM provReg WHERE id_prov = ? LIMIT 1');
    if (!$statement instanceof mysqli_stmt) {
        return null;
    }

    $statement->bind_param('i', $idProv);
    if (!$statement->execute()) {
        $statement->close();
        return null;
    }

    $result = $statement->get_result();
    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    $statement->close();

    if (!is_array($row)) {
        return null;
    }

    return [
        'id_prov' => (int) ($row['id_prov'] ?? 0),
        'provincia' => trim((string) ($row['provincia'] ?? '')),
        'regione' => trim((string) ($row['regione'] ?? '')),
    ];
}

function cvAuthResolveResidence(mysqli $connection, array $payload): array
{
    $idProv = isset($payload['id_prov']) ? (int) $payload['id_prov'] : 0;
    $cityRaw = trim((string) ($payload['citta'] ?? ''));
    $city = substr($cityRaw, 0, 255);

    if ($idProv > 0) {
        $province = cvAuthFetchProvinceById($connection, $idProv);
        if (is_array($province)) {
            return [
                'id_prov' => (int) ($province['id_prov'] ?? 0),
                'citta' => (string) ($province['provincia'] ?? ''),
            ];
        }
    }

    return [
        'id_prov' => 0,
        'citta' => $city !== '' ? $city : '-',
    ];
}

function cvAuthCurrentUser(): ?array
{
    if (!isset($_SESSION['cv_user']) || !is_array($_SESSION['cv_user'])) {
        return null;
    }

    /** @var array<string, mixed> $sessionUser */
    $sessionUser = $_SESSION['cv_user'];
    return [
        'id' => (int) ($sessionUser['id'] ?? 0),
        'nome' => (string) ($sessionUser['nome'] ?? ''),
        'cognome' => (string) ($sessionUser['cognome'] ?? ''),
        'email' => (string) ($sessionUser['email'] ?? ''),
        'tel' => (string) ($sessionUser['tel'] ?? ''),
        'birth_date' => (string) ($sessionUser['birth_date'] ?? ''),
        'citta' => (string) ($sessionUser['citta'] ?? ''),
        'id_prov' => (int) ($sessionUser['id_prov'] ?? 0),
    ];
}

function cvAuthSessionUserId(): int
{
    if (!isset($_SESSION['cv_user']) || !is_array($_SESSION['cv_user'])) {
        return 0;
    }

    return (int) ($_SESSION['cv_user']['id'] ?? 0);
}

function cvAuthLoginSession(array $user, bool $regenerateSessionId = false): void
{
    if ($regenerateSessionId) {
        session_regenerate_id(true);
    }
    $_SESSION['cv_user'] = [
        'id' => (int) ($user['id'] ?? 0),
        'nome' => (string) ($user['nome'] ?? ''),
        'cognome' => (string) ($user['cognome'] ?? ''),
        'email' => (string) ($user['email'] ?? ''),
        'tel' => (string) ($user['tel'] ?? ''),
        'birth_date' => (string) ($user['birth_date'] ?? ''),
        'citta' => (string) ($user['citta'] ?? ''),
        'id_prov' => (int) ($user['id_prov'] ?? 0),
    ];
    $_SESSION['cv_auth_at'] = time();
}

function cvAuthLogout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'] ?? '/',
            $params['domain'] ?? '',
            (bool) ($params['secure'] ?? false),
            (bool) ($params['httponly'] ?? true)
        );
    }
    session_destroy();
}

function cvAuthGetUserByEmail(mysqli $connection, string $email): ?array
{
    $sql = 'SELECT id_vg, nome, cognome, citta, id_prov, email, tel, data, pass, stato';
    if (cvAuthHasGoogleUserIdColumn($connection)) {
        $sql .= ', google_userid';
    } else {
        $sql .= ", '' AS google_userid";
    }
    $sql .= ' FROM viaggiatori WHERE email = ? LIMIT 1';

    $statement = $connection->prepare($sql);
    if (!$statement instanceof mysqli_stmt) {
        return null;
    }

    $statement->bind_param('s', $email);
    if (!$statement->execute()) {
        $statement->close();
        return null;
    }

    $result = $statement->get_result();
    if (!$result instanceof mysqli_result) {
        $statement->close();
        return null;
    }

    $row = $result->fetch_assoc();
    $statement->close();
    return is_array($row) ? $row : null;
}

function cvAuthGetUserByGoogleId(mysqli $connection, string $googleUserId): ?array
{
    if (!cvAuthHasGoogleUserIdColumn($connection)) {
        return null;
    }

    $sql = 'SELECT id_vg, nome, cognome, citta, id_prov, email, tel, data, pass, stato, google_userid FROM viaggiatori WHERE google_userid = ? LIMIT 1';
    $statement = $connection->prepare($sql);
    if (!$statement instanceof mysqli_stmt) {
        return null;
    }

    $statement->bind_param('s', $googleUserId);
    if (!$statement->execute()) {
        $statement->close();
        return null;
    }

    $result = $statement->get_result();
    if (!$result instanceof mysqli_result) {
        $statement->close();
        return null;
    }

    $row = $result->fetch_assoc();
    $statement->close();
    return is_array($row) ? $row : null;
}

function cvAuthGetUserById(mysqli $connection, int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }

    $sql = 'SELECT id_vg, nome, cognome, citta, id_prov, email, tel, data, pass, stato';
    if (cvAuthHasGoogleUserIdColumn($connection)) {
        $sql .= ', google_userid';
    } else {
        $sql .= ", '' AS google_userid";
    }
    $sql .= ' FROM viaggiatori WHERE id_vg = ? LIMIT 1';

    $statement = $connection->prepare($sql);
    if (!$statement instanceof mysqli_stmt) {
        return null;
    }

    $statement->bind_param('i', $userId);
    if (!$statement->execute()) {
        $statement->close();
        return null;
    }

    $result = $statement->get_result();
    if (!$result instanceof mysqli_result) {
        $statement->close();
        return null;
    }

    $row = $result->fetch_assoc();
    $statement->close();
    return is_array($row) ? $row : null;
}

function cvAuthIsHashFormat(string $value): bool
{
    return (strpos($value, '$2y$') === 0) || (strpos($value, '$argon2') === 0);
}

function cvAuthVerifyPassword(string $plainPassword, string $storedPassword): bool
{
    if ($storedPassword === '' || $storedPassword === '-') {
        return false;
    }

    if (cvAuthIsHashFormat($storedPassword)) {
        return password_verify($plainPassword, $storedPassword);
    }

    if (hash_equals($storedPassword, $plainPassword)) {
        return true;
    }

    if (hash_equals($storedPassword, md5($plainPassword))) {
        return true;
    }

    if (hash_equals($storedPassword, sha1($plainPassword))) {
        return true;
    }

    return false;
}

function cvAuthMaybeUpgradePassword(mysqli $connection, int $userId, string $plainPassword, string $storedPassword): void
{
    if (cvAuthIsHashFormat($storedPassword) && !password_needs_rehash($storedPassword, PASSWORD_DEFAULT)) {
        return;
    }

    $newHash = password_hash($plainPassword, PASSWORD_DEFAULT);
    if (!is_string($newHash) || $newHash === '') {
        return;
    }

    $sql = 'UPDATE viaggiatori SET pass = ? WHERE id_vg = ? LIMIT 1';
    $statement = $connection->prepare($sql);
    if (!$statement instanceof mysqli_stmt) {
        return;
    }

    $statement->bind_param('si', $newHash, $userId);
    $statement->execute();
    $statement->close();
}

function cvAuthToUserPayload(array $row): array
{
    $birthDate = trim((string) ($row['data'] ?? ''));
    if ($birthDate === '1970-01-01') {
        $birthDate = '';
    }

    return [
        'id' => (int) ($row['id_vg'] ?? 0),
        'nome' => (string) ($row['nome'] ?? ''),
        'cognome' => (string) ($row['cognome'] ?? ''),
        'email' => (string) ($row['email'] ?? ''),
        'tel' => (string) ($row['tel'] ?? ''),
        'birth_date' => $birthDate,
        'citta' => (string) ($row['citta'] ?? ''),
        'id_prov' => (int) ($row['id_prov'] ?? 0),
    ];
}

function cvAuthHandleLogin(mysqli $connection): void
{
    $payload = cvAuthRequestData();
    $email = cvAuthNormalizeEmail((string) ($payload['email'] ?? ''));
    $password = (string) ($payload['password'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || trim($password) === '') {
        cvAuthResponse(false, 'Inserisci email e password valide.', [], 'VALIDATION_ERROR', 422);
    }

    $row = cvAuthGetUserByEmail($connection, $email);
    if ($row === null) {
        cvAuthResponse(false, 'Credenziali non valide.', [], 'INVALID_CREDENTIALS', 401);
    }

    if ((int) ($row['stato'] ?? 0) !== 1) {
        cvAuthResponse(false, 'Account non attivo. Conferma la registrazione tramite email.', [], 'ACCOUNT_INACTIVE', 403);
    }

    $storedPassword = (string) ($row['pass'] ?? '');
    if (!cvAuthVerifyPassword($password, $storedPassword)) {
        cvAuthResponse(false, 'Credenziali non valide.', [], 'INVALID_CREDENTIALS', 401);
    }

    $userId = (int) ($row['id_vg'] ?? 0);
    if ($userId > 0) {
        cvAuthMaybeUpgradePassword($connection, $userId, $password, $storedPassword);
    }

    $user = cvAuthToUserPayload($row);
    cvAuthLoginSession($user, true);
    cvAuthResponse(true, 'Login effettuato con successo.', ['user' => $user]);
}

function cvAuthHandleRegister(mysqli $connection): void
{
    $payload = cvAuthRequestData();
    $fullName = trim((string) ($payload['name'] ?? ''));
    $email = cvAuthNormalizeEmail((string) ($payload['email'] ?? ''));
    $password = (string) ($payload['password'] ?? '');
    $passwordConfirm = (string) ($payload['password_confirm'] ?? '');
    $newsletterSubscribed = filter_var($payload['newsletter_subscribed'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $residence = cvAuthResolveResidence($connection, $payload);
    $phone = cvAuthNormalizePhone((string) ($payload['tel'] ?? ''));

    if (strlen($fullName) < 3) {
        cvAuthResponse(false, 'Inserisci nome e cognome.', [], 'VALIDATION_ERROR', 422);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        cvAuthResponse(false, 'Email non valida.', [], 'VALIDATION_ERROR', 422);
    }

    if (strlen($password) < 6 || $password !== $passwordConfirm) {
        cvAuthResponse(false, 'Password non valida o non coincidente.', [], 'VALIDATION_ERROR', 422);
    }

    if ((int) ($residence['id_prov'] ?? 0) <= 0) {
        cvAuthResponse(false, 'Seleziona la provincia di residenza.', [], 'VALIDATION_ERROR', 422);
    }

    if ($phone === '') {
        cvAuthResponse(false, 'Numero di telefono non valido.', [], 'VALIDATION_ERROR', 422);
    }

    [$name, $surname] = cvAuthSplitFullName($fullName);
    if ($name === '') {
        cvAuthResponse(false, 'Nome non valido.', [], 'VALIDATION_ERROR', 422);
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    if (!is_string($passwordHash) || $passwordHash === '') {
        cvAuthResponse(false, 'Impossibile creare l\'account.', [], 'REGISTER_ERROR', 500);
    }

    if (!cvAuthEnsureEmailVerificationTable($connection)) {
        cvAuthResponse(false, 'Impossibile preparare la verifica email.', [], 'REGISTER_ERROR', 500);
    }

    $existing = cvAuthGetUserByEmail($connection, $email);
    $userId = 0;

    if (is_array($existing)) {
        if ((int) ($existing['stato'] ?? 0) === 1) {
            cvAuthResponse(false, 'Email già registrata.', [], 'EMAIL_EXISTS', 409);
        }

        $userId = (int) ($existing['id_vg'] ?? 0);
        $city = (string) ($residence['citta'] ?? '-');
        $idProv = (int) ($residence['id_prov'] ?? 0);
        $sqlUpdate = 'UPDATE viaggiatori SET nome = ?, cognome = ?, citta = ?, id_prov = ?, pass = ?, tel = ?, stato = 0 WHERE id_vg = ? LIMIT 1';
        $updateStmt = $connection->prepare($sqlUpdate);
        if (!$updateStmt instanceof mysqli_stmt) {
            cvAuthResponse(false, 'Impossibile aggiornare la registrazione.', [], 'REGISTER_ERROR', 500);
        }

        $updateStmt->bind_param('sssissi', $name, $surname, $city, $idProv, $passwordHash, $phone, $userId);
        if (!$updateStmt->execute()) {
            $updateStmt->close();
            cvAuthResponse(false, 'Errore durante la registrazione.', [], 'REGISTER_ERROR', 500);
        }
        $updateStmt->close();
    } else {
        $sql = 'INSERT INTO viaggiatori (nome, cognome, citta, id_prov, email, pass, tel, data, profilo, tipo_pag, stato) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 0)';
        $statement = $connection->prepare($sql);
        if (!$statement instanceof mysqli_stmt) {
            cvAuthResponse(false, 'Impossibile creare l\'account.', [], 'REGISTER_ERROR', 500);
        }

        $city = (string) ($residence['citta'] ?? '-');
        $idProv = (int) ($residence['id_prov'] ?? 0);
        $defaultDate = '1970-01-01';
        $statement->bind_param('sssissss', $name, $surname, $city, $idProv, $email, $passwordHash, $phone, $defaultDate);

        if (!$statement->execute()) {
            $statement->close();
            cvAuthResponse(false, 'Errore durante la registrazione.', [], 'REGISTER_ERROR', 500);
        }

        $userId = (int) $statement->insert_id;
        $statement->close();
    }

    if ($userId <= 0) {
        cvAuthResponse(false, 'Impossibile creare la verifica email.', [], 'REGISTER_ERROR', 500);
    }

    $token = cvAuthIssueVerificationToken($connection, $userId, $email);
    if ($token === null) {
        cvAuthResponse(false, 'Impossibile creare la verifica email.', [], 'REGISTER_ERROR', 500);
    }

    $sent = cvAuthSendVerificationEmail($connection, $email, trim($name . ' ' . $surname), $token);
    if (!$sent) {
        cvAuthResponse(
            false,
            'Registrazione creata, ma invio email non riuscito. Riprova più tardi.',
            ['pending' => true],
            'VERIFY_EMAIL_SEND_ERROR',
            500
        );
    }

    cvAuthMarkVerificationSent($connection, $userId);
    cvAuthSetNewsletterForUser($connection, $userId, $email, $newsletterSubscribed, 'register');

    cvAuthResponse(
        true,
        'Registrazione completata. Controlla la tua email e conferma il link per attivare l\'account.',
        ['pending' => true]
    );
}

function cvAuthEnsureEmailVerificationTable(mysqli $connection): bool
{
    $sql = <<<SQL
CREATE TABLE IF NOT EXISTS cv_email_verifications (
  id_verification BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_vg INT NOT NULL,
  email VARCHAR(190) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  verified_at DATETIME DEFAULT NULL,
  sent_at DATETIME DEFAULT NULL,
  resend_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id_verification),
  UNIQUE KEY uniq_email_verify_user (id_vg),
  UNIQUE KEY uniq_email_verify_token_hash (token_hash),
  KEY idx_email_verify_email (email),
  KEY idx_email_verify_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL;

    return (bool) $connection->query($sql);
}

function cvAuthIssueVerificationToken(mysqli $connection, int $userId, string $email): ?string
{
    if ($userId <= 0 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return null;
    }

    if (!cvAuthEnsureEmailVerificationTable($connection)) {
        return null;
    }

    try {
        $token = bin2hex(random_bytes(32));
    } catch (Throwable $exception) {
        return null;
    }

    $tokenHash = hash('sha256', $token);
    $expiresAt = date('Y-m-d H:i:s', time() + max(3600, (int) CV_AUTH_VERIFY_TTL_SECONDS));

    $sql = <<<SQL
INSERT INTO cv_email_verifications (id_vg, email, token_hash, expires_at, verified_at, sent_at, resend_count)
VALUES (?, ?, ?, ?, NULL, NULL, 0)
ON DUPLICATE KEY UPDATE
  email = VALUES(email),
  token_hash = VALUES(token_hash),
  expires_at = VALUES(expires_at),
  verified_at = NULL,
  sent_at = NULL,
  resend_count = resend_count + 1
SQL;

    $statement = $connection->prepare($sql);
    if (!$statement instanceof mysqli_stmt) {
        return null;
    }

    $statement->bind_param('isss', $userId, $email, $tokenHash, $expiresAt);
    $ok = $statement->execute();
    $statement->close();

    if (!$ok) {
        return null;
    }

    return $token;
}

function cvAuthMarkVerificationSent(mysqli $connection, int $userId): void
{
    if ($userId <= 0) {
        return;
    }

    $statement = $connection->prepare('UPDATE cv_email_verifications SET sent_at = UTC_TIMESTAMP() WHERE id_vg = ? LIMIT 1');
    if (!$statement instanceof mysqli_stmt) {
        return;
    }

    $statement->bind_param('i', $userId);
    $statement->execute();
    $statement->close();
}

function cvAuthCurrentScriptUrl(): string
{
    $isHttps = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
    $scheme = $isHttps ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '/auth/api.php');

    return $scheme . '://' . $host . $scriptName;
}

function cvAuthBuildVerifyUrl(string $token): string
{
    $base = cvAuthCurrentScriptUrl();
    return $base . '?action=verify_email&token=' . urlencode($token);
}

function cvAuthEnsureMailSettTable(mysqli $connection): bool
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

    return (bool) $connection->query($sql);
}

function cvAuthMailSettings(mysqli $connection, ?int $forcedSlot = null): array
{
    cvAuthEnsureMailSettTable($connection);

    $slot = $forcedSlot !== null ? (int) $forcedSlot : (int) CV_AUTH_MAIL_SLOT;
    if ($slot < 1 || $slot > 3) {
        $slot = 3;
    }

    $defaultFrom = trim((string) CV_AUTH_DEFAULT_FROM_EMAIL);
    if (!filter_var($defaultFrom, FILTER_VALIDATE_EMAIL)) {
        $defaultFrom = 'noreply@fillbus.it';
    }
    $defaultSubject = 'Comunicazioni da ' . (string) CV_AUTH_BRAND_NAME;
    $settings = [
        'from_email' => $defaultFrom,
        'from_name' => (string) CV_AUTH_BRAND_NAME,
        'smtp_host' => '',
        'smtp_port' => 0,
        'smtp_security' => '',
        'smtp_user' => '',
        'smtp_pass' => '',
        'subject_prefix' => $defaultSubject,
    ];

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
    if ($fromEmail === '') {
        $fromEmail = $defaultFrom;
    }

    $subjectPrefix = trim((string) ($row[$subjectField] ?? ''));
    if ($subjectPrefix === '') {
        $subjectPrefix = $defaultSubject;
    }

    $settings['from_email'] = $fromEmail;
    $settings['from_name'] = $subjectPrefix;
    $settings['smtp_host'] = trim((string) ($row['smtp'] ?? ''));
    $settings['smtp_port'] = (int) ($row['smtpport'] ?? 0);
    $settings['smtp_security'] = $smtpSecurity;
    $settings['smtp_user'] = trim((string) ($row[$userField] ?? ''));
    $settings['smtp_pass'] = trim((string) ($row[$passField] ?? ''));
    $settings['subject_prefix'] = $subjectPrefix;

    return $settings;
}

function cvAuthLoadPhpMailer(): bool
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

function cvAuthSendMail(
    mysqli $connection,
    string $toEmail,
    string $toName,
    string $subject,
    string $htmlBody,
    string $plainBody = '',
    ?int $forcedSlot = null
): bool {
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $settings = cvAuthMailSettings($connection, $forcedSlot);
    $fromEmail = trim((string) ($settings['from_email'] ?? ''));
    $fromName = trim((string) ($settings['from_name'] ?? (string) CV_AUTH_BRAND_NAME));
    if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        $fromEmail = trim((string) CV_AUTH_DEFAULT_FROM_EMAIL);
        if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            $fromEmail = 'noreply@fillbus.it';
        }
    }
    if ($fromName === '') {
        $fromName = (string) CV_AUTH_BRAND_NAME;
    }

    $smtpHost = trim((string) ($settings['smtp_host'] ?? ''));
    $smtpPort = (int) ($settings['smtp_port'] ?? 0);
    $smtpSecurity = trim((string) ($settings['smtp_security'] ?? ''));
    $smtpUser = trim((string) ($settings['smtp_user'] ?? ''));
    $smtpPass = (string) ($settings['smtp_pass'] ?? '');

    if ($smtpHost !== '' && cvAuthLoadPhpMailer()) {
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
            $mail->AltBody = $plainBody !== '' ? $plainBody : strip_tags($htmlBody);

            $maxAttempts = 3;
            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                if ($mail->send()) {
                    return true;
                }
                usleep(2000000);
            }
        } catch (Throwable $exception) {
            error_log('cv mail smtp error: ' . $exception->getMessage());
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

function cvAuthSendVerificationEmail(mysqli $connection, string $email, string $fullName, string $token): bool
{
    $verifyUrl = cvAuthBuildVerifyUrl($token);
    $safeName = trim($fullName) !== '' ? trim($fullName) : 'utente';
    $subject = 'Conferma registrazione ' . (string) CV_AUTH_BRAND_NAME;

    $html = '<html><body>';
    $html .= '<p>Ciao ' . htmlspecialchars($safeName, ENT_QUOTES, 'UTF-8') . ',</p>';
    $html .= '<p>Conferma la tua registrazione cliccando sul link seguente:</p>';
    $html .= '<p><a href="' . htmlspecialchars($verifyUrl, ENT_QUOTES, 'UTF-8') . '">Conferma account</a></p>';
    $html .= '<p>Se non hai richiesto la registrazione, ignora questa email.</p>';
    $html .= '</body></html>';

    $plain = "Ciao {$safeName},\nConferma la registrazione: {$verifyUrl}\n";
    return cvAuthSendMail($connection, $email, $safeName, $subject, $html, $plain);
}

function cvAuthMaskEmail(string $email): string
{
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return '';
    }

    [$localPart, $domainPart] = array_pad(explode('@', $email, 2), 2, '');
    if ($localPart === '' || $domainPart === '') {
        return '';
    }

    $localVisible = strlen($localPart) <= 2
        ? substr($localPart, 0, 1)
        : (substr($localPart, 0, 2) . str_repeat('*', max(2, strlen($localPart) - 2)));

    $domainChunks = explode('.', $domainPart);
    $mainDomain = (string) ($domainChunks[0] ?? '');
    $suffix = count($domainChunks) > 1 ? ('.' . implode('.', array_slice($domainChunks, 1))) : '';
    if ($mainDomain !== '') {
        $mainDomain = substr($mainDomain, 0, 1) . str_repeat('*', max(2, strlen($mainDomain) - 1));
    }

    return $localVisible . '@' . $mainDomain . $suffix;
}

function cvAuthEnsureTicketRecoveryTable(mysqli $connection): bool
{
    $sql = <<<SQL
CREATE TABLE IF NOT EXISTS cv_ticket_recovery_requests (
  id_request BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_key VARCHAR(80) NOT NULL DEFAULT '',
  email VARCHAR(190) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  ticket_codes_json MEDIUMTEXT NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME DEFAULT NULL,
  sent_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id_request),
  UNIQUE KEY uq_cv_ticket_recovery_token (token_hash),
  KEY idx_cv_ticket_recovery_email (email),
  KEY idx_cv_ticket_recovery_expires (expires_at),
  KEY idx_cv_ticket_recovery_session (session_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL;

    return (bool) $connection->query($sql);
}

function cvAuthBuildTicketRecoveryUrl(string $token): string
{
    $base = cvAuthCurrentScriptUrl();
    return $base . '?action=ticket_recovery_confirm&token=' . urlencode($token);
}

/**
 * @param array<int,array<string,mixed>> $matches
 */
function cvAuthIssueTicketRecoveryRequest(mysqli $connection, string $sessionKey, string $email, array $matches): ?string
{
    $email = cvAuthNormalizeEmail($email);
    $sessionKey = cvAssistantNormalizeSessionKey($sessionKey);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $sessionKey === '' || count($matches) === 0) {
        return null;
    }

    if (!cvAuthEnsureTicketRecoveryTable($connection)) {
        return null;
    }

    $ticketCodes = [];
    foreach ($matches as $match) {
        if (!is_array($match)) {
            continue;
        }
        $code = strtoupper(trim((string) ($match['codice'] ?? '')));
        if ($code !== '') {
            $ticketCodes[$code] = $code;
        }
    }
    if (count($ticketCodes) === 0) {
        return null;
    }

    try {
        $token = bin2hex(random_bytes(32));
    } catch (Throwable $exception) {
        return null;
    }

    $tokenHash = hash('sha256', $token);
    $ticketCodesJson = json_encode(array_values($ticketCodes), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($ticketCodesJson) || $ticketCodesJson === '') {
        return null;
    }

    $expiresAt = date('Y-m-d H:i:s', time() + 3600);
    $sql = 'INSERT INTO cv_ticket_recovery_requests (session_key, email, token_hash, ticket_codes_json, expires_at, used_at, sent_at)
            VALUES (?, ?, ?, ?, ?, NULL, UTC_TIMESTAMP())';
    $statement = $connection->prepare($sql);
    if (!$statement instanceof mysqli_stmt) {
        return null;
    }
    $statement->bind_param('sssss', $sessionKey, $email, $tokenHash, $ticketCodesJson, $expiresAt);
    $ok = $statement->execute();
    $statement->close();
    return $ok ? $token : null;
}

/**
 * @param array<int,array<string,mixed>> $matches
 */
function cvAuthSendTicketRecoveryEmail(mysqli $connection, string $email, string $fullName, string $token, array $matches): bool
{
    $recoveryUrl = cvAuthBuildTicketRecoveryUrl($token);
    $safeName = trim($fullName) !== '' ? trim($fullName) : 'cliente';
    $ticketCount = max(1, count($matches));
    $subject = 'Recupero biglietto ' . (string) CV_AUTH_BRAND_NAME;

    $html = '<html><body>';
    $html .= '<p>Ciao ' . htmlspecialchars($safeName, ENT_QUOTES, 'UTF-8') . ',</p>';
    $html .= '<p>abbiamo ricevuto una richiesta di recupero per ' . $ticketCount . ' prenotazione/i associata/e ai tuoi dati.</p>';
    $html .= '<p>Per sicurezza non mostriamo il codice in chat: usa questo link per aprire il dettaglio del biglietto.</p>';
    $html .= '<p><a href="' . htmlspecialchars($recoveryUrl, ENT_QUOTES, 'UTF-8') . '">Apri recupero biglietto</a></p>';
    $html .= '<p>Se non sei stato tu a richiedere il recupero, ignora questo messaggio.</p>';
    $html .= '</body></html>';

    $plain = "Ciao {$safeName},\nUsa questo link per recuperare il biglietto: {$recoveryUrl}\nSe non sei stato tu a richiedere il recupero, ignora questo messaggio.\n";
    return cvAuthSendMail($connection, $email, $safeName, $subject, $html, $plain);
}

function cvAuthWantsHtmlResponse(): bool
{
    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    return strpos($accept, 'text/html') !== false;
}

function cvAuthRenderVerifyPage(bool $success, string $title, string $message): void
{
    http_response_code($success ? 200 : 400);
    header('Content-Type: text/html; charset=utf-8');

    $bg = $success ? '#eaf7ee' : '#fdecec';
    $color = $success ? '#195b2a' : '#7d1d1d';
    echo '<!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>';
    echo '<style>body{font-family:Arial,sans-serif;background:#f4f7fb;margin:0;padding:32px}.box{max-width:640px;margin:0 auto;background:' . $bg . ';border:1px solid #d7e3ef;border-radius:12px;padding:20px;color:' . $color . '}a.btn{display:inline-block;margin-top:12px;background:#0f76c6;color:#fff;text-decoration:none;padding:10px 14px;border-radius:8px}</style>';
    echo '</head><body><div class="box"><h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>';
    echo '<p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';
    echo '<a class="btn" href="../">Vai alla home</a></div></body></html>';
    exit;
}

function cvAuthHandleVerifyEmail(mysqli $connection): void
{
    $token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
    if ($token === '') {
        if (cvAuthWantsHtmlResponse()) {
            cvAuthRenderVerifyPage(false, 'Token non valido', 'Il link di verifica non è valido.');
        }
        cvAuthResponse(false, 'Token non valido.', [], 'VERIFY_TOKEN_INVALID', 422);
    }

    if (!cvAuthEnsureEmailVerificationTable($connection)) {
        if (cvAuthWantsHtmlResponse()) {
            cvAuthRenderVerifyPage(false, 'Errore verifica', 'Servizio verifica non disponibile.');
        }
        cvAuthResponse(false, 'Servizio verifica non disponibile.', [], 'VERIFY_ERROR', 500);
    }

    $tokenHash = hash('sha256', $token);
    $sql = 'SELECT id_vg, email, expires_at, verified_at FROM cv_email_verifications WHERE token_hash = ? LIMIT 1';
    $statement = $connection->prepare($sql);
    if (!$statement instanceof mysqli_stmt) {
        cvAuthResponse(false, 'Errore verifica.', [], 'VERIFY_ERROR', 500);
    }
    $statement->bind_param('s', $tokenHash);
    if (!$statement->execute()) {
        $statement->close();
        cvAuthResponse(false, 'Errore verifica.', [], 'VERIFY_ERROR', 500);
    }

    $result = $statement->get_result();
    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    $statement->close();

    if (!is_array($row)) {
        if (cvAuthWantsHtmlResponse()) {
            cvAuthRenderVerifyPage(false, 'Link non valido', 'Il link di verifica non è valido o è già stato usato.');
        }
        cvAuthResponse(false, 'Token non trovato.', [], 'VERIFY_TOKEN_NOT_FOUND', 404);
    }

    $isAlreadyVerified = !empty($row['verified_at']);
    $expiresAt = strtotime((string) ($row['expires_at'] ?? ''));
    if (!$isAlreadyVerified && ($expiresAt === false || $expiresAt < time())) {
        if (cvAuthWantsHtmlResponse()) {
            cvAuthRenderVerifyPage(false, 'Link scaduto', 'Il link di verifica è scaduto. Effettua una nuova registrazione.');
        }
        cvAuthResponse(false, 'Token scaduto.', [], 'VERIFY_TOKEN_EXPIRED', 410);
    }

    $userId = (int) ($row['id_vg'] ?? 0);
    if ($userId <= 0) {
        cvAuthResponse(false, 'Utente non valido.', [], 'VERIFY_USER_INVALID', 500);
    }

    if (!$isAlreadyVerified) {
        $updateUser = $connection->prepare('UPDATE viaggiatori SET stato = 1 WHERE id_vg = ? LIMIT 1');
        if ($updateUser instanceof mysqli_stmt) {
            $updateUser->bind_param('i', $userId);
            $updateUser->execute();
            $updateUser->close();
        }

        $updateVerify = $connection->prepare('UPDATE cv_email_verifications SET verified_at = UTC_TIMESTAMP() WHERE id_vg = ? LIMIT 1');
        if ($updateVerify instanceof mysqli_stmt) {
            $updateVerify->bind_param('i', $userId);
            $updateVerify->execute();
            $updateVerify->close();
        }
    }

    $email = cvAuthNormalizeEmail((string) ($row['email'] ?? ''));
    $userRow = cvAuthGetUserByEmail($connection, $email);
    if (is_array($userRow)) {
        cvAuthLoginSession(cvAuthToUserPayload($userRow), true);
    }

    if (cvAuthWantsHtmlResponse()) {
        cvAuthRenderVerifyPage(true, 'Email confermata', 'Account attivato correttamente. Ora puoi accedere.');
    }

    cvAuthResponse(true, 'Email confermata con successo.', ['verified' => true]);
}

function cvAuthRenderTicketRecoveryResult(bool $success, string $title, string $message, array $ticketCodes = []): void
{
    if (!$success) {
        cvAuthRenderVerifyPage(false, $title, $message);
    }

    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');

    echo '<!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>';
    echo '<style>body{font-family:Arial,sans-serif;background:#f4f7fb;margin:0;padding:32px}.box{max-width:720px;margin:0 auto;background:#eaf3ff;border:1px solid #d7e3ef;border-radius:12px;padding:20px;color:#173764}.list{margin:16px 0 0;padding:0;list-style:none}.list li{margin:0 0 10px}.btn{display:inline-block;background:#0f76c6;color:#fff;text-decoration:none;padding:10px 14px;border-radius:8px}.secondary{display:inline-block;margin-top:12px;color:#173764;text-decoration:none}</style>';
    echo '</head><body><div class="box"><h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>';
    echo '<p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';
    if (count($ticketCodes) > 0) {
        echo '<ul class="list">';
        foreach ($ticketCodes as $ticketRow) {
            $ticketCode = '';
            $routeLabel = '';
            $departureLabel = '';
            if (is_array($ticketRow)) {
                $ticketCode = strtoupper(trim((string) ($ticketRow['ticket_code'] ?? '')));
                $routeLabel = trim((string) ($ticketRow['route_label'] ?? ''));
                $departureLabel = trim((string) ($ticketRow['departure_label'] ?? ''));
            } else {
                $ticketCode = strtoupper(trim((string) $ticketRow));
            }
            if ($ticketCode === '') {
                continue;
            }

            $meta = [];
            if ($routeLabel !== '') {
                $meta[] = $routeLabel;
            }
            if ($departureLabel !== '') {
                $meta[] = $departureLabel;
            }

            echo '<li>';
            if (count($meta) > 0) {
                echo '<div style="margin:0 0 6px;font-weight:600;">' . htmlspecialchars(implode(' | ', $meta), ENT_QUOTES, 'UTF-8') . '</div>';
            }
            echo '<a class="btn" href="../biglietti.php?code=' . rawurlencode($ticketCode) . '">Apri biglietto ' . htmlspecialchars($ticketCode, ENT_QUOTES, 'UTF-8') . '</a>';
            echo '</li>';
        }
        echo '</ul>';
    }
    echo '<a class="secondary" href="../">Torna alla home</a></div></body></html>';
    exit;
}

function cvAuthHandleTicketRecoveryConfirm(mysqli $connection): void
{
    $token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
    if ($token === '') {
        if (cvAuthWantsHtmlResponse()) {
            cvAuthRenderTicketRecoveryResult(false, 'Link non valido', 'Il link di recupero non è valido.');
        }
        cvAuthResponse(false, 'Token recupero non valido.', [], 'TICKET_RECOVERY_TOKEN_INVALID', 422);
    }

    if (!cvAuthEnsureTicketRecoveryTable($connection)) {
        if (cvAuthWantsHtmlResponse()) {
            cvAuthRenderTicketRecoveryResult(false, 'Servizio non disponibile', 'Il recupero ticket non è disponibile in questo momento.');
        }
        cvAuthResponse(false, 'Servizio recupero non disponibile.', [], 'TICKET_RECOVERY_ERROR', 500);
    }

    $tokenHash = hash('sha256', $token);
    $statement = $connection->prepare('SELECT id_request, ticket_codes_json, expires_at, used_at FROM cv_ticket_recovery_requests WHERE token_hash = ? LIMIT 1');
    if (!$statement instanceof mysqli_stmt) {
        cvAuthResponse(false, 'Errore recupero ticket.', [], 'TICKET_RECOVERY_ERROR', 500);
    }
    $statement->bind_param('s', $tokenHash);
    if (!$statement->execute()) {
        $statement->close();
        cvAuthResponse(false, 'Errore recupero ticket.', [], 'TICKET_RECOVERY_ERROR', 500);
    }
    $result = $statement->get_result();
    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $statement->close();

    if (!is_array($row)) {
        if (cvAuthWantsHtmlResponse()) {
            cvAuthRenderTicketRecoveryResult(false, 'Link non valido', 'Il link di recupero non è valido o è già scaduto.');
        }
        cvAuthResponse(false, 'Token recupero non trovato.', [], 'TICKET_RECOVERY_NOT_FOUND', 404);
    }

    $expiresAt = strtotime((string) ($row['expires_at'] ?? ''));
    if ($expiresAt === false || $expiresAt < time()) {
        if (cvAuthWantsHtmlResponse()) {
            cvAuthRenderTicketRecoveryResult(false, 'Link scaduto', 'Il link di recupero è scaduto. Richiedi un nuovo recupero dalla chat.');
        }
        cvAuthResponse(false, 'Link recupero scaduto.', [], 'TICKET_RECOVERY_EXPIRED', 410);
    }

    $ticketCodes = json_decode((string) ($row['ticket_codes_json'] ?? '[]'), true);
    $ticketCodes = is_array($ticketCodes) ? array_values(array_filter(array_map('strval', $ticketCodes))) : [];
    if (count($ticketCodes) === 0) {
        cvAuthResponse(false, 'Nessun biglietto associato al recupero.', [], 'TICKET_RECOVERY_EMPTY', 404);
    }

    $ticketDetails = [];
    $placeholders = implode(',', array_fill(0, count($ticketCodes), '?'));
    $sql = "SELECT
                b.codice,
                b.data AS departure_at,
                s1.nome AS from_name,
                s2.nome AS to_name
            FROM biglietti AS b
            LEFT JOIN tratte_sottoc AS s1 ON s1.id_sott = b.id_sott1
            LEFT JOIN tratte_sottoc AS s2 ON s2.id_sott = b.id_sott2
            WHERE b.codice IN ({$placeholders})";
    $detailStmt = $connection->prepare($sql);
    if ($detailStmt instanceof mysqli_stmt) {
        $types = str_repeat('s', count($ticketCodes));
        $params = [];
        foreach ($ticketCodes as $code) {
            $params[] = strtoupper(trim((string) $code));
        }
        $bind = [$types];
        foreach ($params as $idx => $value) {
            $bind[] = &$params[$idx];
        }
        call_user_func_array([$detailStmt, 'bind_param'], $bind);
        if ($detailStmt->execute()) {
            $detailResult = $detailStmt->get_result();
            while ($detailResult instanceof mysqli_result && ($detailRow = $detailResult->fetch_assoc())) {
                if (!is_array($detailRow)) {
                    continue;
                }
                $code = strtoupper(trim((string) ($detailRow['codice'] ?? '')));
                if ($code === '') {
                    continue;
                }
                $fromName = trim((string) ($detailRow['from_name'] ?? ''));
                $toName = trim((string) ($detailRow['to_name'] ?? ''));
                $routeLabel = ($fromName !== '' && $toName !== '') ? ($fromName . ' -> ' . $toName) : '';
                $departureRaw = trim((string) ($detailRow['departure_at'] ?? ''));
                $departureTs = $departureRaw !== '' ? strtotime($departureRaw) : false;
                $departureLabel = is_int($departureTs) && $departureTs > 0 ? date('d/m/Y H:i', $departureTs) : '';
                $ticketDetails[] = [
                    'ticket_code' => $code,
                    'route_label' => $routeLabel,
                    'departure_label' => $departureLabel,
                    'departure_sort' => is_int($departureTs) ? $departureTs : 0,
                ];
            }
            if ($detailResult instanceof mysqli_result) {
                $detailResult->free();
            }
        }
        $detailStmt->close();
    }

    if (count($ticketDetails) > 1) {
        usort(
            $ticketDetails,
            static function (array $left, array $right): int {
                return ((int) ($right['departure_sort'] ?? 0)) <=> ((int) ($left['departure_sort'] ?? 0));
            }
        );
    }

    if (count($ticketDetails) === 0) {
        foreach ($ticketCodes as $code) {
            $ticketDetails[] = [
                'ticket_code' => strtoupper(trim((string) $code)),
                'route_label' => '',
                'departure_label' => '',
                'departure_sort' => 0,
            ];
        }
    }

    $update = $connection->prepare('UPDATE cv_ticket_recovery_requests SET used_at = UTC_TIMESTAMP() WHERE id_request = ? LIMIT 1');
    if ($update instanceof mysqli_stmt) {
        $idRequest = (int) ($row['id_request'] ?? 0);
        $update->bind_param('i', $idRequest);
        $update->execute();
        $update->close();
    }

    if (cvAuthWantsHtmlResponse()) {
        cvAuthRenderTicketRecoveryResult(true, 'Recupero completato', 'Seleziona il biglietto da aprire.', $ticketDetails);
    }

    cvAuthResponse(true, 'Recupero ticket completato.', ['ticket_codes' => $ticketCodes]);
}

function cvAuthBuildResetPasswordUrl(string $token): string
{
    $base = cvAuthCurrentScriptUrl();
    return $base . '?action=reset_password&token=' . urlencode($token);
}

function cvAuthEnsurePasswordResetTable(mysqli $connection): bool
{
    $sql = <<<SQL
CREATE TABLE IF NOT EXISTS cv_password_resets (
  id_reset BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_vg INT NOT NULL,
  email VARCHAR(190) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  pending_password_hash VARCHAR(255) NOT NULL DEFAULT '',
  expires_at DATETIME NOT NULL,
  used_at DATETIME DEFAULT NULL,
  sent_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id_reset),
  UNIQUE KEY uq_cv_password_resets_token (token_hash),
  KEY idx_cv_password_resets_user (id_vg, used_at),
  KEY idx_cv_password_resets_exp (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL;

    if (!(bool) $connection->query($sql)) {
        return false;
    }

    $columnCheck = $connection->query("SHOW COLUMNS FROM cv_password_resets LIKE 'pending_password_hash'");
    if ($columnCheck instanceof mysqli_result) {
        $hasColumn = $columnCheck->num_rows > 0;
        $columnCheck->free();
        if (!$hasColumn) {
            if (!(bool) $connection->query("ALTER TABLE cv_password_resets ADD COLUMN pending_password_hash VARCHAR(255) NOT NULL DEFAULT '' AFTER token_hash")) {
                return false;
            }
        }
    }

    return true;
}

function cvAuthIssuePasswordResetToken(mysqli $connection, int $userId, string $email, string $pendingPasswordHash = ''): ?string
{
    if ($userId <= 0 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return null;
    }

    if (!cvAuthEnsurePasswordResetTable($connection)) {
        return null;
    }

    try {
        $token = bin2hex(random_bytes(32));
    } catch (Throwable $exception) {
        return null;
    }

    $tokenHash = hash('sha256', $token);
    $expiresAt = date('Y-m-d H:i:s', time() + max(900, (int) CV_AUTH_RESET_TTL_SECONDS));

    // invalidate previous active tokens for the same user
    $invalidate = $connection->prepare('UPDATE cv_password_resets SET used_at = UTC_TIMESTAMP() WHERE id_vg = ? AND used_at IS NULL');
    if ($invalidate instanceof mysqli_stmt) {
        $invalidate->bind_param('i', $userId);
        $invalidate->execute();
        $invalidate->close();
    }

    $statement = $connection->prepare(
        'INSERT INTO cv_password_resets (id_vg, email, token_hash, pending_password_hash, expires_at, used_at, sent_at) VALUES (?, ?, ?, ?, ?, NULL, NULL)'
    );
    if (!$statement instanceof mysqli_stmt) {
        return null;
    }

    $statement->bind_param('issss', $userId, $email, $tokenHash, $pendingPasswordHash, $expiresAt);
    $ok = $statement->execute();
    $statement->close();

    if (!$ok) {
        return null;
    }

    return $token;
}

function cvAuthMarkPasswordResetSent(mysqli $connection, int $userId): void
{
    if ($userId <= 0) {
        return;
    }

    $statement = $connection->prepare(
        'UPDATE cv_password_resets SET sent_at = UTC_TIMESTAMP() WHERE id_vg = ? AND used_at IS NULL ORDER BY id_reset DESC LIMIT 1'
    );
    if (!$statement instanceof mysqli_stmt) {
        return;
    }

    $statement->bind_param('i', $userId);
    $statement->execute();
    $statement->close();
}

function cvAuthSendPasswordResetEmail(mysqli $connection, string $email, string $fullName, string $token, bool $confirmOnly = false): bool
{
    $url = cvAuthBuildResetPasswordUrl($token);
    $safeName = trim($fullName) !== '' ? trim($fullName) : 'utente';
    $subject = 'Ripristino password ' . (string) CV_AUTH_BRAND_NAME;

    $html = '<html><body>';
    $html .= '<p>Ciao ' . htmlspecialchars($safeName, ENT_QUOTES, 'UTF-8') . ',</p>';
    if ($confirmOnly) {
        $html .= '<p>Hai richiesto la conferma della nuova password impostata su ' . htmlspecialchars((string) CV_AUTH_BRAND_NAME, ENT_QUOTES, 'UTF-8') . '.</p>';
        $html .= '<p><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">Conferma ripristino password</a></p>';
    } else {
        $html .= '<p>Hai richiesto il ripristino della password.</p>';
        $html .= '<p><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">Imposta una nuova password</a></p>';
    }
    $html .= '<p>Il link ha validità limitata. Se non hai richiesto il reset, ignora questa email.</p>';
    $html .= '</body></html>';

    if ($confirmOnly) {
        $plain = "Ciao {$safeName},\nConferma il ripristino password: {$url}\n";
    } else {
        $plain = "Ciao {$safeName},\nImposta una nuova password: {$url}\n";
    }
    return cvAuthSendMail($connection, $email, $safeName, $subject, $html, $plain);
}

function cvAuthRenderResetPasswordPage(
    string $token,
    string $message = '',
    bool $isError = false,
    bool $done = false,
    bool $confirmOnly = false
): void
{
    header('Content-Type: text/html; charset=utf-8');
    http_response_code($isError ? 400 : 200);

    $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    $safeToken = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');
    $alertBg = $isError ? '#fdecec' : '#eaf7ee';
    $alertColor = $isError ? '#7d1d1d' : '#195b2a';

    echo '<!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>Ripristino password</title>';
    echo '<style>
      body{font-family:Arial,sans-serif;background:#f4f7fb;margin:0;padding:32px}
      .box{max-width:560px;margin:0 auto;background:#fff;border:1px solid #d7e3ef;border-radius:12px;box-shadow:0 8px 22px rgba(18,51,89,.1);overflow:hidden}
      .hd{padding:16px 18px;border-bottom:1px solid #e6edf6;font-size:22px;font-weight:700;color:#19406f}
      .bd{padding:18px}
      .alert{padding:10px 12px;border-radius:10px;background:' . $alertBg . ';color:' . $alertColor . ';margin-bottom:14px}
      .lbl{display:block;font-size:14px;font-weight:700;color:#425b7e;margin:0 0 6px}
      .in{width:100%;border:1px solid #c7d6e7;border-radius:10px;min-height:44px;padding:8px 10px;margin-bottom:12px}
      .btn{display:inline-block;border:none;border-radius:10px;background:#0f76c6;color:#fff;font-weight:700;padding:10px 14px;cursor:pointer}
      .lnk{display:inline-block;margin-top:14px;color:#0f76c6;text-decoration:none;font-weight:700}
    </style>';
    echo '</head><body><div class="box"><div class="hd">Ripristino password</div><div class="bd">';

    if ($safeMessage !== '') {
        echo '<div class="alert">' . $safeMessage . '</div>';
    }

    if (!$done) {
        echo '<form method="post" action="?action=reset_password">';
        echo '<input type="hidden" name="token" value="' . $safeToken . '">';
        if ($confirmOnly) {
            echo '<p>Conferma il ripristino per applicare la nuova password scelta nella richiesta precedente.</p>';
            echo '<input type="hidden" name="confirm" value="1">';
            echo '<button class="btn" type="submit">Conferma ripristino</button>';
        } else {
            echo '<label class="lbl" for="password">Nuova password</label>';
            echo '<input class="in" id="password" name="password" type="password" minlength="6" required>';
            echo '<label class="lbl" for="password_confirm">Conferma password</label>';
            echo '<input class="in" id="password_confirm" name="password_confirm" type="password" minlength="6" required>';
            echo '<button class="btn" type="submit">Salva password</button>';
        }
        echo '</form>';
    }

    echo '<a class="lnk" href="../">Torna alla home</a>';
    echo '</div></div></body></html>';
    exit;
}

function cvAuthGetPasswordResetRow(mysqli $connection, string $token): ?array
{
    if ($token === '') {
        return null;
    }

    if (!cvAuthEnsurePasswordResetTable($connection)) {
        return null;
    }

    $tokenHash = hash('sha256', $token);
    $statement = $connection->prepare(
        'SELECT id_reset, id_vg, email, pending_password_hash, expires_at, used_at FROM cv_password_resets WHERE token_hash = ? LIMIT 1'
    );
    if (!$statement instanceof mysqli_stmt) {
        return null;
    }

    $statement->bind_param('s', $tokenHash);
    if (!$statement->execute()) {
        $statement->close();
        return null;
    }

    $result = $statement->get_result();
    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    $statement->close();
    return is_array($row) ? $row : null;
}

function cvAuthHandleForgotPassword(mysqli $connection): void
{
    $payload = cvAuthRequestData();
    $email = cvAuthNormalizeEmail((string) ($payload['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        cvAuthResponse(false, 'Inserisci una email valida.', [], 'VALIDATION_ERROR', 422);
    }

    $newPassword = (string) ($payload['new_password'] ?? '');
    $newPasswordConfirm = (string) ($payload['new_password_confirm'] ?? '');
    $hasPresetPassword = trim($newPassword) !== '' || trim($newPasswordConfirm) !== '';

    if ($hasPresetPassword) {
        if (strlen($newPassword) < 6 || $newPassword !== $newPasswordConfirm) {
            cvAuthResponse(false, 'La nuova password non è valida o non coincide.', [], 'VALIDATION_ERROR', 422);
        }
    }

    $row = cvAuthGetUserByEmail($connection, $email);
    if (is_array($row) && (int) ($row['id_vg'] ?? 0) > 0 && (int) ($row['stato'] ?? 0) === 1) {
        $userId = (int) ($row['id_vg'] ?? 0);
        $pendingPasswordHash = '';
        if ($hasPresetPassword) {
            $pendingPasswordHash = (string) password_hash($newPassword, PASSWORD_DEFAULT);
            if ($pendingPasswordHash === '') {
                cvAuthResponse(false, 'Impossibile preparare il reset password.', [], 'RESET_PREPARE_ERROR', 500);
            }
        }

        $token = cvAuthIssuePasswordResetToken($connection, $userId, $email, $pendingPasswordHash);
        if ($token !== null) {
            $fullName = trim((string) (($row['nome'] ?? '') . ' ' . ($row['cognome'] ?? '')));
            $sent = cvAuthSendPasswordResetEmail($connection, $email, $fullName, $token, $hasPresetPassword);
            if ($sent) {
                cvAuthMarkPasswordResetSent($connection, $userId);
            }
        }
    }

    cvAuthResponse(
        true,
        $hasPresetPassword
            ? 'Se l\'email è registrata, riceverai un link per confermare la nuova password.'
            : 'Se l\'email è registrata, riceverai a breve un link per reimpostare la password.',
        [
            'pending' => true,
            'mode' => $hasPresetPassword ? 'confirm_link' : 'set_on_link',
        ]
    );
}

function cvAuthHandleResetPassword(mysqli $connection): void
{
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        $token = trim((string) ($_GET['token'] ?? ''));
        if ($token === '') {
            if (cvAuthWantsHtmlResponse()) {
                cvAuthRenderResetPasswordPage('', 'Link di ripristino non valido.', true, true);
            }
            cvAuthResponse(false, 'Token non valido.', [], 'RESET_TOKEN_INVALID', 422);
        }

        $row = cvAuthGetPasswordResetRow($connection, $token);
        if (!is_array($row)) {
            if (cvAuthWantsHtmlResponse()) {
                cvAuthRenderResetPasswordPage('', 'Link di ripristino non valido o già usato.', true, true);
            }
            cvAuthResponse(false, 'Token non trovato.', [], 'RESET_TOKEN_NOT_FOUND', 404);
        }

        $expiresAt = strtotime((string) ($row['expires_at'] ?? ''));
        $isUsed = !empty($row['used_at']);
        $hasPendingPassword = trim((string) ($row['pending_password_hash'] ?? '')) !== '';
        if ($isUsed || $expiresAt === false || $expiresAt < time()) {
            if (cvAuthWantsHtmlResponse()) {
                cvAuthRenderResetPasswordPage('', 'Link scaduto o già utilizzato.', true, true);
            }
            cvAuthResponse(false, 'Token scaduto o già usato.', [], 'RESET_TOKEN_EXPIRED', 410);
        }

        if (cvAuthWantsHtmlResponse()) {
            cvAuthRenderResetPasswordPage($token, '', false, false, $hasPendingPassword);
        }

        cvAuthResponse(
            true,
            'Token valido.',
            [
                'token_valid' => true,
                'mode' => $hasPendingPassword ? 'confirm' : 'set_password',
            ]
        );
    }

    $payload = cvAuthRequestData();
    $token = trim((string) ($payload['token'] ?? ($_GET['token'] ?? '')));
    $password = (string) ($payload['password'] ?? '');
    $passwordConfirm = (string) ($payload['password_confirm'] ?? '');

    if ($token === '') {
        if (cvAuthWantsHtmlResponse()) {
            cvAuthRenderResetPasswordPage('', 'Token mancante.', true, true);
        }
        cvAuthResponse(false, 'Token mancante.', [], 'RESET_TOKEN_INVALID', 422);
    }

    $row = cvAuthGetPasswordResetRow($connection, $token);
    if (!is_array($row)) {
        if (cvAuthWantsHtmlResponse()) {
            cvAuthRenderResetPasswordPage('', 'Link di ripristino non valido o già usato.', true, true);
        }
        cvAuthResponse(false, 'Token non trovato.', [], 'RESET_TOKEN_NOT_FOUND', 404);
    }

    $expiresAt = strtotime((string) ($row['expires_at'] ?? ''));
    $isUsed = !empty($row['used_at']);
    $pendingPasswordHash = trim((string) ($row['pending_password_hash'] ?? ''));
    $hasPendingPassword = $pendingPasswordHash !== '';
    if ($isUsed || $expiresAt === false || $expiresAt < time()) {
        if (cvAuthWantsHtmlResponse()) {
            cvAuthRenderResetPasswordPage('', 'Link scaduto o già utilizzato.', true, true);
        }
        cvAuthResponse(false, 'Token scaduto o già usato.', [], 'RESET_TOKEN_EXPIRED', 410);
    }

    $passwordHash = '';
    if ($hasPendingPassword) {
        $confirmRaw = strtolower(trim((string) ($payload['confirm'] ?? '1')));
        $isConfirm = in_array($confirmRaw, ['1', 'true', 'yes', 'on'], true);
        if (!$isConfirm) {
            if (cvAuthWantsHtmlResponse()) {
                cvAuthRenderResetPasswordPage($token, 'Conferma richiesta non valida.', true, false, true);
            }
            cvAuthResponse(false, 'Conferma richiesta non valida.', [], 'VALIDATION_ERROR', 422);
        }
        $passwordHash = $pendingPasswordHash;
    } else {
        if (strlen($password) < 6 || $password !== $passwordConfirm) {
            if (cvAuthWantsHtmlResponse()) {
                cvAuthRenderResetPasswordPage($token, 'Password non valida o non coincidente.', true, false);
            }
            cvAuthResponse(false, 'Password non valida o non coincidente.', [], 'VALIDATION_ERROR', 422);
        }

        $passwordHash = (string) password_hash($password, PASSWORD_DEFAULT);
        if ($passwordHash === '') {
            cvAuthResponse(false, 'Impossibile aggiornare la password.', [], 'RESET_UPDATE_ERROR', 500);
        }
    }

    $userId = (int) ($row['id_vg'] ?? 0);
    if ($userId <= 0) {
        cvAuthResponse(false, 'Utente non valido.', [], 'RESET_USER_INVALID', 500);
    }

    $updateUser = $connection->prepare('UPDATE viaggiatori SET pass = ?, stato = 1 WHERE id_vg = ? LIMIT 1');
    if (!$updateUser instanceof mysqli_stmt) {
        cvAuthResponse(false, 'Impossibile aggiornare la password.', [], 'RESET_UPDATE_ERROR', 500);
    }
    $updateUser->bind_param('si', $passwordHash, $userId);
    if (!$updateUser->execute()) {
        $updateUser->close();
        cvAuthResponse(false, 'Errore durante il salvataggio password.', [], 'RESET_UPDATE_ERROR', 500);
    }
    $updateUser->close();

    $idReset = (int) ($row['id_reset'] ?? 0);
    $updateToken = $connection->prepare('UPDATE cv_password_resets SET used_at = UTC_TIMESTAMP(), pending_password_hash = \'\' WHERE id_reset = ? LIMIT 1');
    if ($updateToken instanceof mysqli_stmt) {
        $updateToken->bind_param('i', $idReset);
        $updateToken->execute();
        $updateToken->close();
    }

    if (cvAuthWantsHtmlResponse()) {
        cvAuthRenderResetPasswordPage('', 'Password aggiornata con successo. Ora puoi accedere.', false, true);
    }

    cvAuthResponse(true, 'Password aggiornata con successo.', ['updated' => true]);
}

function cvAuthEnsureNewsletterTable(mysqli $connection): bool
{
    $sql = <<<SQL
CREATE TABLE IF NOT EXISTS cv_newsletter_subscriptions (
  id_subscription BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_vg INT NOT NULL,
  email VARCHAR(190) NOT NULL,
  subscribed TINYINT(1) NOT NULL DEFAULT 0,
  source VARCHAR(64) NOT NULL DEFAULT 'web',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id_subscription),
  UNIQUE KEY uniq_news_user (id_vg),
  KEY idx_news_email (email),
  KEY idx_news_subscribed (subscribed)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL;

    return (bool) $connection->query($sql);
}

function cvAuthSetNewsletterForUser(
    mysqli $connection,
    int $userId,
    string $email,
    bool $subscribed,
    string $source = 'web'
): bool {
    if ($userId <= 0 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    if (!cvAuthEnsureNewsletterTable($connection)) {
        return false;
    }

    $flag = $subscribed ? 1 : 0;
    $normalizedSource = trim($source) !== '' ? trim($source) : 'web';

    $sql = <<<SQL
INSERT INTO cv_newsletter_subscriptions (id_vg, email, subscribed, source)
VALUES (?, ?, ?, ?)
ON DUPLICATE KEY UPDATE
  email = VALUES(email),
  subscribed = VALUES(subscribed),
  source = VALUES(source)
SQL;

    $statement = $connection->prepare($sql);
    if (!$statement instanceof mysqli_stmt) {
        return false;
    }

    $statement->bind_param('isis', $userId, $email, $flag, $normalizedSource);
    $ok = $statement->execute();
    $statement->close();
    return $ok;
}

function cvAuthGetNewsletterForUser(mysqli $connection, int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }

    if (!cvAuthEnsureNewsletterTable($connection)) {
        return false;
    }

    $sql = 'SELECT subscribed FROM cv_newsletter_subscriptions WHERE id_vg = ? LIMIT 1';
    $statement = $connection->prepare($sql);
    if (!$statement instanceof mysqli_stmt) {
        return false;
    }

    $statement->bind_param('i', $userId);
    if (!$statement->execute()) {
        $statement->close();
        return false;
    }

    $result = $statement->get_result();
    if (!$result instanceof mysqli_result) {
        $statement->close();
        return false;
    }

    $row = $result->fetch_assoc();
    $statement->close();
    if (!is_array($row)) {
        return false;
    }

    return ((int) ($row['subscribed'] ?? 0)) === 1;
}

function cvAuthHandleNewsletterGet(mysqli $connection): void
{
    $userId = cvAuthSessionUserId();
    if ($userId <= 0) {
        cvAuthResponse(false, 'Utente non autenticato.', [], 'UNAUTHORIZED', 401);
    }

    $status = cvAuthGetNewsletterForUser($connection, $userId);
    cvAuthResponse(true, 'Preferenze newsletter caricate.', ['subscribed' => $status]);
}

function cvAuthHandleNewsletterSet(mysqli $connection): void
{
    $userId = cvAuthSessionUserId();
    if ($userId <= 0) {
        cvAuthResponse(false, 'Utente non autenticato.', [], 'UNAUTHORIZED', 401);
    }

    $userRow = cvAuthGetUserById($connection, $userId);
    if (!is_array($userRow)) {
        cvAuthResponse(false, 'Utente non trovato.', [], 'USER_NOT_FOUND', 404);
    }

    $payload = cvAuthRequestData();
    $subscribed = filter_var($payload['subscribed'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $email = cvAuthNormalizeEmail((string) ($userRow['email'] ?? ''));

    if (!cvAuthSetNewsletterForUser($connection, $userId, $email, $subscribed, 'profile')) {
        cvAuthResponse(false, 'Impossibile aggiornare la newsletter.', [], 'NEWSLETTER_UPDATE_ERROR', 500);
    }

    cvAuthResponse(
        true,
        $subscribed ? 'Iscrizione newsletter attivata.' : 'Iscrizione newsletter disattivata.',
        ['subscribed' => $subscribed]
    );
}

function cvAuthEnsureNewsletterGuestSubscriptionsTable(mysqli $connection): bool
{
    $sql = <<<SQL
CREATE TABLE IF NOT EXISTS cv_newsletter_guest_subscriptions (
  id_guest_subscription BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email VARCHAR(190) NOT NULL,
  subscribed TINYINT(1) NOT NULL DEFAULT 0,
  source VARCHAR(64) NOT NULL DEFAULT 'guest',
  verified_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id_guest_subscription),
  UNIQUE KEY uq_news_guest_email (email),
  KEY idx_news_guest_subscribed (subscribed)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL;

    return (bool) $connection->query($sql);
}

function cvAuthEnsureNewsletterGuestVerificationTable(mysqli $connection): bool
{
    $sql = <<<SQL
CREATE TABLE IF NOT EXISTS cv_newsletter_guest_verifications (
  id_news_verify BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email VARCHAR(190) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME DEFAULT NULL,
  sent_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id_news_verify),
  UNIQUE KEY uq_news_verify_email (email),
  UNIQUE KEY uq_news_verify_token (token_hash),
  KEY idx_news_verify_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL;

    return (bool) $connection->query($sql);
}

function cvAuthNewsletterConfirmUrl(string $token): string
{
    $base = cvAuthCurrentScriptUrl();
    return $base . '?action=newsletter_confirm&token=' . urlencode($token);
}

function cvAuthIssueGuestNewsletterToken(mysqli $connection, string $email): ?string
{
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return null;
    }
    if (!cvAuthEnsureNewsletterGuestVerificationTable($connection)) {
        return null;
    }

    try {
        $token = bin2hex(random_bytes(32));
    } catch (Throwable $exception) {
        return null;
    }

    $tokenHash = hash('sha256', $token);
    $expiresAt = date('Y-m-d H:i:s', time() + max(3600, (int) CV_AUTH_VERIFY_TTL_SECONDS));
    $sql = <<<SQL
INSERT INTO cv_newsletter_guest_verifications (email, token_hash, expires_at, used_at, sent_at)
VALUES (?, ?, ?, NULL, NULL)
ON DUPLICATE KEY UPDATE
  token_hash = VALUES(token_hash),
  expires_at = VALUES(expires_at),
  used_at = NULL,
  sent_at = NULL
SQL;
    $statement = $connection->prepare($sql);
    if (!$statement instanceof mysqli_stmt) {
        return null;
    }
    $statement->bind_param('sss', $email, $tokenHash, $expiresAt);
    $ok = $statement->execute();
    $statement->close();
    if (!$ok) {
        return null;
    }

    return $token;
}

function cvAuthMarkGuestNewsletterSent(mysqli $connection, string $email): void
{
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return;
    }
    $statement = $connection->prepare('UPDATE cv_newsletter_guest_verifications SET sent_at = UTC_TIMESTAMP() WHERE email = ? LIMIT 1');
    if (!$statement instanceof mysqli_stmt) {
        return;
    }
    $statement->bind_param('s', $email);
    $statement->execute();
    $statement->close();
}

function cvAuthSendGuestNewsletterConfirmEmail(mysqli $connection, string $email, string $token): bool
{
    $confirmUrl = cvAuthNewsletterConfirmUrl($token);
    $subject = 'Conferma iscrizione newsletter ' . (string) CV_AUTH_BRAND_NAME;

    $html = '<html><body>';
    $html .= '<p>Ciao,</p>';
    $html .= '<p>conferma la tua iscrizione newsletter cliccando sul link seguente:</p>';
    $html .= '<p><a href="' . htmlspecialchars($confirmUrl, ENT_QUOTES, 'UTF-8') . '">Conferma iscrizione newsletter</a></p>';
    $html .= '<p>Se non hai richiesto l’iscrizione, ignora questa email.</p>';
    $html .= '</body></html>';

    $plain = "Conferma iscrizione newsletter: {$confirmUrl}\n";
    return cvAuthSendMail($connection, $email, '', $subject, $html, $plain, (int) CV_AUTH_NEWSLETTER_MAIL_SLOT);
}

function cvAuthGetGuestNewsletterStatus(mysqli $connection, string $email): bool
{
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    if (!cvAuthEnsureNewsletterGuestSubscriptionsTable($connection)) {
        return false;
    }

    $statement = $connection->prepare('SELECT subscribed FROM cv_newsletter_guest_subscriptions WHERE email = ? LIMIT 1');
    if (!$statement instanceof mysqli_stmt) {
        return false;
    }
    $statement->bind_param('s', $email);
    if (!$statement->execute()) {
        $statement->close();
        return false;
    }
    $result = $statement->get_result();
    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $statement->close();
    if (!is_array($row)) {
        return false;
    }

    return ((int) ($row['subscribed'] ?? 0)) === 1;
}

function cvAuthHandleNewsletterGuestSubscribe(mysqli $connection): void
{
    $payload = cvAuthRequestData();
    $email = cvAuthNormalizeEmail((string) ($payload['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        cvAuthResponse(false, 'Inserisci una email valida.', [], 'VALIDATION_ERROR', 422);
    }

    $userRow = cvAuthGetUserByEmail($connection, $email);
    $userId = is_array($userRow) ? (int) ($userRow['id_vg'] ?? 0) : 0;
    if ($userId > 0 && cvAuthGetNewsletterForUser($connection, $userId)) {
        cvAuthResponse(true, 'Email già iscritta alla newsletter.', ['already_subscribed' => true]);
    }
    if (cvAuthGetGuestNewsletterStatus($connection, $email)) {
        cvAuthResponse(true, 'Email già iscritta alla newsletter.', ['already_subscribed' => true]);
    }

    $token = cvAuthIssueGuestNewsletterToken($connection, $email);
    if (!is_string($token) || $token === '') {
        cvAuthResponse(false, 'Impossibile preparare la conferma newsletter.', [], 'NEWSLETTER_VERIFY_PREPARE_ERROR', 500);
    }

    $sent = cvAuthSendGuestNewsletterConfirmEmail($connection, $email, $token);
    if (!$sent) {
        cvAuthResponse(false, 'Invio email di conferma non riuscito. Riprova più tardi.', [], 'NEWSLETTER_VERIFY_SEND_ERROR', 500);
    }

    cvAuthMarkGuestNewsletterSent($connection, $email);
    cvAuthResponse(true, 'Controlla la tua email e conferma il link per completare l’iscrizione newsletter.', [
        'pending' => true,
    ]);
}

function cvAuthHandleNewsletterConfirm(mysqli $connection): void
{
    $token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
    if ($token === '') {
        if (cvAuthWantsHtmlResponse()) {
            cvAuthRenderVerifyPage(false, 'Token non valido', 'Il link di conferma newsletter non è valido.');
        }
        cvAuthResponse(false, 'Token non valido.', [], 'NEWSLETTER_TOKEN_INVALID', 422);
    }

    if (!cvAuthEnsureNewsletterGuestVerificationTable($connection)) {
        cvAuthResponse(false, 'Servizio newsletter non disponibile.', [], 'NEWSLETTER_CONFIRM_ERROR', 500);
    }

    $tokenHash = hash('sha256', $token);
    $statement = $connection->prepare('SELECT email, expires_at, used_at FROM cv_newsletter_guest_verifications WHERE token_hash = ? LIMIT 1');
    if (!$statement instanceof mysqli_stmt) {
        cvAuthResponse(false, 'Errore conferma newsletter.', [], 'NEWSLETTER_CONFIRM_ERROR', 500);
    }
    $statement->bind_param('s', $tokenHash);
    if (!$statement->execute()) {
        $statement->close();
        cvAuthResponse(false, 'Errore conferma newsletter.', [], 'NEWSLETTER_CONFIRM_ERROR', 500);
    }
    $result = $statement->get_result();
    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $statement->close();

    if (!is_array($row)) {
        if (cvAuthWantsHtmlResponse()) {
            cvAuthRenderVerifyPage(false, 'Link non valido', 'Il link di conferma non è valido o è già stato usato.');
        }
        cvAuthResponse(false, 'Token non trovato.', [], 'NEWSLETTER_TOKEN_NOT_FOUND', 404);
    }

    $isUsed = !empty($row['used_at']);
    $expiresAt = strtotime((string) ($row['expires_at'] ?? ''));
    if ($isUsed || $expiresAt === false || $expiresAt < time()) {
        if (cvAuthWantsHtmlResponse()) {
            cvAuthRenderVerifyPage(false, 'Link scaduto', 'Il link di conferma newsletter è scaduto o già usato.');
        }
        cvAuthResponse(false, 'Token scaduto o già usato.', [], 'NEWSLETTER_TOKEN_EXPIRED', 410);
    }

    $email = cvAuthNormalizeEmail((string) ($row['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        cvAuthResponse(false, 'Email non valida per la conferma newsletter.', [], 'NEWSLETTER_EMAIL_INVALID', 422);
    }

    $userRow = cvAuthGetUserByEmail($connection, $email);
    $userId = is_array($userRow) ? (int) ($userRow['id_vg'] ?? 0) : 0;
    if ($userId > 0) {
        if (!cvAuthSetNewsletterForUser($connection, $userId, $email, true, 'guest-confirm')) {
            cvAuthResponse(false, 'Impossibile aggiornare la newsletter.', [], 'NEWSLETTER_CONFIRM_ERROR', 500);
        }
    } else {
        if (!cvAuthEnsureNewsletterGuestSubscriptionsTable($connection)) {
            cvAuthResponse(false, 'Impossibile salvare la newsletter.', [], 'NEWSLETTER_CONFIRM_ERROR', 500);
        }
        $source = 'guest-confirm';
        $sql = <<<SQL
INSERT INTO cv_newsletter_guest_subscriptions (email, subscribed, source, verified_at)
VALUES (?, 1, ?, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE
  subscribed = 1,
  source = VALUES(source),
  verified_at = UTC_TIMESTAMP()
SQL;
        $saveStmt = $connection->prepare($sql);
        if (!$saveStmt instanceof mysqli_stmt) {
            cvAuthResponse(false, 'Impossibile salvare la newsletter.', [], 'NEWSLETTER_CONFIRM_ERROR', 500);
        }
        $saveStmt->bind_param('ss', $email, $source);
        if (!$saveStmt->execute()) {
            $saveStmt->close();
            cvAuthResponse(false, 'Impossibile salvare la newsletter.', [], 'NEWSLETTER_CONFIRM_ERROR', 500);
        }
        $saveStmt->close();
    }

    $useStmt = $connection->prepare('UPDATE cv_newsletter_guest_verifications SET used_at = UTC_TIMESTAMP() WHERE token_hash = ? LIMIT 1');
    if ($useStmt instanceof mysqli_stmt) {
        $useStmt->bind_param('s', $tokenHash);
        $useStmt->execute();
        $useStmt->close();
    }

    if (cvAuthWantsHtmlResponse()) {
        cvAuthRenderVerifyPage(true, 'Newsletter confermata', 'La tua iscrizione newsletter è attiva.');
    }
    cvAuthResponse(true, 'Iscrizione newsletter confermata.', ['subscribed' => true]);
}

function cvAuthHandleProvinceList(mysqli $connection): void
{
    $tableCheck = $connection->query("SHOW TABLES LIKE 'provReg'");
    if (!$tableCheck instanceof mysqli_result || $tableCheck->num_rows === 0) {
        cvAuthResponse(true, 'Lista province non disponibile.', ['provinces' => []]);
    }
    $tableCheck->free();

    $result = $connection->query('SELECT id_prov, provincia, regione FROM provReg ORDER BY provincia ASC');
    if (!$result instanceof mysqli_result) {
        cvAuthResponse(false, 'Impossibile caricare le province.', [], 'PROVINCES_LOAD_ERROR', 500);
    }

    $provinces = [];
    while ($row = $result->fetch_assoc()) {
        if (!is_array($row)) {
            continue;
        }
        $provinces[] = [
            'id_prov' => (int) ($row['id_prov'] ?? 0),
            'provincia' => (string) ($row['provincia'] ?? ''),
            'regione' => (string) ($row['regione'] ?? ''),
        ];
    }
    $result->free();

    cvAuthResponse(true, 'Province caricate.', ['provinces' => $provinces]);
}

function cvAuthHandleProfileGet(mysqli $connection): void
{
    $userId = cvAuthSessionUserId();
    if ($userId <= 0) {
        cvAuthResponse(false, 'Utente non autenticato.', [], 'UNAUTHORIZED', 401);
    }

    $userRow = cvAuthGetUserById($connection, $userId);
    if (!is_array($userRow)) {
        cvAuthResponse(false, 'Utente non trovato.', [], 'USER_NOT_FOUND', 404);
    }

    $user = cvAuthToUserPayload($userRow);
    cvAuthLoginSession($user);
    cvAuthResponse(true, 'Profilo caricato.', ['user' => $user]);
}

function cvAuthHandleTickets(mysqli $connection): void
{
    $userId = cvAuthSessionUserId();
    if ($userId <= 0) {
        cvAuthResponse(false, 'Utente non autenticato.', [], 'UNAUTHORIZED', 401);
    }

    try {
        $providerConfigsRaw = cvProviderConfigs($connection);
        $providerConfigs = [];
        foreach ($providerConfigsRaw as $cfgCode => $cfg) {
            if (!is_array($cfg)) {
                continue;
            }
            $normalizedCfgCode = strtolower(trim((string) $cfgCode));
            if ($normalizedCfgCode === '') {
                continue;
            }
            $providerConfigs[$normalizedCfgCode] = $cfg;
        }

        $hasControllato = cvAuthTableHasColumn($connection, 'biglietti', 'controllato');
        $hasAttesa = cvAuthTableHasColumn($connection, 'biglietti', 'attesa');
        $hasDataAttesa = cvAuthTableHasColumn($connection, 'biglietti', 'data_attesa');
        $hasCamb = cvAuthTableHasColumn($connection, 'biglietti', 'camb');
        $selectControllato = $hasControllato ? 'b.controllato' : '0';
        $selectAttesa = $hasAttesa ? 'b.attesa' : '0';
        $selectDataAttesa = $hasDataAttesa ? 'b.data_attesa' : "''";
        $selectCamb = $hasCamb ? 'b.camb' : '0';

        $sql = "SELECT
                    b.id_bg,
                    b.codice,
                    b.codice_camb,
                    b.transaction_id,
                    b.txn_id,
                    b.prezzo,
                    b.prz_comm,
                    b.pagato,
                    b.stato,
                    {$selectControllato} AS controllato,
                    {$selectAttesa} AS attesa,
                    {$selectDataAttesa} AS data_attesa,
                    {$selectCamb} AS camb,
                    b.id_az,
                    b.id_linea,
                    b.id_corsa,
                    b.id_sott1,
                    b.id_sott2,
                    b.id_mz,
                    b.mz_dt,
                    b.posto,
                    b.type,
                    b.data AS departure_at,
                    b.data2 AS arrival_at,
                    b.acquistato,
                    b.note,
                    a.code AS provider_code,
                    a.nome AS provider_name,
                    s1.nome AS from_name,
                    s2.nome AS to_name
                FROM biglietti AS b
                LEFT JOIN aziende AS a ON a.id_az = b.id_az
                LEFT JOIN tratte_sottoc AS s1 ON s1.id_sott = b.id_sott1
                LEFT JOIN tratte_sottoc AS s2 ON s2.id_sott = b.id_sott2
                WHERE b.id_vg = ?
                ORDER BY b.acquistato DESC, b.id_bg DESC
                LIMIT 300";

        $statement = $connection->prepare($sql);
        if (!$statement instanceof mysqli_stmt) {
            throw new RuntimeException('prepare_failed');
        }

        $statement->bind_param('i', $userId);
        if (!$statement->execute()) {
            $statement->close();
            throw new RuntimeException('execute_failed');
        }

        $result = $statement->get_result();
        if (!$result instanceof mysqli_result) {
            $statement->close();
            cvAuthResponse(true, 'Nessun dato disponibile.', ['tickets' => [], 'count' => 0], 'OK', 200);
        }

        $tickets = [];
        while ($row = $result->fetch_assoc()) {
            if (!is_array($row)) {
                continue;
            }
            $tickets[] = cvAuthMapTicketPayload($row, $providerConfigs);
        }

        $result->free();
        $statement->close();

        cvAuthResponse(true, 'Biglietti caricati.', [
            'tickets' => $tickets,
            'count' => count($tickets),
        ]);
    } catch (Throwable $exception) {
        // Fallback ultra-compatibile: evita 500 in presenza di schema parziale.
        $fallbackSql = "SELECT
                            b.id_bg,
                            b.codice,
                            b.codice_camb,
                            b.transaction_id,
                            b.txn_id,
                            b.prezzo,
                            b.prz_comm,
                            b.pagato,
                            b.stato,
                            b.id_az,
                            b.id_linea,
                            b.id_corsa,
                            b.id_mz,
                            b.mz_dt,
                            b.posto,
                            b.type,
                            b.data AS departure_at,
                            b.data2 AS arrival_at,
                            b.acquistato,
                            b.note
                        FROM biglietti AS b
                        WHERE b.id_vg = ?
                        ORDER BY b.acquistato DESC, b.id_bg DESC
                        LIMIT 300";
        $statement = $connection->prepare($fallbackSql);
        if (!$statement instanceof mysqli_stmt) {
            cvAuthResponse(false, 'Errore caricamento biglietti.', [], 'TICKETS_LOAD_ERROR', 500);
        }

        $statement->bind_param('i', $userId);
        if (!$statement->execute()) {
            $statement->close();
            cvAuthResponse(false, 'Errore caricamento biglietti.', [], 'TICKETS_LOAD_ERROR', 500);
        }

        $result = $statement->get_result();
        if (!$result instanceof mysqli_result) {
            $statement->close();
            cvAuthResponse(true, 'Nessun dato disponibile.', ['tickets' => [], 'count' => 0], 'OK', 200);
        }

        $tickets = [];
        while ($row = $result->fetch_assoc()) {
            if (!is_array($row)) {
                continue;
            }
            $tickets[] = cvAuthMapTicketPayload($row, []);
        }

        $result->free();
        $statement->close();

        cvAuthResponse(true, 'Biglietti caricati.', [
            'tickets' => $tickets,
            'count' => count($tickets),
        ]);
    }
}

function cvAuthHandlePublicTicketLookup(mysqli $connection): void
{
    $payload = cvAuthRequestData();
    $rawCode = '';
    if (isset($_GET['code'])) {
        $rawCode = (string) $_GET['code'];
    } elseif (isset($_POST['code'])) {
        $rawCode = (string) $_POST['code'];
    } elseif (isset($payload['code'])) {
        $rawCode = (string) $payload['code'];
    } elseif (isset($payload['ticket_code'])) {
        $rawCode = (string) $payload['ticket_code'];
    }

    $ticketCode = strtoupper(trim($rawCode));
    if ($ticketCode === '' || preg_match('/^[A-Z0-9_.:-]{3,80}$/', $ticketCode) !== 1) {
        cvAuthResponse(false, 'Inserisci un codice biglietto valido.', [], 'VALIDATION_ERROR', 422);
    }

    $hasControllato = cvAuthTableHasColumn($connection, 'biglietti', 'controllato');
    $hasAttesa = cvAuthTableHasColumn($connection, 'biglietti', 'attesa');
    $hasDataAttesa = cvAuthTableHasColumn($connection, 'biglietti', 'data_attesa');
    $hasCamb = cvAuthTableHasColumn($connection, 'biglietti', 'camb');
    $selectControllato = $hasControllato ? 'b.controllato' : '0';
    $selectAttesa = $hasAttesa ? 'b.attesa' : '0';
    $selectDataAttesa = $hasDataAttesa ? 'b.data_attesa' : "''";
    $selectCamb = $hasCamb ? 'b.camb' : '0';

    $singleSql = "SELECT
                    b.id_bg,
                    b.codice,
                    b.codice_camb,
                    b.transaction_id,
                    b.txn_id,
                    b.prezzo,
                    b.prz_comm,
                    b.pagato,
                    b.stato,
                    {$selectControllato} AS controllato,
                    {$selectAttesa} AS attesa,
                    {$selectDataAttesa} AS data_attesa,
                    {$selectCamb} AS camb,
                    b.id_az,
                    b.id_linea,
                    b.id_corsa,
                    b.id_sott1,
                    b.id_sott2,
                    b.id_mz,
                    b.mz_dt,
                    b.posto,
                    b.type,
                    b.data AS departure_at,
                    b.data2 AS arrival_at,
                    b.acquistato,
                    b.note,
                    a.code AS provider_code,
                    a.nome AS provider_name,
                    s1.nome AS from_name,
                    s2.nome AS to_name
                FROM biglietti AS b
                LEFT JOIN aziende AS a ON a.id_az = b.id_az
                LEFT JOIN tratte_sottoc AS s1 ON s1.id_sott = b.id_sott1
                LEFT JOIN tratte_sottoc AS s2 ON s2.id_sott = b.id_sott2
                WHERE b.codice = ?
                ORDER BY b.id_bg DESC
                LIMIT 1";

    $singleStmt = $connection->prepare($singleSql);
    if (!$singleStmt instanceof mysqli_stmt) {
        cvAuthResponse(false, 'Errore lookup biglietto.', [], 'LOOKUP_ERROR', 500);
    }
    $singleStmt->bind_param('s', $ticketCode);
    if (!$singleStmt->execute()) {
        $singleStmt->close();
        cvAuthResponse(false, 'Errore lookup biglietto.', [], 'LOOKUP_ERROR', 500);
    }
    $singleResult = $singleStmt->get_result();
    $singleRow = $singleResult instanceof mysqli_result ? $singleResult->fetch_assoc() : null;
    if ($singleResult instanceof mysqli_result) {
        $singleResult->free();
    }
    $singleStmt->close();

    if (!is_array($singleRow)) {
        cvAuthResponse(false, 'Biglietto non trovato.', [], 'TICKET_NOT_FOUND', 404);
    }

    $note = (string) ($singleRow['note'] ?? '');
    $orderCode = '';
    if ($note !== '' && preg_match('/order:([^;\\s]+)/i', $note, $match) === 1) {
        $orderCode = strtoupper(trim((string) ($match[1] ?? '')));
    }

    $providerConfigsRaw = cvProviderConfigs($connection);
    $providerConfigs = [];
    foreach ($providerConfigsRaw as $cfgCode => $cfg) {
        if (!is_array($cfg)) {
            continue;
        }
        $normalizedCfgCode = strtolower(trim((string) $cfgCode));
        if ($normalizedCfgCode === '') {
            continue;
        }
        $providerConfigs[$normalizedCfgCode] = $cfg;
    }

    $tickets = [];
    if ($orderCode !== '') {
        $orderToken = '%order:' . $orderCode . '%';
        $orderSql = "SELECT
                        b.id_bg,
                        b.codice,
                        b.codice_camb,
                        b.transaction_id,
                        b.txn_id,
                        b.prezzo,
                        b.prz_comm,
                        b.pagato,
                        b.stato,
                        {$selectControllato} AS controllato,
                        {$selectAttesa} AS attesa,
                        {$selectDataAttesa} AS data_attesa,
                        {$selectCamb} AS camb,
                        b.id_az,
                        b.id_linea,
                        b.id_corsa,
                        b.id_sott1,
                        b.id_sott2,
                        b.id_mz,
                        b.mz_dt,
                        b.posto,
                        b.type,
                        b.data AS departure_at,
                        b.data2 AS arrival_at,
                        b.acquistato,
                        b.note,
                        a.code AS provider_code,
                        a.nome AS provider_name,
                        s1.nome AS from_name,
                        s2.nome AS to_name
                    FROM biglietti AS b
                    LEFT JOIN aziende AS a ON a.id_az = b.id_az
                    LEFT JOIN tratte_sottoc AS s1 ON s1.id_sott = b.id_sott1
                    LEFT JOIN tratte_sottoc AS s2 ON s2.id_sott = b.id_sott2
                    WHERE b.note LIKE ?
                    ORDER BY b.acquistato DESC, b.id_bg DESC
                    LIMIT 120";
        $orderStmt = $connection->prepare($orderSql);
        if ($orderStmt instanceof mysqli_stmt) {
            $orderStmt->bind_param('s', $orderToken);
            if ($orderStmt->execute()) {
                $orderResult = $orderStmt->get_result();
                if ($orderResult instanceof mysqli_result) {
                    while ($row = $orderResult->fetch_assoc()) {
                        if (!is_array($row)) {
                            continue;
                        }
                        $tickets[] = cvAuthMapTicketPayload($row, $providerConfigs);
                    }
                    $orderResult->free();
                }
            }
            $orderStmt->close();
        }
    }

    if (count($tickets) === 0) {
        $tickets[] = cvAuthMapTicketPayload($singleRow, $providerConfigs);
    }

    cvAuthResponse(true, 'Biglietto trovato.', [
        'tickets' => $tickets,
        'count' => count($tickets),
        'lookup_code' => $ticketCode,
        'order_code' => $orderCode,
    ]);
}

function cvAuthHandleTicketPdfDownload(mysqli $connection): void
{
    $payload = cvAuthRequestData();
    $ticketId = 0;
    if (isset($_GET['id']) && is_numeric($_GET['id'])) {
        $ticketId = (int) $_GET['id'];
    } elseif (isset($_POST['id']) && is_numeric($_POST['id'])) {
        $ticketId = (int) $_POST['id'];
    } elseif (isset($payload['id']) && is_numeric($payload['id'])) {
        $ticketId = (int) $payload['id'];
    }

    $rawCode = '';
    if (isset($_GET['ticket_code'])) {
        $rawCode = (string) $_GET['ticket_code'];
    } elseif (isset($_GET['code'])) {
        $rawCode = (string) $_GET['code'];
    } elseif (isset($_POST['ticket_code'])) {
        $rawCode = (string) $_POST['ticket_code'];
    } elseif (isset($_POST['code'])) {
        $rawCode = (string) $_POST['code'];
    } elseif (isset($payload['ticket_code'])) {
        $rawCode = (string) $payload['ticket_code'];
    } elseif (isset($payload['code'])) {
        $rawCode = (string) $payload['code'];
    }
    $ticketCode = strtoupper(trim($rawCode));

    $rawPublic = $_GET['public'] ?? $_POST['public'] ?? ($payload['public'] ?? false);
    $isPublicLookup = filter_var($rawPublic, FILTER_VALIDATE_BOOLEAN);

    if ($isPublicLookup && $ticketCode === '') {
        cvAuthResponse(false, 'Codice biglietto mancante.', [], 'VALIDATION_ERROR', 422);
    }
    if ($ticketId <= 0 && $ticketCode === '') {
        cvAuthResponse(false, 'Indica ID o codice biglietto.', [], 'VALIDATION_ERROR', 422);
    }
    if ($ticketCode !== '' && preg_match('/^[A-Z0-9_.:-]{3,80}$/', $ticketCode) !== 1) {
        cvAuthResponse(false, 'Inserisci un codice biglietto valido.', [], 'VALIDATION_ERROR', 422);
    }

    $userId = cvAuthSessionUserId();
    if (!$isPublicLookup && $userId <= 0) {
        cvAuthResponse(false, 'Utente non autenticato.', [], 'UNAUTHORIZED', 401);
    }

    $hasAcquistato = cvAuthTableHasColumn($connection, 'biglietti', 'acquistato');
    $selectAcquistato = $hasAcquistato ? 'b.acquistato' : "'' AS acquistato";

    $sql = "SELECT
                b.id_bg,
                b.codice,
                b.codice_camb,
                b.transaction_id,
                b.prezzo,
                b.id_mz,
                b.mz_dt,
                b.posto,
                b.data AS departure_at,
                b.data2 AS arrival_at,
                {$selectAcquistato},
                a.code AS provider_code,
                a.nome AS provider_name,
                a.ind,
                a.tel,
                a.email_pg,
                a.pi,
                a.recapiti,
                s1.nome AS from_name,
                s2.nome AS to_name,
                'Passeggero' AS passenger_name
            FROM biglietti AS b
            LEFT JOIN aziende AS a ON a.id_az = b.id_az
            LEFT JOIN tratte_sottoc AS s1 ON s1.id_sott = b.id_sott1
            LEFT JOIN tratte_sottoc AS s2 ON s2.id_sott = b.id_sott2
            WHERE ";

    if ($isPublicLookup) {
        $sql .= 'b.codice = ? ORDER BY b.id_bg DESC LIMIT 1';
    } elseif ($ticketId > 0) {
        $sql .= 'b.id_vg = ? AND b.id_bg = ? LIMIT 1';
    } else {
        $sql .= 'b.id_vg = ? AND b.codice = ? ORDER BY b.id_bg DESC LIMIT 1';
    }

    $statement = $connection->prepare($sql);
    if (!$statement instanceof mysqli_stmt) {
        cvAuthResponse(false, 'Impossibile generare il PDF del biglietto.', [
            'phase' => 'prepare',
            'sql_error' => $connection->error,
        ], 'TICKET_PDF_ERROR', 500);
    }

    if ($isPublicLookup) {
        $statement->bind_param('s', $ticketCode);
    } elseif ($ticketId > 0) {
        $statement->bind_param('ii', $userId, $ticketId);
    } else {
        $statement->bind_param('is', $userId, $ticketCode);
    }

    if (!$statement->execute()) {
        $stmtError = (string) $statement->error;
        $statement->close();
        cvAuthResponse(false, 'Errore recupero biglietto.', [
            'phase' => 'execute',
            'sql_error' => $stmtError,
        ], 'TICKET_PDF_ERROR', 500);
    }

    $result = $statement->get_result();
    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $statement->close();

    if (!is_array($row)) {
        cvAuthResponse(false, 'Biglietto non trovato.', [], 'TICKET_NOT_FOUND', 404);
    }

    $ticket = [
        'id_bg' => (int) ($row['id_bg'] ?? 0),
        'code' => (string) ($row['codice'] ?? ''),
        'change_code' => (string) ($row['codice_camb'] ?? ''),
        'shop_id' => (string) ($row['transaction_id'] ?? ''),
        'price' => (float) ($row['prezzo'] ?? 0),
        'bus_number' => (int) (($row['mz_dt'] ?? 0) ?: ($row['id_mz'] ?? 0)),
        'seat_number' => (int) ($row['posto'] ?? 0),
        'departure_at' => (string) ($row['departure_at'] ?? ''),
        'arrival_at' => (string) ($row['arrival_at'] ?? ''),
        'purchased_at' => (string) ($row['acquistato'] ?? ''),
        'provider_name' => (string) ($row['provider_name'] ?? ''),
        'provider_code' => (string) ($row['provider_code'] ?? ''),
        'from_name' => (string) ($row['from_name'] ?? ''),
        'to_name' => (string) ($row['to_name'] ?? ''),
        'passenger_name' => (string) ($row['passenger_name'] ?? ''),
    ];
    $providerCompany = [
        'nome' => (string) ($row['provider_name'] ?? ''),
        'ind' => (string) ($row['ind'] ?? ''),
        'tel' => (string) ($row['tel'] ?? ''),
        'email_pg' => (string) ($row['email_pg'] ?? ''),
        'pi' => (string) ($row['pi'] ?? ''),
        'recapiti' => (string) ($row['recapiti'] ?? ''),
    ];

    $rawPdf = cvAuthGenerateTicketPdfRaw($ticket, $providerCompany);
    if (!is_string($rawPdf) || $rawPdf === '') {
        cvAuthResponse(false, 'Impossibile generare il PDF del biglietto.', [
            'phase' => 'pdf_generation',
            'details' => cvAuthTicketPdfLastError(),
        ], 'TICKET_PDF_ERROR', 500);
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $fileCode = preg_replace('/[^A-Za-z0-9_.-]/', '_', (string) ($ticket['code'] ?? ''));
    if (!is_string($fileCode) || $fileCode === '') {
        $fileCode = 'ticket';
    }
    $filename = 'biglietto_' . $fileCode . '.pdf';

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . (string) strlen($rawPdf));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    echo $rawPdf;
    exit;
}

function cvAuthIsLikelyTicketCode(string $value): bool
{
    $value = strtoupper(trim($value));
    if ($value === '' || preg_match('/^[A-Z0-9_.:-]{6,80}$/', $value) !== 1) {
        return false;
    }

    if (preg_match('/[0-9]/', $value) !== 1) {
        return false;
    }

    if (preg_match('/^[0-9+().:-]+$/', $value) === 1 && preg_match('/[A-Z]/', $value) !== 1) {
        return false;
    }

    return true;
}

function cvAuthExtractTicketCodeFromText(string $rawValue): string
{
    $rawValue = trim($rawValue);
    if ($rawValue === '') {
        return '';
    }

    $candidates = [$rawValue];
    $decoded = urldecode($rawValue);
    if ($decoded !== $rawValue) {
        $candidates[] = $decoded;
    }

    foreach ($candidates as $candidate) {
        if (preg_match('/(?:ticket_code|code)=([A-Za-z0-9_.:-]{3,80})/i', (string) $candidate, $m) === 1) {
            $value = strtoupper(trim((string) ($m[1] ?? '')));
            if (cvAuthIsLikelyTicketCode($value)) {
                return $value;
            }
        }
    }

    foreach ($candidates as $candidate) {
        $value = strtoupper(trim((string) $candidate));
        if (cvAuthIsLikelyTicketCode($value)) {
            return $value;
        }
    }

    foreach ($candidates as $candidate) {
        if (preg_match_all('/(?<![A-Za-z0-9_.:-])([A-Za-z0-9_.:-]{6,80})(?![A-Za-z0-9_.:-])/', (string) $candidate, $matches) >= 1) {
            foreach (($matches[1] ?? []) as $match) {
                $value = strtoupper(trim((string) $match));
                if (cvAuthIsLikelyTicketCode($value)) {
                    return $value;
                }
            }
        }
    }

    return '';
}

function cvAuthTicketChatIsPersonalTicketRequest(string $message): bool
{
    $normalized = cvAssistantNormalizeText($message);
    if ($normalized === '') {
        return false;
    }

    $markers = [
        'non trovo',
        'ho perso',
        'mio biglietto',
        'il mio biglietto',
        'recuperare un biglietto',
        'recupero biglietto',
        'non ho il codice',
        'non ricordo il codice',
        'recuperare il mio biglietto',
        'ticket acquistato',
        'biglietto acquistato',
    ];
    foreach ($markers as $marker) {
        if (strpos($normalized, cvAssistantNormalizeText($marker)) !== false) {
            return true;
        }
    }

    return false;
}

function cvAuthTicketChatIntentNeedsTicket(string $intent): bool
{
    return in_array($intent, ['ticket_status', 'pdf', 'change', 'contact', 'recover'], true);
}

function cvAuthTicketChatPhoneComparable(string $phone): string
{
    $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';
    if ($digits === '') {
        return '';
    }

    if (strpos($digits, '00') === 0) {
        $digits = substr($digits, 2);
    }

    if (strlen($digits) > 10 && strpos($digits, '39') === 0) {
        $withoutCountryCode = ltrim(substr($digits, 2), '0');
        if ($withoutCountryCode !== '') {
            return $withoutCountryCode;
        }
    }

    return ltrim($digits, '0');
}

/**
 * @return array<string,string>|null
 */
function cvAuthTicketChatExtractIdentityFromText(string $message): ?array
{
    $message = trim($message);
    if ($message === '') {
        return null;
    }

    $parts = preg_split('/\s*;\s*|\s*\n+\s*/', $message) ?: [];
    if (count($parts) >= 3) {
        $name = trim((string) ($parts[0] ?? ''));
        $surname = trim((string) ($parts[1] ?? ''));
        $phone = cvAuthNormalizePhone((string) ($parts[2] ?? ''));
        if ($name !== '' && $surname !== '' && $phone !== '' && $phone !== '-') {
            return [
                'name' => $name,
                'surname' => $surname,
                'phone' => $phone,
            ];
        }
    }

    if (preg_match('/^([^,]+),\s*([^,]+),\s*([0-9+\s().-]{6,30})$/u', $message, $m) === 1) {
        $name = trim((string) ($m[1] ?? ''));
        $surname = trim((string) ($m[2] ?? ''));
        $phone = cvAuthNormalizePhone((string) ($m[3] ?? ''));
        if ($name !== '' && $surname !== '' && $phone !== '' && $phone !== '-') {
            return [
                'name' => $name,
                'surname' => $surname,
                'phone' => $phone,
            ];
        }
    }

    return null;
}

/**
 * @return array<int,array<string,mixed>>
 */
function cvAuthTicketChatFindTicketsByIdentity(mysqli $connection, array $identity): array
{
    $name = trim((string) ($identity['name'] ?? ''));
    $surname = trim((string) ($identity['surname'] ?? ''));
    $phone = cvAuthTicketChatPhoneComparable((string) ($identity['phone'] ?? ''));
    if ($name === '' || $surname === '' || $phone === '') {
        return [];
    }

    $sql = "SELECT
                b.id_bg,
                b.id_vg,
                b.id_vgt,
                b.codice,
                b.data AS departure_at,
                b.data2 AS arrival_at,
                a.code AS provider_code,
                a.nome AS provider_name,
                s1.nome AS from_name,
                s2.nome AS to_name,
                COALESCE(NULLIF(TRIM(vgt.nome), ''), NULLIF(TRIM(vg.nome), ''), '') AS passenger_name,
                COALESCE(NULLIF(TRIM(vgt.cognome), ''), NULLIF(TRIM(vg.cognome), ''), '') AS passenger_surname,
                COALESCE(NULLIF(TRIM(vgt.tel), ''), NULLIF(TRIM(vg.tel), ''), '') AS passenger_phone,
                COALESCE(NULLIF(TRIM(vgt.email), ''), NULLIF(TRIM(vg.email), ''), '') AS passenger_email
            FROM biglietti AS b
            LEFT JOIN viaggiatori AS vg ON vg.id_vg = b.id_vg
            LEFT JOIN viaggiatori_temp AS vgt ON vgt.id_vgt = b.id_vgt
            LEFT JOIN aziende AS a ON a.id_az = b.id_az
            LEFT JOIN tratte_sottoc AS s1 ON s1.id_sott = b.id_sott1
            LEFT JOIN tratte_sottoc AS s2 ON s2.id_sott = b.id_sott2
            WHERE (
                (LOWER(TRIM(vg.nome)) = LOWER(?) AND LOWER(TRIM(vg.cognome)) = LOWER(?))
                OR
                (LOWER(TRIM(vgt.nome)) = LOWER(?) AND LOWER(TRIM(vgt.cognome)) = LOWER(?))
            )
            ORDER BY b.acquistato DESC, b.id_bg DESC
            LIMIT 15";

    $statement = $connection->prepare($sql);
    if (!$statement instanceof mysqli_stmt) {
        return [];
    }

    $statement->bind_param('ssss', $name, $surname, $name, $surname);
    if (!$statement->execute()) {
        $statement->close();
        return [];
    }

    $matches = [];
    $result = $statement->get_result();
    while ($result instanceof mysqli_result && ($row = $result->fetch_assoc())) {
        if (!is_array($row)) {
            continue;
        }
        $rowPhone = cvAuthTicketChatPhoneComparable((string) ($row['passenger_phone'] ?? ''));
        if ($rowPhone === '' || $rowPhone !== $phone) {
            continue;
        }
        $matches[] = $row;
    }
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $statement->close();

    return $matches;
}

/**
 * @param array<int,array<string,mixed>> $matches
 * @return array<string,mixed>
 */
function cvAuthTicketChatReplyForIdentityMatchesDirect(array $settings, array $matches): array
{
    if (count($matches) === 0) {
        return [
            'reply' => 'Non ho trovato un biglietto con i dati che mi hai indicato. Ricontrolla nome, cognome e telefono del viaggiatore e riproviamo.',
            'suggestions' => ['Ho il codice biglietto', 'Come recupero il biglietto?', 'Controlla stato biglietto'],
            'actions' => [],
        ];
    }

    if (count($matches) === 1) {
        $row = $matches[0];
        $ticketCode = strtoupper(trim((string) ($row['codice'] ?? '')));
        $routeText = trim((string) ($row['from_name'] ?? '')) !== '' && trim((string) ($row['to_name'] ?? '')) !== ''
            ? trim((string) ($row['from_name'] ?? '')) . ' -> ' . trim((string) ($row['to_name'] ?? ''))
            : '';
        $departureAtIt = cvAuthFormatDateTimeIt((string) ($row['departure_at'] ?? ''));
        $reply = 'Ho trovato il biglietto associato ai dati indicati. Codice ticket: ' . $ticketCode . '.';
        if ($routeText !== '') {
            $reply .= ' Tratta: ' . $routeText . '.';
        }
        if ($departureAtIt !== '') {
            $reply .= ' Partenza: ' . $departureAtIt . '.';
        }

        return [
            'reply' => $reply,
            'suggestions' => cvAuthTicketChatSuggestions($settings, 'ticket_status', true),
            'actions' => [
                [
                    'type' => 'link',
                    'label' => 'Apri biglietto',
                    'href' => './biglietti.php?code=' . rawurlencode($ticketCode),
                ],
                [
                    'type' => 'link',
                    'label' => 'Scarica PDF',
                    'href' => './auth/api.php?action=ticket_pdf_download&public=1&ticket_code=' . rawurlencode($ticketCode),
                ],
            ],
            'ticket' => [
                'ticket_code' => $ticketCode,
                'provider_code' => strtolower(trim((string) ($row['provider_code'] ?? ''))),
                'provider_name' => (string) ($row['provider_name'] ?? ''),
            ],
        ];
    }

    $lines = ['Ho trovato piu biglietti associati a questi dati. Ti elenco i codici piu recenti:'];
    $actions = [];
    foreach (array_slice($matches, 0, 3) as $row) {
        $ticketCode = strtoupper(trim((string) ($row['codice'] ?? '')));
        if ($ticketCode === '') {
            continue;
        }
        $routeText = trim((string) ($row['from_name'] ?? '')) !== '' && trim((string) ($row['to_name'] ?? '')) !== ''
            ? trim((string) ($row['from_name'] ?? '')) . ' -> ' . trim((string) ($row['to_name'] ?? ''))
            : '';
        $departureAtIt = cvAuthFormatDateTimeIt((string) ($row['departure_at'] ?? ''));
        $line = '- ' . $ticketCode;
        if ($routeText !== '') {
            $line .= ' | ' . $routeText;
        }
        if ($departureAtIt !== '') {
            $line .= ' | ' . $departureAtIt;
        }
        $lines[] = $line;
        $actions[] = [
            'type' => 'link',
            'label' => 'Apri ' . $ticketCode,
            'href' => './biglietti.php?code=' . rawurlencode($ticketCode),
        ];
    }
    $lines[] = 'Se vuoi, incollami uno di questi codici e continuo sulla singola prenotazione.';

    return [
        'reply' => implode("\n", $lines),
        'suggestions' => ['Controlla stato biglietto', 'Scarica PDF', 'Cambio biglietto'],
        'actions' => $actions,
    ];
}

/**
 * @param array<int,array<string,mixed>> $matches
 * @return array<string,mixed>
 */
function cvAuthTicketChatReplyForIdentityMatches(
    array $settings,
    array $matches,
    ?mysqli $connection = null,
    string $sessionKey = '',
    int $sessionUserId = 0
): array {
    if (count($matches) === 0) {
        $suggestions = ['Ho il codice biglietto', 'Come recupero il biglietto?', 'Controlla stato biglietto'];
        if (!empty($settings['ticketing_enabled'])) {
            $suggestions[2] = 'Apri ticket assistenza';
        }

        return [
            'reply' => 'Non ho trovato un biglietto con i dati che mi hai indicato. Ricontrolla nome, cognome e telefono del viaggiatore e riproviamo.',
            'suggestions' => $suggestions,
            'actions' => [],
        ];
    }

    $allOwnedByUser = $sessionUserId > 0;
    foreach ($matches as $match) {
        if (!is_array($match) || (int) ($match['id_vg'] ?? 0) !== $sessionUserId) {
            $allOwnedByUser = false;
            break;
        }
    }
    if ($allOwnedByUser) {
        return cvAuthTicketChatReplyForIdentityMatchesDirect($settings, $matches);
    }

    $validEmails = [];
    foreach ($matches as $match) {
        if (!is_array($match)) {
            continue;
        }
        $email = cvAuthNormalizeEmail((string) ($match['passenger_email'] ?? ''));
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $validEmails[$email] = $email;
        }
    }

    $suggestions = ['Ho il codice biglietto', 'Come recupero il biglietto?'];
    if (!empty($settings['ticketing_enabled'])) {
        $suggestions[] = 'Apri ticket assistenza';
    } else {
        $suggestions[] = 'Posso acquistare da ospite?';
    }

    if (
        !empty($settings['recovery_email_enabled'])
        && $connection instanceof mysqli
        && count($validEmails) === 1
        && cvAssistantNormalizeSessionKey($sessionKey) !== ''
    ) {
        $email = (string) array_values($validEmails)[0];
        $safeName = trim((string) ($matches[0]['passenger_name'] ?? ''));
        $token = cvAuthIssueTicketRecoveryRequest($connection, $sessionKey, $email, $matches);
        if ($token !== null && cvAuthSendTicketRecoveryEmail($connection, $email, $safeName, $token, $matches)) {
            $maskedEmail = cvAuthMaskEmail($email);
            $reply = count($matches) > 1
                ? 'Ho trovato piu prenotazioni compatibili. Per sicurezza non le mostro in chat: ti ho inviato il recupero a ' . $maskedEmail . '.'
                : 'Ho trovato una prenotazione compatibile. Per sicurezza non mostro il codice in chat: ti ho inviato il recupero a ' . $maskedEmail . '.';
            $reply .= ' Apri il link ricevuto via email per visualizzare il biglietto. Se non sei stato tu a richiederlo, ignora il messaggio.';

            return [
                'reply' => $reply,
                'suggestions' => $suggestions,
                'actions' => [],
                'recovery' => [
                    'channel' => 'email',
                    'masked_email' => $maskedEmail,
                    'ticket_count' => count($matches),
                ],
            ];
        }

        return [
            'reply' => 'Ho trovato la prenotazione ma al momento non riesco a inviare la conferma email di recupero. Riprova tra poco oppure apri un ticket assistenza.',
            'suggestions' => $suggestions,
            'actions' => [],
        ];
    }

    $reply = count($matches) > 1
        ? 'Ho trovato piu prenotazioni compatibili, ma per sicurezza non mostro codici ticket in chat senza una conferma su email.'
        : 'Ho trovato una prenotazione compatibile, ma per sicurezza non mostro il codice ticket in chat senza una conferma su email.';
    if (count($validEmails) === 0) {
        $reply .= ' Su questa prenotazione non risulta un indirizzo email utile per il recupero automatico.';
    }
    if (!empty($settings['ticketing_enabled'])) {
        $reply .= ' Se vuoi, posso aprire subito un ticket assistenza.';
    }

    return [
        'reply' => $reply,
        'suggestions' => $suggestions,
        'actions' => [],
    ];
}

function cvAuthTicketChatTechnicalPrompt(): string
{
    return 'Posso aiutarti a verificare o recuperare il biglietto. Se hai il codice ticket o il QR, incollalo qui. Se non lo hai, scrivimi nome, cognome e telefono del viaggiatore: se trovo la prenotazione, per sicurezza invio il recupero all’email associata al viaggio.';
}

function cvAuthTicketChatChangeSupportPrompt(bool $ticketingEnabled = false): string
{
    $reply = 'Ti aiuto subito con il cambio biglietto.';
    $reply .= ' Se hai il codice ticket o il QR, incollalo qui e controllo lo stato reale del cambio.';
    $reply .= ' Se non hai il codice, scrivimi nome, cognome e telefono del viaggiatore per avviare il recupero sicuro via email.';
    $reply .= ' Se invece hai appena avviato un cambio e vedi un blocco, completa il checkout già aperto oppure riprova dopo circa 5 minuti.';
    if ($ticketingEnabled) {
        $reply .= ' Se il problema resta, apro io un ticket assistenza.';
    }
    return $reply;
}

function cvAuthTicketChatNormalizeIdentityField(string $field, string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    if ($field === 'phone') {
        $value = cvAuthNormalizePhone($value);
        return ($value !== '' && $value !== '-') ? $value : '';
    }

    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    if ($value === '') {
        return '';
    }

    return preg_match("/^[\p{L} .'-]{2,80}$/u", $value) === 1 ? $value : '';
}

/**
 * @param array<string,string> $draft
 */
function cvAuthTicketChatIdentityPrompt(string $field, array $draft = []): string
{
    if ($field === 'surname') {
        $name = trim((string) ($draft['name'] ?? ''));
        return $name !== ''
            ? 'Perfetto. Ora indicami il cognome del viaggiatore ' . $name . '.'
            : 'Perfetto. Ora indicami il cognome del viaggiatore.';
    }

    if ($field === 'phone') {
        $fullName = trim(
            trim((string) ($draft['name'] ?? '')) . ' ' . trim((string) ($draft['surname'] ?? ''))
        );
        return $fullName !== ''
            ? 'Perfetto. Ora indicami il numero di telefono associato al viaggiatore ' . $fullName . '. Puoi inserirlo con o senza prefisso internazionale.'
            : 'Perfetto. Ora indicami il numero di telefono associato al viaggiatore. Puoi inserirlo con o senza prefisso internazionale.';
    }

    return 'Per recuperare il biglietto senza codice, indicami il nome del viaggiatore.';
}

function cvAuthTicketChatIdentityErrorPrompt(string $field): string
{
    if ($field === 'surname') {
        return 'Il cognome non e valido. Scrivimi solo il cognome del viaggiatore.';
    }

    if ($field === 'phone') {
        return 'Il numero di telefono non e valido. Scrivimi il telefono del viaggiatore con o senza prefisso, solo numero.';
    }

    return 'Il nome non e valido. Scrivimi solo il nome del viaggiatore.';
}

function cvAuthTicketChatSupportTicketPrompt(string $field, array $draft = []): string
{
    if ($field === 'email') {
        $name = trim((string) ($draft['name'] ?? ''));
        return $name !== ''
            ? 'Perfetto ' . $name . '. Ora indicami l’email a cui vuoi ricevere gli aggiornamenti del ticket assistenza.'
            : 'Perfetto. Ora indicami l’email a cui vuoi ricevere gli aggiornamenti del ticket assistenza.';
    }

    if ($field === 'phone') {
        return 'Indica anche un numero di telefono di contatto.';
    }

    if ($field === 'message') {
        return 'Descrivimi il problema in modo sintetico, cosi apro il ticket per l’assistenza manuale.';
    }

    return 'Posso aprire un ticket assistenza. Indicami il tuo nome e cognome.';
}

function cvAuthTicketChatSupportTicketErrorPrompt(string $field): string
{
    if ($field === 'email') {
        return 'L’email non e valida. Scrivimi un indirizzo email corretto.';
    }
    if ($field === 'phone') {
        return 'Il telefono non e valido. Scrivimi solo il numero di contatto.';
    }
    if ($field === 'message') {
        return 'Descrivi brevemente il problema per poter aprire il ticket.';
    }
    return 'Il nome non e valido. Scrivimi nome e cognome.';
}

function cvAuthTicketChatNormalizeSupportTicketField(string $field, string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    if ($field === 'email') {
        $value = cvAuthNormalizeEmail($value);
        return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : '';
    }

    if ($field === 'phone') {
        $value = cvAuthNormalizePhone($value);
        return ($value !== '' && $value !== '-') ? $value : '';
    }

    if ($field === 'message') {
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        return $length >= 10 ? $value : '';
    }

    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    return preg_match("/^[\p{L} .'-]{4,120}$/u", $value) === 1 ? $value : '';
}

function cvAuthExtractProviderPhone(string $tel, string $recapiti): string
{
    $tel = trim($tel);
    if ($tel !== '' && $tel !== '-' && $tel !== '0') {
        return preg_replace('/\s+/', ' ', $tel) ?? $tel;
    }

    $recapiti = trim($recapiti);
    if ($recapiti !== '' && preg_match('/(\+?\d[\d\s().\/-]{5,}\d)/', $recapiti, $m) === 1) {
        $phone = trim((string) ($m[1] ?? ''));
        if ($phone !== '') {
            return preg_replace('/\s+/', ' ', $phone) ?? $phone;
        }
    }

    return '';
}

function cvAuthTicketStatusSummary(array $ticket): array
{
    $paid = (int) ($ticket['pagato'] ?? 0) === 1;
    $active = (int) ($ticket['stato'] ?? 0) === 1;
    $checked = (int) ($ticket['controllato'] ?? 0) === 1;
    $pendingChange = (int) ($ticket['attesa'] ?? 0) === 1;
    $departureRaw = trim((string) ($ticket['departure_at'] ?? ''));
    $departureTs = $departureRaw !== '' ? strtotime($departureRaw) : false;
    $pastDeparture = is_int($departureTs) && $departureTs > 0 && $departureTs < time();

    $statusCode = 'confirmed';
    $statusLabel = 'Biglietto confermato';
    if (!$paid) {
        $statusCode = 'payment_pending';
        $statusLabel = 'Pagamento non completato';
    } elseif (!$active) {
        $statusCode = 'inactive';
        $statusLabel = 'Biglietto non attivo';
    } elseif ($checked) {
        $statusCode = 'used';
        $statusLabel = 'Biglietto gia controllato';
    } elseif ($pendingChange) {
        $statusCode = 'change_pending';
        $statusLabel = 'Cambio in attesa pagamento';
    } elseif ($pastDeparture) {
        $statusCode = 'departed';
        $statusLabel = 'Partenza gia trascorsa';
    }

    return [
        'code' => $statusCode,
        'label' => $statusLabel,
        'is_problem' => $statusCode !== 'confirmed' && $statusCode !== 'departed',
    ];
}

function cvAuthFormatDateTimeIt(string $rawValue): string
{
    $rawValue = trim($rawValue);
    if ($rawValue === '') {
        return '';
    }
    $timestamp = strtotime($rawValue);
    if (!is_int($timestamp) || $timestamp <= 0) {
        return '';
    }
    return date('d/m/Y H:i', $timestamp);
}

function cvAuthTicketChatSessionKey(string $rawValue = ''): string
{
    $normalized = cvAssistantNormalizeSessionKey($rawValue);
    if ($normalized !== '') {
        return $normalized;
    }

    try {
        return substr(strtolower(bin2hex(random_bytes(20))), 0, 40);
    } catch (Throwable $exception) {
        return substr(hash('sha256', uniqid('cvchat', true)), 0, 40);
    }
}

function cvAuthTicketChatIntent(string $message, bool $hasTicketContext = false): string
{
    if (trim($message) === '[operator]') {
        return 'support_ticket';
    }

    $normalized = cvAssistantNormalizeText($message);
    if ($normalized === '') {
        return $hasTicketContext ? 'ticket_status' : 'greeting';
    }

    $map = [
        'recover' => ['recupera', 'recuperare', 'recupero', 'perso', 'persa', 'codice ticket', 'codice biglietto'],
        'stops' => ['fermata', 'fermate', 'vicino', 'vicina', 'vicine', 'intorno', 'nei pressi'],
        'route' => ['orario', 'orari', 'autobus', 'bus', 'pullman', 'tratta', 'tratte', 'partenza', 'arrivo', 'aeroporto', 'andare', 'diretto', 'diretta', 'arrivare'],
        'change' => ['cambio', 'cambiare', 'modifica', 'modificare', 'spostare', 'data', 'corsa'],
        'pdf' => ['pdf', 'scarica', 'download', 'stampa'],
        'contact' => ['contatto', 'telefono', 'numero', 'assistenza', 'supporto', 'provider', 'azienda'],
        'support_ticket' => ['ticket assistenza', 'apri ticket', 'operatore', 'segnalazione', 'supporto umano', 'non hai risolto', 'non ho risolto'],
        'faq' => ['faq', 'domande frequenti', 'come funziona', 'informazioni', 'info'],
        'greeting' => ['ciao', 'salve', 'buongiorno', 'buonasera', 'aiuto', 'help'],
        'cancel' => ['annulla', 'stop', 'reset', 'riparti', 'interrompi'],
        'ticket_status' => ['stato', 'biglietto', 'ticket', 'verifica', 'verificare', 'controlla', 'problema'],
    ];

    foreach ($map as $intent => $keywords) {
        foreach ($keywords as $keyword) {
            if (strpos($normalized, cvAssistantNormalizeText($keyword)) !== false) {
                if ($intent === 'greeting') {
                    $words = preg_split('/\s+/u', trim($normalized)) ?: [];
                    if (count($words) > 3) {
                        continue;
                    }
                }
                return $intent;
            }
        }
    }

    return $hasTicketContext ? 'ticket_status' : 'generic';
}

function cvAuthTicketChatRouteFollowupType(string $message): string
{
    $normalized = cvAssistantNormalizeText($message);
    if ($normalized === '') {
        return '';
    }

    // Prioritize explicit discount/promo questions.
    foreach (['sconto', 'sconti', 'promo', 'promoz', 'offerta', 'coupon', 'codice sconto'] as $needle) {
        if (strpos($normalized, cvAssistantNormalizeText($needle)) !== false) {
            return 'discounts';
        }
    }

    // Cheapest.
    foreach (['piu econom', 'meno caro', 'prezzo piu basso', 'costa meno', 'piu basso'] as $needle) {
        if (strpos($normalized, $needle) !== false) {
            return 'cheapest';
        }
    }

    // Earliest arrival.
    if (
        strpos($normalized, 'arriva prima') !== false
        || strpos($normalized, 'arrivare prima') !== false
        || strpos($normalized, 'arrivo prima') !== false
        || strpos($normalized, 'che arriva prima') !== false
        || strpos($normalized, 'arriva presto') !== false
    ) {
        return 'earliest';
    }

    // Fastest duration.
    foreach (['piu velo', 'piu rapid', 'durata min', 'il piu velo', 'il piu rapid'] as $needle) {
        if (strpos($normalized, $needle) !== false) {
            return 'fastest';
        }
    }

    // Short prompts like "veloce" / "economico".
    if (preg_match('/\\bveloc[ei]\\b/u', $normalized) === 1) {
        return 'fastest';
    }
    if (preg_match('/\\beconomic[oa]\\b/u', $normalized) === 1) {
        return 'cheapest';
    }

    return '';
}

function cvAuthTicketChatDebugLog(string $stage, array $payload = []): void
{
    $line = '[cv-chat-operator][' . $stage . '] ';
    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded) || $encoded === '') {
        $encoded = '{}';
    }

    $dir = dirname(__DIR__) . '/files/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $written = false;
    if (is_dir($dir) && is_writable($dir)) {
        $written = @file_put_contents($dir . '/auth_chat_debug.log', $line . $encoded . PHP_EOL, FILE_APPEND | LOCK_EX) !== false;
    }

    if (!$written) {
        error_log($line . $encoded);
    }
}

/**
 * @return array<int,array<string,string>>
 */
function cvAuthTicketChatActionsForTicket(array $ticket, string $intent = 'ticket_status'): array
{
    $ticketCode = strtoupper(trim((string) ($ticket['codice'] ?? '')));
    if ($ticketCode === '') {
        return [];
    }

    $actions = [];
    $actions[] = [
        'type' => 'link',
        'label' => 'Apri biglietto',
        'href' => './biglietti.php?code=' . rawurlencode($ticketCode),
    ];

    if ($intent === 'pdf' || $intent === 'ticket_status' || $intent === 'generic') {
        $actions[] = [
            'type' => 'link',
            'label' => 'Scarica PDF',
            'href' => './auth/api.php?action=ticket_pdf_download&public=1&ticket_code=' . rawurlencode($ticketCode),
        ];
    }

    $providerPhone = cvAuthExtractProviderPhone(
        (string) ($ticket['provider_phone'] ?? ''),
        (string) ($ticket['provider_contacts'] ?? '')
    );
    if ($providerPhone !== '' && ($intent === 'contact' || (bool) (cvAuthTicketStatusSummary($ticket)['is_problem'] ?? false))) {
        $actions[] = [
            'type' => 'link',
            'label' => 'Chiama provider',
            'href' => 'tel:' . preg_replace('/[^0-9+]/', '', $providerPhone),
        ];
    }

    return $actions;
}

/**
 * @return array<int,string>
 */
function cvAuthTicketChatSuggestions(array $settings, string $intent = 'greeting', bool $hasTicket = false): array
{
    $base = cvAssistantQuickReplies($settings);
    $values = [];

    if ($hasTicket) {
        foreach (['Controlla stato biglietto', 'Scarica PDF', 'Cambio biglietto', 'Contatto provider'] as $value) {
            $values[$value] = $value;
        }
    } elseif ($intent === 'stops') {
        foreach (['Fermate intorno a Roma', 'Fermate intorno a Milano', 'Cerca una tratta'] as $value) {
            $values[$value] = $value;
        }
    } elseif ($intent === 'route') {
        foreach (['Oggi', 'Domani', 'Cerca un altra tratta'] as $value) {
            $values[$value] = $value;
        }
    } elseif ($intent === 'faq') {
        foreach (['Come recupero il biglietto?', 'Posso acquistare da ospite?', 'Come cambio il biglietto?', 'Come funziona il recupero senza codice?'] as $value) {
            $values[$value] = $value;
        }
    } elseif ($intent === 'support_ticket') {
        foreach (['Apri ticket assistenza', 'Come recupero il biglietto?', 'Posso acquistare da ospite?'] as $value) {
            $values[$value] = $value;
        }
    } elseif ($intent === 'recover' || $intent === 'ticket_status' || $intent === 'contact' || $intent === 'change' || $intent === 'pdf') {
        foreach (['Ho il codice biglietto', 'Non ho il codice', 'Come recupero il biglietto?'] as $value) {
            $values[$value] = $value;
        }
    } else {
        foreach ($base as $value) {
            $values[$value] = $value;
        }
    }

    return array_values($values);
}

function cvAuthTicketChatEncodeStopRef(string $rawRef): string
{
    $rawRef = trim($rawRef);
    if ($rawRef === '') {
        return '';
    }

    $encoded = rtrim(strtr(base64_encode($rawRef), '+/', '-_'), '=');
    return $encoded !== '' ? ('r~' . $encoded) : $rawRef;
}

function cvAuthTicketChatBuildSolutionsUrl(string $fromRef, string $toRef, string $dateIt = '', int $adults = 1, int $children = 0): string
{
    $params = [
        'part' => cvAuthTicketChatEncodeStopRef($fromRef),
        'arr' => cvAuthTicketChatEncodeStopRef($toRef),
        'ad' => (string) max(0, $adults),
        'bam' => (string) max(0, $children),
        'mode' => 'oneway',
    ];

    if (trim($dateIt) !== '') {
        $params['dt1'] = trim($dateIt);
    }

    return './soluzioni.php?' . http_build_query($params);
}

function cvAuthTicketChatDurationLabel(int $minutes): string
{
    $minutes = max(0, $minutes);
    $hours = intdiv($minutes, 60);
    $rest = $minutes % 60;
    if ($hours <= 0) {
        return $rest . ' min';
    }

    return $hours . 'h ' . str_pad((string) $rest, 2, '0', STR_PAD_LEFT) . 'm';
}

function cvAuthTicketChatLooksLikeRouteRequest(string $message): bool
{
    $normalized = cvAssistantNormalizeText($message);
    if ($normalized === '') {
        return false;
    }

    if (preg_match('/\bda\s+.+\b(?:a|per|verso)\s+.+/u', $normalized) === 1) {
        return true;
    }

    if (preg_match('/\b(?:devo|vorrei|voglio|sono|sto)\s+(?:andare|arrivare|dirigermi|dirett[oa])\s+(?:a|ad|per|verso)\b/u', $normalized) === 1) {
        return true;
    }

    if (preg_match('/\b(?:pullman|autobus|bus)\b.{0,40}\b(?:a|ad|per|verso)\b/u', $normalized) === 1) {
        return true;
    }

    foreach (['orario', 'orari', 'autobus', 'bus', 'pullman', 'tratta', 'tratte', 'partenza', 'arrivo', 'aeroporto', 'andare', 'diretto', 'diretta', 'arrivare'] as $keyword) {
        if (strpos($normalized, $keyword) !== false) {
            return true;
        }
    }

    return false;
}

function cvAuthTicketChatParseTravelDate(string $message): string
{
    $trimmed = trim($message);
    if ($trimmed === '') {
        return '';
    }

    $timezone = new DateTimeZone('Europe/Rome');
    $today = new DateTimeImmutable('today', $timezone);
    $normalized = cvAssistantNormalizeText($trimmed);

    if (preg_match('/\bdopodomani\b/u', $normalized) === 1) {
        return $today->modify('+2 day')->format('d/m/Y');
    }
    if (preg_match('/\bdomani\b/u', $normalized) === 1) {
        return $today->modify('+1 day')->format('d/m/Y');
    }
    if (preg_match('/\boggi\b/u', $normalized) === 1) {
        return $today->format('d/m/Y');
    }

    $monthMap = [
        'gennaio' => 1,
        'gen' => 1,
        'febbraio' => 2,
        'feb' => 2,
        'marzo' => 3,
        'mar' => 3,
        'aprile' => 4,
        'apr' => 4,
        'maggio' => 5,
        'mag' => 5,
        'giugno' => 6,
        'giu' => 6,
        'luglio' => 7,
        'lug' => 7,
        'agosto' => 8,
        'ago' => 8,
        'settembre' => 9,
        'sett' => 9,
        'set' => 9,
        'ottobre' => 10,
        'ott' => 10,
        'novembre' => 11,
        'nov' => 11,
        'dicembre' => 12,
        'dic' => 12,
    ];
    $monthKeys = array_keys($monthMap);
    usort($monthKeys, static function (string $left, string $right): int {
        return strlen($right) <=> strlen($left);
    });
    $monthPattern = implode('|', array_map('preg_quote', $monthKeys));

    if (preg_match('/\b(\d{1,2})\s+(?:di\s+)?(' . $monthPattern . ')\b(?:\s+(\d{2,4}))?/u', $normalized, $matches) === 1) {
        $day = (int) ($matches[1] ?? 0);
        $month = (int) ($monthMap[$matches[2]] ?? 0);
        $yearRaw = isset($matches[3]) ? trim((string) $matches[3]) : '';
        $year = (int) $today->format('Y');
        if ($yearRaw !== '') {
            $year = strlen($yearRaw) === 2 ? (2000 + (int) $yearRaw) : (int) $yearRaw;
        }
        if (checkdate($month, $day, $year)) {
            $candidate = DateTimeImmutable::createFromFormat('!d/m/Y', sprintf('%02d/%02d/%04d', $day, $month, $year), $timezone);
            if ($candidate instanceof DateTimeImmutable) {
                if ($yearRaw === '' && $candidate < $today) {
                    $candidate = $candidate->modify('+1 year');
                }
                return $candidate->format('d/m/Y');
            }
        }
    }

    if (preg_match('/\b(' . $monthPattern . ')\s+(\d{1,2})\b(?:\s+(\d{2,4}))?/u', $normalized, $matches) === 1) {
        $day = (int) ($matches[2] ?? 0);
        $month = (int) ($monthMap[$matches[1]] ?? 0);
        $yearRaw = isset($matches[3]) ? trim((string) $matches[3]) : '';
        $year = (int) $today->format('Y');
        if ($yearRaw !== '') {
            $year = strlen($yearRaw) === 2 ? (2000 + (int) $yearRaw) : (int) $yearRaw;
        }
        if (checkdate($month, $day, $year)) {
            $candidate = DateTimeImmutable::createFromFormat('!d/m/Y', sprintf('%02d/%02d/%04d', $day, $month, $year), $timezone);
            if ($candidate instanceof DateTimeImmutable) {
                if ($yearRaw === '' && $candidate < $today) {
                    $candidate = $candidate->modify('+1 year');
                }
                return $candidate->format('d/m/Y');
            }
        }
    }

    if (preg_match('/\b(\d{1,2})[\/\.-](\d{1,2})(?:[\/\.-](\d{2,4}))?\b/', $trimmed, $matches) === 1) {
        $day = (int) ($matches[1] ?? 0);
        $month = (int) ($matches[2] ?? 0);
        $yearRaw = isset($matches[3]) ? trim((string) $matches[3]) : '';
        $year = (int) $today->format('Y');

        if ($yearRaw !== '') {
            $year = strlen($yearRaw) === 2 ? (2000 + (int) $yearRaw) : (int) $yearRaw;
        }

        if (!checkdate($month, $day, $year)) {
            return '';
        }

        $candidate = DateTimeImmutable::createFromFormat('!d/m/Y', sprintf('%02d/%02d/%04d', $day, $month, $year), $timezone);
        if (!$candidate instanceof DateTimeImmutable) {
            return '';
        }

        if ($yearRaw === '' && $candidate < $today) {
            $candidate = $candidate->modify('+1 year');
        }

        return $candidate->format('d/m/Y');
    }

    return '';
}

/**
 * @return array<string,mixed>|null
 */
function cvAuthTicketChatExtractNearbyStopsRequest(mysqli $connection, string $message): ?array
{
    $normalizedMessage = cvAssistantNormalizeText($message);
    if ($normalizedMessage === '') {
        return null;
    }

    $hasStopsKeyword = false;
    foreach (['fermata', 'fermate'] as $keyword) {
        if (strpos($normalizedMessage, $keyword) !== false) {
            $hasStopsKeyword = true;
            break;
        }
    }
    if (!$hasStopsKeyword) {
        return null;
    }

    if (
        strpos($normalizedMessage, 'intorno') === false
        && strpos($normalizedMessage, 'vicin') === false
        && strpos($normalizedMessage, 'nei pressi') === false
    ) {
        return null;
    }

    $location = '';
    if (preg_match('/\b(?:intorno\s+a|vicin[oaie]?\s+a|nei\s+pressi\s+di|a|di)\s+([a-z0-9\'\-\s]{2,})$/u', $normalizedMessage, $matches) === 1) {
        $location = trim((string) ($matches[1] ?? ''));
    }

    if ($location === '') {
        return null;
    }

    $location = trim((string) preg_replace('/\s+/', ' ', $location));
    if ($location === '' || strlen(str_replace(' ', '', $location)) < 2) {
        return null;
    }

    $entries = cvAuthTicketChatRouteEntries($connection);
    $matches = [];
    $seen = [];
    foreach ($entries as $entry) {
        $name = trim((string) ($entry['name'] ?? ''));
        $normalizedName = cvAssistantNormalizeText($name);
        if ($name === '' || $normalizedName === '') {
            continue;
        }
        if (strpos($normalizedName, $location) === false) {
            continue;
        }
        if (isset($seen[$normalizedName])) {
            continue;
        }
        $seen[$normalizedName] = true;
        $matches[] = [
            'name' => $name,
            'provider_name' => trim((string) ($entry['provider_name'] ?? '')),
        ];
        if (count($matches) >= 12) {
            break;
        }
    }

    if (count($matches) === 0) {
        return [
            'location' => $location,
            'stops' => [],
        ];
    }

    return [
        'location' => $location,
        'stops' => $matches,
    ];
}

/**
 * @param array<string,mixed> $request
 * @return array<string,mixed>
 */
function cvAuthTicketChatReplyForNearbyStops(array $request): array
{
    $location = trim((string) ($request['location'] ?? ''));
    $stops = isset($request['stops']) && is_array($request['stops']) ? $request['stops'] : [];
    $locationLabel = $location !== '' ? ucfirst($location) : 'la zona richiesta';

    if (count($stops) === 0) {
        return [
            'reply' => 'Non trovo fermate associate a ' . $locationLabel . '. Prova con una localita vicina oppure scrivimi una tratta completa, ad esempio: da Roma a Polla oggi.',
            'suggestions' => ['Cerca una tratta', 'Fermate intorno a Roma', 'Fermate intorno a Milano'],
            'actions' => [],
        ];
    }

    $lines = ['Fermate trovate intorno a ' . $locationLabel . ':'];
    foreach (array_slice($stops, 0, 8) as $idx => $stop) {
        $stopName = trim((string) ($stop['name'] ?? ''));
        if ($stopName === '') {
            continue;
        }
        $providerName = trim((string) ($stop['provider_name'] ?? ''));
        $line = ($idx + 1) . '. ' . $stopName;
        if ($providerName !== '') {
            $line .= ' (' . $providerName . ')';
        }
        $lines[] = $line;
    }

    $lines[] = 'Se vuoi, ora posso cercare la tratta piu veloce: scrivimi ad esempio "da Roma a Polla oggi".';

    return [
        'reply' => implode("\n", $lines),
        'suggestions' => ['Cerca una tratta', 'Da Roma a Polla oggi', 'Domani'],
        'actions' => [],
    ];
}

/**
 * @return array<int,array<string,mixed>>
 */
function cvAuthTicketChatRouteEntries(mysqli $connection): array
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }

    $entries = cvFetchSearchEntries($connection);
    $prepared = [];
    $seenNames = [];

    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $ref = trim((string) ($entry['id'] ?? ''));
        $name = trim((string) ($entry['name'] ?? ''));
        if ($ref === '' || $name === '') {
            continue;
        }

        $normalizedName = cvAssistantNormalizeText($name);
        if ($normalizedName === '' || strlen(str_replace(' ', '', $normalizedName)) < 3) {
            continue;
        }

        if (isset($seenNames[$normalizedName])) {
            continue;
        }
        $seenNames[$normalizedName] = true;

        $prepared[] = [
            'id' => $ref,
            'name' => $name,
            'provider_code' => strtolower(trim((string) ($entry['provider_code'] ?? ''))),
            'provider_name' => trim((string) ($entry['provider_name'] ?? '')),
            'normalized_name' => $normalizedName,
            'lat' => isset($entry['lat']) && is_numeric($entry['lat']) ? (float) $entry['lat'] : null,
            'lon' => isset($entry['lon']) && is_numeric($entry['lon']) ? (float) $entry['lon'] : null,
        ];
    }

    $cache = $prepared;
    return $prepared;
}

/**
 * @return array<string,mixed>|null
 */
function cvAuthTicketChatExtractRouteRequest(mysqli $connection, string $message): ?array
{
    if (!cvAuthTicketChatLooksLikeRouteRequest($message)) {
        return null;
    }

    $normalizedMessage = cvAssistantNormalizeText($message);
    if ($normalizedMessage === '') {
        return null;
    }

    $matches = [];
    foreach (cvAuthTicketChatRouteEntries($connection) as $entry) {
        $normalizedName = (string) ($entry['normalized_name'] ?? '');
        if ($normalizedName === '') {
            continue;
        }

        $position = strpos($normalizedMessage, $normalizedName);
        if ($position === false) {
            continue;
        }

        $prefix = substr($normalizedMessage, max(0, $position - 18), $position - max(0, $position - 18));
        $role = '';
        if (preg_match('/(?:^|\s)da\s*$/u', $prefix) === 1) {
            $role = 'from';
        } elseif (preg_match('/(?:^|\s)(?:a|ad|per|verso)\s*$/u', $prefix) === 1) {
            $role = 'to';
        }

        $candidate = $entry;
        $candidate['position'] = (int) $position;
        $candidate['role'] = $role;
        $candidate['name_length'] = strlen($normalizedName);
        $matches[] = $candidate;
    }

    if (count($matches) === 0) {
        return null;
    }

    usort(
        $matches,
        static function (array $left, array $right): int {
            $posCmp = ((int) ($left['position'] ?? 0)) <=> ((int) ($right['position'] ?? 0));
            if ($posCmp !== 0) {
                return $posCmp;
            }

            return ((int) ($right['name_length'] ?? 0)) <=> ((int) ($left['name_length'] ?? 0));
        }
    );

    $from = null;
    $to = null;
    foreach ($matches as $candidate) {
        if ($from === null && ($candidate['role'] ?? '') === 'from') {
            $from = $candidate;
            continue;
        }
        if ($to === null && ($candidate['role'] ?? '') === 'to') {
            $to = $candidate;
        }
    }

    foreach ($matches as $candidate) {
        if ($from === null) {
            $from = $candidate;
            continue;
        }
        if ($to === null && (string) ($candidate['id'] ?? '') !== (string) ($from['id'] ?? '')) {
            $to = $candidate;
            continue;
        }
    }

    if (!is_array($from) || !is_array($to) || (string) ($from['id'] ?? '') === (string) ($to['id'] ?? '')) {
        return null;
    }

    return [
        'from_ref' => (string) ($from['id'] ?? ''),
        'from_name' => (string) ($from['name'] ?? ''),
        'to_ref' => (string) ($to['id'] ?? ''),
        'to_name' => (string) ($to['name'] ?? ''),
        'date_it' => cvAuthTicketChatParseTravelDate($message),
        'raw_message' => $message,
    ];
}

/**
 * @return array<string,string>|null
 */
function cvAuthTicketChatExtractSingleStopMention(mysqli $connection, string $message): ?array
{
    $normalizedMessage = cvAssistantNormalizeText($message);
    if ($normalizedMessage === '') {
        return null;
    }

    $matches = [];
    foreach (cvAuthTicketChatRouteEntries($connection) as $entry) {
        $normalizedName = (string) ($entry['normalized_name'] ?? '');
        if ($normalizedName === '') {
            continue;
        }

        $position = strpos($normalizedMessage, $normalizedName);
        if ($position === false) {
            continue;
        }

        $prefixStart = max(0, $position - 24);
        $prefix = substr($normalizedMessage, $prefixStart, $position - $prefixStart);
        $role = '';
        if (preg_match('/(?:^|\s)da\s*$/u', $prefix) === 1) {
            $role = 'from';
        } elseif (preg_match('/(?:^|\s)(?:a|ad|per|verso)\s*$/u', $prefix) === 1 || preg_match('/dirett[oa]\s*$/u', $prefix) === 1) {
            $role = 'to';
        }

        $matches[] = [
            'id' => (string) ($entry['id'] ?? ''),
            'name' => (string) ($entry['name'] ?? ''),
            'position' => (int) $position,
            'name_length' => strlen($normalizedName),
            'role' => $role,
        ];
    }

    if (count($matches) === 0) {
        return null;
    }

    usort(
        $matches,
        static function (array $left, array $right): int {
            if ((string) ($left['role'] ?? '') !== (string) ($right['role'] ?? '')) {
                return ((string) ($left['role'] ?? '') === '') ? 1 : -1;
            }
            $posCmp = ((int) ($left['position'] ?? 0)) <=> ((int) ($right['position'] ?? 0));
            if ($posCmp !== 0) {
                return $posCmp;
            }
            return ((int) ($right['name_length'] ?? 0)) <=> ((int) ($left['name_length'] ?? 0));
        }
    );

    $best = $matches[0];
    $bestRole = trim((string) ($best['role'] ?? ''));
    if ($bestRole === '') {
        if (preg_match('/\b(?:a|ad|per|verso)\s+[a-z0-9\'\-\s]{2,}$/u', $normalizedMessage) === 1) {
            $bestRole = 'to';
        } elseif (preg_match('/\bda\s+[a-z0-9\'\-\s]{2,}$/u', $normalizedMessage) === 1) {
            $bestRole = 'from';
        } else {
            $bestRole = 'to';
        }
    }

    return [
        'ref' => (string) ($best['id'] ?? ''),
        'name' => (string) ($best['name'] ?? ''),
        'role' => $bestRole,
    ];
}

/**
 * @return array<string,string>|null
 */
function cvAuthTicketChatExtractRouteTextHint(string $message): ?array
{
    $normalized = cvAssistantNormalizeText($message);
    if ($normalized === '') {
        return null;
    }

    $hasTravelCue = false;
    foreach (['andare', 'arrivare', 'diretto', 'diretta', 'pullman', 'autobus', 'bus', 'tratta', 'orario', 'orari', 'partenza', 'arrivo'] as $cue) {
        if (strpos($normalized, $cue) !== false) {
            $hasTravelCue = true;
            break;
        }
    }

    if (!$hasTravelCue) {
        return null;
    }

    $fromLabel = '';
    $toLabel = '';

    if (preg_match('/\bda\s+([a-z0-9\'\-\s]{2,})\s+(?:a|ad|per|verso)\s+([a-z0-9\'\-\s]{2,})$/u', $normalized, $matches) === 1) {
        $fromLabel = trim((string) ($matches[1] ?? ''));
        $toLabel = trim((string) ($matches[2] ?? ''));
    } elseif (preg_match('/\b(?:a|ad|per|verso)\s+([a-z0-9\'\-\s]{2,})$/u', $normalized, $matches) === 1) {
        $toLabel = trim((string) ($matches[1] ?? ''));
    } elseif (preg_match('/\bda\s+([a-z0-9\'\-\s]{2,})$/u', $normalized, $matches) === 1) {
        $fromLabel = trim((string) ($matches[1] ?? ''));
    }

    if ($fromLabel === '' && $toLabel === '') {
        return null;
    }

    return [
        'from_label' => $fromLabel,
        'to_label' => $toLabel,
    ];
}

/**
 * @return array<string,float>|null
 */
function cvAuthTicketChatExtractGeoPoint(string $message): ?array
{
    $message = trim($message);
    if ($message === '') {
        return null;
    }

    if (preg_match('/^\[geo\]\s*([-+]?\d{1,2}(?:\.\d+)?)\s*,\s*([-+]?\d{1,3}(?:\.\d+)?)$/', $message, $matches) !== 1) {
        return null;
    }

    $lat = isset($matches[1]) ? (float) $matches[1] : 0.0;
    $lon = isset($matches[2]) ? (float) $matches[2] : 0.0;
    if ($lat < -90.0 || $lat > 90.0 || $lon < -180.0 || $lon > 180.0) {
        return null;
    }

    return [
        'lat' => $lat,
        'lon' => $lon,
    ];
}

function cvAuthTicketChatDistanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $earthRadiusKm = 6371.0;
    $latFrom = deg2rad($lat1);
    $lonFrom = deg2rad($lon1);
    $latTo = deg2rad($lat2);
    $lonTo = deg2rad($lon2);

    $latDelta = $latTo - $latFrom;
    $lonDelta = $lonTo - $lonFrom;
    $angle = 2 * asin(sqrt(pow(sin($latDelta / 2), 2) + cos($latFrom) * cos($latTo) * pow(sin($lonDelta / 2), 2)));

    return $angle * $earthRadiusKm;
}

/**
 * @return array<string,mixed>|null
 */
function cvAuthTicketChatResolveEntryByName(mysqli $connection, string $label): ?array
{
    $needle = cvAssistantNormalizeText($label);
    if ($needle === '') {
        return null;
    }

    $fallback = null;
    foreach (cvAuthTicketChatRouteEntries($connection) as $entry) {
        $normalized = trim((string) ($entry['normalized_name'] ?? ''));
        if ($normalized === '') {
            continue;
        }
        if ($normalized === $needle) {
            return $entry;
        }
        if (strpos($normalized, $needle) !== false && $fallback === null) {
            $fallback = $entry;
        }
    }

    return $fallback;
}

/**
 * @param array<string,mixed> $routeDraft
 * @param array<string,float> $geoPoint
 * @return array<string,mixed>
 */
function cvAuthTicketChatReplyForGeoDepartures(mysqli $connection, array $routeDraft, array $geoPoint, string $dateIt = ''): array
{
    $toRef = trim((string) ($routeDraft['to_ref'] ?? ''));
    $toName = trim((string) ($routeDraft['to_name'] ?? ''));
    if ($toRef === '' && $toName !== '') {
        $resolvedTo = cvAuthTicketChatResolveEntryByName($connection, $toName);
        if (is_array($resolvedTo)) {
            $toRef = trim((string) ($resolvedTo['id'] ?? ''));
            $toName = trim((string) ($resolvedTo['name'] ?? $toName));
        }
    }

    if ($toRef === '' || $toName === '') {
        return [
            'reply' => 'Per usare la posizione corrente dimmi prima dove vuoi arrivare. Ad esempio: voglio andare a Rimini.',
            'suggestions' => ['Voglio andare a Rimini', 'Voglio andare a Roma', 'Annulla'],
            'actions' => [],
            'route_lookup' => [
                'from_ref' => '',
                'from_name' => '',
                'to_ref' => '',
                'to_name' => '',
            ],
        ];
    }

    $lat = isset($geoPoint['lat']) ? (float) $geoPoint['lat'] : 0.0;
    $lon = isset($geoPoint['lon']) ? (float) $geoPoint['lon'] : 0.0;
    $dateIt = trim($dateIt);
    if ($dateIt === '') {
        $tomorrowIt = (new DateTimeImmutable('tomorrow', new DateTimeZone('Europe/Rome')))->format('d/m/Y');
        return [
            'reply' => 'Ok. Per arrivare a ' . $toName . ' partendo dalla tua posizione, per che giorno vuoi cercare? (Oggi, Domani oppure una data tipo ' . $tomorrowIt . ').',
            'suggestions' => ['Oggi', 'Domani', $tomorrowIt],
            'actions' => [],
            'route_lookup' => [
                'from_ref' => '',
                'from_name' => '',
                'to_ref' => $toRef,
                'to_name' => $toName,
                'geo_lat' => $lat,
                'geo_lon' => $lon,
            ],
        ];
    }

    $candidates = [];
    foreach (cvAuthTicketChatRouteEntries($connection) as $entry) {
        $fromRef = trim((string) ($entry['id'] ?? ''));
        if ($fromRef === '' || $fromRef === $toRef) {
            continue;
        }

        $entryLat = $entry['lat'] ?? null;
        $entryLon = $entry['lon'] ?? null;
        if (!is_numeric($entryLat) || !is_numeric($entryLon)) {
            continue;
        }

        $distanceKm = cvAuthTicketChatDistanceKm($lat, $lon, (float) $entryLat, (float) $entryLon);
        $candidates[] = [
            'from_ref' => $fromRef,
            'from_name' => trim((string) ($entry['name'] ?? '')),
            'distance_km' => $distanceKm,
        ];
    }

    usort(
        $candidates,
        static function (array $left, array $right): int {
            return ((float) ($left['distance_km'] ?? 0.0)) <=> ((float) ($right['distance_km'] ?? 0.0));
        }
    );

    $matches = [];
    $seenFrom = [];
    foreach (array_slice($candidates, 0, 16) as $candidate) {
        $fromRef = trim((string) ($candidate['from_ref'] ?? ''));
        $fromName = trim((string) ($candidate['from_name'] ?? ''));
        if ($fromRef === '' || $fromName === '' || isset($seenFrom[$fromRef])) {
            continue;
        }

        $search = cvPfSearchSolutions($connection, $fromRef, $toRef, $dateIt, 1, 0, 2, '');
        $solutions = isset($search['solutions']) && is_array($search['solutions']) ? $search['solutions'] : [];
        if (($search['ok'] ?? false) !== true || count($solutions) === 0) {
            continue;
        }

        $seenFrom[$fromRef] = true;
        $first = $solutions[0];
        $matches[] = [
            'from_ref' => $fromRef,
            'from_name' => $fromName,
            'distance_km' => (float) ($candidate['distance_km'] ?? 0.0),
            'departure_hm' => trim((string) ($first['departure_hm'] ?? '')),
        ];
        if (count($matches) >= 3) {
            break;
        }
    }

    if (count($matches) === 0) {
        return [
            'reply' => 'Per arrivare a ' . $toName . ' non trovo partenze vicine dalla tua posizione al momento. Altrimenti indicami una localita di partenza.',
            'suggestions' => ['Altrimenti indicami una localita di partenza', 'Da Napoli', 'Da Roma'],
            'actions' => [],
            'route_lookup' => [
                'from_ref' => '',
                'from_name' => '',
                'to_ref' => $toRef,
                'to_name' => $toName,
            ],
        ];
    }

    $lines = ['Per arrivare a ' . $toName . ' il ' . $dateIt . ', le partenze piu prossime da dove sei sono:'];
    foreach ($matches as $index => $item) {
        $line = ($index + 1) . '. ' . (string) ($item['from_name'] ?? '');
        $distance = (float) ($item['distance_km'] ?? 0.0);
        if ($distance > 0.0) {
            $line .= ' (' . number_format($distance, 1, ',', '.') . ' km)';
        }
        $departureHm = trim((string) ($item['departure_hm'] ?? ''));
        if ($departureHm !== '') {
            $line .= ' - prima partenza ' . $departureHm;
        }
        $lines[] = $line;
    }
    $lines[] = 'Se ne scegli una, scrivimi ad esempio: da ' . (string) ($matches[0]['from_name'] ?? '') . '. Altrimenti indicami una localita di partenza.';

    $suggestions = [];
    foreach ($matches as $item) {
        $fromName = trim((string) ($item['from_name'] ?? ''));
        if ($fromName === '') {
            continue;
        }
        $suggestions[] = 'Da ' . $fromName;
        if (count($suggestions) >= 2) {
            break;
        }
    }
    $suggestions[] = 'Altrimenti indicami una localita di partenza';

    return [
        'reply' => implode("\n", $lines),
        'suggestions' => $suggestions,
        'actions' => [],
        'route_lookup' => [
            'from_ref' => '',
            'from_name' => '',
            'to_ref' => $toRef,
            'to_name' => $toName,
            'geo_lat' => $lat,
            'geo_lon' => $lon,
        ],
    ];
}

/**
 * @param array<string,mixed> $solution
 */
function cvAuthTicketChatSolutionLine(array $solution, int $index = 1, bool $withDiscountHint = false): string
{
    $departureHm = trim((string) ($solution['departure_hm'] ?? ''));
    $arrivalHm = trim((string) ($solution['arrival_hm'] ?? ''));
    $transfers = max(0, (int) ($solution['transfers'] ?? 0));
    $durationLabel = cvAuthTicketChatDurationLabel((int) ($solution['duration_minutes'] ?? 0));
    $amount = (float) ($solution['amount'] ?? 0.0);
    $providerName = '';
    $legs = isset($solution['legs']) && is_array($solution['legs']) ? $solution['legs'] : [];
    if (count($legs) > 0) {
        $providerName = trim((string) ($legs[0]['provider_name'] ?? ''));
    }

    $line = $index . '. ' . $departureHm . ' -> ' . $arrivalHm . ' | ' . $durationLabel;
    $line .= $transfers <= 0 ? ' | diretto' : (' | ' . $transfers . ' cambio');
    if ($providerName !== '') {
        $line .= ' | ' . $providerName;
    }
    if ($amount > 0.0) {
        $line .= ' | da ' . cvFormatEuro($amount) . ' EUR';
    }

    if ($withDiscountHint && count($legs) > 0) {
        $discountPercent = (float) ($legs[0]['discount_percent'] ?? 0.0);
        $originalAmount = (float) ($legs[0]['original_amount'] ?? 0.0);
        if ($discountPercent > 0.0) {
            $line .= ' (sconto ' . rtrim(rtrim(number_format($discountPercent, 2, ',', '.'), '0'), ',') . '%)';
        } elseif ($originalAmount > 0.0 && $amount > 0.0 && $originalAmount > $amount + 0.01) {
            $line .= ' (in sconto)';
        }
    }

    return $line;
}

/**
 * @param array<int,array<string,mixed>> $solutions
 * @return array<string,mixed>|null
 */
function cvAuthTicketChatPickBestSolution(array $solutions, string $followupType): ?array
{
    $best = null;
    $bestKey = null;

    foreach ($solutions as $solution) {
        if (!is_array($solution)) {
            continue;
        }

        if ($followupType === 'cheapest') {
            $amount = (float) ($solution['amount'] ?? 0.0);
            if ($amount <= 0.0) {
                continue;
            }
            $key = [$amount, (int) ($solution['duration_minutes'] ?? 0), (int) ($solution['transfers'] ?? 0), (string) ($solution['departure_iso'] ?? '')];
        } elseif ($followupType === 'earliest') {
            $arrTs = strtotime((string) ($solution['arrival_iso'] ?? '')) ?: 0;
            if ($arrTs <= 0) {
                continue;
            }
            $key = [$arrTs, (int) ($solution['duration_minutes'] ?? 0), (float) ($solution['amount'] ?? 0.0), (string) ($solution['departure_iso'] ?? '')];
        } else {
            // fastest
            $duration = (int) ($solution['duration_minutes'] ?? 0);
            if ($duration <= 0) {
                continue;
            }
            $key = [$duration, (int) ($solution['transfers'] ?? 0), (float) ($solution['amount'] ?? 0.0), (string) ($solution['departure_iso'] ?? '')];
        }

        if ($bestKey === null || $key < $bestKey) {
            $bestKey = $key;
            $best = $solution;
        }
    }

    return is_array($best) ? $best : null;
}

/**
 * @param array<string,mixed> $routeRequest
 * @return array<string,mixed>
 */
function cvAuthTicketChatReplyForRouteFollowup(mysqli $connection, array $settings, array $routeRequest, string $followupType): array
{
    $fromRef = trim((string) ($routeRequest['from_ref'] ?? ''));
    $toRef = trim((string) ($routeRequest['to_ref'] ?? ''));
    $fromName = trim((string) ($routeRequest['from_name'] ?? ''));
    $toName = trim((string) ($routeRequest['to_name'] ?? ''));
    $dateIt = trim((string) ($routeRequest['date_it'] ?? ''));

    if ($fromRef === '' || $toRef === '' || $fromName === '' || $toName === '' || $dateIt === '') {
        return [
            'reply' => 'Ok. Per dirti il risultato migliore mi serve una tratta completa e una data (es: Da Salerno a Siena domani).',
            'suggestions' => ['Oggi', 'Domani', 'Cerca un altra tratta'],
            'actions' => [],
            'route_date_pending' => true,
        ];
    }

    $search = cvPfSearchSolutions($connection, $fromRef, $toRef, $dateIt, 1, 0, 2, '');
    $solutions = isset($search['solutions']) && is_array($search['solutions']) ? $search['solutions'] : [];
    if (($search['ok'] ?? false) !== true || count($solutions) === 0) {
        return [
            'reply' => 'Al momento non trovo soluzioni per ' . $fromName . ' -> ' . $toName . ' il ' . $dateIt . '.',
            'suggestions' => ['Oggi', 'Domani', 'Cerca un altra tratta'],
            'actions' => [
                [
                    'type' => 'link',
                    'label' => 'Apri ricerca',
                    'href' => cvAuthTicketChatBuildSolutionsUrl($fromRef, $toRef, $dateIt),
                ],
            ],
            'route' => [
                'from_ref' => $fromRef,
                'from_name' => $fromName,
                'to_ref' => $toRef,
                'to_name' => $toName,
                'date_it' => $dateIt,
            ],
            'route_date_pending' => false,
        ];
    }

    if ($followupType === 'discounts') {
        $discounted = [];
        foreach ($solutions as $solution) {
            if (!is_array($solution)) {
                continue;
            }
            $legs = isset($solution['legs']) && is_array($solution['legs']) ? $solution['legs'] : [];
            if (count($legs) === 0) {
                continue;
            }
            $amount = (float) ($solution['amount'] ?? 0.0);
            $original = (float) ($legs[0]['original_amount'] ?? 0.0);
            $percent = (float) ($legs[0]['discount_percent'] ?? 0.0);
            if ($percent > 0.0 || ($original > 0.0 && $amount > 0.0 && $original > $amount + 0.01)) {
                $discounted[] = $solution;
            }
        }

        if (count($discounted) === 0) {
            $cheapest = cvAuthTicketChatPickBestSolution($solutions, 'cheapest');
            $reply = 'Per ' . $fromName . ' -> ' . $toName . ' il ' . $dateIt . ' non vedo sconti applicati sulle soluzioni disponibili.';
            if (is_array($cheapest)) {
                $reply .= "\n\nPrezzo piu basso trovato:\n" . cvAuthTicketChatSolutionLine($cheapest, 1, false);
            }
            return [
                'reply' => $reply,
                'suggestions' => ['Apri tutte le soluzioni', 'Dimmi la piu economica', 'Dimmi quella che arriva prima'],
                'actions' => [
                    [
                        'type' => 'link',
                        'label' => 'Apri tutte le soluzioni',
                        'href' => cvAuthTicketChatBuildSolutionsUrl($fromRef, $toRef, $dateIt),
                    ],
                ],
                'route' => [
                    'from_ref' => $fromRef,
                    'from_name' => $fromName,
                    'to_ref' => $toRef,
                    'to_name' => $toName,
                    'date_it' => $dateIt,
                ],
                'route_date_pending' => false,
            ];
        }

        usort(
            $discounted,
            static function (array $a, array $b): int {
                return ((float) ($a['amount'] ?? 0.0)) <=> ((float) ($b['amount'] ?? 0.0));
            }
        );

        $lines = [
            'Per ' . $fromName . ' -> ' . $toName . ' il ' . $dateIt . ' ho trovato ' . count($discounted) . ' soluzioni con sconto:',
        ];
        foreach (array_slice($discounted, 0, 3) as $idx => $solution) {
            $lines[] = cvAuthTicketChatSolutionLine($solution, $idx + 1, true);
        }
        $lines[] = 'Se vuoi, posso anche dirti la piu veloce o quella che arriva prima.';

        return [
            'reply' => implode("\n", $lines),
            'suggestions' => ['Apri tutte le soluzioni', 'Dimmi la piu veloce', 'Dimmi quella che arriva prima'],
            'actions' => [
                [
                    'type' => 'link',
                    'label' => 'Apri tutte le soluzioni',
                    'href' => cvAuthTicketChatBuildSolutionsUrl($fromRef, $toRef, $dateIt),
                ],
            ],
            'route' => [
                'from_ref' => $fromRef,
                'from_name' => $fromName,
                'to_ref' => $toRef,
                'to_name' => $toName,
                'date_it' => $dateIt,
            ],
            'route_date_pending' => false,
        ];
    }

    $best = cvAuthTicketChatPickBestSolution($solutions, $followupType);
    if (!is_array($best)) {
        return [
            'reply' => 'Ok. Ho le soluzioni, ma non riesco a calcolare il risultato migliore in questo momento. Apri tutte le soluzioni e scegli quella che preferisci.',
            'suggestions' => ['Apri tutte le soluzioni', 'Domani', 'Cerca un altra tratta'],
            'actions' => [
                [
                    'type' => 'link',
                    'label' => 'Apri tutte le soluzioni',
                    'href' => cvAuthTicketChatBuildSolutionsUrl($fromRef, $toRef, $dateIt),
                ],
            ],
            'route' => [
                'from_ref' => $fromRef,
                'from_name' => $fromName,
                'to_ref' => $toRef,
                'to_name' => $toName,
                'date_it' => $dateIt,
            ],
            'route_date_pending' => false,
        ];
    }

    $label = 'piu veloce';
    if ($followupType === 'earliest') {
        $label = 'che arriva prima';
    } elseif ($followupType === 'cheapest') {
        $label = 'piu economica';
    }

    $lines = [
        'Per ' . $fromName . ' -> ' . $toName . ' il ' . $dateIt . ', la soluzione ' . $label . ' e:',
        cvAuthTicketChatSolutionLine($best, 1, true),
    ];

    if ($followupType !== 'cheapest') {
        $cheapest = cvAuthTicketChatPickBestSolution($solutions, 'cheapest');
        if (is_array($cheapest)) {
            $lines[] = '';
            $lines[] = 'Prezzo piu basso (se diverso):';
            $lines[] = cvAuthTicketChatSolutionLine($cheapest, 1, true);
        }
    }

    return [
        'reply' => implode("\n", $lines),
        'suggestions' => ['Apri tutte le soluzioni', 'Dimmi la piu economica', 'Dimmi la piu veloce'],
        'actions' => [
            [
                'type' => 'link',
                'label' => 'Apri tutte le soluzioni',
                'href' => cvAuthTicketChatBuildSolutionsUrl($fromRef, $toRef, $dateIt),
            ],
        ],
        'route' => [
            'from_ref' => $fromRef,
            'from_name' => $fromName,
            'to_ref' => $toRef,
            'to_name' => $toName,
            'date_it' => $dateIt,
        ],
        'route_date_pending' => false,
    ];
}

/**
 * @param array<string,mixed> $routeRequest
 * @return array<string,mixed>
 */
function cvAuthTicketChatReplyForRouteRequest(mysqli $connection, array $settings, array $routeRequest): array
{
    $fromRef = trim((string) ($routeRequest['from_ref'] ?? ''));
    $toRef = trim((string) ($routeRequest['to_ref'] ?? ''));
    $fromName = trim((string) ($routeRequest['from_name'] ?? ''));
    $toName = trim((string) ($routeRequest['to_name'] ?? ''));
    $dateIt = trim((string) ($routeRequest['date_it'] ?? ''));
    $followupType = trim((string) ($routeRequest['followup_type'] ?? ''));

    if ($followupType !== '') {
        return cvAuthTicketChatReplyForRouteFollowup($connection, $settings, $routeRequest, $followupType);
    }

    if ($fromRef === '' || $toRef === '' || $fromName === '' || $toName === '') {
        return [
            'reply' => 'Per cercare una tratta ho bisogno di partenza e destinazione. Scrivimi ad esempio: Malpensa a Milano domani.',
            'suggestions' => ['Oggi', 'Domani', 'Cerca un altra tratta'],
            'actions' => [],
        ];
    }

    $isFastestRequest = strpos(cvAssistantNormalizeText((string) ($routeRequest['raw_message'] ?? '')), 'veloc') !== false;
    if ($dateIt === '') {
        $defaultDateIt = date('d/m/Y');
        $searchToday = cvPfSearchSolutions($connection, $fromRef, $toRef, $defaultDateIt, 1, 0, 2, '');
        $todaySolutions = isset($searchToday['solutions']) && is_array($searchToday['solutions']) ? $searchToday['solutions'] : [];

        if (($searchToday['ok'] ?? false) === true && count($todaySolutions) > 0) {
            usort(
                $todaySolutions,
                static function (array $left, array $right): int {
                    return ((int) ($left['duration_minutes'] ?? 0)) <=> ((int) ($right['duration_minutes'] ?? 0));
                }
            );
            $best = $todaySolutions[0];
            $departureHm = trim((string) ($best['departure_hm'] ?? ''));
            $arrivalHm = trim((string) ($best['arrival_hm'] ?? ''));
            $durationLabel = cvAuthTicketChatDurationLabel((int) ($best['duration_minutes'] ?? 0));
            $transfers = max(0, (int) ($best['transfers'] ?? 0));
            $amount = (float) ($best['amount'] ?? 0.0);
            $bestLine = 'Oggi la soluzione piu veloce ' . $fromName . ' -> ' . $toName . ' parte alle ' . $departureHm . ' e arriva alle ' . $arrivalHm . ' (' . $durationLabel . ').';
            $bestLine .= $transfers <= 0 ? ' E diretta.' : (' Ha ' . $transfers . ' cambio.');
            if ($amount > 0.0) {
                $bestLine .= ' Prezzo da ' . cvFormatEuro($amount) . ' EUR.';
            }
            $bestLine .= ' Se vuoi una data specifica, scrivimi ad esempio: domani oppure 31/03/2026.';
            return [
                'reply' => $bestLine,
                'suggestions' => ['Domani', '31/03/2026', 'Cerca un altra tratta'],
                'actions' => [
                    [
                        'type' => 'link',
                        'label' => $isFastestRequest ? 'Apri tutte le soluzioni di oggi' : 'Apri ricerca di oggi',
                        'href' => cvAuthTicketChatBuildSolutionsUrl($fromRef, $toRef, $defaultDateIt),
                    ],
                ],
                'route' => [
                    'from_ref' => $fromRef,
                    'from_name' => $fromName,
                    'to_ref' => $toRef,
                    'to_name' => $toName,
                    'date_it' => $defaultDateIt,
                ],
                'route_date_pending' => false,
            ];
        }

        return [
            'reply' => 'Posso cercare la tratta ' . $fromName . ' -> ' . $toName . ', ma oggi non trovo orari disponibili. Scrivimi la data di partenza, ad esempio: domani oppure 31/03/2026.',
            'suggestions' => ['Domani', '31/03/2026', 'Cerca un altra tratta'],
            'actions' => [],
            'route' => [
                'from_ref' => $fromRef,
                'from_name' => $fromName,
                'to_ref' => $toRef,
                'to_name' => $toName,
                'date_it' => '',
            ],
            'route_date_pending' => true,
        ];
    }

    $search = cvPfSearchSolutions($connection, $fromRef, $toRef, $dateIt, 1, 0, 2, '');
    $solutions = isset($search['solutions']) && is_array($search['solutions']) ? $search['solutions'] : [];

    if (($search['ok'] ?? false) !== true || count($solutions) === 0) {
        return [
            'reply' => 'Al momento non trovo soluzioni per ' . $fromName . ' -> ' . $toName . ' il ' . $dateIt . '. Posso ricontrollare se mi mandi un altra data.',
            'suggestions' => ['Oggi', 'Domani', 'Cerca un altra tratta'],
            'actions' => [
                [
                    'type' => 'link',
                    'label' => 'Apri ricerca',
                    'href' => cvAuthTicketChatBuildSolutionsUrl($fromRef, $toRef, $dateIt),
                ],
            ],
            'route' => [
                'from_ref' => $fromRef,
                'from_name' => $fromName,
                'to_ref' => $toRef,
                'to_name' => $toName,
                'date_it' => $dateIt,
            ],
            'route_date_pending' => true,
        ];
    }

    $lines = [
        'Per ' . $fromName . ' -> ' . $toName . ' il ' . $dateIt . ' ho trovato ' . count($solutions) . ' soluzioni.',
        'Prime partenze disponibili:',
    ];

    foreach (array_slice($solutions, 0, 3) as $index => $solution) {
        $departureHm = trim((string) ($solution['departure_hm'] ?? ''));
        $arrivalHm = trim((string) ($solution['arrival_hm'] ?? ''));
        $transfers = max(0, (int) ($solution['transfers'] ?? 0));
        $durationLabel = cvAuthTicketChatDurationLabel((int) ($solution['duration_minutes'] ?? 0));
        $amount = (float) ($solution['amount'] ?? 0.0);
        $providerName = '';
        $legs = isset($solution['legs']) && is_array($solution['legs']) ? $solution['legs'] : [];
        if (count($legs) > 0) {
            $providerName = trim((string) ($legs[0]['provider_name'] ?? ''));
        }

        $line = ($index + 1) . '. ' . $departureHm . ' -> ' . $arrivalHm . ' | ' . $durationLabel;
        $line .= $transfers <= 0 ? ' | diretto' : (' | ' . $transfers . ' cambio');
        if ($providerName !== '') {
            $line .= ' | ' . $providerName;
        }
        if ($amount > 0.0) {
            $line .= ' | da ' . cvFormatEuro($amount) . ' EUR';
        }
        $lines[] = $line;
    }

    return [
        'reply' => implode("\n", $lines),
        'suggestions' => ['Domani', 'Cerca un altra tratta', 'Come recupero il biglietto?'],
        'actions' => [
            [
                'type' => 'link',
                'label' => 'Apri tutte le soluzioni',
                'href' => cvAuthTicketChatBuildSolutionsUrl($fromRef, $toRef, $dateIt),
            ],
        ],
        'route' => [
            'from_ref' => $fromRef,
            'from_name' => $fromName,
            'to_ref' => $toRef,
            'to_name' => $toName,
            'date_it' => $dateIt,
        ],
        'route_date_pending' => false,
    ];
}

/**
 * @return array<string,mixed>|null
 */
function cvAuthTicketChatLoadTicket(mysqli $connection, string $ticketCode): ?array
{
    $ticketCode = strtoupper(trim($ticketCode));
    if ($ticketCode === '') {
        return null;
    }

    $hasControllato = cvAuthTableHasColumn($connection, 'biglietti', 'controllato');
    $hasAttesa = cvAuthTableHasColumn($connection, 'biglietti', 'attesa');
    $hasDataAttesa = cvAuthTableHasColumn($connection, 'biglietti', 'data_attesa');
    $hasCamb = cvAuthTableHasColumn($connection, 'biglietti', 'camb');
    $selectControllato = $hasControllato ? 'b.controllato' : '0';
    $selectAttesa = $hasAttesa ? 'b.attesa' : '0';
    $selectDataAttesa = $hasDataAttesa ? 'b.data_attesa' : "''";
    $selectCamb = $hasCamb ? 'b.camb' : '0';

    $sql = "SELECT
                b.id_bg,
                b.codice,
                b.codice_camb,
                b.transaction_id,
                b.pagato,
                b.stato,
                {$selectControllato} AS controllato,
                {$selectAttesa} AS attesa,
                {$selectDataAttesa} AS data_attesa,
                {$selectCamb} AS camb,
                b.note,
                b.data AS departure_at,
                b.data2 AS arrival_at,
                a.code AS provider_code,
                a.nome AS provider_name,
                a.tel AS provider_phone,
                a.recapiti AS provider_contacts,
                s1.nome AS from_name,
                s2.nome AS to_name
            FROM biglietti AS b
            LEFT JOIN aziende AS a ON a.id_az = b.id_az
            LEFT JOIN tratte_sottoc AS s1 ON s1.id_sott = b.id_sott1
            LEFT JOIN tratte_sottoc AS s2 ON s2.id_sott = b.id_sott2
            WHERE b.codice = ?
            ORDER BY b.id_bg DESC
            LIMIT 1";

    $statement = $connection->prepare($sql);
    if (!$statement instanceof mysqli_stmt) {
        return null;
    }
    $statement->bind_param('s', $ticketCode);
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

/**
 * @param array<int,array<string,mixed>> $knowledgeItems
 * @return array<string,mixed>
 */
function cvAuthTicketChatFaqMenu(array $knowledgeItems, array $settings): array
{
    $titles = [];
    foreach ($knowledgeItems as $item) {
        if (!is_array($item) || (int) ($item['active'] ?? 0) !== 1) {
            continue;
        }
        $title = trim((string) ($item['title'] ?? ''));
        if ($title === '') {
            continue;
        }
        $titles[$title] = $title;
        if (count($titles) >= 4) {
            break;
        }
    }

    $reply = 'Posso aiutarti con queste richieste: ' . implode(', ', array_values($titles)) . '.';
    if (count($titles) === 0) {
        $reply = (string) ($settings['fallback_message'] ?? 'Scrivimi la tua domanda oppure indicami il codice biglietto.');
    }

    return [
        'reply' => $reply,
        'suggestions' => cvAuthTicketChatSuggestions($settings, 'faq', false),
        'actions' => [],
    ];
}

/**
 * @param array<string,mixed> $settings
 * @param array<string,mixed> $ticket
 * @param array<string,array<string,mixed>> $providerConfigs
 * @return array<string,mixed>
 */
function cvAuthTicketChatReplyForTicket(array $settings, string $intent, array $ticket, array $providerConfigs): array
{
    $ticketCode = strtoupper(trim((string) ($ticket['codice'] ?? '')));
    $status = cvAuthTicketStatusSummary($ticket);
    $providerName = trim((string) ($ticket['provider_name'] ?? ''));
    $providerPhone = cvAuthExtractProviderPhone(
        (string) ($ticket['provider_phone'] ?? ''),
        (string) ($ticket['provider_contacts'] ?? '')
    );
    $fromName = trim((string) ($ticket['from_name'] ?? ''));
    $toName = trim((string) ($ticket['to_name'] ?? ''));
    $departureAtIt = cvAuthFormatDateTimeIt((string) ($ticket['departure_at'] ?? ''));
    $routeText = ($fromName !== '' && $toName !== '') ? ($fromName . ' -> ' . $toName) : '';
    $changeAvailability = cvAuthTicketChangeAvailability($ticket, $providerConfigs);
    $canChange = !empty($changeAvailability['can_change']);
    $changeReason = trim((string) ($changeAvailability['reason'] ?? ''));
    $changeRetryAfterSeconds = max(0, (int) ($changeAvailability['retry_after_seconds'] ?? 0));

    $lines = [];
    if ($intent === 'pdf') {
        $lines[] = 'Ho trovato il biglietto ' . $ticketCode . '.';
        $lines[] = 'Puoi aprire il dettaglio ticket o scaricare subito il PDF.';
    } elseif ($intent === 'change') {
        $lines[] = 'Ho verificato il biglietto ' . $ticketCode . '.';
        if ($canChange) {
            $lines[] = 'Il cambio risulta disponibile in base alle regole attuali del ticket.';
            $lines[] = 'Apri il biglietto per proseguire con il cambio della corsa.';
        } else {
            $lines[] = 'Il cambio al momento non risulta disponibile.';
            if ($changeReason !== '') {
                $lines[] = 'Motivo: ' . $changeReason . '.';
            }
            $changeHelp = cvAuthTicketChatChangeReasonHelp($changeReason, $changeRetryAfterSeconds);
            if ($changeHelp !== '') {
                $lines[] = $changeHelp;
            }
        }
    } elseif ($intent === 'contact') {
        if ($providerName !== '') {
            $lines[] = 'Per questo viaggio il provider di riferimento e ' . $providerName . '.';
        }
        if ($providerPhone !== '') {
            $lines[] = 'Numero assistenza: ' . $providerPhone . '.';
        } else {
            $lines[] = 'Non ho un numero telefonico configurato per questo provider.';
        }
    } else {
        $lines[] = 'Ho trovato il biglietto ' . $ticketCode . '.';
        $lines[] = 'Stato: ' . (string) ($status['label'] ?? 'Biglietto trovato') . '.';
        if ($routeText !== '') {
            $lines[] = 'Tratta: ' . $routeText . '.';
        }
        if ($departureAtIt !== '') {
            $lines[] = 'Partenza: ' . $departureAtIt . '.';
        }
        if ((bool) ($status['is_problem'] ?? false) && $providerPhone !== '') {
            $providerText = $providerName !== '' ? $providerName : 'provider del viaggio';
            $lines[] = 'Per assistenza puoi contattare ' . $providerText . ' al numero ' . $providerPhone . '.';
        }
    }

    return [
        'reply' => implode(' ', $lines),
        'suggestions' => cvAuthTicketChatSuggestions($settings, $intent, true),
        'actions' => cvAuthTicketChatActionsForTicket($ticket, $intent),
        'ticket' => [
            'ticket_code' => $ticketCode,
            'status_code' => (string) ($status['code'] ?? ''),
            'status_label' => (string) ($status['label'] ?? ''),
            'provider_code' => strtolower(trim((string) ($ticket['provider_code'] ?? ''))),
            'provider_name' => $providerName,
            'provider_phone' => $providerPhone,
            'from_name' => $fromName,
            'to_name' => $toName,
            'departure_at_it' => $departureAtIt,
            'can_change' => $canChange,
            'change_reason' => $changeReason,
            'change_retry_after_seconds' => $changeRetryAfterSeconds,
        ],
    ];
}

function cvAuthTicketChatChangeReasonHelp(string $reason, int $retryAfterSeconds = 0): string
{
    $normalized = strtolower(trim($reason));
    if ($normalized === '') {
        return '';
    }

    if ($normalized === 'cambio già in attesa pagamento' || $normalized === 'cambio gia in attesa pagamento') {
        if ($retryAfterSeconds > 0) {
            return 'È presente un cambio appena avviato: riprova tra ' . cvAuthTicketChangeRetryLabel($retryAfterSeconds) . ' oppure completa il checkout già aperto.';
        }
        return 'È presente un cambio appena avviato: attendi circa 5 minuti e riprova, oppure completa il checkout già aperto.';
    }
    if ($normalized === 'biglietto già sostituito' || $normalized === 'biglietto gia sostituito') {
        return 'Questo è il biglietto originario già cambiato: se il vettore consente un ulteriore cambio, va richiesto dal nuovo biglietto.';
    }
    if ($normalized === 'numero massimo cambi raggiunto') {
        return 'Questo ticket ha esaurito i cambi consentiti dal vettore: non è possibile effettuare ulteriori modifiche.';
    }
    if ($normalized === 'finestra cambio scaduta') {
        return 'La finestra temporale prevista dal vettore per il cambio è scaduta, quindi il ticket non è più modificabile.';
    }
    if ($normalized === 'biglietto non attivo') {
        return 'Il cambio è disponibile solo su biglietti attivi.';
    }
    if ($normalized === 'biglietto non pagato') {
        return 'Il cambio è disponibile solo dopo il pagamento del biglietto.';
    }

    return '';
}

/**
 * @return array<int,array<string,mixed>>
 */
function cvAuthTicketChatMapMessages(array $messages, array $feedbackMap = []): array
{
    $mapped = [];
    foreach ($messages as $message) {
        if (!is_array($message)) {
            continue;
        }
        $messageId = isset($message['id_message']) ? (int) $message['id_message'] : 0;
        $meta = json_decode((string) ($message['meta_json'] ?? '{}'), true);
        $mapped[] = [
            'id' => $messageId,
            'role' => (string) ($message['role'] ?? 'assistant'),
            'text' => (string) ($message['message_text'] ?? ''),
            'intent' => (string) ($message['intent'] ?? ''),
            'created_at' => (string) ($message['created_at'] ?? ''),
            'feedback' => isset($feedbackMap[$messageId]) ? (int) $feedbackMap[$messageId] : 0,
            'actions' => isset($meta['actions']) && is_array($meta['actions']) ? array_values($meta['actions']) : [],
        ];
    }
    return $mapped;
}

/**
 * @return array<string,mixed>
 */
function cvAuthTicketChatSupportWaitingState(mysqli $connection, string $sessionKey, int $busyTimeoutMinutes): array
{
    $state = [
        'has_active_ticket' => false,
        'ticket_id' => 0,
        'ticket_status' => '',
        'pending_user_minutes' => 0,
        'is_busy' => false,
        'last_admin_reply_at' => '',
    ];

    $activeTicket = cvAssistantSupportTicketLatestActiveBySession($connection, $sessionKey);
    if (!is_array($activeTicket)) {
        return $state;
    }

    $idTicket = (int) ($activeTicket['id_ticket'] ?? 0);
    if ($idTicket <= 0) {
        return $state;
    }

    $adminTs = 0;
    $userTs = 0;

    $adminStmt = $connection->prepare('SELECT created_at FROM cv_assistant_support_messages WHERE id_ticket = ? AND sender_role = "admin" ORDER BY id_ticket_message DESC LIMIT 1');
    if ($adminStmt instanceof mysqli_stmt) {
        $adminStmt->bind_param('i', $idTicket);
        if ($adminStmt->execute()) {
            $adminResult = $adminStmt->get_result();
            $adminRow = $adminResult instanceof mysqli_result ? $adminResult->fetch_assoc() : null;
            if ($adminResult instanceof mysqli_result) {
                $adminResult->free();
            }
            if (is_array($adminRow)) {
                $adminRaw = trim((string) ($adminRow['created_at'] ?? ''));
                $adminParsed = $adminRaw !== '' ? strtotime($adminRaw) : false;
                if (is_int($adminParsed) && $adminParsed > 0) {
                    $adminTs = $adminParsed;
                    $state['last_admin_reply_at'] = $adminRaw;
                }
            }
        }
        $adminStmt->close();
    }

    $userStmt = $connection->prepare('SELECT created_at FROM cv_assistant_support_messages WHERE id_ticket = ? AND sender_role = "user" ORDER BY id_ticket_message DESC LIMIT 1');
    if ($userStmt instanceof mysqli_stmt) {
        $userStmt->bind_param('i', $idTicket);
        if ($userStmt->execute()) {
            $userResult = $userStmt->get_result();
            $userRow = $userResult instanceof mysqli_result ? $userResult->fetch_assoc() : null;
            if ($userResult instanceof mysqli_result) {
                $userResult->free();
            }
            if (is_array($userRow)) {
                $userRaw = trim((string) ($userRow['created_at'] ?? ''));
                $userParsed = $userRaw !== '' ? strtotime($userRaw) : false;
                if (is_int($userParsed) && $userParsed > 0) {
                    $userTs = $userParsed;
                }
            }
        }
        $userStmt->close();
    }

    $state['has_active_ticket'] = true;
    $state['ticket_id'] = $idTicket;
    $state['ticket_status'] = strtolower(trim((string) ($activeTicket['status'] ?? 'open')));

    $pendingFromTs = 0;
    if ($userTs > 0 && $userTs > $adminTs) {
        $pendingFromTs = $userTs;
    } elseif ($adminTs <= 0 && $userTs > 0) {
        $pendingFromTs = $userTs;
    }

    if ($pendingFromTs > 0) {
        $pendingMinutes = (int) floor(max(0, time() - $pendingFromTs) / 60);
        $state['pending_user_minutes'] = $pendingMinutes;
        $state['is_busy'] = $pendingMinutes >= max(1, $busyTimeoutMinutes);
    }

    return $state;
}

function cvAuthHandleTicketChatFeedback(mysqli $connection): void
{
    $payload = cvAuthRequestData();
    $sessionKey = cvAuthTicketChatSessionKey((string) ($_GET['session_key'] ?? $_POST['session_key'] ?? ($payload['session_key'] ?? '')));
    $messageId = (int) ($_GET['message_id'] ?? $_POST['message_id'] ?? ($payload['message_id'] ?? 0));
    $feedback = (int) ($_GET['feedback'] ?? $_POST['feedback'] ?? ($payload['feedback'] ?? 0));

    $settings = cvAssistantSettings($connection);
    if (empty($settings['feedback_enabled'])) {
        cvAuthResponse(false, 'Feedback assistente non attivo.', [], 'ASSISTANT_FEEDBACK_DISABLED', 403);
    }
    if ($messageId <= 0 || ($feedback !== 1 && $feedback !== -1)) {
        cvAuthResponse(false, 'Feedback non valido.', [], 'ASSISTANT_FEEDBACK_INVALID', 422);
    }

    $saved = cvAssistantFeedbackUpsert($connection, $messageId, $sessionKey, $feedback, [
        'user_agent' => substr(trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 255),
    ]);
    if (!$saved) {
        cvAuthResponse(false, 'Impossibile salvare il feedback.', [], 'ASSISTANT_FEEDBACK_SAVE_ERROR', 500);
    }

    cvAuthResponse(true, 'Feedback salvato.', [
        'message_id' => $messageId,
        'feedback' => $feedback,
    ]);
}

function cvAuthHandleTicketChatSupport(mysqli $connection): void
{
    $payload = cvAuthRequestData();
    cvAssistantEnsureTables($connection);
    $settings = cvAssistantSettings($connection);

    $mode = strtolower(trim((string) ($_GET['mode'] ?? $_POST['mode'] ?? ($payload['mode'] ?? 'message'))));
    if ($mode === '') {
        $mode = 'message';
    }

    $sessionKey = cvAuthTicketChatSessionKey((string) ($_GET['session_key'] ?? $_POST['session_key'] ?? ($payload['session_key'] ?? '')));
    $sessionUserId = cvAuthSessionUserId();
    $collectLogs = !empty($settings['collect_logs']);
    $conversation = [
        'id_conversation' => 0,
        'session_key' => $sessionKey,
        'ticket_code' => '',
        'provider_code' => '',
        'context_json' => '{}',
        'status' => 'open',
    ];

    $conversation = cvAssistantConversationEnsure($connection, $sessionKey, [
        'channel' => 'web',
        'client_ip_hash' => hash('sha256', cvAuthClientIp()),
        'user_agent' => substr(trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 255),
    ]);

    $conversationContext = cvAssistantConversationContext($conversation);
    $operatorThreshold = max(1, (int) ($settings['operator_handoff_after_unresolved'] ?? 4));
    $operatorBusyTimeoutMinutes = max(1, (int) ($settings['operator_busy_timeout_minutes'] ?? 6));
    $operatorLabel = trim((string) ($settings['operator_handoff_label'] ?? 'Chatta con un operatore'));
    if ($operatorLabel === '') {
        $operatorLabel = 'Chatta con un operatore';
    }
    $operatorUnresolvedCount = max(0, (int) ($conversationContext['operator_unresolved_count'] ?? 0));
    $supportWaitingState = cvAuthTicketChatSupportWaitingState($connection, $sessionKey, $operatorBusyTimeoutMinutes);

    if ($mode === 'history') {
        $messages = $collectLogs
            ? cvAssistantConversationMessages($connection, (int) ($conversation['id_conversation'] ?? 0), 25)
            : [];
        $feedbackMap = ($collectLogs && !empty($settings['feedback_enabled']))
            ? cvAssistantFeedbackMapForConversation($connection, (int) ($conversation['id_conversation'] ?? 0), $sessionKey)
            : [];

        if (count($messages) === 0) {
            $welcomeText = (string) ($settings['welcome_message'] ?? 'Come posso esserti utile oggi?');
            if ($collectLogs && (int) ($conversation['id_conversation'] ?? 0) > 0) {
                cvAssistantLogMessage($connection, (int) $conversation['id_conversation'], 'assistant', $welcomeText, 'welcome', 1.0, ['kind' => 'welcome']);
                $messages = cvAssistantConversationMessages($connection, (int) ($conversation['id_conversation'] ?? 0), 25);
                $feedbackMap = !empty($settings['feedback_enabled'])
                    ? cvAssistantFeedbackMapForConversation($connection, (int) ($conversation['id_conversation'] ?? 0), $sessionKey)
                    : [];
            } else {
                $messages[] = [
                    'id_message' => 0,
                    'role' => 'assistant',
                    'message_text' => $welcomeText,
                    'intent' => 'welcome',
                    'created_at' => date('Y-m-d H:i:s'),
                ];
            }
        }

        $historySuggestions = [];
        if (array_key_exists('last_suggestions', $conversationContext) && is_array($conversationContext['last_suggestions'])) {
            $historySuggestions = array_values(array_filter(
                $conversationContext['last_suggestions'],
                static function ($value): bool {
                    return trim((string) $value) !== '';
                }
            ));
        } else {
            $historyIntent = trim((string) ($conversationContext['last_intent'] ?? ''));
            if ($historyIntent === '' && count($messages) === 0) {
                $historyIntent = 'greeting';
            }
            $historySuggestions = cvAuthTicketChatSuggestions(
                $settings,
                $historyIntent !== '' ? $historyIntent : 'greeting',
                trim((string) ($conversation['ticket_code'] ?? '')) !== ''
            );
        }

        if (!empty($supportWaitingState['has_active_ticket']) && !empty($supportWaitingState['is_busy'])) {
            $busyNotice = 'I nostri operatori sono momentaneamente occupati. Riprova piu tardi o lascia un messaggio.';
            $lastMessageText = '';
            if (count($messages) > 0) {
                $lastRaw = $messages[count($messages) - 1];
                if (is_array($lastRaw)) {
                    $lastMessageText = trim((string) ($lastRaw['message_text'] ?? ''));
                }
            }
            if ($lastMessageText !== $busyNotice) {
                $messages[] = [
                    'id_message' => 0,
                    'role' => 'assistant',
                    'message_text' => $busyNotice,
                    'intent' => 'support_waiting',
                    'created_at' => date('Y-m-d H:i:s'),
                    'meta_json' => '{}',
                ];
            }
            $historySuggestions = ['Lascia un messaggio', 'Riprova piu tardi', 'Annulla'];
        }
        $historyOperatorAvailable = !empty($settings['ticketing_enabled'])
            && !$supportWaitingState['has_active_ticket']
            && $operatorUnresolvedCount >= $operatorThreshold;
        if ($historyOperatorAvailable) {
            $historySuggestions[] = $operatorLabel;
            $historySuggestions = array_values(array_unique(array_filter(
                $historySuggestions,
                static function ($value): bool {
                    return trim((string) $value) !== '';
                }
            )));
        }
        if ($collectLogs) {
            cvAuthTicketChatDebugLog('history', [
                'session_key' => $sessionKey,
                'unresolved_count' => $operatorUnresolvedCount,
                'threshold' => $operatorThreshold,
                'operator_available' => $historyOperatorAvailable,
                'has_active_ticket' => !empty($supportWaitingState['has_active_ticket']),
                'collect_logs' => $collectLogs,
            ]);
        }

        cvAuthResponse(true, 'Storico assistente caricato.', [
            'session_key' => $sessionKey,
            'settings' => [
                'assistant_name' => (string) ($settings['assistant_name'] ?? 'Assistente Cercaviaggio'),
                'assistant_badge' => (string) ($settings['assistant_badge'] ?? 'Supporto viaggi'),
                'widget_enabled' => !empty($settings['widget_enabled']),
                'feedback_enabled' => !empty($settings['feedback_enabled']),
                'ticketing_enabled' => !empty($settings['ticketing_enabled']),
                'operator_handoff_label' => $operatorLabel,
            ],
            'messages' => cvAuthTicketChatMapMessages($messages, $feedbackMap),
            'suggestions' => $historySuggestions,
            'operator' => [
                'available' => $historyOperatorAvailable,
                'label' => $operatorLabel,
                'threshold' => $operatorThreshold,
                'unresolved_count' => $operatorUnresolvedCount,
                'busy_timeout_minutes' => $operatorBusyTimeoutMinutes,
                'has_active_ticket' => !empty($supportWaitingState['has_active_ticket']),
                'active_ticket_id' => (int) ($supportWaitingState['ticket_id'] ?? 0),
                'is_busy' => !empty($supportWaitingState['is_busy']),
            ],
            'conversation' => [
                'ticket_code' => strtoupper(trim((string) ($conversation['ticket_code'] ?? ''))),
                'provider_code' => strtolower(trim((string) ($conversation['provider_code'] ?? ''))),
            ],
        ]);
    }

    $rawText = '';
    if (isset($_GET['text'])) {
        $rawText = (string) $_GET['text'];
    } elseif (isset($_POST['text'])) {
        $rawText = (string) $_POST['text'];
    } elseif (isset($payload['text'])) {
        $rawText = (string) $payload['text'];
    } elseif (isset($payload['message'])) {
        $rawText = (string) $payload['message'];
    } elseif (isset($payload['ticket_code'])) {
        $rawText = (string) $payload['ticket_code'];
    } elseif (isset($payload['code'])) {
        $rawText = (string) $payload['code'];
    }

    $messageText = trim($rawText);
    if ($messageText === '') {
        cvAuthResponse(true, 'Messaggio vuoto.', [
            'session_key' => $sessionKey,
            'reply' => (string) ($settings['welcome_message'] ?? 'Come posso esserti utile oggi?'),
            'suggestions' => cvAuthTicketChatSuggestions($settings),
            'actions' => [],
            'operator' => [
                'available' => !empty($settings['ticketing_enabled'])
                    && !$supportWaitingState['has_active_ticket']
                    && $operatorUnresolvedCount >= $operatorThreshold,
                'label' => $operatorLabel,
                'threshold' => $operatorThreshold,
                'unresolved_count' => $operatorUnresolvedCount,
                'busy_timeout_minutes' => $operatorBusyTimeoutMinutes,
            ],
        ]);
    }

    if ($collectLogs && (int) ($conversation['id_conversation'] ?? 0) > 0) {
        cvAssistantLogMessage($connection, (int) $conversation['id_conversation'], 'user', $messageText, '', 0.0);
    }

    $pendingFlow = trim((string) ($conversationContext['pending_flow'] ?? ''));
    $pendingIdentityField = trim((string) ($conversationContext['pending_identity_field'] ?? ''));
    $pendingSupportTicketField = trim((string) ($conversationContext['pending_support_ticket_field'] ?? ''));
    $identityDraft = isset($conversationContext['identity_lookup']) && is_array($conversationContext['identity_lookup'])
        ? $conversationContext['identity_lookup']
        : [];
    $supportTicketDraft = isset($conversationContext['support_ticket_draft']) && is_array($conversationContext['support_ticket_draft'])
        ? $conversationContext['support_ticket_draft']
        : [];
    $existingSupportTicketId = (int) ($conversationContext['support_ticket_id'] ?? 0);
    if ($existingSupportTicketId <= 0 && !empty($supportWaitingState['has_active_ticket'])) {
        $existingSupportTicketId = (int) ($supportWaitingState['ticket_id'] ?? 0);
    }
    $routeDraft = isset($conversationContext['route_lookup']) && is_array($conversationContext['route_lookup'])
        ? $conversationContext['route_lookup']
        : [];
    $lastRoute = isset($conversationContext['last_route']) && is_array($conversationContext['last_route'])
        ? $conversationContext['last_route']
        : [];
    $storedTicketCode = strtoupper(trim((string) ($conversation['ticket_code'] ?? '')));
    $isExplicitOperatorTrigger = trim($messageText) === '[operator]';
    $intent = cvAuthTicketChatIntent($messageText, false);
    $isPersonalTicketRequest = cvAuthTicketChatIsPersonalTicketRequest($messageText);
    $isIdentityFlowActive = $pendingFlow === 'identity_lookup';
    $isSupportTicketFlowActive = $pendingFlow === 'support_ticket';
    $isRouteFlowActive = $pendingFlow === 'route_lookup';
    $operatorAllowedNow = !empty($settings['ticketing_enabled'])
        && !$supportWaitingState['has_active_ticket']
        && $operatorUnresolvedCount >= $operatorThreshold;
    $explicitTicketCode = cvAuthExtractTicketCodeFromText($messageText);
    $ticketCode = $explicitTicketCode;
    if (
        $ticketCode === ''
        && $storedTicketCode !== ''
        && cvAuthTicketChatIntentNeedsTicket($intent)
        && !$isPersonalTicketRequest
        && !$isIdentityFlowActive
        && !$isSupportTicketFlowActive
    ) {
        $ticketCode = $storedTicketCode;
    }

    $ticket = $ticketCode !== '' ? cvAuthTicketChatLoadTicket($connection, $ticketCode) : null;
    $providerConfigsRaw = cvProviderConfigs($connection);
    $providerConfigs = [];
    foreach ($providerConfigsRaw as $cfgCode => $cfg) {
        if (!is_array($cfg)) {
            continue;
        }
        $normalizedCfgCode = strtolower(trim((string) $cfgCode));
        if ($normalizedCfgCode === '') {
            continue;
        }
        $providerConfigs[$normalizedCfgCode] = $cfg;
    }

    $ticketFound = is_array($ticket);
    if ($ticketFound && $intent === 'generic') {
        $intent = 'ticket_status';
    }
    $identity = cvAuthTicketChatExtractIdentityFromText($messageText);
    $routeRequest = null;
    $routeSingleStopHint = null;
    $routeTextHint = null;
    $geoPoint = cvAuthTicketChatExtractGeoPoint($messageText);
    $parsedTravelDate = cvAuthTicketChatParseTravelDate($messageText);
    $routeFollowupType = cvAuthTicketChatRouteFollowupType($messageText);
    $nearbyStopsRequest = null;
    if (!$ticketFound && $explicitTicketCode === '' && !$isIdentityFlowActive && !$isSupportTicketFlowActive && !$isPersonalTicketRequest) {
        if (
            $isRouteFlowActive
            && (
                trim((string) ($routeDraft['from_ref'] ?? '')) === ''
                || trim((string) ($routeDraft['to_ref'] ?? '')) === ''
            )
        ) {
            $singleStop = cvAuthTicketChatExtractSingleStopMention($connection, $messageText);
            if (is_array($singleStop)) {
                $draftFromRef = trim((string) ($routeDraft['from_ref'] ?? ''));
                $draftToRef = trim((string) ($routeDraft['to_ref'] ?? ''));
                $singleRef = trim((string) ($singleStop['ref'] ?? ''));
                $singleName = trim((string) ($singleStop['name'] ?? ''));
                $singleRole = trim((string) ($singleStop['role'] ?? ''));
                if ($singleRef !== '' && $singleName !== '') {
                    if ($draftFromRef === '' && ($singleRole === 'from' || $draftToRef !== $singleRef)) {
                        $routeDraft['from_ref'] = $singleRef;
                        $routeDraft['from_name'] = $singleName;
                    } elseif ($draftToRef === '' && ($singleRole === 'to' || $draftFromRef !== $singleRef)) {
                        $routeDraft['to_ref'] = $singleRef;
                        $routeDraft['to_name'] = $singleName;
                    }
                }
            }
        }

        $nearbyStopsRequest = cvAuthTicketChatExtractNearbyStopsRequest($connection, $messageText);
        if (is_array($nearbyStopsRequest)) {
            $intent = 'stops';
        }

        if ($isRouteFlowActive && !is_array($routeRequest)) {
            if (
                trim((string) ($routeDraft['from_ref'] ?? '')) !== ''
                && trim((string) ($routeDraft['to_ref'] ?? '')) !== ''
            ) {
                $routeRequest = [
                    'from_ref' => (string) ($routeDraft['from_ref'] ?? ''),
                    'from_name' => (string) ($routeDraft['from_name'] ?? ''),
                    'to_ref' => (string) ($routeDraft['to_ref'] ?? ''),
                    'to_name' => (string) ($routeDraft['to_name'] ?? ''),
                    'date_it' => $parsedTravelDate,
                    'raw_message' => $messageText,
                ];
                $intent = 'route';
            }

            $routeDateIt = $parsedTravelDate;
            if (
                $routeDateIt !== ''
                && trim((string) ($routeDraft['from_ref'] ?? '')) !== ''
                && trim((string) ($routeDraft['to_ref'] ?? '')) !== ''
            ) {
                $routeRequest = [
                    'from_ref' => (string) ($routeDraft['from_ref'] ?? ''),
                    'from_name' => (string) ($routeDraft['from_name'] ?? ''),
                    'to_ref' => (string) ($routeDraft['to_ref'] ?? ''),
                    'to_name' => (string) ($routeDraft['to_name'] ?? ''),
                    'date_it' => $routeDateIt,
                    'raw_message' => $messageText,
                ];
                $intent = 'route';
            }
        }

        if (!is_array($nearbyStopsRequest) && !is_array($routeRequest)) {
            $routeRequest = cvAuthTicketChatExtractRouteRequest($connection, $messageText);
            if (is_array($routeRequest)) {
                $intent = 'route';
            }
        }

        if (
            !is_array($nearbyStopsRequest)
            && !is_array($routeRequest)
            && $parsedTravelDate !== ''
            && !empty($lastRoute['from_ref'])
            && !empty($lastRoute['to_ref'])
        ) {
            $routeRequest = [
                'from_ref' => (string) ($lastRoute['from_ref'] ?? ''),
                'from_name' => (string) ($lastRoute['from_name'] ?? ''),
                'to_ref' => (string) ($lastRoute['to_ref'] ?? ''),
                'to_name' => (string) ($lastRoute['to_name'] ?? ''),
                'date_it' => $parsedTravelDate,
                'raw_message' => $messageText,
            ];
            $intent = 'route';
        }

        // Follow-up questions like "dimmi il piu veloce / quello che arriva prima / il piu economico / ci sono sconti?"
        // Reuse the last searched route (from/to/date) even if the user doesn't repeat it.
        if (
            !is_array($nearbyStopsRequest)
            && !is_array($routeRequest)
            && $routeFollowupType !== ''
            && !empty($lastRoute['from_ref'])
            && !empty($lastRoute['to_ref'])
        ) {
            $routeRequest = [
                'from_ref' => (string) ($lastRoute['from_ref'] ?? ''),
                'from_name' => (string) ($lastRoute['from_name'] ?? ''),
                'to_ref' => (string) ($lastRoute['to_ref'] ?? ''),
                'to_name' => (string) ($lastRoute['to_name'] ?? ''),
                'date_it' => (string) ($lastRoute['date_it'] ?? ''),
                'raw_message' => $messageText,
                'followup_type' => $routeFollowupType,
            ];
            $intent = 'route';
        }

        // Follow-up for geo-based departures: user can answer with "domani" / "14 aprile" after sending [geo].
        if (
            !is_array($nearbyStopsRequest)
            && !is_array($routeRequest)
            && !is_array($geoPoint)
            && $parsedTravelDate !== ''
            && trim((string) ($routeDraft['to_ref'] ?? '')) !== ''
            && (isset($routeDraft['geo_lat'], $routeDraft['geo_lon']))
            && is_numeric($routeDraft['geo_lat'])
            && is_numeric($routeDraft['geo_lon'])
        ) {
            $geoPoint = [
                'lat' => (float) $routeDraft['geo_lat'],
                'lon' => (float) $routeDraft['geo_lon'],
            ];
        }

        if (!is_array($nearbyStopsRequest) && !is_array($routeRequest)) {
            $routeSingleStopHint = cvAuthTicketChatExtractSingleStopMention($connection, $messageText);
        }
        if (!is_array($nearbyStopsRequest) && !is_array($routeRequest) && !is_array($routeSingleStopHint)) {
            $routeTextHint = cvAuthTicketChatExtractRouteTextHint($messageText);
        }

        if (is_array($routeRequest) && $routeFollowupType !== '' && empty($routeRequest['followup_type'])) {
            $routeRequest['followup_type'] = $routeFollowupType;
        }
    }
    $knowledgeItems = !empty($settings['learning_enabled'])
        ? cvAssistantKnowledgeList($connection)
        : [];
    $knowledgeMatch = cvAssistantMatchKnowledge(
        $knowledgeItems,
        $messageText,
        $ticketFound ? strtolower(trim((string) ($ticket['provider_code'] ?? ''))) : '',
        $ticketFound
    );

    $replyData = [
        'reply' => '',
        'suggestions' => cvAuthTicketChatSuggestions($settings, $intent, $ticketFound),
        'actions' => [],
    ];
    $countAsUnresolved = false;

    $conversationPatch = [
        'context' => [
            'last_intent' => $intent,
        ],
    ];
    $knowledgeIdUsed = 0;

    if ($intent === 'cancel') {
        $replyData['reply'] = 'Ho interrotto la verifica guidata. Se vuoi, puoi farmi una nuova domanda oppure inviarmi un codice biglietto.';
        $replyData['suggestions'] = cvAuthTicketChatSuggestions($settings, 'greeting', $storedTicketCode !== '');
        $conversationPatch['context']['pending_flow'] = '';
        $conversationPatch['context']['pending_identity_field'] = '';
        $conversationPatch['context']['pending_support_ticket_field'] = '';
        $conversationPatch['context']['identity_lookup'] = [];
        $conversationPatch['context']['support_ticket_draft'] = [];
        $conversationPatch['context']['route_lookup'] = [];
    } elseif ($explicitTicketCode !== '' && !$ticketFound) {
        $replyData['reply'] = 'Non trovo il biglietto ' . $explicitTicketCode . '. Verifica il codice e riprova. Se vuoi, puoi anche incollare il QR oppure scrivermi nome, cognome e telefono del viaggiatore.';
        $replyData['suggestions'] = cvAuthTicketChatSuggestions($settings, 'recover', false);
        $countAsUnresolved = true;
    } elseif ($intent === 'greeting' && !$isPersonalTicketRequest && !$isIdentityFlowActive && !$isSupportTicketFlowActive && !$isRouteFlowActive) {
        $replyData['reply'] = (string) ($settings['welcome_message'] ?? 'Come posso esserti utile oggi?');
    } elseif ($ticketFound) {
        $replyData = cvAuthTicketChatReplyForTicket($settings, $intent, $ticket, $providerConfigs);
        $conversationPatch['ticket_code'] = (string) ($ticket['codice'] ?? '');
        $conversationPatch['provider_code'] = (string) ($ticket['provider_code'] ?? '');
        $conversationPatch['context']['last_ticket_lookup'] = date('c');
        $conversationPatch['context']['pending_flow'] = '';
        $conversationPatch['context']['pending_identity_field'] = '';
        $conversationPatch['context']['pending_support_ticket_field'] = '';
        $conversationPatch['context']['identity_lookup'] = [];
        $conversationPatch['context']['support_ticket_draft'] = [];
        $conversationPatch['context']['route_lookup'] = [];
    } elseif (is_array($identity) && ($isIdentityFlowActive || $isPersonalTicketRequest || $intent === 'recover')) {
        $matches = cvAuthTicketChatFindTicketsByIdentity($connection, $identity);
        $replyData = cvAuthTicketChatReplyForIdentityMatches($settings, $matches, $connection, $sessionKey, $sessionUserId);
        if (count($matches) === 1 && isset($replyData['ticket']) && is_array($replyData['ticket'])) {
            $conversationPatch['ticket_code'] = (string) ($replyData['ticket']['ticket_code'] ?? '');
            $conversationPatch['provider_code'] = (string) ($replyData['ticket']['provider_code'] ?? '');
            $conversationPatch['context']['last_ticket_lookup'] = date('c');
            $conversationPatch['context']['pending_flow'] = '';
            $conversationPatch['context']['pending_identity_field'] = '';
            $conversationPatch['context']['pending_support_ticket_field'] = '';
            $conversationPatch['context']['identity_lookup'] = [];
            $conversationPatch['context']['support_ticket_draft'] = [];
            $conversationPatch['context']['route_lookup'] = [];
        } elseif (count($matches) === 0) {
            $conversationPatch['ticket_code'] = '';
            $conversationPatch['provider_code'] = '';
            $conversationPatch['context']['pending_flow'] = 'identity_lookup';
            $conversationPatch['context']['pending_identity_field'] = 'name';
            $conversationPatch['context']['pending_support_ticket_field'] = '';
            $conversationPatch['context']['identity_lookup'] = [];
            $conversationPatch['context']['support_ticket_draft'] = [];
            $conversationPatch['context']['route_lookup'] = [];
        } else {
            $conversationPatch['ticket_code'] = '';
            $conversationPatch['provider_code'] = '';
            $conversationPatch['context']['pending_flow'] = '';
            $conversationPatch['context']['pending_identity_field'] = '';
            $conversationPatch['context']['pending_support_ticket_field'] = '';
            $conversationPatch['context']['identity_lookup'] = [];
            $conversationPatch['context']['support_ticket_draft'] = [];
            $conversationPatch['context']['route_lookup'] = [];
        }
    } elseif (!$isSupportTicketFlowActive && $supportWaitingState['has_active_ticket'] && !$ticketFound && $intent !== 'cancel') {
        $activeSupportTicketId = (int) ($supportWaitingState['ticket_id'] ?? 0);
        if ($activeSupportTicketId > 0) {
            cvAssistantSupportTicketAddMessage($connection, $activeSupportTicketId, 'user', $messageText, 'chat-user');
        }
        if (!empty($supportWaitingState['is_busy'])) {
            $replyData['reply'] = 'I nostri operatori sono momentaneamente occupati. Riprova piu tardi o lascia un messaggio.';
            $replyData['suggestions'] = ['Riprova piu tardi', 'Lascia un messaggio', 'Annulla'];
        } else {
            $replyData['reply'] = 'Ho inoltrato il messaggio al team. Ti risponderemo appena possibile in questa chat.';
            $replyData['suggestions'] = ['Lascia un messaggio', 'Riprova piu tardi', 'Annulla'];
        }
        $conversationPatch['context']['pending_flow'] = '';
        $conversationPatch['context']['pending_support_ticket_field'] = '';
        $conversationPatch['context']['support_ticket_draft'] = [];
        $conversationPatch['context']['pending_identity_field'] = '';
        $conversationPatch['context']['identity_lookup'] = [];
        $conversationPatch['context']['route_lookup'] = [];
        $countAsUnresolved = true;
    } elseif ($intent === 'support_ticket' && !empty($settings['ticketing_enabled']) && !$operatorAllowedNow && !$isSupportTicketFlowActive && !$ticketFound) {
        $replyData['reply'] = 'Posso ancora provare ad aiutarti qui in chat. Se non risolviamo, ti passo un operatore.';
        $replyData['suggestions'] = cvAuthTicketChatSuggestions($settings, 'greeting', false);
        $countAsUnresolved = true;
    } elseif (($isSupportTicketFlowActive || ($intent === 'support_ticket' && $operatorAllowedNow)) && !$ticketFound) {
        if (empty($settings['ticketing_enabled'])) {
            $replyData['reply'] = 'Il ticket assistenza da chat al momento non e attivo.';
            $replyData['suggestions'] = cvAuthTicketChatSuggestions($settings, 'greeting', false);
            $conversationPatch['context']['pending_flow'] = '';
            $conversationPatch['context']['pending_support_ticket_field'] = '';
            $conversationPatch['context']['support_ticket_draft'] = [];
        } elseif (!$isSupportTicketFlowActive || $pendingSupportTicketField === '') {
            if ($existingSupportTicketId <= 0) {
                try {
                    $existingSupportTicketId = cvAssistantSupportTicketCreate($connection, [
                        'session_key' => $sessionKey,
                        'id_conversation' => (int) ($conversation['id_conversation'] ?? 0),
                        'channel' => 'web',
                        'status' => 'open',
                        'subject' => $storedTicketCode !== '' ? ('Assistenza ticket ' . $storedTicketCode) : 'Richiesta operatore da chat',
                        'customer_name' => 'Cliente chat',
                        'customer_email' => '',
                        'customer_phone' => '',
                        'provider_code' => strtolower(trim((string) ($conversation['provider_code'] ?? ''))),
                        'ticket_code' => $storedTicketCode,
                        'created_by' => 'chat',
                        'message_text' => $isExplicitOperatorTrigger
                            ? 'Richiesta operatore avviata dalla chat'
                            : ($messageText !== '' ? $messageText : 'Richiesta operatore avviata dalla chat'),
                    ]);
                } catch (Throwable $exception) {
                    if ($collectLogs) {
                        cvAuthTicketChatDebugLog('provisional_ticket_error', [
                            'session_key' => $sessionKey,
                            'error' => $exception->getMessage(),
                        ]);
                    }
                }
            }
            if ($isExplicitOperatorTrigger) {
                $replyData['reply'] = 'Attendi, stiamo per trasferirti con un operatore.';
                $replyData['suggestions'] = ['Lascia un messaggio', 'Annulla'];
                $conversationPatch['context']['pending_flow'] = '';
                $conversationPatch['context']['pending_support_ticket_field'] = '';
                $conversationPatch['context']['support_ticket_draft'] = [];
                $conversationPatch['context']['support_ticket_id'] = $existingSupportTicketId;
            } else {
                $replyData['reply'] = cvAuthTicketChatSupportTicketPrompt('name');
                $replyData['suggestions'] = ['Annulla', 'Come recupero il biglietto?', 'Posso acquistare da ospite?'];
                $conversationPatch['context']['pending_flow'] = 'support_ticket';
                $conversationPatch['context']['pending_support_ticket_field'] = 'name';
                $conversationPatch['context']['support_ticket_draft'] = [];
                $conversationPatch['context']['support_ticket_id'] = $existingSupportTicketId;
            }
            $conversationPatch['context']['pending_identity_field'] = '';
            $conversationPatch['context']['identity_lookup'] = [];
            $conversationPatch['context']['route_lookup'] = [];
        } else {
            $fieldValue = cvAuthTicketChatNormalizeSupportTicketField($pendingSupportTicketField, $messageText);
            if ($fieldValue === '') {
                $replyData['reply'] = cvAuthTicketChatSupportTicketErrorPrompt($pendingSupportTicketField);
                $replyData['suggestions'] = ['Annulla'];
                $conversationPatch['context']['pending_flow'] = 'support_ticket';
                $conversationPatch['context']['pending_support_ticket_field'] = $pendingSupportTicketField;
                $conversationPatch['context']['support_ticket_draft'] = $supportTicketDraft;
            } else {
                $supportTicketDraft[$pendingSupportTicketField] = $fieldValue;
                if ($pendingSupportTicketField === 'name') {
                    $replyData['reply'] = cvAuthTicketChatSupportTicketPrompt('email', $supportTicketDraft);
                    $replyData['suggestions'] = ['Annulla'];
                    $conversationPatch['context']['pending_flow'] = 'support_ticket';
                    $conversationPatch['context']['pending_support_ticket_field'] = 'email';
                    $conversationPatch['context']['support_ticket_draft'] = $supportTicketDraft;
                    $conversationPatch['context']['support_ticket_id'] = $existingSupportTicketId;
                } elseif ($pendingSupportTicketField === 'email') {
                    $replyData['reply'] = cvAuthTicketChatSupportTicketPrompt('phone', $supportTicketDraft);
                    $replyData['suggestions'] = ['Annulla'];
                    $conversationPatch['context']['pending_flow'] = 'support_ticket';
                    $conversationPatch['context']['pending_support_ticket_field'] = 'phone';
                    $conversationPatch['context']['support_ticket_draft'] = $supportTicketDraft;
                    $conversationPatch['context']['support_ticket_id'] = $existingSupportTicketId;
                } elseif ($pendingSupportTicketField === 'phone') {
                    $replyData['reply'] = cvAuthTicketChatSupportTicketPrompt('message', $supportTicketDraft);
                    $replyData['suggestions'] = ['Annulla'];
                    $conversationPatch['context']['pending_flow'] = 'support_ticket';
                    $conversationPatch['context']['pending_support_ticket_field'] = 'message';
                    $conversationPatch['context']['support_ticket_draft'] = $supportTicketDraft;
                    $conversationPatch['context']['support_ticket_id'] = $existingSupportTicketId;
                } else {
                    try {
                        $newTicketId = $existingSupportTicketId;
                        if ($newTicketId > 0) {
                            $subjectValue = $storedTicketCode !== '' ? ('Assistenza ticket ' . $storedTicketCode) : 'Richiesta assistenza da chat';
                            $customerNameValue = trim((string) ($supportTicketDraft['name'] ?? ''));
                            if ($customerNameValue === '') {
                                $customerNameValue = 'Cliente chat';
                            }
                            $customerEmailValue = trim((string) ($supportTicketDraft['email'] ?? ''));
                            $customerPhoneValue = trim((string) ($supportTicketDraft['phone'] ?? ''));
                            $providerCodeValue = strtolower(trim((string) ($conversation['provider_code'] ?? '')));
                            $ticketCodeValue = $storedTicketCode;
                            $updateStmt = $connection->prepare('UPDATE cv_assistant_support_tickets
                                SET subject = ?, customer_name = ?, customer_email = ?, customer_phone = ?, provider_code = ?, ticket_code = ?, status = "open", updated_at = CURRENT_TIMESTAMP
                                WHERE id_ticket = ? LIMIT 1');
                            if ($updateStmt instanceof mysqli_stmt) {
                                $updateStmt->bind_param(
                                    'ssssssi',
                                    $subjectValue,
                                    $customerNameValue,
                                    $customerEmailValue,
                                    $customerPhoneValue,
                                    $providerCodeValue,
                                    $ticketCodeValue,
                                    $newTicketId
                                );
                                $updateStmt->execute();
                                $updateStmt->close();
                            }
                            cvAssistantSupportTicketAddMessage(
                                $connection,
                                $newTicketId,
                                'user',
                                (string) ($supportTicketDraft['message'] ?? ''),
                                $customerNameValue
                            );
                        } else {
                            $supportSubject = $storedTicketCode !== ''
                                ? ('Assistenza ticket ' . $storedTicketCode)
                                : 'Richiesta assistenza da chat';
                            $newTicketId = cvAssistantSupportTicketCreate($connection, [
                                'session_key' => $sessionKey,
                                'id_conversation' => (int) ($conversation['id_conversation'] ?? 0),
                                'channel' => 'web',
                                'status' => 'open',
                                'subject' => $supportSubject,
                                'customer_name' => (string) ($supportTicketDraft['name'] ?? ''),
                                'customer_email' => (string) ($supportTicketDraft['email'] ?? ''),
                                'customer_phone' => (string) ($supportTicketDraft['phone'] ?? ''),
                                'provider_code' => strtolower(trim((string) ($conversation['provider_code'] ?? ''))),
                                'ticket_code' => $storedTicketCode,
                                'created_by' => 'chat',
                                'message_text' => (string) ($supportTicketDraft['message'] ?? ''),
                            ]);
                        }
                        $replyData['reply'] = 'Ho aperto il ticket assistenza #' . $newTicketId . '. Il team potra risponderti dal backend e la risposta comparira anche nella chat. Se hai indicato un’email valida, potrai essere ricontattato anche li.';
                        $replyData['suggestions'] = ['Domande frequenti', 'Come recupero il biglietto?', 'Controlla stato biglietto'];
                        $conversationPatch['context']['pending_flow'] = '';
                        $conversationPatch['context']['pending_support_ticket_field'] = '';
                        $conversationPatch['context']['support_ticket_draft'] = [];
                        $conversationPatch['context']['support_ticket_id'] = 0;
                        $conversationPatch['context']['pending_identity_field'] = '';
                        $conversationPatch['context']['identity_lookup'] = [];
                        $conversationPatch['context']['route_lookup'] = [];
                        $countAsUnresolved = false;
                    } catch (Throwable $exception) {
                        $replyData['reply'] = 'Non riesco ad aprire il ticket assistenza in questo momento. Riprova tra poco.';
                        $replyData['suggestions'] = ['Annulla', 'Come recupero il biglietto?', 'Posso acquistare da ospite?'];
                        $conversationPatch['context']['pending_flow'] = 'support_ticket';
                        $conversationPatch['context']['pending_support_ticket_field'] = 'message';
                        $conversationPatch['context']['support_ticket_draft'] = $supportTicketDraft;
                        $conversationPatch['context']['support_ticket_id'] = $existingSupportTicketId;
                        $countAsUnresolved = true;
                    }
                }
                $conversationPatch['context']['pending_identity_field'] = '';
                $conversationPatch['context']['identity_lookup'] = [];
            }
        }
    } elseif (is_array($nearbyStopsRequest) && !$isPersonalTicketRequest) {
        $replyData = cvAuthTicketChatReplyForNearbyStops($nearbyStopsRequest);
        $conversationPatch['ticket_code'] = '';
        $conversationPatch['provider_code'] = '';
        $conversationPatch['context']['pending_flow'] = '';
        $conversationPatch['context']['pending_identity_field'] = '';
        $conversationPatch['context']['pending_support_ticket_field'] = '';
        $conversationPatch['context']['identity_lookup'] = [];
        $conversationPatch['context']['support_ticket_draft'] = [];
        $conversationPatch['context']['route_lookup'] = [];
    } elseif (is_array($routeRequest) && !$isPersonalTicketRequest) {
        $replyData = cvAuthTicketChatReplyForRouteRequest($connection, $settings, $routeRequest);
        if (!empty($replyData['route_date_pending'])) {
            $conversationPatch['ticket_code'] = '';
            $conversationPatch['provider_code'] = '';
            $conversationPatch['context']['pending_flow'] = 'route_lookup';
            $conversationPatch['context']['pending_identity_field'] = '';
            $conversationPatch['context']['pending_support_ticket_field'] = '';
            $conversationPatch['context']['identity_lookup'] = [];
            $conversationPatch['context']['support_ticket_draft'] = [];
            $conversationPatch['context']['route_lookup'] = [
                'from_ref' => (string) ($routeRequest['from_ref'] ?? ''),
                'from_name' => (string) ($routeRequest['from_name'] ?? ''),
                'to_ref' => (string) ($routeRequest['to_ref'] ?? ''),
                'to_name' => (string) ($routeRequest['to_name'] ?? ''),
            ];
        } else {
            $conversationPatch['context']['pending_flow'] = '';
            $conversationPatch['context']['pending_identity_field'] = '';
            $conversationPatch['context']['pending_support_ticket_field'] = '';
            $conversationPatch['context']['identity_lookup'] = [];
            $conversationPatch['context']['support_ticket_draft'] = [];
            $conversationPatch['context']['route_lookup'] = [];
        }
        if (isset($replyData['route']) && is_array($replyData['route'])) {
            $conversationPatch['context']['last_route'] = [
                'from_ref' => (string) ($replyData['route']['from_ref'] ?? ''),
                'from_name' => (string) ($replyData['route']['from_name'] ?? ''),
                'to_ref' => (string) ($replyData['route']['to_ref'] ?? ''),
                'to_name' => (string) ($replyData['route']['to_name'] ?? ''),
                'date_it' => (string) ($replyData['route']['date_it'] ?? ''),
            ];
        }
    } elseif (is_array($routeSingleStopHint) && !$isPersonalTicketRequest) {
        $hintRole = trim((string) ($routeSingleStopHint['role'] ?? ''));
        $hintRef = trim((string) ($routeSingleStopHint['ref'] ?? ''));
        $hintName = trim((string) ($routeSingleStopHint['name'] ?? ''));
        if ($hintRole === 'from') {
            $replyData['reply'] = 'Perfetto, parti da ' . $hintName . '. Dove vuoi arrivare?';
            $replyData['suggestions'] = ['Verso Sapri', 'Verso Roma', 'Annulla'];
            $conversationPatch['context']['pending_flow'] = 'route_lookup';
            $conversationPatch['context']['route_lookup'] = [
                'from_ref' => $hintRef,
                'from_name' => $hintName,
                'to_ref' => '',
                'to_name' => '',
            ];
        } else {
            $replyData['reply'] = 'Perfetto, vuoi arrivare a ' . $hintName . '. Da dove parti? Se vuoi, posso usare la tua posizione corrente.';
            $replyData['suggestions'] = ['Usa posizione corrente', 'Da Roma', 'Altrimenti indicami una localita di partenza'];
            $conversationPatch['context']['pending_flow'] = 'route_lookup';
            $conversationPatch['context']['route_lookup'] = [
                'from_ref' => '',
                'from_name' => '',
                'to_ref' => $hintRef,
                'to_name' => $hintName,
            ];
        }
        $conversationPatch['ticket_code'] = '';
        $conversationPatch['provider_code'] = '';
        $conversationPatch['context']['pending_identity_field'] = '';
        $conversationPatch['context']['pending_support_ticket_field'] = '';
        $conversationPatch['context']['identity_lookup'] = [];
        $conversationPatch['context']['support_ticket_draft'] = [];
    } elseif (is_array($geoPoint) && !$isPersonalTicketRequest) {
        $geoReply = cvAuthTicketChatReplyForGeoDepartures($connection, $routeDraft, $geoPoint, $parsedTravelDate);
        $replyData['reply'] = (string) ($geoReply['reply'] ?? 'Altrimenti indicami una localita di partenza.');
        $replyData['suggestions'] = isset($geoReply['suggestions']) && is_array($geoReply['suggestions'])
            ? $geoReply['suggestions']
            : ['Altrimenti indicami una localita di partenza'];
        $replyData['actions'] = isset($geoReply['actions']) && is_array($geoReply['actions']) ? $geoReply['actions'] : [];
        $routeLookup = isset($geoReply['route_lookup']) && is_array($geoReply['route_lookup']) ? $geoReply['route_lookup'] : [];
        $conversationPatch['context']['pending_flow'] = 'route_lookup';
        $conversationPatch['context']['route_lookup'] = [
            'from_ref' => (string) ($routeLookup['from_ref'] ?? ''),
            'from_name' => (string) ($routeLookup['from_name'] ?? ''),
            'to_ref' => (string) ($routeLookup['to_ref'] ?? ''),
            'to_name' => (string) ($routeLookup['to_name'] ?? ''),
            'geo_lat' => isset($routeLookup['geo_lat']) && is_numeric($routeLookup['geo_lat']) ? (float) $routeLookup['geo_lat'] : null,
            'geo_lon' => isset($routeLookup['geo_lon']) && is_numeric($routeLookup['geo_lon']) ? (float) $routeLookup['geo_lon'] : null,
        ];
        $conversationPatch['ticket_code'] = '';
        $conversationPatch['provider_code'] = '';
        $conversationPatch['context']['pending_identity_field'] = '';
        $conversationPatch['context']['pending_support_ticket_field'] = '';
        $conversationPatch['context']['identity_lookup'] = [];
        $conversationPatch['context']['support_ticket_draft'] = [];
    } elseif (is_array($routeTextHint) && !$isPersonalTicketRequest) {
        $hintFromLabel = trim((string) ($routeTextHint['from_label'] ?? ''));
        $hintToLabel = trim((string) ($routeTextHint['to_label'] ?? ''));
        if ($hintToLabel !== '' && $hintFromLabel === '') {
            $replyData['reply'] = 'Perfetto, vuoi arrivare a ' . ucfirst($hintToLabel) . '. Da dove parti? Se vuoi, posso usare la tua posizione corrente.';
            $replyData['suggestions'] = ['Usa posizione corrente', 'Da Roma', 'Altrimenti indicami una localita di partenza'];
            $conversationPatch['context']['pending_flow'] = 'route_lookup';
            $conversationPatch['context']['route_lookup'] = [
                'from_ref' => '',
                'from_name' => '',
                'to_ref' => '',
                'to_name' => ucfirst($hintToLabel),
            ];
        } elseif ($hintFromLabel !== '' && $hintToLabel === '') {
            $replyData['reply'] = 'Perfetto, parti da ' . ucfirst($hintFromLabel) . '. Dove vuoi arrivare?';
            $replyData['suggestions'] = ['Verso Sapri', 'Verso Roma', 'Annulla'];
            $conversationPatch['context']['pending_flow'] = 'route_lookup';
            $conversationPatch['context']['route_lookup'] = [
                'from_ref' => '',
                'from_name' => ucfirst($hintFromLabel),
                'to_ref' => '',
                'to_name' => '',
            ];
        } else {
            $replyData['reply'] = 'Ti aiuto subito con la tratta. Ti va bene se cerchiamo ' . ucfirst($hintFromLabel) . ' -> ' . ucfirst($hintToLabel) . '?';
            $replyData['suggestions'] = ['Oggi', 'Domani', 'Cerca un altra tratta'];
            $conversationPatch['context']['pending_flow'] = 'route_lookup';
            $conversationPatch['context']['route_lookup'] = [
                'from_ref' => '',
                'from_name' => ucfirst($hintFromLabel),
                'to_ref' => '',
                'to_name' => ucfirst($hintToLabel),
            ];
        }
        $conversationPatch['ticket_code'] = '';
        $conversationPatch['provider_code'] = '';
        $conversationPatch['context']['pending_identity_field'] = '';
        $conversationPatch['context']['pending_support_ticket_field'] = '';
        $conversationPatch['context']['identity_lookup'] = [];
        $conversationPatch['context']['support_ticket_draft'] = [];
    } elseif (
        is_array($knowledgeMatch)
        && !$isPersonalTicketRequest
        && in_array($intent, ['faq', 'generic', 'greeting'], true)
    ) {
        $replyData['reply'] = trim((string) ($knowledgeMatch['answer_text'] ?? ''));
        $replyData['suggestions'] = cvAuthTicketChatSuggestions($settings, 'faq', false);
        $knowledgeIdUsed = isset($knowledgeMatch['id_knowledge']) ? (int) ($knowledgeMatch['id_knowledge'] ?? 0) : 0;
        cvAssistantIncrementKnowledgeHit($connection, $knowledgeIdUsed);
    } elseif ($intent === 'change' && !$ticketFound && $explicitTicketCode === '') {
        $replyData['reply'] = cvAuthTicketChatChangeSupportPrompt(!empty($settings['ticketing_enabled']));
        $replyData['suggestions'] = ['Ho il codice biglietto', 'Non ho il codice', 'Apri ticket assistenza'];
        if (empty($settings['ticketing_enabled'])) {
            $replyData['suggestions'][2] = 'Come recupero il biglietto?';
        }
        $conversationPatch['ticket_code'] = '';
        $conversationPatch['provider_code'] = '';
        $conversationPatch['context']['pending_flow'] = '';
        $conversationPatch['context']['pending_identity_field'] = '';
        $conversationPatch['context']['pending_support_ticket_field'] = '';
        $conversationPatch['context']['identity_lookup'] = [];
        $conversationPatch['context']['support_ticket_draft'] = [];
        $conversationPatch['context']['route_lookup'] = [];
        $countAsUnresolved = true;
    } elseif ($intent === 'recover' && !$ticketFound && !$isIdentityFlowActive && $explicitTicketCode === '') {
        $replyData['reply'] = cvAuthTicketChatIdentityPrompt('name');
        $replyData['suggestions'] = ['Ho il codice biglietto', 'Come recupero il biglietto?', 'Annulla'];
        $conversationPatch['ticket_code'] = '';
        $conversationPatch['provider_code'] = '';
        $conversationPatch['context']['pending_flow'] = 'identity_lookup';
        $conversationPatch['context']['pending_identity_field'] = 'name';
        $conversationPatch['context']['pending_support_ticket_field'] = '';
        $conversationPatch['context']['identity_lookup'] = [];
        $conversationPatch['context']['support_ticket_draft'] = [];
        $conversationPatch['context']['route_lookup'] = [];
    } elseif ($intent === 'faq' && !$ticketFound && !$isPersonalTicketRequest) {
        $replyData = cvAuthTicketChatFaqMenu($knowledgeItems, $settings);
    } elseif ($isIdentityFlowActive && $explicitTicketCode === '') {
        if ($pendingIdentityField === '') {
            $replyData['reply'] = cvAuthTicketChatIdentityPrompt('name');
            $replyData['suggestions'] = ['Ho il codice biglietto', 'Posso acquistare da ospite?', 'Come recupero il biglietto?'];
            $conversationPatch['ticket_code'] = '';
            $conversationPatch['provider_code'] = '';
            $conversationPatch['context']['pending_flow'] = 'identity_lookup';
            $conversationPatch['context']['pending_identity_field'] = 'name';
            $conversationPatch['context']['pending_support_ticket_field'] = '';
            $conversationPatch['context']['identity_lookup'] = [];
            $conversationPatch['context']['support_ticket_draft'] = [];
        } else {
            $fieldValue = cvAuthTicketChatNormalizeIdentityField($pendingIdentityField, $messageText);
            if ($fieldValue === '') {
                $replyData['reply'] = cvAuthTicketChatIdentityErrorPrompt($pendingIdentityField);
                $replyData['suggestions'] = ['Ho il codice biglietto', 'Come recupero il biglietto?', 'Posso acquistare da ospite?'];
                $conversationPatch['ticket_code'] = '';
                $conversationPatch['provider_code'] = '';
                $conversationPatch['context']['pending_flow'] = 'identity_lookup';
                $conversationPatch['context']['pending_identity_field'] = $pendingIdentityField;
                $conversationPatch['context']['pending_support_ticket_field'] = '';
                $conversationPatch['context']['identity_lookup'] = $identityDraft;
                $conversationPatch['context']['support_ticket_draft'] = [];
            } else {
                $identityDraft[$pendingIdentityField] = $fieldValue;
                if ($pendingIdentityField === 'name') {
                    $replyData['reply'] = cvAuthTicketChatIdentityPrompt('surname', $identityDraft);
                    $replyData['suggestions'] = ['Ho il codice biglietto', 'Come recupero il biglietto?', 'Posso acquistare da ospite?'];
                    $conversationPatch['ticket_code'] = '';
                    $conversationPatch['provider_code'] = '';
                    $conversationPatch['context']['pending_flow'] = 'identity_lookup';
                    $conversationPatch['context']['pending_identity_field'] = 'surname';
                    $conversationPatch['context']['pending_support_ticket_field'] = '';
                    $conversationPatch['context']['identity_lookup'] = $identityDraft;
                    $conversationPatch['context']['support_ticket_draft'] = [];
                } elseif ($pendingIdentityField === 'surname') {
                    $replyData['reply'] = cvAuthTicketChatIdentityPrompt('phone', $identityDraft);
                    $replyData['suggestions'] = ['Ho il codice biglietto', 'Come recupero il biglietto?', 'Posso acquistare da ospite?'];
                    $conversationPatch['ticket_code'] = '';
                    $conversationPatch['provider_code'] = '';
                    $conversationPatch['context']['pending_flow'] = 'identity_lookup';
                    $conversationPatch['context']['pending_identity_field'] = 'phone';
                    $conversationPatch['context']['pending_support_ticket_field'] = '';
                    $conversationPatch['context']['identity_lookup'] = $identityDraft;
                    $conversationPatch['context']['support_ticket_draft'] = [];
                } else {
                    $lookupIdentity = [
                        'name' => trim((string) ($identityDraft['name'] ?? '')),
                        'surname' => trim((string) ($identityDraft['surname'] ?? '')),
                        'phone' => trim((string) ($identityDraft['phone'] ?? '')),
                    ];
                    if ($lookupIdentity['name'] === '' || $lookupIdentity['surname'] === '' || $lookupIdentity['phone'] === '') {
                        $replyData['reply'] = cvAuthTicketChatIdentityPrompt('name');
                        $replyData['suggestions'] = ['Ho il codice biglietto', 'Come recupero il biglietto?', 'Posso acquistare da ospite?'];
                        $conversationPatch['ticket_code'] = '';
                        $conversationPatch['provider_code'] = '';
                        $conversationPatch['context']['pending_flow'] = 'identity_lookup';
                        $conversationPatch['context']['pending_identity_field'] = 'name';
                        $conversationPatch['context']['pending_support_ticket_field'] = '';
                        $conversationPatch['context']['identity_lookup'] = [];
                        $conversationPatch['context']['support_ticket_draft'] = [];
                        $conversationPatch['context']['route_lookup'] = [];
                    } else {
                        $matches = cvAuthTicketChatFindTicketsByIdentity($connection, $lookupIdentity);
                        $replyData = cvAuthTicketChatReplyForIdentityMatches($settings, $matches, $connection, $sessionKey, $sessionUserId);
                        if (count($matches) === 1 && isset($replyData['ticket']) && is_array($replyData['ticket'])) {
                            $conversationPatch['ticket_code'] = (string) ($replyData['ticket']['ticket_code'] ?? '');
                            $conversationPatch['provider_code'] = (string) ($replyData['ticket']['provider_code'] ?? '');
                            $conversationPatch['context']['last_ticket_lookup'] = date('c');
                            $conversationPatch['context']['pending_flow'] = '';
                            $conversationPatch['context']['pending_identity_field'] = '';
                            $conversationPatch['context']['pending_support_ticket_field'] = '';
                            $conversationPatch['context']['identity_lookup'] = [];
                            $conversationPatch['context']['support_ticket_draft'] = [];
                            $conversationPatch['context']['route_lookup'] = [];
                        } elseif (count($matches) === 0) {
                            $conversationPatch['ticket_code'] = '';
                            $conversationPatch['provider_code'] = '';
                            $conversationPatch['context']['pending_flow'] = 'identity_lookup';
                            $conversationPatch['context']['pending_identity_field'] = 'name';
                            $conversationPatch['context']['pending_support_ticket_field'] = '';
                            $conversationPatch['context']['identity_lookup'] = [];
                            $conversationPatch['context']['support_ticket_draft'] = [];
                            $conversationPatch['context']['route_lookup'] = [];
                        } else {
                            $conversationPatch['ticket_code'] = '';
                            $conversationPatch['provider_code'] = '';
                            $conversationPatch['context']['pending_flow'] = '';
                            $conversationPatch['context']['pending_identity_field'] = '';
                            $conversationPatch['context']['pending_support_ticket_field'] = '';
                            $conversationPatch['context']['identity_lookup'] = [];
                            $conversationPatch['context']['support_ticket_draft'] = [];
                            $conversationPatch['context']['route_lookup'] = [];
                        }
                    }
                }
            }
        }
    } elseif ($isPersonalTicketRequest) {
        $replyData['reply'] = cvAuthTicketChatIdentityPrompt('name');
        $replyData['suggestions'] = ['Ho il codice biglietto', 'Come recupero il biglietto?', 'Posso acquistare da ospite?'];
        $conversationPatch['ticket_code'] = '';
        $conversationPatch['provider_code'] = '';
        $conversationPatch['context']['pending_flow'] = 'identity_lookup';
        $conversationPatch['context']['pending_identity_field'] = 'name';
        $conversationPatch['context']['pending_support_ticket_field'] = '';
        $conversationPatch['context']['identity_lookup'] = [];
        $conversationPatch['context']['support_ticket_draft'] = [];
        $conversationPatch['context']['route_lookup'] = [];
    } elseif ($isRouteFlowActive) {
        $draftFromRef = trim((string) ($routeDraft['from_ref'] ?? ''));
        $draftToRef = trim((string) ($routeDraft['to_ref'] ?? ''));
        $draftFromName = trim((string) ($routeDraft['from_name'] ?? ''));
        $draftToName = trim((string) ($routeDraft['to_name'] ?? ''));
        if ($draftFromRef === '' && ($draftToRef !== '' || $draftToName !== '')) {
            $targetLabel = $draftToName !== '' ? $draftToName : 'la destinazione indicata';
            $replyData['reply'] = 'Perfetto, vuoi arrivare a ' . $targetLabel . '. Da dove parti? Se vuoi, posso usare la tua posizione corrente.';
            $replyData['suggestions'] = ['Usa posizione corrente', 'Da Roma', 'Altrimenti indicami una localita di partenza'];
        } elseif (($draftToRef === '' && $draftToName === '') && ($draftFromRef !== '' || $draftFromName !== '')) {
            $originLabel = $draftFromName !== '' ? $draftFromName : 'la partenza indicata';
            $replyData['reply'] = 'Perfetto, parti da ' . $originLabel . '. Dove vuoi arrivare?';
            $replyData['suggestions'] = ['Verso Sapri', 'Verso Roma', 'Annulla'];
        } else {
            $replyData['reply'] = 'Per dirti gli orari della tratta ' . $draftFromName . ' -> ' . $draftToName . ' scrivimi la data di partenza. Va bene ad esempio: oggi, domani oppure 31/03/2026.';
            $replyData['suggestions'] = ['Oggi', 'Domani', '31/03/2026'];
        }
        $conversationPatch['context']['pending_flow'] = 'route_lookup';
        $conversationPatch['context']['route_lookup'] = $routeDraft;
        $conversationPatch['context']['pending_identity_field'] = '';
        $conversationPatch['context']['pending_support_ticket_field'] = '';
        $conversationPatch['context']['identity_lookup'] = [];
        $conversationPatch['context']['support_ticket_draft'] = [];
    } elseif (cvAuthTicketChatIntentNeedsTicket($intent)) {
        $replyData['reply'] = cvAuthTicketChatTechnicalPrompt();
        $replyData['suggestions'] = cvAuthTicketChatSuggestions($settings, 'recover', false);
        $countAsUnresolved = true;
    } else {
        if (cvAuthTicketChatLooksLikeRouteRequest($messageText) || is_array(cvAuthTicketChatExtractRouteTextHint($messageText))) {
            $replyData['reply'] = 'Ti aiuto subito con la tratta. Dove vuoi arrivare?';
            $replyData['suggestions'] = ['Verso Sapri', 'Verso Roma', 'Da Napoli a Sapri'];
            $conversationPatch['context']['pending_flow'] = 'route_lookup';
            $conversationPatch['context']['route_lookup'] = [
                'from_ref' => '',
                'from_name' => '',
                'to_ref' => '',
                'to_name' => '',
            ];
            $conversationPatch['context']['pending_identity_field'] = '';
            $conversationPatch['context']['pending_support_ticket_field'] = '';
            $conversationPatch['context']['identity_lookup'] = [];
            $conversationPatch['context']['support_ticket_draft'] = [];
        } else {
            $replyData['reply'] = (string) ($settings['fallback_message'] ?? 'Scrivimi la tua domanda oppure indicami il codice biglietto.');
            $countAsUnresolved = true;
        }
    }

    $replyData['suggestions'] = isset($replyData['suggestions']) && is_array($replyData['suggestions'])
        ? array_values(array_slice(array_filter(
            $replyData['suggestions'],
            static function ($value): bool {
                return trim((string) $value) !== '';
            }
        ), 0, 3))
        : [];
    $conversationPatch['context']['last_suggestions'] = $replyData['suggestions'];
    $nextOperatorUnresolvedCount = $countAsUnresolved ? min(99, $operatorUnresolvedCount + 1) : 0;
    if (($intent === 'support_ticket' && $operatorAllowedNow) || $ticketFound) {
        $nextOperatorUnresolvedCount = 0;
    }
    $conversationPatch['context']['operator_unresolved_count'] = $nextOperatorUnresolvedCount;
    $operatorUnresolvedCount = $nextOperatorUnresolvedCount;

    if ((int) ($conversation['id_conversation'] ?? 0) > 0) {
        $conversation = cvAssistantConversationUpdate($connection, $conversation, $conversationPatch);
    }

    if ($collectLogs && (int) ($conversation['id_conversation'] ?? 0) > 0 && trim((string) ($replyData['reply'] ?? '')) !== '') {
        $replyTicketCode = '';
        if ($ticketFound) {
            $replyTicketCode = (string) ($ticket['codice'] ?? '');
        } elseif (isset($replyData['ticket']) && is_array($replyData['ticket'])) {
            $replyTicketCode = (string) ($replyData['ticket']['ticket_code'] ?? '');
        }
        cvAssistantLogMessage(
            $connection,
            (int) $conversation['id_conversation'],
            'assistant',
            (string) $replyData['reply'],
            $intent,
            0.85,
            [
                'ticket_code' => $replyTicketCode,
                'knowledge_id' => $knowledgeIdUsed,
                'actions' => $replyData['actions'] ?? [],
            ]
        );
    }

    $supportWaitingState = cvAuthTicketChatSupportWaitingState($connection, $sessionKey, $operatorBusyTimeoutMinutes);
    $operatorAvailableResponse = !empty($settings['ticketing_enabled'])
        && !$supportWaitingState['has_active_ticket']
        && $operatorUnresolvedCount >= $operatorThreshold;
    if ($operatorAvailableResponse) {
        $replyData['suggestions'][] = $operatorLabel;
        $replyData['suggestions'] = array_values(array_unique(array_filter(
            $replyData['suggestions'],
            static function ($value): bool {
                return trim((string) $value) !== '';
            }
        )));
        if ($intent !== 'support_ticket' && trim((string) ($replyData['reply'] ?? '')) !== '') {
            $replyData['reply'] .= "\n\nSe preferisci, puoi anche chattare con un operatore.";
        }
    }
    if ($collectLogs) {
        cvAuthTicketChatDebugLog('message', [
            'api_file' => __FILE__,
            'api_build' => 'cv-chat-2026-04-07a',
            'session_key' => $sessionKey,
            'intent' => $intent,
            'message' => $messageText,
            'parsed_date_it' => $parsedTravelDate,
            'route_request' => $routeRequest,
            'unresolved_count' => $operatorUnresolvedCount,
            'threshold' => $operatorThreshold,
            'operator_available' => $operatorAvailableResponse,
            'operator_allowed_now' => $operatorAllowedNow,
            'has_active_ticket' => !empty($supportWaitingState['has_active_ticket']),
            'count_as_unresolved' => $countAsUnresolved,
        ]);
    }

    cvAuthResponse(true, 'Risposta assistente generata.', [
        'session_key' => $sessionKey,
        'reply' => (string) ($replyData['reply'] ?? ''),
        'suggestions' => isset($replyData['suggestions']) && is_array($replyData['suggestions']) ? array_values($replyData['suggestions']) : [],
        'actions' => isset($replyData['actions']) && is_array($replyData['actions']) ? array_values($replyData['actions']) : [],
        'intent' => $intent,
        'found' => $ticketFound || (isset($replyData['ticket']) && is_array($replyData['ticket']) && trim((string) ($replyData['ticket']['ticket_code'] ?? '')) !== ''),
        'ticket' => isset($replyData['ticket']) && is_array($replyData['ticket']) ? $replyData['ticket'] : [],
        'operator' => [
            'available' => $operatorAvailableResponse,
            'label' => $operatorLabel,
            'threshold' => $operatorThreshold,
            'unresolved_count' => $operatorUnresolvedCount,
            'busy_timeout_minutes' => $operatorBusyTimeoutMinutes,
            'has_active_ticket' => !empty($supportWaitingState['has_active_ticket']),
            'active_ticket_id' => (int) ($supportWaitingState['ticket_id'] ?? 0),
            'is_busy' => !empty($supportWaitingState['is_busy']),
        ],
    ]);
}

function cvAuthTicketPdfSafeText(string $value, int $limit = 120): string
{
    $value = trim($value);
    if ($value === '') {
        return '-';
    }

    $value = preg_replace('/\s+/u', ' ', $value);
    if (!is_string($value) || $value === '') {
        return '-';
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($value, 'UTF-8') > $limit) {
            $value = rtrim((string) mb_substr($value, 0, max(1, $limit - 3), 'UTF-8')) . '...';
        }
    } elseif (strlen($value) > $limit) {
        $value = rtrim(substr($value, 0, max(1, $limit - 3))) . '...';
    }

    $latin = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $value);
    return is_string($latin) && $latin !== '' ? $latin : '-';
}

function cvAuthTicketPdfFormatDateTime(string $value): string
{
    $raw = trim($value);
    if ($raw === '') {
        return '-';
    }

    $ts = strtotime($raw);
    if ($ts === false || $ts <= 0) {
        return cvAuthTicketPdfSafeText($raw, 32);
    }

    return date('d/m/Y H:i', $ts);
}

/**
 * @param array<string,mixed> $ticket
 * @param array<string,mixed> $providerCompany
 */
function cvAuthGenerateTicketPdfRaw(array $ticket, array $providerCompany): ?string
{
    cvAuthTicketPdfSetError('init', []);

    $fpdfPath = dirname(__DIR__) . '/fpdf/fpdf.php';
    $fpdiAutoloadPath = dirname(__DIR__) . '/fpdi/src/autoload.php';
    $qrLibPath = dirname(__DIR__) . '/functions/phpqrcode/qrlib.php';
    if (!is_file($fpdfPath) || !is_file($fpdiAutoloadPath) || !is_file($qrLibPath)) {
        cvAuthTicketPdfSetError('missing_libraries', [
            'fpdf' => is_file($fpdfPath),
            'fpdi' => is_file($fpdiAutoloadPath),
            'qrlib' => is_file($qrLibPath),
        ]);
        return null;
    }

    require_once $fpdfPath;
    require_once $fpdiAutoloadPath;
    require_once $qrLibPath;
    if (!class_exists('\\setasign\\Fpdi\\Fpdi') || !class_exists('QRcode')) {
        cvAuthTicketPdfSetError('classes_not_loaded', [
            'fpdi_class' => class_exists('\\setasign\\Fpdi\\Fpdi'),
            'qr_class' => class_exists('QRcode'),
        ]);
        return null;
    }

    $code = trim((string) ($ticket['code'] ?? ''));
    if ($code === '') {
        cvAuthTicketPdfSetError('missing_ticket_code', []);
        return null;
    }

    $tmpQr = tempnam(sys_get_temp_dir(), 'cvqr_');
    if (!is_string($tmpQr) || $tmpQr === '') {
        cvAuthTicketPdfSetError('tmp_qr_create_failed', ['tmp_dir' => sys_get_temp_dir()]);
        return null;
    }
    $qrPath = $tmpQr . '.png';
    @rename($tmpQr, $qrPath);
    QRcode::png($code, $qrPath, 'L', 6, 2);
    if (!is_file($qrPath)) {
        cvAuthTicketPdfSetError('qr_generation_failed', ['qr_path' => $qrPath]);
        return null;
    }

    try {
        $pdf = new \setasign\Fpdi\Fpdi('P', 'mm', 'A4');
        $pdf->AddPage();

        $templatePath = dirname(__DIR__) . '/ticket.pdf';
        if (is_file($templatePath)) {
            try {
                $pdf->setSourceFile($templatePath);
                $tplIdx = $pdf->importPage(1);
                $pdf->useTemplate($tplIdx, 0, 0, 210);
            } catch (Throwable $exception) {
                // Fallback: continue without template if import fails.
            }
        }

        $providerName = cvAuthTicketPdfSafeText((string) ($providerCompany['nome'] ?? $ticket['provider_name'] ?? '-'), 70);
        $fromName = cvAuthTicketPdfSafeText((string) ($ticket['from_name'] ?? ''), 42);
        $toName = cvAuthTicketPdfSafeText((string) ($ticket['to_name'] ?? ''), 42);
        $passengerName = cvAuthTicketPdfSafeText((string) ($ticket['passenger_name'] ?? ''), 68);
        $departureAt = cvAuthTicketPdfFormatDateTime((string) ($ticket['departure_at'] ?? ''));
        $arrivalAt = cvAuthTicketPdfFormatDateTime((string) ($ticket['arrival_at'] ?? ''));
        $shopId = cvAuthTicketPdfSafeText((string) ($ticket['shop_id'] ?? ''), 60);
        $changeCode = cvAuthTicketPdfSafeText((string) ($ticket['change_code'] ?? ''), 40);
        $issuedAt = cvAuthTicketPdfFormatDateTime((string) ($ticket['purchased_at'] ?? ''));
        $price = round((float) ($ticket['price'] ?? 0.0), 2);
        $seat = (int) ($ticket['seat_number'] ?? 0);
        $busNum = (int) ($ticket['bus_number'] ?? 0);
        $busSeatLabel = '-';
        if ($busNum > 0 && $seat > 0) {
            $busSeatLabel = $busNum . ' / ' . $seat;
        } elseif ($busNum > 0) {
            $busSeatLabel = (string) $busNum;
        } elseif ($seat > 0) {
            $busSeatLabel = 'Posto ' . $seat;
        }

        // Template-driven coordinates (A4, mm). Edit only cvAuthTicketPdfLayout() to retune.
        $layout = cvAuthTicketPdfLayout();
        $pdf->SetTextColor(27, 47, 88);

        $pdf->SetFont('Arial', 'B', 9.5);
        $pdf->SetXY((float) $layout['route_x'], (float) $layout['route_y']);
        $pdf->Cell(140, 5, cvAuthTicketPdfSafeText($fromName . ' -> ' . $toName, 84), 0, 0, 'L');

        $pdf->SetFont('Arial', '', 8.7);
        $pdf->SetXY((float) $layout['from_x'], (float) $layout['from_y']);
        $pdf->Cell(72, 4.5, $fromName, 0, 0, 'L');
        $pdf->SetXY((float) $layout['dep_time_x'], (float) $layout['dep_time_y']);
        $pdf->Cell(72, 4.5, $departureAt, 0, 0, 'L');
        $pdf->SetXY((float) $layout['to_x'], (float) $layout['to_y']);
        $pdf->Cell(72, 4.5, $toName, 0, 0, 'L');
        $pdf->SetXY((float) $layout['arr_time_x'], (float) $layout['arr_time_y']);
        $pdf->Cell(72, 4.5, $arrivalAt, 0, 0, 'L');

        $pdf->SetFont('Arial', 'B', 8.7);
        //$pdf->SetXY((float) $layout['provider_x'], (float) $layout['provider_y']);
        //$pdf->Cell(40, 4.5, cvAuthTicketPdfSafeText($providerName, 30), 0, 0, 'R');
        $pdf->SetXY((float) $layout['seat_x'], (float) $layout['seat_y']);
        $pdf->Cell(22, 4.5, cvAuthTicketPdfSafeText($busSeatLabel, 16), 0, 0, 'R');

        $pdf->SetFont('Arial', 'B', 15);
        $pdf->SetXY((float) $layout['price_x'], (float) $layout['price_y']);
        $pdf->Cell(60, 8, number_format($price, 2, ',', '.') . ' EUR', 0, 0, 'L');

        $pdf->SetFont('Arial', 'B', 10.5);
        $pdf->SetXY((float) $layout['code_x'], (float) $layout['code_y']);
        $pdf->Cell(120, 6, cvAuthTicketPdfSafeText($code, 56), 0, 0, 'L');

        $pdf->SetFont('Arial', '', 8.7);
        $pdf->SetXY((float) $layout['passenger_x'], (float) $layout['passenger_y']);
        $pdf->Cell(90, 4.5, cvAuthTicketPdfSafeText($passengerName, 60), 0, 0, 'L');
        $pdf->SetXY((float) $layout['title_no_x'], (float) $layout['title_no_y']);
        $pdf->Cell(54, 4.5, cvAuthTicketPdfSafeText('Titolo #' . (string) ((int) ($ticket['id_bg'] ?? 0)), 24), 0, 0, 'R');
        $pdf->SetXY((float) $layout['issued_at_x'], (float) $layout['issued_at_y']);
        $pdf->Cell(54, 4.5, cvAuthTicketPdfSafeText($issuedAt, 24), 0, 0, 'R');
       // $pdf->SetXY((float) $layout['shop_x'], (float) $layout['shop_y']);
       // $pdf->Cell(120, 4.5, cvAuthTicketPdfSafeText($shopId !== '-' ? ('Acquirente: ' . $shopId) : 'Acquirente: -', 80), 0, 0, 'L');
        $pdf->SetXY((float) $layout['change_x'], (float) $layout['change_y']);
        $pdf->Cell(120, 4.5, cvAuthTicketPdfSafeText('Recapiti corsa: ' . $changeCode, 80), 0, 0, 'L');

        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetXY((float) $layout['origin_code_x'], (float) $layout['origin_code_y']);
        $pdf->Cell(82, 5, cvAuthTicketPdfSafeText($code, 36), 0, 0, 'L');
        $pdf->SetXY((float) $layout['origin_price_x'], (float) $layout['origin_price_y']);
        $pdf->Cell(32, 5, number_format($price, 2, ',', '.') . ' EUR', 0, 0, 'R');

        $pdf->Image(
            $qrPath,
            (float) $layout['qr_x'],
            (float) $layout['qr_y'],
            (float) $layout['qr_w'],
            (float) $layout['qr_h']
        );

        $footerY = 246;
        $pdf->SetDrawColor(205, 220, 238);
        $pdf->Line(12, $footerY - 4, 198, $footerY - 4);
        $pdf->SetTextColor(35, 58, 90);
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetXY(12, $footerY - 2);
        $pdf->Cell(186, 5, cvAuthTicketPdfSafeText('Servizio effettuato dal provider: ' . $providerName, 120), 0, 0, 'L');

        $pdf->SetFont('Arial', '', 8);
        $providerCode = strtolower(trim((string) ($ticket['provider_code'] ?? '')));
        $showProviderContacts = cvAuthTicketPdfShowProviderContacts();
        $showProviderEmail = cvAuthTicketPdfProviderFlag($providerCode, 'ticket_pdf_provider_show_email_map', $showProviderContacts);
        $showProviderSite = cvAuthTicketPdfProviderFlag($providerCode, 'ticket_pdf_provider_show_site_map', false);
        $providerSiteUrl = cvAuthTicketPdfProviderString($providerCode, 'ticket_pdf_provider_site_map', '');
        $providerSiteUrl = trim($providerSiteUrl);
        if ($providerSiteUrl !== '' && !preg_match('#^https?://#i', $providerSiteUrl)) {
            $providerSiteUrl = 'https://' . $providerSiteUrl;
        }

        $footerParts = [];
        $footerParts[] = 'P.IVA: ' . cvAuthTicketPdfSafeText((string) ($providerCompany['pi'] ?? '-'), 46);
        $footerParts[] = 'Tel: ' . cvAuthTicketPdfSafeText((string) ($providerCompany['tel'] ?? '-'), 36);
        if ($showProviderEmail) {
            $footerParts[] = 'Email: ' . cvAuthTicketPdfSafeText((string) ($providerCompany['email_pg'] ?? '-'), 44);
        }
        $footerLine1 = implode(' | ', $footerParts);

        if ($showProviderSite && $providerSiteUrl !== '') {
            $footerLine2 = 'Sito: ' . cvAuthTicketPdfSafeText($providerSiteUrl, 120);
        } else {
            $footerLine2 = 'Per assistenza acquisto contatta il supporto Telefonico.';
        }
        $pdf->SetXY(12, $footerY + 2);
        $pdf->Cell(186, 4, cvAuthTicketPdfSafeText($footerLine1, 140), 0, 0, 'L');
        $pdf->SetXY(12, $footerY + 6);
        $pdf->Cell(186, 4, cvAuthTicketPdfSafeText($footerLine2, 140), 0, 0, 'L');

        $raw = $pdf->Output('S');
        @unlink($qrPath);
        if (!is_string($raw) || $raw === '') {
            cvAuthTicketPdfSetError('pdf_output_empty', []);
            return null;
        }
        cvAuthTicketPdfSetError('ok', []);
        return is_string($raw) && $raw !== '' ? $raw : null;
    } catch (Throwable $exception) {
        @unlink($qrPath);
        cvAuthTicketPdfSetError('exception', [
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ]);
        return null;
    }
}

/**
 * Coordinate template ticket.pdf (A4, mm).
 * Modifica qui i valori per spostare i campi senza toccare la logica.
 *
 * @return array<string,float>
 */
function cvAuthTicketPdfLayout(): array
{
    return [
        'route_x' => 8.0,
        'route_y' => 33.8,

        'from_x' => 15.0,//ok
        'from_y' => 57.0,
        'to_x' => 95.0,//ok
        'to_y' => 57.0,
        'dep_time_x' => 28.0,//ok
        'dep_time_y' => 66.0,
        'arr_time_x' => 112.0,//ok
        'arr_time_y' => 66.0,

        'provider_x' => 163.0,
        'provider_y' => 42.0,
        'seat_x' => 181.0,
        'seat_y' => 49.0,

        'price_x' => 52.0,//ok
        'price_y' => 80.6,
        'code_x' => 58.0,//ok
        'code_y' => 100,

        'origin_code_x' => 48.0,
        'origin_code_y' => 141.6,
        'origin_price_x' => 196.0,
        'origin_price_y' => 143.6,

        'passenger_x' => 8.0,//ok
        'passenger_y' => 183.4,//ok
        'title_no_x' => 148.0,
        'title_no_y' => 175.4,
        'issued_at_x' => 148.0,
        'issued_at_y' => 181.0,
       //'shop_x' => 8.0,
       //'shop_y' => 105.2,
        'change_x' => 158.0,//recapiti corsa
        'change_y' => 100.0,

        'qr_x' => 171.0,
        'qr_y' => 107.0,
        'qr_w' => 33.0,
        'qr_h' => 33.0,
    ];
}

/**
 * @param array<string,mixed> $context
 */
function cvAuthTicketPdfSetError(string $reason, array $context): void
{
    $GLOBALS['cv_auth_ticket_pdf_error'] = [
        'reason' => trim($reason),
        'context' => $context,
    ];
}

/**
 * @return array<string,mixed>
 */
function cvAuthTicketPdfLastError(): array
{
    $raw = $GLOBALS['cv_auth_ticket_pdf_error'] ?? null;
    if (!is_array($raw)) {
        return ['reason' => 'unknown', 'context' => []];
    }
    return $raw;
}

function cvAuthTicketPdfShowProviderContacts(): bool
{
    $raw = cvAuthTicketPdfSettingValue('ticket_pdf_show_provider_contacts');
    return in_array(strtolower(trim($raw)), ['1', 'true', 'yes', 'on'], true);
}

function cvAuthTicketPdfProviderFlag(string $providerCode, string $settingKey, bool $fallback = false): bool
{
    $providerCode = strtolower(trim($providerCode));
    if ($providerCode === '') {
        return $fallback;
    }

    $map = cvAuthTicketPdfSettingIntMap($settingKey);
    if (!isset($map[$providerCode])) {
        return $fallback;
    }

    return ((int) $map[$providerCode]) === 1;
}

function cvAuthTicketPdfProviderString(string $providerCode, string $settingKey, string $fallback = ''): string
{
    $providerCode = strtolower(trim($providerCode));
    if ($providerCode === '') {
        return $fallback;
    }

    $map = cvAuthTicketPdfSettingStringMap($settingKey);
    if (!isset($map[$providerCode])) {
        return $fallback;
    }

    return trim((string) $map[$providerCode]);
}

function cvAuthTicketPdfSettingValue(string $settingKey): string
{
    static $cache = [];
    $settingKey = trim($settingKey);
    if ($settingKey === '') {
        return '';
    }
    if (array_key_exists($settingKey, $cache)) {
        return $cache[$settingKey];
    }

    $cache[$settingKey] = '';
    if (!isset($GLOBALS['connection']) || !($GLOBALS['connection'] instanceof mysqli)) {
        return $cache[$settingKey];
    }

    $connection = $GLOBALS['connection'];
    $columns = cvAuthTicketPdfSettingsColumns($connection);
    $keyColumn = (string) ($columns['key'] ?? '');
    $valueColumn = (string) ($columns['value'] ?? '');
    if ($keyColumn === '' || $valueColumn === '') {
        return $cache[$settingKey];
    }

    $escapedKey = $connection->real_escape_string($settingKey);
    $sql = "SELECT `{$valueColumn}` AS setting_value
            FROM cv_settings
            WHERE `{$keyColumn}` = '{$escapedKey}'
            LIMIT 1";
    $result = $connection->query($sql);
    if (!$result instanceof mysqli_result) {
        return $cache[$settingKey];
    }

    $row = $result->fetch_assoc();
    $result->free();
    $cache[$settingKey] = is_array($row) ? trim((string) ($row['setting_value'] ?? '')) : '';
    return $cache[$settingKey];
}

/**
 * @return array{key:string,value:string}
 */
function cvAuthTicketPdfSettingsColumns(mysqli $connection): array
{
    static $cached = null;
    if (is_array($cached)) {
        return $cached;
    }

    $cached = ['key' => '', 'value' => ''];
    $result = $connection->query('SHOW COLUMNS FROM cv_settings');
    if (!$result instanceof mysqli_result) {
        return $cached;
    }

    $available = [];
    while ($row = $result->fetch_assoc()) {
        if (!is_array($row)) {
            continue;
        }
        $field = strtolower(trim((string) ($row['Field'] ?? '')));
        if ($field !== '') {
            $available[$field] = true;
        }
    }
    $result->free();

    $keyCandidates = ['key_name', 'key', 'chiave', 'name', 'setting_key'];
    $valueCandidates = ['value', 'val', 'valore', 'setting_value', 'content'];

    foreach ($keyCandidates as $candidate) {
        if (isset($available[$candidate])) {
            $cached['key'] = $candidate;
            break;
        }
    }
    foreach ($valueCandidates as $candidate) {
        if (isset($available[$candidate])) {
            $cached['value'] = $candidate;
            break;
        }
    }

    return $cached;
}

/**
 * @return array<string,int>
 */
function cvAuthTicketPdfSettingIntMap(string $settingKey): array
{
    $raw = cvAuthTicketPdfSettingValue($settingKey);
    if ($raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }

    $map = [];
    foreach ($decoded as $code => $value) {
        $providerCode = strtolower(trim((string) $code));
        if ($providerCode === '') {
            continue;
        }
        $map[$providerCode] = ((int) $value) > 0 ? 1 : 0;
    }
    return $map;
}

/**
 * @return array<string,string>
 */
function cvAuthTicketPdfSettingStringMap(string $settingKey): array
{
    $raw = cvAuthTicketPdfSettingValue($settingKey);
    if ($raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }

    $map = [];
    foreach ($decoded as $code => $value) {
        $providerCode = strtolower(trim((string) $code));
        if ($providerCode === '') {
            continue;
        }
        $map[$providerCode] = trim((string) $value);
    }
    return $map;
}

/**
 * @param array<string,mixed> $row
 * @param array<string,array<string,mixed>> $providerConfigs
 * @return array<string,mixed>
 */
function cvAuthMapTicketPayload(array $row, array $providerConfigs): array
{
    $note = (string) ($row['note'] ?? '');
    $orderCode = '';
    if ($note !== '' && preg_match('/order:([^;\\s]+)/i', $note, $m) === 1) {
        $orderCode = strtoupper(trim((string) ($m[1] ?? '')));
    }
    $changeAvailability = cvAuthTicketChangeAvailability($row, $providerConfigs);

    return [
        'id_bg' => (int) ($row['id_bg'] ?? 0),
        'code' => (string) ($row['codice'] ?? ''),
        'change_code' => (string) ($row['codice_camb'] ?? ''),
        'transaction_id' => (string) ($row['transaction_id'] ?? ''),
        'txn_id' => (string) ($row['txn_id'] ?? ''),
        'price' => (float) ($row['prezzo'] ?? 0),
        'commission' => (float) ($row['prz_comm'] ?? 0),
        'paid' => (int) ($row['pagato'] ?? 0) === 1,
        'status' => (int) ($row['stato'] ?? 0),
        'id_az' => (int) ($row['id_az'] ?? 0),
        'line_id' => (int) ($row['id_linea'] ?? 0),
        'trip_id' => (int) ($row['id_corsa'] ?? 0),
        'bus_id' => (int) ($row['id_mz'] ?? 0),
        'bus_number' => (int) (($row['mz_dt'] ?? 0) ?: ($row['id_mz'] ?? 0)),
        'seat_number' => (int) ($row['posto'] ?? 0),
        'ticket_type' => (int) ($row['type'] ?? 0),
        'departure_at' => (string) ($row['departure_at'] ?? ''),
        'arrival_at' => (string) ($row['arrival_at'] ?? ''),
        'purchased_at' => (string) ($row['acquistato'] ?? ''),
        'provider_code' => strtolower(trim((string) ($row['provider_code'] ?? ''))),
        'provider_name' => (string) ($row['provider_name'] ?? ''),
        'from_name' => (string) ($row['from_name'] ?? ''),
        'to_name' => (string) ($row['to_name'] ?? ''),
        'order_code' => $orderCode,
        'note' => $note,
        'can_change' => (bool) ($changeAvailability['can_change'] ?? false),
        'change_reason' => (string) ($changeAvailability['reason'] ?? ''),
        'change_reason_code' => (string) ($changeAvailability['reason_code'] ?? ''),
        'change_retry_after_seconds' => max(0, (int) ($changeAvailability['retry_after_seconds'] ?? 0)),
        'controllato' => (int) ($row['controllato'] ?? 0),
        'attesa' => (int) ($row['attesa'] ?? 0),
        'data_attesa' => (string) ($row['data_attesa'] ?? ''),
        'camb' => (int) ($row['camb'] ?? 0),
    ];
}

/**
 * @param array<string,mixed> $ticket
 * @param array<string,array<string,mixed>> $providerConfigs
 * @return array<string,mixed>
 */
function cvAuthTicketChangeAvailability(array $ticket, array $providerConfigs): array
{
    $providerCode = strtolower(trim((string) ($ticket['provider_code'] ?? '')));
    $note = strtolower(trim((string) ($ticket['note'] ?? '')));
    $providerHint = strtolower(trim((string) ($ticket['provider_name'] ?? '')));
    $code = trim((string) ($ticket['codice'] ?? ''));
    if ($code === '' || $providerCode === '') {
        return ['can_change' => false, 'reason' => 'Dati biglietto mancanti', 'reason_code' => 'MISSING_TICKET_DATA', 'retry_after_seconds' => 0];
    }
    if ((int) ($ticket['pagato'] ?? 0) !== 1) {
        return ['can_change' => false, 'reason' => 'Biglietto non pagato', 'reason_code' => 'TICKET_UNPAID', 'retry_after_seconds' => 0];
    }
    if ((int) ($ticket['stato'] ?? 0) !== 1) {
        if (max(0, (int) ($ticket['camb'] ?? 0)) > 0) {
            return ['can_change' => false, 'reason' => 'Biglietto già sostituito', 'reason_code' => 'CHANGE_ALREADY_REPLACED', 'retry_after_seconds' => 0];
        }
        return ['can_change' => false, 'reason' => 'Biglietto non attivo', 'reason_code' => 'TICKET_NOT_ACTIVE', 'retry_after_seconds' => 0];
    }
    if (!isset($providerConfigs[$providerCode]) || !is_array($providerConfigs[$providerCode])) {
        return ['can_change' => false, 'reason' => 'Provider non configurato', 'reason_code' => 'PROVIDER_NOT_CONFIGURED', 'retry_after_seconds' => 0];
    }

    if ((int) ($ticket['controllato'] ?? 0) === 1) {
        return ['can_change' => false, 'reason' => 'Biglietto già controllato', 'reason_code' => 'TICKET_ALREADY_USED', 'retry_after_seconds' => 0];
    }

    $providerIdentity = $providerCode;
    if ($providerIdentity === '') {
        $providerIdentity = $providerHint;
    }
    if ($providerIdentity === '' && $note !== '' && preg_match('/provider:([^;\\s]+)/i', $note, $m) === 1) {
        $providerIdentity = strtolower(trim((string) ($m[1] ?? '')));
    }
    $providerIdentity = trim($providerIdentity);

    $providerMaxChanges = 1;
    $providerChangeWindowMinutes = 2880;
    if ($providerIdentity === 'leonetti' || strpos($providerIdentity, 'leonetti') !== false) {
        $providerMaxChanges = 2;
        $providerChangeWindowMinutes = 1;
    }

    $usedChanges = max(0, (int) ($ticket['camb'] ?? 0));
    if ($providerMaxChanges > 0 && $usedChanges >= $providerMaxChanges) {
        return ['can_change' => false, 'reason' => 'Numero massimo cambi raggiunto', 'reason_code' => 'MAX_CHANGES_REACHED', 'retry_after_seconds' => 0];
    }

    $departureAt = trim((string) ($ticket['departure_at'] ?? ''));
    $departureTs = $departureAt !== '' ? strtotime($departureAt) : false;
    if ($providerChangeWindowMinutes > 0 && is_int($departureTs) && $departureTs > 0) {
        $changeDeadlineTs = $departureTs + ($providerChangeWindowMinutes * 60);
        if (time() >= $changeDeadlineTs) {
            return ['can_change' => false, 'reason' => 'Finestra cambio scaduta', 'reason_code' => 'CHANGE_WINDOW_EXPIRED', 'retry_after_seconds' => 0];
        }
    }

    $attesa = (int) ($ticket['attesa'] ?? 0);
    $dataAttesa = trim((string) ($ticket['data_attesa'] ?? ''));
    if ($attesa === 1 && $dataAttesa !== '') {
        $retryAfterSeconds = cvAuthTicketChangePendingRetrySeconds($dataAttesa, 300);
        if ($retryAfterSeconds > 0) {
            return [
                'can_change' => false,
                'reason' => 'Cambio già in attesa pagamento',
                'reason_code' => 'CHANGE_PENDING_PAYMENT',
                'retry_after_seconds' => $retryAfterSeconds,
            ];
        }
    }

    return ['can_change' => true, 'reason' => '', 'reason_code' => '', 'retry_after_seconds' => 0];
}

function cvAuthTicketChangePendingRetrySeconds(string $dataAttesa, int $windowSeconds = 300): int
{
    $dataAttesa = trim($dataAttesa);
    if ($dataAttesa === '' || $windowSeconds <= 0) {
        return 0;
    }
    $attesaTs = strtotime($dataAttesa);
    if (!is_int($attesaTs) || $attesaTs <= 0) {
        return 0;
    }
    $expiresTs = $attesaTs + $windowSeconds;
    $remaining = $expiresTs - time();
    return $remaining > 0 ? $remaining : 0;
}

function cvAuthTicketChangeRetryLabel(int $seconds): string
{
    $seconds = max(0, $seconds);
    if ($seconds <= 0) {
        return '';
    }
    if ($seconds < 60) {
        return $seconds . ' secondi';
    }
    $minutes = (int) ceil($seconds / 60);
    return $minutes . ' minut' . ($minutes === 1 ? 'o' : 'i');
}

function cvAuthProviderChangeMessageIsPending(string $message): bool
{
    $normalized = strtolower(trim($message));
    if ($normalized === '') {
        return false;
    }
    if (strpos($normalized, 'pending change payment') !== false) {
        return true;
    }
    if (strpos($normalized, 'change already in pending state') !== false) {
        return true;
    }
    if (strpos($normalized, 'gia in attesa') !== false || strpos($normalized, 'già in attesa') !== false) {
        return true;
    }
    return false;
}

function cvAuthMysqlDateToItDate(string $rawDate): string
{
    $value = trim($rawDate);
    if ($value === '') {
        return '';
    }

    $timestamp = strtotime($value);
    if (!is_int($timestamp) || $timestamp <= 0) {
        return '';
    }

    return date('d/m/Y', $timestamp);
}

function cvAuthDefaultChangeDateIt(string $departureAt): string
{
    $today = date('d/m/Y');
    $departureIt = cvAuthMysqlDateToItDate($departureAt);
    if ($departureIt === '') {
        return $today;
    }

    $depTs = strtotime(str_replace('/', '-', $departureIt));
    $todayTs = strtotime(str_replace('/', '-', $today));
    if (!is_int($depTs) || !is_int($todayTs)) {
        return $departureIt;
    }

    return $depTs < $todayTs ? $today : $departureIt;
}

/**
 * @param array<string,mixed> $context
 */
function cvAuthLogChangePrecheck(mysqli $connection, string $eventCode, string $message, array $context = []): void
{
    try {
        cvErrorLogWrite($connection, [
            'source' => 'auth_api',
            'event_code' => $eventCode,
            'severity' => 'warning',
            'message' => $message,
            'action_name' => 'ticket_change_precheck',
            'provider_code' => isset($context['provider_code']) ? (string) $context['provider_code'] : '',
            'shop_id' => isset($context['shop_id']) ? (string) $context['shop_id'] : '',
            'request_id' => isset($context['trace_id']) ? (string) $context['trace_id'] : '',
            'context' => $context,
        ]);
    } catch (Throwable $exception) {
        // Best effort: non bloccare il flusso applicativo se il log fallisce.
    }
}

function cvAuthHandleTicketChangePrecheck(mysqli $connection): void
{
    $userId = cvAuthSessionUserId();
    $payload = cvAuthRequestData();
    try {
        $traceId = bin2hex(random_bytes(6));
    } catch (Throwable $exception) {
        $traceId = substr(sha1(uniqid('', true)), 0, 12);
    }
    $publicLookup = filter_var(($payload['public_lookup'] ?? $payload['public'] ?? false), FILTER_VALIDATE_BOOLEAN);
    if ($userId <= 0 && !$publicLookup) {
        cvAuthResponse(false, 'Utente non autenticato.', [], 'UNAUTHORIZED', 401);
    }

    $ticketId = isset($payload['ticket_id']) && is_numeric($payload['ticket_id']) ? (int) $payload['ticket_id'] : 0;
    $ticketCode = trim((string) ($payload['ticket_code'] ?? $payload['code'] ?? ''));

    if ($ticketId <= 0 && $ticketCode === '') {
        cvAuthResponse(false, 'Indica ticket_id o ticket_code.', [], 'VALIDATION_ERROR', 422);
    }

            $sql = "SELECT
                b.id_bg,
                b.codice,
                b.codice_camb,
                b.transaction_id,
                b.pagato,
                b.stato,
                b.controllato,
                b.attesa,
                b.data_attesa,
                b.camb,
                b.note,
                b.id_linea,
                b.id_corsa,
                b.id_sott1,
                b.id_sott2,
                b.rid,
                b.id_vg,
                b.id_vgt,
                b.data AS departure_at,
                b.data2 AS arrival_at,
                a.code AS provider_code,
                a.nome AS provider_name,
                COALESCE(
                    NULLIF(TRIM(CONCAT_WS(' ', vgt.nome, vgt.cognome)), ''),
                    NULLIF(TRIM(CONCAT_WS(' ', vg.nome, vg.cognome)), ''),
                    ''
                ) AS passenger_name,
                COALESCE(
                    NULLIF(DATE_FORMAT(vgt.data_reg, '%Y-%m-%d'), '1970-01-01'),
                    NULLIF(DATE_FORMAT(vg.data, '%Y-%m-%d'), '1970-01-01'),
                    ''
                ) AS passenger_birth_date,
                COALESCE(
                    NULLIF(TRIM(vgt.tel), ''),
                    NULLIF(TRIM(vg.tel), ''),
                    ''
                ) AS passenger_phone
            FROM biglietti AS b
            LEFT JOIN aziende AS a ON a.id_az = b.id_az
            LEFT JOIN viaggiatori AS vg ON vg.id_vg = b.id_vg
            LEFT JOIN viaggiatori_temp AS vgt ON vgt.id_vgt = b.id_vgt
            WHERE ";

    if ($publicLookup || $userId <= 0) {
        if ($ticketCode === '') {
            cvAuthResponse(false, 'Codice biglietto mancante.', [], 'VALIDATION_ERROR', 422);
        }
        $sql .= 'b.codice = ? LIMIT 1';
    } elseif ($ticketId > 0) {
        $sql .= 'b.id_vg = ? AND b.id_bg = ? LIMIT 1';
    } else {
        $sql .= 'b.id_vg = ? AND b.codice = ? LIMIT 1';
    }

    $statement = $connection->prepare($sql);
    if (!$statement instanceof mysqli_stmt) {
        cvAuthResponse(false, 'Impossibile verificare il biglietto.', [], 'CHANGE_PRECHECK_ERROR', 500);
    }

    if ($publicLookup || $userId <= 0) {
        $statement->bind_param('s', $ticketCode);
    } elseif ($ticketId > 0) {
        $statement->bind_param('ii', $userId, $ticketId);
    } else {
        $statement->bind_param('is', $userId, $ticketCode);
    }

    if (!$statement->execute()) {
        $statement->close();
        cvAuthResponse(false, 'Errore verifica biglietto.', [], 'CHANGE_PRECHECK_ERROR', 500);
    }

    $result = $statement->get_result();
    $ticket = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $statement->close();

    if (!is_array($ticket)) {
        cvAuthResponse(false, 'Biglietto non trovato.', [], 'TICKET_NOT_FOUND', 404);
    }

    $providerCode = strtolower(trim((string) ($ticket['provider_code'] ?? '')));
    $code = trim((string) ($ticket['codice'] ?? ''));
    if ($providerCode === '' || $code === '') {
        cvAuthResponse(false, 'Cambio non disponibile: provider o codice mancanti.', [], 'CHANGE_NOT_ALLOWED', 409);
    }

    $providerConfigsRaw = cvProviderConfigs($connection);
    $providerConfigs = [];
    foreach ($providerConfigsRaw as $cfgCode => $cfg) {
        if (!is_array($cfg)) {
            continue;
        }
        $normalizedCfgCode = strtolower(trim((string) $cfgCode));
        if ($normalizedCfgCode === '') {
            continue;
        }
        $providerConfigs[$normalizedCfgCode] = $cfg;
    }
    if (!isset($providerConfigs[$providerCode]) || !is_array($providerConfigs[$providerCode])) {
        cvAuthResponse(false, 'Provider non configurato per verifica cambio.', [
            'provider_code' => $providerCode,
        ], 'PROVIDER_NOT_CONFIGURED', 422);
    }

    $changeAvailability = cvAuthTicketChangeAvailability($ticket, $providerConfigs);
    if (empty($changeAvailability['can_change'])) {
        $reason = trim((string) ($changeAvailability['reason'] ?? 'Cambio non disponibile per questo biglietto.'));
        $reasonCode = trim((string) ($changeAvailability['reason_code'] ?? 'CHANGE_NOT_ALLOWED'));
        $retryAfterSeconds = max(0, (int) ($changeAvailability['retry_after_seconds'] ?? 0));
        if ($reasonCode === 'CHANGE_PENDING_PAYMENT' && $retryAfterSeconds > 0) {
            $reason .= '. Riprova tra ' . cvAuthTicketChangeRetryLabel($retryAfterSeconds) . '.';
        } elseif ($reasonCode === 'CHANGE_ALREADY_REPLACED') {
            $reason .= '. Il nuovo tentativo, se consentito, va fatto dal biglietto aggiornato.';
        }
        cvAuthLogChangePrecheck($connection, 'CHANGE_PRECHECK_BLOCKED_LOCAL', $reason, [
            'trace_id' => $traceId,
            'ticket_id' => (int) ($ticket['id_bg'] ?? 0),
            'ticket_code' => (string) ($ticket['codice'] ?? ''),
            'provider_code' => $providerCode,
            'reason_code' => $reasonCode,
            'retry_after_seconds' => $retryAfterSeconds,
            'public_lookup' => $publicLookup ? 1 : 0,
            'shop_id' => (string) ($ticket['transaction_id'] ?? ''),
        ]);
        cvAuthResponse(false, $reason, [
            'provider_code' => $providerCode,
            'reason_code' => $reasonCode,
            'retry_after_seconds' => $retryAfterSeconds,
            'block_stage' => 'availability_local',
            'trace_id' => $traceId,
        ], 'CHANGE_NOT_ALLOWED', 409);
    }

    $providerConfig = $providerConfigs[$providerCode];
    $endpointUrl = cvProviderBuildEndpointUrl((string) ($providerConfig['base_url'] ?? ''), 'change_check');
    if ($endpointUrl === '') {
        cvAuthResponse(false, 'Endpoint provider cambio non valido.', [
            'provider_code' => $providerCode,
        ], 'PROVIDER_ENDPOINT_INVALID', 422);
    }

    $providerPayload = [
        'ticket_code' => $code,
        'change_code' => $code,
        'shop_id' => (string) ($ticket['transaction_id'] ?? ''),
        'line_id' => (int) ($ticket['id_linea'] ?? 0),
        'trip_id' => (int) ($ticket['id_corsa'] ?? 0),
        'from_stop_id' => (int) ($ticket['id_sott1'] ?? 0),
        'to_stop_id' => (int) ($ticket['id_sott2'] ?? 0),
        'departure_at' => (string) ($ticket['departure_at'] ?? ''),
    ];

    $providerResponse = cvProviderHttpPostJson(
        $endpointUrl,
        $providerPayload,
        (string) ($providerConfig['api_key'] ?? '')
    );
    if (!(bool) ($providerResponse['ok'] ?? false) || !is_array($providerResponse['body'] ?? null)) {
        cvAuthLogChangePrecheck($connection, 'CHANGE_PRECHECK_PROVIDER_UNAVAILABLE', 'Provider change_check non disponibile', [
            'trace_id' => $traceId,
            'ticket_id' => (int) ($ticket['id_bg'] ?? 0),
            'ticket_code' => $code,
            'provider_code' => $providerCode,
            'provider_status' => (int) ($providerResponse['status'] ?? 0),
            'provider_error' => (string) ($providerResponse['error'] ?? ''),
            'shop_id' => (string) ($ticket['transaction_id'] ?? ''),
        ]);
        cvAuthResponse(false, 'Verifica cambio provider non disponibile.', [
            'provider_code' => $providerCode,
            'provider_error' => $providerResponse['error'] ?? null,
            'provider_status' => (int) ($providerResponse['status'] ?? 0),
            'block_stage' => 'provider_unavailable',
            'trace_id' => $traceId,
        ], 'PROVIDER_CHANGE_CHECK_UNAVAILABLE', 502);
    }

    $providerBody = $providerResponse['body'];
    $providerSuccess = isset($providerBody['success']) && (bool) $providerBody['success'] === true;
    $providerData = isset($providerBody['data']) && is_array($providerBody['data']) ? $providerBody['data'] : [];
    $providerError = isset($providerBody['error']) && is_array($providerBody['error']) ? $providerBody['error'] : [];
    $providerMessage = '';
    if (isset($providerError['message']) && is_string($providerError['message']) && trim($providerError['message']) !== '') {
        $providerMessage = trim($providerError['message']);
    } elseif (isset($providerBody['message']) && is_string($providerBody['message']) && trim($providerBody['message']) !== '') {
        $providerMessage = trim($providerBody['message']);
    }

    if (!$providerSuccess && cvAuthProviderChangeMessageIsPending($providerMessage)) {
        $shopId = trim((string) ($ticket['transaction_id'] ?? ''));
        $cancelEndpointUrl = cvProviderBuildEndpointUrl((string) ($providerConfig['base_url'] ?? ''), 'cancel');
        cvAuthLogChangePrecheck($connection, 'CHANGE_PRECHECK_PROVIDER_PENDING', 'Provider change_check in pending: avvio tentativo cancel+retry', [
            'trace_id' => $traceId,
            'ticket_id' => (int) ($ticket['id_bg'] ?? 0),
            'ticket_code' => $code,
            'provider_code' => $providerCode,
            'provider_message' => $providerMessage,
            'shop_id' => $shopId,
        ]);
        if ($shopId !== '' && $cancelEndpointUrl !== '') {
            $cancelPayload = [
                'shop_id' => $shopId,
                'ShopId' => $shopId,
                'codice_camb' => $code,
            ];
            cvProviderHttpPostJson(
                $cancelEndpointUrl,
                $cancelPayload,
                (string) ($providerConfig['api_key'] ?? '')
            );

            $retryResponse = cvProviderHttpPostJson(
                $endpointUrl,
                $providerPayload,
                (string) ($providerConfig['api_key'] ?? '')
            );
            if ((bool) ($retryResponse['ok'] ?? false) && is_array($retryResponse['body'] ?? null)) {
                $providerBody = $retryResponse['body'];
                $providerSuccess = isset($providerBody['success']) && (bool) $providerBody['success'] === true;
                $providerData = isset($providerBody['data']) && is_array($providerBody['data']) ? $providerBody['data'] : [];
                $providerError = isset($providerBody['error']) && is_array($providerBody['error']) ? $providerBody['error'] : [];
                $providerMessage = '';
                if (isset($providerError['message']) && is_string($providerError['message']) && trim($providerError['message']) !== '') {
                    $providerMessage = trim($providerError['message']);
                } elseif (isset($providerBody['message']) && is_string($providerBody['message']) && trim($providerBody['message']) !== '') {
                    $providerMessage = trim($providerBody['message']);
                }
                cvAuthLogChangePrecheck($connection, 'CHANGE_PRECHECK_PROVIDER_PENDING_RETRY', 'Eseguito retry dopo cancel', [
                    'trace_id' => $traceId,
                    'ticket_id' => (int) ($ticket['id_bg'] ?? 0),
                    'ticket_code' => $code,
                    'provider_code' => $providerCode,
                    'provider_success' => $providerSuccess ? 1 : 0,
                    'provider_message' => $providerMessage,
                    'shop_id' => $shopId,
                ]);
            }
        }
    }

    if (!$providerSuccess) {
        $errorData = [
            'provider_code' => $providerCode,
            'provider_response' => $providerBody,
            'trace_id' => $traceId,
        ];
        $blockStage = 'provider_change_check_fail';
        if (cvAuthProviderChangeMessageIsPending($providerMessage)) {
            $errorData['reason_code'] = 'CHANGE_PENDING_PAYMENT';
            $errorData['retry_after_seconds'] = 300;
            $blockStage = 'provider_pending_after_cancel';
        }
        $errorData['block_stage'] = $blockStage;
        cvAuthLogChangePrecheck($connection, 'CHANGE_PRECHECK_BLOCKED_PROVIDER', $providerMessage !== '' ? $providerMessage : 'Cambio non disponibile sul provider', [
            'trace_id' => $traceId,
            'ticket_id' => (int) ($ticket['id_bg'] ?? 0),
            'ticket_code' => $code,
            'provider_code' => $providerCode,
            'block_stage' => $blockStage,
            'provider_message' => $providerMessage,
            'shop_id' => (string) ($ticket['transaction_id'] ?? ''),
        ]);
        cvAuthResponse(false, $providerMessage !== '' ? $providerMessage : 'Cambio non disponibile sul provider.', $errorData, 'CHANGE_NOT_ALLOWED', 409);
    }

    $canChange = !empty($providerData['can_change']);
    if (!$canChange) {
        cvAuthLogChangePrecheck($connection, 'CHANGE_PRECHECK_PROVIDER_DENIED', $providerMessage !== '' ? $providerMessage : 'Cambio non consentito dalle regole provider', [
            'trace_id' => $traceId,
            'ticket_id' => (int) ($ticket['id_bg'] ?? 0),
            'ticket_code' => $code,
            'provider_code' => $providerCode,
            'shop_id' => (string) ($ticket['transaction_id'] ?? ''),
        ]);
        cvAuthResponse(false, $providerMessage !== '' ? $providerMessage : 'Cambio non consentito dalle regole del provider.', [
            'provider_code' => $providerCode,
            'provider_response' => $providerBody,
            'block_stage' => 'provider_can_change_false',
            'trace_id' => $traceId,
        ], 'CHANGE_NOT_ALLOWED', 409);
    }

    $fromStopId = isset($providerData['from_stop_id']) && is_numeric($providerData['from_stop_id'])
        ? (int) $providerData['from_stop_id']
        : (int) ($ticket['id_sott1'] ?? 0);
    $toStopId = isset($providerData['to_stop_id']) && is_numeric($providerData['to_stop_id'])
        ? (int) $providerData['to_stop_id']
        : (int) ($ticket['id_sott2'] ?? 0);

    $fromRef = trim((string) ($providerData['from_ref'] ?? ''));
    $toRef = trim((string) ($providerData['to_ref'] ?? ''));
    if ($fromRef === '' && $fromStopId > 0) {
        $fromRef = $providerCode . '|' . $fromStopId;
    }
    if ($toRef === '' && $toStopId > 0) {
        $toRef = $providerCode . '|' . $toStopId;
    }

    if ($fromRef === '' || $toRef === '') {
        cvAuthResponse(false, 'Cambio non disponibile: fermate non valide.', [], 'CHANGE_NOT_ALLOWED', 409);
    }

    $suggestedDateIt = trim((string) ($providerData['suggested_date_it'] ?? ''));
    if ($suggestedDateIt === '' || preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $suggestedDateIt) !== 1) {
        $suggestedDateIt = cvAuthDefaultChangeDateIt((string) ($ticket['departure_at'] ?? ''));
    }

    $rid = isset($ticket['rid']) ? (int) $ticket['rid'] : 0;
    $adultCount = $rid > 0 ? 0 : 1;
    $childCount = $rid > 0 ? 1 : 0;
    $passengerName = trim((string) ($ticket['passenger_name'] ?? ''));
    $passengerBirthDate = cvAuthNormalizeIdentityDate((string) ($ticket['passenger_birth_date'] ?? ''));
    $passengerPhone = trim((string) ($ticket['passenger_phone'] ?? ''));
    if ($passengerName === '') {
        cvAuthResponse(false, 'Cambio non disponibile: passeggero originario non individuabile.', [], 'CHANGE_NOT_ALLOWED', 409);
    }

    $redirectParams = [
        'part' => $fromRef,
        'arr' => $toRef,
        'dt1' => $suggestedDateIt,
        'ad' => $adultCount,
        'bam' => $childCount,
        'mode' => 'oneway',
        'camb' => $code,
    ];
    $redirectUrl = './soluzioni.php?' . http_build_query($redirectParams);

    cvAuthResponse(true, 'Cambio disponibile. Seleziona la nuova corsa.', [
        'can_change' => true,
        'trace_id' => $traceId,
        'block_stage' => 'ok',
        'ticket_id' => (int) ($ticket['id_bg'] ?? 0),
        'provider_code' => $providerCode,
        'provider_name' => (string) ($ticket['provider_name'] ?? $providerCode),
        'ticket_code' => $code,
        'redirect_url' => $redirectUrl,
        'suggested_date_it' => $suggestedDateIt,
        'provider_check' => $providerData,
        'change_context' => [
            'ticket_code' => $code,
            'public_lookup' => $publicLookup,
            'passenger_locked' => $passengerName !== '',
            'ad' => $adultCount,
            'bam' => $childCount,
            'passengers' => [[
                'full_name' => $passengerName,
                'birth_date' => $passengerBirthDate,
                'phone' => $passengerPhone,
            ]],
        ],
    ]);
}

function cvAuthNormalizeIdentityDate(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $timestamp = strtotime($value);
    if ($timestamp === false || $timestamp <= 0) {
        return '';
    }

    return date('Y-m-d', $timestamp);
}

function cvAuthHandleProfileUpdate(mysqli $connection): void
{
    $userId = cvAuthSessionUserId();
    if ($userId <= 0) {
        cvAuthResponse(false, 'Utente non autenticato.', [], 'UNAUTHORIZED', 401);
    }

    $payload = cvAuthRequestData();
    $name = trim((string) ($payload['nome'] ?? ''));
    $surname = trim((string) ($payload['cognome'] ?? ''));
    $newsletterSubscribed = isset($payload['newsletter_subscribed'])
        ? filter_var($payload['newsletter_subscribed'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
        : null;
    $residence = cvAuthResolveResidence($connection, $payload);
    $phone = cvAuthNormalizePhone((string) ($payload['tel'] ?? ''));

    if (strlen($name) < 2) {
        cvAuthResponse(false, 'Inserisci il nome.', [], 'VALIDATION_ERROR', 422);
    }

    if ($surname === '') {
        $surname = '-';
    }

    if ((int) ($residence['id_prov'] ?? 0) <= 0) {
        cvAuthResponse(false, 'Seleziona la provincia di residenza.', [], 'VALIDATION_ERROR', 422);
    }

    if ($phone === '') {
        cvAuthResponse(false, 'Numero di telefono non valido.', [], 'VALIDATION_ERROR', 422);
    }

    $city = (string) ($residence['citta'] ?? '-');
    $idProv = (int) ($residence['id_prov'] ?? 0);

    $statement = $connection->prepare('UPDATE viaggiatori SET nome = ?, cognome = ?, citta = ?, id_prov = ?, tel = ? WHERE id_vg = ? LIMIT 1');
    if (!$statement instanceof mysqli_stmt) {
        cvAuthResponse(false, 'Impossibile aggiornare il profilo.', [], 'PROFILE_UPDATE_ERROR', 500);
    }

    $statement->bind_param('sssisi', $name, $surname, $city, $idProv, $phone, $userId);
    if (!$statement->execute()) {
        $statement->close();
        cvAuthResponse(false, 'Errore aggiornamento profilo.', [], 'PROFILE_UPDATE_ERROR', 500);
    }
    $statement->close();

    $userRow = cvAuthGetUserById($connection, $userId);
    if (!is_array($userRow)) {
        cvAuthResponse(false, 'Utente non trovato.', [], 'USER_NOT_FOUND', 404);
    }

    $email = cvAuthNormalizeEmail((string) ($userRow['email'] ?? ''));
    if ($newsletterSubscribed !== null) {
        cvAuthSetNewsletterForUser($connection, $userId, $email, (bool) $newsletterSubscribed, 'profile');
    }

    $user = cvAuthToUserPayload($userRow);
    cvAuthLoginSession($user);
    cvAuthResponse(true, 'Profilo aggiornato con successo.', ['user' => $user]);
}

function cvAuthGoogleClientIds(?mysqli $connection = null): array
{
    $ids = [];

    if (defined('CV_GOOGLE_CLIENT_ID')) {
        $one = trim((string) CV_GOOGLE_CLIENT_ID);
        if ($one !== '') {
            $ids[] = $one;
        }
    }

    if (defined('CV_GOOGLE_CLIENT_IDS') && is_array(CV_GOOGLE_CLIENT_IDS)) {
        foreach (CV_GOOGLE_CLIENT_IDS as $rawId) {
            $candidate = trim((string) $rawId);
            if ($candidate !== '') {
                $ids[] = $candidate;
            }
        }
    }

    $envIds = trim((string) (getenv('CV_GOOGLE_CLIENT_IDS') ?: ''));
    if ($envIds !== '') {
        $parts = explode(',', $envIds);
        foreach ($parts as $part) {
            $candidate = trim($part);
            if ($candidate !== '') {
                $ids[] = $candidate;
            }
        }
    }

    if ($connection instanceof mysqli && function_exists('cvRuntimeSettings')) {
        try {
            $settings = cvRuntimeSettings($connection);

            $runtimeOne = trim((string) ($settings['auth_google_client_id'] ?? ''));
            if ($runtimeOne !== '') {
                $ids[] = $runtimeOne;
            }

            $runtimeCsv = trim((string) ($settings['auth_google_client_ids_csv'] ?? ''));
            if ($runtimeCsv !== '') {
                $parts = explode(',', $runtimeCsv);
                foreach ($parts as $part) {
                    $candidate = trim($part);
                    if ($candidate !== '') {
                        $ids[] = $candidate;
                    }
                }
            }
        } catch (Throwable $exception) {
            // ignore runtime settings errors
        }
    }

    return array_values(array_unique($ids));
}

function cvAuthHasGoogleUserIdColumn(mysqli $connection): bool
{
    $query = "SHOW COLUMNS FROM viaggiatori LIKE 'google_userid'";
    $result = $connection->query($query);
    if (!$result instanceof mysqli_result) {
        return false;
    }

    $exists = $result->num_rows > 0;
    $result->free();
    return $exists;
}

function cvAuthTableHasColumn(mysqli $connection, string $tableName, string $columnName): bool
{
    static $cache = [];

    $table = trim($tableName);
    $column = trim($columnName);
    if ($table === '' || $column === '') {
        return false;
    }

    if (isset($cache[$table][$column])) {
        return (bool) $cache[$table][$column];
    }

    $safeTable = str_replace('`', '``', $table);
    $safeColumn = str_replace('`', '``', $column);
    $query = "SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'";
    $result = $connection->query($query);
    if (!$result instanceof mysqli_result) {
        $cache[$table][$column] = false;
        return false;
    }

    $exists = $result->num_rows > 0;
    $result->free();
    $cache[$table][$column] = $exists;
    return $exists;
}

function cvAuthEnsureGoogleUserIdColumn(mysqli $connection): bool
{
    if (cvAuthHasGoogleUserIdColumn($connection)) {
        return true;
    }

    $connection->query('ALTER TABLE viaggiatori ADD COLUMN google_userid VARCHAR(191) DEFAULT NULL');
    $connection->query('ALTER TABLE viaggiatori ADD KEY idx_viaggiatori_google_userid (google_userid)');

    return cvAuthHasGoogleUserIdColumn($connection);
}

function cvAuthHttpGetJson(string $url): ?array
{
    $raw = null;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch !== false) {
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 12);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            $response = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if (is_string($response) && $status >= 200 && $status < 300) {
                $raw = $response;
            }
        }
    }

    if (!is_string($raw)) {
        $context = stream_context_create(
            [
                'http' => [
                    'method' => 'GET',
                    'timeout' => 12,
                    'ignore_errors' => true,
                ],
            ]
        );
        $fallback = @file_get_contents($url, false, $context);
        if (is_string($fallback)) {
            $raw = $fallback;
        }
    }

    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function cvAuthVerifyGoogleIdToken(string $idToken, ?mysqli $connection = null): ?array
{
    $url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken);
    $tokenInfo = cvAuthHttpGetJson($url);
    if (!is_array($tokenInfo)) {
        return null;
    }

    $emailVerified = $tokenInfo['email_verified'] ?? false;
    $isVerified = ($emailVerified === true || $emailVerified === 'true' || $emailVerified === 1 || $emailVerified === '1');
    if (!$isVerified) {
        return null;
    }

    $clientIds = cvAuthGoogleClientIds($connection);
    if (count($clientIds) > 0) {
        $audience = (string) ($tokenInfo['aud'] ?? '');
        if ($audience === '' || !in_array($audience, $clientIds, true)) {
            return null;
        }
    } else {
        return null;
    }

    return $tokenInfo;
}

function cvAuthHandleGoogle(mysqli $connection): void
{
    if (!cvAuthEnsureGoogleUserIdColumn($connection)) {
        cvAuthResponse(false, 'Colonna google_userid non disponibile in tabella viaggiatori.', [], 'GOOGLE_SCHEMA_MISSING', 500);
    }

    $payload = cvAuthRequestData();
    $idToken = trim((string) ($payload['id_token'] ?? ''));
    if ($idToken === '') {
        cvAuthResponse(false, 'Token Google mancante.', [], 'VALIDATION_ERROR', 422);
    }

    $tokenInfo = cvAuthVerifyGoogleIdToken($idToken, $connection);
    if (!is_array($tokenInfo)) {
        cvAuthResponse(false, 'Token Google non valido o configurazione assente.', [], 'GOOGLE_TOKEN_INVALID', 401);
    }

    $email = cvAuthNormalizeEmail((string) ($tokenInfo['email'] ?? ''));
    $googleUserId = trim((string) ($tokenInfo['sub'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $googleUserId === '') {
        cvAuthResponse(false, 'Dati Google non validi.', [], 'GOOGLE_DATA_INVALID', 401);
    }

    $userRow = cvAuthGetUserByGoogleId($connection, $googleUserId);
    if (!is_array($userRow)) {
        $userRow = cvAuthGetUserByEmail($connection, $email);
        if (is_array($userRow)) {
            $existingGoogleUserId = trim((string) ($userRow['google_userid'] ?? ''));
            if ($existingGoogleUserId !== '' && $existingGoogleUserId !== $googleUserId) {
                cvAuthResponse(false, 'Questa email è già collegata a un altro account Google.', [], 'GOOGLE_CONFLICT', 409);
            }

            $updateSql = 'UPDATE viaggiatori SET google_userid = ?, stato = 1 WHERE id_vg = ? LIMIT 1';
            $updateStmt = $connection->prepare($updateSql);
            if ($updateStmt instanceof mysqli_stmt) {
                $userId = (int) ($userRow['id_vg'] ?? 0);
                $updateStmt->bind_param('si', $googleUserId, $userId);
                $updateStmt->execute();
                $updateStmt->close();
            }
        }
    }

    if (!is_array($userRow)) {
        $doubleCheckByEmail = cvAuthGetUserByEmail($connection, $email);
        if (is_array($doubleCheckByEmail)) {
            $user = cvAuthToUserPayload($doubleCheckByEmail);
            cvAuthLoginSession($user, true);
            cvAuthResponse(true, 'Accesso con Google completato.', ['user' => $user]);
        }

        $doubleCheckByGoogle = cvAuthGetUserByGoogleId($connection, $googleUserId);
        if (is_array($doubleCheckByGoogle)) {
            $user = cvAuthToUserPayload($doubleCheckByGoogle);
            cvAuthLoginSession($user, true);
            cvAuthResponse(true, 'Accesso con Google completato.', ['user' => $user]);
        }

        $fullName = trim((string) ($tokenInfo['name'] ?? ''));
        if ($fullName === '') {
            $fullName = trim((string) (($tokenInfo['given_name'] ?? '') . ' ' . ($tokenInfo['family_name'] ?? '')));
        }
        [$name, $surname] = cvAuthSplitFullName($fullName !== '' ? $fullName : 'Utente Google');

        $generatedPassword = bin2hex(random_bytes(16));
        $passwordHash = password_hash($generatedPassword, PASSWORD_DEFAULT);
        if (!is_string($passwordHash) || $passwordHash === '') {
            cvAuthResponse(false, 'Impossibile completare il login Google.', [], 'GOOGLE_LOGIN_ERROR', 500);
        }

        $insertSql = 'INSERT INTO viaggiatori (nome, cognome, email, pass, tel, data, profilo, tipo_pag, stato, google_userid) VALUES (?, ?, ?, ?, ?, ?, 0, 0, 1, ?)';
        $insertStmt = $connection->prepare($insertSql);
        if (!$insertStmt instanceof mysqli_stmt) {
            cvAuthResponse(false, 'Impossibile completare il login Google.', [], 'GOOGLE_LOGIN_ERROR', 500);
        }

        $defaultPhone = '-';
        $defaultDate = '1970-01-01';
        $insertStmt->bind_param('sssssss', $name, $surname, $email, $passwordHash, $defaultPhone, $defaultDate, $googleUserId);
        if (!$insertStmt->execute()) {
            $insertStmt->close();
            cvAuthResponse(false, 'Errore durante la creazione dell\'account Google.', [], 'GOOGLE_LOGIN_ERROR', 500);
        }
        $newUserId = (int) $insertStmt->insert_id;
        $insertStmt->close();

        $user = [
            'id' => $newUserId,
            'nome' => $name,
            'cognome' => $surname,
            'email' => $email,
        ];
        cvAuthLoginSession($user, true);
        cvAuthResponse(true, 'Accesso con Google completato.', ['user' => $user]);
    }

    if ((int) ($userRow['stato'] ?? 0) !== 1) {
        $activateSql = 'UPDATE viaggiatori SET stato = 1 WHERE id_vg = ? LIMIT 1';
        $activateStmt = $connection->prepare($activateSql);
        if ($activateStmt instanceof mysqli_stmt) {
            $userId = (int) ($userRow['id_vg'] ?? 0);
            $activateStmt->bind_param('i', $userId);
            $activateStmt->execute();
            $activateStmt->close();
        }
    }

    $user = cvAuthToUserPayload($userRow);
    cvAuthLoginSession($user, true);
    cvAuthResponse(true, 'Accesso con Google completato.', ['user' => $user]);
}

function cvEnsurePartnerLeadsTable(mysqli $connection): bool
{
    $sql = <<<SQL
CREATE TABLE IF NOT EXISTS cv_partner_leads (
  id_lead BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_name VARCHAR(180) NOT NULL,
  contact_name VARCHAR(140) NOT NULL,
  email VARCHAR(180) NOT NULL,
  phone VARCHAR(40) DEFAULT '',
  website VARCHAR(255) DEFAULT '',
  city VARCHAR(120) DEFAULT '',
  notes TEXT DEFAULT NULL,
  source_url VARCHAR(255) DEFAULT '',
  source_ip VARCHAR(45) DEFAULT '',
  status TINYINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id_lead),
  KEY idx_partner_leads_email (email),
  KEY idx_partner_leads_created (created_at),
  KEY idx_partner_leads_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL;

    return (bool) $connection->query($sql);
}

function cvAuthClientIp(): string
{
    $headers = [
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_REAL_IP',
        'REMOTE_ADDR',
    ];

    foreach ($headers as $header) {
        $value = trim((string) ($_SERVER[$header] ?? ''));
        if ($value === '') {
            continue;
        }

        if ($header === 'HTTP_X_FORWARDED_FOR') {
            $parts = explode(',', $value);
            foreach ($parts as $part) {
                $candidate = trim($part);
                if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                    return $candidate;
                }
            }
            continue;
        }

        if (filter_var($value, FILTER_VALIDATE_IP)) {
            return $value;
        }
    }

    return '';
}

function cvAuthNotifyPartnerLead(array $lead): void
{
    $to = trim((string) CV_PARTNER_LEAD_NOTIFY_EMAIL);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return;
    }

    $subject = 'Nuova richiesta partner Cercaviaggio';
    $lines = [
        'Azienda: ' . (string) ($lead['company_name'] ?? ''),
        'Referente: ' . (string) ($lead['contact_name'] ?? ''),
        'Email: ' . (string) ($lead['email'] ?? ''),
        'Telefono: ' . (string) ($lead['phone'] ?? ''),
        'Sito: ' . (string) ($lead['website'] ?? ''),
        'Citta: ' . (string) ($lead['city'] ?? ''),
        'Note: ' . (string) ($lead['notes'] ?? ''),
        'Pagina: ' . (string) ($lead['source_url'] ?? ''),
        'IP: ' . (string) ($lead['source_ip'] ?? ''),
    ];

    $body = implode("\n", $lines);
    $headers = "Content-Type: text/plain; charset=UTF-8\r\n";
    $from = trim((string) CV_AUTH_DEFAULT_FROM_EMAIL);
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        $from = 'noreply@fillbus.it';
    }
    $headers .= "From: " . $from . "\r\n";

    @mail($to, $subject, $body, $headers);
}

function cvAuthHandlePartnerLead(mysqli $connection): void
{
    $payload = cvAuthRequestData();

    $companyName = trim((string) ($payload['company_name'] ?? ''));
    $contactName = trim((string) ($payload['contact_name'] ?? ''));
    $email = cvAuthNormalizeEmail((string) ($payload['email'] ?? ''));
    $phone = trim((string) ($payload['phone'] ?? ''));
    $website = trim((string) ($payload['website'] ?? ''));
    $city = trim((string) ($payload['city'] ?? ''));
    $notes = trim((string) ($payload['notes'] ?? ''));
    $privacyAccepted = (bool) ($payload['privacy_accepted'] ?? false);

    if (strlen($companyName) < 2) {
        cvAuthResponse(false, 'Inserisci il nome azienda.', [], 'VALIDATION_ERROR', 422);
    }

    if (strlen($contactName) < 2) {
        cvAuthResponse(false, 'Inserisci il referente.', [], 'VALIDATION_ERROR', 422);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        cvAuthResponse(false, 'Email non valida.', [], 'VALIDATION_ERROR', 422);
    }

    if (!$privacyAccepted) {
        cvAuthResponse(false, 'Devi accettare l\'informativa privacy.', [], 'VALIDATION_ERROR', 422);
    }

    if ($website !== '') {
        $normalizedWebsite = $website;
        if (!preg_match('#^https?://#i', $normalizedWebsite)) {
            $normalizedWebsite = 'https://' . $normalizedWebsite;
        }
        if (!filter_var($normalizedWebsite, FILTER_VALIDATE_URL)) {
            cvAuthResponse(false, 'Sito web non valido.', [], 'VALIDATION_ERROR', 422);
        }
        $website = $normalizedWebsite;
    }

    if (!cvEnsurePartnerLeadsTable($connection)) {
        cvAuthResponse(false, 'Impossibile salvare la richiesta in questo momento.', [], 'PARTNER_LEAD_ERROR', 500);
    }

    $sourceUrl = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    $sourceIp = cvAuthClientIp();

    $sql = 'INSERT INTO cv_partner_leads (company_name, contact_name, email, phone, website, city, notes, source_url, source_ip, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0)';
    $statement = $connection->prepare($sql);
    if (!$statement instanceof mysqli_stmt) {
        cvAuthResponse(false, 'Impossibile salvare la richiesta in questo momento.', [], 'PARTNER_LEAD_ERROR', 500);
    }

    $statement->bind_param('sssssssss', $companyName, $contactName, $email, $phone, $website, $city, $notes, $sourceUrl, $sourceIp);
    if (!$statement->execute()) {
        $statement->close();
        cvAuthResponse(false, 'Errore durante il salvataggio della richiesta.', [], 'PARTNER_LEAD_ERROR', 500);
    }

    $leadId = (int) $statement->insert_id;
    $statement->close();

    cvAuthNotifyPartnerLead(
        [
            'company_name' => $companyName,
            'contact_name' => $contactName,
            'email' => $email,
            'phone' => $phone,
            'website' => $website,
            'city' => $city,
            'notes' => $notes,
            'source_url' => $sourceUrl,
            'source_ip' => $sourceIp,
        ]
    );

    cvAuthResponse(
        true,
        'Richiesta inviata. Ti contatteremo a breve.',
        [
            'lead_id' => $leadId,
        ]
    );
}
