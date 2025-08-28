<?php
// Legacy entry: morningsir.php now handles login inline.
// Redirect here for backward compatibility.
$target = 'morningsir.php';
if (!headers_sent()) {
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  header('Expires: 0');
  header('Location: ' . $target, true, 302);
  exit;
}
?>
<!doctype html>
<html lang="zh-Hant">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
    <meta http-equiv="refresh" content="0;url=morningsir.php" />
    <title>正在跳轉…</title>
    <style>
      body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial;margin:0;background:#f8fafc;color:#0f172a;display:flex;align-items:center;justify-content:center;min-height:100dvh}
      .tip{padding:24px}
      a{color:#0A66FF;text-decoration:none}
    </style>
  </head>
  <body>
    <div class="tip">正在前往登入頁（<a href="morningsir.php">點此立即跳轉</a>）</div>
  </body>
</html>
