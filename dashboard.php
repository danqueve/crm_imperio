<?php
require 'includes/db.php';

if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
$name = $_SESSION['name'];

// Permisos
$is_vendedor = ($role === 'vendedor');
$is_entregador = ($role === 'entregador');
$is_limited_view = ($is_vendedor || $is_entregador); // Roles que solo ven sus propias ventas
$can_manage = in_array($role, ['admin', 'supervisor', 'verificador']);
$can_assign = in_array($role, ['admin', 'supervisor']);
$is_admin   = ($role === 'admin');

// --- BLOQUE ADMIN: estadísticas, gráficos y filtro de período ---
if ($is_admin) {
    $start = $_GET['start'] ?? date('Y-m-01');
    $end   = $_GET['end']   ?? date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) $start = date('Y-m-01');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end))   $end   = date('Y-m-d');
    if ($start > $end) [$start, $end] = [$end, $start];
    $startSql = $start . ' 00:00:00';
    $endSql   = $end   . ' 23:59:59';

    $stmtStats = $pdo->prepare(
        "SELECT status, COUNT(*) as total, SUM(total_amount) as monto
         FROM sales WHERE created_at BETWEEN ? AND ? GROUP BY status"
    );
    $stmtStats->execute([$startSql, $endSql]);
    $statsRaw = $stmtStats->fetchAll(PDO::FETCH_ASSOC);

    $stats = ['revision' => 0, 'aprobado' => 0, 'entregado' => 0, 'rechazado' => 0];
    $statsMonto = ['revision' => 0, 'aprobado' => 0, 'entregado' => 0, 'rechazado' => 0];
    foreach ($statsRaw as $row) {
        $stats[$row['status']]      = (int)$row['total'];
        $statsMonto[$row['status']] = (float)$row['monto'];
    }
    $total_periodo = array_sum($stats);

    $denominador = $stats['entregado'] + $stats['rechazado'];
    $efectividad = $denominador > 0 ? round($stats['entregado'] / $denominador * 100) : 0;

    // --- Objetivos de Ventas (todos los roles activos, mes calendario en curso, independiente del filtro de arriba) ---
    $mtdStart = date('Y-m-01') . ' 00:00:00';
    $mtdEnd   = date('Y-m-d') . ' 23:59:59';
    $stmtGoals = $pdo->prepare(
        "SELECT u.id, u.name, u.role,
                COALESCE(MAX(g.target_sales), 0) as target_sales,
                COUNT(CASE WHEN s.status IN ('aprobado','entregado')
                                AND s.created_at BETWEEN ? AND ?
                           THEN s.id END) as progress_sales
         FROM users u
         LEFT JOIN seller_goals g ON g.user_id = u.id
         LEFT JOIN sales s ON s.user_id = u.id
         WHERE u.is_active = 1
         GROUP BY u.id
         ORDER BY u.name ASC"
    );
    $stmtGoals->execute([$mtdStart, $mtdEnd]);
    $sellerGoals = $stmtGoals->fetchAll(PDO::FETCH_ASSOC);
}

// --- Objetivo personal del usuario logueado (cualquier rol) ---
$mtdStart = date('Y-m-01') . ' 00:00:00';
$mtdEnd   = date('Y-m-d') . ' 23:59:59';
$stmtMyGoal = $pdo->prepare(
    "SELECT COALESCE(MAX(g.target_sales), 0) as target_sales,
            COUNT(CASE WHEN s.status IN ('aprobado','entregado')
                            AND s.created_at BETWEEN ? AND ?
                       THEN s.id END) as progress_sales
     FROM users u
     LEFT JOIN seller_goals g ON g.user_id = u.id
     LEFT JOIN sales s ON s.user_id = u.id
     WHERE u.id = ?
     GROUP BY u.id"
);
$stmtMyGoal->execute([$mtdStart, $mtdEnd, $user_id]);
$myGoal     = $stmtMyGoal->fetch(PDO::FETCH_ASSOC);
$myTarget   = (int)($myGoal['target_sales'] ?? 0);
$myProgress = (int)($myGoal['progress_sales'] ?? 0);
$myPct      = $myTarget > 0 ? round($myProgress / $myTarget * 100) : 0;

// --- 3. LISTADO DE TABLA (BANDEJA DE ENTRADA) ---
if ($is_limited_view) {
    $stmt = $pdo->prepare("SELECT * FROM sales WHERE user_id = ? AND status IN ('revision', 'aprobado') ORDER BY created_at DESC");
    $stmt->execute([$user_id]);
} elseif ($role === 'verificador') {
    // Modelo estricto: el verificador solo ve las ventas que le fueron asignadas puntualmente
    $stmt = $pdo->prepare("SELECT sales.*, users.name as seller_name
        FROM sales JOIN users ON sales.user_id = users.id
        WHERE sales.status = 'revision' AND sales.assigned_verifier_id = ?
        ORDER BY sales.created_at DESC");
    $stmt->execute([$user_id]);
} else {
    // Admin/Supervisor ven todo el pool en revisión, junto a quién está asignada cada venta
    $stmt = $pdo->query("SELECT sales.*, users.name as seller_name, av.name as verifier_name
        FROM sales JOIN users ON sales.user_id = users.id
        LEFT JOIN users av ON sales.assigned_verifier_id = av.id
        WHERE sales.status = 'revision' ORDER BY sales.created_at DESC");
}
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Verificadores activos disponibles para asignar (solo se usa si $can_assign)
$verifiers = $can_assign
    ? $pdo->query("SELECT id, name FROM users WHERE role = 'verificador' AND is_active = 1 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC)
    : [];

// PREPARAR DATOS PDF
$pdfData = array_map(function($order) {
    return [
        'id' => $order['id'],
        'raw_date' => $order['created_at'],
        'date' => date('d/m/Y H:i', strtotime($order['created_at'])),
        'client' => $order['client_name'],
        'address' => $order['client_address'],
        'phone' => $order['client_whatsapp'],
        'item' => $order['item'],
        'seller' => $order['seller_name'] ?? 'Yo',
        'verifier' => $order['verifier_name'] ?? 'Sin asignar',
        'verifier_id' => (int)($order['assigned_verifier_id'] ?? 0)
    ];
}, $orders);

include 'includes/header.php';
?>

<!-- Librería de exportación PDF -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.29/jspdf.plugin.autotable.min.js"></script>

<main class="flex-1 max-w-7xl mx-auto w-full p-4 sm:p-6 lg:p-8 fade-in">
    
    <?php if (isset($_GET['msg']) && $_GET['msg'] == 'saved'): ?>
    <div id="toast-success" class="fixed bottom-6 right-6 z-50 flex items-center gap-4 rounded-2xl shadow-xl px-5 py-4" style="background:var(--card);border:1.5px solid var(--apr-bg);animation:slideInToast 0.4s cubic-bezier(0.34,1.56,0.64,1) both;box-shadow:0 8px 32px rgba(11,107,70,.12);">
        <div style="background:var(--apr-bg);padding:10px;border-radius:12px;flex-shrink:0;">
            <i data-lucide="check-circle" class="w-5 h-5" style="color:var(--apr-ink);"></i>
        </div>
        <div>
            <p class="font-bold text-sm" style="color:var(--ink);">¡Venta registrada!</p>
            <p class="text-xs mt-0.5" style="color:var(--ink-3);">Guardada correctamente en el sistema.</p>
        </div>
        <button onclick="dismissToast()" class="ml-1 p-1.5 rounded-lg transition shrink-0" style="color:var(--ink-3);" onmouseover="this.style.background='var(--paper)'" onmouseout="this.style.background='transparent'">
            <i data-lucide="x" class="w-4 h-4"></i>
        </button>
    </div>
    <script>
        function dismissToast() {
            const t = document.getElementById('toast-success');
            if (t) { t.style.transition = 'transform 0.4s ease, opacity 0.4s ease'; t.style.transform = 'translateX(110%)'; t.style.opacity = '0'; setTimeout(() => t.remove(), 400); }
        }
        setTimeout(dismissToast, 4500);
    </script>
    <?php endif; ?>

    <!-- Cabecera -->
    <div class="flex flex-col md:flex-row md:items-end justify-between gap-4 mb-6">
        <div>
            <h2 class="text-3xl font-bold tracking-tight uppercase" style="color:var(--ink);">Panel de Control</h2>
            <p class="text-sm mt-1" style="color:var(--ink-3);">Bienvenido, <span class="font-bold" style="color:var(--accent-ink);"><?= htmlspecialchars($name) ?></span>.</p>
        </div>
        <div class="flex flex-wrap gap-3">
            <?php if ($can_assign): ?>
            <div class="relative shrink-0" id="verifierFilterWrap">
                <button type="button" onclick="toggleVerifierDropdown()" id="verifierFilterBtn"
                        class="input-light rounded-xl px-3 py-2 text-sm flex items-center gap-2">
                    <i data-lucide="user-check" class="w-3.5 h-3.5"></i>
                    <span id="verifierFilterLabel">Todos</span>
                    <i data-lucide="chevron-down" class="w-3.5 h-3.5"></i>
                </button>
                <div id="verifierFilterDropdown" class="hidden absolute z-20 mt-2 w-60 rounded-xl shadow-lg p-3"
                     style="background:var(--card);border:1.5px solid var(--line);">
                    <div class="flex justify-between mb-2 text-[10px] font-bold uppercase tracking-widest" style="color:var(--ink-3);">
                        <span>Verificador asignado</span>
                        <button type="button" onclick="toggleAllVerifiers(false)" class="hover:underline">Limpiar</button>
                    </div>
                    <div class="max-h-56 overflow-y-auto space-y-0.5">
                        <label class="flex items-center gap-2 text-sm px-1.5 py-1.5 rounded-lg cursor-pointer hover:bg-black/5">
                            <input type="checkbox" class="verifier-checkbox" value="0" onchange="updateVerifierLabel()">
                            <span style="color:var(--ink-2);">Sin asignar</span>
                        </label>
                        <?php foreach ($verifiers as $v): ?>
                        <label class="flex items-center gap-2 text-sm px-1.5 py-1.5 rounded-lg cursor-pointer hover:bg-black/5">
                            <input type="checkbox" class="verifier-checkbox" value="<?= $v['id'] ?>" onchange="updateVerifierLabel()">
                            <span style="color:var(--ink-2);"><?= htmlspecialchars($v['name']) ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            <button onclick="exportarPDF()" class="flex items-center gap-2 text-sm font-bold px-4 py-2 rounded-xl transition" style="background:var(--rec-bg);color:var(--rec-ink);border:1.5px solid rgba(159,18,57,.2);" onmouseover="this.style.opacity='.85'" onmouseout="this.style.opacity='1'">
                <i data-lucide="file-down" class="w-4 h-4"></i> PDF Pendientes
            </button>
            <?php if ($can_manage || $is_entregador): ?>
            <a href="entregas.php" class="flex items-center gap-2 text-sm font-bold px-4 py-2 rounded-xl transition" style="background:var(--apr-bg);color:var(--apr-ink);border:1.5px solid rgba(11,107,70,.2);">
                <i data-lucide="truck" class="w-4 h-4"></i> Gestión Entregas
            </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($myTarget > 0): ?>
    <!-- Objetivo del mes (usuario logueado, cualquier rol) -->
    <div class="rounded-2xl p-5 mb-8 flex items-center justify-between gap-4 flex-wrap" style="background:var(--card);border:1.5px solid var(--line);box-shadow:var(--shadow-card);">
        <div class="flex items-center gap-3">
            <div class="p-2.5 rounded-xl" style="background:var(--accent-soft);color:var(--accent-ink);"><i data-lucide="target" class="w-5 h-5"></i></div>
            <div>
                <p class="text-[10px] uppercase font-bold tracking-widest" style="color:var(--ink-3);">Objetivo del mes</p>
                <p class="text-lg font-bold" style="color:var(--ink);"><?= $myProgress ?> / <?= $myTarget ?> ventas <span style="color:var(--accent-ink);">(<?= $myPct ?>%)</span></p>
            </div>
        </div>
        <div class="w-full sm:w-48 h-2 rounded-full overflow-hidden shrink-0" style="background:var(--line);">
            <div class="h-full <?= $myPct >= 100 ? 'bg-green-500' : ($myPct >= 50 ? 'bg-yellow-500' : 'bg-red-500') ?>" style="width: <?= min(100, $myPct) ?>%"></div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($is_admin): ?>
    <!-- Filtro de Período -->
    <div class="rounded-2xl p-4 mb-8" style="background:var(--card);border:1.5px solid var(--line);box-shadow:var(--shadow-card);">
        <form method="GET" class="flex flex-col sm:flex-row gap-3 items-end">
            <div class="flex items-center gap-2 shrink-0 mb-1 sm:mb-0">
                <i data-lucide="calendar-range" class="w-4 h-4" style="color:var(--accent);"></i>
                <span class="text-[10px] font-bold uppercase tracking-widest" style="color:var(--ink-3);">Período de análisis</span>
            </div>
            <div class="flex gap-3 flex-1 flex-wrap">
                <div class="flex-1 min-w-[140px]">
                    <label class="block text-[10px] font-bold uppercase mb-1 ml-1 tracking-widest" style="color:var(--ink-3);">Desde</label>
                    <input type="date" name="start" value="<?= $start ?>" class="w-full input-light px-3 py-2 text-sm" style="color:var(--ink);">
                </div>
                <div class="flex-1 min-w-[140px]">
                    <label class="block text-[10px] font-bold uppercase mb-1 ml-1 tracking-widest" style="color:var(--ink-3);">Hasta</label>
                    <input type="date" name="end" value="<?= $end ?>" class="w-full input-light px-3 py-2 text-sm" style="color:var(--ink);">
                </div>
            </div>
            <div class="flex gap-2 shrink-0">
                <button type="submit" class="text-white px-5 py-2 rounded-xl font-bold text-sm transition flex items-center gap-2" style="background:var(--accent);">
                    <i data-lucide="filter" class="w-4 h-4"></i> Aplicar
                </button>
                <a href="dashboard.php" class="px-4 py-2 rounded-xl font-bold text-sm transition flex items-center gap-1" style="background:var(--paper);border:1.5px solid var(--line);color:var(--ink-2);" title="Resetear al mes actual">
                    <i data-lucide="rotate-ccw" class="w-4 h-4"></i>
                </a>
            </div>
        </form>
        <?php if ($total_periodo > 0 || $start !== date('Y-m-01') || $end !== date('Y-m-d')): ?>
        <div class="mt-3 pt-3 flex flex-wrap gap-4 text-xs" style="border-top:1px dashed var(--line);color:var(--ink-3);">
            <span class="flex items-center gap-1.5"><i data-lucide="calendar" class="w-3 h-3"></i> <?= date('d/m/Y', strtotime($start)) ?> — <?= date('d/m/Y', strtotime($end)) ?></span>
            <span class="flex items-center gap-1.5"><i data-lucide="bar-chart-2" class="w-3 h-3"></i> <span class="font-bold" style="color:var(--ink);"><?= $total_periodo ?></span> ventas en el período</span>
            <span class="flex items-center gap-1.5 font-bold" style="color:var(--apr-ink);"><i data-lucide="dollar-sign" class="w-3 h-3"></i> $<?= number_format($statsMonto['entregado'], 0, ',', '.') ?> entregado</span>
            <?php if ($efectividad > 0): ?>
            <span class="flex items-center gap-1.5 font-bold" style="color:var(--accent-ink);"><i data-lucide="target" class="w-3 h-3"></i> <?= $efectividad ?>% efectividad</span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- 2. RESUMEN NUMÉRICO (TARJETAS KPI) -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        <div class="p-5 rounded-2xl flex items-center justify-between cursor-default transition-all hover:-translate-y-0.5" style="background:var(--rev-bg);border:1.5px solid rgba(138,90,8,.18);box-shadow:0 2px 12px rgba(138,90,8,.08);">
            <div>
                <p class="text-[10px] uppercase font-bold tracking-widest mb-1" style="color:var(--rev-ink);">En Revisión</p>
                <p class="text-3xl font-bold stat-counter" style="color:var(--ink);" data-target="<?= $stats['revision'] ?>">0</p>
                <?php if ($statsMonto['revision'] > 0): ?>
                <p class="text-[10px] mt-1 font-mono" style="color:var(--rev-ink);">$<?= number_format($statsMonto['revision'], 0, ',', '.') ?></p>
                <?php endif; ?>
            </div>
            <div class="p-2.5 rounded-xl" style="background:rgba(138,90,8,.12);color:var(--rev-ink);"><i data-lucide="clock" class="w-5 h-5"></i></div>
        </div>
        <div class="p-5 rounded-2xl flex items-center justify-between cursor-default transition-all hover:-translate-y-0.5" style="background:var(--apr-bg);border:1.5px solid rgba(11,107,70,.18);box-shadow:0 2px 12px rgba(11,107,70,.08);">
            <div>
                <p class="text-[10px] uppercase font-bold tracking-widest mb-1" style="color:var(--apr-ink);">Aprobadas</p>
                <p class="text-3xl font-bold stat-counter" style="color:var(--ink);" data-target="<?= $stats['aprobado'] ?>">0</p>
                <?php if ($statsMonto['aprobado'] > 0): ?>
                <p class="text-[10px] mt-1 font-mono" style="color:var(--apr-ink);">$<?= number_format($statsMonto['aprobado'], 0, ',', '.') ?></p>
                <?php endif; ?>
            </div>
            <div class="p-2.5 rounded-xl" style="background:rgba(11,107,70,.12);color:var(--apr-ink);"><i data-lucide="check" class="w-5 h-5"></i></div>
        </div>
        <div class="p-5 rounded-2xl flex items-center justify-between cursor-default transition-all hover:-translate-y-0.5" style="background:var(--ent-bg);border:1.5px solid rgba(55,48,163,.18);box-shadow:0 2px 12px rgba(55,48,163,.08);">
            <div>
                <p class="text-[10px] uppercase font-bold tracking-widest mb-1" style="color:var(--ent-ink);">Entregadas</p>
                <p class="text-3xl font-bold stat-counter" style="color:var(--ink);" data-target="<?= $stats['entregado'] ?>">0</p>
                <?php if ($statsMonto['entregado'] > 0): ?>
                <p class="text-[10px] mt-1 font-mono font-bold" style="color:var(--apr-ink);">$<?= number_format($statsMonto['entregado'], 0, ',', '.') ?></p>
                <?php endif; ?>
            </div>
            <div class="p-2.5 rounded-xl" style="background:rgba(55,48,163,.12);color:var(--ent-ink);"><i data-lucide="truck" class="w-5 h-5"></i></div>
        </div>
        <div class="p-5 rounded-2xl flex items-center justify-between cursor-default transition-all hover:-translate-y-0.5" style="background:var(--rec-bg);border:1.5px solid rgba(159,18,57,.18);box-shadow:0 2px 12px rgba(159,18,57,.08);">
            <div>
                <p class="text-[10px] uppercase font-bold tracking-widest mb-1" style="color:var(--rec-ink);">Rechazadas</p>
                <p class="text-3xl font-bold stat-counter" style="color:var(--ink);" data-target="<?= $stats['rechazado'] ?>">0</p>
                <?php if ($efectividad > 0): ?>
                <p class="text-[10px] mt-1" style="color:var(--ink-3);">efectiv. <span class="font-bold" style="color:var(--accent-ink);"><?= $efectividad ?>%</span></p>
                <?php endif; ?>
            </div>
            <div class="p-2.5 rounded-xl" style="background:rgba(159,18,57,.12);color:var(--rec-ink);"><i data-lucide="x-circle" class="w-5 h-5"></i></div>
        </div>
    </div>

    <?php endif; // fin bloque admin ?>

    <!-- 1. BANDEJA DE ENTRADA (TABLA) -->
    <div class="rounded-2xl overflow-hidden <?= $is_admin ? 'mt-8' : '' ?>" style="background:var(--card);border:1.5px solid var(--line);box-shadow:var(--shadow-card);">
        <div class="p-5 flex justify-between items-center" style="border-bottom:1.5px solid var(--line);background:#f8f7fc;">
            <h3 class="font-bold text-base flex items-center gap-2 uppercase tracking-wide" style="color:var(--ink);">
                <i data-lucide="inbox" class="w-5 h-5" style="color:var(--accent);"></i>
                <?= $is_limited_view ? "Mis Ventas Activas" : ($role === 'verificador' ? "Mis Verificaciones Asignadas" : "Bandeja de Entrada · Revisión") ?>
            </h3>
            <span class="text-[10px] font-bold uppercase tracking-widest px-3 py-1 rounded-full" style="color:var(--ink-3);background:var(--paper);border:1.5px solid var(--line);">
                <?= count($orders) ?> registros
            </span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm table-light">
                <thead>
                    <tr>
                        <th class="hidden sm:table-cell">#</th>
                        <th class="hidden sm:table-cell">Fecha</th>
                        <th>Cliente</th>
                        <th class="hidden lg:table-cell">Localidad</th>
                        <th class="hidden lg:table-cell">Cargado por</th>
                        <th>Artículo / Total</th>
                        <th class="hidden sm:table-cell">Estado</th>
                        <th style="text-align:right;">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($orders)): ?>
                        <tr><td colspan="8" class="p-16 text-center">
                            <div class="flex flex-col items-center gap-4">
                                <div class="p-5 rounded-2xl" style="background:var(--paper);border:1.5px solid var(--line);">
                                    <i data-lucide="inbox" class="w-10 h-10" style="color:var(--ink-3);"></i>
                                </div>
                                <div>
                                    <p class="font-semibold text-base" style="color:var(--ink-2);">Todo al día</p>
                                    <p class="text-sm mt-1" style="color:var(--ink-3);">No hay ventas pendientes de procesar en este momento.</p>
                                </div>
                            </div>
                        </td></tr>
                    <?php else: ?>
                        <?php $counter = 1; foreach ($orders as $order): ?>
                        <tr class="<?= $order['status'] === 'revision' ? 'row-revision' : 'row-aprobado' ?>">
                            <td class="hidden sm:table-cell font-bold" style="color:var(--ink-3);"><?= $counter++ ?></td>
                            <td class="hidden sm:table-cell font-mono text-xs whitespace-nowrap" style="color:var(--ink-2);"><?= date('d/m/Y H:i', strtotime($order['created_at'])) ?></td>
                            <td class="p-3 sm:p-[13px]">
                                <div class="font-bold flex items-center gap-1.5" style="color:var(--ink);">
                                    <?= htmlspecialchars($order['client_name']) ?>
                                    <?php if (($order['sale_type'] ?? 'credito') === 'contado'): ?>
                                    <span class="text-[9px] font-bold uppercase px-1.5 py-0.5 rounded" style="background:var(--apr-bg);color:var(--apr-ink);">Contado</span>
                                    <?php endif; ?>
                                    <?php if (($order['payment_method'] ?? 'normal') === 'rapicompra'): ?>
                                    <span class="text-[9px] font-bold uppercase px-1.5 py-0.5 rounded" style="background:var(--accent-soft);color:var(--accent-ink);">RapiCompra</span>
                                    <?php endif; ?>
                                </div>
                                <div class="text-[10px] font-mono flex items-center gap-1 mt-0.5" style="color:var(--apr-ink);">
                                    <i data-lucide="phone" class="w-2.5 h-2.5"></i> <?= htmlspecialchars($order['client_whatsapp']) ?>
                                </div>
                                <div class="text-[10px] font-mono mt-0.5 sm:hidden" style="color:var(--ink-3);"><?= date('d/m/Y H:i', strtotime($order['created_at'])) ?></div>
                            </td>
                            <td class="hidden lg:table-cell" style="color:var(--ink-2);">
                                <div class="flex items-center gap-1.5">
                                    <i data-lucide="map-pin" class="w-3.5 h-3.5" style="color:var(--ink-3);"></i>
                                    <?= htmlspecialchars($order['client_locality'] ?: '-') ?>
                                </div>
                            </td>
                            <td class="hidden lg:table-cell" style="color:var(--ink-2);">
                                <div class="flex items-center gap-1.5">
                                    <i data-lucide="user-edit" class="w-3.5 h-3.5" style="color:var(--ink-3);"></i>
                                    <a href="perfil_vendedor.php?id=<?= $order['user_id'] ?>" class="transition hover:underline" style="color:var(--ink-2);" onmouseover="this.style.color='var(--accent-ink)'" onmouseout="this.style.color='var(--ink-2)'">
                                        <?= isset($order['seller_name']) ? htmlspecialchars($order['seller_name']) : 'Yo' ?>
                                    </a>
                                </div>
                            </td>
                            <td class="p-3 sm:p-[13px]">
                                <div class="font-medium" style="color:var(--ink);"><?= htmlspecialchars($order['item']) ?></div>
                                <div class="text-xs font-bold" style="color:var(--apr-ink);">$<?= number_format($order['total_amount'], 0, ',', '.') ?></div>
                                <div class="mt-1 sm:hidden"><?= status_badge($order['status']) ?></div>
                            </td>
                            <td class="hidden sm:table-cell"><?= status_badge($order['status']) ?></td>
                            <td class="p-3 sm:p-[13px]" style="text-align:right;">
                                <?php if ($can_assign && $order['status'] === 'revision'): ?>
                                <div class="flex justify-end mb-1">
                                    <?php if (!empty($order['verifier_name'])): ?>
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold" style="background:var(--accent-soft);color:var(--accent-ink);">
                                        <i data-lucide="user-check" class="w-2.5 h-2.5"></i> <?= htmlspecialchars($order['verifier_name']) ?>
                                        <?php if (!empty($order['assigned_at'])): ?>
                                        <span class="font-mono opacity-75">· <?= date('H:i', strtotime($order['assigned_at'])) ?></span>
                                        <?php endif; ?>
                                    </span>
                                    <?php else: ?>
                                    <span class="text-[10px] italic" style="color:var(--ink-3);">Sin asignar</span>
                                    <?php endif; ?>
                                </div>
                                <form method="POST" action="asignar_verificador.php" class="flex justify-end items-center gap-1.5 mb-2">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="sale_id" value="<?= $order['id'] ?>">
                                    <select name="verifier_id" class="input-light text-xs min-h-[44px] py-2 px-2 rounded-lg cursor-pointer min-w-[110px]">
                                        <option value="">Sin asignar</option>
                                        <?php foreach ($verifiers as $v): ?>
                                        <option value="<?= $v['id'] ?>" <?= (int)($order['assigned_verifier_id'] ?? 0) === (int)$v['id'] ? 'selected' : '' ?>><?= htmlspecialchars($v['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="w-11 h-11 flex items-center justify-center rounded-lg transition shrink-0" style="background:var(--accent-soft);color:var(--accent-ink);border:1.5px solid rgba(99,102,241,.2);" title="Asignar verificador" onmouseover="this.style.background='var(--accent)';this.style.color='#fff'" onmouseout="this.style.background='var(--accent-soft)';this.style.color='var(--accent-ink)'">
                                        <i data-lucide="user-check" class="w-4 h-4"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                                <div class="flex justify-end gap-1.5 sm:gap-2 items-center">
                                    <a href="ver_ficha.php?id=<?= $order['id'] ?>" class="w-11 h-11 flex items-center justify-center rounded-lg transition" style="background:var(--accent-soft);color:var(--accent-ink);border:1.5px solid rgba(99,102,241,.2);" title="Ver Ficha" onmouseover="this.style.background='var(--accent)';this.style.color='#fff'" onmouseout="this.style.background='var(--accent-soft)';this.style.color='var(--accent-ink)'">
                                        <i data-lucide="eye" class="w-4 h-4"></i>
                                    </a>

                                    <?php if ($can_manage && $order['status'] === 'revision'): ?>
                                        <div class="w-px h-4 mx-1" style="background:var(--line);"></div>
                                        <form method="POST" action="update_status.php" class="inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= $order['id'] ?>">
                                            <input type="hidden" name="status" value="aprobado">
                                            <button type="submit" class="w-11 h-11 flex items-center justify-center rounded-lg transition" style="background:var(--apr-bg);color:var(--apr-ink);border:1.5px solid rgba(11,107,70,.2);" title="Aprobar Venta" onmouseover="this.style.background='var(--apr-ink)';this.style.color='#fff'" onmouseout="this.style.background='var(--apr-bg)';this.style.color='var(--apr-ink)'">
                                                <i data-lucide="check" class="w-4 h-4"></i>
                                            </button>
                                        </form>
                                        <a href="rechazar_venta.php?id=<?= $order['id'] ?>" class="w-11 h-11 flex items-center justify-center rounded-lg transition" style="background:var(--rec-bg);color:var(--rec-ink);border:1.5px solid rgba(159,18,57,.2);" title="Rechazar Venta" onclick="return confirm('¿Confirma que desea rechazar esta venta?')" onmouseover="this.style.background='var(--rec-ink)';this.style.color='#fff'" onmouseout="this.style.background='var(--rec-bg)';this.style.color='var(--rec-ink)'">
                                            <i data-lucide="x" class="w-4 h-4"></i>
                                        </a>
                                    <?php endif; ?>

                                    <?php if ($is_vendedor && $order['status'] === 'revision'): ?>
                                        <div class="w-px h-4 mx-1" style="background:var(--line);"></div>
                                        <a href="rechazar_venta.php?id=<?= $order['id'] ?>" class="w-11 h-11 flex items-center justify-center rounded-lg transition" style="background:var(--rec-bg);color:var(--rec-ink);border:1.5px solid rgba(159,18,57,.2);" title="Cancelar mi carga" onclick="return confirm('¿Confirma que desea cancelar y retirar esta venta enviada?')" onmouseover="this.style.background='var(--rec-ink)';this.style.color='#fff'" onmouseout="this.style.background='var(--rec-bg)';this.style.color='var(--rec-ink)'">
                                            <i data-lucide="trash-2" class="w-4 h-4"></i>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($is_admin): ?>
    <!-- 4. OBJETIVOS DE VENTAS -->
    <div class="rounded-2xl overflow-hidden mt-8" style="background:var(--card);border:1.5px solid var(--line);box-shadow:var(--shadow-card);">
        <div class="p-5" style="border-bottom:1.5px solid var(--line);background:#f8f7fc;">
            <h3 class="font-bold text-base flex items-center gap-2 uppercase tracking-wide" style="color:var(--ink);">
                <i data-lucide="target" class="w-5 h-5" style="color:var(--accent);"></i> Objetivos de Ventas · Mes Actual
            </h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="uppercase text-[10px] font-bold tracking-widest" style="background:#f8f7fc;color:var(--ink-3);border-bottom:1.5px solid var(--line);">
                    <tr>
                        <th class="p-4 pl-6">Usuario</th>
                        <th class="p-4 text-center">Objetivo</th>
                        <th class="p-4">Progreso</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($sellerGoals)): ?>
                        <tr><td colspan="3" class="p-8 text-center italic" style="color:var(--ink-3);">No hay usuarios activos registrados.</td></tr>
                    <?php else: ?>
                        <?php foreach ($sellerGoals as $g): ?>
                        <?php
                        $gTarget = (int)$g['target_sales'];
                        $gProgress = (int)$g['progress_sales'];
                        $gPct = $gTarget > 0 ? round($gProgress / $gTarget * 100) : 0;
                        $gColor = 'bg-gray-400';
                        if ($gPct >= 100) $gColor = 'bg-green-500';
                        elseif ($gPct >= 50) $gColor = 'bg-yellow-500';
                        elseif ($gPct > 0) $gColor = 'bg-red-500';
                        ?>
                        <tr style="border-bottom:1px dashed var(--line);">
                            <td class="p-4 pl-6">
                                <div class="font-bold" style="color:var(--ink);"><?= htmlspecialchars($g['name']) ?></div>
                                <span class="inline-flex items-center px-2 py-0.5 mt-1 rounded text-[10px] font-bold uppercase tracking-wide border" style="background:var(--paper);color:var(--ink-2);border-color:var(--line);"><?= ucfirst($g['role']) ?></span>
                            </td>
                            <td class="p-4 text-center">
                                <form method="POST" action="set_goal.php" class="flex items-center justify-center gap-2">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="user_id" value="<?= $g['id'] ?>">
                                    <input type="number" name="target_sales" min="0" step="1" value="<?= $gTarget ?>" class="w-16 input-light text-xs py-1 px-2 text-center font-mono">
                                    <button type="submit" class="px-3 py-1.5 rounded-lg text-xs font-bold transition text-white" style="background:var(--accent);">Guardar</button>
                                </form>
                            </td>
                            <td class="p-4">
                                <?php if ($gTarget === 0): ?>
                                    <span class="italic text-xs" style="color:var(--ink-3);">Sin objetivo asignado</span>
                                <?php else: ?>
                                    <div class="flex items-center gap-3">
                                        <span class="text-xs font-bold whitespace-nowrap" style="color:var(--ink);"><?= $gProgress ?> / <?= $gTarget ?> ventas (<?= $gPct ?>%)</span>
                                        <div class="w-full h-1.5 rounded-full overflow-hidden" style="background:var(--line);max-width:160px;">
                                            <div class="h-full <?= $gColor ?>" style="width: <?= min(100, $gPct) ?>%"></div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; // fin sección objetivos ?>
</main>

<script>
    <?php if ($is_admin): ?>
    // Contadores animados en stat cards
    document.querySelectorAll('.stat-counter').forEach(el => {
        const target = parseInt(el.dataset.target) || 0;
        if (target === 0) { el.textContent = '0'; return; }
        const duration = 900;
        const startTime = performance.now();
        function update(now) {
            const progress = Math.min((now - startTime) / duration, 1);
            const eased = 1 - Math.pow(1 - progress, 3);
            el.textContent = Math.round(eased * target);
            if (progress < 1) requestAnimationFrame(update);
        }
        requestAnimationFrame(update);
    });
    <?php endif; ?>

    // Dropdown de verificador (multi-selección con checkboxes) para el PDF
    function toggleVerifierDropdown() {
        const el = document.getElementById('verifierFilterDropdown');
        if (el) el.classList.toggle('hidden');
    }
    function toggleAllVerifiers(checked) {
        document.querySelectorAll('.verifier-checkbox').forEach(cb => cb.checked = checked);
        updateVerifierLabel();
    }
    function updateVerifierLabel() {
        const checked = document.querySelectorAll('.verifier-checkbox:checked');
        const label = document.getElementById('verifierFilterLabel');
        if (!label) return;
        if (checked.length === 0) label.textContent = 'Todos';
        else if (checked.length === 1) label.textContent = checked[0].parentElement.querySelector('span').textContent.trim();
        else label.textContent = checked.length + ' seleccionados';
    }
    document.addEventListener('click', function(e) {
        const wrap = document.getElementById('verifierFilterWrap');
        if (wrap && !wrap.contains(e.target)) {
            document.getElementById('verifierFilterDropdown').classList.add('hidden');
        }
    });

    // Lógica Exportación PDF
    const salesData = <?= json_encode($pdfData) ?>;
    function exportarPDF() {
        const checkedBoxes = document.querySelectorAll('.verifier-checkbox:checked');
        const verifierIds  = Array.from(checkedBoxes).map(cb => cb.value);
        const verifierNames = Array.from(checkedBoxes).map(cb => cb.parentElement.querySelector('span').textContent.trim());
        const data = verifierIds.length > 0
            ? salesData.filter(r => verifierIds.includes(String(r.verifier_id)))
            : salesData;

        if (data.length === 0) {
            alert(verifierIds.length > 0 ? `No hay ventas para: ${verifierNames.join(', ')}.` : "No hay ventas para exportar.");
            return;
        }

        const { jsPDF } = window.jspdf;
        const doc = new jsPDF('p', 'mm', 'a4');
        const pageWidth = doc.internal.pageSize.getWidth();
        doc.setFontSize(16); doc.setFont("helvetica", "bold");
        doc.text("VENTAS PENDIENTES DE REVISIÓN", pageWidth / 2, 15, { align: 'center' });

        let headerY = 22;
        if (verifierNames.length > 0) {
            doc.setFontSize(9); doc.setFont("helvetica", "bolditalic");
            const verifierLines = doc.splitTextToSize('Verificador: ' + verifierNames.join(', '), pageWidth - 20);
            doc.text(verifierLines, pageWidth / 2, headerY, { align: 'center' });
            headerY += verifierLines.length * 4;
        }
        const tableStartY = headerY + 3;

        doc.autoTable({
            head: [['#', 'Fecha', 'Cliente', 'Dirección', 'Artículo', 'Vendedor', 'Verificador']],
            body: data.map((r, i) => [i+1, r.date, r.client, r.address, r.item, r.seller, r.verifier]),
            startY: tableStartY, theme: 'grid', styles: { fontSize: 8 },
            headStyles: { fillColor: [99, 102, 241] },
            margin: { top: tableStartY }
        });
        window.open(doc.output('bloburl'), '_blank');
    }
</script>

<?php include 'includes/footer.php'; ?>