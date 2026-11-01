<?php

define('DB_NAME', getenv('TM_DB_NAME') ?: 'turnimedici_demo');
define('DB_USER', getenv('TM_DB_USER') ?: '');
define('DB_PASSWORD', getenv('TM_DB_PASSWORD') ?: '');
define('DB_HOST', getenv('TM_DB_HOST') ?: 'localhost');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'] as $key) {
    $value = getenv($key);
    if (!$value) {
        throw new RuntimeException('Missing environment variable: ' . $key);
    }
    define($key, $value);
}

$table_prefix = 'tmdemo_';

define('WP_ENVIRONMENT_TYPE', 'local');
define('WP_DEBUG', false);
define('DISABLE_WP_CRON', true);
define('TM_DEMO_MODE', true);

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

require_once ABSPATH . 'wp-settings.php';
