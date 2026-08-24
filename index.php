<?php
// Root index – redirect to login or dashboard
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
startSecureSession();
redirect(APP_URL . (isLoggedIn() ? '/dashboard' : '/login'));
