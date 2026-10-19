<?php
require 'includes/db.php';
require_once 'includes/functions.php';

// SEGURIDAD: Solo Admin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}

$seller_id = $_GET['id'] ?? null;
if (!$seller_id) {
    header("Location: lista_vendedores.php");
    exit;
}

// 1. Obtener Datos del Vendedor
$stmtUser = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmtUser->execute([$seller_id]);
$seller = $stmtUser->fetch(PDO::FETCH_ASSOC);

if (!$seller) die("Vendedor no encontrado.");

// --- 1. FILTROS Y BÚSQUEDA ---
$search = $_GET['search'] ?? '';
$whereClause = "WHERE s.user_id = :seller_id";
$params = [':seller_id' => $seller_id];

if (!empty($search)) {
    $whereClause .= " AND (s.client_name LIKE :search OR s.client_dni LIKE :search)";
    $params[':search'] = "%$search%";
}

// --- 2. LÓGICA DE PAGINACIÓN ---
$registros_por_pagina = 25;
$pagina_actual = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($pagina_actual < 1) $pagina_actual = 1;
$offset = ($pagina_actual - 1) * $registros_por_pagina;

// Contar Total de registros filtrados
$sqlCount = "SELECT COUNT(*) FROM sales s $whereClause";
$stmtCount = $pdo->prepare($sqlCount);
$stmtCount->execute($params);
$total_registros = $stmtCount->fetchColumn();

// --- 3. CONSULTA DE DATOS PAGINADOS ---
$sql = "SELECT s.*, 
               u_rej.name as rejected_by_name,
               u_del.name as delivered_by_name
        FROM sales s
        LEFT JOIN users u_rej ON s.rejected_by = u_rej.id
        LEFT JOIN users u_del ON s.delivered_by = u_del.id
        $whereClause
        ORDER BY s.created_at DESC
        LIMIT :offset, :limit";

$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) { 
    $stmt->bindValue($key, $value); 
}
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->bindValue(':limit', $registros_por_pagina, PDO::PARAM_INT);
$stmt->execute();
$sales = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Parámetros extras para la paginación (mantener búsqueda)
$filtrosParams = ['id' => $seller_id, 'search' => $search];

include 'includes/header.php';
?>

<main class="app-main">
<div class="app-container">
    <div class="page-head">
        <div class="page-head__text">
            <a href="lista_vendedores.php" class="navbar-back"><?= ui_icon('chevron-left') ?> Vendedores</a>
            <h1 class="large-title">Ventas de <?= htmlspecialchars($seller['name']) ?></h1>
            <p class="subhead">Historial completo de ventas cargadas · 25 por página.</p>
        </div>
    </div>

    <form method="GET" class="card card--pad" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end">
        <input type="hidden" name="id" value="<?= $seller_id ?>">
        <div class="field" style="flex:1;min-width:220px"><label class="field__label">Buscar cliente</label><input type="search" name="search" value="<?= htmlspecialchars($search) ?>" class="input" placeholder="Nombre o DNI"></div>
        <button type="submit" class="btn btn--primary"><?= ui_icon('search') ?> Buscar</button>
    </form>

    <section class="only-mobile" style="display:flex;flex-direction:column;gap:8px">
        <h2 class="group__title">Ventas encontradas</h2>
        <?php if (empty($sales)): ?>
        <div class="card"><div class="empty"><?= ui_icon('search-x') ?><p class="empty__title">No se encontraron ventas para la búsqueda</p></div></div>
        <?php else: ?><div class="group__body">
            <?php foreach ($sales as $s): ?>
            <a href="ver_ficha.php?id=<?= $s['id'] ?>" class="cell">
                <span class="cell__body"><span class="cell__title"><?= htmlspecialchars($s['client_name']) ?></span><span class="tab cell__sub"><?= htmlspecialchars($s['item']) ?> · <?= ui_money($s['total_amount']) ?></span><span class="tab cell__meta"><?= date('d/m/Y', strtotime($s['created_at'])) ?> · <?= htmlspecialchars($s['client_dni']) ?></span></span>
                <span class="cell__aside"><?= status_badge($s['status']) ?><span class="cell__chevron"><?= ui_icon('chevron-right') ?></span></span>
            </a>
            <?php endforeach; ?>
        </div><?php endif; ?>
    </section>

    <section class="card only-desktop" style="overflow:hidden">
        <div class="table-wrap"><table class="data-table">
            <thead><tr><th>#</th><th>Fecha</th><th>Cliente</th><th>Artículo</th><th>Estado</th><th>Detalle de auditoría</th><th></th></tr></thead>
            <tbody>
            <?php if (empty($sales)): ?>
                <tr><td colspan="7"><div class="empty"><?= ui_icon('search-x') ?><p class="empty__title">No se encontraron ventas para la búsqueda</p></div></td></tr>
            <?php else: $counter = $offset + 1; foreach ($sales as $s): ?>
                <tr>
                    <td class="tab caption" style="font-weight:700"><?= $counter++ ?></td>
                    <td class="tab caption"><?= date('d/m/Y', strtotime($s['created_at'])) ?></td>
                    <td><span class="cell__title" style="font-size:var(--fs-headline)"><?= htmlspecialchars($s['client_name']) ?></span><span class="tab caption" style="display:block"><?= htmlspecialchars($s['client_dni']) ?></span></td>
                    <td><span><?= htmlspecialchars($s['item']) ?></span><span class="tab caption" style="display:block;color:var(--tint-text);font-weight:700"><?= ui_money($s['total_amount']) ?></span></td>
                    <td><?= status_badge($s['status']) ?></td>
                    <td style="max-width:280px"><div class="caption">
                        <?php if ($s['status'] === 'entregado'): ?>
                            <span style="display:block;font-weight:700;color:var(--green)">Entregó <?= htmlspecialchars($s['delivered_by_name'] ?? 'S/D') ?></span><span class="tab"><?= !empty($s['delivered_at']) ? date('d/m/Y', strtotime($s['delivered_at'])) : '-' ?></span>
                        <?php elseif ($s['status'] === 'rechazado'): ?>
                            <span style="display:block;font-weight:700;color:var(--red)">Rechazó <?= htmlspecialchars($s['rejected_by_name'] ?? 'Admin') ?></span><span class="tag" style="margin:3px 0"><?= htmlspecialchars(rejection_type_label($s['rejected_type'] ?? null)) ?></span><span style="display:block;font-style:italic">“<?= htmlspecialchars($s['rejected_reason'] ?? 'Sin motivo') ?>”</span>
                        <?php elseif ($s['status'] === 'aprobado'): ?>
                            <span>Venta aprobada, pendiente de entrega.</span>
                        <?php else: ?><span>En espera de revisión.</span><?php endif; ?>
                    </div></td>
                    <td style="text-align:right"><a href="ver_ficha.php?id=<?= $s['id'] ?>" class="btn btn--icon btn--sm" title="Ver ficha"><?= ui_icon('eye') ?></a></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table></div>
        <?= renderPagination($total_registros, $registros_por_pagina, $pagina_actual, $filtrosParams); ?>
    </section>

    <div class="only-mobile"><?= renderPagination($total_registros, $registros_por_pagina, $pagina_actual, $filtrosParams); ?></div>
</div>
</main>

<?php include 'includes/footer.php'; ?>
