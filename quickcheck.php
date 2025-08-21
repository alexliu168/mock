<?php
// quickcheck.php — QNAP PHP readiness + write + (optional) upload test
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: text/html; charset=utf-8');

$baseDir = __DIR__ . '/uploads';
$ok = [];$err = [];
$ts = date('c'); $ip = $_SERVER['REMOTE_ADDR'] ?? '-';

function line($b, $msg) { return ($b ? "✅ " : "❌ ") . htmlspecialchars($msg); }
function sanitize($s){ return substr(preg_replace('/[^A-Za-z0-9._-]/','', $s ?? ''), 0, 80); }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['f'])) {
  // Simple upload test
  if (!is_dir($baseDir) && !mkdir($baseDir, 0775, true)) {
    http_response_code(500);
    echo "❌ Failed to create uploads directory";
    exit;
  }
  if (!is_uploaded_file($_FILES['f']['tmp_name'])) {
    http_response_code(400); echo "❌ No valid file uploaded"; exit;
  }
  $name = sanitize($_FILES['f']['name'] ?: 'upload.bin');
  $dest = $baseDir . '/' . time() . '_' . $name;
  if (!move_uploaded_file($_FILES['f']['tmp_name'], $dest)) {
    http_response_code(500); echo "❌ move_uploaded_file failed"; exit;
  }
  echo "✅ Upload saved: uploads/" . basename($dest);
  exit;
}

// 1) PHP running?
$ok[] = line(true, "PHP is running (version: " . PHP_VERSION . ")");

// 2) Create uploads/ if missing
$created = true;
if (!is_dir($baseDir)) { $created = @mkdir($baseDir, 0775, true); }
$ok[] = line(is_dir($baseDir), "uploads/ directory " . (is_dir($baseDir) ? "exists" : "created"));

// 3) Is uploads/ writable?
$writable = is_writable($baseDir);
$ok[] = line($writable, "uploads/ is writable");

// 4) Write/append a test line to a file
$writeRes = false;
if ($writable) {
  $file = $baseDir . '/php_write_test.txt';
  $writeRes = @file_put_contents($file, "$ts\t$ip\n", FILE_APPEND) !== false;
  $ok[] = line($writeRes, "Wrote to uploads/php_write_test.txt");
} else {
  $err[] = "uploads/ is not writable by the web server user.";
}

// 5) Show useful PHP limits
$limits = [
  'upload_max_filesize' => ini_get('upload_max_filesize'),
  'post_max_size'       => ini_get('post_max_size'),
  'max_execution_time'  => ini_get('max_execution_time'),
  'file_uploads'        => ini_get('file_uploads'),
];

?>
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>QNAP PHP Quick Check</title>
<style>
  body{font-family:ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial;margin:20px;color:#0f172a}
  .card{max-width:760px;margin:auto;padding:18px;border:1px solid #e5e7eb;border-radius:12px;background:#fff}
  h1{margin:0 0 8px 0;font-size:20px}
  code{background:#f1f5f9;padding:2px 6px;border-radius:6px}
  ul{margin:8px 0 0 20px}
  .row{margin-top:12px}
  .ok{color:#065f46}.warn{color:#92400e}.err{color:#b91c1c}
</style>
</head><body>
<div class="card">
  <h1>QNAP PHP Quick Check</h1>
  <div class="row">
    <strong>Status:</strong>
    <ul>
      <?php foreach ($ok as $s) echo "<li>$s</li>"; ?>
      <?php foreach ($err as $e) echo "<li class='err'>❌ ".htmlspecialchars($e)."</li>"; ?>
    </ul>
  </div>

  <div class="row">
    <strong>PHP Limits:</strong>
    <ul>
      <?php foreach ($limits as $k=>$v) echo "<li><code>$k</code> = <code>".htmlspecialchars($v)."</code></li>"; ?>
    </ul>
  </div>

  <div class="row">
    <strong>Test files/folders:</strong>
    <ul>
      <li>Folder: <code>uploads/</code> (created if missing)</li>
      <li>Write file: <code>uploads/php_write_test.txt</code></li>
    </ul>
  </div>

  <div class="row">
    <strong>Optional: upload a small file</strong> (tests <code>file_uploads</code> and write perms)
    <form method="post" enctype="multipart/form-data">
      <input type="file" name="f" />
      <button type="submit">Upload</button>
    </form>
  </div>
</div>
</body></html>

