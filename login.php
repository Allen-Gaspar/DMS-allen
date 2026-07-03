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
<!-- HEADER OVERRIDE: Reduced padding down to 8px top/bottom to make the navbar smaller -->
<header class="landing-header" style="padding-top: 19px; padding-bottom: 18px; min-height: unset; display: flex; align-items: center; justify-content: space-between;">
    <div class="landing-brand" style="display: flex; align-items: center; gap: 10px;">
        <!-- CLICKABLE LOGO PREVIEW TRIGGER -->
        <a href="#" onclick="openLogoPreview()" style="text-decoration: none; display: flex; align-items: center;" title="Preview Logo">
            <img src="filestac.png" alt="FILESTAC DMS Logo" class="filestac-icon" style="width: 32px; height: 32px; object-fit: contain; display: block;">
        </a>
        <span style="font-weight: bold; font-size: 18px; letter-spacing: 0.5px;">FILESTAC DMS</span>
    </div>
    <nav class="landing-nav" style="display: flex; align-items: center;">
        <a href="index.php" class="btn btn-outline" style="padding-top: 5px; padding-bottom: 5px; font-size: 13px;">Home</a>
    </nav>
</header>


<div class="login-wrap">
  <div class="login-box">
    <div class="login-logo" style="text-align: center; margin-bottom: 25px; font-family: sans-serif;">
    <!-- LOGO FIX: Non-clickable static local image layout -->
    <img src="filestac.png" alt="FILESTAC DMS Logo" class="filestac-icon" style="width: 85px; height: 85px; object-fit: contain; display: block; margin: 0 auto 15px auto;">
    
    <h1 style="margin: 0; font-size: 24px; font-weight: bold; color: #333; letter-spacing: 0.5px;">FILESTAC DMS</h1>
    <p class="subtitle" style="margin: 5px 0 0 0; color: #666; font-size: 14px;">Document Management System</p>
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
      <button type="submit" class="btn btn-primary btn-full" style="margin-top: 10px;">Login</button>
    </form>
    <p class="login-note">No self-registration. Fill out the contact us form first.</p>
  </div>
</div>

<!-- SYSTEM PREVIEW MODAL CONTAINER -->
<div id="logoPreviewModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; justify-content: center; align-items: center; padding: 20px; box-sizing: border-box;">
    <div style="background: #ffffff; padding: 30px; border-radius: 12px; width: 100%; max-width: 400px; box-shadow: 0 10px 25px rgba(0,0,0,0.2); position: relative; text-align: center; box-sizing: border-box; transform: scale(0.9); transition: transform 0.3s ease;">
        
        <span onclick="closeLogoPreview()" style="position: absolute; top: 12px; right: 18px; cursor: pointer; font-size: 24px; font-weight: bold; color: #aaa; transition: color 0.2s;" onmouseover="this.style.color='#333'" onmouseout="this.style.color='#aaa'">&times;</span>
        
        <img src="filestac.png" alt="Filestac DMS Workspace Logo" style="max-width: 100%; height: auto; display: block; margin: 10px auto; border-radius: 4px; object-fit: contain;">
        
    </div>
</div>

<!-- SMOOTH MODAL ANIMATION ENGINE -->
<script>
    function openLogoPreview() {
        const modal = document.getElementById('logoPreviewModal');
        if (modal) {
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
            setTimeout(() => {
                modal.firstElementChild.style.transform = 'scale(1)';
            }, 10);
        }
    }

    function closeLogoPreview() {
        const modal = document.getElementById('logoPreviewModal');
        if (modal) {
            modal.firstElementChild.style.transform = 'scale(0.9)';
            modal.style.display = 'none';
            document.body.style.overflow = '';
        }
    }

    window.onclick = function(event) {
        const modal = document.getElementById('logoPreviewModal');
        if (event.target === modal) {
            closeLogoPreview();
        }
    }
</script>

</body>
</html>
