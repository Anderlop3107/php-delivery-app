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
    <meta name="csrf-token" content="<?= csrf_token() ?>">
    <title><?= esc($title ?? 'Goo! - Dashboard') ?></title>
    <link rel="icon" href="data:,">
    
    <!-- PWA Meta Tags -->
    <link rel="manifest" href="<?= delivery_app_url('manifest.json') ?>">
    <meta name="theme-color" content="#1d4ed8">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Goo! Envíos">
    <link rel="icon" type="image/png" href="<?= delivery_app_url('assets/img/goologo.png') ?>">
    <link rel="apple-touch-icon" href="<?= delivery_app_url('assets/img/goologo.png') ?>">

    <!-- Service Worker Registration -->
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('<?= delivery_app_url('sw.js') ?>');
            });
        }
    </script>
    <!-- Modern Sans Serif: Inter -->
    <script src="https://api.mapbox.com/mapbox-gl-js/v3.0.1/mapbox-gl.js"></script>
    <link href="https://api.mapbox.com/mapbox-gl-js/v3.0.1/mapbox-gl.css" rel="stylesheet" />

    <!-- CSRF Global Fetch Interceptor -->
    <script>
        (function() {
            const originalFetch = window.fetch;
            window.fetch = async function() {
                let [resource, config] = arguments;
                if (config && config.method && config.method.toUpperCase() === 'POST') {
                    const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
                    if (token) {
                        config.headers = config.headers || {};
                        config.headers['X-CSRF-TOKEN'] = token;
                    }
                }
                return originalFetch(resource, config);
            };
        })();
    </script>
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
            /* Safe Areas Globales PWA */
            --sat: env(safe-area-inset-top, 0px);
            --sab: env(safe-area-inset-bottom, 0px);
            --sal: env(safe-area-inset-left, 0px);
            --sar: env(safe-area-inset-right, 0px);
        }
        * { 
            box-sizing: border-box; 
            -webkit-font-smoothing: antialiased; 
            -moz-osx-font-smoothing: grayscale;
            /* Estándares Táctiles PWA */
            -webkit-tap-highlight-color: transparent;
        }
        body { 
            font-family: 'Inter', sans-serif; 
            margin: 0; 
            background: var(--bg); 
            color: var(--text); 
            line-height: 1.5; 
            /* App Shell PWA */
            min-height: 100dvh;
            display: flex;
            flex-direction: column;
            padding-top: var(--sat);
            padding-left: var(--sal);
            padding-right: var(--sar);
        }
        /* Contenedor central PWA - Fluido de borde a borde en móvil */
        .app-container, main {
            flex: 1;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            width: 100%;
            max-width: 480px;
            margin-left: auto;
            margin-right: auto;
            padding-left: 16px;
            padding-right: 16px;
            box-sizing: border-box;
            padding-bottom: calc(var(--sab) + 85px);
        }
        /* Forzar ancho completo en vistas de página - evita que align-items: center encoja el contenido */
        .driver-scanner-view,
        .local-view,
        .page-view,
        body > div:not(.bottom-nav):not(.modal):not([style*="position: fixed"]) {
            width: 100% !important;
            max-width: 100% !important;
            box-sizing: border-box !important;
        }
        /* Microanimaciones y Accesibilidad PWA */
        button, a.btn, .touch-target {
            transition: transform 150ms ease-out;
        }
        button:active, a.btn:active, .touch-target:active {
            transform: scale(0.98);
        }
        
        /* High-Fidelity iOS Hybrid Navigation */
        .bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: var(--glass);
            -webkit-backdrop-filter: blur(20px);
            backdrop-filter: blur(20px);
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
        
        /* Bento Grid Wrapper - 16px padding estándar móvil */
        .wrap {
            width: 100%;
            max-width: 480px;
            margin: 0 auto;
            padding: 16px 16px 110px;
            box-sizing: border-box;
            flex: 1;
        }
        
        /* Modular Card System - Ancho completo sin márgenes laterales */
        .card, .stat-card, .chart-card, .status-card, .op-card { 
            background: var(--card); 
            border-radius: var(--card-radius); 
            padding: 20px; 
            margin-bottom: 16px;
            margin-left: 0 !important;
            margin-right: 0 !important;
            width: 100% !important;
            box-shadow: var(--shadow);
            border: 1px solid rgba(0,0,0,0.02);
            box-sizing: border-box !important;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .bento-grid {
            width: 100% !important;
            box-sizing: border-box !important;
            margin-left: 0 !important;
            margin-right: 0 !important;
        }

        /* Header de fondo de borde a borde */
        .header-bg, .header-bg-gradient, .page-header {
            width: calc(100% + 32px) !important;
            margin-left: -16px !important;
            margin-right: -16px !important;
            padding-left: 16px !important;
            padding-right: 16px !important;
            box-sizing: border-box !important;
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
        
        @keyframes fadeInUp {
            from { opacity: 0; transform: translate(-50%, 20px); }
            to { opacity: 1; transform: translate(-50%, 0); }
        }

        /* ── Nav badge (subscription alert) ─────────────────── */
        .nav-badge-wrapper { position: relative; display: flex; flex: 1; justify-content: center; }
        .nav-badge-wrapper .nav-item { flex: none; }
        .nav-badge-dot {
            position: absolute;
            top: 6px;
            right: calc(50% - 20px);
            width: 9px; height: 9px;
            border-radius: 50%;
            background: #ef4444;
            border: 2px solid var(--glass);
            animation: navBadgePulse 1.8s ease-in-out infinite;
            z-index: 10;
        }
        @keyframes navBadgePulse {
            0%, 100% { transform: scale(1);   box-shadow: 0 0 0 0 rgba(239,68,68,0.5); }
        /* ---------------------------------------------------- */
        /* SKELETON UI & SHIMMER LOADING ANIMATIONS SYSTEM      */
        /* ---------------------------------------------------- */
        .skeleton-loader {
            position: relative;
            overflow: hidden !important;
            background-color: #e2e8f0 !important;
            color: transparent !important;
            border-color: transparent !important;
            pointer-events: none !important;
            user-select: none !important;
            border-radius: 12px;
        }

        .skeleton-loader::after {
            position: absolute;
            top: 0;
            right: 0;
            bottom: 0;
            left: 0;
            transform: translateX(-100%);
            background-image: linear-gradient(
                90deg,
                rgba(255, 255, 255, 0) 0,
                rgba(255, 255, 255, 0.45) 20%,
                rgba(255, 255, 255, 0.75) 60%,
                rgba(255, 255, 255, 0)
            );
            animation: skeleton-shimmer 1.6s infinite ease-in-out;
            content: '';
            z-index: 99;
        }

        @keyframes skeleton-shimmer {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }

        /* Specific Primitive Skeleton Shapes */
        .skeleton-box { display: block; border-radius: 12px; }
        .skeleton-circle { border-radius: 50% !important; }
        .skeleton-text { height: 14px; border-radius: 6px; margin-bottom: 8px; width: 100%; }
        .skeleton-text.short { width: 45%; }
        .skeleton-text.medium { width: 70%; }
        .skeleton-pill { height: 24px; border-radius: 20px; width: 80px; }

        /* Skeleton Card Wrapper */
        .skeleton-card-wrap {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 20px;
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 12px;
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.03);
        }
        .skeleton-row {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .skeleton-table-row td {
            padding: 16px 12px;
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
        <?php if ($user && $user['role'] === 'repartidor'): ?>
            <a href="<?= delivery_app_url('pages/driver_balance.php') ?>" class="nav-item add-btn <?= str_contains($_SERVER['PHP_SELF'], 'driver_balance.php') ? 'active' : '' ?>">
                <!-- Wallet/ATM Icon -->
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a2.25 2.25 0 0 0-2.25-2.25H15a3 3 0 1 1-6 0H5.25A2.25 2.25 0 0 0 3 12m18 0v6a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 18v-6m18 0V9M3 12V9m18 0a2.25 2.25 0 0 0-2.25-2.25H5.25A2.25 2.25 0 0 0 3 9m18 0V6a2.25 2.25 0 0 0-2.25-2.25H5.25A2.25 2.25 0 0 0 3 6v3"></path></svg>
            </a>
        <?php elseif ($user && $user['role'] === 'admin'): ?>
            <a href="<?= delivery_app_url('dashboard.php') ?>" class="nav-item add-btn <?= str_contains($_SERVER['PHP_SELF'], 'admin_dashboard.php') ? 'active' : '' ?>">
                <!-- System Dashboard Icon -->
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 002 2h2a2 2 0 002-2z"></path></svg>
            </a>
        <?php else: ?>
            <a href="<?= delivery_app_url('pages/create_delivery.php') ?>" class="nav-item add-btn">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"></path></svg>
            </a>
        <?php endif; ?>
        <a href="<?= delivery_app_url('pages/history.php') ?>" class="nav-item <?= str_contains($_SERVER['PHP_SELF'], 'history.php') ? 'active' : '' ?>">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
        </a>
        <?php
            // Show nav badge only for local users with non-active subscription
            if ($user && $user['role'] === 'local') {
                $navUserFull = app_one("
                    SELECT u.subscription_status,
                           (SELECT dp.status FROM driver_payments dp WHERE dp.driver_user_id = u.id ORDER BY dp.id DESC LIMIT 1) as last_payment_status
                    FROM users u WHERE u.id = ?
                ", 'i', [(int)$user['id']]);
                $showNavBadge = $navUserFull && ($navUserFull['subscription_status'] !== 'active');
            } else {
                $showNavBadge = false;
            }
        ?>
        <?php if ($showNavBadge): ?>
        <div class="nav-badge-wrapper">
            <a href="<?= delivery_app_url('pages/profile.php') ?>" class="nav-item <?= str_contains($_SERVER['PHP_SELF'], 'profile.php') ? 'active' : '' ?>">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
            </a>
            <span class="nav-badge-dot" title="Acción requerida: activá tu suscripción"></span>
        </div>
        <?php else: ?>
        <a href="<?= delivery_app_url('pages/profile.php') ?>" class="nav-item <?= str_contains($_SERVER['PHP_SELF'], 'profile.php') ? 'active' : '' ?>">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
        </a>
        <?php endif; ?>
    </nav>

    <?php if ($user): ?>
    <script>
        // Global Skeleton UI Helper System
        window.SkeletonUI = {
            renderTableRows: function(columnsCount = 5, rowsCount = 4) {
                let html = '';
                for (let i = 0; i < rowsCount; i++) {
                    html += `<tr class="skeleton-table-row">`;
                    for (let j = 0; j < columnsCount; j++) {
                        const widthClass = (j === 0) ? 'medium' : (j === columnsCount - 1 ? 'short' : '');
                        html += `<td><div class="skeleton-loader skeleton-text ${widthClass}" style="margin:0;"></div></td>`;
                    }
                    html += `</tr>`;
                }
                return html;
            },
            renderCardGrid: function(count = 3) {
                let html = '';
                for (let i = 0; i < count; i++) {
                    html += `
                        <div class="skeleton-card-wrap">
                            <div class="skeleton-row">
                                <div class="skeleton-loader skeleton-circle" style="width: 44px; height: 44px; flex-shrink: 0;"></div>
                                <div style="flex:1;">
                                    <div class="skeleton-loader skeleton-text medium" style="height:14px;"></div>
                                    <div class="skeleton-loader skeleton-text short" style="height:11px; margin:0;"></div>
                                </div>
                            </div>
                            <div class="skeleton-loader skeleton-text" style="height:12px; margin-top:6px;"></div>
                            <div class="skeleton-loader skeleton-text short" style="height:12px; margin:0;"></div>
                        </div>
                    `;
                }
                return html;
            }
        };

        // Global Audio Context Manager for Autoplay Unlocking on Mobile
        let myAudioContext = null;
        let audioUnlocked = false;

        function initAudioContext() {
            if (audioUnlocked && myAudioContext && myAudioContext.state === 'running') return;
            const AudioContextClass = window.AudioContext || window.webkitAudioContext;
            if (!AudioContextClass) return;

            // Evitar inicializar AudioContext si la política de autoplay requiere un gesto previo del usuario
            if (navigator.userActivation && !navigator.userActivation.hasBeenActive) {
                return;
            }

            if (!myAudioContext) {
                try {
                    myAudioContext = new AudioContextClass();
                } catch (e) {
                    return;
                }
            }
            if (myAudioContext) {
                if (myAudioContext.state === 'suspended') {
                    myAudioContext.resume().then(() => {
                        if (myAudioContext && myAudioContext.state === 'running') {
                            audioUnlocked = true;
                            hideAudioBanner();
                        }
                    }).catch(() => {});
                } else if (myAudioContext.state === 'running') {
                    audioUnlocked = true;
                    hideAudioBanner();
                }
            }
        }

        function unlockAudio() {
            const AudioContextClass = window.AudioContext || window.webkitAudioContext;
            if (AudioContextClass && !myAudioContext) {
                try {
                    myAudioContext = new AudioContextClass();
                } catch (e) {}
            }
            if (myAudioContext && myAudioContext.state === 'suspended') {
                myAudioContext.resume().then(() => {
                    if (myAudioContext && myAudioContext.state === 'running') {
                        audioUnlocked = true;
                        hideAudioBanner();
                    }
                }).catch(() => {});
            }
            // Desbloquear HTML5 Audio reproduciendo un segundo de silencio en base64
            try {
                const silentSrc = "data:audio/wav;base64,UklGRigAAABXQVZFZm10IBIAAAABAAEARKwAAIhYAQACABAAAABkYXRhAgAAAAAA";
                const audio = new Audio(silentSrc);
                const playPromise = audio.play();
                if (playPromise !== undefined) {
                    playPromise.then(() => {
                        audioUnlocked = true;
                        hideAudioBanner();
                    }).catch(() => {});
                }
            } catch (e) {}
        }

        function showAudioBanner() {
            if (audioUnlocked) return;
            if (document.getElementById('audio-unlock-banner')) return;
            const banner = document.createElement('div');
            banner.id = 'audio-unlock-banner';
            banner.style.cssText = `
                position: fixed; bottom: 85px; left: 50%; transform: translateX(-50%);
                background: #FF8C42; color: #fff; padding: 12px 20px;
                border-radius: 20px; font-size: 13px; font-weight: 800;
                box-shadow: 0 10px 25px rgba(255,140,66,0.3); z-index: 9999;
                display: flex; align-items: center; gap: 8px; cursor: pointer;
                animation: fadeInUp 0.4s ease-out; border: 1.5px solid rgba(255,255,255,0.2);
                text-transform: uppercase; letter-spacing: 0.5px;
            `;
            banner.innerHTML = `<span>🔊 Toca la pantalla para activar sonidos</span>`;
            banner.addEventListener('click', (e) => {
                e.stopPropagation();
                unlockAudio();
            });
            document.body.appendChild(banner);
        }

        function hideAudioBanner() {
            const banner = document.getElementById('audio-unlock-banner');
            if (banner) {
                banner.remove();
            }
        }

        window.addEventListener('click', unlockAudio, { once: true });
        window.addEventListener('touchstart', unlockAudio, { once: true });

        // Global function to play sounds reliably via Web Audio or HTML5 Audio
        window.playNotificationSound = async function(src) {
            // 1. Intentar Web Audio con decodificación (para ganancia a 200% volumen)
            try {
                const AudioContextClass = window.AudioContext || window.webkitAudioContext;
                if (AudioContextClass) {
                    if (!myAudioContext) {
                        myAudioContext = new AudioContextClass();
                    }
                    const ctx = myAudioContext;
                    if (ctx.state === 'suspended') {
                        await ctx.resume();
                    }
                    
                    const resp = await fetch(src);
                    const arrayBuffer = await resp.arrayBuffer();
                    const audioBuffer = await ctx.decodeAudioData(arrayBuffer);
                    
                    const source = ctx.createBufferSource();
                    source.buffer = audioBuffer;
                    
                    const gainNode = ctx.createGain();
                    gainNode.gain.value = 2.0; // 200% volumen
                    
                    source.connect(gainNode);
                    gainNode.connect(ctx.destination);
                    source.start(0);
                    return; // Éxito
                }
            } catch (e) {
                console.log("Web Audio falló, usando HTML5 Audio fallback:", e);
            }
            
            // 2. Fallback de HTML5 Audio tradicional (100% compatible y desbloqueado por gesto previo)
            try {
                const audio = new Audio(src);
                audio.volume = 1.0;
                audio.play().catch(err => console.log("HTML5 Audio bloqueado:", err));
            } catch (e) {
                console.log("Error de fallback HTML5:", e);
            }
        };
        
        // Check after load if audio is suspended
        window.addEventListener('load', () => {
            setTimeout(() => {
                const AudioContextClass = window.AudioContext || window.webkitAudioContext;
                if (AudioContextClass) {
                    const tempCtx = new AudioContextClass();
                    if (tempCtx.state === 'suspended') {
                        showAudioBanner();
                    } else {
                        audioUnlocked = true;
                    }
                    tempCtx.close();
                }
            }, 1200);
        });
    </script>
    <?php endif; ?>

    <?php if ($user && $user['role'] === 'local'): ?>
    <script>
        (function() {
            // Solicitar permisos de notificación de escritorio
            if (typeof Notification !== 'undefined' && Notification.permission === 'default') {
                Notification.requestPermission();
            }

            const storageKey = 'local_delivery_statuses';
            const notifiedKey = 'local_notified_orders_states';

            function playNotificationSound(src) {
                if (window.playNotificationSound) {
                    window.playNotificationSound(src);
                } else {
                    const audio = new Audio(src);
                    audio.play().catch(e => console.log("Fallback blocked:", e));
                }
            }

            function showDesktopNotification(title, body) {
                if (typeof Notification !== 'undefined' && Notification.permission === 'granted') {
                    new Notification(title, {
                        body: body,
                        icon: '<?= esc(delivery_app_url("uploads/logos/corona.png")) ?>'
                    });
                }
            }

            function showFloatingToast(title, body, icon = '🔔', borderLeftColor = '#2563eb') {
                const toast = document.createElement('div');
                toast.style.cssText = `
                    position: fixed;
                    bottom: 24px;
                    right: 24px;
                    z-index: 9999;
                    background: #ffffff;
                    border-left: 5px solid ${borderLeftColor};
                    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
                    padding: 16px 20px;
                    border-radius: 20px;
                    display: flex;
                    align-items: center;
                    gap: 14px;
                    min-width: 320px;
                    max-width: 400px;
                    cursor: pointer;
                    font-family: 'Inter', sans-serif;
                    transition: all 0.3s ease;
                    transform: translateY(20px);
                    opacity: 0;
                `;

                toast.innerHTML = `
                    <div style="font-size: 24px; flex-shrink: 0;">${icon}</div>
                    <div style="flex-grow: 1;">
                        <h4 style="margin: 0 0 4px 0; font-size: 13.5px; font-weight: 800; color: #0f172a;">${title}</h4>
                        <p style="margin: 0; font-size: 12px; font-weight: 600; color: #64748b; line-height: 1.4;">${body}</p>
                    </div>
                    <button style="background: none; border: none; font-size: 14px; cursor: pointer; color: #94a3b8; font-weight: bold; padding: 0 4px;">✕</button>
                `;

                toast.onmouseenter = () => { toast.style.transform = 'scale(1.02)'; };
                toast.onmouseleave = () => { toast.style.transform = 'scale(1)'; };

                document.body.appendChild(toast);

                requestAnimationFrame(() => {
                    toast.style.transform = 'translateY(0)';
                    toast.style.opacity = '1';
                });

                const closeToast = () => {
                    toast.style.transform = 'translateY(20px)';
                    toast.style.opacity = '0';
                    setTimeout(() => {
                        toast.remove();
                    }, 300);
                };

                toast.querySelector('button').addEventListener('click', (e) => {
                    e.stopPropagation();
                    closeToast();
                });

                setTimeout(closeToast, 5000);
            }

            async function checkUpdates() {
                try {
                    const resp = await fetch('<?= esc(delivery_app_url("pages/api_get_active_deliveries.php")) ?>?_t=' + Date.now());
                    if (!resp.ok) return;
                    const data = await resp.json();

                    const currentStatuses = {};
                    data.forEach(order => {
                        currentStatuses[order.id] = order.status;

                        // Actualizar en vivo la tarjeta en pantalla si existe el elemento en el DOM
                        const cardEl = document.getElementById('card-' + order.id);
                        if (cardEl) {
                            const s = (order.status || '').toLowerCase();
                            const pill = cardEl.querySelector('.status-pill-tech');
                            let pText = 'Procesando...';
                            let pClass = 'status-local';
                            let cClass = 'state-local';

                            if (s === 'en_puerta' || s === 'en_camino_al_cliente') {
                                pText = 'Camino al Cliente';
                                pClass = 'status-transit';
                                cClass = 'state-transit';
                            } else if (s === 'repartidor_en_local') {
                                pText = 'En el Local';
                                pClass = 'status-local';
                                cClass = 'state-local';
                            } else if (s === 'aceptado') {
                                pText = 'Camino al Local';
                                pClass = 'status-local';
                                cClass = 'state-local';
                            } else if (s === 'pendiente') {
                                pText = 'Buscando Repartidor';
                                pClass = 'status-pendiente';
                                cClass = 'state-pendiente';
                            } else if (s === 'entregado') {
                                pText = '¡Pedido Entregado!';
                                pClass = 'status-entregado';
                                cClass = 'state-entregado';
                            }

                            cardEl.className = 'status-card ' + cClass;
                            if (pill) {
                                pill.className = 'status-pill-tech ' + pClass;
                                pill.innerHTML = (s === 'pendiente' || s === 'aceptado' || s === 'repartidor_en_local' || s === 'en_puerta' || s === 'en_camino_al_cliente')
                                    ? '<span style="width: 4.5px; height: 4.5px; background: var(--primary, #2563eb); border-radius: 50%; display: inline-block; box-shadow: 0 0 5px var(--primary, #2563eb); animation: pulse-dot 1.5s infinite;"></span> ' + pText
                                    : pText;
                            }

                            // Actualizar el dataset.order en la tarjeta para que si el usuario hace clic tenga el nuevo status
                            const infoLocalEl = document.getElementById('info-local-' + order.id);
                            if (infoLocalEl) {
                                infoLocalEl.dataset.order = btoa(JSON.stringify(order));
                            }

                            // ✅ SINCRONIZAR MODAL ABIERTO — si el modal pertenece a este pedido, inyectar los mismos textos
                            const trackingModal = document.getElementById('tracking-sheet-modal');
                            const isModalOpen = trackingModal && trackingModal.style.display !== 'none';
                            const isThisOrder = window.currentTrackingOrder && parseInt(window.currentTrackingOrder.id) === parseInt(order.id);

                            if (isModalOpen && isThisOrder) {
                                const subEl      = document.getElementById('t-header-subtitle');
                                const driverStep = document.getElementById('t-step-driver');
                                const localStep  = document.getElementById('t-step-local');

                                if (s === 'en_puerta' || s === 'en_camino_al_cliente') {
                                    if (driverStep) driverStep.innerText = 'Camino al cliente';
                                    if (localStep)  localStep.innerText  = 'Esperando entrega';
                                    if (subEl)      subEl.innerHTML      = '<span class="live-pulse-dot"></span> En camino al cliente';
                                } else if (s === 'repartidor_en_local' || s === 'en_local') {
                                    if (driverStep) driverStep.innerText = 'En el local';
                                    if (localStep)  localStep.innerText  = 'Entregando';
                                    if (subEl)      subEl.innerHTML      = '<span class="live-pulse-dot"></span> En el local / Entregando';
                                } else if (s === 'aceptado') {
                                    if (driverStep) driverStep.innerText = 'Camino al local';
                                    if (localStep)  localStep.innerText  = 'Esperando';
                                    if (subEl)      subEl.innerHTML      = '<span class="live-pulse-dot"></span> Conductor Asignado';
                                } else if (s === 'entregado') {
                                    if (driverStep) driverStep.innerText = 'Entregado';
                                    if (localStep)  localStep.innerText  = 'Completado';
                                    if (subEl)      subEl.innerHTML      = '<span class="live-pulse-dot"></span> Pedido Completado';
                                }

                                // Sincronizar el objeto global en memoria
                                window.currentTrackingOrder.status = order.status;
                                console.log(`[Header→Modal] Pedido #${order.id} | Modal actualizado a: ${s}`);
                            }
                        }
                    });

                    // Cargar historial de notificaciones enviadas
                    let notified = {};
                    try {
                        const savedNotified = sessionStorage.getItem(notifiedKey);
                        if (savedNotified) notified = JSON.parse(savedNotified);
                    } catch (e) {}

                    // Detectar si algún estado cambió respecto al último chequeo
                    let statusChanged = false;
                    const savedStatusesStr = sessionStorage.getItem(storageKey);
                    if (savedStatusesStr) {
                        const savedStatuses = JSON.parse(savedStatusesStr);
                        for (const orderId in currentStatuses) {
                            if (savedStatuses[orderId] !== currentStatuses[orderId]) {
                                statusChanged = true;
                            }
                        }
                        for (const orderId in savedStatuses) {
                            if (currentStatuses[orderId] === undefined) {
                                statusChanged = true;
                            }
                        }
                    }

                    let playArrivalSound = false;
                    let playCompletedSound = false;
                    let playAssignedSound = false;
                    let notificationText = '';

                    // Inicializar estados en el primer load de la sesión para no sonar cosas viejas
                    const isFirstSessionLoad = !savedStatusesStr;
                    if (isFirstSessionLoad) {
                        data.forEach(order => {
                            if (order.status === 'repartidor_en_local') {
                                notified[order.id + '_assigned'] = true;
                                notified[order.id + '_arrived'] = true;
                            } else if (order.status === 'aceptado') {
                                notified[order.id + '_assigned'] = true;
                            } else if (order.status === 'entregado') {
                                notified[order.id + '_assigned'] = true;
                                notified[order.id + '_arrived'] = true;
                                notified[order.id + '_completed'] = true;
                            }
                        });
                        sessionStorage.setItem(storageKey, JSON.stringify(currentStatuses));
                        sessionStorage.setItem(notifiedKey, JSON.stringify(notified));
                        return;
                    }

                    for (const orderId in currentStatuses) {
                        const currentStatus = currentStatuses[orderId];

                        // 1. Llegada al local
                        if (currentStatus === 'repartidor_en_local') {
                            const notifId = orderId + '_arrived';
                            if (!notified[notifId]) {
                                playArrivalSound = true;
                                notified[notifId] = true;
                                notificationText = "El delivery ha llegado al local (Pedido #" + orderId + ")";
                            }
                        }
                        // 2. Pedido entregado
                        if (currentStatus === 'entregado') {
                            const notifId = orderId + '_completed';
                            if (!notified[notifId]) {
                                playCompletedSound = true;
                                notified[notifId] = true;
                                notificationText = "Pedido entregado con éxito (Pedido #" + orderId + ")";
                            }
                        }
                        // 3. Asignación del chofer
                        if (currentStatus === 'aceptado') {
                            const notifId = orderId + '_assigned';
                            if (!notified[notifId]) {
                                playAssignedSound = true;
                                notified[notifId] = true;
                                notificationText = "Delivery asignado (Pedido #" + orderId + ")";
                            }
                        }
                    }

                    // Guardar los nuevos estados y registro de notificados
                    sessionStorage.setItem(storageKey, JSON.stringify(currentStatuses));
                    sessionStorage.setItem(notifiedKey, JSON.stringify(notified));

                    let soundPlayed = false;
                    if (playArrivalSound) {
                        playNotificationSound('<?= esc(delivery_app_url("uploads/sounds/delivery_arrived.mp3")) ?>');
                        showDesktopNotification("¡Delivery en Local!", notificationText);
                        showFloatingToast("¡Delivery en Local!", notificationText, '📍', '#f59e0b');
                        soundPlayed = true;
                    } else if (playCompletedSound) {
                        playNotificationSound('<?= esc(delivery_app_url("uploads/sounds/delivery_completed.mp3")) ?>');
                        showDesktopNotification("¡Pedido Entregado!", notificationText);
                        showFloatingToast("¡Pedido Entregado!", notificationText, '✅', '#10b981');
                        if (typeof showSuccessModal === 'function') {
                            showSuccessModal("¡Pedido Entregado!", "¡Buen trabajo! El pedido ha sido completado con éxito.");
                        }
                        soundPlayed = true;
                    } else if (playAssignedSound) {
                        playNotificationSound('<?= esc(delivery_app_url("uploads/sounds/delivery_assigned.mp3")) ?>');
                        showDesktopNotification("¡Chofer Asignado!", notificationText);
                        showFloatingToast("¡Chofer Asignado!", notificationText, '🚴', '#2563eb');
                        soundPlayed = true;
                    }

                    // Recargar pantalla si hubo CUALQUIER cambio de estado
                    if (statusChanged && !playCompletedSound) {
                        const onTrackingPage = window.location.pathname.indexOf('my_deliveries.php') !== -1;
                        if (onTrackingPage) {
                            // Si el modal de seguimiento está abierto, NO recargar: 
                            // el live polling del modal (cada 3s) ya actualiza los datos en vivo.
                            const trackingModal = document.getElementById('tracking-sheet-modal');
                            const isTrackingModalOpen = trackingModal && trackingModal.style.display !== 'none';
                            
                            if (isTrackingModalOpen) {
                                console.log('[Header] Status changed while tracking modal is open — updating in-memory state...');
                                if (window.currentTrackingOrder) {
                                    const newStatus = currentStatuses[window.currentTrackingOrder.id];
                                    if (newStatus && newStatus !== window.currentTrackingOrder.status) {
                                        // Actualizar en memoria: el polling propio del modal (3s) ya
                                        // se encarga de los textos; solo refrescamos el botón flotante.
                                        window.currentTrackingOrder.status = newStatus;
                                        if (typeof refreshFloatingActionButton === 'function') {
                                            refreshFloatingActionButton(window.currentTrackingOrder);
                                        }
                                    }
                                }
                            } else if (document.visibilityState === 'visible') {
                                // Guardar los estados YA actualizados en sessionStorage antes
                                // del reload para que la primera ejecución post-reload sepa
                                // que estos estados ya fueron procesados y no silenciar alertas futuras.
                                sessionStorage.setItem(storageKey, JSON.stringify(currentStatuses));
                                sessionStorage.setItem(notifiedKey, JSON.stringify(notified));

                                if (soundPlayed) {
                                    setTimeout(() => {
                                        window.location.reload();
                                    }, 2000);
                                } else {
                                    window.location.reload();
                                }
                            } else {
                                sessionStorage.setItem('needs_reload', 'true');
                            }
                        }
                    }
                } catch (e) {
                    console.error("Error global background update check:", e);
                }
            }

            // Si la pestaña vuelve a primer plano y hay cambios pendientes en la vista de seguimiento, recargar
            document.addEventListener('visibilitychange', () => {
                const onTrackingPage = window.location.pathname.indexOf('my_deliveries.php') !== -1;
                
                if (document.visibilityState === 'visible') {
                    // Verificar si el canal de audio del navegador se suspendió al bloquear la pantalla y solicitar reactivación
                    const AudioContextClass = window.AudioContext || window.webkitAudioContext;
                    if (AudioContextClass) {
                        const tempCtx = new AudioContextClass();
                        if (tempCtx.state === 'suspended') {
                            audioUnlocked = false;
                            showAudioBanner();
                        }
                        tempCtx.close();
                    }

                    if (onTrackingPage && sessionStorage.getItem('needs_reload') === 'true') {
                        sessionStorage.removeItem('needs_reload');
                        // Si el modal de seguimiento está abierto, no recargar
                        const trackingModal2 = document.getElementById('tracking-sheet-modal');
                        const isModalOpen2 = trackingModal2 && trackingModal2.style.display !== 'none';
                        if (isModalOpen2) {
                            console.log('[Header] Visibility restored but tracking modal is open, skipping reload.');
                        } else if (sessionStorage.getItem('needs_redirect_dashboard') === 'true') {
                            sessionStorage.removeItem('needs_redirect_dashboard');
                            window.location.href = '<?= esc(delivery_app_url("dashboard.php")) ?>';
                        } else {
                            window.location.reload();
                        }
                    }
                }
            });

            // Web Worker secundario para evitar throttling en pestañas inactivas
            try {
                const workerCode = `
                    setInterval(() => {
                        postMessage('tick');
                    }, 5000);
                `;
                const blob = new Blob([workerCode], {type: 'application/javascript'});
                const worker = new Worker(URL.createObjectURL(blob));
                worker.onmessage = function() {
                    checkUpdates();
                };
                setTimeout(checkUpdates, 1000);
            } catch (e) {
                setInterval(checkUpdates, 5000);
            }

            function checkSystemNotifications() {
                fetch('<?= delivery_app_url("pages/api_check_approval.php") ?>?_t=' + Date.now())
                .then(res => res.json())
                .then(data => {
                    if (data.success && data.notifications && data.notifications.length > 0) {
                        data.notifications.forEach(n => {
                            if (window.playNotificationSound) {
                                window.playNotificationSound('<?= delivery_app_url("assets/sounds/notification.mp3") ?>');
                            }
                            if (typeof Swal !== 'undefined') {
                                Swal.fire({
                                    icon: n.type.includes('expired') ? 'error' : 'warning',
                                    title: n.title,
                                    text: n.message,
                                    confirmButtonText: 'Entendido 💳',
                                    confirmButtonColor: '#2563eb'
                                }).then(() => {
                                    if (n.type.includes('expired')) window.location.reload();
                                });
                            } else {
                                alert(n.title + "\n\n" + n.message);
                                if (n.type.includes('expired')) window.location.reload();
                            }
                        });
                    }
                })
                .catch(err => console.error("Error checking notifications:", err));
            }
            setInterval(checkSystemNotifications, 8000);
            setTimeout(checkSystemNotifications, 1500);
        })();
    </script>
    <?php endif; ?>
<?php endif; ?>

<div class="wrap">
