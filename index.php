<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', SESSION_TIMEOUT);
    session_start();
}

require_once __DIR__ . '/includes/language.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/cache.php';
require_once __DIR__ . '/includes/texthandler.php';

Language::init();

// Handle language change from URL parameter
if (isset($_GET['lang']) && in_array($_GET['lang'], ['en', 'fa'])) {
    Language::setLanguage($_GET['lang']);
}

Security::init();

function handleApiError(int $code, string $message, ?Throwable $exception = null): void {
    if ($exception) {
        error_log($message . ": " . $exception->getMessage());
    } else {
        error_log($message);
    }

    $isApi = (
        (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
        strpos($_SERVER['REQUEST_URI'] ?? '', '/api-') !== false ||
        (!empty($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false)
    );
    
    http_response_code($code);

    if ($isApi) {
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'error', 
            'message' => Language::get('server_error')
        ]);
        exit;
    }
    
    die('<h1>' . Language::get('error') . '</h1><p>' . Language::get('server_error') . '</p>');
}



function getDB(): PDO {
    static $pdo = null;
    
    if ($pdo === null) {
        $dsn = sprintf(
            "mysql:host=%s;dbname=%s;charset=%s",
            DB_CONFIG['host'],
            DB_CONFIG['name'],
            DB_CONFIG['charset']
        );
        
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        
        try {
            $pdo = new PDO($dsn, DB_CONFIG['user'], DB_CONFIG['pass'], $options);
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Unknown database') !== false) {
                try {
                    $tempDsn = sprintf("mysql:host=%s;charset=%s", DB_CONFIG['host'], DB_CONFIG['charset']);
                    $tempPdo = new PDO($tempDsn, DB_CONFIG['user'], DB_CONFIG['pass'], $options);

                    $tempPdo->exec(
                        "CREATE DATABASE IF NOT EXISTS `" . DB_CONFIG['name'] . "` 
                        CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
                    );
                    $tempPdo = null;

                    $pdo = new PDO($dsn, DB_CONFIG['user'], DB_CONFIG['pass'], $options);

                    $pdo->exec("
                        CREATE TABLE IF NOT EXISTS texts (
                            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                            code VARCHAR(10) UNIQUE NOT NULL,
                            content LONGTEXT NOT NULL,
                            views INT UNSIGNED DEFAULT 0,
                            view_limit INT UNSIGNED DEFAULT NULL,
                            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                            expires_at DATETIME DEFAULT NULL,
                            ip_address VARCHAR(45),
                            is_encrypted TINYINT(1) DEFAULT 0,
                            INDEX idx_code (code),
                            INDEX idx_created_at (created_at),
                            INDEX idx_expires_at (expires_at),
                            INDEX idx_view_limit (view_limit)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                    ");
                } catch (Throwable $ex) {
                    throw new Exception("Database setup failed: " . $ex->getMessage(), 0, $ex);
                }
            } else {
                throw $e;
            }
        }
    }
    return $pdo;
}

function generateCode(): string {
    $db = getDB();
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $codeLength = 6;
    
    do {
        $code = '';
        for ($i = 0; $i < $codeLength; $i++) {
            $code .= $characters[random_int(0, strlen($characters) - 1)];
        }
        
        $stmt = $db->prepare("SELECT id FROM texts WHERE code = ?");
        $stmt->execute([$code]);
    } while ($stmt->fetch());
    
    return $code;
}

function rate_limit(string $action, string $ip, int $limit = 100, int $window = 60): bool {
    return Security::rateLimitCheck($action, $ip, $limit, $window);
}

function getBaseUrl(): string {
    if (APP_URL) return APP_URL;
    
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    $dir = dirname($scriptName);
    
    $dir = str_replace('\\', '/', $dir);
    if ($dir === '/' || $dir === '.') {
        $dir = '';
    }
    
    return rtrim("$protocol://$host$dir", '/');
}

function isAdmin(): bool {
    return isset($_SESSION['admin']) && 
           $_SESSION['admin'] === true && 
           isset($_SESSION['admin_ip']) &&
           $_SESSION['admin_ip'] === ($_SERVER['REMOTE_ADDR'] ?? '');
}

function getRoute(): string {
    $basePath = dirname($_SERVER['SCRIPT_NAME'] ?? '');
    $requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    
    $route = ($basePath === '/' || $basePath === '\\' || $basePath === '.')
        ? trim($requestUri, '/') 
        : trim(str_replace($basePath, '', $requestUri), '/');
        
    $route = preg_replace('/(\/+)/','/', $route);
    
    return explode('/', $route)[0] ?? '';
}

$isAjax = (
    (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
    strpos($_SERVER['REQUEST_URI'] ?? '', '/api-') !== false
);

if ($isAjax) {
    try {
        header('Content-Type: application/json');
        $route = getRoute();
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        
if (!rate_limit($route, $ip, 100, 60)) {
            http_response_code(429);
            echo json_encode(['status' => 'error', 'message' => Language::get('rate_limit')]);
            exit;
        }
        
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        
        switch ($route) {
case 'api-create':
                if (!Security::isValidCSRFToken()) {
                    http_response_code(403);
                    echo json_encode(['status' => 'error', 'message' => Language::get('csrf_token_invalid')]);
                    exit;
                }
                
                $content = Security::sanitize(trim($input['content'] ?? ''), 'html');
                $expiryHours = isset($input['expiry_hours']) ? (int)$input['expiry_hours'] : null;
                $viewLimit = isset($input['view_limit']) ? (int)$input['view_limit'] : null;
                $isEncrypted = (bool)($input['is_encrypted'] ?? false);
                
                if (mb_strlen($content) < 1) {
                    http_response_code(400);
                    echo json_encode(['status' => 'error', 'message' => Language::get('empty_content')]);
                    exit;
                }
                
                if (mb_strlen($content) > MAX_CONTENT_LENGTH) {
                    http_response_code(413);
                    echo json_encode(['status' => 'error', 'message' => Language::get('content_too_long')]);
                    exit;
                }
                
                $textHandler = new TextHandler();
                
                if (!$textHandler->validateExpiryHours($expiryHours)) {
                    http_response_code(400);
                    echo json_encode(['status' => 'error', 'message' => Language::get('invalid_expiry', ['max' => MAX_EXPIRY_HOURS])]);
                    exit;
                }
                
                if (!$textHandler->validateViewLimit($viewLimit)) {
                    http_response_code(400);
                    echo json_encode(['status' => 'error', 'message' => Language::get('invalid_view_limit', ['max' => 1000000])]);
                    exit;
                }
                
                $secureIp = Security::getClientIP();
                $result = $textHandler->createText($content, $expiryHours, $viewLimit, $isEncrypted, $secureIp);
                
                echo json_encode([
                    'status' => 'success',
                    'url' => getBaseUrl() . '/' . $result['code'],
                    'code' => $result['code'],
                    'expires_at' => $result['expires_at'],
                    'view_limit' => $result['view_limit']
                ]);
                exit;
                
case 'api-login':
                $pass = Security::sanitize($input['pass'] ?? '');
                $user = Security::sanitize($input['user'] ?? '');
                
                if ($user === ADMIN_USER && Security::verifyPassword($pass, ADMIN_HASH)) {
                    session_regenerate_id(true);
                    $_SESSION['admin'] = true;
                    $_SESSION['admin_ip'] = $ip;
                    
                    echo json_encode(['status' => 'success']);
                } else {
                    http_response_code(401);
                    echo json_encode(['status' => 'error', 'message' => Language::get('invalid_credentials')]);
                }
                exit;
                
case 'api-delete':
                if (!isAdmin()) {
                    http_response_code(401);
                    echo json_encode(['status' => 'error', 'message' => Language::get('unauthorized')]);
                    exit;
                }
                
                if (!Security::isValidCSRFToken()) {
                    http_response_code(403);
                    echo json_encode(['status' => 'error', 'message' => Language::get('csrf_token_invalid')]);
                    exit;
                }
                
                $id = (int)($input['id'] ?? 0);
                if ($id <= 0) {
                     http_response_code(400);
                     echo json_encode(['status' => 'error', 'message' => Language::get('invalid_id')]);
                     exit;
                }
                
                $textHandler = new TextHandler();
                if ($textHandler->deleteText($id)) {
                     echo json_encode(['status' => 'success']);
                } else {
                     http_response_code(404);
                     echo json_encode(['status' => 'error', 'message' => Language::get('text_not_found')]);
                }
                exit;
        }
        
http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => Language::get('route_not_found')]);
        exit;
        
    } catch (Throwable $e) {
        handleApiError(500, 'API Error', $e);
    }
}

$action = getRoute();
$pageData = ['type' => 'home', 'title' => APP_NAME . ''];

try {
    if ($action === 'admin') {
        header('Location: ' . getBaseUrl() . '/admin.php');
        exit;
} elseif (!empty($action) && !in_array($action, ['index.php', 'admin.php', 'api-create', 'api-login', 'api-delete'])) {
        $textHandler = new TextHandler();
        $text = $textHandler->getText($action);
        
        if ($text) {
            $pageData['type'] = 'view';
            $pageData['text'] = $text;
            $pageData['title'] = Language::get('view') . ' - ' . APP_NAME;
             
            $textHandler->incrementViews($action);
            $text['views']++;
        } else {
            $pageData['type'] = '404';
            $pageData['title'] = Language::get('not_found') . ' - ' . APP_NAME;
        }
    }
} catch (Throwable $e) {
    handleApiError(500, 'Routing Error', $e);
}

?>
<!DOCTYPE html>
<html lang="<?= Language::getCurrentLang() ?>" dir="<?= Language::getDirection() ?>" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageData['title']) ?></title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        'vazir': ['Vazirmatn', 'sans-serif'],
                    }
                }
            }
        }
    </script>
    
    <link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" rel="stylesheet" />
    
    <style>
        @keyframes float {
            0%, 100% { transform: translate(0, 0) rotate(0deg); opacity: 0.15; }
            33% { transform: translate(40px, -40px) rotate(120deg); opacity: 0.25; }
            66% { transform: translate(-30px, 30px) rotate(240deg); opacity: 0.2; }
        }
        
        @keyframes gradientShift {
            0%, 100% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
        }
        
        .animate-float-slow { animation: float 30s ease-in-out infinite; }
        .animate-float-medium { animation: float 35s ease-in-out infinite; }
        .animate-float-fast { animation: float 25s ease-in-out infinite; }
        .animate-gradient { animation: gradientShift 8s ease infinite; background-size: 200% 200%; }

        .glass {
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }
        
        .dark .glass {
            background: rgba(24, 24, 27, 0.7);
            border: 1px solid rgba(63, 63, 70, 0.3);
        }
        
        .glass-strong {
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(25px);
            -webkit-backdrop-filter: blur(25px);
            border: 1px solid rgba(255, 255, 255, 0.5);
        }
        
        .dark .glass-strong {
            background: rgba(24, 24, 27, 0.85);
            border: 1px solid rgba(63, 63, 70, 0.5);
        }

        textarea::-webkit-scrollbar { width: 10px; }
        textarea::-webkit-scrollbar-thumb { 
            background: linear-gradient(to bottom, #10b981, #059669);
            border-radius: 5px;
            border: 2px solid transparent;
            background-clip: padding-box;
        }
        textarea::-webkit-scrollbar-thumb:hover { 
            background: linear-gradient(to bottom, #059669, #047857);
            background-clip: padding-box;
        }
        textarea::-webkit-scrollbar-track { 
            background: rgba(16, 185, 129, 0.1);
            border-radius: 5px;
        }

        .dark textarea::-webkit-scrollbar-thumb { 
            background: linear-gradient(to bottom, #34d399, #10b981);
        }
        .dark textarea::-webkit-scrollbar-thumb:hover { 
            background: linear-gradient(to bottom, #10b981, #059669);
        }
        .dark textarea::-webkit-scrollbar-track { 
            background: rgba(0, 0, 0, 0.3);
        }

        html, body {
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 25%, #6ee7b7 50%, #34d399 75%, #10b981 100%);
            background-attachment: fixed;
            min-height: 100vh;
            overflow-x: hidden;
        }

        html.dark, body.dark {
            background: linear-gradient(135deg, #064e3b 0%, #065f46 25%, #047857 50%, #059669 75%, #0a0f0d 100%);
            background-attachment: fixed;
        }
        
        .password-input-container {
            position: relative;
        }
        
        .toggle-password {
            position: absolute;
            <?= Language::isRTL() ? 'right' : 'left' ?>: 12px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            user-select: none;
        }

        @media (max-width: 640px) {
            html, body {
                background-attachment: scroll;
            }
        }

        textarea {
            resize: none;
        }

        input[type="text"], input[type="password"] {
            -webkit-appearance: none;
            appearance: none;
        }
    </style>
</head>
<body class="h-full font-vazir relative overflow-x-hidden">
    <div class="fixed inset-0 overflow-hidden pointer-events-none -z-10">
        <div class="absolute top-0 left-0 w-96 h-96 bg-emerald-500/30 dark:bg-emerald-400/20 rounded-full blur-3xl animate-float-slow"></div>
        <div class="absolute bottom-0 right-0 w-80 h-80 bg-green-400/30 dark:bg-green-500/20 rounded-full blur-3xl animate-float-medium"></div>
        <div class="absolute top-1/2 left-1/2 w-72 h-72 bg-teal-400/30 dark:bg-teal-500/20 rounded-full blur-3xl animate-float-fast"></div>
        <div class="absolute top-1/4 right-1/4 w-64 h-64 bg-cyan-400/20 dark:bg-cyan-500/15 rounded-full blur-3xl animate-float-medium"></div>
    </div>

    <!-- QR Code Modal -->
    <div id="qrModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 hidden items-center justify-center p-4" onclick="closeQRModal(event)">
        <div class="glass-strong rounded-3xl p-8 max-w-sm w-full shadow-2xl text-center" onclick="event.stopPropagation()">
            <div class="space-y-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-xl font-black text-gray-800 dark:text-white">QR Code</h3>
                    <button onclick="closeQRModal()" class="p-2 rounded-lg glass hover:bg-white/50 dark:hover:bg-zinc-800/50 text-gray-700 dark:text-gray-300 transition-all">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
                
                <div class="flex justify-center">
                    <div id="qrModalCode" class="p-4 bg-white rounded-xl shadow-inner"></div>
                </div>
                
                <p class="text-sm text-gray-600 dark:text-gray-400 font-semibold"><?= Language::get('scan_qr') ?></p>
                
                <button 
                    onclick="closeQRModal()"
                    class="w-full px-4 py-3 bg-gradient-to-r from-emerald-500 to-green-600 text-white font-black rounded-xl hover:shadow-xl hover:from-emerald-600 hover:to-green-700 transition-all animate-gradient"
                >
                    <?= Language::get('close') ?>
                </button>
            </div>
        </div>
    </div>

    <div class="min-h-screen flex items-center justify-center p-4 py-8">
        <div class="w-full max-w-4xl glass-strong rounded-3xl shadow-2xl overflow-hidden">
            
            <header class="flex items-center justify-between p-6 sm:p-8 border-b border-gray-200/50 dark:border-zinc-700/50">
                <div class="flex items-center gap-3 min-w-0">
                    <div class="w-12 h-12 bg-gradient-to-br from-emerald-500 to-green-600 dark:from-emerald-600 dark:to-emerald-800 rounded-xl flex items-center justify-center shadow-xl animate-gradient flex-shrink-0">
                        <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-2xl sm:text-3xl font-black text-gray-800 dark:text-white truncate">
                            <?= APP_NAME ?>
                        </h1>
                        <p class="text-xs text-gray-600 dark:text-gray-400 font-semibold"><?= Language::get('app_version') ?></p>
                    </div>
                </div>
                
                <div class="flex items-center gap-2 flex-shrink-0">
                    <?php if ($pageData['type'] !== 'home'): ?>
                        <a href="<?= getBaseUrl() ?>" class="p-2 rounded-lg glass hover:bg-white/50 dark:hover:bg-zinc-800/50 text-gray-700 dark:text-gray-300 transition-all shadow-md hover:shadow-lg" title="<?= Language::get('home_page') ?>">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                            </svg>
                        </a>
                    <?php endif; ?>
                    
                    <div class="relative">
                        <button id="languageBtn" class="p-2 rounded-lg glass hover:bg-white/50 dark:hover:bg-zinc-800/50 text-gray-700 dark:text-gray-300 transition-all shadow-md hover:shadow-lg" title="<?= Language::get('language') ?>">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5h12M9 3v2m1.048 9.5A18.022 18.022 0 016.412 9m6.088 9h7M11 21l5-10 5 10M12.751 5C11.783 10.77 8.07 15.61 3 18.129" />
                            </svg>
                        </button>
                        <div id="languageDropdown" class="hidden absolute <?= Language::isRTL() ? 'left-0' : 'right-0' ?> mt-2 w-40 glass-strong rounded-xl shadow-xl z-50 overflow-hidden">
                            <button onclick="setLanguage('fa')" class="w-full px-4 py-3 <?= Language::isRTL() ? 'text-right' : 'text-left' ?> hover:bg-white/50 dark:hover:bg-zinc-800/50 text-gray-800 dark:text-gray-200 transition-all flex items-center gap-2">
                                🇮🇷 فارسی
                            </button>
                            <button onclick="setLanguage('en')" class="w-full px-4 py-3 <?= Language::isRTL() ? 'text-right' : 'text-left' ?> hover:bg-white/50 dark:hover:bg-zinc-800/50 text-gray-800 dark:text-gray-200 transition-all flex items-center gap-2">
                                🇬🇧 English
                            </button>
                        </div>
                    </div>
                     
                    <button id="themeBtn" class="p-2 rounded-lg glass hover:bg-white/50 dark:hover:bg-zinc-800/50 text-gray-700 dark:text-gray-300 transition-all shadow-md hover:shadow-lg" title="<?= Language::get('toggle_theme') ?>">
                        <svg id="sunIcon" class="w-5 h-5 hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z" />
                        </svg>
                        <svg id="moonIcon" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
                        </svg>
                    </button>
                </div>
            </header>

            <main class="p-6 sm:p-8">
                <?php if ($pageData['type'] === 'home'): ?>
                    <div class="space-y-6">
                        <div class="space-y-2">
                            <div class="flex items-center justify-between gap-2">
                                <label class="text-sm font-bold text-gray-700 dark:text-gray-300"><?= Language::get('write_text') ?></label>
                                <span id="charCounter" class="text-sm text-gray-600 dark:text-gray-400 font-semibold whitespace-nowrap">0 <?= Language::get('characters') ?></span>
                            </div>
                            <textarea 
                                id="pasteContent" 
                                placeholder="<?= Language::get('text_placeholder') ?>"
                                class="w-full min-h-[400px] max-h-[600px] overflow-y-auto p-4 sm:p-6 glass rounded-2xl text-gray-800 dark:text-gray-200 placeholder-gray-500 dark:placeholder-gray-500 focus:outline-none focus:ring-4 focus:ring-emerald-500/50 focus:border-emerald-500 transition-all duration-300 shadow-inner"
                            ></textarea>
                            <span class="text-xs text-gray-600 dark:text-gray-400 font-semibold"><?= Language::get('max_characters', ['max' => number_format(MAX_CONTENT_LENGTH)]) ?></span>
                        </div>

                        <div class="glass rounded-2xl p-4 space-y-3">
                            <div class="flex items-center gap-2">
                                <input type="checkbox" id="enablePassword" class="w-5 h-5 rounded accent-emerald-600 cursor-pointer">
                                <label for="enablePassword" class="text-sm font-bold text-gray-700 dark:text-gray-300 cursor-pointer select-none">
                                    <?= Language::get('enable_password') ?>
                                </label>
                            </div>
                            
                            <div id="passwordFields" class="hidden space-y-3">
                                <div class="password-input-container">
                                    <input 
                                        type="password" 
                                        id="textPassword" 
                                        placeholder="<?= Language::get('password_placeholder') ?>"
                                        class="w-full px-4 py-3 glass rounded-xl text-gray-800 dark:text-gray-200 placeholder-gray-500 dark:placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/50 transition-all shadow-inner <?= Language::isRTL() ? 'pl-12' : 'pr-12' ?>"
                                    >
                                    <button type="button" class="toggle-password text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200 transition-colors" onclick="togglePasswordVisibility('textPassword', this)">
                                        <svg class="w-5 h-5 eye-open" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                        </svg>
                                        <svg class="w-5 h-5 eye-closed hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" />
                                        </svg>
                                    </button>
                                </div>
                                <p class="text-xs text-amber-600 dark:text-amber-400 font-bold flex items-start gap-2">
                                    <svg class="w-4 h-4 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                    </svg>
                                    <span><?= Language::get('password_warning') ?></span>
                                </p>
</div>
                        </div>

                        <div class="glass rounded-2xl p-4 space-y-3">
                            <div class="text-sm font-bold text-gray-700 dark:text-gray-300 mb-3"><?= Language::get('advanced_options') ?></div>
                            
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label for="expiryHours" class="text-sm font-semibold text-gray-700 dark:text-gray-300"><?= Language::get('expiry_hours') ?></label>
                                    <input 
                                        type="number" 
                                        id="expiryHours" 
                                        min="1" 
                                        max="<?= MAX_EXPIRY_HOURS ?>"
                                        placeholder="<?= Language::get('expiry_placeholder') ?>"
                                        class="w-full px-3 py-2 glass rounded-lg text-gray-800 dark:text-gray-200 placeholder-gray-500 dark:placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/50 transition-all shadow-inner"
                                    >
                                </div>
                                
                                <div>
                                    <label for="viewLimit" class="text-sm font-semibold text-gray-700 dark:text-gray-300"><?= Language::get('view_limit') ?></label>
                                    <input 
                                        type="number" 
                                        id="viewLimit" 
                                        min="1" 
                                        max="1000000"
                                        placeholder="<?= Language::get('view_limit_placeholder') ?>"
                                        class="w-full px-3 py-2 glass rounded-lg text-gray-800 dark:text-gray-200 placeholder-gray-500 dark:placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/50 transition-all shadow-inner"
                                    >
                                </div>
                            </div>
                            
                            <div class="flex items-center gap-2">
                                <input type="checkbox" id="singleUse" class="w-5 h-5 rounded accent-emerald-600 cursor-pointer">
                                <label for="singleUse" class="text-sm font-bold text-gray-700 dark:text-gray-300 cursor-pointer select-none">
                                    <?= Language::get('single_use') ?>
                                </label>
                            </div>
                            
                            <div id="sharingInfo" class="hidden space-y-2 text-xs text-amber-600 dark:text-amber-400 font-semibold">
                                <div id="expiryInfo" class="hidden flex items-center gap-2">
                                    <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                    <span id="expiryText"></span>
                                </div>
                                <div id="viewLimitInfo" class="hidden flex items-center gap-2">
                                    <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                    </svg>
                                    <span id="viewLimitText"></span>
                                </div>
                                <div id="singleUseInfo" class="hidden flex items-center gap-2">
                                    <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                    <span><?= Language::get('single_use_info') ?></span>
                                </div>
                            </div>
                        </div>

                        <button
                            id="saveBtn" 
                            onclick="savePaste()"
                            class="w-full sm:w-auto px-8 py-4 bg-gradient-to-r from-emerald-500 to-green-600 text-white font-black rounded-2xl hover:shadow-2xl hover:from-emerald-600 hover:to-green-700 hover:-translate-y-1 active:translate-y-0 transition-all disabled:opacity-50 disabled:cursor-not-allowed shadow-lg animate-gradient"
                        >
                            <svg class="w-5 h-5 inline-block <?= Language::isRTL() ? 'mr-2' : 'ml-2' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
                            </svg>
                            <?= Language::get('create_share_link') ?>
                        </button>

                        <div class="glass-strong border-2 border-emerald-300/50 dark:border-emerald-700/50 rounded-2xl p-4 flex items-start gap-3 text-emerald-900 dark:text-emerald-300 shadow-md">
                            <svg class="w-6 h-6 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                            </svg>
                            <p class="text-sm font-semibold">
                                <?= Language::get('security_note') ?>
                            </p>
                        </div>
                    </div>

                    <div id="resultOverlay" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 hidden items-center justify-center p-4">
                        <div class="glass-strong rounded-3xl p-8 max-w-lg w-full shadow-2xl text-center">
                            <div class="space-y-6">
                                <div class="w-20 h-20 bg-gradient-to-br from-emerald-500 to-green-600 rounded-full flex items-center justify-center mx-auto shadow-xl animate-gradient">
                                    <svg class="w-10 h-10 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" />
                                    </svg>
                                </div>
                                
                                <div>
                                    <h3 class="text-xl sm:text-2xl font-black text-gray-800 dark:text-white mb-2"><?= Language::get('link_ready') ?></h3>
                                    <p class="text-gray-600 dark:text-gray-400 font-semibold"><?= Language::get('copy_share') ?></p>
                                </div>

                                <div class="flex justify-center my-4">
                                    <div id="qrcode" class="p-3 bg-white rounded-xl shadow-inner"></div>
                                </div>
                                <p class="text-xs text-gray-500 dark:text-gray-400 font-semibold mb-2"><?= Language::get('scan_qr') ?></p>

                                <div class="space-y-3">
                                    <input 
                                        type="text" 
                                        id="finalLink" 
                                        readonly 
                                        class="w-full px-4 py-3 glass rounded-xl text-gray-800 dark:text-gray-200 font-mono text-center font-bold shadow-inner text-sm break-all"
                                    >
                                    <button 
                                        onclick="copyLink(event.target)"
                                        class="w-full px-4 py-3 bg-gradient-to-r from-emerald-500 to-green-600 text-white font-black rounded-xl hover:shadow-xl hover:from-emerald-600 hover:to-green-700 transition-all flex items-center justify-center gap-2 animate-gradient"
                                    >
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                                        </svg>
                                        <?= Language::get('copy_link') ?>
                                    </button>
                                </div>

                                <button 
                                    onclick="resetApp()"
                                    class="w-full px-4 py-3 glass hover:bg-white/70 dark:hover:bg-zinc-800/70 text-gray-800 dark:text-gray-200 font-black rounded-xl hover:shadow-xl transition-all"
                                >
                                    <svg class="w-5 h-5 inline-block <?= Language::isRTL() ? 'mr-2' : 'ml-2' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                                    </svg>
                                    <?= Language::get('create_new') ?>
                                </button>
                            </div>
                        </div>
                    </div>

                <?php elseif ($pageData['type'] === 'view'): ?>
                    <?php if ($pageData['text']['is_encrypted']): ?>
                        <div id="passwordPrompt" class="space-y-6">
                            <div class="text-center space-y-3">
                                <div class="w-20 h-20 bg-gradient-to-br from-amber-500 to-orange-600 dark:from-amber-600 dark:to-orange-700 rounded-full flex items-center justify-center mx-auto shadow-xl animate-gradient">
                                    <svg class="w-10 h-10 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                                    </svg>
                                </div>
                                <h2 class="text-2xl font-black text-gray-800 dark:text-white"><?= Language::get('encrypted_content') ?></h2>
                                <p class="text-gray-600 dark:text-gray-400 font-semibold"><?= Language::get('enter_password') ?></p>
                            </div>

                            <div class="space-y-4">
                                <div class="password-input-container">
                                    <input 
                                        type="password" 
                                        id="decryptPassword" 
                                        placeholder="<?= Language::get('password') ?>"
                                        class="w-full px-4 py-3 glass rounded-xl text-gray-800 dark:text-gray-200 placeholder-gray-500 dark:placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/50 transition-all shadow-inner <?= Language::isRTL() ? 'pl-12' : 'pr-12' ?>"
                                        onkeypress="if(event.key === 'Enter') decryptContent()"
                                    >
                                    <button type="button" class="toggle-password text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200 transition-colors" onclick="togglePasswordVisibility('decryptPassword', this)">
                                        <svg class="w-5 h-5 eye-open" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                        </svg>
                                        <svg class="w-5 h-5 eye-closed hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" />
                                        </svg>
                                    </button>
                                </div>
                                <div id="decryptError" class="hidden p-3 glass-strong border-2 border-red-300 dark:border-red-800 rounded-xl text-red-700 dark:text-red-300 text-sm font-bold shadow-md"></div>
                                <button 
                                    onclick="decryptContent()"
                                    class="w-full px-4 py-3 bg-gradient-to-r from-emerald-500 to-green-600 text-white font-black rounded-xl hover:shadow-xl hover:from-emerald-600 hover:to-green-700 transition-all animate-gradient"
                                >
                                    <svg class="w-5 h-5 inline-block ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 11V7a4 4 0 118 0m-4 8v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2z" />
                                    </svg>
                                    <svg class="w-5 h-5 inline-block <?= Language::isRTL() ? 'mr-2' : 'ml-2' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 11V7a4 4 0 118 0m-4 8v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2z" />
                                    </svg>
                                    <?= Language::get('decrypt_view') ?>
                                </button>
                            </div>

                            <div class="glass-strong border-2 border-amber-300/50 dark:border-amber-700/50 rounded-2xl p-4 flex items-start gap-3 text-amber-900 dark:text-amber-300 shadow-md">
                                <svg class="w-6 h-6 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <p class="text-sm font-semibold">
                                    <?= Language::get('aes_protection') ?>
                                </p>
                            </div>
                        </div>

                        <div id="decryptedContent" class="hidden space-y-6">
                    <?php endif; ?>
                    
                    <?php if (!$pageData['text']['is_encrypted']): ?>
                        <div class="space-y-6">
                    <?php endif; ?>
                        
                            <div class="glass-strong rounded-2xl p-4 space-y-3 shadow-md">
                                <div class="flex flex-wrap items-center gap-4 text-sm text-gray-700 dark:text-gray-300 font-semibold">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4" />
                                        </svg>
                                        <strong><?= Language::get('code_label') ?></strong> 
                                        <a 
                                            href="<?= getBaseUrl() ?>/<?= $pageData['text']['code'] ?>" 
                                            target="_blank"
                                            class="px-2 py-1 bg-gradient-to-r from-emerald-500 to-green-600 text-white rounded-lg text-xs font-black hover:underline shadow-md animate-gradient"
                                        >
                                            <?= htmlspecialchars($pageData['text']['code']) ?>
                                        </a>
                                        <button 
                                            onclick="showQRCode('<?= getBaseUrl() ?>/<?= $pageData['text']['code'] ?>')"
                                            class="p-1.5 bg-gradient-to-r from-emerald-500 to-green-600 text-white rounded-lg hover:shadow-lg transition-all"
                                            title="<?= Language::get('show_qr') ?>"
                                        >
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z" />
                                            </svg>
                                        </button>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                        </svg>
                                        <strong><?= Language::get('views_label') ?></strong> <?= number_format($pageData['text']['views']) ?>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                        </svg>
                                        <strong><?= Language::get('created_label') ?></strong> 
                                        <span dir="ltr"><?= (new DateTime($pageData['text']['created_at']))->format('Y/m/d H:i:s') ?></span>
                                    </div>
                                    <?php if ($pageData['text']['is_encrypted']): ?>
                                    <div class="flex items-center gap-2 text-emerald-700 dark:text-emerald-400">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                                        </svg>
                                        <strong><?= Language::get('encrypted') ?></strong>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="space-y-2">
                                <div class="flex items-center justify-between gap-2">
                                    <label class="text-sm font-bold text-gray-700 dark:text-gray-300 flex items-center gap-2">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                        </svg>
                                        <?= Language::get('content') ?>
                                    </label>
                                    <span class="text-sm text-gray-600 dark:text-gray-400 font-semibold whitespace-nowrap" id="viewCharCount">
                                        <?= number_format(mb_strlen($pageData['text']['content'])) ?> <?= Language::get('characters') ?>
                                    </span>
                                </div>
                                <textarea 
                                    id="viewContentTextarea"
                                    readonly 
                                    class="w-full min-h-[400px] max-h-[600px] overflow-y-auto p-4 sm:p-6 glass rounded-2xl text-gray-800 dark:text-gray-200 font-mono focus:outline-none shadow-inner"
                                    data-encrypted="<?= $pageData['text']['is_encrypted'] ? htmlspecialchars($pageData['text']['content']) : '' ?>"
                                ><?= $pageData['text']['is_encrypted'] ? '' : htmlspecialchars($pageData['text']['content']) ?></textarea>
                            </div>

                            <div class="flex flex-col sm:flex-row gap-3">
                                <button 
                                    onclick="copyContent(event.target)"
                                    class="flex-1 px-6 py-4 bg-gradient-to-r from-emerald-500 to-green-600 text-white font-black rounded-2xl hover:shadow-2xl hover:from-emerald-600 hover:to-green-700 hover:-translate-y-1 active:translate-y-0 transition-all flex items-center justify-center animate-gradient"
                                >
                                    <svg class="w-5 h-5 inline-block <?= Language::isRTL() ? 'mr-2' : 'ml-2' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                                    </svg>
                                    <?= Language::get('copy_text') ?>
                                </button>
                                <a 
                                    href="<?= getBaseUrl() ?>" 
                                    class="flex-1 px-6 py-4 glass hover:bg-white/70 dark:hover:bg-zinc-800/70 text-gray-800 dark:text-gray-200 font-black rounded-2xl hover:shadow-2xl hover:-translate-y-1 active:translate-y-0 transition-all text-center flex items-center justify-center"
                                >
                                    <svg class="w-5 h-5 inline-block <?= Language::isRTL() ? 'mr-2' : 'ml-2' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                                    </svg>
                                    <?= Language::get('create_new_text') ?>
                                </a>
                            </div>
                        
                    <?php if (!$pageData['text']['is_encrypted']): ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($pageData['text']['is_encrypted']): ?>
                        </div>
                    <?php endif; ?>

                <?php else: ?>
                    <div class="text-center py-16 space-y-6">
                        <div class="text-8xl opacity-50">🔍</div>
                        <h2 class="text-3xl font-black text-gray-800 dark:text-white"><?= Language::get('not_found') ?></h2>
                        <p class="text-gray-600 dark:text-gray-400 text-lg font-semibold"><?= Language::get('link_expired') ?></p>
                        <a 
                            href="<?= getBaseUrl() ?>" 
                            class="inline-flex items-center gap-2 px-6 py-3 bg-gradient-to-r from-emerald-500 to-green-600 text-white font-black rounded-2xl hover:shadow-2xl hover:from-emerald-600 hover:to-green-700 hover:-translate-y-1 transition-all animate-gradient"
                        >
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                            </svg>
                            <?= Language::get('back') ?>
                        </a>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/crypto-js/4.1.1/crypto-js.min.js"></script>
    <script>
        const BASE_URL = <?= json_encode(getBaseUrl()) ?>;
        const MAX_LENGTH = <?= MAX_CONTENT_LENGTH ?>;
        const CURRENT_LANG = '<?= Language::getCurrentLang() ?>';
        
        const i18n = {
            enter_text: <?= json_encode(Language::get('enter_text')) ?>,
            content_too_long: <?= json_encode(Language::get('content_too_long')) ?>,
            password_too_short: <?= json_encode(Language::get('min_password_length')) ?>,
            link_creation_error: <?= json_encode(Language::get('link_creation_error')) ?>,
            server_error: <?= json_encode(Language::get('server_error')) ?>,
            link_copied: <?= json_encode(Language::get('link_copied')) ?>,
            copy_error: <?= json_encode(Language::get('copy_error')) ?>,
            content_copied: <?= json_encode(Language::get('content_copied')) ?>,
            decryption_success: <?= json_encode(Language::get('decryption_success')) ?>,
            no_content_to_copy: <?= json_encode(Language::get('no_content_to_copy')) ?>,
            wrong_password: <?= json_encode(Language::get('wrong_password')) ?>,
            decryption_error: <?= json_encode(Language::get('decryption_error')) ?>,
            enter_password_prompt: <?= json_encode(Language::get('enter_password_prompt')) ?>,
            creating: <?= json_encode(Language::get('creating')) ?>,
            characters: <?= json_encode(Language::get('characters')) ?>
        };

        function setLanguage(lang) {
            const url = new URL(window.location);
            url.searchParams.set('lang', lang);
            window.location.href = url.toString();
        }

        function toggleLanguageDropdown() {
            const dropdown = document.getElementById('languageDropdown');
            dropdown?.classList.toggle('hidden');
        }

        document.getElementById('languageBtn')?.addEventListener('click', toggleLanguageDropdown);
        document.addEventListener('click', (e) => {
            if (!e.target.closest('#languageBtn') && !e.target.closest('#languageDropdown')) {
                document.getElementById('languageDropdown')?.classList.add('hidden');
            }
        });

        function notify(msg, type = 'info') {
            const isRTL = document.documentElement.dir === 'rtl';
            const n = document.createElement('div');
            const translateClass = isRTL ? 'translate-x-10' : '-translate-x-10';
            const translateEnd = isRTL ? '-translate-x-10' : 'translate-x-10';
            const positionClass = isRTL ? 'left-4' : 'right-4';
            
            n.className = `fixed top-4 ${positionClass} z-[9999] p-4 rounded-xl shadow-2xl max-w-sm transition-all duration-300 opacity-0 ${translateClass} ${
                type === 'success' ? 'bg-gradient-to-r from-emerald-500 to-green-600 text-white border-2 border-emerald-400' : 'bg-gradient-to-r from-red-500 to-red-600 text-white border-2 border-red-400'
            }`;
            n.innerHTML = `<span class="font-bold">${msg}</span>`;
            document.body.appendChild(n);
            
            setTimeout(() => {
                n.style.opacity = '1';
                n.style.transform = 'translateX(0)';
            }, 50);
            
            setTimeout(() => {
                n.style.opacity = '0';
                n.style.transform = translateEnd;
                setTimeout(() => n.remove(), 300);
            }, 4000);
        }
        
        function showSuccess(btn, text = '✓ ' + i18n.link_copied) {
            const el = btn.closest('button') || btn.closest('a');
            if (!el) return;

            const orig = el.innerHTML;
            el.innerHTML = `<span class="flex items-center font-black">${text}</span>`;
            
            setTimeout(() => {
                el.innerHTML = orig;
            }, 2000);
        }

        function showQRCode(url) {
            const modal = document.getElementById('qrModal');
            const qrContainer = document.getElementById('qrModalCode');
            
            qrContainer.innerHTML = '';
            
            new QRCode(qrContainer, {
                text: url,
                width: 200,
                height: 200,
                colorDark: '#000000',
                colorLight: '#ffffff',
                correctLevel: QRCode.CorrectLevel.H
            });
            
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        function closeQRModal(event) {
            const modal = document.getElementById('qrModal');
            if (event && event.target !== modal) return;
            
            modal.classList.add('hidden');
            modal.classList.remove('flex');
            document.getElementById('qrModalCode').innerHTML = '';
        }

        function updateTheme(isDark) {
            const sun = document.getElementById('sunIcon');
            const moon = document.getElementById('moonIcon');
            if (isDark) {
                sun?.classList.remove('hidden');
                moon?.classList.add('hidden');
            } else {
                sun?.classList.add('hidden');
                moon?.classList.remove('hidden');
            }
        }

        function toggleTheme() {
            const isDark = document.documentElement.classList.contains('dark');
            if (isDark) {
                document.documentElement.classList.remove('dark');
                localStorage.setItem('pastelink_theme', 'light');
                updateTheme(false);
            } else {
                document.documentElement.classList.add('dark');
                localStorage.setItem('pastelink_theme', 'dark');
                updateTheme(true);
            }
        }
        
        const savedTheme = localStorage.getItem('pastelink_theme') || 
                          (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        if (savedTheme === 'dark') {
            document.documentElement.classList.add('dark');
            updateTheme(true);
        } else {
            updateTheme(false);
        }

        document.getElementById('themeBtn')?.addEventListener('click', toggleTheme);

        const textarea = document.getElementById('pasteContent');
        const charCounter = document.getElementById('charCounter');

        if (textarea && charCounter) {
            textarea.addEventListener('input', () => {
                const count = textarea.value.length;
                const locale = CURRENT_LANG === 'fa' ? 'fa-IR' : 'en-US';
                charCounter.textContent = count.toLocaleString(locale) + ' ' + i18n.characters;
                
                if (count > MAX_LENGTH) {
                    charCounter.classList.remove('text-gray-600', 'dark:text-gray-400');
                    charCounter.classList.add('text-red-600', 'dark:text-red-400', 'font-black');
                } else {
                    charCounter.classList.add('text-gray-600', 'dark:text-gray-400');
                    charCounter.classList.remove('text-red-600', 'dark:text-red-400', 'font-black');
                }
            });
            textarea.dispatchEvent(new Event('input'));
        }

        const enablePassword = document.getElementById('enablePassword');
        const passwordFields = document.getElementById('passwordFields');
        
        if (enablePassword && passwordFields) {
            enablePassword.addEventListener('change', () => {
                if (enablePassword.checked) {
                    passwordFields.classList.remove('hidden');
                    document.getElementById('textPassword').focus();
                } else {
                    passwordFields.classList.add('hidden');
                    document.getElementById('textPassword').value = '';
                }
            });
        }

        function togglePasswordVisibility(inputId, button) {
            const input = document.getElementById(inputId);
            const eyeOpen = button.querySelector('.eye-open');
            const eyeClosed = button.querySelector('.eye-closed');
            
            if (input.type === 'password') {
                input.type = 'text';
                eyeOpen.classList.add('hidden');
                eyeClosed.classList.remove('hidden');
            } else {
                input.type = 'password';
                eyeOpen.classList.remove('hidden');
                eyeClosed.classList.add('hidden');
            }
        }

        async function savePaste() {
            const btn = document.getElementById('saveBtn');
            const content = document.getElementById('pasteContent').value;
            const enablePass = document.getElementById('enablePassword')?.checked;
            const password = document.getElementById('textPassword')?.value;
            
            if (!content.trim()) {
                notify(i18n.enter_text, 'error');
                return;
            }

            if (content.length > MAX_LENGTH) {
                notify(i18n.content_too_long, 'error');
                return;
            }

            if (enablePass && (!password || password.length < 4)) {
                notify(i18n.password_too_short, 'error');
                document.getElementById('textPassword').focus();
                return;
            }
            
            btn.disabled = true;
            const orig = btn.innerHTML;
            const marginClass = CURRENT_LANG === 'fa' ? 'ml-2' : 'mr-2';
            btn.innerHTML = `
                <div class="inline-block w-5 h-5 border-2 border-white border-t-transparent rounded-full animate-spin"></div>
                <span class="${marginClass} font-black">${i18n.creating}</span>
            `;
            
            try {
                let finalContent = content;
                let isEncrypted = false;

                if (enablePass && password) {
                    finalContent = CryptoJS.AES.encrypt(content, password).toString();
                    isEncrypted = true;
                }

                const res = await fetch(BASE_URL + '/api-create', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({ 
                        content: finalContent,
                        is_encrypted: isEncrypted
                    }) 
                });
                
                const data = await res.json();
                
                if (data.status === 'success') {
                    document.getElementById('finalLink').value = data.url;
                    
                    // Clear previous QR code
                    document.getElementById('qrcode').innerHTML = "";
                    
                    // Generate new QR code
                    new QRCode(document.getElementById("qrcode"), {
                        text: data.url,
                        width: 128,
                        height: 128,
                        colorDark : "#000000",
                        colorLight : "#ffffff",
                        correctLevel : QRCode.CorrectLevel.H
                    });

                    document.getElementById('resultOverlay').classList.remove('hidden');
                    document.getElementById('resultOverlay').classList.add('flex');
                    document.getElementById('pasteContent').value = '';
                    document.getElementById('pasteContent').dispatchEvent(new Event('input'));
                    if (document.getElementById('enablePassword')) {
                        document.getElementById('enablePassword').checked = false;
                        document.getElementById('passwordFields').classList.add('hidden');
                        document.getElementById('textPassword').value = '';
                    }
                } else {
                    notify(data.message || i18n.link_creation_error, 'error');
                }
            } catch (e) {
                notify(i18n.server_error, 'error');
                console.error(e);
            } finally {
                btn.disabled = false;
                btn.innerHTML = orig;
            }
        }

        function copyLink(btn) {
            const input = document.getElementById('finalLink');
            input.select();
            input.setSelectionRange(0, 99999);
            
            navigator.clipboard.writeText(input.value).then(() => {
                showSuccess(btn, '✓ ' + i18n.link_copied);
                notify(i18n.link_copied, 'success');
            }).catch(() => {
                notify(i18n.copy_error, 'error');
            });
        }

        function resetApp() {
            document.getElementById('resultOverlay').classList.add('hidden');
            document.getElementById('resultOverlay').classList.remove('flex');
            document.getElementById('qrcode').innerHTML = "";
            
            const homeTextarea = document.getElementById('pasteContent');
            if (homeTextarea) {
                homeTextarea.value = '';
                homeTextarea.dispatchEvent(new Event('input'));
                homeTextarea.focus();
            }
        }

        function copyContent(btn) {
            const textarea = document.getElementById('viewContentTextarea');
            if (!textarea || !textarea.value) {
                notify(i18n.no_content_to_copy, 'error');
                return;
            }

            textarea.select();
            textarea.setSelectionRange(0, 99999);
            
            navigator.clipboard.writeText(textarea.value).then(() => {
                showSuccess(btn, '✓ ' + i18n.text_copied);
                notify(i18n.content_copied, 'success');
            }).catch(() => {
                notify(i18n.copy_error, 'error');
            });
        }

        async function decryptContent() {
            const password = document.getElementById('decryptPassword').value;
            const textarea = document.getElementById('viewContentTextarea');
            const encryptedData = textarea.getAttribute('data-encrypted');
            const errorDiv = document.getElementById('decryptError');
            
            if (!password) {
                errorDiv.textContent = i18n.enter_password_prompt;
                errorDiv.classList.remove('hidden');
                return;
            }

            errorDiv.classList.add('hidden');
            
            try {
                const decrypted = CryptoJS.AES.decrypt(encryptedData, password);
                const plaintext = decrypted.toString(CryptoJS.enc.Utf8);
                
                if (!plaintext) {
                    errorDiv.textContent = i18n.wrong_password;
                    errorDiv.classList.remove('hidden');
                    return;
                }
                
                textarea.value = plaintext;
                const locale = CURRENT_LANG === 'fa' ? 'fa-IR' : 'en-US';
                document.getElementById('viewCharCount').textContent = plaintext.length.toLocaleString(locale) + ' ' + i18n.characters;
                
                document.getElementById('passwordPrompt').classList.add('hidden');
                document.getElementById('decryptedContent').classList.remove('hidden');
                
                notify(i18n.decryption_success, 'success');
            } catch (e) {
                errorDiv.textContent = i18n.wrong_password;
                errorDiv.classList.remove('hidden');
                console.error(e);
            }
        }

        document.addEventListener('keydown', (e) => {
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter' && document.getElementById('pasteContent')) {
                e.preventDefault();
                document.getElementById('saveBtn')?.click();
            }
            
            if (e.key === 'Escape') {
                const overlay = document.getElementById('resultOverlay');
                const modal = document.getElementById('qrModal');
                
                if (overlay && overlay.classList.contains('flex')) {
                    resetApp();
                }
                
                if (modal && modal.classList.contains('flex')) {
                    closeQRModal();
                }
            }
        });

        if (textarea) {
            textarea.focus();
        }
    </script>
</body>
</html>