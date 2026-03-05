<?php
require 'includes/db.php';
require_once 'includes/functions.php';

// SEGURIDAD: Solo Admin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}

$seller_id = $_GET['id'] ?? null;
if (!$seller_id) {
    header("Location: lista_vendedores.php");
    exit;
}

// 1. Obtener Datos del Vendedor
$stmtUser = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmtUser->execute([$seller_id]);
$seller = $stmtUser->fetch(PDO::FETCH_ASSOC);

if (!$seller) die("Vendedor no encontrado.");

// --- 1. FILTROS Y BÚSQUEDA ---
$search = $_GET['search'] ?? '';
$whereClause = "WHERE s.user_id = :seller_id";
$params = [':seller_id' => $seller_id];

if (!empty($search)) {
    $whereClause .= " AND (s.client_name LIKE :search OR s.client_dni LIKE :search)";
    $params[':search'] = "%$search%";
}

// --- 2. LÓGICA DE PAGINACIÓN ---
$registros_por_pagina = 25;
$pagina_actual = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($pagina_actual < 1) $pagina_actual = 1;
$offset = ($pagina_actual - 1) * $registros_por_pagina;

// Contar Total de registros filtrados
$sqlCount = "SELECT COUNT(*) FROM sales s $whereClause";
$stmtCount = $pdo->prepare($sqlCount);
$stmtCount->execute($params);
$total_registros = $stmtCount->fetchColumn();

// --- 3. CONSULTA DE DATOS PAGINADOS ---
$sql = "SELECT s.*, 
               u_rej.name as rejected_by_name,
               u_del.name as delivered_by_name
        FROM sales s
        LEFT JOIN users u_rej ON s.rejected_by = u_rej.id
        LEFT JOIN users u_del ON s.delivered_by = u_del.id
        $whereClause
        ORDER BY s.created_at DESC
        LIMIT :offset, :limit";

$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) { 
    $stmt->bindValue($key, $value); 
}
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->bindValue(':limit', $registros_por_pagina, PDO::PARAM_INT);
$stmt->execute();
$sales = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Parámetros extras para la paginación (mantener búsqueda)
$filtrosParams = ['id' => $seller_id, 'search' => $search];

include 'includes/header.php';
?>

<main class="flex-1 max-w-7xl mx-auto w-full p-4 sm:p-6 lg:p-8 fade-in">
    
    <!-- Título y Regresar -->
    <div class="flex items-center gap-4 mb-8">
        <a href="lista_vendedores.php" class="p-2 bg-slate-800 rounded-full text-slate-400 hover:text-white transition border border-slate-700">
            <i data-lucide="chevron-left"></i>
        </a>
        <div>
            <h1 class="text-2xl font-bold text-white tracking-tight">Ventas de <?= htmlspecialchars($seller['name']) ?></h1>
            <p class="text-sm text-slate-500">Historial completo de ventas cargadas (25 por página).</p>
        </div>
    </div>

    <!-- Barra de Filtros (Solo Buscador) -->
    <div class="bg-slate-900 p-5 rounded-2xl border border-slate-800 shadow-lg mb-8">
        <form class="flex flex-col xl:flex-row gap-4 items-end">
            <input type="hidden" name="id" value="<?= $seller_id ?>">
            <div class="w-full xl:flex-1">
                <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1.5 ml-1 tracking-widest">Buscar Cliente (Nombre o DNI)</label>
                <div class="relative">
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" class="w-full bg-slate-950 border border-slate-700 rounded-xl px-4 py-2.5 pl-10 text-white focus:border-blue-500 outline-none transition text-sm" placeholder="Buscar cliente por nombre o dni...">
                    <div class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-500"><i data-lucide="search" class="w-4 h-4"></i></div>
                </div>
            </div>
            <button type="submit" class="w-full xl:w-auto bg-blue-600 hover:bg-blue-500 text-white px-6 py-3 rounded-xl font-bold shadow-lg transition flex items-center justify-center gap-2 text-sm">
                <i data-lucide="filter" class="w-4 h-4"></i> Buscar
            </button>
        </form>
    </div>

    <!-- Tabla Principal -->
    <div class="bg-slate-900 rounded-2xl border border-slate-800 shadow-xl overflow-hidden flex flex-col min-h-[500px]">
        <div class="overflow-x-auto flex-1">
            <table class="w-full text-left text-sm">
                <thead class="bg-slate-950/50 text-slate-500 uppercase text-[10px] font-bold tracking-widest border-b border-slate-800">
                    <tr>
                        <th class="p-5 pl-6">#</th>
                        <th class="p-5">Fecha</th>
                        <th class="p-5">Cliente</th>
                        <th class="p-5">Artículo</th>
                        <th class="p-5 text-center">Estado</th>
                        <th class="p-5">Detalle Auditoría</th>
                        <th class="p-5 text-right">Ficha</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/50">
                    <?php if (empty($sales)): ?>
                        <tr><td colspan="7" class="p-12 text-center text-slate-500 italic flex flex-col items-center justify-center w-full col-span-7"><i data-lucide="search-x" class="w-10 h-10 mb-2 opacity-50"></i>No se encontraron resultados para la búsqueda.</td></tr>
                    <?php else: ?>
                        <?php $counter = $offset + 1; foreach ($sales as $s): ?>
                        <tr class="hover:bg-slate-800/30 transition border-b border-slate-800/50 last:border-0">
                            <td class="p-5 pl-6 font-bold text-slate-600"><?= $counter++ ?></td>
                            <td class="p-5 text-slate-400 font-mono text-xs whitespace-nowrap"><?= date('d/m/Y', strtotime($s['created_at'])) ?></td>
                            <td class="p-5">
                                <div class="font-bold text-white"><?= htmlspecialchars($s['client_name']) ?></div>
                                <div class="text-[10px] text-slate-500 font-mono"><?= htmlspecialchars($s['client_dni']) ?></div>
                            </td>
                            <td class="p-5">
                                <div class="text-slate-300 text-xs font-medium"><?= htmlspecialchars($s['item']) ?></div>
                                <div class="text-[10px] text-emerald-500 font-bold">$<?= number_format($s['total_amount'], 0, ',', '.') ?></div>
                            </td>
                            <td class="p-5 text-center">
                                <?php 
                                $statusClasses = [
                                    'revision' => 'bg-yellow-500/10 text-yellow-400 border-yellow-500/20',
                                    'aprobado' => 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20',
                                    'entregado' => 'bg-blue-500/10 text-blue-400 border-blue-500/20',
                                    'rechazado' => 'bg-red-500/10 text-red-400 border-red-500/20'
                                ];
                                $statusLabels = [
                                    'revision' => 'Revisión',
                                    'aprobado' => 'Aprobado',
                                    'entregado' => 'Entregado',
                                    'rechazado' => 'Rechazado'
                                ];
                                ?>
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-wide border <?= $statusClasses[$s['status']] ?? 'bg-slate-800 text-slate-400 border-slate-700' ?>">
                                    <?= $statusLabels[$s['status']] ?? 'Desconocido' ?>
                                </span>
                            </td>
                            <td class="p-5">
                                <div class="text-[10px] text-slate-400">
                                    <?php if ($s['status'] === 'entregado'): ?>
                                        <span class="block font-bold text-blue-400/80 mb-0.5 uppercase tracking-tighter">Entregado por:</span> 
                                        <span class="flex items-center gap-1"><i data-lucide="user-check" class="w-2.5 h-2.5"></i> <?= htmlspecialchars($s['delivered_by_name'] ?? 'S/D') ?></span>
                                        <span class="text-slate-600 block mt-0.5 font-mono"><?= date('d/m/Y', strtotime($s['delivered_at'])) ?></span>
                                    <?php elseif ($s['status'] === 'rechazado'): ?>
                                        <span class="block font-bold text-red-400/80 mb-0.5 uppercase tracking-tighter">Rechazado por:</span> 
                                        <span class="flex items-center gap-1 mb-1"><i data-lucide="shield-alert" class="w-2.5 h-2.5"></i> <?= htmlspecialchars($s['rejected_by_name'] ?? 'Admin') ?></span>
                                        <div class="italic text-slate-500 border-l-2 border-slate-800 pl-2 line-clamp-1">"<?= htmlspecialchars($s['rejected_reason'] ?? 'Sin motivo') ?>"</div>
                                    <?php elseif ($s['status'] === 'aprobado'): ?>
                                        <span class="text-emerald-500/60 italic">Venta aprobada, pendiente de entrega.</span>
                                    <?php else: ?>
                                        <span class="text-yellow-500/60 italic">En lista de espera para revisión.</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="p-5 text-right">
                                <a href="ver_ficha.php?id=<?= $s['id'] ?>" class="inline-flex items-center justify-center p-2 rounded-lg bg-slate-800 hover:bg-blue-600 text-blue-400 hover:text-white transition border border-slate-700 shadow-sm" title="Ver Ficha">
                                    <i data-lucide="eye" class="w-4 h-4"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Paginación -->
        <?= renderPagination($total_registros, $registros_por_pagina, $pagina_actual, $filtrosParams); ?>
    </div>
</main>

<?php include 'includes/footer.php'; ?>
