<?php
require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db = get_db();

    // FIXED: Phone Number is now heavily validated along with name metrics
    $first_name   = trim($_POST['first_name'] ?? '');
    $last_name    = trim($_POST['last_name'] ?? '');
    $email        = trim($_POST['email'] ?? '');
    $phone        = trim($_POST['phone'] ?? '');
    
    $reasons_arr  = $_POST['reasons'] ?? [];
    $reasons      = implode(', ', $reasons_arr);
    
    $sources_arr  = $_POST['sources'] ?? [];
    $sources      = implode(', ', $sources_arr);

    // FIXED: Form validation rule now mandates Phone field
    if ($first_name === '' || $last_name === '' || $email === '' || $phone === '') {
        $error = 'First Name, Last Name, Email, and Phone Number are strictly required fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please provide a completely valid email address structure.';
    } else {
        $db->query("CREATE TABLE IF NOT EXISTS registration_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            first_name VARCHAR(100),
            last_name VARCHAR(100),
            email VARCHAR(150),
            phone VARCHAR(50),
            reasons TEXT,
            sources TEXT,
            status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        $stmt = $db->prepare('INSERT INTO registration_requests (first_name, last_name, email, phone, reasons, sources) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('ssssss', $first_name, $last_name, $email, $phone, $reasons, $sources);
        
        if ($stmt->execute()) {
            $message = 'Your workspace access request has been successfully submitted! Admin will review and email your credentials shortly.';
        } else {
            $error = 'Something went wrong submitting your form. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Request Access - FILESTAC DMS</title>
    <link href="style.css" rel="stylesheet">
    <style>
        .form-wrap { display: flex; justify-content: center; align-items: center; padding: 40px 20px; min-height: 100vh; background: #f4f7f6; }
        .form-box { width: 100%; max-width: 550px; background: #ffffff; padding: 30px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        .checkbox-group { margin: 20px 0; background: #fafafa; padding: 15px; border-radius: 6px; border: 1px solid #eef2f5; }
        .checkbox-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; border-bottom: 1px dashed #ddd; padding-bottom: 5px; }
        .checkbox-label { font-weight: bold; color: #333; }
        .select-all-btn { font-size: 12px; color: #2e7d32; background: none; border: none; cursor: pointer; font-weight: bold; padding: 0; }
        .select-all-btn:hover { text-decoration: underline; }
        .checkbox-item { display: flex; align-items: center; margin-bottom: 6px; font-size: 14px; cursor: pointer; color: #555; }
        .checkbox-item input { margin-right: 10px; width: 16px; height: 16px; }
        .form-row { display: flex; gap: 15px; }
        .form-row .form-group { flex: 1; }
        .optional-tag { font-size: 11px; color: #888; font-weight: normal; margin-left: 4px; }
    </style>
</head>
<body class="skin-login">
<div class="form-wrap">
    <div class="form-box">
        <div class="login-logo" style="text-align: center; margin-bottom: 20px; font-family: sans-serif;">
    <!-- LOGO CORRECTION: Non-clickable asset render matrix path -->
    <img src="filestac.png" alt="FILESTAC DMS Logo" class="filestac-icon" style="width: 70px; height: 70px; object-fit: contain; display: block; margin: 0 auto 3px auto;">
    
    <h1 style="margin: 0; font-size: 24px; font-weight: bold; color: #333; letter-spacing: 0.5px;">FILESTAC DMS</h1>
    <p class="subtitle" style="margin: 5px 0 0 0; color: #666; font-size: 14px;">Request Access to Workspace</p>
</div>


        <?php if ($error): ?>
            <div class="alert alert-error" style="background: #f8d7da; color: #721c24; padding: 10px; border-radius: 4px; margin-bottom: 15px; font-size: 14px; border: 1px solid #f5c6cb;"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($message): ?>
            <div class="alert alert-success" style="background: #d4edda; color: #155724; padding: 10px; border-radius: 4px; margin-bottom: 15px; font-size: 14px; border: 1px solid #c3e6cb;"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="form-row">
                <div class="form-group">
                    <label for="first_name">First Name <span style="color:red;">*</span></label>
                    <input type="text" id="first_name" name="first_name" required>
                </div>
                <div class="form-group">
                    <label for="last_name">Last Name <span style="color:red;">*</span></label>
                    <input type="text" id="last_name" name="last_name" required>
                </div>
            </div>

            <div class="form-group">
                <label for="email">Email Address <span style="color:red;">*</span></label>
                <input type="email" id="email" name="email" placeholder="name@example.com" required>
            </div>

            <!-- FIXED: Phone input is now marked with explicit required HTML attribute flag -->
            <div class="form-group">
                <label for="phone">Phone Number <span style="color:red;">*</span></label>
                <input type="tel" id="phone" name="phone" placeholder="e.g., +1 234-567-890" required>
            </div>

            <!-- QUESTION 1: REASONS GROUP -->
            <div class="checkbox-group">
                <div class="checkbox-header">
                    <span class="checkbox-label">Why do you want to use FILESTAC DMS?<span class="optional-tag">(Optional)</span></span>
                    <button type="button" class="select-all-btn" onclick="toggleSelectAll('reasons[]', this)">Select All</button>
                </div>
                <label class="checkbox-item"><input type="checkbox" name="reasons[]" value="Uploading Documents"> Uploading Documents</label>
                <label class="checkbox-item"><input type="checkbox" name="reasons[]" value="Presentations / PPT"> Presentations / PPT</label>
                <label class="checkbox-item"><input type="checkbox" name="reasons[]" value="Code File Storage"> Code File Storage</label>
                <label class="checkbox-item"><input type="checkbox" name="reasons[]" value="Collaborating with others"> Collaborating with others</label>
                <label class="checkbox-item"><input type="checkbox" name="reasons[]" value="Group Workplace Setup"> Group Workplace Setup</label>
                <label class="checkbox-item"><input type="checkbox" name="reasons[]" value="Online Storage Layout"> Online Storage Layout</label>
                <!-- FIXED: Added extra custom package checkboxes below -->
                <label class="checkbox-item"><input type="checkbox" name="reasons[]" value="Secure Version Control"> Secure Version Control</label>
                <label class="checkbox-item"><input type="checkbox" name="reasons[]" value="Automated Backup Systems"> Automated Backup Systems</label>
                <label class="checkbox-item"><input type="checkbox" name="reasons[]" value="Client File Sharing Portals"> Client File Sharing Portals</label>
            </div>

            <!-- QUESTION 2: SOURCES GROUP -->
            <div class="checkbox-group">
                <div class="checkbox-header">
                    <span class="checkbox-label">Where did you see us?<span class="optional-tag">(Optional)</span></span>
                    <button type="button" class="select-all-btn" onclick="toggleSelectAll('sources[]', this)">Select All</button>
                </div>
                <label class="checkbox-item"><input type="checkbox" name="sources[]" value="Facebook"> Facebook</label>
                <label class="checkbox-item"><input type="checkbox" name="sources[]" value="Google Search"> Google Search</label>
                <label class="checkbox-item"><input type="checkbox" name="sources[]" value="Friend Reference"> Friend Reference</label>
                <label class="checkbox-item"><input type="checkbox" name="sources[]" value="Advertisement Banner"> Advertisement Banner</label>
                <!-- FIXED: Added extra visibility platform paths below -->
                <label class="checkbox-item"><input type="checkbox" name="sources[]" value="LinkedIn Professional Post"> LinkedIn Professional Post</label>
                <label class="checkbox-item"><input type="checkbox" name="sources[]" value="YouTube Video Review"> YouTube Video Review</label>
                <label class="checkbox-item"><input type="checkbox" name="sources[]" value="GitHub Repository Link"> GitHub Repository Link</label>
                <label class="checkbox-item"><input type="checkbox" name="sources[]" value="Tech Blog Newsletter"> Tech Blog Newsletter</label>
            </div>

            <button type="submit" class="btn btn-primary btn-full">Submit Request</button>
        </form>
        <p class="login-note" style="margin-top:15px; text-align:center;"><a href="index.php" style="color:var(--primary); text-decoration:none;">&larr; Back to Home</a></p>
    </div>
</div>

<script>
// Efficient JS handler managing context states for select-all triggers
function toggleSelectAll(checkboxName, button) {
    const checkboxes = document.querySelectorAll(`input[name="${checkboxName}"]`);
    const allChecked = Array.from(checkboxes).every(cb => cb.checked);
    
    checkboxes.forEach(cb => cb.checked = !allChecked);
    button.textContent = allChecked ? "Select All" : "Deselect All";
}
</script>
</body>
</html>
