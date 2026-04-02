<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';

$state = cvAccessoInit();
if (!$state['authenticated']) {
    cvAccessoRenderLoginPage($state);
    return;
}

$tableExists = false;
$providers = [];
$paymentConfig = [
    'paypal' => ['env' => 'live', 'enabled' => false, 'card_enabled' => false, 'email' => '', 'auth_token' => '', 'client_id' => '', 'client_secret' => '', 'merchant_id' => '', 'api_username' => '', 'api_password' => '', 'api_signature' => ''],
    'stripe' => ['enabled' => false, 'account_id' => '', 'publishable_key' => '', 'secret_key' => '', 'webhook_secret' => ''],
];
$settings = cvRuntimePaymentSettingsDefaults();

try {
    $connection = cvAccessoRequireConnection();
    $tableExists = cvRuntimePaymentSettingsTableExists($connection);
    $settings = cvRuntimePaymentSettings($connection);
    $paymentConfig = cvRuntimeMarketplacePaymentConfig($connection);

    $providers = cvCacheFetchProviders($connection);
    $providers = cvAccessoFilterProviders($state, $providers);
    usort($providers, static function (array $left, array $right): int {
        return strcasecmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
    });

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'save_payments') {
        if (!cvAccessoValidateCsrf()) {
            $state['errors'][] = 'Sessione non valida. Ricarica la pagina.';
        } elseif (!$tableExists) {
            $state['errors'][] = 'La tabella cv_payment_settings non esiste ancora nel database.';
        } else {
            $providerCodes = [];
            foreach ($providers as $provider) {
                $providerCode = strtolower(trim((string) ($provider['code'] ?? '')));
                if ($providerCode !== '') {
                    $providerCodes[$providerCode] = true;
                }
            }

            $providerMaps = [
                'checkout_provider_paypal_environments' => [],
                'checkout_provider_paypal_merchant_ids' => [],
                'checkout_provider_paypal_emails' => [],
                'checkout_provider_paypal_auth_tokens' => [],
                'checkout_provider_paypal_checkout_enabled' => [],
                'checkout_provider_paypal_api_usernames' => [],
                'checkout_provider_paypal_api_passwords' => [],
                'checkout_provider_paypal_api_signatures' => [],
                'checkout_provider_paypal_client_ids' => [],
                'checkout_provider_paypal_secrets' => [],
                'checkout_provider_stripe_account_ids' => [],
                'checkout_provider_stripe_publishable_keys' => [],
                'checkout_provider_stripe_secret_keys' => [],
                'checkout_provider_stripe_webhook_secrets' => [],
            ];

            foreach ($providerCodes as $providerCode => $_) {
                $paypalEnv = strtolower(trim((string) ($_POST['provider_paypal_environments'][$providerCode] ?? 'live')));
                if (!in_array($paypalEnv, ['sandbox', 'live'], true)) {
                    $paypalEnv = 'live';
                }
                if (!cvAccessoIsAdmin($state)) {
                    $paypalEnv = 'live';
                }
                $providerMaps['checkout_provider_paypal_environments'][$providerCode] = $paypalEnv;
                $providerMaps['checkout_provider_paypal_merchant_ids'][$providerCode] = trim((string) ($_POST['provider_paypal_merchant_ids'][$providerCode] ?? ''));
                $providerMaps['checkout_provider_paypal_emails'][$providerCode] = trim((string) ($_POST['provider_paypal_emails'][$providerCode] ?? ''));
                $providerMaps['checkout_provider_paypal_auth_tokens'][$providerCode] = trim((string) ($_POST['provider_paypal_auth_tokens'][$providerCode] ?? ''));
                $providerMaps['checkout_provider_paypal_checkout_enabled'][$providerCode] = ((int) ($_POST['provider_paypal_checkout_enabled'][$providerCode] ?? 0)) > 0 ? 1 : 0;
                $providerMaps['checkout_provider_paypal_api_usernames'][$providerCode] = trim((string) ($_POST['provider_paypal_api_usernames'][$providerCode] ?? ''));
                $providerMaps['checkout_provider_paypal_api_passwords'][$providerCode] = trim((string) ($_POST['provider_paypal_api_passwords'][$providerCode] ?? ''));
                $providerMaps['checkout_provider_paypal_api_signatures'][$providerCode] = trim((string) ($_POST['provider_paypal_api_signatures'][$providerCode] ?? ''));
                $providerMaps['checkout_provider_paypal_client_ids'][$providerCode] = trim((string) ($_POST['provider_paypal_client_ids'][$providerCode] ?? ''));
                $providerMaps['checkout_provider_paypal_secrets'][$providerCode] = trim((string) ($_POST['provider_paypal_secrets'][$providerCode] ?? ''));
                $providerMaps['checkout_provider_stripe_account_ids'][$providerCode] = trim((string) ($_POST['provider_stripe_account_ids'][$providerCode] ?? ''));
                $providerMaps['checkout_provider_stripe_publishable_keys'][$providerCode] = trim((string) ($_POST['provider_stripe_publishable_keys'][$providerCode] ?? ''));
                $providerMaps['checkout_provider_stripe_secret_keys'][$providerCode] = trim((string) ($_POST['provider_stripe_secret_keys'][$providerCode] ?? ''));
                $providerMaps['checkout_provider_stripe_webhook_secrets'][$providerCode] = trim((string) ($_POST['provider_stripe_webhook_secrets'][$providerCode] ?? ''));
            }

            $nextSettings = $settings;
            foreach ($providerMaps as $key => $value) {
                $nextSettings[$key] = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            if (cvAccessoIsAdmin($state)) {
                $marketplacePaypalEnv = strtolower(trim((string) ($_POST['checkout_marketplace_paypal_env'] ?? 'live')));
                if (!in_array($marketplacePaypalEnv, ['sandbox', 'live'], true)) {
                    $marketplacePaypalEnv = 'live';
                }
                $nextSettings['checkout_marketplace_paypal_env'] = $marketplacePaypalEnv;
                $nextSettings['checkout_marketplace_paypal_email'] = trim((string) ($_POST['checkout_marketplace_paypal_email'] ?? ''));
                $nextSettings['checkout_marketplace_paypal_auth_token'] = trim((string) ($_POST['checkout_marketplace_paypal_auth_token'] ?? ''));
                $nextSettings['checkout_marketplace_paypal_checkout_enabled'] = ((int) ($_POST['checkout_marketplace_paypal_checkout_enabled'] ?? 0)) > 0 ? 1 : 0;
                $nextSettings['checkout_marketplace_paypal_card_enabled'] = ((int) ($_POST['checkout_marketplace_paypal_card_enabled'] ?? 0)) > 0 ? 1 : 0;
                $nextSettings['checkout_marketplace_paypal_client_id'] = trim((string) ($_POST['checkout_marketplace_paypal_client_id'] ?? ''));
                $nextSettings['checkout_marketplace_paypal_client_secret'] = trim((string) ($_POST['checkout_marketplace_paypal_client_secret'] ?? ''));
                $nextSettings['checkout_marketplace_paypal_merchant_id'] = trim((string) ($_POST['checkout_marketplace_paypal_merchant_id'] ?? ''));
                $nextSettings['checkout_marketplace_paypal_api_username'] = trim((string) ($_POST['checkout_marketplace_paypal_api_username'] ?? ''));
                $nextSettings['checkout_marketplace_paypal_api_password'] = trim((string) ($_POST['checkout_marketplace_paypal_api_password'] ?? ''));
                $nextSettings['checkout_marketplace_paypal_api_signature'] = trim((string) ($_POST['checkout_marketplace_paypal_api_signature'] ?? ''));
                $nextSettings['checkout_marketplace_stripe_enabled'] = ((int) ($_POST['checkout_marketplace_stripe_enabled'] ?? 0)) > 0 ? 1 : 0;
                $nextSettings['checkout_marketplace_stripe_account_id'] = trim((string) ($_POST['checkout_marketplace_stripe_account_id'] ?? ''));
                $nextSettings['checkout_marketplace_stripe_publishable_key'] = trim((string) ($_POST['checkout_marketplace_stripe_publishable_key'] ?? ''));
                $nextSettings['checkout_marketplace_stripe_secret_key'] = trim((string) ($_POST['checkout_marketplace_stripe_secret_key'] ?? ''));
                $nextSettings['checkout_marketplace_stripe_webhook_secret'] = trim((string) ($_POST['checkout_marketplace_stripe_webhook_secret'] ?? ''));
            }

            $settings = cvRuntimeSavePaymentSettings($connection, $nextSettings);
            $paymentConfig = cvRuntimeMarketplacePaymentConfig($connection);
            $state['messages'][] = 'Configurazione pagamenti salvata.';
        }
    }
} catch (Throwable $exception) {
    $state['errors'][] = 'Errore sezione pagamenti: ' . $exception->getMessage();
}

cvAccessoRenderPageStart('Pagamenti', 'settings-payments', $state);
?>
<div class="row">
    <div class="col-md-12">
        <p class="cv-page-intro">
            Configura i metodi in tab separati. Salvataggio unico: PayPal e Stripe vengono memorizzati insieme e puoi sempre modificare i valori.
        </p>
    </div>
</div>

<?php if (!$tableExists): ?>
    <div class="row">
        <div class="col-md-12">
            <div class="cv-panel-card">
                <div class="alert alert-warning cv-alert" role="alert">
                    La tabella <code>cv_payment_settings</code> non esiste ancora nel database. Importa prima lo schema.
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="row">
    <div class="col-md-12">
        <div class="cv-panel-card">
            <h4>Pagamenti checkout</h4>
            <form method="post" class="cv-form-grid">
                <input type="hidden" name="action" value="save_payments">
                <?= cvAccessoCsrfField() ?>

                <ul class="nav nav-tabs" role="tablist" style="margin-bottom:14px;">
                    <li role="presentation" class="active">
                        <a href="#cv-paypal-tab" aria-controls="cv-paypal-tab" role="tab" data-toggle="tab">PayPal</a>
                    </li>
                    <li role="presentation">
                        <a href="#cv-stripe-tab" aria-controls="cv-stripe-tab" role="tab" data-toggle="tab">Stripe</a>
                    </li>
                </ul>

                <div class="tab-content">
                    <div role="tabpanel" class="tab-pane active" id="cv-paypal-tab">
                        <?php if (cvAccessoIsAdmin($state)): ?>
                            <h5 style="margin-top:0;">Cercaviaggio marketplace - PayPal</h5>
                            <div class="row">
                                <div class="col-md-2 form-group">
                                    <label for="checkout_marketplace_paypal_env">Ambiente</label>
                                    <select id="checkout_marketplace_paypal_env" name="checkout_marketplace_paypal_env" class="form-control">
                                        <option value="live"<?= (($paymentConfig['paypal']['env'] ?? 'live') === 'live') ? ' selected' : '' ?>>Live</option>
                                        <option value="sandbox"<?= (($paymentConfig['paypal']['env'] ?? '') === 'sandbox') ? ' selected' : '' ?>>Sandbox</option>
                                    </select>
                                </div>
                                <div class="col-md-2 form-group">
                                    <label for="checkout_marketplace_paypal_checkout_enabled">Checkout</label>
                                    <select id="checkout_marketplace_paypal_checkout_enabled" name="checkout_marketplace_paypal_checkout_enabled" class="form-control">
                                        <option value="1"<?= !empty($paymentConfig['paypal']['enabled']) ? ' selected' : '' ?>>Attivo</option>
                                        <option value="0"<?= empty($paymentConfig['paypal']['enabled']) ? ' selected' : '' ?>>Disattivo</option>
                                    </select>
                                </div>
                                <div class="col-md-2 form-group">
                                    <label for="checkout_marketplace_paypal_card_enabled">Carta via PayPal</label>
                                    <select id="checkout_marketplace_paypal_card_enabled" name="checkout_marketplace_paypal_card_enabled" class="form-control">
                                        <option value="1"<?= !empty($paymentConfig['paypal']['card_enabled']) ? ' selected' : '' ?>>Attiva</option>
                                        <option value="0"<?= empty($paymentConfig['paypal']['card_enabled']) ? ' selected' : '' ?>>Disattiva</option>
                                    </select>
                                </div>
                                <div class="col-md-4 form-group">
                                    <label for="checkout_marketplace_paypal_merchant_id">Merchant ID</label>
                                    <input id="checkout_marketplace_paypal_merchant_id" name="checkout_marketplace_paypal_merchant_id" type="text" class="form-control" value="<?= cvAccessoH((string) ($paymentConfig['paypal']['merchant_id'] ?? '')) ?>">
                                </div>
                                <div class="col-md-2 form-group">
                                    <label for="checkout_marketplace_paypal_email">Email</label>
                                    <input id="checkout_marketplace_paypal_email" name="checkout_marketplace_paypal_email" type="email" class="form-control" value="<?= cvAccessoH((string) ($paymentConfig['paypal']['email'] ?? '')) ?>">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-3 form-group">
                                    <label for="checkout_marketplace_paypal_auth_token">Auth token</label>
                                    <input id="checkout_marketplace_paypal_auth_token" name="checkout_marketplace_paypal_auth_token" type="text" class="form-control" value="<?= cvAccessoH((string) ($paymentConfig['paypal']['auth_token'] ?? '')) ?>">
                                </div>
                                <div class="col-md-3 form-group">
                                    <label for="checkout_marketplace_paypal_client_id">Client ID</label>
                                    <input id="checkout_marketplace_paypal_client_id" name="checkout_marketplace_paypal_client_id" type="text" class="form-control" value="<?= cvAccessoH((string) ($paymentConfig['paypal']['client_id'] ?? '')) ?>">
                                </div>
                                <div class="col-md-3 form-group">
                                    <label for="checkout_marketplace_paypal_client_secret">Secret</label>
                                    <input id="checkout_marketplace_paypal_client_secret" name="checkout_marketplace_paypal_client_secret" type="text" class="form-control" value="<?= cvAccessoH((string) ($paymentConfig['paypal']['client_secret'] ?? '')) ?>">
                                </div>
                                <div class="col-md-3 form-group">
                                    <label for="checkout_marketplace_paypal_api_username">API Username</label>
                                    <input id="checkout_marketplace_paypal_api_username" name="checkout_marketplace_paypal_api_username" type="text" class="form-control" value="<?= cvAccessoH((string) ($paymentConfig['paypal']['api_username'] ?? '')) ?>">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 form-group">
                                    <label for="checkout_marketplace_paypal_api_password">API Password</label>
                                    <input id="checkout_marketplace_paypal_api_password" name="checkout_marketplace_paypal_api_password" type="text" class="form-control" value="<?= cvAccessoH((string) ($paymentConfig['paypal']['api_password'] ?? '')) ?>">
                                </div>
                                <div class="col-md-6 form-group" style="margin-bottom:0;">
                                    <label for="checkout_marketplace_paypal_api_signature">API Signature</label>
                                    <input id="checkout_marketplace_paypal_api_signature" name="checkout_marketplace_paypal_api_signature" type="text" class="form-control" value="<?= cvAccessoH((string) ($paymentConfig['paypal']['api_signature'] ?? '')) ?>">
                                </div>
                            </div>
                            <hr>
                        <?php endif; ?>

                        <h5>Provider - PayPal</h5>
                        <?php if (count($providers) === 0): ?>
                            <div class="cv-empty">Nessun provider disponibile per il tuo account.</div>
                        <?php else: ?>
                            <?php foreach ($providers as $index => $provider): ?>
                                <?php
                                $providerCode = strtolower(trim((string) ($provider['code'] ?? '')));
                                $providerName = trim((string) ($provider['name'] ?? $providerCode));
                                if ($providerCode === '') {
                                    continue;
                                }
                                $v = static function (string $key) use ($paymentConfig, $providerCode): string {
                                    return (string) (($paymentConfig[$key][$providerCode] ?? ''));
                                };
                                $vFlag = static function (string $key) use ($paymentConfig, $providerCode): int {
                                    return ((int) (($paymentConfig[$key][$providerCode] ?? 0))) > 0 ? 1 : 0;
                                };
                                ?>
                                <div class="cv-panel-card" style="padding:12px; margin:10px 0;">
                                    <h6 style="margin-top:0;"><?= cvAccessoH($providerName) ?> <span class="cv-muted">(<?= cvAccessoH($providerCode) ?>)</span></h6>
                                    <div class="row">
                                        <div class="col-md-2 form-group">
                                            <label for="provider_paypal_environments_<?= (int) $index ?>">Ambiente</label>
                                            <select id="provider_paypal_environments_<?= (int) $index ?>" class="form-control" name="provider_paypal_environments[<?= cvAccessoH($providerCode) ?>]"<?= cvAccessoIsAdmin($state) ? '' : ' disabled' ?>>
                                                <option value="live"<?= $v('provider_paypal_environments') === 'sandbox' ? '' : ' selected' ?>>Live</option>
                                                <option value="sandbox"<?= $v('provider_paypal_environments') === 'sandbox' ? ' selected' : '' ?>>Sandbox</option>
                                            </select>
                                            <?php if (!cvAccessoIsAdmin($state)): ?>
                                                <input type="hidden" name="provider_paypal_environments[<?= cvAccessoH($providerCode) ?>]" value="live">
                                            <?php endif; ?>
                                        </div>
                                        <div class="col-md-2 form-group">
                                            <label for="provider_paypal_checkout_enabled_<?= (int) $index ?>">Checkout</label>
                                            <select id="provider_paypal_checkout_enabled_<?= (int) $index ?>" class="form-control" name="provider_paypal_checkout_enabled[<?= cvAccessoH($providerCode) ?>]">
                                                <option value="1"<?= $vFlag('provider_paypal_checkout_enabled') === 1 ? ' selected' : '' ?>>Attivo</option>
                                                <option value="0"<?= $vFlag('provider_paypal_checkout_enabled') === 0 ? ' selected' : '' ?>>Disattivo</option>
                                            </select>
                                        </div>
                                        <div class="col-md-4 form-group">
                                            <label for="provider_paypal_merchant_ids_<?= (int) $index ?>">Merchant ID</label>
                                            <input id="provider_paypal_merchant_ids_<?= (int) $index ?>" type="text" class="form-control" name="provider_paypal_merchant_ids[<?= cvAccessoH($providerCode) ?>]" value="<?= cvAccessoH($v('provider_paypal_merchant_ids')) ?>">
                                        </div>
                                        <div class="col-md-4 form-group">
                                            <label for="provider_paypal_emails_<?= (int) $index ?>">Email</label>
                                            <input id="provider_paypal_emails_<?= (int) $index ?>" type="email" class="form-control" name="provider_paypal_emails[<?= cvAccessoH($providerCode) ?>]" value="<?= cvAccessoH($v('provider_paypal_emails')) ?>">
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-3 form-group">
                                            <label for="provider_paypal_auth_tokens_<?= (int) $index ?>">Auth token</label>
                                            <input id="provider_paypal_auth_tokens_<?= (int) $index ?>" type="text" class="form-control" name="provider_paypal_auth_tokens[<?= cvAccessoH($providerCode) ?>]" value="<?= cvAccessoH($v('provider_paypal_auth_tokens')) ?>">
                                        </div>
                                        <div class="col-md-3 form-group">
                                            <label for="provider_paypal_client_ids_<?= (int) $index ?>">Client ID</label>
                                            <input id="provider_paypal_client_ids_<?= (int) $index ?>" type="text" class="form-control" name="provider_paypal_client_ids[<?= cvAccessoH($providerCode) ?>]" value="<?= cvAccessoH($v('provider_paypal_client_ids')) ?>">
                                        </div>
                                        <div class="col-md-3 form-group">
                                            <label for="provider_paypal_secrets_<?= (int) $index ?>">Secret</label>
                                            <input id="provider_paypal_secrets_<?= (int) $index ?>" type="text" class="form-control" name="provider_paypal_secrets[<?= cvAccessoH($providerCode) ?>]" value="<?= cvAccessoH($v('provider_paypal_secrets')) ?>">
                                        </div>
                                        <div class="col-md-3 form-group">
                                            <label for="provider_paypal_api_usernames_<?= (int) $index ?>">API Username</label>
                                            <input id="provider_paypal_api_usernames_<?= (int) $index ?>" type="text" class="form-control" name="provider_paypal_api_usernames[<?= cvAccessoH($providerCode) ?>]" value="<?= cvAccessoH($v('provider_paypal_api_usernames')) ?>">
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-6 form-group">
                                            <label for="provider_paypal_api_passwords_<?= (int) $index ?>">API Password</label>
                                            <input id="provider_paypal_api_passwords_<?= (int) $index ?>" type="text" class="form-control" name="provider_paypal_api_passwords[<?= cvAccessoH($providerCode) ?>]" value="<?= cvAccessoH($v('provider_paypal_api_passwords')) ?>">
                                        </div>
                                        <div class="col-md-6 form-group" style="margin-bottom:0;">
                                            <label for="provider_paypal_api_signatures_<?= (int) $index ?>">API Signature</label>
                                            <input id="provider_paypal_api_signatures_<?= (int) $index ?>" type="text" class="form-control" name="provider_paypal_api_signatures[<?= cvAccessoH($providerCode) ?>]" value="<?= cvAccessoH($v('provider_paypal_api_signatures')) ?>">
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <div role="tabpanel" class="tab-pane" id="cv-stripe-tab">
                        <?php if (cvAccessoIsAdmin($state)): ?>
                            <h5 style="margin-top:0;">Cercaviaggio marketplace - Stripe</h5>
                            <div class="row">
                                <div class="col-md-3 form-group">
                                    <label for="checkout_marketplace_stripe_enabled">Stripe</label>
                                    <select id="checkout_marketplace_stripe_enabled" name="checkout_marketplace_stripe_enabled" class="form-control">
                                        <option value="1"<?= !empty($paymentConfig['stripe']['enabled']) ? ' selected' : '' ?>>Attivo</option>
                                        <option value="0"<?= empty($paymentConfig['stripe']['enabled']) ? ' selected' : '' ?>>Disattivo</option>
                                    </select>
                                </div>
                                <div class="col-md-3 form-group">
                                    <label for="checkout_marketplace_stripe_account_id">Account ID (Connect)</label>
                                    <input id="checkout_marketplace_stripe_account_id" name="checkout_marketplace_stripe_account_id" type="text" class="form-control" value="<?= cvAccessoH((string) ($paymentConfig['stripe']['account_id'] ?? '')) ?>">
                                </div>
                                <div class="col-md-3 form-group">
                                    <label for="checkout_marketplace_stripe_publishable_key">Publishable key (pk)</label>
                                    <input id="checkout_marketplace_stripe_publishable_key" name="checkout_marketplace_stripe_publishable_key" type="text" class="form-control" value="<?= cvAccessoH((string) ($paymentConfig['stripe']['publishable_key'] ?? '')) ?>">
                                </div>
                                <div class="col-md-3 form-group">
                                    <label for="checkout_marketplace_stripe_secret_key">Secret key (sk)</label>
                                    <input id="checkout_marketplace_stripe_secret_key" name="checkout_marketplace_stripe_secret_key" type="text" class="form-control" value="<?= cvAccessoH((string) ($paymentConfig['stripe']['secret_key'] ?? '')) ?>">
                                </div>
                                <div class="col-md-3 form-group">
                                    <label for="checkout_marketplace_stripe_webhook_secret">Webhook secret</label>
                                    <input id="checkout_marketplace_stripe_webhook_secret" name="checkout_marketplace_stripe_webhook_secret" type="text" class="form-control" value="<?= cvAccessoH((string) ($paymentConfig['stripe']['webhook_secret'] ?? '')) ?>">
                                </div>
                            </div>
                            <hr>
                        <?php endif; ?>

                        <h5>Provider - Stripe</h5>
                        <?php if (count($providers) === 0): ?>
                            <div class="cv-empty">Nessun provider disponibile per il tuo account.</div>
                        <?php else: ?>
                            <?php foreach ($providers as $index => $provider): ?>
                                <?php
                                $providerCode = strtolower(trim((string) ($provider['code'] ?? '')));
                                $providerName = trim((string) ($provider['name'] ?? $providerCode));
                                if ($providerCode === '') {
                                    continue;
                                }
                                $v = static function (string $key) use ($paymentConfig, $providerCode): string {
                                    return (string) (($paymentConfig[$key][$providerCode] ?? ''));
                                };
                                ?>
                                <div class="cv-panel-card" style="padding:12px; margin:10px 0;">
                                    <h6 style="margin-top:0;"><?= cvAccessoH($providerName) ?> <span class="cv-muted">(<?= cvAccessoH($providerCode) ?>)</span></h6>
                                    <div class="row">
                                        <div class="col-md-3 form-group">
                                            <label for="provider_stripe_account_ids_<?= (int) $index ?>">Account ID (Connect)</label>
                                            <input id="provider_stripe_account_ids_<?= (int) $index ?>" type="text" class="form-control" name="provider_stripe_account_ids[<?= cvAccessoH($providerCode) ?>]" value="<?= cvAccessoH($v('provider_stripe_account_ids')) ?>">
                                        </div>
                                        <div class="col-md-3 form-group">
                                            <label for="provider_stripe_publishable_keys_<?= (int) $index ?>">Publishable key (pk)</label>
                                            <input id="provider_stripe_publishable_keys_<?= (int) $index ?>" type="text" class="form-control" name="provider_stripe_publishable_keys[<?= cvAccessoH($providerCode) ?>]" value="<?= cvAccessoH($v('provider_stripe_publishable_keys')) ?>">
                                        </div>
                                        <div class="col-md-3 form-group">
                                            <label for="provider_stripe_secret_keys_<?= (int) $index ?>">Secret key (sk)</label>
                                            <input id="provider_stripe_secret_keys_<?= (int) $index ?>" type="text" class="form-control" name="provider_stripe_secret_keys[<?= cvAccessoH($providerCode) ?>]" value="<?= cvAccessoH($v('provider_stripe_secret_keys')) ?>">
                                        </div>
                                        <div class="col-md-3 form-group">
                                            <label for="provider_stripe_webhook_secrets_<?= (int) $index ?>">Webhook secret</label>
                                            <input id="provider_stripe_webhook_secrets_<?= (int) $index ?>" type="text" class="form-control" name="provider_stripe_webhook_secrets[<?= cvAccessoH($providerCode) ?>]" value="<?= cvAccessoH($v('provider_stripe_webhook_secrets')) ?>">
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary"<?= $tableExists ? '' : ' disabled' ?>>Salva configurazione pagamenti</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php
cvAccessoRenderPageEnd();
