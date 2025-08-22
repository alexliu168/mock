<?php
// log.php — write client debug events to uploads/<CODE>/logs/client.log
require __DIR__ . '/auth.php';
$user = require_login();

header('Content-Type: application/json; charset=utf-8');

$dir = __DIR__ . '/uploads/' . $user['code'] . '/logs';
if (!is_dir($dir)) { @mkdir($dir, 0775, true); }

$payload = [
  'ts'   => date('c'),
  'ua'   => $_SERVER['HTTP_USER_AGENT'] ?? '',
  'page' => $_POST['page'] ?? ($_GET['page'] ?? ''),
  'event'=> $_POST['event'] ?? ($_GET['event'] ?? ''),
  'msg'  => $_POST['msg'] ?? '',
  'data' => null
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

$file = $dir . '/client.log';
@file_put_contents($file, json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);

echo json_encode(['ok'=>true, 'file'=>str_replace(__DIR__.'/', '', $file)]);
