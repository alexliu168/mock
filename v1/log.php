<?php
// log.php — write client debug events to uploads/<CODE>/logs/client.log
require __DIR__ . '/auth.php';

// Check if user is logged in, otherwise use anonymous
$user_code = strtoupper($_SESSION['invite_code'] ?? '');
$user_label = '';

if ($user_code) {
  $codes = load_codes(__DIR__ . '/invitecodes.txt');
  $user_label = $codes[$user_code] ?? '';
}

header('Content-Type: application/json; charset=utf-8');

$dir = __DIR__ . '/uploads/' . ($user_code ?: '_anon') . '/logs';
if (!is_dir($dir)) { @mkdir($dir, 0775, true); }

$ip = $_SERVER['HTTP_CF_CONNECTING_IP']
  ?? $_SERVER['HTTP_X_FORWARDED_FOR']
  ?? $_SERVER['REMOTE_ADDR']
  ?? '';

$payload = [
  'ts'        => date('c'),
  'ua'        => $_SERVER['HTTP_USER_AGENT'] ?? '',
  'ip'        => $ip,
  'page'      => $_POST['page'] ?? ($_GET['page'] ?? ($_SERVER['REQUEST_URI'] ?? '')),
  'event'     => $_POST['event'] ?? ($_GET['event'] ?? ''),
  'msg'       => $_POST['msg'] ?? '',
  'user_name' => $user_label ?: ($user_code ?: 'anonymous'),
  'data'      => null
];

// Support FormData (data as stringified JSON) or raw JSON body
if (isset($_POST['data'])) {
  $d = $_POST['data'];
  $dec = is_string($d) ? json_decode($d, true) : $d;
  $payload['data'] = $dec === null ? $d : $dec;
} else {
  $raw = file_get_contents('php://input');
  if ($raw) {
    $dec = json_decode($raw, true);
    if (is_array($dec)) $payload = array_merge($payload, $dec);
  }
}

// For eval_* events, recursively remove any fields named 'text' or variants to avoid logging full prompts
function redact_eval_array($arr){
  $strip = ['text','reference_text','ref_text','refText','referenceText'];
  $walker = function(&$v) use (&$walker, $strip) {
    if (is_array($v)) {
      foreach ($v as $k => &$vv) {
        if (in_array($k, $strip, true)) { unset($v[$k]); }
        else { $walker($vv); }
      }
    }
  };
  $walker($arr);
  return $arr;
}

$ev = $payload['event'] ?? '';
if (is_string($ev) && strpos($ev, 'eval_') === 0) {
  if (isset($payload['data']) && is_array($payload['data'])) {
    $payload['data'] = redact_eval_array($payload['data']);
  }
}

// Normalize data payload keys for analytics convenience
if (isset($payload['data']) && is_array($payload['data'])) {
  // Remove course_title if present
  if (array_key_exists('course_title', $payload['data'])) {
    unset($payload['data']['course_title']);
  }
  // Strip idx/course_idx (no longer needed)
  if (array_key_exists('idx', $payload['data'])) {
    unset($payload['data']['idx']);
  }
  if (array_key_exists('course_idx', $payload['data'])) {
    unset($payload['data']['course_idx']);
  }
  // Strip course_id (phrase_uid is sufficient)
  if (array_key_exists('course_id', $payload['data'])) {
    unset($payload['data']['course_id']);
  }
}

$file = $dir . '/client.log';
@file_put_contents($file, json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);

echo json_encode(['ok'=>true, 'file'=>str_replace(__DIR__.'/', '', $file)]);
