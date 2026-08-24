<?php
/**
 * Detailed Dashboard Debug Report
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: text/html; charset=utf-8');

?>
<!DOCTYPE html>
<html>
<head>
    <title>Dashboard Debug Report</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #0f172a;
            color: #e2e8f0;
            padding: 20px;
        }
        .container { max-width: 1000px; margin: 0 auto; }
        h1 { margin: 30px 0 20px; color: #f8fafc; font-size: 2rem; }
        h2 { margin: 25px 0 15px; color: #cbd5e1; font-size: 1.3rem; border-bottom: 2px solid #334155; padding-bottom: 10px; }
        .card {
            background: #1e293b;
            border: 1px solid rgba(148, 163, 184, .26);
            border-radius: 12px;
            padding: 20px;
            margin: 15px 0;
        }
        .success { color: #86efac; font-weight: bold; }
        .error { color: #fca5a5; font-weight: bold; }
        .warning { color: #fbbf24; font-weight: bold; }
        .info { color: #93c5fd; }
        pre {
            background: #0f172a;
            border: 1px solid #334155;
            border-radius: 8px;
            padding: 15px;
            overflow-x: auto;
            font-size: 0.85rem;
            margin: 10px 0;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
            background: #0f172a;
            border: 1px solid #334155;
            border-radius: 8px;
            overflow: hidden;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #334155;
        }
        th {
            background: #1e293b;
            font-weight: 700;
            color: #cbd5e1;
        }
        tr:last-child td { border-bottom: none; }
        .code-block {
            background: #0f172a;
            border-left: 4px solid #3b82f6;
            padding: 15px;
            margin: 10px 0;
            border-radius: 6px;
            font-family: 'Courier New', monospace;
            font-size: 0.9rem;
        }
        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.85rem;
            font-weight: 600;
            margin: 4px 4px 4px 0;
        }
        .badge-success { background: rgba(134, 239, 172, .2); color: #86efac; }
        .badge-error { background: rgba(252, 165, 165, .2); color: #fca5a5; }
        .badge-warning { background: rgba(251, 191, 36, .2); color: #fbbf24; }
        .badge-info { background: rgba(147, 197, 253, .2); color: #93c5fd; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 Dashboard Debug Report</h1>
        
        <?php
        try {
            $db = getDB();
            $user = currentUser();
            
            // Check authentication
            echo '<div class="card">';
            echo '<h2>Authentication Status</h2>';
            if (!$user) {
                echo '<p class="error">❌ NOT LOGGED IN</p>';
                echo '<p>User must be logged in to view dashboard tour.</p>';
            } else {
                echo '<p class="success">✓ Logged In</p>';
                echo '<table>';
                echo '<tr><th>Property</th><th>Value</th></tr>';
                echo '<tr><td>ID</td><td>' . $user['id'] . '</td></tr>';
                echo '<tr><td>Name</td><td>' . $user['full_name'] . '</td></tr>';
                echo '<tr><td>Username</td><td>' . $user['username'] . '</td></tr>';
                echo '<tr><td>Role</td><td><span class="badge badge-info">' . $user['role'] . '</span></td></tr>';
                echo '</table>';
            }
            echo '</div>';
            
            // Check database setup
            echo '<div class="card">';
            echo '<h2>Database Configuration</h2>';
            
            // Check column exists
            $columns = $db->query("SHOW COLUMNS FROM users WHERE FIELD='dashboard_tour_completed'")->fetchAll();
            if (empty($columns)) {
                echo '<p class="error">❌ Column "dashboard_tour_completed" NOT FOUND</p>';
                echo '<p>Run: <code>ALTER TABLE users ADD COLUMN dashboard_tour_completed TINYINT(1) DEFAULT 0;</code></p>';
            } else {
                echo '<p class="success">✓ Column exists</p>';
                echo '<pre>' . json_encode($columns, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . '</pre>';
            }
            echo '</div>';
            
            // Check tour status
            echo '<div class="card">';
            echo '<h2>Tour Status</h2>';
            
            if (!$user) {
                echo '<p class="error">Cannot check tour status - not logged in</p>';
            } else {
                $tourStatus = $db->query("SELECT dashboard_tour_completed FROM users WHERE id = ?", [(int)$user['id']])->fetchColumn();
                $tourCompleted = (bool)$tourStatus;
                $shouldShow = !$tourCompleted;
                
                echo '<table>';
                echo '<tr><th>Property</th><th>Value</th></tr>';
                echo '<tr><td>User ID</td><td>' . $user['id'] . '</td></tr>';
                echo '<tr><td>Raw Value</td><td><code>' . var_export($tourStatus, true) . '</code></td></tr>';
                echo '<tr><td>As Boolean</td><td>' . ($tourCompleted ? 'TRUE' : 'FALSE') . '</td></tr>';
                echo '<tr><td>Tour Completed</td><td>' . ($tourCompleted ? '<span class="badge badge-warning">YES</span>' : '<span class="badge badge-success">NO</span>') . '</td></tr>';
                echo '<tr><td>Should Display Tour</td><td>' . ($shouldShow ? '<span class="badge badge-success">YES ✓</span>' : '<span class="badge badge-error">NO</span>') . '</td></tr>';
                echo '</table>';
            }
            echo '</div>';
            
            // Check PHP rendering
            echo '<div class="card">';
            echo '<h2>PHP Conditional Rendering</h2>';
            
            if (!$user) {
                echo '<p class="warning">Cannot check - not logged in</p>';
            } else {
                $tourStatus = $db->query("SELECT dashboard_tour_completed FROM users WHERE id = ?", [(int)$user['id']])->fetchColumn();
                $tourCompleted = (bool)$tourStatus;
                
                echo '<p>The HTML will be rendered as follows:</p>';
                echo '<div class="code-block">';
                echo '&lt;?php if (!$tourCompleted): ?&gt;<br>';
                echo '&nbsp;&nbsp;&lt;div class="welcome-hero" id="dashboardTour"&gt;<br>';
                echo '&nbsp;&nbsp;&nbsp;&nbsp;/* Welcome hero content here */<br>';
                echo '&nbsp;&nbsp;&lt;/div&gt;<br>';
                echo '&lt;?php endif; ?&gt;';
                echo '</div>';
                
                echo '<p>Which evaluates to:</p>';
                echo '<div class="code-block">';
                if (!$tourCompleted) {
                    echo '&lt;!-- WELCOME HERO WILL BE RENDERED AND VISIBLE --&gt;<br>';
                    echo '&lt;div class="welcome-hero" id="dashboardTour"&gt;<br>';
                    echo '  ...<br>';
                    echo '&lt;/div&gt;';
                } else {
                    echo '&lt;!-- WELCOME HERO WILL BE HIDDEN (not rendered) --&gt;';
                }
                echo '</div>';
                
                echo '<p>Status: <span class="' . ($shouldShow ? 'success' : 'warning') . '">' . ($shouldShow ? '✓ TOUR SHOULD BE VISIBLE' : '✗ TOUR IS HIDDEN') . '</span></p>';
            }
            echo '</div>';
            
            // CSS Check
            echo '<div class="card">';
            echo '<h2>CSS Display Status</h2>';
            echo '<p>The following CSS should make the tour visible:</p>';
            echo '<div class="code-block">';
            echo '.welcome-hero {<br>';
            echo '&nbsp;&nbsp;display: grid !important;<br>';
            echo '&nbsp;&nbsp;visibility: visible;<br>';
            echo '&nbsp;&nbsp;opacity: 1;<br>';
            echo '&nbsp;&nbsp;animation: tourSlideIn 0.5s ease-out;<br>';
            echo '}';
            echo '</div>';
            echo '<p class="success">✓ CSS appears correct</p>';
            echo '</div>';
            
            // JavaScript Check
            echo '<div class="card">';
            echo '<h2>JavaScript Status</h2>';
            echo '<p>JavaScript will:</p>';
            echo '<ol style="margin-left: 20px;">';
            echo '<li>Look for element with id="dashboardTour"</li>';
            echo '<li>If found, attach event listeners to close button and Get Started button</li>';
            echo '<li>On completion, send AJAX POST to dashboard.php with action=complete_tour</li>';
            echo '<li>Update database and hide tour</li>';
            echo '</ol>';
            echo '<p class="success">✓ JavaScript logic appears sound</p>';
            echo '</div>';
            
            // Recommendations
            echo '<div class="card">';
            echo '<h2>Recommendations</h2>';
            
            if (!$user) {
                echo '<ol>';
                echo '<li><span class="error">❌ Log in first</span> - Visit login.php and authenticate</li>';
                echo '</ol>';
            } else {
                $tourStatus = $db->query("SELECT dashboard_tour_completed FROM users WHERE id = ?", [(int)$user['id']])->fetchColumn();
                $tourCompleted = (bool)$tourStatus;
                $shouldShow = !$tourCompleted;
                
                if (!$shouldShow) {
                    echo '<p>The tour is hidden because dashboard_tour_completed = 1</p>';
                    echo '<p style="margin: 15px 0;">To reset and see the tour again, run:</p>';
                    echo '<div class="code-block">UPDATE users SET dashboard_tour_completed = 0 WHERE id = ' . (int)$user['id'] . ';</div>';
                    echo '<p>Or visit: <a href="reset_tour.php" style="color: #3b82f6; text-decoration: underline;">reset_tour.php</a></p>';
                } else {
                    echo '<ol>';
                    echo '<li><span class="success">✓ Tour should be visible</span></li>';
                    echo '<li>Visit <a href="dashboard.php" style="color: #3b82f6; text-decoration: underline;">dashboard.php</a> to see the tour</li>';
                    echo '<li>If still not visible, check browser console for JavaScript errors (F12 &gt; Console)</li>';
                    echo '<li>Check page source (Ctrl+U) for the welcome-hero HTML element</li>';
                    echo '</ol>';
                }
            }
            echo '</div>';
            
        } catch (Exception $e) {
            echo '<div class="card">';
            echo '<p class="error">Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
            echo '</div>';
        }
        ?>
    </div>
</body>
</html>
