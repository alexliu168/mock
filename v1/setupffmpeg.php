<?php
// setup_ffmpeg.php — stage ffmpeg into /tmp and verify, no conversion

header('Content-Type: text/plain');

// 1) Source and destination
$SRC = "/share/Public/bin/ffmpeg";   // your uploaded binary
$DST = "/tmp/ffmpeg";                // exec-friendly location

function run($cmd) {
  $out = []; $rc = 0;
  exec($cmd . " 2>&1", $out, $rc);
  return [$rc, implode("\n", $out)];
}

// 2) Basic checks
if (!file_exists($SRC)) {
  http_response_code(500);
  die("ERROR: Source binary not found at $SRC\n");
}
if (!is_readable($SRC)) {
  http_response_code(500);
  die("ERROR: Source binary not readable: $SRC\n");
}

// 3) Copy to /tmp (overwrite to be safe)
if (!@copy($SRC, $DST)) {
  http_response_code(500);
  die("ERROR: copy() to $DST failed. Check PHP permissions.\n");
}

// 4) Make it executable *in /tmp* (don’t try on /share/Public)
if (!@chmod($DST, 0755)) {
  http_response_code(500);
  die("ERROR: chmod +x failed on $DST\n");
}

// 5) Verify it runs
list($rcVer, $verOut) = run("$DST -version");
echo "=== ffmpeg -version rc=$rcVer ===\n$verOut\n\n";
if ($rcVer !== 0) {
  http_response_code(500);
  die("ERROR: /tmp/ffmpeg did not execute. This is usually a dynamic build needing libs. Use a STATIC build.\n");
}

// 6) Check AAC decoder availability (required to read MP4 AAC audio)
list($rcDec, $decOut) = run("$DST -hide_banner -decoders");
$hasAAC = (stripos($decOut, " aac") !== false); // crude but effective
echo "=== AAC decoder present? ===\n" . ($hasAAC ? "YES\n" : "NO\n");

// Optional: show where we staged it
echo "\nStaged ffmpeg at: $DST\n";

// 7) (Optional) confirm /tmp is executable by actually running a no-op
// Already confirmed via -version; nothing more to do.

echo "\nSetup complete. No conversion was performed.\n";
