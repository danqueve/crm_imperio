<?php
require 'includes/db.php';

// Solo admin puede ver el log de auditoría
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: dashboard.php");
    exit;
}

// Paginación
$por_pagina = 50;
$pagina_actual = max(1, (int)($_GET['page'] ?? 1));
$offset = ($pagina_actual - 1) * $por_pagina;

// Filtros opcionales
$filtro_action = $_GET['action'] ?? '';
$filtro_user   = $_GET['user_id'] ?? '';

$where = [];
$params = [];

if ($filtro_action !== '') {
    $where[] = "a.action = ?";
    $params[] = $filtro_action;
}
if ($filtro_user !== '') {
    $where[] = "a.user_id = ?";
    $params[] = $filtro_user;
}

$whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";

// Contar total
$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM audit_log a $whereSql");
$stmtCount->execute($params);
$total = (int)$stmtCount->fetchColumn();

// Obtener registros
$stmtLog = $pdo->prepare(
    "SELECT a.*, u.name AS user_name
     FROM audit_log a
     LEFT JOIN users u ON a.user_id = u.id
     $whereSql
     ORDER BY a.created_at DESC
     LIMIT $por_pagina OFFSET $offset"
);
$stmtLog->execute($params);
$logs = $stmtLog->fetchAll();

// Obtener lista de acciones únicas para el filtro
$acciones = $pdo->query("SELECT DISTINCT action FROM audit_log ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);

// Obtener lista de usuarios para el filtro
$usuarios = $pdo->query("SELECT id, name FROM users ORDER BY name")->fetchAll();

include 'includes/header.php';
?>

<main class="flex-1 max-w-7xl mx-auto w-full p-4 sm:p-6 lg:p-8 fade-in">

    <!-- Cabecera -->
    <div class="flex items-center gap-4 mb-8">
        <a href="dashboard.php" class="p-2 bg-slate-800 rounded-full text-slate-400 hover:text-white transition border border-slate-700">
            <i data-lucide="arrow-left"></i>
        </a>
        <div>
            <h1 class="text-2xl font-bold text-white flex items-center gap-2">
                <i data-lucide="shield-check" class="w-6 h-6 text-blue-400"></i>
                Log de Auditoría
            </h1>
            <p class="text-sm text-slate-500">Registro de acciones críticas del sistema.</p>
        </div>
    </div>

    <!-- Filtros -->
    <form method="GET" class="bg-slate-900 p-4 rounded-xl border border-slate-800 mb-6 flex flex-wrap gap-3 items-end">
        <div>
            <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Acción</label>
            <select name="action" class="bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-white text-sm">
                <option value="">Todas</option>
                <?php foreach ($acciones as $ac): ?>
                    <option value="<?= htmlspecialchars($ac) ?>" <?= $filtro_action === $ac ? 'selected' : '' ?>>
                        <?= htmlspecialchars($ac) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Usuario</label>
            <select name="user_id" class="bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-white text-sm">
                <option value="">Todos</option>
                <?php foreach ($usuarios as $u): ?>
                    <option value="<?= $u['id'] ?>" <?= $filtro_user == $u['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($u['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-500 text-white text-sm font-bold rounded-lg transition">
            Filtrar
        </button>
        <?php if ($filtro_action || $filtro_user): ?>
            <a href="admin_audit.php" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white text-sm font-bold rounded-lg transition">
                Limpiar
            </a>
        <?php endif; ?>
    </form>

    <!-- Tabla -->
    <div class="bg-slate-900 rounded-2xl border border-slate-800 shadow-xl overflow-hidden flex flex-col">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-slate-950/50 text-slate-400 uppercase text-xs font-bold tracking-wider border-b border-slate-800">
                    <tr>
                        <th class="p-4">Fecha</th>
                        <th class="p-4">Usuario</th>
                        <th class="p-4">Acción</th>
                        <th class="p-4">Tipo</th>
                        <th class="p-4">ID</th>
                        <th class="p-4">Detalles</th>
                        <th class="p-4">IP</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800">
                    <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="7" class="p-8 text-center text-slate-500 italic">
                                No hay registros de auditoría.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                        <tr class="hover:bg-slate-800/30 transition">
                            <td class="p-4 text-slate-400 whitespace-nowrap">
                                <?= date('d/m/Y H:i', strtotime($log['created_at'])) ?>
                            </td>
                            <td class="p-4 text-white font-medium">
                                <?= htmlspecialchars($log['user_name'] ?? 'Sistema') ?>
                            </td>
                            <td class="p-4">
                                <?php
                                $action_colors = [
                                    'login'           => 'bg-blue-500/10 text-blue-400 border-blue-500/20',
                                    'create_sale'     => 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20',
                                    'aprobado'        => 'bg-green-500/10 text-green-400 border-green-500/20',
                                    'entregado'       => 'bg-purple-500/10 text-purple-400 border-purple-500/20',
                                    'revision'        => 'bg-yellow-500/10 text-yellow-400 border-yellow-500/20',
                                    'reject'          => 'bg-red-500/10 text-red-400 border-red-500/20',
                                    'change_password' => 'bg-orange-500/10 text-orange-400 border-orange-500/20',
                                ];
                                $color = $action_colors[$log['action']] ?? 'bg-slate-700 text-slate-300 border-slate-600';
                                ?>
                                <span class="px-2 py-1 rounded border text-xs font-bold <?= $color ?>">
                                    <?= htmlspecialchars($log['action']) ?>
                                </span>
                            </td>
                            <td class="p-4 text-slate-400">
                                <?= htmlspecialchars($log['target_type'] ?? '-') ?>
                            </td>
                            <td class="p-4 text-slate-400 font-mono">
                                <?= $log['target_id'] ?? '-' ?>
                            </td>
                            <td class="p-4 text-slate-400 max-w-xs truncate" title="<?= htmlspecialchars($log['details'] ?? '') ?>">
                                <?= htmlspecialchars($log['details'] ?? '-') ?>
                            </td>
                            <td class="p-4 text-slate-500 font-mono text-xs">
                                <?= htmlspecialchars($log['ip'] ?? '-') ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Paginación -->
        <?= renderPagination($total, $por_pagina, $pagina_actual, array_filter(['action' => $filtro_action, 'user_id' => $filtro_user])) ?>
    </div>

</main>

<?php include 'includes/footer.php'; ?>
