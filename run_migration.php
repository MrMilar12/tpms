<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

$db = getDB();

echo "<h2>Database Migration: Add PSDS/SDC/Unit_Head Roles</h2>";

$migrations = [
    "ALTER TABLE users MODIFY COLUMN role ENUM('admin','hr','school_head','viewer','psds','sdc','unit_head') DEFAULT 'viewer'",
    "ALTER TABLE users ADD COLUMN district_id INT UNSIGNED DEFAULT NULL AFTER role",
    "ALTER TABLE users ADD CONSTRAINT fk_user_district FOREIGN KEY (district_id) REFERENCES districts(id) ON DELETE SET NULL ON UPDATE CASCADE",
    "ALTER TABLE users ADD INDEX idx_district_id (district_id)",
    "ALTER TABLE users ADD COLUMN pending_district_id INT UNSIGNED DEFAULT NULL AFTER district_id",
    "ALTER TABLE users ADD INDEX idx_pending_district_id (pending_district_id)",
];

$createUserDistricts = "CREATE TABLE IF NOT EXISTS user_districts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    district_id INT UNSIGNED NOT NULL,
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_user_district (user_id, district_id),
    CONSTRAINT fk_ud_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_ud_district FOREIGN KEY (district_id) REFERENCES districts(id) ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_district_id (district_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

$success = 0;
$failed = 0;

foreach ($migrations as $sql) {
    try {
        echo "<p>Executing: <code>" . htmlspecialchars(substr($sql, 0, 80)) . "...</code> ";
        
        // Check if column/constraint already exists to avoid duplicate errors
        if (strpos($sql, 'ADD COLUMN') !== false || strpos($sql, 'ADD CONSTRAINT') !== false || strpos($sql, 'ADD INDEX') !== false) {
            // These might already exist, so wrap in try-catch
            try {
                $db->exec($sql);
                echo "<span style='color: green;'>✓ OK</span></p>";
                $success++;
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'Duplicate') || strpos($e->getMessage(), 'already exists')) {
                    echo "<span style='color: orange;'>⚠ Already exists (skipped)</span></p>";
                    $success++;
                } else {
                    throw $e;
                }
            }
        } else {
            $db->exec($sql);
            echo "<span style='color: green;'>✓ OK</span></p>";
            $success++;
        }
    } catch (PDOException $e) {
        echo "<span style='color: red;'>✗ FAILED: " . htmlspecialchars($e->getMessage()) . "</span></p>";
        $failed++;
    }
}

// Create user_districts table
try {
    echo "<p>Creating user_districts table... ";
    $db->exec($createUserDistricts);
    echo "<span style='color: green;'>✓ OK</span></p>";
    $success++;
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'already exists')) {
        echo "<span style='color: orange;'>⚠ Already exists (skipped)</span></p>";
        $success++;
    } else {
        echo "<span style='color: red;'>✗ FAILED: " . htmlspecialchars($e->getMessage()) . "</span></p>";
        $failed++;
    }
}

echo "<hr>";
echo "<h3>Migration Summary</h3>";
echo "<p><strong>Successful:</strong> $success</p>";
echo "<p><strong>Failed:</strong> $failed</p>";

if ($failed === 0) {
    echo "<p style='color: green; font-weight: bold;'>✓ All migrations completed successfully!</p>";
} else {
    echo "<p style='color: red; font-weight: bold;'>✗ Some migrations failed. Please check the errors above.</p>";
}

// Verify the role enum
echo "<hr>";
echo "<h3>Verification</h3>";
$stmt = $db->prepare("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='users' AND COLUMN_NAME='role' AND TABLE_SCHEMA='tpms'");
$stmt->execute();
$result = $stmt->fetch(PDO::FETCH_ASSOC);
if ($result) {
    echo "<p><strong>Role ENUM after migration:</strong></p>";
    echo "<pre>" . htmlspecialchars($result['COLUMN_TYPE']) . "</pre>";
}
?>
