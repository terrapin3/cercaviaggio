<?php

return array(
    // Imposta un token lungo e segreto (o usa env CV_SYNC_WEB_TOKEN).
    'access_token' => getenv('CV_SYNC_WEB_TOKEN') ?: '3301',

    // Se vuoto, accesso da qualsiasi IP (non consigliato in produzione).
    // Esempio: array('127.0.0.1', '::1')
    'allowed_ips' => array(),

    // Durata login sessione in secondi.
    'session_ttl' => 3600,
);

