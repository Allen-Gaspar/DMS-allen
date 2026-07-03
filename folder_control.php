<?php
/**
 * folder_control.php — Asynchronous Endpoint for Folder Access Matrix
 */
require_once __DIR__ . '/auth.php';
$user = require_login();
$db   = get_db();
$role = $user['role'];

$action = $_GET['action'] ?? '';

// Prevent casual users from modifying folder security permissions
if ($role === 'casual') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized operation permission layer.']);
    exit;
}

header('Content-Type: application/json');

switch ($action) {
    case 'get_shares':
        $folder_id = (int)($_GET['folder_id'] ?? 0);
        
        $stmt = $db->prepare(
            'SELECT fs.*, u.username, u.role 
             FROM folder_shares fs
             INNER JOIN users u ON u.id = fs.shared_with_user_id
             WHERE fs.folder_id = ? 
             ORDER BY u.username ASC'
        );
        $stmt->bind_param('i', $folder_id);
        $stmt->execute();
        $shares = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        echo json_encode($shares);
        exit;

    case 'grant_access':
        // Read the inbound JSON stream payload
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            echo json_encode(['success' => false, 'message' => 'Invalid data payload.']);
            exit;
        }

        $folder_id           = (int)($input['folder_id'] ?? 0);
        $shared_with_user_id = (int)($input['shared_with_user_id'] ?? 0);
        $can_add             = (int)($input['can_add'] ?? 0);
        $can_edit            = (int)($input['can_edit'] ?? 0);
        $can_delete          = (int)($input['can_delete'] ?? 0);
        $can_checkout        = (int)($input['can_checkout'] ?? 0);
        $can_download        = (int)($input['can_download'] ?? 0);
        $can_share           = (int)($input['can_share'] ?? 0);

        if ($folder_id <= 0 || $shared_with_user_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Missing essential identification properties.']);
            exit;
        }

        // Upsert strategy (insert or update rules instantly on a duplicate key map constraint match)
        $stmt = $db->prepare(
            'INSERT INTO folder_shares (folder_id, shared_with_user_id, can_edit, can_add, can_delete, can_checkout, can_download, can_share)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE 
                can_edit = VALUES(can_edit), 
                can_add = VALUES(can_add), 
                can_delete = VALUES(can_delete), 
                can_checkout = VALUES(can_checkout), 
                can_download = VALUES(can_download), 
                can_share = VALUES(can_share)'
        );
        $stmt->bind_param('iiiiiiii', $folder_id, $shared_with_user_id, $can_edit, $can_add, $can_delete, $can_checkout, $can_download, $can_share);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Database write error.']);
        }
        exit;

    case 'revoke_access':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'message' => 'Invalid Request Method Framework layer.']);
            exit;
        }

        $share_id = (int)($_GET['share_id'] ?? 0);
        $stmt = $db->prepare('DELETE FROM folder_shares WHERE id = ?');
        $stmt->bind_param('i', $share_id);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to drop access matrix context map reference row.']);
        }
        exit;

    default:
        echo json_encode(['success' => false, 'message' => 'Action path route unrecognized.']);
        exit;
}