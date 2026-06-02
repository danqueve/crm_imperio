<?php
/**
 * Archivo: includes/header.php
 * Propósito: Cabecera global del sistema con navegación dinámica y control de roles.
 */

$current_page = basename($_SERVER['PHP_SELF']);

$role    = $_SESSION['role'] ?? '';
$user_id = $_SESSION['user_id'] ?? 0;

$can_manage_deliveries = in_array($role, ['admin', 'supervisor', 'verificador', 'entregador']);
$can_see_commissions   = in_array($role, ['admin', 'supervisor']);

$pending_count = 0;
if (in_array($role, ['admin', 'supervisor', 'verificador']) && isset($pdo)) {
    $stmtPending = $pdo->query("SELECT COUNT(*) FROM sales WHERE status = 'revision'");
    $pending_count = (int)$stmtPending->fetchColumn();
}

$is_admin           = ($role === 'admin');
$can_view_own_profile = isset($_SESSION['user_id']);

function getAvatarGradient($name) {
    $gradients = [
        'from-blue-500 to-indigo-600',
        'from-emerald-500 to-teal-600',
        'from-purple-500 to-violet-600',
        'from-orange-500 to-amber-600',
        'from-rose-500 to-pink-600',
        'from-cyan-500 to-sky-600',
        'from-amber-400 to-orange-500',
        'from-teal-500 to-emerald-600',
    ];
    $n = mb_strtolower($name ?? 'u');
    $idx = (ord($n[0]) + mb_strlen($n)) % count($gradients);
    return $gradients[$idx];
}
$avatarGradient = getAvatarGradient($_SESSION['name'] ?? 'U');
$avatarInitial  = strtoupper(mb_substr($_SESSION['name'] ?? 'U', 0, 1));

function navLink(string $href, string $icon, string $label, bool $hasBadge = false, int $badgeCount = 0, bool $extraCond = true): string {
    global $current_page;
    if (!$extraCond) return '';
    $page    = basename(strtok($href, '?'));
    $isActive = ($current_page === $page);
    $active   = $isActive ? 'active' : '';
    $badge    = ($hasBadge && $badgeCount > 0)
        ? '<span class="dot-badge">' . ($badgeCount > 99 ? '99+' : $badgeCount) . '</span>'
        : '';
    return sprintf(
        '<a href="%s" class="navpill-item %s"><i data-lucide="%s" style="width:15px;height:15px;"></i> %s %s</a>',
        htmlspecialchars($href), $active, htmlspecialchars($icon), htmlspecialchars($label), $badge
    );
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>CRM Imperio</title>
    <link rel="icon" href="<?= ASSET_BASE ?>img/logo.jpg" type="image/jpeg">
    <!-- Fuentes -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Caveat:wght@500;600;700&display=swap" rel="stylesheet">
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Sistema de diseño -->
    <link rel="stylesheet" href="<?= ASSET_BASE ?>style.css">
    <!-- Iconos Lucide -->
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body style="min-height:100dvh; display:flex; flex-direction:column;">

    <!-- Navbar Superior -->
    <nav style="background:rgba(255,255,255,0.9); backdrop-filter:blur(12px); border-bottom:1.5px solid var(--line); position:sticky; top:0; z-index:30; box-shadow:0 1px 8px rgba(43,43,58,.06);">
        <div style="max-width:1280px; margin:0 auto; padding:0 20px;">
            <div style="display:flex; align-items:center; justify-content:space-between; height:68px; gap:16px;">

                <!-- Logo y marca -->
                <a href="dashboard.php" style="display:flex; align-items:center; gap:10px; text-decoration:none; min-width:0; flex-shrink:0;">
                    <img src="<?= ASSET_BASE ?>img/logo.jpg"
                         alt="Imperio"
                         style="width:38px;height:38px;border-radius:10px;object-fit:cover;border:1.5px solid var(--line);background:#fff;flex-shrink:0;">
                    <div class="hidden md:block">
                        <div style="font-weight:800;font-size:16px;color:var(--ink);letter-spacing:-.01em;line-height:1;">CRM Imperio</div>
                        <div style="font-size:10px;color:var(--ink-3);font-weight:600;letter-spacing:.1em;text-transform:uppercase;">Sistema de ventas</div>
                    </div>
                </a>

                <!-- Navegación centro (escritorio) -->
                <div class="hidden md:flex flex-1 justify-center">
                    <div class="navpill-container">
                        <?= navLink('dashboard.php',        'home',        'Panel',     true, $pending_count) ?>
                        <?= navLink('cargar_venta.php',     'plus-circle', 'Nueva Venta') ?>
                        <?= navLink('historial_ventas.php', 'archive',     'Historial') ?>
                        <?= navLink("perfil_vendedor.php?id=$user_id", 'user', 'Mis Ventas', false, 0, $can_view_own_profile) ?>
                        <?php if ($can_manage_deliveries): ?>
                        <span style="width:1px;height:20px;background:var(--line);margin:0 2px;"></span>
                        <?= navLink('entregas.php',         'truck',       'Entregas') ?>
                        <?php endif; ?>
                        <?= navLink('comisiones.php',       'dollar-sign', 'Comisiones', false, 0, $can_see_commissions) ?>
                        <?php if ($is_admin): ?>
                        <span style="width:1px;height:20px;background:var(--line);margin:0 2px;"></span>
                        <?= navLink('lista_vendedores.php', 'users-2',     'Vendedores') ?>
                        <?= navLink('register_user.php',    'users',       'Usuarios') ?>
                        <?= navLink('admin_audit.php',      'shield-check','Auditoría') ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Perfil y salida -->
                <div style="display:flex;align-items:center;gap:10px;flex-shrink:0;">
                    <div class="hidden sm:flex" style="align-items:center;gap:10px;">
                        <div class="nav-avatar bg-gradient-to-br <?= $avatarGradient ?>" style="color:#fff;">
                            <?= $avatarInitial ?>
                        </div>
                        <div style="text-align:right;">
                            <div style="font-size:13px;font-weight:700;color:var(--ink);line-height:1.2;"><?= htmlspecialchars($_SESSION['name'] ?? 'Usuario') ?></div>
                            <div style="font-size:10px;color:var(--ink-3);font-weight:600;letter-spacing:.1em;text-transform:uppercase;"><?= $role ?></div>
                        </div>
                    </div>
                    <a href="logout.php"
                       title="Cerrar Sesión"
                       style="display:flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:10px;background:var(--paper);border:1.5px solid var(--line);color:var(--ink-3);transition:background .15s,color .15s;"
                       onmouseover="this.style.background='#fad9da';this.style.color='#9f1239';this.style.borderColor='rgba(159,18,57,.3)'"
                       onmouseout="this.style.background='var(--paper)';this.style.color='var(--ink-3)';this.style.borderColor='var(--line)'">
                        <i data-lucide="log-out" style="width:17px;height:17px;"></i>
                    </a>
                </div>

            </div>
        </div>

    </nav>

    <!-- ===== NAV INFERIOR MOBILE (fixed bottom, solo visible en móvil) ===== -->
    <div class="md:hidden" id="bottom-nav-wrapper"
         style="position:fixed;bottom:0;left:0;right:0;z-index:40;background:rgba(255,255,255,.97);border-top:1.5px solid var(--line);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);padding-bottom:env(safe-area-inset-bottom,0px);">
        <div id="mobile-nav-scroll"
             class="no-scrollbar"
             style="display:flex;overflow-x:auto;gap:0;scroll-behavior:smooth;">
            <?php
            $mobileLinks = [
                ['href' => 'dashboard.php',                   'icon' => 'home',         'label' => 'Panel',       'cond' => true],
                ['href' => 'cargar_venta.php',                'icon' => 'plus-circle',  'label' => 'Nueva',       'cond' => true],
                ['href' => 'historial_ventas.php',            'icon' => 'archive',      'label' => 'Historial',   'cond' => true],
                ['href' => "perfil_vendedor.php?id=$user_id", 'icon' => 'user',         'label' => 'Mis Ventas',  'cond' => $can_view_own_profile],
                ['href' => 'entregas.php',                    'icon' => 'truck',        'label' => 'Entregas',    'cond' => $can_manage_deliveries],
                ['href' => 'comisiones.php',                  'icon' => 'dollar-sign',  'label' => 'Comisiones',  'cond' => $can_see_commissions],
                ['href' => 'lista_vendedores.php',            'icon' => 'users-2',      'label' => 'Vendedores',  'cond' => $is_admin],
                ['href' => 'register_user.php',               'icon' => 'user-plus',    'label' => 'Usuarios',    'cond' => $is_admin],
                ['href' => 'admin_audit.php',                 'icon' => 'shield-check', 'label' => 'Auditoría',   'cond' => $is_admin],
            ];

            // Contar items visibles para distribuir espacio correctamente
            $visibleCount = count(array_filter($mobileLinks, fn($l) => $l['cond']));
            // Si caben en pantalla (≤5), los distribuimos igual; si hay más, cada uno tiene ancho fijo para el scroll
            $itemStyle = $visibleCount <= 5
                ? 'flex:1;'
                : 'flex-shrink:0;min-width:64px;';

            foreach ($mobileLinks as $link):
                if (!$link['cond']) continue;
                $isActive = ($current_page === basename(strtok($link['href'], '?')));
                $activeStyle = $isActive
                    ? 'color:var(--accent-ink);background:var(--accent-soft);'
                    : 'color:var(--ink-3);';
            ?>
            <a href="<?= $link['href'] ?>"
               style="display:flex;flex-direction:column;align-items:center;justify-content:center;gap:3px;padding:8px 4px 6px;text-decoration:none;white-space:nowrap;transition:background .15s,color .15s;min-height:52px;<?= $itemStyle ?><?= $activeStyle ?>">
                <i data-lucide="<?= $link['icon'] ?>" style="width:18px;height:18px;flex-shrink:0;"></i>
                <span style="font-size:9px;font-weight:700;letter-spacing:.03em;text-transform:uppercase;line-height:1;"><?= $link['label'] ?></span>
                <span style="width:<?= $isActive ? '16px' : '0' ?>;height:2px;border-radius:1px;background:var(--accent);transition:width .2s;"></span>
            </a>
            <?php endforeach; ?>
        </div>
        <!-- Gradiente derecho — indica scroll horizontal cuando hay más de 5 items -->
        <?php if ($visibleCount > 5): ?>
        <div id="mobile-nav-fade"
             style="position:absolute;right:0;top:0;bottom:0;width:24px;pointer-events:none;background:linear-gradient(to left,rgba(255,255,255,.97),transparent);"></div>
        <?php endif; ?>
    </div>

    <script>
    (function(){
        var scroll = document.getElementById('mobile-nav-scroll');
        var fade   = document.getElementById('mobile-nav-fade');
        if (!scroll) return;
        if (fade) {
            function updateFade(){
                var atEnd = scroll.scrollLeft + scroll.clientWidth >= scroll.scrollWidth - 4;
                fade.style.opacity = atEnd ? '0' : '1';
            }
            scroll.addEventListener('scroll', updateFade, {passive:true});
            updateFade();
        }
        // Desplazar el item activo al centro al cargar
        var active = scroll.querySelector('a[style*="accent-soft"]');
        if (active) active.scrollIntoView({block:'nearest',inline:'center'});
    })();
    </script>
