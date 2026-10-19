<?php
require 'includes/db.php';
require_once 'includes/functions.php';

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'supervisor', 'entregador'])) {
    header("Location: dashboard.php");
    exit;
}

$role = $_SESSION['role'];
$can_view_profile = in_array($role, ['admin', 'supervisor']);

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
             LEFT JOIN users AS deliverer ON sales.delivered_by = deliverer.id
             LEFT JOIN users AS verifier ON sales.assigned_verifier_id = verifier.id";

// --- TOTAL PARA PAGINACIÓN ---
$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM sales $whereClause");
foreach ($queryParams as $k => $v) $stmtCount->bindValue($k, $v);
$stmtCount->execute();
$total_registros = (int)$stmtCount->fetchColumn();

// --- QUERY PANTALLA (paginado) ---
$sql = "SELECT sales.*, users.name as seller_name, deliverer.name as deliverer_name, verifier.name as verifier_name
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
$sqlFull = "SELECT sales.*, users.name as seller_name, deliverer.name as deliverer_name, verifier.name as verifier_name
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
        'date_loaded'    => date('d/m/Y H:i', strtotime($order['created_at'])),
        'date_delivered' => $order['delivered_at'] ? date('d/m/Y H:i', strtotime($order['delivered_at'])) : '-',
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
    if ($currentSort !== $col) return ui_icon('chevrons-up-down');
    return $currentDir === 'ASC' ? ui_icon('chevron-up') : ui_icon('chevron-down');
}

// --- HELPER: indicador de antigüedad (usado en la tabla de escritorio y en las tarjetas mobile) ---
function waitInfo(array $order): array {
    $refDate = !empty($order['approved_at']) ? $order['approved_at'] : $order['created_at'];
    $days    = (int)floor((time() - strtotime($refDate)) / 86400);
    if ($days === 0)    return [$days, 'tag', 'Hoy'];
    if ($days <= 2)     return [$days, 'tag', $days . ' día' . ($days > 1 ? 's' : '')];
    return [$days, 'tag', $days . ' días'];
}

// --- EXTRA PARAMS para paginación ---
$extraParams = array_filter(['tab' => $tab, 'locality' => $filter_locality, 'search' => $filter_search, 'date_from' => $filter_from, 'date_to' => $filter_to, 'sort' => $sort_key, 'dir' => $sort_key ? strtolower($sort_dir) : null]);

$hasFilters = $filter_locality || $filter_search || $filter_from || $filter_to;

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
            <h1 class="large-title">Gestión de entregas</h1>
            <p class="subhead">Control de logística y despachos.</p>
        </div>
        <div class="page-head__actions">
            <div style="position:relative" id="zonaFilterWrap">
                <button type="button" onclick="toggleZonaDropdown()" id="zonaFilterBtn" class="btn btn--sm">
                    <?= ui_icon('map-pin') ?> <span id="zonaFilterLabel">Todas las zonas</span> <?= ui_icon('chevron-down') ?>
                </button>
                <div id="zonaFilterDropdown" class="card card--pad" style="display:none;position:absolute;z-index:20;margin-top:6px;width:260px;max-height:260px;overflow-y:auto;right:0">
                    <div style="display:flex;justify-content:space-between;margin-bottom:8px" class="caption">
                        <span>Zonas a exportar</span>
                        <button type="button" onclick="toggleAllZonas(false)" style="background:none;border:0;color:var(--tint-text);cursor:pointer;padding:0">Limpiar</button>
                    </div>
                    <?php foreach ($localidades_con_conteo as $loc_row): ?>
                    <label style="display:flex;align-items:center;gap:8px;padding:6px 2px;cursor:pointer">
                        <input type="checkbox" class="zona-checkbox" value="<?= htmlspecialchars($loc_row['client_locality']) ?>" onchange="updateZonaLabel()" <?= $filter_locality === $loc_row['client_locality'] ? 'checked' : '' ?>>
                        <span class="subhead" style="margin:0"><?= htmlspecialchars($loc_row['client_locality']) ?> <span class="caption">(<?= $loc_row['total'] ?>)</span></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <button type="button" onclick="exportarPDF('<?= $tab === 'pendientes' ? 'pendientes' : 'entregados' ?>')" class="btn btn--primary btn--sm"><?= ui_icon('file-text') ?> Exportar PDF</button>
        </div>
    </div>

    <!-- Tabs -->
    <?php $tabLocality = $filter_locality ? '&locality=' . urlencode($filter_locality) : ''; ?>
    <div role="group" aria-label="Estado" class="segmented" style="width:fit-content">
        <a href="entregas.php?tab=pendientes<?= $tabLocality ?>" class="segmented__item <?= $tab === 'pendientes' ? 'is-active' : '' ?>"><?= ui_icon('package') ?>&nbsp;Pendientes · <?= $total_pendientes ?></a>
        <a href="entregas.php?tab=entregadas<?= $tabLocality ?>" class="segmented__item <?= $tab === 'entregadas' ? 'is-active' : '' ?>"><?= ui_icon('check-circle') ?>&nbsp;Entregadas · <?= $total_entregadas ?></a>
    </div>

    <!-- Filtros -->
    <form method="GET" class="card card--pad only-desktop" style="display:flex;flex-direction:column;gap:12px">
        <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
        <div class="form-grid">
            <div class="field">
                <label class="field__label">Buscar cliente</label>
                <input type="text" name="search" value="<?= htmlspecialchars($filter_search) ?>" placeholder="Nombre o DNI..." class="input">
            </div>
            <div class="field">
                <label class="field__label">Localidad</label>
                <select name="locality" class="select">
                    <option value="">Todas las localidades</option>
                    <?php foreach ($localidades_con_conteo as $loc_row): ?>
                    <option value="<?= htmlspecialchars($loc_row['client_locality']) ?>" <?= $filter_locality === $loc_row['client_locality'] ? 'selected' : '' ?>><?= htmlspecialchars($loc_row['client_locality']) ?> (<?= $loc_row['total'] ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label class="field__label"><?= $tab === 'pendientes' ? 'Cargado desde' : 'Entregado desde' ?></label>
                <input type="date" name="date_from" value="<?= htmlspecialchars($filter_from) ?>" class="input">
            </div>
            <div class="field">
                <label class="field__label"><?= $tab === 'pendientes' ? 'Cargado hasta' : 'Entregado hasta' ?></label>
                <input type="date" name="date_to" value="<?= htmlspecialchars($filter_to) ?>" class="input">
            </div>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end">
            <?php if ($hasFilters): ?>
            <a href="entregas.php?tab=<?= $tab ?>" class="btn"><?= ui_icon('x') ?> Limpiar</a>
            <?php endif; ?>
            <button type="submit" class="btn btn--primary"><?= ui_icon('filter') ?> Filtrar</button>
        </div>
    </form>

    <!-- Filtro rápido de localidad (celular) -->
    <div class="field only-mobile">
        <select name="locality" class="select" onchange="window.location = '?' + new URLSearchParams({tab: '<?= $tab ?>', locality: this.value}).toString()">
            <option value="">Zona: todas las localidades</option>
            <?php foreach ($localidades_con_conteo as $loc_row): ?>
            <option value="<?= htmlspecialchars($loc_row['client_locality']) ?>" <?= $filter_locality === $loc_row['client_locality'] ? 'selected' : '' ?>><?= htmlspecialchars($loc_row['client_locality']) ?> (<?= $loc_row['total'] ?>)</option>
            <?php endforeach; ?>
        </select>
    </div>

    <?php if (empty($orders)): ?>
    <div class="card">
        <div class="empty">
            <?= ui_icon($tab === 'pendientes' ? 'package' : 'check-circle') ?>
            <p class="empty__title"><?= $tab === 'pendientes' ? 'No hay pedidos pendientes de entrega' : 'Aún no hay entregas registradas' ?></p>
            <?php if ($hasFilters): ?><p class="caption">Probá ajustando los filtros aplicados.</p><?php endif; ?>
        </div>
    </div>
    <?php else: ?>

    <!-- Vista celular: tarjetas agrupadas por localidad -->
    <div class="only-mobile" style="display:flex;flex-direction:column;gap:16px">
        <?php
        $lastLocality = null;
        foreach ($orders as $order):
            if ($order['client_locality'] !== $lastLocality) {
                if ($lastLocality !== null) echo '</div></section>';
                $lastLocality = $order['client_locality'];
                $countInLoc = count(array_filter($orders, fn($o) => $o['client_locality'] === $lastLocality));
                echo '<section style="display:flex;flex-direction:column;gap:8px"><h2 class="group__title">' . htmlspecialchars($lastLocality ?: 'Sin localidad') . ' · ' . $countInLoc . '</h2><div style="display:flex;flex-direction:column;gap:12px">';
            }
            [$days_wait, $waitClass, $waitLabel] = waitInfo($order);
            $isOverdue = ($tab === 'pendientes' && $days_wait > 3);
        ?>
        <article class="card card--pad" style="display:flex;flex-direction:column;gap:10px;<?= $isOverdue ? 'border-color:var(--red)' : '' ?>">
            <a href="ver_ficha.php?id=<?= $order['id'] ?>" style="display:flex;gap:12px;text-decoration:none;color:var(--label)">
                <?= ui_avatar($order['client_name']) ?>
                <span style="flex:1;min-width:0;display:flex;flex-direction:column;gap:2px">
                    <span style="display:flex;justify-content:space-between;gap:8px">
                        <span class="cell__title"><?= htmlspecialchars($order['client_name']) ?></span>
                        <span class="tab" style="font-weight:600;flex-shrink:0"><?= ui_money($order['total_amount']) ?></span>
                    </span>
                    <span class="subhead"><?= htmlspecialchars($order['item']) ?></span>
                    <span class="subhead" style="color:var(--label)"><?= htmlspecialchars($order['client_address']) ?></span>
                    <span class="tab caption">
                        <?php if ($tab === 'pendientes'): ?>
                            <span class="tag"><?= ui_icon('clock') ?> <?= $waitLabel ?></span>
                        <?php else: ?>
                            Entregó <?= htmlspecialchars($order['deliverer_name'] ?? 'S/D') ?> · <?= !empty($order['delivered_at']) ? date('d/m/Y', strtotime($order['delivered_at'])) : '-' ?>
                        <?php endif; ?>
                    </span>
                </span>
            </a>
            <div style="display:flex;flex-wrap:wrap;gap:8px">
                <?php if (!empty($order['client_map_link'])): ?>
                <a href="<?= htmlspecialchars($order['client_map_link']) ?>" target="_blank" class="btn" style="flex:1"><?= ui_icon('navigation') ?> Cómo llegar</a>
                <?php endif; ?>
                <?php if ($order['status'] === 'aprobado'): ?>
                    <form method="POST" action="update_status.php" style="flex:1;display:flex">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= $order['id'] ?>">
                        <input type="hidden" name="status" value="entregado">
                        <button type="submit" class="btn btn--primary btn--block"><?= ui_icon('truck') ?> Entregar</button>
                    </form>
                    <?php if (in_array($role, ['admin', 'supervisor'])): ?>
                    <form method="POST" action="update_status.php">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= $order['id'] ?>">
                        <input type="hidden" name="status" value="revision">
                        <button type="submit" class="btn btn--icon" title="Devolver a revisión"><?= ui_icon('rotate-ccw') ?></button>
                    </form>
                    <a href="rechazar_venta.php?id=<?= $order['id'] ?>" class="btn btn--icon" title="Rechazar" onclick="return confirm('¿Confirma que desea rechazar esta venta?')"><?= ui_icon('x') ?></a>
                    <?php endif; ?>
                <?php else: ?>
                    <?php if (in_array($role, ['admin', 'supervisor', 'entregador'])): ?>
                    <form method="POST" action="update_status.php" style="flex:1;display:flex" onsubmit="return confirm('¿Confirma anular la entrega? El pedido volverá a estado pendiente.');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= $order['id'] ?>">
                        <input type="hidden" name="status" value="aprobado">
                        <button type="submit" class="btn btn--danger btn--block"><?= ui_icon('x-circle') ?> Anular entrega</button>
                    </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </article>
        <?php endforeach; echo '</div></section>'; ?>
    </div>

    <!-- Vista PC: tabla -->
    <section class="card only-desktop" style="overflow:hidden">
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th><a href="<?= sortUrl('fecha', $sort_key ?? '', $sort_dir, $tab, $filter_locality, $filter_search, $filter_from, $filter_to) ?>" style="display:flex;align-items:center;gap:4px;color:inherit"><?= $tab === 'pendientes' ? 'Carga / Espera' : 'Fechas' ?> <?= sortIcon('fecha', $sort_key, $sort_dir) ?></a></th>
                        <th><a href="<?= sortUrl('cliente', $sort_key ?? '', $sort_dir, $tab, $filter_locality, $filter_search, $filter_from, $filter_to) ?>" style="display:flex;align-items:center;gap:4px;color:inherit">Cliente <?= sortIcon('cliente', $sort_key, $sort_dir) ?></a></th>
                        <th>Artículo / Monto</th>
                        <th>Vendedor</th>
                        <?php if ($tab === 'entregadas'): ?><th>Entregado por</th><?php endif; ?>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $order): [$days_wait, $waitClass, $waitLabel] = waitInfo($order); $isOverdue = ($tab === 'pendientes' && $days_wait > 3); ?>
                    <tr style="<?= $isOverdue ? 'border-left:3px solid var(--red)' : '' ?>">
                        <td class="tab caption">#<?= $order['id'] ?></td>
                        <td class="tab caption" style="white-space:nowrap">
                            <div>Carga: <?= date('d/m/Y H:i', strtotime($order['created_at'])) ?></div>
                            <?php if ($tab === 'entregadas'): ?>
                            <div style="color:var(--green)">Entrega: <?= !empty($order['delivered_at']) ? date('d/m/Y H:i', strtotime($order['delivered_at'])) : '-' ?></div>
                            <?php else: ?>
                            <span class="tag" style="margin-top:4px"><?= ui_icon('clock') ?> <?= $waitLabel ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="cell__title" style="font-size:var(--fs-headline)"><?= htmlspecialchars($order['client_name']) ?></div>
                            <div class="subhead"><?= htmlspecialchars($order['client_address']) ?></div>
                            <div class="tag" style="margin-top:4px"><?= htmlspecialchars($order['client_locality']) ?></div>
                            <?php if (!empty($order['client_map_link'])): ?>
                            <div><a href="<?= htmlspecialchars($order['client_map_link']) ?>" target="_blank" class="caption"><?= ui_icon('external-link') ?> Ver en Maps</a></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div><?= htmlspecialchars($order['item']) ?></div>
                            <div class="tab subhead" style="font-weight:600;color:var(--label)"><?= ui_money($order['total_amount']) ?></div>
                            <div class="caption" style="font-style:italic"><?= (int)$order['installments_count'] ?> x <?= ui_money($order['installment_amount']) ?></div>
                        </td>
                        <td class="subhead">
                            <?php if ($can_view_profile): ?>
                            <a href="perfil_vendedor.php?id=<?= $order['user_id'] ?>"><?= htmlspecialchars($order['seller_name']) ?></a>
                            <?php else: ?>
                            <?= htmlspecialchars($order['seller_name']) ?>
                            <?php endif; ?>
                            <?php if (!empty($order['verifier_name'])): ?>
                            <div class="caption" style="color:var(--tint-text)">Verificó: <?= htmlspecialchars($order['verifier_name']) ?></div>
                            <?php endif; ?>
                        </td>
                        <?php if ($tab === 'entregadas'): ?>
                        <td class="subhead"><?= htmlspecialchars($order['deliverer_name'] ?? '—') ?></td>
                        <?php endif; ?>
                        <td style="text-align:right">
                            <div style="display:flex;justify-content:flex-end;gap:6px">
                                <a href="ver_ficha.php?id=<?= $order['id'] ?>" class="btn btn--icon btn--sm" title="Ver ficha"><?= ui_icon('eye') ?></a>
                                <?php if ($order['status'] === 'aprobado'): ?>
                                    <form method="POST" action="update_status.php">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= $order['id'] ?>">
                                        <input type="hidden" name="status" value="entregado">
                                        <button type="submit" class="btn btn--primary btn--sm"><?= ui_icon('truck') ?> Entregar</button>
                                    </form>
                                    <?php if (in_array($role, ['admin', 'supervisor'])): ?>
                                    <form method="POST" action="update_status.php">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= $order['id'] ?>">
                                        <input type="hidden" name="status" value="revision">
                                        <button type="submit" class="btn btn--icon btn--sm" title="Devolver a revisión"><?= ui_icon('rotate-ccw') ?></button>
                                    </form>
                                    <a href="rechazar_venta.php?id=<?= $order['id'] ?>" class="btn btn--icon btn--sm" title="Rechazar" onclick="return confirm('¿Confirma que desea rechazar esta venta?')"><?= ui_icon('x') ?></a>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?php if (in_array($role, ['admin', 'supervisor', 'entregador'])): ?>
                                    <form method="POST" action="update_status.php" onsubmit="return confirm('¿Confirma anular la entrega? El pedido volverá a estado pendiente.');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= $order['id'] ?>">
                                        <input type="hidden" name="status" value="aprobado">
                                        <button type="submit" class="btn btn--icon btn--sm" title="Anular entrega"><?= ui_icon('x-circle') ?></button>
                                    </form>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php endif; ?>

    <?= renderPagination($total_registros, $registros_por_pagina, $pagina_actual, $extraParams) ?>
</div>
</main>

<script>
    const allDeliveryData = <?= json_encode($pdfData) ?>;

    function toggleZonaDropdown() {
        const el = document.getElementById('zonaFilterDropdown');
        if (el) el.style.display = (el.style.display === 'none' ? 'block' : 'none');
    }
    function toggleAllZonas(checked) {
        document.querySelectorAll('.zona-checkbox').forEach(cb => cb.checked = checked);
        updateZonaLabel();
    }
    function updateZonaLabel() {
        const zonas = Array.from(document.querySelectorAll('.zona-checkbox:checked')).map(cb => cb.value);
        const label = document.getElementById('zonaFilterLabel');
        if (!label) return;
        if (zonas.length === 0) label.textContent = 'Todas las zonas';
        else if (zonas.length === 1) label.textContent = zonas[0];
        else label.textContent = zonas.length + ' zonas seleccionadas';
    }
    document.addEventListener('DOMContentLoaded', updateZonaLabel);
    document.addEventListener('click', function(e) {
        const wrap = document.getElementById('zonaFilterWrap');
        if (wrap && !wrap.contains(e.target)) {
            const dd = document.getElementById('zonaFilterDropdown');
            if (dd) dd.style.display = 'none';
        }
    });

    function exportarPDF(tipo) {
        const { jsPDF } = window.jspdf;
        const doc       = new jsPDF('p', 'mm', 'a4');
        const pageWidth = doc.internal.pageSize.getWidth();
        const zonas     = Array.from(document.querySelectorAll('.zona-checkbox:checked')).map(cb => cb.value);
        const data      = zonas.length > 0 ? allDeliveryData.filter(r => zonas.includes(r.locality)) : allDeliveryData;

        if (data.length === 0) {
            alert(zonas.length > 0 ? `No hay registros para: ${zonas.join(', ')}.` : "No hay registros disponibles para generar este reporte.");
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

        let headerY = 22;
        if (zonas.length > 0) {
            doc.setFontSize(9); doc.setFont("helvetica", "bolditalic");
            const zonaLines = doc.splitTextToSize('Zonas: ' + zonas.join(', '), pageWidth - 20);
            doc.text(zonaLines, pageWidth / 2, headerY, { align: 'center' });
            headerY += zonaLines.length * 4;
        }

        doc.setFontSize(9); doc.setFont("helvetica", "normal");
        doc.text("Generado: " + new Date().toLocaleDateString() + " " + new Date().toLocaleTimeString(), pageWidth / 2, headerY, { align: 'center' });
        const tableStartY = headerY + 6;

        doc.autoTable({
            head: headRow, body: tableBody, startY: tableStartY,
            theme: 'grid',
            styles: { fontSize: 7, cellPadding: 2, lineColor: [0,0,0], lineWidth: 0.1, textColor: [0,0,0], overflow: 'linebreak' },
            headStyles: { fillColor: [255,255,255], textColor: [0,0,0], fontStyle: 'bolditalic', lineWidth: 0.1 },
            columnStyles: columnStyles,
            margin: { top: tableStartY, right: 10, bottom: 20, left: 10 }
        });

        window.open(doc.output('bloburl'), '_blank');
    }
</script>

<?php include 'includes/footer.php'; ?>
