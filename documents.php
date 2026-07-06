<?php
/**
 * documents.php — Document browser with Unified Advanced Edit Modal
 */
require_once __DIR__ . '/auth.php';
$user = require_login();
$db   = get_db();
$role = $user['role'];

// Force Philippine Standard Time explicitly for database sync
date_default_timezone_set('Asia/Manila');

// FIXED: Define the helper function at the top so it is available globally during page rendering
function format_bytes(int $bytes): string {
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024)    return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

$search = trim($_GET['search'] ?? '');
$like   = '%' . $search . '%';

// ── Fetch documents based on role ──────────────────────────────────────
if ($role === 'casual') {
    $stmt = $db->prepare(
        'SELECT d.*, u.username AS uploader_name, ur.role AS uploader_role, lk.username AS locker_name, lk.role AS locker_role
         FROM documents d
         INNER JOIN document_shares ds ON ds.document_id = d.id AND ds.shared_with_user_id = ?
         LEFT JOIN users u  ON u.id = d.uploaded_by
         LEFT JOIN users ur ON ur.id = d.uploaded_by
         LEFT JOIN users lk ON lk.id = d.locked_by
         WHERE d.is_deleted = 0 AND d.filename LIKE ?
         ORDER BY d.created_at DESC'
    );
    $stmt->bind_param('is', $user['id'], $like);
    $stmt->execute();
} else {
    $stmt = $db->prepare(
        'SELECT d.*, u.username AS uploader_name, ur.role AS uploader_role, lk.username AS locker_name, lk.role AS locker_role
         FROM documents d
         LEFT JOIN users u  ON u.id = d.uploaded_by
         LEFT JOIN users ur ON ur.id = d.uploaded_by
         LEFT JOIN users lk ON lk.id = d.locked_by
         WHERE d.is_deleted = 0 AND d.filename LIKE ?
         ORDER BY d.created_at DESC'
    );
    $stmt->bind_param('s', $like);
    $stmt->execute();
}
$documents = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch system users for the sharing matrix inside the modal
$users_stmt = $db->prepare("SELECT id, username, role FROM users WHERE id != ? AND status = 'active' ORDER BY username ASC");
$users_stmt->bind_param('i', $user['id']);
$users_stmt->execute();
$system_collaborators = $users_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$success = $_GET['ok']  ?? '';
$error   = $_GET['err'] ?? '';

$page_title = 'Documents';
include __DIR__ . '/partials/header.php';
?>

<style>
  .btn-icon-sm {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    padding: 0 !important;
    border-radius: 6px !important;
    text-decoration: none;
    transition: transform 0.2s ease, background-color 0.2s ease, box-shadow 0.2s ease;
  }
  .btn-icon-sm:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 6px rgba(0,0,0,0.08);
  }
  .btn-icon-sm svg { width: 16px; height: 16px; display: block; }
  .actions-container { display: flex; align-items: center; gap: 6px; flex-wrap: nowrap; }
  .timestamp-wrapper { font-size: 13px; line-height: 1.4; }
  .timestamp-time { font-size: 11px; color: #666; display: block; margin-top: 2px; }
  
  /* Advanced Modal Framework Styles */
  .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 10000; display: flex; justify-content: center; align-items: center; padding: 20px; box-sizing: border-box; opacity: 0; visibility: hidden; transition: opacity 0.3s, visibility 0.3s; }
  .modal-overlay.is-active { opacity: 1; visibility: visible; }
  .modal-wrapper-card { background: #ffffff; padding: 30px; border-radius: 12px; width: 100%; max-width: 900px; box-shadow: 0 20px 40px rgba(0,0,0,0.25); max-height: 90vh; overflow-y: auto; position: relative; box-sizing: border-box; transform: translateY(15px); transition: transform 0.3s ease; }
  .modal-overlay.is-active .modal-wrapper-card { transform: translateY(0); }
  .preview-box-split { border: 1px dashed #cbd5e1; background: #f8fafc; height: 180px; border-radius: 8px; display: flex; align-items: center; justify-content: center; padding: 10px; box-sizing: border-box; overflow: hidden; }
</style>

<div class="page-header">
  <h2 class="page-title">Documents</h2>
  <?php if ($role !== 'casual'): ?>
    <a href="upload.php" class="btn btn-primary">Upload New</a>
  <?php endif; ?>
</div>

<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars(urldecode($success)) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><?= htmlspecialchars(urldecode($error)) ?></div><?php endif; ?>

<form method="GET" action="documents.php" class="search-form">
  <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search by filename…">
  <button type="submit" class="btn btn-secondary">Search</button>
  <?php if ($search): ?><a href="documents.php" class="btn btn-outline">Clear</a><?php endif; ?>
</form>

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
  <?php 
  $items_list = isset($folder_files) ? $folder_files : ($documents ?? []);
  
  if (empty($items_list)): 
  ?>
    <tr><td colspan="7" class="empty-row">No documents found.</td></tr>
  <?php else: ?>
    <?php foreach ($items_list as $doc): 
        // 1. Determine locker's hierarchy role 
        $locker_role = $doc['locker_role'] ?? 'user';
        if (!empty($doc['is_locked']) && !isset($doc['locker_role'])) {
            $locker_role = $doc['uploader_role'] ?? 'user';
        }

        // 2. Enforce Access Rules Permissions Layer
        $can_write = true; 
        if (!empty($doc['is_locked'])) {
            // Admin lock blocks everyone else
            if ($locker_role === 'admin' && $role !== 'admin') {
                $can_write = false;
            }
            // Contributor lock blocks users, but Admin bypasses safely
            elseif ($locker_role === 'contributor' && $role === 'user') {
                $can_write = false;
            }
        }
    ?>
    <tr>
      <td><?= htmlspecialchars($doc['filename'] ?? $doc['foldername'] ?? '') ?></td>
      <td>v<?= (int)($doc['version'] ?? 1) ?></td>
      <td><?= isset($doc['size']) ? format_bytes((int)$doc['size']) : '—' ?></td>
      <td><?= htmlspecialchars($doc['uploader_name'] ?? '—') ?></td>
      <td>
        <?php if (!empty($doc['is_locked'])): ?>
          <span class="badge badge-warn">Locked by <?= htmlspecialchars($doc['locker_name'] ?? '?') ?></span>
        <?php else: ?>
          <span class="badge badge-ok">Available</span>
        <?php endif; ?>
      </td>
      <td>
        <div class="timestamp-wrapper">
          <?php 
            try {
                $dateObj = new DateTime($doc['created_at']);
                $ph_date = $dateObj->format('Y-m-d');
                $ph_time = $dateObj->format('h:i A');
            } catch (Exception $e) {
                $ph_date = isset($doc['created_at']) ? substr($doc['created_at'], 0, 10) : '—';
                $ph_time = '—';
            }
          ?>
          <span><?= htmlspecialchars($ph_date) ?></span>
          <span class="timestamp-time"><?= htmlspecialchars($ph_time) ?></span>
        </div>
      </td>
      <td>
        <div class="actions-container">
          <!-- 1. Master Edit Button (Visible based on permission bypass hierarchy matrix rules) -->
          <?php if ($can_write): ?>
            <button type="button" class="btn-icon-sm btn-primary" title="Edit File Details" onclick="openMasterEditModal(<?= htmlspecialchars(json_encode($doc)) ?>)">
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                <path d="M18.5 2.5a2.121 2.121 0 1 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
              </svg>
            </button>
          <?php endif; ?>

          <!-- 2. Share Link (Always visible to all roles) -->
          <a href="share.php?id=<?= $doc['id'] ?>" class="btn-icon-sm" style="background: #6366f1; color: white; border: none; box-shadow: none; text-decoration: none;" title="Share & Manage Access">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
              <path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"></path>
              <polyline points="16 6 12 2 8 6"></polyline>
              <line x1="12" y1="2" x2="12" y2="15"></line>
            </svg>
          </a>

          <!-- 3. Download Action (Always visible to all roles) -->
          <a href="download.php?id=<?= $doc['id'] ?>" class="btn-icon-sm btn-outline" title="Download">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
              <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
              <polyline points="7 10 12 15 17 10"></polyline>
              <line x1="12" y1="15" x2="12" y2="3"></line>
            </svg>
          </a>

          <?php if ($role !== 'casual'): ?>
            <!-- 4. Checkout / Checkin Action Elements -->
            <?php if (empty($doc['is_locked'])): ?>
              <a href="version_control.php?action=checkout&id=<?= $doc['id'] ?><?= isset($current_folder_id) ? '&origin=folders&folder_id='.(int)$current_folder_id : '' ?>" class="btn-icon-sm btn-warn" title="Checkout (Lock)">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                  <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                  <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                </svg>
              </a>
            <?php else: 
              // Determine clear unlock/bypass authority layout 
              $can_unlock = false;
              if ((int)$doc['locked_by'] === (int)$user['id']) { $can_unlock = true; }
              elseif ($role === 'admin' && $locker_role !== 'admin') { $can_unlock = true; }
              elseif ($role === 'contributor' && $locker_role === 'user') { $can_unlock = true; }
              
              if ($can_unlock):
            ?>
              <a href="version_control.php?action=checkin&id=<?= $doc['id'] ?><?= isset($current_folder_id) ? '&origin=folders&folder_id='.(int)$current_folder_id : '' ?>" class="btn-icon-sm btn-ok" title="Checkin (Unlock)">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                  <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                  <path d="M7 11V7a5 5 0 0 1 9.9-1"></path>
                </svg>
              </a>
              <?php endif; ?>
            <?php endif; ?>

            <!-- 5. Delete Action (Controlled dynamically by lock bypass permission variables) -->
            <?php if ($can_write): ?>
              <a href="delete.php?id=<?= $doc['id'] ?>" class="btn-icon-sm btn-danger" title="Delete" onclick="return confirm('Delete this file context?')">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                  <polyline points="3 6 5 6 21 6"></polyline>
                  <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                  <line x1="10" y1="11" x2="10" y2="17"></line>
                  <line x1="14" y1="11" x2="14" y2="17"></line>
                </svg>
              </a>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
  <?php endif; ?>
  </tbody>
</table>

<!-- UNIFIED MASTER ADVANCED EDIT & MANAGEMENT MODAL -->
<div id="masterEditModal" class="modal-overlay">
  <div class="modal-wrapper-card">
    <span onclick="closeMasterEditModal()" style="position: absolute; top: 15px; right: 20px; cursor: pointer; font-size: 26px; font-weight: bold; color: #aaa;" onmouseover="this.style.color='#333'">&times;</span>
    
    <h3 id="m_title" style="margin: 0 0 5px 0; color: #1e293b; font-size: 20px; font-weight: bold;">File Blueprint Manager</h3>
    
    <!-- ATTRIBUTION PANEL: Shows who gave access and their role -->
    <p id="m_attribution" style="margin: 0 0 25px 0; color: #64748b; font-size: 13px; font-family: sans-serif; display: flex; align-items: center; gap: 6px;"></p>

    <div style="display: flex; flex-direction: column; gap: 25px;">
        
        <!-- SECTION 1: SIDE-BY-SIDE VISUAL COMPARISON AND REVISION UPLOAD -->
        <div style="border-top: 1px solid #f1f5f9; padding-top: 15px;">
            <h4 style="margin: 0 0 12px 0; font-size: 13px; color: #475569; text-transform: uppercase; letter-spacing: 0.5px; font-weight: bold;">1. Version Upload & File Preview Comparison</h4>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 15px;">
                <div>
                  <small style="color: #64748b; font-weight: bold; display: block; margin-bottom: 4px; text-transform: uppercase; font-size: 11px;">Active Source File</small>
                  <div id="p_old" class="preview-box-split"></div>
                </div>
                <div>
                  <small style="color: #64748b; font-weight: bold; display: block; margin-bottom: 4px; text-transform: uppercase; font-size: 11px;">New Preview</small>
                  <div id="p_new" class="preview-box-split" style="color: #94a3b8; font-size: 12px; font-style: italic; text-align: center;">No preview yet.</div>
                </div>
            </div>

            <form id="m_upload_form" method="POST" action="version_control.php?action=commit_revision" enctype="multipart/form-data" style="margin: 0;">
                <input type="hidden" name="document_id" id="m_doc_id">
                <label style="display: block; background: #0284c7; color: #fff; padding: 12px; border-radius: 6px; font-weight: bold; font-size: 13px; cursor: pointer; text-align: center; margin-bottom: 10px; transition: background 0.2s;" onmouseover="this.style.background='#0369a1'" onmouseout="this.style.background='#0284c7'">
                    Choose New File
                    <input type="file" name="revised_document" id="m_file_input" style="display: none;" onchange="renderCompareViewDelta(this)">
                </label>
                <!-- <p style="font-size: 11px; color: #ef4444; margin: 0; line-height: 1.4;">⚠️ Note: Staging a file immediately signs an auto-lock on your name, preventing others from checking out or downloading until you apply modifications.</p> -->
                <button type="submit" class="btn btn-primary" style="width: 100%; padding: 12px; margin-top: 15px; font-weight: bold; background: #16a34a; border: none; color: #fff; border-radius: 6px; cursor: pointer; transition: background 0.2s;" onmouseover="this.style.background='#15803d'" onmouseout="this.style.background='#16a34a'">Apply Changes</button>
            </form>
        </div>

        <!-- SECTION 2: HISTORY LOGS / ROLLBACK CHECKPOINTS -->
        <div style="border-top: 1px solid #f1f5f9; padding-top: 15px;">
            <h4 style="margin: 0 0 12px 0; font-size: 13px; color: #475569; text-transform: uppercase; letter-spacing: 0.5px; font-weight: bold;">2. Version History</h4>
            <div id="m_history_list" style="max-height: 160px; overflow-y: auto; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; display: flex; flex-direction: column; gap: 8px; box-shadow: inset 0 2px 4px rgba(0,0,0,0.01);"></div>
        </div>

        <!-- SECTION 3: ADVANCED ACCESS MATRIX / COLLABORATION ASSIGNMENT
        <div style="border-top: 1px solid #f1f5f9; padding-top: 15px;">
            <h4 style="margin: 0 0 12px 0; font-size: 13px; color: #475569; text-transform: uppercase; letter-spacing: 0.5px; font-weight: bold;">3. Collaborative Access Shares</h4>
            
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 15px; box-sizing: border-box; display: flex; flex-direction: column; gap: 15px;">
                <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px dashed #cbd5e1; padding-bottom: 8px; margin-bottom: 5px;">
                    <select id="m_collab_picker" style="width: 45%; padding: 8px; font-size: 13px; border-radius: 6px; border: 1px solid #cbd5e1; color: #334155; background: #fff;">
                        <option value="">-- Choose User / Collaborator --</option>
                        <?php foreach ($system_collaborators as $cb_u): ?>
                            <option value="<?= $cb_u['id'] ?>"><?= htmlspecialchars($cb_u['username']) ?> (<?= htmlspecialchars($cb_u['role']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    
                    <label style="font-size: 12px; color: #0284c7; font-weight: bold; cursor: pointer; display: flex; align-items: center; gap: 4px;">
                        <input type="checkbox" id="m_select_all_perms" onclick="document.querySelectorAll('.m-perm-cb').forEach(cb => cb.checked = this.checked)" style="cursor: pointer; width: 15px; height: 15px; margin: 0;"> Select All Permissions
                    </label>
                </div> -->

                <!-- Granular Permissions Checklist Row -->
                <!-- <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; padding: 5px 0;">
                    <label style="font-size: 13px; color: #334155; display: flex; align-items: center; gap: 6px; cursor: pointer;"><input type="checkbox" class="m-perm-cb" id="p_edit" value="1" style="width: 16px; height: 16px;"> Can Edit</label>
                    <label style="font-size: 13px; color: #334155; display: flex; align-items: center; gap: 6px; cursor: pointer;"><input type="checkbox" class="m-perm-cb" id="p_delete" value="1" style="width: 16px; height: 16px;"> Can Delete</label>
                    <label style="font-size: 13px; color: #334155; display: flex; align-items: center; gap: 6px; cursor: pointer;"><input type="checkbox" class="m-perm-cb" id="p_download" value="1" checked style="width: 16px; height: 16px;"> Can Download</label>
                    <label style="font-size: 13px; color: #334155; display: flex; align-items: center; gap: 6px; cursor: pointer;"><input type="checkbox" class="m-perm-cb" id="p_checkout" value="1" style="width: 16px; height: 16px;"> Can Lock/Unlock</label>
                </div>

                <button type="button" class="btn btn-secondary" style="width: 100%; font-weight: bold; padding: 10px; border-radius: 6px; font-size: 13px; cursor: pointer;" onclick="submitNewAccessAssignmentRow()">Grant Access</button>
            </div>
        </div> -->

    </div>
  </div>
</div>

<!-- JAVASCRIPT EVENT INTERFACE CONTROLLER MODULE -->
<script>
function openMasterEditModal(doc) {
    const modal = document.getElementById('masterEditModal');
    document.getElementById('m_title').textContent = "Edit: " + doc.filename;
    document.getElementById('m_doc_id').value = doc.id;
    
    // Attribution Tracking Assignment Box
    document.getElementById('m_attribution').innerHTML = ` <strong>Edited By:</strong> ${doc.uploader_name || 'System Root'} <small style="text-transform: uppercase; background: #e2e8f0; padding: 2px 6px; border-radius: 4px; font-weight: bold; color: #475569; font-size: 10px; margin-left: 4px;">${doc.uploader_role || 'ADMIN'}</small>`;

    // Execute Auto-Locking Signal Route Hook via version controller
    fetch('version_control.php?action=silent_lock&id=' + doc.id);

    // Render current active layout file graphic context preview window
    const oldPane = document.getElementById('p_old');
    const ext = doc.filename.split('.').pop().toLowerCase();
    if (['png','jpg','jpeg','gif','webp','svg'].includes(ext)) {
        oldPane.innerHTML = `<img src="uploads/${doc.storage_path}" style="max-width: 100%; max-height: 100%; object-fit: contain; border-radius: 4px;">`;
    } else {
        oldPane.innerHTML = `<div style="text-align: center;"><span style="font-size: 36px; display: block; margin-bottom: 4px;">📄</span><small style="color: #64748b; font-weight: bold;">${ext.toUpperCase()} Object Preview Restrained</small></div>`;
    }

    // Populate Version History logs checklist
    const historyList = document.getElementById('m_history_list');
    historyList.innerHTML = '<small style="color: #64748b; text-align: center; display: block; padding: 10px;">Loading logs history repository...</small>';
    
    fetch('version_control.php?action=get_history&id=' + doc.id)
    .then(res => res.json())
    .then(versions => {
        historyList.innerHTML = '';
        if (versions.length === 0) {
            historyList.innerHTML = '<small style="color: #94a3b8; text-align: center; font-style: italic; display: block; padding: 10px;">No history update recorded.</small>';
            return;
        }
        versions.forEach(v => {
            historyList.innerHTML += `
                <div style="display: flex; justify-content: space-between; align-items: center; background: #fff; border: 1px solid #e2e8f0; padding: 8px 12px; border-radius: 6px; font-size: 12px; gap: 10px;">
                    <div><strong>Checkpoint Version v${v.version_number}</strong><br><small style="color: #64748b; display: block; margin-top: 2px;">Updated at: ${v.created_at}</small></div>
                    <a href="version_control.php?action=rollback&doc_id=${doc.id}&version_id=${v.id}" class="btn btn-sm btn-warn" style="font-size: 11px; padding: 4px 8px; font-weight: bold; text-decoration: none; border-radius: 4px;" onclick="return confirm('Revert active file to this version state? The existing copy will be replaced.')">Rollback</a>
                </div>`;
        });
    }).catch(() => historyList.innerHTML = '<small style="color: #ef4444; text-align: center; display: block; padding: 10px;">Failed to fetch history logs.</small>');

    modal.classList.add('is-active');
    document.body.style.overflow = 'hidden';
}

function closeMasterEditModal() {
    const modal = document.getElementById('masterEditModal');
    const docId = document.getElementById('m_doc_id').value;
    
    // Release Auto-Lock parameters upon safe exit
    fetch('version_control.php?action=silent_unlock&id=' + docId);
    
    modal.classList.remove('is-active');
    document.body.style.overflow = '';
    document.getElementById('p_new').innerHTML = 'No update.';
    document.getElementById('m_file_input').value = '';
    document.getElementById('m_select_all_perms').checked = false;
    document.querySelectorAll('.m-perm-cb').forEach(cb => cb.checked = cb.id === 'p_download');
}

function renderCompareViewDelta(input) {
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
            pane.innerHTML = `<div style="text-align: center; font-size: 12px; padding: 10px;">📄 <strong style="display: block; word-break: break-all; margin-top: 4px;">${file.name}</strong><small style="color: #16a34a; font-weight: bold; display: block; margin-top: 4px;">Ready to Update</small></div>`;
        }
    }
}

function submitNewAccessAssignmentRow() {
    const userId = document.getElementById('m_collab_picker').value;
    const docId = document.getElementById('m_doc_id').value;
    if (!userId) { alert('Please select a target user first.'); return; }

    const payload = {
        document_id: docId,
        shared_with_user_id: userId,
        can_edit: document.getElementById('p_edit').checked ? 1 : 0,
        can_delete: document.getElementById('p_delete').checked ? 1 : 0,
        can_download: document.getElementById('p_download').checked ? 1 : 0,
        can_checkout: document.getElementById('p_checkout').checked ? 1 : 0
    };

    const dynamicPathPrefix = window.location.pathname.includes('/admin/') ? '../' : '';

    fetch(dynamicPathPrefix + 'share.php?action=grant_direct_access', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            alert('Access permissions successfully!');
            document.getElementById('m_collab_picker').value = '';
            document.getElementById('m_select_all_perms').checked = false;
            document.querySelectorAll('.m-perm-cb').forEach(cb => cb.checked = cb.id === 'p_download');
        } else { 
            alert('Access Error: ' + data.message); 
        }
    }).catch(() => alert('Error Updating.'));
}

window.addEventListener('click', function(e) {
    const modal = document.getElementById('masterEditModal');
    if (e.target === modal) { closeMasterEditModal(); }
});
</script>

<?php 
// ── Render Global Page Footer Configuration Layout ────────────────────────
include __DIR__ . '/partials/footer.php'; 
?>