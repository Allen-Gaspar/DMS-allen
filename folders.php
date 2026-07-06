<?php
/**
 * folders.php — Folder exploration and dynamic contextual inner file autocomplete index.
 */
require_once __DIR__ . '/auth.php';
$user = require_role('casual', 'contributor', 'admin');
$db   = get_db();
$role = $user['role']; 

date_default_timezone_set('Asia/Manila');

$success = $_GET['ok']  ?? '';
$error   = $_GET['err'] ?? '';

function format_bytes(int $bytes): string {
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024)    return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

// ── 1. ACTION: HANDLE ASYNC FOLDER PROVISIONING & RENAMING ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_action'])) {
    // A. Create Folder Logic
    if ($_POST['form_action'] === 'create_folder') {
        if ($role === 'casual') {
            header('Location: folders.php?err=' . urlencode('Unauthorized operation folder validation layer.'));
            exit;
        }

        $foldername = trim($_POST['foldername'] ?? '');
        $is_private = isset($_POST['is_private']) ? 1 : 0;
        $created_by = $user['id'];

        if (!empty($foldername)) {
            $stmt = $db->prepare('INSERT INTO folders (name, is_private, created_by) VALUES (?, ?, ?)');
            $stmt->bind_param('sii', $foldername, $is_private, $created_by);
            
            if ($stmt->execute()) {
                header('Location: folders.php?ok=' . urlencode('Folder ecosystem provisioned successfully!'));
                exit;
            } else {
                $error = "Database execution entry framework processing failure.";
            }
        } else {
            $error = "Folder identity descriptor cannot be left blank empty.";
        }
    }
    
    // B. Rename Folder Logic (Combined directly into folders.php)
    if ($_POST['form_action'] === 'rename_folder') {
        if ($role === 'casual') {
            header('Location: folders.php?err=' . urlencode('Unauthorized operation access block.'));
            exit;
        }

        $folder_id   = (int)($_POST['rename_folder_id'] ?? 0);
        $new_name    = trim($_POST['new_foldername'] ?? '');

        if ($folder_id > 0 && !empty($new_name)) {
            // Permission check: Admins can rename anything; contributors can rename their own folders
            $check_stmt = $db->prepare("SELECT created_by FROM folders WHERE id = ? LIMIT 1");
            $check_stmt->bind_param('i', $folder_id);
            $check_stmt->execute();
            $f_owner = $check_stmt->get_result()->fetch_assoc();

            if ($f_owner && ($role === 'admin' || $f_owner['created_by'] == $user['id'])) {
                $update_stmt = $db->prepare("UPDATE folders SET name = ? WHERE id = ?");
                $update_stmt->bind_param('si', $new_name, $folder_id);
                if ($update_stmt->execute()) {
                    header('Location: folders.php?id=' . $folder_id . '&ok=' . urlencode('Folder identity updated successfully.'));
                    exit;
                } else {
                    $error = "Failed to rewrite database workspace node name.";
                }
            } else {
                $error = "Unauthorized to rename this target folder structural block.";
            }
        } else {
            $error = "Invalid folder parameters or structural name missing.";
        }
    }
}

// ── 2. REAL-TIME ASYNC AUTOCOMPLETE API ENDPOINT CHANNEL ───
if (isset($_GET['action']) && $_GET['action'] === 'suggest_inner_files') {
    header('Content-Type: application/json');
    $folder_id = (int)($_GET['folder_id'] ?? 0);
    $query     = trim($_GET['q'] ?? '');

    if ($folder_id <= 0 || $query === '') {
        echo json_encode([]);
        exit;
    }

    $search_term = "%{$query}%";
    $suggest_stmt = $db->prepare("SELECT id, filename FROM documents WHERE folder_id = ? AND filename LIKE ? AND is_deleted = 0 LIMIT 5");
    $suggest_stmt->bind_param('is', $folder_id, $search_term);
    $suggest_stmt->execute();
    $results = $suggest_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    echo json_encode($results);
    exit;
}

// ── 3. SESSION-BASED PERSISTENT STATE LOGIC ───
if (isset($_GET['id'])) {
    $current_folder_id = (int)$_GET['id'];
    $_SESSION['last_viewed_folder_id'] = $current_folder_id;
} else if (isset($_SESSION['last_viewed_folder_id'])) {
    $current_folder_id = (int)$_SESSION['last_viewed_folder_id'];
} else {
    $current_folder_id = 0;
}

// Fetch directory datasets safely according to dynamic privacy validation rules
$folders_query_str = "
    SELECT DISTINCT f.* FROM folders f
    LEFT JOIN folder_shares fs ON fs.folder_id = f.id AND fs.shared_with_user_id = ?
    WHERE f.is_private = 0 
       OR f.created_by = ? 
       OR fs.shared_with_user_id IS NOT NULL 
       OR ? = 'admin'
    ORDER BY f.name ASC
";
$f_list_stmt = $db->prepare($folders_query_str);
$f_list_stmt->bind_param('iis', $user['id'], $user['id'], $role);
$f_list_stmt->execute();
$all_folders = $f_list_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Separate folders array locally into Public and Private groups
$public_folders  = [];
$private_folders = [];
foreach ($all_folders as $f) {
    if (!empty($f['is_private'])) {
        $private_folders[] = $f;
    } else {
        $public_folders[] = $f;
    }
}

$current_folder = null;
$folder_files   = [];
if ($current_folder_id > 0) {
    $f_stmt = $db->prepare("SELECT * FROM folders WHERE id = ? LIMIT 1");
    $f_stmt->bind_param('i', $current_folder_id);
    $f_stmt->execute();
    $current_folder = $f_stmt->get_result()->fetch_assoc();

    if ($current_folder) {
        $files_stmt = $db->prepare(
            "SELECT d.*, u.username AS uploader_name, ur.role AS uploader_role, lk.username AS locker_name
             FROM documents d
             LEFT JOIN users u  ON u.id = d.uploaded_by
             LEFT JOIN users ur ON ur.id = d.uploaded_by
             LEFT JOIN users lk ON lk.id = d.locked_by
             WHERE d.folder_id = ? AND d.is_deleted = 0 
             ORDER BY d.filename ASC"
        );
        $files_stmt->bind_param('i', $current_folder_id);
        $files_stmt->execute();
        $folder_files = $files_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}

$users_stmt = $db->prepare("SELECT id, username, role FROM users WHERE id != ? AND status = 'active' ORDER BY username ASC");
$users_stmt->bind_param('i', $user['id']);
$users_stmt->execute();
$system_collaborators = $users_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ── 4. IN-LINE LOCK/UNLOCK ROUTING INTERCEPTOR ──
if (isset($_GET['inline_action']) && in_array($_GET['inline_action'], ['lock', 'unlock'])) {
    $doc_id = (int)($_GET['file_id'] ?? 0);
    $action = $_GET['inline_action'];
    $current_folder_id = (int)($_GET['folder_id'] ?? 0);
    
    if ($doc_id > 0) {
        if ($action === 'lock') {
            $stmt = $db->prepare("UPDATE documents SET is_locked = 1, locked_by = ? WHERE id = ?");
            $stmt->bind_param('ii', $user['id'], $doc_id);
            $stmt->execute();
        } else if ($action === 'unlock') {
            $stmt = $db->prepare("UPDATE documents SET is_locked = 0, locked_by = NULL WHERE id = ?");
            $stmt->bind_param('i', $doc_id);
            $stmt->execute();
        }
    }
    header("Location: folders.php?id=" . $current_folder_id);
    exit;
}

$page_title = 'Workspace Folders';
include __DIR__ . '/partials/header.php';
?>

<!-- ── DESIGN ELEMENTS HOOK AND LOOKUP SPECIFICATIONS ── -->
<style>
  .workspace-search-bar-wrapper { display: flex; gap: 15px; margin-bottom: 25px; flex-wrap: wrap; background: #f9f9f9; padding: 15px; border-radius: 6px; border: 1px solid #eaeaea; }
  .search-component-box { position: relative; flex: 1; min-width: 280px; }
  .search-component-box input { width: 100%; padding: 12px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; font-size: 14px; }
  .suggestive-dropdown-matrix { position: absolute; top: 100%; left: 0; right: 0; background: #fff; border: 1px solid #ccc; border-top: none; border-radius: 0 0 4px 4px; max-height: 220px; overflow-y: auto; z-index: 2000; display: none; box-shadow: 0 6px 12px rgba(0,0,0,0.15); }
  .suggestive-item-row { padding: 12px; cursor: pointer; border-bottom: 1px solid #f0f0f0; color: #222; font-weight: 500; display: flex; align-items: center; justify-content: space-between; }
  .suggestive-item-row:hover { background-color: #f0f4f8; color: #0056b3; }
  
  .folder-section-title { font-size: 14px; color: #475569; text-transform: uppercase; font-weight: bold; letter-spacing: 0.5px; margin-top: 15px; margin-bottom: 10px; display: flex; align-items: center; gap: 6px; }
  .folder-list-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 15px; margin-bottom: 25px; }
  .folder-item-node { padding: 15px; background: #fff; border: 1px solid #ddd; border-radius: 5px; text-decoration: none; color: #333; display: flex; align-items: center; justify-content: space-between; font-weight: bold; transition: all 0.2s; }
  .folder-item-node:hover { border-color: #007bff; background: #f8f9fa; transform: translateY(-2px); }
  .folder-item-node.active-folder-style { border-color: #007bff; background: #e7f1ff; color: #0056b3; }
  .folder-node-meta-title { display: inline-flex; align-items: center; gap: 8px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  
  .btn-icon-sm { display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; padding: 0 !important; border-radius: 6px !important; text-decoration: none; transition: transform 0.2s, box-shadow 0.2s; border: none; cursor: pointer; }
  .btn-icon-sm:hover { transform: translateY(-2px); box-shadow: 0 4px 6px rgba(0,0,0,0.08); }
  .btn-icon-sm svg { width: 16px; height: 16px; display: block; }
  .actions-container { display: flex; align-items: center; gap: 6px; flex-wrap: nowrap; }
  .timestamp-wrapper { font-size: 13px; line-height: 1.4; }
  .timestamp-time { font-size: 11px; color: #666; display: block; margin-top: 2px; }

  /* Advanced Modal Staging Framework Styles */
  .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 10000; display: flex; justify-content: center; align-items: center; padding: 20px; box-sizing: border-box; opacity: 0; visibility: hidden; transition: opacity 0.3s, visibility 0.3s; }
  .modal-overlay.is-active { opacity: 1; visibility: visible; }
  .modal-wrapper-card { background: #ffffff; padding: 30px; border-radius: 12px; width: 100%; max-width: 950px; box-shadow: 0 20px 40px rgba(0,0,0,0.25); max-height: 92vh; overflow-y: auto; position: relative; box-sizing: border-box; transform: translateY(15px); transition: transform 0.3s ease; }
  .modal-overlay.is-active .modal-wrapper-card { transform: translateY(0); }
  .preview-box-split { border: 1px dashed #cbd5e1; background: #f8fafc; height: 180px; border-radius: 8px; display: flex; align-items: center; justify-content: center; padding: 10px; box-sizing: border-box; overflow: hidden; }
  .staged-list-table { width: 100%; border-collapse: collapse; margin-top: 8px; font-size: 12px; }
  .staged-list-table th { background: #f1f5f9; padding: 6px 8px; text-align: left; font-weight: bold; color: #475569; }
  .staged-list-table td { padding: 6px 8px; border-bottom: 1px solid #e2e8f0; }
</style>

<h2 class="page-title">Folder Workspace</h2>

<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars(urldecode($success)) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><?= htmlspecialchars(urldecode($error)) ?></div><?php endif; ?>

<!-- FOLDER PROVISIONING CONTAINER INTERFACE BLOCK -->
<?php if ($role !== 'casual'): ?>
<div style="background: #f8fafc; border: 1px solid #e2e8f0; padding: 20px; border-radius: 8px; margin-bottom: 25px;">
    <h3 style="margin: 0 0 12px 0; font-size: 13px; color: #475569; text-transform: uppercase; font-weight: bold; letter-spacing: 0.5px;">Create New Folder / Workspace</h3>
    <form method="POST" action="folders.php" style="display: flex; flex-direction: column; gap: 12px; margin: 0;">
        <input type="hidden" name="form_action" value="create_folder">
        <div style="display: flex; gap: 10px;">
            <input type="text" name="foldername" placeholder="Enter folder name..." required style="flex: 1; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 14px;">
            <button type="submit" class="btn btn-primary" style="padding: 10px 20px; font-weight: bold;">Create Folder</button>
        </div>
        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 13px; color: #334155; max-width: max-content; user-select: none;">
            <input type="checkbox" name="is_private" value="1" style="width: 16px; height: 16px; cursor: pointer;">
            <strong>Make as Private</strong> (Only you and assigned users can view this)
        </label>
    </form>
</div>
<?php endif; ?>

<div class="workspace-search-bar-wrapper">
  <div class="search-component-box">
    <label for="globalFolderSearch" style="display:block; margin-bottom:5px; font-weight:bold; font-size:12px; color:#555;">FOLDER SEARCH</label>
    <input type="text" id="globalFolderSearch" placeholder="Type to filter workspace folder names..." autocomplete="off">
  </div>
  
  <div class="search-component-box">
    <label for="innerFileSearch" style="display:block; margin-bottom:5px; font-weight:bold; font-size:12px; color:#555;">FILE SEARCH</label>
    <input type="text" id="innerFileSearch" placeholder="<?= $current_folder ? 'Type filename inside ' . htmlspecialchars($current_folder['name']) . '...' : 'Select a folder below to search inside it...' ?>" <?= $current_folder ? '' : 'disabled' ?> autocomplete="off">
    <div id="fileSuggestionsDropdown" class="suggestive-dropdown-matrix"></div>
  </div>
</div>



<div class="explorer-layout-wrapper">
  <div class="card" style="margin-bottom: 25px;">
    <h3>Available Folders</h3>
    
    <!-- SPLIT A: PRIVATE DIRECTORIES REGISTRY -->
    <div class="folder-section-title" style="color: #ef4444;">Private Folders</div>
    <div class="folder-list-grid" id="privateFoldersContainer">
      <?php if (empty($private_folders)): ?>
        <p style="color:#aaa; font-style:italic; font-size:13px; margin-left: 5px;">No private folders.</p>
      <?php else: ?>
        <?php foreach ($private_folders as $f): ?>
          <div class="folder-item-node <?= ($current_folder_id === (int)$f['id']) ? 'active-folder-style' : '' ?>">
            <a href="folders.php?id=<?= $f['id'] ?>" class="folder-node-meta-title" style="text-decoration:none; color:inherit; flex:1;">
              <span>📁</span> <?= htmlspecialchars($f['name']) ?>
            </a>
            
            <div style="display:flex; gap:6px; align-items:center;">
              <?php if ($role === 'admin' || $f['created_by'] == $user['id']): ?>
                <button type="button" class="btn-icon-sm" style="background: #f59e0b; color: white;" title="Rename Workspace Node" onclick="openRenameFolderModal(<?= $f['id'] ?>, '<?= htmlspecialchars(addslashes($f['name'])) ?>')">
                  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 1 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                </button>
              <?php endif; ?>
              <?php if ($role !== 'casual'): ?>
                <button type="button" class="btn-icon-sm" style="background: #6366f1; color: white;" title="Folder Collaboration Matrix Hub" onclick="openFolderAccessModal(<?= $f['id'] ?>, '<?= htmlspecialchars(addslashes($f['name'])) ?>')">
                  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                </button>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- SPLIT B: PUBLIC DIRECTORIES REGISTRY -->
    <div class="folder-section-title" style="color: #10b981;">Public Folders</div>
    <div class="folder-list-grid" id="publicFoldersContainer">
      <?php if (empty($public_folders)): ?>
        <p style="color:#aaa; font-style:italic; font-size:13px; margin-left: 5px;">No public index workspaces deployed to repository setup.</p>
      <?php else: ?>
        <?php foreach ($public_folders as $f): ?>
          <div class="folder-item-node <?= ($current_folder_id === (int)$f['id']) ? 'active-folder-style' : '' ?>">
            <a href="folders.php?id=<?= $f['id'] ?>" class="folder-node-meta-title" style="text-decoration:none; color:inherit; flex:1;">
              <span>📁</span> <?= htmlspecialchars($f['name']) ?>
            </a>
            
            <div style="display:flex; gap:6px; align-items:center;">
              <?php if ($role === 'admin' || $f['created_by'] == $user['id']): ?>
                <button type="button" class="btn-icon-sm" style="background: #f59e0b; color: white;" title="Rename Workspace Node" onclick="openRenameFolderModal(<?= $f['id'] ?>, '<?= htmlspecialchars(addslashes($f['name'])) ?>')">
                  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 1 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                </button>
              <?php endif; ?>
              <?php if ($role !== 'casual'): ?>
                <button type="button" class="btn-icon-sm" style="background: #6366f1; color: white;" title="Folder Collaboration Matrix Hub" onclick="openFolderAccessModal(<?= $f['id'] ?>, '<?= htmlspecialchars(addslashes($f['name'])) ?>')">
                  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                </button>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($current_folder): ?>
    <div class="card">
      <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
        <h3>Files Inside: <span style="color:#007bff;"><?= htmlspecialchars($current_folder['name']) ?></span></h3>
        <a href="folders.php?id=0" class="btn btn-outline" onclick="<?php unset($_SESSION['last_viewed_folder_id']); ?>">&larr; Clear View</a>
      </div>
      
      <table class="data-table">
        <thead>
          <tr>
            <th>Filename</th>
            <th>Version</th>
            <th>Size</th>
            <th>Uploaded By</th>
            <th>Status</th>
            <th style="width: 130px;">Date & Time</th>
            <th style="width: 180px; text-align: left;">Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($folder_files)): ?>
          <tr><td colspan="7" class="empty-row">No files found inside this folder.</td></tr>
        <?php else: ?>
          <?php foreach ($folder_files as $doc_row): ?>
            <tr>
              <td><?= htmlspecialchars($doc_row['filename'] ?? '') ?></td>
              <td>v<?= (int)($doc_row['version'] ?? 1) ?></td>
              <td><?= isset($doc_row['size']) ? format_bytes((int)$doc_row['size']) : '—' ?></td>
              <td><?= htmlspecialchars($doc_row['uploader_name'] ?? '—') ?></td>
              <td>
                <?php if (!empty($doc_row['is_locked'])): ?>
                  <span class="badge badge-warn">Locked by <?= htmlspecialchars($doc_row['locker_name'] ?? '?') ?></span>
                <?php else: ?>
                  <span class="badge badge-ok">Available</span>
                <?php endif; ?>
              </td>
              <td>
                <div class="timestamp-wrapper">
                  <?php 
                    try {
                        $dateObj = new DateTime($doc_row['created_at']);
                        $ph_date = $dateObj->format('Y-m-d');
                        $ph_time = $dateObj->format('h:i A');
                    } catch (Exception $e) {
                        $ph_date = isset($doc_row['created_at']) ? substr($doc_row['created_at'], 0, 10) : '—';
                        $ph_time = '—';
                    }
                  ?>
                  <span><?= htmlspecialchars($ph_date) ?></span>
                  <span class="timestamp-time"><?= htmlspecialchars($ph_time) ?></span>
                </div>
              </td>
              <td>
                <div class="actions-container">
                  <button type="button" class="btn-icon-sm btn-primary" title="Edit Details" onclick="openMasterEditModal(<?= htmlspecialchars(json_encode($doc_row)) ?>)">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 1 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                  </button>

                  <a href="share.php?id=<?= $doc_row['id'] ?>" class="btn-icon-sm" style="background: #6366f1; color: white; text-decoration: none;" title="Share & Manage Access">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"></path><polyline points="16 6 12 2 8 6"></polyline><line x1="12" y1="2" x2="12" y2="15"></line></svg>
                  </a>

                  <a href="download.php?id=<?= $doc_row['id'] ?>" class="btn-icon-sm btn-outline" title="Download Document" style="background: #fff; border:1px solid #ccc;">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                  </a>

                  <?php if ($role !== 'casual'): ?>
                    <?php if (empty($doc_row['is_locked'])): ?>
                      <a href="version_control.php?action=checkout&id=<?= $doc_row['id'] ?>&origin=folders&folder_id=<?= (int)$current_folder_id ?>" class="btn-icon-sm btn-warn" title="Checkout (Lock)">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                      </a>
                    <?php elseif ($doc_row['locked_by'] == $user['id'] || $role === 'admin'): ?>
                      <a href="version_control.php?action=checkin&id=<?= $doc_row['id'] ?>&origin=folders&folder_id=<?= (int)$current_folder_id ?>" class="btn-icon-sm btn-ok" title="Checkin (Unlock)">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 9.9-1"></path></svg>
                      </a>
                    <?php endif; ?>

                    <a href="delete.php?id=<?= $doc_row['id'] ?>" class="btn-icon-sm btn-danger" title="Delete File" onclick="return confirm('Delete this file from workspace?')">
                      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>
                    </a>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<!-- NATIVE DYNAMIC FOLDER IDENTITY RENAMING OVERLAY MODAL -->
<div id="folderRenameModal" class="modal-overlay">
  <div class="modal-wrapper-card" style="max-width: 480px;">
    <span onclick="closeRenameFolderModal()" style="position: absolute; top: 15px; right: 20px; cursor: pointer; font-size: 26px; font-weight: bold; color: #aaa;" onmouseover="this.style.color='#333'">&times;</span>
    <h3 style="margin: 0 0 5px 0; color: #1e293b; font-size: 18px; font-weight: bold;">Rename Directory Node</h3>
    <p style="margin: 0 0 20px 0; color: #64748b; font-size: 13px;">Rewrite descriptor properties parameters across workspace allocations.</p>
    
    <form method="POST" action="folders.php">
      <input type="hidden" name="form_action" value="rename_folder">
      <input type="hidden" name="rename_folder_id" id="r_folder_id">
      
      <div style="margin-bottom: 18px;">
        <label for="r_foldername" style="display:block; margin-bottom:6px; font-weight:bold; font-size:12px; color:#475569;">DIRECTORY NAME</label>
        <input type="text" name="new_foldername" id="r_foldername" required style="width:100%; padding:10px; border:1px solid #cbd5e1; border-radius:6px; font-size:14px; box-sizing:border-box;">
      </div>
      
      <div style="display:flex; justify-content:flex-end; gap:10px;">
        <button type="button" onclick="closeRenameFolderModal()" style="background:#e2e8f0; border:none; color:#334155; padding:10px 20px; border-radius:6px; font-weight:bold; cursor:pointer;">Cancel</button>
        <button type="submit" style="background:#f59e0b; border:none; color:white; padding:10px 25px; border-radius:6px; font-weight:bold; cursor:pointer;">Apply Rename</button>
      </div>
    </form>
  </div>
</div>

<!-- UNIFIED FOLDER BLUEPRINT ACCESS MATRIX CONTROL MODAL -->
<div id="folderAccessModal" class="modal-overlay">
  <div class="modal-wrapper-card" style="max-width: 850px;">
    <span onclick="closeFolderAccessModal()" style="position: absolute; top: 15px; right: 20px; cursor: pointer; font-size: 26px; font-weight: bold; color: #aaa;" onmouseover="this.style.color='#333'">&times;</span>
    
    <h3 id="f_modal_title" style="margin: 0 0 5px 0; color: #1e293b; font-size: 19px; font-weight: bold;">Folder Collaboration Hub</h3>
    <p style="margin: 0 0 20px 0; color: #64748b; font-size: 13px;">Manage access control, granular operations rules, and active collaborator visibility lists.</p>

    <!-- SECTION A: ADD NEW FOLDER COLLABORATOR OPERATION RULES -->
    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 15px; box-sizing: border-box; display: flex; flex-direction: column; gap: 12px; margin-bottom: 25px;">
        <h4 style="margin: 0; font-size: 12px; color: #475569; text-transform: uppercase; letter-spacing: 0.5px; font-weight: bold;">Provision User Operation Access Scope</h4>
        
        <div style="display: flex; gap: 15px; align-items: center;">
            <select id="f_collab_picker" style="flex: 1; padding: 8px; font-size: 13px; border-radius: 6px; border: 1px solid #cbd5e1; background: #fff;">
                <option value="">-- Choose User / Collaborator Target --</option>
                <?php foreach ($system_collaborators as $cb_u): ?>
                    <option value="<?= $cb_u['id'] ?>"><?= htmlspecialchars($cb_u['username']) ?> (<?= htmlspecialchars($cb_u['role']) ?>)</option>
                <?php endforeach; ?>
            </select>
            
            <label style="font-size: 12px; color: #0284c7; font-weight: bold; cursor: pointer; user-select: none;">
                <input type="checkbox" onclick="document.querySelectorAll('.f-perm-cb').forEach(cb => cb.checked = this.checked)" style="cursor: pointer;"> Select All
            </label>
        </div>

        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; padding: 5px 0; border-top: 1px dashed #e2e8f0; padding-top: 10px;">
            <label style="font-size: 12px; color: #334155; display: flex; align-items: center; gap: 6px; cursor: pointer;"><input type="checkbox" class="f-perm-cb" id="fp_add" value="1" style="width: 15px; height: 15px;"> Allow Add Files</label>
            <label style="font-size: 12px; color: #334155; display: flex; align-items: center; gap: 6px; cursor: pointer;"><input type="checkbox" class="f-perm-cb" id="fp_edit" value="1" style="width: 15px; height: 15px;"> Allow Edit Details</label>
            <label style="font-size: 12px; color: #334155; display: flex; align-items: center; gap: 6px; cursor: pointer;"><input type="checkbox" class="f-perm-cb" id="fp_delete" value="1" style="width: 15px; height: 15px;"> Allow Delete Items</label>
            <label style="font-size: 12px; color: #334155; display: flex; align-items: center; gap: 6px; cursor: pointer;"><input type="checkbox" class="f-perm-cb" id="fp_checkout" value="1" style="width: 15px; height: 15px;"> Allow Checkin/Out</label>
            <label style="font-size: 12px; color: #334155; display: flex; align-items: center; gap: 6px; cursor: pointer;"><input type="checkbox" class="f-perm-cb" id="fp_download" value="1" checked style="width: 15px; height: 15px;"> Allow Download</label>
            <label style="font-size: 12px; color: #334155; display: flex; align-items: center; gap: 6px; cursor: pointer;"><input type="checkbox" class="f-perm-cb" id="fp_share" value="1" style="width: 15px; height: 15px;"> Allow Sub-Sharing</label>
        </div>

        <button type="button" class="btn btn-primary" style="padding: 10px; font-weight: bold; font-size: 13px;" onclick="submitFolderAccessScope()">Map Collaborator Permissions</button>
    </div>

    <!-- SECTION B: ACTIVE FOLDER USERS WITH CUSTOM ROLES REGISTRY LIST -->
    <div>
        <h4 style="margin: 0 0 10px 0; font-size: 12px; color: #475569; text-transform: uppercase; letter-spacing: 0.5px; font-weight: bold;">Active Access Control Registry List</h4>
        <div id="f_active_shares_container" style="max-height: 200px; overflow-y: auto; display: flex; flex-direction: column; gap: 8px;"></div>
    </div>
  </div>
</div>

<!-- UNIFIED MASTER ADVANCED EDIT & STAGING MANAGEMENT MODAL (Documents Layer) -->
<div id="masterEditModal" class="modal-overlay">
  <div class="modal-wrapper-card">
    <span onclick="closeMasterEditModal()" style="position: absolute; top: 15px; right: 20px; cursor: pointer; font-size: 26px; font-weight: bold; color: #aaa;" onmouseover="this.style.color='#333'">&times;</span>
    
    <h3 id="m_title" style="margin: 0 0 5px 0; color: #1e293b; font-size: 20px; font-weight: bold;">File Blueprint Manager</h3>
    <p id="m_attribution" style="margin: 0 0 25px 0; color: #64748b; font-size: 13px; font-family: sans-serif; display: flex; align-items: center; gap: 6px;"></p>

    <form id="m_staging_master_form" method="POST" action="version_control.php?action=commit_revision" enctype="multipart/form-data" onsubmit="executeMasterStagingSubmission(event)">
        <input type="hidden" name="document_id" id="m_doc_id">
        <input type="hidden" name="staged_rollback_version_id" id="m_staged_rollback_id" value="">
        <input type="hidden" name="staged_sharing_matrix_json" id="m_staged_sharing_json" value="[]">

        <div style="display: flex; flex-direction: column; gap: 25px;">
            
            <!-- SECTION 1: VISUAL REVISION PREVIEW UPLOAD -->
            <div style="border-top: 1px solid #f1f5f9; padding-top: 15px;">
                <h4 style="margin: 0 0 12px 0; font-size: 13px; color: #475569; text-transform: uppercase; letter-spacing: 0.5px; font-weight: bold;">1. Version Upload & File Preview Comparison</h4>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 15px;">
                    <div>
                      <small style="color: #64748b; font-weight: bold; display: block; margin-bottom: 4px; text-transform: uppercase; font-size: 11px;">Active Source File</small>
                      <div id="p_old" class="preview-box-split"></div>
                    </div>
                    <div>
                      <small style="color: #64748b; font-weight: bold; display: block; margin-bottom: 4px; text-transform: uppercase; font-size: 11px;">New Staged Revision/Rollback Preview</small>
                      <div id="p_new" class="preview-box-split" style="color: #94a3b8; font-size: 12px; font-style: italic; text-align: center;">No payload staged.</div>
                    </div>
                </div>

                <label style="display: block; background: #0284c7; color: #fff; padding: 12px; border-radius: 6px; font-weight: bold; font-size: 13px; cursor: pointer; text-align: center; margin-bottom: 10px; transition: background 0.2s;" onmouseover="this.style.background='#0369a1'" onmouseout="this.style.background='#0284c7'">
                    Choose New Overwrite File
                    <input type="file" name="revised_document" id="m_file_input" style="display: none;" onchange="renderCompareViewDelta(this)">
                </label>
                <p style="font-size: 11px; color: #ef4444; margin: 0; line-height: 1.4;">⚠️ Note: Staging file adjustments wraps an auto-lock on your account line, preventing concurrent checkouts until changes are applied or closed.</p>
            </div>

            <!-- SECTION 2: HISTORY LOGS / ROLLBACK CHECKPOINTS -->
            <div style="border-top: 1px solid #f1f5f9; padding-top: 15px;">
                <h4 style="margin: 0 0 12px 0; font-size: 13px; color: #475569; text-transform: uppercase; letter-spacing: 0.5px; font-weight: bold;">2. Version Registry History Log</h4>
                <div id="m_history_list" style="max-height: 160px; overflow-y: auto; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; display: flex; flex-direction: column; gap: 8px;"></div>
            </div>

            <!-- SECTION 3: ADVANCED ACCESS MATRIX -->
            <div style="border-top: 1px solid #f1f5f9; padding-top: 15px;">
                <h4 style="margin: 0 0 12px 0; font-size: 13px; color: #475569; text-transform: uppercase; letter-spacing: 0.5px; font-weight: bold;">3. Collaborative Access Shares</h4>
                
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 15px; box-sizing: border-box; display: flex; flex-direction: column; gap: 12px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px dashed #cbd5e1; padding-bottom: 8px;">
                        <select id="m_collab_picker" style="width: 45%; padding: 8px; font-size: 13px; border-radius: 6px; border: 1px solid #cbd5e1; color: #334155; background: #fff;">
                            <option value="">-- Choose User / Collaborator --</option>
                            <?php foreach ($system_collaborators as $cb_u): ?>
                                <option value="<?= $cb_u['id'] ?>" data-username="<?= htmlspecialchars($cb_u['username']) ?>" data-role="<?= htmlspecialchars($cb_u['role']) ?>"><?= htmlspecialchars($cb_u['username']) ?> (<?= htmlspecialchars($cb_u['role']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                        
                        <label style="font-size: 12px; color: #0284c7; font-weight: bold; cursor: pointer; display: flex; align-items: center; gap: 4px;">
                            <input type="checkbox" id="m_select_all_perms" onclick="document.querySelectorAll('.m-perm-cb').forEach(cb => cb.checked = this.checked)" style="cursor: pointer; width: 15px; height: 15px; margin: 0;"> Select All
                        </label>
                        <label style="font-size: 13px; color: #334155; display: flex; align-items: center; gap: 6px; cursor: pointer;"><input type="checkbox" class="m-perm-cb" id="p_edit" value="1"> Can Edit</label>
                        <label style="font-size: 13px; color: #334155; display: flex; align-items: center; gap: 6px; cursor: pointer;"><input type="checkbox" class="m-perm-cb" id="p_delete" value="1"> Can Delete</label>
                        <label style="font-size: 13px; color: #334155; display: flex; align-items: center; gap: 6px; cursor: pointer;"><input type="checkbox" class="m-perm-cb" id="p_download" value="1" checked> Can Download</label>
                        <label style="font-size: 13px; color: #334155; display: flex; align-items: center; gap: 6px; cursor: pointer;"><input type="checkbox" class="m-perm-cb" id="p_checkout" value="1"> Can Lock/Unlock</label>
                    </div>

                    <button type="button" class="btn btn-secondary" style="width: 100%; font-weight: bold; padding: 10px; border-radius: 6px; font-size: 13px; cursor: pointer;" onclick="stageDirectAccessPermissionRow()">Grant User Access</button>
                    
                    <div style="margin-top: 10px;">
                        <strong style="font-size: 11px; color: #64748b; text-transform: uppercase;">Share:</strong>
                        <table class="staged-list-table" id="m_staged_shares_table">
                            <thead>
                                <tr><th>Collaborator</th><th>Role</th><th>Capabilities</th><th>Remove</th></tr>
                            </thead>
                            <tbody id="m_staged_shares_tbody">
                                <tr><td colspan="4" style="text-align:center; color:#aaa; font-style:italic;">No existing update.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- UNIFIED SUBMIT FOOTER -->
            <div style="border-top: 2px solid #e2e8f0; padding-top: 20px; display: flex; justify-content: flex-end; gap: 12px; margin-top: 15px;">
                <button type="button" onclick="closeMasterEditModal()" style="background: #e2e8f0; border: none; color: #334155; padding: 12px 25px; border-radius: 6px; font-weight: bold; cursor: pointer; font-family: sans-serif;">Cancel Updates</button>
                <button type="submit" style="background: #16a34a; border: none; color: #fff; padding: 12px 35px; border-radius: 6px; font-weight: bold; cursor: pointer; font-size: 14px; box-shadow: 0 4px 6px rgba(22,163,74,0.2); font-family: sans-serif;" onmouseover="this.style.background='#15803d'" onmouseout="this.style.background='#16a34a'">Update Changes & Permissions →</button>
            </div>
        </div>
    </form>
  </div>
</div>

<!-- FRONTEND UI CONTROLLER SCRIPTS MODULAR ARCHITECTURE -->
<script>
// ==========================================
// 1. INLINE FOLDER RENAME CONTROLLER ENGINE
// ==========================================
function openRenameFolderModal(folderId, folderName) {
    document.getElementById('r_folder_id').value = folderId;
    document.getElementById('r_foldername').value = folderName;
    document.getElementById('folderRenameModal').classList.add('is-active');
    document.body.style.overflow = 'hidden';
}

function closeRenameFolderModal() {
    document.getElementById('folderRenameModal').classList.remove('is-active');
    document.body.style.overflow = '';
}

// ==========================================
// 2. FOLDER ACCESS MANAGEMENT MODAL ENGINE
// ==========================================
let activeFolderId = null;

function openFolderAccessModal(folderId, folderName) {
    activeFolderId = folderId;
    document.getElementById('f_modal_title').textContent = "Access Setup: " + folderName;
    refreshFolderAccessList();

    document.getElementById('folderAccessModal').classList.add('is-active');
    document.body.style.overflow = 'hidden';
}

function closeFolderAccessModal() {
    document.getElementById('folderAccessModal').classList.remove('is-active');
    document.body.style.overflow = '';
    activeFolderId = null;
    document.getElementById('f_collab_picker').value = '';
    document.querySelectorAll('.f-perm-cb').forEach(cb => cb.checked = cb.id === 'fp_download');
}

function refreshFolderAccessList() {
    const container = document.getElementById('f_active_shares_container');
    container.innerHTML = '<small style="color:#64748b; text-align:center; display:block; padding:10px;">Synchronizing mapping metrics registries...</small>';

    fetch(`folder_control.php?action=get_shares&folder_id=${activeFolderId}`)
    .then(res => res.json())
    .then(data => {
        container.innerHTML = '';
        if(data.length === 0) {
            container.innerHTML = '<small style="color:#94a3b8; font-style:italic; text-align:center; display:block; padding:10px;">No custom user shares assigned to this folder context yet.</small>';
            return;
        }
        data.forEach(row => {
            let scopes = [];
            if(row.can_add == 1)      scopes.push('Add');
            if(row.can_edit == 1)     scopes.push('Edit');
            if(row.can_delete == 1)   scopes.push('Delete');
            if(row.can_checkout == 1) scopes.push('Checkin/Out');
            if(row.can_download == 1) scopes.push('Download');
            if(row.can_share == 1)    scopes.push('Share');

            container.innerHTML += `
                <div style="display:flex; justify-content:space-between; align-items:center; background:#f8fafc; border:1px solid #e2e8f0; padding:10px 14px; border-radius:6px; font-size:12px;">
                    <div>
                        <strong>${row.username}</strong> <small style="color:#475569; background:#e2e8f0; padding:2px 5px; border-radius:4px; font-weight:bold; font-size:10px;">${row.role.toUpperCase()}</small>
                        <div style="margin-top:4px; color:#0284c7; font-weight:500; font-size:11px;">Rules: ${scopes.join(', ') || 'Visibility Block Map'}</div>
                    </div>
                    <button type="button" class="btn btn-sm btn-danger" style="padding:4px 8px; font-size:11px; cursor:pointer;" onclick="revokeFolderAccess(${row.id})">Revoke</button>
                </div>
            `;
        });
    }).catch(() => container.innerHTML = '<small style="color:#ef4444; display:block; padding:10px;">Failure to pull database allocation metrics.</small>');
}

function submitFolderAccessScope() {
    const userId = document.getElementById('f_collab_picker').value;
    if (!userId) { alert('Please choose a user target first.'); return; }

    const payload = {
        folder_id: activeFolderId,
        shared_with_user_id: userId,
        can_add: document.getElementById('fp_add').checked ? 1 : 0,
        can_edit: document.getElementById('fp_edit').checked ? 1 : 0,
        can_delete: document.getElementById('fp_delete').checked ? 1 : 0,
        can_checkout: document.getElementById('fp_checkout').checked ? 1 : 0,
        can_download: document.getElementById('fp_download').checked ? 1 : 0,
        can_share: document.getElementById('fp_share').checked ? 1 : 0
    };

    fetch('folder_control.php?action=grant_access', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(res => res.json())
    .then(resData => {
        if(resData.success) {
            refreshFolderAccessList();
            document.getElementById('f_collab_picker').value = '';
        } else {
            alert('Allocation framework exception error: ' + resData.message);
        }
    });
}

function revokeFolderAccess(shareId) {
    if(!confirm('Revoke all custom access metrics rules from this collaborator target?')) return;
    fetch(`folder_control.php?action=revoke_access&share_id=${shareId}`, { method: 'POST' })
    .then(res => res.json())
    .then(() => refreshFolderAccessList());
}

// ==========================================
// 3. DOCUMENT BLUEPRINT STUDIO MASTER WINDOWS
// ==========================================
let localStagedSharingMatrix = [];
let historicalBacklogCache = [];

function openMasterEditModal(doc) {
    const modal = document.getElementById('masterEditModal');
    document.getElementById('m_title').textContent = "Staging Studio: " + doc.filename;
    document.getElementById('m_doc_id').value = doc.id;
    document.getElementById('m_staged_rollback_id').value = "";
    document.getElementById('m_staged_sharing_json').value = "[]";
    localStagedSharingMatrix = [];
    renderStagedSharingQueueTable();

    document.getElementById('m_attribution').innerHTML = `👤 <strong>Granted By:</strong> ${doc.uploader_name || 'System Root'} <small style="text-transform: uppercase; background: #e2e8f0; padding: 2px 6px; border-radius: 4px; font-weight: bold; color: #475569; font-size: 10px; margin-left: 4px;">${doc.uploader_role || 'ADMIN'}</small>`;

    fetch('version_control.php?action=silent_lock&id=' + doc.id);

    const oldPane = document.getElementById('p_old');
    const ext = doc.filename.split('.').pop().toLowerCase();
    if (['png','jpg','jpeg','gif','webp','svg'].includes(ext)) {
        oldPane.innerHTML = `<img src="uploads/${doc.storage_path}" style="max-width: 100%; max-height: 100%; object-fit: contain; border-radius: 4px;">`;
    } else {
        oldPane.innerHTML = `<div style="text-align: center;"><span style="font-size: 36px; display: block; margin-bottom: 4px;">📄</span><small style="color: #64748b; font-weight: bold;">${ext.toUpperCase()} Source Available</small></div>`;
    }

    const historyList = document.getElementById('m_history_list');
    historyList.innerHTML = '<small style="color: #64748b; text-align: center; display: block; padding: 10px;">Loading history checkpoint backlog logs...</small>';
    
    fetch('version_control.php?action=get_history&id=' + doc.id)
    .then(res => res.json())
    .then(versions => {
        historyList.innerHTML = '';
        historicalBacklogCache = versions; 
        if (versions.length === 0) {
            historyList.innerHTML = '<small style="color: #94a3b8; text-align: center; font-style: italic; display: block; padding: 10px;">No historical checkpoints recorded.</small>';
            return;
        }
        versions.forEach(v => {
            historyList.innerHTML += `
                <div style="display: flex; justify-content: space-between; align-items: center; background: #fff; border: 1px solid #e2e8f0; padding: 8px 12px; border-radius: 6px; font-size: 12px; gap: 10px;">
                    <div><strong>Checkpoint Version v${v.version_number}</strong><br><small style="color: #64748b; display: block; margin-top: 2px;">Created: ${v.created_at}</small></div>
                    <button type="button" class="btn btn-sm btn-warn" style="font-size: 11px; padding: 5px 10px; font-weight: bold; border-radius: 4px; cursor:pointer;" onclick="stageHistoricalVersionRollback(${v.id}, ${v.version_number}, '${v.storage_path}')">Stage Rollback</button>
                </div>`;
        });
    }).catch(() => historyList.innerHTML = '<small style="color: #ef4444; text-align: center; display: block; padding: 10px;">Failed to fetch history checkpoint logs.</small>');

    modal.classList.add('is-active');
    document.body.style.overflow = 'hidden';
}

function closeMasterEditModal() {
    const modal = document.getElementById('masterEditModal');
    const docId = document.getElementById('m_doc_id').value;
    fetch('version_control.php?action=silent_unlock&id=' + docId);
    
    modal.classList.remove('is-active');
    document.body.style.overflow = '';
    document.getElementById('p_new').innerHTML = 'No payload staged.';
    document.getElementById('m_file_input').value = '';
    document.getElementById('m_staged_rollback_id').value = '';
    document.getElementById('m_select_all_perms').checked = false;
    document.querySelectorAll('.m-perm-cb').forEach(cb => cb.checked = cb.id === 'p_download');
}

function renderCompareViewDelta(input) {
    document.getElementById('m_staged_rollback_id').value = "";
    const pane = document.getElementById('p_new');
    
    if (input.files && input.files[0]) {
        const file = input.files[0];
        const ext = file.name.split('.').pop().toLowerCase();
        if (['png','jpg','jpeg','gif','webp','svg'].includes(ext)) {
            const reader = new FileReader();
            reader.onload = function(e) {
                pane.innerHTML = `<img src="${e.target.result}" style="max-width: 100%; max-height: 100%; object-fit: contain; border-radius: 4px;">`;
            };
            reader.readAsDataURL(file);
        } else {
            pane.innerHTML = `<div style="text-align: center; font-size: 12px; padding: 10px;">📄 <strong style="display: block; word-break: break-all; margin-top: 4px;">${file.name}</strong><small style="color: #16a34a; font-weight: bold; display: block; margin-top: 4px;">🔄 Staged File Replacement (Pending Commit)</small></div>`;
        }
    }
}

function stageHistoricalVersionRollback(versionId, versionNumber, storagePath) {
    document.getElementById('m_file_input').value = "";
    document.getElementById('m_staged_rollback_id').value = versionId;
    
    const pane = document.getElementById('p_new');
    const ext = storagePath.split('.').pop().toLowerCase();
    
    if (['png','jpg','jpeg','gif','webp','svg'].includes(ext)) {
        pane.innerHTML = `<div style="position:relative; width:100%; height:100%;"><img src="uploads/${storagePath}" style="max-width: 100%; max-height: 100%; object-fit: contain; border-radius: 4px; opacity:0.8;"><span style="position:absolute; bottom:5px; left:5px; background:rgba(217,119,6,0.9); color:#fff; font-size:10px; padding:2px 6px; font-weight:bold; border-radius:4px;">STAGED: v${versionNumber} ROLLBACK</span></div>`;
    } else {
        pane.innerHTML = `<div style="text-align: center; padding: 10px;">⏪ <strong style="display: block; margin-top: 4px;">Rollback Checkpoint v${versionNumber}</strong><small style="color: #d97706; font-weight: bold; display: block; margin-top: 4px;">Staged for Rollback (Pending Commit)</small></div>`;
    }
}

function stageDirectAccessPermissionRow() {
    const picker = document.getElementById('m_collab_picker');
    const userId = picker.value;
    if (!userId) { alert('Please select a target user / collaborator first.'); return; }

    const selectedOption = picker.options[picker.selectedIndex];
    const username = selectedOption.getAttribute('data-username');
    const userRole = selectedOption.getAttribute('data-role');

    const caps = {
        user_id: parseInt(userId),
        username: username,
        role: userRole,
        can_edit: document.getElementById('p_edit').checked ? 1 : 0,
        can_delete: document.getElementById('p_delete').checked ? 1 : 0,
        can_download: document.getElementById('p_download').checked ? 1 : 0,
        can_checkout: document.getElementById('p_checkout').checked ? 1 : 0
    };

    localStagedSharingMatrix = localStagedSharingMatrix.filter(item => item.user_id !== caps.user_id);
    localStagedSharingMatrix.push(caps);
    
    document.getElementById('m_staged_sharing_json').value = JSON.stringify(localStagedSharingMatrix);
    renderStagedSharingQueueTable();
    
    picker.value = "";
    document.getElementById('m_select_all_perms').checked = false;
    document.querySelectorAll('.m-perm-cb').forEach(cb => cb.checked = cb.id === 'p_download');
}

function removeStagedPermissionRow(userId) {
    localStagedSharingMatrix = localStagedSharingMatrix.filter(item => item.user_id !== userId);
    document.getElementById('m_staged_sharing_json').value = JSON.stringify(localStagedSharingMatrix);
    renderStagedSharingQueueTable();
}

function renderStagedSharingQueueTable() {
    const tbody = document.getElementById('m_staged_shares_tbody');
    if (localStagedSharingMatrix.length === 0) {
        tbody.innerHTML = `<tr><td colspan="4" style="text-align:center; color:#aaa; font-style:italic;">No existing update.</td></tr>`;
        return;
    }
    
    tbody.innerHTML = "";
    localStagedSharingMatrix.forEach(item => {
        let capsStringArray = [];
        if (item.can_edit) capsStringArray.push('Edit');
        if (item.can_delete) capsStringArray.push('Delete');
        if (item.can_download) capsStringArray.push('Download');
        if (item.can_checkout) capsStringArray.push('Lock/Unlock');
        let capabilitiesLabel = capsStringArray.length > 0 ? capsStringArray.join(', ') : 'No Access (Blocked)';

        tbody.innerHTML += `
            <tr>
                <td><strong>${escapeHtml(item.username)}</strong></td>
                <td><small style="background:#e2e8f0; padding:1px 4px; border-radius:4px; font-weight:bold; text-transform:uppercase; font-size:10px;">${escapeHtml(item.role)}</small></td>
                <td style="color:#16a34a; font-weight:bold;">${capabilitiesLabel}</td>
                <td><button type="button" style="background:#ef4444; border:none; color:#fff; padding:2px 6px; font-size:11px; border-radius:4px; cursor:pointer;" onclick="removeStagedPermissionRow(${item.user_id})">✕ Remove</button></td>
            </tr>`;
    });
}

function executeMasterStagingSubmission(event) {
    event.preventDefault();
    
    const fileInput = document.getElementById('m_file_input');
    const rollbackId = document.getElementById('m_staged_rollback_id').value;
    const docId = document.getElementById('m_doc_id').value;
    
    const formData = new FormData(document.getElementById('m_staging_master_form'));
    let actionTargetUrl = 'version_control.php?action=commit_revision';
    
    if (rollbackId !== "") {
        actionTargetUrl = `version_control.php?action=rollback&doc_id=${docId}&version_id=${rollbackId}`;
    } else if (!fileInput.files.length) {
        actionTargetUrl = 'version_control.php?action=commit_permissions_only';
    }

    fetch(actionTargetUrl, { method: 'POST', body: formData })
    .then(res => {
        const encodedMsg = encodeURIComponent('Update successfully!');
        window.location.href = 'folders.php?id=<?= $current_folder_id ?>&ok=' + encodedMsg;
    }).catch(() => alert('Network asset allocation system sync exception error loop.'));
}

function escapeHtml(str) {
    return str.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
}

// ==========================================
// 4. FRONTEND LAYOUT ACCELERATED SEARCH METRICS
// ==========================================
document.addEventListener("DOMContentLoaded", () => {
    window.addEventListener('click', function(e) {
        const fModal = document.getElementById('folderAccessModal');
        const mModal = document.getElementById('masterEditModal');
        const rModal = document.getElementById('folderRenameModal');
        if (e.target === fModal) { closeFolderAccessModal(); }
        if (e.target === mModal) { closeMasterEditModal(); }
        if (e.target === rModal) { closeRenameFolderModal(); }
    });

    const folderSearchInput = document.getElementById('globalFolderSearch');
    if (folderSearchInput) {
        folderSearchInput.addEventListener('input', function() {
            const val = this.value.toLowerCase().trim();
            const items = document.querySelectorAll('.folder-item-node');
            items.forEach(item => {
                const titleNode = item.querySelector('.folder-node-meta-title');
                if (titleNode) {
                    const text = titleNode.textContent.toLowerCase();
                    item.style.display = text.includes(val) ? 'flex' : 'none';
                }
            });
        });
    }

    const fileSearchInput = document.getElementById('innerFileSearch');
    const fileDropdown = document.getElementById('fileSuggestionsDropdown');
    
    if (fileSearchInput && fileDropdown) {
        fileSearchInput.addEventListener('input', function() {
            const query = this.value.trim();
            const fid = '<?= (int)$current_folder_id ?>';
            
            if (query.length < 1) {
                fileDropdown.style.display = 'none';
                return;
            }

            fetch(`folders.php?action=suggest_inner_files&folder_id=${fid}&q=${encodeURIComponent(query)}`)
            .then(res => res.json())
            .then(arr => {
                fileDropdown.innerHTML = '';
                if (arr.length === 0) {
                    fileDropdown.innerHTML = '<div class="suggestive-item-row" style="color:#aaa; cursor:default;">No internal files found matching search query...</div>';
                    fileDropdown.style.display = 'block';
                    return;
                }
                arr.forEach(item => {
                    const dRow = document.createElement('div');
                    dRow.className = 'suggestive-item-row';
                    dRow.innerHTML = `<span>📄 ${escapeHtml(item.filename)}</span> <small style="color:#007bff; font-weight:bold;">Open File Context →</small>`;
                    dRow.addEventListener('click', () => {
                        fileSearchInput.value = item.filename;
                        fileDropdown.style.display = 'none';
                        alert('Targeting inner resource: ' + item.filename);
                    });
                    fileDropdown.appendChild(dRow);
                });
                fileDropdown.style.display = 'block';
            });
        });

        document.addEventListener('click', function(e) {
            if (e.target !== fileSearchInput && e.target !== fileDropdown) {
                fileDropdown.style.display = 'none';
            }
        });
    }
});
</script>

<?php 
include __DIR__ . '/partials/footer.php'; 
?>