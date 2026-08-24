<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

$db = getDB();

echo "<h2>Database Schema Check</h2>";

// Check users table structure
$stmt = $db->prepare('DESCRIBE users');
$stmt->execute();
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<h3>Users Table Structure:</h3>";
echo "<pre>";
foreach ($columns as $col) {
    if ($col['Field'] === 'role') {
        echo "ROLE COLUMN DEFINITION:\n";
        echo "Type: " . $col['Type'] . "\n";
        echo "Null: " . $col['Null'] . "\n";
        echo "Key: " . $col['Key'] . "\n";
        echo "Default: " . $col['Default'] . "\n";
        echo "\n";
    }
}
echo "</pre>";

// Extract ENUM values from Type
$typeCheck = 'enum(\'admin\',\'hr\',\'school_head\',\'viewer\',\'psds\',\'sdc\',\'unit_head\')';
echo "<h3>Expected ENUM Values:</h3>";
echo "<p>psds, sdc, unit_head</p>";

// Query to show actual ENUM values
$sql = "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='users' AND COLUMN_NAME='role'";
$stmt = $db->prepare($sql);
$stmt->execute();
$result = $stmt->fetch(PDO::FETCH_ASSOC);

if ($result) {
    echo "<h3>Actual ENUM Values in Database:</h3>";
    echo "<pre>" . htmlspecialchars($result['COLUMN_TYPE']) . "</pre>";
}
?>
