<?php
/**
 * users.php — Admin-only user management panel.
 */
require_once __DIR__ . '/auth.php';
$user = require_role('admin');
$db   = get_db();

$success = '';
$error   = '';

// ── Handle POST actions ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $uname  = trim($_POST['username'] ?? '');
        $email  = trim($_POST['email'] ?? '');
        $pass   = $_POST['password'] ?? '';
        $role   = $_POST['role'] ?? '';
        $roles  = ['admin', 'contributor', 'casual'];

        if (!$uname || !$email || !$pass || !in_array($role, $roles, true)) {
            $error = 'All fields are required and role must be valid.';
        } elseif (strlen($pass) < 6) {
            $error = 'Password must be at least 6 characters.';
        } else {
            try {
                $hash = password_hash($pass, PASSWORD_DEFAULT);
                $stmt = $db->prepare('INSERT INTO users (username,email,password_hash,role) VALUES (?,?,?,?)');
                $stmt->bind_param('ssss', $uname, $email, $hash, $role);
                $stmt->execute();
                audit_log($user['id'], 'USER_CREATE', "Created user '$uname' ($role)");
                $success = "User '$uname' created.";
            } catch (Exception $e) {
                $error = 'Username or email already exists.';
            }
        }
    } elseif ($action === 'toggle_status') {
        $uid    = (int)($_POST['uid'] ?? 0);
        $status = $_POST['status'] ?? '';
        if ($uid > 0 && in_array($status, ['active', 'frozen'], true)) {
            $stmt = $db->prepare('UPDATE users SET status=? WHERE id=?');
            $stmt->bind_param('si', $status, $uid);
            $stmt->execute();
            audit_log($user['id'], 'USER_STATUS', "Set user #$uid status to '$status'");
            $success = "User status updated.";
        }
    } elseif ($action === 'reset_password') {
        $uid  = (int)($_POST['uid'] ?? 0);
        $pass = $_POST['new_password'] ?? '';
        if ($uid > 0 && strlen($pass) >= 6) {
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            $stmt = $db->prepare('UPDATE users SET password_hash=? WHERE id=?');
            $stmt->bind_param('si', $hash, $uid);
            $stmt->execute();
            audit_log($user['id'], 'USER_PASSWORD_RESET', "Reset password for user #$uid");
            $success = "Password reset successfully.";
        } else {
            $error = 'Password must be at least 6 characters.';
        }
    } elseif ($action === 'delete') {
        $uid = (int)($_POST['uid'] ?? 0);
        if ($uid === $user['id']) {
            $error = 'Cannot delete your own account.';
        } elseif ($uid > 0) {
            $stmt = $db->prepare('SELECT username FROM users WHERE id=?');
            $stmt->bind_param('i', $uid);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt = $db->prepare('DELETE FROM users WHERE id=?');
            $stmt->bind_param('i', $uid);
            $stmt->execute();
            audit_log($user['id'], 'USER_DELETE', "Deleted user '{$row['username']}'");
            $success = "User deleted.";
        }
    }
}

$all_users = $db->query('SELECT * FROM users ORDER BY created_at DESC')->fetch_all(MYSQLI_ASSOC);

$page_title = 'User Management';
include __DIR__ . '/partials/header.php';
?>

<h2 class="page-title">User Management</h2>

<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<!-- Create user form -->
<div class="card">
  <h3>Create New User</h3>
  <form method="POST" action="users.php" class="inline-form">
    <input type="hidden" name="action" value="create">
    <input type="text"     name="username" placeholder="Username" required>
    <input type="email"    name="email"    placeholder="Email" required>
    <input type="password" name="password" placeholder="Password (min 6)" required>
    <select name="role" required>
      <option value="">— Role —</option>
      <option value="admin">Admin</option>
      <option value="contributor">Contributor</option>
      <option value="casual">Casual</option>
    </select>
    <button type="submit" class="btn btn-primary">Create User</button>
  </form>
</div>

<!-- User grid -->
<table class="data-table">
  <thead>
    <tr><th>Username</th><th>Email</th><th>Role</th><th>Status</th><th>Created</th><th>Actions</th></tr>
  </thead>
  <tbody>
  <?php foreach ($all_users as $u): ?>
    <tr>
      <td><?= htmlspecialchars($u['username']) ?><?= $u['id'] == $user['id'] ? ' <em>(you)</em>' : '' ?></td>
      <td><?= htmlspecialchars($u['email']) ?></td>
      <td><span class="badge"><?= $u['role'] ?></span></td>
      <td>
        <span class="badge <?= $u['status'] === 'active' ? 'badge-ok' : 'badge-warn' ?>">
          <?= $u['status'] ?>
        </span>
      </td>
      <td><?= htmlspecialchars(substr($u['created_at'], 0, 10)) ?></td>
      <td class="actions">
        <?php if ($u['id'] !== $user['id']): ?>
          <!-- Toggle freeze -->
          <form method="POST" action="users.php" style="display:inline">
            <input type="hidden" name="action" value="toggle_status">
            <input type="hidden" name="uid" value="<?= $u['id'] ?>">
            <?php if ($u['status'] === 'active'): ?>
              <input type="hidden" name="status" value="frozen">
              <button class="btn btn-sm btn-warn">Freeze</button>
            <?php else: ?>
              <input type="hidden" name="status" value="active">
              <button class="btn btn-sm btn-ok">Unfreeze</button>
            <?php endif; ?>
          </form>
          <!-- Reset password -->
          <form method="POST" action="users.php" style="display:inline"
                onsubmit="var p=prompt('New password for <?= htmlspecialchars($u['username']) ?>:');if(!p){return false;}this.new_password.value=p;">
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="uid" value="<?= $u['id'] ?>">
            <input type="hidden" name="new_password" value="">
            <button class="btn btn-sm btn-outline">Reset PW</button>
          </form>
          <!-- Delete -->
          <form method="POST" action="users.php" style="display:inline"
                onsubmit="return confirm('Delete <?= htmlspecialchars($u['username']) ?>?')">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="uid" value="<?= $u['id'] ?>">
            <button class="btn btn-sm btn-danger">Delete</button>
          </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<?php include __DIR__ . '/partials/footer.php'; ?>
