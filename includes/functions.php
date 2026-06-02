<?php
/**
 * Archivo: includes/functions.php
 * Propósito: Funciones auxiliares reutilizables.
 */

// --- CSRF Protection ---

if (!function_exists('csrf_token')) {
    function csrf_token() {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field() {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
    }
}

if (!function_exists('csrf_verify')) {
    function csrf_verify() {
        $token = $_POST['csrf_token'] ?? '';
        if (!hash_equals(csrf_token(), $token)) {
            http_response_code(403);
            die("Acción no autorizada. Token de seguridad inválido.");
        }
    }
}

// --- Auditoría ---

if (!function_exists('log_audit')) {
    function log_audit($pdo, $action, $target_type = null, $target_id = null, $details = null) {
        $user_id = $_SESSION['user_id'] ?? 0;
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $stmt = $pdo->prepare(
            "INSERT INTO audit_log (user_id, action, target_type, target_id, details, ip) VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([$user_id, $action, $target_type, $target_id, $details, $ip]);
    }
}

// Evitar que el sistema falle si la función ya existe por algún motivo
if (!function_exists('renderPagination')) {
    /**
     * Genera el HTML de la barra de paginación.
     */
    function renderPagination($total_registros, $registros_por_pagina, $pagina_actual, $extra_params = []) {
        $total_paginas = ceil($total_registros / $registros_por_pagina);
        
        if ($total_paginas <= 1) return '';

        // Limpiar 'page' para evitar duplicados en la URL
        unset($extra_params['page']);
        $queryString = http_build_query($extra_params);
        $baseUrl = $_SERVER['PHP_SELF'] . '?' . ($queryString ? $queryString . '&' : '');

        ob_start();
        ?>
        <?php
        $from = min(($pagina_actual - 1) * $registros_por_pagina + 1, $total_registros);
        $to   = min($pagina_actual * $registros_por_pagina, $total_registros);
        ?>
        <div class="p-4 flex justify-between items-center mt-auto" style="border-top:1.5px solid var(--line);background:#f8f7fc;">
            <div class="text-xs" style="color:var(--ink-3);">
                Mostrando <span class="font-bold" style="color:var(--ink-2);"><?= $from ?>–<?= $to ?></span> de <span class="font-bold" style="color:var(--ink-2);"><?= $total_registros ?></span> registros
            </div>
            <div class="flex gap-2">
                <?php if ($pagina_actual > 1): ?>
                    <a href="<?= $baseUrl ?>page=<?= $pagina_actual - 1 ?>"
                       class="px-3 py-1.5 text-xs rounded-lg transition flex items-center gap-1" style="background:var(--paper);border:1.5px solid var(--line);color:var(--ink-2);" onmouseover="this.style.background='var(--accent-soft)';this.style.color='var(--accent-ink)'" onmouseout="this.style.background='var(--paper)';this.style.color='var(--ink-2)'">
                        <i data-lucide="chevron-left" class="w-3 h-3"></i> Anterior
                    </a>
                <?php endif; ?>

                <?php if ($pagina_actual < $total_paginas): ?>
                    <a href="<?= $baseUrl ?>page=<?= $pagina_actual + 1 ?>"
                       class="px-3 py-1.5 text-xs rounded-lg transition flex items-center gap-1" style="background:var(--accent);color:#fff;" onmouseover="this.style.background='#4f46e5'" onmouseout="this.style.background='var(--accent)'">
                        Siguiente <i data-lucide="chevron-right" class="w-3 h-3"></i>
                    </a>
                <?php endif; ?>
            </div>
        </div>
        <script>if(window.lucide) { lucide.createIcons(); }</script>
        <?php
        return ob_get_clean();
    }
}

// --- Control de Acceso ---

if (!function_exists('require_role')) {
    function require_role(array $roles, string $redirect = 'dashboard.php'): void {
        if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $roles)) {
            header("Location: $redirect");
            exit;
        }
    }
}

// --- Paginación ---

if (!function_exists('getPaginationParams')) {
    function getPaginationParams(int $perPage = 20): array {
        $page   = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $offset = ($page - 1) * $perPage;
        return [$page, $offset, $perPage];
    }
}

// --- Status Badge ---

if (!function_exists('status_badge')) {
    function status_badge(string $status): string {
        $map = [
            'revision'  => ['classes' => 'chip-revision',  'label' => 'En Revisión', 'dot' => 'kpi-dot-revision animate-pulse'],
            'aprobado'  => ['classes' => 'chip-aprobado',  'label' => 'Aprobado',    'dot' => 'kpi-dot-aprobado'],
            'entregado' => ['classes' => 'chip-entregado', 'label' => 'Entregado',   'dot' => 'kpi-dot-entregado'],
            'rechazado' => ['classes' => 'chip-rechazado', 'label' => 'Rechazado',   'dot' => 'kpi-dot-rechazado'],
        ];
        $cfg = $map[$status] ?? ['classes' => 'bg-gray-100 text-gray-500 border-gray-200', 'label' => ucfirst($status), 'dot' => 'bg-gray-400'];
        return sprintf(
            '<span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-wide border %s"><span class="w-1.5 h-1.5 rounded-full shrink-0 %s"></span>%s</span>',
            $cfg['classes'],
            $cfg['dot'],
            htmlspecialchars($cfg['label'])
        );
    }
}