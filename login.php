<?php
require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// Already logged in — redirect
if (!empty($_SESSION['user'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Username and password are required.';
    } else {
        $db   = get_db();
        $stmt = $db->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        $isValidPassword = password_verify($password, $user['password_hash'] ?? '');


        if (!$user || !$isValidPassword) {
            $error = 'Invalid username or password.';
        } elseif ($user['status'] === 'frozen') {
            $error = 'Your account has been frozen. Contact an administrator.';
        } else {
            // Regenerate session ID to prevent fixation
            session_regenerate_id(true);
            $_SESSION['user'] = [
                'id'       => (int) $user['id'],
                'username' => $user['username'],
                'email'    => $user['email'],
                'role'     => $user['role'],
                'status'   => $user['status'],
            ];
            audit_log((int) $user['id'], 'LOGIN', "User '{$user['username']}' logged in");
            header('Location: dashboard.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Login</title>
<link rel="stylesheet" href="style.css">
</head>
<body class="skin-login">
  <header class="landing-header">
    <div class="landing-brand">
      <span class="kiwi-icon">&#x1F95D;</span>
      <span>Kiwi DMS</span>
    </div>
    <nav class="landing-nav">
      <a href="index.php" class="btn btn-outline">Home</a>
    </nav>
  </header>
<div class="login-wrap">
  <div class="login-box">
    <div class="login-logo">
      <span class="kiwi-icon">&#x1F95D;</span>
      <h1>Kiwi DMS</h1>
      <p class="subtitle">Document Management System</p>
    </div>
    <?php if ($error): ?>
      <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <form method="POST" action="login.php">
      <div class="form-group">
        <label for="username">Username</label>
        <input type="text" id="username" name="username" autocomplete="username"
               value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required>
      </div>
      <div class="form-group">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" autocomplete="current-password" required>
      </div>
      <button type="submit" class="btn btn-primary btn-full">Login</button>
    </form>
    <p class="login-note">No self-registration. Contact your admin for access.</p>
  </div>
</div>
</body>
</html>
