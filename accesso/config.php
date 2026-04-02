<?php
declare(strict_types=1);

return [
    // Se vuoto, nessun filtro IP.
    'allowed_ips' => [],

    // Durata sessione backend.
    'session_ttl' => 604800,

    // Nome sessione dedicato al backend.
    'session_name' => 'cercaviaggio_back',

    // Chiave per cifrare le password consultabili nel backend.
    // Mantienila stabile: se la cambi, le password gia cifrate non saranno piu leggibili.
    'credential_cipher_key' => getenv('CV_ACCESSO_CIPHER_KEY') ?: 'cercaviaggio-accesso-2026-keep-this-key-private-and-stable',

    'brand_name' => 'Cercaviaggio',
    'brand_subtitle' => 'Backend multiazienda',

    // Utenti backend iniziali. Duplica questo blocco per le prossime aziende.
    'accounts' => [
        [
            'email' => 'admin@cercaviaggio.local',
            'name' => 'Amministratore Cercaviaggio',
            'password_hash' => '$2y$12$39STi7owZLkcZkIgJSESZOG93VacZ10J8I3XjfP4PstpMEIHkrkGW',
            'role' => 'admin',
            'providers' => ['*'],
            'active' => true,
        ],
        [
            'email' => 'curcio@cercaviaggio.local',
            'name' => 'Autolinee Curcio',
            'password_hash' => '$2y$12$9AuSW9opVXm8cQqOJxfvWejgfhNsTZkB4cjTlboCmok7WM33TDeHa',
            'role' => 'provider',
            'providers' => ['curcio'],
            'active' => true,
        ],
    ],
];
