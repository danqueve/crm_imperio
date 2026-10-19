<?php
require dirname(__DIR__, 2) . '/includes/db.php';

// Seguridad básica: debe estar logueado
if (!isset($_SESSION['role'])) {
    header("Location: index.php");
    exit;
}

$my_role = $_SESSION['role'];
$my_id = $_SESSION['user_id'];

// LÓGICA DE ID:
// - Vendedores, Verificadores y Entregadores: SOLO pueden ver su propio perfil (Seguridad)
// - Admin y Supervisor: Pueden ver el de cualquiera por GET, o el suyo por defecto
if (in_array($my_role, ['vendedor', 'verificador', 'entregador'])) {
    $seller_id = $my_id;
} else {
    $seller_id = $_GET['id'] ?? $my_id;
}

// 1. Obtener Datos del Vendedor
$stmtUser = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmtUser->execute([$seller_id]);
$seller = $stmtUser->fetch(PDO::FETCH_ASSOC);

if (!$seller) die("Vendedor no encontrado.");

// 2. Configurar Filtro de Fechas (Por defecto Lunes a Sábado de la semana actual)
$defaultStart = date('Y-m-d', strtotime('monday this week'));
$defaultEnd = date('Y-m-d', strtotime('saturday this week'));

$start = $_GET['start'] ?? $defaultStart;
$end = $_GET['end'] ?? $defaultEnd;

$startSql = $start . ' 00:00:00';
$endSql = $end . ' 23:59:59';

// 3. Obtener Ventas Entregadas en el Rango
$sql = "SELECT * FROM sales
        WHERE user_id = ?
        AND status = 'entregado'
        AND delivered_at BETWEEN ? AND ?
        ORDER BY delivered_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute([$seller_id, $startSql, $endSql]);
$sales = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 4. Calcular Totales
// Comisión: tasa propia del vendedor, salvo ventas RapiCompra que siempre cobran RAPICOMPRA_COMMISSION_RATE
// (misma fórmula que comisiones.php, para no mostrar cifras distintas entre pantallas)
$sellerRate = (float)($seller['commission_rate'] ?? 5.00);
$totalVentas = 0;
$totalComisiones = 0;
foreach ($sales as $i => $s) {
    $rate = (($s['payment_method'] ?? 'normal') === 'rapicompra') ? RAPICOMPRA_COMMISSION_RATE : $sellerRate;
    $commission = $s['total_amount'] * ($rate / 100);
    $sales[$i]['commission'] = $commission;
    $totalVentas += $s['total_amount'];
    $totalComisiones += $commission;
}

// 4b. Objetivo del mes (mismo criterio que dashboard.php) y ventas activas sin entregar todavía
$mtdStart = date('Y-m-01') . ' 00:00:00';
$mtdEnd   = date('Y-m-d') . ' 23:59:59';
$stmtGoal = $pdo->prepare(
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
$stmtGoal->execute([$mtdStart, $mtdEnd, $seller_id]);
$goal = $stmtGoal->fetch(PDO::FETCH_ASSOC);
$goalTarget   = (int)($goal['target_sales'] ?? 0);
$goalProgress = (int)($goal['progress_sales'] ?? 0);
$goalPct      = $goalTarget > 0 ? round($goalProgress / $goalTarget * 100) : 0;

$stmtPending = $pdo->prepare("SELECT status, COUNT(*) as total FROM sales WHERE user_id = ? AND status IN ('revision','aprobado') GROUP BY status");
$stmtPending->execute([$seller_id]);
$pendingCounts = ['revision' => 0, 'aprobado' => 0];
foreach ($stmtPending->fetchAll(PDO::FETCH_ASSOC) as $row) { $pendingCounts[$row['status']] = (int)$row['total']; }

// 5. Cambio de contraseña (solo el propio usuario)
$pass_message = '';
$pass_error   = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password') {
    csrf_verify();
    $current = $_POST['current_password'] ?? '';
    $new     = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (!password_verify($current, $seller['password'])) {
        $pass_error = "La contraseña actual es incorrecta.";
    } elseif (strlen($new) < 6) {
        $pass_error = "La nueva contraseña debe tener al menos 6 caracteres.";
    } elseif ($new !== $confirm) {
        $pass_error = "Las contraseñas no coinciden.";
    } else {
        $hashed = password_hash($new, PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hashed, $my_id]);
        log_audit($pdo, 'change_password', 'user', $my_id, 'Contraseña actualizada');
        $pass_message = "Contraseña actualizada correctamente.";
    }
}

$isOwnProfile = ($seller_id == $my_id);

include dirname(__DIR__, 2) . '/includes/header.php';
?>

<main class="app-main">
<div class="app-container">

    <div class="page-head">
        <div class="page-head__text">
            <a href="dashboard.php" class="navbar-back only-desktop"><?= ui_icon('chevron-left') ?> Panel</a>
            <h1 class="large-title"><?= $isOwnProfile ? 'Mis ventas y comisiones' : 'Ficha de vendedor' ?></h1>
            <p class="subhead">Detalle de desempeño y liquidación del período.</p>
        </div>
    </div>

    <?php if ($pass_message): ?><div class="notice notice--ok"><?= ui_icon('check-circle') ?> <span><?= htmlspecialchars($pass_message) ?></span></div><?php endif; ?>
    <?php if ($pass_error): ?><div class="notice notice--error"><?= ui_icon('alert-octagon') ?> <span><?= htmlspecialchars($pass_error) ?></span></div><?php endif; ?>

    <!-- Perfil + objetivo (celular) -->
    <section class="card card--pad only-mobile" style="display:flex;align-items:center;gap:12px">
        <?= ui_avatar($seller['name'], 'lg') ?>
        <div>
            <span class="cell__title"><?= htmlspecialchars($seller['name']) ?></span>
            <div class="subhead tab"><?= ucfirst($seller['role']) ?> · comisión <?= (int)$sellerRate ?>%</div>
        </div>
    </section>

    <!-- El filtro existía antes del rediseño: se mantiene también en celular. -->
    <form method="GET" class="card card--pad only-mobile" style="display:flex;gap:8px;align-items:flex-end">
        <?php if (!$isOwnProfile): ?><input type="hidden" name="id" value="<?= $seller_id ?>"><?php endif; ?>
        <div class="field" style="flex:1;min-width:0">
            <label class="field__label">Desde</label>
            <input type="date" name="start" value="<?= $start ?>" class="input">
        </div>
        <div class="field" style="flex:1;min-width:0">
            <label class="field__label">Hasta</label>
            <input type="date" name="end" value="<?= $end ?>" class="input">
        </div>
        <button type="submit" class="btn btn--icon" aria-label="Filtrar período"><?= ui_icon('filter') ?></button>
    </form>

    <?php if ($goalTarget > 0): $r = 38; $circ = round(2 * M_PI * $r, 1); $off = round($circ * (1 - min(100, $goalPct) / 100), 1); ?>
    <section class="card card--pad" style="display:flex;align-items:center;gap:16px">
        <div class="ring-wrap" style="width:84px;height:84px">
            <svg class="ring" viewBox="0 0 84 84" width="84" height="84">
                <circle class="ring__track" cx="42" cy="42" r="<?= $r ?>" stroke-width="9"></circle>
                <circle class="ring__value" cx="42" cy="42" r="<?= $r ?>" stroke-width="9" stroke-dasharray="<?= $circ ?>" stroke-dashoffset="<?= $off ?>"></circle>
            </svg>
            <div class="ring__label"><span class="tab" style="font-weight:700"><?= $goalPct ?>%</span></div>
        </div>
        <div>
            <?php $mesesEs = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre']; ?>
            <span class="eyebrow">Objetivo de <?= $mesesEs[(int)date('n')] ?></span>
            <div class="tab headline"><?= $goalProgress ?> de <?= $goalTarget ?> ventas</div>
            <div class="subhead"><?= $goalProgress >= $goalTarget ? '¡Objetivo cumplido!' : 'Te faltan ' . ($goalTarget - $goalProgress) . ' para llegar' ?></div>
        </div>
    </section>
    <?php endif; ?>

    <div class="row" style="align-items:flex-start">
        <div class="col-main" style="display:flex;flex-direction:column;gap:20px">

            <!-- KPIs -->
            <div class="kpis only-mobile">
                <article class="card kpi"><span class="eyebrow">Entregadas</span><span class="kpi__value tab"><?= count($sales) ?></span></article>
                <article class="card kpi"><span class="eyebrow">Vendido</span><span class="kpi__value tab" style="font-size:18px"><?= ui_money($totalVentas) ?></span></article>
                <article class="card kpi" style="background:var(--tint-soft);border-color:var(--tint)"><span class="eyebrow" style="color:var(--tint-text)">A cobrar</span><span class="kpi__value tab" style="font-size:18px;color:var(--tint-text)"><?= ui_money($totalComisiones) ?></span></article>
            </div>

            <?php if ($pendingCounts['revision'] > 0 || $pendingCounts['aprobado'] > 0): ?>
            <div class="card card--pad only-mobile" style="display:flex;align-items:center;gap:10px">
                <span class="dot dot--revision"></span>
                <span class="subhead" style="margin:0"><?= $pendingCounts['revision'] ?> en revisión · <?= $pendingCounts['aprobado'] ?> aprobada<?= $pendingCounts['aprobado'] == 1 ? '' : 's' ?> sin entregar</span>
            </div>
            <?php endif; ?>

            <!-- Filtro + totales (PC) -->
            <section class="card card--pad only-desktop">
                <form style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;padding-bottom:16px;margin-bottom:16px;border-bottom:1px solid var(--separator)">
                    <?php if (!$isOwnProfile): ?><input type="hidden" name="id" value="<?= $seller_id ?>"><?php endif; ?>
                    <div class="field" style="flex:1;min-width:140px">
                        <label class="field__label">Desde</label>
                        <input type="date" name="start" value="<?= $start ?>" class="input">
                    </div>
                    <div class="field" style="flex:1;min-width:140px">
                        <label class="field__label">Hasta</label>
                        <input type="date" name="end" value="<?= $end ?>" class="input">
                    </div>
                    <button type="submit" class="btn btn--primary"><?= ui_icon('filter') ?> Filtrar período</button>
                </form>
                <div class="kpis">
                    <article class="card kpi"><span class="eyebrow">Ventas entregadas</span><span class="kpi__value tab"><?= ui_money($totalVentas) ?></span><span class="kpi__sub tab"><?= count($sales) ?> operaciones</span></article>
                    <article class="card kpi" style="background:var(--tint-soft);border-color:var(--tint)"><span class="eyebrow" style="color:var(--tint-text)">Comisiones</span><span class="kpi__value tab" style="color:var(--tint-text)"><?= ui_money($totalComisiones) ?></span><span class="kpi__sub">A cobrar</span></article>
                </div>
            </section>

            <!-- Listado de ventas -->
            <section class="card only-desktop" style="overflow:hidden">
                <div class="card__head"><h2 class="headline" style="margin:0"><?= ui_icon('list') ?> Detalle de ventas del período</h2></div>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead><tr><th>Fecha</th><th>Cliente</th><th>Artículo</th><th class="num">Monto</th><th class="num">Comisión</th><th></th></tr></thead>
                        <tbody>
                            <?php if (empty($sales)): ?>
                                <tr><td colspan="6"><div class="empty"><?= ui_icon('receipt') ?><p class="empty__title">No hay ventas entregadas en este rango de fechas</p></div></td></tr>
                            <?php else: foreach ($sales as $s): ?>
                                <tr>
                                    <td class="tab caption"><?= date('d/m/Y', strtotime($s['delivered_at'])) ?></td>
                                    <td><?= htmlspecialchars($s['client_name']) ?></td>
                                    <td class="subhead"><?= htmlspecialchars($s['item']) ?></td>
                                    <td class="num tab"><?= ui_money($s['total_amount']) ?></td>
                                    <td class="num tab" style="font-weight:700;color:var(--tint-text)"><?= ui_money($s['commission']) ?></td>
                                    <td style="text-align:right"><a href="ver_ficha.php?id=<?= $s['id'] ?>" class="btn btn--icon btn--sm"><?= ui_icon('eye') ?></a></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- Listado de ventas (celular) -->
            <section class="only-mobile" style="display:flex;flex-direction:column;gap:8px">
                <h2 class="group__title">Entregadas en el período</h2>
                <?php if (empty($sales)): ?>
                <div class="card"><div class="empty"><?= ui_icon('receipt') ?><p class="empty__title">No hay ventas entregadas en este rango</p></div></div>
                <?php else: ?>
                <div class="group__body">
                    <?php foreach ($sales as $s): ?>
                    <a href="ver_ficha.php?id=<?= $s['id'] ?>" class="cell">
                        <span class="cell__body">
                            <span class="cell__title"><?= htmlspecialchars($s['client_name']) ?></span>
                            <span class="tab cell__sub"><?= htmlspecialchars($s['item']) ?> · <?= date('d/m', strtotime($s['delivered_at'])) ?></span>
                        </span>
                        <span style="display:flex;flex-direction:column;align-items:flex-end">
                            <span class="tab" style="font-weight:600"><?= ui_money($s['total_amount']) ?></span>
                            <span class="tab caption" style="color:var(--green);font-weight:600">+<?= ui_money($s['commission']) ?></span>
                        </span>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </section>
        </div>

        <!-- Perfil + cambio de contraseña (PC en columna lateral; celular al final) -->
        <aside class="col-side" style="display:flex;flex-direction:column;gap:20px">
            <section class="card card--pad only-desktop">
                <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px">
                    <?= ui_avatar($seller['name'], 'lg') ?>
                    <div>
                        <span class="cell__title"><?= htmlspecialchars($seller['name']) ?></span>
                        <div><span class="tag"><?= ucfirst($seller['role']) ?></span></div>
                    </div>
                </div>
                <div class="kv" style="padding:0"><span class="kv__label">Usuario/DNI</span><span class="tab"><?= htmlspecialchars($seller['username']) ?></span></div>
                <div class="kv" style="padding:0"><span class="kv__label">Celular</span><span><?= htmlspecialchars($seller['phone'] ?? '-') ?></span></div>
                <div class="kv" style="padding:0"><span class="kv__label">Comisión</span><span class="tab"><?= (int)$sellerRate ?>%</span></div>
                <div class="kv" style="padding:0"><span class="kv__label">Alta</span><span class="tab"><?= date('d/m/Y', strtotime($seller['created_at'])) ?></span></div>
            </section>

            <?php if ($isOwnProfile): ?>
            <section class="card card--pad">
                <h2 class="headline" style="margin:0 0 12px"><?= ui_icon('lock') ?> Cambiar contraseña</h2>
                <form method="POST" style="display:flex;flex-direction:column;gap:12px">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="change_password">
                    <div class="field">
                        <label class="field__label">Contraseña actual</label>
                        <input type="password" name="current_password" required class="input">
                    </div>
                    <div class="field">
                        <label class="field__label">Nueva contraseña</label>
                        <input type="password" name="new_password" required minlength="6" class="input">
                    </div>
                    <div class="field">
                        <label class="field__label">Repetir nueva contraseña</label>
                        <input type="password" name="confirm_password" required minlength="6" class="input">
                    </div>
                    <button type="submit" class="btn btn--primary"><?= ui_icon('save') ?> Actualizar contraseña</button>
                </form>
            </section>
            <?php endif; ?>
        </aside>
    </div>
</div>
</main>

<?php include dirname(__DIR__, 2) . '/includes/footer.php'; ?>
