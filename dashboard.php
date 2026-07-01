<?php
require_once __DIR__ . '/auth.php';
$user = require_login();

switch ($user['role']) {
    case 'admin':
        header('Location: /DMS-allen/DMS-allen/admin/admin_dashboard.php');
        exit;
    case 'contributor':
        header('Location: /DMS-allen/DMS-allen/cont/contributor_dashboard.php');
        exit;
    default:
        header('Location: /DMS-allen/DMS-allen/casual/casual_user_dashboard.php');
        exit;
}
