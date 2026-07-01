<?php

declare(strict_types=1);

// Zona horaria local (Paraguay UTC-4 / UTC-3 verano)
date_default_timezone_set('America/Asuncion');

// Modern Security Headers
header("Content-Security-Policy: default-src 'self' https: 'unsafe-inline' 'unsafe-eval' data:; worker-src 'self' blob:; child-src 'self' blob:; frame-ancestors 'none';");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/deliveries.php';
