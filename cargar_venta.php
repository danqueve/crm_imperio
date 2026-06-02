<?php
require 'includes/db.php';

// SEGURIDAD: Permitir acceso a Vendedor, Admin, Supervisor, Verificador y Entregador
$allowed_roles = ['vendedor', 'admin', 'supervisor', 'verificador', 'entregador'];

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) { 
    header("Location: dashboard.php"); 
    exit; 
}

include 'includes/header.php';
?>

<main class="flex-1 max-w-5xl mx-auto w-full p-4 sm:p-6 lg:p-8 fade-in">
    
    <!-- Cabecera interna -->
    <div class="flex items-center gap-4 mb-8">
        <a href="dashboard.php" class="w-10 h-10 flex items-center justify-center rounded-full shrink-0 transition" style="background:var(--paper);border:1.5px solid var(--line);color:var(--ink-3);" onmouseover="this.style.background='var(--accent-soft)';this.style.color='var(--accent-ink)'" onmouseout="this.style.background='var(--paper)';this.style.color='var(--ink-3)'">
            <i data-lucide="chevron-left"></i>
        </a>
        <div>
            <h1 class="text-2xl font-bold" style="color:var(--ink);">Cargar Nueva Venta</h1>
            <p class="text-sm" style="color:var(--ink-3);">Complete el formulario siguiendo los datos de la ficha del cliente.</p>
        </div>
    </div>

    <!-- BLOQUE DE ERRORES MEJORADO -->
    <?php if (isset($_GET['error'])):
        $field_messages = [
            'dni'              => 'DNI es obligatorio.',
            'dni_formato'      => 'El DNI debe tener 7 u 8 dígitos numéricos.',
            'nombre'           => 'El nombre del cliente es obligatorio.',
            'whatsapp_formato' => 'El WhatsApp debe contener solo dígitos (7 a 15 caracteres).',
            'telefono_formato' => 'El teléfono alternativo tiene un formato inválido.',
            'articulo'         => 'El artículo es obligatorio.',
            'monto'            => 'El monto por cuota debe ser mayor a cero.',
            'cuotas'           => 'La cantidad de cuotas debe ser mayor a cero.',
        ];
        $fields = array_filter(explode(',', $_GET['fields'] ?? ''));
        $specific_msgs = array_filter(array_map(fn($f) => $field_messages[$f] ?? null, $fields));
    ?>
    <div class="mb-8 p-4 bg-red-500/10 border border-red-500/20 text-red-400 rounded-xl flex items-start gap-3 shadow-lg">
        <div class="bg-red-500/20 p-2 rounded-lg shrink-0">
            <i data-lucide="alert-circle" class="w-5 h-5"></i>
        </div>
        <div>
            <span class="font-bold block text-base">Atención: No se pudo guardar la venta</span>
            <p class="text-sm opacity-90 mt-1">
                <?php
                    if ($_GET['error'] === 'csrf') {
                        echo "La sesión expiró mientras completaba el formulario. Por favor vuelva a ingresar los datos.";
                    } elseif ($_GET['error'] === 'missing_data' && !empty($specific_msgs)) {
                        echo implode(' ', $specific_msgs);
                    } elseif ($_GET['error'] === 'missing_data') {
                        echo "Faltan campos obligatorios. Por favor, asegúrese de completar DNI, Nombre del Cliente y el Artículo.";
                    } elseif ($_GET['error'] === 'db_error') {
                        echo "Error interno al guardar la venta. Por favor intente nuevamente o contacte al administrador.";
                    } else {
                        echo "Ocurrió un error inesperado al procesar la solicitud.";
                    }
                ?>
            </p>
        </div>
    </div>
    <?php endif; ?>

    <form action="save_sale.php" method="POST" enctype="multipart/form-data" class="space-y-8" id="ventaForm">
        <?= csrf_field() ?>

        <!-- Sección 1: Datos del Cliente -->
        <div class="rounded-2xl p-6 md:p-8" style="background:var(--card);border:1.5px solid var(--line);box-shadow:var(--shadow-card);">
            <div class="flex items-center gap-3 mb-6 pb-4" style="color:var(--accent);border-bottom:1.5px solid var(--line);">
                <i data-lucide="user"></i>
                <h2 class="font-bold text-lg tracking-tight uppercase" style="color:var(--ink);">DATOS DEL CLIENTE</h2>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <div>
                    <label class="block text-xs font-bold uppercase mb-2 ml-1" style="color:var(--ink-3);">DNI</label>
                    <input type="text" name="client_dni" required placeholder="Sin puntos ni espacios" 
                           class="w-full input-light px-4 py-3 font-mono">
                </div>
                <div class="md:col-span-1 lg:col-span-2">
                    <label class="block text-xs font-bold uppercase mb-2 ml-1" style="color:var(--ink-3);">Apellido y Nombre</label>
                    <input type="text" name="client_name" required placeholder="Nombre completo" 
                           class="w-full input-light px-4 py-3">
                </div>

                <div class="md:col-span-2">
                    <label class="block text-xs font-bold uppercase mb-2 ml-1" style="color:var(--ink-3);">Domicilio</label>
                    <input type="text" name="client_address" required placeholder="Calle y número" 
                           class="w-full input-light px-4 py-3">
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase mb-2 ml-1" style="color:var(--ink-3);">Barrio</label>
                    <input type="text" name="client_neighborhood" required placeholder="Barrio" 
                           class="w-full input-light px-4 py-3">
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase mb-2 ml-1" style="color:var(--ink-3);">Localidad</label>
                    <select name="client_locality" required
                            class="w-full input-light px-4 py-3">
                        <option value="" disabled selected>Seleccionar localidad...</option>
                        <option value="Acheral">Acheral</option>
                        <option value="Aguilares">Aguilares</option>
                        <option value="Alderete">Alderete</option>
                        <option value="Banda del Río Salí">Banda del Río Salí</option>
                        <option value="Bella Vista">Bella Vista</option>
                        <option value="Burruyacu">Burruyacu</option>
                        <option value="Catamarca">Catamarca</option>
                        <option value="Concepción">Concepción</option>
                        <option value="Famaillá">Famaillá</option>
                        <option value="Lules">Lules</option>
                        <option value="Manantial">Manantial</option>
                        <option value="Monteros">Monteros</option>
                        <option value="Río Colorado">Río Colorado</option>
                        <option value="San Miguel de Tucumán">San Miguel de Tucumán</option>
                        <option value="Simoca">Simoca</option>
                        <option value="Tafí del Valle">Tafí del Valle</option>
                        <option value="Tafí Viejo">Tafí Viejo</option>
                        <option value="Termas">Termas</option>
                        <option value="Sgo">Sgo del Estero</option>
                        <option value="Villa Carmela">Villa Carmela</option>
                        <option value="Yerba Buena">Yerba Buena</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase mb-2 ml-1" style="color:var(--ink-3);">WhatsApp</label>
                    <input type="text" name="client_whatsapp" required placeholder="Ej: 381..." 
                           class="w-full input-light px-4 py-3 font-mono">
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase mb-2 ml-1" style="color:var(--ink-3);">Nro Llamada (Opcional)</label>
                    <input type="text" name="client_phone" placeholder="Alternativo" 
                           class="w-full input-light px-4 py-3 font-mono">
                </div>

                <div class="md:col-span-2 lg:col-span-3">
                    <label class="block text-xs font-bold uppercase mb-2 ml-1" style="color:var(--ink-3);">Ubicación Google Maps</label>
                    <input type="text" name="client_map_link" placeholder="Pegue el enlace aquí (https://maps...)" 
                           class="w-full input-light px-4 py-3">
                </div>
            </div>
        </div>

        <!-- Sección 2: Datos Laborales -->
        <div class="rounded-2xl p-6 md:p-8" style="background:var(--card);border:1.5px solid var(--line);box-shadow:var(--shadow-card);">
            <div class="flex items-center gap-3 mb-6 pb-4" style="color:var(--accent);border-bottom:1.5px solid var(--line);">
                <i data-lucide="briefcase"></i>
                <h2 class="font-bold text-lg tracking-tight uppercase" style="color:var(--ink);">DATOS LABORALES</h2>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label class="block text-xs font-bold uppercase mb-2 ml-1" style="color:var(--ink-3);">Tipo de Empleo</label>
                    <input type="text" name="job_type" placeholder="Ej: Rel. Dependencia, Monotributo" 
                           class="w-full input-light px-4 py-3">
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase mb-2 ml-1" style="color:var(--ink-3);">Ocupación</label>
                    <input type="text" name="job_occupation" placeholder="Ej: Empleado de comercio" 
                           class="w-full input-light px-4 py-3">
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase mb-2 ml-1" style="color:var(--ink-3);">Nombre del Trabajo</label>
                    <input type="text" name="job_name" placeholder="Nombre de la empresa o lugar" 
                           class="w-full input-light px-4 py-3">
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase mb-2 ml-1" style="color:var(--ink-3);">Domicilio Laboral</label>
                    <input type="text" name="job_address" placeholder="Dirección del trabajo" 
                           class="w-full input-light px-4 py-3">
                </div>
            </div>
        </div>

        <!-- Sección 3: Detalle de la Venta -->
        <div class="rounded-2xl p-6 md:p-8" style="background:var(--card);border:1.5px solid var(--line);box-shadow:var(--shadow-card);">
            <div class="flex items-center gap-3 mb-6 pb-4" style="color:#059669;border-bottom:1.5px solid var(--line);">
                <i data-lucide="dollar-sign"></i>
                <h2 class="font-bold text-lg uppercase tracking-tight" style="color:var(--ink);">DETALLE DE LA VENTA</h2>
            </div>

            <!-- Fila principal -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <div>
                    <label class="block text-xs font-bold uppercase mb-2 ml-1" style="color:var(--ink-3);">Artículo</label>
                    <input type="text" name="item" required placeholder="Producto vendido" 
                           class="w-full input-light px-4 py-3">
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase mb-2 ml-1" style="color:var(--ink-3);">Día de Cobro</label>
                    <select name="payment_day" required 
                            class="w-full input-light px-4 py-3 cursor-pointer">
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
                    <label class="block text-xs font-bold uppercase mb-2 ml-1" style="color:var(--ink-3);">Cuotas</label>
                    <input type="number" name="installments_count" id="installments_count" required placeholder="Cant." 
                           class="w-full input-light px-4 py-3" oninput="calculateTotal()">
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase mb-2 ml-1" style="color:var(--ink-3);">Monto ($)</label>
                    <input type="number" name="installment_amount" id="installment_amount" required placeholder="Monto" 
                           class="w-full input-light px-4 py-3" oninput="calculateTotal()">
                </div>
            </div>

            <!-- Fila frecuencia y adelanto -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                <div>
                    <label class="block text-xs font-bold uppercase mb-2 ml-1" style="color:var(--ink-3);">Frecuencia de Pago</label>
                    <select name="payment_frequency" required 
                            class="w-full input-light px-4 py-3 cursor-pointer">
                        <option value="semanal" selected>Semanal</option>
                        <option value="quincenal">Quincenal</option>
                        <option value="mensual">Mensual</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase mb-2 ml-1" style="color:var(--ink-3);">Adelanto ($)</label>
                    <input type="number" name="down_payment" value="0"
                           class="w-full input-light px-4 py-3">
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
            <div class="rounded-2xl p-6 md:p-8" style="background:var(--card);border:1.5px solid var(--line);box-shadow:var(--shadow-card);">
                <div class="flex items-center gap-3 mb-6 pb-4" style="color:#7c3aed;border-bottom:1.5px solid var(--line);">
                    <i data-lucide="file-text"></i>
                    <h2 class="font-bold text-lg tracking-tight uppercase" style="color:var(--ink);">DOCUMENTACIÓN ADJUNTA</h2>
                </div>
                <div class="rounded-2xl p-8 text-center group transition-colors" style="background:var(--paper);border:2px dashed var(--line);" onmouseover="this.style.borderColor='var(--accent)'" onmouseout="this.style.borderColor='var(--line)'">
                    <input type="hidden" name="MAX_FILE_SIZE" value="5242880">
                    <input type="file" name="sale_files[]" id="sale_files" multiple accept="image/*,.pdf" class="hidden" onchange="previewFiles(this)">
                    <label for="sale_files" class="cursor-pointer flex flex-col items-center">
                        <i data-lucide="upload-cloud" class="w-8 h-8 mb-4 transition" style="color:var(--ink-3);"></i>
                        <span class="font-bold block mb-1" style="color:var(--ink);">Click para subir documentos</span>
                        <span class="text-xs" style="color:var(--ink-3);">(DNI, Servicios, Recibos de sueldo)</span>
                    </label>
                </div>
                <div id="file-list" class="mt-4 flex flex-wrap gap-2 justify-center"></div>
            </div>

            <div class="rounded-2xl p-6 md:p-8" style="background:var(--card);border:1.5px solid var(--line);box-shadow:var(--shadow-card);">
                <div class="flex items-center gap-3 mb-6 pb-4" style="color:var(--rev-ink);border-bottom:1.5px solid var(--line);">
                    <i data-lucide="message-square"></i>
                    <h2 class="font-bold text-lg tracking-tight uppercase" style="color:var(--ink);">OBSERVACIONES</h2>
                </div>
                <textarea name="observations" rows="5" placeholder="Horarios de entrega, aclaraciones del crédito, etc."
                          class="w-full input-light px-4 py-4 resize-none h-[145px]"></textarea>
            </div>
        </div>

        <button type="submit" class="w-full text-white font-bold py-4 rounded-2xl flex items-center justify-center gap-3 transition active:scale-[0.98]" style="background:var(--accent);box-shadow:0 4px 20px rgba(99,102,241,.3);" onmouseover="this.style.background='#4f46e5'" onmouseout="this.style.background='var(--accent)'"  >
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
                span.className = 'text-[10px] px-2 py-1 rounded flex items-center gap-1'; span.style.cssText = 'background:var(--paper);color:var(--ink-2);border:1px solid var(--line);';
                span.innerHTML = `<i data-lucide="file" class="w-3 h-3"></i> ${file.name}`;
                list.appendChild(span);
            });
            lucide.createIcons();
        }
    }

    // Loading state al enviar el formulario
    document.getElementById('ventaForm').addEventListener('submit', function() {
        const btn = this.querySelector('[type=submit]');
        btn.disabled = true;
        btn.classList.add('opacity-75', 'cursor-not-allowed');
        btn.innerHTML = '<svg class="animate-spin w-5 h-5 mr-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path></svg> Guardando...';
    });
</script>

<?php include 'includes/footer.php'; ?>