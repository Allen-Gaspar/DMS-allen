<?php
if (!isset($user)) $user = current_user();
$role = $user['role'] ?? 'casual';

function app_url($path = '') {
    // Ensure the base path starts with a leading slash '/'
    return '/DMS-allen/DMS-allen/' . $path;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($page_title ?? 'FILESTAC DMS') ?> — FILESTAC DMS</title>
<link rel="stylesheet" href="/DMS-allen/DMS-allen/style.css">

<style>
    /* Backdrop transition configuration */
    #logoManagementModal {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.6);
        z-index: 10000;
        display: flex; /* Kept as flex for perfect alignment matrix */
        justify-content: center;
        align-items: center;
        padding: 20px;
        box-sizing: border-box;
        
        /* Hidden state by default */
        opacity: 0;
        visibility: hidden;
        transition: opacity 0.3s cubic-bezier(0.25, 0.8, 0.25, 1), visibility 0.3s;
    }

    /* Inner card modal zoom transition scaling */
    #logoManagementModal .modal-card {
        background: #ffffff;
        padding: 35px;
        border-radius: 12px;
        width: 100%;
        max-width: 480px;
        box-shadow: 0 12px 30px rgba(0,0,0,0.25);
        position: relative;
        text-align: center;
        box-sizing: border-box;
        
        /* Start slightly scaled down and dropped down */
        transform: scale(0.92) translateY(10px);
        transition: transform 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
    }

    /* Active State Trigger Rule Sets */
    #logoManagementModal.is-active {
        opacity: 1;
        visibility: visible;
    }
    #logoManagementModal.is-active .modal-card {
        transform: scale(1) translateY(0);
    }
</style>
</head>
<body class="skin-<?= htmlspecialchars($role) ?>">

<div class="app-layout">
  <!-- Sidebar navigation -->
  <aside class="sidebar">
    <!-- SIDEBAR LOGO: Fully interactive preview/management node linkage -->
    <!-- FIXED SIDEBAR PATH: Automatically adjusts folder paths dynamically -->
    <div class="sidebar-logo" style="display: flex; align-items: center; gap: 10px; padding: 15px; border-bottom: 1px solid rgba(255,255,255,0.1); font-family: sans-serif;">
        <a href="#" onclick="openLogoManagementModal()" style="text-decoration: none; display: flex; align-items: center;" title="Manage Workspace Logo">
            <img src="<?= (strpos($_SERVER['PHP_SELF'], '/admin/') !== false) ? '../' : ''; ?>filestac.png?v=<?= time(); ?>" alt="Logo" id="sidebarLogoAsset" style="width: 30px; height: 30px; object-fit: contain; display: block; border-radius: 4px; margin-top: 10px;">
        </a>
        <span class="sidebar-brand" style="font-weight: bold; color: #000000; font-size: 15px; letter-spacing: 0.5px; margin-top: 10px;">FILESTAC DMS</span>
    </div>

    <nav class="sidebar-nav"> 
      <!-- FIX: Allows 'Dashboard' to stay highlighted on both the central router and the admin-specific home page -->
<?php 
    $current_file = basename($_SERVER['PHP_SELF']);
    $is_dashboard_active = ($current_file === 'dashboard.php' || $current_file === 'admin_dashboard.php');
?>
<a href="<?= htmlspecialchars(app_url('dashboard.php')) ?>" class="nav-item <?= $is_dashboard_active ? 'active' : '' ?>">
    Dashboard
</a>

      <a href="<?= htmlspecialchars(app_url('documents.php')) ?>" class="nav-item <?= basename($_SERVER['PHP_SELF']) === 'documents.php' ? 'active' : '' ?>">
    Documents
</a>

<a href="<?= htmlspecialchars(app_url('folders.php')) ?>" class="nav-item <?= basename($_SERVER['PHP_SELF']) === 'folders.php' ? 'active' : '' ?>" style="display: flex; align-items: center; gap: 8px;">
  Folders
</a>

      <?php if ($role === 'admin'): ?>
        <a href="<?= htmlspecialchars(app_url('users.php')) ?>" class="nav-item <?= basename($_SERVER['PHP_SELF']) === 'users.php' ? 'active' : '' ?>">
          Users
        </a>
        <a href="<?= htmlspecialchars(app_url('trash.php')) ?>" class="nav-item <?= basename($_SERVER['PHP_SELF']) === 'trash.php' ? 'active' : '' ?>">
          Trash
        </a>
        <a href="<?= htmlspecialchars(app_url('audit.php')) ?>" class="nav-item <?= basename($_SERVER['PHP_SELF']) === 'audit.php' ? 'active' : '' ?>">
          Audit Log
        </a>

        <a href="<?= htmlspecialchars(app_url('admin/admin_approvals.php')) ?>" class="nav-item <?= basename($_SERVER['PHP_SELF']) === 'admin_approvals.php' ? 'active' : '' ?>">
          Admin Approvals
        </a>


      <?php endif; ?>

      <?php if ($role === 'contributor'): ?>
        <a href="<?= htmlspecialchars(app_url('upload.php')) ?>" class="nav-item <?= basename($_SERVER['PHP_SELF']) === 'upload.php' ? 'active' : '' ?>">
          Upload
        </a>
      <?php endif; ?>
    </nav>

    <div class="sidebar-footer">
      <div class="user-pill">
        <span class="user-role-dot"></span>
        <div>
          <div class="user-name"><?= htmlspecialchars($user['username']) ?></div>
          <div class="user-role"><?= ucfirst($role) ?></div>
        </div>
      </div>
      <a href="<?= htmlspecialchars(app_url('logout.php')) ?>" class="btn btn-outline btn-sm btn-full">Logout</a>
    </div>
  </aside>

<div id="logoManagementModal">
    <div class="modal-card">
        
        <span onclick="closeLogoManagementModal()" style="position: absolute; top: 12px; right: 18px; cursor: pointer; font-size: 24px; font-weight: bold; color: #aaa; transition: color 0.2s;" onmouseover="this.style.color='#333'" onmouseout="this.style.color='#aaa'">&times;</span>
        
        <h3 style="margin: 0 0 5px 0; color: #333; font-size: 18px; font-family: sans-serif;">Workspace Customization</h3>
        <p style="margin: 0 0 25px 0; color: #666; font-size: 13px; font-family: sans-serif;">Delete or update your platform logo asset</p>
        
        <!-- Preview Canvas -->
        <div style="width: 240px; height: 240px; margin: 0 auto 25px auto; border: 1px dashed #cbd5e1; border-radius: 12px; display: flex; align-items: center; justify-content: center; background: #f8fafc; padding: 15px; box-sizing: border-box; box-shadow: inset 0 2px 4px rgba(0,0,0,0.02);">
            <img src="../filestac.png?v=<?= time(); ?>" id="modalLogoPreview" alt="Current Logo" style="max-width: 100%; max-height: 100%; object-fit: contain; width: auto; height: auto; display: block;">
        </div>

        <!-- Management Actions Layout Grid -->
        <div style="display: flex; flex-direction: column; gap: 10px;">
            
            <form id="logoUploadForm" enctype="multipart/form-data" style="margin: 0;">
                <label style="display: block; background: #2e7d32; color: #fff; padding: 12px; border-radius: 6px; font-weight: bold; font-size: 13px; cursor: pointer; text-align: center; transition: background 0.2s; font-family: sans-serif;" onmouseover="this.style.background='#235f26'" onmouseout="this.style.background='#2e7d32'">
                    Choose New Logo Image
                    <input type="file" name="new_logo" id="newLogoInput" accept="image/png, image/jpeg, image/jpg" style="display: none;" onchange="submitLogoUpdate()">
                </label>
            </form>

            <button type="button" onclick="executeLogoDeletion()" style="width: 100%; background: #c62828; color: #fff; border: none; padding: 12px; border-radius: 6px; font-weight: bold; font-size: 13px; cursor: pointer; transition: background 0.2s; font-family: sans-serif;" onmouseover="this.style.background='#9a1f1f'" onmouseout="this.style.background='#c62828'">
                Remove Current Logo
            </button>
        </div>
    </div>
</div>

<script>
function openLogoManagementModal() {
    const modal = document.getElementById('logoManagementModal');
    if (modal) {
        // 1. Detect if the script is running inside the /admin/ folder layer
        const pathPrefix = window.location.pathname.includes('/admin/') ? '../' : '';
        const timestamp = new Date().getTime();
        
        // 2. Force update the modal image preview path dynamically right before displaying it
        const modalPreviewImage = document.getElementById('modalLogoPreview');
        if (modalPreviewImage) {
            modalPreviewImage.src = pathPrefix + 'filestac.png?v=' + timestamp;
        }

        // 3. Trigger modal visibility class layers smoothly
        modal.classList.add('is-active');
        document.body.style.overflow = 'hidden';
    }
}


function closeLogoManagementModal() {
    const modal = document.getElementById('logoManagementModal');
    if (modal) {
        modal.classList.remove('is-active');
        document.body.style.overflow = ''; // Unlock dashboard scroll
    }
}

// Outside Click Closer Engine Rule Set
window.addEventListener('click', function(event) {
    const modal = document.getElementById('logoManagementModal');
    if (modal && event.target === modal) {
        closeLogoManagementModal();
    }
});


function submitLogoUpdate() {
    const fileInput = document.getElementById('newLogoInput');
    if (!fileInput.files.length) return;

    const formData = new FormData();
    formData.append('action', 'update');
    formData.append('new_logo', fileInput.files[0]);

    // FIXED: Added ../ to target the root directory file
    fetch('../upload_logo.php', { method: 'POST', body: formData })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            const timestamp = new Date().getTime();
            document.getElementById('sidebarLogoAsset').src = '../filestac.png?v=' + timestamp;
            document.getElementById('modalLogoPreview').src = '../filestac.png?v=' + timestamp;
            alert(data.message);
        } else {
            alert('Upload Error: ' + data.message);
        }
    }).catch(() => alert('Network error handling asset adjustment.'));
}


function executeLogoDeletion() {
    if (!confirm('Are you absolutely certain you want to remove the current logo? This will fallback to a default file icon.')) return;

    const formData = new FormData();
    formData.append('action', 'delete');

    // FIXED: Added ../ to target the root directory file
    fetch('../upload_logo.php', { method: 'POST', body: formData })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            const timestamp = new Date().getTime();
            document.getElementById('sidebarLogoAsset').src = '../filestac.png?v=' + timestamp;
            document.getElementById('modalLogoPreview').src = '../filestac.png?v=' + timestamp;
            alert(data.message);
        } else {
            alert('Error executing asset truncation: ' + data.message);
        }
    }).catch(() => alert('Network connectivity failure.'));
}




// Close the Logo Management Modal when clicking anywhere outside the white card container
window.addEventListener('click', function(event) {
    const managementModal = document.getElementById('logoManagementModal');
    
    // Check if the modal is currently open and the click happened directly on the dark backdrop
    if (managementModal && event.target === managementModal) {
        closeLogoManagementModal();
    }
});


</script>


  <!-- Main content -->
  <main class="main-content">
