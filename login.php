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
        $error = 'Credenciales incorrectas.';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Goo! - Iniciar Sesión</title>
    <!-- Google Fonts: Plus Jakarta Sans for modern clean UI -->
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --bg-translucent: rgba(255, 255, 255, 0.03);
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
            background-color: #000;
            position: relative;
            overflow: hidden;
            padding: 20px;
        }

        /* Cityscape Background with low brightness / dark filter */
        body::before {
            content: '';
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: url('<?= delivery_app_url("uploads/images/login_goo!.jpg") ?>') no-repeat center center;
            background-size: cover;
            filter: grayscale(100%) brightness(85%) contrast(95%);
            z-index: -1;
        }

        .login-wrapper {
            width: 100%;
            max-width: 380px;
            z-index: 10;
            margin-top: -8vh;
        }

        .logo-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-bottom: 36px;
            text-align: center;
        }

        /* Animated Toggle Switch Logo */
        .switch-logo-container {
            width: 120px;
            height: 72px;
            margin-top: -20px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .switch-track {
            width: 90px;
            height: 50px;
            background: rgba(255, 255, 255, 0.08);
            border: 2px solid rgba(255, 255, 255, 0.25);
            border-radius: 30px;
            position: relative;
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.2);
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            animation: autoToggleTrack 5s ease-in-out infinite;
        }

        .switch-handle {
            width: 36px;
            height: 36px;
            background: #ffffff;
            border-radius: 50%;
            position: absolute;
            top: 5px;
            left: 5px;
            box-shadow: 0 4px 10px rgba(37, 99, 235, 0.3);
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            animation: autoToggleHandle 5s ease-in-out infinite;
        }

        /* Auto toggling loop animation */
        @keyframes autoToggleTrack {
            0%, 10% {
                background: rgba(255, 255, 255, 0.08);
                border-color: rgba(255, 255, 255, 0.25);
                box-shadow: 0 8px 24px rgba(0, 0, 0, 0.2);
            }
            40%, 60% {
                background: rgba(37, 99, 235, 0.25);
                border-color: #2563eb;
                box-shadow: 0 8px 30px rgba(37, 99, 235, 0.4);
            }
            90%, 100% {
                background: rgba(255, 255, 255, 0.08);
                border-color: rgba(255, 255, 255, 0.25);
                box-shadow: 0 8px 24px rgba(0, 0, 0, 0.2);
            }
        }

        @keyframes autoToggleHandle {
            0%, 10% {
                left: 5px;
                background: #ffffff;
                box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
            }
            40%, 60% {
                left: 45px;
                background: #ffffff;
                box-shadow: 0 6px 15px rgba(37, 99, 235, 0.6), 0 0 10px #2563eb;
            }
            90%, 100% {
                left: 5px;
                background: #ffffff;
                box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
            }
        }

        .logo-container h1 {
            font-size: 28px;
            font-weight: 800;
            color: #ffffff;
            letter-spacing: -1px;
            margin-top: 14px;
        }

        .logo-container h1 span {
            color: var(--primary);
        }

        /* Error Box Styling */
        .error-box {
            background: rgba(239, 68, 68, 0.15);
            border: 1.5px solid rgba(239, 68, 68, 0.3);
            color: #fca5a5;
            padding: 12px 16px;
            border-radius: 14px;
            font-size: 13.5px;
            font-weight: 600;
            margin-bottom: 20px;
            text-align: center;
            animation: shake 0.4s ease;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-4px); }
            75% { transform: translateX(4px); }
        }

        /* Translucent Box Layout for Inputs */
        .form-group-translucent {
            width: 100%;
            margin-bottom: 16px;
            background: var(--bg-translucent);
            border: 1px solid rgba(255, 255, 255, 0.15); /* Glass edge */
            border-radius: 14px;
            padding: 14px 18px;
            display: flex;
            align-items: center;
            gap: 14px;
            backdrop-filter: blur(2px);
            -webkit-backdrop-filter: blur(2px);
            box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.25);
            transition: all 0.25s ease;
        }

        .form-group-translucent:focus-within {
            border-color: rgba(255, 255, 255, 0.4);
            background: rgba(255, 255, 255, 0.16);
            box-shadow: 0 8px 32px 0 rgba(37, 99, 235, 0.2);
        }

        .input-icon {
            width: 20px;
            height: 20px;
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .input-field-wrapper {
            flex-grow: 1;
            display: flex;
            align-items: center;
            position: relative;
        }

        .input-field-wrapper input {
            width: 100%;
            background: transparent;
            border: none;
            outline: none;
            color: #ffffff;
            font-size: 14.5px;
            font-weight: 600;
            padding-right: 32px; /* Prevent text from typing under the eye icon */
            height: 24px;
        }

        .input-field-wrapper input::placeholder {
            color: rgba(255, 255, 255, 0.7);
            font-weight: 500;
        }

        /* Eye Icon (Ojo de Pez) */
        .toggle-eye-btn {
            color: rgba(255, 255, 255, 0.5);
            cursor: pointer;
            user-select: none;
            display: flex;
            align-items: center;
            transition: color 0.2s ease;
            position: absolute;
            right: 0;
            top: 50%;
            transform: translateY(-50%);
            z-index: 5; /* Ensure it is clickable on top of the input */
        }

        .toggle-eye-btn:hover {
            color: #ffffff;
        }

        /* Pill Action Button */
        .btn-ingresar {
            width: 80%;
            height: 52px;
            background: var(--primary);
            color: #ffffff;
            border: none;
            border-radius: 50px; /* Estilo píldora */
            font-size: 18px;
            font-weight: 600; /* semi-bold */
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 6px 20px rgba(37, 99, 235, 0.3);
            margin: 25px auto 0; /* Center horizontally inside the form */
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .btn-ingresar:hover {
            background: var(--primary-hover);
            box-shadow: 0 8px 24px rgba(37, 99, 235, 0.4);
            transform: translateY(-1px);
        }

        .btn-ingresar:active {
            transform: translateY(1px);
        }

        /* Support Recovery Link */
        .support-link-box {
            text-align: center;
            margin-top: 28px;
            font-size: 13px;
            color: rgba(255, 255, 255, 0.5);
            font-weight: 500;
        }

        .support-link-box a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 700;
            transition: color 0.2s ease;
        }

        .support-link-box a:hover {
            color: #3b82f6;
            text-decoration: underline;
        }
    </style>
</head>
<body>

    <div class="login-wrapper">
        
        <!-- Top Logotipo -->
        <div class="logo-container">
            <div class="switch-logo-container">
                <div class="switch-track">
                    <div class="switch-handle"></div>
                </div>
            </div>
            <h1>Goo<span>!</span></h1>
        </div>

        <?php if ($error): ?>
            <div class="error-box">
                <span><?= esc($error) ?></span>
            </div>
        <?php endif; ?>

        <!-- Credentials Form -->
        <form method="post" autocomplete="on">
            
            <!-- Email Input -->
            <div class="form-group-translucent">
                <div class="input-icon">
                    <!-- SVG: Envelope icon in #2563eb -->
                    <svg style="width:20px; height:20px;" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                    </svg>
                </div>
                <div class="input-field-wrapper">
                    <input type="email" name="email" placeholder="E-mail address" required>
                </div>
            </div>

            <!-- Password Input -->
            <div class="form-group-translucent">
                <div class="input-icon">
                    <!-- SVG: Padlock icon in #2563eb -->
                    <svg style="width:20px; height:20px;" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                    </svg>
                </div>
                <div class="input-field-wrapper">
                    <input type="password" name="password" id="password" placeholder="••••••••" required>
                    <!-- Eye Button (Ojo de Pez) -->
                    <div class="toggle-eye-btn" onclick="togglePasswordVisibility()">
                        <!-- Open Eye -->
                        <svg id="eye-open" style="width: 20px; height: 20px;" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                        </svg>
                        <!-- Closed Eye -->
                        <svg id="eye-closed" style="width: 20px; height: 20px; display: none;" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"></path>
                        </svg>
                    </div>
                </div>
            </div>

            <!-- Submit Action -->
            <button type="submit" class="btn-ingresar">Ingresar a Goo!</button>

        </form>

        <!-- Recovery / Contact Support -->
        <div class="support-link-box">
            ¿Problemas para acceder? <a href="https://wa.me/595986107629" target="_blank" rel="noopener noreferrer">Contacta a Soporte</a>
        </div>

    </div>

    <script>
        function togglePasswordVisibility() {
            const passwordField = document.getElementById('password');
            const eyeOpen = document.getElementById('eye-open');
            const eyeClosed = document.getElementById('eye-closed');

            if (passwordField.type === 'password') {
                passwordField.type = 'text';
                eyeOpen.style.display = 'none';
                eyeClosed.style.display = 'block';
            } else {
                passwordField.type = 'password';
                eyeOpen.style.display = 'block';
                eyeClosed.style.display = 'none';
            }
        }
    </script>
</body>
</html>
