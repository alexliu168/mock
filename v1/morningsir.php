<?php
require __DIR__.'/auth.php';
// Prevent stale caches serving old inline JS (e.g., references like doMock)
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

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
<html lang="zh-Hans">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <base href="<?php echo htmlspecialchars($ABS_BASE, ENT_QUOTES, 'UTF-8'); ?>">
  <title>Morning SIR! — 服务用语训练 </title>
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
  <header class="appbar">Morning SIR! — 服务用语训练 <span class="version-badge">V4</span></header>
  <div class="hero">
    <div>
  <div class="header-brand"><img src="assets/img/logo_full.svg" alt="AVSECO" class="header-logo"/></div>
  <div class="header-sub">针对旅客服务场景的英语用语练习</div>
    </div>
  </div>

  <main id="views">
  <!-- 主页 -->
    <section id="home" class="view panel active">
      <div class="card card--pad">
  <h2 class="h2--no-margin">欢迎使用</h2>
  <p class="muted p--no-margin">这是一个浏览器版本，使用预录英语音频与本地评分机制。大多数移动浏览器可录音。</p>
        <div class="row row--mt">
          <button class="btn secondary" onclick="switchTab('practice')">前往练习</button>
          <button class="btn primary" onclick="alert('Morning SIR! H5 — 本地音频与评分机制。')">关于本示范</button>
        </div>
      </div>
    </section>

  <!-- 练习：课程列表 + 闪卡 -->
    <section id="practice" class="view panel">
  <h2>课程列表</h2>
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
          <div class="hint">向<b>左滑</b>＝已认识 • 向<b>右滑</b>＝加入多练</div>
        </div>

        <div class="toolbar">
          <button class="btn secondary" id="btnPlay">▶︎ 播放</button>
          <button class="btn secondary" id="btnRecord">● 录音</button>
          <button class="btn secondary" id="btnStop" disabled>■ 停止</button>
          <button class="btn secondary" id="btnPlayback" disabled>🔁 回放</button>
          <button class="btn success" id="btnScore" disabled>★ 评分</button>
        </div>

        <div id="result" class="result"></div>
        <div class="row row--mt-sm">
          <button class="btn primary right" id="btnNext">下一张 →</button>
        </div>

        <div id="summary" class="summary card summary--card">
          <h3 class="h3--no-margin">本次练习总结</h3>
          <div class="grid">
            <div class="kpi"><div class="muted">卡片数</div><div id="smCards" class="en">0</div></div>
            <div class="kpi"><div class="muted">平均分</div><div id="smAvg" class="en">—</div></div>
            <div class="kpi"><div class="muted">需要多练</div><div id="smHard" class="en">0</div></div>
            <div class="kpi"><div class="muted">已认识</div><div id="smEasy" class="en">0</div></div>
          </div>
        </div>
      </div>

      <div class="spacer"></div>
    </section>

  <!-- 报告 -->
    <section id="reports" class="view panel">
  <h2>报告（示范）</h2>
  <div class="card card--pad">
  <p class="muted">此页仅展示您在本设备上的简单练习统计（示范）。</p>
  <div class="grid grid--two">
          <div class="kpi"><div class="muted">练习次数</div><div id="rSessions" class="en">0</div></div>
          <div class="kpi"><div class="muted">平均分</div><div id="rAvg" class="en">—</div></div>
        </div>
      </div>
      <div class="spacer"></div>
    </section>

  <!-- 我的 -->
    <section id="profile" class="view panel">
  <h2>我的</h2>
  <div class="card card--pad">
<label class="muted" for="nickname">昵称</label>
<input id="nickname" placeholder="请输入昵称" style="display:block;width:100%;padding:12px;margin-top:6px;border-radius:10px;border:1px solid #e5e7eb"/>
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

  <!-- 底部导航 -->
  <nav class="tabbar">
    <div class="tab active" data-tab="home" data-icon-default="assets/tabbar/home.png" data-icon-selected="assets/tabbar/home_selected.png">
      <img src="assets/tabbar/home_selected.png" alt="主页">
  <div class="tab-label">主页</div>
    </div>
    <div class="tab" data-tab="practice" data-icon-default="assets/tabbar/practice.png" data-icon-selected="assets/tabbar/practice_selected.png">
      <img src="assets/tabbar/practice.png" alt="练习">
  <div class="tab-label">练习</div>
    </div>
    <div class="tab" data-tab="reports" data-icon-default="assets/tabbar/reports.png" data-icon-selected="assets/tabbar/reports_selected.png">
      <img src="assets/tabbar/reports.png" alt="报告">
  <div class="tab-label">报告</div>
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
  id: 'avseco_1', title: 'AVSECO 基础', color:'#dbeafe', cover:'assets/img/cover-avseco.jpg',
       phrases: [
  {
  zh: '先生/女士，这是受限制物品，请联系航空公司协助，可领取收据或选择弃置。',
    en: "Sir/Madam, this is a restricted item. Please contact your airline for assistance. You may obtain a receipt or choose to dispose of the item.",
    audio: "assets/audio/avseco1.mp3"
  },
  {
  zh: '不好意思，先生/女士，此物品属于受限制物品，出于安全原因不能带上飞机。',
    en: "Excuse me, Sir/Madam. This item is restricted. For safety reasons, it cannot be taken on board the aircraft",
    audio: "assets/audio/avseco2.mp3"
  },
  {
  zh: '请站在脚印上，按照指示，并把双手张开，谢谢。',
    en: "Please stand on the footprints, follow the sign, and spread out your hands. Thank you.",
    audio: "assets/audio/avseco3.mp3"
  },
  {
  zh: '您好，先生/女士，请将所有随身物品放入托盘，谢谢。',
    en: "Hello, Sir/Madam, please place all your belongings into the tray. Thank you.",
    audio: "assets/audio/avseco4.mp3"
  },
  {
  zh: '不好意思，先生/女士，这是随机手检，请把口袋内所有物品取出并放到这个黑色托盘。',
    en: "Excuse me, Sir/Madam, this is a random hand search, so please take out all items from your pockets and put them in this black tray.",
    audio: "assets/audio/avseco5.mp3"
  },
  {
  zh: '不好意思，先生/女士，您的护照没有芯片，请前往人工协助通道。',
    en: "Sorry, Sir/Madam, your passport does not have a chip, so please proceed to the assisted channel.",
    audio: "assets/audio/avseco6.mp3"
  },
  {
  zh: '携带小童的旅客请使用家庭通道，并按照指示前往人工协助通道。',
    en: "For families with young travelers, please use the family lane and follow the signs to the assisted channel.",
    audio: "assets/audio/avseco7.mp3"
  },
  {
  zh: '请向前一步或向后一步，然后站在脚印上并看向摄像头。',
    en: "Please step forward or step back, then stand on the footprints and look at the camera.",
    audio: "assets/audio/avseco8.mp3"
  },
  {
  zh: '通过海关后，机场快线车站位于到达大厅的对面。',
    en: "After you pass customs, the Airport Express station is on the opposite side of the arrival hall.",
    audio: "assets/audio/avseco9.mp3"
  },
  {
  zh: '不好意思，先生/女士，您不能返回行李提取大厅。',
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
const EVAL_URL = 'eval-sa.php';


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
    let mediaRecorder, recordedChunks = [], recordedBlob = null, recordedMimeType = '';
// Use a single audio element for playback
const audioEl = document.createElement('audio');
audioEl.id = 'recPreview';
audioEl.controls = false;
audioEl.preload = 'metadata';
audioEl.playsInline = true; audioEl.setAttribute('playsinline', ''); audioEl.muted = false;
audioEl.style.display = 'none';
document.body.appendChild(audioEl);
  // Fallback recorder state (for iOS Safari / browsers without MediaRecorder or unsupported mime types)
  let fallbackRecorder = null;

  function setPlaybackBlob(blob, mime) {
  recordedBlob = blob;
  recordedMimeType = (mime || blob?.type || '').toLowerCase();
  audioEl.src = URL.createObjectURL(recordedBlob);
  try { audioEl.load(); } catch(_) {}
  btnPlayback.disabled = !(recordedBlob && recordedBlob.size);
  btnScore.disabled = !(recordedBlob && recordedBlob.size);
}
    // Helper: encode Float32 samples to WAV ArrayBuffer

 async function sniffAudioExt(blob) {
  try {
    const head = new Uint8Array(await blob.slice(0, 16).arrayBuffer());
    // WAV: "RIFF....WAVE"
    if (head[0]===0x52 && head[1]===0x49 && head[2]===0x46 && head[3]===0x46) return 'wav';
    // MP3: "ID3" or MPEG sync
    if (head[0]===0x49 && head[1]===0x44 && head[2]===0x33) return 'mp3';
    if (head[0]===0xFF && (head[1] & 0xE0) === 0xE0) return 'mp3';
    // M4A/MP4: "ftyp" in bytes 4-7, major brands: M4A, isom, mp42, etc.
    if (head[4]===0x66 && head[5]===0x74 && head[6]===0x79 && head[7]===0x70) {
      // Check for m4a/mp4 major brands
      const brand = String.fromCharCode(head[8],head[9],head[10],head[11]).toLowerCase();
      if (brand==='m4a '||brand==='mp4 '||brand==='isom'||brand==='mp42') return 'm4a';
      return 'mp4';
    }
    // OGG: "OggS"
    if (head[0]===0x4F && head[1]===0x67 && head[2]===0x67 && head[3]===0x53) return 'ogg';
    // WEBM: "\x1A\x45\xDF\xA3"
    if (head[0]===0x1A && head[1]===0x45 && head[2]===0xDF && head[3]===0xA3) return 'webm';
    // FLAC: "fLaC"
    if (head[0]===0x66 && head[1]===0x4C && head[2]===0x61 && head[3]===0x43) return 'flac';
    // AIFF: "FORM"
    if (head[0]===0x46 && head[1]===0x4F && head[2]===0x52 && head[3]===0x4D) return 'aiff';
    // If blob has a type property, check mime type
    if (blob.type) {
      if (/wav/i.test(blob.type)) return 'wav';
      if (/mp3/i.test(blob.type)) return 'mp3';
      if (/m4a/i.test(blob.type)) return 'm4a';
      if (/mp4/i.test(blob.type)) return 'mp4';
      if (/ogg/i.test(blob.type)) return 'ogg';
      if (/webm/i.test(blob.type)) return 'webm';
      if (/flac/i.test(blob.type)) return 'flac';
      if (/aiff/i.test(blob.type)) return 'aiff';
    }
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

// Build eval context for logging
function getEvalCtx() {
  try {
    const cid = session?.course?.id || '';
  const idx = Number(session?.idx ?? -1);
  const uid = cid ? `${cid}#${(idx+1)}` : '';
    // Keep minimal identifiers: only phrase_uid
    return { phrase_uid: uid };
  } catch (_) { return { phrase_uid:'' }; }
}

// Redact potentially sensitive text fields from SA responses
function redactEvalObject(obj){
  try {
    const clone = JSON.parse(JSON.stringify(obj||{}));
    const stripKeys = new Set(['text','reference_text','ref_text','refText','referenceText']);
    const walk = (v) => {
      if (Array.isArray(v)) { v.forEach(walk); }
      else if (v && typeof v === 'object') {
        Object.keys(v).forEach(k => {
          if (stripKeys.has(k)) delete v[k]; else walk(v[k]);
        });
      }
    };
    walk(clone);
    return clone;
  } catch(_) { return {}; }
}


  //start recording and forecewave

// Simplified: always use MediaRecorder with platform default format
async function startRecording() {
  if (!navigator.mediaDevices || !window.MediaRecorder) {
  alert('此浏览器不支持录音。');
    return;
  }
  try {
    const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
    mediaRecorder = new MediaRecorder(stream); // use default format
    const chunks = [];
    mediaRecorder.ondataavailable = e => { if (e.data && e.data.size) chunks.push(e.data); };
    mediaRecorder.onstop = () => {
      const blob = new Blob(chunks, { type: mediaRecorder.mimeType || '' });
      setPlaybackBlob(blob, mediaRecorder.mimeType || '');
      btnRecord.disabled = false;
      btnStop.disabled   = true;
      btnPlayback.disabled = !(blob && blob.size);
    };
    // Reset playback state on new recording
    recordedBlob = null;
    recordedMimeType = '';
    audioEl.src = '';
    btnPlayback.disabled = true;
    mediaRecorder.start();
    btnRecord.disabled = true;
    btnStop.disabled   = false;
    // auto-stop after 15s
    setTimeout(()=>{ try{ mediaRecorder?.state!=='inactive' && mediaRecorder.stop(); }catch(_){} }, 15000);
  } catch (err) {
  alert('麦克风权限被拒或不可用。');
    console.error(err);
  }
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
  btnRecord.addEventListener('click', startRecording);
    btnStop.addEventListener('click', ()=>{
      if(mediaRecorder && mediaRecorder.state!=='inactive'){ mediaRecorder.stop(); }
      if(fallbackRecorder){ try{ fallbackRecorder.stop(); }catch(e){ console.error(e); } }
      btnRecord.disabled = false; btnStop.disabled = true;
      // Reset playback state after stop
      btnPlayback.disabled = !(recordedBlob && recordedBlob.size);
    });

    btnPlayback.addEventListener('click', ()=>{
      if(!recordedBlob || !recordedBlob.size){ return; }
      audioEl.currentTime = 0;
      audioEl.play().catch(err => {
        console.warn('Playback failed:', err);
      });
    });

    // btnScore: call evaluateReal when resources ready, otherwise fallback to 
    
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
    if (!blob || !blob.size) {
  toast('请先录音再评分');
      enable?.([btnScore]);
      return;
    }

    // pick an extension SpeechAce is happy with
    let ext = await sniffAudioExt(blob);
    if (!ext && mediaRecorder?.mimeType?.includes('/')) {
      ext = mediaRecorder.mimeType.split('/')[1];
    }
    if (!ext) ext = 'webm';

    await debugBeacon('eval_pre', {
  hasBlob: !!(blob && blob.size), mime, size: blob?.size||0, textLen: text.length, ext,
  ...getEvalCtx()
    });

    // build request
    const fd = new FormData();
    fd.append('text', text);
    fd.append('dialect', 'en-us');
    fd.append('audio', blob, 'clip.'+ext);
    fd.append('user_id', window.SESSION_USER.name);
    fd.append('include_fluency', '1');
    fd.append('include_intonation', '1');
    // Include phrase_uid so server can associate the eval
    try {
      const ctx = getEvalCtx();
      if (ctx && ctx.phrase_uid) fd.append('phrase_uid', ctx.phrase_uid);
    } catch(_) {}


    // call SpeechAce adapter
    const res = await fetch(EVAL_URL, { method:'POST', body: fd, credentials:'include' });
  await debugBeacon('eval_status', { status: res.status, ...getEvalCtx() });

    if (res.status === 401 || res.status === 403) { location.href = 'login.php'; return; }

    let data = {};
    try { data = await res.json(); }
    catch {
      // avoid logging key named "text"; include context
      await debugBeacon('eval_parse_err', { body: await res.text().catch(()=>null), ...getEvalCtx() });
      throw new Error('json');
    }

    // log sanitized API response
    const redacted = redactEvalObject(data);
    await debugBeacon('eval_api_response', { response: redacted, ...getEvalCtx() });

    // NEW contract
    if (!res.ok || data?.status !== 'ok') throw new Error('bad');

    // Score & mistakes from SpeechAce adapter
    const score = Math.round(Number(data.overall ?? 0)) || 0;
    const weakWords = Array.isArray(data.weak_words)
      ? data.weak_words
          .map(w => ({ word: String(w.word||'').trim(), tip: String(w.tip||'').trim(), score: Number(w.score||0) }))
          .filter(x => x.word)
      : [];
    const mistakes = weakWords.map(w => w.word);
    await debugBeacon('eval_mistakes', { mistakes, ...getEvalCtx() });

    showResult?.(score, mistakes, weakWords);

    // optional: show fluency
    const flu = Number(data.fluency ?? NaN);
    if (!Number.isNaN(flu)) {
  resultBox.innerHTML += `<div class=\"muted\" style=\"margin-top:6px\">流畅度：${Math.round(flu)}</div>`;
    }

    (session.results ||= {})[session.idx] = {
      score,
      mistakes,
      sa: { overall: data.overall, fluency: data.fluency, meta: data.meta }
    };

    // one-line eval summary for analytics
    await debugBeacon('eval_done', {
      score,
      fluency: Number(data.fluency ?? null),
      overall: Number(data.overall ?? null),
      mistakes,
      response: redacted,
      ...getEvalCtx()
    });
    enable?.([btnNext, btnScore]);
  } catch (e) {
    await debugBeacon('eval_catch', { err: String(e), ...getEvalCtx() });
  toast('评分失败，请重试');
    enable?.([btnScore]);
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
  function markHard(){ session.hard.add(session.idx); toast('已加入多练'); }
  function markEasy(){ session.easy.add(session.idx); toast('已标记为已认识'); }

    function clearResult(){ resultBox.classList.remove('visible'); resultBox.innerHTML=''; }
  function showScoring(){ resultBox.classList.add('visible'); resultBox.innerHTML = `<div class=\"row\"><div>AI 评分中…</div><div class=\"right muted\">~1s</div></div>`; }

  // Robust TTS helper (iOS-friendly)
  function speakWord(word, lang='en-US'){
    try {
      if (!('speechSynthesis' in window) || !word) return;
      const u = new window.SpeechSynthesisUtterance(String(word));
      u.lang = lang; u.rate = 0.95; u.pitch = 1.0; u.volume = 1.0;

      const startSpeak = () => {
        const voices = window.speechSynthesis.getVoices?.() || [];
        const v = voices.find(v => (v.lang||'').toLowerCase().startsWith(lang.toLowerCase()));
        if (v) u.voice = v;
        try { window.speechSynthesis.cancel(); } catch(_) {}
        // iOS quirk: nudge the engine
        try { window.speechSynthesis.pause(); window.speechSynthesis.resume(); } catch(_) {}
        window.speechSynthesis.speak(u);
      };

      if (!window.speechSynthesis.getVoices?.().length) {
        window.speechSynthesis.addEventListener('voiceschanged', startSpeak, { once:true });
        setTimeout(startSpeak, 400);
      } else {
        startSpeak();
      }
    } catch(e) {
      try { debugBeacon?.('tts_error', { err:String(e) }); } catch(_){}
    }
  }

  // Prime TTS once on first user interaction (helps iOS load voices)
  (function primeTTSOnce(){
    const prime = () => {
      try {
        window.speechSynthesis?.getVoices?.();
        const u = new window.SpeechSynthesisUtterance(' ');
        u.volume = 0; u.rate = 1; u.lang = 'en-US';
        try { window.speechSynthesis.cancel(); } catch(_) {}
        setTimeout(() => { try { window.speechSynthesis.speak(u); window.speechSynthesis.cancel(); } catch(_) {} }, 0);
      } catch(_) {}
    };
    document.addEventListener('click', prime, { once:true, passive:true });
    document.addEventListener('touchend', prime, { once:true, passive:true });
  })();
    // Feedback message by score: from encouragement to compliment
    function feedbackForScore(s){
      try {
        const n = Number(s) || 0;
        if (n >= 90) return '太棒了！几乎完美！';
        if (n >= 80) return '做得好！继续保持！';
        if (n >= 70) return '不错！再注意一些细节。';
        if (n >= 60) return '加油！多练几次会更好。';
        return '别气馁，坚持练习会进步。';
      } catch(_) { return '继续努力，加油！'; }
    }

    function showResult(score, mistakes, weakWords) {
      resultBox.classList.add('visible');
  const badge = `<span class=\"badge badge-real\">A.I. 分析结果</span>`;
      const pillsHtml = (mistakes && mistakes.length)
        ? `<div class=\"mistake-wrap\">重点练习：${mistakes.map(m=>`<span class='mistake' tabindex='0' role='button' aria-label='点击朗读'>${m}</span>`).join('')}</div>`
        : '';
      const tipsHtml = (Array.isArray(weakWords) && weakWords.length)
        ? `<div class='muted' style='margin-top:4px'>提示：${weakWords.map(w=>{
              const t = (w.tip||'').replace(/[<>]/g,'');
              const ww = (w.word||'').replace(/[<>]/g,'');
              return `${ww}：${t}`;
            }).join('； ')}</div>`
        : '';
      resultBox.innerHTML = `<div class=\"row\"><div class=\"score\">分數：${score}</div><div class=\"result-badge\">${badge}</div></div>`
        + pillsHtml
        + `<div class='muted' style='margin-top:8px'>${feedbackForScore(score)}</div>`
        + tipsHtml;

      // Wire TTS for each weak-word pill using robust helper
      if (mistakes.length) {
        const pills = resultBox.querySelectorAll('.mistake');
        pills.forEach(pill => {
          const clone = pill.cloneNode(true);
          pill.replaceWith(clone);
          clone.addEventListener('click', () => {
            const word = (clone.textContent || '').trim();
            try { debugBeacon?.('tts_click', { word }); } catch(_){}
            speakWord(word, 'en-US');
          }, false);
        });
      }
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
  nick.title = '由系统设置（邀请码名册）';
  }
});
</script>

</body>
</html>

