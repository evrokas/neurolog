<?php
// Shared start-up for bin/ scripts: database constants, the framework's classes, the app's class extensions,
// a database connection and the app's pure helpers. CLI only.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// the app's timezone (config/settings.info.yaml `tz`), so "today" is the same here as in web requests
if (preg_match('/^tz:\s*"?([A-Za-z_\/+-]+)"?\s*$/m', (string)@file_get_contents(__DIR__ . '/../../config/settings.info.yaml'), $tzm)) @date_default_timezone_set($tzm[1]);
chdir(__DIR__ . '/../../web');
include_once(__DIR__ . '/../../config/db.php');
require_once(__FWDIR__ . '/bootstrap.php');
if (is_file('ClassesEx.php')) include_once('ClassesEx.php');
dbConnection::init(DB_HOST, DB_USER, DB_PASS, DB_NAME);
require_once(__DIR__ . '/../../web/nl_id.php');
