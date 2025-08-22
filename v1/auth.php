<?php
// auth.php — secure session + invite-code validation
$CODES_FILE = __DIR__ . '/invitecodes.txt';

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


function require_login(){
  global $CODES_FILE;
  $codes = load_codes($CODES_FILE);
  $code  = strtoupper($_SESSION['invite_code'] ?? '');
  if ($code === '' || !isset($codes[$code])) {
    // Not logged in (or code revoked) → show login screen
    header('Location: morningsir.php'); exit;
  }
  return ['code'=>$code, 'label'=>$codes[$code]];
}
?>
