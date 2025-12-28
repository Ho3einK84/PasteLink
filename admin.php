<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_strict_mode', '1');
    session_start();
}

require_once __DIR__ . '/includes/language.php';
Language::init();

// Handle language change from URL parameter
if (isset($_GET['lang']) && in_array($_GET['lang'], ['en', 'fa'])) {
    Language::setLanguage($_GET['lang']);
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
        
        $pdo = new PDO($dsn, DB_CONFIG['user'], DB_CONFIG['pass'], $options);
    }
    return $pdo;
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

// Handle logout
if (isset($_GET['logout'])) {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
    session_start();
    header('Location: ' . getBaseUrl() . '/admin.php');
    exit;
}

// Handle AJAX requests
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    header('Content-Type: application/json');
    
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $input['action'] ?? '';
    
    try {
        if ($action === 'login') {
            $pass = $input['pass'] ?? '';
            $user = $input['user'] ?? '';
            
            if ($user === ADMIN_USER && password_verify($pass, ADMIN_HASH)) {
                session_regenerate_id(true);
                $_SESSION['admin'] = true;
                $_SESSION['admin_ip'] = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                
                echo json_encode(['status' => 'success']);
            } else {
                http_response_code(401);
                echo json_encode(['status' => 'error', 'message' => Language::get('invalid_credentials')]);
            }
            exit;
        }
        
        if (!isAdmin()) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => Language::get('unauthorized')]);
            exit;
        }
        
        if ($action === 'delete') {
            $id = filter_var($input['id'] ?? 0, FILTER_VALIDATE_INT);
            if ($id === false || $id <= 0) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => Language::get('invalid_id')]);
                exit;
            }
            
            $stmt = getDB()->prepare("DELETE FROM texts WHERE id = ?");
            $stmt->execute([$id]);
            
            if ($stmt->rowCount() > 0) {
                echo json_encode(['status' => 'success']);
            } else {
                http_response_code(404);
                echo json_encode(['status' => 'error', 'message' => Language::get('text_not_found')]);
            }
            exit;
        }
        
        if ($action === 'search') {
            $query = trim($input['query'] ?? '');
            if (strlen($query) < 2) {
                echo json_encode(['status' => 'error', 'message' => Language::get('min_search_chars')]);
                exit;
            }
            
            $stmt = getDB()->prepare("
                SELECT id, code, content, views, created_at, is_encrypted 
                FROM texts 
                WHERE code LIKE ? OR content LIKE ? 
                ORDER BY created_at DESC 
                LIMIT 100
            ");
            $searchTerm = "%$query%";
            $stmt->execute([$searchTerm, $searchTerm]);
            $results = $stmt->fetchAll();
            
            echo json_encode(['status' => 'success', 'data' => $results]);
            exit;
        }
        
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => Language::get('server_error')]);
        error_log($e->getMessage());
        exit;
    }
}

$pageData = ['type' => 'admin_login', 'title' => Language::get('admin_login') . ' - ' . APP_NAME];

if (isAdmin()) {
    $pageData['type'] = 'admin_panel';
    $pageData['title'] = Language::get('admin_panel_title') . ' - ' . APP_NAME;
    
    $db = getDB();
    $stmt = $db->query("SELECT id, code, content, views, created_at, is_encrypted FROM texts ORDER BY created_at DESC LIMIT 500");
    $texts = $stmt->fetchAll();
    
    $pageData['texts'] = $texts;
    
    $statsStmt = $db->query("SELECT COUNT(*) AS total_texts, COALESCE(SUM(views), 0) AS total_views FROM texts");
    $stats = $statsStmt->fetch();
    
    $recentStmt = $db->query("SELECT COUNT(*) AS recent_count FROM texts WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $recentData = $recentStmt->fetch();
    
    $encryptedStmt = $db->query("SELECT COUNT(*) AS encrypted_count FROM texts WHERE is_encrypted = 1");
    $encryptedData = $encryptedStmt->fetch();
    
    $pageData['stats'] = [
        'total_texts' => (int)($stats['total_texts'] ?? 0),
        'total_views' => (int)($stats['total_views'] ?? 0),
        'recent_count' => (int)($recentData['recent_count'] ?? 0),
        'encrypted_count' => (int)($encryptedData['encrypted_count'] ?? 0)
    ];
}

?>
<!DOCTYPE html>
<html lang="<?= Language::getCurrentLang() ?>" dir="<?= Language::getDirection() ?>" class="h-full scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageData['title']) ?></title>
    
    <script src="https://cdn.tailwindcss.com"></script>
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
        
        @keyframes slideIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @keyframes pulse-ring {
            0% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
            70% { box-shadow: 0 0 0 10px rgba(16, 185, 129, 0); }
            100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
        }
        
        .animate-float-slow { animation: float 30s ease-in-out infinite; }
        .animate-float-medium { animation: float 35s ease-in-out infinite; }
        .animate-float-fast { animation: float 25s ease-in-out infinite; }
        .animate-gradient { animation: gradientShift 8s ease infinite; background-size: 200% 200%; }
        .animate-slide-in { animation: slideIn 0.4s ease-out; }
        .animate-pulse-ring { animation: pulse-ring 2s infinite; }

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

        @media (max-width: 640px) {
            html, body {
                background-attachment: scroll;
            }
        }

        textarea {
            resize: none;
        }

        input[type="text"], input[type="password"], input[type="search"] {
            -webkit-appearance: none;
            appearance: none;
        }

        .password-input-container {
            position: relative;
        }

        .toggle-password {
            user-select: none;
        }

        .table-row-hover:hover {
            background-color: rgba(255, 255, 255, 0.15);
        }

        .dark .table-row-hover:hover {
            background-color: rgba(24, 24, 27, 0.4);
        }

        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        ::-webkit-scrollbar-track {
            background: transparent;
        }

        ::-webkit-scrollbar-thumb {
            background: rgba(16, 185, 129, 0.5);
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: rgba(16, 185, 129, 0.7);
        }
    </style>
</head>
<body class="font-vazir relative">
    <div class="fixed inset-0 overflow-hidden pointer-events-none -z-10">
        <div class="absolute top-0 left-0 w-96 h-96 bg-emerald-500/30 dark:bg-emerald-400/20 rounded-full blur-3xl animate-float-slow"></div>
        <div class="absolute bottom-0 right-0 w-80 h-80 bg-green-400/30 dark:bg-green-500/20 rounded-full blur-3xl animate-float-medium"></div>
        <div class="absolute top-1/2 left-1/2 w-72 h-72 bg-teal-400/30 dark:bg-teal-500/20 rounded-full blur-3xl animate-float-fast"></div>
        <div class="absolute top-1/4 right-1/4 w-64 h-64 bg-cyan-400/20 dark:bg-cyan-500/15 rounded-full blur-3xl animate-float-medium"></div>
    </div>

    <?php if ($pageData['type'] === 'admin_panel'): ?>
        <div class="min-h-screen flex flex-col">
            <header class="glass-strong sticky top-0 z-50 shadow-lg border-b border-gray-200/50 dark:border-zinc-700/50">
                <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
                    <div class="flex items-center justify-between gap-4">
                        <div class="flex items-center gap-3 min-w-0">
                            <div class="w-12 h-12 bg-gradient-to-br from-emerald-500 to-green-600 dark:from-emerald-600 dark:to-emerald-800 rounded-xl flex items-center justify-center shadow-xl animate-gradient flex-shrink-0">
                                <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                </svg>
                            </div>
                            <div class="min-w-0">
                                <h1 class="text-xl sm:text-2xl font-black text-gray-800 dark:text-white truncate">
                                    <?= APP_NAME ?>
                                </h1>
                                <p class="text-xs text-gray-600 dark:text-gray-400 font-semibold"><?= Language::get('admin_version') ?></p>
                            </div>
                        </div>
                        
                        <div class="flex items-center gap-2 flex-shrink-0">
                            <a href="<?= getBaseUrl() ?>" class="p-2 rounded-lg glass hover:bg-white/50 dark:hover:bg-zinc-800/50 text-gray-700 dark:text-gray-300 transition-all shadow-md hover:shadow-lg" title="<?= Language::get('home_page') ?>">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                                </svg>
                            </a>

                            <div class="relative">
                                <button id="languageBtn" class="p-2 rounded-lg glass hover:bg-white/50 dark:hover:bg-zinc-800/50 text-gray-700 dark:text-gray-300 transition-all shadow-md hover:shadow-lg" title="<?= Language::get('language') ?>">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5h12M9 3v2m1.048 9.5A18.022 18.022 0 016.412 9m6.088 9h7M11 21l5-10 5 10M12.751 5C11.783 10.77 8.07 15.61 3 18.129" />
                                    </svg>
                                </button>
                                <div id="languageDropdown" class="hidden absolute <?= Language::isRTL() ? 'left-0' : 'right-0' ?> mt-2 w-40 glass-strong rounded-xl shadow-xl z-50 overflow-hidden text-sm">
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

                            <a 
                                href="<?= getBaseUrl() ?>/admin.php?logout=true" 
                                class="px-3 py-2 sm:px-4 bg-gradient-to-r from-red-500 to-red-600 text-white text-sm font-bold rounded-lg hover:shadow-xl hover:from-red-600 hover:to-red-700 transition-all inline-flex items-center gap-2"
                                title="خروج"
                            >
                                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                                </svg>
                                <span class="hidden sm:inline"><?= Language::get('logout') ?></span>
                            </a>
                        </div>
                    </div>
                </div>
            </header>

            <main class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-6 sm:py-8">
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
                    <div class="glass-strong rounded-2xl p-6 hover:shadow-2xl transition-all transform hover:-translate-y-1 animate-slide-in">
                        <div class="flex items-center justify-between">
                            <div>
                                <div class="text-3xl font-black text-emerald-600 dark:text-emerald-400">
                                    <?= number_format($pageData['stats']['total_texts']) ?>
                                </div>
                                <div class="text-sm text-gray-600 dark:text-gray-400 mt-1 font-bold"><?= Language::get('total_texts') ?></div>
                            </div>
                            <div class="w-14 h-14 bg-gradient-to-br from-emerald-100 to-emerald-200 dark:from-emerald-900/30 dark:to-emerald-800/30 rounded-2xl flex items-center justify-center shadow-lg flex-shrink-0">
                                <svg class="w-7 h-7 text-emerald-600 dark:text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                </svg>
                            </div>
                        </div>
                    </div>

                    <div class="glass-strong rounded-2xl p-6 hover:shadow-2xl transition-all transform hover:-translate-y-1 animate-slide-in" style="animation-delay: 50ms;">
                        <div class="flex items-center justify-between">
                            <div>
                                <div class="text-3xl font-black text-green-600 dark:text-green-400">
                                    <?= number_format($pageData['stats']['total_views']) ?>
                                </div>
                                <div class="text-sm text-gray-600 dark:text-gray-400 mt-1 font-bold"><?= Language::get('total_views') ?></div>
                            </div>
                            <div class="w-14 h-14 bg-gradient-to-br from-green-100 to-green-200 dark:from-green-900/30 dark:to-green-800/30 rounded-2xl flex items-center justify-center shadow-lg flex-shrink-0">
                                <svg class="w-7 h-7 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                </svg>
                            </div>
                        </div>
                    </div>

                    <div class="glass-strong rounded-2xl p-6 hover:shadow-2xl transition-all transform hover:-translate-y-1 animate-slide-in" style="animation-delay: 100ms;">
                        <div class="flex items-center justify-between">
                            <div>
                                <div class="text-3xl font-black text-teal-600 dark:text-teal-400">
                                    <?= number_format($pageData['stats']['recent_count']) ?>
                                </div>
                                <div class="text-sm text-gray-600 dark:text-gray-400 mt-1 font-bold"><?= Language::get('last_7_days') ?></div>
                            </div>
                            <div class="w-14 h-14 bg-gradient-to-br from-teal-100 to-teal-200 dark:from-teal-900/30 dark:to-teal-800/30 rounded-2xl flex items-center justify-center shadow-lg flex-shrink-0">
                                <svg class="w-7 h-7 text-teal-600 dark:text-teal-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                </svg>
                            </div>
                        </div>
                    </div>

                    <div class="glass-strong rounded-2xl p-6 hover:shadow-2xl transition-all transform hover:-translate-y-1 animate-slide-in" style="animation-delay: 150ms;">
                        <div class="flex items-center justify-between">
                            <div>
                                <div class="text-3xl font-black text-amber-600 dark:text-amber-400">
                                    <?= number_format($pageData['stats']['encrypted_count']) ?>
                                </div>
                                <div class="text-sm text-gray-600 dark:text-gray-400 mt-1 font-bold"><?= Language::get('encrypted_texts') ?></div>
                            </div>
                            <div class="w-14 h-14 bg-gradient-to-br from-amber-100 to-amber-200 dark:from-amber-900/30 dark:to-amber-800/30 rounded-2xl flex items-center justify-center shadow-lg flex-shrink-0">
                                <svg class="w-7 h-7 text-amber-600 dark:text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                                </svg>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="glass-strong rounded-2xl overflow-hidden shadow-2xl animate-slide-in" style="animation-delay: 200ms;">
                    <div class="p-4 sm:p-6 border-b border-gray-200/50 dark:border-zinc-700/50">
                        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                            <h2 class="text-lg sm:text-xl font-black text-gray-800 dark:text-white"><?= Language::get('texts_list') ?></h2>
                            <div class="w-full sm:w-64 relative">
                                <input 
                                    type="search" 
                                    id="searchInput" 
                                    placeholder="<?= Language::get('search_placeholder') ?>"
                                    class="w-full px-4 py-2 pr-10 glass rounded-xl text-gray-800 dark:text-gray-200 placeholder-gray-500 dark:placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/50 transition-all shadow-md text-sm"
                                >
                                <svg class="w-5 h-5 absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                </svg>
                            </div>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead class="glass sticky top-0 z-10">
                                <tr>
                                    <th class="px-4 py-3 <?= Language::isRTL() ? 'text-right' : 'text-left' ?> text-xs sm:text-sm font-bold text-gray-700 dark:text-gray-300 whitespace-nowrap"><?= Language::get('id') ?></th>
                                    <th class="px-4 py-3 <?= Language::isRTL() ? 'text-right' : 'text-left' ?> text-xs sm:text-sm font-bold text-gray-700 dark:text-gray-300 whitespace-nowrap"><?= Language::get('code') ?></th>
                                    <th class="px-4 py-3 <?= Language::isRTL() ? 'text-right' : 'text-left' ?> text-xs sm:text-sm font-bold text-gray-700 dark:text-gray-300 hidden sm:table-cell"><?= Language::get('preview') ?></th>
                                    <th class="px-4 py-3 <?= Language::isRTL() ? 'text-right' : 'text-left' ?> text-xs sm:text-sm font-bold text-gray-700 dark:text-gray-300 whitespace-nowrap"><?= Language::get('views') ?></th>
                                    <th class="px-4 py-3 <?= Language::isRTL() ? 'text-right' : 'text-left' ?> text-xs sm:text-sm font-bold text-gray-700 dark:text-gray-300 hidden lg:table-cell whitespace-nowrap"><?= Language::get('date') ?></th>
                                    <th class="px-4 py-3 text-center text-xs sm:text-sm font-bold text-gray-700 dark:text-gray-300 whitespace-nowrap"><?= Language::get('actions') ?></th>
                                </tr>
                            </thead>
                            <tbody id="tableBody">
                                <?php if (empty($pageData['texts'])): ?>
                                    <tr>
                                        <td colspan="6" class="p-8 text-center text-gray-500 dark:text-gray-400">
                                            <?= Language::get('no_texts_yet') ?>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($pageData['texts'] as $row): ?>
                                    <tr class="border-t border-gray-200/50 dark:border-zinc-700/50 table-row-hover transition-colors" id="row-<?= $row['id'] ?>" data-code="<?= htmlspecialchars($row['code']) ?>" data-content="<?= htmlspecialchars(mb_substr($row['content'], 0, 100)) ?>">
                                        <td class="px-4 py-3 text-sm font-bold text-gray-800 dark:text-gray-200"><?= number_format($row['id']) ?></td>
                                        <td class="px-4 py-3">
                                            <a 
                                                href="<?= getBaseUrl() ?>/<?= htmlspecialchars($row['code']) ?>" 
                                                target="_blank"
                                                class="inline-flex items-center gap-1 px-2 py-1 glass rounded-lg text-emerald-700 dark:text-emerald-400 text-xs font-bold hover:bg-white/50 dark:hover:bg-zinc-800/50 transition-all hover:shadow-md"
                                            >
                                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                                                </svg>
                                                <?= htmlspecialchars($row['code']) ?>
                                            </a>
                                            <?php if ($row['is_encrypted']): ?>
                                            <span class="inline-flex items-center gap-1 px-2 py-1 glass rounded-lg text-amber-700 dark:text-amber-400 text-xs font-bold ml-2">
                                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                                                </svg>
                                                🔐
                                            </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-3 text-gray-600 dark:text-gray-400 text-sm max-w-xs truncate hidden sm:table-cell">
                                            <?php if ($row['is_encrypted']): ?>
                                                <span class="text-amber-600 dark:text-amber-400 font-bold"><?= Language::get('encrypted_content_preview') ?></span>
                                            <?php else: ?>
                                                <?= htmlspecialchars(mb_substr($row['content'], 0, 40)) ?>
                                                <?= mb_strlen($row['content']) > 40 ? '...' : '' ?>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-3">
                                            <span class="inline-flex items-center gap-1 px-2 py-1 glass rounded-lg text-green-700 dark:text-green-400 text-xs font-bold">
                                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                                </svg>
                                                <?= number_format($row['views']) ?>
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-gray-600 dark:text-gray-400 text-xs hidden lg:table-cell whitespace-nowrap" dir="ltr">
                                            <?= (new DateTime($row['created_at']))->format('Y/m/d H:i') ?>
                                        </td>
                                        <td class="px-4 py-3 text-center">
                                            <button 
                                                onclick="deletePost(<?= $row['id'] ?>)"
                                                class="p-2 glass hover:bg-red-100 dark:hover:bg-red-900/30 text-red-600 dark:text-red-400 rounded-lg transition-all shadow-md hover:shadow-lg"
                                                title="حذف"
                                            >
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                </svg>
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </main>
        </div>

    <?php else: ?>
        <div class="min-h-screen py-8 flex items-center justify-center p-4">
            <div class="w-full max-w-md glass-strong rounded-3xl shadow-2xl overflow-hidden animate-slide-in">
                <div class="p-8">
                    <div class="text-center space-y-3 mb-6">
                        <div class="w-16 h-16 bg-gradient-to-br from-emerald-500 to-green-600 dark:from-emerald-600 dark:to-emerald-800 rounded-2xl flex items-center justify-center mx-auto shadow-xl animate-gradient">
                            <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                            </svg>
                        </div>
                        <h2 class="text-2xl font-black text-gray-800 dark:text-white"><?= Language::get('admin_panel_title') ?></h2>
                        <p class="text-gray-600 dark:text-gray-400 font-semibold"><?= Language::get('admin_version') ?> - <?= Language::get('access_denied') ?></p>
                    </div>

                    <form onsubmit="handleLogin(event)" class="space-y-4">
                        <input type="hidden" id="adminUser" value="<?= ADMIN_USER ?>">
                        
                        <div>
                            <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">🔐 <?= Language::get('password') ?></label>
                            <input 
                                type="password" 
                                id="adminPass" 
                                placeholder="<?= Language::get('password_placeholder') ?>"
                                class="w-full px-4 py-3 glass rounded-xl text-gray-800 dark:text-gray-200 placeholder-gray-500 dark:placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/50 transition-all shadow-inner"
                                required
                                autocomplete="off"
                            >
                        </div>
                        <div id="loginError" class="hidden p-3 glass-strong border-2 border-red-300 dark:border-red-800 rounded-xl text-red-700 dark:text-red-300 text-sm font-bold shadow-md flex items-start gap-2">
                            <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4v2m0 4v2m6.364-2.636l-1.414-1.414m2.828-2.828l-1.414-1.414M4.22 4.22l-1.414 1.414m2.828 2.828L4.22 11.66" />
                            </svg>
                            <span id="errorText"></span>
                        </div>
                        <button 
                            type="submit"
                            class="w-full px-4 py-3 bg-gradient-to-r from-emerald-500 to-green-600 text-white font-black rounded-xl hover:shadow-2xl hover:from-emerald-600 hover:to-green-700 hover:-translate-y-1 active:translate-y-0 transition-all disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            <svg class="w-5 h-5 inline-block <?= Language::isRTL() ? 'ml-2' : 'mr-2' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1" />
                            </svg>
                            <?= Language::get('login') ?>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <script>
        const BASE_URL = <?= json_encode(getBaseUrl()) ?>;
        let allTexts = <?= json_encode($pageData['texts'] ?? []) ?>;

        function notify(msg, type = 'info') {
            const n = document.createElement('div');
            n.className = `fixed top-4 right-4 z-[9999] p-4 rounded-xl shadow-2xl max-w-sm transition-all duration-300 opacity-0 translate-x-10 flex items-start gap-3 ${
                type === 'success' ? 'bg-gradient-to-r from-emerald-500 to-green-600 text-white border-2 border-emerald-400' : 'bg-gradient-to-r from-red-500 to-red-600 text-white border-2 border-red-400'
            }`;
            n.innerHTML = `
                <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="${type === 'success' ? 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z' : 'M12 8v4m0 4v.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z'}" />
                </svg>
                <span class="font-bold">${msg}</span>
            `;
            document.body.appendChild(n);
            
            setTimeout(() => {
                n.style.opacity = '1';
                n.style.transform = 'translateX(0)';
            }, 50);
            
            setTimeout(() => {
                n.style.opacity = '0';
                n.style.transform = 'translateX(10px)';
                setTimeout(() => n.remove(), 300);
            }, 4000);
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

        // Language Dropdown
        const langBtn = document.getElementById('languageBtn');
        const langDropdown = document.getElementById('languageDropdown');
        if (langBtn && langDropdown) {
            langBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                langDropdown.classList.toggle('hidden');
            });
            document.addEventListener('click', () => langDropdown.classList.add('hidden'));
        }

        function setLanguage(lang) {
            const url = new URL(window.location.href);
            url.searchParams.set('lang', lang);
            window.location.href = url.toString();
        }

        const searchInput = document.getElementById('searchInput');
        if (searchInput) {
            let searchTimeout;
            searchInput.addEventListener('input', async (e) => {
                const query = e.target.value.trim();
                clearTimeout(searchTimeout);

                if (!query) {
                    renderTable(allTexts);
                    return;
                }

                if (query.length < 2) return;

                searchTimeout = setTimeout(async () => {
                    try {
                        const res = await fetch(BASE_URL + '/admin.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            body: JSON.stringify({ action: 'search', query })
                        });

                        const data = await res.json();
                        if (data.status === 'success') {
                            renderTable(data.data);
                        }
                    } catch (e) {
                        console.error('Search error:', e);
                    }
                }, 300);
            });
        }

        function renderTable(rows) {
            const tbody = document.getElementById('tableBody');
            if (!tbody) return;

            if (!rows || rows.length === 0) {
                tbody.innerHTML = `<tr><td colspan="6" class="p-8 text-center text-gray-500 dark:text-gray-400">${<?= json_encode(Language::get('no_texts_found')) ?>}</td></tr>`;
                return;
            }

            const currentLang = <?= json_encode(Language::getCurrentLang()) ?>;

            tbody.innerHTML = rows.map(row => `
                <tr class="border-t border-gray-200/50 dark:border-zinc-700/50 table-row-hover transition-colors" id="row-${row.id}">
                    <td class="px-4 py-3 text-sm font-bold text-gray-800 dark:text-gray-200">${(row.id).toLocaleString(currentLang === 'fa' ? 'fa-IR' : 'en-US')}</td>
                    <td class="px-4 py-3">
                        <a 
                            href="${BASE_URL}/${row.code}" 
                            target="_blank"
                            class="inline-flex items-center gap-1 px-2 py-1 glass rounded-lg text-emerald-700 dark:text-emerald-400 text-xs font-bold hover:bg-white/50 dark:hover:bg-zinc-800/50 transition-all hover:shadow-md"
                        >
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                            </svg>
                            ${row.code}
                        </a>
                        ${row.is_encrypted ? `<span class="inline-flex items-center gap-1 px-2 py-1 glass rounded-lg text-amber-700 dark:text-amber-400 text-xs font-bold ${currentLang === 'fa' ? 'ml-2' : 'mr-2'}"><svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" /></svg> 🔐</span>` : ''}
                    </td>
                    <td class="px-4 py-3 text-gray-600 dark:text-gray-400 text-sm max-w-xs truncate hidden sm:table-cell">
                        ${row.is_encrypted ? `<span class="text-amber-600 dark:text-amber-400 font-bold">${<?= json_encode(Language::get('encrypted_content_preview')) ?>}</span>` : (row.content.substring(0, 40) + (row.content.length > 40 ? '...' : ''))}
                    </td>
                    <td class="px-4 py-3">
                        <span class="inline-flex items-center gap-1 px-2 py-1 glass rounded-lg text-green-700 dark:text-green-400 text-xs font-bold">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                            </svg>
                            ${(row.views).toLocaleString(currentLang === 'fa' ? 'fa-IR' : 'en-US')}
                        </span>
                    </td>
                    <td class="px-4 py-3 text-gray-600 dark:text-gray-400 text-xs hidden lg:table-cell whitespace-nowrap" dir="ltr">
                        ${new Date(row.created_at).toLocaleDateString(currentLang === 'fa' ? 'fa-IR' : 'en-US', { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' })}
                    </td>
                    <td class="px-4 py-3 text-center">
                        <button 
                            onclick="deletePost(${row.id})"
                            class="p-2 glass hover:bg-red-100 dark:hover:bg-red-900/30 text-red-600 dark:text-red-400 rounded-lg transition-all shadow-md hover:shadow-lg"
                            title="${<?= json_encode(Language::get('delete')) ?>}"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                            </svg>
                        </button>
                    </td>
                </tr>
            `).join('');
        }

        async function handleLogin(e) {
            e.preventDefault();
            const user = document.getElementById('adminUser').value;
            const pass = document.getElementById('adminPass').value;
            const errorDiv = document.getElementById('loginError');
            const errorText = document.getElementById('errorText');
            const btn = e.target.querySelector('button[type="submit"]');
            
            errorDiv.classList.add('hidden');
            btn.disabled = true;
            const orig = btn.innerHTML;
            btn.innerHTML = `
                <svg class="w-5 h-5 inline-block <?= Language::isRTL() ? 'ml-2' : 'mr-2' ?> animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2" fill="none"></circle>
                    <path fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <span class="<?= Language::isRTL() ? 'mr-2' : 'ml-2' ?> font-black">${<?= json_encode(Language::get('logging_in')) ?>}</span>
            `;
            
            try {
                const res = await fetch(BASE_URL + '/admin.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({ action: 'login', user, pass })
                });
                
                const data = await res.json();
                
                if (data.status === 'success') {
                    notify(<?= json_encode(Language::get('login_success')) ?>, 'success');
                    setTimeout(() => window.location.reload(), 600);
                } else {
                    errorText.textContent = data.message || <?= json_encode(Language::get('login_error')) ?>;
                    errorDiv.classList.remove('hidden');
                    document.getElementById('adminPass').value = '';
                }
            } catch (e) {
                errorText.textContent = <?= json_encode(Language::get('server_connection_error')) ?>;
                errorDiv.classList.remove('hidden');
                console.error(e);
            } finally {
                btn.disabled = false;
                btn.innerHTML = orig;
            }
        }

        async function deletePost(id) {
            if (!confirm(<?= json_encode(Language::get('delete_confirm')) ?>)) return;
            
            const row = document.getElementById(`row-${id}`);
            if (!row) return;
            
            try {
                const res = await fetch(BASE_URL + '/admin.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({ action: 'delete', id }) 
                });
                
                const data = await res.json();
                
                if (data.status === 'success') {
                    row.style.opacity = '0';
                    row.style.transform = 'translateX(-20px)';
                    row.style.transition = 'all 0.3s ease-in-out';
                    setTimeout(() => row.remove(), 300);
                    notify(<?= json_encode(Language::get('text_deleted')) ?>, 'success');
                    allTexts = allTexts.filter(t => t.id !== id);
                } else {
                    notify(data.message || <?= json_encode(Language::get('delete_error')) ?>, 'error');
                }
            } catch (e) {
                notify(<?= json_encode(Language::get('server_connection_error')) ?>, 'error');
                console.error(e);
            }
        }
    </script>
</body>
</html>