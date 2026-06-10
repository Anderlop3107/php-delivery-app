<?php

declare(strict_types=1);

$title = $title ?? 'Delivery App';
$user = current_user();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= esc($title) ?></title>
    <!-- Modern Sans Serif: Inter -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #f8fafc;
            --card: #ffffff;
            --text: #111827;
            --muted: #64748b;
            --primary: #2563eb; /* Azul Eléctrico */
            --primary-soft: rgba(37, 99, 235, 0.08);
            --danger: #ef4444;
            --border: #e2e8f0;
            --card-radius: 24px;
            --shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.04), 0 4px 6px -2px rgba(0, 0, 0, 0.01);
            --glass: rgba(255, 255, 255, 0.8);
        }
        * { 
            box-sizing: border-box; 
            -webkit-font-smoothing: antialiased; 
            -moz-osx-font-smoothing: grayscale;
        }
        body { 
            font-family: 'Inter', sans-serif; 
            margin: 0; 
            background: var(--bg); 
            color: var(--text); 
            line-height: 1.5; 
        }
        
        /* High-Fidelity iOS Hybrid Navigation */
        .bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: var(--glass);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            display: flex;
            justify-content: space-around;
            align-items: center;
            height: 85px;
            padding-bottom: env(safe-area-inset-bottom);
            border-top: 1px solid rgba(0,0,0,0.05);
            z-index: 2000;
        }
        .nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-decoration: none;
            color: #94a3b8;
            flex: 1;
            padding: 8px 0;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .nav-item.active {
            color: var(--primary);
        }
        .nav-item svg {
            width: 24px;
            height: 24px;
            stroke-width: 2.2;
        }
        .nav-item span {
            font-size: 10px;
            font-weight: 600;
            margin-top: 4px;
            letter-spacing: 0.2px;
        }
        
        /* Material 3 Floating Action Button */
        .nav-item.add-btn {
            position: relative;
            top: -15px;
            background: var(--primary);
            color: white;
            border-radius: 20px;
            width: 58px;
            height: 58px;
            flex: none;
            box-shadow: 0 10px 25px rgba(37, 99, 235, 0.3);
            justify-content: center;
        }
        .nav-item.add-btn svg { width: 28px; height: 28px; color: #fff; }
        
        /* Bento Grid Wrapper */
        .wrap { max-width: 500px; margin: 0 auto; padding: 20px 20px 110px; }
        
        /* Modular Card System */
        .card { 
            background: var(--card); 
            border-radius: var(--card-radius); 
            padding: 24px; 
            margin-bottom: 16px; 
            box-shadow: var(--shadow);
            border: 1px solid rgba(0,0,0,0.02);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        
        h1, h2, h3 { margin: 0; font-weight: 800; letter-spacing: -0.025em; color: var(--text); }
        .muted { color: var(--muted); font-size: 14px; font-weight: 500; }
        
        /* Minimal Tech Inputs */
        input, select, textarea { 
            width: 100%; 
            border: 1px solid var(--border); 
            border-radius: 16px; 
            padding: 14px 18px; 
            background: #ffffff; 
            color: var(--text);
            font-size: 15px; 
            font-weight: 500;
            transition: all 0.2s; 
        }
        input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 4px var(--primary-soft); }
        
        /* iOS Refined Buttons */
        button, .btn { 
            border: 0; 
            border-radius: 18px; 
            padding: 16px 24px; 
            background: var(--primary); 
            color: #fff; 
            font-weight: 700; 
            cursor: pointer; 
            text-decoration: none; 
            display: inline-block; 
            text-align: center; 
            font-size: 16px; 
            transition: all 0.2s; 
        }
        button:active, .btn:active { transform: scale(0.97); opacity: 0.9; }
        
        .status-pill { 
            display: inline-block; 
            padding: 6px 14px; 
            border-radius: 12px; 
            font-size: 11px; 
            font-weight: 700; 
            text-transform: uppercase; 
            letter-spacing: 0.3px; 
        }
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
