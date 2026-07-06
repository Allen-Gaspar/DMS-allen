<?php
/**
 * share.php — Document sharing management and dynamic API permissions endpoint.
 */
require_once __DIR__ . '/auth.php';
$user = require_role('contributor', 'admin');
$db   = get_db();

// ── 1. ADVANCED MODAL HEADLESS JSON API ENDPOINT CONTROLLER CHANNEL ───
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    // Live autocomplete file suggestion engine channel
    if ($_GET['action'] === 'search_files_suggest') {
        $query = trim($_GET['q'] ?? '');
        if ($query === '') {
            echo json_encode([]);
            exit;
        }
        
        $search_term = "%{$query}%";
        $suggest_stmt = $db->prepare("SELECT id, filename FROM documents WHERE filename LIKE ? AND is_deleted = 0 LIMIT 5");
        $suggest_stmt->bind_param('s', $search_term);
        $suggest_stmt->execute();
        $results = $suggest_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        echo json_encode($results);
        exit;
    }

    // Dynamic API Authorization matrix controller
    if ($_GET['action'] === 'grant_direct_access') {
        $raw_json = file_get_contents('php://input');
        $data = json_decode($raw_json, true);

        if (!$data) {
            echo json_encode(['success' => false, 'message' => 'No valid data package parameters received.']);
            exit;
        }

        $doc_id       = (int)($data['document_id'] ?? 0);
        $target_uid   = (int)($data['shared_with_user_id'] ?? 0);
        $can_edit     = (int)($data['can_edit'] ?? 0);
        $can_delete   = (int)($data['can_delete'] ?? 0);
        $can_download = (int)($data['can_download'] ?? 0);
        $can_checkout = (int)($data['can_checkout'] ?? 0);

        if ($doc_id <= 0 || $target_uid <= 0) {
            echo json_encode(['success' => false, 'message' => 'Both Document ID and Target User elements are strictly mandatory.']);
            exit;
        }

        $check_stmt = $db->prepare("SELECT id FROM document_shares WHERE document_id = ? AND shared_with_user_id = ? LIMIT 1");
        $check_stmt->bind_param('ii', $doc_id, $target_uid);
        $check_stmt->execute();
        $existing_record = $check_stmt->get_result()->fetch_assoc();

        if ($existing_record) {
            $up_stmt = $db->prepare("UPDATE document_shares SET can_edit = ?, can_delete = ?, can_download = ?, can_checkout = ? WHERE id = ?");
            $up_stmt->bind_param('iiiii', $can_edit, $can_delete, $can_download, $can_checkout, $existing_record['id']);
            $executed = $up_stmt->execute();
        } else {
            $ins_stmt = $db->prepare("INSERT INTO document_shares (document_id, shared_with_user_id, shared_by, can_edit, can_delete, can_download, can_checkout) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $ins_stmt->bind_param('iiiiiii', $doc_id, $target_uid, $user['id'], $can_edit, $can_delete, $can_download, $can_checkout);
            $executed = $ins_stmt->execute();
        }

        if ($executed) {
            if (function_exists('audit_log')) {
                audit_log($user['id'], 'PERMISSION_CHANGE', "Updated access authorization matrix for User ID {$target_uid} on Document ID {$doc_id}");
            }
            echo json_encode(['success' => true, 'message' => 'Access permissions matrix mapped successfully!']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Database execution error processing parameters matrix: ' . $db->error]);
        }
        exit;
    }
}

// ── 2. TRADITIONAL STANDALONE WEB LAYOUT FALLBACK PROCESSING ENGINE ────
$doc_id = (int)($_GET['id'] ?? 0);
if ($doc_id <= 0) {
    header('Location: documents.php');
    exit;
}

$stmt = $db->prepare('SELECT * FROM documents WHERE id=? AND is_deleted=0 LIMIT 1');
$stmt->bind_param('i', $doc_id);
$stmt->execute();
$doc = $stmt->get_result()->fetch_assoc();
if (!$doc) {
    header('Location: documents.php?err=' . urlencode('Document not found.'));
    exit;
}

$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action    = $_POST['action'] ?? '';
    $share_uid = (int)($_POST['share_uid'] ?? 0);

    if ($action === 'add' && $share_uid > 0) {
        $share_uid_safe = (int)$share_uid;
        $doc_id_safe    = (int)$doc_id;

        $user_chk = $db->query("SELECT id FROM users WHERE id = {$share_uid_safe} LIMIT 1");
        
        if (!$user_chk || $user_chk->num_rows === 0) {
            $error = 'User not found in system.';
        } else {
            // Manual uniqueness verification mapping check
            $dup_chk = $db->query("SELECT id FROM document_shares WHERE document_id = {$doc_id_safe} AND shared_with_user_id = {$share_uid_safe} LIMIT 1");
            
            if ($dup_chk && $dup_chk->num_rows > 0) {
                $error = 'Already shared with that user.';
            } else {
                // REMOVED 'shared_by' completely to align with your database table columns setup
                $insert_query = $db->query("INSERT INTO document_shares 
                    (document_id, shared_with_user_id) 
                    VALUES 
                    ({$doc_id_safe}, {$share_uid_safe})");
                
                if ($insert_query) {
                    if (function_exists('audit_log')) {
                        audit_log($user['id'], 'SHARE', "Shared '{$doc['filename']}' with user #$share_uid");
                    }
                    $success = 'Document shared successfully.';
                } else {
                    $error = 'Database mapping execution error: ' . htmlspecialchars($db->error);
                }
            }
        }
    } elseif ($action === 'remove' && $share_uid > 0) {
        $share_uid_safe = (int)$share_uid;
        $doc_id_safe    = (int)$doc_id;

        $remove_query = $db->query("DELETE FROM document_shares WHERE document_id = {$doc_id_safe} AND shared_with_user_id = {$share_uid_safe}");
        
        if ($remove_query) {
            if (function_exists('audit_log')) {
                audit_log($user['id'], 'UNSHARE', "Removed share of '{$doc['filename']}' from user #$share_uid");
            }
            $success = 'Share removed.';
        } else {
            $error = 'Failed to drop share record entry.';
        }
    }
}



// ── 3. FETCH SHARED COLLABORATORS REGISTRY RECORDS (Bypasses bind_param crashes) ──
$doc_id_safe = (int)$doc_id;
$shared_users = [];

$shared_query = $db->query("SELECT ds.shared_with_user_id AS id, u.username 
                            FROM document_shares ds 
                            LEFT JOIN users u ON ds.shared_with_user_id = u.id 
                            WHERE ds.document_id = {$doc_id_safe}");

if ($shared_query) {
    $shared_users = $shared_query->fetch_all(MYSQLI_ASSOC);
} else {
    $shared_query_fallback = $db->query("SELECT shared_with_user_id AS id FROM document_shares WHERE document_id = {$doc_id_safe}");
    if ($shared_query_fallback) {
        $raw_rows = $shared_query_fallback->fetch_all(MYSQLI_ASSOC);
        foreach ($raw_rows as $row) {
            $shared_users[] = ['id' => $row['id'], 'username' => 'User ID #' . $row['id']];
        }
    }
}
$shared_ids = array_column($shared_users, 'id');

// ── 4. FETCH AVAILABLE SYSTEM USERS FOR THE SELECTION MENU (Safe Query) ──
$user_id_safe = (int)$user['id'];
$available = [];
$avail_query = $db->query("SELECT id, username FROM users WHERE id != {$user_id_safe} ORDER BY username ASC");

if ($avail_query) {
    $available = $avail_query->fetch_all(MYSQLI_ASSOC);
} else {
    $avail_query_fallback = $db->query("SELECT id, username FROM users ORDER BY username ASC");
    if ($avail_query_fallback) {
        $available = $avail_query_fallback->fetch_all(MYSQLI_ASSOC);
    }
}

$page_title = 'Share Document';
include __DIR__ . '/partials/header.php';
?>

<style>
  .search-container { display: flex; gap: 15px; margin-bottom: 25px; flex-wrap: wrap; background: #f8fafc; padding: 15px; border-radius: 6px; border: 1px solid #e2e8f0; }
  .search-box { position: relative; flex: 1; min-width: 260px; }
  .search-box label { display: block; margin-bottom: 5px; font-weight: bold; font-size: 11px; color: #475569; text-transform: uppercase; letter-spacing: 0.5px; }
  .search-box input { width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 4px; box-sizing: border-box; font-size: 14px; background: #fff; color: #333; }
  .suggestion-dropdown { position: absolute; top: 100%; left: 0; right: 0; background: #fff; border: 1px solid #cbd5e1; border-top: none; border-radius: 0 0 4px 4px; max-height: 200px; overflow-y: auto; z-index: 2000; display: none; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
  .suggestion-item { padding: 11px; cursor: pointer; border-bottom: 1px solid #f1f5f9; color: #1e293b; font-size: 13px; font-weight: 500; }
  .suggestion-item:last-child { border-bottom: none; }
  .suggestion-item:hover { background-color: #f0f4f8; color: #0056b3; }
</style>

<h2 class="page-title">Share: <?= htmlspecialchars($doc['filename']) ?></h2>

<div class="search-container">
  <div class="search-box">
    <label for="folderSearchInput">Folder Content</label>
    <input type="text" id="folderSearchInput" placeholder="Type to filter folders..." autocomplete="off">
  </div>
  <div class="search-box">
    <label for="fileSearchInput">Search File Inside Folder</label>
    <input type="text" id="fileSearchInput" placeholder="Type to filter files inside folder..." autocomplete="off">
    <div id="suggestionDropdown" class="suggestion-dropdown"></div>
  </div>
</div>

<p><a href="documents.php" class="btn btn-outline">&larr; Back to Documents</a></p>

<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="two-col">
  <div class="card folder-card">
    <h3>Add Share</h3>
    <form method="POST">
      <input type="hidden" name="action" value="add">
      <select name="share_uid" required>
        <option value="">— Select workspace user —</option>
        <?php foreach ($available as $u): ?>
          <?php if (!in_array($u['id'], $shared_ids)): ?>
            <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['username']) ?></option>
          <?php endif; ?>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-primary">Share</button>
    </form>
  </div>

  <div class="card folder-card">
    <h3>Currently Shared With</h3>
    <?php if (empty($shared_users)): ?>
      <p>Not shared with anyone yet.</p>
    <?php else: ?>
      <ul class="share-list">
        <?php foreach ($shared_users as $su): ?>
          <li>
            <?= htmlspecialchars($su['username']) ?>
            <form method="POST" style="display:inline">
              <input type="hidden" name="action"    value="remove">
              <input type="hidden" name="share_uid" value="<?= $su['id'] ?>">
              <button class="btn btn-sm btn-danger">Remove</button>
            </form>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", () => {
    const folderInput = document.getElementById("folderSearchInput");
    const fileInput = document.getElementById("fileSearchInput");
    const dropdown = document.getElementById("suggestionDropdown");
    const folderCards = document.querySelectorAll(".folder-card");

    if (folderInput) {
        folderInput.addEventListener("input", (e) => {
            const value = e.target.value.toLowerCase().trim();
            folderCards.forEach(card => {
                const headingText = card.querySelector("h3") ? card.querySelector("h3").textContent.toLowerCase() : "";
                if (headingText.includes(value) || card.textContent.toLowerCase().includes(value)) {
                    card.style.display = "";
                } else {
                    card.style.display = "none";
                }
            });
        });
    }

    let debounceTimer;
    if (fileInput && dropdown) {
        fileInput.addEventListener("input", (e) => {
            clearTimeout(debounceTimer);
            const query = e.target.value.trim();

            if (query.length < 2) {
                dropdown.style.display = "none";
                dropdown.innerHTML = "";
                return;
            }

            debounceTimer = setTimeout(() => {
                fetch(`share.php?action=search_files_suggest&q=${encodeURIComponent(query)}`)
                    .then(response => response.json())
                    .then(data => {
                        dropdown.innerHTML = "";
                        if (data.length === 0) {
                            dropdown.style.display = "none";
                            return;
                        }
                        data.forEach(item => {
                            const div = document.createElement("div");
                            div.className = "suggestion-item";
                            div.textContent = item.filename;
                            div.addEventListener("click", () => {
                                window.location.href = `share.php?id=${item.id}`;
                            });
                            dropdown.appendChild(div);
                        });
                        dropdown.style.display = "block";
                    })
                    .catch(err => console.error("Error communicating with data endpoints:", err));
            }, 250);
        });

        document.addEventListener("click", (e) => {
            if (e.target !== fileInput && e.target !== dropdown) {
                dropdown.style.display = "none";
            }
        });
    }
});
</script>
