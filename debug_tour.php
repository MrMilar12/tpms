<?php
/**
 * Debug Script - Check Dashboard Tour Status
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: text/plain');

try {
    $db = getDB();
    $user = currentUser();
    
    echo "═══════════════════════════════════════════════════════\n";
    echo "DASHBOARD TOUR DEBUG\n";
    echo "═══════════════════════════════════════════════════════\n\n";
    
    if (!$user) {
        echo "❌ No user logged in\n";
        exit;
    }
    
    echo "👤 User: " . $user['full_name'] . " (ID: " . $user['id'] . ")\n";
    echo "📊 Role: " . ($user['role'] ?? 'N/A') . "\n\n";
    
    // Check column exists
    $columns = $db->query("SHOW COLUMNS FROM users LIKE 'dashboard_tour_completed'")->fetchAll();
    echo "🔍 Database Column Check:\n";
    if (empty($columns)) {
        echo "   ❌ Column 'dashboard_tour_completed' DOES NOT EXIST\n";
    } else {
        echo "   ✓ Column exists\n";
    }
    echo "\n";
    
    // Get tour status
    $tourStatus = $db->query("SELECT dashboard_tour_completed FROM users WHERE id = ?", [(int)$user['id']])->fetchColumn();
    echo "📌 Tour Status for User:\n";
    echo "   Raw Value: " . var_export($tourStatus, true) . "\n";
    echo "   As Boolean: " . (bool)$tourStatus . "\n";
    echo "   Should Display Tour: " . (!$tourStatus ? "YES ✓" : "NO ✗") . "\n\n";
    
    // Check if all users have the column
    $allUsers = $db->query("SELECT id, full_name, dashboard_tour_completed FROM users")->fetchAll();
    echo "📊 All Users Tour Status:\n";
    foreach ($allUsers as $u) {
        echo "   - " . $u['full_name'] . " (ID: " . $u['id'] . ") = " . ($u['dashboard_tour_completed'] ?? 'NULL') . "\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>
