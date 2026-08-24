<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

$db = getDB();

$migrationSQL = file_get_contents(__DIR__ . '/migrations/add_new_roles_and_district.sql');

// Remove comments and split statements
$lines = explode("\n", $migrationSQL);
$currentStatement = '';

$statements = [];

foreach ($lines as $line) {
    $line = trim($line);
    
    // Skip empty lines and comments
    if (empty($line) || preg_match('/^--/', $line)) {
        continue;
    }
    
    $currentStatement .= ' ' . $line;
    
    // If line ends with semicolon, we have a complete statement
    if (preg_match('/;$/', $line)) {
        $stmt = trim($currentStatement);
        if (!empty($stmt)) {
            $statements[] = $stmt;
        }
        $currentStatement = '';
    }
}

$success = true;
$executed = 0;

foreach ($statements as $statement) {
    $statement = trim($statement);
    if (empty($statement) || preg_match('/^--/', $statement)) {
        continue;
    }
    
    try {
        echo "Executing: " . substr($statement, 0, 80) . "...\n";
        $db->exec($statement);
        $executed++;
        echo "✓ Success\n\n";
    } catch (Exception $e) {
        echo "✗ Error: " . $e->getMessage() . "\n\n";
        // Don't fail completely, some statements might already exist
    }
}

echo "====================================\n";
echo "Migration complete!\n";
echo "Executed: $executed statements\n";
echo "====================================\n";
?>
