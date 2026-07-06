<?php
require 'includes/db.php';
require_once 'includes/functions.php';

// SEGURIDAD: Solo Admin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}

$role = $_SESSION['role'];

// --- Filtro de Período (por defecto: mes calendario anterior completo) ---
$defaultStart = date('Y-m-01', strtotime('first day of last month'));
$defaultEnd   = date('Y-m-d', strtotime('last day of last month'));

$start = $_GET['start'] ?? $defaultStart;
$end   = $_GET['end']   ?? $defaultEnd;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) $start = $defaultStart;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end))   $end   = $defaultEnd;
if ($start > $end) [$start, $end] = [$end, $start];
$startSql = $start . ' 00:00:00';
$endSql   = $end   . ' 23:59:59';

// Consulta para obtener todos los usuarios que han cargado al menos una venta,
// con estadísticas acotadas al período seleccionado
$sql = "SELECT u.id, u.name, u.username, u.role, u.phone,
               COUNT(s.id) as total_sales_all,
               SUM(CASE WHEN s.created_at BETWEEN ? AND ? THEN 1 ELSE 0 END) as total_sales_period,
               SUM(CASE WHEN s.created_at BETWEEN ? AND ?
                             AND s.status IN ('aprobado', 'entregado') THEN 1 ELSE 0 END) as approved_sales_period,
               SUM(CASE WHEN s.created_at BETWEEN ? AND ?
                             AND s.status = 'rechazado' THEN 1 ELSE 0 END) as rejected_sales_period,
               MAX(s.created_at) as last_sale_date
        FROM users u
        JOIN sales s ON u.id = s.user_id
        GROUP BY u.id
        ORDER BY total_sales_period DESC, total_sales_all DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute([$startSql, $endSql, $startSql, $endSql, $startSql, $endSql]);
$sellers = $stmt->fetchAll(PDO::FETCH_ASSOC);
$totalPeriodoTodos = array_sum(array_column($sellers, 'total_sales_period'));

// --- Desglose semanal (aprobadas y entregadas diferenciadas, una sola consulta para todos los vendedores) ---
$sqlWeekly = "SELECT s.user_id,
                     DATE_SUB(DATE(s.created_at), INTERVAL WEEKDAY(s.created_at) DAY) as week_monday,
                     SUM(CASE WHEN s.status = 'aprobado' THEN 1 ELSE 0 END) as aprobadas,
                     SUM(CASE WHEN s.status = 'entregado' THEN 1 ELSE 0 END) as entregadas,
                     COUNT(*) as total
              FROM sales s
              WHERE s.created_at BETWEEN ? AND ?
                AND s.status IN ('aprobado', 'entregado')
              GROUP BY s.user_id, week_monday
              ORDER BY s.user_id ASC, week_monday ASC";
$stmtWeekly = $pdo->prepare($sqlWeekly);
$stmtWeekly->execute([$startSql, $endSql]);
$weeklyRaw = $stmtWeekly->fetchAll(PDO::FETCH_ASSOC);

$weeklyByUser = [];
$weeklyByUserWeek = []; // [uid][monday] = ['aprobadas'=>x,'entregadas'=>y,'total'=>z] — para la tabla dinámica del PDF
foreach ($weeklyRaw as $row) {
    $uid    = (int)$row['user_id'];
    $monday = $row['week_monday'];
    $sunday = date('Y-m-d', strtotime($monday . ' +6 days'));
    $label  = date('d/m', strtotime($monday)) . ' - ' . date('d/m', strtotime($sunday));
    $stats  = [
        'aprobadas'  => (int)$row['aprobadas'],
        'entregadas' => (int)$row['entregadas'],
        'total'      => (int)$row['total'],
    ];
    $weeklyByUser[$uid][] = ['label' => $label] + $stats;
    $weeklyByUserWeek[$uid][$monday] = $stats;
}

// Todas las semanas del período (lunes a lunes), para las columnas fijas de la tabla dinámica del PDF
$allWeeks = [];
$weekCursor = strtotime($start);
$weekCursor = strtotime(date('Y-m-d', $weekCursor) . ' -' . (date('N', $weekCursor) - 1) . ' days');
$endTs = strtotime($end);
while ($weekCursor <= $endTs) {
    $mondayKey = date('Y-m-d', $weekCursor);
    $sundayTs  = strtotime($mondayKey . ' +6 days');
    $allWeeks[] = ['key' => $mondayKey, 'label' => date('d/m', $weekCursor) . '-' . date('d/m', $sundayTs)];
    $weekCursor = strtotime($mondayKey . ' +7 days');
}

// Datos para el PDF general: solo vendedores con al menos una venta aprobada/entregada en el período
$sellersForPdf = [];
foreach ($sellers as $s) {
    if (isset($weeklyByUser[(int)$s['id']])) {
        $sellersForPdf[] = ['id' => (int)$s['id'], 'name' => $s['name']];
    }
}

include 'includes/header.php';
?>

<!-- Librería PDF -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.29/jspdf.plugin.autotable.min.js"></script>

<main class="flex-1 max-w-7xl mx-auto w-full p-4 sm:p-6 lg:p-8 fade-in">
    
    <!-- Título y Regresar -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-8">
        <div class="flex items-center gap-4">
            <a href="dashboard.php" class="p-2 rounded-full transition" style="background:var(--paper);border:1.5px solid var(--line);color:var(--ink-3);" onmouseover="this.style.background='var(--accent-soft)';this.style.color='var(--accent-ink)'" onmouseout="this.style.background='var(--paper)';this.style.color='var(--ink-3)'">
                <i data-lucide="chevron-left"></i>
            </a>
            <div>
                <h1 class="text-2xl font-bold tracking-tight" style="color:var(--ink);">Listado de Vendedores</h1>
                <p class="text-sm text-slate-500">Usuarios que han registrado ventas en el sistema.</p>
            </div>
        </div>
        <button onclick="exportarPDFSemanal()" class="flex items-center justify-center gap-2 text-sm font-bold px-4 py-2 rounded-xl transition shrink-0" style="background:var(--rec-bg);color:var(--rec-ink);border:1.5px solid rgba(159,18,57,.2);" onmouseover="this.style.opacity='.85'" onmouseout="this.style.opacity='1'">
            <i data-lucide="file-down" class="w-4 h-4"></i> PDF Desglose Semanal
        </button>
    </div>

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
                <a href="lista_vendedores.php" class="px-4 py-2 rounded-xl font-bold text-sm transition flex items-center gap-1" style="background:var(--paper);border:1.5px solid var(--line);color:var(--ink-2);" title="Resetear al mes anterior">
                    <i data-lucide="rotate-ccw" class="w-4 h-4"></i>
                </a>
            </div>
        </form>
        <div class="mt-3 pt-3 flex flex-wrap gap-4 text-xs" style="border-top:1px dashed var(--line);color:var(--ink-3);">
            <span class="flex items-center gap-1.5"><i data-lucide="calendar" class="w-3 h-3"></i> <?= date('d/m/Y', strtotime($start)) ?> — <?= date('d/m/Y', strtotime($end)) ?></span>
            <span class="flex items-center gap-1.5"><i data-lucide="bar-chart-2" class="w-3 h-3"></i> <span class="font-bold" style="color:var(--ink);"><?= $totalPeriodoTodos ?></span> ventas en el período (todos los vendedores)</span>
        </div>
    </div>

    <!-- Tabla de Vendedores -->
    <div class="rounded-2xl overflow-hidden flex flex-col min-h-[500px]" style="background:var(--card);border:1.5px solid var(--line);box-shadow:var(--shadow-card);">
        <div class="overflow-x-auto flex-1">
            <table class="w-full text-left text-sm">
                <thead class="uppercase text-[10px] font-bold tracking-widest" style="background:#f8f7fc;color:var(--ink-3);border-bottom:1.5px solid var(--line);">
                    <tr>
                        <th class="p-5 pl-6">Vendedor</th>
                        <th class="p-5 text-center">Rol</th>
                        <th class="p-5 text-center">Ventas (Período)</th>
                        <th class="p-5 text-center text-green-500">Aprobadas</th>
                        <th class="p-5 text-center text-red-500">Rechazadas</th>
                        <th class="p-5 text-center">Efectividad</th>
                        <th class="p-5">Última Carga</th>
                        <th class="p-5 text-right">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/50">
                    <?php if (empty($sellers)): ?>
                        <tr><td colspan="8" class="p-12 text-center text-slate-500 italic flex flex-col items-center justify-center w-full col-span-8"><i data-lucide="users-2" class="w-10 h-10 mb-2 opacity-50"></i>No se encontraron vendedores con ventas registradas.</td></tr>
                    <?php else: ?>
                        <?php foreach ($sellers as $s): ?>
                        <tr class="transition" style="border-bottom:1px dashed var(--line);" onmouseover="this.style.background='rgba(99,102,241,.04)'" onmouseout="this.style.background=''">
                            <td class="p-5 pl-6">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-full bg-blue-600/20 text-blue-400 flex items-center justify-center font-bold border border-blue-500/20">
                                        <?= strtoupper(substr($s['name'], 0, 1)) ?>
                                    </div>
                                    <div>
                                        <div class="font-bold" style="color:var(--ink);"><?= htmlspecialchars($s['name']) ?></div>
                                        <div class="text-[10px] text-slate-500 font-mono uppercase tracking-tighter">@<?= htmlspecialchars($s['username']) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td class="p-5 text-center">
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wide border" style="background:var(--paper);color:var(--ink-2);border-color:var(--line);">
                                    <?= ucfirst($s['role']) ?>
                                </span>
                            </td>
                            <td class="p-5 text-center font-bold" style="color:var(--ink);">
                                <?= $s['total_sales_period'] ?>
                                <div class="text-[10px] font-normal mt-0.5" style="color:var(--ink-3);">de <?= $s['total_sales_all'] ?> históricas</div>
                            </td>
                            <td class="p-5 text-center font-bold text-green-400">
                                <?= $s['approved_sales_period'] ?>
                            </td>
                            <td class="p-5 text-center font-bold text-red-400">
                                <?= $s['rejected_sales_period'] ?>
                            </td>
                            <td class="p-5 text-center">
                                <?php
                                $percentage = $s['total_sales_period'] > 0 ? round(($s['approved_sales_period'] / $s['total_sales_period']) * 100) : 0;
                                $color = 'text-slate-400';
                                if ($percentage >= 80) $color = 'text-green-500';
                                elseif ($percentage >= 50) $color = 'text-yellow-500';
                                elseif ($percentage > 0) $color = 'text-red-500';
                                ?>
                                <div class="<?= $color ?> font-bold text-sm"><?= $percentage ?>%</div>
                                <div class="w-16 h-1 rounded-full mx-auto mt-1 overflow-hidden" style="background:var(--line);">
                                    <div class="h-full <?= str_replace('text', 'bg', $color) ?>" style="width: <?= $percentage ?>%"></div>
                                </div>
                            </td>
                            <td class="p-5 text-slate-400 font-mono text-xs">
                                <?= date('d/m/Y H:i', strtotime($s['last_sale_date'])) ?>
                            </td>
                            <td class="p-5 text-right">
                                <div class="flex justify-end gap-2">
                                    <button type="button" onclick="openWeekModal(<?= $s['id'] ?>, '<?= addslashes(htmlspecialchars($s['name'])) ?>')" class="inline-flex items-center gap-2 px-3 py-2 rounded-lg transition font-bold text-xs" style="background:var(--accent-soft);color:var(--accent-ink);border:1.5px solid rgba(99,102,241,.2);" title="Ver desglose semanal" onmouseover="this.style.background='var(--accent)';this.style.color='#fff'" onmouseout="this.style.background='var(--accent-soft)';this.style.color='var(--accent-ink)'">
                                        <i data-lucide="calendar-days" class="w-4 h-4"></i>
                                    </button>
                                    <a href="ventas_vendedor.php?id=<?= $s['id'] ?>" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-blue-600 hover:bg-blue-500 text-white transition font-bold text-xs shadow-lg shadow-blue-900/20">
                                        <i data-lucide="list" class="w-4 h-4"></i> Ver todas las ventas
                                    </a>
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

<!-- Modal: Desglose Semanal -->
<div id="weekModal" class="fixed inset-0 backdrop-blur-xl hidden z-50 overflow-y-auto" style="background:rgba(43,43,58,.55);">
    <div class="flex min-h-full items-start sm:items-center justify-center p-2 sm:p-4">
    <div class="rounded-2xl sm:rounded-3xl shadow-2xl w-full max-w-lg p-4 sm:p-8 my-2 sm:my-6 transform transition-all" style="background:var(--card);border:1.5px solid var(--line);box-shadow:var(--shadow-card);">
        <div class="flex justify-between items-center mb-6 pb-4" style="border-bottom:1.5px solid var(--line);">
            <h3 class="text-lg font-bold flex items-center gap-3" style="color:var(--ink);">
                <i data-lucide="calendar-days" class="w-5 h-5" style="color:var(--accent);"></i>
                Desglose Semanal · <span id="weekModalSellerName"></span>
            </h3>
            <button onclick="closeWeekModal()" class="transition w-10 h-10 flex items-center justify-center rounded-full shrink-0" style="color:var(--ink-2);background:var(--paper);border:1.5px solid var(--line);"><i data-lucide="x" class="w-5 h-5"></i></button>
        </div>
        <p class="text-[10px] uppercase font-bold tracking-widest mb-3" style="color:var(--ink-3);">Solo ventas aprobadas + entregadas</p>
        <table class="w-full text-left text-sm">
            <thead class="uppercase text-[10px] font-bold tracking-widest" style="color:var(--ink-3);border-bottom:1.5px solid var(--line);">
                <tr>
                    <th class="p-3">Semana</th>
                    <th class="p-3 text-center text-green-500">Aprobadas</th>
                    <th class="p-3 text-center text-blue-500">Entregadas</th>
                    <th class="p-3 text-center">Total</th>
                </tr>
            </thead>
            <tbody id="weekModalBody"></tbody>
        </table>
    </div></div>
</div>

<script>
    const weeklyData = <?= json_encode($weeklyByUser) ?>;

    function openWeekModal(userId, userName) {
        document.getElementById('weekModalSellerName').textContent = userName;
        const weeks = weeklyData[userId] || [];
        const tbody = document.getElementById('weekModalBody');
        tbody.innerHTML = weeks.length
            ? weeks.map(w => `<tr style="border-bottom:1px dashed var(--line);"><td class="p-3 font-mono text-xs" style="color:var(--ink-2);">${w.label}</td><td class="p-3 text-center font-bold text-green-500">${w.aprobadas}</td><td class="p-3 text-center font-bold text-blue-500">${w.entregadas}</td><td class="p-3 text-center font-bold" style="color:var(--ink);">${w.total}</td></tr>`).join('')
            : '<tr><td colspan="4" class="p-6 text-center italic" style="color:var(--ink-3);">Sin ventas aprobadas/entregadas en este período.</td></tr>';
        document.getElementById('weekModal').classList.remove('hidden');
    }

    function closeWeekModal() {
        document.getElementById('weekModal').classList.add('hidden');
    }

    // --- Exportación PDF: tabla dinámica horizontal (una fila por vendedor, una columna por semana) ---
    const sellersForPdf = <?= json_encode($sellersForPdf) ?>;
    const allWeeks = <?= json_encode($allWeeks) ?>;
    const weeklyByUserWeek = <?= json_encode($weeklyByUserWeek) ?>;
    const periodoLabel = "<?= date('d/m/Y', strtotime($start)) ?> — <?= date('d/m/Y', strtotime($end)) ?>";

    function exportarPDFSemanal() {
        const { jsPDF } = window.jspdf;
        const doc = new jsPDF('p', 'mm', 'a4'); // vertical
        const pageWidth = doc.internal.pageSize.getWidth();

        let y = 12;
        doc.setFontSize(14); doc.setFont("helvetica", "bold");
        doc.text("DESGLOSE SEMANAL DE VENTAS POR VENDEDOR", pageWidth / 2, y, { align: 'center' });
        y += 5;
        doc.setFontSize(9); doc.setFont("helvetica", "normal");
        doc.text(`Período: ${periodoLabel}  ·  Celda = cantidad de ventas de esa semana  ·  "-" = sin ventas esa semana`, pageWidth / 2, y, { align: 'center' });
        y += 6;

        if (sellersForPdf.length === 0) {
            doc.setFontSize(11);
            doc.text("No hay ventas aprobadas/entregadas registradas en este período.", pageWidth / 2, y + 8, { align: 'center' });
            window.open(doc.output('bloburl'), '_blank');
            return;
        }

        const head = [['Vendedor', ...allWeeks.map(w => w.label), 'Aprob.', 'Entreg.', 'Total']];
        const weekTotals = allWeeks.map(() => ({ a: 0, e: 0 }));
        let grandAprobadas = 0, grandEntregadas = 0, grandTotal = 0;

        const body = sellersForPdf.map(s => {
            const byWeek = weeklyByUserWeek[s.id] || {};
            let subA = 0, subE = 0;
            const weekCells = allWeeks.map((w, i) => {
                const d = byWeek[w.key];
                if (!d || d.total === 0) return '-';
                subA += d.aprobadas;
                subE += d.entregadas;
                weekTotals[i].a += d.aprobadas;
                weekTotals[i].e += d.entregadas;
                return String(d.total);
            });
            grandAprobadas += subA;
            grandEntregadas += subE;
            grandTotal += (subA + subE);
            return [s.name, ...weekCells, String(subA), String(subE), String(subA + subE)];
        });

        body.push([
            'TOTAL GENERAL',
            ...weekTotals.map(t => (t.a + t.e) > 0 ? String(t.a + t.e) : '-'),
            String(grandAprobadas), String(grandEntregadas), String(grandTotal)
        ]);
        const totalRowIndex = body.length - 1;
        const totalCols = 1 + allWeeks.length + 3;

        doc.autoTable({
            head: head,
            body: body,
            startY: y,
            theme: 'grid',
            styles: { fontSize: totalCols > 10 ? 6 : 7, cellPadding: 1.2, halign: 'center', valign: 'middle' },
            headStyles: { fillColor: [99, 102, 241], fontSize: totalCols > 10 ? 6 : 7 },
            columnStyles: { 0: { halign: 'left', fontStyle: 'bold', cellWidth: 32 } },
            margin: { left: 8, right: 8 },
            didParseCell: function (data) {
                if (data.section === 'body' && data.row.index === totalRowIndex) {
                    data.cell.styles.fontStyle = 'bold';
                    data.cell.styles.fillColor = [240, 240, 250];
                }
            }
        });

        window.open(doc.output('bloburl'), '_blank');
    }
</script>

<?php include 'includes/footer.php'; ?>
