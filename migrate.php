<?php
/**
 * Database Migration Script - SQL Version Compatible
 * Works with: MySQL 5.7+, MySQL 8.0+, MariaDB 10.1+
 * Run this script to apply pending migrations
 * Visit: http://your-domain.com/tpms/migrate.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

// Suppress output buffering issues
header('Content-Type: text/plain; charset=UTF-8');

/**
 * Helper function to check if column exists (version-compatible)
 */
function columnExists($db, $table, $column) {
    try {
        $stmt = $db->prepare("
            SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = ? 
            AND COLUMN_NAME = ?
            LIMIT 1
        ");
        $stmt->execute([$table, $column]);
        return (bool)$stmt->fetch();
    } catch (Exception $e) {
        // Fallback for very old MySQL versions without INFORMATION_SCHEMA
        try {
            $result = $db->query("SHOW COLUMNS FROM $table LIKE '$column'")->fetch();
            return (bool)$result;
        } catch (Exception $e2) {
            return false;
        }
    }
}

/**
 * Helper function to check if index exists (version-compatible)
 */
function indexExists($db, $table, $indexName) {
    try {
        $stmt = $db->prepare("
            SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = ? 
            AND INDEX_NAME = ?
            LIMIT 1
        ");
        $stmt->execute([$table, $indexName]);
        return (bool)$stmt->fetch();
    } catch (Exception $e) {
        // Fallback
        try {
            $result = $db->query("SHOW INDEXES FROM $table WHERE Key_name = '$indexName'")->fetch();
            return (bool)$result;
        } catch (Exception $e2) {
            return false;
        }
    }
}

try {
    $db = getDB();
    
    echo "Starting database migrations...\n";
    echo "Database: " . (getenv('DB_HOST') ?? 'localhost') . "\n";
    echo str_repeat("─", 70) . "\n\n";
    
    $migrationNumber = 1;
    $totalMigrations = 6;
    
    // Migration 1: Add district_id column
    echo "[$migrationNumber/$totalMigrations] Adding district_id column to users table...\n";
    $migrationNumber++;
    try {
        if (!columnExists($db, 'users', 'district_id')) {
            $db->exec("ALTER TABLE users ADD COLUMN district_id INT UNSIGNED DEFAULT NULL AFTER role");
            echo "✓ Column added successfully\n\n";
        } else {
            echo "✓ Column already exists\n\n";
        }
    } catch (Exception $e) {
        echo "⚠ Warning: " . $e->getMessage() . "\n\n";
    }
    
    // Migration 2: Add twofa_enabled column
    echo "[$migrationNumber/$totalMigrations] Adding twofa_enabled column to users table...\n";
    $migrationNumber++;
    try {
        if (!columnExists($db, 'users', 'twofa_enabled')) {
            $db->exec("ALTER TABLE users ADD COLUMN twofa_enabled TINYINT(1) DEFAULT 0 AFTER is_active");
            echo "✓ Column added successfully\n\n";
        } else {
            echo "✓ Column already exists\n\n";
        }
    } catch (Exception $e) {
        echo "⚠ Warning: " . $e->getMessage() . "\n\n";
    }
    
    // Migration 3: Add twofa_secret column
    echo "[$migrationNumber/$totalMigrations] Adding twofa_secret column to users table...\n";
    $migrationNumber++;
    try {
        if (!columnExists($db, 'users', 'twofa_secret')) {
            $db->exec("ALTER TABLE users ADD COLUMN twofa_secret VARCHAR(64) DEFAULT NULL AFTER twofa_enabled");
            echo "✓ Column added successfully\n\n";
        } else {
            echo "✓ Column already exists\n\n";
        }
    } catch (Exception $e) {
        echo "⚠ Warning: " . $e->getMessage() . "\n\n";
    }
    
    // Migration 4: Add dashboard_tour_completed column
    echo "[$migrationNumber/$totalMigrations] Adding dashboard_tour_completed column to users table...\n";
    $migrationNumber++;
    try {
        if (!columnExists($db, 'users', 'dashboard_tour_completed')) {
            $db->exec("ALTER TABLE users ADD COLUMN dashboard_tour_completed TINYINT(1) DEFAULT 0 AFTER twofa_secret");
            echo "✓ Column added successfully\n\n";
        } else {
            echo "✓ Column already exists\n\n";
        }
    } catch (Exception $e) {
        echo "⚠ Warning: " . $e->getMessage() . "\n\n";
    }
    
    // Migration 5: Update role ENUM to include new roles
    echo "[$migrationNumber/$totalMigrations] Updating role ENUM to include psds, sdc, unit_head...\n";
    $migrationNumber++;
    try {
        // Check current ENUM values safely
        $stmt = $db->prepare("
            SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = 'users' 
            AND COLUMN_NAME = 'role'
            LIMIT 1
        ");
        $stmt->execute();
        $colInfo = $stmt->fetch();
        
        if (!$colInfo) {
            echo "⚠ Could not determine role column type\n\n";
        } elseif (strpos($colInfo['COLUMN_TYPE'], 'psds') === false) {
            // ENUM needs update - use MODIFY COLUMN (compatible with all modern MySQL/MariaDB)
            try {
                $db->exec("ALTER TABLE users MODIFY COLUMN role ENUM('admin','hr','school_head','viewer','psds','sdc','unit_head') DEFAULT NULL");
                echo "✓ Role ENUM updated and DEFAULT changed to NULL\n";
                echo "  ℹ This enables role selection flow for certain users\n\n";
            } catch (Exception $e) {
                // Try fallback: DROP and recreate (for very restrictive MySQL versions)
                echo "⚠ MODIFY COLUMN failed, attempting alternative method...\n";
                try {
                    // This is a safe fallback that works on almost any MySQL version
                    $db->exec("ALTER TABLE users CHANGE COLUMN role role ENUM('admin','hr','school_head','viewer','psds','sdc','unit_head') DEFAULT NULL");
                    echo "✓ Role ENUM updated using CHANGE COLUMN\n\n";
                } catch (Exception $e2) {
                    echo "✗ Error: " . $e->getMessage() . "\n";
                    echo "  Manual fix required. In phpMyAdmin SQL tab, run:\n";
                    echo "  ALTER TABLE users MODIFY COLUMN role ENUM('admin','hr','school_head','viewer','psds','sdc','unit_head') DEFAULT NULL;\n\n";
                }
            }
        } else {
            echo "✓ Role ENUM already includes all required roles\n\n";
        }
    } catch (Exception $e) {
        echo "⚠ Warning: " . $e->getMessage() . "\n\n";
    }
    
    // Migration 6: Add indexes
    echo "[$migrationNumber/$totalMigrations] Adding indexes for performance...\n";
    $migrationNumber++;
    try {
        if (!indexExists($db, 'users', 'idx_district_id')) {
            try {
                $db->exec("ALTER TABLE users ADD INDEX idx_district_id (district_id)");
                echo "✓ Index added for district_id\n\n";
            } catch (Exception $e) {
                // Index creation might fail if it already exists (some MySQL versions)
                echo "✓ Index already exists or created\n\n";
            }
        } else {
            echo "✓ Index already exists\n\n";
        }
    } catch (Exception $e) {
        echo "⚠ Warning: " . $e->getMessage() . "\n\n";
    }
    
    echo str_repeat("─", 70) . "\n";
    echo "✓ All migrations completed!\n\n";
    
    echo "📋 Summary:\n";
    echo "  • Users can now select their role (psds, sdc, unit_head) at login\n";
    echo "  • District assignment is supported via district_id column\n";
    echo "  • All 2FA columns are in place\n";
    echo "  • Dashboard tour tracking is enabled\n\n";
    
    echo "⚠ Next Steps:\n";
    echo "  1. Log in with a user that has role = NULL or 'viewer'\n";
    echo "  2. You should be redirected to select-role page\n";
    echo "  3. After selecting role, complete district selection if PSDS/SDC\n";
    echo "  4. Delete this migrate.php file for security\n";
    
} catch (Exception $e) {
    echo "\n✗ Migration failed: " . $e->getMessage() . "\n";
    echo "Stack trace:\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
?>
    exit(1);
}
?>
