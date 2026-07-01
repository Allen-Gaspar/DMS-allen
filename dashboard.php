<?php
/**
 * dashboard.php — Central router.
 */
require_once __DIR__ . '/auth.php';
$user = require_login();

switch ($user['role']) {
    case 'admin':
        header('Location: admin/admin_dashboard.php');
        exit;
    case 'contributor':
        header('Location: cont/contributor_dashboard.php');
        exit;
    default:
        header('Location: casual/casual_user_dashboard.php');
        exit;
}