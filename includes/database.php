<?php
declare(strict_types=1);

/**
 * Database Singleton Class
 * Provides a single database connection instance across the application
 */
class Database {
    private static ?PDO $instance = null;
    
    private function __construct() {
        // Private constructor to prevent direct instantiation
    }
    
    private function __clone() {
        // Prevent cloning
    }
    
    public function __wakeup(): void {
        throw new Exception("Cannot unserialize singleton");
    }
    
    /**
     * Get database connection instance
     * 
     * @return PDO Database connection
     * @throws PDOException If connection fails
     */
    public static function getInstance(): PDO {
        if (self::$instance === null) {
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
                PDO::ATTR_PERSISTENT => false, // Set to true for persistent connections if needed
            ];
            
            try {
                self::$instance = new PDO($dsn, DB_CONFIG['user'], DB_CONFIG['pass'], $options);
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'Unknown database') !== false) {
                    try {
                        // Try to create database if it doesn't exist
                        $tempDsn = sprintf("mysql:host=%s;charset=%s", DB_CONFIG['host'], DB_CONFIG['charset']);
                        $tempPdo = new PDO($tempDsn, DB_CONFIG['user'], DB_CONFIG['pass'], $options);

                        $tempPdo->exec(
                            "CREATE DATABASE IF NOT EXISTS `" . DB_CONFIG['name'] . "` 
                            CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
                        );
                        $tempPdo = null;

                        self::$instance = new PDO($dsn, DB_CONFIG['user'], DB_CONFIG['pass'], $options);

                        // Create table if it doesn't exist
                        self::$instance->exec("
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
                                INDEX idx_view_limit (view_limit),
                                INDEX idx_expires_view_limit (expires_at, view_limit),
                                INDEX idx_code_expires (code, expires_at)
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
        
        return self::$instance;
    }
    
    /**
     * Reset the database instance (useful for testing)
     */
    public static function reset(): void {
        self::$instance = null;
    }
}

