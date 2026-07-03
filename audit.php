<?php
/**
 * audit.php — Admin-only advanced audit log viewer with modular filtering arrays.
 */
require_once __DIR__ . '/auth.php';
$user = require_role('admin');
$db   = get_db();

// ── 1. SETUP PACING & INPUT FILTER VARIABLES ──
$limit  = 10; // Forced configuration rule parameter path
$offset = max(0, (int)($_GET['offset'] ?? 0));

// Extract active filtering states from global GET parameters array channels
$filter_date   = trim($_GET['filter_date'] ?? '');
$filter_role   = trim($_GET['filter_role'] ?? '');
$filter_action = trim($_GET['filter_action'] ?? '');

// ── 2. DYNAMICALLY BUILD SQL WHERE STATEMENT CONDITIONS ──
$where_clauses = [];
$bind_types    = "";
$bind_params   = [];

// Filter A: Explicit Calendar Date Selection Target
if ($filter_date !== '') {
    $where_clauses[] = "DATE(al.timestamp) = ?";
    $bind_types     .= "s";
    $bind_params[]   = $filter_date;
}

// Filter B: Access Authorization Role Selection
if ($filter_role !== '') {
    $where_clauses[] = "u.role = ?";
    $bind_types     .= "s";
    $bind_params[]   = $filter_role;
}

// Filter C: Distinct Functional System Activity Type
if ($filter_action !== '') {
    $where_clauses[] = "al.action_type = ?";
    $bind_types     .= "s";
    $bind_params[]   = $filter_action;
}

// Combine all filtering criteria smoothly into final query strings
$where_sql = "";
if (!empty($where_clauses)) {
    $where_sql = "WHERE " . implode(" AND ", $where_clauses);
}

// ── 3. FETCH DATA REGISTRY MATRIX ROWS ──
$logs_query_str = "
    SELECT al.*, u.username, u.role 
    FROM audit_logs al
    LEFT JOIN users u ON al.user_id = u.id
    $where_sql
    ORDER BY al.timestamp DESC
    LIMIT ? OFFSET ?
";

$logs = $db->prepare($logs_query_str);

// Dynamically bind standard parameters alongside core pagination indexing limits
if ($where_sql !== "") {
    $final_bind_types = $bind_types . "ii";
    $final_params     = array_merge($bind_params, [$limit, $offset]);
    $logs->bind_param($final_bind_types, ...$final_params);
} else {
    $logs->bind_param('ii', $limit, $offset);
}

$logs->execute();
$entries = $logs->get_result()->fetch_all(MYSQLI_ASSOC);

// ── 4. EXTRACT TOTAL RESULTS SCALE BARS ──
$count_query_str = "
    SELECT COUNT(*) 
    FROM audit_logs al
    LEFT JOIN users u ON al.user_id = u.id
    $where_sql
";
$count_stmt = $db->prepare($count_query_str);

if ($where_sql !== "") {
    $count_stmt->bind_param($bind_types, ...$bind_params);
}
$count_stmt->execute();
$total = (int) $count_stmt->get_result()->fetch_row()[0];

// Fetch distinct operational actions from the database to automatically populate filter choices
$action_types_query = $db->query("SELECT DISTINCT action_type FROM audit_logs WHERE action_type IS NOT NULL ORDER BY action_type ASC");
$available_actions  = $action_types_query ? $action_types_query->fetch_all(MYSQLI_ASSOC) : [];

$page_title = 'Audit Log Master Studio';
include __DIR__ . '/partials/header.php';

// Helper function to append existing query options onto pagination links
function keep_filters_attached($filter_date, $filter_role, $filter_action) {
    return '&filter_date=' . urlencode($filter_date) . 
           '&filter_role=' . urlencode($filter_role) . 
           '&filter_action=' . urlencode($filter_action);
}
?>

<!-- ── DESIGN IMPLEMENTATIONS OVERRIDE HOOK ── -->
<style>
  .audit-filter-panel { background: #f8fafc; border: 1px solid #e2e8f0; padding: 20px; border-radius: 8px; margin-bottom: 25px; display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)) auto; gap: 15px; align-items: flex-end; }
  .filter-input-wrapper { display: flex; flex-direction: column; gap: 6px; }
  .filter-input-wrapper label { font-size: 12px; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.5px; }
  .filter-input-wrapper input, .filter-input-wrapper select { padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 14px; background-color: #fff; width: 100%; box-sizing: border-box; color: #333; }
  .filter-action-btn-row { display: flex; gap: 10px; }
</style>

<h2 class="page-title">System Audit Logs</h2>
<p class="muted" style="margin-bottom: 20px; font-weight: 500;">Found <strong><?= number_format($total) ?></strong> matching records</p>

<!-- ── 1. MODULAR MATRIX LOGICAL FILTERS BAR ── -->
<form method="GET" action="audit.php" class="audit-filter-panel">
  <input type="hidden" name="offset" value="0"> <!-- Reset offset on submitting new parameter filters -->

  <!-- Filter Input Node 1: Calendar Timeline Search -->
  <div class="filter-input-wrapper">
    <label for="filter_date">Search Timestamp Date</label>
    <input type="date" id="filter_date" name="filter_date" value="<?= htmlspecialchars($filter_date) ?>">
  </div>

  <!-- Filter Input Node 2: System Account Level Classification Selection -->
  <div class="filter-input-wrapper">
    <label for="filter_role">Filter User Role</label>
    <select id="filter_role" name="filter_role">
      <option value="">— View All Roles —</option>
      <option value="admin" <?= $filter_role === 'admin' ? 'selected' : '' ?>>Administrators Only</option>
      <option value="contributor" <?= $filter_role === 'contributor' ? 'selected' : '' ?>>Contributors Only</option>
      <option value="casual" <?= $filter_role === 'casual' ? 'selected' : '' ?>>Casual Users Only</option>
    </select>
  </div>

  <!-- Filter Input Node 3: Distinct Action Type -->
  <div class="filter-input-wrapper">
    <label for="filter_action">Filter Logged Action</label>
    <select id="filter_action" name="filter_action">
      <option value="">— View All Actions —</option>
      <?php foreach ($available_actions as $act): ?>
        <option value="<?= htmlspecialchars($act['action_type']) ?>" <?= $filter_action === $act['action_type'] ? 'selected' : '' ?>>
          <?= htmlspecialchars(str_replace('_', ' ', $act['action_type'])) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <!-- Form Action Trigger Elements Section -->
  <div class="filter-action-btn-row">
    <button type="submit" class="btn btn-primary" style="padding: 11px 20px;">Apply Filters</button>
    <?php if ($filter_date !== '' || $filter_role !== '' || $filter_action !== ''): ?>
      <a href="audit.php" class="btn btn-outline" style="padding: 11px 15px; text-decoration: none; text-align: center;">Reset</a>
    <?php endif; ?>
  </div>
</form>

<!-- ── 2. LEDGER RECORDS PRESENTATION TABLE DATA TREE ── -->
<div class="table-responsive">
  <table class="data-table">
    <thead>
      <tr>
        <th style="width: 180px;">Timestamp</th>
        <th>User Account</th>
        <th>System Role</th>
        <th>Action Type</th>
        <th>Activity Description</th>
      </tr>
    </thead>
    <tbody>
    <?php if (empty($entries)): ?>
      <tr><td colspan="5" class="empty-row" style="text-align: center; padding: 30px; color: #64748b; font-style: italic;">No matching system audit history records located matching criteria parameters.</td></tr>
    <?php endif; ?>
    <?php foreach ($entries as $log): ?>
      <tr>
        <td style="white-space:nowrap; font-weight: 500; font-size: 13px; color: #334155;">
          <?php 
            $dateObj = new DateTime($log['timestamp']);
            echo htmlspecialchars($dateObj->format('Y-m-d h:i A'));
          ?>
        </td>
        <td style="font-weight: 600; color: #1e293b;"><?= htmlspecialchars($log['username'] ?? '—') ?></td>
        <td>
          <?php if (($log['role'] ?? '') === 'admin'): ?>
            <span class="badge" style="background: #ef4444; color: #fff; padding: 2px 6px; border-radius: 4px; font-size: 11px;">ADMIN</span>
          <?php elseif (($log['role'] ?? '') === 'contributor'): ?>
            <span class="badge" style="background: #3b82f6; color: #fff; padding: 2px 6px; border-radius: 4px; font-size: 11px;">CONTRIBUTOR</span>
          <?php elseif (($log['role'] ?? '') === 'casual'): ?>
            <span class="badge" style="background: #64748b; color: #fff; padding: 2px 6px; border-radius: 4px; font-size: 11px;">CASUAL</span>
          <?php else: ?>
            <span style="color:#94a3b8;">—</span>
          <?php endif; ?>
        </td>
        <td>
          <span class="badge" style="text-transform: uppercase; font-weight: bold; background: #e2e8f0; color: #334155; padding: 4px 8px; border-radius: 4px; font-size: 11px;">
            <?= htmlspecialchars($log['action_type']) ?>
          </span>
        </td>
        <td style="color: #475569; font-size: 14px; max-width: 400px; word-break: break-word;"><?= htmlspecialchars($log['description']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- ── 3. STATEFUL PAGINATION CONTROL TRACKING RAIL ── -->
<div class="pagination" style="display: flex; gap: 10px; margin-top: 25px; align-items: center;">
  <?php if ($offset > 0): ?>
    <a href="audit.php?offset=<?= max(0, $offset - $limit) . keep_filters_attached($filter_date, $filter_role, $filter_action) ?>" class="btn btn-outline">&larr; Newer Entries</a>
  <?php else: ?>
    <button class="btn btn-outline" disabled style="opacity: 0.5; cursor: not-allowed;">&larr; Newer Entries</button>
  <?php endif; ?>

  <span style="font-size: 14px; color: #64748b; font-weight: 500;">
    Showing rows <?= min($total, $offset + 1) ?> to <?= min($total, $offset + $limit) ?> of <?= $total ?>
  </span>

  <?php if (($offset + $limit) < $total): ?>
    <a href="audit.php?offset=<?= ($offset + $limit) . keep_filters_attached($filter_date, $filter_role, $filter_action) ?>" class="btn btn-outline">Older Entries &rarr;</a>
  <?php else: ?>
    <button class="btn btn-outline" disabled style="opacity: 0.5; cursor: not-allowed;">Older Entries &rarr;</button>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
