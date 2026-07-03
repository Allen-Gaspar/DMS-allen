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

// Query the active registration backlog for pending requests
$pending_count_res = $db->query("SELECT COUNT(*) as total FROM registration_requests WHERE status = 'pending'");
$pending_count = $pending_count_res ? (int)$pending_count_res->fetch_assoc()['total'] : 0;



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


// --- ADMIN REGISTRATION REQUESTS PROCESSING CODE ---
$req_message = '';
$req_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type']) && $_POST['action_type'] === 'approve_user') {
    $request_id = (int)($_POST['request_id'] ?? 0);
    $new_username = trim($_POST['generated_username'] ?? '');
    $new_password = $_POST['generated_password'] ?? '';
    $assigned_role = $_POST['assigned_role'] ?? 'casual';

    // Fetch the target application metadata
    $req_res = $db->query("SELECT * FROM registration_requests WHERE id = $request_id LIMIT 1")->fetch_assoc();

    if (!$req_res) {
        $req_error = 'Target request entry not found.';
    } elseif ($new_username === '' || $new_password === '') {
        $req_error = 'Username and Password fields must be filled to generate an active account account identity profile.';
    } else {
        // Check if username is already taken
        $check_user = $db->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        $check_user->bind_param('s', $new_username);
        $check_user->execute();
        
        if ($check_user->get_result()->num_rows > 0) {
            $req_error = 'That username is already taken. Please type a different username.';
        } else {
            // 1. Create the new user account record inside your real system users table
            $password_hash = password_hash($new_password, PASSWORD_BCRYPT);
            $full_name = $req_res['first_name'] . ' ' . $req_res['last_name'];
            
            $create_stmt = $db->prepare("INSERT INTO users (username, password_hash, email, role, status) VALUES (?, ?, ?, ?, 'active')");
            $create_stmt->bind_param('ssss', $new_username, $password_hash, $req_res['email'], $assigned_role);
            
            if ($create_stmt->execute()) {
                // 2. Update registration status flag to approved
                $db->query("UPDATE registration_requests SET status = 'approved' WHERE id = $request_id");

                // 3. Email Notification Delivery Logic [1]
                $to = $req_res['email'];
                $subject = "Welcome to FILESTAC DMS - Your Workspace Credentials";
                $login_url = "http://localhost/DMS-allen/DMS-allen/login.php";
                
                $email_content = "Hi " . $req_res['first_name'] . ",\n\n"
                               . "Your workspace request has been approved by the Admin! Here are your system credentials:\n\n"
                               . "Username: " . $new_username . "\n"
                               . "Temporary Password: " . $new_password . "\n\n"
                               . "Please log in here immediately to change your password:\n" . $login_url . "\n\n"
                               . "Best regards,\nAllen";
                               
                $headers = "From: no-reply@filestacdms.local";

                // Attempt to send email via standard PHP mail() [1]
                @mail($to, $subject, $email_content, $headers);

                $req_message = "Account successfully registered for '{$full_name}'! Credentials have been sent to {$to}.";
            } else {
                $req_error = 'Failed to record the user account in the system database.';
            }
        }
    }
}

// Fetch all pending requests to display in an administration panel table view element grid
$pending_requests = $db->query("SELECT * FROM registration_requests WHERE status = 'pending' ORDER BY created_at DESC")->fetch_all(MYSQLI_ASSOC);




$page_title = 'Admin Dashboard';
include __DIR__ . '/../partials/header.php';
?>


<h2 class="page-title">Admin Dashboard</h2>

<!-- Grid layout dynamically scaled to fit 5 columns seamlessly -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 20px; margin-bottom: 30px; font-family: sans-serif;">

    <!-- 1. TOTAL DOCUMENTS CARD -->
    <a href="../documents.php" 
       style="text-decoration: none; display: block;" 
       onmouseover="this.querySelector('.doc-card').style.transform='translateY(-4px)'; this.querySelector('.doc-card').style.boxShadow='0 8px 16px rgba(0,0,0,0.1)';" 
       onmouseout="this.querySelector('.doc-card').style.transform='translateY(0)'; this.querySelector('.doc-card').style.boxShadow='0 2px 8px rgba(0,0,0,0.05)';">
        <div class="doc-card" style="background: #fff; padding: 20px; border-radius: 8px; text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.05); border: 1px solid #eef2f5; height: 100%; box-sizing: border-box; transition: transform 0.3s ease, box-shadow 0.3s ease;">
            <h1 style="color: #2e7d32; font-size: 36px; margin: 0; font-weight: bold;">6</h1>
            <p style="color: #666; font-size: 11px; font-weight: bold; margin: 8px 0 0 0; text-transform: uppercase; letter-spacing: 0.5px;">Total Documents</p>
        </div>
    </a>

    <!-- 2. LOCKED CARD -->
    <a href="../documents.php?filter=locked" 
       style="text-decoration: none; display: block;" 
       onmouseover="this.querySelector('.locked-card').style.transform='translateY(-4px)'; this.querySelector('.locked-card').style.boxShadow='0 8px 16px rgba(0,0,0,0.1)';" 
       onmouseout="this.querySelector('.locked-card').style.transform='translateY(0)'; this.querySelector('.locked-card').style.boxShadow='0 2px 8px rgba(0,0,0,0.05)';">
        <div class="locked-card" style="background: #fff; padding: 20px; border-radius: 8px; text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.05); border: 1px solid #eef2f5; height: 100%; box-sizing: border-box; transition: transform 0.3s ease, box-shadow 0.3s ease;">
            <h1 style="color: #2e7d32; font-size: 36px; margin: 0; font-weight: bold;">1</h1>
            <p style="color: #666; font-size: 11px; font-weight: bold; margin: 8px 0 0 0; text-transform: uppercase; letter-spacing: 0.5px;">Locked</p>
        </div>
    </a>

    <!-- 4. USERS CARD -->
    <a href="../users.php" 
       style="text-decoration: none; display: block;" 
       onmouseover="this.querySelector('.users-card').style.transform='translateY(-4px)'; this.querySelector('.users-card').style.boxShadow='0 8px 16px rgba(0,0,0,0.1)';" 
       onmouseout="this.querySelector('.users-card').style.transform='translateY(0)'; this.querySelector('.users-card').style.boxShadow='0 2px 8px rgba(0,0,0,0.05)';">
        <div class="users-card" style="background: #fff; padding: 20px; border-radius: 8px; text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.05); border: 1px solid #eef2f5; height: 100%; box-sizing: border-box; transition: transform 0.3s ease, box-shadow 0.3s ease;">
            <h1 style="color: #2e7d32; font-size: 36px; margin: 0; font-weight: bold;">6</h1>
            <p style="color: #666; font-size: 11px; font-weight: bold; margin: 8px 0 0 0; text-transform: uppercase; letter-spacing: 0.5px;">Users</p>
        </div>
    </a>

    <!-- 3. IN TRASH CARD -->
    <a href="../trash.php" 
       style="text-decoration: none; display: block;" 
       onmouseover="this.querySelector('.trash-card').style.transform='translateY(-4px)'; this.querySelector('.trash-card').style.boxShadow='0 8px 16px rgba(0,0,0,0.1)';" 
       onmouseout="this.querySelector('.trash-card').style.transform='translateY(0)'; this.querySelector('.trash-card').style.boxShadow='0 2px 8px rgba(0,0,0,0.05)';">
        <div class="trash-card" style="background: #fff; padding: 20px; border-radius: 8px; text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.05); border: 1px solid #eef2f5; height: 100%; box-sizing: border-box; transition: transform 0.3s ease, box-shadow 0.3s ease;">
            <h1 style="color: #2e7d32; font-size: 36px; margin: 0; font-weight: bold;">0</h1>
            <p style="color: #666; font-size: 11px; font-weight: bold; margin: 8px 0 0 0; text-transform: uppercase; letter-spacing: 0.5px;">In Trash</p>
        </div>
    </a>

    

    <!-- 5. PENDING REQUEST ACCESS CARD -->
    <a href="admin_approvals.php" 
       style="text-decoration: none; display: block;" 
       onmouseover="this.querySelector('.pending-card').style.transform='translateY(-4px)'; this.querySelector('.pending-card').style.boxShadow='0 8px 16px rgba(0,0,0,0.1)';" 
       onmouseout="this.querySelector('.pending-card').style.transform='translateY(0)'; this.querySelector('.pending-card').style.boxShadow='0 2px 8px rgba(0,0,0,0.06)';">
        <div class="pending-card" style="background: #fff; padding: 20px; border-radius: 8px; text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.06); border: 1px solid #eef2f5; position: relative; height: 100%; box-sizing: border-box; transition: transform 0.3s ease, box-shadow 0.3s ease;">
            
            <!-- Conditional Notification Badge -->
            <?php if (isset($pending_count) && $pending_count > 0): ?>
                <span style="position: absolute; top: 10px; right: 10px; background: #e53935; color: #fff; font-size: 10px; font-weight: bold; padding: 3px 7px; border-radius: 20px; text-transform: uppercase; letter-spacing: 0.5px; box-shadow: 0 2px 4px rgba(229,57,53,0.3);">
                    NEW <?php echo $pending_count; ?>
                </span>
            <?php endif; ?>

            <h1 style="color: #d32f2f; font-size: 36px; margin: 0; font-weight: bold;">
                <?php echo isset($pending_count) ? $pending_count : 0; ?>
            </h1>
            <p style="color: #44546a; font-size: 11px; font-weight: bold; margin: 8px 0 0 0; text-transform: uppercase; letter-spacing: 0.5px;">
                Pending Requests →
            </p>
        </div>
    </a>

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