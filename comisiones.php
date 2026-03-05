<?php
require 'includes/db.php';
require_once 'includes/functions.php'; // Requerimiento de funciones globales

// SEGURIDAD: Solo Admin o Supervisor
if (!isset($_SESSION['role']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'supervisor')) { 
    header("Location: dashboard.php"); 
    exit; 
}

// --- 1. CONFIGURACIÓN DE FECHAS (Semana Vigente: Lunes a Sábado) ---
$defaultStart = date('Y-m-d', strtotime('monday this week'));
$defaultEnd = date('Y-m-d', strtotime('saturday this week'));

$start = $_GET['start'] ?? $defaultStart;
$end = $_GET['end'] ?? $defaultEnd;

$startSql = $start . ' 00:00:00';
$endSql = $end . ' 23:59:59';

// Preparamos filtros para que la paginación los mantenga en la URL
$filtros = ['start' => $start, 'end' => $end];

// --- 2. OBTENER TOTALES GENERALES DEL PERIODO (Para las tarjetas de resumen) ---
// Consultamos todas las ventas entregadas en el rango para calcular los montos totales del periodo
$sqlTotals = "SELECT SUM(total_amount) as total_ventas, SUM(total_amount * 0.05) as total_comisiones, COUNT(*) as total_registros 
              FROM sales WHERE status = 'entregado' AND delivered_at BETWEEN ? AND ?";
$stmtT = $pdo->prepare($sqlTotals);
$stmtT->execute([$startSql, $endSql]);
$summary = $stmtT->fetch();

$totalVentas = $summary['total_ventas'] ?? 0;
$totalComisiones = $summary['total_comisiones'] ?? 0;
$total_registros = $summary['total_registros'] ?? 0;

// --- 3. LÓGICA DE PAGINACIÓN ---
$registros_por_pagina = 25;
$pagina_actual = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($pagina_actual < 1) $pagina_actual = 1;
$offset = ($pagina_actual - 1) * $registros_por_pagina;

// --- 4. OBTENER DATOS PAGINADOS PARA LA TABLA ---
$sqlTable = "SELECT sales.*, users.name as seller_name, (sales.total_amount * 0.05) as commission 
             FROM sales JOIN users ON sales.user_id = users.id 
             WHERE status = 'entregado' AND delivered_at BETWEEN ? AND ?
             ORDER BY delivered_at DESC
             LIMIT $offset, $registros_por_pagina";

$stmtTable = $pdo->prepare($sqlTable);
$stmtTable->execute([$startSql, $endSql]);
$displaySales = $stmtTable->fetchAll(PDO::FETCH_ASSOC);

// --- 5. OBTENER TODO EL PERIODO PARA EL PDF ---
$sqlFull = "SELECT sales.*, users.name as seller_name, (sales.total_amount * 0.05) as commission 
            FROM sales JOIN users ON sales.user_id = users.id 
            WHERE status = 'entregado' AND delivered_at BETWEEN ? AND ?
            ORDER BY seller_name ASC, delivered_at DESC";
$stmtFull = $pdo->prepare($sqlFull);
$stmtFull->execute([$startSql, $endSql]);
$allSales = $stmtFull->fetchAll(PDO::FETCH_ASSOC);

// Preparar datos para JS (PDF)
$pdfData = array_map(function($s) {
    return [
        'date' => date('d/m/Y', strtotime($s['delivered_at'])),
        'seller' => $s['seller_name'],
        'client' => $s['client_name'],
        'item' => $s['item'],
        'installments' => $s['installments_count'],
        'amount_installment' => $s['installment_amount'],
        'total' => (float)$s['total_amount'],
        'commission' => (float)$s['commission']
    ];
}, $allSales);

include 'includes/header.php';
?>

<!-- Librerías PDF -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.29/jspdf.plugin.autotable.min.js"></script>

<main class="flex-1 max-w-7xl mx-auto w-full p-4 sm:p-6 lg:p-8 fade-in">
    
    <div class="flex flex-col md:flex-row md:items-center gap-4 mb-8 justify-between">
        <div class="flex items-center gap-4">
            <a href="dashboard.php" class="p-2 bg-slate-800 rounded-full text-slate-400 hover:text-white transition border border-slate-700">
                <i data-lucide="chevron-left"></i>
            </a>
            <div>
                <h1 class="text-2xl font-bold text-white">Reporte de Comisiones</h1>
                <p class="text-sm text-slate-500">Liquidación semanal por fecha de entrega.</p>
            </div>
        </div>
    </div>

    <!-- Filtros y Resumen -->
    <div class="flex flex-col xl:flex-row gap-6 mb-8">
        <!-- Formulario Filtro -->
        <div class="flex-1 bg-slate-900 p-5 rounded-2xl border border-slate-800 shadow-lg">
            <form class="flex flex-col sm:flex-row gap-4 items-end">
                <div class="w-full sm:w-auto flex-1">
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-1.5">Desde</label>
                    <input type="date" name="start" value="<?= $start ?>" class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-white focus:border-blue-500 outline-none">
                </div>
                <div class="w-full sm:w-auto flex-1">
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-1.5">Hasta</label>
                    <input type="date" name="end" value="<?= $end ?>" class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-white focus:border-blue-500 outline-none">
                </div>
                <button type="submit" class="bg-blue-600 hover:bg-blue-500 text-white px-6 py-2.5 rounded-lg font-bold shadow-lg transition flex items-center gap-2">
                    <i data-lucide="filter" class="w-4 h-4"></i> Filtrar
                </button>
                <button type="button" onclick="exportarPDFComplejo()" class="bg-rose-600 hover:bg-rose-500 text-white px-6 py-2.5 rounded-lg font-bold shadow-lg transition flex items-center gap-2">
                    <i data-lucide="file-chart-column" class="w-4 h-4"></i> Reporte Liquidación
                </button>
            </form>
        </div>

        <!-- Tarjetas Totales -->
        <div class="flex gap-4 flex-col sm:flex-row xl:w-auto">
            <div class="bg-slate-900 p-5 rounded-2xl border border-slate-800 shadow-lg flex-1 min-w-[200px]">
                <span class="text-slate-500 text-xs uppercase font-bold tracking-wider block mb-1">Total Ventas</span>
                <div class="text-2xl font-bold text-white">$<?= number_format($totalVentas, 0, ',', '.') ?></div>
            </div>
            <div class="bg-slate-900 p-5 rounded-2xl border border-emerald-500/20 shadow-lg flex-1 min-w-[200px]">
                <span class="text-emerald-500 text-xs uppercase font-bold tracking-wider block mb-1">Comisiones (5%)</span>
                <div class="text-3xl font-bold text-emerald-400">$<?= number_format($totalComisiones, 0, ',', '.') ?></div>
            </div>
        </div>
    </div>

    <!-- Tabla de Resultados -->
    <div class="bg-slate-900 rounded-2xl border border-slate-800 overflow-hidden shadow-xl flex flex-col min-h-[500px]">
        <div class="overflow-x-auto flex-1">
            <table class="w-full text-sm text-left">
                <thead class="bg-slate-950/50 text-slate-400 uppercase text-xs font-bold tracking-wider border-b border-slate-800">
                    <tr>
                        <th class="p-4 whitespace-nowrap">F. Entrega</th>
                        <th class="p-4">Vendedor</th>
                        <th class="p-4">Cliente</th>
                        <th class="p-4">Artículo</th>
                        <th class="p-4 text-right">Total Venta</th>
                        <th class="p-4 text-right text-emerald-400">Comisión</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800">
                    <?php if (empty($displaySales)): ?>
                        <tr><td colspan="6" class="p-10 text-center text-slate-500 italic">No hay registros entregados en este rango de fechas.</td></tr>
                    <?php else: ?>
                        <?php foreach ($displaySales as $s): ?>
                        <tr class="hover:bg-slate-800/30 transition">
                            <td class="p-4 text-slate-300 font-mono text-xs">
                                <?= date('d/m/Y', strtotime($s['delivered_at'])) ?>
                            </td>
                            <td class="p-4 font-bold text-slate-300">
                                <a href="perfil_vendedor.php?id=<?= $s['user_id'] ?>" class="hover:text-blue-400 hover:underline transition flex items-center gap-1 group">
                                    <?= htmlspecialchars($s['seller_name']) ?>
                                    <i data-lucide="external-link" class="w-3 h-3 opacity-0 group-hover:opacity-100 transition-opacity"></i>
                                </a>
                            </td>
                            <td class="p-4 text-slate-300"><?= htmlspecialchars($s['client_name']) ?></td>
                            <td class="p-4 text-blue-300"><?= htmlspecialchars($s['item']) ?></td>
                            <td class="p-4 text-right font-medium text-white">$<?= number_format($s['total_amount'], 0, ',', '.') ?></td>
                            <td class="p-4 text-right font-bold text-emerald-400">$<?= number_format($s['commission'], 0, ',', '.') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- PAGINACIÓN GLOBAL -->
        <?= renderPagination($total_registros, $registros_por_pagina, $pagina_actual, $filtros); ?>
    </div>

</main>

<script>
    const salesData = <?= json_encode($pdfData) ?>;
    const currency = new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS', minimumFractionDigits: 0 });

    function exportarPDFComplejo() {
        const { jsPDF } = window.jspdf;
        const doc = new jsPDF('p', 'mm', 'a4');
        const pageWidth = doc.internal.pageSize.getWidth();

        // 1. Cabecera
        doc.setFontSize(18); doc.setFont("helvetica", "bold");
        doc.text("Liquidación de Comisiones", pageWidth / 2, 15, { align: 'center' });
        doc.setFontSize(10); doc.setFont("helvetica", "normal");
        doc.text("Periodo: <?= date('d/m/Y', strtotime($start)) ?> al <?= date('d/m/Y', strtotime($end)) ?>", pageWidth / 2, 22, { align: 'center' });

        // 2. Tabla Detalle
        const detailBody = salesData.map(row => [
            row.date, row.seller, row.client, row.item, 
            row.installments + ' x ' + currency.format(row.amount_installment),
            currency.format(row.total), currency.format(row.commission)
        ]);

        doc.autoTable({
            head: [['Fecha Ent.', 'Vendedor', 'Cliente', 'Artículo', 'Plan', 'Total', 'Comisión']],
            body: detailBody,
            startY: 30,
            theme: 'grid',
            styles: { fontSize: 7, cellPadding: 2 },
            headStyles: { fillColor: [240, 240, 240], textColor: [0,0,0], fontStyle: 'bold' }
        });

        // 3. Resumen por Vendedor
        const vendorTotals = {};
        salesData.forEach(row => {
            if (!vendorTotals[row.seller]) vendorTotals[row.seller] = 0;
            vendorTotals[row.seller] += row.commission;
        });
        const summaryBody = Object.keys(vendorTotals).map(v => [v, currency.format(vendorTotals[v])]);

        doc.autoTable({
            head: [['Vendedor', 'Subtotal Comisión']],
            body: summaryBody,
            startY: doc.lastAutoTable.finalY + 10,
            theme: 'grid',
            tableWidth: 80,
            headStyles: { fillColor: [51, 65, 85], textColor: [255, 255, 255] }
        });

        window.open(doc.output('bloburl'), '_blank');
    }
</script>

<?php include 'includes/footer.php'; ?>