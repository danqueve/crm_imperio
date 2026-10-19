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

// --- Navegación semana anterior/siguiente (mismo largo de rango que el actual) ---
$spanDays = (int)((strtotime($end) - strtotime($start)) / 86400) + 1;
$prevStart = date('Y-m-d', strtotime($start . " -$spanDays days"));
$prevEnd   = date('Y-m-d', strtotime($start . " -1 day"));
$nextStart = date('Y-m-d', strtotime($end . " +1 day"));
$nextEnd   = date('Y-m-d', strtotime($end . " +$spanDays days"));

// --- 2. OBTENER TOTALES GENERALES DEL PERIODO (Para las tarjetas de resumen) ---
// Comisión: tasa propia del vendedor, salvo ventas RapiCompra que siempre cobran RAPICOMPRA_COMMISSION_RATE
$sqlTotals = "SELECT SUM(s.total_amount) as total_ventas,
                     SUM(s.total_amount * (CASE WHEN s.payment_method = 'rapicompra' THEN " . RAPICOMPRA_COMMISSION_RATE . " ELSE u.commission_rate END / 100)) as total_comisiones,
                     COUNT(*) as total_registros
              FROM sales s
              JOIN users u ON s.user_id = u.id
              WHERE s.status = 'entregado' AND s.delivered_at BETWEEN ? AND ?";
$stmtT = $pdo->prepare($sqlTotals);
$stmtT->execute([$startSql, $endSql]);
$summary = $stmtT->fetch();

$totalVentas = $summary['total_ventas'] ?? 0;
$totalComisiones = $summary['total_comisiones'] ?? 0;
$total_registros = $summary['total_registros'] ?? 0;

// --- Resumen por vendedor (celular): mismo criterio de comisión, agrupado ---
$sqlPorVendedor = "SELECT u.id, u.name,
                           COUNT(*) as cnt,
                           SUM(s.total_amount) as total_venta,
                           SUM(s.total_amount * (CASE WHEN s.payment_method = 'rapicompra' THEN " . RAPICOMPRA_COMMISSION_RATE . " ELSE u.commission_rate END / 100)) as total_comision
                    FROM sales s JOIN users u ON s.user_id = u.id
                    WHERE s.status = 'entregado' AND s.delivered_at BETWEEN ? AND ?
                    GROUP BY u.id
                    ORDER BY total_comision DESC";
$stmtPorVendedor = $pdo->prepare($sqlPorVendedor);
$stmtPorVendedor->execute([$startSql, $endSql]);
$porVendedor = $stmtPorVendedor->fetchAll(PDO::FETCH_ASSOC);

// --- 3. LÓGICA DE PAGINACIÓN ---
$registros_por_pagina = 25;
$pagina_actual = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($pagina_actual < 1) $pagina_actual = 1;
$offset = ($pagina_actual - 1) * $registros_por_pagina;

// --- 4. OBTENER DATOS PAGINADOS PARA LA TABLA ---
$sqlTable = "SELECT sales.*, users.name as seller_name, users.commission_rate,
                    (sales.total_amount * (CASE WHEN sales.payment_method = 'rapicompra' THEN " . RAPICOMPRA_COMMISSION_RATE . " ELSE users.commission_rate END / 100)) as commission
             FROM sales JOIN users ON sales.user_id = users.id
             WHERE status = 'entregado' AND delivered_at BETWEEN :start AND :end
             ORDER BY delivered_at DESC
             LIMIT :limit OFFSET :offset";

$stmtTable = $pdo->prepare($sqlTable);
$stmtTable->bindValue(':start', $startSql);
$stmtTable->bindValue(':end', $endSql);
$stmtTable->bindValue(':limit', $registros_por_pagina, PDO::PARAM_INT);
$stmtTable->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmtTable->execute();
$displaySales = $stmtTable->fetchAll(PDO::FETCH_ASSOC);

// --- 5. OBTENER TODO EL PERIODO PARA EL PDF ---
$sqlFull = "SELECT sales.*, users.name as seller_name, users.commission_rate,
                   (sales.total_amount * (CASE WHEN sales.payment_method = 'rapicompra' THEN " . RAPICOMPRA_COMMISSION_RATE . " ELSE users.commission_rate END / 100)) as commission
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

<main class="app-main">
<div class="app-container">

    <div class="page-head">
        <div class="page-head__text">
            <a href="dashboard.php" class="navbar-back only-desktop"><?= ui_icon('chevron-left') ?> Panel</a>
            <h1 class="large-title">Reporte de comisiones</h1>
            <p class="subhead">Liquidación semanal por fecha de entrega.</p>
        </div>
    </div>

    <!-- Navegación de semana (celular) -->
    <div class="segmented only-mobile" style="grid-template-columns:auto 1fr auto">
        <a href="?<?= http_build_query(['start' => $prevStart, 'end' => $prevEnd]) ?>" class="segmented__item" aria-label="Período anterior"><?= ui_icon('chevron-left') ?></a>
        <span class="segmented__item tab" style="font-weight:600;cursor:default"><?= date('d/m', strtotime($start)) ?> – <?= date('d/m', strtotime($end)) ?></span>
        <a href="?<?= http_build_query(['start' => $nextStart, 'end' => $nextEnd]) ?>" class="segmented__item" aria-label="Período siguiente"><?= ui_icon('chevron-right') ?></a>
    </div>

    <!-- Filtros y Resumen -->
    <div class="row" style="align-items:stretch">
        <form class="card card--pad only-desktop" style="flex:2 1 400px;display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end">
            <div class="field" style="flex:1;min-width:140px">
                <label class="field__label">Desde</label>
                <input type="date" name="start" value="<?= $start ?>" class="input">
            </div>
            <div class="field" style="flex:1;min-width:140px">
                <label class="field__label">Hasta</label>
                <input type="date" name="end" value="<?= $end ?>" class="input">
            </div>
            <button type="submit" class="btn btn--primary"><?= ui_icon('filter') ?> Filtrar</button>
            <button type="button" onclick="exportarPDFComplejo()" class="btn btn--danger"><?= ui_icon('file-chart-column') ?> Reporte liquidación</button>
        </form>

        <div class="kpis" style="flex:1 1 320px">
            <article class="card kpi">
                <span class="eyebrow">Ventas entregadas</span>
                <span class="kpi__value tab"><?= ui_money($totalVentas) ?></span>
                <span class="kpi__sub tab"><?= $total_registros ?> operaciones</span>
            </article>
            <article class="card kpi" style="background:var(--tint-soft);border-color:var(--tint)">
                <span class="eyebrow" style="color:var(--tint-text)">A liquidar</span>
                <span class="kpi__value tab" style="color:var(--tint-text)"><?= ui_money($totalComisiones) ?></span>
                <span class="kpi__sub">Según comisión de cada vendedor</span>
            </article>
        </div>
    </div>

    <!-- Por vendedor (celular) -->
    <section class="only-mobile" style="display:flex;flex-direction:column;gap:8px">
        <h2 class="group__title">Por vendedor</h2>
        <?php if (empty($porVendedor)): ?>
        <div class="card"><div class="empty"><?= ui_icon('users') ?><p class="empty__title">Sin entregas en el período</p></div></div>
        <?php else: ?>
        <div class="group__body">
            <?php foreach ($porVendedor as $v): ?>
            <a href="perfil_vendedor.php?id=<?= $v['id'] ?>" class="cell">
                <?= ui_avatar($v['name']) ?>
                <span class="cell__body">
                    <span class="cell__title"><?= htmlspecialchars($v['name']) ?></span>
                    <span class="tab cell__sub"><?= (int)$v['cnt'] ?> entrega<?= $v['cnt'] == 1 ? '' : 's' ?> · <?= ui_money($v['total_venta']) ?></span>
                </span>
                <span class="tab" style="font-weight:700"><?= ui_money($v['total_comision']) ?></span>
                <span class="cell__chevron"><?= ui_icon('chevron-right') ?></span>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <button type="button" onclick="exportarPDFComplejo()" class="btn btn--block"><?= ui_icon('file-text') ?> Descargar liquidación en PDF</button>
    </section>

    <!-- Tabla de Resultados (PC) -->
    <section class="card only-desktop" style="overflow:hidden">
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>F. entrega</th><th>Vendedor</th><th>Cliente</th><th>Artículo</th><th class="num">Total venta</th><th class="num">Comisión</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($displaySales)): ?>
                        <tr><td colspan="6"><div class="empty"><?= ui_icon('receipt') ?><p class="empty__title">No hay registros entregados en este rango de fechas</p></div></td></tr>
                    <?php else: foreach ($displaySales as $s): ?>
                        <tr>
                            <td class="tab caption"><?= date('d/m/Y', strtotime($s['delivered_at'])) ?></td>
                            <td><a href="perfil_vendedor.php?id=<?= $s['user_id'] ?>"><?= htmlspecialchars($s['seller_name']) ?></a></td>
                            <td><?= htmlspecialchars($s['client_name']) ?></td>
                            <td class="subhead"><?= htmlspecialchars($s['item']) ?></td>
                            <td class="num tab"><?= ui_money($s['total_amount']) ?></td>
                            <td class="num tab" style="font-weight:700;color:var(--tint-text)"><?= ui_money($s['commission']) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </section>

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

        doc.setFontSize(18); doc.setFont("helvetica", "bold");
        doc.text("Liquidación de Comisiones", pageWidth / 2, 15, { align: 'center' });
        doc.setFontSize(10); doc.setFont("helvetica", "normal");
        doc.text("Periodo: <?= date('d/m/Y', strtotime($start)) ?> al <?= date('d/m/Y', strtotime($end)) ?>", pageWidth / 2, 22, { align: 'center' });

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
