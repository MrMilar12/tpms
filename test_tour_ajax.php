<?php
session_start();
require 'config.php';
require 'includes/db.php';
require 'includes/auth.php';

echo "<h1>Tour Completion Test</h1>";

if (!isLoggedIn()) {
    echo "You are not logged in!";
    exit;
}

$user = currentUser();
$userId = $user['id'];

echo "<p><strong>Current User:</strong> ID $userId - " . $user['username'] . "</p>";

// Check current status
$db = getDB();
$stmt = $db->prepare("SELECT dashboard_tour_completed FROM users WHERE id = ?");
$stmt->execute([$userId]);
$currentStatus = $stmt->fetchColumn();

echo "<p><strong>Current Tour Status:</strong> " . ($currentStatus ? 'COMPLETED (1)' : 'NOT COMPLETED (0)') . "</p>";

// Simulate the AJAX request
echo "<h2>Simulating AJAX POST...</h2>";

$_POST['action'] = 'complete_tour';
$_SERVER['REQUEST_METHOD'] = 'POST';

// Include the handler code directly
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'complete_tour') {
    header('Content-Type: application/json');
    
    if (!isLoggedIn()) {
        $response = ['success' => false, 'error' => 'Not logged in'];
    } else {
        try {
            $db = getDB();
            $user = currentUser();
            $userId = (int)($user['id'] ?? 0);
            
            if ($userId <= 0) {
                $response = ['success' => false, 'error' => 'Invalid user ID'];
            } else {
                $stmt = $db->prepare("UPDATE users SET dashboard_tour_completed = 1, updated_at = NOW() WHERE id = ?");
                $result = $stmt->execute([$userId]);
                
                if ($result) {
                    $verify = $db->prepare("SELECT dashboard_tour_completed FROM users WHERE id = ?");
                    $verify->execute([$userId]);
                    $newStatus = $verify->fetchColumn();
                    
                    $response = ['success' => true, 'message' => 'Tour marked as complete', 'rows_affected' => $stmt->rowCount(), 'verified_status' => (int)$newStatus];
                } else {
                    $response = ['success' => false, 'error' => 'Database update failed', 'rowsAffected' => $stmt->rowCount()];
                }
            }
        } catch (Throwable $e) {
            $response = ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    echo "<pre>";
    echo "Response: " . json_encode($response, JSON_PRETTY_PRINT) . "\n";
    echo "</pre>";
    
    // Verify the final status
    $stmt = $db->prepare("SELECT dashboard_tour_completed FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $finalStatus = $stmt->fetchColumn();
    
    echo "<p><strong>Final Tour Status:</strong> " . ($finalStatus ? 'COMPLETED (1)' : 'NOT COMPLETED (0)') . "</p>";
}
?>
