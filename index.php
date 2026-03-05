<?php
/*
 * Archivo: index.php
 * Propósito: Pantalla de inicio de sesión.
 */

require 'includes/db.php';

// Si el usuario ya está logueado, redirigir al dashboard
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit;
}

$error = '';

// Mostrar mensaje si la sesión expiró por inactividad
if (isset($_GET['msg']) && $_GET['msg'] === 'timeout') {
    $error = "Tu sesión expiró por inactividad. Ingresá nuevamente.";
}

define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_WINDOW', 15 * 60); // 15 minutos en segundos

// Procesar el formulario cuando se envía
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $user_input = trim($_POST['username']);
    $pass_input = trim($_POST['password']);

    if (empty($user_input) || empty($pass_input)) {
        $error = "Por favor, complete todos los campos.";
    } else {
        $ip = $_SERVER['REMOTE_ADDR'];

        // Limpiar intentos vencidos
        $pdo->prepare("DELETE FROM login_attempts WHERE ip = ? AND attempted_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)")
            ->execute([$ip]);

        // Contar intentos recientes
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip = ?");
        $stmt->execute([$ip]);
        $attempt_count = (int) $stmt->fetchColumn();

        if ($attempt_count >= MAX_LOGIN_ATTEMPTS) {
            $error = "Demasiados intentos fallidos. Esperá 15 minutos antes de volver a intentar.";
        } else {
            // Buscar usuario en la BD
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->execute([$user_input]);
            $user = $stmt->fetch();

            if ($user && password_verify($pass_input, $user['password'])) {
                // Login exitoso: limpiar intentos y crear sesión
                $pdo->prepare("DELETE FROM login_attempts WHERE ip = ?")->execute([$ip]);

                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['name'] = $user['name'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['last_activity'] = time();

                log_audit($pdo, 'login', 'user', $user['id'], 'Login exitoso');

                header("Location: dashboard.php");
                exit;
            } else {
                // Registrar intento fallido
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
    <title>Login - CRM Imperio</title>
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Iconos Lucide -->
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="min-h-screen bg-slate-950 flex items-center justify-center p-4 bg-[radial-gradient(ellipse_at_top,_var(--tw-gradient-stops))] from-slate-900 via-slate-950 to-slate-950">

    <div class="bg-slate-900 p-8 rounded-2xl border border-slate-800 shadow-2xl max-w-md w-full text-center relative overflow-hidden">
        
        <!-- Decoración Superior -->
        <div class="absolute top-0 left-0 w-full h-1 bg-gradient-to-r from-blue-500 via-purple-500 to-pink-500"></div>

        <!-- Icono Central -->
        <div class="bg-slate-800 w-20 h-20 rounded-2xl flex items-center justify-center mx-auto mb-6 shadow-inner rotate-3 hover:rotate-6 transition duration-500 group">
            <i data-lucide="users" class="text-blue-400 w-10 h-10 group-hover:scale-110 transition"></i>
        </div>

        <h2 class="text-3xl font-bold text-white mb-2 tracking-tight">CRM Imperio</h2>
        <p class="text-slate-400 mb-8 text-sm">Ingrese sus credenciales para acceder</p>

        <!-- Mensaje de Error -->
        <?php if ($error): ?>
            <div class="bg-red-500/10 border border-red-500/20 text-red-400 text-sm p-3 rounded-lg mb-6 flex items-center gap-2 justify-center">
                <i data-lucide="alert-circle" class="w-4 h-4"></i>
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <!-- Formulario de Login -->
        <form method="POST" action="index.php" class="space-y-4 text-left">
            <?= csrf_field() ?>
            
            <div>
                <label class="block text-xs font-bold text-slate-500 uppercase mb-1.5 ml-1">Usuario</label>
                <div class="relative">
                    <input type="text" name="username" required 
                        class="w-full bg-slate-950 border border-slate-700 rounded-lg px-4 py-3 text-white placeholder:text-slate-600 focus:ring-2 focus:ring-blue-500/50 focus:border-blue-500 outline-none transition-all pl-10"
                        placeholder="Ej: maria">
                    <div class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-500">
                        <i data-lucide="user" class="w-4 h-4"></i>
                    </div>
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-500 uppercase mb-1.5 ml-1">Contraseña</label>
                <div class="relative">
                    <input type="password" name="password" required 
                        class="w-full bg-slate-950 border border-slate-700 rounded-lg px-4 py-3 text-white placeholder:text-slate-600 focus:ring-2 focus:ring-blue-500/50 focus:border-blue-500 outline-none transition-all pl-10"
                        placeholder="••••••">
                    <div class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-500">
                        <i data-lucide="lock" class="w-4 h-4"></i>
                    </div>
                </div>
            </div>

            <button type="submit" class="w-full py-3 px-4 bg-blue-600 hover:bg-blue-500 text-white font-bold rounded-lg shadow-lg shadow-blue-900/50 transition transform hover:-translate-y-0.5 mt-4">
                Ingresar al Sistema
            </button>

        </form>

        <!-- Pie de tarjeta -->
        <div class="mt-8 pt-4 border-t border-slate-800 text-xs text-slate-600">
            &copy; <?= date('Y') ?> CRM Imperio v1.0
        </div>
    </div>

    <!-- Inicializar Iconos -->
    <script>
        lucide.createIcons();
    </script>
</body>
</html>