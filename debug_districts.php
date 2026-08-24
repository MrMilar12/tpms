<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

$db = getDB();

echo "<h2>District Debugging Info</h2>";

// Check if districts table has data
$stmt = $db->prepare('SELECT COUNT(*) as count FROM districts');
$stmt->execute();
$result = $stmt->fetch(PDO::FETCH_ASSOC);
echo "<p><strong>Total districts in database:</strong> " . $result['count'] . "</p>";

// List all districts
$stmt = $db->prepare('SELECT id, district_name FROM districts ORDER BY district_name');
$stmt->execute();
$districts = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($districts)) {
    echo "<p style='color: red;'><strong>ERROR: No districts found in database!</strong></p>";
} else {
    echo "<p><strong>Districts found:</strong></p>";
    echo "<ul>";
    foreach ($districts as $d) {
        echo "<li>" . htmlspecialchars($d['district_name']) . " (ID: " . $d['id'] . ")</li>";
    }
    echo "</ul>";
}

// Check if schools table has data
$stmt = $db->prepare('SELECT COUNT(*) as count FROM schools');
$stmt->execute();
$result = $stmt->fetch(PDO::FETCH_ASSOC);
echo "<p><strong>Total schools in database:</strong> " . $result['count'] . "</p>";

// Check user_districts table
$stmt = $db->prepare('SELECT COUNT(*) as count FROM user_districts');
$stmt->execute();
$result = $stmt->fetch(PDO::FETCH_ASSOC);
echo "<p><strong>Total user-district assignments:</strong> " . $result['count'] . "</p>";

// Check the table structure
echo "<p><strong>Districts table structure:</strong></p>";
$stmt = $db->prepare('DESCRIBE districts');
$stmt->execute();
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "<pre>";
print_r($columns);
echo "</pre>";
?>
