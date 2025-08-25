<?php
// testmp3.php — quick check: can this server accept uploaded audio files and produce an MP3 copy?
// Place in your web root and open in a browser. Select any audio file and submit.

function humanSize($s){
    if ($s < 1024) return $s . ' B';
    if ($s < 1048576) return round($s/1024,1) . ' KB';
    return round($s/1048576,2) . ' MB';
}

$php_info = [
  'file_uploads' => ini_get('file_uploads'),
  'upload_max_filesize' => ini_get('upload_max_filesize'),
  'post_max_size' => ini_get('post_max_size'),
  'max_file_uploads' => ini_get('max_file_uploads'),
];

$result = null;
// directory to store final mp3 files (web-accessible uploads folder)
$uploadsDir = __DIR__ . '/uploads';
if (!is_dir($uploadsDir)) {
  $mk = @mkdir($uploadsDir, 0755, true);
  if ($mk) @chmod($uploadsDir, 0755);
}
// diagnostics about uploads dir
$uploadsDirExists = is_dir($uploadsDir);
$uploadsDirWritable = is_writable($uploadsDir);

$convertReport = null;
$opkgReport = null;

// handle conversion request for existing test.mp4 -> test.mp3
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['convert_test'])) {
  $src = $uploadsDir . DIRECTORY_SEPARATOR . 'test.mp4';
  $dst = $uploadsDir . DIRECTORY_SEPARATOR . 'test.wav';
  if (!file_exists($src)) {
    $convertReport = ['ok'=>false, 'msg'=>'Source file not found', 'src'=>$src];
  } elseif (!hasFfmpeg()) {
    $convertReport = ['ok'=>false, 'msg'=>'ffmpeg not available on server'];
  } else {
  $escapedIn = escapeshellarg($src);
  $escapedOut = escapeshellarg($dst);
    // Preferred modern command: convert to WAV PCM 16-bit, 16kHz mono
    $cmd1 = "ffmpeg -y -i $escapedIn -vn -ar 16000 -ac 1 -c:a pcm_s16le $escapedOut 2>&1";
    // Fallback for older ffmpeg builds that expect '-acodec' instead of '-c:a'
    $cmd2 = "ffmpeg -y -i $escapedIn -ac 1 -ar 16000 -acodec pcm_s16le $escapedOut 2>&1";
    exec($cmd1, $out1, $rc1);
    if ($rc1 === 0 && file_exists($dst) && filesize($dst) > 0) {
      $convertReport = ['ok'=>true, 'msg'=>'Converted successfully (cmd1)', 'dst'=>$dst, 'cmd'=>$cmd1, 'out' => array_slice($out1, -40)];
    } else {
      // try fallback
      exec($cmd2, $out2, $rc2);
      if ($rc2 === 0 && file_exists($dst) && filesize($dst) > 0) {
        $convertReport = ['ok'=>true, 'msg'=>'Converted successfully (cmd2)', 'dst'=>$dst, 'cmd'=>$cmd2, 'out' => array_slice($out2, -40), 'fallback_used'=>true, 'first_out' => array_slice($out1, -40)];
      } else {
        // report both
        $allOut = array_merge(['--cmd1--'],$out1,['--cmd2--'],$out2);
        $convertReport = ['ok'=>false, 'msg'=>'Conversion failed (both cmds)', 'rc_cmd1'=>$rc1, 'rc_cmd2'=>$rc2, 'out' => $allOut, 'cmd1'=>$cmd1, 'cmd2'=>$cmd2];
      }
    }
  }
}

// handle opkg update request (run from button)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['opkg_update'])) {
  $out = [];
  $rc = 1;
  // run opkg update and capture output
  @exec('opkg update 2>&1', $out, $rc);
  $opkgReport = ['ok' => ($rc === 0), 'rc' => $rc, 'out' => $out];
}

function hasFfmpeg(){
  static $cached = null;
  if ($cached !== null) return $cached;
  $out = null; $rc = 1;
  // prefer which, fallback to -version check
  @exec('which ffmpeg 2>/dev/null', $out, $rc);
  if ($rc === 0 && !empty($out)) { $cached = true; return true; }
  @exec('ffmpeg -version 2>&1', $out, $rc);
  $cached = ($rc === 0);
  return $cached;
}

// handle normal upload POST (skip if conversion button was used)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['convert_test'])) {
  if (!isset($_FILES['audio'])) {
    // collect diagnostics to explain why PHP didn't populate $_FILES['audio']
    $filesCount = count($_FILES);
    $filesKeys = array_keys($_FILES);
    $contentType = $_SERVER['CONTENT_TYPE'] ?? ($_SERVER['HTTP_CONTENT_TYPE'] ?? null);
    $contentLen = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : null;
    // helper to parse php size strings like '8M'
    $sizeToBytes = function($v){ if (!$v) return 0; $u = preg_replace('/[^bkmgtpezy]/i','', $v); $n = (int) filter_var($v, FILTER_SANITIZE_NUMBER_INT); $u = strtoupper($u); $map = ['' => 1, 'K' => 1024, 'M' => 1048576, 'G' => 1073741824]; return $n * ($map[$u] ?? 1); };
    $postMax = ini_get('post_max_size'); $uploadMax = ini_get('upload_max_filesize');
    $postMaxBytes = $sizeToBytes($postMax);
    $likelyTooLarge = ($contentLen !== null && $postMaxBytes && $contentLen > $postMaxBytes);

    $result = ['ok'=>false, 'msg'=>'No file field named "audio" was submitted.',
      'diagnostic' => [
        'files_count' => $filesCount,
        'files_keys' => $filesKeys,
        'content_type' => $contentType,
        'content_length' => $contentLen,
        'post_max_size' => $postMax,
        'upload_max_filesize' => $uploadMax,
        'content_exceeds_post_max' => $likelyTooLarge,
      ]
    ];
  } else {
    $f = $_FILES['audio'];
    if ($f['error'] !== UPLOAD_ERR_OK) {
      $errMap = [
        UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds upload_max_filesize.',
        UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the MAX_FILE_SIZE directive.',
        UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded.',
        UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder on server.',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
        UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload.',
      ];
      $result = ['ok'=>false, 'msg'=>$errMap[$f['error']] ?? ('Upload error code '.$f['error'])];
    } else {
      $tmp = $f['tmp_name'];
      $origName = basename($f['name']);
      $size = $f['size'];

      // detect mime type from file contents
      $mime = null;
      if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) { $mime = finfo_file($fi, $tmp); finfo_close($fi); }
      }
      if (!$mime && function_exists('mime_content_type')) {
        $mime = mime_content_type($tmp);
      }

      $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
      $isWavExt = ($ext === 'wav');
      $isLikelyAudio = $mime && preg_match('#audio/#i', $mime);

      // target path for saved WAV (pcm_s16le 16k mono)
      $targetBase = $uploadsDir . DIRECTORY_SEPARATOR . 'upload_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6));
      $targetWav = $targetBase . '.wav';
      $saved = false; $converted = false; $notes = [];

      // If the uploaded is already WAV by ext or mime, move it directly
      if ($isWavExt || in_array($mime, ['audio/wav','audio/x-wav','audio/wave','audio/vnd.wave'])) {
        if (@move_uploaded_file($tmp, $targetWav)) {
          $saved = true; $converted = false; $notes[] = 'Moved original WAV to uploads.';
        } else {
          // try rename as fallback
          if (@rename($tmp, $targetWav)) {
            $saved = true; $notes[] = 'Renamed original WAV to uploads.';
          } else {
            // try copy as last resort
            if (@copy($tmp, $targetWav)) { $saved = true; $notes[] = 'Copied original WAV to uploads as fallback.'; @unlink($tmp); }
            else { $err = error_get_last(); $notes[] = 'Failed to move original WAV to uploads.'; $notes[] = 'move/rename/copy error: ' . ($err['message'] ?? 'unknown'); }
          }
        }
      } else {
        // attempt conversion with ffmpeg if available
        if (hasFfmpeg()) {
          $escapedIn = escapeshellarg($tmp);
          $escapedOut = escapeshellarg($targetWav);
          // Preferred modern command: convert to 16k mono WAV PCM signed 16-bit
          $cmd1 = "ffmpeg -y -i $escapedIn -vn -ar 16000 -ac 1 -c:a pcm_s16le $escapedOut 2>&1";
          // Fallback for older ffmpeg builds
          $cmd2 = "ffmpeg -y -i $escapedIn -ac 1 -ar 16000 -acodec pcm_s16le $escapedOut 2>&1";
          exec($cmd1, $out1, $rc1);
          if ($rc1 === 0 && file_exists($targetWav) && filesize($targetWav) > 0) {
            $saved = true; $converted = true; $notes[] = 'Converted to WAV using ffmpeg (cmd1).';
            if (file_exists($tmp)) @unlink($tmp);
          } else {
            // try fallback
            exec($cmd2, $out2, $rc2);
            if ($rc2 === 0 && file_exists($targetWav) && filesize($targetWav) > 0) {
              $saved = true; $converted = true; $notes[] = 'Converted to WAV using ffmpeg (cmd2 fallback).';
              if (file_exists($tmp)) @unlink($tmp);
              // include first cmd output for debugging
              $notes[] = 'ffmpeg cmd1 output (last lines): ' . implode('\n', array_slice($out1, -10));
            } else {
              $notes[] = 'ffmpeg conversion failed (both cmds). cmd1 rc=' . intval($rc1) . ', cmd2 rc=' . intval($rc2);
              $notes[] = 'cmd1 last lines: ' . implode('\n', array_slice($out1, -10));
              $notes[] = 'cmd2 last lines: ' . implode('\n', array_slice($out2, -10));
            }
          }
        } else {
          $notes[] = 'ffmpeg not available on server; cannot convert. Saving original file instead.';
          // save original file with original extension to uploads
          $origTarget = $targetBase . '.' . ($ext ?: 'bin');
          if (@move_uploaded_file($tmp, $origTarget) || @rename($tmp, $origTarget)) {
            $saved = true; $converted = false; $notes[] = 'Saved original uploaded file (not converted).';
            // user can convert later on server
          } else {
            // try copy as fallback
            if (@copy($tmp, $origTarget)) { $saved = true; $converted = false; $notes[] = 'Copied original uploaded file to uploads as fallback.'; @unlink($tmp); }
            else { $err = error_get_last(); $notes[] = 'Failed to move original uploaded file to uploads.'; $notes[] = 'move/rename/copy error: ' . ($err['message'] ?? 'unknown'); }
          }
        }
      }

      $result = [
        'ok' => $saved === true,
        'orig_name' => $origName,
        'size' => $size,
        'size_human' => humanSize($size),
        'tmp_name' => $tmp,
        'mime' => $mime,
        'ext' => $ext,
        'is_likely_audio' => (bool)$isLikelyAudio,
        'converted' => $converted,
        'saved_to' => $saved ? ($converted ? $targetWav : (isset($origTarget)?$origTarget:$targetWav)) : null,
        'notes' => $notes,
      ];
    }
  }
}

?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>testmp3 — upload capability check</title>
  <style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial;margin:24px} label{display:block;margin-bottom:8px} .ok{color:green}.bad{color:#b91c1c}</style>
</head>
<body>
  <h1>Audio upload test</h1>
  <p>Click the button below to convert <code>uploads/test.mp4</code> → <code>uploads/test.mp3</code> (server must have ffmpeg).</p>
  <form method="post">
    <button type="submit" name="convert_test" value="1">Convert uploads/test.mp4 → uploads/test.mp3</button>
  </form>

  <h2>PHP upload settings</h2>
  <ul>
    <li>file_uploads: <strong><?php echo htmlspecialchars($php_info['file_uploads']); ?></strong></li>
    <li>upload_max_filesize: <strong><?php echo htmlspecialchars($php_info['upload_max_filesize']); ?></strong></li>
    <li>post_max_size: <strong><?php echo htmlspecialchars($php_info['post_max_size']); ?></strong></li>
    <li>max_file_uploads: <strong><?php echo htmlspecialchars($php_info['max_file_uploads']); ?></strong></li>
  </ul>

  <form method="post" style="margin-top:12px">
    <button type="submit" name="opkg_update" value="1">Run opkg update (show output)</button>
  </form>

<!-- no upload form — only conversion button and result -->

<?php if ($convertReport !== null): ?>
  <h2>Conversion result</h2>
  <?php if ($convertReport['ok'] ?? false): ?>
    <p class="ok"><strong>Success:</strong> <?php echo htmlspecialchars($convertReport['msg'] ?? 'Converted'); ?></p>
  <?php else: ?>
    <p class="bad"><strong>Failure:</strong> <?php echo htmlspecialchars($convertReport['msg'] ?? 'Conversion failed'); ?></p>
  <?php endif; ?>
  <pre style="background:#f7f7f7;padding:8px;border-radius:6px;max-height:240px;overflow:auto"><?php echo htmlspecialchars(json_encode($convertReport, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)); ?></pre>
<?php endif; ?>

<?php if ($opkgReport !== null): ?>
  <h2>opkg update result</h2>
  <?php if ($opkgReport['ok'] ?? false): ?>
    <p class="ok"><strong>Success:</strong> exit code <?php echo intval($opkgReport['rc']); ?></p>
  <?php else: ?>
    <p class="bad"><strong>Failure:</strong> exit code <?php echo intval($opkgReport['rc']); ?></p>
  <?php endif; ?>
  <pre style="background:#f7f7f7;padding:8px;border-radius:6px;max-height:360px;overflow:auto"><?php echo htmlspecialchars(implode("\n", (array)($opkgReport['out'] ?? []))); ?></pre>
<?php endif; ?>
</body>
</html>
