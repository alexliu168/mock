# Copilot Project Instructions

Purpose: Lightweight browser + PHP demo for English service phrase practice. Features: invite-code session auth, audio recording + external speech scoring (Tencent SOE legacy `eval.php` and SpeechAce primary `eval-sa.php`), per-user and overall CSV–driven reporting, minimal PWA shell.

## Architecture Snapshot
- Entry/Login: `v1/morningsir.php` handles session cookie (14‑day) + inline invite code form. On success injects `SESSION_USER` + `DISPLAY_NAME` into first `<script>` of `mainapp.html`. All references/redirects (manifest `start_url`, redirects in `eval.php`, `eval-sa.php`) point to `morningsir.php`.
- Frontend: Single-page `v1/mainapp.html` (≈1000 lines) with inline JS (no build step) managing tabs (home/practice/reports/profile), localStorage (nick + stats), audio capture (MediaRecorder or fallback), scoring fetch to `eval-sa.php` (SpeechAce) or legacy `eval.php` (Tencent). Assets under `v1/assets/**`.
- Speech Evaluation Endpoints:
  - `eval-sa.php`: Primary endpoint. Accepts multipart (audio + text [+ flags]) and calls SpeechAce API (`/api/scoring/text/v9/json`). Produces compact JSON plus optional Simplified Chinese HTML summary (`summary_zh`). Implements: strict session reuse (never creates new), optional audio persistence (`save_audio`), JSONL server logging via `append_eval_server_log()` to `uploads/evallog/evalresult-<MM-DD>.log`.
  - `eval.php`: Legacy Tencent SOE sentence-only flow. Validates single sentence constraints (≤35 words, one terminator, ≤220 chars). Performs TC3 signing manually for `InitOralProcess` + single `TransmitOralProcess` chunk. Returns raw API responses + summary.
- Client Logging: `log.php` writes per-user JSON lines to `uploads/<CODE>/logs/client.log` (debug mode only when `MS_DEBUG` true). Redacts `text` fields for `eval_*` events.
- Reports: CSV store `v1/reports/practice_data.csv` (headers: ts,user_id,phrase_uid,request_id,pron,flu,ielts_pron,ielts_flu,speech_rate,pause_count,attempt_no). Rendered by:
  - `reports/report_individual.php`: Enforces session; non‑Admin users can only view self (`user_id` from session). Options: `excludeZeros=1`, `dailyAvg=0|1` (default on unless query present), `showScatter=1`, `trend_phrase=<phrase_uid>`.
  - `reports/report_overall.php`: No auth gate here; consider restricting if exposing publicly. Option: `excludeZeros=1`.
- PWA: `manifest.json` points `start_url` to `morningsir.php`. App is now in production; all components reference the production entry point consistently.

## Conventions & Patterns
- Session auth: Presence of `$_SESSION['invite_code']`; Admin capabilities when `invite_label/name` starts with `Admin` (e.g., shows overall report button, cross-user report access).
- Invite codes + labels: `lib/invitecodes.txt` lines: CODE,Optional Label (case-insensitive, `#` comments). Multiple loaders (`load_codes`, `ms_load_codes`). Keep parsing consistent if modifying.
- Logging style: One JSON object per line, UTF-8, with `JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES`. Avoid leaking raw evaluated `text` in client eval events (see redaction list in `log.php`).
- Error responses: `eval-sa.php` uses `oops(<http>, <msg>, extra)` returning `{status:'error', message, ...}`; success sets `status:'ok'`. Legacy `eval.php` uses `out_json(['ok'=>true|false,...])`.
- Security boundaries: `eval-sa.php` refuses to start sessions; expects pre-existing cookie. Do not add `session_start()` without checking; emulate the existing guarded logic to prevent session fixation.
- SpeechAce enhancements: Word-level weak word extraction (<60) plus heuristic tips; Chinese HTML summary built by `summarizeSpeechAce()` (keep style names `sa-*`). When extending, ensure large responses still redact sensitive text in logs.
- File writes: Always create directories with `@mkdir($dir, 0775, true)`; use exclusive append with `LOCK_EX`.
- Audio handling: `eval-sa.php` optionally saves original user upload after API call (naming with SpeechAce `request_id` when available). Maintain that ordering (evaluation before save) to incorporate `req_id`.
- Frontend scoring flow: Buttons `btnRecord` -> capture Blob -> enable `btnPlayback` & `btnScore`. Score request posts to `EVAL_URL` (`eval-sa.php`) with `FormData`. Expect response fields: `overall`, `pronunciation`, `fluency`, `weak_words[] {word,score,tip}`, optional `summary_zh`.
- Admin UI indicator: In JS, `isAdmin = SESSION_USER.name startsWith('Admin')` — altering naming scheme affects gating.

## When Editing / Extending
- Keep endpoints stateless beyond session + file logging; avoid introducing DB dependencies unless adding a documented migration path.
- For new evaluation providers, follow `eval-sa.php` pattern: strict input validation, centralized error helper, append JSONL log with status markers (e.g., `speechace_response`).
- If adding fields to `practice_data.csv`, update both report scripts’ header handling and JSON encoding blocks (maintain order & skip malformed lines).
- Preserve sentence constraints in `eval.php` if reusing for other providers (clients assume single-sentence mode).
- Any new client logs with potentially sensitive text should extend redaction list in `log.php`.

## Local Dev & Testing Notes
- No build step: edit PHP/HTML directly. Ensure web server has `curl` + `openssl` extensions for Tencent/SpeechAce calls.
- Setup: Copy `lib/setup-sa.sample.php` to `lib/setup-sa.php` and configure `SPEECHACE_API_KEY`. Optional settings: `SPEECHACE_API_URL`, `MS_SAVE_AUDIO`.
- Audio generation: `makemp3/makemp3.py` generates MP3 files from text using Tencent TTS. Requires `TENCENT_SECRET_ID` and `TENCENT_SECRET_KEY` environment variables.
- Health check: `GET eval.php?ping=1` returns environment flags (`curl_loaded`, `openssl_loaded`, `session`).
- To trace evaluation issues: tail `uploads/evallog/evalresult-<MM-DD>.log` or user `uploads/<CODE>/logs/client.log` (enable `window.MS_DEBUG=true` early).

## Quick Reference
- Primary eval POST: audio (file), text (string), optional: dialect, user_id, include_fluency=1, include_intonation=1, include_summary=0|1, save_audio=1.
- Weak words threshold: 60; adjust in `eval-sa.php` if UX changes.
- Score synthesis fallback: overall ≈ 0.7*pron + 0.3*fluency when SpeechAce `overall` missing.

(End of instructions — keep concise; update when adding new providers, CSV columns, or auth model changes.)
