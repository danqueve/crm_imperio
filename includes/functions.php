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
        <div class="p-4 border-t border-slate-800 bg-slate-950/30 flex justify-between items-center mt-auto">
            <div class="text-xs text-slate-500">
                Página <span class="font-bold text-slate-400"><?= $pagina_actual ?></span> de <span class="font-bold text-slate-400"><?= $total_paginas ?></span> 
                (Total: <?= $total_registros ?> registros)
            </div>
            <div class="flex gap-2">
                <?php if ($pagina_actual > 1): ?>
                    <a href="<?= $baseUrl ?>page=<?= $pagina_actual - 1 ?>" 
                       class="px-3 py-1.5 bg-slate-800 hover:bg-slate-700 text-white text-xs rounded-lg transition flex items-center gap-1 border border-slate-700">
                        <i data-lucide="chevron-left" class="w-3 h-3"></i> Anterior
                    </a>
                <?php endif; ?>

                <?php if ($pagina_actual < $total_paginas): ?>
                    <a href="<?= $baseUrl ?>page=<?= $pagina_actual + 1 ?>" 
                       class="px-3 py-1.5 bg-slate-800 hover:bg-slate-700 text-white text-xs rounded-lg transition flex items-center gap-1 border border-slate-700">
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