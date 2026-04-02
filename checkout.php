<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/site_layout.php';
require_once __DIR__ . '/includes/conn.php';
require_once __DIR__ . '/includes/runtime_settings.php';

/**
 * @return array<string,float>
 */
function cvCheckoutCommissionMapSafe(): array
{
    try {
        $connection = cvDbConnection();
        return cvRuntimeProviderCommissionMap($connection);
    } catch (Throwable $exception) {
        return [];
    }
}

/**
 * @return array<string,mixed>
 */
function cvCheckoutPaymentPublicConfigSafe(): array
{
    try {
        $connection = cvDbConnection();
        $paymentConfig = cvRuntimeMarketplacePaymentConfig($connection);
        $paypal = is_array($paymentConfig['paypal'] ?? null) ? $paymentConfig['paypal'] : [];
        $stripe = is_array($paymentConfig['stripe'] ?? null) ? $paymentConfig['stripe'] : [];

        $paypalClientId = trim((string) ($paypal['client_id'] ?? ''));
        $paypalClientSecret = trim((string) ($paypal['client_secret'] ?? ''));
        $paypalEnv = strtolower(trim((string) ($paypal['env'] ?? 'live')));
        if (!in_array($paypalEnv, ['sandbox', 'live'], true)) {
            $paypalEnv = 'live';
        }
        $paypalEnabled = !empty($paypal['enabled']) && $paypalClientId !== '' && $paypalClientSecret !== '';

        $stripePk = trim((string) ($stripe['publishable_key'] ?? ''));
        $stripeSk = trim((string) ($stripe['secret_key'] ?? ''));
        $stripeEnabled = !empty($stripe['enabled']) && $stripePk !== '' && $stripeSk !== '';

        $providerPaypalCheckoutEnabled = is_array($paymentConfig['provider_paypal_checkout_enabled'] ?? null)
            ? $paymentConfig['provider_paypal_checkout_enabled']
            : [];
        $providerPaypalCardEnabled = is_array($paymentConfig['provider_paypal_card_enabled'] ?? null)
            ? $paymentConfig['provider_paypal_card_enabled']
            : [];
        $providerPaypalMerchantIds = is_array($paymentConfig['provider_paypal_merchant_ids'] ?? null)
            ? $paymentConfig['provider_paypal_merchant_ids']
            : [];
        $providerPaypalEmails = is_array($paymentConfig['provider_paypal_emails'] ?? null)
            ? $paymentConfig['provider_paypal_emails']
            : [];
        $providerPaypalEmails = is_array($paymentConfig['provider_paypal_emails'] ?? null)
            ? $paymentConfig['provider_paypal_emails']
            : [];
        $providerStripeAccountIds = is_array($paymentConfig['provider_stripe_account_ids'] ?? null)
            ? $paymentConfig['provider_stripe_account_ids']
            : [];

        $providerPaypalEnabled = [];
        $providerPaypalCardMap = [];
        foreach ($providerPaypalCheckoutEnabled as $providerCode => $enabledFlag) {
            $providerCode = strtolower(trim((string) $providerCode));
            if ($providerCode === '') {
                continue;
            }
            $merchantId = trim((string) ($providerPaypalMerchantIds[$providerCode] ?? ''));
            $email = trim((string) ($providerPaypalEmails[$providerCode] ?? ''));
            $providerPaypalEnabled[$providerCode] = ((int) $enabledFlag) === 1 && ($merchantId !== '' || $email !== '');
            if (array_key_exists($providerCode, $providerPaypalCardEnabled)) {
                $providerPaypalCardMap[$providerCode] = ((int) ($providerPaypalCardEnabled[$providerCode] ?? 0)) === 1;
            } else {
                $providerPaypalCardMap[$providerCode] = $providerPaypalEnabled[$providerCode];
            }
        }

        $providerStripeEnabled = [];
        foreach ($providerStripeAccountIds as $providerCode => $accountId) {
            $providerCode = strtolower(trim((string) $providerCode));
            if ($providerCode === '') {
                continue;
            }
            $providerStripeEnabled[$providerCode] = trim((string) $accountId) !== '';
        }

        $normalizedProviderPaypalMerchantIds = [];
        foreach ($providerPaypalMerchantIds as $providerCode => $merchantIdRaw) {
            $providerCode = strtolower(trim((string) $providerCode));
            if ($providerCode === '') {
                continue;
            }
            $merchantId = strtoupper(trim((string) $merchantIdRaw));
            if ($merchantId === '') {
                continue;
            }
            $normalizedProviderPaypalMerchantIds[$providerCode] = $merchantId;
        }
        $normalizedProviderPaypalEmails = [];
        foreach ($providerPaypalEmails as $providerCode => $emailRaw) {
            $providerCode = strtolower(trim((string) $providerCode));
            if ($providerCode === '') {
                continue;
            }
            $email = trim((string) $emailRaw);
            if ($email === '') {
                continue;
            }
            $normalizedProviderPaypalEmails[$providerCode] = $email;
        }

        $paypalCardEnabled = array_key_exists('card_enabled', $paypal)
            ? !empty($paypal['card_enabled'])
            : true;
        $paypalMerchantIds = [];
        $platformMerchantId = trim((string) ($paypal['merchant_id'] ?? ''));
        if ($platformMerchantId !== '') {
            $paypalMerchantIds[strtoupper($platformMerchantId)] = strtoupper($platformMerchantId);
        }
        foreach ($normalizedProviderPaypalMerchantIds as $providerMerchantId) {
            $providerMerchantId = trim((string) $providerMerchantId);
            if ($providerMerchantId === '') {
                continue;
            }
            $paypalMerchantIds[strtoupper($providerMerchantId)] = strtoupper($providerMerchantId);
        }

        return [
            'paypal' => [
                'enabled' => $paypalEnabled,
                'env' => $paypalEnv,
                'client_id' => $paypalClientId,
                'marketplace_merchant_id' => strtoupper($platformMerchantId),
                'merchant_ids' => array_values($paypalMerchantIds),
                'provider_merchant_ids' => $normalizedProviderPaypalMerchantIds,
                'provider_emails' => $normalizedProviderPaypalEmails,
                'provider_enabled' => $providerPaypalEnabled,
                'card_enabled' => $paypalCardEnabled,
                'provider_card_enabled' => $providerPaypalCardMap,
            ],
            'stripe' => [
                'enabled' => $stripeEnabled,
                'publishable_key' => $stripePk,
                'provider_enabled' => $providerStripeEnabled,
            ],
        ];
    } catch (Throwable $exception) {
        return [
            'paypal' => [
                'enabled' => false,
                'env' => 'live',
                'client_id' => '',
                'marketplace_merchant_id' => '',
                'merchant_ids' => [],
                'provider_merchant_ids' => [],
                'provider_emails' => [],
                'provider_enabled' => [],
            ],
            'stripe' => ['enabled' => false, 'publishable_key' => '', 'provider_enabled' => []],
        ];
    }
}

/**
 * @return array<string,string>
 */
function cvCheckoutProviderLogoMap(string $dirPath, string $assetPrefix): array
{
    if (!is_dir($dirPath)) {
        return [];
    }

    $paths = glob(rtrim($dirPath, '/') . '/*.{svg,png,webp,jpg,jpeg}', GLOB_BRACE);
    if (!is_array($paths)) {
        return [];
    }

    $map = [];
    foreach ($paths as $path) {
        if (!is_file($path)) {
            continue;
        }

        $filename = pathinfo($path, PATHINFO_FILENAME);
        $providerCode = strtolower((string) preg_replace('/^logo[_-]?/i', '', $filename));
        if ($providerCode === '') {
            continue;
        }

        $map[$providerCode] = cvAsset('images/providers/' . basename($path));
    }

    ksort($map);
    return $map;
}

$commissionMap = cvCheckoutCommissionMapSafe();
$providerLogoMap = cvCheckoutProviderLogoMap(__DIR__ . '/assets/images/providers', 'images/providers');
$paymentPublicConfig = cvCheckoutPaymentPublicConfigSafe();
?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Checkout | cercaviaggio</title>
  <meta name="robots" content="noindex,nofollow">
  <?= cvRenderNamedAssetBundle('public-base-css') ?>
  <?= cvRenderNamedAssetBundle('public-date-css') ?>
  <?= cvRenderNamedAssetBundle('public-app-css') ?>
</head>
<body>
  <div class="cv-page-bg" aria-hidden="true"></div>

  <main class="container cv-shell py-4 py-lg-5">
    <?= cvRenderSiteHeader([
        'active' => 'ricerca',
        'contact_button' => true,
    ]) ?>

    <section>
      <p class="cv-eyebrow mb-2">Checkout</p>
      <h1 class="cv-title mb-2">Completa prenotazione e pagamento</h1>
    </section>

    <section class="cv-checkout-grid mt-3 mt-lg-4" id="cvCheckoutApp">
      <article class="cv-checkout-panel cv-checkout-main" id="cvCheckoutMainPanel">
        <div class="cv-checkout-empty d-none" id="cvCheckoutEmptyState">
          Selezione non trovata. Torna alle soluzioni e scegli una tratta prima di aprire il checkout.
          <div class="mt-3">
            <a href="./" class="btn cv-route-cta">Vai alla ricerca</a>
          </div>
        </div>

        <div id="cvCheckoutAuthGate" class="cv-checkout-auth-gate d-none">
          <h2 class="cv-checkout-section-title mb-2">Come vuoi proseguire?</h2>
          <p class="cv-muted mb-3">
            Puoi continuare senza registrazione oppure accedere/registrarti per precompilare i dati.
          </p>
          <div class="cv-checkout-auth-gate-actions">
            <button id="checkoutContinueGuestBtn" type="button" class="btn cv-checkout-auth-btn cv-checkout-auth-btn-secondary">
              Continua senza registrazione
            </button>
            <button id="checkoutOpenAuthBtn" type="button" class="btn cv-checkout-auth-btn cv-checkout-auth-btn-primary" data-bs-toggle="modal" data-bs-target="#authModal" data-auth-tab="login">
              Login / Registrazione
            </button>
          </div>
        </div>

        <div id="cvCheckoutContent" class="d-none">
          <div class="cv-checkout-block">
            <h2 class="cv-checkout-section-title">Contatto</h2>
            <div class="row g-3">
              <div class="col-md-6">
                <label for="checkoutContactName" class="cv-label">Nome e cognome</label>
                <input id="checkoutContactName" type="text" class="form-control cv-auth-input" maxlength="120" autocomplete="name">
              </div>
              <div class="col-md-6">
                <label for="checkoutContactEmail" class="cv-label">Email</label>
                <input id="checkoutContactEmail" type="email" class="form-control cv-auth-input" maxlength="160" autocomplete="email">
              </div>
              <div class="col-md-6">
                <label for="checkoutContactPhone" class="cv-label">Telefono</label>
                <input id="checkoutContactPhone" type="tel" class="form-control cv-auth-input" maxlength="32" autocomplete="tel">
              </div>
              <div class="col-12 d-none" id="checkoutNotYouToggleWrap">
                <div class="form-check mt-1">
                  <input class="form-check-input" type="checkbox" id="checkoutNotYouToggle">
                  <label class="form-check-label cv-muted" for="checkoutNotYouToggle">
                    Non sei tu a viaggiare
                  </label>
                </div>
              </div>
            </div>
          </div>

          <div class="cv-checkout-block">
            <h2 class="cv-checkout-section-title">Passeggeri</h2>
            <div id="checkoutPassengersWrap" class="cv-checkout-passenger-list"></div>
          </div>

          <div class="cv-checkout-block">
            <h2 class="cv-checkout-section-title">Bagagli</h2>
            <p class="cv-muted mb-3">Imposta i bagagli per ogni segmento (stiva e cabina).</p>
            <div id="checkoutBaggageWrap" class="cv-checkout-baggage-list"></div>
          </div>

          <div class="cv-checkout-block">
            <h2 id="checkoutPaymentTitle" class="cv-checkout-section-title">Pagamento</h2>
            <p id="checkoutPaymentLead" class="cv-muted mb-3">Scegli il metodo attivo e completa il pagamento.</p>

            <div class="cv-checkout-payments mt-3">
              <div id="checkoutPaymentUnavailable" class="alert alert-warning d-none mb-3" role="alert"></div>

              <div class="cv-checkout-payment-method" id="checkoutPaypalMethod">
                <div class="cv-checkout-payment-head">
                  <i class="bi bi-paypal"></i>
                  <span>PayPal</span>
                </div>
                <div id="checkoutPaypalWrap">
                  <div id="checkoutPaypalButton"></div>
                  <div id="checkoutPaypalCardButton" class="mt-2"></div>
                </div>
              </div>

              <div class="cv-checkout-payment-method" id="checkoutStripeMethod">
                <div class="cv-checkout-payment-head">
                  <i class="bi bi-credit-card-2-front"></i>
                  <span>Carta (Stripe)</span>
                </div>
                <button id="checkoutStripeBtn" type="button" class="btn cv-route-cta">Paga con carta</button>
              </div>

              <div class="cv-checkout-payment-method d-none" id="checkoutFreeChangeWrap">
                <div class="cv-checkout-payment-head">
                  <i class="bi bi-check2-circle"></i>
                  <span>Cambio gratuito</span>
                </div>
                <button id="checkoutFreeChangeBtn" type="button" class="btn cv-route-cta">Conferma cambio gratuito</button>
              </div>
            </div>
            <div id="checkoutInlineAlert" class="alert d-none mt-3 mb-0" role="alert"></div>
          </div>
        </div>
      </article>

      <aside class="cv-checkout-panel cv-checkout-summary" id="cvCheckoutSummaryPanel">
        <h2 class="cv-checkout-section-title">Riepilogo viaggio</h2>
        <div id="checkoutRouteSummary" class="cv-checkout-route-summary"></div>
        <div class="cv-checkout-promo mt-3">
          <label for="checkoutPromoCode" class="cv-label">Codice sconto</label>
          <div class="cv-checkout-promo-inline">
            <input id="checkoutPromoCode" type="text" class="form-control cv-auth-input" maxlength="40" placeholder="Inserisci codice promo">
            <button id="checkoutPromoApplyBtn" type="button" class="btn cv-account-secondary">Applica</button>
          </div>
          <div id="checkoutPromoMessage" class="cv-checkout-promo-message cv-muted"></div>
        </div>
        <div class="cv-checkout-totals" id="checkoutTotals"></div>
      </aside>
    </section>

    <?= cvRenderSiteFooter('mt-4') ?>
  </main>
  <?= cvRenderSiteAuthModals() ?>

  <script>
    window.CV_CHECKOUT_CONFIG = {
      createOrderUrl: './checkout_api.php',
      authMeUrl: './auth/api.php?action=me',
      successRedirectLogged: './biglietti.php',
      successRedirectGuest: './index.php',
      baseUrl: <?= json_encode(cvBaseUrl(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
      commissionMap: <?= json_encode($commissionMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
      providerLogos: <?= json_encode($providerLogoMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
      payment: <?= json_encode($paymentPublicConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
    };
  </script>
  <?php if (!empty($paymentPublicConfig['stripe']['enabled'])): ?>
    <script src="https://js.stripe.com/v3/"></script>
  <?php endif; ?>
  <?= cvRenderNamedAssetBundle('public-core-js') ?>
  <?= cvRenderNamedAssetBundle('public-date-js') ?>
  <?= cvRenderNamedAssetBundle('public-app-js') ?>
  <?= cvRenderNamedAssetBundle('public-checkout-js') ?>
</body>
</html>
