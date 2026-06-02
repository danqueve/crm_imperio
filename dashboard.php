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

    $stmtMonthly = $pdo->prepare(
        "SELECT DATE_FORMAT(created_at, '%b %y') as label,
                COUNT(*) as total,
                SUM(CASE WHEN status = 'entregado' THEN 1 ELSE 0 END) as entregadas
         FROM sales WHERE created_at BETWEEN ? AND ?
         GROUP BY DATE_FORMAT(created_at, '%Y-%m') ORDER BY MIN(created_at) ASC"
    );
    $stmtMonthly->execute([$startSql, $endSql]);
    $monthlyStats = $stmtMonthly->fetchAll(PDO::FETCH_ASSOC);

    $labelsMonths   = json_encode(array_column($monthlyStats, 'label'));
    $dataMonths     = json_encode(array_column($monthlyStats, 'total'));
    $dataEntregadas = json_encode(array_column($monthlyStats, 'entregadas'));
    $dataStatus     = json_encode(array_values($stats));

    $denominador = $stats['entregado'] + $stats['rechazado'];
    $efectividad = $denominador > 0 ? round($stats['entregado'] / $denominador * 100) : 0;
}

// --- 3. LISTADO DE TABLA (BANDEJA DE ENTRADA) ---
if ($is_limited_view) {
    $stmt = $pdo->prepare("SELECT * FROM sales WHERE user_id = ? AND status IN ('revision', 'aprobado') ORDER BY created_at DESC");
    $stmt->execute([$user_id]);
} else {
    // Gestores ven quién cargó la venta mediante el JOIN con users
    $stmt = $pdo->query("SELECT sales.*, users.name as seller_name FROM sales JOIN users ON sales.user_id = users.id WHERE status = 'revision' ORDER BY created_at DESC");
}
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// PREPARAR DATOS PDF
$pdfData = array_map(function($order) {
    return [
        'id' => $order['id'],
        'raw_date' => $order['created_at'],
        'date' => date('d/m/Y', strtotime($order['created_at'])),
        'client' => $order['client_name'],
        'address' => $order['client_address'],
        'phone' => $order['client_whatsapp'],
        'item' => $order['item'],
        'seller' => $order['seller_name'] ?? 'Yo'
    ];
}, $orders);

include 'includes/header.php';
?>

<!-- Librerías de Gráficos y PDF -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

    <!-- 3. GRÁFICOS ESTADÍSTICOS -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 p-6 rounded-2xl" style="background:var(--card);border:1.5px solid var(--line);box-shadow:var(--shadow-card);">
            <div class="flex items-center justify-between mb-6">
                <h3 class="font-bold flex items-center gap-2 uppercase text-xs tracking-widest" style="color:var(--ink);">
                    <i data-lucide="trending-up" class="w-4 h-4" style="color:var(--accent);"></i> Desempeño mensual
                </h3>
                <span class="text-[10px] font-mono px-2 py-1 rounded" style="color:var(--ink-3);background:var(--paper);border:1.5px solid var(--line);">
                    <?= date('d/m/Y', strtotime($start)) ?> – <?= date('d/m/Y', strtotime($end)) ?>
                </span>
            </div>
            <div class="h-[250px] w-full"><canvas id="monthlyChart"></canvas></div>
        </div>

        <div class="p-6 rounded-2xl" style="background:var(--card);border:1.5px solid var(--line);box-shadow:var(--shadow-card);">
            <div class="flex items-center justify-between mb-6">
                <h3 class="font-bold flex items-center gap-2 uppercase text-xs tracking-widest" style="color:var(--ink);">
                    <i data-lucide="pie-chart" class="w-4 h-4" style="color:var(--accent);"></i> Distribución
                </h3>
                <?php if ($total_periodo > 0): ?>
                <span class="text-[10px] font-mono px-2 py-1 rounded" style="color:var(--ink-3);background:var(--paper);border:1.5px solid var(--line);"><?= $total_periodo ?> total</span>
                <?php endif; ?>
            </div>
            <div class="h-[220px] w-full flex items-center justify-center"><canvas id="statusChart"></canvas></div>
        </div>
    </div>
    <?php endif; // fin bloque admin ?>

    <!-- 1. BANDEJA DE ENTRADA (TABLA) -->
    <div class="rounded-2xl overflow-hidden <?= $is_admin ? 'mt-8' : '' ?>" style="background:var(--card);border:1.5px solid var(--line);box-shadow:var(--shadow-card);">
        <div class="p-5 flex justify-between items-center" style="border-bottom:1.5px solid var(--line);background:#f8f7fc;">
            <h3 class="font-bold text-base flex items-center gap-2 uppercase tracking-wide" style="color:var(--ink);">
                <i data-lucide="inbox" class="w-5 h-5" style="color:var(--accent);"></i>
                <?= $is_limited_view ? "Mis Ventas Activas" : "Bandeja de Entrada · Revisión" ?>
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
                        <th class="hidden lg:table-cell">Cargado por</th>
                        <th>Artículo / Total</th>
                        <th class="hidden sm:table-cell">Estado</th>
                        <th style="text-align:right;">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($orders)): ?>
                        <tr><td colspan="7" class="p-16 text-center">
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
                            <td class="hidden sm:table-cell font-mono text-xs" style="color:var(--ink-2);"><?= date('d/m/Y', strtotime($order['created_at'])) ?></td>
                            <td class="p-3 sm:p-[13px]">
                                <div class="font-bold" style="color:var(--ink);"><?= htmlspecialchars($order['client_name']) ?></div>
                                <div class="text-[10px] font-mono flex items-center gap-1 mt-0.5" style="color:var(--apr-ink);">
                                    <i data-lucide="phone" class="w-2.5 h-2.5"></i> <?= htmlspecialchars($order['client_whatsapp']) ?>
                                </div>
                                <div class="text-[10px] font-mono mt-0.5 sm:hidden" style="color:var(--ink-3);"><?= date('d/m/Y', strtotime($order['created_at'])) ?></div>
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
</main>

<script>
    <?php if ($is_admin): ?>
    // Configuración Gráficos (solo admin)
    const ctxMonthly = document.getElementById('monthlyChart').getContext('2d');
    new Chart(ctxMonthly, {
        type: 'line',
        data: {
            labels: <?= $labelsMonths ?>,
            datasets: [
                {
                    label: 'Cargadas',
                    data: <?= $dataMonths ?>,
                    borderColor: '#3b82f6',
                    backgroundColor: 'rgba(59, 130, 246, 0.08)',
                    borderWidth: 2.5, tension: 0.4, fill: true, pointBackgroundColor: '#3b82f6', pointRadius: 4
                },
                {
                    label: 'Entregadas',
                    data: <?= $dataEntregadas ?>,
                    borderColor: '#10b981',
                    backgroundColor: 'rgba(16, 185, 129, 0.05)',
                    borderWidth: 2.5, tension: 0.4, fill: false, pointBackgroundColor: '#10b981', pointRadius: 4, borderDash: []
                }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    position: 'top',
                    align: 'end',
                    labels: { color: '#6b6b80', font: { size: 10 }, boxWidth: 12, padding: 12, usePointStyle: true }
                }
            },
            scales: {
                y: { beginAtZero: true, grid: { color: 'rgba(43,43,58,0.06)' }, ticks: { color: '#9a9aae', precision: 0 } },
                x: { grid: { display: false }, ticks: { color: '#9a9aae' } }
            }
        }
    });

    const ctxStatus = document.getElementById('statusChart').getContext('2d');
    new Chart(ctxStatus, {
        type: 'doughnut',
        data: {
            labels: ['Revisión', 'Aprobado', 'Entregado', 'Rechazado'],
            datasets: [{
                data: <?= $dataStatus ?>,
                backgroundColor: ['#eab308', '#10b981', '#3b82f6', '#ef4444'],
                borderWidth: 0, hoverOffset: 10
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom', labels: { color: '#6b6b80', font: { size: 10 } } } },
            cutout: '75%'
        }
    });

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

    // Lógica Exportación PDF
    const salesData = <?= json_encode($pdfData) ?>;
    function exportarPDF() {
        if (salesData.length === 0) { alert("No hay ventas para exportar."); return; }
        const { jsPDF } = window.jspdf;
        const doc = new jsPDF('p', 'mm', 'a4');
        const pageWidth = doc.internal.pageSize.getWidth();
        doc.setFontSize(16); doc.setFont("helvetica", "bold");
        doc.text("VENTAS PENDIENTES DE REVISIÓN", pageWidth / 2, 15, { align: 'center' });
        doc.autoTable({ 
            head: [['#', 'Fecha', 'Cliente', 'Dirección', 'Artículo', 'Vendedor']],
            body: salesData.map((r, i) => [i+1, r.date, r.client, r.address, r.item, r.seller]),
            startY: 25, theme: 'grid', styles: { fontSize: 8 },
            headStyles: { fillColor: [99, 102, 241] }
        });
        window.open(doc.output('bloburl'), '_blank');
    }
</script>

<?php include 'includes/footer.php'; ?>