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

// --- 1. FILTROS (Fechas y Búsqueda) ---
$start = $_GET['start'] ?? date('Y-m-01'); // Primer día del mes actual
$end = $_GET['end'] ?? date('Y-m-d');
$search = $_GET['search'] ?? ''; 

$startSql = $start . ' 00:00:00';
$endSql = $end . ' 23:59:59';

// Construcción base del WHERE 
$whereClause = "WHERE s.status IN ('rechazado', 'entregado') 
                AND s.created_at BETWEEN :start AND :end";
$params = [':start' => $startSql, ':end' => $endSql];

// Si es vendedor, solo ve su propio historial
if ($role === 'vendedor') {
    $whereClause .= " AND s.user_id = :user_id";
    $params[':user_id'] = $user_id;
}

// Filtro de búsqueda por Nombre o DNI
if (!empty($search)) {
    $whereClause .= " AND (s.client_name LIKE :search OR s.client_dni LIKE :search)";
    $params[':search'] = "%$search%";
}

// Preparamos los parámetros de URL para que la paginación los mantenga
$filtrosParams = ['start' => $start, 'end' => $end, 'search' => $search];

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
                u_del.name as delivered_by_name
            FROM sales s
            LEFT JOIN users u_seller ON s.user_id = u_seller.id
            LEFT JOIN users u_rej ON s.rejected_by = u_rej.id
            LEFT JOIN users u_del ON s.delivered_by = u_del.id
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
                u_del.name as delivered_by_name
            FROM sales s
            LEFT JOIN users u_seller ON s.user_id = u_seller.id
            LEFT JOIN users u_rej ON s.rejected_by = u_rej.id
            LEFT JOIN users u_del ON s.delivered_by = u_del.id
            $whereClause
            ORDER BY s.created_at DESC";

$stmtFull = $pdo->prepare($sqlFull);
foreach ($params as $key => $value) { $stmtFull->bindValue($key, $value); }
$stmtFull->execute();
$allHistory = $stmtFull->fetchAll(PDO::FETCH_ASSOC);

// Preparar array de datos simplificados para que JavaScript genere el PDF
$pdfData = array_map(function($h) {
    $auditDetail = ($h['status'] === 'entregado') 
        ? "Entregado por: " . ($h['delivered_by_name'] ?? 'S/D') . " el " . date('d/m/Y', strtotime($h['delivered_at']))
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
            <tr class="hover:bg-slate-800/30 transition border-b border-slate-800/50 last:border-0">
                <td class="p-5 pl-6 font-bold text-slate-600"><?= $counter++ ?></td>
                <td class="p-5 text-slate-400 font-mono text-xs whitespace-nowrap"><?= date('d/m/Y', strtotime($h['created_at'])) ?></td>
                <td class="p-5">
                    <div class="font-bold text-white"><?= htmlspecialchars($h['client_name']) ?></div>
                    <div class="text-[10px] text-slate-500 font-mono"><?= htmlspecialchars($h['client_dni']) ?></div>
                </td>
                <td class="p-5">
                    <div class="text-slate-300 text-xs font-medium"><?= htmlspecialchars($h['item']) ?></div>
                    <div class="text-[10px] text-slate-500 italic"><?= htmlspecialchars($h['seller_name']) ?></div>
                </td>
                <td class="p-5">
                    <?php if ($h['status'] === 'entregado'): ?>
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-wide border bg-blue-500/10 text-blue-400 border-blue-500/20"><i data-lucide="check-circle" class="w-3 h-3"></i> Entregado</span>
                    <?php else: ?>
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-wide border bg-red-500/10 text-red-400 border-red-500/20"><i data-lucide="x-circle" class="w-3 h-3"></i> Rechazado</span>
                    <?php endif; ?>
                </td>
                <td class="p-5">
                    <?php if ($h['status'] === 'entregado'): ?>
                        <div class="text-[10px] text-slate-400">
                            <span class="block font-bold text-blue-400/80 mb-0.5 uppercase tracking-tighter">Entregado por:</span> 
                            <span class="flex items-center gap-1"><i data-lucide="user-check" class="w-2.5 h-2.5"></i> <?= htmlspecialchars($h['delivered_by_name'] ?? 'S/D') ?></span>
                            <span class="text-slate-600 block mt-0.5 font-mono"><?= date('d/m/Y', strtotime($h['delivered_at'])) ?></span>
                        </div>
                    <?php else: ?>
                        <div class="text-[10px] text-slate-400 max-w-[200px]">
                            <span class="block font-bold text-red-400/80 mb-0.5 uppercase tracking-tighter">Rechazado por:</span> 
                            <span class="flex items-center gap-1 mb-1"><i data-lucide="shield-alert" class="w-2.5 h-2.5"></i> <?= htmlspecialchars($h['rejected_by_name'] ?? 'Admin') ?></span>
                            <div class="italic text-slate-500 border-l-2 border-slate-800 pl-2 line-clamp-2">"<?= htmlspecialchars($h['rejected_reason'] ?? 'Sin motivo') ?>"</div>
                        </div>
                    <?php endif; ?>
                </td>
                <td class="p-5 text-right">
                    <a href="ver_ficha.php?id=<?= $h['id'] ?>" class="inline-flex items-center justify-center p-2 rounded-lg bg-slate-800 hover:bg-blue-600 text-blue-400 hover:text-white transition border border-slate-700 shadow-sm" title="Ver Ficha">
                        <i data-lucide="eye" class="w-4 h-4"></i>
                    </a>
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
        <a href="dashboard.php" class="p-2 bg-slate-800 rounded-full text-slate-400 hover:text-white transition border border-slate-700">
            <i data-lucide="chevron-left"></i>
        </a>
        <div>
            <h1 class="text-2xl font-bold text-white tracking-tight">Historial de Ventas</h1>
            <p class="text-sm text-slate-500">Auditoría completa de ventas finalizadas.</p>
        </div>
    </div>

    <!-- Barra de Filtros -->
    <div class="bg-slate-900 p-5 rounded-2xl border border-slate-800 shadow-lg mb-8">
        <form id="filterForm" class="flex flex-col xl:flex-row gap-4 items-end">
            <div class="flex gap-4 w-full xl:w-auto">
                <div class="flex-1 min-w-[140px]">
                    <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1.5 ml-1 tracking-widest">Desde</label>
                    <input type="date" name="start" value="<?= $start ?>" class="w-full bg-slate-950 border border-slate-700 rounded-xl px-3 py-2.5 text-white focus:border-blue-500 outline-none transition text-sm">
                </div>
                <div class="flex-1 min-w-[140px]">
                    <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1.5 ml-1 tracking-widest">Hasta</label>
                    <input type="date" name="end" value="<?= $end ?>" class="w-full bg-slate-950 border border-slate-700 rounded-xl px-3 py-2.5 text-white focus:border-blue-500 outline-none transition text-sm">
                </div>
            </div>
            <div class="w-full xl:flex-1">
                <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1.5 ml-1 tracking-widest">Buscar Cliente</label>
                <div class="relative">
                    <input type="text" name="search" id="searchInput" value="<?= htmlspecialchars($search) ?>" class="w-full bg-slate-950 border border-slate-700 rounded-xl px-4 py-2.5 pl-10 text-white focus:border-blue-500 outline-none transition text-sm" placeholder="Nombre o DNI del cliente...">
                    <div class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-500"><i data-lucide="search" class="w-4 h-4"></i></div>
                </div>
            </div>
            <div class="flex gap-2 w-full xl:w-auto">
                <button type="submit" class="flex-1 bg-blue-600 hover:bg-blue-500 text-white px-6 py-2.5 rounded-xl font-bold shadow-lg transition flex items-center justify-center gap-2 text-sm">
                    <i data-lucide="filter" class="w-4 h-4"></i> Filtrar
                </button>
                <button type="button" onclick="exportarPDF()" class="bg-rose-600 hover:bg-rose-500 text-white px-6 py-2.5 rounded-xl font-bold shadow-lg transition flex items-center justify-center gap-2 text-sm">
                    <i data-lucide="file-down" class="w-4 h-4"></i> Exportar PDF
                </button>
            </div>
        </form>
    </div>

    <!-- Tabla Principal -->
    <div class="bg-slate-900 rounded-2xl border border-slate-800 shadow-xl overflow-hidden flex flex-col min-h-[500px]">
        <div class="overflow-x-auto flex-1">
            <table class="w-full text-left text-sm">
                <thead class="bg-slate-950/50 text-slate-500 uppercase text-[10px] font-bold tracking-widest border-b border-slate-800">
                    <tr>
                        <th class="p-5 pl-6">#</th>
                        <th class="p-5">Fecha Carga</th>
                        <th class="p-5">Cliente</th>
                        <th class="p-5">Detalle Venta</th>
                        <th class="p-5">Estado</th>
                        <th class="p-5">Auditoría</th>
                        <th class="p-5 text-right">Ficha</th>
                    </tr>
                </thead>
                <tbody id="historyTableBody" class="divide-y divide-slate-800/50">
                    <?php if (empty($history)): ?>
                        <tr><td colspan="7" class="p-12 text-center text-slate-500 italic flex flex-col items-center justify-center w-full col-span-7"><i data-lucide="search-x" class="w-10 h-10 mb-2 opacity-50"></i>No se encontraron resultados coincidentes.</td></tr>
                    <?php else: ?>
                        <?php $counter = $offset + 1; foreach ($history as $h): ?>
                        <tr class="hover:bg-slate-800/30 transition border-b border-slate-800/50 last:border-0">
                            <td class="p-5 pl-6 font-bold text-slate-600"><?= $counter++ ?></td>
                            <td class="p-5 text-slate-400 font-mono text-xs whitespace-nowrap"><?= date('d/m/Y', strtotime($h['created_at'])) ?></td>
                            <td class="p-5">
                                <div class="font-bold text-white"><?= htmlspecialchars($h['client_name']) ?></div>
                                <div class="text-[10px] text-slate-500 font-mono uppercase tracking-tighter"><?= htmlspecialchars($h['client_dni']) ?></div>
                            </td>
                            <td class="p-5">
                                <div class="text-slate-300 text-xs font-medium"><?= htmlspecialchars($h['item']) ?></div>
                                <div class="text-[10px] text-slate-500 italic"><?= htmlspecialchars($h['seller_name']) ?></div>
                            </td>
                            <td class="p-5">
                                <?php if ($h['status'] === 'entregado'): ?>
                                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-wide border bg-blue-500/10 text-blue-400 border-blue-500/20"><i data-lucide="check-circle" class="w-3 h-3"></i> Entregado</span>
                                <?php else: ?>
                                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-wide border bg-red-500/10 text-red-400 border-red-500/20"><i data-lucide="x-circle" class="w-3 h-3"></i> Rechazado</span>
                                <?php endif; ?>
                            </td>
                            <td class="p-5">
                                <?php if ($h['status'] === 'entregado'): ?>
                                    <div class="text-[10px] text-slate-400">
                                        <span class="block font-bold text-blue-400/80 mb-0.5 uppercase tracking-tighter">Entregado por:</span> 
                                        <span class="flex items-center gap-1"><i data-lucide="user-check" class="w-2.5 h-2.5"></i> <?= htmlspecialchars($h['delivered_by_name'] ?? 'S/D') ?></span>
                                        <span class="text-slate-600 block mt-0.5 font-mono"><?= date('d/m/Y', strtotime($h['delivered_at'])) ?></span>
                                    </div>
                                <?php else: ?>
                                    <div class="text-[10px] text-slate-400 max-w-[200px]">
                                        <span class="block font-bold text-red-400/80 mb-0.5 uppercase tracking-tighter">Rechazado por:</span> 
                                        <span class="flex items-center gap-1 mb-1"><i data-lucide="shield-alert" class="w-2.5 h-2.5"></i> <?= htmlspecialchars($h['rejected_by_name'] ?? 'Admin') ?></span>
                                        <div class="italic text-slate-500 border-l-2 border-slate-800 pl-2 line-clamp-2">"<?= htmlspecialchars($h['rejected_reason'] ?? 'Sin motivo') ?>"</div>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="p-5 text-right">
                                <a href="ver_ficha.php?id=<?= $h['id'] ?>" class="inline-flex items-center justify-center p-2 rounded-lg bg-slate-800 hover:bg-blue-600 text-blue-400 hover:text-white transition border border-slate-700 shadow-sm" title="Ver Ficha">
                                    <i data-lucide="eye" class="w-4 h-4"></i>
                                </a>
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