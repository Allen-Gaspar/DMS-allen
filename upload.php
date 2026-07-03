<?php
/**
 * upload.php — File upload processor, Folder Management, and Sharing Rules Framework.
 */
require_once __DIR__ . '/auth.php';

// Synchronize your PHP runtime environment with Philippine Standard Time (PST)
date_default_timezone_set('Asia/Manila');

$user = require_login(); // Ensure user session parameters are populated
$db   = get_db();
$role = $user['role'];

// Protect script access logic bounds
if ($role !== 'admin' && $role !== 'contributor') {
    header('Location: documents.php?err=' . urlencode('Unauthorized access attempt.'));
    exit;
}

define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('MAX_SIZE', 200 * 1024 * 1024); // Set file limit ceiling to 200 MB

// Expanded extension arrays to accept Images, Presentations, Spreadsheets, Videos, and raw Code formats
$allowed_ext = [
    'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp',  // Images
    'pdf', 'docx', 'doc', 'xlsx', 'xls',         // Docs
    'pptx', 'ppt',                               // Presentations
    'mp4', 'mkv', 'avi', 'mov',                  // Videos
    'txt', 'php', 'js', 'html', 'css', 'json', 'py', 'java', 'cpp', 'sql' // Code Files
];

// --- HANDLE POST REQUEST PROCESSING ENGINES ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // ACTION 1: CREATE NEW VIRTUAL FOLDER LAYOUT WITH PRIVACY TYPES
    if (isset($_POST['action_type']) && $_POST['action_type'] === 'create_folder') {
        $folder_name = trim($_POST['new_folder_name'] ?? '');
        $folder_type = $_POST['folder_privacy_type'] ?? 'private';

        // Restrict type strictly to allowed enum strings
        if (!in_array($folder_type, ['public', 'private'], true)) {
            $folder_type = 'private';
        }

        if ($folder_name === '') {
            header('Location: upload.php?err=' . urlencode('Folder name cannot be blank.'));
            exit;
        }

        // Altering execution array to parse folder structure scope definitions
        $stmt = $db->prepare("INSERT INTO folders (name, type, created_by) VALUES (?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param('ssi', $folder_name, $folder_type, $user['id']);
            $stmt->execute();
            if (function_exists('audit_log')) {
                audit_log($user['id'], 'FOLDER_CREATION', "Created new ($folder_type) workspace folder structure: $folder_name");
            }
            header('Location: upload.php?ok=' . urlencode("Folder '$folder_name' created successfully."));
            exit;
        } else {
            die("Critical Folder Database Error: " . $db->error);
        }
    }

    // ACTION 2: STANDARD SINGLE FILE FILE-SYSTEM STREAM PROCESSING
    if (isset($_FILES['document'])) {
        if ($_FILES['document']['error'] !== UPLOAD_ERR_OK) {
            $code = $_FILES['document']['error'];
            if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) {
                header('Location: upload.php?err=' . urlencode('File exceeds the absolute system limit of 200 MB.'));
                exit;
            }
            header('Location: upload.php?err=' . urlencode('No file received or upload exception error encountered.'));
            exit;
        }

        $file      = $_FILES['document'];
        $tmp_path  = $file['tmp_name'];
        $orig_name = basename($file['name']);
        $ext       = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));

        // Validate File Format against broad whitelist matrix array metrics
        if (!in_array($ext, $allowed_ext, true)) {
            header('Location: upload.php?err=' . urlencode("File extension '.$ext' is restricted. Check allowed type options list."));
            exit;
        }

        // Validate Physical payload sizing limits (200MB boundary validation checkpoint)
        if ($file['size'] > MAX_SIZE) {
            header('Location: upload.php?err=' . urlencode('File layout size boundary breached. Maximum permitted is 200 MB.'));
            exit;
        }

        if (!is_dir(UPLOAD_DIR)) {
            mkdir(UPLOAD_DIR, 0755, true);
        }

        $stored_name = uniqid('doc_', true) . '.' . $ext;
        $dest        = UPLOAD_DIR . $stored_name;

        if (!move_uploaded_file($tmp_path, $dest)) {
            header('Location: upload.php?err=' . urlencode('Storage allocation failure. Check server directory permissions.'));
            exit;
        }

        // Detect Location Routing (Current layout context root vs target child directory folder parameters mapping)
        $folder_id = $_POST['target_folder_location'] ?? '';
        $folder_id_param = ($folder_id === 'root' || $folder_id === '') ? null : (int)$folder_id;

        // FIXED QUERY LOGIC: Handled dynamic data properties matching layout fields safely
        $sql_query = "INSERT INTO documents (filename, storage_path, size, uploaded_by, folder_id) VALUES (?, ?, ?, ?, ?)";
        $stmt = $db->prepare($sql_query);

        // Emergency validation checkpoint trap protecting against structural schema mismatches
        if ($stmt === false) {
            die("<div style='font-family:sans-serif; padding:30px; background:#fff5f5; border-left:5px solid #ef4444;'>
                    <h3 style='color:#b91c1c; margin:0 0 10px 0;'>Database Structure Error</h3>
                    <p>Your documents table columns do not match the expected naming parameters.</p>
                    <strong style='color:#7f1d1d;'>MySQL Error String:</strong> <code>" . htmlspecialchars($db->error) . "</code>
                 </div>");
        }

        // Bind data values securely. 'i' fields drop safely to null when matching variable pointers are passed
        $stmt->bind_param('ssiii', $orig_name, $stored_name, $file['size'], $user['id'], $folder_id_param);
        
        if (!$stmt->execute()) {
            die("Execution Failure: " . $stmt->error);
        }
        $new_doc_id = $db->insert_id;

        // --- MANAGE WORKSPACE COLLABORATION AND SHARING PARAMETERS CONFIG ---
        $share_scope = $_POST['sharing_scope'] ?? 'private';
        
        if ($share_scope === 'all') {
            $share_all_stmt = $db->prepare("INSERT INTO document_shares (document_id, share_with_all, shared_by) VALUES (?, 1, ?)");
            if ($share_all_stmt) {
                $share_all_stmt->bind_param('ii', $new_doc_id, $user['id']);
                $share_all_stmt->execute();
            }
        } elseif ($share_scope === 'specific' && isset($_POST['specific_users'])) {
            foreach ($_POST['specific_users'] as $target_uid) {
                $share_spec = $db->prepare("INSERT INTO document_shares (document_id, shared_with_user_id, shared_by) VALUES (?, ?, ?)");
                if ($share_spec) {
                    $target_uid_int = (int)$target_uid;
                    $share_spec->bind_param('iii', $new_doc_id, $target_uid_int, $user['id']);
                    $share_spec->execute();
                }
            }
        }

        if (function_exists('audit_log')) {
            audit_log($user['id'], 'UPLOAD', "Uploaded file asset '$orig_name' into location index scope.");
        }

        header('Location: documents.php?ok=' . urlencode("'$orig_name' uploaded successfully."));
        exit;
    }
}

// --- FETCH AUXILIARY SYSTEM CONTEXT VALUES FOR FRONTEND UI INJECTORS ---
$folders_res = $db->query("SELECT * FROM folders ORDER BY name ASC");
$folders = $folders_res ? $folders_res->fetch_all(MYSQLI_ASSOC) : [];

$users_res = $db->prepare("SELECT id, username, role FROM users WHERE id != ? AND status = 'active' ORDER BY username ASC");
$users_res->bind_param('i', $user['id']);
$users_res->execute();
$system_collaborators = $users_res->get_result()->fetch_all(MYSQLI_ASSOC);

$success = $_GET['ok'] ?? '';
$error   = $_GET['err'] ?? '';

$page_title = 'Upload & Customization Manager';
include __DIR__ . '/partials/header.php';
?>

<style>
    .upload-grid-workspace { display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-top: 20px; font-family: sans-serif; }
    .workspace-card-box { background: #fff; border: 1px solid #eef2f6; padding: 25px; border-radius: 10px; box-shadow: 0 4px 12px rgba(0,0,0,0.03); }
    .form-group-block { display: flex; flex-direction: column; gap: 6px; margin-bottom: 15px; }
    .form-group-block label { font-size: 13px; font-weight: bold; color: #475569; text-transform: uppercase; }
    .form-control-input { width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 14px; box-sizing: border-box; }
    .preview-canvas-box { border: 2px dashed #cbd5e1; border-radius: 8px; background: #f8fafc; min-height: 250px; display: flex; align-items: center; justify-content: center; padding: 15px; box-sizing: border-box; text-align: center; color: #64748b; margin-top: 10px; overflow: hidden; }
</style>

<h2 class="page-title" style="margin-bottom: 5px;">File Storage Manager</h2>
<p style="color: #666; margin: 0 0 20px 0; font-size: 14px;">Upload media assets, create folders, and share to others.</p>

<?php if ($success): ?><div class="alert alert-success" style="margin-bottom: 15px;"><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error" style="margin-bottom: 15px;"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="upload-grid-workspace">
    
    <!-- LEFT SIDEBAR SECTION PANEL: STORAGE ROUTING AND MEDIA FILE UPLOADS -->
    <div class="workspace-card-box">
        <h4 style="margin: 0 0 15px 0; border-bottom: 1px solid #f1f5f9; padding-bottom: 8px; color: #1e293b; font-size: 14px; font-weight: bold; text-transform: uppercase;">1. Upload File</h4>
        
        <form method="POST" action="upload.php" enctype="multipart/form-data" id="masterUploadForm">
            
            <div class="form-group-block">
                <label for="target_folder_location">Storage Destination Location Target</label>
                <select name="target_folder_location" id="target_folder_location" class="form-control-input">
                    <option value="root">/ Main Root Folder Directory</option>
                    <?php foreach ($folders as $f): ?>
                        <option value="<?= $f['id'] ?>">
                            📁 <?= htmlspecialchars($f['name']) ?> 
                            (<?= isset($f['type']) ? strtoupper(htmlspecialchars($f['type'])) : 'PRIVATE' ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group-block">
                <label>Select Target Media Source File</label>
                <p style="font-size: 11px; color: #64748b; margin: 0 0 5px 0;">Accepts Docs, PPT, Images, Videos, and raw Code formats. Max: 200 MB</p>
                <input type="file" name="document" id="documentFileAssetSelector" class="form-control-input" required onchange="generateLiveMediaAssetPreview(this)">
            </div>

            <!-- COLLABORATION WORKSPACE ASSIGNMENT SHARING SCOPE RULES INTERFACE COMPONENT -->
            <div class="form-group-block" style="margin-top: 20px;">
                <label for="sharing_scope">File Access Sharing Permissions Scope</label>
                <select name="sharing_scope" id="sharing_scope" class="form-control-input" onchange="toggleSharingSelectorViews(this.value)">
                    <option value="private">Private (Only Me & System Administrators)</option>
                    <option value="all">Share Globally (All Registered System Collaborators)</option>
                    <option value="specific">Targeted Group Share (Select Specific Users/Collaborators)</option>
                </select>
            </div>

            <!-- TARGETED GROUP ACCESS TICK LIST WRAPPER COMPONENT -->
            <div id="specificUsersSelectionWrapper" style="display: none; background: #f8fafc; border: 1px solid #e2e8f0; padding: 15px; border-radius: 8px; margin-bottom: 15px;">
                <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px dashed #cbd5e1; padding-bottom: 6px; margin-bottom: 10px;">
                    <p style="font-size: 11px; font-weight: bold; color: #475569; margin: 0; text-transform: uppercase;">Select Target Recipients</p>
                    <button type="button" onclick="toggleSelectAllCollaborators(this)" style="font-size: 11px; color: #0284c7; background: none; border: none; cursor: pointer; font-weight: bold; padding: 0;">Select All Users</button>
                </div>
                
                <div style="max-height: 150px; overflow-y: auto; display: flex; flex-direction: column; gap: 8px;">
                    <?php if (empty($system_collaborators)): ?>
                        <p style="font-size: 12px; color: #64748b; margin: 0; text-align: center;">No other active workspace collaborators found.</p>
                    <?php else: ?>
                        <?php foreach ($system_collaborators as $collab_user): ?>
                            <label style="display: flex; align-items: center; gap: 10px; font-size: 13px; cursor: pointer; color: #334155;">
                                <input type="checkbox" name="specific_users[]" value="<?= $collab_user['id'] ?>" class="collab-user-checkbox" style="width: 16px; height: 16px; cursor: pointer; margin: 0;">
                                <span><?= htmlspecialchars($collab_user['username']) ?> <small style="color: #64748b; background: #f1f5f9; padding: 1px 5px; border-radius: 4px; margin-left: 4px; font-size: 10px; text-transform: uppercase; font-weight: bold;"><?= htmlspecialchars($collab_user['role']) ?></small></span>
                            </label>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%; padding: 12px; margin-top: 10px; font-weight: bold;">Execute Master File Upload Pipeline</button>
        </form>
    </div>

    <!-- RIGHT SIDEBAR SECTION PANEL: LIVE ASSET INSPECTION & DIRECTORY MANAGEMENT TOOLKIT -->
    <div style="display: flex; flex-direction: column; gap: 20px;">
        
        <div class="workspace-card-box" style="flex: 1; display: flex; flex-direction: column;">
            <h4 style="margin: 0 0 12px 0; color: #1e293b; font-size: 14px; font-weight: bold; text-transform: uppercase; border-bottom: 1px solid #f1f5f9; padding-bottom: 8px;">2. Live Preview</h4>
            <div class="preview-canvas-box" id="liveMediaPreviewBoxViewport">
                <span style="font-size: 13px; line-height: 1.5; color: #64748b;">No media file selected yet.<br>Click "Choose File" on the left to initialize preview.</span>
            </div>
        </div>

        <div class="workspace-card-box">
            <h4 style="margin: 0 0 12px 0; color: #1e293b; font-size: 14px; font-weight: bold; text-transform: uppercase; border-bottom: 1px solid #f1f5f9; padding-bottom: 8px;">3. Directory</h4>
            <form method="POST" action="upload.php" style="margin: 0;">
                <input type="hidden" name="action_type" value="create_folder">
                
                <div class="form-group-block">
                    <label for="new_folder_name">New Directory Folder Name</label>
                    <input type="text" name="new_folder_name" id="new_folder_name" placeholder="e.g., Marketing PPT Presentations" class="form-control-input" required>
                </div>

                <div class="form-group-block">
                    <label for="folder_privacy_type">Directory Visibility Type</label>
                    <div style="display: flex; gap: 10px; margin-top: 4px;">
                        <select name="folder_privacy_type" id="folder_privacy_type" class="form-control-input" style="flex: 1;">
                            <option value="private">Private Workspace Directory</option>
                            <option value="public">Public Open Workspace</option>
                        </select>
                        <button type="submit" class="btn btn-secondary" style="white-space: nowrap; font-weight: bold; font-size: 13px; padding: 0 15px;">Create Folder</button>
                    </div>
                </div>
            </form>
        </div>

    </div>

</div>

<!-- MASTER FRONTEND COGNITIVE SCRIPTS ENGINE -->
<script>
function generateLiveMediaAssetPreview(input) {
    const previewContainer = document.getElementById('liveMediaPreviewBoxViewport');
    if (!previewContainer) return;

    if (!input.files || !input.files[0]) {
        previewContainer.innerHTML = '<span style="font-size: 13px; color: #64748b;">No media file selected yet.</span>';
        return;
    }

    const file = input.files[0];
    const filename = file.name;
    const extension = filename.split('.').pop().toLowerCase();
    const fileSizeMB = (file.size / (1024 * 1024)).toFixed(2);

    if (['png', 'jpg', 'jpeg', 'gif', 'svg', 'webp'].includes(extension)) {
        const reader = new FileReader();
        reader.onload = function(e) {
            previewContainer.innerHTML = `<img src="${e.target.result}" style="max-width: 100%; max-height: 240px; object-fit: contain; border-radius: 6px; display: block; margin: 0 auto; box-shadow: 0 4px 10px rgba(0,0,0,0.05);">`;
        };
        reader.readAsDataURL(file);
        return;
    }

    if (['mp4', 'mkv', 'mov', 'avi'].includes(extension)) {
        const fileURL = URL.createObjectURL(file);
        previewContainer.innerHTML = `<video src="${fileURL}" controls style="width: 100%; max-height: 240px; outline: none; border-radius: 6px; background: #000; box-shadow: 0 4px 10px rgba(0,0,0,0.1);"></video>`;
        return;
    }

    if (['txt', 'php', 'js', 'html', 'css', 'json', 'py', 'java', 'cpp', 'sql', 'xml'].includes(extension)) {
        if (file.size > 1024 * 1024) {
            previewContainer.innerHTML = `
                <div style="padding: 10px;">
                    <div style="font-size: 40px; margin-bottom: 5px;">💻</div>
                    <strong style="color: #1e293b; display: block; font-size: 13px; word-break: break-all;">${filename}</strong>
                    <span style="font-size: 12px; color: #64748b; display: block; margin-top: 4px;">Large source file ready for deployment (${fileSizeMB} MB)</span>
                </div>`;
            return;
        }
        
        const reader = new FileReader();
        reader.onload = function(e) {
            const sanitizedText = e.target.result.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
            previewContainer.innerHTML = `<pre style="text-align: left; font-family: 'Courier New', Courier, monospace; font-size: 12px; background: #1e1e1e; color: #d4d4d4; padding: 15px; border-radius: 6px; margin: 0; max-height: 240px; overflow: auto; width: 100%; box-sizing: border-box; border: 1px solid #2d2d2d; line-height: 1.5; white-space: pre-wrap; word-break: break-all;"><code>${sanitizedText}</code></pre>`;
        };
        reader.readAsText(file);
        return;
    }

    let placeholderIcon = "📄";
    if (['docx', 'doc'].includes(extension)) placeholderIcon = "📝 Word Document";
    if (['xlsx', 'xls'].includes(extension)) placeholderIcon = "📊 Excel Spreadsheet";
    if (['pptx', 'ppt'].includes(extension)) placeholderIcon = "📉 PowerPoint Presentation";
    if (extension === 'pdf') placeholderIcon = "📕 PDF Document";

    previewContainer.innerHTML = `
        <div style="font-family: sans-serif; padding: 10px;">
            <div style="font-size: 48px; margin-bottom: 10px;">${placeholderIcon}</div>
            <strong style="color: #1e293b; display: block; font-size: 14px; word-break: break-all;">${filename}</strong>
            <span style="font-size: 12px; color: #64748b; display: block; margin-top: 4px;">Ready to upload (${fileSizeMB} MB)</span>
        </div>
    `;
}

function toggleSharingSelectorViews(selectedValue) {
    const specificSelectionWrapper = document.getElementById('specificUsersSelectionWrapper');
    if (specificSelectionWrapper) {
        specificSelectionWrapper.style.display = (selectedValue === 'specific') ? 'block' : 'none';
    }
}

function toggleSelectAllCollaborators(buttonElement) {
    const checkboxes = document.querySelectorAll('.collab-user-checkbox');
    const allChecked = Array.from(checkboxes).every(cb => cb.checked);
    checkboxes.forEach(cb => cb.checked = !allChecked);
    buttonElement.textContent = allChecked ? "Select All Users" : "Deselect All Users";
}
</script>

<?php 
include __DIR__ . '/partials/footer.php'; 
?>  