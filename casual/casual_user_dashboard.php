<?php
/**
 * casual_dashboard.php — Dedicated Casual Dashboard.
 */
require_once __DIR__ . '/../auth.php';
$user = require_login();

if ($user['role'] !== 'casual') {
    header('Location: dashboard.php');
    exit;
}

$db = get_db();
$stats = [];

$stmt = $db->prepare(
    'SELECT COUNT(*) FROM document_shares ds
     JOIN documents d ON ds.document_id=d.id
     WHERE ds.shared_with_user_id=? AND d.is_deleted=0'
);
$stmt->bind_param('i', $user['id']);
$stmt->execute();
$stats['shared_with_me'] = (int) $stmt->get_result()->fetch_row()[0];

$page_title = 'Casual Dashboard';
include __DIR__ . '/../partials/header.php';
?>

<h2 class="page-title">Casual Dashboard</h2>

<div class="stats-grid">
  <div class="stat-card">
    <div class="stat-number"><?= (int)$stats['shared_with_me'] ?></div>
    <div class="stat-label">Documents Shared With Me</div>
  </div>
</div>

<p><a href="../documents.php" class="btn btn-outline">Browse Shared Documents &rarr;</a></p>

<?php include __DIR__ . '/../partials/footer.php'; ?>