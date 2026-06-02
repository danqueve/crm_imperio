<?php
require 'includes/db.php';
require_once 'includes/functions.php';

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'supervisor', 'verificador', 'entregador'])) {
    header("Location: dashboard.php");
    exit;
}

$role = $_SESSION['role'];
$can_view_profile = in_array($role, ['admin', 'supervisor', 'verificador']);

// --- TAB ---
$tab          = (isset($_GET['tab']) && $_GET['tab'] === 'entregadas') ? 'entregadas' : 'pendientes';
$statusFilter = ($tab === 'pendientes') ? 'aprobado' : 'entregado';
$dateField    = ($tab === 'pendientes') ? 'sales.created_at' : 'sales.delivered_at';

// --- LOCALIDADES (ordenadas por cantidad de pedidos del tab activo) ---
$stmtLoc = $pdo->prepare(
    "SELECT client_locality, COUNT(*) as total
     FROM sales
     WHERE status = :status AND client_locality != ''
     GROUP BY client_locality
     ORDER BY total DESC"
);
$stmtLoc->execute([':status' => $statusFilter]);
$localidades_con_conteo = $stmtLoc->fetchAll(PDO::FETCH_ASSOC);
$localidades_disponibles = array_column($localidades_con_conteo, 'client_locality');

// --- FILTROS ---
$filter_locality = isset($_GET['locality']) && in_array($_GET['locality'], $localidades_disponibles) ? $_GET['locality'] : '';
$filter_search   = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_from     = isset($_GET['date_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_from']) ? $_GET['date_from'] : '';
$filter_to       = isset($_GET['date_to'])   && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_to'])   ? $_GET['date_to']   : '';

// --- ORDENAMIENTO ---
$sort_map     = ['fecha' => $dateField, 'cliente' => 'sales.client_name', 'localidad' => 'sales.client_locality', 'monto' => 'sales.total_amount'];
$sort_key     = (isset($_GET['sort']) && array_key_exists($_GET['sort'], $sort_map)) ? $_GET['sort'] : null;
$sort_dir     = (isset($_GET['dir']) && $_GET['dir'] === 'desc') ? 'DESC' : 'ASC';
$orderBy      = $sort_key
    ? "ORDER BY " . $sort_map[$sort_key] . " $sort_dir"
    : (($tab === 'pendientes') ? "ORDER BY sales.created_at ASC" : "ORDER BY sales.delivered_at DESC");

// --- PAGINACIÓN ---
$registros_por_pagina = 15;
$pagina_actual = max(1, (int)($_GET['page'] ?? 1));
$offset = ($pagina_actual - 1) * $registros_por_pagina;

// --- CONTADORES DE TABS (unificado, solo filtro localidad) ---
$localityWhere = $filter_locality ? " AND client_locality = :locality_count" : "";
$stmtCounts = $pdo->prepare(
    "SELECT SUM(CASE WHEN status='aprobado' THEN 1 ELSE 0 END) as pendientes,
            SUM(CASE WHEN status='entregado' THEN 1 ELSE 0 END) as entregadas
     FROM sales WHERE status IN ('aprobado','entregado')" . $localityWhere
);
if ($filter_locality) $stmtCounts->bindValue(':locality_count', $filter_locality);
$stmtCounts->execute();
$counts = $stmtCounts->fetch(PDO::FETCH_ASSOC);
$total_pendientes = (int)($counts['pendientes'] ?? 0);
$total_entregadas = (int)($counts['entregadas'] ?? 0);

// --- WHERE PRINCIPAL (todos los filtros) ---
$conditions  = ["sales.status = :status"];
$queryParams = [':status' => $statusFilter];

if ($filter_locality) {
    $conditions[]           = "sales.client_locality = :locality";
    $queryParams[':locality'] = $filter_locality;
}
if ($filter_search !== '') {
    $conditions[]           = "(sales.client_name LIKE :search OR sales.client_dni LIKE :search2)";
    $queryParams[':search']  = '%' . $filter_search . '%';
    $queryParams[':search2'] = '%' . $filter_search . '%';
}
if ($filter_from) {
    $conditions[]               = "$dateField >= :date_from";
    $queryParams[':date_from']   = $filter_from;
}
if ($filter_to) {
    $conditions[]               = "$dateField <= :date_to";
    $queryParams[':date_to']     = $filter_to . ' 23:59:59';
}

$whereClause = "WHERE " . implode(" AND ", $conditions);

$baseJoin = "FROM sales
             JOIN users ON sales.user_id = users.id
             LEFT JOIN users AS deliverer ON sales.delivered_by = deliverer.id";

// --- TOTAL PARA PAGINACIÓN ---
$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM sales $whereClause");
foreach ($queryParams as $k => $v) $stmtCount->bindValue($k, $v);
$stmtCount->execute();
$total_registros = (int)$stmtCount->fetchColumn();

// --- QUERY PANTALLA (paginado) ---
$sql = "SELECT sales.*, users.name as seller_name, deliverer.name as deliverer_name
        $baseJoin
        $whereClause
        $orderBy
        LIMIT :offset, :limit";
$stmt = $pdo->prepare($sql);
foreach ($queryParams as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->bindValue(':limit',  $registros_por_pagina, PDO::PARAM_INT);
$stmt->execute();
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- QUERY PDF (sin paginación) ---
$sqlFull = "SELECT sales.*, users.name as seller_name, deliverer.name as deliverer_name
            $baseJoin
            $whereClause
            $orderBy";
$stmtFull = $pdo->prepare($sqlFull);
foreach ($queryParams as $k => $v) $stmtFull->bindValue($k, $v);
$stmtFull->execute();
$allOrders = $stmtFull->fetchAll(PDO::FETCH_ASSOC);

// --- DATOS PDF ---
$pdfData = array_map(function($order) {
    return [
        'id'             => $order['id'],
        'raw_date'       => $order['created_at'],
        'raw_delivered'  => $order['delivered_at'],
        'date_loaded'    => date('d/m/Y', strtotime($order['created_at'])),
        'date_delivered' => $order['delivered_at'] ? date('d/m/Y', strtotime($order['delivered_at'])) : '-',
        'client'         => $order['client_name'],
        'address'        => $order['client_address'] . ' (' . $order['client_locality'] . ')',
        'locality'       => $order['client_locality'],
        'phone'          => $order['client_whatsapp'],
        'item'           => $order['item'],
        'seller'         => $order['seller_name'],
        'deliverer'      => $order['deliverer_name'] ?? '-',
        'status'         => ucfirst($order['status']),
    ];
}, $allOrders);

// --- HELPER: URL de sort preservando filtros actuales ---
function sortUrl(string $col, string $currentSort, string $currentDir, string $tab, string $locality, string $search, string $from, string $to): string {
    $dir = ($currentSort === $col && $currentDir === 'ASC') ? 'desc' : 'asc';
    $p   = array_filter(['tab' => $tab, 'sort' => $col, 'dir' => $dir, 'locality' => $locality, 'search' => $search, 'date_from' => $from, 'date_to' => $to]);
    return 'entregas.php?' . http_build_query($p);
}
function sortIcon(string $col, ?string $currentSort, string $currentDir): string {
    if ($currentSort !== $col) return '<i data-lucide="chevrons-up-down" class="w-3 h-3 opacity-30"></i>';
    return $currentDir === 'ASC'
        ? '<i data-lucide="chevron-up" class="w-3 h-3 text-blue-500"></i>'
        : '<i data-lucide="chevron-down" class="w-3 h-3 text-blue-500"></i>';
}

// --- EXTRA PARAMS para paginación ---
$extraParams = array_filter(['tab' => $tab, 'locality' => $filter_locality, 'search' => $filter_search, 'date_from' => $filter_from, 'date_to' => $filter_to, 'sort' => $sort_key, 'dir' => $sort_key ? strtolower($sort_dir) : null]);

$hasFilters = $filter_locality || $filter_search || $filter_from || $filter_to;

include 'includes/header.php';
?>

<!-- Librerías PDF -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.29/jspdf.plugin.autotable.min.js"></script>

<main class="flex-1 max-w-7xl mx-auto w-full p-4 sm:p-6 lg:p-8 fade-in">

    <!-- Header -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
        <div class="flex items-center gap-4">
            <a href="dashboard.php" class="p-2 rounded-full transition" style="background:var(--card);border:1.5px solid var(--line);color:var(--ink-2);">
                <i data-lucide="chevron-left"></i>
            </a>
            <div>
                <h1 class="text-2xl font-bold tracking-tight" style="color:var(--ink);">Gestión de Entregas</h1>
                <p class="text-sm font-medium" style="color:var(--ink-2);">Control de logística y despachos.</p>
            </div>
        </div>
        <?php if ($tab === 'pendientes'): ?>
        <button onclick="exportarPDF('pendientes')" class="bg-blue-600 hover:bg-blue-500 text-white px-4 py-2.5 rounded-xl transition flex items-center gap-2 text-sm font-bold shadow-lg shadow-blue-900/30 shrink-0">
            <i data-lucide="file-text" class="w-4 h-4"></i> Exportar PDF
        </button>
        <?php else: ?>
        <button onclick="exportarPDF('entregados')" class="bg-emerald-600 hover:bg-emerald-500 text-white px-4 py-2.5 rounded-xl transition flex items-center gap-2 text-sm font-bold shadow-lg shadow-emerald-900/30 shrink-0">
            <i data-lucide="file-text" class="w-4 h-4"></i> Exportar PDF
        </button>
        <?php endif; ?>
    </div>

    <!-- Tabs -->
    <?php
    $tabLocality = $filter_locality ? '&locality=' . urlencode($filter_locality) : '';
    ?>
    <div class="flex gap-1 rounded-2xl p-1.5 mb-6 w-fit shadow-lg" style="background:var(--card);border:1.5px solid var(--line);">
        <a href="entregas.php?tab=pendientes<?= $tabLocality ?>"
           class="flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-bold transition <?= $tab === 'pendientes' ? 'bg-blue-600 text-white shadow-md' : 'hover:bg-blue-50' ?>"
           <?= $tab !== 'pendientes' ? 'style="color:var(--ink-2);"' : '' ?>>
            <i data-lucide="package" class="w-4 h-4"></i>
            Pendientes
            <span class="text-[10px] font-bold px-2 py-0.5 rounded-full <?= $tab === 'pendientes' ? 'bg-blue-500/40' : '' ?>"
                  <?= $tab !== 'pendientes' ? 'style="background:var(--paper);color:' . ($total_pendientes > 0 ? '#b45309' : 'var(--ink-3)') . ';"' : '' ?>>
                <?= $total_pendientes ?>
            </span>
        </a>
        <a href="entregas.php?tab=entregadas<?= $tabLocality ?>"
           class="flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-bold transition <?= $tab === 'entregadas' ? 'bg-emerald-600 text-white shadow-md' : 'hover:bg-emerald-50' ?>"
           <?= $tab !== 'entregadas' ? 'style="color:var(--ink-2);"' : '' ?>>
            <i data-lucide="check-circle" class="w-4 h-4"></i>
            Entregadas
            <span class="text-[10px] font-bold px-2 py-0.5 rounded-full <?= $tab === 'entregadas' ? 'bg-emerald-500/40' : '' ?>"
                  <?= $tab !== 'entregadas' ? 'style="background:var(--paper);color:var(--ink-3);"' : '' ?>>
                <?= $total_entregadas ?>
            </span>
        </a>
    </div>

    <!-- Filtros -->
    <form method="GET" class="rounded-2xl p-4 mb-6 shadow-sm" style="background:var(--card);border:1.5px solid var(--line);">
        <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 mb-3">
            <!-- Búsqueda -->
            <div>
                <label class="block text-[10px] font-bold uppercase mb-1.5 ml-1 tracking-widest" style="color:var(--ink-3);">Buscar cliente</label>
                <div class="relative">
                    <i data-lucide="search" class="w-3.5 h-3.5 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" style="color:var(--ink-3);"></i>
                    <input type="text" name="search" value="<?= htmlspecialchars($filter_search) ?>" placeholder="Nombre o DNI..."
                           class="input-light w-full rounded-xl pl-9 pr-4 py-2.5 transition text-sm">
                </div>
            </div>
            <!-- Localidad -->
            <div>
                <label class="block text-[10px] font-bold uppercase mb-1.5 ml-1 tracking-widest" style="color:var(--ink-3);">Localidad</label>
                <select name="locality" class="input-light w-full rounded-xl px-4 py-2.5 transition text-sm">
                    <option value="">Todas las localidades</option>
                    <?php foreach ($localidades_con_conteo as $loc_row): ?>
                    <option value="<?= htmlspecialchars($loc_row['client_locality']) ?>" <?= $filter_locality === $loc_row['client_locality'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($loc_row['client_locality']) ?> (<?= $loc_row['total'] ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <!-- Desde -->
            <div>
                <label class="block text-[10px] font-bold uppercase mb-1.5 ml-1 tracking-widest" style="color:var(--ink-3);">
                    <?= $tab === 'pendientes' ? 'Cargado desde' : 'Entregado desde' ?>
                </label>
                <input type="date" name="date_from" value="<?= htmlspecialchars($filter_from) ?>"
                       class="input-light w-full rounded-xl px-4 py-2.5 transition text-sm">
            </div>
            <!-- Hasta -->
            <div>
                <label class="block text-[10px] font-bold uppercase mb-1.5 ml-1 tracking-widest" style="color:var(--ink-3);">
                    <?= $tab === 'pendientes' ? 'Cargado hasta' : 'Entregado hasta' ?>
                </label>
                <input type="date" name="date_to" value="<?= htmlspecialchars($filter_to) ?>"
                       class="input-light w-full rounded-xl px-4 py-2.5 transition text-sm">
            </div>
        </div>
        <div class="flex gap-2 justify-end">
            <?php if ($hasFilters): ?>
            <a href="entregas.php?tab=<?= $tab ?>" class="px-4 py-2 rounded-xl font-bold text-sm transition flex items-center gap-1.5" style="background:var(--paper);color:var(--ink-2);border:1.5px solid var(--line);">
                <i data-lucide="x" class="w-4 h-4"></i> Limpiar
            </a>
            <?php endif; ?>
            <button type="submit" class="bg-indigo-600 hover:bg-indigo-500 text-white px-5 py-2 rounded-xl font-bold text-sm transition flex items-center gap-2">
                <i data-lucide="filter" class="w-4 h-4"></i> Filtrar
            </button>
        </div>
    </form>

    <!-- Tabla -->
    <div class="rounded-2xl overflow-hidden flex flex-col min-h-[500px]" style="background:var(--card);border:1.5px solid var(--line);box-shadow:var(--shadow-card);">
        <div class="overflow-x-auto flex-1">
            <table class="table-light w-full text-left text-sm border-collapse">
                <thead>
                    <tr>
                        <th class="p-4 pl-5 hidden sm:table-cell w-10">#</th>
                        <th class="p-4 hidden sm:table-cell w-16">ID</th>
                        <?php if ($tab === 'entregadas'): ?>
                        <th class="p-4 whitespace-nowrap">
                            <a href="<?= sortUrl('fecha', $sort_key ?? '', $sort_dir, $tab, $filter_locality, $filter_search, $filter_from, $filter_to) ?>"
                               class="flex items-center gap-1 transition hover:opacity-70">
                                Fechas <?= sortIcon('fecha', $sort_key, $sort_dir) ?>
                            </a>
                        </th>
                        <?php endif; ?>
                        <th class="p-4">
                            <a href="<?= sortUrl('cliente', $sort_key ?? '', $sort_dir, $tab, $filter_locality, $filter_search, $filter_from, $filter_to) ?>"
                               class="flex items-center gap-1 transition hover:opacity-70">
                                Cliente <?= sortIcon('cliente', $sort_key, $sort_dir) ?>
                            </a>
                        </th>
                        <th class="p-4">Artículo / Monto</th>
                        <th class="p-4 hidden lg:table-cell">Vendedor</th>
                        <th class="p-4">
                            <?php if ($tab === 'pendientes'): ?>
                                <a href="<?= sortUrl('fecha', $sort_key ?? '', $sort_dir, $tab, $filter_locality, $filter_search, $filter_from, $filter_to) ?>"
                                   class="flex items-center gap-1 transition hover:opacity-70">
                                    Espera <?= sortIcon('fecha', $sort_key, $sort_dir) ?>
                                </a>
                            <?php else: ?>
                                Entregado por
                            <?php endif; ?>
                        </th>
                        <th class="p-4 text-right">Gestión</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($orders)): ?>
                        <tr>
                            <td colspan="8" class="p-20 text-center">
                                <div class="flex flex-col items-center gap-4">
                                    <div class="p-5 rounded-2xl" style="background:var(--paper);border:1.5px solid var(--line);">
                                        <i data-lucide="<?= $tab === 'pendientes' ? 'package' : 'check-circle' ?>" class="w-10 h-10" style="color:var(--ink-3);"></i>
                                    </div>
                                    <div>
                                        <p class="font-semibold text-base" style="color:var(--ink-2);">
                                            <?= $tab === 'pendientes' ? 'No hay pedidos pendientes de entrega' : 'Aún no hay entregas registradas' ?>
                                        </p>
                                        <?php if ($hasFilters): ?>
                                        <p class="text-sm mt-1" style="color:var(--ink-3);">Probá ajustando los filtros aplicados.</p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php $counter = $offset + 1; foreach ($orders as $order): ?>
                        <?php
                            // Indicador de antigüedad (solo Pendientes)
                            $refDate    = !empty($order['approved_at']) ? $order['approved_at'] : $order['created_at'];
                            $days_wait  = (int)floor((time() - strtotime($refDate)) / 86400);
                            if ($days_wait === 0)      { $waitClass = 'bg-emerald-500/10 text-emerald-600 border-emerald-500/20'; $waitLabel = 'Hoy'; }
                            elseif ($days_wait <= 2)   { $waitClass = 'bg-yellow-500/10 text-yellow-600 border-yellow-500/20';   $waitLabel = $days_wait . ' día' . ($days_wait > 1 ? 's' : ''); }
                            else                       { $waitClass = 'bg-red-500/10 text-red-600 border-red-500/20';            $waitLabel = $days_wait . ' días'; }
                        ?>
                        <tr class="transition-colors group <?= ($tab === 'pendientes' && $days_wait > 3) ? 'border-l-2 border-l-red-400/60' : '' ?>" style="border-bottom:1px dashed var(--line);">
                            <td class="p-4 pl-5 font-bold hidden sm:table-cell" style="color:var(--ink-3);"><?= $counter++ ?></td>
                            <td class="p-4 font-mono text-xs hidden sm:table-cell" style="color:var(--ink-3);">#<?= $order['id'] ?></td>

                            <?php if ($tab === 'entregadas'): ?>
                            <!-- Fechas (solo en tab Entregadas) -->
                            <td class="p-4 whitespace-nowrap" style="color:var(--ink-2);">
                                <div class="flex flex-col gap-1">
                                    <div class="flex items-center gap-1.5">
                                        <span class="text-[9px] px-1.5 py-0.5 rounded font-bold uppercase" style="background:var(--paper);color:var(--ink-3);">Carga</span>
                                        <span class="text-xs"><?= date('d/m/Y', strtotime($order['created_at'])) ?></span>
                                    </div>
                                    <div class="flex items-center gap-1.5">
                                        <span class="text-[9px] px-1.5 py-0.5 rounded font-bold uppercase" style="background:var(--apr-bg);color:var(--apr-ink);">Entrega</span>
                                        <span class="text-xs" style="color:var(--apr-ink);"><?= !empty($order['delivered_at']) ? date('d/m/Y', strtotime($order['delivered_at'])) : '-' ?></span>
                                    </div>
                                </div>
                            </td>
                            <?php endif; ?>

                            <!-- Cliente + Dirección + Localidad -->
                            <td class="p-4">
                                <div class="font-bold" style="color:var(--ink);"><?= htmlspecialchars($order['client_name']) ?></div>
                                <div class="text-xs flex items-center gap-1 mt-0.5" style="color:var(--ink-3);">
                                    <i data-lucide="map-pin" class="w-3 h-3 shrink-0" style="color:var(--ink-3);"></i>
                                    <?= htmlspecialchars($order['client_address']) ?>
                                </div>
                                <div class="flex items-center gap-1.5 mt-1">
                                    <span class="text-[10px] font-bold px-2 py-0.5 rounded-full" style="background:var(--paper);color:var(--ink-2);border:1px solid var(--line);">
                                        <?= htmlspecialchars($order['client_locality']) ?>
                                    </span>
                                </div>
                                <?php if (!empty($order['client_map_link'])): ?>
                                <a href="<?= htmlspecialchars($order['client_map_link']) ?>" target="_blank"
                                   class="text-[10px] text-blue-500 hover:text-blue-600 flex items-center gap-1 mt-1 transition">
                                    <i data-lucide="external-link" class="w-2.5 h-2.5"></i> Ver en Maps
                                </a>
                                <?php endif; ?>
                                <?php if ($tab === 'pendientes'): ?>
                                <div class="text-[10px] mt-0.5 sm:hidden" style="color:var(--ink-3);">Carga: <?= date('d/m/Y', strtotime($order['created_at'])) ?></div>
                                <?php endif; ?>
                            </td>

                            <!-- Artículo / Monto -->
                            <td class="p-4">
                                <div class="font-medium" style="color:var(--accent);"><?= htmlspecialchars($order['item']) ?></div>
                                <div class="flex items-center gap-2 mt-1 flex-wrap">
                                    <span class="text-xs font-bold" style="color:var(--apr-ink);">$<?= number_format($order['total_amount'], 0, ',', '.') ?></span>
                                    <span class="text-[10px] italic" style="color:var(--ink-3);"><?= $order['installments_count'] ?> x $<?= number_format($order['installment_amount'], 0, ',', '.') ?></span>
                                </div>
                            </td>

                            <!-- Vendedor (oculto en mobile) -->
                            <td class="p-4 hidden lg:table-cell" style="color:var(--ink-2);">
                                <?php if ($can_view_profile): ?>
                                    <a href="perfil_vendedor.php?id=<?= $order['user_id'] ?>" class="hover:text-blue-500 hover:underline transition flex items-center gap-1 group/seller">
                                        <?= htmlspecialchars($order['seller_name']) ?>
                                        <i data-lucide="external-link" class="w-3 h-3 opacity-0 group-hover/seller:opacity-100 transition-opacity"></i>
                                    </a>
                                <?php else: ?>
                                    <?= htmlspecialchars($order['seller_name']) ?>
                                <?php endif; ?>
                            </td>

                            <!-- Días de espera (Pendientes) / Entregado por (Entregadas) -->
                            <td class="p-4">
                                <?php if ($tab === 'pendientes'): ?>
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-bold uppercase border <?= $waitClass ?>">
                                        <i data-lucide="clock" class="w-3 h-3"></i> <?= $waitLabel ?>
                                    </span>
                                <?php else: ?>
                                    <?php if (!empty($order['deliverer_name'])): ?>
                                    <div class="flex items-center gap-1.5 text-xs" style="color:var(--ink-2);">
                                        <i data-lucide="user-check" class="w-3.5 h-3.5 shrink-0" style="color:var(--apr-ink);"></i>
                                        <?= htmlspecialchars($order['deliverer_name']) ?>
                                    </div>
                                    <?php else: ?>
                                    <span class="text-xs" style="color:var(--ink-3);">—</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>

                            <!-- Gestión -->
                            <td class="p-4 text-right">
                                <div class="flex justify-end gap-1.5 items-center">
                                    <a href="ver_ficha.php?id=<?= $order['id'] ?>"
                                       class="w-11 h-11 flex items-center justify-center rounded-lg hover:bg-blue-600 hover:text-white transition shadow-sm" style="background:var(--paper);color:var(--accent);border:1.5px solid var(--line);" title="Ver Ficha">
                                        <i data-lucide="search" class="w-4 h-4"></i>
                                    </a>

                                    <?php if ($order['status'] === 'aprobado'): ?>
                                        <form method="POST" action="update_status.php" class="inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= $order['id'] ?>">
                                            <input type="hidden" name="status" value="entregado">
                                            <button type="submit"
                                                    class="bg-blue-600 hover:bg-blue-500 text-white px-3 py-2 rounded-lg text-xs font-bold flex items-center gap-1.5 transition shadow-lg shadow-blue-900/30 <?= $days_wait > 3 ? 'ring-1 ring-red-400/50' : '' ?>">
                                                <i data-lucide="truck" class="w-3.5 h-3.5"></i>
                                                <span class="hidden sm:inline">Entregar</span>
                                            </button>
                                        </form>

                                        <?php if (in_array($role, ['admin', 'supervisor'])): ?>
                                        <form method="POST" action="update_status.php" class="inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= $order['id'] ?>">
                                            <input type="hidden" name="status" value="revision">
                                            <button type="submit" class="w-11 h-11 flex items-center justify-center rounded-lg hover:bg-yellow-500 hover:text-white transition shadow-sm" style="background:var(--rev-bg);color:var(--rev-ink);border:1.5px solid var(--rev-ink);" title="Devolver a Revisión">
                                                <i data-lucide="rotate-ccw" class="w-4 h-4"></i>
                                            </button>
                                        </form>
                                        <a href="rechazar_venta.php?id=<?= $order['id'] ?>"
                                           class="w-11 h-11 flex items-center justify-center rounded-lg hover:bg-red-600 hover:text-white transition shadow-sm" style="background:var(--rec-bg);color:var(--rec-ink);border:1.5px solid var(--rec-ink);" title="Rechazar"
                                           onclick="return confirm('¿Confirma que desea rechazar esta venta?')">
                                            <i data-lucide="x" class="w-4 h-4"></i>
                                        </a>
                                        <?php endif; ?>

                                    <?php else: ?>
                                        <?php if (in_array($role, ['admin', 'supervisor', 'entregador'])): ?>
                                        <form method="POST" action="update_status.php" class="inline"
                                              onsubmit="return confirm('¿Confirma anular la entrega? El pedido volverá a estado pendiente.');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= $order['id'] ?>">
                                            <input type="hidden" name="status" value="aprobado">
                                            <button type="submit" class="w-11 h-11 flex items-center justify-center rounded-lg hover:bg-red-600 hover:text-white transition shadow-sm" style="background:var(--rec-bg);color:var(--rec-ink);border:1.5px solid var(--rec-ink);" title="Anular Entrega">
                                                <i data-lucide="x-circle" class="w-4 h-4"></i>
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div>
            <?= renderPagination($total_registros, $registros_por_pagina, $pagina_actual, $extraParams) ?>
        </div>
    </div>

</main>

<script>
    const allDeliveryData = <?= json_encode($pdfData) ?>;

    function exportarPDF(tipo) {
        const { jsPDF } = window.jspdf;
        const doc       = new jsPDF('p', 'mm', 'a4');
        const pageWidth = doc.internal.pageSize.getWidth();
        const data      = allDeliveryData;

        if (data.length === 0) {
            alert("No hay registros disponibles para generar este reporte.");
            return;
        }

        let title, headRow, columnStyles, tableBody;

        if (tipo === 'pendientes') {
            title        = "Hoja de Ruta - Entregas Pendientes";
            headRow      = [['#', 'ID', 'F. Carga', 'Cliente', 'Dirección', 'Localidad', 'Celular', 'Artículo', 'Vendedor']];
            columnStyles = {
                0: { cellWidth: 6, halign: 'center', fontStyle: 'bold' },
                1: { cellWidth: 8 }, 2: { cellWidth: 16 }, 3: { cellWidth: 24 },
                4: { cellWidth: 35 }, 5: { cellWidth: 22 }, 6: { cellWidth: 20 },
                7: { cellWidth: 30 }, 8: { cellWidth: 18 }
            };
            tableBody = data.map((r, i) => [i + 1, r.id, r.date_loaded, r.client, r.address, r.locality, r.phone, r.item, r.seller]);
        } else {
            title        = "Reporte de Entregas Realizadas";
            headRow      = [['#', 'ID', 'F. Carga', 'F. Entrega', 'Cliente', 'Dirección', 'Localidad', 'Artículo', 'Entregó']];
            columnStyles = {
                0: { cellWidth: 6, halign: 'center', fontStyle: 'bold' },
                1: { cellWidth: 8 }, 2: { cellWidth: 16 }, 3: { cellWidth: 16 },
                4: { cellWidth: 24 }, 5: { cellWidth: 30 }, 6: { cellWidth: 20 },
                7: { cellWidth: 28 }, 8: { cellWidth: 20 }
            };
            tableBody = data.map((r, i) => [i + 1, r.id, r.date_loaded, r.date_delivered, r.client, r.address, r.locality, r.item, r.deliverer]);
        }

        doc.setFontSize(18); doc.setFont("helvetica", "bold");
        doc.text(title, pageWidth / 2, 15, { align: 'center' });
        doc.setFontSize(9); doc.setFont("helvetica", "normal");
        doc.text("Generado: " + new Date().toLocaleDateString() + " " + new Date().toLocaleTimeString(), pageWidth / 2, 22, { align: 'center' });

        doc.autoTable({
            head: headRow, body: tableBody, startY: 28,
            theme: 'grid',
            styles: { fontSize: 7, cellPadding: 2, lineColor: [0,0,0], lineWidth: 0.1, textColor: [0,0,0], overflow: 'linebreak' },
            headStyles: { fillColor: [255,255,255], textColor: [0,0,0], fontStyle: 'bolditalic', lineWidth: 0.1 },
            columnStyles: columnStyles,
            margin: { top: 28, right: 10, bottom: 20, left: 10 }
        });

        window.open(doc.output('bloburl'), '_blank');
    }
</script>

<?php include 'includes/footer.php'; ?>
