<?php
/*
 * Archivo: index.php
 * Propósito: Pantalla de inicio de sesión.
 */

require 'includes/db.php';

if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit;
}

$error = '';

if (isset($_GET['msg']) && $_GET['msg'] === 'timeout') {
    $error = "Tu sesión expiró por inactividad. Ingresá nuevamente.";
}

define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_WINDOW', 15 * 60);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $user_input = trim($_POST['username']);
    $pass_input = trim($_POST['password']);

    if (empty($user_input) || empty($pass_input)) {
        $error = "Por favor, complete todos los campos.";
    } else {
        $ip = $_SERVER['REMOTE_ADDR'];

        $pdo->prepare("DELETE FROM login_attempts WHERE ip = ? AND attempted_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)")
            ->execute([$ip]);

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip = ?");
        $stmt->execute([$ip]);
        $attempt_count = (int) $stmt->fetchColumn();

        if ($attempt_count >= MAX_LOGIN_ATTEMPTS) {
            $error = "Demasiados intentos fallidos. Esperá 15 minutos antes de volver a intentar.";
        } else {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->execute([$user_input]);
            $user = $stmt->fetch();

            if ($user && password_verify($pass_input, $user['password'])) {
                $pdo->prepare("DELETE FROM login_attempts WHERE ip = ?")->execute([$ip]);
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['name']    = $user['name'];
                $_SESSION['role']    = $user['role'];
                $_SESSION['last_activity'] = time();
                log_audit($pdo, 'login', 'user', $user['id'], 'Login exitoso');
                header("Location: dashboard.php");
                exit;
            } else {
                $pdo->prepare("INSERT INTO login_attempts (ip, username) VALUES (?, ?)")
                    ->execute([$ip, $user_input]);
                $error = "Usuario o contraseña incorrectos.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login · CRM Imperio</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <link rel="stylesheet" href="style.css">
</head>
<body style="min-height:100vh;display:flex;align-items:center;justify-content:center;padding:16px;">

    <div style="width:100%;max-width:400px;">

        <!-- Tarjeta login -->
        <div style="background:var(--card);border-radius:20px;border:1.6px solid var(--line);box-shadow:0 8px 40px rgba(43,43,58,.1);overflow:hidden;">

            <!-- Franja de acento superior -->
            <div style="height:6px;background:linear-gradient(90deg,var(--accent),#818cf8);"></div>

            <div style="padding:36px 32px 28px;text-align:center;">

                <!-- Logo -->
                <div style="width:76px;height:76px;margin:0 auto 18px;border-radius:18px;border:1.8px solid var(--line);background:#fff;display:grid;place-items:center;overflow:hidden;box-shadow:0 2px 12px rgba(43,43,58,.08);">
                    <img src="img/logo.jpg" alt="Imperio" style="width:100%;height:100%;object-fit:contain;padding:6px;">
                </div>

                <h1 style="font-size:22px;font-weight:800;letter-spacing:-.02em;color:var(--ink);margin:0 0 6px;">CRM Imperio</h1>
                <p style="font-size:13px;color:var(--ink-3);margin:0 0 26px;">Ingrese sus credenciales para acceder</p>

                <!-- Error -->
                <?php if ($error): ?>
                <div style="background:var(--rec-bg);border:1.5px solid rgba(159,18,57,.2);color:var(--rec-ink);font-size:13px;padding:10px 14px;border-radius:11px;margin-bottom:18px;display:flex;align-items:center;gap:8px;text-align:left;">
                    <i data-lucide="alert-circle" style="width:16px;height:16px;flex-shrink:0;"></i>
                    <?= htmlspecialchars($error) ?>
                </div>
                <?php endif; ?>

                <!-- Formulario -->
                <form method="POST" action="index.php" style="text-align:left;display:flex;flex-direction:column;gap:14px;">
                    <?= csrf_field() ?>

                    <div>
                        <label style="display:block;font-size:10.5px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--ink-3);margin-bottom:6px;margin-left:2px;">Usuario · DNI</label>
                        <div style="position:relative;">
                            <input type="text" name="username" required
                                   placeholder="Ej: 12345678"
                                   class="input-light"
                                   style="width:100%;padding:11px 12px 11px 38px;font-size:14px;">
                            <i data-lucide="user" style="position:absolute;left:11px;top:50%;transform:translateY(-50%);width:16px;height:16px;color:var(--ink-3);pointer-events:none;"></i>
                        </div>
                    </div>

                    <div>
                        <label style="display:block;font-size:10.5px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--ink-3);margin-bottom:6px;margin-left:2px;">Contraseña</label>
                        <div style="position:relative;">
                            <input type="password" name="password" required
                                   placeholder="••••••••"
                                   class="input-light"
                                   style="width:100%;padding:11px 12px 11px 38px;font-size:14px;">
                            <i data-lucide="lock" style="position:absolute;left:11px;top:50%;transform:translateY(-50%);width:16px;height:16px;color:var(--ink-3);pointer-events:none;"></i>
                        </div>
                    </div>

                    <button type="submit"
                            style="width:100%;padding:12px;border-radius:12px;background:var(--accent);color:#fff;font-weight:700;font-size:14px;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;margin-top:4px;transition:opacity .15s;"
                            onmouseover="this.style.opacity='.88'" onmouseout="this.style.opacity='1'">
                        <i data-lucide="log-in" style="width:16px;height:16px;"></i>
                        Ingresar al sistema
                    </button>
                </form>

                <!-- Pie -->
                <div style="margin-top:24px;padding-top:16px;border-top:1px dashed var(--line);font-size:11px;color:var(--ink-3);">
                    &copy; <?= date('Y') ?> CRM Imperio · acceso seguro
                </div>
            </div>
        </div>

    </div>

    <script>lucide.createIcons();</script>
</body>
</html>
