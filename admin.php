<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_strict_mode', '1');
    session_start();
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
                echo json_encode(['status' => 'error', 'message' => 'نام کاربری یا رمز عبور اشتباه است']);
            }
            exit;
        }
        
        if (!isAdmin()) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => 'دسترسی غیرمجاز']);
            exit;
        }
        
        if ($action === 'delete') {
            $id = filter_var($input['id'] ?? 0, FILTER_VALIDATE_INT);
            if ($id === false || $id <= 0) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'شناسه نامعتبر']);
                exit;
            }
            
            $stmt = getDB()->prepare("DELETE FROM texts WHERE id = ?");
            $stmt->execute([$id]);
            
            if ($stmt->rowCount() > 0) {
                echo json_encode(['status' => 'success']);
            } else {
                http_response_code(404);
                echo json_encode(['status' => 'error', 'message' => 'متن یافت نشد']);
            }
            exit;
        }
        
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'خطای سرور']);
        error_log($e->getMessage());
        exit;
    }
}

$pageData = ['type' => 'admin_login', 'title' => 'ورود - ' . APP_NAME];

if (isAdmin()) {
    $pageData['type'] = 'admin_panel';
    $pageData['title'] = 'پنل مدیریت - ' . APP_NAME;
    
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
<html lang="fa" dir="rtl" class="h-full">
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

        body {
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 25%, #6ee7b7 50%, #34d399 75%, #10b981 100%);
        }

        body.dark {
            background: linear-gradient(135deg, #064e3b 0%, #065f46 25%, #047857 50%, #059669 75%, #0a0f0d 100%);
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

    <?php if ($pageData['type'] === 'admin_panel'): ?>
        <div class="min-h-screen">
            <header class="glass-strong sticky top-0 z-50 shadow-lg border-b border-gray-200/50 dark:border-zinc-700/50">
                <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-12 h-12 bg-gradient-to-br from-emerald-500 to-green-600 dark:from-emerald-600 dark:to-emerald-800 rounded-xl flex items-center justify-center shadow-xl animate-gradient">
                                <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                </svg>
                            </div>
                            <div>
                                <h1 class="text-xl sm:text-2xl font-black text-gray-800 dark:text-white">
                                    <?= APP_NAME ?>
                                </h1>
                                <p class="text-xs text-gray-600 dark:text-gray-400 font-semibold">پنل مدیریت v2.0</p>
                            </div>
                        </div>
                        
                        <div class="flex items-center gap-2">
                            <a href="<?= getBaseUrl() ?>" class="p-2 rounded-lg glass hover:bg-white/50 dark:hover:bg-zinc-800/50 text-gray-700 dark:text-gray-300 transition-all shadow-md" title="صفحه اصلی">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                                </svg>
                            </a>
                            
                            <button id="themeBtn" class="p-2 rounded-lg glass hover:bg-white/50 dark:hover:bg-zinc-800/50 text-gray-700 dark:text-gray-300 transition-all shadow-md">
                                <svg id="sunIcon" class="w-5 h-5 hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z" />
                                </svg>
                                <svg id="moonIcon" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
                                </svg>
                            </button>

                            <a 
                                href="<?= getBaseUrl() ?>/admin.php?logout=true" 
                                class="px-3 py-2 sm:px-4 bg-gradient-to-r from-red-500 to-red-600 text-white text-sm font-bold rounded-lg hover:shadow-xl hover:from-red-600 hover:to-red-700 transition-all inline-flex items-center gap-2 animate-gradient"
                            >
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                                </svg>
                                <span class="hidden sm:inline">خروج</span>
                            </a>
                        </div>
                    </div>
                </div>
            </header>

            <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 sm:py-8">
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
                    <div class="glass-strong rounded-2xl p-6 hover:shadow-2xl transition-all transform hover:-translate-y-1">
                        <div class="flex items-center justify-between">
                            <div>
                                <div class="text-3xl font-black text-emerald-600 dark:text-emerald-400">
                                    <?= number_format($pageData['stats']['total_texts']) ?>
                                </div>
                                <div class="text-sm text-gray-600 dark:text-gray-400 mt-1 font-bold">کل متن‌ها</div>
                            </div>
                            <div class="w-14 h-14 bg-gradient-to-br from-emerald-100 to-emerald-200 dark:from-emerald-900/30 dark:to-emerald-800/30 rounded-2xl flex items-center justify-center shadow-lg">
                                <svg class="w-7 h-7 text-emerald-600 dark:text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                </svg>
                            </div>
                        </div>
                    </div>

                    <div class="glass-strong rounded-2xl p-6 hover:shadow-2xl transition-all transform hover:-translate-y-1">
                        <div class="flex items-center justify-between">
                            <div>
                                <div class="text-3xl font-black text-green-600 dark:text-green-400">
                                    <?= number_format($pageData['stats']['total_views']) ?>
                                </div>
                                <div class="text-sm text-gray-600 dark:text-gray-400 mt-1 font-bold">کل بازدید</div>
                            </div>
                            <div class="w-14 h-14 bg-gradient-to-br from-green-100 to-green-200 dark:from-green-900/30 dark:to-green-800/30 rounded-2xl flex items-center justify-center shadow-lg">
                                <svg class="w-7 h-7 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                </svg>
                            </div>
                        </div>
                    </div>

                    <div class="glass-strong rounded-2xl p-6 hover:shadow-2xl transition-all transform hover:-translate-y-1">
                        <div class="flex items-center justify-between">
                            <div>
                                <div class="text-3xl font-black text-teal-600 dark:text-teal-400">
                                    <?= number_format($pageData['stats']['recent_count']) ?>
                                </div>
                                <div class="text-sm text-gray-600 dark:text-gray-400 mt-1 font-bold">۷ روز اخیر</div>
                            </div>
                            <div class="w-14 h-14 bg-gradient-to-br from-teal-100 to-teal-200 dark:from-teal-900/30 dark:to-teal-800/30 rounded-2xl flex items-center justify-center shadow-lg">
                                <svg class="w-7 h-7 text-teal-600 dark:text-teal-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                </svg>
                            </div>
                        </div>
                    </div>

                    <div class="glass-strong rounded-2xl p-6 hover:shadow-2xl transition-all transform hover:-translate-y-1">
                        <div class="flex items-center justify-between">
                            <div>
                                <div class="text-3xl font-black text-amber-600 dark:text-amber-400">
                                    <?= number_format($pageData['stats']['encrypted_count']) ?>
                                </div>
                                <div class="text-sm text-gray-600 dark:text-gray-400 mt-1 font-bold">رمزگذاری شده</div>
                            </div>
                            <div class="w-14 h-14 bg-gradient-to-br from-amber-100 to-amber-200 dark:from-amber-900/30 dark:to-amber-800/30 rounded-2xl flex items-center justify-center shadow-lg">
                                <svg class="w-7 h-7 text-amber-600 dark:text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                                </svg>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="glass-strong rounded-2xl overflow-hidden shadow-2xl">
                    <div class="p-4 sm:p-6 border-b border-gray-200/50 dark:border-zinc-700/50">
                        <h2 class="text-lg sm:text-xl font-black text-gray-800 dark:text-white">لیست متن‌ها</h2>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead class="glass">
                                <tr>
                                    <th class="px-4 py-3 text-right text-xs sm:text-sm font-bold text-gray-700 dark:text-gray-300">ID</th>
                                    <th class="px-4 py-3 text-right text-xs sm:text-sm font-bold text-gray-700 dark:text-gray-300">کد</th>
                                    <th class="px-4 py-3 text-right text-xs sm:text-sm font-bold text-gray-700 dark:text-gray-300 hidden sm:table-cell">پیش‌نمایش</th>
                                    <th class="px-4 py-3 text-right text-xs sm:text-sm font-bold text-gray-700 dark:text-gray-300">بازدید</th>
                                    <th class="px-4 py-3 text-right text-xs sm:text-sm font-bold text-gray-700 dark:text-gray-300 hidden lg:table-cell">تاریخ</th>
                                    <th class="px-4 py-3 text-center text-xs sm:text-sm font-bold text-gray-700 dark:text-gray-300">عملیات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($pageData['texts'])): ?>
                                    <tr>
                                        <td colspan="6" class="p-8 text-center text-gray-500 dark:text-gray-400">
                                            هنوز متنی ثبت نشده
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($pageData['texts'] as $row): ?>
                                    <tr class="border-t border-gray-200/50 dark:border-zinc-700/50 hover:bg-white/30 dark:hover:bg-zinc-800/30 transition-colors" id="row-<?= $row['id'] ?>">
                                        <td class="px-4 py-3 text-sm font-bold text-gray-800 dark:text-gray-200"><?= number_format($row['id']) ?></td>
                                        <td class="px-4 py-3">
                                            <a 
                                                href="<?= getBaseUrl() ?>/<?= htmlspecialchars($row['code']) ?>" 
                                                target="_blank"
                                                class="inline-flex items-center gap-1 px-2 py-1 glass rounded-lg text-emerald-700 dark:text-emerald-400 text-xs font-bold hover:bg-white/50 dark:hover:bg-zinc-800/50 transition-all"
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
                                                <span class="text-amber-600 dark:text-amber-400 font-bold">محتوای رمزگذاری شده</span>
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
                                        <td class="px-4 py-3 text-gray-600 dark:text-gray-400 text-xs hidden lg:table-cell" dir="ltr">
                                            <?= (new DateTime($row['created_at']))->format('Y/m/d H:i') ?>
                                        </td>
                                        <td class="px-4 py-3 text-center">
                                            <button 
                                                onclick="deletePost(<?= $row['id'] ?>)"
                                                class="p-2 glass hover:bg-red-100 dark:hover:bg-red-900/30 text-red-600 dark:text-red-400 rounded-lg transition-all shadow-md"
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
        <div class="min-h-full flex items-center justify-center p-4">
            <div class="w-full max-w-md glass-strong rounded-3xl shadow-2xl overflow-hidden">
                <div class="p-8">
                    <div class="text-center space-y-3 mb-6">
                        <div class="w-16 h-16 bg-gradient-to-br from-emerald-500 to-green-600 dark:from-emerald-600 dark:to-emerald-800 rounded-2xl flex items-center justify-center mx-auto shadow-xl animate-gradient">
                            <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                            </svg>
                        </div>
                        <h2 class="text-2xl font-black text-gray-800 dark:text-white">پنل مدیریت</h2>
                        <p class="text-gray-600 dark:text-gray-400 font-semibold">برای دسترسی وارد شوید</p>
                    </div>

                    <form onsubmit="handleLogin(event)" class="space-y-4">
                        <input type="hidden" id="adminUser" value="<?= ADMIN_USER ?>">
                        
                        <div>
                            <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">رمز عبور</label>
                            <input 
                                type="password" 
                                id="adminPass" 
                                placeholder="رمز عبور"
                                class="w-full px-4 py-3 glass rounded-xl text-gray-800 dark:text-gray-200 placeholder-gray-500 dark:placeholder-gray-500 focus:outline-none focus:ring-4 focus:ring-emerald-500/50 focus:border-emerald-500 shadow-inner"
                                required
                            >
                        </div>
                        <div id="loginError" class="hidden p-3 glass-strong border-2 border-red-300 dark:border-red-800 rounded-xl text-red-700 dark:text-red-300 text-sm font-bold shadow-md"></div>
                        <button 
                            type="submit"
                            class="w-full px-4 py-3 bg-gradient-to-r from-emerald-500 to-green-600 text-white font-black rounded-xl hover:shadow-2xl hover:from-emerald-600 hover:to-green-700 hover:-translate-y-1 active:translate-y-0 transition-all disabled:opacity-50 disabled:cursor-not-allowed animate-gradient"
                        >
                            <svg class="w-5 h-5 inline-block ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1" />
                            </svg>
                            ورود
                        </button>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <script>
        const BASE_URL = <?= json_encode(getBaseUrl()) ?>;

        function notify(msg, type = 'info') {
            const n = document.createElement('div');
            n.className = `fixed top-4 right-4 z-[9999] p-4 rounded-xl shadow-2xl max-w-sm transition-all duration-300 opacity-0 translate-x-10 ${
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

        async function handleLogin(e) {
            e.preventDefault();
            const user = document.getElementById('adminUser').value;
            const pass = document.getElementById('adminPass').value;
            const errorDiv = document.getElementById('loginError');
            const btn = e.target.querySelector('button[type="submit"]');
            
            errorDiv.classList.add('hidden');
            btn.disabled = true;
            const orig = btn.innerHTML;
            btn.innerHTML = `
                <div class="inline-block w-5 h-5 border-2 border-white border-t-transparent rounded-full animate-spin"></div>
                <span class="mr-2 font-black">در حال ورود...</span>
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
                    notify('ورود موفق', 'success');
                    setTimeout(() => window.location.reload(), 500);
                } else {
                    errorDiv.textContent = data.message;
                    errorDiv.classList.remove('hidden');
                    document.getElementById('adminPass').value = '';
                }
            } catch (e) {
                errorDiv.textContent = 'خطا در ارتباط با سرور';
                errorDiv.classList.remove('hidden');
                console.error(e);
            } finally {
                btn.disabled = false;
                btn.innerHTML = orig;
            }
        }

        async function deletePost(id) {
            if (!confirm('⚠️ آیا مطمئن هستید؟\n\nاین عملیات قابل بازگشت نیست!')) return;
            
            const row = document.getElementById(`row-${id}`);
            
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
                    row.style.transform = 'translateY(10px)';
                    row.style.transition = 'all 0.3s ease-in-out';
                    setTimeout(() => row.remove(), 300);
                    notify('حذف شد', 'success');
                } else {
                    notify(data.message || 'خطا در حذف', 'error');
                }
            } catch (e) {
                notify('خطا در ارتباط با سرور', 'error');
                console.error(e);
            }
        }
    </script>
</body>
</html>