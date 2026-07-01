<?php
/**
 * download.php — Serve a file for download with access control.
 */
require_once __DIR__ . '/auth.php';
$user = require_login();
$db   = get_db();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    die('Invalid document ID.');
}

$stmt = $db->prepare('SELECT * FROM documents WHERE id=? AND is_deleted=0 LIMIT 1');
$stmt->bind_param('i', $id);
$stmt->execute();
$doc = $stmt->get_result()->fetch_assoc();

if (!$doc) {
    http_response_code(404);
    die('Document not found.');
}

// Casual users can only download shared documents
if ($user['role'] === 'casual') {
    $chk = $db->prepare(
        'SELECT id FROM document_shares WHERE document_id=? AND shared_with_user_id=? LIMIT 1'
    );
    $chk->bind_param('ii', $id, $user['id']);
    $chk->execute();
    if (!$chk->get_result()->fetch_assoc()) {
        http_response_code(403);
        die('Access denied.');
    }
}

$file_path = __DIR__ . '/uploads/' . basename($doc['storage_path']);
if (!file_exists($file_path)) {
    http_response_code(404);
    die('File not found on server.');
}

audit_log($user['id'], 'DOWNLOAD', "Downloaded '{$doc['filename']}'");

// Stream the file
header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . addslashes($doc['filename']) . '"');
header('Content-Length: ' . filesize($file_path));
header('Cache-Control: must-revalidate');
readfile($file_path);
exit;
