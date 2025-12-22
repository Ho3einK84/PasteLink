<?php
declare(strict_types=1);

// Load database settings
require_once __DIR__ . '/config.php';

try {
    // Database connection
    $dsn = sprintf(
        "mysql:host=%s;dbname=%s;charset=%s",
        DB_CONFIG['host'],
        DB_CONFIG['name'],
        DB_CONFIG['charset']
    );

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];

    $pdo = new PDO($dsn, DB_CONFIG['user'], DB_CONFIG['pass'], $options);

    // Delete records older than 24 hours
    $sql = "DELETE FROM texts WHERE created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();

    // Log the result
    $deletedRows = $stmt->rowCount();
    $timestamp = date('Y-m-d H:i:s');
    echo "[$timestamp] Deleted: $deletedRows rows." . PHP_EOL;

} catch (PDOException $e) {
    error_log("Cleanup Error: " . $e->getMessage());
    die("Error: Check logs.");
}
