<?php
require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/DMS-allen/DMS-allen/',
        // REMOVED 'domain' => 'localhost' to let Incognito handle the local context layout safely
        'secure'   => false, 
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}


$error = '';
$message = '';

// Capture the logout message if passed via the URL query string
if (isset($_GET['msg']) && $_GET['msg'] === 'logged_out') {
    $message = 'You have been successfully logged out.';
}

// AUTO-LOGOUT: If a session exists, clear everything immediately instead of redirecting to the dashboard
if (!empty($_SESSION['user'])) {
    $user     = $_SESSION['user'];
    $user_id  = isset($user['id']) ? (int) $user['id'] : null;
    $username = $user['username'] ?? 'Unknown';
    
    if (function_exists('audit_log')) {
        audit_log($user_id, 'LOGOUT', "User '{$username}' auto-logged out by visiting login page");
    }

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }

    session_destroy();
    
    // FIX: Redirects back to itself using SCRIPT_NAME to prevent nested folder 404 path breaks
    header('Location: ' . $_SERVER['SCRIPT_NAME'] . '?msg=logged_out');
    exit;
}

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
        } elseif (($user['status'] ?? '') === 'frozen') {
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
<script>
    // Append an endless loop state to the active window thread history tracker
    window.history.pushState(null, "", window.location.href);
    window.onpopstate = function () {
        // If the user tries to click backward or forward, forcefully trap them right here on the login page
        window.history.pushState(null, "", window.location.href);
    };
</script>
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
    
    <!-- Error Alert block -->
    <?php if ($error): ?>
      <div class="alert alert-error" style="background: #f8d7da; color: #721c24; padding: 10px; border-radius: 4px; margin-bottom: 15px; font-size: 14px; border: 1px solid #f5c6cb;">
        <?= htmlspecialchars($error) ?>
      </div>
    <?php endif; ?>

    <!-- Success Logout Alert block -->
    <?php if ($message): ?>
      <div class="alert alert-success" style="background: #d4edda; color: #155724; padding: 10px; border-radius: 4px; margin-bottom: 15px; font-size: 14px; border: 1px solid #c3e6cb;">
        <?= htmlspecialchars($message) ?>
      </div>
    <?php endif; ?>

    <!-- FIX: Updated action attribute to dynamically point to the correct subfolder location -->
    <form method="POST" action="<?= htmlspecialchars($_SERVER['SCRIPT_NAME']) ?>">
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
