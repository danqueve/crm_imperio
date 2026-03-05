<?php
require 'includes/db.php';
require_once 'includes/functions.php'; // Requerimiento de funciones globales

// SEGURIDAD: Permitir acceso a Admin, Supervisor y Verificador
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'supervisor', 'verificador'])) {
    header("Location: dashboard.php");
    exit;
}

$role = $_SESSION['role'];

// Variable para saber si puede ver el perfil del vendedor (solo admin/supervisor)
$can_view_profile = in_array($role, ['admin', 'supervisor', 'verificador']);

// --- CONFIGURACIÓN DE PAGINACIÓN ---
$registros_por_pagina = 15;
$pagina_actual = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($pagina_actual < 1) $pagina_actual = 1;
$offset = ($pagina_actual - 1) * $registros_por_pagina;

// 1. Consultar Total de Registros para el contador
$sqlCount = "SELECT COUNT(*) FROM sales WHERE status IN ('aprobado', 'entregado')";
$total_registros = $pdo->query($sqlCount)->fetchColumn();

// 2. Consultar Registros para PANTALLA (Paginados)
// Ordenamiento: 'aprobado' (pendientes) primero, luego 'entregado'.
$sql = "SELECT sales.*, users.name as seller_name 
        FROM sales 
        JOIN users ON sales.user_id = users.id 
        WHERE sales.status IN ('aprobado', 'entregado') 
        ORDER BY 
            FIELD(sales.status, 'aprobado', 'entregado') ASC, 
            CASE WHEN sales.status = 'aprobado' THEN sales.created_at END ASC,
            CASE WHEN sales.status = 'entregado' THEN sales.delivered_at END DESC
        LIMIT :offset, :limit";

$stmt = $pdo->prepare($sql);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->bindValue(':limit', $registros_por_pagina, PDO::PARAM_INT);
$stmt->execute();
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 3. Consultar TODOS para PDF (Sin límite de página)
$sqlFull = "SELECT sales.*, users.name as seller_name 
            FROM sales 
            JOIN users ON sales.user_id = users.id 
            WHERE sales.status IN ('aprobado', 'entregado') 
            ORDER BY 
                FIELD(sales.status, 'aprobado', 'entregado') ASC, 
                CASE WHEN sales.status = 'aprobado' THEN sales.created_at END ASC,
                CASE WHEN sales.status = 'entregado' THEN sales.delivered_at END DESC";
$stmtFull = $pdo->query($sqlFull);
$allOrders = $stmtFull->fetchAll(PDO::FETCH_ASSOC);

// --- PREPARAR DATOS PARA PDF (JSON) ---
$pdfData = array_map(function($order) {
    return [
        'id' => $order['id'],
        'raw_date' => $order['created_at'],
        'raw_delivered_at' => $order['delivered_at'],
        'date_loaded' => date('d/m/Y', strtotime($order['created_at'])),
        'date_delivered' => $order['delivered_at'] ? date('d/m/Y', strtotime($order['delivered_at'])) : '-',
        'client' => $order['client_name'],
        'address' => $order['client_address'] . ' (' . $order['client_locality'] . ')',
        'phone' => $order['client_whatsapp'],
        'item' => $order['item'],
        'seller' => $order['seller_name'],
        'status' => ucfirst($order['status'])
    ];
}, $allOrders);

include 'includes/header.php';
?>

<!-- Librerías PDF -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.29/jspdf.plugin.autotable.min.js"></script>

<main class="flex-1 max-w-7xl mx-auto w-full p-4 sm:p-6 lg:p-8 fade-in">

    <div class="flex flex-col md:flex-row md:items-end justify-between gap-4 mb-8">
        <div class="flex items-center gap-4">
            <a href="dashboard.php" class="p-2 bg-slate-800 rounded-full text-slate-400 hover:text-white transition border border-slate-700">
                <i data-lucide="chevron-left"></i>
            </a>
            <div>
                <h1 class="text-2xl font-bold text-white tracking-tight">Gestión de Entregas</h1>
                <p class="text-sm text-slate-500 font-medium">Control de logística y despachos. (Total: <?= $total_registros ?> registros)</p>
            </div>
        </div>

        <!-- Botones de Reporte PDF -->
        <div class="flex gap-2">
            <button onclick="exportarPDF('pendientes')" class="bg-blue-600 hover:bg-blue-500 text-white px-4 py-2.5 rounded-xl transition flex items-center gap-2 text-sm font-bold shadow-lg shadow-blue-900/30">
                <i data-lucide="package" class="w-4 h-4"></i> PDF Pendientes
            </button>
            <button onclick="exportarPDF('entregados')" class="bg-emerald-600 hover:bg-emerald-500 text-white px-4 py-2.5 rounded-xl transition flex items-center gap-2 text-sm font-bold shadow-lg shadow-emerald-900/30">
                <i data-lucide="check-circle" class="w-4 h-4"></i> PDF Entregados
            </button>
        </div>
    </div>

    <!-- Contenedor de Tabla -->
    <div class="bg-slate-900 rounded-2xl border border-slate-800 shadow-xl overflow-hidden flex flex-col min-h-[600px]">
        <div class="overflow-x-auto flex-1">
            <table class="w-full text-left text-sm border-collapse">
                <thead class="bg-slate-950/50 text-slate-500 uppercase text-[10px] font-bold tracking-widest border-b border-slate-800">
                    <tr>
                        <th class="p-5 pl-6">#</th>
                        <th class="p-5">ID</th>
                        <th class="p-5">Fechas</th>
                        <th class="p-5">Cliente / Dirección</th>
                        <th class="p-5">Artículo / Monto</th>
                        <th class="p-5">Vendedor</th>
                        <th class="p-5">Estado</th>
                        <th class="p-5 text-right">Gestión</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/50">
                    <?php if (empty($orders)): ?>
                        <tr>
                            <td colspan="8" class="p-20 text-center text-slate-500 italic flex flex-col items-center justify-center w-full col-span-8">
                                <div class="bg-slate-800/50 p-4 rounded-full mb-4">
                                    <i data-lucide="truck" class="w-10 h-10 text-slate-600"></i>
                                </div>
                                <span class="text-lg">No hay entregas pendientes ni recientes.</span>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php 
                        $counter = $offset + 1; 
                        foreach ($orders as $order): 
                        ?>
                        <tr class="hover:bg-slate-800/30 transition-colors group">
                            <td class="p-5 pl-6 font-bold text-slate-600"><?= $counter++ ?></td>
                            <td class="p-5 font-mono text-slate-500">#<?= $order['id'] ?></td>
                            
                            <!-- Columna Fechas -->
                            <td class="p-5 text-slate-300 whitespace-nowrap">
                                <div class="flex flex-col gap-1">
                                    <div class="flex items-center gap-1.5">
                                        <span class="text-[9px] px-1.5 py-0.5 rounded bg-slate-800 text-slate-500 font-bold uppercase">Carga</span>
                                        <span class="text-xs"><?= date('d/m/Y', strtotime($order['created_at'])) ?></span>
                                    </div>
                                    <?php if ($order['status'] === 'entregado'): ?>
                                        <div class="flex items-center gap-1.5 mt-0.5">
                                            <span class="text-[9px] px-1.5 py-0.5 rounded bg-emerald-500/10 text-emerald-500 font-bold uppercase">Entrega</span>
                                            <span class="text-xs text-emerald-400"><?= date('d/m/Y', strtotime($order['delivered_at'])) ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </td>

                            <!-- Columna Cliente y Ubicación -->
                            <td class="p-5">
                                <div class="font-bold text-white"><?= htmlspecialchars($order['client_name']) ?></div>
                                <div class="text-xs text-slate-500 flex items-center gap-1 mt-1">
                                    <i data-lucide="map-pin" class="w-3 h-3 text-slate-600"></i> 
                                    <?= htmlspecialchars($order['client_address']) ?>
                                </div>
                                <?php if(!empty($order['client_map_link'])): ?>
                                    <a href="<?= htmlspecialchars($order['client_map_link']) ?>" target="_blank" class="text-[10px] text-blue-400 hover:text-blue-300 flex items-center gap-1 mt-1.5 transition">
                                        <i data-lucide="external-link" class="w-2.5 h-2.5"></i> Ver en Google Maps
                                    </a>
                                <?php endif; ?>
                            </td>

                            <!-- Columna Artículo y Financiero -->
                            <td class="p-5">
                                <div class="text-blue-300 font-medium"><?= htmlspecialchars($order['item']) ?></div>
                                <div class="flex items-center gap-2 mt-1">
                                    <span class="text-xs font-bold text-emerald-500">$<?= number_format($order['total_amount'], 0, ',', '.') ?></span>
                                    <span class="text-[10px] text-slate-600 italic"><?= $order['installments_count'] ?> x $<?= number_format($order['installment_amount'], 0, ',', '.') ?></span>
                                </div>
                            </td>

                            <!-- Columna Vendedor -->
                            <td class="p-5 text-slate-400">
                                <?php if ($can_view_profile): ?>
                                    <a href="perfil_vendedor.php?id=<?= $order['user_id'] ?>" class="hover:text-blue-400 hover:underline transition flex items-center gap-1 group/seller" title="Ficha del Vendedor">
                                        <?= htmlspecialchars($order['seller_name']) ?>
                                        <i data-lucide="external-link" class="w-3 h-3 opacity-0 group-hover/seller:opacity-100 transition-opacity"></i>
                                    </a>
                                <?php else: ?>
                                    <?= htmlspecialchars($order['seller_name']) ?>
                                <?php endif; ?>
                            </td>

                            <!-- Columna Estado -->
                            <td class="p-5">
                                <?php if ($order['status'] === 'aprobado'): ?>
                                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-wide border bg-emerald-500/10 text-emerald-400 border-emerald-500/20 shadow-sm shadow-emerald-950/20">
                                        <i data-lucide="package" class="w-3 h-3"></i> Pendiente
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-wide border bg-blue-500/10 text-blue-400 border-blue-500/20 shadow-sm shadow-blue-950/20">
                                        <i data-lucide="check-circle" class="w-3 h-3"></i> Entregado
                                    </span>
                                <?php endif; ?>
                            </td>

                            <!-- Columna Acciones -->
                            <td class="p-5 text-right">
                                <div class="flex justify-end gap-2 items-center">
                                    <a href="ver_ficha.php?id=<?= $order['id'] ?>" class="p-2 rounded-lg bg-slate-800 hover:bg-blue-600 text-blue-400 hover:text-white transition border border-slate-700 shadow-sm" title="Ver Ficha">
                                        <i data-lucide="search" class="w-4 h-4"></i>
                                    </a>

                                    <?php if ($order['status'] === 'aprobado'): ?>
                                        <!-- Botón Entregar -->
                                        <form method="POST" action="update_status.php" class="inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= $order['id'] ?>">
                                            <input type="hidden" name="status" value="entregado">
                                            <button type="submit" class="bg-blue-600 hover:bg-blue-500 text-white px-3 py-2 rounded-lg text-xs font-bold flex items-center gap-1.5 transition shadow-lg shadow-blue-900/30">
                                                <i data-lucide="truck" class="w-3.5 h-3.5"></i> Entregar
                                            </button>
                                        </form>

                                        <!-- Opciones extras solo para Admin/Sup -->
                                        <?php if (in_array($role, ['admin', 'supervisor'])): ?>
                                        <form method="POST" action="update_status.php" class="inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= $order['id'] ?>">
                                            <input type="hidden" name="status" value="revision">
                                            <button type="submit" class="p-2 rounded-lg bg-slate-800 hover:bg-yellow-600 text-yellow-500 hover:text-white transition border border-slate-700 shadow-sm" title="Devolver a Revisión">
                                                <i data-lucide="rotate-ccw" class="w-4 h-4"></i>
                                            </button>
                                        </form>
                                        <a href="rechazar_venta.php?id=<?= $order['id'] ?>" class="p-2 rounded-lg bg-slate-800 hover:bg-red-600 text-red-500 hover:text-white transition border border-slate-700 shadow-sm" title="Rechazar / Cancelar">
                                            <i data-lucide="x" class="w-4 h-4"></i>
                                        </a>
                                        <?php endif; ?>

                                    <?php else: ?>
                                        <!-- Botón para Anular Entrega (Solo Admin/Sup) -->
                                        <?php if (in_array($role, ['admin', 'supervisor'])): ?>
                                        <form method="POST" action="update_status.php" class="inline" onsubmit="return confirm('¿Confirma anular la entrega? El pedido volverá a estado pendiente.');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= $order['id'] ?>">
                                            <input type="hidden" name="status" value="aprobado">
                                            <button type="submit" class="p-2 rounded-lg bg-slate-800 hover:bg-red-600 text-red-400 hover:text-white transition border border-slate-700 shadow-sm" title="Anular Entrega">
                                                <i data-lucide="x-circle" class="w-4 h-4"></i>
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- PAGINACIÓN GLOBAL -->
        <div id="paginationContainer">
            <?= renderPagination($total_registros, $registros_por_pagina, $pagina_actual); ?>
        </div>
    </div>
</main>

<!-- Lógica de Exportación PDF -->
<script>
    const allDeliveryData = <?= json_encode($pdfData) ?>;

    function exportarPDF(tipo) {
        const { jsPDF } = window.jspdf;
        const doc = new jsPDF('p', 'mm', 'a4');
        const pageWidth = doc.internal.pageSize.getWidth();

        let filteredData = [];
        let title = "";
        let headRow = [];
        let columnStylesConfig = {};

        if (tipo === 'pendientes') {
            filteredData = allDeliveryData.filter(row => row.status === 'Aprobado');
            filteredData.sort((a, b) => new Date(a.raw_date) - new Date(b.raw_date));
            title = "Hoja de Ruta - Entregas Pendientes";
            headRow = [['#', 'ID', 'F. Carga', 'Cliente', 'Dirección', 'Celular', 'Artículo', 'Vendedor']];
            columnStylesConfig = {
                0: { cellWidth: 7, fontStyle: 'bold', halign: 'center' },
                1: { cellWidth: 10 }, 2: { cellWidth: 18 }, 3: { cellWidth: 25 }, 4: { cellWidth: 40 }, 5: { cellWidth: 22 }, 6: { cellWidth: 35 }, 7: { cellWidth: 20 }
            };
        } else if (tipo === 'entregados') {
            filteredData = allDeliveryData.filter(row => row.status === 'Entregado');
            filteredData.sort((a, b) => new Date(b.raw_delivered_at) - new Date(a.raw_delivered_at));
            title = "Reporte de Entregas Realizadas";
            headRow = [['#', 'ID', 'F. Carga', 'F. Entrega', 'Cliente', 'Dirección', 'Celular', 'Artículo']];
            columnStylesConfig = {
                0: { cellWidth: 7, fontStyle: 'bold', halign: 'center' },
                1: { cellWidth: 10 }, 2: { cellWidth: 18 }, 3: { cellWidth: 18 }, 4: { cellWidth: 25 }, 5: { cellWidth: 40 }, 6: { cellWidth: 22 }, 7: { cellWidth: 'auto' }
            };
        }

        if (filteredData.length === 0) {
            alert("No hay registros disponibles para generar este reporte.");
            return;
        }

        // Diseño del PDF
        doc.setFontSize(18);
        doc.setFont("helvetica", "bold");
        doc.text(title, pageWidth / 2, 15, { align: 'center' });
        
        doc.setFontSize(10);
        doc.setFont("helvetica", "normal");
        const meta = "Generado: " + new Date().toLocaleDateString() + " " + new Date().toLocaleTimeString();
        doc.text(meta, pageWidth / 2, 22, { align: 'center' });

        const tableBody = filteredData.map((row, i) => {
            if (tipo === 'pendientes') {
                return [i + 1, row.id, row.date_loaded, row.client, row.address, row.phone, row.item, row.seller];
            } else {
                return [i + 1, row.id, row.date_loaded, row.date_delivered, row.client, row.address, row.phone, row.item];
            }
        });

        doc.autoTable({ 
            head: headRow, 
            body: tableBody, 
            startY: 30, 
            theme: 'grid', 
            styles: { fontSize: 7, cellPadding: 2, lineColor: [0, 0, 0], lineWidth: 0.1, textColor: [0, 0, 0], overflow: 'linebreak' },
            headStyles: { fillColor: [255, 255, 255], textColor: [0, 0, 0], fontStyle: 'bolditalic', lineWidth: 0.1, lineColor: [0, 0, 0] },
            columnStyles: columnStylesConfig,
            margin: { top: 30, right: 14, bottom: 20, left: 14 }
        });

        window.open(doc.output('bloburl'), '_blank');
    }
</script>

<?php include 'includes/footer.php'; ?>