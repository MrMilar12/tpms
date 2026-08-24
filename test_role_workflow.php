<?php
/**
 * Test script to verify role selection workflow
 * Run this from terminal: php test_role_workflow.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

$db = getDB();

echo "================================\n";
echo "Role Selection Workflow Test\n";
echo "================================\n\n";

// Test 1: Check database schema
echo "1. Checking database schema...\n";
$result = $db->query("DESCRIBE users")->fetchAll();
$hasNewRoles = false;
$hasDistrictId = false;

foreach ($result as $col) {
    if ($col['Field'] === 'role' && strpos($col['Type'], 'psds') !== false) {
        $hasNewRoles = true;
    }
    if ($col['Field'] === 'district_id') {
        $hasDistrictId = true;
    }
}

echo "   - New roles (psds, sdc, unit_head): " . ($hasNewRoles ? "✓ YES" : "✗ NO") . "\n";
echo "   - district_id column: " . ($hasDistrictId ? "✓ YES" : "✗ NO") . "\n\n";

// Test 2: Check user_districts table
echo "2. Checking user_districts table...\n";
$tables = $db->query("SHOW TABLES LIKE 'user_districts'")->fetchAll();
echo "   - Table exists: " . (!empty($tables) ? "✓ YES" : "✗ NO") . "\n\n";

// Test 3: Find or create test user with NULL role
echo "3. Checking for test user with NULL role...\n";
$testUser = $db->query("SELECT id, username, full_name, role FROM users WHERE role IS NULL LIMIT 1")->fetch();

if ($testUser) {
    echo "   - Found existing NULL role user:\n";
    echo "     ID: " . $testUser['id'] . "\n";
    echo "     Username: " . $testUser['username'] . "\n";
    echo "     Full Name: " . $testUser['full_name'] . "\n";
    echo "     Role: " . ($testUser['role'] === null ? "NULL" : $testUser['role']) . "\n";
} else {
    echo "   - No NULL role user found in database\n";
    echo "   - To test, create a user with NULL role:\n";
    echo "     INSERT INTO users (username, password, full_name, role) \n";
    echo "     VALUES ('testuser', '" . password_hash('password', PASSWORD_BCRYPT) . "', 'Test User', NULL);\n";
}

echo "\n";

// Test 4: Check if migration files exist
echo "4. Checking migration files...\n";
$migrationFile = __DIR__ . '/migrations/add_new_roles_and_district.sql';
echo "   - Migration file exists: " . (file_exists($migrationFile) ? "✓ YES" : "✗ NO") . "\n";
if (file_exists($migrationFile)) {
    echo "   - Location: $migrationFile\n";
}

echo "\n";

// Test 5: Check role-related pages
echo "5. Checking role selection pages...\n";
$pages = [
    'select-role.php' => '/select-role.php exists',
    'setup-districts.php' => '/setup-districts.php exists',
    'select-district.php' => '/select-district.php exists',
];

foreach ($pages as $file => $desc) {
    $path = __DIR__ . '/' . $file;
    echo "   - $desc: " . (file_exists($path) ? "✓ YES" : "✗ NO") . "\n";
}

echo "\n================================\n";
echo "Test Complete\n";
echo "================================\n\n";

echo "To test the complete workflow:\n";
echo "1. Create a test user with NULL role (see above)\n";
echo "2. Login with that user\n";
echo "3. You should be redirected to: " . APP_URL . "/select-role\n";
echo "4. Select a role (PSDS, SDC, or Unit Head)\n";
echo "5. If PSDS/SDC: select districts\n";
echo "6. Select active working district\n";
echo "7. Redirect to dashboard with district filter active\n";
?>
