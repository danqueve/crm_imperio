<?php
/**
 * Archivo: includes/header.php  (REFERENCIA del rediseño 2026)
 * Cabecera global: barra superior + barra lateral (PC). La barra inferior (celular) va en footer.php.
 * Mantiene la misma lógica de permisos y el mismo conteo de pendientes que el header anterior.
 * Requiere includes/ui_helpers.php (cargado desde functions.php).
 */

$current_page = basename($_SERVER['PHP_SELF']);
$role    = $_SESSION['role'] ?? '';
$user_id = (int)($_SESSION['user_id'] ?? 0);
$user_name = $_SESSION['name'] ?? 'Usuario';

$pending_count = 0;
if (in_array($role, ['admin', 'supervisor', 'verificador']) && isset($pdo)) {
    $stmtPending = $pdo->query("SELECT COUNT(*) FROM sales WHERE status = 'revision'");
    $pending_count = (int)$stmtPending->fetchColumn();
}

// Mismas variables que definía el header anterior (por compatibilidad)
$can_manage_deliveries = in_array($role, ['admin', 'supervisor', 'entregador']);
$can_see_commissions   = in_array($role, ['admin', 'supervisor']);
$is_admin              = ($role === 'admin');

$nav = ui_nav($role, $user_id, $pending_count);
$role_labels = [
    'admin' => 'Administrador', 'supervisor' => 'Supervisor', 'verificador' => 'Verificador',
    'vendedor' => 'Vendedor', 'entregador' => 'Entregador',
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#ffffff">
    <title>CRM Imperio</title>
    <link rel="icon" href="<?= ASSET_BASE ?>img/logo.jpg" type="image/jpeg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- Tailwind se mantiene solo mientras queden páginas con clases utilitarias (ver PROMPT.md, fase 5) -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="<?= ASSET_BASE ?>style.css">
    <!-- Versión fija: @latest puede romper íconos sin aviso -->
    <script src="https://unpkg.com/lucide@0.460.0/dist/umd/lucide.min.js"></script>
</head>
<body class="has-tabbar">

<header class="app-topbar">
    <a href="<?= ASSET_BASE ?>dashboard.php" class="app-brand">
        <img src="<?= ASSET_BASE ?>img/logo.jpg" alt="Imperio Comercial">
        <span class="only-desktop" style="display:flex;flex-direction:column">
            <span class="app-brand__name">CRM Imperio</span>
            <span class="app-brand__sub">Ventas a crédito</span>
        </span>
        <span class="only-mobile app-brand__name">CRM Imperio</span>
    </a>

    <form class="app-search" method="GET" action="<?= ASSET_BASE ?>historial_ventas.php" role="search">
        <?= ui_icon('search') ?>
        <label for="topbar-search" class="sr-only">Buscar</label>
        <input id="topbar-search" type="search" name="search" placeholder="Buscar cliente, DNI o teléfono">
    </form>

    <div class="app-user">
        <a href="<?= ASSET_BASE ?>cargar_venta.php" class="btn btn--primary only-desktop">
            <?= ui_icon('plus') ?> Nueva venta
        </a>
        <a href="<?= ASSET_BASE ?>historial_ventas.php" class="btn btn--icon only-mobile" aria-label="Buscar" style="border:0">
            <?= ui_icon('search') ?>
        </a>
        <?= ui_avatar($user_name, 'sm') ?>
        <span class="only-desktop" style="display:flex;flex-direction:column">
            <span class="app-user__name"><?= htmlspecialchars($user_name) ?></span>
            <span class="app-user__role"><?= htmlspecialchars($role_labels[$role] ?? $role) ?></span>
        </span>
        <a href="<?= ASSET_BASE ?>logout.php" class="btn btn--icon only-desktop" aria-label="Cerrar sesión" title="Cerrar sesión">
            <?= ui_icon('log-out') ?>
        </a>
    </div>
</header>

<div class="app-shell">
    <nav class="app-sidebar" aria-label="Secciones">
        <?php foreach ($nav['groups'] as $group): ?>
        <div class="nav-group">
            <?php if ($group['title']): ?>
                <span class="nav-group__title"><?= htmlspecialchars($group['title']) ?></span>
            <?php endif; ?>
            <?php foreach ($group['items'] as $item): ?>
            <a href="<?= ASSET_BASE . htmlspecialchars($item['href']) ?>"
               class="nav-item <?= ui_is_active($item['href']) ? 'is-active' : '' ?>"
               <?= ui_is_active($item['href']) ? 'aria-current="page"' : '' ?>>
                <?= ui_icon($item['icon']) ?>
                <span class="nav-item__label"><?= htmlspecialchars($item['label']) ?></span>
                <?php if (!empty($item['badge'])): ?>
                    <span class="badge"><?= $item['badge'] > 99 ? '99+' : (int)$item['badge'] ?></span>
                <?php endif; ?>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>

        <div class="nav-group app-sidebar__bottom">
            <a href="<?= ASSET_BASE ?>logout.php" class="nav-item">
                <?= ui_icon('log-out') ?><span class="nav-item__label">Cerrar sesión</span>
            </a>
        </div>
    </nav>

    <div class="app-content" style="flex:1;min-width:0;display:flex;flex-direction:column">
    <!-- Aquí sigue el <main> de cada página; footer.php cierra .app-content y .app-shell -->
