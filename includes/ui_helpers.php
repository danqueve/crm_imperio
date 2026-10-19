<?php
/**
 * Archivo: includes/ui_helpers.php  (NUEVO — rediseño 2026)
 * Propósito: helpers de presentación compartidos por header, footer, mas.php y las páginas.
 * Solo devuelven HTML/arrays; no tocan la base de datos ni la sesión.
 * Incluir desde includes/functions.php:  require_once __DIR__ . '/ui_helpers.php';
 */

if (!function_exists('ui_icon')) {
    /** Ícono Lucide. Lucide reemplaza el <i> por un <svg> al llamar lucide.createIcons(). */
    function ui_icon(string $name, string $extraClass = ''): string {
        return '<i data-lucide="' . htmlspecialchars($name) . '" class="icon ' . htmlspecialchars($extraClass) . '" aria-hidden="true"></i>';
    }
}

if (!function_exists('ui_initials')) {
    /** "María Belén Juárez" → "MJ" */
    function ui_initials(?string $name): string {
        $parts = preg_split('/\s+/u', trim((string)$name), -1, PREG_SPLIT_NO_EMPTY);
        if (!$parts) return '?';
        $first = mb_substr($parts[0], 0, 1);
        $last  = count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '';
        return mb_strtoupper($first . $last);
    }
}

if (!function_exists('ui_avatar_class')) {
    /** Color de avatar estable por nombre: avatar--1 … avatar--7 */
    function ui_avatar_class(?string $name): string {
        $n = mb_strtolower(trim((string)$name));
        return 'avatar--' . ((crc32($n) % 7) + 1);
    }
}

if (!function_exists('ui_avatar')) {
    function ui_avatar(?string $name, string $size = ''): string {
        $cls = 'avatar ' . ui_avatar_class($name) . ($size ? ' avatar--' . $size : '');
        return '<span class="' . $cls . '" aria-hidden="true">' . htmlspecialchars(ui_initials($name)) . '</span>';
    }
}

if (!function_exists('ui_money')) {
    /** 1240000 → "$1.240.000" */
    function ui_money($amount): string {
        return '$' . number_format((float)$amount, 0, ',', '.');
    }
}

if (!function_exists('ui_status')) {
    /**
     * Estado de venta: SIEMPRE punto de color + palabra (nunca solo color).
     * revision = naranja · aprobado = azul · entregado = verde · rechazado = rojo
     * Reemplaza el HTML que devolvía status_badge(); status_badge() puede llamar a esta.
     */
    function ui_status(string $status): string {
        $labels = [
            'revision'  => 'En revisión',
            'aprobado'  => 'Aprobada',
            'entregado' => 'Entregada',
            'rechazado' => 'Rechazada',
        ];
        $label = $labels[$status] ?? ucfirst($status);
        $mod   = isset($labels[$status]) ? ' status--' . $status : '';
        return '<span class="status' . $mod . '"><span class="dot"></span>' . htmlspecialchars($label) . '</span>';
    }
}

if (!function_exists('ui_nav')) {
    /**
     * Menú según rol. Una sola fuente para: barra lateral (PC), barra inferior (celular) y mas.php.
     * Respeta EXACTAMENTE los permisos que ya tenía includes/header.php.
     *
     * @return array{groups: array, tabbar: array}
     */
    function ui_nav(string $role, int $user_id, int $pending_count = 0): array {
        $can_manage_deliveries = in_array($role, ['admin', 'supervisor', 'entregador'], true);
        $can_see_commissions   = in_array($role, ['admin', 'supervisor'], true);
        $is_admin              = ($role === 'admin');

        $items = [
            'panel'      => ['href' => 'dashboard.php',                     'icon' => 'house',        'label' => 'Panel',       'badge' => $pending_count, 'show' => true],
            'nueva'      => ['href' => 'cargar_venta.php',                  'icon' => 'circle-plus',  'label' => 'Nueva venta', 'short' => 'Nueva', 'show' => true],
            'historial'  => ['href' => 'historial_ventas.php',              'icon' => 'archive',      'label' => 'Historial',   'show' => true],
            'mis_ventas' => ['href' => 'perfil_vendedor.php?id=' . $user_id, 'icon' => 'user',        'label' => 'Mis ventas',  'show' => $user_id > 0],
            'entregas'   => ['href' => 'entregas.php',                      'icon' => 'truck',        'label' => 'Entregas',    'show' => $can_manage_deliveries],
            'comisiones' => ['href' => 'comisiones.php',                    'icon' => 'dollar-sign',  'label' => 'Comisiones',  'show' => $can_see_commissions],
            'vendedores' => ['href' => 'lista_vendedores.php',              'icon' => 'users',        'label' => 'Vendedores',  'show' => $is_admin],
            'usuarios'   => ['href' => 'register_user.php',                 'icon' => 'user-plus',    'label' => 'Usuarios',    'show' => $is_admin],
            'auditoria'  => ['href' => 'admin_audit.php',                   'icon' => 'shield-check', 'label' => 'Auditoría',   'show' => $is_admin],
        ];
        $pick = function (array $keys) use ($items) {
            $out = [];
            foreach ($keys as $k) if (!empty($items[$k]['show'])) $out[$k] = $items[$k];
            return $out;
        };

        $groups = array_filter([
            ['title' => null,             'items' => $pick(['panel', 'nueva', 'historial', 'mis_ventas'])],
            ['title' => 'Operación',      'items' => $pick(['entregas', 'comisiones'])],
            ['title' => 'Administración', 'items' => $pick(['vendedores', 'usuarios', 'auditoria'])],
        ], fn($g) => !empty($g['items']));

        // Barra inferior: máximo 4 accesos + "Más"
        $tabKeys = [
            'admin'       => ['panel', 'historial', 'entregas', 'comisiones'],
            'supervisor'  => ['panel', 'historial', 'entregas', 'comisiones'],
            'verificador' => ['panel', 'nueva', 'historial', 'mis_ventas'],
            'vendedor'    => ['panel', 'nueva', 'historial', 'mis_ventas'],
            'entregador'  => ['panel', 'entregas', 'historial', 'mis_ventas'],
        ][$role] ?? ['panel', 'historial'];
        $tabbar = $pick($tabKeys);
        $tabbar['mas'] = ['href' => 'mas.php', 'icon' => 'ellipsis', 'label' => 'Más', 'show' => true];

        return ['groups' => array_values($groups), 'tabbar' => $tabbar];
    }
}

if (!function_exists('ui_is_active')) {
    /** ¿El href apunta a la página actual? (ignora el query string) */
    function ui_is_active(string $href): bool {
        return basename($_SERVER['PHP_SELF']) === basename(strtok($href, '?'));
    }
}

if (!function_exists('ui_fecha_larga')) {
    /** "Sábado 17 de octubre", para el eyebrow de .page-head. Sin depender del locale del servidor. */
    function ui_fecha_larga(?int $ts = null): string {
        $ts = $ts ?? time();
        $dias  = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
        $meses = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
        return $dias[(int)date('w', $ts)] . ' ' . (int)date('j', $ts) . ' de ' . $meses[(int)date('n', $ts)];
    }
}
