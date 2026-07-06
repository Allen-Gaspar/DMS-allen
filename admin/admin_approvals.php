<?php
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Safely pull source files out of your subfolder directory structures
require_once __DIR__ . '/../PHPMailer/src/Exception.php';
require_once __DIR__ . '/../PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../PHPMailer/src/SMTP.php';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/DMS-allen/DMS-allen/',
        'secure'   => false, // Set to true if running on HTTPS
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

// Ensure auth.php is loaded correctly from the immediate parent directory
require_once __DIR__ . '/../auth.php';
// Protect the route using your core auth framework handler
$user = Auth::requireRole('admin'); 
$db = get_db();

$message = '';
$error = '';
$show_success_modal = false;
$approved_user_details = [];

// --- PROCESS APPROVAL AND ACCOUNT CREATION ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type'])) {
    $action = $_POST['action_type'];
    $request_id = (int)($_POST['request_id'] ?? 0);

    if ($action === 'approve_user') {
        $new_username = trim($_POST['generated_username'] ?? '');
        $new_password = $_POST['generated_password'] ?? '';
        $assigned_role = $_POST['assigned_role'] ?? 'casual';

        // Fetching ALL required column blocks using your prepared statement
        $stmt_fetch = $db->prepare("SELECT id, first_name, last_name, email, phone, reasons FROM registration_requests WHERE id = ? LIMIT 1");
        $stmt_fetch->bind_param('i', $request_id);
        $stmt_fetch->execute();
        $req_res = $stmt_fetch->get_result()->fetch_assoc();

        if (!$req_res) {
            $error = 'Target access request record was not found.';
        } elseif ($new_username === '' || $new_password === '') {
            $error = 'Both Username and Password fields are required to issue active credentials.';
        } else {
            // Check if username is already taken in the system users table
            $check_user = $db->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
            $check_user->bind_param('s', $new_username);
            $check_user->execute();
            
            if ($check_user->get_result()->num_rows > 0) {
                $error = "The username '{$new_username}' is already taken. Please try another one.";
            } else {
                // 1. Insert account profile parameters into system users table
                $password_hash = password_hash($new_password, PASSWORD_BCRYPT);
                
                $create_stmt = $db->prepare("INSERT INTO users (username, password_hash, email, role, status) VALUES (?, ?, ?, ?, 'active')");
                $create_stmt->bind_param('ssss', $new_username, $password_hash, $req_res['email'], $assigned_role);
                
                if ($create_stmt->execute()) {
                    $new_user_id = (int)$db->insert_id;

                    // 2. Safely update request status tracker flag to approved
                    $update_stmt = $db->prepare("UPDATE registration_requests SET status = 'approved' WHERE id = ?");
                    $update_stmt->bind_param('i', $request_id);
                    $update_stmt->execute();

                    // 3. Log the creation event inside your audit trail table
                    if (function_exists('audit_log')) {
                        audit_log($user['id'], 'USER_CREATION', "Approved request ID {$request_id}. Created account profile '{$new_username}' with role '{$assigned_role}'");
                    }

                    // 4. Send Confirmation and Activation Email via PHPMailer
                    $mail = new PHPMailer(true);

                    try {
                        // --- SERVER CONFIGURATION FOR GMAIL ---
                        $mail->SMTPDebug  = 0;                                      
                        $mail->isSMTP();                                            
                        $mail->Host       = 'smtp.gmail.com';                     
                        $mail->SMTPAuth   = true;                                   
                        $mail->Username   = 'len.10212005@gmail.com';         
                        $mail->Password   = 'ezcc bxnp nhks qyrc';                     
                        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;         
                        $mail->Port       = 587;                                    

                        // --- THE FIX FOR SSL CERTIFICATE VERIFY FAILED ---
                        $mail->SMTPOptions = [
                            'ssl' => [
                                'verify_peer'       => false,
                                'verify_peer_name'  => false,
                                'allow_self_signed' => true
                            ]
                        ];

                        // --- RECIPIENTS ---
                        $mail->setFrom('len.10212005@gmail.com', 'FILESTAC DMS Workspace');
                        $mail->addAddress($req_res['email'], $req_res['first_name'] . ' ' . $req_res['last_name']);

                        // --- CONTENT SPECS ---
                        $login_url = "http://localhost/DMS-allen/DMS-allen/login.php";
                        $mail->isHTML(true);
                        $mail->Subject = 'Your FILESTAC DMS Workspace Account Is Ready';
                        
                        $mail->Body = "
                            <div style='font-family: sans-serif; padding: 20px; line-height: 1.6; color: #333;'>
                                <h2 style='color: #2e7d32;'>Hello " . htmlspecialchars($req_res['first_name']) . ",</h2>
                                <p>Good news! Your access request for <strong>FILESTAC DMS</strong> has been approved.</p>
                                <p>An administrator has successfully provisioned your new system user profile:</p>
                                <div style='background: #f8f9fa; border-left: 4px solid #2e7d32; padding: 15px; margin: 15px 0;'>
                                    <strong>Username:</strong> <code>" . htmlspecialchars($new_username) . "</code><br>
                                    <strong>Temporary Password:</strong> <code>" . htmlspecialchars($new_password) . "</code>
                                </div>
                                <p style='margin-top: 25px;'>
                                    <a href='" . $login_url . "' style='background: #2e7d32; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; display: inline-block; font-weight: bold;'>Go to Workspace Login</a>
                                </p>
                                <p style='font-size: 12px; color: #666; margin-top: 30px;'>
                                    <em>Note: For maximum data safety, please navigate directly to your account settings layout and update this system-generated password immediately upon logging in.</em>
                                </p>
                                <hr style='border: 0; border-top: 1px solid #eee; margin-top: 30px;'>
                                <p style='font-size: 13px; color: #888;'>Best regards,<br>Allen Gabriel S. Gaspar</p>
                            </div>
                        ";
                        
                        $mail->AltBody = "Hello " . $req_res['first_name'] . ",\n\n"
                                       . "Good news! Your access request for FILESTAC DMS has been approved.\n\n"
                                       . "Username: " . $new_username . "\n"
                                       . "Temporary Password: " . $new_password . "\n\n"
                                       . "Login Here: " . $login_url . "\n\n"
                                       . "Best regards,\nAllen";

                        $mail->send();
                        $message = "Account successfully provisioned for " . htmlspecialchars($req_res['first_name'] . ' ' . $req_res['last_name']) . " and verification email dispatched.";
                        
                    } catch (Exception $e) {
                        $error = "Account provisioned successfully, but confirmation email could not be delivered. Mailer Error: {$mail->ErrorInfo}";
                    }

                    // Flag to trigger the success approval feedback modal view layout
                    $show_success_modal = true;
                    $approved_user_details = [
                        'name' => $req_res['first_name'] . ' ' . $req_res['last_name'],
                        'email' => $req_res['email'],
                        'username' => $new_username,
                        'password' => $new_password,
                        'role' => $assigned_role
                    ];
                } else {
                     $error = 'Failed to save account credentials: ' . htmlspecialchars($create_stmt->error);
                }
            }
        }
    } elseif ($action === 'reject_user') {
        $update_stmt = $db->prepare("UPDATE registration_requests SET status = 'rejected' WHERE id = ?");
        $update_stmt->bind_param('i', $request_id);
        $update_stmt->execute();
        $message = 'Access request successfully declined and removed from the active grid queue.';
    }
}

// CRITICAL FIX: Always query this list AFTER the approval code logic runs so that the updated status takes immediate effect.
$pending_list = $db->query("SELECT * FROM registration_requests WHERE status = 'pending' ORDER BY created_at DESC")->fetch_all(MYSQLI_ASSOC);

$page_title = 'Access Requests Approvals';
include __DIR__ . '/../partials/header.php';
?>

<style>
.custom-modal-overlay {
    position: fixed; top: 0; left: 0; width: 100%; height: 100%;
    background: rgba(0,0,0,0.6); display: flex; align-items: center;
    justify-content: center; z-index: 9999;
}
.custom-modal-card {
    background: #fff; padding: 25px; border-radius: 8px; max-width: 450px;
    width: 100%; box-shadow: 0 4px 25px rgba(0,0,0,0.3); animation: modalFadeIn 0.3s cubic-bezier(0.16, 1, 0.3, 1);
}
.credential-well {
    background: #f8f9fa; border: 1px solid #e2e8f0; padding: 15px;
    border-radius: 6px; margin: 15px 0; font-family: monospace; font-size: 14px; line-height: 1.5;
}
@keyframes modalFadeIn { from { opacity:0; transform:translateY(-10px); } to { opacity:1; transform:translateY(0); } }
</style>

<div class="approvals-container" style="padding: 20px 0;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 25px;">
        <h2 class="page-title" style="margin:0;">Workspace Access Approvals</h2>
        <a href="admin_dashboard.php" class="btn btn-outline" style="font-size:14px; text-decoration:none;">&larr; Back to Dashboard</a>
    </div>

    <?php if ($error): ?>
      <div class="alert alert-error" style="background: #f8d7da; color: #721c24; padding: 12px; border-radius: 4px; margin-bottom: 20px; font-size: 14px; border: 1px solid #f5c6cb;"><?= $error ?></div>
    <?php endif; ?>

    <?php if ($message): ?>
      <div class="alert alert-success" style="background: #d4edda; color: #155724; padding: 12px; border-radius: 4px; margin-bottom: 20px; font-size: 14px; border: 1px solid #c3e6cb;"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <?php if ($show_success_modal): ?>
    <div class="custom-modal-overlay" id="approvalModal">
        <div class="custom-modal-card">
            <h3 style="color: #2e7d32; margin-top: 0; display:flex; align-items:center; gap:8px;">
                <span>✅</span> Account Approved Successfully!
            </h3>
            <p style="font-size: 14px; color: #4a5568;">
                The user account for <strong><?= htmlspecialchars($approved_user_details['name']) ?></strong> has been created.
            </p>
            <div class="credential-well">
                <strong>Username:</strong> <?= htmlspecialchars($approved_user_details['username']) ?><br>
                <strong>Password:</strong> <?= htmlspecialchars($approved_user_details['password']) ?><br>
                <strong>Assigned Role:</strong> <?= ucfirst(htmlspecialchars($approved_user_details['role'])) ?><br>
                <strong>Email Address:</strong> <?= htmlspecialchars($approved_user_details['email']) ?>
            </div>
            <button type="button" class="btn btn-primary" style="width:100%; padding:10px; border-radius:4px; cursor:pointer;" onclick="closeApprovalModal()">Done & Refresh</button>
        </div>
    </div>
    <?php endif; ?>

    <table class="data-table">
      <thead>
        <tr>
          <th>Applicant Information</th>
          <th>Usage Intention Reasons</th>
          <th>Discovery Source</th>
          <th>Date Submitted</th>
          <th style="width: 420px; text-align: center;">Credentials Generation Console</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($pending_list)): ?>
        <tr>
            <td colspan="5" style="text-align:center; padding:30px; color:#888; font-style: italic;">
                No pending access account creation.
            </td>
        </tr>
      <?php else: ?>
        <?php foreach ($pending_list as $row): ?>
          <tr>
            <td>
                <strong style="font-size:15px; color:#222;"><?= htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) ?></strong><br>
                <span style="font-size:13px; color:#555;"><?= htmlspecialchars($row['email']) ?></span><br>
                <small style="color:#777; font-family: monospace;"><?= htmlspecialchars($row['phone'] ?: 'No Phone Provided') ?></small>
            </td>
            <td>
                <div style="max-height:80px; overflow-y:auto; font-size:12px; color:#444; line-height:1.4;">
                    <?= htmlspecialchars($row['reasons']) ?>
                </div>
            </td>
            <td>
                <span class="badge" style="background:#e9ecef; color:#495057; padding:4px 8px; border-radius:4px; font-size:11px;">
                    <?= htmlspecialchars($row['sources'] ?: 'Direct Visit') ?>
                </span>
            </td>
            <td>
                <small style="color:#666;"><?= date('M d, Y h:i A', strtotime($row['created_at'])) ?></small>
            </td>
            <td>
              <div style="background:#f8f9fa; padding:12px; border-radius:6px; border:1px solid #e2e8f0;">
                <form method="POST" action="" style="margin:0;">
                  <input type="hidden" name="action_type" value="approve_user">
                  <input type="hidden" name="request_id" value="<?= (int)$row['id'] ?>">
                  
                  <div style="display:flex; gap:8px; margin-bottom:8px;">
                    <input type="text" name="generated_username" placeholder="Assign Username" 
                           value="<?= strtolower(htmlspecialchars($row['first_name'] . $row['id'])) ?>" 
                           style="flex:1; padding:6px; font-size:13px; border:1px solid #cbd5e1; border-radius:4px;" required>
                    
                    <div style="display:flex; flex:1; position:relative;">
                        <?php $random_pass = bin2hex(random_bytes(4)); ?>
                        <input type="text" id="pass_<?= (int)$row['id'] ?>" name="generated_password" placeholder="Assign Password" 
                               value="<?= $random_pass ?>" 
                               style="width:100%; padding:6px 35px 6px 6px; font-size:13px; border:1px solid #cbd5e1; border-radius:4px;" required>
                        
                        <button type="button" onclick="copyPassword(<?= (int)$row['id'] ?>)" title="Copy Password"
                                style="position:absolute; right:5px; top:50%; transform:translateY(-50%); background:none; border:none; padding:4px; cursor:pointer; display:flex; align-items:center; justify-content:center; color:#64748b;">
                            <svg xmlns="http://www.w3.org" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-copy"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>
                        </button>
                    </div>
                  </div>
                  
                  <div style="display:flex; justify-content:space-between; align-items:center;">
                    <div style="display:flex; align-items:center; gap:5px;">
                        <label style="font-size:12px; font-weight:bold; color:#4a5568;">Role:</label>
                        <select name="assigned_role" style="padding:4px; font-size:13px; border:1px solid #cbd5e1; border-radius:4px; background:#fff;">
                            <option value="casual">Casual User</option>
                            <option value="contributor">Contributor</option>
                            <option value="admin">Administrator</option>
                        </select>
                    </div>
                    
                    <div style="display:flex; gap:5px;">
                        <button type="submit" class="btn btn-primary" style="padding:6px 12px; font-size:13px; border-radius:4px;">Approve Account</button>
                        <button type="submit" name="action_type" value="reject_user" class="btn btn-outline" 
                                style="padding:6px 12px; font-size:13px; border-radius:4px; color:#dc3545; border-color:#dc3545; background:none;"
                                onclick="return confirm('Are you sure you want to permanently decline this workspace registration request profile?');">Decline</button>
                    </div>
                  </div>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
</div>

<script>
function closeApprovalModal() {
    const modal = document.getElementById('approvalModal');
    if(modal) modal.style.display = 'none';
    window.location.href = 'admin_approvals.php'; // Forces a clean redirect to clear post headers and completely remove the account row from current view structure
}

function copyPassword(id) {
    const passwordInput = document.getElementById('pass_' + id);
    if (!passwordInput) return;

    navigator.clipboard.writeText(passwordInput.value).then(() => {
        alert('Password successfully copied to clipboard: ' + passwordInput.value);
    }).catch(err => {
        console.error('Failed to copy text parameters out of form input content: ', err);
    });
}
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>