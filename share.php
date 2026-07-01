<?php
/**
 * share.php — Document sharing management.
 */
require_once __DIR__ . '/auth.php';
$user = require_role('contributor', 'admin');
$db   = get_db();

$doc_id = (int)($_GET['id'] ?? 0);
if ($doc_id <= 0) {
    header('Location: documents.php');
    exit;
}

$stmt = $db->prepare('SELECT * FROM documents WHERE id=? AND is_deleted=0 LIMIT 1');
$stmt->bind_param('i', $doc_id);
$stmt->execute();
$doc = $stmt->get_result()->fetch_assoc();
if (!$doc) {
    header('Location: documents.php?err=' . urlencode('Document not found.'));
    exit;
}

$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action  = $_POST['action'] ?? '';
    $share_uid = (int)($_POST['share_uid'] ?? 0);

    if ($action === 'add' && $share_uid > 0) {
        $chk = $db->prepare('SELECT id FROM users WHERE id=? AND role=\'casual\' LIMIT 1');
        $chk->bind_param('i', $share_uid);
        $chk->execute();
        if (!$chk->get_result()->fetch_assoc()) {
            $error = 'User not found or not a casual user.';
        } else {
            $insert = $db->prepare('INSERT INTO document_shares (document_id, shared_with_user_id) VALUES (?, ?)');
            $insert->bind_param('ii', $doc_id, $share_uid);
            if (!$insert->execute()) {
                $error = 'Already shared with that user.';
            } else {
                audit_log($user['id'], 'SHARE', "Shared '{$doc['filename']}' with user #$share_uid");
                $success = 'Document shared.';
            }
        }
    } elseif ($action === 'remove' && $share_uid > 0) {
        $remove = $db->prepare('DELETE FROM document_shares WHERE document_id=? AND shared_with_user_id=?');
        $remove->bind_param('ii', $doc_id, $share_uid);
        $remove->execute();
        audit_log($user['id'], 'UNSHARE', "Removed share of '{$doc['filename']}' from user #$share_uid");
        $success = 'Share removed.';
    }
}

// Casual users not yet shared with
$shared = $db->prepare(
    'SELECT u.id, u.username FROM document_shares ds
     JOIN users u ON ds.shared_with_user_id=u.id
     WHERE ds.document_id=?'
);
$shared->bind_param('i', $doc_id);
$shared->execute();
$shared_users = $shared->get_result()->fetch_all(MYSQLI_ASSOC);
$shared_ids   = array_column($shared_users, 'id');

$available = $db->query(
    "SELECT id, username FROM users WHERE role='casual' ORDER BY username"
)->fetch_all(MYSQLI_ASSOC);

$page_title = 'Share Document';
include __DIR__ . '/partials/header.php';
?>

<h2 class="page-title">Share: <?= htmlspecialchars($doc['filename']) ?></h2>
<p><a href="documents.php" class="btn btn-outline">&larr; Back to Documents</a></p>

<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="two-col">
  <div class="card">
    <h3>Add Share</h3>
    <form method="POST">
      <input type="hidden" name="action" value="add">
      <select name="share_uid" required>
        <option value="">— Select casual user —</option>
        <?php foreach ($available as $u): ?>
          <?php if (!in_array($u['id'], $shared_ids)): ?>
            <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['username']) ?></option>
          <?php endif; ?>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-primary">Share</button>
    </form>
  </div>

  <div class="card">
    <h3>Currently Shared With</h3>
    <?php if (empty($shared_users)): ?>
      <p>Not shared with anyone yet.</p>
    <?php else: ?>
      <ul class="share-list">
        <?php foreach ($shared_users as $su): ?>
          <li>
            <?= htmlspecialchars($su['username']) ?>
            <form method="POST" style="display:inline">
              <input type="hidden" name="action"    value="remove">
              <input type="hidden" name="share_uid" value="<?= $su['id'] ?>">
              <button class="btn btn-sm btn-danger">Remove</button>
            </form>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
