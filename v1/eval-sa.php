<?php
/**
 * eval-sa.php — compact JSON for your UI
 * Input (multipart/form-data):
 *   audio: file
 *   text:  expected sentence
 *   dialect, user_id, include_fluency, include_intonation, include_summary (optional)
 *
 * Output (application/json): compact summary + weak_words tips
 */

header('Content-Type: application/json; charset=utf-8');

// 1) Require an existing session from morningsir.php, do NOT create new sessions here.
if (session_status() === PHP_SESSION_NONE) {
  // Must have a session cookie already
  $sname = session_name();
  $sid = isset($_COOKIE[$sname]) ? preg_replace('/[^A-Za-z0-9,-]/', '', $_COOKIE[$sname]) : '';
  if ($sid === '') { header('Location: morningsir.php'); exit; }

  // If using files handler, ensure the backing session file exists; otherwise redirect.
  $handler = ini_get('session.save_handler') ?: 'files';
  if ($handler === 'files') {
    $spath = ini_get('session.save_path') ?: sys_get_temp_dir();
    // session.save_path may contain prefixes like "N;/path" — take the last segment as the path
    if (strpos($spath, ';') !== false) { $parts = explode(';', $spath); $spath = end($parts); }
    $spath = rtrim($spath, "/\\");
    $sfile = $spath . DIRECTORY_SEPARATOR . 'sess_' . $sid;
    if (!is_file($sfile)) { header('Location: morningsir.php'); exit; }
  }

  // Open the existing session (won't create a new one for files handler when file check passed)
  session_id($sid);
  session_start();
}
// Require the login flag
if (empty($_SESSION['invite_code'])) { header('Location: morningsir.php'); exit; }

// Optional per-environment config (define constants or setenv here)
if (is_file(__DIR__ . '/lib/setup-sa.php')) { require_once __DIR__ . '/lib/setup-sa.php'; }

// In-file toggle for saving uploaded audio. Set to true to enable by default.
// You can still override per-request via POST save_audio=1/0
$SAVE_AUDIO_DEFAULT = false;

// --- tiny JSON helper ---
function out_json($data, $code = 200) {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

// --- server-side logging (one JSONL per invocation) ---
function append_eval_server_log($entry) {
  try {
    // Use invite code folder when available, else _anon
    $code = strtoupper($_SESSION['invite_code'] ?? '');
    $dir = __DIR__ . '/uploads/' . ($code ?: '_anon') . '/logs';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $file = $dir . '/server-eval.log';
    $payload = array_merge(['ts' => date('c')], $entry);
    @file_put_contents($file, json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
  } catch (\Throwable $e) { /* ignore logging errors */ }
}

// enblish explaination of the result
/**
 * Summarize SpeechAce response into a high-level Simplified Chinese report.
 *
 * @param array|string $input  SpeechAce结果：
 *   - 直接传入 sa_raw 的 JSON 字符串，或
 *   - 传入解码后的数组，或
 *   - 传入外层对象，包含 'sa_raw'（字符串）字段
 * @return string  中文总结（不含HTML）
 */
function summarizeSpeechAce($input): string
{
    // --- 1) 解析输入（支持多种形态） ---
    $data = null;
    if (is_string($input)) {
        $maybe = json_decode($input, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            // 如果是外层包裹，尝试读取 sa_raw
            if (isset($maybe['sa_raw']) && is_string($maybe['sa_raw'])) {
                $inner = json_decode($maybe['sa_raw'], true);
                if (json_last_error() === JSON_ERROR_NONE) $data = $inner;
            } else {
                $data = $maybe;
            }
        }
    } elseif (is_array($input)) {
        if (isset($input['sa_raw']) && is_string($input['sa_raw'])) {
            $inner = json_decode($input['sa_raw'], true);
            if (json_last_error() === JSON_ERROR_NONE) $data = $inner;
        } else {
            $data = $input;
        }
    }

    if (!$data || !is_array($data)) {
        return "无法解析评测结果。请确认输入为 SpeechAce 的 JSON 或已解码数组。";
    }

    // --- 2) 便捷取值函数 ---
    $get = function ($arr, $path, $default = null) {
        $cur = $arr;
        foreach (explode('.', $path) as $seg) {
            if (is_array($cur) && array_key_exists($seg, $cur)) {
                $cur = $cur[$seg];
            } else {
                return $default;
            }
        }
        return $cur;
    };

    // --- 3) 提取关键汇总分 ---
    $text                 = $get($data, 'text_score.text', '');
    $saPron               = $get($data, 'speechace_score.pronunciation');
    $saFlu                = $get($data, 'speechace_score.fluency');
    $ieltsPron            = $get($data, 'ielts_score.pronunciation');
    $ieltsFlu             = $get($data, 'ielts_score.fluency');
    $ptePron              = $get($data, 'pte_score.pronunciation');
    $pteFlu               = $get($data, 'pte_score.fluency');
    $cefrPron             = $get($data, 'cefr_score.pronunciation');
    $cefrFlu              = $get($data, 'cefr_score.fluency');

    // --- 4) 流利度度量（若有 segment 或 overall） ---
    $overall = $get($data, 'fluency.overall_metrics', $get($data, 'fluency.segment_metrics_list.0', []));
    $durationSec          = $get($overall, 'duration', null);
    $syllableCount        = $get($overall, 'syllable_count', null);
    $speechRateSyllPerSec = $get($overall, 'speech_rate', null);          // 音节/秒
    $articRate            = $get($overall, 'articulation_rate', null);     // 发音速率（去停顿）
    $pauseCount           = $get($overall, 'all_pause_count', null);
    $pauseDur             = $get($overall, 'all_pause_duration', null);
    $mlr                  = $get($overall, 'mean_length_run', null);       // 平均连续语段长度（秒）
    $maxRun               = $get($overall, 'max_length_run', null);

    // --- 5) 词级与音素级薄弱点 ---
    $wordList             = $get($data, 'text_score.word_score_list', []);
    $weakWords = [];
    $phonesIssues = [];

    // 简易映射：音素 -> 中文提示（用于给建议）
    $phoneHints = [
        'th' => '齿擦音 /θ, ð/（舌尖轻触上齿背）',
        'dh' => '浊 th /ð/（如 “the”）',
        't'  => '清齿龈塞音 /t/（送气更清晰）',
        'd'  => '浊齿龈塞音 /d/（声带震动）',
        's'  => '清擦音 /s/（保持气流）',
        'z'  => '浊擦音 /z/（轻微嗡鸣）',
        'sh' => '卷舌擦音 /ʃ/（如 “ship”）',
        'zh' => '卷舌浊擦音 /ʒ/（如 “measure”）',
        'r'  => '卷舌近音 /r/（舌尖后卷不触碰上颚）',
        'l'  => '边近音 /l/（舌尖顶上齿龈）',
        'ae' => '短元音 /æ/（张口扁平，如 “cat”）',
        'ah' => '央元音 /ʌ/（放松短促，如 “cup”）',
        'ao' => '后圆唇 /ɔː/ 或 /ɑː/（后舌圆唇）',
        'er' => '弱读/卷舌 /ər/（美式 r-colored）',
        'iy' => '长元音 /iː/（如 “see”）',
        'ih' => '短元音 /ɪ/（如 “sit”）',
        'ey' => '双元音 /eɪ/（如 “say”）',
        'ay' => '双元音 /aɪ/（如 “side”）',
        'ow' => '双元音 /oʊ/（如 “go”）',
        'uw' => '长元音 /uː/（如 “you”）',
        'v'  => '唇齿浊擦音 /v/（上齿轻触下唇）',
        'p'  => '清双唇塞音 /p/（送气）',
        'k'  => '清软腭塞音 /k/（收敛后舌）',
        'g'  => '浊软腭塞音 /g/',
        'hh' => '声门擦音 /h/',
        'm'  => '双唇鼻音 /m/',
        'n'  => '齿龈鼻音 /n/',
    ];

    $stressMismatches = 0;
    $totalWords = 0;
    foreach ($wordList as $w) {
        $totalWords++;
        $wText  = isset($w['word']) ? $w['word'] : '';
        $wScore = isset($w['quality_score']) ? floatval($w['quality_score']) : null;

        if ($wScore !== null && $wScore < 75) {
            $weakWords[] = ['word' => $wText, 'score' => $wScore];
        }

        // 统计重音预测 vs 实际（如果字段存在且不一致）
        if (isset($w['phone_score_list'])) {
            foreach ($w['phone_score_list'] as $ph) {
                if (isset($ph['stress_level'], $ph['predicted_stress_level'])) {
                    $exp = $ph['stress_level'];
                    $pred = $ph['predicted_stress_level'];
                    if ($exp !== null && $pred !== null && $exp !== $pred) {
                        $stressMismatches++;
                    }
                }
                // 收集低分音素
                if (isset($ph['phone'], $ph['quality_score'])) {
                    $p = strtolower($ph['phone']);
                    $qs = floatval($ph['quality_score']);
                    if ($qs < 70) {
                        $phonesIssues[$p] = isset($phonesIssues[$p]) ? min($phonesIssues[$p], $qs) : $qs;
                    }
                }
                // 子音素（child_phones）也观察
                if (isset($ph['child_phones']) && is_array($ph['child_phones'])) {
                    foreach ($ph['child_phones'] as $cp) {
                        if (isset($cp['quality_score'], $cp['sound_most_like'])) {
                            $p = strtolower($cp['sound_most_like']);
                            $qs = floatval($cp['quality_score']);
                            if ($qs < 70 && $p) {
                                $phonesIssues[$p] = isset($phonesIssues[$p]) ? min($phonesIssues[$p], $qs) : $qs;
                            }
                        }
                    }
                }
            }
        }
    }

    // 取若干最弱词
    usort($weakWords, function($a, $b){ return $a['score'] <=> $b['score']; });
    $weakWordSamples = array_slice($weakWords, 0, 5);
    $weakWordStr = '';
    if ($weakWordSamples) {
        $tmp = array_map(function($x){
            return $x['word'] . '('.round($x['score']).')';
        }, $weakWordSamples);
        $weakWordStr = implode('，', $tmp);
    }

    // 取若干最弱音素
    asort($phonesIssues);
    $weakPhones = array_slice($phonesIssues, 0, 6, true);
    $weakPhoneStr = '';
    if ($weakPhones) {
        $tmp = [];
        foreach ($weakPhones as $p => $sc) {
            $hint = $phoneHints[$p] ?? null;
            $tmp[] = $p . '≈' . round($sc) . ($hint ? ('：'.$hint) : '');
        }
        $weakPhoneStr = implode('；', $tmp);
    }

    // --- 6) 依据阈值生成建议 ---
    // 速率基准（经验值）：清晰度友好的音节速率 ~ 3.0–4.5 音节/秒
    $advice = [];

    if ($speechRateSyllPerSec !== null) {
        if ($speechRateSyllPerSec > 4.8) {
            $advice[] = "语速偏快，建议降低到每秒约 3–4.5 个音节，确保重读与连读更清晰。";
        } elseif ($speechRateSyllPerSec < 2.8) {
            $advice[] = "语速偏慢，尝试提升连贯度（缩短不必要停顿），保持自然流。";
        }
    }

    if ($pauseCount !== null && $pauseDur !== null) {
        if ($pauseCount >= 5 || $pauseDur > 0.6) {
            $advice[] = "停顿略多/略长，建议在词组边界做短停顿，在词内保持连贯。";
        }
    }

    if ($mlr !== null && $mlr < 1.0) {
        $advice[] = "平均连续语段较短，尝试把“功能词+实义词”合成更长的语块来输出。";
    }

    if (!empty($weakPhones)) {
        $advice[] = "针对低分音素进行最小对立练习（minimal pairs），并跟读慢速范例，聚焦口型与气流控制：".$weakPhoneStr;
    }

    if (!empty($weakWordStr)) {
        $advice[] = "集中攻克低分单词（重读与元音质量）：".$weakWordStr;
    }

    if ($stressMismatches > max(1, intval($totalWords * 0.2))) {
        $advice[] = "部分单词重音位置与预测不一致，建议先听美式/英式标准重音，再配合节奏标记练习。";
    }

    if ($cefrPron && $cefrFlu) {
        // 若发音已C级而流利度仅B级，给组合建议
        $levels = ['A1'=>1,'A2'=>2,'B1'=>3,'B2'=>4,'C1'=>5,'C2'=>6];
        $lp = $levels[$cefrPron] ?? null;
        $lf = $levels[$cefrFlu] ?? null;
        if ($lp !== null && $lf !== null && $lp - $lf >= 2) {
            $advice[] = "发音水平明显高于流利度，建议用“影子跟读 + 定时朗读”提高节奏与连续输出能力。";
        }
    }

    // --- 7) 组织中文报告 ---
    $lines = [];

    // 7.1 标题与原句
    if ($text) {
        $lines[] = "【评测句子】".$text;
    }

    // 7.2 总体水平
    $overallParts = [];
    if ($saPron !== null || $saFlu !== null) {
        $tmp = [];
        if ($saPron !== null) $tmp[] = "发音 ".$saPron;
        if ($saFlu  !== null) $tmp[] = "流利度 ".$saFlu;
        $overallParts[] = "SpeechAce：".implode("，", $tmp);
    }
    if ($ieltsPron !== null || $ieltsFlu !== null) {
        $tmp = [];
        if ($ieltsPron !== null) $tmp[] = "发音 ".$ieltsPron;
        if ($ieltsFlu  !== null) $tmp[] = "流利度 ".$ieltsFlu;
        $overallParts[] = "IELTS(估算)：".implode("，", $tmp);
    }
    if ($ptePron !== null || $pteFlu !== null) {
        $tmp = [];
        if ($ptePron !== null) $tmp[] = "发音 ".$ptePron;
        if ($pteFlu  !== null) $tmp[] = "流利度 ".$pteFlu;
        $overallParts[] = "PTE(估算)：".implode("，", $tmp);
    }
    if ($cefrPron || $cefrFlu) {
        $tmp = [];
        if ($cefrPron) $tmp[] = "发音 ".$cefrPron;
        if ($cefrFlu)  $tmp[] = "流利度 ".$cefrFlu;
        $overallParts[] = "CEFR(估算)：".implode("，", $tmp);
    }
    if ($overallParts) {
        $lines[] = "【总体水平】".implode("；", $overallParts);
    }

    // 7.3 流利度与节奏
    $fluParts = [];
    if ($speechRateSyllPerSec !== null) $fluParts[] = "音节速率≈".round($speechRateSyllPerSec,2)." 音节/秒";
    if ($articRate !== null)            $fluParts[] = "发音速率≈".round($articRate,2);
    if ($pauseCount !== null)           $fluParts[] = "停顿次数 ".$pauseCount;
    if ($pauseDur !== null)             $fluParts[] = "总停顿时长≈".round($pauseDur,2)." 秒";
    if ($mlr !== null)                  $fluParts[] = "平均连续语段≈".round($mlr,2)." 秒";
    if ($maxRun !== null)               $fluParts[] = "最长连续≈".round($maxRun,2)." 秒";
    if ($fluParts) {
        $lines[] = "【流利度/节奏】".implode("，", $fluParts);
    }

    // 7.4 主要薄弱点
    $weakParts = [];
    if ($weakWordStr)  $weakParts[] = "低分词：".$weakWordStr;
    if ($weakPhoneStr) $weakParts[] = "低分音素：".$weakPhoneStr;
    if ($stressMismatches > 0) $weakParts[] = "重音疑似不一致次数：".$stressMismatches;
    if ($weakParts) {
        $lines[] = "【主要薄弱点】".implode("；", $weakParts);
    }

    // 7.5 改进建议
    if (!empty($advice)) {
        $lines[] = "【改进建议】";
        foreach ($advice as $a) {
            $lines[] = "· ".$a;
        }
    }

    // 7.6 收尾
    if (!$lines) {
        return "未检测到可用的评测指标。";
    }
    return implode("\n", $lines);
}

$SPEECHACE_KEY  = getenv('SPEECHACE_API_KEY') ?: (defined('SPEECHACE_API_KEY') ? SPEECHACE_API_KEY : '');
$SPEECHACE_BASE = getenv('SPEECHACE_API_URL') ?: (defined('SPEECHACE_API_URL') ? SPEECHACE_API_URL : 'https://api2.speechace.com');
$SPEECHACE_PATH = '/api/scoring/text/v9/json';

function oops($code, $msg, $extra = []) {
  // Log the failure with any context available (user_id, phrase_uid, and error)
  $user_id   = trim($_POST['user_id'] ?? '');
  $phrase_uid= trim($_POST['phrase_uid'] ?? '');
  append_eval_server_log([
    'status'      => 'error',
    'http_code'   => $code,
    'user_id'     => $user_id,
    'phrase_uid'  => $phrase_uid,
    'error'       => $msg,
    'extra'       => $extra,
  ]);
  http_response_code($code);
  echo json_encode(array_merge(['status'=>'error','message'=>$msg], $extra), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  oops(405, 'Use POST multipart/form-data');
}
if (!$SPEECHACE_KEY) {
  oops(500, 'SpeechAce API key not configured');
}
if (empty($_FILES['audio']) || $_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
  oops(400, 'Missing or invalid audio upload');
}
$phrase_uid = trim($_POST['phrase_uid'] ?? '');
$text = trim($_POST['text'] ?? '');
if ($text === '') {
  oops(400, 'Missing required field: text');
}
$dialect = trim($_POST['dialect'] ?? 'en-us');
$user_id = trim($_POST['user_id'] ?? '');
$include_fluency    = (($_POST['include_fluency'] ?? '') === '1') ? '1' : null;
$include_intonation = (($_POST['include_intonation'] ?? '') === '1') ? '1' : null;
$no_mc              = (($_POST['no_mc'] ?? '') === '1') ? '1' : null;
// Optional: include a Simplified Chinese summary paragraph in the compact payload
$include_summary    = (($_POST['include_summary'] ?? '') === '1');
// Optional: control whether to persist a copy of the uploaded audio on server (in-file variable only)
$save_audio_default = $SAVE_AUDIO_DEFAULT;
$save_audio_override = $_POST['save_audio'] ?? null; // '1' or '0' to override per-request
$save_audio = $save_audio_default;
if ($save_audio_override === '1') { $save_audio = true; }
if ($save_audio_override === '0') { $save_audio = false; }

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
  // Log curl failure
  append_eval_server_log([
    'status'      => 'curl_error',
    'user_id'     => $user_id,
    'phrase_uid'  => $phrase_uid,
    'error'       => $err,
  ]);
  oops(502, 'SpeechAce request failed', ['detail'=>$err]);
}
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// Log full SpeechAce response (success or failure)
append_eval_server_log([
  'status'       => 'speechace_response',
  'user_id'      => $user_id,
  'phrase_uid'   => $phrase_uid,
  'sa_http_code' => $code,
  'sa_raw'       => $resp,
]);

// Parse SA JSON
$sa = json_decode($resp, true);
// Defer optional audio save until after eval, so we can use request_id when available
if ($save_audio) {
  try {
    $codeFolder = strtoupper($_SESSION['invite_code'] ?? '');
    $dir = __DIR__ . '/uploads/' . ($codeFolder ?: '_anon') . '/audio';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $ext = strtolower(pathinfo($audioName, PATHINFO_EXTENSION) ?: 'bin');
    $reqId = (is_array($sa) && isset($sa['request_id']) && $sa['request_id']) ? preg_replace('/[^A-Za-z0-9_.-]+/', '_', $sa['request_id']) : null;
    $safeOriginal = preg_replace('/[^A-Za-z0-9_.-]+/', '_', basename($audioName) ?: ('clip.' . $ext));
    $filename = $reqId ? ($reqId . '.' . $ext) : $safeOriginal;
    $dest = $dir . '/' . $filename;
    $copied = @copy($audioTmp, $dest);
    append_eval_server_log([
      'status'     => $copied ? 'audio_saved' : 'audio_save_failed',
      'user_id'    => $user_id,
      'phrase_uid' => $phrase_uid,
      'req_id'     => $reqId,
      'file'       => $dest,
      'bytes'      => $copied ? (@filesize($dest) ?: null) : null,
    ]);
  } catch (\Throwable $e) {
    append_eval_server_log([
      'status'     => 'audio_save_error',
      'user_id'    => $user_id,
      'phrase_uid' => $phrase_uid,
      'error'      => $e->getMessage(),
    ]);
  }
}
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

$pron     = $speechace['pronunciation'] ?? null;
$fluency  = $speechace['fluency'] ?? ($flu['overall_metrics']['speechace_score']['fluency'] ?? null);
// If still missing, try averaging segment-level fluency scores
if (!is_numeric($fluency) && !empty($flu['segment_metrics_list']) && is_array($flu['segment_metrics_list'])) {
  $vals = [];
  foreach ($flu['segment_metrics_list'] as $seg) {
    if (isset($seg['speechace_score']['fluency']) && is_numeric($seg['speechace_score']['fluency'])) {
      $vals[] = (float)$seg['speechace_score']['fluency'];
    }
  }
  if ($vals) {
    $fluency = (int) round(array_sum($vals) / count($vals));
  }
}
// Prefer SA overall when present; otherwise, if both pron and fluency exist, compute a simple average; else fall back to pron
$overall  = $speechace['overall'] ?? (is_numeric($pron) && is_numeric($fluency)
  ? (int) round(0.7 * (float)$pron + 0.3 * (float)$fluency)
  : ($pron ?? null));
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
  'overall'    => $overall,       // SA overall (not just pronunciation)
  'pronunciation' => $pron,        // SA pronunciation component
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

// Optionally attach a Simplified Chinese summary paragraph
if ($include_summary) {
  try {
    $out['summary_zh'] = summarizeSpeechAce($sa);
  } catch (\Throwable $e) {
    // If summarization fails, omit the field and log
    append_eval_server_log([
      'status'     => 'summary_error',
      'user_id'    => $user_id,
      'phrase_uid' => $phrase_uid,
      'error'      => $e->getMessage(),
    ]);
  }
}

$out['status'] = 'ok';

echo json_encode($out, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
