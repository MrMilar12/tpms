<?php
/**
 * Check Database Schema - user_districts Table
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

header('Content-Type: text/plain');

try {
    $db = getDB();
    
    echo "═══════════════════════════════════════════════════════\n";
    echo "DATABASE SCHEMA CHECK - user_districts TABLE\n";
    echo "═══════════════════════════════════════════════════════\n\n";
    
    // Check if table exists
    echo "Checking if user_districts table exists...\n";
    try {
        $result = $db->query("SHOW TABLES LIKE 'user_districts'")->fetchAll();
        if (empty($result)) {
            echo "❌ Table DOES NOT EXIST\n\n";
            echo "Creating table...\n";
            
            $db->exec("CREATE TABLE `user_districts` (
                `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` INT(10) UNSIGNED NOT NULL,
                `district_id` INT(10) UNSIGNED NOT NULL,
                `assigned_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unique_user_district` (`user_id`, `district_id`),
                KEY `idx_user` (`user_id`),
                KEY `idx_district` (`district_id`),
                CONSTRAINT `fk_user_districts_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_user_districts_district` FOREIGN KEY (`district_id`) REFERENCES `districts`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            
            echo "✓ Table created successfully!\n\n";
        } else {
            echo "✓ Table exists\n\n";
            
            // Show table structure
            echo "Table structure:\n";
            $columns = $db->query("SHOW COLUMNS FROM user_districts")->fetchAll();
            foreach ($columns as $col) {
                echo "  - {$col['Field']}: {$col['Type']} {$col['Null']} {$col['Key']} {$col['Default']}\n";
            }
            echo "\n";
        }
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
    
    echo "═══════════════════════════════════════════════════════\n";
    echo "✓ Check complete!\n";
    echo "═══════════════════════════════════════════════════════\n";
    
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}
?>
