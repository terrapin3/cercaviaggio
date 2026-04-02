<?php
declare(strict_types=1);

if (!defined('CV_APP_TIMEZONE')) {
    define('CV_APP_TIMEZONE', 'Europe/Rome');
}

if (function_exists('date_default_timezone_set')) {
    @date_default_timezone_set(CV_APP_TIMEZONE);
}

if (!defined('CV_DB_HOST')) {
    define('CV_DB_HOST', 'localhost');
    define('CV_DB_NAME', 'gestbusi_cviaggio');
    define('CV_DB_USER', 'gestbusi_cviaggio');
    define('CV_DB_PASS', 'N@poli_78');
    define('CV_DB_PORT', 3306);
}

if (!function_exists('cvDbConnection')) {
    function cvDbConnection(): mysqli
    {
        static $connection = null;

        if ($connection instanceof mysqli) {
            return $connection;
        }

        $connection = new mysqli(
            CV_DB_HOST,
            CV_DB_USER,
            CV_DB_PASS,
            CV_DB_NAME,
            CV_DB_PORT
        );

        if ($connection->connect_error) {
            throw new RuntimeException('Connessione DB cercaviaggio fallita: ' . $connection->connect_error);
        }

        $connection->set_charset('utf8mb4');
        cvDbApplySessionTimezone($connection);
        return $connection;
    }
}

if (!function_exists('cvDbApplySessionTimezone')) {
    function cvDbApplySessionTimezone(mysqli $connection): void
    {
        try {
            $tz = new DateTimeZone(CV_APP_TIMEZONE);
            $now = new DateTimeImmutable('now', $tz);
            $offsetSeconds = (int) $tz->getOffset($now);
            $sign = $offsetSeconds < 0 ? '-' : '+';
            $abs = abs($offsetSeconds);
            $hours = (int) floor($abs / 3600);
            $minutes = (int) floor(($abs % 3600) / 60);
            $offset = sprintf('%s%02d:%02d', $sign, $hours, $minutes);
        } catch (Throwable $exception) {
            $offset = date('P');
        }

        if (!preg_match('/^[+-][0-9]{2}:[0-9]{2}$/', $offset)) {
            return;
        }

        $safeOffset = $connection->real_escape_string($offset);
        @$connection->query("SET time_zone = '{$safeOffset}'");
    }
}
