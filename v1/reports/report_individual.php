<?php
/* -----------------------------------------------------------------------
   Per-User Speech Practice Dashboard (Chart.js)
   Usage:
     user_report.php?user_id=LSO32885
     + optional: &excludeZeros=1  &dailyAvg=1  &showScatter=1
   ----------------------------------------------------------------------- */

declare(strict_types=1);

// Uncomment for debugging 500s (disable on prod):
// ini_set('display_errors', '1'); error_reporting(E_ALL);

$CSV_PATH = __DIR__ . '/practice_data.csv';
$requestedUser = '';
$paramUser = isset($_GET['user_id']) ? trim((string)$_GET['user_id']) : '';
$excludeZeros  = isset($_GET['excludeZeros']) && $_GET['excludeZeros'] == '1';
$hasQuery      = !empty($_GET);
$dailyAvg      = isset($_GET['dailyAvg']) ? ($_GET['dailyAvg'] == '1') : (!$hasQuery ? true : false);
$showScatter   = isset($_GET['showScatter']) && $_GET['showScatter'] == '1';
$trendPhrase   = isset($_GET['trend_phrase']) ? trim((string)$_GET['trend_phrase']) : '';

// Access control: only users whose ID begins with "Admin" can view other users' reports
// Others are restricted to their own reports regardless of the requested user_id
session_start();
$sessionUser = isset($_SESSION['user_id']) ? trim((string)$_SESSION['user_id']) : '';
if ($sessionUser === '') {
  http_response_code(403);
  echo "<h1>禁止访问</h1><p>未登录或无法识别用户。</p>";
  exit;
}
$isAdmin = strncmp($sessionUser, 'Admin', 5) === 0;
$requestedUser = $isAdmin ? $paramUser : $sessionUser;

if (!file_exists($CSV_PATH)) {
  http_response_code(404);
  echo "<h1>未找到 practice_data.csv</h1><p>路径：" . htmlspecialchars($CSV_PATH) . "</p>";
  exit;
}

/* ---------- Load all rows ---------- */
$fh = fopen($CSV_PATH, 'r');
if ($fh === false) {
  http_response_code(500);
  echo "<h1>无法打开数据文件</h1>";
  exit;
}

$header = fgetcsv($fh);
if ($header === false) {
  fclose($fh);
  echo "<h1>数据文件为空</h1>";
  exit;
}
$allRows = [];
while (($r = fgetcsv($fh)) !== false) {
  if (count($r) !== count($header)) {
    // skip malformed line
    continue;
  }
  $row = array_combine($header, $r);
  if (!$row) continue;

  // normalize
  $row['ts'] = $row['ts'] ?? null;
  $row['user_id'] = $row['user_id'] ?? 'unknown';
  $row['phrase_uid'] = $row['phrase_uid'] ?? 'unknown';
  $row['pron'] = isset($row['pron']) && $row['pron'] !== '' ? (float)$row['pron'] : null;
  $row['flu']  = isset($row['flu'])  && $row['flu']  !== '' ? (float)$row['flu']  : null;

  if ($excludeZeros) {
    if (($row['pron'] !== null && $row['pron'] <= 0) || ($row['flu'] !== null && $row['flu'] <= 0)) {
      continue;
    }
  }

  // skip rows missing both scores
  if ($row['pron'] === null && $row['flu'] === null) continue;

  $allRows[] = $row;
}
fclose($fh);

if (empty($allRows)) {
  echo "<h1>没有可用数据。</h1>";
  exit;
}

/* ---------- Build user list for dropdown ---------- */
$allUsers = [];
foreach ($allRows as $r) $allUsers[$r['user_id']] = true;
$allUsers = array_keys($allUsers);
sort($allUsers, SORT_NATURAL);

/* ---------- Admin default behavior: if no/invalid user, use own ID (no picker) ---------- */
if ($isAdmin && ($requestedUser === '' || !in_array($requestedUser, $allUsers, true))) {
  $requestedUser = $sessionUser;
}

/* ---------- Filter rows to this user ---------- */
$rows = array_values(array_filter($allRows, function($r) use ($requestedUser) {
  return $r['user_id'] === $requestedUser;
}));

if (empty($rows)) {
  if ($isAdmin) {
    echo "<h1>未找到该用户的练习记录：" . htmlspecialchars($requestedUser) . "</h1>";
  } else {
    echo "<h1>未找到您的练习记录。</h1>";
  }
  exit;
}

/* ---------- Helpers ---------- */
function safe_avg(float $sum,int $n): float { return $n>0 ? $sum/$n : 0.0; }

/* ---------- KPIs & per-phrase ---------- */
$sumPron=0.0;$nPron=0;$sumFlu=0.0;$nFlu=0;
$phrasesSet=[]; $phraseAgg=[]; // phrase => [sumPron,nPron,sumFlu,nFlu]

foreach ($rows as $r) {
  $phrasesSet[$r['phrase_uid']] = true;
  if ($r['pron'] !== null){ $sumPron += (float)$r['pron']; $nPron++; }
  if ($r['flu']  !== null){ $sumFlu  += (float)$r['flu'];  $nFlu++;  }

  $p = $r['phrase_uid'];
  if (!isset($phraseAgg[$p])) $phraseAgg[$p] = [0.0,0,0.0,0];
  if ($r['pron'] !== null){ $phraseAgg[$p][0] += (float)$r['pron']; $phraseAgg[$p][1]++; }
  if ($r['flu']  !== null){ $phraseAgg[$p][2] += (float)$r['flu'];  $phraseAgg[$p][3]++; }
}
$avgPron = safe_avg($sumPron,$nPron);
$avgFlu  = safe_avg($sumFlu,$nFlu);
$uniquePhrases = count($phrasesSet);

/* ---------- Per-phrase series ---------- */
$phraseSeries = [];
foreach ($phraseAgg as $p=>$a){
  $phraseSeries[] = [
    'phrase'=>$p,
    'pron'=>round(safe_avg((float)$a[0],(int)$a[1]),2),
    'flu'=> round(safe_avg((float)$a[2],(int)$a[3]),2),
  ];
}
usort($phraseSeries, function($a,$b){ return $b['pron'] <=> $a['pron']; });
// Validate trend phrase after we know all phrases
$validTrendPhrase = ($trendPhrase !== '' && isset($phrasesSet[$trendPhrase]));

/* ---------- Trend data ---------- */
// sort by timestamp first
usort($rows, function($a,$b){
  $ta = $a['ts'] ? strtotime($a['ts']) : 0;
  $tb = $b['ts'] ? strtotime($b['ts']) : 0;
  return $ta <=> $tb;
});

$trendTs = [];
$trendPron = [];
$trendFlu  = [];

// Filter rows for trend if a specific phrase is selected
$rowsForTrend = $validTrendPhrase
  ? array_values(array_filter($rows, function($r) use ($trendPhrase){ return $r['phrase_uid'] === $trendPhrase; }))
  : $rows;

if ($dailyAvg) {
  // aggregate by day
  $byDay = []; // 'YYYY-MM-DD' => [sumP, nP, sumF, nF]
  foreach ($rowsForTrend as $r) {
    if (!$r['ts']) continue;
    $d = date('Y-m-d', strtotime($r['ts']));
    if (!isset($byDay[$d])) $byDay[$d] = [0.0,0,0.0,0];
    if ($r['pron'] !== null){ $byDay[$d][0] += (float)$r['pron']; $byDay[$d][1]++; }
    if ($r['flu']  !== null){ $byDay[$d][2] += (float)$r['flu'];  $byDay[$d][3]++; }
  }
  ksort($byDay);
  foreach ($byDay as $d => $v) {
    // Use epoch milliseconds to avoid Safari date parsing issues
    $trendTs[]   = strtotime($d . ' 00:00:00') * 1000;
    $trendPron[] = round(safe_avg((float)$v[0], (int)$v[1]), 2);
    $trendFlu[]  = round(safe_avg((float)$v[2], (int)$v[3]), 2);
  }
} else {
  foreach ($rowsForTrend as $r) {
    // Use epoch milliseconds for full timestamps as well
    $trendTs[]   = $r['ts'] ? (strtotime($r['ts']) * 1000) : null;
    $trendPron[] = $r['pron'];
    $trendFlu[]  = $r['flu'];
  }
}

/* ---------- Scatter data (optional) ---------- */
$scatter = [];
if ($showScatter) {
  foreach ($rows as $r) {
    if ($r['pron'] === null || $r['flu'] === null) continue;
    $x = (float)$r['pron']; $y = (float)$r['flu'];
    if (is_finite($x) && is_finite($y)) $scatter[] = [$x, $y];
  }
}

/* ---------- JSON for front-end ---------- */
$data = [
  'user'      => $requestedUser,
  'overall'   => [
    'attempts' => count($rows),
    'phrases'  => $uniquePhrases,
    'avgPron'  => round($avgPron, 2),
    'avgFlu'   => round($avgFlu,  2),
    'excludeZeros' => $excludeZeros ? 1 : 0,
    'dailyAvg'     => $dailyAvg ? 1 : 0,
    'showScatter'  => $showScatter ? 1 : 0,
  ],
  'perPhrase' => array_values($phraseSeries),
  'trend'     => [
    'ts'   => $trendTs,
    'pron' => $trendPron,
    'flu'  => $trendFlu,
  ],
  'scatter'   => $scatter,
  'allUsers'  => $allUsers,
];

?>
<!doctype html>
<html lang="zh-Hans">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>个人报告 — <?= htmlspecialchars($requestedUser) ?></title>
<link rel="preconnect" href="https://cdn.jsdelivr.net" />
<style>
  :root { --fg:#111; --muted:#666; --card:#fff; --bg:#fafafa; }
  html,body{margin:0;padding:0;background:var(--bg);color:var(--fg);font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif}
  .wrap{max-width:1100px;margin:24px auto;padding:0 16px}
  .kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:16px}
  .card{background:var(--card);border-radius:16px;box-shadow:0 6px 20px rgba(0,0,0,.06);padding:16px}
  .kpi h3{margin:0;font-size:14px;color:var(--muted)}
  .kpi p{margin:4px 0 0;font-size:28px;font-weight:700}
  .row{display:grid;grid-template-columns:1fr;gap:16px}
  @media (min-width: 960px){ .row{grid-template-columns:1fr 1fr} }
  canvas{width:100% !important;display:block;min-height:260px}
  @media (min-width: 960px){ canvas{min-height:320px} }
  .toolbar{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:12px 0}
  .toolbar a, .toolbar button{padding:6px 10px;border-radius:10px;background:#eee;text-decoration:none;color:#333;border:0;cursor:pointer}
  .toolbar a.active{background:#111;color:#fff}
  select{padding:6px 10px;border-radius:10px;border:1px solid #ddd}
</style>
</head>
<body>
<div class="wrap">
  <h1 style="margin:6px 0 8px;">个人报告 — <?= htmlspecialchars($requestedUser) ?></h1>

  <div class="toolbar">
    <form method="get" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <label>用户：</label>
      <?php $toolbarUsers = $isAdmin ? $data['allUsers'] : [$sessionUser]; ?>
      <select name="user_id" onchange="this.form.submit()">
        <?php foreach ($toolbarUsers as $u): ?>
          <option value="<?= htmlspecialchars($u) ?>" <?= $u===$requestedUser?'selected':'' ?>><?= htmlspecialchars($u) ?></option>
        <?php endforeach; ?>
      </select>
      <label><input type="checkbox" name="excludeZeros" value="1" <?= $excludeZeros?'checked':'' ?> onchange="this.form.submit()"> 排除0分</label>
      <!-- Hidden fallback ensures dailyAvg is present even when unchecked -->
      <input type="hidden" name="dailyAvg" value="0" />
      <label><input type="checkbox" name="dailyAvg" value="1" <?= $dailyAvg?'checked':'' ?> onchange="this.form.submit()"> 按日平均</label>
      <label><input type="checkbox" name="showScatter" value="1" <?= $showScatter?'checked':'' ?> onchange="this.form.submit()"> 显示散点图</label>
      <?php $allPhrases = array_keys($phrasesSet); sort($allPhrases, SORT_NATURAL); ?>
      <label>趋势范围：</label>
      <select name="trend_phrase" onchange="this.form.submit()">
        <option value="" <?= $validTrendPhrase? '' : 'selected' ?>>全部句子</option>
        <?php foreach ($allPhrases as $ph): ?>
          <option value="<?= htmlspecialchars($ph) ?>" <?= ($validTrendPhrase && $ph===$trendPhrase)?'selected':'' ?>><?= htmlspecialchars($ph) ?></option>
        <?php endforeach; ?>
      </select>
      <noscript><button type="submit">Apply</button></noscript>
    </form>
  </div>

  <div class="kpis">
  <div class="card kpi"><h3>总尝试次数</h3><p id="k_attempts">-</p></div>
  <div class="card kpi"><h3>句子数</h3><p id="k_phrases">-</p></div>
  <div class="card kpi"><h3>平均发音</h3><p id="k_pron">-</p></div>
  <div class="card kpi"><h3>平均流畅度</h3><p id="k_flu">-</p></div>
  </div>

  <div class="row">
  <div class="card"><h3>各句子平均</h3><canvas id="chartPhrases"></canvas></div>
  <div class="card"><h3>趋势（按时间）</h3><canvas id="chartTrend"></canvas></div>
  </div>

  <?php if ($showScatter): ?>
  <div class="row" style="margin-top:16px">
  <div class="card"><h3>发音 vs 流畅度</h3><canvas id="chartScatter"></canvas></div>
  </div>
  <?php endif; ?>

  <p style="color:#999;margin-top:20px">数据文件：<code><?= htmlspecialchars($CSV_PATH) ?></code> • 视图：
    <?= $excludeZeros? '排除0分' : '全部' ?>
    <?= $dailyAvg? ' • 按日平均' : '' ?>
    <?= $showScatter? ' • 散点图开启' : '' ?>
  </p>
</div>

<!-- Robust loader: try multiple CDNs for Chart.js and the adapter, then render charts -->
<script>
const DATA = <?= json_encode($data, JSON_UNESCAPED_UNICODE) ?>;

// Render KPIs immediately (no dependency on Chart.js)
document.getElementById('k_attempts').textContent = DATA.overall.attempts;
document.getElementById('k_phrases').textContent  = DATA.overall.phrases;
document.getElementById('k_pron').textContent     = Number(DATA.overall.avgPron).toFixed(1);
document.getElementById('k_flu').textContent      = Number(DATA.overall.avgFlu).toFixed(1);

function loadScriptSequential(urls) {
  return new Promise((resolve, reject) => {
    let i = 0;
    const next = () => {
      if (i >= urls.length) return reject(new Error('All script sources failed'));
      const src = urls[i++];
      const s = document.createElement('script');
      s.src = src; s.async = true; s.onload = () => resolve(src); s.onerror = next;
      document.head.appendChild(s);
    };
    next();
  });
}

async function ensureChartsLoaded() {
  const chartURLs = [
    // Prefer local vendored copies if present
    'v1/assets/js/chart.umd.min.js',
    'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js',
    'https://unpkg.com/chart.js@4.4.1/dist/chart.umd.min.js',
    'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js'
  ];
  const adapterURLs = [
    'v1/assets/js/chartjs-adapter-date-fns.min.js',
    'https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns',
    'https://unpkg.com/chartjs-adapter-date-fns@3.0.0/dist/chartjs-adapter-date-fns.min.js',
    'https://cdnjs.cloudflare.com/ajax/libs/chartjs-adapter-date-fns/3.0.0/chartjs-adapter-date-fns.min.js'
  ];
  if (typeof window.Chart === 'undefined') {
    await loadScriptSequential(chartURLs);
  }
  // Adapter is needed for time scale; safe to attempt even if already present
  await loadScriptSequential(adapterURLs).catch(() => {});
}

function showLoadError() {
  // Fallback: render simple tables so the user still sees data
  renderPerPhraseFallback();
  renderTrendFallback();
  <?php if ($showScatter): ?>
  // optional: could add scatter fallback later
  <?php endif; ?>
}

function renderPerPhraseFallback(){
  const host = document.getElementById('chartPhrases');
  if (!host) return;
  const wrap = document.createElement('div');
  wrap.innerHTML = `<div style="padding:12px 0 4px;color:#b91c1c">图表库加载失败，显示表格替代。</div>`;
  const tbl = document.createElement('table');
  tbl.style.width = '100%';
  tbl.style.borderCollapse = 'collapse';
  tbl.innerHTML = `<thead><tr><th style="text-align:left;padding:6px;border-bottom:1px solid #eee">句子</th><th style="text-align:right;padding:6px;border-bottom:1px solid #eee">发音</th><th style="text-align:right;padding:6px;border-bottom:1px solid #eee">流畅</th></tr></thead>`;
  const tb = document.createElement('tbody');
  (DATA.perPhrase || []).forEach(r => {
    const tr = document.createElement('tr');
    tr.innerHTML = `<td style="padding:6px;border-bottom:1px solid #f2f2f2">${r.phrase}</td>
                    <td style="padding:6px;border-bottom:1px solid #f2f2f2;text-align:right">${Number(r.pron).toFixed(1)}</td>
                    <td style="padding:6px;border-bottom:1px solid #f2f2f2;text-align:right">${Number(r.flu).toFixed(1)}</td>`;
    tb.appendChild(tr);
  });
  tbl.appendChild(tb);
  wrap.appendChild(tbl);
  host.replaceWith(wrap);
}

function renderTrendFallback(){
  const host = document.getElementById('chartTrend');
  if (!host) return;
  const wrap = document.createElement('div');
  wrap.innerHTML = `<div style="padding:12px 0 4px;color:#b91c1c">图表库加载失败，显示表格替代。</div>`;
  const tbl = document.createElement('table');
  tbl.style.width = '100%';
  tbl.style.borderCollapse = 'collapse';
  tbl.innerHTML = `<thead><tr><th style="text-align:left;padding:6px;border-bottom:1px solid #eee">日期</th><th style="text-align:right;padding:6px;border-bottom:1px solid #eee">发音</th><th style="text-align:right;padding:6px;border-bottom:1px solid #eee">流畅</th></tr></thead>`;
  const tb = document.createElement('tbody');
  for (let i=0;i<(DATA.trend.ts||[]).length;i++){
    const t = DATA.trend.ts[i];
    const p = DATA.trend.pron[i];
    const f = DATA.trend.flu[i];
    const dt = t!=null ? new Date(Number(t)) : null;
    const ds = dt ? dt.toISOString().slice(0,10) : '';
    const tr = document.createElement('tr');
    tr.innerHTML = `<td style="padding:6px;border-bottom:1px solid #f2f2f2">${ds}</td>
                    <td style="padding:6px;border-bottom:1px solid #f2f2f2;text-align:right">${(typeof p==='number'&&isFinite(p))?p.toFixed(1):''}</td>
                    <td style="padding:6px;border-bottom:1px solid #f2f2f2;text-align:right">${(typeof f==='number'&&isFinite(f))?f.toFixed(1):''}</td>`;
    tb.appendChild(tr);
  }
  tbl.appendChild(tb);
  wrap.appendChild(tbl);
  host.replaceWith(wrap);
}

async function initCharts(){
// KPIs
document.getElementById('k_attempts').textContent = DATA.overall.attempts;
document.getElementById('k_phrases').textContent  = DATA.overall.phrases;
document.getElementById('k_pron').textContent     = DATA.overall.avgPron.toFixed(1);
document.getElementById('k_flu').textContent      = DATA.overall.avgFlu.toFixed(1);

// Colors
const palette = {
  pron: 'rgba(53, 162, 235, 0.9)',
  flu:  'rgba(75, 192, 192, 0.9)',
  grid: 'rgba(0,0,0,.08)'
};

// Bar: per-phrase
(() => {
  const labels = DATA.perPhrase.map(x => x.phrase);
  const pron   = DATA.perPhrase.map(x => x.pron);
  const flu    = DATA.perPhrase.map(x => x.flu);
  new Chart(document.getElementById('chartPhrases'), {
    type: 'bar',
    data: {
      labels,
      datasets: [
        {label: 'Pronunciation', data: pron, borderWidth: 0, backgroundColor: palette.pron},
        {label: 'Fluency',       data: flu,  borderWidth: 0, backgroundColor: palette.flu}
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      scales: {
        y: {beginAtZero: true, max: 100, grid: {color: palette.grid}},
        x: {grid: {display:false}}
      }
    }
  });
})();

// Line: trend (date labels)
(() => {
  const pronPts = [];
  const fluPts  = [];
  for (let i = 0; i < DATA.trend.ts.length; i++) {
    const t = DATA.trend.ts[i];
    const p = DATA.trend.pron[i];
    const f = DATA.trend.flu[i];
    // t is epoch milliseconds; guard nulls
    if (t != null) {
      const dt = new Date(Number(t));
      if (typeof p === 'number' && Number.isFinite(p)) pronPts.push({ x: dt, y: p });
      if (typeof f === 'number' && Number.isFinite(f)) fluPts.push({ x: dt, y: f });
    }
  }

  console.log('trend points', {pron: pronPts.length, flu: fluPts.length});
  new Chart(document.getElementById('chartTrend'), {
    type: 'line',
    data: {
      datasets: [
        { label: 'Pronunciation', data: pronPts, tension: 0.25, pointRadius: 0, borderWidth: 2, borderColor: palette.pron },
        { label: 'Fluency',       data: fluPts,  tension: 0.25, pointRadius: 0, borderWidth: 2, borderColor: palette.flu  }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'nearest', intersect: false },
      parsing: true,
      spanGaps: true,
      scales: {
        x: {
          type: 'time',
          time: { unit: 'day', tooltipFormat: 'yyyy-MM-dd' },
          grid: { display: false }
        },
        y: { beginAtZero: true, max: 100, grid: { color: palette.grid } }
      }
    }
  });
})();

<?php if ($showScatter): ?>
// Scatter: Pron vs Flu (guarded & lightweight)
(() => {
  const raw = DATA.scatter || [];
  console.log("Scatter count", raw.length);
  const points = raw
    .map(([x,y]) => ({ x: Number(x), y: Number(y) }))
    .filter(pt => Number.isFinite(pt.x) && Number.isFinite(pt.y));

  console.log('scatter points', points.length);
  new Chart(document.getElementById('chartScatter'), {
    type: 'scatter',
    data: { datasets: [{ label: 'Attempts', data: points, pointRadius: 3, pointHoverRadius: 5, backgroundColor: 'rgba(53, 162, 235, 0.8)' }] },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      scales: {
        x: { title:{display:true,text:'Pronunciation'}, min:0, max:100, grid:{color: palette.grid} },
        y: { title:{display:true,text:'Fluency'},       min:0, max:100, grid:{color: palette.grid} }
      }
    }
  });
})();
<?php endif; ?>
}

(async () => {
  try {
    await ensureChartsLoaded();
    await initCharts();
  } catch (e) {
    console.error('Chart load/init failed', e);
    showLoadError();
  }
})();
</script>
</body>
</html>
