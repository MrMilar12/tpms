<?php
/**
 * Check Database Schema and Tour Status
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

header('Content-Type: text/html; charset=utf-8');

?>
<!DOCTYPE html>
<html>
<head>
    <title>Database Check</title>
    <style>
        body { font-family: monospace; padding: 20px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; }
        .section { margin: 20px 0; padding: 15px; background: #f9f9f9; border-left: 4px solid #3498db; }
        .section h3 { margin: 0 0 10px 0; }
        .success { color: #27ae60; }
        .error { color: #e74c3c; }
        .info { color: #3498db; }
        pre { background: #ecf0f1; padding: 10px; border-radius: 4px; overflow-x: auto; }
    </style>
</head>
<body>
    <div class="container">
        <h1>TPMS Database Schema Check</h1>
        
        <?php
        try {
            $db = getDB();
            
            // Check if column exists
            echo '<div class="section">';
            echo '<h3>Column Check</h3>';
            $columns = $db->query("SHOW COLUMNS FROM users LIKE 'dashboard_tour_completed'")->fetchAll();
            if (empty($columns)) {
                echo '<p class="error">❌ Column dashboard_tour_completed DOES NOT EXIST</p>';
            } else {
                echo '<p class="success">✓ Column exists</p>';
                echo '<pre>';
                print_r($columns);
                echo '</pre>';
            }
            echo '</div>';
            
            // Show all users and their tour status
            echo '<div class="section">';
            echo '<h3>User Tour Status</h3>';
            $users = $db->query("SELECT id, full_name, dashboard_tour_completed FROM users ORDER BY id")->fetchAll();
            echo '<table border="1" cellpadding="10" style="width: 100%; text-align: left;">';
            echo '<tr style="background: #ecf0f1;"><th>ID</th><th>Name</th><th>Tour Completed</th><th>Show Tour</th></tr>';
            foreach ($users as $user) {
                $tourStatus = $user['dashboard_tour_completed'];
                $showTour = !$tourStatus;
                echo '<tr>';
                echo '<td>' . $user['id'] . '</td>';
                echo '<td>' . $user['full_name'] . '</td>';
                echo '<td class="' . ($tourStatus ? 'success' : 'error') . '">' . ($tourStatus ?? '0') . '</td>';
                echo '<td class="' . ($showTour ? 'success' : 'error') . '">' . ($showTour ? 'YES - Show' : 'NO - Hide') . '</td>';
                echo '</tr>';
            }
            echo '</table>';
            echo '</div>';
            
            // SQL to reset all tours
            echo '<div class="section">';
            echo '<h3>Reset Tours (if needed)</h3>';
            echo '<p>To reset all tours, run this SQL:</p>';
            echo '<pre>UPDATE users SET dashboard_tour_completed = 0;</pre>';
            echo '<form method="POST">';
            echo '<input type="hidden" name="action" value="reset_all_tours">';
            echo '<button type="submit" onclick="return confirm(\'Are you sure? This will show the tour for all users.\');">Reset All Tours</button>';
            echo '</form>';
            echo '</div>';
            
            // Handle reset action
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_all_tours') {
                $db->exec("UPDATE users SET dashboard_tour_completed = 0");
                echo '<div class="section" style="border-left-color: #27ae60;">';
                echo '<p class="success">✓ All tours have been reset to 0 (SHOW)</p>';
                echo '</div>';
            }
            
        } catch (Exception $e) {
            echo '<div class="section" style="border-left-color: #e74c3c;">';
            echo '<p class="error">Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
            echo '</div>';
        }
        ?>
    </div>
</body>
</html>
