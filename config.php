<?php
declare(strict_types=1);

// Database Configuration
const DB_CONFIG = [
    'host'    => 'localhost',
    'name'    => 'pastelink',
    'user'    => 'pastelink',
    'pass'    => 'password',
    'charset' => 'utf8mb4'
];

// App Configuration
const ADMIN_USER = 'admin';
const ADMIN_HASH = '$2a$12$NzcQ25v3S1F8o7zno5SPZeEzx65VwT7uCWB0wIKmjy3rQoFFWEIg2';
const APP_NAME = 'PasteLink';
const APP_URL  = ''; // Leave empty to auto-detect
const MAX_CONTENT_LENGTH = 100000;

// v3 Configuration
const CACHE_ENABLED = true;
const SESSION_TIMEOUT = 3600; // 1 hour
const SESSION_SECURE = true;
const CSRF_TOKEN_ENABLED = true;
const SECURITY_HEADERS = true;
const MAX_EXPIRY_HOURS = 168; // 7 days

?>