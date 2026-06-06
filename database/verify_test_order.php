<?php
require_once __DIR__ . '/../lib/db.php';
$orders = app_all("SELECT id, local_user_id, created_at, status FROM deliveries ORDER BY id DESC LIMIT 5");
echo "Last 5 orders with owner:\n";
foreach($orders as $o) {
    echo "ID: {$o['id']} | Local UID: {$o['local_user_id']} | Date: {$o['created_at']} | Status: {$o['status']}\n";
}
