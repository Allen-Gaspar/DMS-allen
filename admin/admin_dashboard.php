<?php

header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/DMS-allen/DMS-allen/',
        'secure'   => false,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}


require_once __DIR__ . '/../auth.php';
$user = require_role('admin'); // Make sure this runs AFTER the session scope is active!
$db = get_db();


// Fetch Admin Stats
$stats = [];
$stats['total_docs']   = (int) $db->query('SELECT COUNT(*) FROM documents WHERE is_deleted=0')->fetch_row()[0];
$stats['locked_docs']  = (int) $db->query('SELECT COUNT(*) FROM documents WHERE is_locked=1 AND is_deleted=0')->fetch_row()[0];
$stats['deleted_docs'] = (int) $db->query('SELECT COUNT(*) FROM documents WHERE is_deleted=1')->fetch_row()[0];
$stats['total_users']  = (int) $db->query('SELECT COUNT(*) FROM users')->fetch_row()[0];

$recent = $db->query(
    'SELECT al.*, u.username FROM audit_logs al
     LEFT JOIN users u ON al.user_id=u.id
     ORDER BY al.timestamp DESC LIMIT 10'
)->fetch_all(MYSQLI_ASSOC);



$page_title = 'Admin Dashboard';
include __DIR__ . '/../partials/header.php';
?>


<h2 class="page-title">Admin Dashboard</h2>

<div class="stats-grid">
  <div class="stat-card">
    <div class="stat-number"><?= (int)$stats['total_docs'] ?></div>
    <div class="stat-label">Total Documents</div>
  </div>
  <div class="stat-card">
    <div class="stat-number"><?= (int)$stats['locked_docs'] ?></div>
    <div class="stat-label">Locked</div>
  </div>
  <div class="stat-card">
    <div class="stat-number"><?= (int)$stats['deleted_docs'] ?></div>
    <div class="stat-label">In Trash</div>
  </div>
  <div class="stat-card">
    <div class="stat-number"><?= (int)$stats['total_users'] ?></div>
    <div class="stat-label">Users</div>
  </div>
</div>

<h3 class="section-title">Recent Activity</h3>
<table class="data-table">
  <thead><tr><th>Time</th><th>User</th><th>Action</th><th>Description</th></tr></thead>
  <tbody>
  <?php foreach ($recent as $log): ?>
    <tr>
      <td><?= htmlspecialchars($log['timestamp']) ?></td>
      <td><?= htmlspecialchars($log['username'] ?? '—') ?></td>
      <td><span class="badge"><?= htmlspecialchars($log['action_type']) ?></span></td>
      <td><?= htmlspecialchars($log['description']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<?php include __DIR__ . '/../partials/footer.php'; ?>