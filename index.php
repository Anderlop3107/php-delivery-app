<?php
require_once __DIR__ . '/bootstrap.php';
// Si el usuario está logueado, auth.php lo dejará pasar en dashboard.php
// Si no está logueado, lo redirigirá a login.php automáticamente.
header('Location: ' . delivery_app_url('dashboard.php'));
exit;
