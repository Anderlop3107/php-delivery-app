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
    <title>Login - Delivery App</title>
    <style>
        body { font-family: sans-serif; display: flex; justify-content: center; align-items: center; min-height: 100vh; background: #f5f7fb; }
        .card { background: #fff; padding: 24px; border-radius: 12px; border: 1px solid #e5e7eb; width: 100%; max-width: 400px; }
        input { width: 100%; padding: 10px; margin: 10px 0; border: 1px solid #e5e7eb; border-radius: 8px; }
        button { width: 100%; padding: 12px; background: #0f766e; color: #fff; border: none; border-radius: 8px; cursor: pointer; }
        .error { color: #b91c1c; margin-bottom: 15px; }
    </style>
</head>
<body>
    <div class="card">
        <h2>Login</h2>
        <?php if ($error): ?><p class="error"><?= esc($error) ?></p><?php endif; ?>
        <form method="post">
            <input type="email" name="email" placeholder="Email" required>
            <input type="password" name="password" placeholder="Contraseña" required>
            <button type="submit">Ingresar</button>
        </form>
    </div>
</body>
</html>
