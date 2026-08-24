<?php
// ============================================================
// Database Connection (PDO Singleton)
// ============================================================

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=%s',
                DB_HOST, DB_NAME, DB_CHARSET
            );
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, sql_mode='STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION', time_zone = '+08:00'",
            ];

            // Block execution of multiple statements in a single query call.
            if (defined('PDO::MYSQL_ATTR_MULTI_STATEMENTS')) {
                $options[PDO::MYSQL_ATTR_MULTI_STATEMENTS] = false;
            }

            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            error_log('TPMS DB Error: ' . $e->getMessage());
            http_response_code(500);
            die('<div style="font-family:sans-serif;padding:2rem;text-align:center;">'
              . '<h2>Database Connection Failed</h2>'
              . '<p>Please check your database settings in <strong>config.php</strong>.</p>'
              . '</div>');
        }
    }
    return $pdo;
}
