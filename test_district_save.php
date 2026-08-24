<?php
/**
 * Test District Saving Logic
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: text/plain');

try {
    $db = getDB();
    $user = currentUser();
    $userId = (int)($user['id'] ?? 0);
    
    echo "═══════════════════════════════════════════════════════\n";
    echo "DISTRICT SAVING TEST\n";
    echo "═══════════════════════════════════════════════════════\n\n";
    
    if (!$user) {
        echo "❌ NOT LOGGED IN\n";
        exit;
    }
    
    echo "User: " . $user['full_name'] . " (ID: $userId)\n";
    echo "Role: " . $user['role'] . "\n\n";
    
    // Test 1: Clear existing assignments
    echo "Test 1: Clearing existing district assignments...\n";
    $stmt = $db->prepare('DELETE FROM user_districts WHERE user_id = ?');
    $stmt->execute([(int)$userId]);
    echo "  ✓ Cleared\n\n";
    
    // Test 2: Get first few districts
    echo "Test 2: Getting available districts...\n";
    $stmt = $db->prepare('SELECT id, district_name FROM districts LIMIT 3');
    $stmt->execute();
    $testDistricts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($testDistricts)) {
        echo "  ❌ No districts found in database\n";
    } else {
        foreach ($testDistricts as $d) {
            echo "  - District {$d['id']}: {$d['district_name']}\n";
        }
        echo "\n";
    }
    
    // Test 3: Insert test assignments
    if (!empty($testDistricts)) {
        echo "Test 3: Inserting test district assignments...\n";
        $stmt = $db->prepare('INSERT INTO user_districts (user_id, district_id) VALUES (?, ?)');
        
        foreach ($testDistricts as $d) {
            $districtId = (int)$d['id'];
            try {
                $stmt->execute([$userId, $districtId]);
                echo "  ✓ Inserted user_id=$userId, district_id=$districtId\n";
            } catch (Exception $e) {
                echo "  ❌ Error inserting: " . $e->getMessage() . "\n";
            }
        }
        echo "\n";
    }
    
    // Test 4: Verify assignments
    echo "Test 4: Verifying saved assignments...\n";
    $stmt = $db->prepare('SELECT user_id, district_id, assigned_at FROM user_districts WHERE user_id = ? ORDER BY district_id');
    $stmt->execute([$userId]);
    $saved = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($saved)) {
        echo "  ❌ No assignments found!\n";
    } else {
        foreach ($saved as $s) {
            echo "  - user_id={$s['user_id']}, district_id={$s['district_id']}, assigned_at={$s['assigned_at']}\n";
        }
        echo "\n";
    }
    
    // Test 5: Check database connection
    echo "Test 5: Database connection test...\n";
    $result = $db->query("SELECT 1")->fetchColumn();
    echo "  ✓ Database connection working\n\n";
    
    echo "═══════════════════════════════════════════════════════\n";
    if (!empty($saved) && count($saved) === count($testDistricts)) {
        echo "✓ ALL TESTS PASSED - Districts are saving correctly!\n";
    } else {
        echo "⚠️  Some tests had issues - check output above\n";
    }
    echo "═══════════════════════════════════════════════════════\n";
    
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    echo "\nStack trace:\n";
    echo $e->getTraceAsString() . "\n";
}
?>
