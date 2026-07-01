<?php
/**
 * upload.php — File upload processor with strict whitelist validation.
 */
require_once __DIR__ . '/auth.php';
$user = require_role('contributor', 'admin');
$db   = get_db();

define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('MAX_SIZE', 50 * 1024 * 1024); // 50 MB
$allowed_ext = ['pdf', 'docx', 'xlsx', 'png'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Show upload form standalone
    $page_title = 'Upload Document';
    include __DIR__ . '/partials/header.php';
    ?>
    <h2 class="page-title">Upload Document</h2>
    <div class="upload-zone">
      <form method="POST" action="upload.php" enctype="multipart/form-data">
        <p class="upload-hint">Allowed: .pdf, .docx, .xlsx, .png — Max 50 MB</p>
        <input type="file" name="document" id="document" class="file-input" required>
        <label for="document" class="btn btn-primary">Choose File</label>
        <button type="submit" class="btn btn-secondary">Upload</button>
      </form>
    </div>
    <?php
    include __DIR__ . '/partials/footer.php';
    exit;
}

// ── Validate upload ────────────────────────────────────────────────────
if (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
    $code = $_FILES['document']['error'] ?? -1;
    if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) {
        redirect_err('File exceeds the maximum allowed size of 50 MB.');
    }
    redirect_err('No file received or upload error.');
}

$file     = $_FILES['document'];
$tmp_path = $file['tmp_name'];
$orig_name = basename($file['name']);
$ext      = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));

if (!in_array($ext, $allowed_ext, true)) {
    redirect_err("File type '.$ext' is not allowed. Use: " . implode(', ', array_map(fn($e)=>".$e", $allowed_ext)));
}

if ($file['size'] > MAX_SIZE) {
    redirect_err('File exceeds the maximum allowed size of 50 MB.');
}

// Double-check MIME type for extra safety
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($tmp_path);
$safe_mimes = [
    'pdf'  => 'application/pdf',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'png'  => 'image/png',
];
if (isset($safe_mimes[$ext]) && $mime !== $safe_mimes[$ext]) {
    redirect_err('File MIME type does not match the extension. Upload rejected.');
}

// ── Move to uploads dir ────────────────────────────────────────────────
if (!is_dir(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
}

$stored_name = uniqid('doc_', true) . '.' . $ext;
$dest        = UPLOAD_DIR . $stored_name;

if (!move_uploaded_file($tmp_path, $dest)) {
    redirect_err('Failed to save the file. Check server permissions.');
}

// ── Insert database record ─────────────────────────────────────────────
$stmt = $db->prepare(
    'INSERT INTO documents (filename, storage_path, size, uploaded_by) VALUES (?, ?, ?, ?)'
);
$stmt->bind_param('ssii', $orig_name, $stored_name, $file['size'], $user['id']);
$stmt->execute();

$size_label = $file['size'] >= 1048576
    ? round($file['size'] / 1048576, 1) . ' MB'
    : round($file['size'] / 1024, 1) . ' KB';

audit_log($user['id'], 'UPLOAD', "Uploaded document '$orig_name' ($size_label)");

header('Location: documents.php?ok=' . urlencode("'$orig_name' uploaded successfully."));
exit;

function redirect_err(string $msg): never {
    header('Location: dashboard.php?upload_err=' . urlencode($msg));
    exit;
}
