<?php
require __DIR__.'/auth.php';

// logout
if (isset($_GET['logout'])) {
  $_SESSION = [];
  if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time()-42000, $p['path'], $p['domain'] ?? '', !empty($_SERVER['HTTPS']), true);
  }
  session_destroy();
  header('Location: login.php'); exit;
}

// if not logged in, send to login.php
$codes = load_codes($CODES_FILE);
$code  = strtoupper($_SESSION['invite_code'] ?? '');
if ($code === '' || !isset($codes[$code])) {
  header('Location: login.php'); exit;
}
$user = ['code'=>$code, 'label'=>$codes[$code]];
$DISPLAY_NAME = htmlspecialchars($user['label'] ?: $user['code'], ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <base href="<?php echo htmlspecialchars($ABS_BASE, ENT_QUOTES, 'UTF-8'); ?>">
  <title>Morning SIR! — 服務用語訓練 </title>
  <link rel="stylesheet" href="assets/css/styles.css" />
  <!-- Progressive Web App / iOS Home Screen -->
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="Morning SIR!">
  <link rel="apple-touch-icon" sizes="192x192" href="assets/img/minilogo.png">
  <link rel="manifest" href="manifest.json">
  <meta name="theme-color" content="#0A66FF">
</head>
<body>
  <header class="appbar">Morning SIR! — 服務用語訓練 <span class="version-badge">V3</span></header>
  <div class="hero">
    <div>
  <div class="header-brand"><img src="assets/img/logo_full.svg" alt="AVSECO" class="header-logo"/></div>
  <div class="header-sub">針對旅客服務場景的英語用語練習</div>
    </div>
  </div>

  <main id="views">
    <!-- 主頁 -->
    <section id="home" class="view panel active">
      <div class="card card--pad">
        <h2 class="h2--no-margin">歡迎使用</h2>
        <p class="muted p--no-margin">這是一個瀏覽器版本，使用預錄英語音檔與本地評分機制。大多數行動瀏覽器可錄音。</p>
        <div class="row row--mt">
          <button class="btn secondary" onclick="switchTab('practice')">前往練習</button>
          <button class="btn primary" onclick="alert('Morning SIR! H5 — 本地音檔與評分機制。')">關於本示範</button>
        </div>
      </div>
    </section>

    <!-- 練習：課程列表 + 閃卡 -->
    <section id="practice" class="view panel">
      <h2>課程列表</h2>
      <div id="courseList" class="list"></div>

      <div id="session" class="hidden">
        <div class="row">
          <div id="courseTitle" class="course-title"></div>
          <div class="right muted" id="counter">0/0</div>
        </div>
        <div class="progress"><div id="progressBar"></div></div>

        <div id="flashcard" class="flashcard" aria-live="polite">
          <div class="zh" id="fcZh">—</div>
          <div class="en" id="fcEn">—</div>
          <div class="chips" id="chips"></div>
          <div class="hint">向<b>左滑</b>＝已認識 • 向<b>右滑</b>＝加入多練</div>
        </div>

        <div class="toolbar">
          <button class="btn secondary" id="btnPlay">▶︎ 播放</button>
          <button class="btn secondary" id="btnRecord">● 錄音</button>
          <button class="btn secondary" id="btnStop" disabled>■ 停止</button>
          <button class="btn secondary" id="btnPlayback" disabled>🔁 回放</button>
          <button class="btn success" id="btnScore" disabled>★ 評分</button>
        </div>

        <div id="result" class="result"></div>
        <div class="row row--mt-sm">
          <button class="btn primary right" id="btnNext">下一張 →</button>
        </div>

        <div id="summary" class="summary card summary--card">
          <h3 class="h3--no-margin">本次練習總結</h3>
          <div class="grid">
            <div class="kpi"><div class="muted">卡片數</div><div id="smCards" class="en">0</div></div>
            <div class="kpi"><div class="muted">平均分</div><div id="smAvg" class="en">—</div></div>
            <div class="kpi"><div class="muted">需要多練</div><div id="smHard" class="en">0</div></div>
            <div class="kpi"><div class="muted">已認識</div><div id="smEasy" class="en">0</div></div>
          </div>
        </div>
      </div>

      <div class="spacer"></div>
    </section>

    <!-- 報告 -->
    <section id="reports" class="view panel">
      <h2>報告（示範）</h2>
  <div class="card card--pad">
        <p class="muted">此頁僅展示您在本裝置上的簡單練習統計（示範）。</p>
  <div class="grid grid--two">
          <div class="kpi"><div class="muted">練習次數</div><div id="rSessions" class="en">0</div></div>
          <div class="kpi"><div class="muted">平均分</div><div id="rAvg" class="en">—</div></div>
        </div>
      </div>
      <div class="spacer"></div>
    </section>

    <!-- 我的 -->
    <section id="profile" class="view panel">
      <h2>我的</h2>
  <div class="card card--pad">
<label class="muted" for="nickname">暱稱</label>
<input id="nickname" placeholder="請輸入暱稱" style="display:block;width:100%;padding:12px;margin-top:6px;border-radius:10px;border:1px solid #e5e7eb"/>
<div class="hint"></div>

      <hr style="margin:14px 0;border:none;border-top:1px solid #eef2ff" />
      <h3 style="margin:0 0 8px 0">我的成就</h3>
      <div class="pins-grid" id="myPins">
        <!-- pins will be inserted here -->
      </div>
      </div>
      <div class="spacer"></div>
    </section>
  </main>

  <!-- 底部導覽 -->
  <nav class="tabbar">
    <div class="tab active" data-tab="home" data-icon-default="assets/tabbar/home.png" data-icon-selected="assets/tabbar/home_selected.png">
      <img src="assets/tabbar/home_selected.png" alt="主頁">
      <div class="tab-label">主頁</div>
    </div>
    <div class="tab" data-tab="practice" data-icon-default="assets/tabbar/practice.png" data-icon-selected="assets/tabbar/practice_selected.png">
      <img src="assets/tabbar/practice.png" alt="練習">
      <div class="tab-label">練習</div>
    </div>
    <div class="tab" data-tab="reports" data-icon-default="assets/tabbar/reports.png" data-icon-selected="assets/tabbar/reports_selected.png">
      <img src="assets/tabbar/reports.png" alt="報告">
      <div class="tab-label">報告</div>
    </div>
    <div class="tab" data-tab="profile" data-icon-default="assets/tabbar/profile.png" data-icon-selected="assets/tabbar/profile_selected.png">
      <img src="assets/tabbar/profile.png" alt="我的">
      <div class="tab-label">我的</div>
    </div>
  </nav>

  <script>
    // ====== 課程資料（包含 AVSECO 基礎 10 句） ======
    const COURSES = [
      {
        id: 'avseco', title: 'AVSECO 基礎', color:'#dbeafe', cover:'assets/img/cover-avseco.jpg',
       phrases: [
  {
    zh: '先生/女士，這是受限制物品，請聯絡航空公司協助、辦理物品收據，或選擇棄置。',
    en: "Sir/Madam, this is a restricted article, so please seek your airline’s assistance, obtain a property receipt, or dispose of it.",
    audio: "assets/audio/avseco1.mp3"
  },
  {
    zh: '不好意思，先生/女士，此物品屬於受限制物品，基於安全原因不能帶上飛機。',
    en: "Excuse me, Sir/Madam, this item is a restricted article and, for safety reasons, cannot be taken on board the plane.",
    audio: "assets/audio/avseco2.mp3"
  },
  {
    zh: '請站在腳印上，按照指示，並把雙手張開，謝謝。',
    en: "Please stand on the footprints, follow the sign, and spread out your hands. Thank you.",
    audio: "assets/audio/avseco3.mp3"
  },
  {
    zh: '您好，先生/女士，請將所有隨身物品放入托盤，謝謝。',
    en: "Hello, Sir/Madam, please place all your belongings into the tray. Thank you.",
    audio: "assets/audio/avseco4.mp3"
  },
  {
    zh: '不好意思，先生/女士，這是隨機手檢，請把口袋內所有物品取出並放到這個黑色托盤。',
    en: "Excuse me, Sir/Madam, this is a random hand search, so please take out all items from your pockets and put them in this black tray.",
    audio: "assets/audio/avseco5.mp3"
  },
  {
    zh: '不好意思，先生/女士，您的護照沒有晶片，請前往人工協助通道。',
    en: "Sorry, Sir/Madam, your passport does not have a chip, so please proceed to the assisted channel.",
    audio: "assets/audio/avseco6.mp3"
  },
  {
    zh: '攜帶小童的旅客請使用家庭通道，並按照指示前往人工協助通道。',
    en: "For families with young travelers, please use the family lane and follow the signs to the assisted channel.",
    audio: "assets/audio/avseco7.mp3"
  },
  {
    zh: '請向前一步或向後一步，然後站在腳印上並看向攝像頭。',
    en: "Please step forward or step back, then stand on the footprints and look at the camera.",
    audio: "assets/audio/avseco8.mp3"
  },
  {
    zh: '通過海關後，機場快線車站位於到達大堂的對面。',
    en: "After you pass customs, the Airport Express station is on the opposite side of the arrival hall.",
    audio: "assets/audio/avseco9.mp3"
  },
  {
    zh: '不好意思，先生/女士，您不能返回行李提取大堂。',
    en: "Excuse me, Sir/Madam, you are not allowed to return to the baggage reclaim hall.",
    audio: "assets/audio/avseco10.mp3"
  }
]

      }
    ];

    // ====== 狀態儲存 ======
    const Views = { home: qs('#home'), practice: qs('#practice'), reports: qs('#reports'), profile: qs('#profile') };
    const Tabs = $$('.tab');
    let session = null; // {course, idx, results:[], hard:Set, easy:Set}
    const store = {
      get nick(){ return localStorage.getItem('ms_nick')||'' },
      set nick(v){ localStorage.setItem('ms_nick', v||'') },
      get stats(){ return JSON.parse(localStorage.getItem('ms_stats')||'{"sessions":0,"scores":[]}') },
      set stats(v){ localStorage.setItem('ms_stats', JSON.stringify(v)) }
    }
const EVAL_URL = 'eval.php';

function mimeToExt(t){
  if (!t) return null;
  if (/wav/i.test(t)) return 'wav';
  if (/mp3/i.test(t)) return 'mp3';
  return null; // 其餘格式（webm/mp4/ogg）不送 eval.php（會回退 mock）
}

//for debug use only
async function logEvent(event, data, msg) {
  try {
    const fd = new FormData();
    fd.append('event', event);
    fd.append('page', location.pathname + location.hash);
    if (msg) fd.append('msg', String(msg));
    if (data != null) fd.append('data', JSON.stringify(data));
    await fetch('log.php', { method:'POST', body: fd, credentials:'include' });
  } catch (e) { /* ignore */ }
}

// === SOE per-word 解析參數 ===
const SOE_BAD_ACC = 65;   // PronAccuracy < 65 視為待加強
const SOE_MAX_TOKENS = 3; // 最多顯示 3 個重點詞

// 依 SOE 結果挑詞；若沒有可用詞就退回隨機挑
function deriveMistakesFromSoe(soeWords, fallbackWords) {
  try {
    const items = Array.isArray(soeWords) ? soeWords : [];
    // 清洗：只取有 Word 與 PronAccuracy 的項目
    const scored = items
      .map(w => ({
        w: String((w.Word ?? w.word ?? '')).replace(/[^A-Za-z']/g, ''),
        acc: Number(w.PronAccuracy ?? w.pronAccuracy ?? NaN)
      }))
      .filter(x => x.w && !Number.isNaN(x.acc));

    // 低準確度優先，其次字面順序
    const bad = scored
      .filter(x => x.acc < SOE_BAD_ACC)
      .sort((a,b) => a.acc - b.acc)
      .slice(0, SOE_MAX_TOKENS)
      .map(x => x.w);

    if (bad.length) return bad;

    // 若都不差，挑可能的功能詞以外的詞作輕提醒（取 1–2 個）
    const contentWords = (fallbackWords || [])
      .map(w => w.replace(/[^A-Za-z']/g,''))
      .filter(Boolean)
      .filter(w => !/^(the|a|an|and|or|to|of|in|on|at|for|with|by|from)$/i.test(w));

    if (contentWords.length) {
      return contentWords.slice(0, Math.min(2, SOE_MAX_TOKENS));
    }
  } catch (_) {}

  // 最後保底：沿用舊的隨機挑詞（若有）
  if (typeof pickMistakes === 'function') {
    return pickMistakes(fallbackWords || []);
  }
  return [];
}


    // ====== 初始化 ======
    document.addEventListener('DOMContentLoaded', () => {
      Tabs.forEach(t => t.addEventListener('click', () => switchTab(t.dataset.tab)));
      const nick = qs('#nickname'); nick.value = store.nick; nick.addEventListener('change', e=>store.nick=e.target.value);
      renderCourses(); updateReports(); wirePracticeControls();
      // initialize tab icons
      Tabs.forEach(tab => {
        const img = tab.querySelector('img');
        if(!img) return;
        const sel = tab.classList.contains('active');
        img.src = sel ? tab.dataset.iconSelected : tab.dataset.iconDefault;
      });
          // populate pins (show up to 8 pins named pin1.png..pin8.png)
          try {
            const pinsWrap = qs('#myPins');
            if (pinsWrap) {
              pinsWrap.innerHTML = '';
              for (let i=1;i<=8;i++) {
                const d = document.createElement('div'); d.className='pin';
                const img = document.createElement('img'); img.src = `assets/pins/pin${i}.png`; img.alt = `pin${i}`;
                d.appendChild(img); pinsWrap.appendChild(d);
              }
            }
          } catch(e) { console.warn('populate pins failed', e); }
    });

    function switchTab(tab){ Object.values(Views).forEach(v => v.classList.remove('active')); qs('#'+tab).classList.add('active');
      Tabs.forEach(t => {
        const isActive = t.dataset.tab===tab;
        t.classList.toggle('active', isActive);
        const img = t.querySelector('img'); if(img) img.src = isActive ? t.dataset.iconSelected : t.dataset.iconDefault;
      });
    }

    // ====== 課程清單 ======
    function renderCourses(){
      const wrap = qs('#courseList'); wrap.innerHTML = '';
      COURSES.forEach(c => {
        const el = document.createElement('div'); el.className='card course';
        el.innerHTML = `
          <div class="avatar" style="background:${c.color||'#e2e8f0'}">${c.title[0]}</div>
          <div class="meta"><div class="title">${c.title}</div><div class="sub">${c.phrases.length} 句 · 英/中</div></div>
          <div class="right muted">開始 →</div>`;
        el.addEventListener('click', () => startSession(c)); wrap.appendChild(el);
      });
    }

    // ====== 練習流程 ======
    const sessionBox = qs('#session');
    const counter = qs('#counter'); const progressBar = qs('#progressBar');
    const fcZh = qs('#fcZh'); const fcEn = qs('#fcEn'); const chips = qs('#chips');
    const btnPlay = qs('#btnPlay'); const btnRecord = qs('#btnRecord'); const btnStop = qs('#btnStop');
    const btnPlayback = qs('#btnPlayback'); const btnScore = qs('#btnScore'); const btnNext = qs('#btnNext');
    const resultBox = qs('#result');
    let mediaRecorder, recordedChunks = [], recordedBlob = null, recordedMimeType = '', audioEl = new Audio();
  // Fallback recorder state (for iOS Safari / browsers without MediaRecorder or unsupported mime types)
  let fallbackRecorder = null;

  function setPlaybackBlob(blob, mime) {
  recordedBlob = blob;
  recordedMimeType = (mime || blob?.type || '').toLowerCase();

  // create/update the preview audio element
  let a = document.getElementById('recPreview');
  if (!a) {
    a = document.createElement('audio');
    a.id = 'recPreview';
    a.controls = false;
    a.preload = 'metadata';
    a.playsInline = true; a.setAttribute('playsinline', ''); a.muted = false;
    a.style.display = 'none';
    document.body.appendChild(a);
  }
  a.src = URL.createObjectURL(recordedBlob);
  try { a.load(); } catch(_) {}

  // enable buttons
  if (typeof btnPlayback !== 'undefined') btnPlayback.disabled = !(recordedBlob && recordedBlob.size);
  if (typeof btnScore    !== 'undefined') btnScore.disabled    = !(recordedBlob && recordedBlob.size);
}
    // Helper: encode Float32 samples to WAV ArrayBuffer
    function encodeWAV(samples, sampleRate){
      const buffer = new ArrayBuffer(44 + samples.length * 2);
      const view = new DataView(buffer);
      function writeString(offset, s){ for(let i=0;i<s.length;i++) view.setUint8(offset+i, s.charCodeAt(i)); }
      writeString(0, 'RIFF');
      view.setUint32(4, 36 + samples.length*2, true);
      writeString(8, 'WAVE');
      writeString(12, 'fmt ');
      view.setUint32(16, 16, true);
      view.setUint16(20, 1, true);
      view.setUint16(22, 1, true);
      view.setUint32(24, sampleRate, true);
      view.setUint32(28, sampleRate * 2, true);
      view.setUint16(32, 2, true);
      view.setUint16(34, 16, true);
      writeString(36, 'data');
      view.setUint32(40, samples.length * 2, true);
      let offset = 44;
      for(let i=0;i<samples.length;i++){
        const s = Math.max(-1, Math.min(1, samples[i]));
        view.setInt16(offset, s < 0 ? s * 0x8000 : s * 0x7FFF, true);
        offset += 2;
      }
      return view;
    }

 async function sniffAudioExt(blob) {
  try {
    const head = new Uint8Array(await blob.slice(0, 12).arrayBuffer());
    // WAV: "RIFF....WAVE"
    if (head[0]===0x52 && head[1]===0x49 && head[2]===0x46 && head[3]===0x46) return 'wav';
    // MP3: "ID3" or MPEG sync
    if (head[0]===0x49 && head[1]===0x44 && head[2]===0x33) return 'mp3';
    if (head[0]===0xFF && (head[1] & 0xE0) === 0xE0) return 'mp3';
  } catch(e){}
  return null;
}

async function debugBeacon(tag, obj) {
  try {
    const fd = new FormData();
    fd.append('event', tag);
    fd.append('data', JSON.stringify(obj||{}));
    await fetch('log.php', { method:'POST', body: fd, credentials:'include' });
  } catch(e){}
}


  //start recording and forecewave
    const FORCE_WAV = /iPhone|iPad|iPod/.test(navigator.userAgent) || window.navigator.standalone === true;

    async function startRecording(){
      if (FORCE_WAV) return startRecordingFallback();  // your WAV exporter
      return startRecordingWithMediaRecorder();
    }

    // Start a fallback recorder using Web Audio API that produces WAV
  async function startRecordingFallback(stream){
  const ac = new (window.AudioContext || window.webkitAudioContext)({ sampleRate: 44100 });
  const src = ac.createMediaStreamSource(stream);
  const proc = ac.createScriptProcessor(4096, 1, 1);
  const chunks = [];
  proc.onaudioprocess = e => { chunks.push(new Float32Array(e.inputBuffer.getChannelData(0))); };
  src.connect(proc); proc.connect(ac.destination);

  function mergeFloat32(parts){ let n=0; parts.forEach(p=>n+=p.length); const out=new Float32Array(n); let o=0; parts.forEach(p=>{ out.set(p,o); o+=p.length; }); return out; }
  function to16kMonoWav(float32, srcRate){
    const tgt=16000, ratio=srcRate/tgt, len=Math.round(float32.length/ratio);
    const res = new Float32Array(len); for(let i=0,p=0;i<len;i++,p+=ratio) res[i]=float32[p|0];
    const buf = new ArrayBuffer(44+len*2), v=new DataView(buf); let o=0;
    const w16=x=>{v.setUint16(o,x,true);o+=2;}, w32=x=>{v.setUint32(o,x,true);o+=4;};
    w32(0x46464952); w32(36+len*2); w32(0x45564157);
    w32(0x20746d66); w32(16); w16(1); w16(1); w32(tgt); w32(tgt*2); w16(2); w16(16);
    w32(0x61746164); w32(len*2);
    for(let i=0;i<len;i++){ const s=Math.max(-1,Math.min(1,res[i])); v.setInt16(o, s<0?s*0x8000:s*0x7FFF, true); o+=2; }
    return new Blob([buf], { type:'audio/wav' });
  }

  fallbackRecorder = {
    stop: async ()=>{
      try {
        proc.disconnect(); src.disconnect();
        const blob = to16kMonoWav(mergeFloat32(chunks), ac.sampleRate);
        setPlaybackBlob(blob, 'audio/wav');
        btnRecord.disabled = false;
        btnStop.disabled   = true;
      } finally { try{ await ac.close(); }catch(_){} fallbackRecorder=null; }
    }
  };

  btnRecord.disabled = true;
  btnStop.disabled   = false;
  setTimeout(()=>{ try{ fallbackRecorder?.stop(); }catch(_){} }, 15000);
}

// 合併多段 Float32Array
function mergeFloat32(chunks){
  let len = 0; for (const c of chunks) len += c.length;
  const out = new Float32Array(len);
  let off = 0; for (const c of chunks){ out.set(c, off); off += c.length; }
  return out;
}

// 轉成 16k 單聲道 WAV（16-bit PCM）
function to16kMonoWav(float32, srcRate){
  const tgt = 16000;
  const ratio = srcRate / tgt;
  const newLen = Math.round(float32.length / ratio);
  const resampled = new Float32Array(newLen);
  for (let i=0, p=0; i<newLen; i++, p+=ratio) resampled[i] = float32[p|0];

  const buffer = new ArrayBuffer(44 + newLen*2);
  const view = new DataView(buffer);
  let off = 0;
  const w16 = v => { view.setUint16(off, v, true); off+=2; };
  const w32 = v => { view.setUint32(off, v, true); off+=4; };

  // RIFF
  w32(0x46464952); w32(36 + newLen*2); w32(0x45564157);
  // fmt
  w32(0x20746d66); w32(16); w16(1); w16(1); w32(tgt); w32(tgt*2); w16(2); w16(16);
  // data
  w32(0x61746164); w32(newLen*2);
  for (let i=0; i<newLen; i++){
    const s = Math.max(-1, Math.min(1, resampled[i]));
    view.setInt16(off, s<0 ? s*0x8000 : s*0x7FFF, true); off+=2;
  }
  return new Blob([buffer], { type: 'audio/wav' });
}


    // Start a MediaRecorder if available (prefer webm/ogg/mp4 where supported)
function startRecordingWithMediaRecorder(stream) {
  let mime = '';
  if (MediaRecorder.isTypeSupported('audio/webm;codecs=opus')) mime = 'audio/webm;codecs=opus';
  else if (MediaRecorder.isTypeSupported('audio/webm')) mime = 'audio/webm';
  else if (MediaRecorder.isTypeSupported('audio/mpeg')) mime = 'audio/mpeg';
  else if (MediaRecorder.isTypeSupported('audio/wav'))  mime = 'audio/wav';

  mediaRecorder = new MediaRecorder(stream, mime ? { mimeType: mime } : undefined);
  const chunks = [];

  mediaRecorder.ondataavailable = e => { if (e.data && e.data.size) chunks.push(e.data); };

  mediaRecorder.onstop = () => {
    const blob = new Blob(chunks, { type: mediaRecorder.mimeType || '' });
    setPlaybackBlob(blob, mediaRecorder.mimeType || '');
    btnRecord.disabled = false;
    btnStop.disabled   = true;
  };

  mediaRecorder.start();
  btnRecord.disabled = true;
  btnStop.disabled   = false;

  // auto-stop after 15s
  setTimeout(()=>{ try{ mediaRecorder?.state!=='inactive' && mediaRecorder.stop(); }catch(_){} }, 15000);
}


    function startSession(course){
      qs('#courseTitle').textContent = course.title; qs('#summary').classList.remove('visible');
  session = { course, idx:0, results:[], hard:new Set(), easy:new Set() };
  // hide the static "課程列表" heading when the user starts a session
  const practiceHeader = qs('#practice h2'); if(practiceHeader) practiceHeader.classList.add('hidden');
  qs('#courseList').classList.add('hidden'); sessionBox.classList.remove('hidden'); loadCard();
    }

    function loadCard(){
  const total = session.course.phrases.length; if(session.idx>=total){ return endSession(); }
  // ensure any swipe animation classes are cleared
  card.classList.remove('animating','swipe-left','swipe-right');
      const p = session.course.phrases[session.idx]; fcZh.textContent = p.zh; fcEn.textContent = p.en;
      chips.innerHTML = ''; (p.en.split(/\s+/).slice(0,4)).forEach(w=>{ const s=document.createElement('span'); s.className='pill'; s.textContent=w.replace(/[^a-zA-Z']/g,''); chips.appendChild(s); });
      counter.textContent = `${session.idx+1}/${total}`; progressBar.style.width = `${((session.idx)/total)*100}%`;
  clearResult(); disable([btnPlayback, btnScore]);
    }

    function endSession(){
      progressBar.style.width = '100%';
      const scores = session.results.map(r=>r.score); const avg = scores.length? Math.round(scores.reduce((a,b)=>a+b,0)/scores.length) : null;
      qs('#smCards').textContent = session.course.phrases.length; qs('#smAvg').textContent = avg!=null? `${avg}` : '—';
      qs('#smHard').textContent = session.hard.size; qs('#smEasy').textContent = session.easy.size; qs('#summary').classList.add('visible');
      const stats = store.stats; stats.sessions += 1; if(avg!=null) stats.scores.push(avg); store.stats = stats; updateReports();
    }

    function updateReports(){ const s = store.stats; qs('#rSessions').textContent = s.sessions; const avg = s.scores.length? Math.round(s.scores.reduce((a,b)=>a+b,0)/s.scores.length) : '—'; qs('#rAvg').textContent = avg; }

    // ====== 音訊控制 ======
    btnPlay.addEventListener('click', ()=>{ const p = session.course.phrases[session.idx]; audioEl.src = p.audio; audioEl.play(); });
    btnRecord.addEventListener('click', async ()=>{
      if(!navigator.mediaDevices){ alert('此瀏覽器不支援錄音。您仍可使用播放與模擬評分功能。'); return; }
      try{ const stream = await navigator.mediaDevices.getUserMedia({ audio:true });
        // prefer MediaRecorder when available and supports a common type
        if(window.MediaRecorder){
          try{
            startRecordingWithMediaRecorder(stream);
            return;
          }catch(err){ console.warn('MediaRecorder failed, falling back to WAV recorder', err); }
        }
        // fallback for iOS Safari or older browsers
        await startRecordingFallback(stream);
        return;
       }catch(err){ alert('麥克風權限被拒或不可用。'); console.error(err); }
    });
    btnStop.addEventListener('click', ()=>{
      if(mediaRecorder && mediaRecorder.state!=='inactive'){ mediaRecorder.stop(); }
      if(fallbackRecorder){ try{ fallbackRecorder.stop(); }catch(e){ console.error(e); } }
      btnRecord.disabled = false; btnStop.disabled = true; 
    });

    btnPlayback.addEventListener('click', ()=>{
      if(!recordedBlob){ return; }
      // iOS requires user interaction to play and prefers correct type
      const url = URL.createObjectURL(recordedBlob);
      audioEl.src = url; audioEl.play().catch(err=>{
        console.warn('Playback failed:', err);
        // fallback: create a temporary audio element and attach to DOM to ensure play is allowed
        const tmp = document.createElement('audio'); tmp.controls = true; tmp.src = url; document.body.appendChild(tmp);
        tmp.play().catch(e=>console.error(e));
      });
    });

    // ====== 模擬評分 ======
    // Centralized real-eval function. Returns parsed JSON { ok, summary, result } or throws.
    async function evaluateReal(blob, ext){
      const p = session && session.course && session.course.phrases ? session.course.phrases[session.idx] : null;
      const text = p && p.en ? String(p.en).replace(/\s+/g,' ').trim() : '';
      const fd = new FormData();
      fd.append('text', text);
      // ensure filename uses proper extension
      fd.append('audio', blob, `clip.${ext || (blob.type||'').split('/').pop()}`);

      const res = await fetch(EVAL_URL, { method: 'POST', body: fd, credentials: 'include' });
      if (res.status === 401 || res.status === 403) { location.href = 'login.php'; throw new Error('auth'); }
      const data = await res.json().catch(()=> ({}));
      if (!res.ok || !data || !data.ok || !data.summary) throw new Error('eval failed');
      return { ok: true, summary: data.summary, result: data.result || null };
    }

    // btnScore: call evaluateReal when resources ready, otherwise fallback to doMock
   btnScore?.addEventListener('click', onScoreClick, false);

async function onScoreClick(){
  try {
    showScoring?.(); disable?.([btnScore]);

    // sentence & words
    const p = session?.course?.phrases?.[session.idx];
    const text = (p?.en || '').replace(/\s+/g,' ').trim();
    const words = (p?.en || '').replace(/[^A-Za-z'\s]/g,'').split(/\s+/).filter(Boolean);
    if (!text) throw new Error('no-text');

    // blob sanity
    const blob = recordedBlob;
    const mime = (recordedMimeType || blob?.type || '').toLowerCase();
    let ext = /wav/.test(mime) ? 'wav' : (/mp3/.test(mime) ? 'mp3' : null);
    if (!ext && blob) ext = await sniffAudioExt(blob);

    await debugBeacon('eval_pre', {
      hasBlob: !!(blob && blob.size), mime, ext, size: blob?.size||0, textLen: text.length
    });

    if (!blob || !blob.size) return doMock(words);              // no recording
    if (!ext)             return doMock(words);                 // unknown type (webm/mp4)

    // build request
    const fd = new FormData();
    fd.append('text', text);
    fd.append('audio', blob, 'clip.'+ext); // give server an extension

    // call eval
    const res = await fetch('eval.php', { method:'POST', body: fd, credentials:'include' });
    await debugBeacon('eval_status', { status: res.status });

    if (res.status === 401 || res.status === 403) { location.href = 'login.php'; return; }

    let data = {};
    try { data = await res.json(); }
    catch { await debugBeacon('eval_parse_err', { text: await res.text().catch(()=>null) }); throw new Error('json'); }

    await debugBeacon('eval_body', { ok: data?.ok, summary: data?.summary });

    if (!res.ok || !data?.ok || !data?.summary) throw new Error('bad');

    // success
    const s1 = data.summary.SuggestedScore;
    const s2 = data.summary.PronAccuracy;
    const score = Math.round(Number(s1 ?? s2 ?? 0)) || 0;

    const soeWords = data?.result?.Response?.Words || [];
    const mistakes = (typeof deriveMistakesFromSoe === 'function')
      ? deriveMistakesFromSoe(soeWords, words)
      : (typeof pickMistakes === 'function' ? pickMistakes(words) : []);

    showResult?.(score, mistakes);
    (session.results ||= {})[session.idx] = { score, mistakes, soi: data.summary };
    enable?.([btnNext, btnScore]);
  } catch (e) {
    await debugBeacon('eval_catch', { err: String(e) });
    await doMockSafe();
  }

  async function doMockSafe(){
    const base = 70 + ((session?.idx||0)*7)%18;
    const score = Math.min(99, base + Math.floor(Math.random()*6));
    const mistakes = (typeof deriveMistakesFromSoe==='function') ? deriveMistakesFromSoe([], [])
                    : (typeof pickMistakes==='function') ? pickMistakes([]) : [];
    await new Promise(r=>setTimeout(r,600));
    showResult?.(score, mistakes);
    (session.results ||= {})[session.idx] = { score, mistakes, soi: null };
    enable?.([btnNext, btnScore]);
  }
}

    btnNext.addEventListener('click', ()=>{ session.idx += 1; loadCard(); });

    // 手勢
    const card = qs('#flashcard'); let touchX = null;
    card.addEventListener('touchstart', e=>{ touchX = e.changedTouches[0].clientX; },{passive:true});
    card.addEventListener('touchend', e=>{
      if(touchX==null) return;
      const dx = e.changedTouches[0].clientX - touchX; touchX=null;
      if(Math.abs(dx) < 40) return; // ignore small moves
      const isRight = dx>0;
      // trigger animation class
      card.classList.add('animating', isRight ? 'swipe-right' : 'swipe-left');
      // mark according to swipe
      if(isRight) markHard(); else markEasy();
      // advance after animation ends
      const onEnd = ()=>{
        card.removeEventListener('transitionend', onEnd);
        card.classList.remove('animating','swipe-right','swipe-left');
        if(session){ session.idx += 1; loadCard(); }
      };
      card.addEventListener('transitionend', onEnd);
    });
    function markHard(){ session.hard.add(session.idx); toast('已加入多練'); }
    function markEasy(){ session.easy.add(session.idx); toast('已標記為已認識'); }

    function clearResult(){ resultBox.classList.remove('visible'); resultBox.innerHTML=''; }
    function showScoring(){ resultBox.classList.add('visible'); resultBox.innerHTML = `<div class="row"><div>AI 評分中…</div><div class="right muted">~1s</div></div>`; }
    function showResult(score, mistakes, source){
      resultBox.classList.add('visible');
      const badge = source === 'real'
        ? `<span class="badge badge-real">真實</span>`
        : `<span class="badge badge-simulated">模擬</span>`;
      resultBox.innerHTML = `<div class="row"><div class="score">分數：${score}</div><div class="result-badge">${badge}</div></div>`
        + (mistakes.length
          ? `<div class="mistake-wrap">重點練習：${mistakes.map(m=>`<span class='mistake'>${m}</span>`).join('')}</div>`
          : `<div class='muted' style='margin-top:8px'>做得好！</div>`
        );
    }

  // （預留）真實 API — implemented above as evaluateReal(blob, ext)

    // ====== 工具 ======
    function qs(sel, root=document){ return root.querySelector(sel); }
    function $$(sel, root=document){ return Array.from(root.querySelectorAll(sel)); }
    function wait(ms){ return new Promise(r=>setTimeout(r, ms)); }
    function disable(btns){ btns.forEach(b=>b.disabled=true); }
    function enable(btns){ btns.forEach(b=>b.disabled=false); }
    function toast(msg){ const t = document.createElement('div'); t.textContent = msg; t.style.position='fixed'; t.style.left='50%'; t.style.bottom='80px'; t.style.transform='translateX(-50%)'; t.style.background='#111827'; t.style.color='#fff'; t.style.padding='10px 14px'; t.style.borderRadius='999px'; t.style.boxShadow='var(--shadow)'; t.style.zIndex=99; document.body.appendChild(t); setTimeout(()=>{ t.remove(); }, 900); }
    function pickMistakes(words){ if(words.length<=2) return []; const copy=[...words]; const n = Math.random()<0.5?1:2; const out=[]; for(let i=0;i<n;i++){ const idx = Math.floor(Math.random()*copy.length); out.push(copy.splice(idx,1)[0]); } return out; }

    // 兼容舊版：某些版本會呼叫 wirePracticeControls()
    // 本檔已在內部綁好事件，這裡提供空函式避免報錯
    function wirePracticeControls(){}

// Values from the PHP session (safe to embed via json_encode)
window.SESSION_USER = <?php
  echo json_encode(
    ['code' => $user['code'], 'name' => ($user['label'] ?: $user['code'])],
    JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT
  );
?>;

// 1) Update the existing store on every login/app load
try {
  localStorage.setItem('ms_nick', window.SESSION_USER.name);
  localStorage.setItem('ms_staffNo', window.SESSION_USER.code); // optional, if you use it elsewhere
} catch (e) {}

// 2) Keep the profile page unchanged, just show the session name and prevent edits
document.addEventListener('DOMContentLoaded', () => {
  const nick = document.getElementById('nickname');
  if (nick) {
    nick.value = window.SESSION_USER.name;
    nick.readOnly = true;   // stop edits
    nick.disabled = true;   // greys it out & blocks events
    nick.title = '由系統設定（邀請碼名冊）';
  }
});
</script>

</body>
</html>

