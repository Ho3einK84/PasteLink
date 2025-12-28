<?php
declare(strict_types=1);

// Load dependencies
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/texthandler.php';
require_once __DIR__ . '/includes/security.php';

Security::init();

try {
    $textHandler = new TextHandler();
    
    // Delete expired and exhausted view limit texts
    $deletedRows = $textHandler->deleteExpiredTexts();
    
    // Get statistics
    $stats = $textHandler->getTextStats();
    
    $timestamp = date('Y-m-d H:i:s');
    echo "[$timestamp] === PasteLink v3 Cleanup ===" . PHP_EOL;
    echo "Deleted expired/exhausted texts: $deletedRows" . PHP_EOL;
    echo "Total texts: {$stats['total_texts']}" . PHP_EOL;
    echo "Total views: {$stats['total_views']}" . PHP_EOL;
    echo "Expiring texts: {$stats['expiring_texts']}" . PHP_EOL;
    echo "Limited texts: {$stats['limited_texts']}" . PHP_EOL;
    echo "Cleanup completed successfully." . PHP_EOL;

} catch (Throwable $e) {
    error_log("Cleanup Error: " . $e->getMessage());
    die("Error: " . $e->getMessage());
}
