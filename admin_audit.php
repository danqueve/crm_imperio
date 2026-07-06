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
    $where[] = "a.action = :filtro_action";
    $params[':filtro_action'] = $filtro_action;
}
if ($filtro_user !== '') {
    $where[] = "a.user_id = :filtro_user";
    $params[':filtro_user'] = (int)$filtro_user;
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
     LIMIT :limit OFFSET :offset"
);
foreach ($params as $key => $value) {
    $stmtLog->bindValue($key, $value);
}
$stmtLog->bindValue(':limit',  $por_pagina, PDO::PARAM_INT);
$stmtLog->bindValue(':offset', $offset,     PDO::PARAM_INT);
$stmtLog->execute();
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
        <a href="dashboard.php" class="p-2 rounded-full transition" style="background:var(--paper);border:1.5px solid var(--line);color:var(--ink-3);" onmouseover="this.style.background='var(--accent-soft)';this.style.color='var(--accent-ink)'" onmouseout="this.style.background='var(--paper)';this.style.color='var(--ink-3)'">
            <i data-lucide="arrow-left"></i>
        </a>
        <div>
            <h1 class="text-2xl font-bold flex items-center gap-2" style="color:var(--ink);">
                <i data-lucide="shield-check" class="w-6 h-6 text-blue-400"></i>
                Log de Auditoría
            </h1>
            <p class="text-sm text-slate-500">Registro de acciones críticas del sistema.</p>
        </div>
    </div>

    <!-- Filtros -->
    <form method="GET" class="p-4 rounded-xl mb-6 flex flex-wrap gap-3 items-end" style="background:var(--card);border:1.5px solid var(--line);box-shadow:var(--shadow-card);">
        <div>
            <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Acción</label>
            <select name="action" class="input-light px-3 py-2 text-sm">
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
            <select name="user_id" class="input-light px-3 py-2 text-sm">
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
            <a href="admin_audit.php" class="px-4 py-2 text-sm font-bold rounded-lg transition" style="background:var(--paper);border:1.5px solid var(--line);color:var(--ink-2);" onmouseover="this.style.background='var(--accent-soft)';this.style.color='var(--accent-ink)'" onmouseout="this.style.background='var(--paper)';this.style.color='var(--ink-2)'"  >
                Limpiar
            </a>
        <?php endif; ?>
    </form>

    <!-- Tabla -->
    <div class="rounded-2xl overflow-hidden flex flex-col" style="background:var(--card);border:1.5px solid var(--line);box-shadow:var(--shadow-card);">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="uppercase text-xs font-bold tracking-wider" style="background:#f8f7fc;color:var(--ink-3);border-bottom:1.5px solid var(--line);">
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
                        <tr class="transition" style="border-bottom:1px dashed var(--line);" onmouseover="this.style.background='rgba(99,102,241,.04)'" onmouseout="this.style.background=''">
                            <td class="p-4 text-slate-400 whitespace-nowrap">
                                <?= date('d/m/Y H:i', strtotime($log['created_at'])) ?>
                            </td>
                            <td class="p-4 font-medium" style="color:var(--ink);">
                                <?= htmlspecialchars($log['user_name'] ?? 'Sistema') ?>
                            </td>
                            <td class="p-4">
                                <?php
                                $action_colors = [
                                    'login'           => 'bg-blue-100 text-blue-800 border-blue-200',
                                    'create_sale'     => 'bg-emerald-100 text-emerald-800 border-emerald-200',
                                    'aprobado'        => 'bg-green-100 text-green-800 border-green-200',
                                    'entregado'       => 'bg-indigo-100 text-indigo-800 border-indigo-200',
                                    'revision'        => 'bg-amber-100 text-amber-800 border-amber-200',
                                    'reject'          => 'bg-red-100 text-red-800 border-red-200',
                                    'change_password' => 'bg-orange-100 text-orange-800 border-orange-200',
                                ];
                                $color = $action_colors[$log['action']] ?? 'bg-gray-100 text-gray-700 border-gray-200';
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
