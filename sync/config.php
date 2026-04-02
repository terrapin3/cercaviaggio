<?php

return array(
    'db' => array(
        'host' => getenv('CV_DB_HOST') ?: 'localhost',
        'user' => getenv('CV_DB_USER') ?: 'gestbusi_cviaggio',
        'pass' => getenv('CV_DB_PASS') ?: 'N@poli_78',
        'name' => getenv('CV_DB_NAME') ?: 'gestbusi_cviaggio',
        'port' => (int)(getenv('CV_DB_PORT') ?: 3306),
    ),
);
