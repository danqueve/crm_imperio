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
// - Vendedores, Verificadores y Entregadores: SOLO pueden ver su propio perfil (Seguridad)
// - Admin y Supervisor: Pueden ver el de cualquiera por GET, o el suyo por defecto
if (in_array($my_role, ['vendedor', 'verificador', 'entregador'])) {
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
// Comisión: tasa propia del vendedor, salvo ventas RapiCompra que siempre cobran RAPICOMPRA_COMMISSION_RATE
// (misma fórmula que comisiones.php, para no mostrar cifras distintas entre pantallas)
$sellerRate = (float)($seller['commission_rate'] ?? 5.00);
$totalVentas = 0;
$totalComisiones = 0;
foreach ($sales as $i => $s) {
    $rate = (($s['payment_method'] ?? 'normal') === 'rapicompra') ? RAPICOMPRA_COMMISSION_RATE : $sellerRate;
    $commission = $s['total_amount'] * ($rate / 100);
    $sales[$i]['commission'] = $commission;
    $totalVentas += $s['total_amount'];
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
        <a href="dashboard.php" class="p-2 rounded-full transition" style="background:var(--paper);border:1.5px solid var(--line);color:var(--ink-3);" onmouseover="this.style.background='var(--accent-soft)';this.style.color='var(--accent-ink)'" onmouseout="this.style.background='var(--paper)';this.style.color='var(--ink-3)'">
            <i data-lucide="arrow-left"></i>
        </a>
        <div>
            <h1 class="text-2xl font-bold" style="color:var(--ink);">
                <!-- Título Dinámico: Si veo mi propio ID dice 'Mis Ventas', sino 'Ficha' -->
                <?= ($seller_id == $my_id) ? 'Mis Ventas y Comisiones' : 'Ficha de Vendedor' ?>
            </h1>
            <p class="text-sm text-slate-500">Detalle de desempeño y liquidación del periodo.</p>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
        
        <!-- Tarjeta de Perfil -->
        <div class="p-6 rounded-2xl" style="background:var(--card);border:1.5px solid var(--line);box-shadow:var(--shadow-card);">
            <div class="flex items-center gap-4 mb-4">
                <div class="w-16 h-16 rounded-full bg-gradient-to-br from-blue-600 to-purple-600 flex items-center justify-center text-2xl font-bold text-white shadow-lg">
                    <?= strtoupper(substr($seller['name'], 0, 1)) ?>
                </div>
                <div>
                    <h2 class="text-lg font-bold" style="color:var(--ink);"><?= htmlspecialchars($seller['name']) ?></h2>
                    <span class="text-xs px-2 py-1 rounded uppercase font-bold" style="background:var(--accent-soft);color:var(--accent-ink);border:1px solid rgba(99,102,241,.2);"><?= ucfirst($seller['role']) ?></span>
                </div>
            </div>
            <div class="space-y-2 text-sm pt-4" style="color:var(--ink-2);border-top:1.5px solid var(--line);">
                <div class="flex justify-between"><span>Usuario/DNI:</span> <span class="font-mono" style="color:var(--ink);"><?= htmlspecialchars($seller['username']) ?></span></div>
                <div class="flex justify-between"><span>Celular:</span> <span style="color:var(--ink);"><?= htmlspecialchars($seller['phone'] ?? '-') ?></span></div>
                <div class="flex justify-between"><span>Alta:</span> <span style="color:var(--ink);"><?= date('d/m/Y', strtotime($seller['created_at'])) ?></span></div>
            </div>
        </div>

        <!-- Tarjeta de Totales del Periodo -->
        <div class="lg:col-span-2 p-6 rounded-2xl flex flex-col justify-between" style="background:var(--card);border:1.5px solid var(--line);box-shadow:var(--shadow-card);">
            
            <!-- Filtro -->
            <form class="flex flex-wrap gap-3 items-end mb-6 pb-6" style="border-bottom:1.5px solid var(--line);">
                <!-- Si es admin/sup y está viendo a OTRO, mantenemos el ID en el filtro -->
                <?php if ($seller_id != $my_id): ?>
                    <input type="hidden" name="id" value="<?= $seller_id ?>">
                <?php endif; ?>
                
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Desde</label>
                    <input type="date" name="start" value="<?= $start ?>" class="input-light p-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Hasta</label>
                    <input type="date" name="end" value="<?= $end ?>" class="input-light p-2 text-sm">
                </div>
                <button type="submit" class="bg-blue-600 hover:bg-blue-500 text-white px-4 py-2 rounded text-sm font-bold shadow-lg transition">Filtrar Periodo</button>
            </form>

            <!-- Resultados Numéricos -->
            <div class="grid grid-cols-2 gap-4">
                <div class="p-4 rounded-xl" style="background:var(--paper);border:1.5px solid var(--line);">
                    <p class="text-xs text-slate-500 uppercase font-bold">Ventas Entregadas</p>
                    <p class="text-2xl font-bold mt-1" style="color:var(--ink);">$<?= number_format($totalVentas, 0, ',', '.') ?></p>
                    <p class="text-xs text-slate-600 mt-1"><?= count($sales) ?> operaciones</p>
                </div>
                <div class="bg-emerald-900/10 p-4 rounded-xl border border-emerald-500/20">
                    <p class="text-xs text-emerald-500 uppercase font-bold">Comisiones</p>
                    <p class="text-2xl font-bold text-emerald-400 mt-1">$<?= number_format($totalComisiones, 0, ',', '.') ?></p>
                    <p class="text-xs text-emerald-500/60 mt-1">A cobrar</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Listado de Ventas -->
    <div class="rounded-2xl overflow-hidden" style="background:var(--card);border:1.5px solid var(--line);box-shadow:var(--shadow-card);">
        <div class="p-5" style="border-bottom:1.5px solid var(--line);background:#f8f7fc;">
            <h3 class="font-bold flex items-center gap-2" style="color:var(--ink);"><i data-lucide="list" class="w-4 h-4" style="color:var(--ink-3);"></i> Detalle de Ventas del Periodo</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="uppercase text-xs font-bold tracking-wider" style="background:#f8f7fc;color:var(--ink-3);border-bottom:1.5px solid var(--line);">
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
                        <tr class="transition" style="border-bottom:1px dashed var(--line);" onmouseover="this.style.background='rgba(99,102,241,.04)'" onmouseout="this.style.background=''">
                            <td class="p-4 text-black"><?= date('d/m/Y', strtotime($s['delivered_at'])) ?></td>
                            <td class="p-4 font-medium text-black"><?= htmlspecialchars($s['client_name']) ?></td>
                            <td class="p-4 text-black"><?= htmlspecialchars($s['item']) ?></td>
                            <td class="p-4 text-right font-mono text-black">$<?= number_format($s['total_amount'], 0, ',', '.') ?></td>
                            <td class="p-4 text-right font-bold text-emerald-400">$<?= number_format($s['commission'], 0, ',', '.') ?></td>
                            <td class="p-4 text-center">
                                <a href="ver_ficha.php?id=<?= $s['id'] ?>" class="p-1.5 rounded transition inline-block" style="color:var(--accent-ink);" onmouseover="this.style.background='var(--accent)';this.style.color='#fff'" onmouseout="this.style.background='transparent';this.style.color='var(--accent-ink)'"><i data-lucide="eye" class="w-4 h-4"></i></a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>



</main>

<?php include 'includes/footer.php'; ?>