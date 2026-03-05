<?php
require 'includes/db.php';

// Seguridad básica de sesión
if (!isset($_SESSION['user_id'])) { 
    header("Location: index.php"); 
    exit; 
}

$id = $_GET['id'] ?? null;
$role = $_SESSION['role'];

// Permisos de Gestión (Admin, Supervisor y Verificador)
$can_manage = in_array($role, ['admin', 'supervisor', 'verificador']);

// 1. Obtener datos de la venta (Incluyendo aprobador y vendedor)
$sql = "SELECT 
            s.*, 
            seller.name as seller_name,
            approver.name as approved_by_name
        FROM sales s 
        JOIN users seller ON s.user_id = seller.id 
        LEFT JOIN users approver ON s.approved_by = approver.id
        WHERE s.id = ?";

$stmt = $pdo->prepare($sql);
$stmt->execute([$id]);
$order = $stmt->fetch();

if (!$order) die("Venta no encontrada");

// Seguridad: Vendedores solo ven sus propias ventas
if ($role === 'vendedor' && $order['user_id'] != $_SESSION['user_id']) {
    die("No tiene permiso para ver esta venta.");
}

// 2. OBTENER ARCHIVOS ADJUNTOS
$stmtFiles = $pdo->prepare("SELECT file_path FROM sale_files WHERE sale_id = ?");
$stmtFiles->execute([$id]);
$files = $stmtFiles->fetchAll(PDO::FETCH_ASSOC);

if (!empty($order['file_path'])) {
    $already_listed = false;
    foreach ($files as $f) {
        if ($f['file_path'] === $order['file_path']) {
            $already_listed = true;
            break;
        }
    }
    if (!$already_listed) {
        array_unshift($files, ['file_path' => $order['file_path']]);
    }
}

include 'includes/header.php';
?>

<main class="flex-1 max-w-5xl mx-auto w-full p-4 sm:p-6 lg:p-8 fade-in">

    <!-- Mensaje Flash -->
    <?php if (isset($_GET['msg']) && $_GET['msg'] == 'updated'): ?>
    <div class="mb-6 p-4 bg-blue-500/10 border border-blue-500/20 text-blue-400 rounded-xl flex items-center gap-3 shadow-lg animate-pulse">
        <i data-lucide="check-circle" class="w-5 h-5"></i> <span>Ficha actualizada correctamente.</span>
    </div>
    <?php endif; ?>

    <!-- Cabecera -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-8">
        <div class="flex items-center gap-4">
            <a href="javascript:history.back()" class="p-2 bg-slate-800 rounded-full text-slate-400 hover:text-white transition border border-slate-700">
                <i data-lucide="arrow-left"></i>
            </a>
            <div>
                <h1 class="text-2xl font-bold text-white tracking-tight">Ficha de Venta #<?= $order['id'] ?></h1>
                <p class="text-sm text-slate-500">Cargado por <span class="text-blue-400 font-bold"><?= htmlspecialchars($order['seller_name'] ?? 'Desconocido') ?></span></p>
            </div>
        </div>
        
        <?php if ($can_manage): ?>
        <button onclick="openEditModal()" class="flex items-center justify-center gap-2 bg-blue-600 hover:bg-blue-500 text-white px-6 py-3 rounded-xl font-bold transition shadow-lg shadow-blue-900/20">
            <i data-lucide="edit-3" class="w-4 h-4"></i> EDITAR FICHA
        </button>
        <?php endif; ?>
    </div>

    <div class="bg-slate-900 rounded-3xl border border-slate-800 shadow-2xl overflow-hidden mb-12">
        <!-- Pipeline Visual de Estado -->
        <?php
        $pipelineSteps = [
            ['key' => 'revision',  'label' => 'En Revisión', 'icon' => 'clock'],
            ['key' => 'aprobado',  'label' => 'Aprobado',    'icon' => 'check-circle'],
            ['key' => 'entregado', 'label' => 'Entregado',   'icon' => 'truck'],
        ];
        $currentStatus  = $order['status'];
        $currentStepIdx = array_search($currentStatus, array_column($pipelineSteps, 'key'));
        $isRejected     = ($currentStatus === 'rechazado');
        ?>
        <div class="px-6 py-5 border-b border-slate-800 bg-slate-950/40">
            <?php if ($isRejected): ?>
            <div class="flex items-center justify-between gap-4">
                <div class="flex items-center gap-3">
                    <div class="flex h-9 w-9 items-center justify-center rounded-full bg-red-500/10 border border-red-500/30 shrink-0">
                        <i data-lucide="x" class="w-4 h-4 text-red-400"></i>
                    </div>
                    <div>
                        <p class="text-[10px] uppercase font-bold tracking-widest text-red-500">Operación Rechazada</p>
                        <p class="text-xs text-slate-500 mt-0.5">Esta venta no fue aprobada</p>
                    </div>
                </div>
                <span class="inline-flex items-center gap-1.5 px-4 py-1.5 rounded-full text-xs font-bold uppercase border bg-red-500/10 text-red-400 border-red-500/20 shrink-0">
                    <span class="w-1.5 h-1.5 rounded-full bg-red-500"></span> Rechazado
                </span>
            </div>
            <?php else: ?>
            <div class="flex items-center max-w-sm mx-auto sm:max-w-md">
                <?php foreach ($pipelineSteps as $i => $step):
                    $isCompleted = $currentStepIdx !== false && $i < $currentStepIdx;
                    $isCurrent   = $currentStepIdx !== false && $i === $currentStepIdx;
                ?>
                <?php if ($i > 0): ?>
                    <div class="flex-1 h-0.5 mx-2 rounded-full <?= $isCompleted ? 'bg-emerald-500' : 'bg-slate-700' ?> transition-all duration-500"></div>
                <?php endif; ?>
                <div class="flex flex-col items-center gap-1.5 shrink-0">
                    <div class="w-9 h-9 rounded-full flex items-center justify-center transition-all duration-300
                        <?php if ($isCompleted): ?>bg-emerald-500 text-white shadow-lg shadow-emerald-500/30
                        <?php elseif ($isCurrent): ?>bg-blue-600 text-white shadow-lg shadow-blue-500/30 ring-4 ring-blue-500/20
                        <?php else: ?>bg-slate-800 text-slate-600 border border-slate-700<?php endif; ?>">
                        <?php if ($isCompleted): ?>
                            <i data-lucide="check" class="w-4 h-4"></i>
                        <?php else: ?>
                            <i data-lucide="<?= $step['icon'] ?>" class="w-4 h-4"></i>
                        <?php endif; ?>
                    </div>
                    <span class="text-[9px] uppercase font-bold tracking-wider whitespace-nowrap
                        <?php if ($isCompleted): ?>text-emerald-400<?php elseif ($isCurrent): ?>text-blue-400<?php else: ?>text-slate-600<?php endif; ?>">
                        <?= $step['label'] ?>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <div class="p-8 space-y-10">

            <!-- BLOQUE DE AUDITORÍA: APROBACIÓN (Restaurado) -->
            <?php if (in_array($order['status'], ['aprobado', 'entregado']) && !empty($order['approved_at'])): ?>
            <div class="bg-emerald-500/5 border border-emerald-500/20 p-5 rounded-2xl flex items-start gap-4">
                <div class="bg-emerald-500/10 p-2 rounded-xl text-emerald-500 shrink-0">
                    <i data-lucide="shield-check" class="w-6 h-6"></i>
                </div>
                <div>
                    <h4 class="text-emerald-500 uppercase text-[10px] font-bold tracking-widest mb-1">Crédito Aprobado</h4>
                    <p class="text-white text-sm font-medium">
                        Verificado por <span class="text-emerald-400"><?= htmlspecialchars($order['approved_by_name'] ?? 'Sistema') ?></span>
                    </p>
                    <p class="text-slate-500 text-xs mt-1">
                        Fecha y Hora: <?= date('d/m/Y - H:i', strtotime($order['approved_at'])) ?> hs.
                    </p>
                </div>
            </div>
            <?php endif; ?>

            <!-- BLOQUE DE AUDITORÍA: RECHAZO -->
            <?php if ($order['status'] === 'rechazado'): ?>
            <div class="bg-red-500/5 border border-red-500/20 p-5 rounded-2xl flex items-start gap-4">
                <div class="bg-red-500/10 p-2 rounded-xl text-red-500 shrink-0">
                    <i data-lucide="alert-triangle" class="w-6 h-6"></i>
                </div>
                <div>
                    <h4 class="text-red-500 uppercase text-[10px] font-bold tracking-widest mb-1">Operación Rechazada</h4>
                    <p class="text-red-200 text-sm italic">"<?= htmlspecialchars($order['rejected_reason'] ?? 'Sin motivo especificado') ?>"</p>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- SECCIÓN: DATOS DEL CLIENTE -->
            <div class="grid lg:grid-cols-2 gap-12 mt-6">
                <div class="space-y-6">
                    <div class="flex items-center gap-2 text-blue-400 border-b border-slate-800 pb-2">
                        <i data-lucide="user" class="w-4 h-4"></i>
                        <h3 class="font-bold uppercase text-xs tracking-wider">Datos del Cliente</h3>
                    </div>
                    <div class="grid grid-cols-1 gap-5 text-sm">
                        <div>
                            <p class="text-slate-500 text-[10px] uppercase font-bold mb-1">Nombre y Apellido</p>
                            <p class="text-white text-lg font-bold"><?= htmlspecialchars($order['client_name'] ?? '-') ?></p>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <p class="text-slate-500 text-[10px] uppercase font-bold mb-1">DNI</p>
                                <p class="text-slate-300 font-mono"><?= htmlspecialchars($order['client_dni'] ?? '-') ?></p>
                            </div>
                            <div>
                                <p class="text-slate-500 text-[10px] uppercase font-bold mb-1">WhatsApp</p>
                                <a href="https://wa.me/<?= preg_replace('/[^0-9]/', '', $order['client_whatsapp'] ?? '') ?>" target="_blank" class="text-emerald-400 font-bold flex items-center gap-1 hover:text-emerald-300 transition">
                                    <i data-lucide="message-circle" class="w-3 h-3"></i> <?= htmlspecialchars($order['client_whatsapp'] ?? '-') ?>
                                </a>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <p class="text-slate-500 text-[10px] uppercase font-bold mb-1">Nro Llamada</p>
                                <p class="text-slate-300"><?= htmlspecialchars($order['client_phone'] ?? '-') ?></p>
                            </div>
                            <div>
                                <p class="text-slate-500 text-[10px] uppercase font-bold mb-1">Localidad</p>
                                <p class="text-slate-300"><?= htmlspecialchars($order['client_locality'] ?? '-') ?></p>
                            </div>
                        </div>
                        <div>
                            <p class="text-slate-500 text-[10px] uppercase font-bold mb-1">Domicilio / Barrio</p>
                            <p class="text-slate-200"><?= htmlspecialchars($order['client_address'] ?? '-') ?></p>
                            <p class="text-slate-500 text-xs mt-0.5">Barrio: <?= htmlspecialchars($order['client_neighborhood'] ?? 'No especificado') ?></p>
                        </div>

                        <?php if(!empty($order['client_map_link'])): ?>
                        <div class="pt-2">
                            <a href="<?= htmlspecialchars($order['client_map_link']) ?>" target="_blank" class="w-full sm:w-auto inline-flex items-center justify-center gap-3 text-blue-400 bg-blue-400/10 px-5 py-3 rounded-2xl text-xs font-bold hover:bg-blue-600 hover:text-white transition border border-blue-400/20 shadow-lg shadow-blue-950/20">
                                <i data-lucide="map-pin" class="w-5 h-5"></i> ABRIR UBICACIÓN GOOGLE MAPS
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- SECCIÓN: DATOS LABORALES -->
                <div class="space-y-6">
                    <div class="flex items-center gap-2 text-indigo-400 border-b border-slate-800 pb-2">
                        <i data-lucide="briefcase" class="w-4 h-4"></i>
                        <h3 class="font-bold uppercase text-xs tracking-wider">Información Laboral</h3>
                    </div>
                    <div class="grid grid-cols-2 gap-5 text-sm">
                        <div>
                            <p class="text-slate-500 text-[10px] uppercase font-bold mb-1">Tipo de Empleo</p>
                            <p class="text-white"><?= htmlspecialchars($order['job_type'] ?? '-') ?></p>
                        </div>
                        <div>
                            <p class="text-slate-500 text-[10px] uppercase font-bold mb-1">Ocupación</p>
                            <p class="text-slate-300"><?= htmlspecialchars($order['job_occupation'] ?? '-') ?></p>
                        </div>
                        <div class="col-span-2">
                            <p class="text-slate-500 text-[10px] uppercase font-bold mb-1">Nombre de la Empresa</p>
                            <p class="text-white font-medium text-base"><?= htmlspecialchars($order['job_name'] ?? '-') ?></p>
                        </div>
                        <div class="col-span-2">
                            <p class="text-slate-500 text-[10px] uppercase font-bold mb-1">Domicilio Laboral</p>
                            <p class="text-slate-300"><?= htmlspecialchars($order['job_address'] ?? '-') ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- SECCIÓN: DETALLE ECONÓMICO -->
            <div class="bg-slate-950/50 p-8 rounded-3xl border border-slate-800 shadow-inner">
                <div class="flex items-center gap-2 text-emerald-400 mb-8 border-b border-slate-800/50 pb-4">
                    <i data-lucide="dollar-sign" class="w-5 h-5"></i>
                    <h3 class="font-bold uppercase text-xs tracking-widest">Plan de Pago y Producto</h3>
                </div>

                <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-8">
                    <div class="col-span-2 md:col-span-4 lg:col-span-2">
                        <p class="text-slate-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Artículo</p>
                        <p class="text-white text-lg font-bold"><?= htmlspecialchars($order['item'] ?? '-') ?></p>
                    </div>
                    <div>
                        <p class="text-slate-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Frecuencia</p>
                        <span class="text-blue-400 font-bold uppercase text-[10px] px-2 py-1 bg-blue-400/10 border border-blue-400/20 rounded-lg">
                            <?= htmlspecialchars($order['payment_frequency'] ?? 'Semanal') ?>
                        </span>
                    </div>
                    <div>
                        <p class="text-slate-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Día Cobro</p>
                        <p class="text-emerald-400 font-bold uppercase text-xs"><?= htmlspecialchars($order['payment_day'] ?? '-') ?></p>
                    </div>
                    <div>
                        <p class="text-slate-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Cuotas</p>
                        <p class="text-white font-medium"><?= $order['installments_count'] ?? 0 ?> pagos</p>
                    </div>
                    <div>
                        <p class="text-slate-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Monto Cuota</p>
                        <p class="text-white font-bold">$<?= number_format($order['installment_amount'] ?? 0, 0, ',', '.') ?></p>
                    </div>
                </div>

                <div class="mt-8 pt-6 border-t border-slate-800/50 flex flex-col md:flex-row justify-between items-end gap-4">
                    <div>
                        <p class="text-[10px] text-slate-500 uppercase font-bold mb-1">Adelanto / Entrega</p>
                        <p class="text-slate-300 font-bold text-xl">$<?= number_format($order['down_payment'] ?? 0, 0, ',', '.') ?></p>
                    </div>
                    <div class="text-right">
                        <p class="text-[10px] text-emerald-500 uppercase font-bold tracking-widest mb-1">Valor Total Final</p>
                        <p class="text-emerald-400 font-black text-4xl">$<?= number_format($order['total_amount'] ?? 0, 0, ',', '.') ?></p>
                    </div>
                </div>
            </div>

            <!-- OBSERVACIONES -->
            <?php if (!empty($order['observations'])): ?>
            <div class="bg-yellow-900/10 border border-yellow-500/20 p-6 rounded-2xl">
                <div class="flex items-center gap-2 mb-3">
                    <i data-lucide="message-square" class="text-yellow-500 w-4 h-4"></i>
                    <strong class="text-yellow-500 uppercase text-[10px] font-bold tracking-widest">Notas de la Venta</strong>
                </div>
                <p class="text-yellow-100/80 text-sm italic whitespace-pre-wrap leading-relaxed"><?= htmlspecialchars($order['observations']) ?></p>
            </div>
            <?php endif; ?>

            <!-- DOCUMENTACIÓN ADJUNTA -->
            <?php if (!empty($files)): ?>
            <div class="space-y-4">
                <div class="flex items-center gap-2 text-purple-400 border-b border-slate-800 pb-2">
                    <i data-lucide="files" class="w-4 h-4"></i>
                    <h3 class="font-bold uppercase text-xs tracking-wider">Documentación Adjunta</h3>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
                    <?php foreach ($files as $file): ?>
                    <a href="uploads/<?= htmlspecialchars($file['file_path'] ?? '') ?>" target="_blank" class="flex items-center gap-3 bg-slate-950 p-4 rounded-2xl border border-slate-800 hover:border-purple-500/50 transition group shadow-lg">
                        <div class="bg-slate-900 p-2.5 rounded-lg border border-slate-800 group-hover:bg-purple-600 group-hover:text-white transition-colors">
                            <i data-lucide="<?= strpos(strtolower($file['file_path'] ?? ''), '.pdf') !== false ? 'file-text' : 'image' ?>" class="w-5 h-5"></i>
                        </div>
                        <span class="text-[10px] text-slate-400 truncate flex-1"><?= htmlspecialchars($file['file_path'] ?? '') ?></span>
                        <i data-lucide="external-link" class="w-4 h-4 text-slate-700 group-hover:text-purple-400"></i>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- ACCIONES DE GESTIÓN (Auditores) -->
        <?php if ($can_manage && $order['status'] === 'revision'): ?>
        <div class="bg-slate-950/80 backdrop-blur p-8 border-t border-slate-800 flex flex-col sm:flex-row justify-end gap-4 items-center">
            <div class="text-slate-500 text-[10px] uppercase font-bold tracking-widest mr-auto hidden sm:block">
                <i data-lucide="shield-check" class="inline w-3.5 h-3.5 mr-1 text-blue-500"></i> Auditoría Pendiente
            </div>
            <a href="rechazar_venta.php?id=<?= $order['id'] ?>" class="w-full sm:w-auto px-10 py-4 bg-red-600/10 hover:bg-red-600 text-red-500 hover:text-white font-bold rounded-2xl transition border border-red-600/20 flex justify-center items-center gap-2">
                <i data-lucide="x-circle" class="w-5 h-5"></i> Rechazar
            </a>
            <form method="POST" action="update_status.php" class="w-full sm:w-auto">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $order['id'] ?>">
                <input type="hidden" name="status" value="aprobado">
                <button type="submit" class="w-full px-10 py-4 bg-emerald-600 hover:bg-emerald-500 text-white font-bold rounded-2xl shadow-xl shadow-emerald-900/30 transition transform hover:-translate-y-1 flex justify-center items-center gap-2">
                    <i data-lucide="check-circle" class="w-5 h-5"></i> Aprobar Venta
                </button>
            </form>
        </div>
        <?php endif; ?>
    </div>
</main>

<!-- MODAL DE EDICIÓN TOTAL -->
<?php if ($can_manage): ?>
<div id="editModal" class="fixed inset-0 bg-slate-950/75 backdrop-blur-xl hidden items-center justify-center p-4 z-50 overflow-y-auto">
    <div class="bg-slate-900/95 border border-slate-700/60 rounded-3xl shadow-2xl shadow-black/60 ring-1 ring-white/5 w-full max-w-4xl p-6 sm:p-10 my-8 transform transition-all">
        <div class="flex justify-between items-center mb-8 border-b border-slate-800 pb-4">
            <h3 class="text-xl font-bold text-white flex items-center gap-3">
                <i data-lucide="settings-2" class="text-blue-500 w-6 h-6"></i> Edición Integral de la Ficha
            </h3>
            <button onclick="closeEditModal()" class="text-slate-500 hover:text-white transition bg-slate-800 p-2 rounded-full"><i data-lucide="x" class="w-5 h-5"></i></button>
        </div>

        <form action="update_sale_full.php" method="POST" class="space-y-8">
            <?= csrf_field() ?>
            <input type="hidden" name="sale_id" value="<?= $order['id'] ?>">
            
            <div class="grid md:grid-cols-2 gap-10">
                <!-- Columna 1: Cliente -->
                <div class="space-y-4">
                    <h4 class="text-blue-400 text-xs font-bold uppercase tracking-widest border-l-4 border-blue-500 pl-3">Datos del Cliente</h4>
                    
                    <div class="grid grid-cols-2 gap-4">
                        <div class="col-span-2">
                            <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1 ml-1">Nombre Completo</label>
                            <input type="text" name="client_name" value="<?= htmlspecialchars($order['client_name'] ?? '') ?>" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white focus:border-blue-500 outline-none transition">
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1 ml-1">DNI</label>
                            <input type="text" name="client_dni" value="<?= htmlspecialchars($order['client_dni'] ?? '') ?>" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white focus:border-blue-500 outline-none transition font-mono">
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1 ml-1">WhatsApp</label>
                            <input type="text" name="client_whatsapp" value="<?= htmlspecialchars($order['client_whatsapp'] ?? '') ?>" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white focus:border-blue-500 outline-none transition font-mono">
                        </div>
                        <div class="col-span-2">
                            <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1 ml-1">Nro Llamada (Opcional)</label>
                            <input type="text" name="client_phone" value="<?= htmlspecialchars($order['client_phone'] ?? '') ?>" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white focus:border-blue-500 outline-none transition font-mono">
                        </div>
                        <div class="col-span-2">
                            <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1 ml-1">Domicilio</label>
                            <input type="text" name="client_address" value="<?= htmlspecialchars($order['client_address'] ?? '') ?>" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white focus:border-blue-500 outline-none transition">
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1 ml-1">Barrio</label>
                            <input type="text" name="client_neighborhood" value="<?= htmlspecialchars($order['client_neighborhood'] ?? '') ?>" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white focus:border-blue-500 outline-none transition">
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1 ml-1">Localidad</label>
                            <input type="text" name="client_locality" value="<?= htmlspecialchars($order['client_locality'] ?? '') ?>" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white focus:border-blue-500 outline-none transition">
                        </div>
                        <div class="col-span-2">
                            <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1 ml-1 text-emerald-400">Enlace Google Maps</label>
                            <div class="relative">
                                <input type="text" name="client_map_link" value="<?= htmlspecialchars($order['client_map_link'] ?? '') ?>" placeholder="Pegue aquí el enlace de ubicación" class="w-full bg-slate-950 border border-emerald-900/50 rounded-xl px-4 py-2.5 text-white focus:border-emerald-500 outline-none transition">
                                <div class="absolute right-3 top-1/2 -translate-y-1/2 text-emerald-500"><i data-lucide="map-pin" class="w-4 h-4"></i></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Columna 2: Laboral y Venta -->
                <div class="space-y-8">
                    <!-- Laboral -->
                    <div class="space-y-4">
                        <h4 class="text-indigo-400 text-xs font-bold uppercase tracking-widest border-l-4 border-indigo-500 pl-3">Datos Laborales</h4>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1 ml-1">Tipo Empleo</label>
                                <input type="text" name="job_type" value="<?= htmlspecialchars($order['job_type'] ?? '') ?>" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white focus:border-indigo-500 outline-none transition">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1 ml-1">Ocupación</label>
                                <input type="text" name="job_occupation" value="<?= htmlspecialchars($order['job_occupation'] ?? '') ?>" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white focus:border-indigo-500 outline-none transition">
                            </div>
                            <div class="col-span-2">
                                <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1 ml-1">Empresa</label>
                                <input type="text" name="job_name" value="<?= htmlspecialchars($order['job_name'] ?? '') ?>" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white focus:border-indigo-500 outline-none transition">
                            </div>
                            <div class="col-span-2">
                                <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1 ml-1">Dirección Laboral</label>
                                <input type="text" name="job_address" value="<?= htmlspecialchars($order['job_address'] ?? '') ?>" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white focus:border-indigo-500 outline-none transition">
                            </div>
                        </div>
                    </div>

                    <!-- Venta -->
                    <div class="space-y-4">
                        <h4 class="text-emerald-400 text-xs font-bold uppercase tracking-widest border-l-4 border-emerald-500 pl-3">Plan de Pago</h4>
                        <div class="grid grid-cols-2 gap-4">
                            <div class="col-span-2">
                                <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1 ml-1">Artículo</label>
                                <input type="text" name="item" value="<?= htmlspecialchars($order['item'] ?? '') ?>" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white focus:border-emerald-500 outline-none transition" required>
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1 ml-1">Frecuencia</label>
                                <select name="payment_frequency" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white outline-none transition appearance-none cursor-pointer">
                                    <option value="semanal" <?= (($order['payment_frequency'] ?? 'semanal') == 'semanal') ? 'selected' : '' ?>>Semanal</option>
                                    <option value="quincenal" <?= (($order['payment_frequency'] ?? '') == 'quincenal') ? 'selected' : '' ?>>Quincenal</option>
                                    <option value="mensual" <?= (($order['payment_frequency'] ?? '') == 'mensual') ? 'selected' : '' ?>>Mensual</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1 ml-1">Día Cobro</label>
                                <select name="payment_day" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white outline-none transition appearance-none cursor-pointer">
                                    <?php $dias = ['Lunes','Martes','Miércoles','Jueves','Viernes','Sábado']; 
                                    foreach($dias as $d): ?>
                                        <option value="<?= $d ?>" <?= (($order['payment_day'] ?? '') == $d) ? 'selected' : '' ?>><?= $d ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1 ml-1">Cant. Cuotas</label>
                                <input type="number" id="edit_cuotas" name="installments" value="<?= $order['installments_count'] ?? 0 ?>" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white outline-none" oninput="recalculateTotal()" required>
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1 ml-1">Valor Cuota ($)</label>
                                <input type="number" id="edit_monto" name="amount" value="<?= $order['installment_amount'] ?? 0 ?>" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white outline-none" oninput="recalculateTotal()" required>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-emerald-900/10 border border-emerald-500/20 p-6 rounded-3xl flex justify-between items-center px-6 sm:px-12">
                <span class="text-xs uppercase font-bold text-emerald-500 tracking-widest">Nuevo Total Calculado</span>
                <div id="edit_total" class="text-3xl font-black text-white">$<?= number_format($order['total_amount'] ?? 0, 0, ',', '.') ?></div>
            </div>

            <div class="flex gap-4 pt-4">
                <button type="button" onclick="closeEditModal()" class="flex-1 py-4 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded-2xl transition font-bold uppercase text-xs">Cancelar</button>
                <button type="submit" class="flex-1 py-4 bg-blue-600 hover:bg-blue-500 text-white rounded-2xl transition font-bold uppercase text-xs shadow-xl shadow-blue-900/30">Guardar Cambios</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openEditModal() {
        const modal = document.getElementById('editModal');
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        document.body.style.overflow = 'hidden';
        lucide.createIcons();
    }

    function closeEditModal() {
        const modal = document.getElementById('editModal');
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        document.body.style.overflow = 'auto';
    }

    function recalculateTotal() {
        const cuotas = parseFloat(document.getElementById('edit_cuotas').value) || 0;
        const monto = parseFloat(document.getElementById('edit_monto').value) || 0;
        const total = cuotas * monto;
        document.getElementById('edit_total').innerText = '$' + total.toLocaleString('es-AR');
    }
</script>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>