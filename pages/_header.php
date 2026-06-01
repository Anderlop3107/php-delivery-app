<?php

declare(strict_types=1);

$title = $title ?? 'Delivery App';
$user = current_user();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= esc($title) ?></title>
    <style>
        :root {
            --bg: #ffffff;
            --card: #f8fafc;
            --text: #0f172a;
            --muted: #64748b;
            --primary: #FF8C42;
            --primary-soft: rgba(255, 140, 66, 0.1);
            --danger: #e11d48;
            --border: #e2e8f0;
        }
        * { box-sizing: border-box; }
        body { 
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; 
            margin: 0; 
            background: var(--bg); 
            color: var(--text); 
            line-height: 1.5; 
            -webkit-font-smoothing: antialiased;
        }
        
        /* Floating Capsule Navigation */
        .bottom-nav {
            position: fixed;
            bottom: 25px;
            left: 20px;
            right: 20px;
            background: #ffffff;
            display: flex;
            justify-content: space-around;
            align-items: center;
            height: 65px;
            border-radius: 40px;
            z-index: 1000;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
            padding: 0 10px;
            border: 1px solid var(--border);
        }
        .nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-decoration: none;
            color: #94a3b8;
            flex: 1;
            padding: 5px 0;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .nav-item.active {
            color: var(--primary);
        }
        .nav-item svg {
            width: 22px;
            height: 22px;
        }
        .nav-item span {
            font-size: 10px;
            font-weight: 500;
            margin-top: 4px;
            display: none; /* Minimalist line icons style */
        }
        .nav-item.add-btn {
            position: relative;
            background: var(--primary);
            color: white;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            flex: none;
            opacity: 1;
            box-shadow: 0 4px 15px rgba(255, 140, 66, 0.3);
            justify-content: center;
            transform: translateY(-5px);
        }
        .nav-item.add-btn svg {
            width: 28px;
            height: 28px;
        }
        
        .wrap { max-width: 500px; margin: 0 auto; padding: 25px 20px 120px; }
        .card { 
            background: #ffffff; 
            border-radius: 28px; 
            border: 1px solid var(--border); 
            padding: 24px; 
            margin-bottom: 20px; 
            box-shadow: 0 4px 20px rgba(0,0,0,0.05); 
        }
        
        h1, h2, h3 { margin-top: 0; color: var(--text); }
        .muted { color: var(--muted); font-size: 14px; }
        
        input, select, textarea { 
            width: 100%; 
            border: 1px solid var(--border); 
            border-radius: 16px; 
            padding: 14px 18px; 
            background: #f1f5f9; 
            color: var(--text);
            font-size: 15px; 
            transition: all 0.2s; 
        }
        input:focus { outline: none; border-color: var(--primary); background: #ffffff; }
        
        button, .btn { 
            border: 0; 
            border-radius: 16px; 
            padding: 16px 24px; 
            background: var(--primary); 
            color: #fff; 
            font-weight: 700; 
            cursor: pointer; 
            text-decoration: none; 
            display: inline-block; 
            text-align: center; 
            font-size: 16px; 
            transition: transform 0.2s, opacity 0.2s; 
        }
        button:active, .btn:active { transform: scale(0.98); opacity: 0.9; }
        
        .status-badge { display: inline-block; padding: 6px 14px; border-radius: 999px; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; }
    </style>
</head>
<body>

<?php if ($user): ?>
    <nav class="bottom-nav">
        <a href="<?= delivery_app_url('dashboard.php') ?>" class="nav-item <?= str_contains($_SERVER['PHP_SELF'], 'dashboard.php') ? 'active' : '' ?>">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path></svg>
        </a>
        <a href="<?= delivery_app_url('pages/my_deliveries.php') ?>" class="nav-item <?= str_contains($_SERVER['PHP_SELF'], 'my_deliveries.php') ? 'active' : '' ?>">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"></path></svg>
        </a>
        <a href="<?= delivery_app_url('pages/create_delivery.php') ?>" class="nav-item add-btn">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"></path></svg>
        </a>
        <a href="<?= delivery_app_url('pages/history.php') ?>" class="nav-item <?= str_contains($_SERVER['PHP_SELF'], 'history.php') ? 'active' : '' ?>">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
        </a>
        <a href="<?= delivery_app_url('pages/profile.php') ?>" class="nav-item <?= str_contains($_SERVER['PHP_SELF'], 'profile.php') ? 'active' : '' ?>">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
        </a>
    </nav>
<?php endif; ?>

<div class="wrap">
