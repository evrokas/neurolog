<?php
/**
 * NeuroLog front controller (the only entry point; web/.htaccess rewrites everything else here).
 * Kernel::boot() runs the ZeusFW request sequence; route handlers live in pages.php.
 */
include_once(__DIR__ . "/../config/db.php");

require_once(__FWDIR__ . '/bootstrap.php');
require_once(__DIR__ . '/nl_id.php');
require_once(__DIR__ . '/vocab.php');
require_once(__DIR__ . '/operations.php');
require_once(__DIR__ . '/quickline.php');
require_once(__DIR__ . '/operation_pages.php');
require_once(__DIR__ . '/settings_pages.php');
require_once(__DIR__ . '/pages.php');

$kernel = new Kernel($_SERVER, "../config");

$kernel->boot([
    'csrf_login'       => true,
    'csrf_webforms'    => true,
    'login_lockout'    => true,
    'login_redirect'   => '/login',
    'default_language' => 'en',
]);
