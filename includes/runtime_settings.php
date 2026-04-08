<?php
declare(strict_types=1);

if (!function_exists('cvRuntimeSettingSpecs')) {
    /**
     * @return array<string,array<string,mixed>>
     */
    function cvRuntimeSettingSpecs(): array
    {
        return [
            'pathfind_transfer_max_wait_minutes' => [
                'label' => 'Attesa massima scalo',
                'help' => 'Tempo massimo di attesa tra una corsa e la successiva.',
                'type' => 'int',
                'default' => 120,
                'min' => 10,
                'max' => 720,
                'step' => 5,
                'unit' => 'minuti',
            ],
            'pathfind_transfer_max_distance_km' => [
                'label' => 'Distanza massima scalo',
                'help' => 'Distanza massima tra due fermate per consentire uno scalo.',
                'type' => 'float',
                'default' => 0.6,
                'min' => 0.0,
                'max' => 10.0,
                'step' => 0.1,
                'unit' => 'km',
            ],
            'places_macroarea_capoluogo_radius_km' => [
                'label' => 'Raggio accorpamento macroarea',
                'help' => 'Distanza massima (dal centro del capoluogo) per includere citta e fermate nella macroarea provinciale.',
                'type' => 'float',
                'default' => 35.0,
                'min' => 5.0,
                'max' => 200.0,
                'step' => 1.0,
                'unit' => 'km',
            ],
            'pathfind_max_transfers' => [
                'label' => 'Scali massimi',
                'help' => 'Numero massimo di cambi consentiti in una soluzione.',
                'type' => 'int',
                'default' => 2,
                'min' => 0,
                'max' => 3,
                'step' => 1,
                'unit' => 'scali',
            ],
            'pathfind_cache_ttl_seconds' => [
                'label' => 'Durata cache pathfind',
                'help' => 'Per quanti minuti riusare una ricerca già calcolata prima di ricalcolarla.',
                'type' => 'int',
                'default' => 600,
                'min' => 60,
                'max' => 86400,
                'step' => 30,
                'unit' => 'secondi',
            ],
            'pathfind_price_calendar_range_days' => [
                'label' => 'Range prezzi calendario (giorni)',
                'help' => 'Quanti giorni prima/dopo mostrare nella riga date con prezzo minimo.',
                'type' => 'int',
                'default' => 3,
                'min' => 2,
                'max' => 10,
                'step' => 1,
                'unit' => 'giorni',
            ],
            'pathfind_date_price_calendar_enabled' => [
                'label' => 'Prezzi nella riga date',
                'help' => 'Mostra/Nascondi i prezzi nella riga date. Disattivando velocizzi il caricamento.',
                'type' => 'int',
                'default' => 1,
                'min' => 0,
                'max' => 1,
                'step' => 1,
            ],
            'pathfind_two_transfer_trigger_max_solutions' => [
                'label' => 'Soglia attivazione 2 scali',
                'help' => 'Prova percorsi a 2 scali solo se le soluzioni trovate sono sotto questa soglia.',
                'type' => 'int',
                'default' => 12,
                'min' => 0,
                'max' => 30,
                'step' => 1,
                'unit' => 'soluzioni',
            ],
            'pathfind_all_rows_limit' => [
                'label' => 'Limite righe per ramo 2 scali',
                'help' => 'Limite massimo dati usati nel calcolo avanzato a 2 scali.',
                'type' => 'int',
                'default' => 5000,
                'min' => 1000,
                'max' => 8000,
                'step' => 100,
                'unit' => 'righe',
            ],
            'provider_price_modes' => [
                'label' => 'Modalita prezzi provider',
                'help' => 'Mappa JSON con modalita prezzo per provider (es: {"curcio":"discounted","leonetti":"full"}).',
                'type' => 'string',
                'default' => '',
            ],
            'checkout_provider_commission_percent' => [
                'label' => 'Commissione checkout per provider',
                'help' => 'Mappa JSON con commissione percentuale trattenuta da Cercaviaggio per provider (es: {"curcio":5.5,"leonetti":4}).',
                'type' => 'string',
                'default' => '',
            ],
            'checkout_provider_paypal_merchant_ids' => [
                'label' => 'Merchant ID PayPal provider',
                'help' => 'Mappa JSON con merchant_id PayPal per provider (es: {"curcio":"ABC...","leonetti":"XYZ..."}).',
                'type' => 'string',
                'default' => '',
            ],
            'checkout_provider_paypal_environments' => [
                'label' => 'Ambiente PayPal provider',
                'help' => 'Mappa JSON sandbox/live per provider.',
                'type' => 'string',
                'default' => '',
            ],
            'checkout_provider_paypal_emails' => [
                'label' => 'Email PayPal provider',
                'help' => 'Mappa JSON con email PayPal per provider (fallback se merchant_id non disponibile).',
                'type' => 'string',
                'default' => '',
            ],
            'checkout_provider_paypal_auth_tokens' => [
                'label' => 'Auth token PayPal provider',
                'help' => 'Mappa JSON con auth token PayPal provider.',
                'type' => 'string',
                'default' => '',
            ],
            'checkout_provider_paypal_checkout_enabled' => [
                'label' => 'PayPal checkout provider abilitato',
                'help' => 'Mappa JSON con flag 0/1 per abilitare PayPal checkout sul provider.',
                'type' => 'string',
                'default' => '',
            ],
            'checkout_provider_paypal_card_enabled' => [
                'label' => 'PayPal carta provider abilitato',
                'help' => 'Mappa JSON con flag 0/1 per abilitare pagamento carta PayPal sul provider.',
                'type' => 'string',
                'default' => '',
            ],
            'checkout_provider_paypal_api_usernames' => [
                'label' => 'API username PayPal provider',
                'help' => 'Mappa JSON API username (legacy API PayPal).',
                'type' => 'string',
                'default' => '',
            ],
            'checkout_provider_paypal_api_passwords' => [
                'label' => 'API password PayPal provider',
                'help' => 'Mappa JSON API password (legacy API PayPal).',
                'type' => 'string',
                'default' => '',
            ],
            'checkout_provider_paypal_api_signatures' => [
                'label' => 'API signature PayPal provider',
                'help' => 'Mappa JSON API signature (legacy API PayPal).',
                'type' => 'string',
                'default' => '',
            ],
            'checkout_provider_paypal_client_ids' => [
                'label' => 'Client ID PayPal provider',
                'help' => 'Mappa JSON client_id provider.',
                'type' => 'string',
                'default' => '',
            ],
            'checkout_provider_paypal_secrets' => [
                'label' => 'Secret PayPal provider',
                'help' => 'Mappa JSON secret provider.',
                'type' => 'string',
                'default' => '',
            ],
            'checkout_provider_stripe_account_ids' => [
                'label' => 'Account ID Stripe Connect provider',
                'help' => 'Mappa JSON con account_id Stripe Connect per provider (es: {"curcio":"acct_..."}).',
                'type' => 'string',
                'default' => '',
            ],
            'checkout_provider_stripe_publishable_keys' => [
                'label' => 'Publishable key Stripe provider',
                'help' => 'Mappa JSON pk Stripe provider.',
                'type' => 'string',
                'default' => '',
            ],
            'checkout_provider_stripe_secret_keys' => [
                'label' => 'Secret key Stripe provider',
                'help' => 'Mappa JSON sk Stripe provider.',
                'type' => 'string',
                'default' => '',
            ],
            'checkout_provider_stripe_webhook_secrets' => [
                'label' => 'Webhook secret Stripe provider',
                'help' => 'Mappa JSON webhook secret Stripe provider.',
                'type' => 'string',
                'default' => '',
            ],
            'checkout_marketplace_paypal_env' => [
                'label' => 'Ambiente PayPal marketplace',
                'help' => 'sandbox oppure live.',
                'type' => 'string',
                'default' => 'live',
            ],
            'checkout_marketplace_paypal_email' => [
                'label' => 'Email PayPal marketplace',
                'help' => 'Email account PayPal piattaforma.',
                'type' => 'string',
                'default' => '',
            ],
            'checkout_marketplace_paypal_auth_token' => [
                'label' => 'Auth token PayPal marketplace',
                'help' => 'Auth token PayPal piattaforma.',
                'type' => 'string',
                'default' => '',
            ],
            'checkout_marketplace_paypal_checkout_enabled' => [
                'label' => 'PayPal checkout marketplace abilitato',
                'help' => '0/1 abilita il pagamento PayPal nel checkout.',
                'type' => 'int',
                'default' => 1,
                'min' => 0,
                'max' => 1,
                'step' => 1,
            ],
            'checkout_marketplace_paypal_card_enabled' => [
                'label' => 'PayPal carta marketplace abilitato',
                'help' => '0/1 abilita pagamento carta via PayPal.',
                'type' => 'int',
                'default' => 1,
                'min' => 0,
                'max' => 1,
                'step' => 1,
            ],
            'checkout_marketplace_paypal_client_id' => [
                'label' => 'PayPal Client ID marketplace',
                'help' => 'Client ID PayPal della piattaforma Cercaviaggio.',
                'type' => 'string',
                'default' => '',
            ],
            'checkout_marketplace_paypal_client_secret' => [
                'label' => 'PayPal Client Secret marketplace',
                'help' => 'Client Secret PayPal della piattaforma Cercaviaggio.',
                'type' => 'string',
                'default' => '',
            ],
            'checkout_marketplace_paypal_merchant_id' => [
                'label' => 'PayPal Merchant ID marketplace',
                'help' => 'Merchant ID PayPal della piattaforma Cercaviaggio (quota commissione).',
                'type' => 'string',
                'default' => '',
            ],
            'checkout_marketplace_paypal_api_username' => [
                'label' => 'PayPal API Username marketplace',
                'help' => 'API Username PayPal (legacy/NVP), opzionale.',
                'type' => 'string',
                'default' => '',
            ],
            'checkout_marketplace_paypal_api_password' => [
                'label' => 'PayPal API Password marketplace',
                'help' => 'API Password PayPal (legacy/NVP), opzionale.',
                'type' => 'string',
                'default' => '',
            ],
            'checkout_marketplace_paypal_api_signature' => [
                'label' => 'PayPal API Signature marketplace',
                'help' => 'API Signature PayPal (legacy/NVP), opzionale.',
                'type' => 'string',
                'default' => '',
            ],
            'checkout_marketplace_stripe_account_id' => [
                'label' => 'Stripe Account ID marketplace',
                'help' => 'Account ID Stripe Connect della piattaforma (opzionale).',
                'type' => 'string',
                'default' => '',
            ],
            'checkout_marketplace_stripe_publishable_key' => [
                'label' => 'Stripe Publishable Key marketplace',
                'help' => 'Chiave pubblica Stripe della piattaforma Cercaviaggio.',
                'type' => 'string',
                'default' => '',
            ],
            'checkout_marketplace_stripe_secret_key' => [
                'label' => 'Stripe Secret Key marketplace',
                'help' => 'Chiave segreta Stripe della piattaforma Cercaviaggio.',
                'type' => 'string',
                'default' => '',
            ],
            'checkout_marketplace_stripe_webhook_secret' => [
                'label' => 'Stripe webhook secret marketplace',
                'help' => 'Secret webhook Stripe piattaforma.',
                'type' => 'string',
                'default' => '',
            ],
            'checkout_marketplace_stripe_enabled' => [
                'label' => 'Stripe marketplace abilitato',
                'help' => '0/1 abilita Stripe nel checkout.',
                'type' => 'int',
                'default' => 1,
                'min' => 0,
                'max' => 1,
                'step' => 1,
            ],
            'homepage_popular_provider_codes' => [
                'label' => 'Provider in evidenza home',
                'help' => 'Lista codici provider abilitati nella sezione "In evidenza per provider" della home. Vuoto = tutti i provider attivi. Le tratte precise vengono scelte poi dal provider in backend.',
                'type' => 'string',
                'default' => '',
            ],
            'homepage_popular_provider_limits' => [
                'label' => 'Limiti vetrina home per provider',
                'help' => 'Mappa JSON con il numero massimo di tratte selezionabili e pubblicabili per provider (es: {"curcio":100,"leonetti":0}). Vuoto = usa il valore standard.',
                'type' => 'string',
                'default' => '',
            ],
            'homepage_popular_per_provider' => [
                'label' => 'Default tratte per provider in home',
                'help' => 'Numero standard di tratte che ogni provider puo selezionare per la vetrina home se non ha un limite dedicato.',
                'type' => 'int',
                'default' => 4,
                'min' => 1,
                'max' => 50,
                'step' => 1,
                'unit' => 'tratte',
            ],
            'ticket_pdf_show_provider_contacts' => [
                'label' => 'PDF biglietto: mostra contatti provider',
                'help' => '0 nasconde email/telefono/indirizzo del provider nel PDF (consigliato per anti-disintermediazione), 1 li mostra.',
                'type' => 'int',
                'default' => 0,
                'min' => 0,
                'max' => 1,
                'step' => 1,
            ],
            'ticket_pdf_provider_show_email_map' => [
                'label' => 'PDF provider: mappa visibilita email',
                'help' => 'Mappa JSON per provider con flag 0/1 di visibilita email nel PDF.',
                'type' => 'string',
                'default' => '',
            ],
            'ticket_pdf_provider_show_site_map' => [
                'label' => 'PDF provider: mappa visibilita sito',
                'help' => 'Mappa JSON per provider con flag 0/1 di visibilita sito nel PDF.',
                'type' => 'string',
                'default' => '',
            ],
            'ticket_pdf_provider_site_map' => [
                'label' => 'PDF provider: mappa URL sito',
                'help' => 'Mappa JSON per provider con URL sito da mostrare nel PDF.',
                'type' => 'string',
                'default' => '',
            ],
        ];
    }
}

if (!function_exists('cvRuntimeGeneralSettingSpecs')) {
    /**
     * @return array<string,array<string,mixed>>
     */
    function cvRuntimeGeneralSettingSpecs(): array
    {
        $specs = [];
        foreach (cvRuntimeSettingSpecs() as $key => $spec) {
            if (strpos($key, 'checkout_') === 0) {
                continue;
            }
            $specs[$key] = $spec;
        }

        return $specs;
    }
}

if (!function_exists('cvRuntimePaymentSettingSpecs')) {
    /**
     * @return array<string,array<string,mixed>>
     */
    function cvRuntimePaymentSettingSpecs(): array
    {
        $specs = [];
        foreach (cvRuntimeSettingSpecs() as $key => $spec) {
            if (strpos($key, 'checkout_') !== 0) {
                continue;
            }
            $specs[$key] = $spec;
        }

        return $specs;
    }
}

if (!function_exists('cvRuntimeSettingsDefaults')) {
    /**
     * @return array<string,int|float|string|bool>
     */
    function cvRuntimeSettingsDefaults(): array
    {
        $defaults = [];
        foreach (cvRuntimeGeneralSettingSpecs() as $key => $spec) {
            $defaults[$key] = $spec['default'];
        }

        return $defaults;
    }
}

if (!function_exists('cvRuntimePaymentSettingsDefaults')) {
    /**
     * @return array<string,int|float|string|bool>
     */
    function cvRuntimePaymentSettingsDefaults(): array
    {
        $defaults = [];
        foreach (cvRuntimePaymentSettingSpecs() as $key => $spec) {
            $defaults[$key] = $spec['default'];
        }

        return $defaults;
    }
}

if (!function_exists('cvRuntimeSettingsTableExists')) {
    function cvRuntimeSettingsTableExists(mysqli $connection): bool
    {
        static $cache = null;
        if (is_bool($cache)) {
            return $cache;
        }

        $result = $connection->query("SHOW TABLES LIKE 'cv_settings'");
        if (!$result instanceof mysqli_result) {
            $cache = false;
            return $cache;
        }

        $cache = $result->num_rows > 0;
        $result->free();
        return $cache;
    }
}

if (!function_exists('cvRuntimeNormalizeSettingValue')) {
    /**
     * @param mixed $rawValue
     * @return int|float|string|bool
     */
    function cvRuntimeNormalizeSettingValue(array $spec, $rawValue)
    {
        $type = (string) ($spec['type'] ?? 'string');

        if ($type === 'int') {
            $value = is_numeric($rawValue) ? (int) round((float) $rawValue) : (int) ($spec['default'] ?? 0);
            $min = isset($spec['min']) ? (int) $spec['min'] : $value;
            $max = isset($spec['max']) ? (int) $spec['max'] : $value;
            return max($min, min($max, $value));
        }

        if ($type === 'float') {
            $normalized = is_string($rawValue) ? str_replace(',', '.', trim($rawValue)) : $rawValue;
            $value = is_numeric($normalized) ? (float) $normalized : (float) ($spec['default'] ?? 0.0);
            $min = isset($spec['min']) ? (float) $spec['min'] : $value;
            $max = isset($spec['max']) ? (float) $spec['max'] : $value;
            return max($min, min($max, $value));
        }

        if ($type === 'bool') {
            if (is_bool($rawValue)) {
                return $rawValue;
            }

            $normalized = strtolower(trim((string) $rawValue));
            return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
        }

        return trim((string) $rawValue);
    }
}

if (!function_exists('cvRuntimeSettingCsvList')) {
    /**
     * @param mixed $rawValue
     * @return array<int,string>
     */
    function cvRuntimeSettingCsvList($rawValue): array
    {
        $normalized = [];
        $parts = explode(',', (string) $rawValue);
        foreach ($parts as $part) {
            $value = strtolower(trim((string) $part));
            if ($value === '') {
                continue;
            }
            $normalized[$value] = $value;
        }

        return array_values($normalized);
    }
}

if (!function_exists('cvRuntimeSettingCsvSerialize')) {
    /**
     * @param array<int,string> $values
     */
    function cvRuntimeSettingCsvSerialize(array $values): string
    {
        $normalized = [];
        foreach ($values as $value) {
            $key = strtolower(trim((string) $value));
            if ($key === '') {
                continue;
            }
            $normalized[$key] = $key;
        }

        return implode(',', array_values($normalized));
    }
}

if (!function_exists('cvRuntimeSettingJsonMap')) {
    /**
     * @param mixed $rawValue
     * @return array<string,int>
     */
    function cvRuntimeSettingJsonMap($rawValue): array
    {
        if (!is_string($rawValue) || trim($rawValue) === '') {
            return [];
        }

        $decoded = json_decode($rawValue, true);
        if (!is_array($decoded)) {
            return [];
        }

        $normalized = [];
        foreach ($decoded as $key => $value) {
            $providerCode = strtolower(trim((string) $key));
            if ($providerCode === '') {
                continue;
            }

            $limit = is_numeric($value) ? (int) round((float) $value) : 0;
            $normalized[$providerCode] = max(0, $limit);
        }

        return $normalized;
    }
}

if (!function_exists('cvRuntimeSettingJsonFloatMap')) {
    /**
     * @param mixed $rawValue
     * @return array<string,float>
     */
    function cvRuntimeSettingJsonFloatMap($rawValue): array
    {
        if (!is_string($rawValue) || trim($rawValue) === '') {
            return [];
        }

        $decoded = json_decode($rawValue, true);
        if (!is_array($decoded)) {
            return [];
        }

        $normalized = [];
        foreach ($decoded as $key => $value) {
            $providerCode = strtolower(trim((string) $key));
            if ($providerCode === '') {
                continue;
            }

            $commission = is_numeric($value) ? (float) $value : 0.0;
            $normalized[$providerCode] = max(0.0, min(100.0, round($commission, 4)));
        }

        return $normalized;
    }
}

if (!function_exists('cvRuntimeSettingJsonStringMap')) {
    /**
     * @param mixed $rawValue
     * @return array<string,string>
     */
    function cvRuntimeSettingJsonStringMap($rawValue): array
    {
        if (!is_string($rawValue) || trim($rawValue) === '') {
            return [];
        }

        $decoded = json_decode($rawValue, true);
        if (!is_array($decoded)) {
            return [];
        }

        $normalized = [];
        foreach ($decoded as $key => $value) {
            $providerCode = strtolower(trim((string) $key));
            if ($providerCode === '') {
                continue;
            }

            $normalized[$providerCode] = trim((string) $value);
        }

        return $normalized;
    }
}

if (!function_exists('cvRuntimeSettingJsonIntMap')) {
    /**
     * @param mixed $rawValue
     * @return array<string,int>
     */
    function cvRuntimeSettingJsonIntMap($rawValue): array
    {
        if (!is_string($rawValue) || trim($rawValue) === '') {
            return [];
        }

        $decoded = json_decode($rawValue, true);
        if (!is_array($decoded)) {
            return [];
        }

        $normalized = [];
        foreach ($decoded as $key => $value) {
            $providerCode = strtolower(trim((string) $key));
            if ($providerCode === '') {
                continue;
            }

            $normalized[$providerCode] = ((int) $value) > 0 ? 1 : 0;
        }

        return $normalized;
    }
}

if (!function_exists('cvRuntimeNormalizeProviderPriceMode')) {
    function cvRuntimeNormalizeProviderPriceMode(string $value): string
    {
        $normalized = strtolower(trim($value));
        return $normalized === 'full' ? 'full' : 'discounted';
    }
}

if (!function_exists('cvRuntimeSettingProviderPriceModes')) {
    /**
     * @param mixed $rawValue
     * @return array<string,string>
     */
    function cvRuntimeSettingProviderPriceModes($rawValue): array
    {
        if (!is_string($rawValue) || trim($rawValue) === '') {
            return [];
        }

        $decoded = json_decode($rawValue, true);
        if (!is_array($decoded)) {
            return [];
        }

        $normalized = [];
        foreach ($decoded as $key => $value) {
            $providerCode = strtolower(trim((string) $key));
            if ($providerCode === '') {
                continue;
            }

            $normalized[$providerCode] = cvRuntimeNormalizeProviderPriceMode((string) $value);
        }

        return $normalized;
    }
}

if (!function_exists('cvRuntimeProviderPriceModeMap')) {
    /**
     * @return array<string,string>
     */
    function cvRuntimeProviderPriceModeMap(mysqli $connection): array
    {
        $settings = cvRuntimeSettings($connection);
        return cvRuntimeSettingProviderPriceModes($settings['provider_price_modes'] ?? '');
    }
}

if (!function_exists('cvRuntimeProviderCommissionMap')) {
    /**
     * @return array<string,float>
     */
    function cvRuntimeProviderCommissionMap(mysqli $connection): array
    {
        $settings = cvRuntimePaymentSettings($connection);
        return cvRuntimeSettingJsonFloatMap($settings['checkout_provider_commission_percent'] ?? '');
    }
}

if (!function_exists('cvRuntimeMarketplacePaymentConfig')) {
    /**
     * @return array<string,mixed>
     */
    function cvRuntimeMarketplacePaymentConfig(mysqli $connection): array
    {
        $settings = cvRuntimePaymentSettings($connection);

        $paypalEnv = strtolower(trim((string) ($settings['checkout_marketplace_paypal_env'] ?? 'live')));
        if (!in_array($paypalEnv, ['sandbox', 'live'], true)) {
            $paypalEnv = 'live';
        }

        return [
            'paypal' => [
                'env' => $paypalEnv,
                'enabled' => ((int) ($settings['checkout_marketplace_paypal_checkout_enabled'] ?? 1)) === 1,
                'card_enabled' => ((int) ($settings['checkout_marketplace_paypal_card_enabled'] ?? 1)) === 1,
                'email' => trim((string) ($settings['checkout_marketplace_paypal_email'] ?? '')),
                'auth_token' => trim((string) ($settings['checkout_marketplace_paypal_auth_token'] ?? '')),
                'client_id' => trim((string) ($settings['checkout_marketplace_paypal_client_id'] ?? '')),
                'client_secret' => trim((string) ($settings['checkout_marketplace_paypal_client_secret'] ?? '')),
                'merchant_id' => trim((string) ($settings['checkout_marketplace_paypal_merchant_id'] ?? '')),
                'api_username' => trim((string) ($settings['checkout_marketplace_paypal_api_username'] ?? '')),
                'api_password' => trim((string) ($settings['checkout_marketplace_paypal_api_password'] ?? '')),
                'api_signature' => trim((string) ($settings['checkout_marketplace_paypal_api_signature'] ?? '')),
            ],
            'stripe' => [
                'enabled' => ((int) ($settings['checkout_marketplace_stripe_enabled'] ?? 1)) === 1,
                'account_id' => trim((string) ($settings['checkout_marketplace_stripe_account_id'] ?? '')),
                'publishable_key' => trim((string) ($settings['checkout_marketplace_stripe_publishable_key'] ?? '')),
                'secret_key' => trim((string) ($settings['checkout_marketplace_stripe_secret_key'] ?? '')),
                'webhook_secret' => trim((string) ($settings['checkout_marketplace_stripe_webhook_secret'] ?? '')),
            ],
            'provider_paypal_environments' => cvRuntimeSettingJsonStringMap($settings['checkout_provider_paypal_environments'] ?? ''),
            'provider_paypal_merchant_ids' => cvRuntimeSettingJsonStringMap($settings['checkout_provider_paypal_merchant_ids'] ?? ''),
            'provider_paypal_emails' => cvRuntimeSettingJsonStringMap($settings['checkout_provider_paypal_emails'] ?? ''),
            'provider_paypal_auth_tokens' => cvRuntimeSettingJsonStringMap($settings['checkout_provider_paypal_auth_tokens'] ?? ''),
            'provider_paypal_checkout_enabled' => cvRuntimeSettingJsonIntMap($settings['checkout_provider_paypal_checkout_enabled'] ?? ''),
            'provider_paypal_card_enabled' => cvRuntimeSettingJsonIntMap($settings['checkout_provider_paypal_card_enabled'] ?? ''),
            'provider_paypal_api_usernames' => cvRuntimeSettingJsonStringMap($settings['checkout_provider_paypal_api_usernames'] ?? ''),
            'provider_paypal_api_passwords' => cvRuntimeSettingJsonStringMap($settings['checkout_provider_paypal_api_passwords'] ?? ''),
            'provider_paypal_api_signatures' => cvRuntimeSettingJsonStringMap($settings['checkout_provider_paypal_api_signatures'] ?? ''),
            'provider_paypal_client_ids' => cvRuntimeSettingJsonStringMap($settings['checkout_provider_paypal_client_ids'] ?? ''),
            'provider_paypal_secrets' => cvRuntimeSettingJsonStringMap($settings['checkout_provider_paypal_secrets'] ?? ''),
            'provider_stripe_account_ids' => cvRuntimeSettingJsonStringMap($settings['checkout_provider_stripe_account_ids'] ?? ''),
            'provider_stripe_publishable_keys' => cvRuntimeSettingJsonStringMap($settings['checkout_provider_stripe_publishable_keys'] ?? ''),
            'provider_stripe_secret_keys' => cvRuntimeSettingJsonStringMap($settings['checkout_provider_stripe_secret_keys'] ?? ''),
            'provider_stripe_webhook_secrets' => cvRuntimeSettingJsonStringMap($settings['checkout_provider_stripe_webhook_secrets'] ?? ''),
        ];
    }
}

if (!function_exists('cvRuntimeProviderPriceMode')) {
    function cvRuntimeProviderPriceMode(mysqli $connection, string $providerCode): string
    {
        $providerCode = strtolower(trim($providerCode));
        if ($providerCode === '') {
            return 'discounted';
        }

        $map = cvRuntimeProviderPriceModeMap($connection);
        return $map[$providerCode] ?? 'discounted';
    }
}

if (!function_exists('cvRuntimeResolveDisplayedAmount')) {
    function cvRuntimeResolveDisplayedAmount(string $priceMode, float $discountedAmount, float $originalAmount): float
    {
        $discounted = max(0.0, $discountedAmount);
        $original = max(0.0, $originalAmount);

        if (cvRuntimeNormalizeProviderPriceMode($priceMode) === 'full' && $original > 0.0) {
            return $original;
        }

        if ($discounted > 0.0) {
            return $discounted;
        }

        return $original;
    }
}

if (!function_exists('cvRuntimeApplyProviderCommission')) {
    /**
     * Calcola la commissione provider sul prezzo esposto senza ridurre il totale cliente.
     *
     * @return array{client_amount:float,commission_amount:float,commission_percent:float,base_amount:float}
     */
    function cvRuntimeApplyProviderCommission(float $baseAmount, float $commissionPercent, int $roundScale = 1): array
    {
        $base = max(0.0, $baseAmount);
        $percent = max(0.0, min(100.0, $commissionPercent));
        $commissionRaw = ($base * $percent) / 100;
        $clientAmount = round($base, $roundScale);
        $commissionAmount = round(max(0.0, min($commissionRaw, $base)), 2);

        return [
            'base_amount' => $base,
            'client_amount' => $clientAmount,
            'commission_amount' => $commissionAmount,
            'commission_percent' => round($percent, 4),
        ];
    }
}

if (!function_exists('cvRuntimeSettings')) {
    /**
     * @return array<string,int|float|string|bool>
     */
    function cvRuntimeSettings(mysqli $connection, bool $forceRefresh = false): array
    {
        static $cache = null;
        if (!$forceRefresh && is_array($cache)) {
            return $cache;
        }

        $defaults = cvRuntimeSettingsDefaults();
        if (!cvRuntimeSettingsTableExists($connection)) {
            $cache = $defaults;
            return $cache;
        }

        $specs = cvRuntimeGeneralSettingSpecs();
        $keys = array_keys($specs);
        if (count($keys) === 0) {
            $cache = $defaults;
            return $cache;
        }

        $escapedKeys = [];
        foreach ($keys as $key) {
            $escapedKeys[] = "'" . $connection->real_escape_string($key) . "'";
        }

        $sql = "SELECT setting_key, setting_value
                FROM cv_settings
                WHERE setting_key IN (" . implode(', ', $escapedKeys) . ")";
        $result = $connection->query($sql);
        if (!$result instanceof mysqli_result) {
            $cache = $defaults;
            return $cache;
        }

        $values = $defaults;
        while ($row = $result->fetch_assoc()) {
            if (!is_array($row)) {
                continue;
            }

            $key = trim((string) ($row['setting_key'] ?? ''));
            if ($key === '' || !isset($specs[$key])) {
                continue;
            }

            $values[$key] = cvRuntimeNormalizeSettingValue($specs[$key], $row['setting_value'] ?? null);
        }

        $result->free();
        $cache = $values;
        return $cache;
    }
}

if (!function_exists('cvRuntimePaymentSettingsTableExists')) {
    function cvRuntimePaymentSettingsTableExists(mysqli $connection): bool
    {
        static $cache = null;
        if (is_bool($cache)) {
            return $cache;
        }

        $result = $connection->query("SHOW TABLES LIKE 'cv_payment_settings'");
        if (!$result instanceof mysqli_result) {
            $cache = false;
            return $cache;
        }

        $cache = $result->num_rows > 0;
        $result->free();
        return $cache;
    }
}

if (!function_exists('cvRuntimePaymentSettings')) {
    /**
     * @return array<string,int|float|string|bool>
     */
    function cvRuntimePaymentSettings(mysqli $connection, bool $forceRefresh = false): array
    {
        static $cache = null;
        if (!$forceRefresh && is_array($cache)) {
            return $cache;
        }

        $defaults = cvRuntimePaymentSettingsDefaults();
        if (!cvRuntimePaymentSettingsTableExists($connection)) {
            $cache = $defaults;
            return $cache;
        }

        $specs = cvRuntimePaymentSettingSpecs();
        $keys = array_keys($specs);
        if (count($keys) === 0) {
            $cache = $defaults;
            return $cache;
        }

        $escapedKeys = [];
        foreach ($keys as $key) {
            $escapedKeys[] = "'" . $connection->real_escape_string($key) . "'";
        }

        $sql = "SELECT setting_key, setting_value
                FROM cv_payment_settings
                WHERE setting_key IN (" . implode(', ', $escapedKeys) . ")";
        $result = $connection->query($sql);
        if (!$result instanceof mysqli_result) {
            $cache = $defaults;
            return $cache;
        }

        $values = $defaults;
        while ($row = $result->fetch_assoc()) {
            if (!is_array($row)) {
                continue;
            }

            $key = trim((string) ($row['setting_key'] ?? ''));
            if ($key === '' || !isset($specs[$key])) {
                continue;
            }

            $values[$key] = cvRuntimeNormalizeSettingValue($specs[$key], $row['setting_value'] ?? null);
        }

        $result->free();
        $cache = $values;
        return $cache;
    }
}

if (!function_exists('cvRuntimeSettingsVersionToken')) {
    function cvRuntimeSettingsVersionToken(mysqli $connection): string
    {
        $settings = cvRuntimeSettings($connection);
        ksort($settings, SORT_STRING);

        $parts = [];
        foreach ($settings as $key => $value) {
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }
            $parts[] = $key . '=' . (string) $value;
        }

        return hash('sha256', implode('|', $parts));
    }
}

if (!function_exists('cvRuntimePaymentSettingsVersionToken')) {
    function cvRuntimePaymentSettingsVersionToken(mysqli $connection): string
    {
        $settings = cvRuntimePaymentSettings($connection);
        ksort($settings, SORT_STRING);

        $parts = [];
        foreach ($settings as $key => $value) {
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }
            $parts[] = $key . '=' . (string) $value;
        }

        return hash('sha256', implode('|', $parts));
    }
}

if (!function_exists('cvRuntimeSaveSettings')) {
    /**
     * @param array<string,mixed> $payload
     * @return array<string,int|float|string|bool>
     */
    function cvRuntimeSaveSettings(mysqli $connection, array $payload): array
    {
        if (!cvRuntimeSettingsTableExists($connection)) {
            throw new RuntimeException('Tabella cv_settings non disponibile.');
        }

        $specs = cvRuntimeGeneralSettingSpecs();
        $normalized = cvRuntimeSettingsDefaults();

        foreach ($specs as $key => $spec) {
            $rawValue = array_key_exists($key, $payload) ? $payload[$key] : $spec['default'];
            $normalized[$key] = cvRuntimeNormalizeSettingValue($spec, $rawValue);
        }

        $statement = $connection->prepare(
            "INSERT INTO cv_settings (setting_key, setting_value, value_type, updated_at)
             VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                setting_value = VALUES(setting_value),
                value_type = VALUES(value_type),
                updated_at = NOW()"
        );
        if (!$statement instanceof mysqli_stmt) {
            throw new RuntimeException('Impossibile preparare il salvataggio impostazioni.');
        }

        foreach ($specs as $key => $spec) {
            $value = $normalized[$key];
            $type = (string) ($spec['type'] ?? 'string');

            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            } elseif (is_float($value)) {
                $value = rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
            } else {
                $value = (string) $value;
            }

            $statement->bind_param('sss', $key, $value, $type);
            if (!$statement->execute()) {
                $statement->close();
                throw new RuntimeException('Errore salvataggio impostazioni: ' . $statement->error);
            }
        }

        $statement->close();
        cvRuntimeSettings($connection, true);
        return cvRuntimeSettings($connection);
    }
}

if (!function_exists('cvRuntimeSavePaymentSettings')) {
    /**
     * @param array<string,mixed> $payload
     * @return array<string,int|float|string|bool>
     */
    function cvRuntimeSavePaymentSettings(mysqli $connection, array $payload): array
    {
        if (!cvRuntimePaymentSettingsTableExists($connection)) {
            throw new RuntimeException('Tabella cv_payment_settings non disponibile.');
        }

        $specs = cvRuntimePaymentSettingSpecs();
        $normalized = cvRuntimePaymentSettingsDefaults();

        foreach ($specs as $key => $spec) {
            $rawValue = array_key_exists($key, $payload) ? $payload[$key] : $spec['default'];
            $normalized[$key] = cvRuntimeNormalizeSettingValue($spec, $rawValue);
        }

        $statement = $connection->prepare(
            "INSERT INTO cv_payment_settings (setting_key, setting_value, value_type, updated_at)
             VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                setting_value = VALUES(setting_value),
                value_type = VALUES(value_type),
                updated_at = NOW()"
        );
        if (!$statement instanceof mysqli_stmt) {
            throw new RuntimeException('Impossibile preparare il salvataggio impostazioni pagamenti.');
        }

        foreach ($specs as $key => $spec) {
            $value = $normalized[$key];
            $type = (string) ($spec['type'] ?? 'string');

            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            } elseif (is_float($value)) {
                $value = rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
            } else {
                $value = (string) $value;
            }

            $statement->bind_param('sss', $key, $value, $type);
            if (!$statement->execute()) {
                $statement->close();
                throw new RuntimeException('Errore salvataggio impostazioni pagamenti: ' . $statement->error);
            }
        }

        $statement->close();
        cvRuntimePaymentSettings($connection, true);
        return cvRuntimePaymentSettings($connection);
    }
}
