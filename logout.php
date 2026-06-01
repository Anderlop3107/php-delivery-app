<?php
require_once __DIR__ . '/bootstrap.php';
session_destroy();
header('Location: ' . delivery_app_url('login.php'));
exit;
