<?php
require 'includes/db.php';
require_once 'includes/functions.php';

// SEGURIDAD: Solo Admin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}

$role = $_SESSION['role'];

// Consulta para obtener todos los usuarios que han cargado al menos una venta
$sql = "SELECT u.id, u.name, u.username, u.role, u.phone, 
               COUNT(s.id) as total_sales,
               SUM(CASE WHEN s.status IN ('aprobado', 'entregado') THEN 1 ELSE 0 END) as approved_sales,
               SUM(CASE WHEN s.status = 'rechazado' THEN 1 ELSE 0 END) as rejected_sales,
               MAX(s.created_at) as last_sale_date
        FROM users u
        JOIN sales s ON u.id = s.user_id
        GROUP BY u.id
        ORDER BY total_sales DESC";

$stmt = $pdo->query($sql);
$sellers = $stmt->fetchAll(PDO::FETCH_ASSOC);

include 'includes/header.php';
?>

<main class="flex-1 max-w-7xl mx-auto w-full p-4 sm:p-6 lg:p-8 fade-in">
    
    <!-- Título y Regresar -->
    <div class="flex items-center gap-4 mb-8">
        <a href="dashboard.php" class="p-2 bg-slate-800 rounded-full text-slate-400 hover:text-white transition border border-slate-700">
            <i data-lucide="chevron-left"></i>
        </a>
        <div>
            <h1 class="text-2xl font-bold text-white tracking-tight">Listado de Vendedores</h1>
            <p class="text-sm text-slate-500">Usuarios que han registrado ventas en el sistema.</p>
        </div>
    </div>

    <!-- Tabla de Vendedores -->
    <div class="bg-slate-900 rounded-2xl border border-slate-800 shadow-xl overflow-hidden flex flex-col min-h-[500px]">
        <div class="overflow-x-auto flex-1">
            <table class="w-full text-left text-sm">
                <thead class="bg-slate-950/50 text-slate-500 uppercase text-[10px] font-bold tracking-widest border-b border-slate-800">
                    <tr>
                        <th class="p-5 pl-6">Vendedor</th>
                        <th class="p-5 text-center">Rol</th>
                        <th class="p-5 text-center">Ventas Totales</th>
                        <th class="p-5 text-center text-green-500">Aprobadas</th>
                        <th class="p-5 text-center text-red-500">Rechazadas</th>
                        <th class="p-5 text-center">Efectividad</th>
                        <th class="p-5">Última Carga</th>
                        <th class="p-5 text-right">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/50">
                    <?php if (empty($sellers)): ?>
                        <tr><td colspan="8" class="p-12 text-center text-slate-500 italic flex flex-col items-center justify-center w-full col-span-8"><i data-lucide="users-2" class="w-10 h-10 mb-2 opacity-50"></i>No se encontraron vendedores con ventas registradas.</td></tr>
                    <?php else: ?>
                        <?php foreach ($sellers as $s): ?>
                        <tr class="hover:bg-slate-800/30 transition border-b border-slate-800/50 last:border-0">
                            <td class="p-5 pl-6">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-full bg-blue-600/20 text-blue-400 flex items-center justify-center font-bold border border-blue-500/20">
                                        <?= strtoupper(substr($s['name'], 0, 1)) ?>
                                    </div>
                                    <div>
                                        <div class="font-bold text-white"><?= htmlspecialchars($s['name']) ?></div>
                                        <div class="text-[10px] text-slate-500 font-mono uppercase tracking-tighter">@<?= htmlspecialchars($s['username']) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td class="p-5 text-center">
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wide border bg-slate-800 text-slate-400 border-slate-700">
                                    <?= ucfirst($s['role']) ?>
                                </span>
                            </td>
                            <td class="p-5 text-center font-bold text-white">
                                <?= $s['total_sales'] ?>
                            </td>
                            <td class="p-5 text-center font-bold text-green-400">
                                <?= $s['approved_sales'] ?>
                            </td>
                            <td class="p-5 text-center font-bold text-red-400">
                                <?= $s['rejected_sales'] ?>
                            </td>
                            <td class="p-5 text-center">
                                <?php 
                                $percentage = $s['total_sales'] > 0 ? round(($s['approved_sales'] / $s['total_sales']) * 100) : 0;
                                $color = 'text-slate-400';
                                if ($percentage >= 80) $color = 'text-green-500';
                                elseif ($percentage >= 50) $color = 'text-yellow-500';
                                elseif ($percentage > 0) $color = 'text-red-500';
                                ?>
                                <div class="<?= $color ?> font-bold text-sm"><?= $percentage ?>%</div>
                                <div class="w-16 h-1 bg-slate-800 rounded-full mx-auto mt-1 overflow-hidden">
                                    <div class="h-full <?= str_replace('text', 'bg', $color) ?>" style="width: <?= $percentage ?>%"></div>
                                </div>
                            </td>
                            <td class="p-5 text-slate-400 font-mono text-xs">
                                <?= date('d/m/Y H:i', strtotime($s['last_sale_date'])) ?>
                            </td>
                            <td class="p-5 text-right">
                                <a href="ventas_vendedor.php?id=<?= $s['id'] ?>" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-blue-600 hover:bg-blue-500 text-white transition font-bold text-xs shadow-lg shadow-blue-900/20">
                                    <i data-lucide="list" class="w-4 h-4"></i> Ver todas las ventas
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<?php include 'includes/footer.php'; ?>
