<?php
// Copy this file to setup-sa.php and fill in your creds. This file is gitignored by default.
// You can either define constants or use putenv() so eval-sa.php can read them.

define('SPEECHACE_API_KEY',    'paste-your-key-here');
// Optional: override base URL if needed
// define('SPEECHACE_API_URL', 'https://api2.speechace.com');

// Save uploaded audio on server (optional; default off). Set to true to enable.
// This is read by eval-sa.php as MS_SAVE_AUDIO.
// Option A: define a constant
// define('MS_SAVE_AUDIO', true);
// Option B: set an environment variable (string '1' enables)
// putenv('MS_SAVE_AUDIO=1');

// Alternatively, set environment variables (effective for this PHP process):
// putenv('SPEECHACE_API_KEY=paste-your-key-here');
// putenv('SPEECHACE_API_URL=https://api2.speechace.com');

