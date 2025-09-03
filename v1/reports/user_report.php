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

$requestedUser = isset($_GET['user_id']) ? trim((string)$_GET['user_id']) : '';
$excludeZeros  = isset($_GET['excludeZeros']) && $_GET['excludeZeros'] == '1';
$dailyAvg      = isset($_GET['dailyAvg']) && $_GET['dailyAvg'] == '1';
$showScatter   = isset($_GET['showScatter']) && $_GET['showScatter'] == '1';

if (!file_exists($CSV_PATH)) {
  http_response_code(404);
  echo "<h1>practice_data.csv not found</h1><p>Expected at: " . htmlspecialchars($CSV_PATH) . "</p>";
  exit;
}

/* ---------- Load all rows ---------- */
$fh = fopen($CSV_PATH, 'r');
if ($fh === false) {
  http_response_code(500);
  echo "<h1>Failed to open CSV</h1>";
  exit;
}

$header = fgetcsv($fh);
if ($header === false) {
  fclose($fh);
  echo "<h1>Empty CSV</h1>";
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
  echo "<h1>No data rows after filtering.</h1>";
  exit;
}

/* ---------- Build user list for dropdown ---------- */
$allUsers = [];
foreach ($allRows as $r) $allUsers[$r['user_id']] = true;
$allUsers = array_keys($allUsers);
sort($allUsers, SORT_NATURAL);

/* ---------- If no/invalid user specified, show picker ---------- */
if ($requestedUser === '' || !in_array($requestedUser, $allUsers, true)) {
  $base = strtok($_SERVER["REQUEST_URI"],'?');
  ?>
  <!doctype html>
  <html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Select User • Speech Practice</title>
    <style>
      html,body{margin:0;padding:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;background:#fafafa;color:#111}
      .wrap{max-width:680px;margin:40px auto;padding:0 16px}
      .card{background:#fff;border-radius:16px;box-shadow:0 6px 20px rgba(0,0,0,.06);padding:20px}
      h1{margin:0 0 12px}
      select,button{font-size:16px;padding:10px 12px;border-radius:10px;border:1px solid #ddd}
      .row{display:flex;gap:8px;align-items:center}
      small{color:#666}
    </style>
  </head>
  <body>
    <div class="wrap">
      <div class="card">
        <h1>Select your user</h1>
        <form method="get" action="<?= htmlspecialchars($base) ?>">
          <div class="row">
            <select name="user_id" required>
              <option value="" disabled selected>Choose a user…</option>
              <?php foreach ($allUsers as $u): ?>
                <option value="<?= htmlspecialchars($u) ?>"><?= htmlspecialchars($u) ?></option>
              <?php endforeach; ?>
            </select>
            <button type="submit">Open</button>
          </div>
          <p style="margin-top:12px"><small>Tip: add <code>&excludeZeros=1</code> or <code>&dailyAvg=1</code> in the URL.</small></p>
        </form>
      </div>
    </div>
  </body>
  </html>
  <?php
  exit;
}

/* ---------- Filter rows to this user ---------- */
$rows = array_values(array_filter($allRows, function($r) use ($requestedUser) {
  return $r['user_id'] === $requestedUser;
}));

if (empty($rows)) {
  echo "<h1>No attempts found for user: " . htmlspecialchars($requestedUser) . "</h1>";
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

if ($dailyAvg) {
  // aggregate by day
  $byDay = []; // 'YYYY-MM-DD' => [sumP, nP, sumF, nF]
  foreach ($rows as $r) {
    if (!$r['ts']) continue;
    $d = date('Y-m-d', strtotime($r['ts']));
    if (!isset($byDay[$d])) $byDay[$d] = [0.0,0,0.0,0];
    if ($r['pron'] !== null){ $byDay[$d][0] += (float)$r['pron']; $byDay[$d][1]++; }
    if ($r['flu']  !== null){ $byDay[$d][2] += (float)$r['flu'];  $byDay[$d][3]++; }
  }
  ksort($byDay);
  foreach ($byDay as $d => $v) {
    $trendTs[]   = $d . ' 00:00:00';
    $trendPron[] = round(safe_avg((float)$v[0], (int)$v[1]), 2);
    $trendFlu[]  = round(safe_avg((float)$v[2], (int)$v[3]), 2);
  }
} else {
  foreach ($rows as $r) {
    $trendTs[]   = $r['ts'];
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
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>User Dashboard • <?= htmlspecialchars($requestedUser) ?></title>
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
  canvas{width:100% !important;height:auto !important}
  .toolbar{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:12px 0}
  .toolbar a, .toolbar button{padding:6px 10px;border-radius:10px;background:#eee;text-decoration:none;color:#333;border:0;cursor:pointer}
  .toolbar a.active{background:#111;color:#fff}
  select{padding:6px 10px;border-radius:10px;border:1px solid #ddd}
</style>
</head>
<body>
<div class="wrap">
  <h1 style="margin:6px 0 8px;">User Dashboard — <?= htmlspecialchars($requestedUser) ?></h1>

  <div class="toolbar">
    <form method="get" style="display:flex;gap:8px;align-items:center">
      <label>User:</label>
      <select name="user_id" onchange="this.form.submit()">
        <?php foreach ($data['allUsers'] as $u): ?>
          <option value="<?= htmlspecialchars($u) ?>" <?= $u===$requestedUser?'selected':'' ?>><?= htmlspecialchars($u) ?></option>
        <?php endforeach; ?>
      </select>
      <label><input type="checkbox" name="excludeZeros" value="1" <?= $excludeZeros?'checked':'' ?> onchange="this.form.submit()"> Exclude zeros</label>
      <label><input type="checkbox" name="dailyAvg" value="1" <?= $dailyAvg?'checked':'' ?> onchange="this.form.submit()"> Daily average</label>
      <label><input type="checkbox" name="showScatter" value="1" <?= $showScatter?'checked':'' ?> onchange="this.form.submit()"> Show scatter</label>
      <noscript><button type="submit">Apply</button></noscript>
    </form>
  </div>

  <div class="kpis">
    <div class="card kpi"><h3>Total attempts</h3><p id="k_attempts">-</p></div>
    <div class="card kpi"><h3>Phrases</h3><p id="k_phrases">-</p></div>
    <div class="card kpi"><h3>Avg Pronunciation</h3><p id="k_pron">-</p></div>
    <div class="card kpi"><h3>Avg Fluency</h3><p id="k_flu">-</p></div>
  </div>

  <div class="row">
    <div class="card"><h3>Average per Phrase</h3><canvas id="chartPhrases"></canvas></div>
    <div class="card"><h3>Trend Over Time</h3><canvas id="chartTrend"></canvas></div>
  </div>

  <?php if ($showScatter): ?>
  <div class="row" style="margin-top:16px">
    <div class="card"><h3>Pronunciation vs Fluency</h3><canvas id="chartScatter"></canvas></div>
  </div>
  <?php endif; ?>

  <p style="color:#999;margin-top:20px">Data: <code><?= htmlspecialchars($CSV_PATH) ?></code> • View:
    <?= $excludeZeros? 'Exclude zeros' : 'All' ?>
    <?= $dailyAvg? ' • Daily average' : '' ?>
    <?= $showScatter? ' • Scatter on' : '' ?>
  </p>
</div>

<!-- Chart.js must load before the date adapter -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns"></script>
<script>
const DATA = <?= json_encode($data, JSON_UNESCAPED_UNICODE) ?>;

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
    if (typeof p === 'number' && Number.isFinite(p)) pronPts.push({ x: new Date(t), y: p });
    if (typeof f === 'number' && Number.isFinite(f)) fluPts.push({ x: new Date(t), y: f });
  }

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

  new Chart(document.getElementById('chartScatter'), {
    type: 'scatter',
    data: { datasets: [{ label: 'Attempts', data: points, pointRadius: 3, pointHoverRadius: 5, backgroundColor: 'rgba(53, 162, 235, 0.8)' }] },
    options: {
      responsive: true,
      scales: {
        x: { title:{display:true,text:'Pronunciation'}, min:0, max:100, grid:{color: palette.grid} },
        y: { title:{display:true,text:'Fluency'},       min:0, max:100, grid:{color: palette.grid} }
      }
    }
  });
})();
<?php endif; ?>
</script>
</body>
</html>
