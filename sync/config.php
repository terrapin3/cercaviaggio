<?php

return array(
    'db' => array(
        'host' => getenv('CV_DB_HOST') ?: 'cvmbexdcercavg.mysql.db',
        'user' => getenv('CV_DB_USER') ?: 'cvmbexdcercavg',
        'pass' => getenv('CV_DB_PASS') ?: 'Napoli1978',
        'name' => getenv('CV_DB_NAME') ?: 'cvmbexdcercavg',
        'port' => (int)(getenv('CV_DB_PORT') ?: 3306),
    ),
);
