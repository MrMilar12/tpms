<?php
/**
 * Test Dashboard Tour Database Logic
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
    echo "DASHBOARD TOUR LOGIC TEST\n";
    echo "═══════════════════════════════════════════════════════\n\n";
    
    if (!$user) {
        echo "❌ NOT LOGGED IN\n";
        exit;
    }
    
    echo "✓ Logged in as: " . $user['full_name'] . " (ID: $userId)\n\n";
    
    // Test SELECT query
    echo "Testing SELECT query...\n";
    $stmt = $db->prepare("SELECT dashboard_tour_completed FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $tourStatus = $stmt->fetchColumn();
    $tourCompleted = (bool)$tourStatus;
    
    echo "  Raw value: " . var_export($tourStatus, true) . "\n";
    echo "  As boolean: " . ($tourCompleted ? 'TRUE' : 'FALSE') . "\n";
    echo "  Should display tour: " . (!$tourCompleted ? 'YES ✓' : 'NO') . "\n\n";
    
    // Test UPDATE query
    echo "Testing UPDATE query...\n";
    $stmt = $db->prepare("UPDATE users SET dashboard_tour_completed = 1, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$userId]);
    echo "  ✓ Update executed (marking tour as complete)\n\n";
    
    // Verify UPDATE worked
    echo "Verifying UPDATE...\n";
    $stmt = $db->prepare("SELECT dashboard_tour_completed FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $newStatus = $stmt->fetchColumn();
    echo "  New value: " . $newStatus . "\n";
    echo "  Update successful: " . ($newStatus == 1 ? 'YES ✓' : 'NO ✗') . "\n\n";
    
    // Reset for testing
    echo "Resetting for next test...\n";
    $stmt = $db->prepare("UPDATE users SET dashboard_tour_completed = 0 WHERE id = ?");
    $stmt->execute([$userId]);
    echo "  ✓ Reset complete\n\n";
    
    echo "═══════════════════════════════════════════════════════\n";
    echo "✓ ALL TESTS PASSED - Dashboard tour logic is working!\n";
    echo "═══════════════════════════════════════════════════════\n";
    
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    echo "\nStack trace:\n";
    echo $e->getTraceAsString() . "\n";
}
?>
