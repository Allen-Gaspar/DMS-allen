<?php
if (!isset($user)) $user = current_user();
$role = $user['role'] ?? 'casual';

function app_url($path = '') {
    // Ensure the base path starts with a leading slash '/'
    return '/DMS-allen/DMS-allen/' . $path;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($page_title ?? 'Kiwi DMS') ?> — Kiwi DMS</title>
<link rel="stylesheet" href="/DMS-allen/DMS-allen/style.css">
</head>
<body class="skin-<?= htmlspecialchars($role) ?>">

<div class="app-layout">
  <!-- Sidebar navigation -->
  <aside class="sidebar">
    <div class="sidebar-logo">
      <span class="kiwi-icon">&#x1F95D;</span>
      <span class="sidebar-brand">Kiwi DMS</span>
    </div>

    <nav class="sidebar-nav">
      <a href="<?= htmlspecialchars(app_url('dashboard.php')) ?>" class="nav-item <?= basename($_SERVER['PHP_SELF']) === 'dashboard.php' ? 'active' : '' ?>">
    Dashboard
</a>
      <a href="<?= htmlspecialchars(app_url('documents.php')) ?>" class="nav-item <?= basename($_SERVER['PHP_SELF']) === 'documents.php' ? 'active' : '' ?>">
    Documents
</a>

      <?php if ($role === 'admin'): ?>
        <a href="<?= htmlspecialchars(app_url('users.php')) ?>" class="nav-item <?= basename($_SERVER['PHP_SELF']) === 'users.php' ? 'active' : '' ?>">
          Users
        </a>
        <a href="<?= htmlspecialchars(app_url('trash.php')) ?>" class="nav-item <?= basename($_SERVER['PHP_SELF']) === 'trash.php' ? 'active' : '' ?>">
          Trash
        </a>
        <a href="<?= htmlspecialchars(app_url('audit.php')) ?>" class="nav-item <?= basename($_SERVER['PHP_SELF']) === 'audit.php' ? 'active' : '' ?>">
          Audit Log
        </a>
      <?php endif; ?>

      <?php if ($role === 'contributor'): ?>
        <a href="<?= htmlspecialchars(app_url('upload.php')) ?>" class="nav-item <?= basename($_SERVER['PHP_SELF']) === 'upload.php' ? 'active' : '' ?>">
          Upload
        </a>
      <?php endif; ?>
    </nav>

    <div class="sidebar-footer">
      <div class="user-pill">
        <span class="user-role-dot"></span>
        <div>
          <div class="user-name"><?= htmlspecialchars($user['username']) ?></div>
          <div class="user-role"><?= ucfirst($role) ?></div>
        </div>
      </div>
      <a href="<?= htmlspecialchars(app_url('logout.php')) ?>" class="btn btn-outline btn-sm btn-full">Logout</a>
    </div>
  </aside>

  <!-- Main content -->
  <main class="main-content">
