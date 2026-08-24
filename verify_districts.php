<?php
/**
 * Comprehensive District Save Verification
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: text/html; charset=utf-8');

?>
<!DOCTYPE html>
<html>
<head>
    <title>District Saving - Full Diagnostic</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f5f5; padding: 20px; }
        .container { max-width: 900px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,.1); }
        h1 { color: #333; border-bottom: 2px solid #3498db; padding-bottom: 10px; }
        h2 { color: #555; margin-top: 20px; font-size: 16px; }
        .section { margin: 15px 0; padding: 15px; background: #f9f9f9; border-left: 3px solid #3498db; border-radius: 4px; }
        .success { color: #27ae60; font-weight: bold; }
        .error { color: #e74c3c; font-weight: bold; }
        .warning { color: #f39c12; font-weight: bold; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        th, td { padding: 8px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #ecf0f1; font-weight: bold; }
        code { background: #f0f0f0; padding: 2px 6px; border-radius: 3px; font-family: monospace; }
        pre { background: #f0f0f0; padding: 10px; border-radius: 4px; overflow-x: auto; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 District Saving - Full Diagnostic Report</h1>
        
        <?php
        try {
            $db = getDB();
            $user = currentUser();
            
            echo '<div class="section">';
            echo '<h2>Authentication & User</h2>';
            if (!$user) {
                echo '<p class="error">❌ NOT LOGGED IN</p>';
            } else {
                echo '<p><strong>User:</strong> ' . htmlspecialchars($user['full_name']) . '</p>';
                echo '<p><strong>User ID:</strong> ' . $user['id'] . '</p>';
                echo '<p><strong>Role:</strong> ' . htmlspecialchars($user['role']) . '</p>';
            }
            echo '</div>';
            
            if (!$user) {
                echo '<div class="section"><p class="error">Cannot proceed without user. Please log in first.</p></div>';
            } else {
                $userId = (int)$user['id'];
                
                // Check user_districts table
                echo '<div class="section">';
                echo '<h2>Database: user_districts Table</h2>';
                try {
                    $columns = $db->query("SHOW COLUMNS FROM user_districts")->fetchAll();
                    echo '<table>';
                    echo '<tr><th>Column</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>';
                    foreach ($columns as $col) {
                        echo '<tr>';
                        echo '<td>' . $col['Field'] . '</td>';
                        echo '<td>' . $col['Type'] . '</td>';
                        echo '<td>' . $col['Null'] . '</td>';
                        echo '<td>' . ($col['Key'] ?: 'No') . '</td>';
                        echo '<td>' . ($col['Default'] ?: 'NULL') . '</td>';
                        echo '</tr>';
                    }
                    echo '</table>';
                    echo '<p class="success">✓ Table exists and is properly configured</p>';
                } catch (Exception $e) {
                    echo '<p class="error">❌ Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
                }
                echo '</div>';
                
                // Check current assignments for this user
                echo '<div class="section">';
                echo '<h2>Current District Assignments for User ID ' . $userId . '</h2>';
                try {
                    $stmt = $db->prepare('SELECT id, user_id, district_id, assigned_at FROM user_districts WHERE user_id = ? ORDER BY district_id');
                    $stmt->execute([$userId]);
                    $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    if (empty($assignments)) {
                        echo '<p><span class="warning">ℹ️ No current assignments</span></p>';
                    } else {
                        echo '<table>';
                        echo '<tr><th>ID</th><th>User ID</th><th>District ID</th><th>Assigned At</th></tr>';
                        foreach ($assignments as $a) {
                            echo '<tr>';
                            echo '<td>' . $a['id'] . '</td>';
                            echo '<td>' . $a['user_id'] . '</td>';
                            echo '<td>' . $a['district_id'] . '</td>';
                            echo '<td>' . $a['assigned_at'] . '</td>';
                            echo '</tr>';
                        }
                        echo '</table>';
                        echo '<p class="success">✓ User has ' . count($assignments) . ' district assignment(s)</p>';
                    }
                } catch (Exception $e) {
                    echo '<p class="error">❌ Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
                }
                echo '</div>';
                
                // Check available districts
                echo '<div class="section">';
                echo '<h2>Available Districts in System</h2>';
                try {
                    $stmt = $db->prepare('SELECT id, district_name FROM districts ORDER BY district_name');
                    $stmt->execute();
                    $districts = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    if (empty($districts)) {
                        echo '<p class="error">❌ No districts found</p>';
                    } else {
                        echo '<table>';
                        echo '<tr><th>ID</th><th>District Name</th><th>Schools</th></tr>';
                        foreach ($districts as $d) {
                            $countStmt = $db->prepare('SELECT COUNT(*) FROM schools WHERE district_id = ?');
                            $countStmt->execute([(int)$d['id']]);
                            $schoolCount = $countStmt->fetchColumn();
                            echo '<tr>';
                            echo '<td>' . $d['id'] . '</td>';
                            echo '<td>' . htmlspecialchars($d['district_name']) . '</td>';
                            echo '<td>' . $schoolCount . '</td>';
                            echo '</tr>';
                        }
                        echo '</table>';
                        echo '<p class="success">✓ Found ' . count($districts) . ' district(s)</p>';
                    }
                } catch (Exception $e) {
                    echo '<p class="error">❌ Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
                }
                echo '</div>';
                
                // Test INSERT operation
                echo '<div class="section">';
                echo '<h2>Test: Insert Operation</h2>';
                echo '<p>Testing if we can insert a test assignment...</p>';
                try {
                    // Get first district
                    $stmt = $db->prepare('SELECT id FROM districts LIMIT 1');
                    $stmt->execute();
                    $testDistrict = $stmt->fetchColumn();
                    
                    if (!$testDistrict) {
                        echo '<p class="warning">⚠️ No test district available</p>';
                    } else {
                        // Try insert
                        $stmt = $db->prepare('INSERT INTO user_districts (user_id, district_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE assigned_at = NOW()');
                        $result = $stmt->execute([$userId, (int)$testDistrict]);
                        
                        if ($result) {
                            echo '<p class="success">✓ Insert test successful (District ID: ' . $testDistrict . ')</p>';
                            
                            // Verify it was inserted
                            $stmt = $db->prepare('SELECT COUNT(*) FROM user_districts WHERE user_id = ? AND district_id = ?');
                            $stmt->execute([$userId, (int)$testDistrict]);
                            $count = $stmt->fetchColumn();
                            
                            if ($count > 0) {
                                echo '<p class="success">✓ Verified: Record was saved to database</p>';
                            } else {
                                echo '<p class="error">❌ Record was not saved to database</p>';
                            }
                        } else {
                            echo '<p class="error">❌ Insert failed</p>';
                        }
                    }
                } catch (Exception $e) {
                    echo '<p class="error">❌ Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
                }
                echo '</div>';
                
                // Check form submission
                echo '<div class="section">';
                echo '<h2>Form Test</h2>';
                echo '<p>Try selecting districts from the form below and submitting:</p>';
                echo '<form method="POST" style="margin-top: 15px;">';
                echo '<input type="hidden" name="csrf_token" value="test">';
                
                try {
                    $stmt = $db->prepare('SELECT id, district_name FROM districts LIMIT 5');
                    $stmt->execute();
                    $testDistricts = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    foreach ($testDistricts as $d) {
                        echo '<label style="display: block; margin: 5px 0;"><input type="checkbox" name="test_districts[]" value="' . $d['id'] . '"> ' . htmlspecialchars($d['district_name']) . '</label>';
                    }
                } catch (Exception $e) {
                    echo '<p class="error">Error loading districts</p>';
                }
                echo '<button type="submit" style="margin-top: 10px; padding: 8px 16px; background: #3498db; color: white; border: none; border-radius: 4px; cursor: pointer;">Test Submit</button>';
                echo '</form>';
                echo '</div>';
            }
            
        } catch (Exception $e) {
            echo '<div class="section"><p class="error">❌ Error: ' . htmlspecialchars($e->getMessage()) . '</p></div>';
        }
        ?>
    </div>
</body>
</html>
