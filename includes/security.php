<?php
declare(strict_types=1);

class Security {
    private static ?string $csrfToken = null;
    
    public static function init(): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        self::hardenedSessionConfig();
        
        if (SECURITY_HEADERS) {
            self::sendSecurityHeaders();
        }
    }
    
    private static function hardenedSessionConfig(): void {
        ini_set('session.cookie_httponly', '1');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.cookie_secure', SESSION_SECURE ? '1' : '0');
        ini_set('session.cookie_samesite', 'Strict');
        ini_set('session.gc_maxlifetime', SESSION_TIMEOUT);
        ini_set('session.cookie_lifetime', SESSION_TIMEOUT);
        
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        if (!isset($_SESSION['initiated'])) {
            session_regenerate_id(true);
            $_SESSION['initiated'] = true;
        }
        
        if (isset($_SESSION['ip_address']) && $_SESSION['ip_address'] !== ($_SERVER['REMOTE_ADDR'] ?? '')) {
            session_destroy();
            session_start();
            session_regenerate_id(true);
        }
        
        $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'] ?? '';
        $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
    }
    
    public static function sendSecurityHeaders(): void {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Content-Security-Policy: default-src \'self\'; script-src \'self\' \'unsafe-inline\' https://cdn.tailwindcss.com https://cdnjs.cloudflare.com; style-src \'self\' \'unsafe-inline\' https://cdn.tailwindcss.com https://cdn.jsdelivr.net; img-src \'self\' data:; font-src \'self\' https://cdn.jsdelivr.net; connect-src \'self\'; frame-ancestors \'none\';');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
        
        if (!headers_sent()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
        }
    }
    
    public static function generateCSRFToken(): string {
        if (self::$csrfToken === null) {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            
            if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            }
            
            self::$csrfToken = $_SESSION['csrf_token'];
        }
        
        return self::$csrfToken;
    }
    
    public static function validateCSRFToken(string $token): bool {
        if (!CSRF_TOKEN_ENABLED) {
            return true;
        }
        
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        return isset($_SESSION['csrf_token']) && 
               hash_equals($_SESSION['csrf_token'], $token);
    }
    
    public static function getCSRFInput(): string {
        $token = self::generateCSRFToken();
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }
    
    public static function sanitize(string $input, string $type = 'string'): string {
        return match ($type) {
            'email' => filter_var($input, FILTER_SANITIZE_EMAIL),
            'url' => filter_var($input, FILTER_SANITIZE_URL),
            'int' => filter_var($input, FILTER_SANITIZE_NUMBER_INT),
            'float' => filter_var($input, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION),
            'html' => htmlspecialchars($input, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            default => htmlspecialchars($input, ENT_QUOTES, 'UTF-8')
        };
    }
    
    public static function validate(string $input, string $type): bool {
        return match ($type) {
            'email' => filter_var($input, FILTER_VALIDATE_EMAIL) !== false,
            'url' => filter_var($input, FILTER_VALIDATE_URL) !== false,
            'int' => filter_var($input, FILTER_VALIDATE_INT) !== false,
            'float' => filter_var($input, FILTER_VALIDATE_FLOAT) !== false,
            'alpha' => ctype_alpha($input),
            'alnum' => ctype_alnum($input),
            'code' => preg_match('/^[a-zA-Z0-9]{6,10}$/', $input) === 1,
            default => !empty(trim($input))
        };
    }
    
    public static function isValidCSRFToken(): bool {
        if (!CSRF_TOKEN_ENABLED) {
            return true;
        }
        
        // Check POST data, JSON body, or header
        $token = $_POST['csrf_token'] ?? 
                 ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '') ?: 
                 (json_decode(file_get_contents('php://input'), true)['csrf_token'] ?? '');
        
        return self::validateCSRFToken($token);
    }
    
    public static function rateLimitCheck(string $action, string $ip, int $limit = 100, int $window = 60): bool {
        if (isset($_SESSION['admin']) && $_SESSION['admin'] === true) {
            return true;
        }
        
        $key = "rate_{$action}_" . md5($ip);
        
        if (!isset($_SESSION[$key])) {
            $_SESSION[$key] = [];
        }
        
        $now = time();
        $_SESSION[$key] = array_filter($_SESSION[$key], fn($t) => $now - $t < $window);
        
        if (count($_SESSION[$key]) >= $limit) {
            return false;
        }
        
        $_SESSION[$key][] = $now;
        return true;
    }
    
    public static function getClientIP(): string {
        $ipKeys = ['HTTP_CF_CONNECTING_IP', 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR'];
        
        foreach ($ipKeys as $key) {
            if (!empty($_SERVER[$key])) {
                $ips = explode(',', $_SERVER[$key]);
                $ip = trim($ips[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
    
    public static function generateSecureCode(int $length = 6): string {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $code = '';
        
        for ($i = 0; $i < $length; $i++) {
            $code .= $characters[random_int(0, strlen($characters) - 1)];
        }
        
        return $code;
    }
    
    public static function hashPassword(string $password): string {
        return password_hash($password, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost' => 4,
            'threads' => 3
        ]);
    }
    
    public static function verifyPassword(string $password, string $hash): bool {
        return password_verify($password, $hash);
    }
}