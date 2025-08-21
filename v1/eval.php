<?php
// eval.php — Direct Tencent Cloud SOE (sentence-only), no SCF, session-gated
// Place in the same folder as auth.php / morningsir.php
// Requires: PHP cURL + JSON; depends on your existing auth.php session gate.

require __DIR__ . '/auth.php';
$user = require_login();                      // blocks if not logged in

header('Content-Type: application/json; charset=utf-8');
@ini_set('max_execution_time', '30');

// ================== CONFIG (server-side secrets) ==================
$SECRET_ID   = getenv('TC_SECRET_ID') ?: (getenv('SECRET_ID') ?: 'YOUR_SECRET_ID');
$SECRET_KEY  = getenv('TC_SECRET_KEY') ?: (getenv('SECRET_KEY') ?: 'YOUR_SECRET_KEY');
$SOE_APP_ID  = getenv('SOE_APP_ID') ?: '';
$SCORE_COEFF = getenv('SCORE_COEFF') ? floatval(getenv('SCORE_COEFF')) : 3.5; // 1.0 (kids) ~ 4.0 (adults stricter)
$TC_API_HOST = getenv('TC_API_HOST') ?: 'soe.tencentcloudapi.com';
$TC_VERSION  = getenv('TC_VERSION') ?: '2018-07-24';
// ================================================================

// ---------- Helpers (Tencent TC3-HMAC-SHA256 signing + POST) ----------
function tc3_request($action, $payloadArr, $secretId, $secretKey, $host, $version) {
  $timestamp = time();
  $date = gmdate('Y-m-d', $timestamp);

  $payload = json_encode($payloadArr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  $hashedPayload = hash('sha256', $payload);

  $canonicalHeaders = "content-type:application/json; charset=utf-8\nhost:$host\n";
  $signedHeaders = "content-type;host";
  $canonicalRequest = "POST\n/\n\n$canonicalHeaders\n$signedHeaders\n$hashedPayload";

  $algorithm = 'TC3-HMAC-SHA256';
  $credentialScope = "$date/soe/tc3_request";
  $hashedCanonicalRequest = hash('sha256', $canonicalRequest);
  $stringToSign = "$algorithm\n$timestamp\n$credentialScope\n$hashedCanonicalRequest";

  $secretDate    = hash_hmac('sha256', $date, "TC3$secretKey", true);
  $secretService = hash_hmac('sha256', 'soe', $secretDate, true);
  $secretSigning = hash_hmac('sha256', 'tc3_request', $secretService, true);
  $signature     = hash_hmac('sha256', $stringToSign, $secretSigning);

  $authorization = "$algorithm Credential=$secretId/$credentialScope, SignedHeaders=$signedHeaders, Signature=$signature";

  $headers = [
    "Authorization: $authorization",
    "Content-Type: application/json; charset=utf-8",
    "Host: $host",
    "X-TC-Action: $action",
    "X-TC-Version: $version",
    // X-TC-Region: not required for SOE
    "X-TC-Timestamp: $timestamp"
  ];

  $ch = curl_init("https://$host");
  curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_TIMEOUT        => 30,
  ]);
  $resp = curl_exec($ch);
  $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err  = curl_error($ch);
  curl_close($ch);

  return [$http, $resp, $err, $headers, $payload];
}

// ---------- Input: sentence-only text ----------
$rawText = $_POST['text'] ?? '';
$refText = preg_replace('/\s+/u', ' ', trim($rawText)); // collapse whitespace

if ($refText === '') {
  http_response_code(400); echo json_encode(['ok'=>false,'err'=>'Missing text']); exit;
}
if (preg_match("/[\r\n]/u", $refText)) {
  http_response_code(400); echo json_encode(['ok'=>false,'err'=>'Sentence mode only: no line breaks']); exit;
}
$charLimit = 220;
if (mb_strlen($refText, 'UTF-8') > $charLimit) {
  http_response_code(400); echo json_encode(['ok'=>false,'err'=>"Sentence too long (> {$charLimit} chars)"]); exit;
}
$wordCount = preg_match_all("/[\\p{L}']+/u", $refText);
if ($wordCount > 35) {
  http_response_code(400); echo json_encode(['ok'=>false,'err'=>'Sentence too long (>35 words)']); exit;
}
$enders = preg_match_all('/[.!?]+/u', $refText);
if ($enders > 1) {
  http_response_code(400); echo json_encode(['ok'=>false,'err'=>'Multiple sentences detected; sentence mode only']); exit;
}

// ---------- Input: audio file (WAV/MP3 recommended) ----------
if (empty($_FILES['audio'])) {
  http_response_code(400); echo json_encode(['ok'=>false,'err'=>'Missing audio']); exit;
}
$tmp = $_FILES['audio']['tmp_name'] ?? '';
$origName = $_FILES['audio']['name'] ?? 'audio';
$mime = $_FILES['audio']['type'] ?? '';
$bytes = @file_get_contents($tmp);
if ($bytes === false) {
  http_response_code(400); echo json_encode(['ok'=>false,'err'=>'Cannot read upload']); exit;
}

$ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
$voiceFileType = null; // 2=wav, 3=mp3 per SOE
if ($ext === 'wav' || strpos($mime, 'wav') !== false) $voiceFileType = 2;
if ($ext === 'mp3' || strpos($mime, 'mp3') !== false) $voiceFileType = 3;
if ($voiceFileType === null) {
  http_response_code(415);
  echo json_encode(['ok'=>false,'err'=>'Unsupported audio type; please upload WAV or MP3']); exit;
}

$voiceB64 = base64_encode($bytes);
$sessionId = 'ms_' . bin2hex(random_bytes(8));

// ---------- 1) InitOralProcess (WorkMode=1 non-streaming, EvalMode=1 sentence) ----------
$initPayload = [
  'SessionId'  => $sessionId,
  'RefText'    => $refText,
  'WorkMode'   => 1,   // non-streaming once
  'EvalMode'   => 1,   // sentence mode only
  'ScoreCoeff' => $SCORE_COEFF,
  'ServerType' => 0    // 0=English
];
if ($SOE_APP_ID !== '') $initPayload['SoeAppId'] = $SOE_APP_ID;

list($http1, $resp1, $err1) = tc3_request('InitOralProcess', $initPayload, $SECRET_ID, $SECRET_KEY, $TC_API_HOST, $TC_VERSION);
if ($err1) { http_response_code(502); echo json_encode(['ok'=>false,'stage'=>'init','err'=>'Curl error: '.$err1]); exit; }
$init = json_decode($resp1, true);
if ($http1 !== 200 || !isset($init['Response']['SessionId'])) {
  http_response_code($http1 ?: 500);
  echo json_encode(['ok'=>false,'stage'=>'init','http'=>$http1,'resp'=>$init ?: $resp1]); exit;
}

// ---------- 2) TransmitOralProcess (send full audio once) ----------
$txPayload = [
  'SeqId'           => 1,
  'IsEnd'           => 1,
  'VoiceFileType'   => $voiceFileType, // 2=wav, 3=mp3
  'VoiceEncodeType' => 1,              // PCM
  'UserVoiceData'   => $voiceB64,
  'SessionId'       => $sessionId
];
if ($SOE_APP_ID !== '') $txPayload['SoeAppId'] = $SOE_APP_ID;

list($http2, $resp2, $err2) = tc3_request('TransmitOralProcess', $txPayload, $SECRET_ID, $SECRET_KEY, $TC_API_HOST, $TC_VERSION);
if ($err2) { http_response_code(502); echo json_encode(['ok'=>false,'stage'=>'transmit','err'=>'Curl error: '.$err2]); exit; }

$tx = json_decode($resp2, true);
if ($http2 !== 200 || !isset($tx['Response'])) {
  http_response_code($http2 ?: 500);
  echo json_encode(['ok'=>false,'stage'=>'transmit','http'=>$http2,'resp'=>$tx ?: $resp2]); exit;
}

// ---------- Summary ----------
$resp = $tx['Response'];
$summary = [
  'Mode'           => 'sentence',
  'SuggestedScore' => $resp['SuggestedScore'] ?? null,
  'PronAccuracy'   => $resp['PronAccuracy']   ?? null,
  'PronFluency'    => $resp['PronFluency']    ?? null,
  'PronCompletion' => $resp['PronCompletion'] ?? null,
  'WordsCount'     => isset($resp['Words']) ? count($resp['Words']) : null,
  'RequestId'      => $resp['RequestId'] ?? null,
  'SessionId'      => $resp['SessionId'] ?? $sessionId
];

// ---------- Return ----------
echo json_encode([
  'ok'      => true,
  'who'     => $user['code'],   // your session invite code (for logs)
  'text'    => $refText,
  'init'    => $init,
  'result'  => $tx,
  'summary' => $summary
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
