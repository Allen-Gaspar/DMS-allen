<?php
/**
 * cont/contributor_dashboard.php — Dedicated Contributor Dashboard.
 */
// CHANGE THIS LINE (Line 5): Add /../ to step up to the root folder
require_once __DIR__ . '/../auth.php'; 

$user = require_login();

// Guard checking role permissions
if ($user['role'] !== 'contributor') {
    // Step out to find the root router dashboard
    header('Location: ../dashboard.php');
    exit;
}

$db = get_db();
$stats = [];

$stmt = $db->prepare('SELECT COUNT(*) FROM documents WHERE uploaded_by=? AND is_deleted=0');
$stmt->bind_param('i', $user['id']);
$stmt->execute();
$stats['my_docs'] = (int) $stmt->get_result()->fetch_row()[0];

$stmt = $db->prepare('SELECT COUNT(*) FROM documents WHERE locked_by=? AND is_locked=1');
$stmt->bind_param('i', $user['id']);
$stmt->execute();
$stats['checked_out'] = (int) $stmt->get_result()->fetch_row()[0];

$page_title = 'Contributor Dashboard';
include __DIR__ . '/../partials/header.php';
?>

<h2 class="page-title">Contributor Dashboard</h2>

<div class="stats-grid">
  <div class="stat-card">
    <div class="stat-number"><?= (int)$stats['my_docs'] ?></div>
    <div class="stat-label">My Documents</div>
  </div>
  <div class="stat-card">
    <div class="stat-number"><?= (int)$stats['checked_out'] ?></div>
    <div class="stat-label">Checked Out by Me</div>
  </div>
</div>

<h3 class="section-title">Quick Upload</h3>
<div class="upload-zone">
  <?php if (isset($_GET['upload_ok'])): ?>
    <div class="alert alert-success">File uploaded successfully!</div>
  <?php elseif (isset($_GET['upload_err'])): ?>
    <div class="alert alert-error"><?= htmlspecialchars(urldecode($_GET['upload_err'])) ?></div>
  <?php endif; ?>
  <form method="POST" action="upload.php" enctype="multipart/form-data">
    <p class="upload-hint">Allowed: .pdf, .docx, .xlsx, .png — Max 50 MB</p>
    <input type="file" name="document" id="document" class="file-input" required>
    <label for="document" class="btn btn-primary">Choose File</label>
    <button type="submit" class="btn btn-secondary">Upload</button>
  </form>
</div>

<p><a href="documents.php" class="btn btn-outline">View All Documents &rarr;</a></p>

<?php include __DIR__ . '/../partials/footer.php'; ?>