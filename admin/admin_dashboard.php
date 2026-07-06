<?php
/**
 * admin_dashboard.php — Clean Admin Analytics & Interactive Time-Series Control Center
 */

// ── 1. OS-AGNOSTIC ROUTING & ACCESS SAFEGUARDS ─────────────────────────
$base_dir = dirname(__DIR__); 
require_once $base_dir . DIRECTORY_SEPARATOR . 'auth.php';
$user = require_login();
$db   = get_db();

if ($user['role'] !== 'admin') {
    header('Location: ../documents.php?err=' . urlencode('Unauthorized dashboard access attempt.'));
    exit;
}

date_default_timezone_set('Asia/Manila');

function format_dashboard_bytes(int $bytes): string {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576)    return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024)       return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

// ── 2. HIGH-PERFORMANCE DATABASE AGGREGATIONS ─────────────────────────
$active_files_count = 0;
$locked_files_count  = 0;
$trash_files_count   = 0;
$total_storage_used  = 0;

$file_stats_query = $db->query("
    SELECT 
        COUNT(CASE WHEN is_deleted = 0 THEN 1 END) as active_files,
        COUNT(CASE WHEN is_locked = 1 AND is_deleted = 0 THEN 1 END) as locked_files,
        COUNT(CASE WHEN is_deleted = 1 THEN 1 END) as trash_files,
        SUM(CASE WHEN is_deleted = 0 THEN size ELSE 0 END) as total_size
    FROM documents
");

if ($file_stats_query !== false) {
    $file_stats = $file_stats_query->fetch_assoc();
    $active_files_count = (int)($file_stats['active_files'] ?? 0);
    $locked_files_count = (int)($file_stats['locked_files'] ?? 0);
    $trash_files_count  = (int)($file_stats['trash_files'] ?? 0);
    $total_storage_used = (int)($file_stats['total_size'] ?? 0);
}

// Native SQL Extension Splitting
$extensions_breakdown = [];
$extension_query = $db->query("
    SELECT LOWER(SUBSTRING_INDEX(filename, '.', -1)) AS ext, COUNT(*) AS count 
    FROM documents 
    WHERE is_deleted = 0 AND filename LIKE '%.%'
    GROUP BY ext ORDER BY count DESC
");
if ($extension_query !== false) {
    while ($row = $extension_query->fetch_assoc()) {
        $extensions_breakdown[$row['ext']] = (int)$row['count'];
    }
}

// ── 3. DATA TIMELINE BUILDER PARSING (DYNAMIC ANCHOR TIME MATCHING) ──
$log_table = null;
$table_check = $db->query("SHOW TABLES LIKE '%log%'");
if ($table_check && $table_check->num_rows > 0) {
    while($t_row = $table_check->fetch_row()) {
        $tableName = $t_row[0];
        if (strpos($tableName, 'audit') !== false || strpos($tableName, 'activity') !== false || $tableName === 'logs') {
            $log_table = $tableName;
            break;
        }
    }
}

// 1. Dynamic Anchor Point: Find the latest document upload to synchronize chart views
$anchor_date_str = date('Y-m-d'); 
$anchor_query = $db->query("SELECT MAX(created_at) as latest_entry FROM documents");
if ($anchor_query && $row = $anchor_query->fetch_assoc()) {
    if (!empty($row['latest_entry'])) {
        $anchor_date_str = date('Y-m-d', strtotime($row['latest_entry']));
    }
}
$anchor_time = strtotime($anchor_date_str);

$raw_logs = [];
if ($log_table) {
    $timeline_query = $db->query("SELECT created_at, action FROM $log_table WHERE created_at >= DATE_SUB('$anchor_date_str', INTERVAL 1 YEAR)");
    if ($timeline_query) {
        while ($row = $timeline_query->fetch_assoc()) {
            $raw_logs[] = $row;
        }
    }
} else {
    // Select documents matching the relative 2026 cluster range seamlessly
    $doc_history = $db->query("SELECT created_at, 'upload' as action FROM documents WHERE created_at >= DATE_SUB('$anchor_date_str', INTERVAL 1 YEAR)");
    if ($doc_history) {
        while ($row = $doc_history->fetch_assoc()) {
            $raw_logs[] = $row;
        }
    }
}

$chart_series = [
    'daily'   => ['labels' => [], 'adds' => [], 'edits' => [], 'deletes' => [], 'checkouts' => [], 'shares' => []],
    'weekly'  => ['labels' => [], 'adds' => [], 'edits' => [], 'deletes' => [], 'checkouts' => [], 'shares' => []],
    'monthly' => ['labels' => [], 'adds' => [], 'edits' => [], 'deletes' => [], 'checkouts' => [], 'shares' => []],
    'yearly'  => ['labels' => [], 'adds' => [], 'edits' => [], 'deletes' => [], 'checkouts' => [], 'shares' => []],
];

// Generate structural calendar frames backwards from the active record cluster anchor
for ($i = 6; $i >= 0; $i--) {
    $target = strtotime("-$i days", $anchor_time);
    $d = date('Y-m-d', $target);
    $lbl = date('m/d', $target);
    $chart_series['daily']['labels'][$d] = $lbl;
    $chart_series['daily']['adds'][$d] = $chart_series['daily']['edits'][$d] = $chart_series['daily']['deletes'][$d] = $chart_series['daily']['checkouts'][$d] = $chart_series['daily']['shares'][$d] = 0;
}
for ($i = 4; $i >= 0; $i--) {
    $target = strtotime("-$i weeks", $anchor_time);
    $w = date('o-W', $target);
    $lbl = "Wk " . date('W', $target);
    $chart_series['weekly']['labels'][$w] = $lbl;
    $chart_series['weekly']['adds'][$w] = $chart_series['weekly']['edits'][$w] = $chart_series['weekly']['deletes'][$w] = $chart_series['weekly']['checkouts'][$w] = $chart_series['weekly']['shares'][$w] = 0;
}
for ($i = 5; $i >= 0; $i--) {
    $target = strtotime("-$i months", $anchor_time);
    $m = date('Y-m', $target);
    $lbl = date('M', $target);
    $chart_series['monthly']['labels'][$m] = $lbl;
    $chart_series['monthly']['adds'][$m] = $chart_series['monthly']['edits'][$m] = $chart_series['monthly']['deletes'][$m] = $chart_series['monthly']['checkouts'][$m] = $chart_series['monthly']['shares'][$m] = 0;
}
for ($i = 2; $i >= 0; $i--) {
    $target = strtotime("-$i years", $anchor_time);
    $y = date('Y', $target);
    $chart_series['yearly']['labels'][$y] = $y;
    $chart_series['yearly']['adds'][$y] = $chart_series['yearly']['edits'][$y] = $chart_series['yearly']['deletes'][$y] = $chart_series['yearly']['checkouts'][$y] = $chart_series['yearly']['shares'][$y] = 0;
}

// Map real records dynamically
foreach ($raw_logs as $log) {
    $ts = strtotime($log['created_at']);
    $act = strtolower($log['action']);
    
    $dKey = date('Y-m-d', $ts);
    $wKey = date('o-W', $ts);
    $mKey = date('Y-m', $ts);
    $yKey = date('Y', $ts);

    // Default catch-all changed to 'adds' to handle 'upload' records correctly
    $type = 'adds'; 
    
    if (preg_match('/(edit|update|modify|write)/', $act)) {
        $type = 'edits';
    } elseif (preg_match('/(delete|remove|trash|destroy)/', $act)) {
        $type = 'deletes';
    } elseif (preg_match('/(checkout|lock|hold)/', $act)) {
        $type = 'checkouts';
    } elseif (preg_match('/(share|permission|grant|public)/', $act)) {
        $type = 'shares';
    } elseif (preg_match('/(upload|add|insert|create)/', $act)) {
        $type = 'adds';
    }

    if (isset($chart_series['daily']['adds'][$dKey]))      $chart_series['daily'][$type][$dKey]++;
    if (isset($chart_series['weekly']['adds'][$wKey]))     $chart_series['weekly'][$type][$wKey]++;
    if (isset($chart_series['monthly']['adds'][$mKey]))    $chart_series['monthly'][$type][$mKey]++;
    if (isset($chart_series['yearly']['adds'][$yKey]))     $chart_series['yearly'][$type][$yKey]++;
}

// Flatten arrays out into indexed elements for JavaScript reading
foreach(['daily','weekly','monthly','yearly'] as $v) {
    $chart_series[$v]['labels']    = array_values($chart_series[$v]['labels']);
    $chart_series[$v]['adds']      = array_values($chart_series[$v]['adds']);
    $chart_series[$v]['edits']     = array_values($chart_series[$v]['edits']);
    $chart_series[$v]['deletes']   = array_values($chart_series[$v]['deletes']);
    $chart_series[$v]['checkouts'] = array_values($chart_series[$v]['checkouts']);
    $chart_series[$v]['shares']    = array_values($chart_series[$v]['shares']);
}

$page_title = 'Admin Analytics Center';
$header_path = $base_dir . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'header.php';
if (!file_exists($header_path)) { $header_path = $base_dir . DIRECTORY_SEPARATOR . 'header.php'; }
include $header_path;
?>

<!-- Clean Custom CSS to override margin/padding constraints seamlessly -->
<style>
    /* Standardized fluid padding setup upang maging kamukha ng sa documents page */
    .dashboard-wrapper { 
        padding: 2rem; 
        background-color: #f8fafc; 
        font-family: system-ui, -apple-system, sans-serif; 
    }
    
    .top-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0; padding-bottom: 1.25rem; margin-bottom: 2rem; }
    .grid-layout-2 { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 1.5rem; margin-bottom: 1.5rem; }
    .grid-layout-4 { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.5rem; margin-bottom: 2rem; }
    .grid-charts { display: grid; grid-template-columns: 1fr 2fr; gap: 1.5rem; }
    @media(max-width: 1024px) { .grid-charts { grid-template-columns: 1fr; } }
    
    /* Padding Fix — Added breathing space so strings are never katabi to borders */
    .dashboard-card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 0.75rem; padding: 1.75rem !important; box-shadow: 0 1px 3px rgba(0,0,0,0.02); }
    .action-card-button { width: 100%; display: flex; align-items: center; justify-content: space-between; padding: 1.5rem !important; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 0.75rem; cursor: pointer; text-align: left; transition: all 0.2s ease; }
    .action-card-button:hover { border-color: #2563eb; box-shadow: 0 4px 12px rgba(37,99,235,0.06); transform: translateY(-1px); }
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<div class="dashboard-wrapper">
    
    <!-- DASHBOARD HEADER BAR -->
    <div class="top-header">
        <div>
            <h1 style="font-size: 1.5rem; font-weight: 900; color: #0f172a; letter-spacing: -0.025em; display: flex; align-items: center; gap: 0.5rem;">
                Admin Dashboard
            </h1>
        </div>
        <div style="font-size: 0.75rem; font-weight: 700; color: #475569; background: #fff; border: 1px solid #e2e8f0; padding: 0.5rem 1rem; border-radius: 0.75rem; display: flex; align-items: center; gap: 0.5rem;">
            <span style="width: 8px; height: 8px; background: #10b981; border-radius: 50%;"></span>
            Server Time: <span id="live-server-clock"><?= date('h:i A') ?></span>
        </div>
    </div>

    <!-- QUICK ACTION LINKS -->
    <div class="grid-layout-2">
        <button onclick="toggleModal('quickUploadModal', true)" class="action-card-button">
            <div style="display: flex; align-items: center; gap: 1rem;">
                <div style="padding: 0.75rem; background: #eff6ff; color: #2563eb; border-radius: 0.75rem;">
                    <svg style="width: 1.25rem; height: 1.25rem;" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                </div>
                <div>
                    <h3 style="font-weight: 800; color: #1e293b; font-size: 1rem;">Upload New Document</h3>
                    <p style="font-size: 0.75rem; color: #94a3b8; margin-top: 0.125rem;">Upload and choose folder path.</p>
                </div>
            </div>
            <span style="color: #cbd5e1; font-weight: 900; font-size: 1.25rem;">&rarr;</span>
        </button>

        <button onclick="toggleModal('pendingRequestsModal', true)" class="action-card-button">
            <div style="display: flex; align-items: center; gap: 1rem;">
                <div style="padding: 0.75rem; background: #fffbeb; color: #d97706; border-radius: 0.75rem;">
                    <svg style="width: 1.25rem; height: 1.25rem;" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"/></svg>
                </div>
                <div>
                    <h3 style="font-weight: 800; color: #1e293b; font-size: 1rem;">Pending Review Requests</h3>
                    <p style="font-size: 0.75rem; color: #94a3b8; margin-top: 0.125rem;">Manage pending account request.</p>
                </div>
            </div>
            <span style="color: #cbd5e1; font-weight: 900; font-size: 1.25rem;">&rarr;</span>
        </button>
    </div>

    <!-- METRIC BLOCK LAYOUT CARDS -->
    <div class="grid-layout-4">
        <div class="dashboard-card">
            <span style="font-size: 0.75rem; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em; display: block;">Active System Files</span>
            <div style="display: flex; align-items: baseline; gap: 0.5rem; margin-top: 0.5rem;">
                <span style="font-size: 2rem; font-weight: 900; color: #0f172a;"><?= $active_files_count ?></span>
                <span style="font-size: 0.75rem; font-weight: 600; color: #94a3b8;">active items</span>
            </div>
        </div>
        <div class="dashboard-card">
            <span style="font-size: 0.75rem; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em; display: block;">Locked (Checked-Out)</span>
            <div style="display: flex; align-items: baseline; gap: 0.5rem; margin-top: 0.5rem;">
                <span style="font-size: 2rem; font-weight: 900; color: #d97706;"><?= $locked_files_count ?></span>
                <span style="font-size: 0.75rem; font-weight: 600; color: #b45309; background: #fffbeb; padding: 0.125rem 0.5rem; border-radius: 0.375rem;">held locks</span>
            </div>
        </div>
        <div class="dashboard-card">
            <span style="font-size: 0.75rem; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em; display: block;">In System Trash</span>
            <div style="display: flex; align-items: baseline; gap: 0.5rem; margin-top: 0.5rem;">
                <span style="font-size: 2rem; font-weight: 900; color: #e11d48;"><?= $trash_files_count ?></span>
                <span style="font-size: 0.75rem; font-weight: 600; color: #be123c; background: #fff1f2; padding: 0.125rem 0.5rem; border-radius: 0.375rem;">soft deleted</span>
            </div>
        </div>
        <div class="dashboard-card">
            <span style="font-size: 0.75rem; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em; display: block;">Total Storage</span>
            <div style="display: flex; align-items: baseline; gap: 0.5rem; margin-top: 0.5rem;">
                <span style="font-size: 1.75rem; font-weight: 900; color: #2563eb; letter-spacing: -0.025em;"><?= format_dashboard_bytes($total_storage_used) ?></span>
            </div>
        </div>
    </div>

    <!-- MAIN MONITOR GRID -->
    <div class="grid-charts">
        
        <!-- EXTENSION BREAKDOWN -->
        <div class="dashboard-card" style="display: flex; flex-direction: column; justify-content: space-between;">
    <!-- Centered header container block -->
    <div style="text-align: center; width: 100%;">
        <h2 style="font-size: 1rem; font-weight: 800; color: #0f172a; margin: 0;">File Extensions</h2>
        <p style="font-size: 0.75rem; color: #94a3b8; margin-top: 0.125rem; margin-bottom: 0;">All files extensions.</p>
    </div>
    
    <div style="position: relative; min-height: 260px; display: flex; align-items: center; justify-content: center; margin-top: 1.5rem;">
        <?php if (empty($extensions_breakdown)): ?>
            <p style="font-size: 0.875rem; color: #94a3b8; font-style: italic;">No files found.</p>
        <?php else: ?>
            <canvas id="fileTypeDistributionChart"></canvas>
        <?php endif; ?>
    </div>
</div>

        <!-- LINE GRAPH MONITORING -->
<div class="dashboard-card" style="display: flex; flex-direction: column; justify-content: space-between;">
    <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #f1f5f9; padding-bottom: 0.75rem; margin-bottom: 1.5rem;">
        <div>
            <h2 style="font-size: 1rem; font-weight: 800; color: #0f172a;">System Monitoring</h2>
            <p style="font-size: 0.75rem; color: #94a3b8; margin-top: 0.125rem;">Daily, Weekly, Monthly & Yearly logs.</p>
        </div>
        <div>
            <select id="timelineScopeSelector" style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 0.5rem; padding: 0.5rem 0.75rem; font-size: 0.75rem; font-weight: 700; color: #334155; cursor: pointer;">
                <option value="daily">Daily Timeline (7 Days)</option>
                <option value="weekly">Weekly View</option>
                <option value="monthly">Monthly View</option>
                <option value="yearly">Yearly View</option>
            </select>
        </div>
    </div>
    
    <!-- FIX: Nilagyan ng height constraints at fully responsive setup para hindi na lumaki mag-isa -->
    <div style="position: relative; height: 260px !important; width: 100%; max-width: 100%; overflow: hidden;">
        <canvas id="historicalActivityTimelineChart" style="width: 100% !important; height: 100% !important;"></canvas>
    </div>
</div>
    </div>
</div>

<!-- OVERLAY WINDOW MODALS -->
<div id="quickUploadModal" style="position: fixed; inset: 0; z-index: 100; display: none; background: rgba(15,23,42,0.6); backdrop-filter: blur(4px); align-items: center; justify-content: center; padding: 1rem;">
    <div style="background: #ffffff; border-radius: 0.75rem; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); width: 100%; max-width: 30rem; overflow: hidden; border: 1px solid #e2e8f0;">
        <div style="padding: 1rem 1.5rem; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; background: #f8fafc;">
            <h3 style="font-size: 1rem; font-weight: 700; color: #0f172a;">Upload New Document</h3>
            <button onclick="toggleModal('quickUploadModal', false)" style="background: none; border: none; color: #94a3b8; font-size: 1.5rem; cursor: pointer;">&times;</button>
        </div>
        <form method="POST" action="../upload.php" enctype="multipart/form-data" style="padding: 1.5rem;">
            <div style="margin-bottom: 1rem;">
                <label style="display: block; font-size: 0.75rem; font-weight: 700; color: #475569; text-transform: uppercase; margin-bottom: 0.5rem;">Select File</label>
                <input type="file" name="document" required style="width: 100%; border: 1px solid #e2e8f0; border-radius: 0.5rem; padding: 0.5rem; font-size: 0.875rem;">
            </div>
            <div style="margin-bottom: 1.5rem;">
                <label style="display: block; font-size: 0.75rem; font-weight: 700; color: #475569; text-transform: uppercase; margin-bottom: 0.5rem;">Target Folder</label>
                <select name="target_folder_location" style="width: 100%; border: 1px solid #e2e8f0; border-radius: 0.5rem; padding: 0.625rem; font-size: 0.875rem; background: #fff; color: #1e293b;">
                    <option value="root">/ Root Base Directory Layer</option>
                    <?php
                    $folders_fetch = $db->query("SELECT id, name FROM folders ORDER BY name ASC");
                    if ($folders_fetch !== false) {
                        while($f_row = $folders_fetch->fetch_assoc()) {
                            echo "<option value='".(int)$f_row['id']."'>📁 ".htmlspecialchars($f_row['name'])."</option>";
                        }
                    }
                    ?>
                </select>
            </div>
            <div style="border-top: 1px solid #f1f5f9; padding-top: 1rem; display: flex; justify-content: flex-end; gap: 0.75rem;">
                <button type="button" onclick="toggleModal('quickUploadModal', false)" style="padding: 0.5rem 1rem; background: #f1f5f9; color: #475569; border-radius: 0.5rem; border: none; font-weight: 700; font-size: 0.875rem; cursor: pointer;">Cancel</button>
                <button type="submit" style="padding: 0.5rem 1.25rem; background: #2563eb; color: #fff; border-radius: 0.5rem; border: none; font-weight: 700; font-size: 0.875rem; cursor: pointer;">Upload</button>
            </div>
        </form>
    </div>
</div>

<div id="pendingRequestsModal" style="position: fixed; inset: 0; z-index: 100; display: none; background: rgba(15,23,42,0.6); backdrop-filter: blur(4px); align-items: center; justify-content: center; padding: 1rem;">
    <div style="background: #ffffff; border-radius: 0.75rem; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); width: 100%; max-width: 38rem; overflow: hidden; border: 1px solid #e2e8f0;">
        <div style="padding: 1rem 1.5rem; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; background: #f8fafc;">
            <h3 style="font-size: 1rem; font-weight: 700; color: #0f172a;">Pending Requests</h3>
            <button onclick="toggleModal('pendingRequestsModal', false)" style="background: none; border: none; color: #94a3b8; font-size: 1.5rem; cursor: pointer;">&times;</button>
        </div>
        <div style="padding: 2rem; text-align: center; font-size: 0.875rem; color: #94a3b8; font-style: italic;">
            All access control allocations are synchronized.
            <div style="margin-top: 1.5rem; border-top: 1px solid #f1f5f9; padding-top: 1rem; display: flex; justify-content: flex-end;">
                <button type="button" onclick="toggleModal('pendingRequestsModal', false)" style="padding: 0.5rem 1.25rem; background: #1e293b; color: #fff; border-radius: 0.5rem; border: none; font-weight: 700; cursor: pointer;">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
function toggleModal(modalId, show) {
    const target = document.getElementById(modalId);
    if (!target) return;
    if (show) {
        target.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    } else {
        target.style.display = 'none';
        document.body.style.overflow = '';
    }
}

document.addEventListener("DOMContentLoaded", () => {
<?php if (!empty($extensions_breakdown)): ?>
    const ctxA = document.getElementById('fileTypeDistributionChart').getContext('2d');
    new Chart(ctxA, {
        type: 'doughnut',
        data: {
            labels: Object.keys(<?= json_encode($extensions_breakdown) ?>).map(k => k.toUpperCase()),
            datasets: [{
                data: Object.values(<?= json_encode($extensions_breakdown) ?>),
                backgroundColor: ['#2563eb', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#64748b'],
                borderWidth: 2, borderColor: '#ffffff'
            }]
        },
        options: { 
            responsive: true, 
            maintainAspectRatio: false, 
            // ── ADD LAYOUT PADDING TO SHIFT UPWARD ──
            layout: {
                padding: {
                    bottom: 15 // Adjust this number higher to nudge the doughnut further up
                }
            },
            plugins: { 
                legend: { 
                    position: 'bottom',
                    labels: {
                        padding: 15 // Keeps a nice clean gap between the shifted chart and the labels
                    }
                } 
            }, 
            cutout: '72%' 
        }
    });
<?php endif; ?>

    // 1. Dito in-initialize ang iyong line graph (Mananatili itong pareho)
    const timelineRepository = <?= json_encode($chart_series) ?>;
    const ctxTimeline = document.getElementById('historicalActivityTimelineChart').getContext('2d');
    
    let activityChartInstance = new Chart(ctxTimeline, {
        type: 'line',
        data: getChartDatasetScope('daily'),
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'top', labels: { boxWidth: 12, font: { weight: 'bold', size: 11 } } } },
            scales: {
                y: { beginAtZero: true, grid: { color: '#f1f5f9' }, ticks: { precision: 0 } },
                x: { grid: { display: false } }
            }
        }
    });

    // 2. ANG MATALINONG UPDATE (Ilagay ito mismo sa ilalim ng activityChartInstance):
    // Kusa nitong babantayan ang wrapper ng canvas at aayusin ang size tuwing gagalaw ang sidebar.
    const chartWrapper = ctxTimeline.canvas.parentElement;
    if (chartWrapper) {
        const lineGraphObserver = new ResizeObserver(() => {
            if (activityChartInstance) {
                activityChartInstance.resize();
                activityChartInstance.update('none'); // Huling redraw para walang visual lag
            }
        });
        lineGraphObserver.observe(chartWrapper);
    }

    function getChartDatasetScope(scope) {
        const source = timelineRepository[scope];
        return {
            labels: source.labels,
            datasets: [
                { label: 'Adds / Uploads', data: source.adds, borderColor: '#2563eb', backgroundColor: 'rgba(37, 99, 235, 0.04)', borderWidth: 3, tension: 0.3, fill: true },
                { label: 'Edits', data: source.edits, borderColor: '#06b6d4', backgroundColor: 'transparent', borderWidth: 2.5, tension: 0.25 },
                { label: 'Deletes', data: source.deletes, borderColor: '#ef4444', backgroundColor: 'transparent', borderWidth: 2, borderDash: [4, 4], tension: 0.1 },
                { label: 'Checkouts', data: source.checkouts, borderColor: '#f59e0b', backgroundColor: 'transparent', borderWidth: 2.5, tension: 0.3 },
                { label: 'Shares', data: source.shares, borderColor: '#10b981', backgroundColor: 'transparent', borderWidth: 2, borderDash: [6, 2], tension: 0.2 }
            ]
        };
    }

    document.getElementById('timelineScopeSelector').addEventListener('change', (e) => {
        activityChartInstance.data = getChartDatasetScope(e.target.value);
        activityChartInstance.update();
    });
});


function startLiveClock() {
    const clockElement = document.getElementById('live-server-clock');
    if (!clockElement) return;

    // 1. Grab the initial time from the server so the clocks align perfectly
    let currentTime = new Date();

    setInterval(() => {
        // 2. Increment the clock by exactly 1 second
        currentTime.setSeconds(currentTime.getSeconds() + 1);

        // 3. Format the hours, minutes, and AM/PM strings
        let hours = currentTime.getHours();
        let minutes = currentTime.getMinutes();
        const ampm = hours >= 12 ? 'PM' : 'AM';

        hours = hours % 12;
        hours = hours ? hours : 12; // Handle midnights (0 hours becomes 12)
        minutes = minutes < 10 ? '0' + minutes : minutes; // Add leading zeros

        // 4. Print it dynamically to the screen
        clockElement.textContent = `${hours}:${minutes} ${ampm}`;
    }, 1000);
}

// Fire the interval manager immediately when the page finishes rendering
document.addEventListener('DOMContentLoaded', startLiveClock);

</script>

<?php 
$footer_path = $base_dir . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'footer.php';
if (!file_exists($footer_path)) { $footer_path = $base_dir . DIRECTORY_SEPARATOR . 'footer.php'; }
include $footer_path; 
?>