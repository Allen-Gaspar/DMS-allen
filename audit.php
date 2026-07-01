<?php
/**
 * audit.php — Admin-only audit log viewer.
 */
require_once __DIR__ . '/auth.php';
$user = require_role('admin');
$db   = get_db();

$limit  = 100;
$offset = max(0, (int)($_GET['offset'] ?? 0));

$logs = $db->prepare(
    'SELECT al.*, u.username FROM audit_logs al
     LEFT JOIN users u ON al.user_id=u.id
     ORDER BY al.timestamp DESC
     LIMIT ? OFFSET ?'
);
$logs->bind_param('ii', $limit, $offset);
$logs->execute();
$entries = $logs->get_result()->fetch_all(MYSQLI_ASSOC);

$total = (int) $db->query('SELECT COUNT(*) FROM audit_logs')->fetch_row()[0];

$page_title = 'Audit Log';
include __DIR__ . '/partials/header.php';
?>

<h2 class="page-title">Audit Log</h2>
<p class="muted"><?= number_format($total) ?> total entries</p>

<table class="data-table">
  <thead>
    <tr><th>Timestamp</th><th>User</th><th>Action</th><th>Description</th></tr>
  </thead>
  <tbody>
  <?php if (empty($entries)): ?>
    <tr><td colspan="4" class="empty-row">No audit entries yet.</td></tr>
  <?php endif; ?>
  <?php foreach ($entries as $log): ?>
    <tr>
      <td style="white-space:nowrap"><?= htmlspecialchars($log['timestamp']) ?></td>
      <td><?= htmlspecialchars($log['username'] ?? '—') ?></td>
      <td><span class="badge"><?= htmlspecialchars($log['action_type']) ?></span></td>
      <td><?= htmlspecialchars($log['description']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<div class="pagination">
  <?php if ($offset > 0): ?>
    <a href="audit.php?offset=<?= max(0, $offset - $limit) ?>" class="btn btn-outline">&larr; Newer</a>
  <?php endif; ?>
  <?php if (($offset + $limit) < $total): ?>
    <a href="audit.php?offset=<?= $offset + $limit ?>" class="btn btn-outline">Older &rarr;</a>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
