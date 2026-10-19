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
                             AND s.status = 'entregado' THEN 1 ELSE 0 END) as delivered_sales_period,
               SUM(CASE WHEN s.created_at BETWEEN ? AND ?
                             AND s.status = 'rechazado' THEN 1 ELSE 0 END) as rejected_sales_period,
               MAX(s.created_at) as last_sale_date
        FROM users u
        JOIN sales s ON u.id = s.user_id
        GROUP BY u.id
        ORDER BY total_sales_period DESC, total_sales_all DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute([$startSql, $endSql, $startSql, $endSql, $startSql, $endSql, $startSql, $endSql]);
$sellers = $stmt->fetchAll(PDO::FETCH_ASSOC);
$totalPeriodoTodos = array_sum(array_column($sellers, 'total_sales_period'));

// Atajos de período para la navegación táctil. Mantienen los mismos parámetros GET.
$weekStart = date('Y-m-d', strtotime('monday this week'));
$weekEnd   = date('Y-m-d', strtotime('sunday this week'));
$monthStart = date('Y-m-01');
$monthEnd   = date('Y-m-t');

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

<main class="app-main">
<div class="app-container">
    <div class="page-head">
        <div class="page-head__text">
            <a href="mas.php" class="navbar-back only-mobile"><?= ui_icon('chevron-left') ?> Más</a>
            <a href="dashboard.php" class="navbar-back only-desktop"><?= ui_icon('chevron-left') ?> Panel</a>
            <h1 class="large-title">Vendedores</h1>
            <p class="subhead tab"><?= $totalPeriodoTodos ?> ventas en el período · todos los vendedores</p>
        </div>
        <div class="page-head__actions only-desktop">
            <button type="button" onclick="exportarPDFSemanal()" class="btn"><?= ui_icon('file-down') ?> PDF desglose semanal</button>
        </div>
    </div>

    <div class="segmented only-mobile" style="grid-template-columns:repeat(3,minmax(0,1fr))" role="group" aria-label="Período">
        <a href="?<?= http_build_query(['start' => $weekStart, 'end' => $weekEnd]) ?>" class="segmented__item <?= $start === $weekStart && $end === $weekEnd ? 'is-active' : '' ?>">Semana</a>
        <a href="?<?= http_build_query(['start' => $monthStart, 'end' => $monthEnd]) ?>" class="segmented__item <?= $start === $monthStart && $end === $monthEnd ? 'is-active' : '' ?>">Mes</a>
        <button type="button" class="segmented__item" onclick="togglePeriodFilter()">Elegir fechas</button>
    </div>

    <form id="periodFilter" method="GET" class="card card--pad only-mobile" style="display:none;gap:8px;align-items:flex-end">
        <div class="field" style="flex:1;min-width:0"><label class="field__label">Desde</label><input type="date" name="start" value="<?= $start ?>" class="input"></div>
        <div class="field" style="flex:1;min-width:0"><label class="field__label">Hasta</label><input type="date" name="end" value="<?= $end ?>" class="input"></div>
        <button type="submit" class="btn btn--icon" aria-label="Aplicar período"><?= ui_icon('filter') ?></button>
    </form>

    <section class="card card--pad only-desktop">
        <form method="GET" style="display:flex;flex-wrap:wrap;align-items:flex-end;gap:12px">
            <div class="field" style="flex:1;min-width:160px"><label class="field__label">Desde</label><input type="date" name="start" value="<?= $start ?>" class="input"></div>
            <div class="field" style="flex:1;min-width:160px"><label class="field__label">Hasta</label><input type="date" name="end" value="<?= $end ?>" class="input"></div>
            <button type="submit" class="btn btn--primary"><?= ui_icon('filter') ?> Aplicar</button>
            <a href="lista_vendedores.php" class="btn btn--icon" title="Restablecer período" aria-label="Restablecer período"><?= ui_icon('rotate-ccw') ?></a>
        </form>
    </section>

    <div class="caption" style="display:flex;flex-wrap:wrap;gap:14px;padding:0 4px">
        <span class="status status--entregado"><span class="dot"></span>Entregadas</span>
        <span class="status status--aprobado"><span class="dot"></span>Aprobadas</span>
        <span class="status status--rechazado"><span class="dot"></span>Rechazadas</span>
        <span class="tab only-desktop"><?= date('d/m/Y', strtotime($start)) ?> – <?= date('d/m/Y', strtotime($end)) ?></span>
    </div>

    <section class="only-mobile" style="display:flex;flex-direction:column;gap:8px">
        <?php if (empty($sellers)): ?>
        <div class="card"><div class="empty"><?= ui_icon('users') ?><p class="empty__title">No hay vendedores con ventas en este período</p></div></div>
        <?php else: ?><div class="group__body">
            <?php foreach ($sellers as $index => $s):
                $delivered = (int)$s['delivered_sales_period'];
                $approved = max(0, (int)$s['approved_sales_period'] - $delivered);
                $rejected = (int)$s['rejected_sales_period'];
                $total = (int)$s['total_sales_period'];
                $effectiveness = $total > 0 ? round(((int)$s['approved_sales_period'] / $total) * 100) : 0;
            ?>
            <a href="ventas_vendedor.php?id=<?= $s['id'] ?>" class="cell" style="align-items:flex-start;gap:10px;padding:12px 14px">
                <span class="tab caption" style="width:18px;padding-top:10px;text-align:center;font-weight:700"><?= $index + 1 ?></span>
                <?= ui_avatar($s['name']) ?>
                <span class="cell__body" style="gap:6px">
                    <span style="display:flex;justify-content:space-between;gap:8px"><span class="cell__title"><?= htmlspecialchars($s['name']) ?></span><span class="tab caption" style="font-weight:700;color:var(--label)"><?= $effectiveness ?>% efectividad</span></span>
                    <span aria-hidden="true" style="display:flex;height:6px;border-radius:3px;overflow:hidden;background:var(--bg-card-2);gap:2px">
                        <span style="width:<?= $total ? round($delivered / $total * 100, 2) : 0 ?>%;background:var(--green)"></span><span style="width:<?= $total ? round($approved / $total * 100, 2) : 0 ?>%;background:var(--tint)"></span><span style="width:<?= $total ? round($rejected / $total * 100, 2) : 0 ?>%;background:var(--red)"></span>
                    </span>
                    <span class="tab cell__sub"><?= $total ?> ventas · <?= $delivered ?> entr. · <?= $approved ?> aprob. · <?= $rejected ?> rech. · última <?= date('d/m H:i', strtotime($s['last_sale_date'])) ?></span>
                </span>
            </a>
            <?php endforeach; ?>
        </div><?php endif; ?>
        <button type="button" onclick="exportarPDFSemanal()" class="btn btn--block"><?= ui_icon('file-text') ?> Descargar desglose semanal</button>
    </section>

    <section class="card only-desktop" style="overflow:hidden">
        <div class="table-wrap"><table class="data-table">
            <thead><tr><th>Vendedor</th><th>Rol</th><th class="num">Ventas</th><th class="num">Entregadas</th><th class="num">Aprobadas</th><th class="num">Rechazadas</th><th class="num">Efectividad</th><th>Última carga</th><th></th></tr></thead>
            <tbody>
            <?php if (empty($sellers)): ?>
                <tr><td colspan="9"><div class="empty"><?= ui_icon('users') ?><p class="empty__title">No hay vendedores con ventas en este período</p></div></td></tr>
            <?php else: foreach ($sellers as $s):
                $delivered = (int)$s['delivered_sales_period'];
                $approved = max(0, (int)$s['approved_sales_period'] - $delivered);
                $rejected = (int)$s['rejected_sales_period'];
                $total = (int)$s['total_sales_period'];
                $effectiveness = $total > 0 ? round(((int)$s['approved_sales_period'] / $total) * 100) : 0;
            ?>
                <tr>
                    <td><div style="display:flex;align-items:center;gap:10px"><?= ui_avatar($s['name']) ?><span><span class="cell__title"><?= htmlspecialchars($s['name']) ?></span><span class="tab caption" style="display:block">@<?= htmlspecialchars($s['username']) ?></span></span></div></td>
                    <td><span class="tag"><?= ucfirst($s['role']) ?></span></td>
                    <td class="num tab"><strong><?= $total ?></strong><span class="caption" style="display:block">de <?= (int)$s['total_sales_all'] ?></span></td>
                    <td class="num tab" style="color:var(--green);font-weight:700"><?= $delivered ?></td><td class="num tab" style="color:var(--tint-text);font-weight:700"><?= $approved ?></td><td class="num tab" style="color:var(--red);font-weight:700"><?= $rejected ?></td>
                    <td class="num"><span class="tab" style="font-weight:700"><?= $effectiveness ?>%</span><div class="progress" style="width:72px;margin:4px 0 0 auto"><span class="progress__bar" style="width:<?= $effectiveness ?>%"></span></div></td>
                    <td class="tab caption"><?= date('d/m/Y H:i', strtotime($s['last_sale_date'])) ?></td>
                    <td><div style="display:flex;justify-content:flex-end;gap:8px"><button type="button" onclick="openWeekModal(<?= $s['id'] ?>, <?= htmlspecialchars(json_encode($s['name']), ENT_QUOTES, 'UTF-8') ?>)" class="btn btn--icon btn--sm" title="Ver desglose semanal"><?= ui_icon('calendar-days') ?></button><a href="ventas_vendedor.php?id=<?= $s['id'] ?>" class="btn btn--sm"><?= ui_icon('list') ?> Ver ventas</a></div></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table></div>
    </section>
</div>
</main>

<!-- Modal: desglose semanal -->
<div id="weekModal" class="sheet-backdrop" style="display:none" role="dialog" aria-modal="true" aria-labelledby="weekModalTitle">
    <div class="sheet" style="max-width:600px">
        <span class="sheet__grabber"></span>
        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:16px">
            <div><h2 id="weekModalTitle" class="headline" style="margin:0">Desglose semanal</h2><p id="weekModalSellerName" class="subhead"></p></div>
            <button type="button" onclick="closeWeekModal()" class="btn btn--icon" aria-label="Cerrar"><?= ui_icon('x') ?></button>
        </div>
        <p class="caption">Solo ventas aprobadas y entregadas.</p>
        <div class="table-wrap"><table class="data-table"><thead><tr><th>Semana</th><th class="num">Aprobadas</th><th class="num">Entregadas</th><th class="num">Total</th></tr></thead><tbody id="weekModalBody"></tbody></table></div>
    </div>
</div>

<script>
    const weeklyData = <?= json_encode($weeklyByUser) ?>;

    function togglePeriodFilter() {
        const filter = document.getElementById('periodFilter');
        filter.style.display = filter.style.display === 'flex' ? 'none' : 'flex';
    }

    function openWeekModal(userId, userName) {
        document.getElementById('weekModalSellerName').textContent = userName;
        const weeks = weeklyData[userId] || [];
        const tbody = document.getElementById('weekModalBody');
        tbody.innerHTML = weeks.length
            ? weeks.map(w => `<tr><td class="tab caption">${w.label}</td><td class="num tab" style="color:var(--tint-text);font-weight:700">${w.aprobadas}</td><td class="num tab" style="color:var(--green);font-weight:700">${w.entregadas}</td><td class="num tab" style="font-weight:700">${w.total}</td></tr>`).join('')
            : '<tr><td colspan="4"><div class="empty"><p class="empty__title">Sin ventas aprobadas o entregadas en este período</p></div></td></tr>';
        document.getElementById('weekModal').style.display = 'flex';
    }

    function closeWeekModal() {
        document.getElementById('weekModal').style.display = 'none';
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
