<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';

if (!function_exists('cvUsersHandleLogoUpload')) {
    function cvUsersIsValidImageHandle($handle): bool
    {
        return is_resource($handle) || is_object($handle);
    }

    function cvUsersCreateImageResourceFromUpload(string $tmpPath, string $mime)
    {
        if ($mime === 'image/jpeg' && function_exists('imagecreatefromjpeg')) {
            return @imagecreatefromjpeg($tmpPath);
        }
        if ($mime === 'image/png' && function_exists('imagecreatefrompng')) {
            return @imagecreatefrompng($tmpPath);
        }
        if ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
            return @imagecreatefromwebp($tmpPath);
        }
        if ($mime === 'image/gif' && function_exists('imagecreatefromgif')) {
            return @imagecreatefromgif($tmpPath);
        }
        return null;
    }

    function cvUsersHandleLogoUpload(array $file): ?string
    {
        $errorCode = isset($file['error']) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
        if ($errorCode === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ($errorCode !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload logo non riuscito (codice ' . $errorCode . ').');
        }

        $tmpPath = (string) ($file['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            throw new RuntimeException('File logo non valido.');
        }

        if (!function_exists('imagejpeg')) {
            throw new RuntimeException('Libreria immagini non disponibile sul server (GD).');
        }

        $imageInfo = @getimagesize($tmpPath);
        if (!is_array($imageInfo) || empty($imageInfo['mime'])) {
            throw new RuntimeException('File logo non riconosciuto come immagine valida.');
        }

        $mime = strtolower(trim((string) $imageInfo['mime']));
        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        if (!in_array($mime, $allowedMimes, true)) {
            throw new RuntimeException('Formato logo non supportato. Usa JPG, PNG, WEBP o GIF.');
        }

        $source = cvUsersCreateImageResourceFromUpload($tmpPath, $mime);
        if (!cvUsersIsValidImageHandle($source)) {
            throw new RuntimeException('Impossibile leggere il file immagine caricato.');
        }

        $targetDir = dirname(__DIR__) . '/files/cache/aziende';
        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            throw new RuntimeException('Impossibile creare la cartella logo.');
        }

        $safeFilename = 'logo-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.jpg';
        $targetPath = $targetDir . '/' . $safeFilename;

        $sourceWidth = max(1, imagesx($source));
        $sourceHeight = max(1, imagesy($source));
        $maxWidth = 1200;
        $maxHeight = 1200;
        $scale = min(1.0, min($maxWidth / $sourceWidth, $maxHeight / $sourceHeight));
        $newWidth = max(1, (int) round($sourceWidth * $scale));
        $newHeight = max(1, (int) round($sourceHeight * $scale));

        $canvas = imagecreatetruecolor($newWidth, $newHeight);
        if (!cvUsersIsValidImageHandle($canvas)) {
            imagedestroy($source);
            throw new RuntimeException('Impossibile creare canvas immagine logo.');
        }

        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $newWidth, $newHeight, $white);
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $newWidth, $newHeight, $sourceWidth, $sourceHeight);

        $saved = @imagejpeg($canvas, $targetPath, 82);
        imagedestroy($canvas);
        imagedestroy($source);

        if (!$saved || !is_file($targetPath)) {
            throw new RuntimeException('Salvataggio logo non riuscito.');
        }

        return 'cache/aziende/' . $safeFilename;
    }
}

$state = cvAccessoInit();
if (!$state['authenticated']) {
    cvAccessoRenderLoginPage($state);
    return;
}

if (!cvAccessoIsAdmin($state)) {
    http_response_code(403);
    cvAccessoRenderPageStart('Utenti', 'users', $state);
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

$providers = [];
$users = [];
$editUserId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editUser = null;
$savedPasswordPreview = isset($_SESSION['cv_accesso_saved_password_preview'])
    ? (string) $_SESSION['cv_accesso_saved_password_preview']
    : '';
unset($_SESSION['cv_accesso_saved_password_preview']);
$formData = [
    'id_user' => 0,
    'email' => '',
    'name' => '',
    'logo_path' => '',
    'password' => '',
    'role' => 'provider',
    'is_active' => 1,
    'providers' => [],
];

try {
    $connection = cvAccessoRequireConnection();
    cvAccessoEnsureBackendLogoColumn($connection);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_user') {
        if (!cvAccessoValidateCsrf()) {
            $state['errors'][] = 'Sessione non valida. Ricarica la pagina.';
        } else {
            $currentLogoPath = trim((string) ($_POST['current_logo_path'] ?? ''));
            if (!empty($_POST['remove_logo'])) {
                $currentLogoPath = '';
            }
            if (isset($_FILES['logo_upload']) && is_array($_FILES['logo_upload'])) {
                $uploadedLogoPath = cvUsersHandleLogoUpload($_FILES['logo_upload']);
                if (is_string($uploadedLogoPath) && $uploadedLogoPath !== '') {
                    $currentLogoPath = $uploadedLogoPath;
                }
            }

            $payload = [
                'id_user' => (int) ($_POST['id_user'] ?? 0),
                'email' => (string) ($_POST['email'] ?? ''),
                'name' => (string) ($_POST['name'] ?? ''),
                'logo_path' => $currentLogoPath,
                'role' => (string) ($_POST['role'] ?? 'provider'),
                'is_active' => !empty($_POST['is_active']) ? 1 : 0,
                'providers' => isset($_POST['providers']) && is_array($_POST['providers']) ? $_POST['providers'] : [],
                'password' => (string) ($_POST['password'] ?? ''),
            ];

            $savedUserId = cvAccessoSaveBackendUser($connection, $payload);
            $message = ((int) $payload['id_user'] > 0) ? 'Account aggiornato.' : 'Account creato.';
            if (trim((string) $payload['password']) !== '') {
                $savedPasswordPreview = (string) $payload['password'];
                $_SESSION['cv_accesso_saved_password_preview'] = $savedPasswordPreview;
                $message .= ' Password salvata correttamente.';
            }
            $state['messages'][] = $message;

            $editUserId = $savedUserId;
            $formData = [
                'id_user' => $savedUserId,
                'email' => (string) $payload['email'],
                'name' => (string) $payload['name'],
                'logo_path' => (string) $payload['logo_path'],
                'password' => '',
                'role' => (string) $payload['role'],
                'is_active' => (int) $payload['is_active'],
                'providers' => isset($payload['providers']) && is_array($payload['providers']) ? $payload['providers'] : [],
            ];
        }
    }

    $providers = cvCacheFetchProviders($connection);
    $users = cvAccessoFetchBackendUsers($connection);

    if ($editUserId > 0) {
        foreach ($users as $user) {
            if ((int) ($user['id_user'] ?? 0) === $editUserId) {
                $editUser = $user;
                $formData = [
                    'id_user' => (int) ($user['id_user'] ?? 0),
                    'email' => (string) ($user['email'] ?? ''),
                    'name' => (string) ($user['name'] ?? ''),
                    'logo_path' => (string) ($user['logo_path'] ?? ''),
                    'password' => (string) ($user['password_plain'] ?? ''),
                    'role' => (string) ($user['role'] ?? 'provider'),
                    'is_active' => (int) ($user['is_active'] ?? 0),
                    'providers' => isset($user['providers']) && is_array($user['providers']) ? $user['providers'] : [],
                ];
                break;
            }
        }
    }
} catch (Throwable $exception) {
    $state['errors'][] = 'Errore sezione utenti: ' . $exception->getMessage();
}

$activeProviderRows = array_values(array_filter(
    $providers,
    static fn (array $provider): bool => (int) ($provider['is_active'] ?? 0) === 1
));

cvAccessoRenderPageStart('Utenti', 'users', $state);
?>
<div class="row">
    <div class="col-md-12">
        <p class="cv-page-intro">
            Gestione utenti backend. Da qui l’amministratore crea account azienda, aggiorna credenziali e assegna i provider.
        </p>
    </div>
</div>

<div class="row">
    <div class="col-md-5">
        <div class="cv-panel-card">
            <h4><?= $formData['id_user'] > 0 ? 'Modifica account' : 'Nuovo account' ?></h4>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="save_user">
                <input type="hidden" name="id_user" value="<?= (int) $formData['id_user'] ?>">
                <input type="hidden" name="current_logo_path" value="<?= cvAccessoH((string) ($formData['logo_path'] ?? '')) ?>">
                <?= cvAccessoCsrfField() ?>

                <div class="form-group">
                    <label for="name">Nome</label>
                    <input id="name" name="name" class="form-control" value="<?= cvAccessoH((string) $formData['name']) ?>" required>
                </div>

                <div class="form-group">
                    <label for="email">Email</label>
                    <input id="email" type="email" name="email" class="form-control" value="<?= cvAccessoH((string) $formData['email']) ?>" required>
                </div>

                <div class="form-group">
                    <label for="logo_upload">Logo accesso (opzionale)</label>
                    <input id="logo_upload" type="file" name="logo_upload" class="form-control" accept=".jpg,.jpeg,.png,.webp,.gif,image/jpeg,image/png,image/webp,image/gif">
                    <div class="cv-muted" style="margin-top:6px;">L'immagine viene ridimensionata automaticamente e salvata in formato JPG.</div>
                    <?php $currentLogoPath = trim((string) ($formData['logo_path'] ?? '')); ?>
                    <?php if ($currentLogoPath !== ''): ?>
                        <div style="margin-top:8px;">
                            <img src="<?= cvAccessoH(cvAccessoAccountLogoUrl($currentLogoPath)) ?>" alt="Logo account" style="max-height:44px; width:auto; border:1px solid #dce6f0; border-radius:8px; background:#fff; padding:4px;">
                        </div>
                        <div class="checkbox" style="margin-top:8px;">
                            <input id="remove_logo" type="checkbox" name="remove_logo" value="1">
                            <label for="remove_logo">Rimuovi logo attuale</label>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label for="password"><?= $formData['id_user'] > 0 ? 'Nuova password' : 'Password' ?></label>
                    <input
                        id="password"
                        type="text"
                        name="password"
                        class="form-control"
                        value="<?= cvAccessoH((string) ($formData['password'] ?? '')) ?>"
                        autocomplete="off"
                        autocapitalize="off"
                        spellcheck="false"
                        <?= $formData['id_user'] > 0 ? '' : 'required' ?>
                    >
                    <?php if ($formData['id_user'] > 0): ?>
                        <div class="cv-muted">Lascia vuoto per non cambiarla. La password salvata resta consultabile solo subito dopo questo salvataggio.</div>
                    <?php endif; ?>
                </div>

                <?php if ($savedPasswordPreview !== ''): ?>
                    <div class="form-group">
                        <label for="saved_password_preview">Password appena salvata</label>
                        <input
                            id="saved_password_preview"
                            type="text"
                            class="form-control"
                            value="<?= cvAccessoH($savedPasswordPreview) ?>"
                            autocomplete="off"
                            readonly
                        >
                        <div class="cv-muted">Questa anteprima e visibile solo subito dopo il salvataggio corrente.</div>
                    </div>
                <?php endif; ?>

                <div class="form-group">
                    <label for="role">Ruolo</label>
                    <select id="role" name="role" class="form-control">
                        <option value="provider"<?= $formData['role'] === 'provider' ? ' selected' : '' ?>>Provider</option>
                        <option value="admin"<?= $formData['role'] === 'admin' ? ' selected' : '' ?>>Admin</option>
                    </select>
                </div>

                <div class="checkbox checkbox-primary">
                    <input
                        id="is_active"
                        type="checkbox"
                        name="is_active"
                        value="1"
                        <?= (int) $formData['is_active'] === 1 ? ' checked' : '' ?>
                    >
                    <label for="is_active">Account attivo</label>
                </div>

                <div class="form-group">
                    <label>Provider assegnati</label>
                    <div class="cv-checklist">
                        <?php foreach ($activeProviderRows as $index => $provider): ?>
                            <?php $providerCode = (string) ($provider['code'] ?? ''); ?>
                            <?php $checkboxId = 'provider_' . (string) $index; ?>
                            <div class="checkbox">
                                <input
                                    id="<?= cvAccessoH($checkboxId) ?>"
                                    type="checkbox"
                                    name="providers[]"
                                    value="<?= cvAccessoH($providerCode) ?>"
                                    <?= in_array($providerCode, (array) $formData['providers'], true) ? 'checked' : '' ?>
                                >
                                <label for="<?= cvAccessoH($checkboxId) ?>">
                                    <?= cvAccessoH($providerCode) ?> - <?= cvAccessoH((string) ($provider['name'] ?? '')) ?>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="cv-muted">Per il ruolo admin le assegnazioni provider vengono ignorate.</div>
                </div>

                <div class="cv-inline-actions">
                    <button type="submit" class="btn btn-primary"><?= $formData['id_user'] > 0 ? 'Salva modifiche' : 'Crea account' ?></button>
                    <?php if ($formData['id_user'] > 0): ?>
                        <a class="btn btn-default" href="<?= cvAccessoH(cvAccessoUrl('users.php')) ?>">Nuovo account</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <div class="col-md-7">
        <div class="cv-panel-card">
            <h4>Account backend</h4>
            <?php if (count($users) === 0): ?>
                <div class="cv-empty">Nessun account trovato nel database backend.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table cv-table">
                        <thead>
                        <tr>
                            <th>Logo</th>
                            <th>Nome</th>
                            <th>Email</th>
                            <th>Ruolo</th>
                            <th>Provider</th>
                            <th>Stato</th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <td>
                                    <?php $logoPath = trim((string) ($user['logo_path'] ?? '')); ?>
                                    <?php if ($logoPath !== ''): ?>
                                        <img src="<?= cvAccessoH(cvAccessoAccountLogoUrl($logoPath)) ?>" alt="Logo" style="max-height:26px; width:auto; border:1px solid #dce6f0; border-radius:6px; background:#fff; padding:2px;">
                                    <?php else: ?>
                                        <span class="cv-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= cvAccessoH((string) ($user['name'] ?? '')) ?></td>
                                <td><?= cvAccessoH((string) ($user['email'] ?? '')) ?></td>
                                <td><?= cvAccessoH((string) ($user['role'] ?? '')) ?></td>
                                <td>
                                    <div class="cv-pill-list">
                                        <?php
                                        $userProviders = isset($user['providers']) && is_array($user['providers']) ? $user['providers'] : [];
                                        if (count($userProviders) === 0) {
                                            echo '<span class="cv-pill">-</span>';
                                        } else {
                                            foreach ($userProviders as $providerCode) {
                                                echo '<span class="cv-pill">' . cvAccessoH((string) $providerCode) . '</span>';
                                            }
                                        }
                                        ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if ((int) ($user['is_active'] ?? 0) === 1): ?>
                                        <span class="cv-badge-active">Attivo</span>
                                    <?php else: ?>
                                        <span class="cv-badge-inactive">Disattivo</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a class="btn btn-default btn-xs" href="<?= cvAccessoH(cvAccessoUrl('users.php?edit=' . (int) ($user['id_user'] ?? 0))) ?>">Modifica</a>
                                </td>
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
