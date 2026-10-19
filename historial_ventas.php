<?php
require 'includes/db.php';
require_once 'includes/functions.php'; // Requerimiento de funciones globales

// SEGURIDAD: Solo usuarios logueados
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

// --- 1. FILTROS (Fechas, Búsqueda, Estado, Vendedor) ---
$start            = $_GET['start']       ?? date('Y-m-01');
$end              = $_GET['end']         ?? date('Y-m-d');
$search           = $_GET['search']      ?? '';
$filter_status    = $_GET['status']      ?? '';
$filter_seller    = (int)($_GET['seller_id']   ?? 0);
$filter_approver  = (int)($_GET['approver_id'] ?? 0);

$startSql = $start . ' 00:00:00';
$endSql   = $end   . ' 23:59:59';

// Construcción base del WHERE
$whereClause = "WHERE s.status IN ('rechazado', 'entregado')
                AND s.created_at BETWEEN :start AND :end";
$params = [':start' => $startSql, ':end' => $endSql];

// Si es vendedor o entregador, solo ve su propio historial
if ($role === 'vendedor' || $role === 'entregador') {
    $whereClause .= " AND s.user_id = :user_id";
    $params[':user_id'] = $user_id;
} elseif ($filter_seller > 0) {
    $whereClause .= " AND s.user_id = :seller_id";
    $params[':seller_id'] = $filter_seller;
}

// Filtro por quien aprobó/verificó
if ($filter_approver > 0) {
    $whereClause .= " AND s.approved_by = :approver_id";
    $params[':approver_id'] = $filter_approver;
}

// Filtro por estado
if (!empty($filter_status) && in_array($filter_status, ['entregado', 'rechazado'])) {
    $whereClause = str_replace("s.status IN ('rechazado', 'entregado')", "s.status = :filter_status", $whereClause);
    $params[':filter_status'] = $filter_status;
}

// Filtro de búsqueda por Nombre o DNI
if (!empty($search)) {
    $whereClause .= " AND (s.client_name LIKE :search OR s.client_dni LIKE :search)";
    $params[':search'] = "%$search%";
}

// Lista de vendedores para el selector (solo para roles gestores)
$sellers = [];
$approvers = [];
if (in_array($role, ['admin', 'supervisor', 'verificador'])) {
    $sellers   = $pdo->query("SELECT id, name FROM users WHERE role = 'vendedor' ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
    $approvers = $pdo->query("SELECT id, name FROM users WHERE role IN ('admin', 'supervisor', 'verificador') ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
}

// Preparamos los parámetros de URL para que la paginación los mantenga
$filtrosParams = ['start' => $start, 'end' => $end, 'search' => $search, 'status' => $filter_status, 'seller_id' => $filter_seller ?: '', 'approver_id' => $filter_approver ?: ''];

// --- 2. LÓGICA DE PAGINACIÓN ---
$registros_por_pagina = 20;
$pagina_actual = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($pagina_actual < 1) $pagina_actual = 1;
$offset = ($pagina_actual - 1) * $registros_por_pagina;

// Consulta A: Contar Total de registros filtrados
$sqlCount = "SELECT COUNT(*) FROM sales s $whereClause";
$stmtCount = $pdo->prepare($sqlCount);
$stmtCount->execute($params);
$total_registros = $stmtCount->fetchColumn();

// --- 3. CONSULTA B: DATOS PAGINADOS (Para la tabla) ---
$sqlPage = "SELECT
                s.*,
                u_seller.name as seller_name,
                u_rej.name as rejected_by_name,
                u_del.name as delivered_by_name,
                u_apr.name as approved_by_name,
                u_ver.name as assigned_verifier_name
            FROM sales s
            LEFT JOIN users u_seller ON s.user_id = u_seller.id
            LEFT JOIN users u_rej ON s.rejected_by = u_rej.id
            LEFT JOIN users u_del ON s.delivered_by = u_del.id
            LEFT JOIN users u_apr ON s.approved_by = u_apr.id
            LEFT JOIN users u_ver ON s.assigned_verifier_id = u_ver.id
            $whereClause
            ORDER BY s.created_at DESC
            LIMIT :offset, :limit";

$stmtPage = $pdo->prepare($sqlPage);
foreach ($params as $key => $value) { $stmtPage->bindValue($key, $value); }
$stmtPage->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmtPage->bindValue(':limit', $registros_por_pagina, PDO::PARAM_INT);
$stmtPage->execute();
$history = $stmtPage->fetchAll(PDO::FETCH_ASSOC);

// --- 4. CONSULTA C: DATOS PARA PDF (Todos los filtrados) ---
$sqlFull = "SELECT
                s.*,
                u_seller.name as seller_name,
                u_rej.name as rejected_by_name,
                u_del.name as delivered_by_name,
                u_apr.name as approved_by_name,
                u_ver.name as assigned_verifier_name
            FROM sales s
            LEFT JOIN users u_seller ON s.user_id = u_seller.id
            LEFT JOIN users u_rej ON s.rejected_by = u_rej.id
            LEFT JOIN users u_del ON s.delivered_by = u_del.id
            LEFT JOIN users u_apr ON s.approved_by = u_apr.id
            LEFT JOIN users u_ver ON s.assigned_verifier_id = u_ver.id
            $whereClause
            ORDER BY s.created_at DESC";

$stmtFull = $pdo->prepare($sqlFull);
foreach ($params as $key => $value) { $stmtFull->bindValue($key, $value); }
$stmtFull->execute();
$allHistory = $stmtFull->fetchAll(PDO::FETCH_ASSOC);

// Preparar array de datos simplificados para que JavaScript genere el PDF
$pdfData = array_map(function($h) {
    $approvedStr = !empty($h['approved_by_name']) ? " | Aprobado por: " . $h['approved_by_name'] : '';
    $auditDetail = ($h['status'] === 'entregado')
        ? "Entregado por: " . ($h['delivered_by_name'] ?? 'S/D') . " el " . (!empty($h['delivered_at']) ? date('d/m/Y H:i', strtotime($h['delivered_at'])) : '-') . $approvedStr
        : "Rechazado por: " . ($h['rejected_by_name'] ?? 'Admin') . ". Motivo: " . ($h['rejected_reason'] ?? '-');

    return [
        'date'   => date('d/m/Y H:i', strtotime($h['created_at'])),
        'dni'    => $h['client_dni'] ?? '-',
        'client' => $h['client_name'],
        'phone'  => $h['client_whatsapp'] ?? '-',
        'item'   => $h['item'],
        'seller' => $h['seller_name'],
        'status' => strtoupper($h['status']),
        'audit'  => $auditDetail
    ];
}, $allHistory);

// --- Presentación: una sola fuente de markup para la fila de PC y la tarjeta de celular,
// reutilizada tanto en el render inicial como en la respuesta AJAX de búsqueda. ---
function historia_auditoria_texto(array $h): string {
    if ($h['status'] === 'entregado') {
        $txt = 'Entregó ' . ($h['delivered_by_name'] ?? 'S/D');
        if (!empty($h['approved_by_name'])) $txt .= ' · Aprobó ' . $h['approved_by_name'];
        return $txt;
    }
    $t = rejection_type_blocks($h['rejected_type'] ?? null) ? 'Lista negra' : 'Se puede volver a cargar';
    return $t . (!empty($h['rejected_by_name']) ? ' · ' . $h['rejected_by_name'] : '');
}

function render_history_row_desktop(array $h, int $counter, string $role): string {
    ob_start();
    ?>
    <tr>
        <td class="caption" style="font-weight:700"><?= $counter ?></td>
        <td class="tab caption" style="white-space:nowrap"><?= date('d/m/Y H:i', strtotime($h['created_at'])) ?></td>
        <td>
            <div class="cell__title" style="font-size:var(--fs-headline)"><?= htmlspecialchars($h['client_name']) ?></div>
            <div class="tab caption"><?= htmlspecialchars($h['client_dni']) ?></div>
        </td>
        <td>
            <div><?= htmlspecialchars($h['item']) ?></div>
            <div class="caption" style="font-style:italic"><?= htmlspecialchars($h['seller_name']) ?></div>
        </td>
        <td><?= status_badge($h['status']) ?></td>
        <td style="max-width:260px">
            <?php if ($h['status'] === 'entregado'): ?>
                <div class="caption">
                    <span style="display:block;font-weight:700;color:var(--green)">Entregado por <?= htmlspecialchars($h['delivered_by_name'] ?? 'S/D') ?></span>
                    <span class="tab" style="display:block"><?= !empty($h['delivered_at']) ? date('d/m/Y H:i', strtotime($h['delivered_at'])) : '-' ?></span>
                    <?php if (!empty($h['approved_by_name'])): ?>
                    <span style="display:block;font-weight:700;margin-top:4px;color:var(--indigo)">Aprobó <?= htmlspecialchars($h['approved_by_name']) ?></span>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="caption">
                    <span style="display:block;font-weight:700;color:var(--red)">Rechazado por <?= htmlspecialchars($h['rejected_by_name'] ?? 'Admin') ?></span>
                    <span class="tag" style="margin:2px 0"><?= htmlspecialchars(rejection_type_label($h['rejected_type'] ?? null)) ?></span>
                    <div style="font-style:italic;padding-left:8px;border-left:2px solid var(--separator)">"<?= htmlspecialchars($h['rejected_reason'] ?? 'Sin motivo') ?>"</div>
                </div>
            <?php endif; ?>
            <?php if (!empty($h['assigned_verifier_name'])): ?>
            <div class="caption" style="margin-top:6px;padding-top:6px;border-top:1px dashed var(--separator);color:var(--tint-text)">
                Verificador: <?= htmlspecialchars($h['assigned_verifier_name']) ?>
            </div>
            <?php endif; ?>
        </td>
        <td style="text-align:right">
            <div style="display:flex;justify-content:flex-end;gap:8px">
                <a href="ver_ficha.php?id=<?= $h['id'] ?>" class="btn btn--icon btn--sm" title="Ver ficha"><?= ui_icon('eye') ?></a>
                <?php if ($h['status'] === 'rechazado' && in_array($role, ['admin', 'supervisor'])): ?>
                <form method="POST" action="update_status.php" onsubmit="return confirm('¿Devolver esta venta al panel de revisión?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $h['id'] ?>">
                    <input type="hidden" name="status" value="revision">
                    <button type="submit" class="btn btn--icon btn--sm" title="Volver a revisión"><?= ui_icon('rotate-ccw') ?></button>
                </form>
                <?php endif; ?>
            </div>
        </td>
    </tr>
    <?php
    return ob_get_clean();
}

function render_history_cell_mobile(array $h): string {
    ob_start();
    ?>
    <a href="ver_ficha.php?id=<?= $h['id'] ?>" class="cell">
        <?= ui_avatar($h['client_name']) ?>
        <span class="cell__body">
            <span style="display:flex;justify-content:space-between;gap:8px">
                <span class="cell__title"><?= htmlspecialchars($h['client_name']) ?></span>
                <span class="tab" style="font-weight:600;flex-shrink:0"><?= ui_money($h['total_amount']) ?></span>
            </span>
            <span class="cell__meta">
                <span class="dot dot--<?= $h['status'] ?>"></span>
                <?= htmlspecialchars(ucfirst($h['status'] === 'entregado' ? 'Entregada' : 'Rechazada')) ?>
                · <?= htmlspecialchars(historia_auditoria_texto($h)) ?>
            </span>
        </span>
    </a>
    <?php
    return ob_get_clean();
}

// --- 5. RESPUESTA PARA BÚSQUEDA AJAX ---
if (isset($_GET['ajax'])) {
    if (empty($history)) {
        $tableHtml = '<tr><td colspan="7"><div class="empty">' . ui_icon('search-x') . '<p class="empty__title">Sin resultados</p></div></td></tr>';
        $mobileHtml = '<div class="empty">' . ui_icon('search-x') . '<p class="empty__title">Sin resultados</p></div>';
    } else {
        $tableHtml = '';
        $mobileHtml = '';
        $counter = $offset + 1;
        foreach ($history as $h) {
            $tableHtml .= render_history_row_desktop($h, $counter++, $role);
            $mobileHtml .= render_history_cell_mobile($h);
        }
    }

    $paginationHtml = renderPagination($total_registros, $registros_por_pagina, $pagina_actual, $filtrosParams);

    header('Content-Type: application/json');
    echo json_encode([
        'html' => $tableHtml,
        'mobileHtml' => $mobileHtml,
        'pagination' => $paginationHtml,
        'pdfData' => $pdfData
    ]);
    exit;
}

include 'includes/header.php';
?>

<!-- Librerías de Exportación -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.29/jspdf.plugin.autotable.min.js"></script>

<main class="app-main">
<div class="app-container">

    <div class="page-head">
        <div class="page-head__text">
            <a href="dashboard.php" class="navbar-back only-desktop"><?= ui_icon('chevron-left') ?> Panel</a>
            <h1 class="large-title">Historial de ventas</h1>
            <p class="subhead">Auditoría completa de ventas finalizadas.</p>
        </div>
        <div class="page-head__actions">
            <button type="button" onclick="exportarPDF()" class="btn btn--sm"><?= ui_icon('file-down') ?> PDF</button>
            <a href="export_csv.php?<?= http_build_query($filtrosParams) ?>" class="btn btn--sm"><?= ui_icon('sheet') ?> CSV</a>
        </div>
    </div>

    <!-- Filtro rápido por estado (celular) -->
    <div class="chips only-mobile">
        <a href="?<?= http_build_query(array_merge($filtrosParams, ['status' => ''])) ?>" class="chip <?= $filter_status === '' ? 'is-active' : '' ?>">Todas</a>
        <a href="?<?= http_build_query(array_merge($filtrosParams, ['status' => 'entregado'])) ?>" class="chip <?= $filter_status === 'entregado' ? 'is-active' : '' ?>"><span class="dot dot--entregado"></span> Entregadas</a>
        <a href="?<?= http_build_query(array_merge($filtrosParams, ['status' => 'rechazado'])) ?>" class="chip <?= $filter_status === 'rechazado' ? 'is-active' : '' ?>"><span class="dot dot--rechazado"></span> Rechazadas</a>
    </div>

    <!-- Barra de Filtros -->
    <div class="card card--pad">
        <form id="filterForm" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end">
            <div class="field" style="flex:1;min-width:130px">
                <label class="field__label">Desde</label>
                <input type="date" name="start" value="<?= $start ?>" class="input">
            </div>
            <div class="field" style="flex:1;min-width:130px">
                <label class="field__label">Hasta</label>
                <input type="date" name="end" value="<?= $end ?>" class="input">
            </div>
            <div class="field" style="flex:2;min-width:200px">
                <label class="field__label">Buscar cliente</label>
                <input type="text" name="search" id="searchInput" value="<?= htmlspecialchars($search) ?>" class="input" placeholder="Nombre o DNI del cliente...">
            </div>
            <div class="field only-desktop" style="flex:1;min-width:140px">
                <label class="field__label">Estado</label>
                <select name="status" class="select">
                    <option value="">Todos</option>
                    <option value="entregado" <?= $filter_status === 'entregado' ? 'selected' : '' ?>>Entregado</option>
                    <option value="rechazado" <?= $filter_status === 'rechazado' ? 'selected' : '' ?>>Rechazado</option>
                </select>
            </div>
            <?php if (!empty($sellers)): ?>
            <div class="field" style="flex:1;min-width:160px">
                <label class="field__label">Vendedor</label>
                <select name="seller_id" class="select">
                    <option value="">Todos</option>
                    <?php foreach ($sellers as $s): ?>
                    <option value="<?= $s['id'] ?>" <?= $filter_seller === (int)$s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <?php if (!empty($approvers)): ?>
            <div class="field only-desktop" style="flex:1;min-width:160px">
                <label class="field__label">Verificó / Aprobó</label>
                <select name="approver_id" class="select">
                    <option value="">Todos</option>
                    <?php foreach ($approvers as $a): ?>
                    <option value="<?= $a['id'] ?>" <?= $filter_approver === (int)$a['id'] ? 'selected' : '' ?>><?= htmlspecialchars($a['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <button type="submit" class="btn btn--primary"><?= ui_icon('filter') ?> Filtrar</button>
        </form>
    </div>

    <!-- Tabla (PC) -->
    <section class="card only-desktop" style="overflow:hidden">
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>#</th><th>Fecha carga</th><th>Cliente</th><th>Detalle venta</th><th>Estado</th><th>Auditoría</th><th></th>
                    </tr>
                </thead>
                <tbody id="historyTableBody">
                    <?php if (empty($history)): ?>
                        <tr><td colspan="7"><div class="empty"><?= ui_icon('search-x') ?><p class="empty__title">No se encontraron resultados</p></div></td></tr>
                    <?php else: $counter = $offset + 1; foreach ($history as $h): ?>
                        <?= render_history_row_desktop($h, $counter++, $role) ?>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <!-- Lista (celular) -->
    <section class="group__body only-mobile" id="historyMobileBody">
        <?php if (empty($history)): ?>
            <div class="empty"><?= ui_icon('search-x') ?><p class="empty__title">No se encontraron resultados</p></div>
        <?php else: foreach ($history as $h): ?>
            <?= render_history_cell_mobile($h) ?>
        <?php endforeach; endif; ?>
    </section>

    <div id="paginationContainer"><?= renderPagination($total_registros, $registros_por_pagina, $pagina_actual, $filtrosParams); ?></div>
</div>
</main>

<script>
    // 1. Lógica de Búsqueda AJAX
    let typingTimer;
    let historyData = <?= json_encode($pdfData) ?>; // Cargamos datos iniciales para el PDF

    const searchInput = document.getElementById('searchInput');
    const filterForm = document.getElementById('filterForm');
    const tableBody = document.getElementById('historyTableBody');
    const mobileBody = document.getElementById('historyMobileBody');
    const paginationContainer = document.getElementById('paginationContainer');

    searchInput.addEventListener('input', function () {
        clearTimeout(typingTimer);
        typingTimer = setTimeout(performSearch, 400); // 400ms de espera después de escribir
    });

    function performSearch() {
        const formData = new FormData(filterForm);
        const params = new URLSearchParams(formData);
        params.append('ajax', '1');

        const currentUrl = new URL(window.location.href);
        formData.forEach((value, key) => currentUrl.searchParams.set(key, value));
        currentUrl.searchParams.delete('page');
        window.history.replaceState({}, '', currentUrl);

        fetch('historial_ventas.php?' + params.toString())
            .then(res => res.json())
            .then(data => {
                tableBody.innerHTML = data.html;
                mobileBody.innerHTML = data.mobileHtml;
                paginationContainer.innerHTML = data.pagination;
                historyData = data.pdfData;
                if (window.lucide) lucide.createIcons();
            })
            .catch(err => console.error("Error en búsqueda:", err));
    }

    // 2. Lógica de Exportación a PDF
    function exportarPDF() {
        if (historyData.length === 0) {
            alert("No hay registros para exportar.");
            return;
        }

        const { jsPDF } = window.jspdf;
        const doc = new jsPDF('p', 'mm', 'a4');
        const pageWidth = doc.internal.pageSize.getWidth();
        const rangeText = "Periodo: <?= date('d/m/Y', strtotime($start)) ?> al <?= date('d/m/Y', strtotime($end)) ?>";

        const statusFilter = document.querySelector('[name="status"]') ? document.querySelector('[name="status"]').value : '<?= $filter_status ?>';
        const soloRechazados = statusFilter === 'rechazado';

        if (soloRechazados) {
            doc.setFontSize(16);
            doc.setFont("helvetica", "bold");
            doc.text("Ventas Rechazadas", pageWidth / 2, 15, { align: 'center' });

            doc.setFontSize(9);
            doc.setFont("helvetica", "normal");
            doc.text(rangeText, pageWidth / 2, 22, { align: 'center' });

            const body = historyData.map((row, i) => [
                i + 1, row.dni, row.client, row.phone, row.item, row.seller
            ]);

            doc.autoTable({
                head: [['#', 'DNI', 'Nombre y Apellido', 'Celular', 'Artículo', 'Vendedor']],
                body: body,
                startY: 28, theme: 'grid',
                styles: { fontSize: 8, cellPadding: 2.5, textColor: [0, 0, 0] },
                headStyles: { fillColor: [231, 54, 107], textColor: [255, 255, 255], fontStyle: 'bold' },
                columnStyles: { 0: { cellWidth: 8 }, 1: { cellWidth: 22 }, 2: { cellWidth: 50 }, 3: { cellWidth: 28 }, 4: { cellWidth: 50 }, 5: { cellWidth: 'auto' } },
                margin: { top: 28, left: 14, right: 14 }
            });
        } else {
            doc.setFontSize(18);
            doc.setFont("helvetica", "bold");
            doc.text("Auditoría de Ventas (Historial)", pageWidth / 2, 15, { align: 'center' });

            doc.setFontSize(10);
            doc.setFont("helvetica", "normal");
            doc.text(rangeText, pageWidth / 2, 22, { align: 'center' });

            const body = historyData.map((row, i) => [
                i + 1, row.date, row.client, row.item, row.seller, row.status, row.audit
            ]);

            doc.autoTable({
                head: [['#', 'Fecha', 'Cliente', 'Artículo', 'Vendedor', 'Estado', 'Detalle Auditoría']],
                body: body,
                startY: 30, theme: 'grid',
                styles: { fontSize: 7, cellPadding: 2, textColor: [0, 0, 0] },
                headStyles: { fillColor: [240, 240, 240], textColor: [0, 0, 0], fontStyle: 'bold' },
                columnStyles: { 0: { cellWidth: 7 }, 1: { cellWidth: 18 }, 2: { cellWidth: 35 }, 3: { cellWidth: 35 }, 4: { cellWidth: 25 }, 5: { cellWidth: 20 }, 6: { cellWidth: 'auto' } },
                margin: { top: 30, left: 14, right: 14 }
            });
        }

        window.open(doc.output('bloburl'), '_blank');
    }
</script>

<?php include 'includes/footer.php'; ?>
