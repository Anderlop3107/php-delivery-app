<?php
require_once __DIR__ . '/bootstrap.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if (app_login($email, $password)) {
        header('Location: ' . delivery_app_url('dashboard.php'));
        exit;
    } else {
        $error = 'Credenciales incorrectas. Intenta de nuevo.';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión - Delivery App</title>
    <!-- Modern Fonts: Plus Jakarta Sans & Inter -->
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --primary-soft: rgba(37, 99, 235, 0.08);
            --bg: #f8fafc;
            --card-bg: #ffffff;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --border: #e2e8f0;
            --radius: 16px;
            --shadow: 0 20px 40px -15px rgba(15, 23, 42, 0.08), 
                      0 0 0 1px rgba(15, 23, 42, 0.04);
        }

        * {
            box-sizing: border-box;
            font-family: 'Plus Jakarta Sans', sans-serif;
            margin: 0;
            padding: 0;
        }

        body {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            background: var(--bg);
            position: relative;
            overflow: hidden;
            padding: 20px;
        }

        /* Background Decorative Blur Rings for Glassmorphic Depth */
        .bg-glow-1 {
            position: absolute;
            width: 350px;
            height: 350px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(37, 99, 235, 0.15) 0%, rgba(37, 99, 235, 0) 70%);
            top: -100px;
            left: -100px;
            z-index: 1;
            filter: blur(40px);
        }

        .bg-glow-2 {
            position: absolute;
            width: 450px;
            height: 450px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(16, 185, 129, 0.1) 0%, rgba(16, 185, 129, 0) 70%);
            bottom: -150px;
            right: -100px;
            z-index: 1;
            filter: blur(40px);
        }

        .login-container {
            width: 100%;
            max-width: 420px;
            z-index: 10;
        }

        .login-card {
            background: var(--card-bg);
            padding: 40px 32px;
            border-radius: 28px;
            box-shadow: var(--shadow);
            border: 1px solid rgba(255, 255, 255, 0.8);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .login-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 30px 60px -20px rgba(15, 23, 42, 0.12), 
                        0 0 0 1px rgba(15, 23, 42, 0.04);
        }

        .login-header {
            text-align: center;
            margin-bottom: 32px;
        }

        .brand-logo {
            width: 56px;
            height: 56px;
            border-radius: 16px;
            background: var(--primary-soft);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            margin: 0 auto 16px;
            box-shadow: 0 8px 16px rgba(37, 99, 235, 0.1);
            font-weight: 800;
        }

        .login-header h2 {
            font-size: 24px;
            font-weight: 800;
            color: var(--text-main);
            letter-spacing: -0.5px;
            margin-bottom: 8px;
        }

        .login-header p {
            font-size: 14px;
            color: var(--text-muted);
            font-weight: 500;
        }

        /* Error Box Styling */
        .error-box {
            background: #fef2f2;
            border: 1px solid #fca5a5;
            color: #b91c1c;
            padding: 12px 16px;
            border-radius: 14px;
            font-size: 13.5px;
            font-weight: 600;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: shake 0.4s ease;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-4px); }
            75% { transform: translateX(4px); }
        }

        /* Form Controls */
        .form-group {
            margin-bottom: 20px;
            position: relative;
        }

        .form-group label {
            display: block;
            font-size: 12px;
            font-weight: 700;
            color: var(--text-muted);
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .input-wrapper {
            position: relative;
        }

        .field-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            width: 18px;
            height: 18px;
            color: var(--text-muted);
            transition: color 0.2s ease;
            pointer-events: none;
        }

        .input-wrapper input {
            width: 100%;
            height: 52px;
            padding: 0 48px;
            border-radius: var(--radius);
            border: 1.5px solid var(--border);
            background: #ffffff;
            font-size: 14px;
            font-weight: 600;
            color: var(--text-main);
            outline: none;
            transition: all 0.2s ease;
        }

        .input-wrapper input::placeholder {
            color: #94a3b8;
            font-weight: 500;
        }

        .input-wrapper input:focus {
            border-color: var(--primary);
            background: #ffffff;
            box-shadow: 0 0 0 4px var(--primary-soft);
        }

        .input-wrapper input:focus ~ .field-icon {
            color: var(--primary);
        }

        /* Toggle Password (Ojo de Pez) */
        .toggle-password-btn {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: var(--text-muted);
            transition: color 0.2s ease;
            user-select: none;
        }

        .toggle-password-btn:hover {
            color: var(--text-main);
        }

        /* Primary Button */
        .btn-login {
            width: 100%;
            height: 54px;
            background: var(--primary);
            color: #ffffff;
            border: none;
            border-radius: var(--radius);
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 8px 24px rgba(37, 99, 235, 0.25);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 10px;
        }

        .btn-login:hover {
            background: var(--primary-hover);
            box-shadow: 0 12px 30px rgba(37, 99, 235, 0.35);
            transform: translateY(-1px);
        }

        .btn-login:active {
            transform: translateY(1px);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.2);
        }

        .footer-text {
            text-align: center;
            margin-top: 24px;
            font-size: 13.5px;
            color: var(--text-muted);
            font-weight: 500;
        }

        .footer-text a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 700;
            transition: color 0.2s ease;
        }

        .footer-text a:hover {
            color: var(--primary-hover);
            text-decoration: underline;
        }
    </style>
</head>
<body>

    <!-- Decorative glows -->
    <div class="bg-glow-1"></div>
    <div class="bg-glow-2"></div>

    <div class="login-container">
        <div class="login-card">
            
            <div class="login-header">
                <div class="brand-logo">⚡</div>
                <h2>Bienvenido de nuevo</h2>
                <p>Ingresa tus datos para acceder a tu cuenta</p>
            </div>

            <?php if ($error): ?>
                <div class="error-box">
                    <svg style="width: 20px; height: 20px; flex-shrink: 0;" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                    </svg>
                    <span><?= esc($error) ?></span>
                </div>
            <?php endif; ?>

            <form method="post" autocomplete="on">
                
                <!-- Email Group -->
                <div class="form-group">
                    <label for="email">Correo Electrónico</label>
                    <div class="input-wrapper">
                        <input type="email" name="email" id="email" placeholder="ejemplo@correo.com" required>
                        <!-- SVG Icon: Email -->
                        <svg class="field-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                        </svg>
                    </div>
                </div>

                <!-- Password Group -->
                <div class="form-group">
                    <label for="password">Contraseña</label>
                    <div class="input-wrapper">
                        <input type="password" name="password" id="password" placeholder="••••••••••••" required>
                        <!-- SVG Icon: Lock -->
                        <svg class="field-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                        </svg>
                        <!-- Eye Button (Ojo de pez) -->
                        <div class="toggle-password-btn" onclick="togglePasswordVisibility()">
                            <svg id="eye-icon-open" style="width: 20px; height: 20px;" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                            </svg>
                            <svg id="eye-icon-closed" style="width: 20px; height: 20px; display: none;" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"></path>
                            </svg>
                        </div>
                    </div>
                </div>

                <!-- Submit Button -->
                <button type="submit" class="btn-login">
                    <span>Ingresar a la plataforma</span>
                    <svg style="width: 18px; height: 18px;" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13 5l7 7-7 7M5 5l7 7-7 7"></path>
                    </svg>
                </button>

            </form>

            <div class="footer-text">
                ¿Problemas para acceder? <a href="mailto:soporte@deliveryapp.com">Contactar a Soporte</a>
            </div>

        </div>
    </div>

    <script>
        function togglePasswordVisibility() {
            const passwordField = document.getElementById('password');
            const openIcon = document.getElementById('eye-icon-open');
            const closedIcon = document.getElementById('eye-icon-closed');

            if (passwordField.type === 'password') {
                passwordField.type = 'text';
                openIcon.style.display = 'none';
                closedIcon.style.display = 'block';
            } else {
                passwordField.type = 'password';
                openIcon.style.display = 'block';
                closedIcon.style.display = 'none';
            }
        }
    </script>
</body>
</html>
