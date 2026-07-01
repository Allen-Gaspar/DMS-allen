<?php
/**
 * trash.php — Admin-only trash bin (restore or permanently purge).
 */
require_once __DIR__ . '/auth.php';
$user = require_role('admin');
$db   = get_db();

$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);

    $stmt = $db->prepare('SELECT * FROM documents WHERE id=? AND is_deleted=1 LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $doc = $stmt->get_result()->fetch_assoc();

    if (!$doc) {
        $error = 'Document not found in trash.';
    } elseif ($action === 'restore') {
        $stmt = $db->prepare('UPDATE documents SET is_deleted=0 WHERE id=?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        audit_log($user['id'], 'RESTORE', "Restored '{$doc['filename']}' from trash");
        $success = "'{$doc['filename']}' restored.";
    } elseif ($action === 'purge') {
        $file_path = __DIR__ . '/uploads/' . basename($doc['storage_path']);
        if (file_exists($file_path)) unlink($file_path);
        $stmt = $db->prepare('DELETE FROM documents WHERE id=?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        audit_log($user['id'], 'PURGE', "Permanently purged '{$doc['filename']}'");
        $success = "'{$doc['filename']}' permanently deleted.";
    }
}

$trashed = $db->query(
    'SELECT d.*, u.username AS uploader_name FROM documents d
     LEFT JOIN users u ON u.id=d.uploaded_by
     WHERE d.is_deleted=1
     ORDER BY d.created_at DESC'
)->fetch_all(MYSQLI_ASSOC);

$page_title = 'Trash';
include __DIR__ . '/partials/header.php';
?>

<h2 class="page-title">Trash</h2>

<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<table class="data-table">
  <thead>
    <tr><th>Filename</th><th>Version</th><th>Uploaded By</th><th>Date</th><th>Actions</th></tr>
  </thead>
  <tbody>
  <?php if (empty($trashed)): ?>
    <tr><td colspan="5" class="empty-row">Trash is empty.</td></tr>
  <?php endif; ?>
  <?php foreach ($trashed as $doc): ?>
    <tr>
      <td><?= htmlspecialchars($doc['filename']) ?></td>
      <td>v<?= (int)$doc['version'] ?></td>
      <td><?= htmlspecialchars($doc['uploader_name'] ?? '—') ?></td>
      <td><?= htmlspecialchars(substr($doc['created_at'], 0, 10)) ?></td>
      <td class="actions">
        <form method="POST" style="display:inline">
          <input type="hidden" name="id"     value="<?= $doc['id'] ?>">
          <input type="hidden" name="action" value="restore">
          <button class="btn btn-sm btn-ok">Restore</button>
        </form>
        <form method="POST" style="display:inline"
              onsubmit="return confirm('Permanently delete this file? This cannot be undone.')">
          <input type="hidden" name="id"     value="<?= $doc['id'] ?>">
          <input type="hidden" name="action" value="purge">
          <button class="btn btn-sm btn-danger">Purge</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<?php include __DIR__ . '/partials/footer.php'; ?>
