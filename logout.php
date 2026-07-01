<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Safely log the activity if session user data exists
if (!empty($_SESSION['user'])) {
    $user     = $_SESSION['user'];
    $user_id  = isset($user['id']) ? (int) $user['id'] : null;
    $username = $user['username'] ?? 'Unknown';
    
    audit_log($user_id, 'LOGOUT', "User '{$username}' logged out");
}

// Wipe out session variables from memory
$_SESSION = [];

// Expire the session cookie in the client browser
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(), 
        '', 
        time() - 42000,
        $params['path'], 
        $params['domain'],
        $params['secure'], 
        $params['httponly']
    );
}

// Kill the session state storage file on the server
session_destroy();

// Route back to the login script with a logout feedback flag
header('Location: login.php?msg=logged_out');
exit;
