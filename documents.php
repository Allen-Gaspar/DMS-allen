<?php
/**
 * documents.php — Document browser (role-filtered).
 */
require_once __DIR__ . '/auth.php';
$user = require_login();
$db   = get_db();
$role = $user['role'];

$search = trim($_GET['search'] ?? '');
$like   = '%' . $search . '%';

// ── Fetch documents based on role ──────────────────────────────────────
if ($role === 'casual') {
    $stmt = $db->prepare(
        'SELECT d.*, u.username AS uploader_name, lk.username AS locker_name
         FROM documents d
         INNER JOIN document_shares ds ON ds.document_id=d.id AND ds.shared_with_user_id=?
         LEFT JOIN users u  ON u.id=d.uploaded_by
         LEFT JOIN users lk ON lk.id=d.locked_by
         WHERE d.is_deleted=0 AND d.filename LIKE ?
         ORDER BY d.created_at DESC'
    );
    $stmt->bind_param('is', $user['id'], $like);
    $stmt->execute();
} else {
    $stmt = $db->prepare(
        'SELECT d.*, u.username AS uploader_name, lk.username AS locker_name
         FROM documents d
         LEFT JOIN users u  ON u.id=d.uploaded_by
         LEFT JOIN users lk ON lk.id=d.locked_by
         WHERE d.is_deleted=0 AND d.filename LIKE ?
         ORDER BY d.created_at DESC'
    );
    $stmt->bind_param('s', $like);
    $stmt->execute();
}
$documents = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ── Flash messages ─────────────────────────────────────────────────────
$success = $_GET['ok']  ?? '';
$error   = $_GET['err'] ?? '';

$page_title = 'Documents';
include __DIR__ . '/partials/header.php';
?>

<div class="page-header">
  <h2 class="page-title">Documents</h2>
  <?php if ($role !== 'casual'): ?>
    <a href="upload.php" class="btn btn-primary">Upload New</a>
  <?php endif; ?>
</div>

<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars(urldecode($success)) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><?= htmlspecialchars(urldecode($error)) ?></div><?php endif; ?>

<form method="GET" action="documents.php" class="search-form">
  <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search by filename…">
  <button type="submit" class="btn btn-secondary">Search</button>
  <?php if ($search): ?><a href="documents.php" class="btn btn-outline">Clear</a><?php endif; ?>
</form>

<table class="data-table">
  <thead>
    <tr>
      <th>Filename</th>
      <th>Version</th>
      <th>Size</th>
      <th>Uploaded By</th>
      <th>Status</th>
      <th>Date</th>
      <th>Actions</th>
    </tr>
  </thead>
  <tbody>
  <?php if (empty($documents)): ?>
    <tr><td colspan="7" class="empty-row">No documents found.</td></tr>
  <?php endif; ?>
  <?php foreach ($documents as $doc): ?>
    <tr>
      <td><?= htmlspecialchars($doc['filename']) ?></td>
      <td>v<?= (int)$doc['version'] ?></td>
      <td><?= format_bytes((int)$doc['size']) ?></td>
      <td><?= htmlspecialchars($doc['uploader_name'] ?? '—') ?></td>
      <td>
        <?php if ($doc['is_locked']): ?>
          <span class="badge badge-warn">Locked by <?= htmlspecialchars($doc['locker_name'] ?? '?') ?></span>
        <?php else: ?>
          <span class="badge badge-ok">Available</span>
        <?php endif; ?>
      </td>
      <td><?= htmlspecialchars(substr($doc['created_at'], 0, 10)) ?></td>
      <td class="actions">
        <!-- Download always available -->
        <a href="download.php?id=<?= $doc['id'] ?>" class="btn btn-sm btn-outline">Download</a>

        <?php if ($role !== 'casual'): ?>
          <?php if (!$doc['is_locked']): ?>
            <a href="version_control.php?action=checkout&id=<?= $doc['id'] ?>" class="btn btn-sm btn-warn">Checkout</a>
          <?php elseif ($doc['locked_by'] == $user['id'] || $role === 'admin'): ?>
            <a href="version_control.php?action=checkin&id=<?= $doc['id'] ?>" class="btn btn-sm btn-ok">Checkin</a>
          <?php endif; ?>

          <?php if ($role === 'contributor'): ?>
            <a href="share.php?id=<?= $doc['id'] ?>" class="btn btn-sm btn-outline">Share</a>
          <?php endif; ?>

          <a href="delete.php?id=<?= $doc['id'] ?>"
             class="btn btn-sm btn-danger"
             onclick="return confirm('Delete this document?')">Delete</a>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<?php include __DIR__ . '/partials/footer.php'; ?>
<?php
function format_bytes(int $bytes): string {
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024)    return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}
?>
