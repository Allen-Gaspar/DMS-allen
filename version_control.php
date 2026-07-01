<?php
/**
 * version_control.php — Checkout (lock) and checkin (unlock + version bump).
 */
require_once __DIR__ . '/auth.php';
$user = require_role('contributor', 'admin');
$db   = get_db();

$action = $_GET['action'] ?? '';
$id     = (int)($_GET['id'] ?? 0);

if ($id <= 0 || !in_array($action, ['checkout', 'checkin'], true)) {
    header('Location: documents.php?err=' . urlencode('Invalid request.'));
    exit;
}

$stmt = $db->prepare('SELECT * FROM documents WHERE id=? AND is_deleted=0 LIMIT 1');
$stmt->bind_param('i', $id);
$stmt->execute();
$doc = $stmt->get_result()->fetch_assoc();

if (!$doc) {
    header('Location: documents.php?err=' . urlencode('Document not found.'));
    exit;
}

if ($action === 'checkout') {
    if ($doc['is_locked']) {
        header('Location: documents.php?err=' . urlencode("Document is already locked."));
        exit;
    }
    $stmt = $db->prepare('UPDATE documents SET is_locked=1, locked_by=? WHERE id=?');
    $stmt->bind_param('ii', $user['id'], $id);
    $stmt->execute();
    audit_log($user['id'], 'CHECKOUT', "Checked out '{$doc['filename']}'");
    header('Location: documents.php?ok=' . urlencode("'{$doc['filename']}' checked out."));
    exit;
}

// Checkin
if (!$doc['is_locked']) {
    header('Location: documents.php?err=' . urlencode('Document is not checked out.'));
    exit;
}
if ((int)$doc['locked_by'] !== $user['id'] && $user['role'] !== 'admin') {
    header('Location: documents.php?err=' . urlencode('You did not check out this document.'));
    exit;
}

$new_version = (int)$doc['version'] + 1;
$stmt = $db->prepare('UPDATE documents SET is_locked=0, locked_by=NULL, version=? WHERE id=?');
$stmt->bind_param('ii', $new_version, $id);
$stmt->execute();
audit_log($user['id'], 'CHECKIN', "Checked in '{$doc['filename']}' — now version $new_version");
header('Location: documents.php?ok=' . urlencode("'{$doc['filename']}' checked in as version $new_version."));
exit;
