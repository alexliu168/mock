<?php
/**
 * test-eval-sa.php
 * Simple example: evaluate a fixed audio file using SpeechAce API.
 * Make sure you set SPEECHACE_KEY and SPEECHACE_BASE properly.
 */

if (is_file(__DIR__ . '/setup-sa.php')) { require __DIR__ . '/setup-sa.php'; }

// --- CONFIG ---
$SPEECHACE_KEY  = getenv('SPEECHACE_API_KEY') ?: 'EMPTY';
$SPEECHACE_BASE = getenv('SPEECHACE_API_URL') ?: 'https://api2.speechace.com'; // Singapore region
$SPEECHACE_PATH = '/api/scoring/text/v9/json';

$audioPath = __DIR__ . '/uploads/test_audio.aiff';  // local file path
$text      = "Please fasten your seat belt.";       // expected text
$dialect   = 'en-us';                               // or en-gb, fr-fr, etc.

if (!file_exists($audioPath)) {
    die("Audio file not found: $audioPath\n");
}
if (!$SPEECHACE_KEY || $SPEECHACE_KEY === 'YOUR_SPEECHACE_KEY_HERE') {
    die("Please set SPEECHACE_KEY in env or hardcode it.\n");
}

// --- Build API endpoint ---
$endpoint = rtrim($SPEECHACE_BASE, '/') . $SPEECHACE_PATH
          . '?' . http_build_query([
              'key'     => $SPEECHACE_KEY,
              'dialect' => $dialect,
              'user_id' => 'demo-user-001'
          ]);

// --- Prepare cURL ---
$curl = curl_init();
$postFields = [
    'text'            => $text,
    'user_audio_file' => new CURLFile($audioPath, mime_content_type($audioPath), basename($audioPath)),
    'include_fluency' => '1',
    'include_intonation' => '1'
];

curl_setopt_array($curl, [
    CURLOPT_URL            => $endpoint,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $postFields,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 60,
]);

$response = curl_exec($curl);
if ($response === false) {
    die("cURL error: " . curl_error($curl) . "\n");
}
$httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);

// --- Parse response ---
echo "HTTP status: $httpCode\n";
$decoded = json_decode($response, true);
if ($decoded === null) {
    echo "Raw response:\n$response\n";
    exit;
}

// Print summary

$summary = [
    'overall_pronunciation' => $decoded['text_score']['speechace_score']['pronunciation'] ?? null,
    'ielts_pronunciation'   => $decoded['text_score']['ielts_score']['pronunciation'] ?? null,
    'pte_pronunciation'     => $decoded['text_score']['pte_score']['pronunciation'] ?? null,
    'fluency'               => $decoded['text_score']['speechace_score']['fluency'] ?? null,
    'intonation'            => $decoded['text_score']['speechace_score']['intonation'] ?? null,
    'details'               => $decoded['text_score']['word_scores'] ?? [],
];

echo "Summary:\n";
foreach ($summary as $k => $v) {
    if ($k === 'details') {
        echo "Word Scores:\n";
        foreach ($v as $word) {
            $w = $word['word'] ?? '';
            $score = $word['score']['pronunciation'] ?? null;
            echo "  $w: pronunciation=$score\n";
        }
    } else {
        echo "  $k: ";
        if (is_array($v)) print_r($v); else echo "$v\n";
    }
}

// Optionally, print full JSON for debugging
 echo "\nFull API response:\n";
 print_r($decoded);
