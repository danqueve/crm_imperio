<?php
/**
 * Archivo: includes/header.php
 * Propósito: Cabecera global del sistema con navegación dinámica y control de roles.
 */

// Detectar el nombre del archivo actual para marcar el enlace activo
$current_page = basename($_SERVER['PHP_SELF']);

// Estilos para enlace Activo e Inactivo (Escritorio)
$active_class = 'bg-blue-600 text-white shadow-md shadow-blue-900/20';
$inactive_class = 'text-slate-400 hover:text-white hover:bg-slate-700/50';

function getLinkClass($page_name) {
    global $current_page, $active_class, $inactive_class;
    return $current_page === $page_name ? $active_class : $inactive_class;
}

// Estilos para enlace Activo e Inactivo (Móvil)
$mobile_active = 'bg-blue-600 text-white border-blue-500 shadow-lg';
$mobile_inactive = 'bg-slate-800 text-slate-300 border-slate-700';

function getMobileClass($page_name) {
    global $current_page, $mobile_active, $mobile_inactive;
    return $current_page === $page_name ? $mobile_active : $mobile_inactive;
}

// --- DEFINICIÓN DE PERMISOS BASADOS EN ROLES ---
$role = $_SESSION['role'] ?? '';
$user_id = $_SESSION['user_id'] ?? 0;

// Permiso para gestionar entregas (Admin, Supervisor y Verificador)
$can_manage_deliveries = in_array($role, ['admin', 'supervisor', 'verificador']);

// Permiso para ver comisiones GLOBALES (Solo Admin y Supervisor)
$can_see_commissions = in_array($role, ['admin', 'supervisor']);

// Permiso para gestión de usuarios (Solo Admin)
$is_admin = ($role === 'admin');

// Permiso para ver su propio perfil (Todos los usuarios logueados)
$can_view_own_profile = isset($_SESSION['user_id']);

// Color de avatar basado en el nombre del usuario
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
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CRM Imperio</title>
    <link rel="icon" href="img/logo.jpg" type="image/jpeg">
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Iconos Lucide -->
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        /* Personalización de scrollbar */
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: #0f172a; }
        ::-webkit-scrollbar-thumb { background: #334155; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #475569; }
        /* Animación de entrada */
        .fade-in { animation: fadeIn 0.3s ease-in-out; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        /* Utilidad para ocultar scrollbar en móviles */
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
    </style>
</head>
<body class="bg-slate-950 text-gray-100 font-sans min-h-screen flex flex-col selection:bg-blue-500 selection:text-white">

    <!-- Navbar Superior -->
    <nav class="bg-slate-900/80 backdrop-blur-md border-b border-slate-800 sticky top-0 z-30">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-20 items-center">
                
                <!-- 1. Logo y Marca -->
                <div class="flex items-center gap-3 min-w-[150px]">
                    <div class="bg-gradient-to-br from-blue-600 to-indigo-600 p-2 rounded-lg shadow-lg shadow-blue-900/20">
                        <i data-lucide="layout-dashboard" class="text-white w-6 h-6"></i>
                    </div>
                    <div class="hidden md:block">
                        <h1 class="font-bold text-xl text-white tracking-tight leading-none">CRM Imperio</h1>
                        
                    </div>
                </div>

                <!-- 2. Navegación Centro (Solo Escritorio) -->
                <div class="hidden md:flex items-center justify-center flex-1">
                    <div class="flex items-center gap-1 bg-slate-800/50 border border-slate-700/50 rounded-full p-1.5 shadow-inner">
                        
                        <a href="dashboard.php" class="flex items-center gap-2 px-4 py-2 rounded-full text-sm font-medium transition-all <?= getLinkClass('dashboard.php') ?>">
                            <i data-lucide="home" class="w-4 h-4"></i> Panel
                        </a>
                        
                        <a href="cargar_venta.php" class="flex items-center gap-2 px-4 py-2 rounded-full text-sm font-medium transition-all <?= getLinkClass('cargar_venta.php') ?>">
                            <i data-lucide="plus-circle" class="w-4 h-4"></i> Nueva Venta
                        </a>

                        <a href="historial_ventas.php" class="flex items-center gap-2 px-4 py-2 rounded-full text-sm font-medium transition-all <?= getLinkClass('historial_ventas.php') ?>">
                            <i data-lucide="archive" class="w-4 h-4"></i> Historial
                        </a>

                        <?php if ($can_view_own_profile): ?>
                        <a href="perfil_vendedor.php?id=<?= $user_id ?>" class="flex items-center gap-2 px-4 py-2 rounded-full text-sm font-medium transition-all <?= getLinkClass('perfil_vendedor.php') ?>">
                            <i data-lucide="user" class="w-4 h-4"></i> Mis Ventas
                        </a>
                        <?php endif; ?>

                        <?php if ($can_manage_deliveries): ?>
                        <div class="w-px h-5 bg-slate-700 mx-1"></div>
                        <a href="entregas.php" class="flex items-center gap-2 px-4 py-2 rounded-full text-sm font-medium transition-all <?= getLinkClass('entregas.php') ?>">
                            <i data-lucide="truck" class="w-4 h-4"></i> Entregas
                        </a>
                        <?php endif; ?>

                        <?php if ($can_see_commissions): ?>
                        <a href="comisiones.php" class="flex items-center gap-2 px-4 py-2 rounded-full text-sm font-medium transition-all <?= getLinkClass('comisiones.php') ?>">
                            <i data-lucide="dollar-sign" class="w-4 h-4"></i> Comisiones
                        </a>
                        <?php endif; ?>

                        <?php if ($is_admin): ?>
                        <a href="lista_vendedores.php" class="flex items-center gap-2 px-4 py-2 rounded-full text-sm font-medium transition-all <?= getLinkClass('lista_vendedores.php') ?>">
                            <i data-lucide="users-2" class="w-4 h-4"></i> Vendedores
                        </a>
                        <?php endif; ?>

                        <?php if ($is_admin): ?>
                        <a href="register_user.php" class="flex items-center gap-2 px-4 py-2 rounded-full text-sm font-medium transition-all <?= getLinkClass('register_user.php') ? $active_class : 'text-purple-400 hover:text-purple-300 hover:bg-purple-500/10' ?>">
                            <i data-lucide="users" class="w-4 h-4"></i> Usuarios
                        </a>
                        <a href="admin_audit.php" class="flex items-center gap-2 px-4 py-2 rounded-full text-sm font-medium transition-all <?= getLinkClass('admin_audit.php') ?>">
                            <i data-lucide="shield-check" class="w-4 h-4"></i> Auditoría
                        </a>
                        <?php endif; ?>

                    </div>
                </div>

                <!-- 3. Perfil y Salida -->
                <div class="flex items-center justify-end gap-3 min-w-[100px] md:min-w-[200px]">
                    <div class="hidden sm:flex items-center gap-3">
                        <div class="w-9 h-9 rounded-xl bg-gradient-to-br <?= $avatarGradient ?> flex items-center justify-center text-sm font-bold text-white shadow-lg shrink-0 border border-white/10">
                            <?= $avatarInitial ?>
                        </div>
                        <div class="text-right">
                            <p class="text-sm font-semibold text-white leading-tight"><?= htmlspecialchars($_SESSION['name'] ?? 'Usuario') ?></p>
                            <p class="text-[10px] text-slate-500 uppercase font-bold tracking-wider"><?= $role ?></p>
                        </div>
                    </div>
                    <a href="logout.php" class="p-2 bg-slate-800 hover:bg-red-500/10 text-slate-400 hover:text-red-400 rounded-full transition-colors border border-slate-700 hover:border-red-500/20" title="Cerrar Sesión">
                        <i data-lucide="log-out" class="w-5 h-5"></i>
                    </a>
                </div>

            </div>
        </div>

        <!-- Navegación Móvil -->
        <div class="md:hidden border-t border-slate-800/80 bg-slate-900 px-2 py-1.5 flex overflow-x-auto gap-1 no-scrollbar items-center">
            <?php
            $mobileLinks = [
                ['href' => 'dashboard.php',              'icon' => 'home',        'label' => 'Panel',    'cond' => true],
                ['href' => 'cargar_venta.php',           'icon' => 'plus-circle', 'label' => 'Nueva',    'cond' => true],
                ['href' => 'historial_ventas.php',       'icon' => 'archive',     'label' => 'Historial','cond' => true],
                ['href' => "perfil_vendedor.php?id=$user_id", 'icon' => 'user',   'label' => 'Mis Ventas','cond' => $can_view_own_profile],
                ['href' => 'entregas.php',               'icon' => 'truck',       'label' => 'Entregas', 'cond' => $can_manage_deliveries],
            ];
            foreach ($mobileLinks as $link):
                if (!$link['cond']) continue;
                $isActive = ($current_page === basename(strtok($link['href'], '?')));
            ?>
            <a href="<?= $link['href'] ?>" class="flex-shrink-0 flex flex-col items-center gap-0.5 px-4 py-2 rounded-xl transition-all whitespace-nowrap <?= $isActive ? 'text-blue-400 bg-blue-500/10' : 'text-slate-500 hover:text-slate-300 hover:bg-slate-800/50' ?>">
                <i data-lucide="<?= $link['icon'] ?>" class="w-5 h-5"></i>
                <span class="text-[9px] font-bold uppercase tracking-wide"><?= $link['label'] ?></span>
                <?php if ($isActive): ?>
                    <span class="w-1 h-1 rounded-full bg-blue-400"></span>
                <?php else: ?>
                    <span class="w-1 h-1"></span>
                <?php endif; ?>
            </a>
            <?php endforeach; ?>
        </div>
    </nav>