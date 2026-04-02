<?php
declare(strict_types=1);

$prefillEmail = strtolower(trim((string) ($_POST['email'] ?? '')));
$accountLogoMap = [];
$accounts = isset($state['config']['accounts']) && is_array($state['config']['accounts']) ? $state['config']['accounts'] : [];
foreach ($accounts as $account) {
    if (!is_array($account)) {
        continue;
    }
    $email = strtolower(trim((string) ($account['email'] ?? '')));
    $logoPath = trim((string) ($account['logo_path'] ?? ''));
    if ($email === '' || $logoPath === '') {
        continue;
    }
    $accountLogoMap[$email] = cvAccessoAccountLogoUrl($logoPath);
}
?>
<div class="simple-page-wrap">
    <div class="simple-page-logo animated swing">
        <a href="<?= cvAccessoH(cvAccessoUrl('index.php')) ?>">
            <span><i class="fa fa-bus"></i></span>
            <span><?= cvAccessoH((string) ($state['config']['brand_name'] ?? 'Cercaviaggio')) ?></span>
        </a>
    </div>

    <?php cvAccessoRenderMessages($state); ?>

    <div class="simple-page-form animated flipInY" id="login-form">
        <h4 class="form-title m-b-xl text-center">Login backend</h4>
        <div id="login-account-logo-wrap" class="cv-login-account-logo" style="display:none;">
            <img id="login-account-logo" src="" alt="Logo account">
        </div>
        <form method="post" action="<?= cvAccessoH(cvAccessoUrl('index.php')) ?>">
            <input type="hidden" name="action" value="login">

            <div class="form-group">
                <input id="login-email" type="email" name="email" class="form-control" placeholder="Email" autocomplete="username" required value="<?= cvAccessoH($prefillEmail) ?>">
            </div>

            <div class="form-group">
                <div class="input-group cv-password-group">
                    <input
                        id="login-password"
                        type="password"
                        name="password"
                        class="form-control"
                        placeholder="Password"
                        autocomplete="current-password"
                        required
                    >
                    <span class="input-group-btn">
                        <button
                            type="button"
                            class="btn btn-default cv-password-toggle"
                            data-password-toggle="login-password"
                            aria-label="Mostra password"
                            aria-pressed="false"
                        >
                            <i class="fa fa-eye"></i>
                        </button>
                    </span>
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-block">Login</button>
        </form>

        <div class="cv-login-help">
            <strong>Accessi previsti</strong>
            <div>Admin globale e account azienda con scope sui provider assegnati.</div>
        </div>
    </div>

    <div class="simple-page-footer">
        <p><small><?= cvAccessoH((string) ($state['config']['brand_subtitle'] ?? 'Backend multiazienda')) ?></small></p>
    </div>
</div>

<?php if (count($accountLogoMap) > 0): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var emailInput = document.getElementById('login-email');
    var wrap = document.getElementById('login-account-logo-wrap');
    var img = document.getElementById('login-account-logo');
    if (!emailInput || !wrap || !img) {
        return;
    }

    var logos = <?= json_encode($accountLogoMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var refreshLogo = function () {
        var email = String(emailInput.value || '').toLowerCase().trim();
        if (email !== '' && logos[email]) {
            img.src = logos[email];
            wrap.style.display = '';
        } else {
            img.src = '';
            wrap.style.display = 'none';
        }
    };

    emailInput.addEventListener('input', refreshLogo);
    emailInput.addEventListener('change', refreshLogo);
    refreshLogo();
});
</script>
<?php endif; ?>
