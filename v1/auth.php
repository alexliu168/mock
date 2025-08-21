<?php
// auth.php — secure session + login enforcement + code revalidation
$CODES_FILE = __DIR__ . '/invitecodes.txt';

session_set_cookie_params([
  'lifetime' => 60*60*24*14,   // 14 days
  'path'     => '/',
  'secure'   => !empty($_SERVER['HTTPS']),  // set true under HTTPS
  'httponly' => true,
  'samesite' => 'Lax'
]);
ini_set('session.use_strict_mode', '1');
session_start();

function load_codes($path){
  $o=[]; if (is_file($path)) {
    foreach (file($path) as $l) {
      $l=trim($l); if ($l==='' || $l[0]==='#') continue;
      $parts = array_map('trim', explode(',', $l, 2));
      $o[strtoupper($parts[0])] = $parts[1] ?? '';
    }
  }
  return $o;
}

function require_login(){
  global $CODES_FILE;
  $codes = load_codes($CODES_FILE);
  $code  = strtoupper($_SESSION['invite_code'] ?? '');
  if ($code === '' || !isset($codes[$code])) {
    // Not logged in or code revoked -> send to login
    header('Location: morningsir.php'); exit;
  }
  return ['code'=>$code, 'label'=>$codes[$code]];
}
