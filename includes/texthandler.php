<?php
declare(strict_types=1);

class TextHandler {
    private PDO $db;
    
    public function __construct() {
        require_once __DIR__ . '/database.php';
        $this->db = Database::getInstance();
    }
    
    public function createText(string $content, ?int $expiryHours = null, ?int $viewLimit = null, bool $isEncrypted = false, string $ip = ''): array {
        // Don't cache create operations - each text needs unique code
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
            'id' => (int)$this->db->lastInsertId(),
            'code' => $code,
            'expires_at' => $expiresAt,
            'view_limit' => $viewLimit
        ];
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
        // Get codes before deletion for cache invalidation
        $stmt = $this->db->prepare("
            SELECT code FROM texts 
            WHERE (expires_at IS NOT NULL AND expires_at <= NOW())
            OR (view_limit IS NOT NULL AND views >= view_limit)
        ");
        $stmt->execute();
        $codes = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $stmt = $this->db->prepare("
            DELETE FROM texts 
            WHERE (expires_at IS NOT NULL AND expires_at <= NOW())
            OR (view_limit IS NOT NULL AND views >= view_limit)
        ");
        
        $stmt->execute();
        $deleted = $stmt->rowCount();
        
        // Invalidate cache for deleted texts only
        foreach ($codes as $code) {
            Cache::delete('text_code_' . $code);
        }
        
        return $deleted;
    }
    
    public function deleteText(int $id): bool {
        // Get code before deletion for cache invalidation
        $stmt = $this->db->prepare("SELECT code FROM texts WHERE id = ?");
        $stmt->execute([$id]);
        $text = $stmt->fetch();
        
        $stmt = $this->db->prepare("DELETE FROM texts WHERE id = ?");
        $result = $stmt->execute([$id]);
        
        if ($result && $text) {
            // Invalidate only the specific cache entry
            Cache::delete('text_code_' . $text['code']);
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