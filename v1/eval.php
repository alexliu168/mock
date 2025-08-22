<?php
// eval.php — Tencent SOE (sentence-only), session-gated, minimal/compatible version

error_reporting(E_ALL);
ini_set('display_errors', '0'); // set '1' temporarily if you need to see errors

// --- tiny JSON helper ---
function out_json($data, $code = 200) {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

// 1) session + optional local env
require __DIR__ . '/auth.php';
if (is_file(__DIR__ . '/setup.php')) { require __DIR__ . '/setup.php'; }

// 2) health ping (no login required)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ping'])) {
  out_json([
    'ok'             => true,
    'session'        => isset($_SESSION['invite_code']),
    'secrets'        => (getenv('TC_SECRET_ID') && getenv('TC_SECRET_KEY')),
    'curl_loaded'    => extension_loaded('curl'),
    'openssl_loaded' => extension_loaded('openssl'),
    'php'            => PHP_VERSION,
    'upload_max'     => ini_get('upload_max_filesize'),
    'post_max'       => ini_get('post_max_size')
  ]);
}

// 3) gate real requests
$user = require_login();


// 4) config from env
function envv($k, $d = null) { $v = getenv($k); return ($v === false || $v === '') ? $d : $v; }

$SECRET_ID   = envv('TC_SECRET_ID',  envv('SECRET_ID'));
$SECRET_KEY  = envv('TC_SECRET_KEY', envv('SECRET_KEY'));
$SOE_APP_ID  = envv('TC_SOE_APP_ID', envv('SOE_APP_ID', ''));
$SCORE_COEFF = (float) envv('SCORE_COEFF', '3.5');
if ($SCORE_COEFF < 1.0) $SCORE_COEFF = 1.0;
if ($SCORE_COEFF > 4.0) $SCORE_COEFF = 4.0;

$TC_API_HOST = 'soe.tencentcloudapi.com';
$TC_VERSION  = '2018-07-24';

if (!$SECRET_ID || !$SECRET_KEY) out_json(['ok'=>false,'err'=>'SOE secrets missing'], 500);

// 5) TC3 signing + POST
function tc3_post($action, $payloadArr, $secretId, $secretKey, $host, $version) {
  $timestamp = time();
  $dateStr = gmdate('Y-m-d', $timestamp);

  $payload = json_encode($payloadArr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  $hashedPayload = hash('sha256', $payload);

  $canonicalHeaders = "content-type:application/json; charset=utf-8\nhost:$host\n";
  $signedHeaders = "content-type;host";
  $canonicalRequest = "POST\n/\n\n".$canonicalHeaders."\n".$signedHeaders."\n".$hashedPayload;

  $algorithm = 'TC3-HMAC-SHA256';
  $credentialScope = $dateStr.'/soe/tc3_request';
  $hashedCanonicalRequest = hash('sha256', $canonicalRequest);
  $stringToSign = $algorithm."\n".$timestamp."\n".$credentialScope."\n".$hashedCanonicalRequest;

  $secretDate    = hash_hmac('sha256', $dateStr, 'TC3'.$secretKey, true);
  $secretService = hash_hmac('sha256', 'soe', $secretDate, true);
  $secretSigning = hash_hmac('sha256', 'tc3_request', $secretService, true);
  $signature     = hash_hmac('sha256', $stringToSign, $secretSigning);

  $authorization = $algorithm.' Credential='.$secretId.'/'.$credentialScope.', SignedHeaders='.$signedHeaders.', Signature='.$signature;

  $headers = [
    'Authorization: '.$authorization,
    'Content-Type: application/json; charset=utf-8',
    'Host: '.$host,
    'X-TC-Action: '.$action,
    'X-TC-Version: '.$version,
    'X-TC-Timestamp: '.$timestamp
  ];

  $ch = curl_init('https://'.$host);
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
  curl_setopt($ch, CURLOPT_TIMEOUT, 30);
  $resp = curl_exec($ch);
  $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err  = curl_error($ch);
  curl_close($ch);
  return [$http, $resp, $err];
}

// 6) sentence-only validation
$rawText = isset($_POST['text']) ? $_POST['text'] : '';
$refText = trim(preg_replace('/\s+/u', ' ', $rawText));
if ($refText === '') out_json(['ok'=>false,'err'=>'Missing text'], 400);
if (preg_match('/[\r\n]/u', $refText)) out_json(['ok'=>false,'err'=>'Sentence mode only: no line breaks'], 400);
if (mb_strlen($refText, 'UTF-8') > 220) out_json(['ok'=>false,'err'=>'Sentence too long (>220 chars)'], 400);
$wordCount = preg_match_all("/[\\p{L}']+/u", $refText);
if ($wordCount > 35) out_json(['ok'=>false,'err'=>'Sentence too long (>35 words)'], 400);
$enders = preg_match_all('/[.!?]+/u', $refText);
if ($enders > 1) out_json(['ok'=>false,'err'=>'Multiple sentences detected; sentence mode only'], 400);

// 7) audio validation
if (empty($_FILES['audio'])) out_json(['ok'=>false,'err'=>'Missing audio'], 400);
$tmp = isset($_FILES['audio']['tmp_name']) ? $_FILES['audio']['tmp_name'] : '';
$origName = isset($_FILES['audio']['name']) ? $_FILES['audio']['name'] : 'audio';
$mime = isset($_FILES['audio']['type']) ? $_FILES['audio']['type'] : '';
$bytes = @file_get_contents($tmp);
if ($bytes === false) out_json(['ok'=>false,'err'=>'Cannot read upload'], 400);

$ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
$voiceFileType = null; // 2=wav, 3=mp3
if ($ext === 'wav' || strpos($mime, 'wav') !== false) $voiceFileType = 2;
if ($ext === 'mp3' || strpos($mime, 'mp3') !== false) $voiceFileType = 3;
if ($voiceFileType === null) out_json(['ok'=>false,'err'=>'Unsupported audio type; please upload WAV or MP3'], 415);

$voiceB64 = base64_encode($bytes);
$sessionId = 'ms_'.bin2hex(random_bytes(8));

// 8) InitOralProcess (WorkMode=1, EvalMode=1, EN)
$initPayload = [
  'SessionId'  => $sessionId,
  'RefText'    => $refText,
  'WorkMode'   => 1,
  'EvalMode'   => 1,
  'ScoreCoeff' => $SCORE_COEFF,
  'ServerType' => 0
];
if ($SOE_APP_ID !== '') $initPayload['SoeAppId'] = $SOE_APP_ID;

list($http1, $body1, $err1) = tc3_post('InitOralProcess', $initPayload, $SECRET_ID, $SECRET_KEY, $TC_API_HOST, $TC_VERSION);
if ($err1) out_json(['ok'=>false,'stage'=>'init','err'=>'Curl error: '.$err1], 502);
$init = json_decode($body1, true);
if ($http1 !== 200 || !isset($init['Response']['SessionId'])) out_json(['ok'=>false,'stage'=>'init','http'=>$http1,'resp'=>($init ?: $body1)], $http1 ?: 500);

// 9) TransmitOralProcess (single chunk)
$txPayload = [
  'SeqId'           => 1,
  'IsEnd'           => 1,
  'VoiceFileType'   => $voiceFileType,
  'VoiceEncodeType' => 1,
  'UserVoiceData'   => $voiceB64,
  'SessionId'       => $sessionId
];
if ($SOE_APP_ID !== '') $txPayload['SoeAppId'] = $SOE_APP_ID;

list($http2, $body2, $err2) = tc3_post('TransmitOralProcess', $txPayload, $SECRET_ID, $SECRET_KEY, $TC_API_HOST, $TC_VERSION);

if ($err2) out_json(['ok'=>false,'stage'=>'transmit','err'=>'Curl error: '.$err2], 502);
$tx = json_decode($body2, true);
if ($http2 !== 200 || !isset($tx['Response'])) out_json(['ok'=>false,'stage'=>'transmit','http'=>$http2,'resp'=>($tx ?: $body2)], $http2 ?: 500);

// 10) summary + return
$resp = $tx['Response'];
$summary = [
  'Mode'           => 'sentence',
  'SuggestedScore' => isset($resp['SuggestedScore']) ? $resp['SuggestedScore'] : null,
  'PronAccuracy'   => isset($resp['PronAccuracy']) ? $resp['PronAccuracy'] : null,
  'PronFluency'    => isset($resp['PronFluency']) ? $resp['PronFluency'] : null,
  'PronCompletion' => isset($resp['PronCompletion']) ? $resp['PronCompletion'] : null,
  'WordsCount'     => isset($resp['Words']) ? count($resp['Words']) : null,
  'RequestId'      => isset($resp['RequestId']) ? $resp['RequestId'] : null,
  'SessionId'      => isset($resp['SessionId']) ? $resp['SessionId'] : $sessionId
];

out_json([
  'ok'      => true,
  'who'     => $user['code'],
  'text'    => $refText,
  'init'    => $init,
  'result'  => $tx,
  'summary' => $summary
]);
?>