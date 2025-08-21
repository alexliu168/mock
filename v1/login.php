<?php
require __DIR__.'/auth.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['invitecode'])) {
  $try = strtoupper(trim($_POST['invitecode']));
  $codes = load_codes($CODES_FILE);
  if ($try !== '' && isset($codes[$try])) {
    session_regenerate_id(true);
    $_SESSION['invite_code']  = $try;
    $_SESSION['invite_label'] = $codes[$try];
    header('Location: morningsir.php'); exit;
  } else {
    $error = '邀請碼無效，請重新輸入。';
  }
}
?>
<!doctype html>
<html lang="zh-Hant"><head>
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
</head><body><div class="wrap"><div class="card">
  <h2>輸入邀請碼</h2>
  <p class="muted">請輸入邀請碼以進入系統。</p>
  <form method="post" autocomplete="off">
    <input name="invitecode" placeholder="邀請碼" required autofocus />
    <button type="submit">進入</button>
  </form>
  <?php if ($error) echo '<div class="err">'.htmlspecialchars($error).'</div>'; ?>
  <div class="foot">沒有邀請碼？請聯繫管理員。</div>
</div></div></body></html>
