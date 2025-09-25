<?php
// Authentication logic (moved from auth.php)
$CODES_FILE = __DIR__ . '/lib/invitecodes.txt';

session_set_cookie_params([
  'lifetime' => 60*60*24*14,      // 14 days
  'path'     => '/',
  'secure'   => !empty($_SERVER['HTTPS']), // set true automatically when on HTTPS
  'httponly' => true,
  'samesite' => 'Lax'
]);
ini_set('session.use_strict_mode', '1');
session_start();

// URL pieces for absolute <base> (works in Safari + Home Screen)
$SCHEME  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$ORIGIN  = $SCHEME . '://' . $_SERVER['HTTP_HOST'];
$BASE    = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/'; // e.g. /morningsir/
$ABS_BASE = $ORIGIN . $BASE;                                     // e.g. https://host/morningsir/

function load_codes($path){
  $o=[]; if (is_file($path)) {
    foreach (file($path) as $l) {
      $l = trim($l);
      if ($l === '' || $l[0] === '#') continue;
      $p = array_map('trim', explode(',', $l, 2));
      $o[strtoupper($p[0])] = $p[1] ?? '';
    }
  }
  return $o;
}

// Prevent stale caches serving old inline JS (e.g., references like doMock)
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// logout
if (isset($_GET['logout'])) {
  $_SESSION = [];
  if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time()-42000, $p['path'], $p['domain'] ?? '', !empty($_SERVER['HTTPS']));
  }
  session_destroy();
  header('Location: morningsir.php'); exit;
}

// Check authentication
$codes = load_codes($CODES_FILE);
$code  = strtoupper($_SESSION['invite_code'] ?? '');
$is_authenticated = ($code !== '' && isset($codes[$code]));

if (!$is_authenticated) {
  // Show login form inline instead of redirecting
  $login_error = '';
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['invitecode'])) {
    $try = strtoupper(trim($_POST['invitecode']));
    if ($try !== '' && isset($codes[$try])) {
      session_regenerate_id(true);
      $_SESSION['invite_code']  = $try;
      $_SESSION['invite_label'] = $codes[$try];
      $_SESSION['user_id'] = $codes[$try];
      header('Location: morningsir.php'); exit;
    } else {
      $login_error = '邀請碼無效，請重新輸入。';
    }
  }
  
  // Display login form
  ?>
  <!DOCTYPE html>
  <html lang="zh-Hant">
  <head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <base href="<?php echo htmlspecialchars($ABS_BASE, ENT_QUOTES, 'UTF-8'); ?>">
  <title>輸入邀請碼</title>
  <style>
    body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial;margin:0;background:#f8fafc;color:#0f172a}
    .wrap{min-height:100dvh;display:flex;align-items:center;justify-content:center;padding:24px}
    .card{width:min(420px,92vw);background:#fff;border:1px solid #e5e7eb;border-radius:16px;box-shadow:0 10px 30px rgba(2,8,23,.06);padding:20px}
    h2{margin:0 0 8px 0}.muted{color:#64748b}.row{margin-top:12px}
    input{width:100%;padding:12px;border:1px solid #e5e7eb;border-radius:10px;font-size:16px}
    button{width:100%;margin-top:12px;padding:12px;border:none;border-radius:10px;background:#0A66FF;color:#fff;font-weight:700;cursor:pointer}
    .err{color:#b91c1c;margin-top:8px}.foot{margin-top:12px;font-size:12px;color:#64748b}
  </style>
  </head>
  <body>
  <div class="wrap">
  <div class="card">
    <h2>輸入邀請碼</h2>
    <p class="muted">請輸入邀請碼以進入系統。</p>
    <form method="post" autocomplete="off">
      <input name="invitecode" placeholder="邀請碼" required autofocus />
      <button type="submit">進入</button>
    </form>
    <?php if ($login_error) echo '<div class="err">'.htmlspecialchars($login_error).'</div>'; ?>
    <div class="foot">沒有邀請碼？請聯繫管理員。</div>
  </div>
  </div>
  </body>
  </html>
  <?php
  exit;
}

// User is authenticated
$user = ['code'=>$code, 'name'=>$codes[$code] ?: $code];
// Use 'name' (label) when available, else fallback to code
$DISPLAY_NAME = htmlspecialchars(($user['name'] ?: $user['code']), ENT_QUOTES, 'UTF-8');

// Include the HTML content with session variables injected
ob_start();
include __DIR__ . '/mainapp.html';
$html = ob_get_clean();

// Inject session variables at the beginning of the first script tag
$sessionScript = "<script>\n // Global debug switch (default OFF)\n    window.MS_DEBUG = (typeof window.MS_DEBUG === 'boolean') ? window.MS_DEBUG : false;\n    // Session variables from PHP\n    window.SESSION_USER = " . json_encode($user) . ";\n    window.DISPLAY_NAME = " . json_encode($DISPLAY_NAME) . ";\n    ";
$html = preg_replace('/<script>/', $sessionScript, $html, 1);

echo $html;
