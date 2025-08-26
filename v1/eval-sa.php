<?php
/**
 * eval-sa.php — compact JSON for your UI
 * Input (multipart/form-data):
 *   audio: file
 *   text:  expected sentence
 *   dialect, user_id, include_fluency, include_intonation (optional)
 *
 * Output (application/json): compact summary + weak_words tips
 */

header('Content-Type: application/json; charset=utf-8');

// 1) session + optional local env
require __DIR__ . '/auth.php';

// if (is_file(__DIR__ . '/setup-sa.php')) { require __DIR__ . '/setup-sa.php'; }


// 2) health ping (no login required)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ping'])) {
  out_json([
    'ok'             => true,
    'session'        => isset($_SESSION['invite_code']),
    'secrets'        => getenv('SPEECHACE_API_KEY') ? true : false,
    'speechace_url'  => getenv('SPEECHACE_API_URL') ?: 'default',
    'curl_loaded'    => extension_loaded('curl'),
    'openssl_loaded' => extension_loaded('openssl'),
    'php'            => PHP_VERSION,
    'upload_max'     => ini_get('upload_max_filesize'),
    'post_max'       => ini_get('post_max_size')
  ]);
}

// --- tiny JSON helper ---
function out_json($data, $code = 200) {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}


$SPEECHACE_KEY  = getenv('SPEECHACE_API_KEY') ?: 'YOUR_SPEECHACE_KEY_HERE';
$SPEECHACE_BASE = getenv('SPEECHACE_API_URL') ?: 'https://api2.speechace.com';
$SPEECHACE_PATH = '/api/scoring/text/v9/json';

function oops($code, $msg, $extra = []) {
  http_response_code($code);
  echo json_encode(array_merge(['status'=>'error','message'=>$msg], $extra), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  oops(405, 'Use POST multipart/form-data');
}
if (!$SPEECHACE_KEY || $SPEECHACE_KEY === 'YOUR_SPEECHACE_KEY_HERE') {
  oops(500, 'SpeechAce API key not configured');
}
if (empty($_FILES['audio']) || $_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
  oops(400, 'Missing or invalid audio upload');
}
$text = trim($_POST['text'] ?? '');
if ($text === '') {
  oops(400, 'Missing required field: text');
}
$dialect = trim($_POST['dialect'] ?? 'en-us');
$user_id = trim($_POST['user_id'] ?? '');
$include_fluency    = (($_POST['include_fluency'] ?? '') === '1') ? '1' : null;
$include_intonation = (($_POST['include_intonation'] ?? '') === '1') ? '1' : null;
$no_mc              = (($_POST['no_mc'] ?? '') === '1') ? '1' : null;

// Build endpoint
$endpoint = rtrim($SPEECHACE_BASE, '/') . $SPEECHACE_PATH . '?' . http_build_query([
  'key'     => $SPEECHACE_KEY,
  'dialect' => $dialect,
] + ($user_id ? ['user_id'=>$user_id] : []));

// Prepare POST
$audioTmp  = $_FILES['audio']['tmp_name'];
$audioName = $_FILES['audio']['name'];
$audioType = $_FILES['audio']['type'] ?: 'application/octet-stream';

$postFields = [
  'text'            => $text,
  'user_audio_file' => new CURLFile($audioTmp, $audioType, $audioName),
];
if ($include_fluency)    $postFields['include_fluency'] = '1';
if ($include_intonation) $postFields['include_intonation'] = '1';
if ($no_mc)              $postFields['no_mc'] = '1';

$ch = curl_init();
curl_setopt_array($ch, [
  CURLOPT_URL            => $endpoint,
  CURLOPT_POST           => true,
  CURLOPT_POSTFIELDS     => $postFields,
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_HEADER         => false,
  CURLOPT_TIMEOUT        => 60,
]);
$resp = curl_exec($ch);
if ($resp === false) {
  $err = curl_error($ch);
  curl_close($ch);
  oops(502, 'SpeechAce request failed', ['detail'=>$err]);
}
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// Parse SA JSON
$sa = json_decode($resp, true);
if ($sa === null) {
  oops(502, 'Invalid response from SpeechAce', ['raw'=>$resp, 'http_code'=>$code]);
}
if (($sa['status'] ?? '') !== 'success') {
  // Pass through SpeechAce error details
  oops(400, 'SpeechAce error', [
    'short_message'  => $sa['short_message'] ?? null,
    'detail_message' => $sa['detail_message'] ?? null,
    'http_code'      => $code
  ]);
}

// --- Compact mapping for your UI ---
$ts = $sa['text_score'] ?? [];
$speechace = $ts['speechace_score'] ?? [];
$ielts     = $ts['ielts_score'] ?? [];
$pte       = $ts['pte_score'] ?? [];
$cefr      = $ts['cefr_score'] ?? [];
$flu = $sa['fluency'] ?? []; // present if include_fluency=1

$overall  = $speechace['pronunciation'] ?? null;
$fluency  = $speechace['fluency'] ?? ($flu['overall_metrics']['speechace_score']['fluency'] ?? null);
$ielts_pr = $ielts['pronunciation'] ?? null;
$pte_pr   = $pte['pronunciation'] ?? null;
$cefr_pr  = $cefr['pronunciation'] ?? null;

// Heuristic tips for weak words (< 60)
$weak_words = [];
$words = $ts['word_score_list'] ?? [];
foreach ($words as $w) {
  $wrd = $w['word'] ?? '';
  $sc  = isset($w['quality_score']) ? (int)round($w['quality_score']) : null;
  if ($wrd === '' || $sc === null) continue;
  if ($sc >= 60) continue; // only weak ones

  // Simple tip heuristics based on spelling
  $tip = 'pronounce clearly';
  $lower = strtolower($wrd);
  if (str_starts_with($lower, 'p')) $tip = 'strong /p/ at the start';
  if (str_starts_with($lower, 's')) $tip = 'clear /s/ at the start';
  if (str_contains($lower, 'lt'))   $tip = 'articulate the /lt/ cluster';
  if (preg_match('/[tdkgpb]$/', $lower)) $tip = 'finish the final consonant';
  if (str_ends_with($lower, 't'))   $tip = 'finish the /t/ sound';
  if ($lower === 'belt')            $tip = 'finish the /t/ and hold the /l/ before it';
  if ($lower === 'please')          $tip = 'strong /p/ and clear final /z/';
  if ($lower === 'seat')            $tip = 'clear /s/ and finish /t/';

  $weak_words[] = ['word'=>$wrd, 'score'=>$sc, 'tip'=>$tip];
}

// Sort weakest first
usort($weak_words, function($a,$b){ return $a['score'] <=> $b['score']; });

// Build compact payload
$out = [
  'overall'    => $overall,
  'fluency'    => $fluency,
  'ielts_pron' => $ielts_pr,
  'pte_pron'   => $pte_pr,
  'cefr_pron'  => $cefr_pr,
  'weak_words' => $weak_words,
  'meta'       => [
    'dialect' => $dialect,
    'user_id' => $user_id ?: null,
    'text'    => $ts['text'] ?? $text,
    'version' => $sa['version'] ?? null,
    'req_id'  => $sa['request_id'] ?? null,
  ],
];

$out['status'] = 'ok';

echo json_encode($out, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
