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
                u_apr.name as approved_by_name
            FROM sales s
            LEFT JOIN users u_seller ON s.user_id = u_seller.id
            LEFT JOIN users u_rej ON s.rejected_by = u_rej.id
            LEFT JOIN users u_del ON s.delivered_by = u_del.id
            LEFT JOIN users u_apr ON s.approved_by = u_apr.id
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
                u_apr.name as approved_by_name
            FROM sales s
            LEFT JOIN users u_seller ON s.user_id = u_seller.id
            LEFT JOIN users u_rej ON s.rejected_by = u_rej.id
            LEFT JOIN users u_del ON s.delivered_by = u_del.id
            LEFT JOIN users u_apr ON s.approved_by = u_apr.id
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
        ? "Entregado por: " . ($h['delivered_by_name'] ?? 'S/D') . " el " . (!empty($h['delivered_at']) ? date('d/m/Y', strtotime($h['delivered_at'])) : '-') . $approvedStr
        : "Rechazado por: " . ($h['rejected_by_name'] ?? 'Admin') . ". Motivo: " . ($h['rejected_reason'] ?? '-');

    return [
        'date' => date('d/m/Y', strtotime($h['created_at'])),
        'client' => $h['client_name'],
        'item' => $h['item'],
        'seller' => $h['seller_name'],
        'status' => strtoupper($h['status']),
        'audit' => $auditDetail
    ];
}, $allHistory);

// --- 5. RESPUESTA PARA BÚSQUEDA AJAX ---
if (isset($_GET['ajax'])) {
    ob_start();
    if (empty($history)) {
        echo '<tr><td colspan="7" class="p-12 text-center text-slate-500 italic flex flex-col items-center justify-center w-full col-span-7"><i data-lucide="search-x" class="w-10 h-10 mb-2 opacity-50"></i>No se encontraron resultados.</td></tr>';
    } else {
        $counter = $offset + 1;
        foreach ($history as $h) {
            ?>
            <tr class="transition">
                <td class="p-5 pl-6 font-bold" style="color:var(--ink-3);"><?= $counter++ ?></td>
                <td class="p-5 font-mono text-xs whitespace-nowrap" style="color:var(--ink-2);"><?= date('d/m/Y', strtotime($h['created_at'])) ?></td>
                <td class="p-5">
                    <div class="font-bold" style="color:var(--ink);"><?= htmlspecialchars($h['client_name']) ?></div>
                    <div class="text-[10px] font-mono uppercase tracking-tighter" style="color:var(--ink-3);"><?= htmlspecialchars($h['client_dni']) ?></div>
                </td>
                <td class="p-5">
                    <div class="text-xs font-medium" style="color:var(--ink);"><?= htmlspecialchars($h['item']) ?></div>
                    <div class="text-[10px] italic" style="color:var(--ink-3);"><?= htmlspecialchars($h['seller_name']) ?></div>
                </td>
                <td class="p-5">
                    <?= status_badge($h['status']) ?>
                </td>
                <td class="p-5">
                    <?php if ($h['status'] === 'entregado'): ?>
                        <div class="text-[10px]" style="color:var(--ink-2);">
                            <span class="block font-bold mb-0.5 uppercase tracking-tighter" style="color:var(--ent-ink);">Entregado por:</span>
                            <span class="flex items-center gap-1"><i data-lucide="user-check" class="w-2.5 h-2.5"></i> <?= htmlspecialchars($h['delivered_by_name'] ?? 'S/D') ?></span>
                            <span class="block mt-0.5 font-mono" style="color:var(--ink-3);"><?= !empty($h['delivered_at']) ? date('d/m/Y', strtotime($h['delivered_at'])) : '-' ?></span>
                            <?php if (!empty($h['approved_by_name'])): ?>
                            <span class="block font-bold mt-1.5 mb-0.5 uppercase tracking-tighter" style="color:#7c3aed;">Aprobado por:</span>
                            <span class="flex items-center gap-1"><i data-lucide="shield-check" class="w-2.5 h-2.5"></i> <?= htmlspecialchars($h['approved_by_name']) ?></span>
                            <?php if (!empty($h['approved_at'])): ?>
                            <span class="block mt-0.5 font-mono" style="color:var(--ink-3);"><?= date('d/m/Y', strtotime($h['approved_at'])) ?></span>
                            <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-[10px] max-w-[200px]" style="color:var(--ink-2);">
                            <span class="block font-bold mb-0.5 uppercase tracking-tighter" style="color:var(--rec-ink);">Rechazado por:</span>
                            <span class="flex items-center gap-1 mb-1"><i data-lucide="shield-alert" class="w-2.5 h-2.5"></i> <?= htmlspecialchars($h['rejected_by_name'] ?? 'Admin') ?></span>
                            <div class="italic pl-2 line-clamp-2" style="color:var(--ink-3);border-left:2px solid var(--rev-bg);">"<?= htmlspecialchars($h['rejected_reason'] ?? 'Sin motivo') ?>"</div>
                        </div>
                    <?php endif; ?>
                </td>
                <td class="p-5 text-right">
                    <div class="flex justify-end gap-2 items-center">
                        <a href="ver_ficha.php?id=<?= $h['id'] ?>" class="inline-flex items-center justify-center w-11 h-11 rounded-lg transition" style="background:var(--accent-soft);color:var(--accent-ink);border:1.5px solid rgba(99,102,241,.2);" title="Ver Ficha" onmouseover="this.style.background='var(--accent)';this.style.color='#fff'" onmouseout="this.style.background='var(--accent-soft)';this.style.color='var(--accent-ink)'">
                            <i data-lucide="eye" class="w-4 h-4"></i>
                        </a>
                        <?php if ($h['status'] === 'rechazado' && in_array($role, ['admin', 'supervisor'])): ?>
                        <form method="POST" action="update_status.php" class="inline" onsubmit="return confirm('¿Devolver esta venta al panel de revisión?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= $h['id'] ?>">
                            <input type="hidden" name="status" value="revision">
                            <button type="submit" class="inline-flex items-center justify-center w-11 h-11 rounded-lg transition" style="background:var(--rev-bg);color:var(--rev-ink);border:1.5px solid rgba(138,90,8,.2);" title="Volver a Revisión" onmouseover="this.style.background='var(--rev-ink)';this.style.color='#fff'" onmouseout="this.style.background='var(--rev-bg)';this.style.color='var(--rev-ink)'">
                                <i data-lucide="rotate-ccw" class="w-4 h-4"></i>
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php
        }
    }
    $tableHtml = ob_get_clean();

    // Renderizar paginación AJAX
    $paginationHtml = renderPagination($total_registros, $registros_por_pagina, $pagina_actual, $filtrosParams);

    header('Content-Type: application/json');
    echo json_encode([
        'html' => $tableHtml, 
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

<main class="flex-1 max-w-7xl mx-auto w-full p-4 sm:p-6 lg:p-8 fade-in">
    
    <!-- Título y Regresar -->
    <div class="flex items-center gap-4 mb-8">
        <a href="dashboard.php" class="p-2 rounded-full transition" style="background:var(--paper);border:1.5px solid var(--line);color:var(--ink-3);" onmouseover="this.style.background='var(--accent-soft)';this.style.color='var(--accent-ink)'" onmouseout="this.style.background='var(--paper)';this.style.color='var(--ink-3)'">
            <i data-lucide="chevron-left"></i>
        </a>
        <div>
            <h1 class="text-2xl font-bold tracking-tight" style="color:var(--ink);">Historial de Ventas</h1>
            <p class="text-sm" style="color:var(--ink-3);">Auditoría completa de ventas finalizadas.</p>
        </div>
    </div>

    <!-- Barra de Filtros -->
    <div class="p-5 rounded-2xl mb-8" style="background:var(--card);border:1.5px solid var(--line);box-shadow:var(--shadow-card);">
        <form id="filterForm" class="flex flex-col xl:flex-row gap-4 items-end flex-wrap">
            <div class="flex gap-4 w-full xl:w-auto">
                <div class="flex-1 min-w-[140px]">
                    <label class="block text-[10px] font-bold uppercase mb-1.5 ml-1 tracking-widest" style="color:var(--ink-3);">Desde</label>
                    <input type="date" name="start" value="<?= $start ?>" class="w-full input-light px-3 py-2.5 text-sm">
                </div>
                <div class="flex-1 min-w-[140px]">
                    <label class="block text-[10px] font-bold uppercase mb-1.5 ml-1 tracking-widest" style="color:var(--ink-3);">Hasta</label>
                    <input type="date" name="end" value="<?= $end ?>" class="w-full input-light px-3 py-2.5 text-sm">
                </div>
            </div>
            <div class="w-full xl:flex-1">
                <label class="block text-[10px] font-bold uppercase mb-1.5 ml-1 tracking-widest" style="color:var(--ink-3);">Buscar Cliente</label>
                <div class="relative">
                    <input type="text" name="search" id="searchInput" value="<?= htmlspecialchars($search) ?>" class="w-full input-light px-4 py-2.5 pl-10 text-sm" placeholder="Nombre o DNI del cliente...">
                    <div class="absolute left-3 top-1/2 -translate-y-1/2" style="color:var(--ink-3);"><i data-lucide="search" class="w-4 h-4"></i></div>
                </div>
            </div>
            <div class="flex gap-4 w-full xl:w-auto">
                <div class="flex-1 min-w-[130px]">
                    <label class="block text-[10px] font-bold uppercase mb-1.5 ml-1 tracking-widest" style="color:var(--ink-3);">Estado</label>
                    <select name="status" class="w-full input-light px-3 py-2.5 text-sm">
                        <option value="">Todos</option>
                        <option value="entregado" <?= $filter_status === 'entregado' ? 'selected' : '' ?>>Entregado</option>
                        <option value="rechazado" <?= $filter_status === 'rechazado' ? 'selected' : '' ?>>Rechazado</option>
                    </select>
                </div>
                <?php if (!empty($sellers)): ?>
                <div class="flex-1 min-w-[160px]">
                    <label class="block text-[10px] font-bold uppercase mb-1.5 ml-1 tracking-widest" style="color:var(--ink-3);">Vendedor</label>
                    <select name="seller_id" class="w-full input-light px-3 py-2.5 text-sm">
                        <option value="">Todos</option>
                        <?php foreach ($sellers as $s): ?>
                        <option value="<?= $s['id'] ?>" <?= $filter_seller === (int)$s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <?php if (!empty($approvers)): ?>
                <div class="flex-1 min-w-[160px]">
                    <label class="block text-[10px] font-bold uppercase mb-1.5 ml-1 tracking-widest" style="color:var(--ink-3);">Verificó / Aprobó</label>
                    <select name="approver_id" class="w-full input-light px-3 py-2.5 text-sm">
                        <option value="">Todos</option>
                        <?php foreach ($approvers as $a): ?>
                        <option value="<?= $a['id'] ?>" <?= $filter_approver === (int)$a['id'] ? 'selected' : '' ?>><?= htmlspecialchars($a['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
            </div>
            <div class="flex gap-2 w-full xl:w-auto">
                <button type="submit" class="flex-1 text-white px-6 py-2.5 rounded-xl font-bold transition flex items-center justify-center gap-2 text-sm" style="background:var(--accent);" onmouseover="this.style.background='#4f46e5'" onmouseout="this.style.background='var(--accent)'">
                    <i data-lucide="filter" class="w-4 h-4"></i> Filtrar
                </button>
                <button type="button" onclick="exportarPDF()" class="text-white px-5 py-2.5 rounded-xl font-bold transition flex items-center justify-center gap-2 text-sm" style="background:var(--rec-ink);" title="Exportar PDF" onmouseover="this.style.opacity='.85'" onmouseout="this.style.opacity='1'">
                    <i data-lucide="file-down" class="w-4 h-4"></i> PDF
                </button>
                <a href="export_csv.php?<?= http_build_query(['start' => $start, 'end' => $end, 'search' => $search, 'status' => $filter_status, 'seller_id' => $filter_seller ?: '', 'approver_id' => $filter_approver ?: '']) ?>" class="text-white px-5 py-2.5 rounded-xl font-bold transition flex items-center justify-center gap-2 text-sm" style="background:var(--apr-ink);" title="Exportar CSV para Excel" onmouseover="this.style.opacity='.85'" onmouseout="this.style.opacity='1'">
                    <i data-lucide="sheet" class="w-4 h-4"></i> CSV
                </a>
            </div>
        </form>
    </div>

    <!-- Tabla Principal -->
    <div class="rounded-2xl overflow-hidden flex flex-col min-h-[500px]" style="background:var(--card);border:1.5px solid var(--line);box-shadow:var(--shadow-card);">
        <div class="overflow-x-auto flex-1">
            <table class="w-full text-left text-sm table-light">
                <thead>
                    <tr>
                        <th class="p-5 pl-6">#</th>
                        <th class="p-5">Fecha Carga</th>
                        <th class="p-5">Cliente</th>
                        <th class="p-5">Detalle Venta</th>
                        <th class="p-5">Estado</th>
                        <th class="p-5">Auditoría</th>
                        <th class="p-5 text-right">Acciones</th>
                    </tr>
                </thead>
                <tbody id="historyTableBody">
                    <?php if (empty($history)): ?>
                        <tr><td colspan="7" class="p-12 text-center text-slate-500 italic flex flex-col items-center justify-center w-full col-span-7"><i data-lucide="search-x" class="w-10 h-10 mb-2 opacity-50"></i>No se encontraron resultados coincidentes.</td></tr>
                    <?php else: ?>
                        <?php $counter = $offset + 1; foreach ($history as $h): ?>
                        <tr class="transition">
                            <td class="p-5 pl-6 font-bold" style="color:var(--ink-3);"><?= $counter++ ?></td>
                            <td class="p-5 font-mono text-xs whitespace-nowrap" style="color:var(--ink-2);"><?= date('d/m/Y', strtotime($h['created_at'])) ?></td>
                            <td class="p-5">
                                <div class="font-bold" style="color:var(--ink);"><?= htmlspecialchars($h['client_name']) ?></div>
                                <div class="text-[10px] font-mono uppercase tracking-tighter" style="color:var(--ink-3);"><?= htmlspecialchars($h['client_dni']) ?></div>
                            </td>
                            <td class="p-5">
                                <div class="text-xs font-medium" style="color:var(--ink);"><?= htmlspecialchars($h['item']) ?></div>
                                <div class="text-[10px] italic" style="color:var(--ink-3);"><?= htmlspecialchars($h['seller_name']) ?></div>
                            </td>
                            <td class="p-5">
                                <?= status_badge($h['status']) ?>
                            </td>
                            <td class="p-5">
                                <?php if ($h['status'] === 'entregado'): ?>
                                    <div class="text-[10px]" style="color:var(--ink-2);">
                                        <span class="block font-bold mb-0.5 uppercase tracking-tighter" style="color:var(--ent-ink);">Entregado por:</span>
                                        <span class="flex items-center gap-1"><i data-lucide="user-check" class="w-2.5 h-2.5"></i> <?= htmlspecialchars($h['delivered_by_name'] ?? 'S/D') ?></span>
                                        <span class="block mt-0.5 font-mono" style="color:var(--ink-3);"><?= !empty($h['delivered_at']) ? date('d/m/Y', strtotime($h['delivered_at'])) : '-' ?></span>
                                        <?php if (!empty($h['approved_by_name'])): ?>
                                        <span class="block font-bold mt-1.5 mb-0.5 uppercase tracking-tighter" style="color:#7c3aed;">Aprobado por:</span>
                                        <span class="flex items-center gap-1"><i data-lucide="shield-check" class="w-2.5 h-2.5"></i> <?= htmlspecialchars($h['approved_by_name']) ?></span>
                                        <?php if (!empty($h['approved_at'])): ?>
                                        <span class="block mt-0.5 font-mono" style="color:var(--ink-3);"><?= date('d/m/Y', strtotime($h['approved_at'])) ?></span>
                                        <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="text-[10px] max-w-[200px]" style="color:var(--ink-2);">
                                        <span class="block font-bold mb-0.5 uppercase tracking-tighter" style="color:var(--rec-ink);">Rechazado por:</span>
                                        <span class="flex items-center gap-1 mb-1"><i data-lucide="shield-alert" class="w-2.5 h-2.5"></i> <?= htmlspecialchars($h['rejected_by_name'] ?? 'Admin') ?></span>
                                        <div class="italic pl-2 line-clamp-2" style="color:var(--ink-3);border-left:2px solid var(--rev-bg);">"<?= htmlspecialchars($h['rejected_reason'] ?? 'Sin motivo') ?>"</div>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="p-5 text-right">
                                <div class="flex justify-end gap-2 items-center">
                                    <a href="ver_ficha.php?id=<?= $h['id'] ?>" class="inline-flex items-center justify-center w-11 h-11 rounded-lg transition" style="background:var(--accent-soft);color:var(--accent-ink);border:1.5px solid rgba(99,102,241,.2);" title="Ver Ficha" onmouseover="this.style.background='var(--accent)';this.style.color='#fff'" onmouseout="this.style.background='var(--accent-soft)';this.style.color='var(--accent-ink)'">
                                        <i data-lucide="eye" class="w-4 h-4"></i>
                                    </a>
                                    <?php if ($h['status'] === 'rechazado' && in_array($role, ['admin', 'supervisor'])): ?>
                                    <form method="POST" action="update_status.php" class="inline" onsubmit="return confirm('¿Devolver esta venta al panel de revisión?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= $h['id'] ?>">
                                        <input type="hidden" name="status" value="revision">
                                        <button type="submit" class="inline-flex items-center justify-center w-11 h-11 rounded-lg transition" style="background:var(--rev-bg);color:var(--rev-ink);border:1.5px solid rgba(138,90,8,.2);" title="Volver a Revisión" onmouseover="this.style.background='var(--rev-ink)';this.style.color='#fff'" onmouseout="this.style.background='var(--rev-bg)';this.style.color='var(--rev-ink)'">
                                            <i data-lucide="rotate-ccw" class="w-4 h-4"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Contenedor de Paginación -->
        <div id="paginationContainer">
            <?= renderPagination($total_registros, $registros_por_pagina, $pagina_actual, $filtrosParams); ?>
        </div>
    </div>
</main>

<script>
    // 1. Lógica de Búsqueda AJAX
    let typingTimer;
    let historyData = <?= json_encode($pdfData) ?>; // Cargamos datos iniciales para el PDF
    
    const searchInput = document.getElementById('searchInput');
    const filterForm = document.getElementById('filterForm');
    const tableBody = document.getElementById('historyTableBody');
    const paginationContainer = document.getElementById('paginationContainer');

    searchInput.addEventListener('input', function () {
        clearTimeout(typingTimer);
        typingTimer = setTimeout(performSearch, 400); // 400ms de espera después de escribir
    });

    function performSearch() {
        const formData = new FormData(filterForm);
        const params = new URLSearchParams(formData);
        params.append('ajax', '1');

        // Actualizamos la URL visualmente (opcional pero recomendado para UX)
        const currentUrl = new URL(window.location.href);
        formData.forEach((value, key) => currentUrl.searchParams.set(key, value));
        currentUrl.searchParams.delete('page'); // Al buscar volvemos a pág 1
        window.history.replaceState({}, '', currentUrl);

        fetch('historial_ventas.php?' + params.toString())
            .then(res => res.json())
            .then(data => {
                tableBody.innerHTML = data.html;
                paginationContainer.innerHTML = data.pagination;
                historyData = data.pdfData; // Actualizamos los datos para el PDF
                lucide.createIcons();
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

        // Cabecera del Documento
        doc.setFontSize(18);
        doc.setFont("helvetica", "bold");
        doc.text("Auditoría de Ventas (Historial)", pageWidth / 2, 15, { align: 'center' });
        
        doc.setFontSize(10);
        doc.setFont("helvetica", "normal");
        const rangeText = "Periodo: <?= date('d/m/Y', strtotime($start)) ?> al <?= date('d/m/Y', strtotime($end)) ?>";
        doc.text(rangeText, pageWidth / 2, 22, { align: 'center' });

        // Tabla de datos
        const tableBody = historyData.map((row, i) => [
            i + 1,
            row.date,
            row.client,
            row.item,
            row.seller,
            row.status,
            row.audit
        ]);

        doc.autoTable({
            head: [['#', 'Fecha', 'Cliente', 'Artículo', 'Vendedor', 'Estado', 'Detalle Auditoría']],
            body: tableBody,
            startY: 30,
            theme: 'grid',
            styles: { fontSize: 7, cellPadding: 2, textColor: [0, 0, 0] },
            headStyles: { fillColor: [240, 240, 240], textColor: [0, 0, 0], fontStyle: 'bold' },
            columnStyles: {
                0: { cellWidth: 7 }, 1: { cellWidth: 18 }, 2: { cellWidth: 35 }, 
                3: { cellWidth: 35 }, 4: { cellWidth: 25 }, 5: { cellWidth: 20 }, 
                6: { cellWidth: 'auto' }
            },
            margin: { top: 30, left: 14, right: 14 }
        });

        const fileName = `Historial_Ventas_${new Date().toISOString().slice(0,10)}.pdf`;
        window.open(doc.output('bloburl'), '_blank');
    }
</script>

<?php include 'includes/footer.php'; ?>