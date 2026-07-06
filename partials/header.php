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
        display: flex;
        justify-content: center;
        align-items: center;
        padding: 20px;
        box-sizing: border-box;
        opacity: 0;
        visibility: hidden;
        transition: opacity 0.3s cubic-bezier(0.25, 0.8, 0.25, 1), visibility 0.3s;
    }

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
        transform: scale(0.92) translateY(10px);
        transition: transform 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
    }

    #logoManagementModal.is-active {
        opacity: 1;
        visibility: visible;
    }
    #logoManagementModal.is-active .modal-card {
        transform: scale(1) translateY(0);
    }

    /* ── LAYOUT ENGINE ── */
    .app-layout {
        display: flex;
        width: 100%;
        min-height: 100vh;
        position: relative;
    }

    /* Makinis na transition engine para sa sidebar width */
    .sidebar {
        width: 230px;
        background: #f8fafc; 
        transition: width 0.35s cubic-bezier(0.4, 0, 0.2, 1), padding 0.35s cubic-bezier(0.4, 0, 0.2, 1);
        display: flex;
        flex-direction: column;
        overflow: hidden;
        flex-shrink: 0;
    }

    /* Makinis na transition para sa pag-usad ng main content kapag sumasara/bumubukas ang sidebar */
    body .main-content {
        flex-grow: 1;
        min-width: 0; 
        transition: all 0.35s cubic-bezier(0.4, 0, 0.2, 1) !important;
    }

    /* ── COLLAPSED SIDEBAR CONSTRAINTS ── */
    
    /* Ginagawang ganap na 0px ang pisikal na sukat ng sidebar container nang hindi pinapatay ang opacity ng X button */
    .sidebar.collapsed,
    #dashboard-sidebar.collapsed {
        width: 0px !important;
        max-width: 0px !important;
        min-width: 0px !important;
        padding: 0 !important;
        margin: 0 !important;
        border: none !important;
    }

    /* Puwersahing sumagad sa kaliwa nang pantay at swabe ang content panel */
    body .sidebar.collapsed + .main-content,
    body #dashboard-sidebar.collapsed + .main-content {
        margin-left: 0px !important;
        padding-left: 0px !important;
        border-left: none !important;
    }

    /* Itago LAMANG ang mga menu items at texts. Hindi idinamay ang .sidebar-logo para laging buhay ang X button */
    #dashboard-sidebar.collapsed .sidebar-brand-text,
    #dashboard-sidebar.collapsed .sidebar-menu,
    #dashboard-sidebar.collapsed .sidebar-user-profile,
    #dashboard-sidebar.collapsed .sidebar-logout-btn,
    #dashboard-sidebar.collapsed .sidebar-brand-content a {
        display: none !important;
        opacity: 0 !important;
    }

    /* ── FLOATING TOGGLE TRIGGER CONTROL ── */
    
    /* Ang pagbabalik ng lumulutang na maliit na X button frame box */
    #dashboard-sidebar.collapsed .sidebar-logo,
    .sidebar.collapsed .sidebar-logo {
        position: fixed !important; 
        top: 15px !important;
        left: 0px !important;
        z-index: 9999 !important;
        
        display: flex !important;
        justify-content: center !important;
        align-items: center !important;
        
        /* Eksaktong square geometry proportion rates */
        padding: 0 !important;
        height: 42px !important;
        width: 42px !important; 
        
        background: #ffffff !important;
        box-shadow: 2px 4px 12px rgba(0, 0, 0, 0.1) !important;
        border-radius: 0px 8px 8px 0px !important;
        border: 1px solid #e2e8f0 !important;
        border-left: none !important;
        
        /* Makinis na custom element display projection */
        animation: toggleBtnFadeIn 0.35s cubic-bezier(0.4, 0, 0.2, 1) forwards;
    }

    /* Sinisigurong perpektong nakasentro ang menu/X icon core element */
    #dashboard-sidebar.collapsed #sidebar-toggle-btn {
        padding: 0 !important;
        margin: 0 !important;
        width: 100% !important;
        height: 100% !important;
        display: flex !important;
        justify-content: center !important;
        align-items: center !important;
    }

    @keyframes toggleBtnFadeIn {
        from { opacity: 0; transform: translateX(-15px); }
        to { opacity: 1; transform: translateX(0); }
    }
</style>
</head>
<body class="skin-<?= htmlspecialchars($role) ?>">

<div class="app-layout">
    <!-- Collapsible Sidebar Container Widget Element -->
    <aside id="dashboard-sidebar" class="sidebar">
        <!-- SIDEBAR LOGO: Fully interactive preview/management node linkage -->
        <div class="sidebar-logo" style="display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 15px; border-bottom: 1px solid #e2e8f0; width: 100%; box-sizing: border-box;">
            <div class="sidebar-brand-content" style="display: flex; align-items: center; gap: 10px;">
                <a href="#" onclick="openLogoManagementModal()" style="text-decoration: none; display: flex; align-items: center; shrink: 0;">
                    <img src="<?= (strpos($_SERVER['PHP_SELF'], '/admin/') !== false) ? '../' : ''; ?>filestac.png?v=<?= time(); ?>" style="height: 32px; width: auto;" alt="Logo">
                </a>
                <span class="sidebar-brand-text" style="font-weight: bold; color: #000000; font-size: 15px; letter-spacing: 0.5px; white-space: nowrap;">FILESTAC DMS</span>
            </div>

            <!-- Burger / Close Button on the right side of the text -->
            <button id="sidebar-toggle-btn" style="background: none; border: none; cursor: pointer; color: #64748b; padding: 4px; display: flex; align-items: center; outline: none;">
                <!-- Burger Icon (Visible by default) -->
                <svg id="burger-svg-icon" style="width: 20px; height: 20px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"></path></svg>
                <!-- Close X Icon (Hidden by default, turns active on state change) -->
                <svg id="close-svg-icon" style="width: 20px; height: 20px; display: none;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
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
        const pathPrefix = window.location.pathname.includes('/admin/') ? '../' : '';
        const timestamp = new Date().getTime();
        
        const modalPreviewImage = document.getElementById('modalLogoPreview');
        if (modalPreviewImage) {
            modalPreviewImage.src = pathPrefix + 'filestac.png?v=' + timestamp;
        }

        modal.classList.add('is-active');
        document.body.style.overflow = 'hidden';
    }
}

function closeLogoManagementModal() {
    const modal = document.getElementById('logoManagementModal');
    if (modal) {
        modal.classList.remove('is-active');
        document.body.style.overflow = ''; 
    }
}

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
</script>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const sidebar = document.getElementById('dashboard-sidebar');
    const toggleBtn = document.getElementById('sidebar-toggle-btn');
    const burgerIcon = document.getElementById('burger-svg-icon');
    const closeIcon = document.getElementById('close-svg-icon');

    if (toggleBtn && sidebar) {
        toggleBtn.addEventListener('click', (e) => {
            e.preventDefault();
            sidebar.classList.toggle('collapsed');

            // Swap the icon state indicators inside the modular floating header block
            if (sidebar.classList.contains('collapsed')) {
                burgerIcon.style.display = 'none';
                closeIcon.style.display = 'block';
            } else {
                closeIcon.style.display = 'none';
                burgerIcon.style.display = 'block';
            }
        });
    }
});
</script>

<!-- Main content wrapper framework node -->
<main class="main-content">