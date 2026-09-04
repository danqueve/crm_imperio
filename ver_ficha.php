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
$can_assign = in_array($role, ['admin', 'supervisor']);

// 1. Obtener datos de la venta (Incluyendo aprobador, vendedor y verificador asignado)
$sql = "SELECT
            s.*,
            seller.name as seller_name,
            approver.name as approved_by_name,
            verifier.name as assigned_verifier_name
        FROM sales s
        JOIN users seller ON s.user_id = seller.id
        LEFT JOIN users approver ON s.approved_by = approver.id
        LEFT JOIN users verifier ON s.assigned_verifier_id = verifier.id
        WHERE s.id = ?";

$stmt = $pdo->prepare($sql);
$stmt->execute([$id]);
$order = $stmt->fetch();

if (!$order) die("Venta no encontrada");

// Verificadores activos disponibles para asignar (solo se usa si $can_assign)
$verifiers = $can_assign
    ? $pdo->query("SELECT id, name FROM users WHERE role = 'verificador' AND is_active = 1 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC)
    : [];

// Seguridad: Vendedores solo ven sus propias ventas
// Entregadores pueden ver sus ventas + cualquier venta aprobada/entregada (para gestionar la entrega)
if ($role === 'vendedor' && $order['user_id'] != $_SESSION['user_id']) {
    die("No tiene permiso para ver esta venta.");
}
if ($role === 'entregador'
    && $order['user_id'] != $_SESSION['user_id']
    && !in_array($order['status'], ['aprobado', 'entregado'])) {
    die("No tiene permiso para ver esta venta.");
}

// 2. OBTENER ARCHIVOS ADJUNTOS
$stmtFiles = $pdo->prepare("SELECT file_path FROM sale_files WHERE sale_id = ?");
$stmtFiles->execute([$id]);
$files = $stmtFiles->fetchAll(PDO::FETCH_ASSOC);

include 'includes/header.php';
?>

<main class="flex-1 max-w-5xl mx-auto w-full p-4 sm:p-6 lg:p-8 fade-in">

    <!-- Mensajes Flash -->
    <?php if (isset($_GET['msg']) && $_GET['msg'] == 'updated'): ?>
    <div class="mb-6 p-4 bg-blue-500/10 border border-blue-500/20 text-blue-400 rounded-xl flex items-center gap-3 shadow-lg animate-pulse">
        <i data-lucide="check-circle" class="w-5 h-5"></i> <span>Ficha actualizada correctamente.</span>
    </div>
    <?php elseif (isset($_GET['msg']) && $_GET['msg'] == 'file_deleted'): ?>
    <div class="mb-6 p-4 bg-red-500/10 border border-red-500/20 text-red-400 rounded-xl flex items-center gap-3 shadow-lg">
        <i data-lucide="trash-2" class="w-5 h-5"></i> <span>Archivo eliminado correctamente.</span>
    </div>
    <?php elseif (isset($_GET['msg']) && $_GET['msg'] == 'error_delete'): ?>
    <div class="mb-6 p-4 bg-yellow-500/10 border border-yellow-500/20 text-yellow-400 rounded-xl flex items-center gap-3 shadow-lg">
        <i data-lucide="alert-triangle" class="w-5 h-5"></i> <span>No se pudo eliminar el archivo.</span>
    </div>
    <?php elseif (isset($_GET['msg']) && $_GET['msg'] == 'error'):
        $field_messages = [
            'telefono'         => 'El número de llamada (teléfono alternativo) es obligatorio.',
            'maps'             => 'La ubicación de Google Maps es obligatoria (debe ser un enlace válido).',
            'whatsapp_formato' => 'El WhatsApp debe contener solo dígitos (7 a 15 caracteres).',
            'telefono_formato' => 'El teléfono alternativo tiene un formato inválido.',
            'tipo_rechazo'     => 'No se pudo reclasificar el rechazo: tipo inválido o la venta no está rechazada.',
            'explicacion_requerida' => 'Para dejar el rechazo como "No es potable" tiene que haber una explicación cargada.',
        ];
        $fields = array_filter(explode(',', $_GET['fields'] ?? ''));
        $specific_msgs = array_filter(array_map(fn($f) => $field_messages[$f] ?? null, $fields));
    ?>
    <div class="mb-6 p-4 bg-red-500/10 border border-red-500/20 text-red-400 rounded-xl flex items-center gap-3 shadow-lg">
        <i data-lucide="alert-octagon" class="w-5 h-5"></i> <span><?= !empty($specific_msgs) ? implode(' ', $specific_msgs) : 'Ocurrió un error al guardar los cambios. Intente nuevamente.' ?></span>
    </div>
    <?php endif; ?>

    <!-- Cabecera -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-8">
        <div class="flex items-center gap-4">
            <a href="javascript:history.back()" class="p-2 rounded-full transition" style="background:var(--card);border:1.5px solid var(--line);color:var(--ink-2);">
                <i data-lucide="arrow-left"></i>
            </a>
            <div>
                <h1 class="text-2xl font-bold tracking-tight flex items-center gap-2" style="color:var(--ink);">
                    Ficha de Venta #<?= $order['id'] ?>
                    <?php if (($order['sale_type'] ?? 'credito') === 'contado'): ?>
                    <span class="text-[10px] font-bold uppercase px-2 py-0.5 rounded-full" style="background:var(--apr-bg);color:var(--apr-ink);">Contado</span>
                    <?php endif; ?>
                    <?php if (($order['payment_method'] ?? 'normal') === 'rapicompra'): ?>
                    <span class="text-[10px] font-bold uppercase px-2 py-0.5 rounded-full" style="background:var(--accent-soft);color:var(--accent-ink);">RapiCompra</span>
                    <?php endif; ?>
                </h1>
                <p class="text-sm font-medium" style="color:var(--ink-2);">Cargado por <span class="text-blue-500 font-bold"><?= htmlspecialchars($order['seller_name'] ?? 'Desconocido') ?></span></p>
            </div>
        </div>

        <?php if ($can_manage): ?>
        <button onclick="openEditModal()" class="flex items-center justify-center gap-2 bg-blue-600 hover:bg-blue-500 text-white px-6 py-3 rounded-xl font-bold transition shadow-lg shadow-blue-900/20">
            <i data-lucide="edit-3" class="w-4 h-4"></i> EDITAR FICHA
        </button>
        <?php elseif ($role === 'vendedor' && $order['user_id'] == $_SESSION['user_id'] && $order['status'] === 'revision'): ?>
        <a href="editar_venta.php?id=<?= $order['id'] ?>" class="flex items-center justify-center gap-2 bg-blue-600 hover:bg-blue-500 text-white px-6 py-3 rounded-xl font-bold transition shadow-lg shadow-blue-900/20">
            <i data-lucide="edit-3" class="w-4 h-4"></i> EDITAR MI VENTA
        </a>
        <?php endif; ?>
    </div>

    <div class="rounded-3xl overflow-hidden mb-12" style="background:var(--card);border:1.5px solid var(--line);box-shadow:var(--shadow-card);">
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
        <div class="px-6 py-5" style="background:var(--paper);border-bottom:1.5px solid var(--line);">
            <?php if ($isRejected): ?>
            <div class="flex items-center justify-between gap-4">
                <div class="flex items-center gap-3">
                    <div class="flex h-9 w-9 items-center justify-center rounded-full shrink-0" style="background:var(--rec-bg);border:1px solid var(--rec-ink);">
                        <i data-lucide="x" class="w-4 h-4" style="color:var(--rec-ink);"></i>
                    </div>
                    <div>
                        <p class="text-[10px] uppercase font-bold tracking-widest" style="color:var(--rec-ink);">Operación Rechazada</p>
                        <p class="text-xs mt-0.5" style="color:var(--ink-2);">Esta venta no fue aprobada</p>
                    </div>
                </div>
                <span class="inline-flex items-center gap-1.5 px-4 py-1.5 rounded-full text-xs font-bold uppercase border shrink-0" style="background:var(--rec-bg);color:var(--rec-ink);border-color:var(--rec-ink);">
                    <span class="w-1.5 h-1.5 rounded-full" style="background:var(--rec-ink);"></span> Rechazado
                </span>
            </div>
            <?php else: ?>
            <div class="flex items-center max-w-sm mx-auto sm:max-w-md">
                <?php foreach ($pipelineSteps as $i => $step):
                    $isCompleted = $currentStepIdx !== false && $i < $currentStepIdx;
                    $isCurrent   = $currentStepIdx !== false && $i === $currentStepIdx;
                ?>
                <?php if ($i > 0): ?>
                    <div class="flex-1 h-0.5 mx-2 rounded-full transition-all duration-500" style="background:<?= $isCompleted ? '#10b981' : 'var(--line)' ?>;"></div>
                <?php endif; ?>
                <div class="flex flex-col items-center gap-1.5 shrink-0">
                    <div class="w-9 h-9 rounded-full flex items-center justify-center transition-all duration-300
                        <?php if ($isCompleted): ?>shadow-lg<?php elseif ($isCurrent): ?>ring-4 shadow-lg<?php endif; ?>"
                        style="<?php if ($isCompleted): ?>background:#10b981;color:#fff;box-shadow:0 4px 12px rgba(16,185,129,.3);
                               <?php elseif ($isCurrent): ?>background:var(--accent);color:#fff;box-shadow:0 4px 12px rgba(99,102,241,.3);ring-color:rgba(99,102,241,.2);
                               <?php else: ?>background:var(--paper);color:var(--ink-3);border:1.5px solid var(--line);<?php endif; ?>">
                        <?php if ($isCompleted): ?>
                            <i data-lucide="check" class="w-4 h-4"></i>
                        <?php else: ?>
                            <i data-lucide="<?= $step['icon'] ?>" class="w-4 h-4"></i>
                        <?php endif; ?>
                    </div>
                    <span class="text-[9px] uppercase font-bold tracking-wider whitespace-nowrap"
                        style="color:<?php if ($isCompleted): ?>#10b981<?php elseif ($isCurrent): ?>var(--accent)<?php else: ?>var(--ink-3)<?php endif; ?>;">
                        <?= $step['label'] ?>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <div class="p-8 space-y-10">

            <!-- BLOQUE DE VERIFICACIÓN ASIGNADA -->
            <?php if ($can_manage && $order['status'] === 'revision'): ?>
            <div class="p-5 rounded-2xl flex flex-col sm:flex-row sm:items-center justify-between gap-4" style="background:var(--accent-soft);border:1px solid rgba(99,102,241,.3);">
                <div class="flex items-start gap-4">
                    <div class="p-2 rounded-xl shrink-0" style="background:rgba(99,102,241,.12);color:var(--accent-ink);">
                        <i data-lucide="user-check" class="w-6 h-6"></i>
                    </div>
                    <div>
                        <h4 class="uppercase text-[10px] font-bold tracking-widest mb-1" style="color:var(--accent-ink);">Verificación</h4>
                        <p class="text-sm font-medium" style="color:var(--ink);">
                            <?php if (!empty($order['assigned_verifier_name'])): ?>
                                Asignada a <span style="color:var(--accent-ink);font-weight:700;"><?= htmlspecialchars($order['assigned_verifier_name']) ?></span>
                                <?php if (!empty($order['assigned_at'])): ?>
                                <span class="font-mono text-xs" style="color:var(--ink-3);">· <?= date('d/m/Y H:i', strtotime($order['assigned_at'])) ?></span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="italic" style="color:var(--ink-3);">Sin verificador asignado</span>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>

                <?php if ($can_assign): ?>
                <form method="POST" action="asignar_verificador.php" class="flex items-center gap-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="sale_id" value="<?= $order['id'] ?>">
                    <select name="verifier_id" class="input-light text-xs min-h-[44px] py-2 px-3 rounded-lg cursor-pointer min-w-[160px]">
                        <option value="">Sin asignar</option>
                        <?php foreach ($verifiers as $v): ?>
                        <option value="<?= $v['id'] ?>" <?= (int)($order['assigned_verifier_id'] ?? 0) === (int)$v['id'] ? 'selected' : '' ?>><?= htmlspecialchars($v['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="min-h-[44px] px-4 rounded-lg font-bold text-xs transition shrink-0" style="background:var(--accent);color:#fff;">
                        Asignar
                    </button>
                </form>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- BLOQUE DE AUDITORÍA: APROBACIÓN (Restaurado) -->
            <?php if (in_array($order['status'], ['aprobado', 'entregado']) && !empty($order['approved_at'])): ?>
            <div class="p-5 rounded-2xl flex items-start gap-4" style="background:var(--apr-bg);border:1px solid var(--apr-ink);">
                <div class="p-2 rounded-xl shrink-0" style="background:rgba(11,107,70,.12);color:var(--apr-ink);">
                    <i data-lucide="shield-check" class="w-6 h-6"></i>
                </div>
                <div>
                    <h4 class="uppercase text-[10px] font-bold tracking-widest mb-1" style="color:var(--apr-ink);">Crédito Aprobado</h4>
                    <p class="text-sm font-medium" style="color:var(--ink);">
                        Verificado por <span style="color:var(--apr-ink);font-weight:700;"><?= htmlspecialchars($order['approved_by_name'] ?? 'Sistema') ?></span>
                    </p>
                    <p class="text-xs mt-1" style="color:var(--ink-2);">
                        Fecha y Hora: <?= date('d/m/Y - H:i', strtotime($order['approved_at'])) ?> hs.
                    </p>
                </div>
            </div>
            <?php endif; ?>

            <!-- BLOQUE DE AUDITORÍA: RECHAZO -->
            <?php if ($order['status'] === 'rechazado'): ?>
            <div class="p-5 rounded-2xl flex items-start gap-4" style="background:var(--rec-bg);border:1px solid var(--rec-ink);">
                <div class="p-2 rounded-xl shrink-0" style="background:rgba(159,18,57,.12);color:var(--rec-ink);">
                    <i data-lucide="alert-triangle" class="w-6 h-6"></i>
                </div>
                <div class="flex-1">
                    <h4 class="uppercase text-[10px] font-bold tracking-widest mb-1" style="color:var(--rec-ink);">Operación Rechazada</h4>
                    <?php $rt = $order['rejected_type'] ?? null; ?>
                    <p class="text-sm font-bold mb-1" style="color:var(--ink);">
                        <?= htmlspecialchars(rejection_type_label($rt)) ?>
                        <?php if (rejection_type_blocks($rt)): ?>
                        <span class="text-[10px] font-bold uppercase px-2 py-0.5 rounded-full ml-1" style="background:rgba(159,18,57,.15);color:var(--rec-ink);">Queda en lista negra</span>
                        <?php else: ?>
                        <span class="text-[10px] font-bold uppercase px-2 py-0.5 rounded-full ml-1" style="background:var(--apr-bg);color:var(--apr-ink);">Se puede volver a cargar</span>
                        <?php endif; ?>
                    </p>
                    <p class="text-sm italic" style="color:var(--ink);">"<?= htmlspecialchars($order['rejected_reason'] ?? 'Sin motivo especificado') ?>"</p>

                    <?php if ($can_assign): // admin y supervisor pueden reclasificar ?>
                    <form method="POST" action="reclasificar_rechazo.php" class="mt-3 flex flex-wrap items-center gap-2">
                        <?= csrf_field() ?>
                        <input type="hidden" name="sale_id" value="<?= (int)$order['id'] ?>">
                        <label class="text-[10px] uppercase font-bold" style="color:var(--rec-ink);">Reclasificar:</label>
                        <select name="reject_type" class="input-light rounded-lg px-3 py-1.5 text-xs cursor-pointer">
                            <?php foreach (REJECTION_TYPES_SELECTABLE as $t): ?>
                            <option value="<?= $t ?>" <?= $rt === $t ? 'selected' : '' ?>><?= htmlspecialchars(rejection_type_label($t)) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="text-xs font-bold px-3 py-1.5 rounded-lg text-white transition" style="background:var(--rec-ink);" onmouseover="this.style.opacity='.85'" onmouseout="this.style.opacity='1'">Guardar</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- SECCIÓN: DATOS DEL CLIENTE -->
            <div class="grid lg:grid-cols-2 gap-12 mt-6">
                <div class="space-y-6">
                    <div class="flex items-center gap-2 pb-2" style="color:var(--accent);border-bottom:1.5px solid var(--line);">
                        <i data-lucide="user" class="w-4 h-4"></i>
                        <h3 class="font-bold uppercase text-xs tracking-wider">Datos del Cliente</h3>
                    </div>
                    <div class="grid grid-cols-1 gap-5 text-sm">
                        <div>
                            <p class="text-[10px] uppercase font-bold mb-1" style="color:var(--ink-3);">Nombre y Apellido</p>
                            <p class="text-lg font-bold" style="color:var(--ink);"><?= htmlspecialchars($order['client_name'] ?? '-') ?></p>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <p class="text-[10px] uppercase font-bold mb-1" style="color:var(--ink-3);">DNI</p>
                                <p class="font-mono" style="color:var(--ink-2);"><?= htmlspecialchars($order['client_dni'] ?? '-') ?></p>
                            </div>
                            <div>
                                <p class="text-[10px] uppercase font-bold mb-1" style="color:var(--ink-3);">WhatsApp</p>
                                <a href="https://wa.me/<?= preg_replace('/[^0-9]/', '', $order['client_whatsapp'] ?? '') ?>" target="_blank" class="text-emerald-600 font-bold flex items-center gap-1 hover:text-emerald-500 transition">
                                    <i data-lucide="message-circle" class="w-3 h-3"></i> <?= htmlspecialchars($order['client_whatsapp'] ?? '-') ?>
                                </a>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <p class="text-[10px] uppercase font-bold mb-1" style="color:var(--ink-3);">Nro Llamada</p>
                                <p style="color:var(--ink-2);"><?= htmlspecialchars($order['client_phone'] ?? '-') ?></p>
                            </div>
                            <div>
                                <p class="text-[10px] uppercase font-bold mb-1" style="color:var(--ink-3);">Localidad</p>
                                <p style="color:var(--ink-2);"><?= htmlspecialchars($order['client_locality'] ?? '-') ?></p>
                            </div>
                        </div>
                        <div>
                            <p class="text-[10px] uppercase font-bold mb-1" style="color:var(--ink-3);">Domicilio / Barrio</p>
                            <p style="color:var(--ink);"><?= htmlspecialchars($order['client_address'] ?? '-') ?></p>
                            <p class="text-xs mt-0.5" style="color:var(--ink-2);">Barrio: <?= htmlspecialchars($order['client_neighborhood'] ?? 'No especificado') ?></p>
                        </div>

                        <?php if(!empty($order['client_map_link'])): ?>
                        <div class="pt-2">
                            <a href="<?= htmlspecialchars($order['client_map_link']) ?>" target="_blank" class="w-full sm:w-auto inline-flex items-center justify-center gap-3 text-blue-500 px-5 py-3 rounded-2xl text-xs font-bold hover:bg-blue-600 hover:text-white transition border shadow-lg" style="background:var(--accent-soft);border-color:rgba(99,102,241,.3);">
                                <i data-lucide="map-pin" class="w-5 h-5"></i> ABRIR UBICACIÓN GOOGLE MAPS
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- SECCIÓN: DATOS LABORALES -->
                <div class="space-y-6">
                    <div class="flex items-center gap-2 pb-2" style="color:var(--accent-ink);border-bottom:1.5px solid var(--line);">
                        <i data-lucide="briefcase" class="w-4 h-4"></i>
                        <h3 class="font-bold uppercase text-xs tracking-wider">Información Laboral</h3>
                    </div>
                    <div class="grid grid-cols-2 gap-5 text-sm">
                        <div>
                            <p class="text-[10px] uppercase font-bold mb-1" style="color:var(--ink-3);">Tipo de Empleo</p>
                            <p style="color:var(--ink);"><?= htmlspecialchars($order['job_type'] ?? '-') ?></p>
                        </div>
                        <div>
                            <p class="text-[10px] uppercase font-bold mb-1" style="color:var(--ink-3);">Ocupación</p>
                            <p style="color:var(--ink-2);"><?= htmlspecialchars($order['job_occupation'] ?? '-') ?></p>
                        </div>
                        <div class="col-span-2">
                            <p class="text-[10px] uppercase font-bold mb-1" style="color:var(--ink-3);">Nombre de la Empresa</p>
                            <p class="font-medium text-base" style="color:var(--ink);"><?= htmlspecialchars($order['job_name'] ?? '-') ?></p>
                        </div>
                        <div class="col-span-2">
                            <p class="text-[10px] uppercase font-bold mb-1" style="color:var(--ink-3);">Domicilio Laboral</p>
                            <p style="color:var(--ink-2);"><?= htmlspecialchars($order['job_address'] ?? '-') ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- SECCIÓN: DETALLE ECONÓMICO -->
            <div class="p-8 rounded-3xl shadow-inner" style="background:var(--paper);border:1.5px solid var(--line);">
                <div class="flex items-center gap-2 mb-8 pb-4" style="color:#0b6b46;border-bottom:1.5px solid var(--line);">
                    <i data-lucide="dollar-sign" class="w-5 h-5"></i>
                    <h3 class="font-bold uppercase text-xs tracking-widest">Plan de Pago y Producto</h3>
                </div>

                <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-8">
                    <div class="col-span-2 md:col-span-4 lg:col-span-2">
                        <p class="text-[10px] uppercase font-bold mb-2 tracking-widest" style="color:var(--ink-3);">Artículo</p>
                        <p class="text-lg font-bold" style="color:var(--ink);"><?= htmlspecialchars($order['item'] ?? '-') ?></p>
                    </div>
                    <div>
                        <p class="text-[10px] uppercase font-bold mb-2 tracking-widest" style="color:var(--ink-3);">Frecuencia</p>
                        <span class="text-blue-600 font-bold uppercase text-[10px] px-2 py-1 rounded-lg" style="background:var(--ent-bg);border:1px solid var(--ent-ink);">
                            <?= htmlspecialchars($order['payment_frequency'] ?? 'Semanal') ?>
                        </span>
                    </div>
                    <div>
                        <p class="text-[10px] uppercase font-bold mb-2 tracking-widest" style="color:var(--ink-3);">Día Cobro</p>
                        <p class="font-bold uppercase text-xs" style="color:#0b6b46;"><?= htmlspecialchars($order['payment_day'] ?? '-') ?></p>
                    </div>
                    <div>
                        <p class="text-[10px] uppercase font-bold mb-2 tracking-widest" style="color:var(--ink-3);">Cuotas</p>
                        <p class="font-medium" style="color:var(--ink);"><?= $order['installments_count'] ?? 0 ?> pagos</p>
                    </div>
                    <div>
                        <p class="text-[10px] uppercase font-bold mb-2 tracking-widest" style="color:var(--ink-3);">Monto Cuota</p>
                        <p class="font-bold" style="color:var(--ink);">$<?= number_format($order['installment_amount'] ?? 0, 0, ',', '.') ?></p>
                    </div>
                </div>

                <div class="mt-8 pt-6 flex flex-col md:flex-row justify-between items-end gap-4" style="border-top:1.5px solid var(--line);">
                    <div>
                        <p class="text-[10px] uppercase font-bold mb-1" style="color:var(--ink-3);">Adelanto / Entrega</p>
                        <p class="font-bold text-xl" style="color:var(--ink-2);">$<?= number_format($order['down_payment'] ?? 0, 0, ',', '.') ?></p>
                    </div>
                    <div class="text-right">
                        <p class="text-[10px] uppercase font-bold tracking-widest mb-1" style="color:var(--apr-ink);">Valor Total Final</p>
                        <p class="font-black text-4xl" style="color:var(--apr-ink);">$<?= number_format($order['total_amount'] ?? 0, 0, ',', '.') ?></p>
                    </div>
                </div>
            </div>

            <!-- OBSERVACIONES -->
            <?php if (!empty($order['observations'])): ?>
            <div class="p-6 rounded-2xl" style="background:#fffbeb;border:1px solid #f59e0b;">
                <div class="flex items-center gap-2 mb-3">
                    <i data-lucide="message-square" class="w-4 h-4" style="color:#b45309;"></i>
                    <strong class="uppercase text-[10px] font-bold tracking-widest" style="color:#b45309;">Notas de la Venta</strong>
                </div>
                <p class="text-sm italic whitespace-pre-wrap leading-relaxed" style="color:#78350f;"><?= htmlspecialchars($order['observations']) ?></p>
            </div>
            <?php endif; ?>

            <!-- DOCUMENTACIÓN ADJUNTA -->
            <?php if (!empty($files)): ?>
            <div class="space-y-4">
                <div class="flex items-center gap-2 pb-2" style="color:#7c3aed;border-bottom:1.5px solid var(--line);">
                    <i data-lucide="files" class="w-4 h-4"></i>
                    <h3 class="font-bold uppercase text-xs tracking-wider">Documentación Adjunta</h3>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
                    <?php foreach ($files as $file): ?>
                    <a href="uploads/<?= htmlspecialchars($file['file_path'] ?? '') ?>" target="_blank" class="flex items-center gap-3 p-4 rounded-2xl transition group shadow-sm hover:shadow-md" style="background:var(--card);border:1.5px solid var(--line);">
                        <div class="p-2.5 rounded-lg transition-colors group-hover:bg-purple-600 group-hover:text-white" style="background:var(--paper);border:1.5px solid var(--line);">
                            <i data-lucide="<?= strpos(strtolower($file['file_path'] ?? ''), '.pdf') !== false ? 'file-text' : 'image' ?>" class="w-5 h-5"></i>
                        </div>
                        <span class="text-[10px] truncate flex-1" style="color:var(--ink-2);"><?= htmlspecialchars($file['file_path'] ?? '') ?></span>
                        <i data-lucide="external-link" class="w-4 h-4 group-hover:text-purple-500" style="color:var(--line);"></i>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- ACCIONES DE GESTIÓN (Auditores) -->
        <?php if ($can_manage && $order['status'] === 'revision'): ?>
        <div class="backdrop-blur p-8 flex flex-col sm:flex-row justify-end gap-4 items-center" style="background:var(--paper);border-top:1.5px solid var(--line);">
            <div class="text-[10px] uppercase font-bold tracking-widest mr-auto hidden sm:block" style="color:var(--ink-3);">
                <i data-lucide="shield-check" class="inline w-3.5 h-3.5 mr-1 text-blue-500"></i> Auditoría Pendiente
            </div>
            <a href="rechazar_venta.php?id=<?= $order['id'] ?>" class="w-full sm:w-auto px-10 py-4 font-bold rounded-2xl transition flex justify-center items-center gap-2" style="background:var(--rec-bg);color:var(--rec-ink);border:1.5px solid var(--rec-ink);">
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
<div id="editModal" class="fixed inset-0 backdrop-blur-xl hidden z-50 overflow-y-auto" style="background:rgba(43,43,58,.55);">
    <div class="flex min-h-full items-start sm:items-center justify-center p-2 sm:p-4">
    <div class="rounded-2xl sm:rounded-3xl shadow-2xl w-full max-w-4xl p-4 sm:p-8 my-2 sm:my-6 transform transition-all" style="background:var(--card);border:1.5px solid var(--line);box-shadow:var(--shadow-card);">
        <div class="flex justify-between items-center mb-8 pb-4" style="border-bottom:1.5px solid var(--line);">
            <h3 class="text-xl font-bold flex items-center gap-3" style="color:var(--ink);">
                <i data-lucide="settings-2" class="text-blue-500 w-6 h-6"></i> Edición Integral de la Ficha
            </h3>
            <button onclick="closeEditModal()" class="transition w-10 h-10 flex items-center justify-center rounded-full shrink-0" style="color:var(--ink-2);background:var(--paper);border:1.5px solid var(--line);"><i data-lucide="x" class="w-5 h-5"></i></button>
        </div>

        <form action="update_sale_full.php" method="POST" enctype="multipart/form-data" class="space-y-8">
            <?= csrf_field() ?>
            <input type="hidden" name="sale_id" value="<?= $order['id'] ?>">

            <div class="grid md:grid-cols-2 gap-10">
                <!-- Columna 1: Cliente -->
                <div class="space-y-4">
                    <h4 class="text-blue-500 text-xs font-bold uppercase tracking-widest border-l-4 border-blue-500 pl-3">Datos del Cliente</h4>

                    <div class="grid grid-cols-2 gap-4">
                        <div class="col-span-2">
                            <label class="block text-[10px] font-bold uppercase mb-1 ml-1" style="color:var(--ink-3);">Nombre Completo</label>
                            <input type="text" name="client_name" value="<?= htmlspecialchars($order['client_name'] ?? '') ?>" class="input-light w-full rounded-xl px-4 py-2.5 transition">
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold uppercase mb-1 ml-1" style="color:var(--ink-3);">DNI</label>
                            <input type="text" name="client_dni" value="<?= htmlspecialchars($order['client_dni'] ?? '') ?>" class="input-light w-full rounded-xl px-4 py-2.5 transition font-mono">
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold uppercase mb-1 ml-1" style="color:var(--ink-3);">WhatsApp</label>
                            <input type="tel" name="client_whatsapp" inputmode="numeric" oninput="this.value=this.value.replace(/\D/g,'').slice(0,15)" value="<?= htmlspecialchars($order['client_whatsapp'] ?? '') ?>" class="input-light w-full rounded-xl px-4 py-2.5 transition font-mono">
                        </div>
                        <div class="col-span-2">
                            <label class="block text-[10px] font-bold uppercase mb-1 ml-1" style="color:var(--ink-3);">Nro Llamada</label>
                            <input type="tel" name="client_phone" <?= ($order['sale_type'] ?? 'credito') === 'contado' ? '' : 'required' ?> inputmode="numeric" oninput="this.value=this.value.replace(/\D/g,'').slice(0,15)" value="<?= htmlspecialchars($order['client_phone'] ?? '') ?>" class="input-light w-full rounded-xl px-4 py-2.5 transition font-mono">
                        </div>
                        <div class="col-span-2">
                            <label class="block text-[10px] font-bold uppercase mb-1 ml-1" style="color:var(--ink-3);">Domicilio</label>
                            <input type="text" name="client_address" value="<?= htmlspecialchars($order['client_address'] ?? '') ?>" class="input-light w-full rounded-xl px-4 py-2.5 transition">
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold uppercase mb-1 ml-1" style="color:var(--ink-3);">Barrio</label>
                            <input type="text" name="client_neighborhood" value="<?= htmlspecialchars($order['client_neighborhood'] ?? '') ?>" class="input-light w-full rounded-xl px-4 py-2.5 transition">
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold uppercase mb-1 ml-1" style="color:var(--ink-3);">Localidad</label>
                            <?php
                            $localidades = ['Acheral','Aguilares','Alderete','Banda del Río Salí','Bella Vista','Burruyacu','Catamarca','Concepción','Famaillá','Leales','Lules','Manantial','Monteros','Río Colorado','San Miguel de Tucumán','Simoca','Tafí del Valle','Tafí Viejo','Termas','Sgo','Villa Carmela','Yerba Buena'];
                            $localidadesLabels = ['Sgo' => 'Sgo del Estero'];
                            $cur_loc = $order['client_locality'] ?? '';
                            ?>
                            <select name="client_locality" id="client_locality" class="input-light w-full rounded-xl px-4 py-2.5 transition">
                                <option value="" disabled <?= $cur_loc === '' ? 'selected' : '' ?>>Seleccionar localidad...</option>
                                <?php foreach ($localidades as $loc): ?>
                                <option value="<?= htmlspecialchars($loc) ?>" <?= $cur_loc === $loc ? 'selected' : '' ?>><?= htmlspecialchars($localidadesLabels[$loc] ?? $loc) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-span-2">
                            <label class="block text-[10px] font-bold uppercase mb-1 ml-1" style="color:#0b6b46;">Enlace Google Maps</label>
                            <div class="relative">
                                <input type="text" name="client_map_link" required value="<?= htmlspecialchars($order['client_map_link'] ?? '') ?>" placeholder="Pegue aquí el enlace de ubicación" class="input-light w-full rounded-xl px-4 py-2.5 transition">
                                <div class="absolute right-3 top-1/2 -translate-y-1/2" style="color:#0b6b46;"><i data-lucide="map-pin" class="w-4 h-4"></i></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Columna 2: Laboral y Venta -->
                <div class="space-y-8">
                    <!-- Laboral -->
                    <div class="space-y-4">
                        <h4 class="text-xs font-bold uppercase tracking-widest border-l-4 pl-3" style="color:var(--accent-ink);border-color:var(--accent-ink);">Datos Laborales</h4>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-[10px] font-bold uppercase mb-1 ml-1" style="color:var(--ink-3);">Tipo Empleo</label>
                                <input type="text" name="job_type" value="<?= htmlspecialchars($order['job_type'] ?? '') ?>" class="input-light w-full rounded-xl px-4 py-2.5 transition">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold uppercase mb-1 ml-1" style="color:var(--ink-3);">Ocupación</label>
                                <input type="text" name="job_occupation" value="<?= htmlspecialchars($order['job_occupation'] ?? '') ?>" class="input-light w-full rounded-xl px-4 py-2.5 transition">
                            </div>
                            <div class="col-span-2">
                                <label class="block text-[10px] font-bold uppercase mb-1 ml-1" style="color:var(--ink-3);">Empresa</label>
                                <input type="text" name="job_name" value="<?= htmlspecialchars($order['job_name'] ?? '') ?>" class="input-light w-full rounded-xl px-4 py-2.5 transition">
                            </div>
                            <div class="col-span-2">
                                <label class="block text-[10px] font-bold uppercase mb-1 ml-1" style="color:var(--ink-3);">Dirección Laboral</label>
                                <input type="text" name="job_address" value="<?= htmlspecialchars($order['job_address'] ?? '') ?>" class="input-light w-full rounded-xl px-4 py-2.5 transition">
                            </div>
                        </div>
                    </div>

                    <!-- Venta -->
                    <div class="space-y-4">
                        <h4 class="text-xs font-bold uppercase tracking-widest border-l-4 pl-3" style="color:#0b6b46;border-color:#0b6b46;">Plan de Pago</h4>
                        <div class="grid grid-cols-2 gap-4">
                            <div class="col-span-2">
                                <label class="block text-[10px] font-bold uppercase mb-1 ml-1" style="color:var(--ink-3);">Artículo</label>
                                <input type="text" name="item" value="<?= htmlspecialchars($order['item'] ?? '') ?>" class="input-light w-full rounded-xl px-4 py-2.5 transition" required>
                            </div>
                            <?php if (($order['sale_type'] ?? 'credito') === 'contado'): ?>
                            <div class="col-span-2">
                                <label class="block text-[10px] font-bold uppercase mb-1 ml-1" style="color:var(--ink-3);">Método de Pago</label>
                                <select name="payment_method" class="input-light w-full rounded-xl px-4 py-2.5 transition appearance-none cursor-pointer">
                                    <option value="normal" <?= (($order['payment_method'] ?? 'normal') === 'normal') ? 'selected' : '' ?>>Normal</option>
                                    <option value="rapicompra" <?= (($order['payment_method'] ?? 'normal') === 'rapicompra') ? 'selected' : '' ?>>RapiCompra (comisión 6%)</option>
                                </select>
                            </div>
                            <?php endif; ?>
                            <div>
                                <label class="block text-[10px] font-bold uppercase mb-1 ml-1" style="color:var(--ink-3);">Frecuencia</label>
                                <select name="payment_frequency" class="input-light w-full rounded-xl px-4 py-2.5 transition appearance-none cursor-pointer">
                                    <option value="semanal" <?= (($order['payment_frequency'] ?? 'semanal') == 'semanal') ? 'selected' : '' ?>>Semanal</option>
                                    <option value="quincenal" <?= (($order['payment_frequency'] ?? '') == 'quincenal') ? 'selected' : '' ?>>Quincenal</option>
                                    <option value="mensual" <?= (($order['payment_frequency'] ?? '') == 'mensual') ? 'selected' : '' ?>>Mensual</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold uppercase mb-1 ml-1" style="color:var(--ink-3);">Día Cobro</label>
                                <select name="payment_day" id="payment_day" class="input-light w-full rounded-xl px-4 py-2.5 transition appearance-none cursor-pointer">
                                    <?php 
                                    $dias_permitidos = ['Lunes', 'Martes', 'Miércoles', 'Sábado'];
                                    $current_day = $order['payment_day'] ?? '';
                                    $options_to_render = $dias_permitidos;
                                    if (!empty($current_day) && !in_array($current_day, $dias_permitidos)) {
                                        $options_to_render[] = $current_day;
                                    }
                                    foreach ($options_to_render as $d): 
                                        $is_historical = !in_array($d, $dias_permitidos);
                                    ?>
                                        <option value="<?= htmlspecialchars($d) ?>" 
                                                <?= $current_day === $d ? 'selected' : '' ?>
                                                <?= $is_historical ? 'class="historical-opt"' : '' ?>
                                                id="<?= $d === 'Sábado' ? 'opt-sabado' : ($is_historical ? 'opt-historical' : '') ?>">
                                            <?= htmlspecialchars($d) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold uppercase mb-1 ml-1" style="color:var(--ink-3);">Cant. Cuotas</label>
                                <input type="number" id="edit_cuotas" name="installments" value="<?= $order['installments_count'] ?? 0 ?>" class="input-light w-full rounded-xl px-4 py-2.5" oninput="recalculateTotal()" required>
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold uppercase mb-1 ml-1" style="color:var(--ink-3);">Valor Cuota ($)</label>
                                <input type="number" id="edit_monto" name="amount" value="<?= $order['installment_amount'] ?? 0 ?>" class="input-light w-full rounded-xl px-4 py-2.5" oninput="recalculateTotal()" required>
                            </div>
                            <div class="col-span-2">
                                <label class="block text-[10px] font-bold uppercase mb-1 ml-1" style="color:var(--ink-3);">Adelanto ($)</label>
                                <input type="number" name="down_payment" value="<?= htmlspecialchars($order['down_payment'] ?? 0) ?>" min="0" step="0.01" class="input-light w-full rounded-xl px-4 py-2.5 transition">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Observaciones -->
            <div class="space-y-2">
                <label class="block text-[10px] font-bold uppercase mb-1 ml-1" style="color:#b45309;">Observaciones</label>
                <textarea name="observations" rows="3" placeholder="Notas internas de la venta..." class="input-light w-full rounded-xl px-4 py-2.5 transition resize-none"><?= htmlspecialchars($order['observations'] ?? '') ?></textarea>
            </div>

            <div class="p-6 rounded-3xl flex justify-between items-center px-6 sm:px-12" style="background:var(--apr-bg);border:1px solid var(--apr-ink);">
                <span class="text-xs uppercase font-bold tracking-widest" style="color:var(--apr-ink);">Nuevo Total Calculado</span>
                <div id="edit_total" class="text-3xl font-black" style="color:var(--ink);">$<?= number_format($order['total_amount'] ?? 0, 0, ',', '.') ?></div>
            </div>

            <!-- DOCUMENTACIÓN ADJUNTA (EDICIÓN) -->
            <div class="space-y-4">
                <h4 class="text-xs font-bold uppercase tracking-widest border-l-4 border-purple-500 pl-3" style="color:#7c3aed;">Documentación Adjunta</h4>

                <?php
                // Re-query con id para los botones de eliminar
                $stmtFilesEdit = $pdo->prepare("SELECT sf.id, sf.file_path FROM sale_files sf WHERE sf.sale_id = ?");
                $stmtFilesEdit->execute([$order['id']]);
                $filesWithIds = $stmtFilesEdit->fetchAll(PDO::FETCH_ASSOC);
                ?>

                <?php if (!empty($filesWithIds)): ?>
                <div>
                    <p class="text-[10px] font-bold uppercase mb-2 ml-1" style="color:var(--ink-3);">Archivos actuales:</p>
                    <div class="flex flex-wrap gap-2">
                        <?php foreach ($filesWithIds as $ef):
                            $isPdf = strpos(strtolower($ef['file_path']), '.pdf') !== false;
                        ?>
                        <div class="flex items-center gap-2 rounded-xl px-3 py-2 text-xs" id="file-chip-<?= $ef['id'] ?>" style="background:var(--paper);border:1.5px solid var(--line);color:var(--ink-2);">
                            <i data-lucide="<?= $isPdf ? 'file-text' : 'image' ?>" class="w-4 h-4 shrink-0" style="color:#7c3aed;"></i>
                            <a href="uploads/<?= htmlspecialchars($ef['file_path']) ?>" target="_blank"
                               class="truncate max-w-[140px] hover:text-purple-500 transition">
                                <?= htmlspecialchars($ef['file_path']) ?>
                            </a>
                            <?php if (in_array($role, ['admin', 'supervisor']) || ($role === 'vendedor' && $order['status'] === 'revision')): ?>
                            <button type="button"
                                    class="p-1 transition rounded hover:text-red-500"
                                    style="color:var(--ink-3);"
                                    title="Eliminar"
                                    onclick="deleteFile(<?= $ef['id'] ?>, <?= $order['id'] ?>)">
                                <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Zona de carga de nuevos archivos -->
                <div class="rounded-2xl p-6 text-center group hover:border-purple-500/50 transition-colors cursor-pointer"
                     style="background:var(--paper);border:2px dashed var(--line);"
                     onclick="document.getElementById('edit_sale_files').click()">
                    <input type="file" name="sale_files[]" id="edit_sale_files" multiple
                           accept="image/*,.pdf" class="hidden" onchange="previewEditFiles(this)">
                    <div id="edit_file_list">
                        <i data-lucide="upload-cloud" class="w-7 h-7 mx-auto mb-2 group-hover:text-purple-500 transition" style="color:var(--ink-3);"></i>
                        <p class="text-xs font-medium" style="color:var(--ink-2);">Click para agregar más documentos</p>
                        <p class="text-[10px] mt-1" style="color:var(--ink-3);">JPG, PNG, PDF — máx. 5MB cada uno</p>
                    </div>
                </div>
            </div>

            <div class="flex gap-4 pt-4">
                <button type="button" onclick="closeEditModal()" class="flex-1 py-4 rounded-2xl transition font-bold uppercase text-xs" style="background:var(--paper);color:var(--ink-2);border:1.5px solid var(--line);">Cancelar</button>
                <button type="submit" class="flex-1 py-4 bg-blue-600 hover:bg-blue-500 text-white rounded-2xl transition font-bold uppercase text-xs shadow-xl shadow-blue-900/30">Guardar Cambios</button>
            </div>
        </form>
    </div>
    </div>
</div>

<script>
    function openEditModal() {
        const modal = document.getElementById('editModal');
        modal.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
        lucide.createIcons();
    }

    function closeEditModal() {
        const modal = document.getElementById('editModal');
        modal.classList.add('hidden');
        document.body.style.overflow = '';
    }

    function recalculateTotal() {
        const cuotas = parseFloat(document.getElementById('edit_cuotas').value) || 0;
        const monto = parseFloat(document.getElementById('edit_monto').value) || 0;
        const total = cuotas * monto;
        document.getElementById('edit_total').innerText = '$' + total.toLocaleString('es-AR');
    }

    function previewEditFiles(input) {
        const container = document.getElementById('edit_file_list');
        if (!input.files || input.files.length === 0) return;
        let html = '<div class="flex flex-wrap gap-1 justify-center mt-1">';
        Array.from(input.files).forEach(file => {
            html += `<span class="text-[10px] px-2 py-1 rounded flex items-center gap-1" style="background:var(--paper);color:var(--ink-2);border:1.5px solid var(--line);">
                <i data-lucide="file" class="w-3 h-3" style="color:#7c3aed;"></i>${file.name}
            </span>`;
        });
        html += '</div>';
        container.innerHTML = html;
        lucide.createIcons();
    }

    const _csrfToken = '<?= htmlspecialchars(csrf_token()) ?>';

    function deleteFile(fileId, saleId) {
        if (!confirm('¿Eliminar este archivo? Esta acción no se puede deshacer.')) return;

        const fd = new FormData();
        fd.append('file_id',    fileId);
        fd.append('sale_id',    saleId);
        fd.append('csrf_token', _csrfToken);

        fetch('delete_sale_file.php', { method: 'POST', body: fd })
            .then(response => {
                // El endpoint redirige; tomamos la URL final para detectar errores
                const chip = document.getElementById('file-chip-' + fileId);
                if (response.url && response.url.includes('error_delete')) {
                    alert('No se pudo eliminar el archivo.');
                } else {
                    if (chip) chip.remove();
                }
            })
            .catch(() => alert('Error de conexión al intentar eliminar.'));
    }

    // Control dinámico del día de cobro "Sábado" y resguardo histórico según la localidad en modal de edición
    document.addEventListener('DOMContentLoaded', function() {
        const localitySelect = document.getElementById('client_locality');
        const paymentDaySelect = document.getElementById('payment_day');
        const optSabado = document.getElementById('opt-sabado');
        const optHistorical = document.getElementById('opt-historical');
        
        function updatePaymentDays() {
            if (!localitySelect || !paymentDaySelect) return;
            
            const locality = localitySelect.value;
            const isTafiOrLeales = (locality === 'Tafí del Valle' || locality === 'Leales');
            
            if (optSabado) {
                if (isTafiOrLeales) {
                    optSabado.disabled = false;
                    optSabado.style.display = '';
                } else {
                    if (paymentDaySelect.value === 'Sábado') {
                        optSabado.disabled = false;
                        optSabado.style.display = '';
                    } else {
                        optSabado.disabled = true;
                        optSabado.style.display = 'none';
                    }
                }
            }
            
            if (optHistorical) {
                if (paymentDaySelect.value === optHistorical.value) {
                    optHistorical.disabled = false;
                    optHistorical.style.display = '';
                } else {
                    optHistorical.disabled = true;
                    optHistorical.style.display = 'none';
                }
            }
        }
        
        if (localitySelect) {
            localitySelect.addEventListener('change', updatePaymentDays);
            updatePaymentDays();
        }
    });
</script>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>
