<?php
/* -----------------------------------------------------------------------
   Simple HTML/JS dashboard for practice_data.csv
   - Put this file next to practice_data.csv (or edit $CSV_PATH)
   - Optional: ?excludeZeros=1  (filters pron=0 or flu=0 rows)
   ----------------------------------------------------------------------- */

$CSV_PATH = __DIR__ . '/practice_data.csv';
$excludeZeros = isset($_GET['excludeZeros']) && $_GET['excludeZeros'] == '1';

if (!file_exists($CSV_PATH)) {
  http_response_code(404);
  echo "<h1>practice_data.csv not found</h1><p>Expected at: {$CSV_PATH}</p>";
  exit;
}

/* ---------- Load CSV ---------- */
$fh = fopen($CSV_PATH, 'r');
$header = fgetcsv($fh);
$rows = [];
while (($r = fgetcsv($fh)) !== false) {
  $row = array_combine($header, $r);
  if (!$row) continue;

  // Normalize / cast
  $row['ts']  = isset($row['ts']) ? $row['ts'] : null;
  $row['user_id'] = $row['user_id'] ?? 'unknown';
  $row['phrase_uid'] = $row['phrase_uid'] ?? 'unknown';

  $row['pron'] = isset($row['pron']) ? floatval($row['pron']) : null;
  $row['flu']  = isset($row['flu'])  ? floatval($row['flu'])  : null;

  if ($excludeZeros) {
    if (($row['pron'] !== null && $row['pron'] <= 0) || ($row['flu'] !== null && $row['flu'] <= 0)) {
      continue;
    }
  }

  // skip rows missing both scores
  if ($row['pron'] === null && $row['flu'] === null) continue;

  $rows[] = $row;
}
fclose($fh);

if (empty($rows)) {
  echo "<h1>No data rows after filtering.</h1>";
  exit;
}

/* ---------- Helpers ---------- */
function safe_avg($sum, $n) { return $n > 0 ? $sum / $n : 0; }

/* ---------- Overall stats ---------- */
$totalAttempts = count($rows);
$usersSet = [];
$phrasesSet = [];
$sumPron = 0; $nPron = 0;
$sumFlu  = 0; $nFlu  = 0;

foreach ($rows as $r) {
  $usersSet[$r['user_id']] = true;
  $phrasesSet[$r['phrase_uid']] = true;
  if ($r['pron'] !== null) { $sumPron += $r['pron']; $nPron++; }
  if ($r['flu']  !== null) { $sumFlu  += $r['flu'];  $nFlu++;  }
}
$uniqueUsers   = count($usersSet);
$uniquePhrases = count($phrasesSet);
$avgPron = safe_avg($sumPron, $nPron);
$avgFlu  = safe_avg($sumFlu, $nFlu);

/* ---------- Per-user averages ---------- */
$userAgg = []; // user_id => [sumPron, nPron, sumFlu, nFlu]
foreach ($rows as $r) {
  $u = $r['user_id'];
  if (!isset($userAgg[$u])) $userAgg[$u] = [0,0,0,0];
  if ($r['pron'] !== null) { $userAgg[$u][0] += $r['pron']; $userAgg[$u][1]++; }
  if ($r['flu']  !== null) { $userAgg[$u][2] += $r['flu'];  $userAgg[$u][3]++; }
}
$userSeries = []; // [{user, pron, flu}, ...]
foreach ($userAgg as $u => $a) {
  $userSeries[] = [
    'user' => $u,
    'pron' => round(safe_avg($a[0], $a[1]), 2),
    'flu'  => round(safe_avg($a[2], $a[3]), 2),
  ];
}
// sort by pron desc
usort($userSeries, function($a,$b){ return $b['pron'] <=> $a['pron']; });

/* ---------- Per-phrase averages ---------- */
$phraseAgg = []; // phrase => [sumPron, nPron, sumFlu, nFlu]
foreach ($rows as $r) {
  $p = $r['phrase_uid'];
  if (!isset($phraseAgg[$p])) $phraseAgg[$p] = [0,0,0,0];
  if ($r['pron'] !== null) { $phraseAgg[$p][0] += $r['pron']; $phraseAgg[$p][1]++; }
  if ($r['flu']  !== null) { $phraseAgg[$p][2] += $r['flu'];  $phraseAgg[$p][3]++; }
}
$phraseSeries = []; // [{phrase, pron, flu}, ...]
foreach ($phraseAgg as $p => $a) {
  $phraseSeries[] = [
    'phrase' => $p,
    'pron' => round(safe_avg($a[0], $a[1]), 2),
    'flu'  => round(safe_avg($a[2], $a[3]), 2),
  ];
}
// sort by pron desc
usort($phraseSeries, function($a,$b){ return $b['pron'] <=> $a['pron']; });

/* ---------- Time trend (sorted by ts) ---------- */
usort($rows, function($a,$b){
  return strtotime($a['ts']) <=> strtotime($b['ts']);
});
$trendTs   = [];
$trendPron = [];
$trendFlu  = [];
foreach ($rows as $r) {
  $trendTs[]   = $r['ts'];
  $trendPron[] = $r['pron'] ?? null;
  $trendFlu[]  = $r['flu'] ?? null;
}

/* ---------- Scatter data ---------- */
$scatter = [];
foreach ($rows as $r) {
  if ($r['pron'] === null || $r['flu'] === null) continue;
  $scatter[] = [floatval($r['pron']), floatval($r['flu'])];
}

/* ---------- JSON for front-end ---------- */
$data = [
  'overall' => [
    'attempts' => $totalAttempts,
    'users'    => $uniqueUsers,
    'phrases'  => $uniquePhrases,
    'avgPron'  => round($avgPron, 2),
    'avgFlu'   => round($avgFlu,  2),
    'excludeZeros' => $excludeZeros ? 1 : 0,
  ],
  'perUser'   => array_values($userSeries),
  'perPhrase' => array_values($phraseSeries),
  'trend'     => [
    'ts'   => $trendTs,
    'pron' => $trendPron,
    'flu'  => $trendFlu,
  ],
  'scatter'   => $scatter,
];

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Speech Practice Dashboard</title>
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
  .toolbar{display:flex;gap:8px;align-items:center;margin:12px 0}
  .toolbar a{padding:6px 10px;border-radius:10px;background:#eee;text-decoration:none;color:#333}
  .toolbar a.active{background:#111;color:#fff}
</style>
</head>
<body>
<div class="wrap">
  <h1 style="margin:6px 0 8px;">Speech Practice Dashboard</h1>
  <div class="toolbar">
    <strong>Filter:</strong>
    <?php
      $base = strtok($_SERVER["REQUEST_URI"],'?');
      $linkOn  = htmlspecialchars($base.'?excludeZeros=1');
      $linkOff = htmlspecialchars($base);
      $isOn = $excludeZeros ? 'active' : '';
    ?>
    <a href="<?= $linkOff ?>" class="<?= $excludeZeros?'':'active' ?>">All</a>
    <a href="<?= $linkOn ?>" class="<?= $excludeZeros?'active':'' ?>">Exclude zeros</a>
  </div>

  <div class="kpis">
    <div class="card kpi"><h3>Total attempts</h3><p id="k_attempts">-</p></div>
    <div class="card kpi"><h3>Active users</h3><p id="k_users">-</p></div>
    <div class="card kpi"><h3>Phrases</h3><p id="k_phrases">-</p></div>
    <div class="card kpi"><h3>Avg Pronunciation</h3><p id="k_pron">-</p></div>
    <div class="card kpi"><h3>Avg Fluency</h3><p id="k_flu">-</p></div>
  </div>

  <div class="row">
    <div class="card"><h3>Average per User</h3><canvas id="chartUsers"></canvas></div>
    <div class="card"><h3>Average per Phrase</h3><canvas id="chartPhrases"></canvas></div>
  </div>

  <div class="row" style="margin-top:16px">
    <div class="card"><h3>Trend Over Time</h3><canvas id="chartTrend"></canvas></div>
    <div class="card"><h3>Pronunciation vs Fluency</h3><canvas id="chartScatter"></canvas></div>
  </div>

  <p style="color:#999;margin-top:20px">Data source: <code><?= htmlspecialchars($CSV_PATH) ?></code> • View: <?= $excludeZeros? 'Exclude zeros' : 'All' ?></p>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns"></script>

<script>
const DATA = <?= json_encode($data, JSON_UNESCAPED_UNICODE) ?>;

// KPIs
document.getElementById('k_attempts').textContent = DATA.overall.attempts;
document.getElementById('k_users').textContent    = DATA.overall.users;
document.getElementById('k_phrases').textContent  = DATA.overall.phrases;
document.getElementById('k_pron').textContent     = DATA.overall.avgPron.toFixed(1);
document.getElementById('k_flu').textContent      = DATA.overall.avgFlu.toFixed(1);

// Colors
const palette = {
  pron: 'rgba(53, 162, 235, 0.9)',
  flu:  'rgba(75, 192, 192, 0.9)',
  grid: 'rgba(0,0,0,.08)'
};

// Bar: per-user
(() => {
  const labels = DATA.perUser.map(x => x.user);
  const pron   = DATA.perUser.map(x => x.pron);
  const flu    = DATA.perUser.map(x => x.flu);
  new Chart(document.getElementById('chartUsers'), {
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

// Line: trend
(() => {
  // Build points and drop nulls
  const pronPts = [];
  const fluPts  = [];
  for (let i = 0; i < DATA.trend.ts.length; i++) {
    const t = DATA.trend.ts[i];
    const p = DATA.trend.pron[i];
    const f = DATA.trend.flu[i];
    if (typeof p === 'number' && !isNaN(p)) pronPts.push({ x: new Date(t), y: p });
    if (typeof f === 'number' && !isNaN(f)) fluPts.push({ x: new Date(t), y: f });
  }

  new Chart(document.getElementById('chartTrend'), {
    type: 'line',
    data: {
      datasets: [
        { label: 'Pronunciation', data: pronPts, tension: 0.25, pointRadius: 0, borderWidth: 2 },
        { label: 'Fluency',       data: fluPts,  tension: 0.25, pointRadius: 0, borderWidth: 2 }
      ]
    },
    options: {
      responsive: true,
      interaction: { mode: 'nearest', intersect: false },
      parsing: true, // let Chart.js parse {x,y}
      spanGaps: true,
      scales: {
        x: {
          type: 'time',
          time: { unit: 'day',tooltipFormat: 'yyyy-MM-dd' },
          grid: { display: false }
        },
        y: {
          beginAtZero: true, max: 100,
          grid: { color: 'rgba(0,0,0,.08)' }
        }
      }
    }
  });
})();

// Scatter: Pron vs Flu
/*
(() => {
  // Ensure numbers and drop invalid points
  const points = (DATA.scatter || [])
    .map(([x, y]) => ({ x: Number(x), y: Number(y) }))
    .filter(pt => Number.isFinite(pt.x) && Number.isFinite(pt.y));

  const ctx = document.getElementById('chartScatter');
  new Chart(ctx, {
    type: 'scatter',
    data: {
      datasets: [{
        label: 'Attempts',
        data: points,
        pointRadius: 3,
        pointHoverRadius: 5,
        backgroundColor: 'rgba(53, 162, 235, 0.8)',
        borderColor: 'rgba(53, 162, 235, 0.8)'
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      scales: {
        x: {
          title: { display: true, text: 'Pronunciation' },
          min: 0, max: 100,
          grid: { color: 'rgba(0,0,0,.08)' }
        },
        y: {
          title: { display: true, text: 'Fluency' },
          min: 0, max: 100,
          grid: { color: 'rgba(0,0,0,.08)' }
        }
      }
    }
  });
})();
*/
</script>
</body>
</html>
