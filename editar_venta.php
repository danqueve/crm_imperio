<?php
require 'includes/db.php';

/**
 * Archivo: editar_venta.php
 * Propósito: Permite al vendedor editar sus propias ventas en estado "revision".
 */

// SEGURIDAD: Solo vendedores (gestores usan el modal en ver_ficha.php)
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'vendedor') {
    header("Location: dashboard.php");
    exit;
}

$sale_id = (int)($_GET['id'] ?? 0);
$user_id = (int)$_SESSION['user_id'];

if (!$sale_id) {
    header("Location: dashboard.php");
    exit;
}

// Cargar la venta verificando propiedad Y estado
$stmt = $pdo->prepare("SELECT * FROM sales WHERE id = ? AND user_id = ? AND status = 'revision'");
$stmt->execute([$sale_id, $user_id]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    // La venta no existe, no le pertenece, o ya no está en revisión
    header("Location: dashboard.php");
    exit;
}

// Obtener archivos adjuntos con sus IDs (para botón eliminar)
$stmtFiles = $pdo->prepare("SELECT id, file_path FROM sale_files WHERE sale_id = ?");
$stmtFiles->execute([$sale_id]);
$files = $stmtFiles->fetchAll(PDO::FETCH_ASSOC);

include 'includes/header.php';
?>

<main class="flex-1 max-w-5xl mx-auto w-full p-4 sm:p-6 lg:p-8 fade-in">

    <!-- Cabecera -->
    <div class="flex items-center gap-4 mb-8">
        <a href="ver_ficha.php?id=<?= $sale_id ?>" class="w-10 h-10 flex items-center justify-center rounded-full shrink-0 transition" style="background:var(--paper);border:1.5px solid var(--line);color:var(--ink-3);" onmouseover="this.style.background='var(--accent-soft)';this.style.color='var(--accent-ink)'" onmouseout="this.style.background='var(--paper)';this.style.color='var(--ink-3)'"  >
            <i data-lucide="arrow-left"></i>
        </a>
        <div>
            <h1 class="text-2xl font-bold" style="color:var(--ink);">Editar Venta <span class="text-blue-400">#<?= $sale_id ?></span></h1>
            <p class="text-sm text-slate-500">Modifique los datos y guarde los cambios. Solo disponible mientras la venta esté En Revisión.</p>
        </div>
    </div>

    <!-- Alerta de estado -->
    <div class="mb-8 flex items-center gap-3 bg-yellow-500/10 border border-yellow-500/20 text-yellow-400 rounded-xl px-5 py-4 text-sm">
        <i data-lucide="clock" class="w-5 h-5 shrink-0"></i>
        <span>Esta venta está <strong>En Revisión</strong>. Una vez aprobada o rechazada no podrá editarla.</span>
    </div>

    <!-- Mensajes flash -->
    <?php if (isset($_GET['msg']) && $_GET['msg'] === 'file_deleted'): ?>
    <div class="mb-6 flex items-center gap-3 bg-red-500/10 border border-red-500/20 text-red-400 rounded-xl px-5 py-4 text-sm">
        <i data-lucide="trash-2" class="w-5 h-5 shrink-0"></i>
        <span>Archivo eliminado correctamente.</span>
    </div>
    <?php endif; ?>

    <?php if (isset($_GET['msg']) && $_GET['msg'] === 'error'): ?>
    <div class="mb-6 flex items-center gap-3 bg-red-500/10 border border-red-500/20 text-red-400 rounded-xl px-5 py-4 text-sm">
        <i data-lucide="alert-octagon" class="w-5 h-5 shrink-0"></i>
        <span>Ocurrió un error al guardar los cambios. Intente nuevamente.</span>
    </div>
    <?php endif; ?>

    <form action="update_sale_full.php" method="POST" enctype="multipart/form-data" class="space-y-8">
        <?= csrf_field() ?>
        <input type="hidden" name="sale_id" value="<?= $sale_id ?>">

        <!-- ── SECCIÓN 1: DATOS DEL CLIENTE ── -->
        <div class="rounded-2xl p-6 md:p-8" style="background:var(--card);border:1.5px solid var(--line);box-shadow:var(--shadow-card);">
            <div class="flex items-center gap-3 mb-6 pb-4" style="color:var(--accent);border-bottom:1.5px solid var(--line);">
                <i data-lucide="user"></i>
                <h2 class="font-bold text-lg tracking-tight uppercase">Datos del Cliente</h2>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <div>
                    <label class="block text-xs font-bold uppercase mb-2 ml-1" style="color:var(--ink-3);">DNI <span class="text-red-400">*</span></label>
                    <input type="text" name="client_dni" required
                           value="<?= htmlspecialchars($order['client_dni'] ?? '') ?>"
                           class="w-full input-light px-4 py-3 font-mono">
                </div>
                <div class="md:col-span-1 lg:col-span-2">
                    <label class="block text-xs font-bold uppercase mb-2 ml-1" style="color:var(--ink-3);">Apellido y Nombre <span class="text-red-400">*</span></label>
                    <input type="text" name="client_name" required
                           value="<?= htmlspecialchars($order['client_name'] ?? '') ?>"
                           class="w-full input-light px-4 py-3">
                </div>

                <div class="md:col-span-2">
                    <label class="block text-xs font-bold uppercase mb-2 ml-1" style="color:var(--ink-3);">Domicilio <span class="text-red-400">*</span></label>
                    <input type="text" name="client_address" required
                           value="<?= htmlspecialchars($order['client_address'] ?? '') ?>"
                           class="w-full input-light px-4 py-3">
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase mb-2 ml-1" style="color:var(--ink-3);">Barrio <span class="text-red-400">*</span></label>
                    <input type="text" name="client_neighborhood" required
                           value="<?= htmlspecialchars($order['client_neighborhood'] ?? '') ?>"
                           class="w-full input-light px-4 py-3">
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase mb-2 ml-1" style="color:var(--ink-3);">Localidad <span class="text-red-400">*</span></label>
                    <?php
                    $localidades = ['Acheral','Aguilares','Alderete','Banda del Río Salí','Bella Vista','Burruyacu','Concepción','Famaillá','Leales','Lules','Manantial','Monteros','Río Colorado','San Miguel de Tucumán','Simoca','Tafí del Valle','Tafí Viejo','Termas','Sgo','Villa Carmela','Yerba Buena'];
                    $localidadesLabels = ['Sgo' => 'Sgo del Estero'];
                    $current_locality = $order['client_locality'] ?? '';
                    ?>
                    <select name="client_locality" id="client_locality" required
                            class="w-full input-light px-4 py-3">
                        <option value="" disabled <?= $current_locality === '' ? 'selected' : '' ?>>Seleccionar localidad...</option>
                        <?php foreach ($localidades as $loc): ?>
                        <option value="<?= htmlspecialchars($loc) ?>" <?= $current_locality === $loc ? 'selected' : '' ?>><?= htmlspecialchars($localidadesLabels[$loc] ?? $loc) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase mb-2 ml-1" style="color:var(--ink-3);">WhatsApp <span class="text-red-400">*</span></label>
                    <input type="text" name="client_whatsapp" required
                           value="<?= htmlspecialchars($order['client_whatsapp'] ?? '') ?>"
                           class="w-full input-light px-4 py-3 font-mono">
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase mb-2 ml-1" style="color:var(--ink-3);">Nro Llamada (Opcional)</label>
                    <input type="text" name="client_phone"
                           value="<?= htmlspecialchars($order['client_phone'] ?? '') ?>"
                           class="w-full input-light px-4 py-3 font-mono">
                </div>

                <div class="md:col-span-2 lg:col-span-3">
                    <label class="block text-xs font-bold uppercase mb-2 ml-1" style="color:var(--ink-3);">Ubicación Google Maps</label>
                    <div class="relative">
                        <input type="text" name="client_map_link"
                               value="<?= htmlspecialchars($order['client_map_link'] ?? '') ?>"
                               placeholder="Pegue el enlace aquí (https://maps...)"
                               class="w-full input-light px-4 py-3 pr-10">
                        <div class="absolute right-3 top-1/2 -translate-y-1/2 text-emerald-500 pointer-events-none">
                            <i data-lucide="map-pin" class="w-4 h-4"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── SECCIÓN 2: DATOS LABORALES ── -->
        <div class="rounded-2xl p-6 md:p-8" style="background:var(--card);border:1.5px solid var(--line);box-shadow:var(--shadow-card);">
            <div class="flex items-center gap-3 mb-6 pb-4" style="color:#4f46e5;border-bottom:1.5px solid var(--line);">
                <i data-lucide="briefcase"></i>
                <h2 class="font-bold text-lg tracking-tight uppercase">Datos Laborales</h2>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label class="block text-xs font-bold uppercase mb-2 ml-1" style="color:var(--ink-3);">Tipo de Empleo</label>
                    <input type="text" name="job_type"
                           value="<?= htmlspecialchars($order['job_type'] ?? '') ?>"
                           placeholder="Ej: Rel. Dependencia, Monotributo"
                           class="w-full input-light px-4 py-3">
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase mb-2 ml-1" style="color:var(--ink-3);">Ocupación</label>
                    <input type="text" name="job_occupation"
                           value="<?= htmlspecialchars($order['job_occupation'] ?? '') ?>"
                           placeholder="Ej: Empleado de comercio"
                           class="w-full input-light px-4 py-3">
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase mb-2 ml-1" style="color:var(--ink-3);">Nombre del Trabajo</label>
                    <input type="text" name="job_name"
                           value="<?= htmlspecialchars($order['job_name'] ?? '') ?>"
                           placeholder="Nombre de la empresa o lugar"
                           class="w-full input-light px-4 py-3">
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase mb-2 ml-1" style="color:var(--ink-3);">Domicilio Laboral</label>
                    <input type="text" name="job_address"
                           value="<?= htmlspecialchars($order['job_address'] ?? '') ?>"
                           placeholder="Dirección del trabajo"
                           class="w-full input-light px-4 py-3">
                </div>
            </div>
        </div>

        <!-- ── SECCIÓN 3: DETALLE DE LA VENTA ── -->
        <div class="rounded-2xl p-6 md:p-8" style="background:var(--card);border:1.5px solid var(--line);box-shadow:var(--shadow-card);">
            <div class="flex items-center gap-3 mb-6 pb-4" style="color:#059669;border-bottom:1.5px solid var(--line);">
                <i data-lucide="dollar-sign"></i>
                <h2 class="font-bold text-lg uppercase tracking-tight">Detalle de la Venta</h2>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <div class="md:col-span-2">
                    <label class="block text-xs font-bold uppercase mb-2 ml-1" style="color:var(--ink-3);">Artículo <span class="text-red-400">*</span></label>
                    <input type="text" name="item" required
                           value="<?= htmlspecialchars($order['item'] ?? '') ?>"
                           placeholder="Producto vendido"
                           class="w-full input-light px-4 py-3">
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase mb-2 ml-1" style="color:var(--ink-3);">Día de Cobro <span class="text-red-400">*</span></label>
                    <select name="payment_day" id="payment_day" required
                            class="w-full input-light px-4 py-3 cursor-pointer">
                        <?php 
                        $dias_permitidos = ['Lunes', 'Martes', 'Miércoles', 'Sábado'];
                        $current_day = $order['payment_day'] ?? '';
                        $options_to_render = $dias_permitidos;
                        if (!empty($current_day) && !in_array($current_day, $dias_permitidos)) {
                            $options_to_render[] = $current_day;
                        }
                        foreach ($options_to_render as $dia): 
                            $is_historical = !in_array($dia, $dias_permitidos);
                        ?>
                            <option value="<?= htmlspecialchars($dia) ?>" 
                                    <?= ($order['payment_day'] ?? '') === $dia ? 'selected' : '' ?>
                                    <?= $is_historical ? 'class="historical-opt"' : '' ?>
                                    id="<?= $dia === 'Sábado' ? 'opt-sabado' : ($is_historical ? 'opt-historical' : '') ?>">
                                <?= htmlspecialchars($dia) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase mb-2 ml-1" style="color:var(--ink-3);">Frecuencia <span class="text-red-400">*</span></label>
                    <select name="payment_frequency" required
                            class="w-full input-light px-4 py-3 cursor-pointer">
                        <?php foreach (['semanal' => 'Semanal', 'quincenal' => 'Quincenal', 'mensual' => 'Mensual'] as $val => $label): ?>
                            <option value="<?= $val ?>" <?= ($order['payment_frequency'] ?? 'semanal') === $val ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <div>
                    <label class="block text-xs font-bold uppercase mb-2 ml-1" style="color:var(--ink-3);">Cuotas <span class="text-red-400">*</span></label>
                    <input type="number" name="installments" id="edit_cuotas" required min="1"
                           value="<?= (int)($order['installments_count'] ?? 0) ?>"
                           class="w-full input-light px-4 py-3"
                           oninput="recalcTotal()">
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase mb-2 ml-1" style="color:var(--ink-3);">Monto por cuota ($) <span class="text-red-400">*</span></label>
                    <input type="number" name="amount" id="edit_monto" required min="0"
                           value="<?= (float)($order['installment_amount'] ?? 0) ?>"
                           class="w-full input-light px-4 py-3"
                           oninput="recalcTotal()">
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase mb-2 ml-1" style="color:var(--ink-3);">Adelanto ($)</label>
                    <input type="number" name="down_payment" min="0" step="0.01"
                           value="<?= (float)($order['down_payment'] ?? 0) ?>"
                           class="w-full input-light px-4 py-3">
                </div>
            </div>

            <!-- Total calculado -->
            <div class="bg-emerald-950/20 border border-emerald-500/20 rounded-xl p-4 flex justify-between items-center px-8 shadow-inner">
                <span class="text-xs uppercase text-emerald-500 font-bold tracking-widest">Total calculado</span>
                <div class="text-2xl font-black text-emerald-400" id="edit_total_display">
                    $<?= number_format((float)($order['total_amount'] ?? 0), 0, ',', '.') ?>
                </div>
            </div>
        </div>

        <!-- ── SECCIÓN 4: ARCHIVOS Y OBSERVACIONES ── -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">

            <!-- Archivos adjuntos -->
            <div class="rounded-2xl p-6 md:p-8" style="background:var(--card);border:1.5px solid var(--line);box-shadow:var(--shadow-card);">
                <div class="flex items-center gap-3 mb-6 pb-4" style="color:#7c3aed;border-bottom:1.5px solid var(--line);">
                    <i data-lucide="files"></i>
                    <h2 class="font-bold text-lg tracking-tight uppercase">Documentación Adjunta</h2>
                </div>

                <!-- Archivos existentes -->
                <?php if (!empty($files)): ?>
                <div class="mb-5">
                    <p class="text-[10px] font-bold text-slate-500 uppercase mb-3 ml-1">Archivos actuales:</p>
                    <div id="files-container" class="flex flex-wrap gap-2">
                        <?php foreach ($files as $f):
                            $isPdf = str_ends_with(strtolower($f['file_path']), '.pdf');
                        ?>
                        <div class="flex items-center gap-2 rounded-xl px-3 py-2 text-xs" id="chip-<?= $f['id'] ?>" style="background:var(--paper);border:1.5px solid var(--line);color:var(--ink-2);">
                            <i data-lucide="<?= $isPdf ? 'file-text' : 'image' ?>" class="w-4 h-4 text-purple-400 shrink-0"></i>
                            <a href="uploads/<?= htmlspecialchars($f['file_path']) ?>" target="_blank"
                               class="truncate max-w-[130px] hover:text-purple-300 transition">
                                <?= htmlspecialchars($f['file_path']) ?>
                            </a>
                            <!-- Botón eliminar archivo (vía JavaScript para evitar forms anidados) -->
                            <button type="button"
                                    class="p-1 text-slate-600 hover:text-red-400 transition rounded"
                                    title="Eliminar archivo"
                                    onclick="deleteFileFromEdit(<?= $f['id'] ?>, <?= $sale_id ?>)">
                                <i data-lucide="x" class="w-3.5 h-3.5"></i>
                            </button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php else: ?>
                <p class="text-slate-600 text-sm italic mb-5">No hay archivos adjuntos aún.</p>
                <?php endif; ?>

                <!-- Zona de subida de nuevos archivos -->
                <div class="rounded-2xl p-8 text-center group transition-colors" style="background:var(--paper);border:2px dashed var(--line);" onmouseover="this.style.borderColor='#7c3aed'" onmouseout="this.style.borderColor='var(--line)'">
                    <input type="hidden" name="MAX_FILE_SIZE" value="5242880">
                    <input type="file" name="sale_files[]" id="sale_files" multiple accept="image/*,.pdf"
                           class="hidden" onchange="previewNewFiles(this)">
                    <label for="sale_files" class="cursor-pointer flex flex-col items-center">
                        <i data-lucide="upload-cloud" class="w-8 h-8 text-slate-500 mb-4 transition group-hover:text-purple-400"></i>
                        <span class="font-bold block mb-1" style="color:var(--ink);">Click para agregar documentos</span>
                        <span class="text-slate-500 text-xs">JPG, PNG, PDF · Máx. 5 MB por archivo</span>
                    </label>
                </div>
                <div id="new-file-list" class="mt-4 flex flex-wrap gap-2 justify-center"></div>
            </div>

            <!-- Observaciones -->
            <div class="rounded-2xl p-6 md:p-8" style="background:var(--card);border:1.5px solid var(--line);box-shadow:var(--shadow-card);">
                <div class="flex items-center gap-3 mb-6 pb-4" style="color:var(--rev-ink);border-bottom:1.5px solid var(--line);">
                    <i data-lucide="message-square"></i>
                    <h2 class="font-bold text-lg tracking-tight uppercase">Observaciones</h2>
                </div>
                <textarea name="observations" rows="5"
                          placeholder="Horarios de entrega, aclaraciones del crédito, etc."
                          class="w-full input-light px-4 py-4 resize-none h-[185px]"><?= htmlspecialchars($order['observations'] ?? '') ?></textarea>
            </div>
        </div>

        <!-- Botones -->
        <div class="flex flex-col sm:flex-row gap-4">
            <a href="ver_ficha.php?id=<?= $sale_id ?>"
               class="flex-1 sm:flex-none text-center px-8 py-4 font-bold rounded-2xl transition" style="background:var(--paper);border:1.5px solid var(--line);color:var(--ink-2);"
                Cancelar
            </a>
            <button type="submit"
                    class="flex-1 bg-blue-600 hover:bg-blue-500 text-white font-bold py-4 rounded-2xl shadow-xl shadow-blue-900/30 flex items-center justify-center gap-3 transition active:scale-[0.98]">
                <i data-lucide="save" class="w-5 h-5"></i> Guardar Cambios
            </button>
        </div>

    </form>
</main>

<script>
    // Token CSRF para operaciones via fetch
    const _csrfToken = '<?= htmlspecialchars(csrf_token()) ?>';

    // Recalcular total en tiempo real
    function recalcTotal() {
        const cuotas = parseFloat(document.getElementById('edit_cuotas').value) || 0;
        const monto  = parseFloat(document.getElementById('edit_monto').value)  || 0;
        const total  = Math.round(cuotas * monto);
        document.getElementById('edit_total_display').textContent =
            '$' + total.toLocaleString('es-AR');
    }

    // Previsualizar archivos nuevos seleccionados
    function previewNewFiles(input) {
        const list = document.getElementById('new-file-list');
        list.innerHTML = '';
        if (input.files) {
            Array.from(input.files).forEach(file => {
                const span = document.createElement('span');
                span.className = 'text-[10px] px-2 py-1 rounded flex items-center gap-1'; span.style.cssText = 'background:var(--paper);color:var(--ink-2);border:1px solid var(--line);';
                span.innerHTML = `<i data-lucide="file" class="w-3 h-3"></i> ${file.name}`;
                list.appendChild(span);
            });
            lucide.createIcons();
        }
    }

    // Eliminar archivo adjunto vía fetch (sin anidar formularios)
    function deleteFileFromEdit(fileId, saleId) {
        if (!confirm('¿Eliminar este archivo? Esta acción no se puede deshacer.')) return;

        const fd = new FormData();
        fd.append('file_id',    fileId);
        fd.append('sale_id',    saleId);
        fd.append('csrf_token', _csrfToken);

        fetch('delete_sale_file.php', { method: 'POST', body: fd })
            .then(response => {
                const chip = document.getElementById('chip-' + fileId);
                if (response.url && response.url.includes('error_delete')) {
                    alert('No se pudo eliminar el archivo.');
                } else {
                    if (chip) chip.remove();
                }
            })
            .catch(() => alert('Error de conexión al intentar eliminar.'));
    }

    // Control dinámico del día de cobro "Sábado" y resguardo histórico según la localidad
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

<?php include 'includes/footer.php'; ?>
