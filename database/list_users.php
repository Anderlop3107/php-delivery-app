<?php
require_once __DIR__ . '/../lib/db.php';
$u = app_one("SELECT id, email, business_name FROM users WHERE email = 'local1@delivery.com'");
print_r($u);
