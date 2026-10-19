<?php
require 'includes/db.php';

if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
$name = $_SESSION['name'];

// Permisos
$is_vendedor = ($role === 'vendedor');
$is_entregador = ($role === 'entregador');
$is_limited_view = ($is_vendedor || $is_entregador); // Roles que solo ven sus propias ventas
$can_manage = in_array($role, ['admin', 'supervisor', 'verificador']);
$can_assign = in_array($role, ['admin', 'supervisor']);
$is_admin   = ($role === 'admin');

// --- BLOQUE ADMIN: estadísticas, gráficos y filtro de período ---
if ($is_admin) {
    $start = $_GET['start'] ?? date('Y-m-01');
    $end   = $_GET['end']   ?? date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) $start = date('Y-m-01');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end))   $end   = date('Y-m-d');
    if ($start > $end) [$start, $end] = [$end, $start];
    $startSql = $start . ' 00:00:00';
    $endSql   = $end   . ' 23:59:59';

    $stmtStats = $pdo->prepare(
        "SELECT status, COUNT(*) as total, SUM(total_amount) as monto
         FROM sales WHERE created_at BETWEEN ? AND ? GROUP BY status"
    );
    $stmtStats->execute([$startSql, $endSql]);
    $statsRaw = $stmtStats->fetchAll(PDO::FETCH_ASSOC);

    $stats = ['revision' => 0, 'aprobado' => 0, 'entregado' => 0, 'rechazado' => 0];
    $statsMonto = ['revision' => 0, 'aprobado' => 0, 'entregado' => 0, 'rechazado' => 0];
    foreach ($statsRaw as $row) {
        $stats[$row['status']]      = (int)$row['total'];
        $statsMonto[$row['status']] = (float)$row['monto'];
    }
    $total_periodo = array_sum($stats);

    $denominador = $stats['entregado'] + $stats['rechazado'];
    $efectividad = $denominador > 0 ? round($stats['entregado'] / $denominador * 100) : 0;

    // Rechazos por "no potable" en el período (clientes que quedaron en lista negra)
    $stmtBlacklist = $pdo->prepare(
        "SELECT COUNT(DISTINCT client_dni) FROM sales
         WHERE status = 'rechazado' AND rejected_type = 'no_potable' AND created_at BETWEEN ? AND ?"
    );
    $stmtBlacklist->execute([$startSql, $endSql]);
    $blacklistedCount = (int)$stmtBlacklist->fetchColumn();

    // --- Objetivos de Ventas (todos los roles activos, mes calendario en curso, independiente del filtro de arriba) ---
    $mtdStart = date('Y-m-01') . ' 00:00:00';
    $mtdEnd   = date('Y-m-d') . ' 23:59:59';
    $stmtGoals = $pdo->prepare(
        "SELECT u.id, u.name, u.role,
                COALESCE(MAX(g.target_sales), 0) as target_sales,
                COUNT(CASE WHEN s.status IN ('aprobado','entregado')
                                AND s.created_at BETWEEN ? AND ?
                           THEN s.id END) as progress_sales
         FROM users u
         LEFT JOIN seller_goals g ON g.user_id = u.id
         LEFT JOIN sales s ON s.user_id = u.id
         WHERE u.is_active = 1
         GROUP BY u.id
         ORDER BY u.name ASC"
    );
    $stmtGoals->execute([$mtdStart, $mtdEnd]);
    $sellerGoals = $stmtGoals->fetchAll(PDO::FETCH_ASSOC);
}

// --- Objetivo personal del usuario logueado (cualquier rol) ---
$mtdStart = date('Y-m-01') . ' 00:00:00';
$mtdEnd   = date('Y-m-d') . ' 23:59:59';
$stmtMyGoal = $pdo->prepare(
    "SELECT COALESCE(MAX(g.target_sales), 0) as target_sales,
            COUNT(CASE WHEN s.status IN ('aprobado','entregado')
                            AND s.created_at BETWEEN ? AND ?
                       THEN s.id END) as progress_sales
     FROM users u
     LEFT JOIN seller_goals g ON g.user_id = u.id
     LEFT JOIN sales s ON s.user_id = u.id
     WHERE u.id = ?
     GROUP BY u.id"
);
$stmtMyGoal->execute([$mtdStart, $mtdEnd, $user_id]);
$myGoal     = $stmtMyGoal->fetch(PDO::FETCH_ASSOC);
$myTarget   = (int)($myGoal['target_sales'] ?? 0);
$myProgress = (int)($myGoal['progress_sales'] ?? 0);
$myPct      = $myTarget > 0 ? round($myProgress / $myTarget * 100) : 0;

// --- 3. LISTADO DE TABLA (BANDEJA DE ENTRADA) ---
// Subconsulta compartida: cuántos intentos de contacto tiene registrados cada venta
// (historial de contacto durante la verificación, guardado en audit_log)
$contactAttemptsSql = "(SELECT COUNT(*) FROM audit_log
                        WHERE audit_log.action = 'contact_log' AND audit_log.target_type = 'sale'
                          AND audit_log.target_id = sales.id) as contact_attempts";

if ($is_limited_view) {
    $stmt = $pdo->prepare("SELECT sales.*, $contactAttemptsSql
        FROM sales WHERE user_id = ? AND status IN ('revision', 'aprobado') ORDER BY created_at DESC");
    $stmt->execute([$user_id]);
} elseif ($role === 'verificador') {
    // Modelo estricto: el verificador solo ve las ventas que le fueron asignadas puntualmente
    $stmt = $pdo->prepare("SELECT sales.*, users.name as seller_name, $contactAttemptsSql
        FROM sales JOIN users ON sales.user_id = users.id
        WHERE sales.status = 'revision' AND sales.assigned_verifier_id = ?
        ORDER BY sales.created_at DESC");
    $stmt->execute([$user_id]);
} else {
    // Admin/Supervisor ven todo el pool en revisión, junto a quién está asignada cada venta
    $stmt = $pdo->query("SELECT sales.*, users.name as seller_name, av.name as verifier_name, $contactAttemptsSql
        FROM sales JOIN users ON sales.user_id = users.id
        LEFT JOIN users av ON sales.assigned_verifier_id = av.id
        WHERE sales.status = 'revision' ORDER BY sales.created_at DESC");
}
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Verificadores activos disponibles para asignar (solo se usa si $can_assign)
$verifiers = $can_assign
    ? $pdo->query("SELECT id, name FROM users WHERE role = 'verificador' AND is_active = 1 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC)
    : [];

// PREPARAR DATOS PDF
$pdfData = array_map(function($order) {
    return [
        'id' => $order['id'],
        'raw_date' => $order['created_at'],
        'date' => date('d/m/Y H:i', strtotime($order['created_at'])),
        'client' => $order['client_name'],
        'address' => $order['client_address'],
        'phone' => $order['client_whatsapp'],
        'item' => $order['item'],
        'seller' => $order['seller_name'] ?? 'Yo',
        'verifier' => $order['verifier_name'] ?? 'Sin asignar',
        'verifier_id' => (int)($order['assigned_verifier_id'] ?? 0)
    ];
}, $orders);

include 'includes/header.php';

// --- Derivados de presentación (no tocan $start/$end ni la consulta) ---
if ($is_admin) {
    $todayStr      = date('Y-m-d');
    $weekStartStr  = date('Y-m-d', strtotime('monday this week'));
    $monthStartStr = date('Y-m-01');
    $periodMode = 'personalizado';
    if ($start === $todayStr && $end === $todayStr) $periodMode = 'hoy';
    elseif ($start === $weekStartStr && $end === $todayStr) $periodMode = 'semana';
    elseif ($start === $monthStartStr && $end === $todayStr) $periodMode = 'mes';
}

$bandejaTitle = $is_limited_view ? 'Mis ventas activas' : ($role === 'verificador' ? 'Mis verificaciones asignadas' : 'Bandeja de revisión');
$bienvenidaMsg = $is_limited_view
    ? ('Tenés ' . count($orders) . ' venta' . (count($orders) === 1 ? '' : 's') . ' activa' . (count($orders) === 1 ? '' : 's') . '.')
    : ($role === 'verificador'
        ? ('Tenés ' . count($orders) . ' verificación' . (count($orders) === 1 ? '' : 'es') . ' asignada' . (count($orders) === 1 ? '' : 's') . '.')
        : ('Tenés ' . count($orders) . ' venta' . (count($orders) === 1 ? '' : 's') . ' esperando verificación.'));
?>

<!-- Librería de exportación PDF -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.29/jspdf.plugin.autotable.min.js"></script>

<main class="app-main">
<div class="app-container">

    <?php if (isset($_GET['msg']) && $_GET['msg'] == 'saved'): ?>
    <div id="toast-success" class="toast">
        <span class="icon-box icon-box--green"><?= ui_icon('check-circle') ?></span>
        <div>
            <p class="cell__title" style="margin:0">¡Venta registrada!</p>
            <p class="caption" style="margin:0">Guardada correctamente en el sistema.</p>
        </div>
        <button type="button" onclick="dismissToast()" class="btn btn--icon btn--sm" style="border:0;margin-left:4px" aria-label="Cerrar">
            <?= ui_icon('x') ?>
        </button>
    </div>
    <script>
        function dismissToast() {
            const t = document.getElementById('toast-success');
            if (t) { t.style.transition = 'transform .3s ease, opacity .3s ease'; t.style.transform = 'translateY(12px)'; t.style.opacity = '0'; setTimeout(() => t.remove(), 300); }
        }
        setTimeout(dismissToast, 4500);
    </script>
    <?php endif; ?>

    <!-- Encabezado de pantalla -->
    <div class="page-head">
        <div class="page-head__text">
            <span class="eyebrow"><?= htmlspecialchars(ui_fecha_larga()) ?></span>
            <h1 class="large-title">Panel</h1>
            <p class="subhead">Bienvenido, <?= htmlspecialchars($name) ?>. <?= htmlspecialchars($bienvenidaMsg) ?></p>
        </div>
        <div class="page-head__actions">
            <?php if ($is_admin): ?>
            <div role="group" aria-label="Período" class="segmented">
                <a href="dashboard.php?start=<?= $todayStr ?>&end=<?= $todayStr ?>" class="segmented__item <?= $periodMode === 'hoy' ? 'is-active' : '' ?>">Hoy</a>
                <a href="dashboard.php?start=<?= $weekStartStr ?>&end=<?= $todayStr ?>" class="segmented__item <?= $periodMode === 'semana' ? 'is-active' : '' ?>">Semana</a>
                <a href="dashboard.php?start=<?= $monthStartStr ?>&end=<?= $todayStr ?>" class="segmented__item <?= $periodMode === 'mes' ? 'is-active' : '' ?>">Mes</a>
                <button type="button" class="segmented__item <?= $periodMode === 'personalizado' ? 'is-active' : '' ?>" onclick="togglePeriodoForm()">Personalizado</button>
            </div>
            <?php endif; ?>

            <?php if ($can_assign): ?>
            <div style="position:relative;flex-shrink:0" id="verifierFilterWrap">
                <button type="button" onclick="toggleVerifierDropdown()" id="verifierFilterBtn" class="btn btn--sm">
                    <?= ui_icon('user-check') ?>
                    <span id="verifierFilterLabel">Todos</span>
                    <?= ui_icon('chevron-down') ?>
                </button>
                <div id="verifierFilterDropdown" class="card card--pad" style="display:none;position:absolute;z-index:20;margin-top:6px;width:240px;max-height:260px;overflow-y:auto">
                    <div style="display:flex;justify-content:space-between;margin-bottom:8px" class="caption">
                        <span>Verificador asignado</span>
                        <button type="button" onclick="toggleAllVerifiers(false)" class="caption" style="background:none;border:0;color:var(--tint-text);cursor:pointer;padding:0">Limpiar</button>
                    </div>
                    <label style="display:flex;align-items:center;gap:8px;padding:6px 2px;cursor:pointer">
                        <input type="checkbox" class="verifier-checkbox" value="0" onchange="updateVerifierLabel()">
                        <span class="subhead" style="margin:0">Sin asignar</span>
                    </label>
                    <?php foreach ($verifiers as $v): ?>
                    <label style="display:flex;align-items:center;gap:8px;padding:6px 2px;cursor:pointer">
                        <input type="checkbox" class="verifier-checkbox" value="<?= $v['id'] ?>" onchange="updateVerifierLabel()">
                        <span class="subhead" style="margin:0"><?= htmlspecialchars($v['name']) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <button type="button" onclick="exportarPDF()" class="btn btn--sm">
                <?= ui_icon('file-down') ?> PDF Pendientes
            </button>

            <?php if (in_array($role, ['admin', 'supervisor']) || $is_entregador): ?>
            <a href="entregas.php" class="btn btn--sm">
                <?= ui_icon('truck') ?> Gestión Entregas
            </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($is_admin): ?>
    <!-- Formulario de fechas personalizadas (se muestra con "Personalizado" o si ya hay un rango custom) -->
    <form method="GET" id="periodoForm" class="card card--pad" style="display:<?= $periodMode === 'personalizado' ? 'flex' : 'none' ?>;gap:12px;flex-wrap:wrap;align-items:flex-end">
        <div class="field" style="flex:1;min-width:140px">
            <label class="field__label" for="f-start">Desde</label>
            <input type="date" id="f-start" name="start" value="<?= $start ?>" class="input">
        </div>
        <div class="field" style="flex:1;min-width:140px">
            <label class="field__label" for="f-end">Hasta</label>
            <input type="date" id="f-end" name="end" value="<?= $end ?>" class="input">
        </div>
        <button type="submit" class="btn btn--primary"><?= ui_icon('filter') ?> Aplicar</button>
    </form>
    <p class="caption">
        <?= date('d/m/Y', strtotime($start)) ?> – <?= date('d/m/Y', strtotime($end)) ?> ·
        <strong class="tab" style="color:var(--label)"><?= $total_periodo ?></strong> ventas en el período
    </p>
    <?php endif; ?>

    <?php if ($myTarget > 0 || $is_admin): ?>
    <div class="row">
        <?php if ($myTarget > 0):
            $r = 52; $circunferencia = round(2 * M_PI * $r, 1);
            $pctClamped = min(100, max(0, $myPct));
            $dashoffset = round($circunferencia * (1 - $pctClamped / 100), 1);
            $diasRestantes = max(0, (int)date('t') - (int)date('j'));
            $faltan = max(0, $myTarget - $myProgress);
        ?>
        <!-- Objetivo del mes (usuario logueado, cualquier rol) -->
        <section class="card card--pad" style="flex:1 1 360px;display:flex;align-items:center;gap:20px">
            <div class="ring-wrap" style="width:128px;height:128px">
                <svg class="ring" viewBox="0 0 128 128" width="128" height="128" role="img" aria-label="Objetivo del mes: <?= $myPct ?> por ciento">
                    <circle class="ring__track" cx="64" cy="64" r="<?= $r ?>" stroke-width="12"></circle>
                    <circle class="ring__value" cx="64" cy="64" r="<?= $r ?>" stroke-width="12" stroke-dasharray="<?= $circunferencia ?>" stroke-dashoffset="<?= $dashoffset ?>"></circle>
                </svg>
                <div class="ring__label">
                    <span class="tab title2"><?= $myPct ?>%</span>
                    <span class="caption">del objetivo</span>
                </div>
            </div>
            <div style="display:flex;flex-direction:column;gap:6px;min-width:0">
                <span class="eyebrow">Objetivo del mes</span>
                <span class="tab title2"><?= $myProgress ?> de <?= $myTarget ?> ventas</span>
                <span class="subhead">
                    <?= $faltan > 0 ? ('Te faltan ' . $faltan . ' aprobadas · ') : '¡Objetivo cumplido! · ' ?>quedan <?= $diasRestantes ?> día<?= $diasRestantes === 1 ? '' : 's' ?>
                </span>
                <?php if ($is_admin): ?>
                <div style="display:flex;gap:20px;margin-top:8px;padding-top:12px;border-top:1px solid var(--separator)">
                    <div style="display:flex;flex-direction:column">
                        <span class="caption">Efectividad</span>
                        <span class="tab subhead" style="font-weight:600;color:var(--label)"><?= $efectividad ?>%</span>
                    </div>
                    <div style="display:flex;flex-direction:column">
                        <span class="caption">Entregado</span>
                        <span class="tab subhead" style="font-weight:600;color:var(--label)"><?= ui_money($statsMonto['entregado']) ?></span>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($is_admin): ?>
        <!-- 2. RESUMEN NUMÉRICO (TARJETAS KPI) -->
        <div class="kpis" style="flex:999 1 520px">
            <article class="card kpi">
                <div class="kpi__top">
                    <span class="eyebrow">En revisión</span>
                    <span class="icon-box icon-box--orange"><?= ui_icon('clock') ?></span>
                </div>
                <span class="kpi__value stat-counter" data-target="<?= $stats['revision'] ?>">0</span>
                <span class="kpi__sub"><?= $statsMonto['revision'] > 0 ? (ui_money($statsMonto['revision']) . ' en juego') : '—' ?></span>
            </article>
            <article class="card kpi">
                <div class="kpi__top">
                    <span class="eyebrow">Aprobadas</span>
                    <span class="icon-box icon-box--tint"><?= ui_icon('check') ?></span>
                </div>
                <span class="kpi__value stat-counter" data-target="<?= $stats['aprobado'] ?>">0</span>
                <span class="kpi__sub"><?= $statsMonto['aprobado'] > 0 ? ui_money($statsMonto['aprobado']) : 'Esperando entrega' ?></span>
            </article>
            <article class="card kpi">
                <div class="kpi__top">
                    <span class="eyebrow">Entregadas</span>
                    <span class="icon-box icon-box--green"><?= ui_icon('truck') ?></span>
                </div>
                <span class="kpi__value stat-counter" data-target="<?= $stats['entregado'] ?>">0</span>
                <span class="kpi__sub"><?= ui_money($statsMonto['entregado']) ?></span>
            </article>
            <article class="card kpi">
                <div class="kpi__top">
                    <span class="eyebrow">Rechazadas</span>
                    <span class="icon-box icon-box--red"><?= ui_icon('x-circle') ?></span>
                </div>
                <span class="kpi__value stat-counter" data-target="<?= $stats['rechazado'] ?>">0</span>
                <span class="kpi__sub"><?= $blacklistedCount > 0 ? ($blacklistedCount . ' en lista negra') : '—' ?></span>
            </article>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- 1. BANDEJA DE ENTRADA -->
    <section class="card" style="overflow:hidden">
        <div class="card__head">
            <div style="display:flex;align-items:center;gap:10px">
                <h2 class="headline" style="margin:0"><?= htmlspecialchars($bandejaTitle) ?></h2>
                <span class="tag tab"><?= count($orders) ?></span>
            </div>
        </div>

        <?php if (empty($orders)): ?>
        <div class="empty">
            <?= ui_icon('inbox') ?>
            <p class="empty__title">Todo al día</p>
            <p class="caption">No hay ventas pendientes de procesar en este momento.</p>
        </div>
        <?php else: ?>

        <!-- Tabla (PC) -->
        <div class="table-wrap only-desktop">
            <table class="data-table">
                <thead>
                    <tr>
                        <th></th>
                        <th>Cliente</th>
                        <th>Localidad</th>
                        <th>Artículo · total</th>
                        <th>Vendedor</th>
                        <th>Verificador</th>
                        <th>Estado</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $order): ?>
                    <tr onclick="dashRowClick(event, <?= (int)$order['id'] ?>)" style="cursor:pointer">
                        <td><?= ui_avatar($order['client_name']) ?></td>
                        <td>
                            <div style="display:flex;flex-wrap:wrap;align-items:center;gap:6px">
                                <span class="cell__title" style="font-size:var(--fs-headline)"><?= htmlspecialchars($order['client_name']) ?></span>
                                <?php if (($order['sale_type'] ?? 'credito') === 'contado'): ?>
                                <span class="tag">Contado</span>
                                <?php endif; ?>
                                <?php if (($order['payment_method'] ?? 'normal') === 'rapicompra'): ?>
                                <span class="tag tag--indigo">RapiCompra</span>
                                <?php endif; ?>
                            </div>
                            <div class="tab subhead" style="display:flex;align-items:center;gap:6px">
                                <?= htmlspecialchars($order['client_whatsapp']) ?>
                                <?php if ((int)($order['contact_attempts'] ?? 0) > 0): ?>
                                <span style="display:inline-flex;align-items:center;gap:4px;color:var(--label)">
                                    <?= ui_icon('history', '') ?> <?= (int)$order['contact_attempts'] ?> intento<?= (int)$order['contact_attempts'] > 1 ? 's' : '' ?>
                                </span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="subhead"><?= htmlspecialchars($order['client_locality'] ?: '-') ?></td>
                        <td>
                            <div><?= htmlspecialchars($order['item']) ?></div>
                            <div class="tab subhead" style="font-weight:600;color:var(--label)"><?= ui_money($order['total_amount']) ?></div>
                        </td>
                        <td class="subhead">
                            <a href="perfil_vendedor.php?id=<?= $order['user_id'] ?>" onclick="event.stopPropagation()">
                                <?= isset($order['seller_name']) ? htmlspecialchars($order['seller_name']) : 'Yo' ?>
                            </a>
                        </td>
                        <td class="subhead">
                            <?php if (!empty($order['verifier_name'])): ?>
                                <?= htmlspecialchars($order['verifier_name']) ?>
                            <?php elseif ($can_assign && $order['status'] === 'revision'): ?>
                                <span class="tag" style="border:1px dashed var(--label-3);background:transparent"><?= ui_icon('user-check') ?> Sin asignar</span>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="display:flex;flex-direction:column;gap:3px">
                                <?= status_badge($order['status']) ?>
                                <span class="tab caption"><?= date('d/m/Y H:i', strtotime($order['created_at'])) ?></span>
                            </div>
                        </td>
                        <td><span class="cell__chevron"><?= ui_icon('chevron-right') ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Lista agrupada (celular) -->
        <div class="only-mobile">
            <div class="group__body">
                <?php foreach ($orders as $order): ?>
                <a href="ver_ficha.php?id=<?= $order['id'] ?>" class="cell">
                    <?= ui_avatar($order['client_name']) ?>
                    <span class="cell__body">
                        <span style="display:flex;justify-content:space-between;gap:8px">
                            <span class="cell__title"><?= htmlspecialchars($order['client_name']) ?></span>
                            <span class="tab caption" style="flex-shrink:0"><?= date('d/m/Y', strtotime($order['created_at'])) ?></span>
                        </span>
                        <span class="tab cell__sub"><?= htmlspecialchars($order['item']) ?> · <?= ui_money($order['total_amount']) ?></span>
                        <span class="cell__meta">
                            <span class="dot dot--<?= $order['status'] ?>"></span>
                            <?php
                            $metaParts = [];
                            if (!empty($order['verifier_name'])) $metaParts[] = htmlspecialchars($order['verifier_name']);
                            elseif (array_key_exists('verifier_name', $order)) $metaParts[] = 'Sin asignar';
                            if (($order['sale_type'] ?? 'credito') === 'contado') $metaParts[] = 'Contado';
                            if (($order['payment_method'] ?? 'normal') === 'rapicompra') $metaParts[] = 'RapiCompra';
                            if ((int)($order['contact_attempts'] ?? 0) > 0) $metaParts[] = (int)$order['contact_attempts'] . ' intento' . ((int)$order['contact_attempts'] > 1 ? 's' : '');
                            echo implode(' · ', $metaParts);
                            ?>
                        </span>
                    </span>
                    <span class="cell__chevron"><?= ui_icon('chevron-right') ?></span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </section>

    <?php if ($is_admin): ?>
    <!-- 4. OBJETIVOS DE VENTAS -->
    <section class="card" style="padding:16px 20px 20px">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px">
            <h2 class="headline" style="margin:0">Objetivos de ventas · mes actual</h2>
        </div>
        <?php if (empty($sellerGoals)): ?>
        <p class="caption">No hay usuarios activos registrados.</p>
        <?php else: ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(260px, 1fr));gap:12px">
            <?php foreach ($sellerGoals as $g):
                $gTarget = (int)$g['target_sales'];
                $gProgress = (int)$g['progress_sales'];
                $gPct = $gTarget > 0 ? round($gProgress / $gTarget * 100) : 0;
            ?>
            <div class="card card--pad" style="display:flex;align-items:center;gap:12px">
                <?= ui_avatar($g['name']) ?>
                <div style="flex:1;min-width:0;display:flex;flex-direction:column;gap:6px">
                    <div style="display:flex;justify-content:space-between;gap:8px;align-items:center">
                        <span class="cell__title"><?= htmlspecialchars($g['name']) ?></span>
                        <span class="tab subhead"><?= $gTarget > 0 ? ($gProgress . ' / ' . $gTarget) : 'Sin objetivo' ?></span>
                    </div>
                    <div class="progress">
                        <div class="progress__bar <?= $gPct >= 100 ? 'progress__bar--done' : '' ?>" style="width:<?= min(100, $gPct) ?>%"></div>
                    </div>
                    <form method="POST" action="set_goal.php" style="display:flex;align-items:center;gap:8px">
                        <?= csrf_field() ?>
                        <input type="hidden" name="user_id" value="<?= $g['id'] ?>">
                        <input type="number" name="target_sales" min="0" step="1" value="<?= $gTarget ?>" class="input" style="height:32px;width:72px;padding:0 8px;font-size:13px">
                        <button type="submit" class="btn btn--sm">Guardar</button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>

</div>
</main>

<script>
    <?php if ($is_admin): ?>
    // Contadores animados en las tarjetas KPI
    document.querySelectorAll('.stat-counter').forEach(el => {
        const target = parseInt(el.dataset.target) || 0;
        if (target === 0) { el.textContent = '0'; return; }
        const duration = 900;
        const startTime = performance.now();
        function update(now) {
            const progress = Math.min((now - startTime) / duration, 1);
            const eased = 1 - Math.pow(1 - progress, 3);
            el.textContent = Math.round(eased * target);
            if (progress < 1) requestAnimationFrame(update);
        }
        requestAnimationFrame(update);
    });

    function togglePeriodoForm() {
        const f = document.getElementById('periodoForm');
        if (f) f.style.display = (f.style.display === 'none' ? 'flex' : 'none');
    }
    <?php endif; ?>

    // Fila/celda de la bandeja → ver_ficha.php (ignora clicks en links/botones propios)
    function dashRowClick(e, id) {
        if (e.target.closest('a, button, input, select, form')) return;
        window.location = 'ver_ficha.php?id=' + id;
    }

    // Dropdown de verificador (multi-selección con checkboxes) para el PDF
    function toggleVerifierDropdown() {
        const el = document.getElementById('verifierFilterDropdown');
        if (el) el.style.display = (el.style.display === 'none' ? 'block' : 'none');
    }
    function toggleAllVerifiers(checked) {
        document.querySelectorAll('.verifier-checkbox').forEach(cb => cb.checked = checked);
        updateVerifierLabel();
    }
    function updateVerifierLabel() {
        const checked = document.querySelectorAll('.verifier-checkbox:checked');
        const label = document.getElementById('verifierFilterLabel');
        if (!label) return;
        if (checked.length === 0) label.textContent = 'Todos';
        else if (checked.length === 1) label.textContent = checked[0].parentElement.querySelector('span').textContent.trim();
        else label.textContent = checked.length + ' seleccionados';
    }
    document.addEventListener('click', function(e) {
        const wrap = document.getElementById('verifierFilterWrap');
        if (wrap && !wrap.contains(e.target)) {
            const dd = document.getElementById('verifierFilterDropdown');
            if (dd) dd.style.display = 'none';
        }
    });

    // Lógica Exportación PDF
    const salesData = <?= json_encode($pdfData) ?>;
    function exportarPDF() {
        const checkedBoxes = document.querySelectorAll('.verifier-checkbox:checked');
        const verifierIds  = Array.from(checkedBoxes).map(cb => cb.value);
        const verifierNames = Array.from(checkedBoxes).map(cb => cb.parentElement.querySelector('span').textContent.trim());
        const data = verifierIds.length > 0
            ? salesData.filter(r => verifierIds.includes(String(r.verifier_id)))
            : salesData;

        if (data.length === 0) {
            alert(verifierIds.length > 0 ? `No hay ventas para: ${verifierNames.join(', ')}.` : "No hay ventas para exportar.");
            return;
        }

        const { jsPDF } = window.jspdf;
        const doc = new jsPDF('p', 'mm', 'a4');
        const pageWidth = doc.internal.pageSize.getWidth();
        doc.setFontSize(16); doc.setFont("helvetica", "bold");
        doc.text("VENTAS PENDIENTES DE REVISIÓN", pageWidth / 2, 15, { align: 'center' });

        let headerY = 22;
        if (verifierNames.length > 0) {
            doc.setFontSize(9); doc.setFont("helvetica", "bolditalic");
            const verifierLines = doc.splitTextToSize('Verificador: ' + verifierNames.join(', '), pageWidth - 20);
            doc.text(verifierLines, pageWidth / 2, headerY, { align: 'center' });
            headerY += verifierLines.length * 4;
        }
        const tableStartY = headerY + 3;

        doc.autoTable({
            head: [['#', 'Fecha', 'Cliente', 'Celular', 'Dirección', 'Artículo', 'Vendedor', 'Verificador']],
            body: data.map((r, i) => [i+1, r.date, r.client, r.phone, r.address, r.item, r.seller, r.verifier]),
            startY: tableStartY, theme: 'grid', styles: { fontSize: 8 },
            headStyles: { fillColor: [16, 141, 255] },
            margin: { top: tableStartY }
        });
        window.open(doc.output('bloburl'), '_blank');
    }
</script>

<?php include 'includes/footer.php'; ?>
