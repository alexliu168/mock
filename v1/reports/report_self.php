<?php
/* -----------------------------------------------------------------------
   单用户练习看板（Chart.js，简体中文）
   - 仅显示当前登录用户自己的数据
   - 用户ID 来自 $_SESSION['user_id'] （请根据你的系统调整）
   ----------------------------------------------------------------------- */

declare(strict_types=1);
session_start();

// 假设你的应用把 user_id 存在会话里
// 如果你用 window.SESSION_USER.name 注入前端，也要在后端保持一致
$requestedUser = $_SESSION['user_id'] ?? '';

if ($requestedUser === '') {
  http_response_code(403);
  echo "未登录或无法识别用户。";
  exit;
}

$CSV_PATH   = __DIR__ . '/practice_data.csv';
$excludeZeros = true;   // 默认排除0分
$dailyAvg     = true;   // 默认按日均值
$showScatter  = false;  // 默认不显示散点

if (!file_exists($CSV_PATH)) {
  http_response_code(404);
  echo "未找到数据文件：" . htmlspecialchars($CSV_PATH);
  exit;
}

/* ---------- 加载 CSV ---------- */
$fh = fopen($CSV_PATH, 'r');
$header = fgetcsv($fh);
$rows = [];
while (($r = fgetcsv($fh)) !== false) {
  if (count($r) !== count($header)) continue;
  $row = array_combine($header, $r);
  if (!$row) continue;

  $row['ts'] = $row['ts'] ?? null;
  $row['user_id'] = $row['user_id'] ?? 'unknown';
  $row['phrase_uid'] = $row['phrase_uid'] ?? 'unknown';
  $row['pron'] = ($row['pron'] !== '') ? (float)$row['pron'] : null;
  $row['flu']  = ($row['flu']  !== '') ? (float)$row['flu']  : null;

  if ($row['user_id'] !== $requestedUser) continue;
  if ($excludeZeros && (($row['pron'] ?? 0) <= 0 || ($row['flu'] ?? 0) <= 0)) continue;
  if ($row['pron'] === null && $row['flu'] === null) continue;

  $rows[] = $row;
}
fclose($fh);

if (empty($rows)) {
  echo "没有找到该用户的练习记录：".htmlspecialchars($requestedUser);
  exit;
}

/* ---------- 统计 ---------- */
function safe_avg($sum,$n){ return $n>0 ? $sum/$n : 0; }

$sumPron=0;$nPron=0;$sumFlu=0;$nFlu=0;
$phrasesSet=[];$phraseAgg=[];
foreach ($rows as $r) {
  $phrasesSet[$r['phrase_uid']] = true;
  if ($r['pron']!==null){ $sumPron+=$r['pron'];$nPron++; }
  if ($r['flu'] !==null){ $sumFlu +=$r['flu']; $nFlu++; }
  $p=$r['phrase_uid'];
  if (!isset($phraseAgg[$p])) $phraseAgg[$p]=[0,0,0,0];
  if ($r['pron']!==null){ $phraseAgg[$p][0]+=$r['pron'];$phraseAgg[$p][1]++; }
  if ($r['flu'] !==null){ $phraseAgg[$p][2]+=$r['flu']; $phraseAgg[$p][3]++; }
}
$avgPron=safe_avg($sumPron,$nPron);
$avgFlu=safe_avg($sumFlu,$nFlu);
$uniquePhrases=count($phrasesSet);

$phraseSeries=[];
foreach ($phraseAgg as $p=>$a){
  $phraseSeries[]=['phrase'=>$p,'pron'=>round(safe_avg($a[0],$a[1]),2),'flu'=>round(safe_avg($a[2],$a[3]),2)];
}
usort($phraseSeries,function($a,$b){return $b['pron']<=>$a['pron'];});

// 趋势（按日）
usort($rows,function($a,$b){return strtotime($a['ts'])<=>strtotime($b['ts']);});
$byDay=[];
foreach ($rows as $r){
  if(!$r['ts']) continue;
  $d=date('Y-m-d',strtotime($r['ts']));
  if(!isset($byDay[$d])) $byDay[$d]=[0,0,0,0];
  if($r['pron']!==null){$byDay[$d][0]+=$r['pron'];$byDay[$d][1]++;}
  if($r['flu'] !==null){$byDay[$d][2]+=$r['flu']; $byDay[$d][3]++;}
}
ksort($byDay);
$trendTs=[];$trendPron=[];$trendFlu=[];
foreach($byDay as $d=>$v){
  $trendTs[]=$d.' 00:00:00';
  $trendPron[]=round(safe_avg($v[0],$v[1]),2);
  $trendFlu[]=round(safe_avg($v[2],$v[3]),2);
}

/* ---------- 输出 JSON ---------- */
$data=[
  'user'=>$requestedUser,
  'overall'=>[
    'attempts'=>count($rows),
    'phrases'=>$uniquePhrases,
    'avgPron'=>round($avgPron,2),
    'avgFlu'=>round($avgFlu,2),
  ],
  'perPhrase'=>$phraseSeries,
  'trend'=>['ts'=>$trendTs,'pron'=>$trendPron,'flu'=>$trendFlu],
];
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>我的练习看板</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns"></script>
<style>
  :root{--fg:#111;--muted:#666;--card:#fff;--bg:#fafafa;}
  html,body{margin:0;padding:0;background:var(--bg);color:var(--fg);font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif}
  .wrap{max-width:1000px;margin:24px auto;padding:0 16px}
  .kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:16px}
  .card{background:var(--card);border-radius:16px;box-shadow:0 6px 20px rgba(0,0,0,.06);padding:16px}
  .kpi h3{margin:0;font-size:14px;color:var(--muted)}
  .kpi p{margin:4px 0 0;font-size:28px;font-weight:700}
  .row{display:grid;grid-template-columns:1fr;gap:16px}
  @media(min-width:960px){.row{grid-template-columns:1fr 1fr}}
  canvas{width:100%!important;height:auto!important}
</style>
</head>
<body>
<div class="wrap">
  <h1>我的练习看板</h1>
  <p style="color:#666">用户：<strong><?=htmlspecialchars($requestedUser)?></strong></p>

  <div class="kpis">
    <div class="card kpi"><h3>总尝试次数</h3><p id="k_attempts"></p></div>
    <div class="card kpi"><h3>练习短句数</h3><p id="k_phrases"></p></div>
    <div class="card kpi"><h3>平均发音分</h3><p id="k_pron"></p></div>
    <div class="card kpi"><h3>平均流利度</h3><p id="k_flu"></p></div>
  </div>

  <div class="row">
    <div class="card"><h3>各短句平均分</h3><canvas id="chartPhrases"></canvas></div>
    <div class="card"><h3>按日趋势</h3><canvas id="chartTrend"></canvas></div>
  </div>
</div>

<script>
const DATA = <?= json_encode($data,JSON_UNESCAPED_UNICODE)?>;
document.getElementById('k_attempts').textContent=DATA.overall.attempts;
document.getElementById('k_phrases').textContent=DATA.overall.phrases;
document.getElementById('k_pron').textContent=DATA.overall.avgPron.toFixed(1);
document.getElementById('k_flu').textContent=DATA.overall.avgFlu.toFixed(1);

const palette={pron:'rgba(53,162,235,0.9)',flu:'rgba(75,192,192,0.9)',grid:'rgba(0,0,0,.08)'};

// per phrase
(() => {
  const labels=DATA.perPhrase.map(x=>x.phrase);
  const pron=DATA.perPhrase.map(x=>x.pron);
  const flu=DATA.perPhrase.map(x=>x.flu);
  new Chart(document.getElementById('chartPhrases'),{
    type:'bar',
    data:{labels,datasets:[
      {label:'发音',data:pron,backgroundColor:palette.pron},
      {label:'流利度',data:flu,backgroundColor:palette.flu}
    ]},
    options:{scales:{y:{beginAtZero:true,max:100,grid:{color:palette.grid}},x:{grid:{display:false}}}}
  });
})();

// trend
(() => {
  const pronPts=[],fluPts=[];
  for(let i=0;i<DATA.trend.ts.length;i++){
    const t=DATA.trend.ts[i];
    const p=DATA.trend.pron[i];
    const f=DATA.trend.flu[i];
    if(Number.isFinite(p)) pronPts.push({x:new Date(t),y:p});
    if(Number.isFinite(f)) fluPts.push({x:new Date(t),y:f});
  }
  new Chart(document.getElementById('chartTrend'),{
    type:'line',
    data:{datasets:[
      {label:'发音',data:pronPts,borderColor:palette.pron,tension:0.25},
      {label:'流利度',data:fluPts,borderColor:palette.flu,tension:0.25}
    ]},
    options:{
      scales:{
        x:{type:'time',time:{unit:'day',tooltipFormat:'yyyy-MM-dd'},grid:{display:false}},
        y:{beginAtZero:true,max:100,grid:{color:palette.grid}}
      }
    }
  });
})();
</script>
</body>
</html>
