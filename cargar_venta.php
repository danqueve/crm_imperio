<?php
require 'includes/db.php';

// SEGURIDAD: Permitir acceso a Vendedor, Admin, Supervisor Y VERIFICADOR
$allowed_roles = ['vendedor', 'admin', 'supervisor', 'verificador'];

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) { 
    header("Location: dashboard.php"); 
    exit; 
}

include 'includes/header.php';
?>

<main class="flex-1 max-w-5xl mx-auto w-full p-4 sm:p-6 lg:p-8 fade-in">
    
    <!-- Cabecera interna -->
    <div class="flex items-center gap-4 mb-8">
        <a href="dashboard.php" class="p-2 bg-slate-800 rounded-full text-slate-400 hover:text-white transition border border-slate-700">
            <i data-lucide="chevron-left"></i>
        </a>
        <div>
            <h1 class="text-2xl font-bold text-white">Cargar Nueva Venta</h1>
            <p class="text-sm text-slate-500">Complete el formulario siguiendo los datos de la ficha del cliente.</p>
        </div>
    </div>

    <!-- BLOQUE DE ERRORES MEJORADO -->
    <?php if (isset($_GET['error'])): ?>
    <div class="mb-8 p-4 bg-red-500/10 border border-red-500/20 text-red-400 rounded-xl flex items-start gap-3 shadow-lg animate-pulse">
        <div class="bg-red-500/20 p-2 rounded-lg">
            <i data-lucide="alert-circle" class="w-5 h-5"></i>
        </div>
        <div>
            <span class="font-bold block text-base">Atención: No se pudo guardar la venta</span>
            <p class="text-sm opacity-90 mt-1">
                <?php 
                    if ($_GET['error'] == 'missing_data') {
                        echo "Faltan campos obligatorios. Por favor, asegúrese de completar DNI, Nombre del Cliente y el Artículo.";
                    } elseif ($_GET['error'] == 'db_error') {
                        echo "Error de base de datos. Esto suele suceder si faltan columnas en la tabla 'sales'. <br><small class='opacity-70 font-mono'>Detalle: " . htmlspecialchars($_GET['msg'] ?? 'Desconocido') . "</small>";
                    } else {
                        echo "Ocurrió un error inesperado al procesar la solicitud.";
                    }
                ?>
            </p>
        </div>
    </div>
    <?php endif; ?>

    <form action="save_sale.php" method="POST" enctype="multipart/form-data" class="space-y-8">
        <?= csrf_field() ?>

        <!-- Sección 1: Datos del Cliente -->
        <div class="bg-slate-900 rounded-2xl border border-slate-800 p-6 md:p-8 shadow-2xl">
            <div class="flex items-center gap-3 mb-6 text-blue-400 border-b border-slate-800 pb-4">
                <i data-lucide="user"></i>
                <h2 class="font-bold text-lg text-white tracking-tight uppercase">DATOS DEL CLIENTE</h2>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-2 ml-1">DNI</label>
                    <input type="text" name="client_dni" required placeholder="Sin puntos ni espacios" 
                           class="w-full bg-slate-950 border border-slate-700 rounded-xl px-4 py-3 text-white focus:border-blue-500 outline-none transition font-mono">
                </div>
                <div class="md:col-span-1 lg:col-span-2">
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-2 ml-1">Apellido y Nombre</label>
                    <input type="text" name="client_name" required placeholder="Nombre completo" 
                           class="w-full bg-slate-950 border border-slate-700 rounded-xl px-4 py-3 text-white focus:border-blue-500 outline-none transition">
                </div>

                <div class="md:col-span-2">
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-2 ml-1">Domicilio</label>
                    <input type="text" name="client_address" required placeholder="Calle y número" 
                           class="w-full bg-slate-950 border border-slate-700 rounded-xl px-4 py-3 text-white focus:border-blue-500 outline-none transition">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-2 ml-1">Barrio</label>
                    <input type="text" name="client_neighborhood" required placeholder="Barrio" 
                           class="w-full bg-slate-950 border border-slate-700 rounded-xl px-4 py-3 text-white focus:border-blue-500 outline-none transition">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-2 ml-1">Localidad</label>
                    <input type="text" name="client_locality" required placeholder="Ciudad / Localidad" 
                           class="w-full bg-slate-950 border border-slate-700 rounded-xl px-4 py-3 text-white focus:border-blue-500 outline-none transition">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-2 ml-1">WhatsApp</label>
                    <input type="text" name="client_whatsapp" required placeholder="Ej: 381..." 
                           class="w-full bg-slate-950 border border-slate-700 rounded-xl px-4 py-3 text-white focus:border-blue-500 outline-none transition font-mono">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-2 ml-1">Nro Llamada (Opcional)</label>
                    <input type="text" name="client_phone" placeholder="Alternativo" 
                           class="w-full bg-slate-950 border border-slate-700 rounded-xl px-4 py-3 text-white focus:border-blue-500 outline-none transition font-mono">
                </div>

                <div class="md:col-span-2 lg:col-span-3">
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-2 ml-1">Ubicación Google Maps</label>
                    <input type="text" name="client_map_link" placeholder="Pegue el enlace aquí (https://maps...)" 
                           class="w-full bg-slate-950 border border-slate-700 rounded-xl px-4 py-3 text-white focus:border-blue-500 outline-none transition">
                </div>
            </div>
        </div>

        <!-- Sección 2: Datos Laborales -->
        <div class="bg-slate-900 rounded-2xl border border-slate-800 p-6 md:p-8 shadow-2xl">
            <div class="flex items-center gap-3 mb-6 text-blue-400 border-b border-slate-800 pb-4">
                <i data-lucide="briefcase"></i>
                <h2 class="font-bold text-lg text-white tracking-tight uppercase">DATOS LABORALES</h2>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-2 ml-1">Tipo de Empleo</label>
                    <input type="text" name="job_type" placeholder="Ej: Rel. Dependencia, Monotributo" 
                           class="w-full bg-slate-950 border border-slate-700 rounded-xl px-4 py-3 text-white focus:border-blue-500 outline-none transition">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-2 ml-1">Ocupación</label>
                    <input type="text" name="job_occupation" placeholder="Ej: Empleado de comercio" 
                           class="w-full bg-slate-950 border border-slate-700 rounded-xl px-4 py-3 text-white focus:border-blue-500 outline-none transition">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-2 ml-1">Nombre del Trabajo</label>
                    <input type="text" name="job_name" placeholder="Nombre de la empresa o lugar" 
                           class="w-full bg-slate-950 border border-slate-700 rounded-xl px-4 py-3 text-white focus:border-blue-500 outline-none transition">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-2 ml-1">Domicilio Laboral</label>
                    <input type="text" name="job_address" placeholder="Dirección del trabajo" 
                           class="w-full bg-slate-950 border border-slate-700 rounded-xl px-4 py-3 text-white focus:border-blue-500 outline-none transition">
                </div>
            </div>
        </div>

        <!-- Sección 3: Detalle de la Venta -->
        <div class="bg-slate-900 rounded-2xl border border-slate-800 p-6 md:p-8 shadow-2xl">
            <div class="flex items-center gap-3 mb-6 text-emerald-400 border-b border-slate-800 pb-4">
                <i data-lucide="dollar-sign"></i>
                <h2 class="font-bold text-lg text-white uppercase tracking-tight">DETALLE DE LA VENTA</h2>
            </div>

            <!-- Fila principal -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-2 ml-1">Artículo</label>
                    <input type="text" name="item" required placeholder="Producto vendido" 
                           class="w-full bg-slate-950 border border-slate-700 rounded-xl px-4 py-3 text-white focus:border-emerald-500 outline-none transition">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-2 ml-1">Día de Cobro</label>
                    <select name="payment_day" required 
                            class="w-full bg-slate-950 border border-slate-700 rounded-xl px-4 py-3 text-white focus:border-emerald-500 outline-none cursor-pointer">
                        <option value="" disabled selected>Seleccionar día...</option>
                        <option value="Lunes">Lunes</option>
                        <option value="Martes">Martes</option>
                        <option value="Miércoles">Miércoles</option>
                        <option value="Jueves">Jueves</option>
                        <option value="Viernes">Viernes</option>
                        <option value="Sábado">Sábado</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-2 ml-1">Cuotas</label>
                    <input type="number" name="installments_count" id="installments_count" required placeholder="Cant." 
                           class="w-full bg-slate-950 border border-slate-700 rounded-xl px-4 py-3 text-white focus:border-emerald-500 outline-none transition" oninput="calculateTotal()">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-2 ml-1">Monto ($)</label>
                    <input type="number" name="installment_amount" id="installment_amount" required placeholder="Monto" 
                           class="w-full bg-slate-950 border border-slate-700 rounded-xl px-4 py-3 text-white focus:border-emerald-500 outline-none transition" oninput="calculateTotal()">
                </div>
            </div>

            <!-- Fila frecuencia y adelanto -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-2 ml-1">Frecuencia de Pago</label>
                    <select name="payment_frequency" required 
                            class="w-full bg-slate-950 border border-slate-700 rounded-xl px-4 py-3 text-white focus:border-emerald-500 outline-none">
                        <option value="semanal" selected>Semanal</option>
                        <option value="quincenal">Quincenal</option>
                        <option value="mensual">Mensual</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-2 ml-1">Adelanto ($)</label>
                    <input type="number" name="down_payment" value="0"
                           class="w-full bg-slate-950 border border-slate-700 rounded-xl px-4 py-3 text-white focus:border-emerald-500 outline-none transition">
                </div>
            </div>

            <!-- Bloque de Total -->
            <div class="bg-emerald-950/20 border border-emerald-500/20 rounded-xl p-4 flex justify-between items-center px-8 shadow-inner">
                <span class="text-xs uppercase text-emerald-500 font-bold tracking-widest">TOTAL CALCULADO</span>
                <div class="flex items-center gap-1 text-2xl font-bold text-emerald-400">
                    <span>$</span>
                    <input type="number" name="total_amount" id="total_amount" value="0" readonly 
                           class="bg-transparent border-none p-0 w-32 focus:ring-0 text-right outline-none cursor-default">
                </div>
            </div>
        </div>

        <!-- Sección 4: Archivos y Observaciones -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <div class="bg-slate-900 rounded-2xl border border-slate-800 p-6 md:p-8 shadow-2xl">
                <div class="flex items-center gap-3 mb-6 text-purple-400 border-b border-slate-800 pb-4">
                    <i data-lucide="file-text"></i>
                    <h2 class="font-bold text-lg text-white tracking-tight uppercase">DOCUMENTACIÓN ADJUNTA</h2>
                </div>
                <div class="bg-slate-950 border-2 border-dashed border-slate-800 rounded-2xl p-8 text-center group hover:border-blue-500/50 transition-colors">
                    <input type="hidden" name="MAX_FILE_SIZE" value="5242880">
                    <input type="file" name="sale_files[]" id="sale_files" multiple accept="image/*,.pdf" class="hidden" onchange="previewFiles(this)">
                    <label for="sale_files" class="cursor-pointer flex flex-col items-center">
                        <i data-lucide="upload-cloud" class="w-8 h-8 text-slate-500 mb-4 transition group-hover:text-blue-400"></i>
                        <span class="text-white font-bold block mb-1">Click para subir documentos</span>
                        <span class="text-slate-500 text-xs">(DNI, Servicios, Recibos de sueldo)</span>
                    </label>
                </div>
                <div id="file-list" class="mt-4 flex flex-wrap gap-2 justify-center"></div>
            </div>

            <div class="bg-slate-900 rounded-2xl border border-slate-800 p-6 md:p-8 shadow-2xl">
                <div class="flex items-center gap-3 mb-6 text-yellow-400 border-b border-slate-800 pb-4">
                    <i data-lucide="message-square"></i>
                    <h2 class="font-bold text-lg text-white tracking-tight uppercase">OBSERVACIONES</h2>
                </div>
                <textarea name="observations" rows="5" placeholder="Horarios de entrega, aclaraciones del crédito, etc." 
                          class="w-full bg-slate-950 border border-slate-700 rounded-xl px-4 py-4 text-white focus:border-yellow-500 outline-none transition resize-none h-[145px]"></textarea>
            </div>
        </div>

        <button type="submit" class="w-full bg-blue-600 hover:bg-blue-500 text-white font-bold py-4 rounded-2xl shadow-xl shadow-blue-900/30 flex items-center justify-center gap-3 transition transform active:scale-[0.98]">
            <i data-lucide="send"></i> GUARDAR Y ENVIAR VENTA PARA REVISIÓN
        </button>

    </form>
</main>

<script>
    // Función para calcular el total en tiempo real (Cuotas * Monto)
    function calculateTotal() {
        const installments = parseFloat(document.getElementById('installments_count').value) || 0;
        const amount = parseFloat(document.getElementById('installment_amount').value) || 0;
        const total = installments * amount;
        document.getElementById('total_amount').value = Math.round(total);
    }

    // Previsualización de archivos seleccionados
    function previewFiles(input) {
        const list = document.getElementById('file-list');
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