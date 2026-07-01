<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/DMS-allen/DMS-allen/',
        'secure'   => false,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

// ... your audit logging / memory wiping logic ...

if (ini_get('session.use_cookies')) {
    setcookie(
        session_name(), 
        '', 
        [
            'expires'  => time() - 42000,
            'path'     => '/DMS-allen/DMS-allen/',
            'secure'   => false,
            'httponly' => true,
            'samesite' => 'Lax'
        ]
    );
}

session_destroy();
header('Location: login.php');
exit;
