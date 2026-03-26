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
        <a href="ver_ficha.php?id=<?= $sale_id ?>" class="w-10 h-10 flex items-center justify-center bg-slate-800 rounded-full text-slate-400 hover:text-white transition border border-slate-700 shrink-0">
            <i data-lucide="arrow-left"></i>
        </a>
        <div>
            <h1 class="text-2xl font-bold text-white">Editar Venta <span class="text-blue-400">#<?= $sale_id ?></span></h1>
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

    <form action="update_sale_full.php" method="POST" enctype="multipart/form-data" class="space-y-8">
        <?= csrf_field() ?>
        <input type="hidden" name="sale_id" value="<?= $sale_id ?>">

        <!-- ── SECCIÓN 1: DATOS DEL CLIENTE ── -->
        <div class="bg-slate-900 rounded-2xl border border-slate-800 p-6 md:p-8 shadow-2xl">
            <div class="flex items-center gap-3 mb-6 text-blue-400 border-b border-slate-800 pb-4">
                <i data-lucide="user"></i>
                <h2 class="font-bold text-lg text-white tracking-tight uppercase">Datos del Cliente</h2>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-2 ml-1">DNI <span class="text-red-400">*</span></label>
                    <input type="text" name="client_dni" required
                           value="<?= htmlspecialchars($order['client_dni'] ?? '') ?>"
                           class="w-full bg-slate-950 border border-slate-700 rounded-xl px-4 py-3 text-white focus:border-blue-500 outline-none transition font-mono">
                </div>
                <div class="md:col-span-1 lg:col-span-2">
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-2 ml-1">Apellido y Nombre <span class="text-red-400">*</span></label>
                    <input type="text" name="client_name" required
                           value="<?= htmlspecialchars($order['client_name'] ?? '') ?>"
                           class="w-full bg-slate-950 border border-slate-700 rounded-xl px-4 py-3 text-white focus:border-blue-500 outline-none transition">
                </div>

                <div class="md:col-span-2">
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-2 ml-1">Domicilio <span class="text-red-400">*</span></label>
                    <input type="text" name="client_address" required
                           value="<?= htmlspecialchars($order['client_address'] ?? '') ?>"
                           class="w-full bg-slate-950 border border-slate-700 rounded-xl px-4 py-3 text-white focus:border-blue-500 outline-none transition">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-2 ml-1">Barrio <span class="text-red-400">*</span></label>
                    <input type="text" name="client_neighborhood" required
                           value="<?= htmlspecialchars($order['client_neighborhood'] ?? '') ?>"
                           class="w-full bg-slate-950 border border-slate-700 rounded-xl px-4 py-3 text-white focus:border-blue-500 outline-none transition">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-2 ml-1">Localidad <span class="text-red-400">*</span></label>
                    <input type="text" name="client_locality" required
                           value="<?= htmlspecialchars($order['client_locality'] ?? '') ?>"
                           class="w-full bg-slate-950 border border-slate-700 rounded-xl px-4 py-3 text-white focus:border-blue-500 outline-none transition">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-2 ml-1">WhatsApp <span class="text-red-400">*</span></label>
                    <input type="text" name="client_whatsapp" required
                           value="<?= htmlspecialchars($order['client_whatsapp'] ?? '') ?>"
                           class="w-full bg-slate-950 border border-slate-700 rounded-xl px-4 py-3 text-white focus:border-blue-500 outline-none transition font-mono">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-2 ml-1">Nro Llamada (Opcional)</label>
                    <input type="text" name="client_phone"
                           value="<?= htmlspecialchars($order['client_phone'] ?? '') ?>"
                           class="w-full bg-slate-950 border border-slate-700 rounded-xl px-4 py-3 text-white focus:border-blue-500 outline-none transition font-mono">
                </div>

                <div class="md:col-span-2 lg:col-span-3">
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-2 ml-1">Ubicación Google Maps</label>
                    <div class="relative">
                        <input type="text" name="client_map_link"
                               value="<?= htmlspecialchars($order['client_map_link'] ?? '') ?>"
                               placeholder="Pegue el enlace aquí (https://maps...)"
                               class="w-full bg-slate-950 border border-slate-700 rounded-xl px-4 py-3 pr-10 text-white focus:border-emerald-500 outline-none transition">
                        <div class="absolute right-3 top-1/2 -translate-y-1/2 text-emerald-500 pointer-events-none">
                            <i data-lucide="map-pin" class="w-4 h-4"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── SECCIÓN 2: DATOS LABORALES ── -->
        <div class="bg-slate-900 rounded-2xl border border-slate-800 p-6 md:p-8 shadow-2xl">
            <div class="flex items-center gap-3 mb-6 text-indigo-400 border-b border-slate-800 pb-4">
                <i data-lucide="briefcase"></i>
                <h2 class="font-bold text-lg text-white tracking-tight uppercase">Datos Laborales</h2>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-2 ml-1">Tipo de Empleo</label>
                    <input type="text" name="job_type"
                           value="<?= htmlspecialchars($order['job_type'] ?? '') ?>"
                           placeholder="Ej: Rel. Dependencia, Monotributo"
                           class="w-full bg-slate-950 border border-slate-700 rounded-xl px-4 py-3 text-white focus:border-indigo-500 outline-none transition">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-2 ml-1">Ocupación</label>
                    <input type="text" name="job_occupation"
                           value="<?= htmlspecialchars($order['job_occupation'] ?? '') ?>"
                           placeholder="Ej: Empleado de comercio"
                           class="w-full bg-slate-950 border border-slate-700 rounded-xl px-4 py-3 text-white focus:border-indigo-500 outline-none transition">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-2 ml-1">Nombre del Trabajo</label>
                    <input type="text" name="job_name"
                           value="<?= htmlspecialchars($order['job_name'] ?? '') ?>"
                           placeholder="Nombre de la empresa o lugar"
                           class="w-full bg-slate-950 border border-slate-700 rounded-xl px-4 py-3 text-white focus:border-indigo-500 outline-none transition">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-2 ml-1">Domicilio Laboral</label>
                    <input type="text" name="job_address"
                           value="<?= htmlspecialchars($order['job_address'] ?? '') ?>"
                           placeholder="Dirección del trabajo"
                           class="w-full bg-slate-950 border border-slate-700 rounded-xl px-4 py-3 text-white focus:border-indigo-500 outline-none transition">
                </div>
            </div>
        </div>

        <!-- ── SECCIÓN 3: DETALLE DE LA VENTA ── -->
        <div class="bg-slate-900 rounded-2xl border border-slate-800 p-6 md:p-8 shadow-2xl">
            <div class="flex items-center gap-3 mb-6 text-emerald-400 border-b border-slate-800 pb-4">
                <i data-lucide="dollar-sign"></i>
                <h2 class="font-bold text-lg text-white uppercase tracking-tight">Detalle de la Venta</h2>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <div class="md:col-span-2">
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-2 ml-1">Artículo <span class="text-red-400">*</span></label>
                    <input type="text" name="item" required
                           value="<?= htmlspecialchars($order['item'] ?? '') ?>"
                           placeholder="Producto vendido"
                           class="w-full bg-slate-950 border border-slate-700 rounded-xl px-4 py-3 text-white focus:border-emerald-500 outline-none transition">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-2 ml-1">Día de Cobro <span class="text-red-400">*</span></label>
                    <select name="payment_day" required
                            class="w-full bg-slate-950 border border-slate-700 rounded-xl px-4 py-3 text-white focus:border-emerald-500 outline-none cursor-pointer">
                        <?php foreach (['Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'] as $dia): ?>
                            <option value="<?= $dia ?>" <?= ($order['payment_day'] ?? '') === $dia ? 'selected' : '' ?>><?= $dia ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-2 ml-1">Frecuencia <span class="text-red-400">*</span></label>
                    <select name="payment_frequency" required
                            class="w-full bg-slate-950 border border-slate-700 rounded-xl px-4 py-3 text-white focus:border-emerald-500 outline-none">
                        <?php foreach (['semanal' => 'Semanal', 'quincenal' => 'Quincenal', 'mensual' => 'Mensual'] as $val => $label): ?>
                            <option value="<?= $val ?>" <?= ($order['payment_frequency'] ?? 'semanal') === $val ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-2 ml-1">Cuotas <span class="text-red-400">*</span></label>
                    <input type="number" name="installments" id="edit_cuotas" required min="1"
                           value="<?= (int)($order['installments_count'] ?? 0) ?>"
                           class="w-full bg-slate-950 border border-slate-700 rounded-xl px-4 py-3 text-white focus:border-emerald-500 outline-none transition"
                           oninput="recalcTotal()">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-2 ml-1">Monto por cuota ($) <span class="text-red-400">*</span></label>
                    <input type="number" name="amount" id="edit_monto" required min="0"
                           value="<?= (float)($order['installment_amount'] ?? 0) ?>"
                           class="w-full bg-slate-950 border border-slate-700 rounded-xl px-4 py-3 text-white focus:border-emerald-500 outline-none transition"
                           oninput="recalcTotal()">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-2 ml-1">Adelanto ($)</label>
                    <input type="number" name="down_payment" min="0" step="0.01"
                           value="<?= (float)($order['down_payment'] ?? 0) ?>"
                           class="w-full bg-slate-950 border border-slate-700 rounded-xl px-4 py-3 text-white focus:border-emerald-500 outline-none transition">
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
            <div class="bg-slate-900 rounded-2xl border border-slate-800 p-6 md:p-8 shadow-2xl">
                <div class="flex items-center gap-3 mb-6 text-purple-400 border-b border-slate-800 pb-4">
                    <i data-lucide="files"></i>
                    <h2 class="font-bold text-lg text-white tracking-tight uppercase">Documentación Adjunta</h2>
                </div>

                <!-- Archivos existentes -->
                <?php if (!empty($files)): ?>
                <div class="mb-5">
                    <p class="text-[10px] font-bold text-slate-500 uppercase mb-3 ml-1">Archivos actuales:</p>
                    <div id="files-container" class="flex flex-wrap gap-2">
                        <?php foreach ($files as $f):
                            $isPdf = str_ends_with(strtolower($f['file_path']), '.pdf');
                        ?>
                        <div class="flex items-center gap-2 bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-xs text-slate-400" id="chip-<?= $f['id'] ?>">
                            <i data-lucide="<?= $isPdf ? 'file-text' : 'image' ?>" class="w-4 h-4 text-purple-400 shrink-0"></i>
                            <a href="uploads/<?= htmlspecialchars($f['file_path']) ?>" target="_blank"
                               class="truncate max-w-[130px] hover:text-purple-300 transition">
                                <?= htmlspecialchars($f['file_path']) ?>
                            </a>
                            <!-- Botón eliminar archivo (mini-form CSRF) -->
                            <form method="POST" action="delete_sale_file.php"
                                  onsubmit="return confirm('¿Eliminar este archivo? Esta acción no se puede deshacer.')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="file_id" value="<?= $f['id'] ?>">
                                <input type="hidden" name="sale_id" value="<?= $sale_id ?>">
                                <button type="submit"
                                        class="p-1 text-slate-600 hover:text-red-400 transition rounded"
                                        title="Eliminar archivo">
                                    <i data-lucide="x" class="w-3.5 h-3.5"></i>
                                </button>
                            </form>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php else: ?>
                <p class="text-slate-600 text-sm italic mb-5">No hay archivos adjuntos aún.</p>
                <?php endif; ?>

                <!-- Zona de subida de nuevos archivos -->
                <div class="bg-slate-950 border-2 border-dashed border-slate-800 rounded-2xl p-8 text-center group hover:border-purple-500/50 transition-colors">
                    <input type="hidden" name="MAX_FILE_SIZE" value="5242880">
                    <input type="file" name="sale_files[]" id="sale_files" multiple accept="image/*,.pdf"
                           class="hidden" onchange="previewNewFiles(this)">
                    <label for="sale_files" class="cursor-pointer flex flex-col items-center">
                        <i data-lucide="upload-cloud" class="w-8 h-8 text-slate-500 mb-4 transition group-hover:text-purple-400"></i>
                        <span class="text-white font-bold block mb-1">Click para agregar documentos</span>
                        <span class="text-slate-500 text-xs">JPG, PNG, PDF · Máx. 5 MB por archivo</span>
                    </label>
                </div>
                <div id="new-file-list" class="mt-4 flex flex-wrap gap-2 justify-center"></div>
            </div>

            <!-- Observaciones -->
            <div class="bg-slate-900 rounded-2xl border border-slate-800 p-6 md:p-8 shadow-2xl">
                <div class="flex items-center gap-3 mb-6 text-yellow-400 border-b border-slate-800 pb-4">
                    <i data-lucide="message-square"></i>
                    <h2 class="font-bold text-lg text-white tracking-tight uppercase">Observaciones</h2>
                </div>
                <textarea name="observations" rows="5"
                          placeholder="Horarios de entrega, aclaraciones del crédito, etc."
                          class="w-full bg-slate-950 border border-slate-700 rounded-xl px-4 py-4 text-white focus:border-yellow-500 outline-none transition resize-none h-[185px]"><?= htmlspecialchars($order['observations'] ?? '') ?></textarea>
            </div>
        </div>

        <!-- Botones -->
        <div class="flex flex-col sm:flex-row gap-4">
            <a href="ver_ficha.php?id=<?= $sale_id ?>"
               class="flex-1 sm:flex-none text-center px-8 py-4 bg-slate-800 hover:bg-slate-700 text-slate-300 font-bold rounded-2xl transition border border-slate-700">
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
                span.className = 'bg-slate-800 text-slate-400 text-[10px] px-2 py-1 rounded border border-slate-700 flex items-center gap-1';
                span.innerHTML = `<i data-lucide="file" class="w-3 h-3"></i> ${file.name}`;
                list.appendChild(span);
            });
            lucide.createIcons();
        }
    }
</script>

<?php include 'includes/footer.php'; ?>
