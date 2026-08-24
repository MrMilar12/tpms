<?php
require_once 'config.php';
require_once 'includes/db.php';

try {
    $db = getDB();
    
    // Check if districts table has data
    $stmt = $db->prepare('SELECT COUNT(*) FROM districts');
    $stmt->execute();
    $count = $stmt->fetchColumn();
    
    echo "Districts in database: $count\n";
    
    // List first 10 districts
    $stmt = $db->prepare('SELECT id, district_name FROM districts ORDER BY district_name LIMIT 10');
    $stmt->execute();
    $districts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($districts) > 0) {
        foreach ($districts as $d) {
            echo "  - ID: {$d['id']}, Name: {$d['district_name']}\n";
        }
    } else {
        echo "  [No districts found]\n";
    }
    
    // Check if user_districts table exists and has data
    $stmt = $db->prepare('SELECT COUNT(*) FROM user_districts');
    $stmt->execute();
    $udCount = $stmt->fetchColumn();
    echo "\nUser district assignments: $udCount\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
