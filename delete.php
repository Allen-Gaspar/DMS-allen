<?php
/**
 * delete.php — Soft delete (contributor) or hard delete (admin) with folder preservation routing.
 */
require_once __DIR__ . '/auth.php';
$user = require_role('contributor', 'admin');
$db   = get_db();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: documents.php?err=' . urlencode('Invalid document identification parameter.'));
    exit;
}

$stmt = $db->prepare('SELECT * FROM documents WHERE id=? LIMIT 1');
$stmt->bind_param('i', $id);
$stmt->execute();
$doc = $stmt->get_result()->fetch_assoc();

if (!$doc) {
    header('Location: documents.php?err=' . urlencode('Document not found.'));
    exit;
}

// Track if the file belongs to a virtual directory folder structure before dropping the record
$folder_id = isset($doc['folder_id']) ? (int)$doc['folder_id'] : 0;

if ($user['role'] === 'admin') {
    // Hard delete: remove physical file and database record parameters
    $file_path = __DIR__ . '/uploads/' . basename($doc['storage_path']);
    if (file_exists($file_path)) {
        unlink($file_path);
    }
    $stmt = $db->prepare('DELETE FROM documents WHERE id=?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    audit_log($user['id'], 'HARD_DELETE', "Admin hard-deleted '{$doc['filename']}'");
    $success_msg = urlencode("'{$doc['filename']}' permanently deleted.");
} else {
    // Soft delete: flag is_deleted parameters mapping state
    $stmt = $db->prepare('UPDATE documents SET is_deleted=1 WHERE id=?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    audit_log($user['id'], 'SOFT_DELETE', "Soft-deleted '{$doc['filename']}'");
    $success_msg = urlencode("'{$doc['filename']}' moved to trash folder.");
}

// ── FIXED SMART ROUTING SWITCHBOARD: Keeps you inside the active folder view ──
if ($folder_id > 0) {
    // Redirect back to folders.php while passing the specific folder ID parameters layout focus
    header("Location: folders.php?view_folder_id={$folder_id}&ok={$success_msg}");
} else {
    // Fallback global directory redirect if the file was deleted from the general documents overview
    header("Location: documents.php?ok={$success_msg}");
}
exit;
