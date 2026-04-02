<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/conn.php';
require_once __DIR__ . '/../includes/cache_tools.php';
require_once __DIR__ . '/../includes/runtime_settings.php';

if (!function_exists('cvAccessoLoadConfig')) {
    /**
     * @return array<string,mixed>
     */
    function cvAccessoLoadConfig(): array
    {
        static $config = null;
        if (is_array($config)) {
            return $config;
        }

        $base = [
            'allowed_ips' => [],
            'session_ttl' => 604800,
            'session_name' => 'cercaviaggio_back',
            'brand_name' => 'Cercaviaggio',
            'brand_subtitle' => 'Backend multiazienda',
            'accounts' => [],
        ];

        $legacyConfigFile = dirname(__DIR__) . '/sync/web_config.php';
        if (is_file($legacyConfigFile)) {
            $legacyConfig = require $legacyConfigFile;
            if (is_array($legacyConfig)) {
                if (empty($base['allowed_ips']) && isset($legacyConfig['allowed_ips']) && is_array($legacyConfig['allowed_ips'])) {
                    $base['allowed_ips'] = $legacyConfig['allowed_ips'];
                }
                if (isset($legacyConfig['session_ttl'])) {
                    $base['session_ttl'] = (int) $legacyConfig['session_ttl'];
                }
            }
        }

        $localConfigFile = __DIR__ . '/config.php';
        if (is_file($localConfigFile)) {
            $localConfig = require $localConfigFile;
            if (is_array($localConfig)) {
                $base = array_merge($base, $localConfig);
            }
        }

        $base['allowed_ips'] = isset($base['allowed_ips']) && is_array($base['allowed_ips']) ? $base['allowed_ips'] : [];
        $base['session_ttl'] = max(1800, (int) ($base['session_ttl'] ?? 604800));
        $base['session_name'] = trim((string) ($base['session_name'] ?? 'cercaviaggio_back')) ?: 'cercaviaggio_back';
        $base['brand_name'] = trim((string) ($base['brand_name'] ?? 'Cercaviaggio')) ?: 'Cercaviaggio';
        $base['brand_subtitle'] = trim((string) ($base['brand_subtitle'] ?? 'Backend multiazienda')) ?: 'Backend multiazienda';

        $configAccounts = cvAccessoNormalizeAccounts(isset($base['accounts']) && is_array($base['accounts']) ? $base['accounts'] : []);
        $dbAccounts = cvAccessoLoadAccountsFromDatabase();
        $base['accounts'] = count($dbAccounts) > 0 ? $dbAccounts : $configAccounts;
        $base['auth_source'] = count($dbAccounts) > 0 ? 'database' : 'config';

        $config = $base;
        return $config;
    }
}

if (!function_exists('cvAccessoNormalizeAccounts')) {
    /**
     * @param array<int|string,mixed> $accounts
     * @return array<string,array<string,mixed>>
     */
    function cvAccessoNormalizeAccounts(array $accounts): array
    {
        $normalized = [];
        foreach ($accounts as $key => $account) {
            if (!is_array($account)) {
                continue;
            }

            $fallbackEmail = is_string($key) ? $key : '';
            $email = strtolower(trim((string) ($account['email'] ?? $fallbackEmail)));
            if ($email === '') {
                continue;
            }

            $role = strtolower(trim((string) ($account['role'] ?? 'provider')));
            if ($role !== 'admin') {
                $role = 'provider';
            }

            $providers = [];
            $rawProviders = isset($account['providers']) && is_array($account['providers']) ? $account['providers'] : [];
            foreach ($rawProviders as $providerCode) {
                $providerCode = trim((string) $providerCode);
                if ($providerCode === '') {
                    continue;
                }
                $providers[$providerCode] = $providerCode;
            }

            if ($role === 'admin') {
                $providers = ['*' => '*'];
            }

            $normalized[$email] = [
                'email' => $email,
                'name' => trim((string) ($account['name'] ?? $email)) ?: $email,
                'password_hash' => trim((string) ($account['password_hash'] ?? '')),
                'logo_path' => trim((string) ($account['logo_path'] ?? '')),
                'role' => $role,
                'providers' => array_values($providers),
                'active' => !array_key_exists('active', $account) || !empty($account['active']),
            ];
        }

        return $normalized;
    }
}

if (!function_exists('cvAccessoBackendTablesExist')) {
    function cvAccessoBackendTablesExist(mysqli $connection): bool
    {
        static $exists = null;
        if (is_bool($exists)) {
            return $exists;
        }

        $sql = "SELECT COUNT(*) AS total
                FROM information_schema.tables
                WHERE table_schema = DATABASE()
                  AND table_name IN ('cv_backend_users', 'cv_backend_user_providers')";
        $result = $connection->query($sql);
        if (!$result instanceof mysqli_result) {
            $exists = false;
            return $exists;
        }

        $row = $result->fetch_assoc();
        $result->free();
        $exists = isset($row['total']) && (int) $row['total'] === 2;
        return $exists;
    }
}

if (!function_exists('cvAccessoBackendEncryptedPasswordColumnExists')) {
    function cvAccessoBackendEncryptedPasswordColumnExists(mysqli $connection): bool
    {
        static $cache = null;
        if (is_bool($cache)) {
            return $cache;
        }

        $result = $connection->query("SHOW COLUMNS FROM cv_backend_users LIKE 'password_encrypted'");
        if (!$result instanceof mysqli_result) {
            $cache = false;
            return $cache;
        }

        $cache = $result->num_rows > 0;
        $result->free();
        return $cache;
    }
}

if (!function_exists('cvAccessoBackendLogoColumnExists')) {
    function cvAccessoBackendLogoColumnExists(mysqli $connection, bool $refresh = false): bool
    {
        static $cache = null;
        if ($refresh) {
            $cache = null;
        }
        if (is_bool($cache)) {
            return $cache;
        }

        $result = $connection->query("SHOW COLUMNS FROM cv_backend_users LIKE 'logo_path'");
        if (!$result instanceof mysqli_result) {
            $cache = false;
            return $cache;
        }

        $cache = $result->num_rows > 0;
        $result->free();
        return $cache;
    }
}

if (!function_exists('cvAccessoEnsureBackendLogoColumn')) {
    function cvAccessoEnsureBackendLogoColumn(mysqli $connection): void
    {
        if (!cvAccessoBackendTablesExist($connection) || cvAccessoBackendLogoColumnExists($connection)) {
            return;
        }

        $connection->query("ALTER TABLE cv_backend_users ADD COLUMN logo_path VARCHAR(255) DEFAULT NULL AFTER name");
        cvAccessoBackendLogoColumnExists($connection, true);
    }
}

if (!function_exists('cvAccessoCredentialCipherKey')) {
    function cvAccessoCredentialCipherKey(array $config): string
    {
        return trim((string) ($config['credential_cipher_key'] ?? ''));
    }
}

if (!function_exists('cvAccessoEncryptVisiblePassword')) {
    function cvAccessoEncryptVisiblePassword(string $plainPassword, array $config): string
    {
        $plainPassword = (string) $plainPassword;
        $keyMaterial = cvAccessoCredentialCipherKey($config);
        if ($plainPassword === '' || $keyMaterial === '' || !function_exists('openssl_encrypt')) {
            return '';
        }

        $key = hash('sha256', $keyMaterial, true);
        $iv = random_bytes(16);
        $cipherRaw = openssl_encrypt($plainPassword, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if (!is_string($cipherRaw) || $cipherRaw === '') {
            return '';
        }

        $mac = hash_hmac('sha256', $iv . $cipherRaw, $key, true);
        return 'v1$' . base64_encode($iv) . '$' . base64_encode($mac) . '$' . base64_encode($cipherRaw);
    }
}

if (!function_exists('cvAccessoDecryptVisiblePassword')) {
    function cvAccessoDecryptVisiblePassword(string $encryptedPayload, array $config): string
    {
        $payload = trim($encryptedPayload);
        $keyMaterial = cvAccessoCredentialCipherKey($config);
        if ($payload === '' || $keyMaterial === '' || !function_exists('openssl_decrypt')) {
            return '';
        }

        $parts = explode('$', $payload, 4);
        if (count($parts) !== 4 || $parts[0] !== 'v1') {
            return '';
        }

        $iv = base64_decode($parts[1], true);
        $mac = base64_decode($parts[2], true);
        $cipherRaw = base64_decode($parts[3], true);
        if (!is_string($iv) || !is_string($mac) || !is_string($cipherRaw) || strlen($iv) !== 16 || $mac === '') {
            return '';
        }

        $key = hash('sha256', $keyMaterial, true);
        $expectedMac = hash_hmac('sha256', $iv . $cipherRaw, $key, true);
        if (!hash_equals($expectedMac, $mac)) {
            return '';
        }

        $plain = openssl_decrypt($cipherRaw, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return is_string($plain) ? $plain : '';
    }
}

if (!function_exists('cvAccessoLoadAccountsFromDatabase')) {
    /**
     * @return array<string,array<string,mixed>>
     */
    function cvAccessoLoadAccountsFromDatabase(): array
    {
        static $accounts = null;
        if (is_array($accounts)) {
            return $accounts;
        }

        $accounts = [];

        try {
            $connection = cvDbConnection();
            if (!cvAccessoBackendTablesExist($connection)) {
                return $accounts;
            }

            $hasEncryptedPasswordColumn = cvAccessoBackendEncryptedPasswordColumnExists($connection);
            $hasLogoPathColumn = cvAccessoBackendLogoColumnExists($connection);

            $sql = "SELECT
                        u.id_user,
                        u.email,
                        u.name,
                        " . ($hasLogoPathColumn ? "u.logo_path" : "'' AS logo_path") . ",
                        u.password_hash,
                        " . ($hasEncryptedPasswordColumn ? "u.password_encrypted" : "'' AS password_encrypted") . ",
                        u.role,
                        u.is_active,
                        up.provider_code
                    FROM cv_backend_users AS u
                    LEFT JOIN cv_backend_user_providers AS up
                        ON up.id_user = u.id_user
                    ORDER BY u.id_user ASC, up.provider_code ASC";
            $result = $connection->query($sql);
            if (!$result instanceof mysqli_result) {
                return $accounts;
            }

            while ($row = $result->fetch_assoc()) {
                if (!is_array($row)) {
                    continue;
                }

                $email = strtolower(trim((string) ($row['email'] ?? '')));
                if ($email === '') {
                    continue;
                }

                if (!isset($accounts[$email])) {
                    $role = strtolower(trim((string) ($row['role'] ?? 'provider')));
                    if ($role !== 'admin') {
                        $role = 'provider';
                    }

                    $accounts[$email] = [
                        'email' => $email,
                        'name' => trim((string) ($row['name'] ?? $email)) ?: $email,
                        'logo_path' => trim((string) ($row['logo_path'] ?? '')),
                        'password_hash' => trim((string) ($row['password_hash'] ?? '')),
                        'password_encrypted' => trim((string) ($row['password_encrypted'] ?? '')),
                        'role' => $role,
                        'providers' => [],
                        'active' => isset($row['is_active']) ? (int) $row['is_active'] === 1 : true,
                    ];
                }

                if ($accounts[$email]['role'] === 'admin') {
                    $accounts[$email]['providers'] = ['*'];
                    continue;
                }

                $providerCode = trim((string) ($row['provider_code'] ?? ''));
                if ($providerCode !== '') {
                    $accounts[$email]['providers'][$providerCode] = $providerCode;
                }
            }

            $result->free();

            foreach ($accounts as $email => $account) {
                $providers = isset($account['providers']) && is_array($account['providers']) ? array_values($account['providers']) : [];
                $accounts[$email]['providers'] = $providers;
            }
        } catch (Throwable $exception) {
            $accounts = [];
        }

        return $accounts;
    }
}

if (!function_exists('cvAccessoFetchBackendUsers')) {
    /**
     * @return array<int,array<string,mixed>>
     */
    function cvAccessoFetchBackendUsers(mysqli $connection): array
    {
        if (!cvAccessoBackendTablesExist($connection)) {
            return [];
        }

        $hasEncryptedPasswordColumn = cvAccessoBackendEncryptedPasswordColumnExists($connection);
        $hasLogoPathColumn = cvAccessoBackendLogoColumnExists($connection);
        $config = cvAccessoLoadConfig();

        $sql = "SELECT
                    u.id_user,
                    u.email,
                    u.name,
                    " . ($hasLogoPathColumn ? "u.logo_path" : "'' AS logo_path") . ",
                    " . ($hasEncryptedPasswordColumn ? "u.password_encrypted" : "'' AS password_encrypted") . ",
                    u.role,
                    u.is_active,
                    u.created_at,
                    u.updated_at,
                    up.provider_code
                FROM cv_backend_users AS u
                LEFT JOIN cv_backend_user_providers AS up
                    ON up.id_user = u.id_user
                ORDER BY u.role ASC, u.email ASC, up.provider_code ASC";
        $result = $connection->query($sql);
        if (!$result instanceof mysqli_result) {
            return [];
        }

        $users = [];
        while ($row = $result->fetch_assoc()) {
            if (!is_array($row)) {
                continue;
            }

            $idUser = isset($row['id_user']) ? (int) $row['id_user'] : 0;
            if ($idUser <= 0) {
                continue;
            }

            if (!isset($users[$idUser])) {
                $role = strtolower(trim((string) ($row['role'] ?? 'provider')));
                if ($role !== 'admin') {
                    $role = 'provider';
                }

                $users[$idUser] = [
                    'id_user' => $idUser,
                    'email' => strtolower(trim((string) ($row['email'] ?? ''))),
                    'name' => trim((string) ($row['name'] ?? '')),
                    'logo_path' => trim((string) ($row['logo_path'] ?? '')),
                    'password_plain' => cvAccessoDecryptVisiblePassword((string) ($row['password_encrypted'] ?? ''), $config),
                    'role' => $role,
                    'is_active' => isset($row['is_active']) ? (int) $row['is_active'] : 0,
                    'created_at' => (string) ($row['created_at'] ?? ''),
                    'updated_at' => (string) ($row['updated_at'] ?? ''),
                    'providers' => [],
                ];
            }

            if ($users[$idUser]['role'] === 'admin') {
                $users[$idUser]['providers'] = ['*'];
                continue;
            }

            $providerCode = trim((string) ($row['provider_code'] ?? ''));
            if ($providerCode !== '') {
                $users[$idUser]['providers'][$providerCode] = $providerCode;
            }
        }

        $result->free();

        foreach ($users as $idUser => $user) {
            $providers = isset($user['providers']) && is_array($user['providers']) ? array_values($user['providers']) : [];
            $users[$idUser]['providers'] = $providers;
        }

        return array_values($users);
    }
}

if (!function_exists('cvAccessoSaveBackendUser')) {
    /**
     * @param array<string,mixed> $payload
     */
    function cvAccessoSaveBackendUser(mysqli $connection, array $payload): int
    {
        if (!cvAccessoBackendTablesExist($connection)) {
            throw new RuntimeException('Le tabelle backend utenti non sono disponibili.');
        }

        $idUser = isset($payload['id_user']) ? (int) $payload['id_user'] : 0;
        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        $name = trim((string) ($payload['name'] ?? ''));
        $logoPath = trim((string) ($payload['logo_path'] ?? ''));
        $role = strtolower(trim((string) ($payload['role'] ?? 'provider')));
        $password = (string) ($payload['password'] ?? '');
        $isActive = !empty($payload['is_active']) ? 1 : 0;
        $providers = isset($payload['providers']) && is_array($payload['providers']) ? cvCacheNormalizeProviderCodes($payload['providers']) : [];
        $hasEncryptedPasswordColumn = cvAccessoBackendEncryptedPasswordColumnExists($connection);
        $hasLogoPathColumn = cvAccessoBackendLogoColumnExists($connection);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Email non valida.');
        }

        if ($name === '') {
            throw new RuntimeException('Nome account obbligatorio.');
        }

        if ($role !== 'admin') {
            $role = 'provider';
        }

        if ($role === 'provider' && count($providers) === 0) {
            throw new RuntimeException('Assegna almeno un provider all’account.');
        }

        if ($idUser <= 0 && $password === '') {
            throw new RuntimeException('Password obbligatoria per il nuovo account.');
        }

        $passwordHash = '';
        $passwordEncrypted = '';
        if ($password !== '') {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            if (!is_string($passwordHash) || $passwordHash === '') {
                throw new RuntimeException('Impossibile generare l’hash password.');
            }

            if ($hasEncryptedPasswordColumn) {
                $passwordEncrypted = cvAccessoEncryptVisiblePassword($password, cvAccessoLoadConfig());
                if ($passwordEncrypted === '') {
                    throw new RuntimeException('Impossibile cifrare la password consultabile. Verifica la chiave backend.');
                }
            }
        }

        $connection->begin_transaction();

        try {
            if ($idUser > 0) {
                if ($passwordHash !== '') {
                    if ($hasEncryptedPasswordColumn && $hasLogoPathColumn) {
                        $sql = "UPDATE cv_backend_users
                                SET email = ?, name = ?, password_hash = ?, password_encrypted = ?, logo_path = ?, role = ?, is_active = ?, updated_at = NOW()
                                WHERE id_user = ?
                                LIMIT 1";
                    } elseif ($hasEncryptedPasswordColumn) {
                        $sql = "UPDATE cv_backend_users
                                SET email = ?, name = ?, password_hash = ?, password_encrypted = ?, role = ?, is_active = ?, updated_at = NOW()
                                WHERE id_user = ?
                                LIMIT 1";
                    } elseif ($hasLogoPathColumn) {
                        $sql = "UPDATE cv_backend_users
                                SET email = ?, name = ?, password_hash = ?, logo_path = ?, role = ?, is_active = ?, updated_at = NOW()
                                WHERE id_user = ?
                                LIMIT 1";
                    } else {
                        $sql = "UPDATE cv_backend_users
                                SET email = ?, name = ?, password_hash = ?, role = ?, is_active = ?, updated_at = NOW()
                                WHERE id_user = ?
                                LIMIT 1";
                    }
                    $statement = $connection->prepare($sql);
                    if (!$statement instanceof mysqli_stmt) {
                        throw new RuntimeException('Prepare update utente fallita.');
                    }
                    if ($hasEncryptedPasswordColumn && $hasLogoPathColumn) {
                        $statement->bind_param('ssssssii', $email, $name, $passwordHash, $passwordEncrypted, $logoPath, $role, $isActive, $idUser);
                    } elseif ($hasEncryptedPasswordColumn) {
                        $statement->bind_param('sssssii', $email, $name, $passwordHash, $passwordEncrypted, $role, $isActive, $idUser);
                    } elseif ($hasLogoPathColumn) {
                        $statement->bind_param('sssssii', $email, $name, $passwordHash, $logoPath, $role, $isActive, $idUser);
                    } else {
                        $statement->bind_param('ssssii', $email, $name, $passwordHash, $role, $isActive, $idUser);
                    }
                } else {
                    if ($hasLogoPathColumn) {
                        $sql = "UPDATE cv_backend_users
                                SET email = ?, name = ?, logo_path = ?, role = ?, is_active = ?, updated_at = NOW()
                                WHERE id_user = ?
                                LIMIT 1";
                    } else {
                        $sql = "UPDATE cv_backend_users
                                SET email = ?, name = ?, role = ?, is_active = ?, updated_at = NOW()
                                WHERE id_user = ?
                                LIMIT 1";
                    }
                    $statement = $connection->prepare($sql);
                    if (!$statement instanceof mysqli_stmt) {
                        throw new RuntimeException('Prepare update utente fallita.');
                    }
                    if ($hasLogoPathColumn) {
                        $statement->bind_param('ssssii', $email, $name, $logoPath, $role, $isActive, $idUser);
                    } else {
                        $statement->bind_param('sssii', $email, $name, $role, $isActive, $idUser);
                    }
                }

                if (!$statement->execute()) {
                    $error = $statement->error;
                    $statement->close();
                    throw new RuntimeException('Update utente fallita: ' . $error);
                }
                $statement->close();
            } else {
                if ($hasEncryptedPasswordColumn && $hasLogoPathColumn) {
                    $sql = "INSERT INTO cv_backend_users (email, name, password_hash, password_encrypted, logo_path, role, is_active)
                            VALUES (?, ?, ?, ?, ?, ?, ?)";
                } elseif ($hasEncryptedPasswordColumn) {
                    $sql = "INSERT INTO cv_backend_users (email, name, password_hash, password_encrypted, role, is_active)
                            VALUES (?, ?, ?, ?, ?, ?)";
                } elseif ($hasLogoPathColumn) {
                    $sql = "INSERT INTO cv_backend_users (email, name, password_hash, logo_path, role, is_active)
                            VALUES (?, ?, ?, ?, ?, ?)";
                } else {
                    $sql = "INSERT INTO cv_backend_users (email, name, password_hash, role, is_active)
                            VALUES (?, ?, ?, ?, ?)";
                }
                $statement = $connection->prepare($sql);
                if (!$statement instanceof mysqli_stmt) {
                    throw new RuntimeException('Prepare insert utente fallita.');
                }
                if ($hasEncryptedPasswordColumn && $hasLogoPathColumn) {
                    $statement->bind_param('ssssssi', $email, $name, $passwordHash, $passwordEncrypted, $logoPath, $role, $isActive);
                } elseif ($hasEncryptedPasswordColumn) {
                    $statement->bind_param('sssssi', $email, $name, $passwordHash, $passwordEncrypted, $role, $isActive);
                } elseif ($hasLogoPathColumn) {
                    $statement->bind_param('sssssi', $email, $name, $passwordHash, $logoPath, $role, $isActive);
                } else {
                    $statement->bind_param('ssssi', $email, $name, $passwordHash, $role, $isActive);
                }
                if (!$statement->execute()) {
                    $error = $statement->error;
                    $statement->close();
                    throw new RuntimeException('Insert utente fallita: ' . $error);
                }
                $idUser = (int) $statement->insert_id;
                $statement->close();
            }

            $deleteStatement = $connection->prepare('DELETE FROM cv_backend_user_providers WHERE id_user = ?');
            if (!$deleteStatement instanceof mysqli_stmt) {
                throw new RuntimeException('Prepare reset provider utente fallita.');
            }
            $deleteStatement->bind_param('i', $idUser);
            if (!$deleteStatement->execute()) {
                $error = $deleteStatement->error;
                $deleteStatement->close();
                throw new RuntimeException('Reset provider utente fallito: ' . $error);
            }
            $deleteStatement->close();

            if ($role === 'provider') {
                $insertStatement = $connection->prepare(
                    'INSERT INTO cv_backend_user_providers (id_user, provider_code) VALUES (?, ?)'
                );
                if (!$insertStatement instanceof mysqli_stmt) {
                    throw new RuntimeException('Prepare insert provider utente fallita.');
                }

                foreach ($providers as $providerCode) {
                    $insertStatement->bind_param('is', $idUser, $providerCode);
                    if (!$insertStatement->execute()) {
                        $error = $insertStatement->error;
                        $insertStatement->close();
                        throw new RuntimeException('Assegnazione provider fallita: ' . $error);
                    }
                }
                $insertStatement->close();
            }

            $connection->commit();
            return $idUser;
        } catch (Throwable $exception) {
            $connection->rollback();
            throw $exception;
        }
    }
}

if (!function_exists('cvAccessoH')) {
    function cvAccessoH(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('cvAccessoBaseUrl')) {
    function cvAccessoBaseUrl(): string
    {
        $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/accesso/index.php'));
        $dir = trim(dirname($scriptName), '/');
        if ($dir === '' || $dir === '.') {
            return '/';
        }

        return '/' . $dir;
    }
}

if (!function_exists('cvAccessoUrl')) {
    function cvAccessoUrl(string $path = ''): string
    {
        $base = rtrim(cvAccessoBaseUrl(), '/');
        if ($path === '') {
            return $base !== '' ? $base . '/' : '/';
        }

        return ($base !== '' ? $base : '') . '/' . ltrim($path, '/');
    }
}

if (!function_exists('cvAccessoAssetVersion')) {
    function cvAccessoAssetVersion(string $relativePath): string
    {
        $absolutePath = __DIR__ . '/' . ltrim($relativePath, '/');
        if (!is_file($absolutePath)) {
            return '1';
        }

        return (string) filemtime($absolutePath);
    }
}

if (!function_exists('cvAccessoAssetUrl')) {
    function cvAccessoAssetUrl(string $relativePath): string
    {
        return cvAccessoUrl($relativePath) . '?v=' . rawurlencode(cvAccessoAssetVersion($relativePath));
    }
}

if (!function_exists('cvAccessoAccountLogoUrl')) {
    function cvAccessoAccountLogoUrl(string $logoPath): string
    {
        $path = trim($logoPath);
        if ($path === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $path) === 1) {
            return $path;
        }

        $path = ltrim($path, '/');

        if (strpos($path, 'images/') === 0) {
            return cvAccessoUrl('../assets/' . $path);
        }

        if (strpos($path, 'cache/') === 0) {
            return cvAccessoUrl('../files/' . $path);
        }

        if (strpos($path, 'files/') === 0) {
            return cvAccessoUrl('../' . $path);
        }

        return cvAccessoUrl('../files/' . $path);
    }
}

if (!function_exists('cvAccessoStartSession')) {
    /**
     * @param array<string,mixed> $config
     */
    function cvAccessoStartSession(array $config): void
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

        @ini_set('session.use_only_cookies', '1');
        @ini_set('session.use_strict_mode', '1');
        @ini_set('session.gc_maxlifetime', (string) (int) $config['session_ttl']);

        session_name((string) $config['session_name']);
        session_set_cookie_params([
            'lifetime' => (int) $config['session_ttl'],
            'path' => rtrim(cvAccessoBaseUrl(), '/') . '/',
            'domain' => '',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();

        $now = time();
        $lastAuthAt = isset($_SESSION['cv_accesso_auth_at']) ? (int) $_SESSION['cv_accesso_auth_at'] : 0;
        if ($lastAuthAt > 0 && ($now - $lastAuthAt) > (int) $config['session_ttl']) {
            $_SESSION = [];
            session_regenerate_id(true);
            return;
        }

        if (!empty($_SESSION['cv_accesso_authenticated'])) {
            $_SESSION['cv_accesso_auth_at'] = $now;
        }
    }
}

if (!function_exists('cvAccessoCsrfToken')) {
    function cvAccessoCsrfToken(): string
    {
        if (empty($_SESSION['cv_accesso_csrf']) || !is_string($_SESSION['cv_accesso_csrf'])) {
            $_SESSION['cv_accesso_csrf'] = bin2hex(random_bytes(24));
        }

        return (string) $_SESSION['cv_accesso_csrf'];
    }
}

if (!function_exists('cvAccessoCsrfField')) {
    function cvAccessoCsrfField(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . cvAccessoH(cvAccessoCsrfToken()) . '">';
    }
}

if (!function_exists('cvAccessoValidateCsrf')) {
    function cvAccessoValidateCsrf(): bool
    {
        $sessionToken = isset($_SESSION['cv_accesso_csrf']) ? (string) $_SESSION['cv_accesso_csrf'] : '';
        $postedToken = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
        return $sessionToken !== '' && $postedToken !== '' && hash_equals($sessionToken, $postedToken);
    }
}

if (!function_exists('cvAccessoLoginAccount')) {
    /**
     * @param array<string,mixed> $config
     * @return array<string,mixed>|null
     */
    function cvAccessoLoginAccount(array $config, string $email, string $password): ?array
    {
        $email = strtolower(trim($email));
        if ($email === '' || $password === '') {
            return null;
        }

        $accounts = isset($config['accounts']) && is_array($config['accounts']) ? $config['accounts'] : [];
        if (!isset($accounts[$email]) || !is_array($accounts[$email])) {
            return null;
        }

        $account = $accounts[$email];
        if (empty($account['active'])) {
            return null;
        }

        $passwordHash = (string) ($account['password_hash'] ?? '');
        if ($passwordHash === '' || !password_verify($password, $passwordHash)) {
            return null;
        }

        return $account;
    }
}

if (!function_exists('cvAccessoCurrentUser')) {
    /**
     * @param array<string,mixed> $config
     * @return array<string,mixed>|null
     */
    function cvAccessoCurrentUser(array $config): ?array
    {
        if (empty($_SESSION['cv_accesso_authenticated']) || empty($_SESSION['cv_accesso_user']) || !is_array($_SESSION['cv_accesso_user'])) {
            return null;
        }

        $sessionUser = $_SESSION['cv_accesso_user'];
        $email = strtolower(trim((string) ($sessionUser['email'] ?? '')));
        if ($email === '') {
            return null;
        }

        $accounts = isset($config['accounts']) && is_array($config['accounts']) ? $config['accounts'] : [];
        if (!isset($accounts[$email]) || !is_array($accounts[$email]) || empty($accounts[$email]['active'])) {
            return null;
        }

        $account = $accounts[$email];
        return [
            'email' => (string) ($account['email'] ?? $email),
            'name' => (string) ($account['name'] ?? $email),
            'logo_path' => trim((string) ($account['logo_path'] ?? '')),
            'role' => (string) ($account['role'] ?? 'provider'),
            'providers' => isset($account['providers']) && is_array($account['providers']) ? $account['providers'] : [],
            'login_at' => isset($sessionUser['login_at']) ? (int) $sessionUser['login_at'] : time(),
        ];
    }
}

if (!function_exists('cvAccessoLogout')) {
    function cvAccessoLogout(): void
    {
        $_SESSION['cv_accesso_authenticated'] = false;
        $_SESSION['cv_accesso_auth_at'] = 0;
        unset($_SESSION['cv_accesso_user']);
        session_regenerate_id(true);
    }
}

if (!function_exists('cvAccessoInit')) {
    /**
     * @return array{authenticated:bool,config:array<string,mixed>,messages:array<int,string>,errors:array<int,string>,current_user:?array}
     */
    function cvAccessoInit(): array
    {
        $config = cvAccessoLoadConfig();

        $clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        if (!empty($config['allowed_ips']) && !in_array($clientIp, $config['allowed_ips'], true)) {
            http_response_code(403);
            echo 'Access denied for IP: ' . cvAccessoH($clientIp);
            exit;
        }

        cvAccessoStartSession($config);

        $messages = [];
        $errors = [];
        $action = trim((string) ($_POST['action'] ?? ''));

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'logout') {
            if (!cvAccessoValidateCsrf()) {
                $errors[] = 'Sessione non valida. Riprova.';
            } else {
                cvAccessoLogout();
                $messages[] = 'Sessione chiusa.';
            }
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'login') {
            $email = (string) ($_POST['email'] ?? '');
            $password = (string) ($_POST['password'] ?? '');
            $account = cvAccessoLoginAccount($config, $email, $password);
            if ($account === null) {
                $errors[] = 'Credenziali non valide.';
            } else {
                $_SESSION['cv_accesso_authenticated'] = true;
                $_SESSION['cv_accesso_auth_at'] = time();
                $_SESSION['cv_accesso_user'] = [
                    'email' => (string) ($account['email'] ?? ''),
                    'login_at' => time(),
                ];
                cvAccessoCsrfToken();
                session_regenerate_id(true);
                $messages[] = 'Accesso effettuato.';
            }
        }

        $currentUser = cvAccessoCurrentUser($config);
        if ($currentUser === null && !empty($_SESSION['cv_accesso_authenticated'])) {
            cvAccessoLogout();
        }

        return [
            'authenticated' => $currentUser !== null,
            'config' => $config,
            'messages' => $messages,
            'errors' => $errors,
            'current_user' => $currentUser,
        ];
    }
}

if (!function_exists('cvAccessoRequireConnection')) {
    function cvAccessoRequireConnection(): mysqli
    {
        return cvDbConnection();
    }
}

if (!function_exists('cvAccessoIsAdmin')) {
    function cvAccessoIsAdmin(array $state): bool
    {
        return !empty($state['authenticated']) && isset($state['current_user']['role']) && $state['current_user']['role'] === 'admin';
    }
}

if (!function_exists('cvAccessoAllowedProviderCodes')) {
    /**
     * @return array<int,string>
     */
    function cvAccessoAllowedProviderCodes(array $state): array
    {
        if (cvAccessoIsAdmin($state)) {
            return [];
        }

        $codes = isset($state['current_user']['providers']) && is_array($state['current_user']['providers'])
            ? $state['current_user']['providers']
            : [];

        return cvCacheNormalizeProviderCodes($codes);
    }
}

if (!function_exists('cvAccessoRoleLabel')) {
    function cvAccessoRoleLabel(array $state): string
    {
        return cvAccessoIsAdmin($state) ? 'Amministratore' : 'Azienda';
    }
}

if (!function_exists('cvAccessoScopeLabel')) {
    function cvAccessoScopeLabel(array $state): string
    {
        if (cvAccessoIsAdmin($state)) {
            return 'Tutti i provider';
        }

        $codes = cvAccessoAllowedProviderCodes($state);
        if (count($codes) === 0) {
            return 'Nessun provider assegnato';
        }

        return implode(', ', $codes);
    }
}

if (!function_exists('cvAccessoScopeProviderCodes')) {
    /**
     * @param array<int,string> $providerCodes
     * @return array<int,string>
     */
    function cvAccessoScopeProviderCodes(array $state, array $providerCodes): array
    {
        $providerCodes = cvCacheNormalizeProviderCodes($providerCodes);
        if (cvAccessoIsAdmin($state)) {
            return $providerCodes;
        }

        $allowed = array_fill_keys(cvAccessoAllowedProviderCodes($state), true);
        $scoped = [];
        foreach ($providerCodes as $providerCode) {
            if (isset($allowed[$providerCode])) {
                $scoped[$providerCode] = $providerCode;
            }
        }

        return array_values($scoped);
    }
}

if (!function_exists('cvAccessoFilterProviders')) {
    /**
     * @param array<int,array<string,mixed>> $providers
     * @return array<int,array<string,mixed>>
     */
    function cvAccessoFilterProviders(array $state, array $providers): array
    {
        if (cvAccessoIsAdmin($state)) {
            return array_values($providers);
        }

        $allowed = array_fill_keys(cvAccessoAllowedProviderCodes($state), true);
        $filtered = [];
        foreach ($providers as $provider) {
            if (!is_array($provider)) {
                continue;
            }
            $providerCode = trim((string) ($provider['code'] ?? ''));
            if ($providerCode !== '' && isset($allowed[$providerCode])) {
                $filtered[] = $provider;
            }
        }

        return $filtered;
    }
}

if (!function_exists('cvAccessoNavItems')) {
    /**
     * @return array<int,array<string,mixed>>
     */
    function cvAccessoNavItems(array $state): array
    {
        $items = [
            ['slug' => 'index', 'label' => 'Dashboard', 'href' => cvAccessoUrl('index.php'), 'icon' => 'fa-home'],
            [
                'slug' => 'tickets-root',
                'label' => 'Biglietti',
                'href' => '#',
                'icon' => 'fa-ticket',
                'children' => [
                    [
                        'slug' => 'tickets',
                        'label' => 'Biglietti',
                        'href' => cvAccessoUrl('biglietti.php'),
                    ],
                    [
                        'slug' => 'tickets-history',
                        'label' => 'Storico',
                        'href' => cvAccessoUrl('biglietti-storico.php'),
                    ],
                ],
            ],
            ['slug' => 'providers', 'label' => 'Provider', 'href' => cvAccessoUrl('providers.php'), 'icon' => 'fa-building'],
            ['slug' => 'homepage-featured', 'label' => 'Vetrina home', 'href' => cvAccessoUrl('homepage.php'), 'icon' => 'fa-star'],
            ['slug' => 'sync', 'label' => 'Sync', 'href' => cvAccessoUrl('sync.php'), 'icon' => 'fa-refresh'],
            ['slug' => 'cache', 'label' => 'Cache', 'href' => cvAccessoUrl('cache.php'), 'icon' => 'fa-database'],
            ['slug' => 'statistics', 'label' => 'Statistiche', 'href' => cvAccessoUrl('statistiche.php'), 'icon' => 'fa-bar-chart'],
        ];

        if (cvAccessoIsAdmin($state)) {
            $items[] = [
                'slug' => 'assistant-root',
                'label' => 'Assistente',
                'href' => '#',
                'icon' => 'fa-comments-o',
                'children' => [
                    [
                        'slug' => 'assistant',
                        'label' => 'Config',
                        'href' => cvAccessoUrl('assistant.php'),
                    ],
                    [
                        'slug' => 'assistant-tickets',
                        'label' => 'Ticket/Messaggi',
                        'href' => cvAccessoUrl('assistant_tickets.php'),
                    ],
                    [
                        'slug' => 'assistant-conversations',
                        'label' => 'Conversazioni',
                        'href' => cvAccessoUrl('assistant_conversations.php'),
                    ],
                ],
            ];
            $items[] = [
                'slug' => 'newsletter',
                'label' => 'Newsletter',
                'href' => cvAccessoUrl('newsletter.php'),
                'icon' => 'fa-envelope-o',
            ];
            $items[] = [
                'slug' => 'error-log',
                'label' => 'Error log',
                'href' => cvAccessoUrl('error-log.php'),
                'icon' => 'fa-exclamation-triangle',
            ];
            $items[] = [
                'slug' => 'users',
                'label' => 'Utenti',
                'href' => cvAccessoUrl('users.php'),
                'icon' => 'fa-users',
            ];
            $items[] = [
                'slug' => 'settings-places',
                'label' => 'Macroaree',
                'href' => cvAccessoUrl('places.php'),
                'icon' => 'fa-map-marker',
            ];
            $items[] = [
                'slug' => 'settings-promotions',
                'label' => 'Promozioni',
                'href' => cvAccessoUrl('promozioni.php'),
                'icon' => 'fa-tags',
            ];
            $items[] = [
                'slug' => 'maintenance',
                'label' => 'Manutenzione',
                'href' => cvAccessoUrl('manutenzione.php'),
                'icon' => 'fa-wrench',
            ];
            $items[] = [
                'slug' => 'settings-route-pages',
                'label' => 'Pagine tratte',
                'href' => cvAccessoUrl('tratte-seo.php'),
                'icon' => 'fa-file-text-o',
            ];
            $items[] = [
                'slug' => 'settings-blog',
                'label' => 'Blog',
                'href' => cvAccessoUrl('blog.php'),
                'icon' => 'fa-pencil-square-o',
            ];
            $items[] = [
                'slug' => 'settings',
                'label' => 'Settings',
                'href' => '#',
                'icon' => 'fa-sliders',
                'children' => [
                    [
                        'slug' => 'settings-search',
                        'label' => 'Ricerca',
                        'href' => cvAccessoUrl('settings.php'),
                    ],
                    [
                        'slug' => 'settings-frontend',
                        'label' => 'Frontend & SEO',
                        'href' => cvAccessoUrl('frontend.php'),
                    ],
                    [
                        'slug' => 'settings-payments',
                        'label' => 'Pagamenti',
                        'href' => cvAccessoUrl('pagamenti.php'),
                    ],
                    [
                        'slug' => 'settings-mail',
                        'label' => 'Mail',
                        'href' => cvAccessoUrl('mail-settings.php'),
                    ],
                    [
                        'slug' => 'settings-cronjob',
                        'label' => 'Cronjob',
                        'href' => cvAccessoUrl('cronjob.php'),
                    ],
                ],
            ];
        }

        return $items;
    }
}

if (!function_exists('cvAccessoRenderMessages')) {
    function cvAccessoRenderMessages(array $state): void
    {
        foreach ($state['messages'] as $message) {
            echo '<div class="alert alert-success cv-alert" role="alert">' . cvAccessoH((string) $message) . '</div>';
        }
        foreach ($state['errors'] as $error) {
            echo '<div class="alert alert-danger cv-alert" role="alert">' . cvAccessoH((string) $error) . '</div>';
        }
    }
}

if (!function_exists('cvAccessoRenderLoginPage')) {
    function cvAccessoRenderLoginPage(array $state): void
    {
        $pageTitle = 'Login';
        $bodyClass = 'simple-page';
        $includeAppScripts = false;
        $GLOBALS['cv_accesso_render_state'] = $state;
        require __DIR__ . '/partials/head.php';
        require __DIR__ . '/partials/login.php';
        require __DIR__ . '/partials/footer.php';
    }
}

if (!function_exists('cvAccessoRenderPageStart')) {
    function cvAccessoRenderPageStart(string $pageTitle, string $activeSlug, array $state): void
    {
        $bodyClass = 'sb-left pace-done theme-primary';
        $includeAppScripts = true;
        $navItems = cvAccessoNavItems($state);
        $GLOBALS['cv_accesso_render_state'] = $state;

        require __DIR__ . '/partials/head.php';
        require __DIR__ . '/partials/sidebar.php';
        require __DIR__ . '/partials/topbar.php';
    }
}

if (!function_exists('cvAccessoRenderPageEnd')) {
    function cvAccessoRenderPageEnd(): void
    {
        require __DIR__ . '/partials/footer.php';
    }
}

if (!function_exists('cvAccessoCountCacheFiles')) {
    /**
     * @param array<int,string>|null $providerCodes
     * @return array<string,int>
     */
    function cvAccessoCountCacheFiles(?array $providerCodes = null): array
    {
        $counts = [];
        $providerCodes = $providerCodes !== null ? cvCacheNormalizeProviderCodes($providerCodes) : null;
        $providerFilter = $providerCodes !== null && count($providerCodes) > 0
            ? array_fill_keys($providerCodes, true)
            : null;

        foreach (cvCacheDirectoryMap() as $bucketKey => $bucket) {
            $files = is_dir($bucket['path']) ? glob($bucket['path'] . '/*.json') : [];
            if (!is_array($files)) {
                $counts[$bucketKey] = 0;
                continue;
            }

            if ($providerFilter === null) {
                $counts[$bucketKey] = count($files);
                continue;
            }

            $count = 0;
            foreach ($files as $filePath) {
                $payload = cvCacheReadJsonFile($filePath);
                if (!is_array($payload)) {
                    continue;
                }

                $payloadProviders = cvCachePayloadProviderCodes($bucketKey, $payload);
                foreach ($payloadProviders as $providerCode) {
                    if (isset($providerFilter[$providerCode])) {
                        $count++;
                        break;
                    }
                }
            }

            $counts[$bucketKey] = $count;
        }

        return $counts;
    }
}
