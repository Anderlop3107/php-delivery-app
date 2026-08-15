<?php

$dir = __DIR__ . '/pages/';
$files = glob($dir . 'api_*.php');

foreach ($files as $file) {
    $content = file_get_contents($file);
    $basename = basename($file);
    
    // Saltamos si ya tiene rate_limit_check
    if (strpos($content, 'rate_limit_check') !== false) {
        continue;
    }
    
    // Determinar si es una API que necesita CSRF (casi todas las POST)
    $needsCsrf = false;
    if (strpos($content, '$_POST') !== false || $basename === 'api_update_status.php' || $basename === 'api_toggle_status.php' || $basename === 'api_driver_upload_payment.php') {
        $needsCsrf = true;
    }
    // Excepciones donde no forzamos CSRF por simplicidad o porque son GET
    if (in_array($basename, ['api_check_new_orders.php', 'api_get_active_deliveries.php', 'api_get_order_live_location.php', 'api_check_approval.php', 'api_driver_active_count.php', 'api_update_location.php'])) {
        $needsCsrf = false;
    }
    
    $injection = "\n// --- SEGURIDAD ---\n";
    $injection .= "if (!rate_limit_check('{$basename}', 120, 60)) {\n    rate_limit_deny();\n}\n";
    if ($needsCsrf) {
        $injection .= "csrf_require();\n";
    }
    $injection .= "// -----------------\n";

    // Insertar después del último header('...') o ob_clean()
    $pattern = '/header\([^)]+\);\s*/i';
    if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
        $lastMatch = end($matches[0]);
        $pos = $lastMatch[1] + strlen($lastMatch[0]);
        $newContent = substr($content, 0, $pos) . $injection . substr($content, $pos);
        file_put_contents($file, $newContent);
        echo "Inyectado en $basename\n";
    }
}
