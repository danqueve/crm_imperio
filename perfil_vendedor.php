<?php
require 'includes/db.php';

// Seguridad básica: debe estar logueado
if (!isset($_SESSION['role'])) {
    header("Location: index.php");
    exit;
}

$my_role = $_SESSION['role'];
$my_id = $_SESSION['user_id'];

// LÓGICA DE ID:
// - Vendedores y Verificadores: SOLO pueden ver su propio perfil (Seguridad)
// - Admin y Supervisor: Pueden ver el de cualquiera por GET, o el suyo por defecto
if (in_array($my_role, ['vendedor', 'verificador'])) {
    $seller_id = $my_id;
} else {
    $seller_id = $_GET['id'] ?? $my_id;
}

// 1. Obtener Datos del Vendedor
$stmtUser = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmtUser->execute([$seller_id]);
$seller = $stmtUser->fetch(PDO::FETCH_ASSOC);

if (!$seller) die("Vendedor no encontrado.");

// 2. Configurar Filtro de Fechas (Por defecto Lunes a Sábado de la semana actual)
$defaultStart = date('Y-m-d', strtotime('monday this week'));
$defaultEnd = date('Y-m-d', strtotime('saturday this week'));

$start = $_GET['start'] ?? $defaultStart;
$end = $_GET['end'] ?? $defaultEnd;

$startSql = $start . ' 00:00:00';
$endSql = $end . ' 23:59:59';

// 3. Obtener Ventas Entregadas en el Rango
$sql = "SELECT * FROM sales 
        WHERE user_id = ? 
        AND status = 'entregado' 
        AND delivered_at BETWEEN ? AND ? 
        ORDER BY delivered_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute([$seller_id, $startSql, $endSql]);
$sales = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 4. Calcular Totales
$totalVentas = 0;
$totalComisiones = 0;
foreach ($sales as $s) {
    $totalVentas += $s['total_amount'];
    $commission = $s['total_amount'] * 0.05;
    $totalComisiones += $commission;
}

// 5. Cambio de contraseña (solo el propio usuario)
$pass_message = '';
$pass_error   = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password') {
    csrf_verify();
    $current = $_POST['current_password'] ?? '';
    $new     = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (!password_verify($current, $seller['password'])) {
        $pass_error = "La contraseña actual es incorrecta.";
    } elseif (strlen($new) < 6) {
        $pass_error = "La nueva contraseña debe tener al menos 6 caracteres.";
    } elseif ($new !== $confirm) {
        $pass_error = "Las contraseñas no coinciden.";
    } else {
        $hashed = password_hash($new, PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hashed, $my_id]);
        log_audit($pdo, 'change_password', 'user', $my_id, 'Contraseña actualizada');
        $pass_message = "Contraseña actualizada correctamente.";
    }
}

include 'includes/header.php';
?>

<main class="flex-1 max-w-5xl mx-auto w-full p-4 sm:p-6 lg:p-8 fade-in">

    <!-- Cabecera y Volver -->
    <div class="flex items-center gap-4 mb-8">
        <a href="dashboard.php" class="p-2 bg-slate-800 rounded-full text-slate-400 hover:text-white transition border border-slate-700">
            <i data-lucide="arrow-left"></i>
        </a>
        <div>
            <h1 class="text-2xl font-bold text-white">
                <!-- Título Dinámico: Si veo mi propio ID dice 'Mis Ventas', sino 'Ficha' -->
                <?= ($seller_id == $my_id) ? 'Mis Ventas y Comisiones' : 'Ficha de Vendedor' ?>
            </h1>
            <p class="text-sm text-slate-500">Detalle de desempeño y liquidación del periodo.</p>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
        
        <!-- Tarjeta de Perfil -->
        <div class="bg-slate-900 p-6 rounded-2xl border border-slate-800 shadow-lg">
            <div class="flex items-center gap-4 mb-4">
                <div class="w-16 h-16 rounded-full bg-gradient-to-br from-blue-600 to-purple-600 flex items-center justify-center text-2xl font-bold text-white shadow-lg">
                    <?= strtoupper(substr($seller['name'], 0, 1)) ?>
                </div>
                <div>
                    <h2 class="text-lg font-bold text-white"><?= htmlspecialchars($seller['name']) ?></h2>
                    <span class="text-xs bg-slate-800 text-blue-400 px-2 py-1 rounded border border-slate-700 uppercase font-bold"><?= ucfirst($seller['role']) ?></span>
                </div>
            </div>
            <div class="space-y-2 text-sm text-slate-400 border-t border-slate-800 pt-4">
                <div class="flex justify-between"><span>Usuario/DNI:</span> <span class="text-white font-mono"><?= htmlspecialchars($seller['username']) ?></span></div>
                <div class="flex justify-between"><span>Celular:</span> <span class="text-white"><?= htmlspecialchars($seller['phone'] ?? '-') ?></span></div>
                <div class="flex justify-between"><span>Alta:</span> <span class="text-white"><?= date('d/m/Y', strtotime($seller['created_at'])) ?></span></div>
            </div>
        </div>

        <!-- Tarjeta de Totales del Periodo -->
        <div class="lg:col-span-2 bg-slate-900 p-6 rounded-2xl border border-slate-800 shadow-lg flex flex-col justify-between">
            
            <!-- Filtro -->
            <form class="flex flex-wrap gap-3 items-end mb-6 border-b border-slate-800 pb-6">
                <!-- Si es admin/sup y está viendo a OTRO, mantenemos el ID en el filtro -->
                <?php if ($seller_id != $my_id): ?>
                    <input type="hidden" name="id" value="<?= $seller_id ?>">
                <?php endif; ?>
                
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Desde</label>
                    <input type="date" name="start" value="<?= $start ?>" class="bg-slate-950 border border-slate-700 rounded p-2 text-white text-sm">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Hasta</label>
                    <input type="date" name="end" value="<?= $end ?>" class="bg-slate-950 border border-slate-700 rounded p-2 text-white text-sm">
                </div>
                <button type="submit" class="bg-blue-600 hover:bg-blue-500 text-white px-4 py-2 rounded text-sm font-bold shadow-lg transition">Filtrar Periodo</button>
            </form>

            <!-- Resultados Numéricos -->
            <div class="grid grid-cols-2 gap-4">
                <div class="bg-slate-950/50 p-4 rounded-xl border border-slate-800">
                    <p class="text-xs text-slate-500 uppercase font-bold">Ventas Entregadas</p>
                    <p class="text-2xl font-bold text-white mt-1">$<?= number_format($totalVentas, 0, ',', '.') ?></p>
                    <p class="text-xs text-slate-600 mt-1"><?= count($sales) ?> operaciones</p>
                </div>
                <div class="bg-emerald-900/10 p-4 rounded-xl border border-emerald-500/20">
                    <p class="text-xs text-emerald-500 uppercase font-bold">Comisiones (5%)</p>
                    <p class="text-2xl font-bold text-emerald-400 mt-1">$<?= number_format($totalComisiones, 0, ',', '.') ?></p>
                    <p class="text-xs text-emerald-500/60 mt-1">A cobrar</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Listado de Ventas -->
    <div class="bg-slate-900 rounded-2xl border border-slate-800 shadow-xl overflow-hidden">
        <div class="p-5 border-b border-slate-800">
            <h3 class="font-bold text-white flex items-center gap-2"><i data-lucide="list" class="w-4 h-4 text-slate-500"></i> Detalle de Ventas del Periodo</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-slate-950/50 text-slate-400 uppercase text-xs font-bold tracking-wider border-b border-slate-800">
                    <tr>
                        <th class="p-4">Fecha</th>
                        <th class="p-4">Cliente</th>
                        <th class="p-4">Artículo</th>
                        <th class="p-4 text-right">Monto</th>
                        <th class="p-4 text-right text-emerald-400">Comisión</th>
                        <th class="p-4 text-center">Ver</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800">
                    <?php if (empty($sales)): ?>
                        <tr><td colspan="6" class="p-8 text-center text-slate-500 italic">No hay ventas entregadas en este rango de fechas.</td></tr>
                    <?php else: ?>
                        <?php foreach ($sales as $s): ?>
                        <tr class="hover:bg-slate-800/30 transition">
                            <td class="p-4 text-slate-300"><?= date('d/m/Y', strtotime($s['delivered_at'])) ?></td>
                            <td class="p-4 font-medium text-white"><?= htmlspecialchars($s['client_name']) ?></td>
                            <td class="p-4 text-slate-400"><?= htmlspecialchars($s['item']) ?></td>
                            <td class="p-4 text-right font-mono">$<?= number_format($s['total_amount'], 0, ',', '.') ?></td>
                            <td class="p-4 text-right font-bold text-emerald-400">$<?= number_format($s['total_amount'] * 0.05, 0, ',', '.') ?></td>
                            <td class="p-4 text-center">
                                <a href="ver_ficha.php?id=<?= $s['id'] ?>" class="text-blue-400 hover:text-white p-1.5 hover:bg-slate-700 rounded transition inline-block"><i data-lucide="eye" class="w-4 h-4"></i></a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>


    <!-- Cambio de Contraseña (solo visible para el propio usuario) -->
    <?php if ($seller_id == $my_id): ?>
    <div class="bg-slate-900 rounded-2xl border border-slate-800 shadow-xl mt-6 overflow-hidden">
        <div class="p-5 border-b border-slate-800 flex items-center gap-2">
            <i data-lucide="key-round" class="w-4 h-4 text-slate-500"></i>
            <h3 class="font-bold text-white">Cambiar Contraseña</h3>
        </div>
        <div class="p-6">
            <?php if ($pass_message): ?>
                <div class="bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-sm p-3 rounded-lg mb-4 flex items-center gap-2">
                    <i data-lucide="check-circle" class="w-4 h-4"></i>
                    <?= htmlspecialchars($pass_message) ?>
                </div>
            <?php endif; ?>
            <?php if ($pass_error): ?>
                <div class="bg-red-500/10 border border-red-500/20 text-red-400 text-sm p-3 rounded-lg mb-4 flex items-center gap-2">
                    <i data-lucide="alert-circle" class="w-4 h-4"></i>
                    <?= htmlspecialchars($pass_error) ?>
                </div>
            <?php endif; ?>
            <form method="POST" class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="change_password">
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-1.5">Contraseña Actual</label>
                    <input type="password" name="current_password" required
                        class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2.5 text-white text-sm focus:ring-2 focus:ring-blue-500/50 focus:border-blue-500 outline-none transition">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-1.5">Nueva Contraseña</label>
                    <input type="password" name="new_password" required minlength="6"
                        class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2.5 text-white text-sm focus:ring-2 focus:ring-blue-500/50 focus:border-blue-500 outline-none transition">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-1.5">Confirmar Contraseña</label>
                    <input type="password" name="confirm_password" required minlength="6"
                        class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2.5 text-white text-sm focus:ring-2 focus:ring-blue-500/50 focus:border-blue-500 outline-none transition">
                </div>
                <div class="sm:col-span-3 flex justify-end">
                    <button type="submit" class="px-5 py-2.5 bg-blue-600 hover:bg-blue-500 text-white font-bold rounded-lg shadow-lg transition text-sm">
                        Actualizar Contraseña
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

</main>

<?php include 'includes/footer.php'; ?>