<?php
declare(strict_types=1);

if (!defined('CV_AUTH_SESSION_NAME')) {
    define('CV_AUTH_SESSION_NAME', 'cv_auth_cercaviaggio');
}

if (!defined('CV_AUTH_SESSION_TTL')) {
    define('CV_AUTH_SESSION_TTL', 7200);
}

if (!defined('CV_AUTH_SESSION_PATH')) {
    define('CV_AUTH_SESSION_PATH', '/');
}

if (!defined('CV_AUTH_SESSION_DOMAIN')) {
    define('CV_AUTH_SESSION_DOMAIN', '');
}

if (!defined('CV_GOOGLE_CLIENT_ID')) {
    define('CV_GOOGLE_CLIENT_ID', '');
}

if (!defined('CV_GOOGLE_CLIENT_IDS')) {
    define('CV_GOOGLE_CLIENT_IDS', []);
}

if (!defined('CV_PARTNER_LEAD_NOTIFY_EMAIL')) {
    define('CV_PARTNER_LEAD_NOTIFY_EMAIL', '');
}

if (!defined('CV_AUTH_VERIFY_TTL_SECONDS')) {
    define('CV_AUTH_VERIFY_TTL_SECONDS', 86400);
}

if (!defined('CV_AUTH_RESET_TTL_SECONDS')) {
    define('CV_AUTH_RESET_TTL_SECONDS', 3600);
}

if (!defined('CV_AUTH_MAIL_SLOT')) {
    define('CV_AUTH_MAIL_SLOT', 1);
}

if (!defined('CV_AUTH_NEWSLETTER_MAIL_SLOT')) {
    define('CV_AUTH_NEWSLETTER_MAIL_SLOT', 2);
}

if (!defined('CV_AUTH_BRAND_NAME')) {
    define('CV_AUTH_BRAND_NAME', 'cercaviaggio');
}

if (!defined('CV_AUTH_DEFAULT_FROM_EMAIL')) {
    define('CV_AUTH_DEFAULT_FROM_EMAIL', 'noreply@fillbus.it');
}
