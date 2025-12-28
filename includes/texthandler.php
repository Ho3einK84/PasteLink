<?php
declare(strict_types=1);

class TextHandler {
    private PDO $db;
    
    public function __construct() {
        $this->db = $this->getDB();
    }
    
    private function getDB(): PDO {
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
    
    public function createText(string $content, ?int $expiryHours = null, ?int $viewLimit = null, bool $isEncrypted = false, string $ip = ''): array {
        $cacheKey = 'text_' . md5($content . $expiryHours . $viewLimit);
        
        return Cache::remember($cacheKey, function() use ($content, $expiryHours, $viewLimit, $isEncrypted, $ip) {
            $code = $this->generateUniqueCode();
            
            $expiresAt = null;
            if ($expiryHours !== null && $expiryHours > 0) {
                $expiresAt = date('Y-m-d H:i:s', time() + ($expiryHours * 3600));
            }
            
            $stmt = $this->db->prepare("
                INSERT INTO texts (code, content, ip_address, is_encrypted, expires_at, view_limit) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $code,
                $content,
                $ip,
                $isEncrypted ? 1 : 0,
                $expiresAt,
                $viewLimit
            ]);
            
            return [
                'id' => $this->db->lastInsertId(),
                'code' => $code,
                'expires_at' => $expiresAt,
                'view_limit' => $viewLimit
            ];
        }, 300);
    }
    
    public function getText(string $code): ?array {
        $cacheKey = 'text_code_' . $code;
        
        $text = Cache::get($cacheKey);
        
        if ($text === null) {
            $stmt = $this->db->prepare("
                SELECT * FROM texts 
                WHERE code = ? 
                AND (expires_at IS NULL OR expires_at > NOW())
                AND (view_limit IS NULL OR views < view_limit)
            ");
            
            $stmt->execute([$code]);
            $text = $stmt->fetch();
            
            if ($text) {
                Cache::set($cacheKey, $text, 300);
            }
        }
        
        return $text ?: null;
    }
    
    public function incrementViews(string $code): bool {
        $stmt = $this->db->prepare("
            UPDATE texts 
            SET views = views + 1 
            WHERE code = ?
        ");
        
        $result = $stmt->execute([$code]);
        
        if ($result) {
            Cache::delete('text_code_' . $code);
        }
        
        return $result;
    }
    
    public function shouldDeleteText(array $text): bool {
        if ($text['expires_at'] && strtotime($text['expires_at']) <= time()) {
            return true;
        }
        
        if ($text['view_limit'] && $text['views'] >= $text['view_limit']) {
            return true;
        }
        
        return false;
    }
    
    public function deleteExpiredTexts(): int {
        $stmt = $this->db->prepare("
            DELETE FROM texts 
            WHERE (expires_at IS NOT NULL AND expires_at <= NOW())
            OR (view_limit IS NOT NULL AND views >= view_limit)
        ");
        
        $stmt->execute();
        $deleted = $stmt->rowCount();
        
        if ($deleted > 0) {
            Cache::clear();
        }
        
        return $deleted;
    }
    
    public function deleteText(int $id): bool {
        $stmt = $this->db->prepare("DELETE FROM texts WHERE id = ?");
        $result = $stmt->execute([$id]);
        
        if ($result) {
            Cache::clear();
        }
        
        return $result;
    }
    
    public function getAllTexts(int $limit = 50, int $offset = 0): array {
        $stmt = $this->db->prepare("
            SELECT * FROM texts 
            ORDER BY created_at DESC 
            LIMIT ? OFFSET ?
        ");
        
        $stmt->execute([$limit, $offset]);
        return $stmt->fetchAll();
    }
    
    public function getTextStats(): array {
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total_texts,
                SUM(views) as total_views,
                COUNT(CASE WHEN expires_at IS NOT NULL THEN 1 END) as expiring_texts,
                COUNT(CASE WHEN view_limit IS NOT NULL THEN 1 END) as limited_texts
            FROM texts
        ");
        
        $stmt->execute();
        return $stmt->fetch() ?: [
            'total_texts' => 0,
            'total_views' => 0,
            'expiring_texts' => 0,
            'limited_texts' => 0
        ];
    }
    
    private function generateUniqueCode(): string {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $codeLength = 6;
        $maxAttempts = 100;
        $attempts = 0;
        
        do {
            $code = '';
            for ($i = 0; $i < $codeLength; $i++) {
                $code .= $characters[random_int(0, strlen($characters) - 1)];
            }
            
            $stmt = $this->db->prepare("SELECT id FROM texts WHERE code = ?");
            $stmt->execute([$code]);
            $exists = $stmt->fetch();
            
            $attempts++;
        } while ($exists && $attempts < $maxAttempts);
        
        if ($attempts >= $maxAttempts) {
            throw new Exception("Unable to generate unique code after {$maxAttempts} attempts");
        }
        
        return $code;
    }
    
    public function validateExpiryHours(?int $hours): bool {
        if ($hours === null) {
            return true;
        }
        
        return $hours > 0 && $hours <= MAX_EXPIRY_HOURS;
    }
    
    public function validateViewLimit(?int $limit): bool {
        if ($limit === null) {
            return true;
        }
        
        return $limit > 0 && $limit <= 1000000;
    }
}