<?php
/**
 * delete.php — Soft delete (contributor) or hard delete (admin).
 */
require_once __DIR__ . '/auth.php';
$user = require_role('contributor', 'admin');
$db   = get_db();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: documents.php?err=' . urlencode('Invalid document ID.'));
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

if ($user['role'] === 'admin') {
    // Hard delete: remove physical file and database record
    $file_path = __DIR__ . '/uploads/' . basename($doc['storage_path']);
    if (file_exists($file_path)) {
        unlink($file_path);
    }
    $stmt = $db->prepare('DELETE FROM documents WHERE id=?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    audit_log($user['id'], 'HARD_DELETE', "Admin hard-deleted '{$doc['filename']}'");
    header('Location: documents.php?ok=' . urlencode("'{$doc['filename']}' permanently deleted."));
} else {
    // Soft delete: flag is_deleted
    $stmt = $db->prepare('UPDATE documents SET is_deleted=1 WHERE id=?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    audit_log($user['id'], 'SOFT_DELETE', "Soft-deleted '{$doc['filename']}'");
    header('Location: documents.php?ok=' . urlencode("'{$doc['filename']}' moved to trash."));
}
exit;
