<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

$db = getDB();

// Check if admin1 exists
$stmt = $db->prepare('SELECT id, username, is_active, role FROM users WHERE username = ? LIMIT 1');
$stmt->execute(['admin1']);
$user = $stmt->fetch();

if ($user) {
    echo "User found:\n";
    echo "ID: " . $user['id'] . "\n";
    echo "Username: " . $user['username'] . "\n";
    echo "Is Active: " . $user['is_active'] . "\n";
    echo "Role: " . $user['role'] . "\n";
    
    // Try to authenticate with a test password
    $testPassword = 'admin123'; // common default
    $result = authenticateCredentials('admin1', $testPassword);
    
    if ($result === false) {
        echo "Authentication failed with password: $testPassword\n";
        
        // Check password hash
        $hashStmt = $db->prepare('SELECT password_hash FROM users WHERE username = ? LIMIT 1');
        $hashStmt->execute(['admin1']);
        $hashRow = $hashStmt->fetch();
        if ($hashRow) {
            echo "Password hash exists: " . (strlen($hashRow['password_hash']) > 0 ? "yes" : "no") . "\n";
        }
    } else {
        echo "Authentication successful!\n";
    }
} else {
    echo "User admin1 not found in database.\n";
    
    // List all users
    echo "\nAll users in database:\n";
    $allUsers = $db->query('SELECT id, username, is_active, role FROM users')->fetchAll();
    foreach ($allUsers as $u) {
        echo "  - " . $u['username'] . " (active: " . $u['is_active'] . ", role: " . $u['role'] . ")\n";
    }
}
