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

?>