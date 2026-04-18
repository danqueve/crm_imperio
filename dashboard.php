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
    <div id="toast-success" class="fixed bottom-6 right-6 z-50 flex items-center gap-4 bg-slate-900 border border-emerald-500/30 text-white rounded-2xl shadow-2xl shadow-black/50 px-5 py-4" style="animation: slideInToast 0.4s cubic-bezier(0.34,1.56,0.64,1) both;">
        <div class="bg-emerald-500/15 p-2.5 rounded-xl shrink-0 border border-emerald-500/20">
            <i data-lucide="check-circle" class="w-5 h-5 text-emerald-400"></i>
        </div>
        <div>
            <p class="font-bold text-sm">¡Venta registrada!</p>
            <p class="text-xs text-slate-400 mt-0.5">Guardada correctamente en el sistema.</p>
        </div>
        <button onclick="dismissToast()" class="ml-1 p-1.5 text-slate-500 hover:text-white transition rounded-lg hover:bg-slate-800 shrink-0">
            <i data-lucide="x" class="w-4 h-4"></i>
        </button>
    </div>
    <style>@keyframes slideInToast { from { transform: translateX(110%) scale(0.9); opacity: 0; } to { transform: translateX(0) scale(1); opacity: 1; } }</style>
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
            <h2 class="text-3xl font-bold text-white tracking-tight uppercase">Panel de Control</h2>
            <p class="text-slate-400 text-sm mt-1 tracking-wide">Bienvenido, <span class="text-blue-400 font-bold"><?= htmlspecialchars($name) ?></span>.</p>
        </div>
        <div class="flex flex-wrap gap-3">
            <button onclick="exportarPDF()" class="bg-rose-600 hover:bg-rose-500 text-white px-4 py-2 rounded-lg transition flex items-center gap-2 text-sm font-bold shadow-lg shadow-rose-900/30">
                <i data-lucide="file-down" class="w-4 h-4"></i> PDF PENDIENTES
            </button>
            <?php if ($can_manage || $is_entregador): ?>
            <a href="entregas.php" class="bg-emerald-600 hover:bg-emerald-500 text-white px-4 py-2 rounded-lg transition flex items-center gap-2 text-sm font-bold shadow-lg shadow-emerald-900/30">
                <i data-lucide="truck" class="w-4 h-4"></i> GESTIÓN ENTREGAS
            </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($is_admin): ?>
    <!-- Filtro de Período -->
    <div class="bg-slate-900 border border-slate-800 rounded-2xl p-4 mb-8 shadow-lg">
        <form method="GET" class="flex flex-col sm:flex-row gap-3 items-end">
            <div class="flex items-center gap-2 text-slate-400 shrink-0 mb-1 sm:mb-0">
                <i data-lucide="calendar-range" class="w-4 h-4 text-blue-400"></i>
                <span class="text-[10px] font-bold uppercase tracking-widest text-slate-500">Período de análisis</span>
            </div>
            <div class="flex gap-3 flex-1 flex-wrap">
                <div class="flex-1 min-w-[140px]">
                    <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1 ml-1 tracking-widest">Desde</label>
                    <input type="date" name="start" value="<?= $start ?>" class="w-full bg-slate-950 border border-slate-700 rounded-xl px-3 py-2 text-white focus:border-blue-500 outline-none transition text-sm">
                </div>
                <div class="flex-1 min-w-[140px]">
                    <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1 ml-1 tracking-widest">Hasta</label>
                    <input type="date" name="end" value="<?= $end ?>" class="w-full bg-slate-950 border border-slate-700 rounded-xl px-3 py-2 text-white focus:border-blue-500 outline-none transition text-sm">
                </div>
            </div>
            <div class="flex gap-2 shrink-0">
                <button type="submit" class="bg-blue-600 hover:bg-blue-500 text-white px-5 py-2 rounded-xl font-bold text-sm transition flex items-center gap-2 shadow-lg">
                    <i data-lucide="filter" class="w-4 h-4"></i> Aplicar
                </button>
                <a href="dashboard.php" class="bg-slate-800 hover:bg-slate-700 text-slate-300 px-4 py-2 rounded-xl font-bold text-sm transition border border-slate-700 flex items-center gap-1" title="Resetear al mes actual">
                    <i data-lucide="rotate-ccw" class="w-4 h-4"></i>
                </a>
            </div>
        </form>
        <?php if ($total_periodo > 0 || $start !== date('Y-m-01') || $end !== date('Y-m-d')): ?>
        <div class="mt-3 pt-3 border-t border-slate-800/70 flex flex-wrap gap-4 text-xs text-slate-500">
            <span class="flex items-center gap-1.5"><i data-lucide="calendar" class="w-3 h-3"></i> <?= date('d/m/Y', strtotime($start)) ?> — <?= date('d/m/Y', strtotime($end)) ?></span>
            <span class="flex items-center gap-1.5"><i data-lucide="bar-chart-2" class="w-3 h-3"></i> <span class="text-white font-bold"><?= $total_periodo ?></span> ventas en el período</span>
            <span class="flex items-center gap-1.5 text-emerald-400"><i data-lucide="dollar-sign" class="w-3 h-3"></i> $<?= number_format($statsMonto['entregado'], 0, ',', '.') ?> entregado</span>
            <?php if ($efectividad > 0): ?>
            <span class="flex items-center gap-1.5 text-blue-400"><i data-lucide="target" class="w-3 h-3"></i> <?= $efectividad ?>% efectividad</span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- 2. RESUMEN NUMÉRICO (TARJETAS) — filtradas por período -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        <div class="p-5 rounded-2xl border border-yellow-500/20 bg-slate-900/50 flex items-center justify-between transition-all duration-300 hover:shadow-xl hover:shadow-yellow-500/10 hover:border-yellow-500/40 hover:-translate-y-0.5 cursor-default group">
            <div>
                <p class="text-[10px] uppercase font-bold text-yellow-500 tracking-widest mb-1">En Revisión</p>
                <p class="text-3xl font-bold text-white stat-counter" data-target="<?= $stats['revision'] ?>">0</p>
                <?php if ($statsMonto['revision'] > 0): ?>
                <p class="text-[10px] text-slate-500 mt-1 font-mono">$<?= number_format($statsMonto['revision'], 0, ',', '.') ?></p>
                <?php endif; ?>
            </div>
            <div class="p-2.5 rounded-xl bg-yellow-500/10 text-yellow-400 group-hover:bg-yellow-500/20 transition-colors"><i data-lucide="clock" class="w-5 h-5"></i></div>
        </div>
        <div class="p-5 rounded-2xl border border-emerald-500/20 bg-slate-900/50 flex items-center justify-between transition-all duration-300 hover:shadow-xl hover:shadow-emerald-500/10 hover:border-emerald-500/40 hover:-translate-y-0.5 cursor-default group">
            <div>
                <p class="text-[10px] uppercase font-bold text-emerald-500 tracking-widest mb-1">Aprobadas</p>
                <p class="text-3xl font-bold text-white stat-counter" data-target="<?= $stats['aprobado'] ?>">0</p>
                <?php if ($statsMonto['aprobado'] > 0): ?>
                <p class="text-[10px] text-slate-500 mt-1 font-mono">$<?= number_format($statsMonto['aprobado'], 0, ',', '.') ?></p>
                <?php endif; ?>
            </div>
            <div class="p-2.5 rounded-xl bg-emerald-500/10 text-emerald-400 group-hover:bg-emerald-500/20 transition-colors"><i data-lucide="check" class="w-5 h-5"></i></div>
        </div>
        <div class="p-5 rounded-2xl border border-blue-500/20 bg-slate-900/50 flex items-center justify-between transition-all duration-300 hover:shadow-xl hover:shadow-blue-500/10 hover:border-blue-500/40 hover:-translate-y-0.5 cursor-default group">
            <div>
                <p class="text-[10px] uppercase font-bold text-blue-500 tracking-widest mb-1">Entregadas</p>
                <p class="text-3xl font-bold text-white stat-counter" data-target="<?= $stats['entregado'] ?>">0</p>
                <?php if ($statsMonto['entregado'] > 0): ?>
                <p class="text-[10px] text-emerald-400/70 mt-1 font-mono font-bold">$<?= number_format($statsMonto['entregado'], 0, ',', '.') ?></p>
                <?php endif; ?>
            </div>
            <div class="p-2.5 rounded-xl bg-blue-500/10 text-blue-400 group-hover:bg-blue-500/20 transition-colors"><i data-lucide="truck" class="w-5 h-5"></i></div>
        </div>
        <div class="p-5 rounded-2xl border border-red-500/20 bg-slate-900/50 flex items-center justify-between transition-all duration-300 hover:shadow-xl hover:shadow-red-500/10 hover:border-red-500/40 hover:-translate-y-0.5 cursor-default group">
            <div>
                <p class="text-[10px] uppercase font-bold text-red-500 tracking-widest mb-1">Rechazadas</p>
                <p class="text-3xl font-bold text-white stat-counter" data-target="<?= $stats['rechazado'] ?>">0</p>
                <?php if ($efectividad > 0): ?>
                <p class="text-[10px] text-slate-500 mt-1">efectiv. <span class="text-blue-400 font-bold"><?= $efectividad ?>%</span></p>
                <?php endif; ?>
            </div>
            <div class="p-2.5 rounded-xl bg-red-500/10 text-red-400 group-hover:bg-red-500/20 transition-colors"><i data-lucide="x-circle" class="w-5 h-5"></i></div>
        </div>
    </div>

    <!-- 3. GRÁFICOS ESTADÍSTICOS -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        <div class="lg:col-span-2 bg-slate-900 p-6 rounded-2xl border border-slate-800 shadow-xl">
            <div class="flex items-center justify-between mb-6">
                <h3 class="text-white font-bold flex items-center gap-2 uppercase text-xs tracking-widest">
                    <i data-lucide="trending-up" class="w-4 h-4 text-blue-400"></i> Desempeño mensual
                </h3>
                <span class="text-[10px] text-slate-500 font-mono bg-slate-800 px-2 py-1 rounded border border-slate-700">
                    <?= date('d/m/Y', strtotime($start)) ?> – <?= date('d/m/Y', strtotime($end)) ?>
                </span>
            </div>
            <div class="h-[250px] w-full"><canvas id="monthlyChart"></canvas></div>
        </div>

        <div class="bg-slate-900 p-6 rounded-2xl border border-slate-800 shadow-xl">
            <div class="flex items-center justify-between mb-6">
                <h3 class="text-white font-bold flex items-center gap-2 uppercase text-xs tracking-widest">
                    <i data-lucide="pie-chart" class="w-4 h-4 text-purple-400"></i> Distribución
                </h3>
                <?php if ($total_periodo > 0): ?>
                <span class="text-[10px] text-slate-500 font-mono bg-slate-800 px-2 py-1 rounded border border-slate-700"><?= $total_periodo ?> total</span>
                <?php endif; ?>
            </div>
            <div class="h-[220px] w-full flex items-center justify-center"><canvas id="statusChart"></canvas></div>
        </div>
    </div>
    <?php endif; // fin bloque admin ?>

    <!-- 1. BANDEJA DE ENTRADA (TABLA) -->
    <div class="bg-slate-900 rounded-2xl border border-slate-800 shadow-2xl overflow-hidden <?= $is_admin ? 'mt-8' : '' ?>">
        <div class="p-6 border-b border-slate-800 flex justify-between items-center bg-slate-950/30">
            <h3 class="font-bold text-lg text-white flex items-center gap-2 uppercase tracking-wider">
                <i data-lucide="inbox" class="w-5 h-5 text-blue-500"></i>
                <?= $is_limited_view ? "Mis Ventas Activas" : "Bandeja de Entrada (Revisión)" ?>
            </h3>
            <span class="text-[10px] font-bold text-slate-500 uppercase bg-slate-950 px-3 py-1 rounded border border-slate-800 tracking-widest">
                <?= count($orders) ?> REGISTROS
            </span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-slate-950/50 text-slate-500 uppercase text-[10px] font-bold tracking-widest border-b border-slate-800">
                    <tr>
                        <th class="p-5 pl-6 hidden sm:table-cell">#</th>
                        <th class="p-5 hidden sm:table-cell">Fecha</th>
                        <th class="p-5">Cliente</th>
                        <th class="p-5 hidden lg:table-cell">Cargado por</th>
                        <th class="p-5">Artículo / Total</th>
                        <th class="p-5 hidden sm:table-cell">Estado</th>
                        <th class="p-5 text-right">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/50">
                    <?php if (empty($orders)): ?>
                        <tr><td colspan="7" class="p-16 text-center">
                            <div class="flex flex-col items-center gap-4">
                                <div class="p-5 bg-slate-800/60 rounded-2xl border border-slate-700/50 shadow-inner">
                                    <i data-lucide="inbox" class="w-10 h-10 text-slate-600"></i>
                                </div>
                                <div>
                                    <p class="text-slate-400 font-semibold text-base">Todo al día</p>
                                    <p class="text-slate-600 text-sm mt-1">No hay ventas pendientes de procesar en este momento.</p>
                                </div>
                            </div>
                        </td></tr>
                    <?php else: ?>
                        <?php $counter = 1; foreach ($orders as $order): ?>
                        <tr class="hover:bg-slate-800/30 transition-colors border-l-4 <?= $order['status'] === 'revision' ? 'border-yellow-500/60' : 'border-emerald-500/60' ?>">
                            <td class="p-5 pl-6 font-bold text-slate-600 hidden sm:table-cell"><?= $counter++ ?></td>
                            <td class="p-5 text-slate-300 font-mono text-xs hidden sm:table-cell"><?= date('d/m/Y', strtotime($order['created_at'])) ?></td>
                            <td class="p-3 sm:p-5">
                                <div class="font-bold text-white"><?= htmlspecialchars($order['client_name']) ?></div>
                                <div class="text-[10px] text-emerald-400 font-mono tracking-tighter flex items-center gap-1 mt-0.5">
                                    <i data-lucide="phone" class="w-2.5 h-2.5"></i> <?= htmlspecialchars($order['client_whatsapp']) ?>
                                </div>
                                <div class="text-[10px] text-slate-500 font-mono mt-0.5 sm:hidden"><?= date('d/m/Y', strtotime($order['created_at'])) ?></div>
                            </td>
                            <td class="p-5 text-slate-400 hidden lg:table-cell">
                                <div class="flex items-center gap-1.5">
                                    <i data-lucide="user-edit" class="w-3.5 h-3.5 text-slate-500"></i>
                                    <a href="perfil_vendedor.php?id=<?= $order['user_id'] ?>" class="hover:text-blue-400 hover:underline transition flex items-center gap-1 group/seller">
                                        <?= isset($order['seller_name']) ? htmlspecialchars($order['seller_name']) : 'Yo' ?>
                                        <i data-lucide="external-link" class="w-2.5 h-2.5 opacity-0 group-hover/seller:opacity-100 transition-opacity"></i>
                                    </a>
                                </div>
                            </td>
                            <td class="p-3 sm:p-5">
                                <div class="text-slate-200 font-medium"><?= htmlspecialchars($order['item']) ?></div>
                                <div class="text-xs font-bold text-emerald-500/80">$<?= number_format($order['total_amount'], 0, ',', '.') ?></div>
                                <div class="mt-1 sm:hidden"><?= status_badge($order['status']) ?></div>
                            </td>
                            <td class="p-5 hidden sm:table-cell">
                                <?= status_badge($order['status']) ?>
                            </td>
                            <td class="p-3 sm:p-5 text-right">
                                <div class="flex justify-end gap-1.5 sm:gap-2 items-center">
                                    <a href="ver_ficha.php?id=<?= $order['id'] ?>" class="w-10 h-10 flex items-center justify-center rounded-lg bg-slate-800 hover:bg-blue-600 text-blue-400 hover:text-white transition border border-slate-700 shadow-sm" title="Ver Ficha">
                                        <i data-lucide="eye" class="w-4 h-4"></i>
                                    </a>

                                    <?php if ($can_manage && $order['status'] === 'revision'): ?>
                                        <div class="w-px h-4 bg-slate-700 mx-1"></div>
                                        <form method="POST" action="update_status.php" class="inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= $order['id'] ?>">
                                            <input type="hidden" name="status" value="aprobado">
                                            <button type="submit" class="w-10 h-10 flex items-center justify-center bg-emerald-500/10 text-emerald-400 rounded-lg hover:bg-emerald-600 hover:text-white transition border border-emerald-500/20" title="Aprobar Venta">
                                                <i data-lucide="check" class="w-4 h-4"></i>
                                            </button>
                                        </form>
                                        <a href="rechazar_venta.php?id=<?= $order['id'] ?>" class="w-10 h-10 flex items-center justify-center bg-red-500/10 text-red-400 rounded-lg hover:bg-red-600 hover:text-white transition border border-red-500/20" title="Cancelar/Rechazar Venta" onclick="return confirm('¿Confirma que desea rechazar esta venta?')">
                                            <i data-lucide="x" class="w-4 h-4"></i>
                                        </a>
                                    <?php endif; ?>

                                    <?php if ($is_vendedor && $order['status'] === 'revision'): ?>
                                        <div class="w-px h-4 bg-slate-700 mx-1"></div>
                                        <a href="rechazar_venta.php?id=<?= $order['id'] ?>" class="w-10 h-10 flex items-center justify-center bg-red-500/10 text-red-400 rounded-lg hover:bg-red-600 hover:text-white transition border border-red-500/20" title="Cancelar mi carga" onclick="return confirm('¿Confirma que desea cancelar y retirar esta venta enviada?')">
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
                    labels: { color: '#94a3b8', font: { size: 10 }, boxWidth: 12, padding: 12, usePointStyle: true }
                }
            },
            scales: {
                y: { beginAtZero: true, grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { color: '#64748b', precision: 0 } },
                x: { grid: { display: false }, ticks: { color: '#64748b' } }
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
            plugins: { legend: { position: 'bottom', labels: { color: '#94a3b8', font: { size: 10 } } } },
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
            headStyles: { fillColor: [51, 65, 85] }
        });
        window.open(doc.output('bloburl'), '_blank');
    }
</script>

<?php include 'includes/footer.php'; ?>