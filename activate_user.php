<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

$db = getDB();

// Activate admin1 and set role to admin
$stmt = $db->prepare('UPDATE users SET is_active = 1, role = ? WHERE username = ?');
$stmt->execute(['admin', 'admin1']);

echo "User admin1 has been activated and role set to admin.\n";

// Verify the change
$verifyStmt = $db->prepare('SELECT id, username, is_active, role FROM users WHERE username = ? LIMIT 1');
$verifyStmt->execute(['admin1']);
$user = $verifyStmt->fetch();

if ($user) {
    echo "Verification:\n";
    echo "  Username: " . $user['username'] . "\n";
    echo "  Is Active: " . $user['is_active'] . "\n";
    echo "  Role: " . $user['role'] . "\n";
}
