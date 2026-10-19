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
                if (!$user['is_active']) {
                    $error = "Tu cuenta está desactivada. Contactá al administrador.";
                } else {
                    $pdo->prepare("DELETE FROM login_attempts WHERE ip = ?")->execute([$ip]);
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['name']    = $user['name'];
                    $_SESSION['role']    = $user['role'];
                    $_SESSION['last_activity'] = time();
                    log_audit($pdo, 'login', 'user', $user['id'], 'Login exitoso');
                    header("Location: dashboard.php");
                    exit;
                }
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#2f384f">
    <title>Ingreso · CRM Imperio</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@0.460.0/dist/umd/lucide.min.js"></script>
    <link rel="stylesheet" href="style.css">
</head>
<body class="login-page">
    <main class="login-shell">
        <section class="login-brand" aria-label="CRM Imperio">
            <div class="login-brand__head">
                <img class="login-brand__logo" src="img/logo.jpg" alt="Imperio Comercial">
                <span>
                    <span class="login-brand__name">CRM Imperio</span>
                    <span class="login-brand__company">Imperio Comercial</span>
                </span>
            </div>

            <div>
                <h1 class="login-brand__claim">De la carga a la entrega, cada venta en un solo lugar.</h1>
                <ol class="login-brand__steps">
                    <li class="login-brand__step"><span class="icon-box"><?= ui_icon('circle-plus') ?></span><span><strong>Cargá la venta</strong><span>Cliente, plan de pago y documentación.</span></span></li>
                    <li class="login-brand__step"><span class="icon-box"><?= ui_icon('shield-check') ?></span><span><strong>Verificá y aprobá</strong><span>Contacto con el cliente y decisión de crédito.</span></span></li>
                    <li class="login-brand__step"><span class="icon-box"><?= ui_icon('truck') ?></span><span><strong>Entregá</strong><span>Seguimiento hasta que el producto llega al cliente.</span></span></li>
                </ol>
            </div>

            <p class="login-brand__location">Tucumán · Santiago del Estero · Catamarca</p>
        </section>

        <section class="login-form-pane" aria-label="Ingreso al sistema">
            <form method="POST" action="index.php" class="login-card">
                <?= csrf_field() ?>
                <span class="login-grabber" aria-hidden="true"></span>
                <div>
                    <h2 class="login-card__title">Ingresá</h2>
                    <p class="login-card__intro">Usá el usuario que te dio el administrador.</p>
                </div>

                <?php if ($error): ?>
                <div class="notice notice--error" role="alert">
                    <?= ui_icon('alert-circle') ?><span><?= htmlspecialchars($error) ?></span>
                </div>
                <?php endif; ?>

                <div class="field">
                    <label class="field__label" for="username">Usuario</label>
                    <input id="username" type="text" name="username" required autocomplete="username" placeholder="nombre.apellido" class="input">
                </div>

                <div class="field">
                    <label class="field__label" for="password">Contraseña</label>
                    <div class="login-password">
                        <input id="password" type="password" name="password" required autocomplete="current-password" placeholder="••••••••" class="input">
                        <button id="passwordToggle" type="button" class="login-password__toggle" aria-label="Mostrar contraseña" aria-pressed="false"><?= ui_icon('eye') ?></button>
                    </div>
                </div>

                <button type="submit" class="btn btn--primary btn--lg btn--block">Ingresar <?= ui_icon('arrow-right') ?></button>
                <p class="login-footer">&copy; <?= date('Y') ?> CRM Imperio · acceso seguro</p>
            </form>
        </section>
    </main>

    <script>
        const passwordInput = document.getElementById('password');
        const passwordToggle = document.getElementById('passwordToggle');
        passwordToggle.addEventListener('click', function () {
            const willShow = passwordInput.type === 'password';
            passwordInput.type = willShow ? 'text' : 'password';
            passwordToggle.setAttribute('aria-pressed', willShow ? 'true' : 'false');
            passwordToggle.setAttribute('aria-label', willShow ? 'Ocultar contraseña' : 'Mostrar contraseña');
            passwordToggle.innerHTML = '<i data-lucide="' + (willShow ? 'eye-off' : 'eye') + '" class="icon" aria-hidden="true"></i>';
            if (window.lucide) lucide.createIcons();
        });
        if (window.lucide) lucide.createIcons();
    </script>
</body>
</html>
