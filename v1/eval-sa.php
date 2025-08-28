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
 * Summarize SpeechAce response (array or JSON or wrapper with sa_raw) into a styled HTML report (Simplified Chinese).
 * - Red-highlight any word with quality_score < 60.
 * - Sections: 评测句子, 总体水平, 流利度/节奏, 主要薄弱点, 改进建议.
 *
 * @param array|string $input
 * @return string HTML (includes a small <style> block)
 */
function summarizeSpeechAce($input): string
{
    // 1) Parse input (supports: raw SA JSON, decoded array, or wrapper with 'sa_raw')
    $data = null;
    if (is_string($input)) {
        $maybe = json_decode($input, true);
        if (json_last_error() === JSON_ERROR_NONE) {
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
        return '<div class="sa-report">无法解析评测结果。请确认输入为 SpeechAce 的 JSON 或已解码数组。</div>';
    }

    // 2) helper getter
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

    // 3) extract scores
    $text     = $get($data, 'text_score.text', '');
    $saPron   = $get($data, 'speechace_score.pronunciation');
    $saFlu    = $get($data, 'speechace_score.fluency');
    $ieltsPron= $get($data, 'ielts_score.pronunciation');
    $ieltsFlu = $get($data, 'ielts_score.fluency');
    $ptePron  = $get($data, 'pte_score.pronunciation');
    $pteFlu   = $get($data, 'pte_score.fluency');
    $cefrPron = $get($data, 'cefr_score.pronunciation');
    $cefrFlu  = $get($data, 'cefr_score.fluency');

    // 4) fluency metrics
    $overall  = $get($data, 'fluency.overall_metrics', $get($data, 'fluency.segment_metrics_list.0', []));
    $speechRateSyllPerSec = $get($overall, 'speech_rate', null);
    $articRate            = $get($overall, 'articulation_rate', null);
    $pauseCount           = $get($overall, 'all_pause_count', null);
    $pauseDur             = $get($overall, 'all_pause_duration', null);
    $mlr                  = $get($overall, 'mean_length_run', null);
    $maxRun               = $get($overall, 'max_length_run', null);

    // 5) words & phones
    $wordList = $get($data, 'text_score.word_score_list', []) ?: [];

    $phoneHints = [
        'th'=>'齿擦音 /θ/ 或 /ð/：舌尖轻触上齿背，保持持续气流',
        'dh'=>'浊 th /ð/：声带轻震，如 “the”',
        't' =>'清塞音 /t/：送气更清晰', 'd'=>'浊塞音 /d/：注意声带启动',
        's' =>'清擦音 /s/：保持窄通道、稳定气流', 'z'=>'浊擦音 /z/：带轻微嗡鸣',
        'sh'=>'/ʃ/：卷舌或舌面后缩，如 “ship”', 'zh'=>'/ʒ/：如 “measure” 中间音',
        'r' =>'/r/：舌尖后卷不触上颚', 'l'=>'/l/：舌尖顶上齿龈，尾音更清楚',
        'ae'=>'/æ/：张口扁平，如 “cat”', 'ah'=>'/ʌ/：放松短促，如 “cup”',
        'ao'=>'/ɔː/ 或 /ɑː/：后舌、圆唇', 'er'=>'/ər/（美式卷舌元音）',
        'iy'=>'/iː/：如 “see”', 'ih'=>'/ɪ/：如 “sit”', 'ey'=>'/eɪ/：如 “say”',
        'ay'=>'/aɪ/：如 “side”', 'ow'=>'/oʊ/：如 “go”', 'uw'=>'/uː/：如 “you”',
        'v' =>'上齿轻触下唇并发声', 'p'=>'清双唇塞音 /p/：加强送气',
        'k' =>'清软腭塞音 /k/：后舌顶软腭', 'g'=>'浊软腭塞音 /g/',
        'hh'=>'/h/：轻气流起始', 'm'=>'/m/：双唇闭合鼻腔共鸣', 'n'=>'/n/：舌尖抵上齿龈鼻腔共鸣',
    ];

    $stressMismatches = 0;
    $totalWords = 0;

    $lowWordsMap = [];   // word(lower) => ['word'=>原词, 'score'=>数值, 'phones'=>[...]]
    $phonesIssues = [];  // global low phones <70

    foreach ($wordList as $w) {
        $totalWords++;
        $wText  = $w['word'] ?? '';
        $wScore = isset($w['quality_score']) ? floatval($w['quality_score']) : null;

        if ($wText && $wScore !== null && $wScore < 60) {
            $key = mb_strtolower($wText, 'UTF-8');
            $lowWordsMap[$key] = ['word'=>$wText, 'score'=>$wScore, 'phones'=>[]];
        }

        if (!empty($w['phone_score_list']) && is_array($w['phone_score_list'])) {
            foreach ($w['phone_score_list'] as $ph) {
                if (isset($ph['stress_level'], $ph['predicted_stress_level'])) {
                    $exp = $ph['stress_level'];
                    $pred = $ph['predicted_stress_level'];
                    if ($exp !== null && $pred !== null && $exp !== $pred) {
                        $stressMismatches++;
                    }
                }
                if (isset($ph['phone'], $ph['quality_score'])) {
                    $p  = strtolower($ph['phone']);
                    $qs = floatval($ph['quality_score']);
                    if ($qs < 70) {
                        $phonesIssues[$p] = isset($phonesIssues[$p]) ? min($phonesIssues[$p], $qs) : $qs;
                        $lwKey = mb_strtolower($wText, 'UTF-8');
                        if (isset($lowWordsMap[$lwKey])) {
                            $lowWordsMap[$lwKey]['phones'][$p] =
                                isset($lowWordsMap[$lwKey]['phones'][$p]) ? min($lowWordsMap[$lwKey]['phones'][$p], $qs) : $qs;
                        }
                    }
                }
                if (!empty($ph['child_phones'])) {
                    foreach ($ph['child_phones'] as $cp) {
                        if (isset($cp['quality_score'], $cp['sound_most_like'])) {
                            $p  = strtolower($cp['sound_most_like']);
                            $qs = floatval($cp['quality_score']);
                            if ($qs < 70 && $p) {
                                $phonesIssues[$p] = isset($phonesIssues[$p]) ? min($phonesIssues[$p], $qs) : $qs;
                                $lwKey = mb_strtolower($wText, 'UTF-8');
                                if (isset($lowWordsMap[$lwKey])) {
                                    $lowWordsMap[$lwKey]['phones'][$p] =
                                        isset($lowWordsMap[$lwKey]['phones'][$p]) ? min($lowWordsMap[$lwKey]['phones'][$p], $qs) : $qs;
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    // 6) Mark low words in sentence (HTML, red color). Safe-escape non-word chunks.
    $markLowWordsHtml = function(string $sentence, array $lowMap): string {
        if ($sentence === '') return '';
        // split by words (keep delimiters)
        $parts = preg_split('/([A-Za-z]+(?:\'[A-Za-z]+)?)/u', $sentence, -1, PREG_SPLIT_DELIM_CAPTURE);
        $out = '';
        foreach ($parts as $i => $chunk) {
            if ($chunk === '') continue;
            if ($i % 2 === 1) { // word token
                $lc = mb_strtolower($chunk, 'UTF-8');
                if (isset($lowMap[$lc])) {
                    $score = round($lowMap[$lc]['score']);
                    $out .= '<span class="sa-low" title="得分 '.$score.'">'.$this->e($chunk).'</span>';
                } else {
                    $out .= $this->e($chunk);
                }
            } else {
                $out .= $this->e($chunk);
            }
        }
        return $out;
    };

    // helper escaper as closure property workaround
    $escaper = function($s){ return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); };
    // bind escaper into $markLowWordsHtml
    $markLowWordsHtml = $markLowWordsHtml->bindTo((object)['e'=>$escaper], null);
    $markedSentence = $markLowWordsHtml($text, $lowWordsMap);

    // 7) Advice
    $advice = [];
    if ($speechRateSyllPerSec !== null) {
        if ($speechRateSyllPerSec > 4.8) {
            $advice[] = "语速偏快，建议降至每秒约 3–4.5 个音节，突出重读与语调起伏。";
        } elseif ($speechRateSyllPerSec < 2.8) {
            $advice[] = "语速偏慢，尝试合并词组、减少不必要停顿，提升连贯度。";
        }
    }
    if ($pauseCount !== null && $pauseDur !== null) {
        if ($pauseCount >= 5 || $pauseDur > 0.6) {
            $advice[] = "停顿略多/略长，建议在词组边界轻微停顿，词内保持连读。";
        }
    }
    if ($mlr !== null && $mlr < 1.0) {
        $advice[] = "平均连续语段较短，练习“功能词+实义词”组合输出，延长一次性表达时长。";
    }
    asort($phonesIssues);
    $weakPhones = array_slice($phonesIssues, 0, 6, true);
    if (!empty($weakPhones)) {
        $tmp = [];
        foreach ($weakPhones as $p => $sc) {
            $hint = $phoneHints[$p] ?? null;
            $tmp[] = $escaper($p).'≈'.round($sc).($hint ? ('：'.$escaper($hint)) : '');
        }
        $advice[] = "集中纠正低分音素（最小对立练习 + 跟读）：".implode('；', $tmp);
    }
    if ($stressMismatches > max(1, intval($totalWords * 0.2))) {
        $advice[] = "存在多处重音不一致，建议先确立单词正确重音，再叠加句子节奏与语调。";
    }

    // 8) Build “主要薄弱点” detail for low words
    $weakSections = [];
    if (!empty($lowWordsMap)) {
        $lowList = array_values($lowWordsMap);
        usort($lowList, function($a,$b){ return $a['score'] <=> $b['score']; });
        $lowList = array_slice($lowList, 0, 6);

        $items = [];
        foreach ($lowList as $it) {
            $w = $escaper($it['word']);
            $s = round($it['score']);
            $phoneTips = [];
            if (!empty($it['phones'])) {
                asort($it['phones']);
                $picked = array_slice($it['phones'], 0, 2, true);
                foreach ($picked as $p => $psc) {
                    $tip = $phoneHints[$p] ?? null;
                    $phoneTips[] = $escaper($p).'≈'.round($psc).($tip ? ('（'.$escaper($tip).'）') : '');
                }
            }
            $extra = $phoneTips ? '<div class="sa-sub">关键音素：'.implode('；', $phoneTips).'</div>' : '';
            $items[] = '<li><span class="sa-low">'.$w.'</span><span class="sa-mild">（得分 '.$s.'）</span>'.$extra.'</li>';
        }
        $weakSections[] = '<div class="sa-kicker">低分词（&lt;60，已在句子中红色高亮）</div><ul class="sa-list">'.$items=implode('', $items).'</ul>';
    }
    if (!empty($weakPhones)) {
        $tmp = [];
        foreach ($weakPhones as $p => $sc) {
            $hint = $phoneHints[$p] ?? null;
            $tmp[] = '<li><code>'.$escaper($p).'</code><span class="sa-mild">≈'.round($sc).'</span>'.($hint ? '：'.$escaper($hint) : '').'</li>';
        }
        $weakSections[] = '<div class="sa-kicker">全局低分音素</div><ul class="sa-list">'.$items=implode('', $tmp).'</ul>';
    }
    if ($stressMismatches > 0) {
        $weakSections[] = '<div class="sa-kicker">重音问题</div><div class="sa-note">疑似不一致次数：<b>'.$escaper((string)$stressMismatches).'</b>。建议先确认单词重音，再练习句子节奏。</div>';
    }

    // 9) Overall badges
    $badge = function($label, $p, $f) use ($escaper){
        $parts = [];
        if ($p !== null) $parts[] = '发音 '.$escaper((string)$p);
        if ($f !== null) $parts[] = '流利度 '.$escaper((string)$f);
        return $parts ? '<div class="sa-badge"><span class="sa-badge-title">'.$escaper($label).'</span><span>'.implode('，', $parts).'</span></div>' : '';
    };
    $badges = '';
    $badges .= $badge('SpeechAce', $saPron, $saFlu);
    $badges .= $badge('IELTS(估算)', $ieltsPron, $ieltsFlu);
    $badges .= $badge('PTE(估算)', $ptePron, $pteFlu);
    $badges .= $badge('CEFR(估算)', $cefrPron, $cefrFlu);

    // 10) Fluency chips
    $chips = [];
    if ($speechRateSyllPerSec !== null) $chips[] = '音节速率≈'.round($speechRateSyllPerSec,2).' 音节/秒';
    if ($articRate !== null)            $chips[] = '发音速率≈'.round($articRate,2);
    if ($pauseCount !== null)           $chips[] = '停顿次数 '.$escaper((string)$pauseCount);
    if ($pauseDur !== null)             $chips[] = '总停顿≈'.round($pauseDur,2).' 秒';
    if ($mlr !== null)                  $chips[] = '平均连续≈'.round($mlr,2).' 秒';
    if ($maxRun !== null)               $chips[] = '最长连续≈'.round($maxRun,2).' 秒';

    $chipsHtml = '';
    if ($chips) {
        $c = array_map(function($t){ return '<span class="sa-chip">'.$t.'</span>'; }, $chips);
        $chipsHtml = implode('', $c);
    }

    // 11) Advice list
    $adviceHtml = '';
    if (!empty($advice)) {
        $lis = array_map(function($t) use ($escaper){ return '<li>'.$escaper($t).'</li>'; }, $advice);
        $adviceHtml = '<ul class="sa-list sa-list-dot">'.implode('', $lis).'</ul>';
    }

    // 12) Assemble HTML
    $titleSentence = $text ? '<div class="sa-section"><div class="sa-title">评测句子</div><div class="sa-sentence">'.$markedSentence.'</div><div class="sa-legend">注：<span class="sa-low">红色</span>表示该词得分 &lt; 60，需要重点改进。</div></div>' : '';

    $html =
    '<div class="sa-report">
        <style>
            .sa-report{font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, "Noto Sans SC", "PingFang SC", "Microsoft YaHei", sans-serif; color:#111; line-height:1.6; font-size:14px;}
            .sa-title{font-weight:700; margin:6px 0 6px; font-size:15px;}
            .sa-section{background:#fff; border:1px solid #eee; border-radius:12px; padding:14px 16px; margin:10px 0; box-shadow:0 1px 2px rgba(0,0,0,.03);}
            .sa-sentence{font-size:16px; line-height:1.8; margin-top:4px;}
            .sa-low{color:#e02424; font-weight:700;}
            .sa-legend{color:#666; font-size:12px; margin-top:6px;}
            .sa-badges{display:flex; flex-wrap:wrap; gap:8px; margin-top:6px;}
            .sa-badge{background:#f6f7f9; border:1px solid #eef0f2; padding:8px 10px; border-radius:10px; display:flex; gap:8px; align-items:center;}
            .sa-badge-title{font-weight:600; color:#36454f;}
            .sa-chips{display:flex; flex-wrap:wrap; gap:6px; margin-top:6px;}
            .sa-chip{background:#fafafa; border:1px solid #eee; border-radius:999px; padding:5px 10px; font-size:12px;}
            .sa-kicker{font-weight:600; margin:6px 0 4px; color:#36454f;}
            .sa-list{padding-left:18px; margin:6px 0;}
            .sa-list li{margin:4px 0;}
            .sa-list-dot{list-style:disc;}
            .sa-mild{color:#777;}
            .sa-note{color:#444; font-size:13px; margin-top:4px;}
        </style>
        '.$titleSentence.'

        <div class="sa-section">
            <div class="sa-title">总体水平</div>
            <div class="sa-badges">'.$badges.'</div>
        </div>

        <div class="sa-section">
            <div class="sa-title">流利度 / 节奏</div>
            <div class="sa-chips">'.$chipsHtml.'</div>
        </div>';

    if (!empty($weakSections)) {
        $html .= '<div class="sa-section"><div class="sa-title">主要薄弱点</div>'.implode('', $weakSections).'</div>';
    }

    if ($adviceHtml) {
        $html .= '<div class="sa-section"><div class="sa-title">改进建议</div>'.$adviceHtml.'</div>';
    }

    $html .= '</div>';

    return $html;
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
  $sum = summarizeSpeechAce($sa);
  // Provide multiple keys for robustness
  $out['summary_zh'] = $sum;           // primary
  $out['summary_zh_html'] = $sum;      // explicit HTML key
  $out['summary_html'] = $sum;         // generic HTML key
  // Plain-text fallback for older clients or quick previews
  $plain = trim(preg_replace('/\s+/', ' ', strip_tags($sum)));
  if ($plain !== '') { $out['summary_zh_text'] = $plain; }
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
