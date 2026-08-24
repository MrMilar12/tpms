<?php
/**
 * Quick Reset Tool - Reset Dashboard Tour for Testing
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

header('Content-Type: text/plain');

try {
    $db = getDB();
    
    echo "Resetting dashboard tour for all users...\n";
    $result = $db->exec("UPDATE users SET dashboard_tour_completed = 0");
    
    echo "✓ Reset complete!\n";
    echo "✓ All users will now see the tour on their next dashboard visit\n\n";
    
    // Show updated status
    $users = $db->query("SELECT id, full_name, dashboard_tour_completed FROM users")->fetchAll();
    echo "Current status:\n";
    foreach ($users as $user) {
        echo "  - " . $user['full_name'] . ": " . $user['dashboard_tour_completed'] . " (will show tour: " . (!$user['dashboard_tour_completed'] ? 'YES' : 'NO') . ")\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>
