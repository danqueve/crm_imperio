<?php
require 'includes/db.php';

// SEGURIDAD: Permitir acceso a Vendedor, Admin, Supervisor, Verificador y Entregador
$allowed_roles = ['vendedor', 'admin', 'supervisor', 'verificador', 'entregador'];

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    header("Location: dashboard.php");
    exit;
}

// Sticky form: si venimos de un error de validación, recuperamos una sola vez lo que
// el usuario había cargado para no obligarlo a re-escribir todo el formulario.
$sticky = (isset($_GET['error']) && !empty($_SESSION['sale_form_sticky'])) ? $_SESSION['sale_form_sticky'] : [];
unset($_SESSION['sale_form_sticky']);

function old(string $key, array $sticky, string $default = ''): string {
    return htmlspecialchars($sticky[$key] ?? $default);
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
            'dni_rechazado'    => 'Este DNI tiene un rechazo previo sin una compra exitosa posterior. Comuníquese con el Administrador.',
            'whatsapp_rechazado' => 'Este número de WhatsApp/Celular tiene un rechazo previo sin una compra exitosa posterior. Comuníquese con el Administrador.',
            'nombre'           => 'El nombre del cliente es obligatorio.',
            'whatsapp_formato' => 'El WhatsApp debe contener solo dígitos (7 a 15 caracteres).',
            'telefono'         => 'El número de llamada (teléfono alternativo) es obligatorio.',
            'telefono_formato' => 'El teléfono alternativo tiene un formato inválido.',
            'maps'             => 'La ubicación de Google Maps es obligatoria (debe ser un enlace válido).',
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
            <?php if ($_GET['error'] === 'missing_data' && !empty($sticky)): ?>
            <p class="text-xs opacity-75 mt-1">Los demás datos que había completado se mantuvieron. Si había adjuntado archivos, debe volver a seleccionarlos.</p>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <form action="save_sale.php" method="POST" enctype="multipart/form-data" class="space-y-8" id="ventaForm">
        <?= csrf_field() ?>

        <!-- Tipo de Venta -->
        <?php $cur_sale_type = ($sticky['sale_type'] ?? 'credito') === 'contado' ? 'contado' : 'credito'; ?>
        <div class="rounded-2xl p-6 md:p-8" style="background:var(--card);border:1.5px solid var(--line);box-shadow:var(--shadow-card);">
            <label class="block text-xs font-bold uppercase mb-3 ml-1" style="color:var(--ink-3);">Tipo de Venta</label>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <label id="label_sale_type_credito" class="cursor-pointer rounded-xl p-4 flex items-start gap-3 transition" style="border:1.5px solid var(--accent);background:var(--accent-soft);">
                    <input type="radio" name="sale_type" value="credito" id="sale_type_credito" class="mt-1" <?= $cur_sale_type === 'credito' ? 'checked' : '' ?>>
                    <div>
                        <p class="font-bold" style="color:var(--ink);">A Crédito</p>
                        <p class="text-xs mt-0.5" style="color:var(--ink-3);">Requiere todos los datos del cliente para evaluar el crédito.</p>
                    </div>
                </label>
                <label id="label_sale_type_contado" class="cursor-pointer rounded-xl p-4 flex items-start gap-3 transition" style="border:1.5px solid var(--line);">
                    <input type="radio" name="sale_type" value="contado" id="sale_type_contado" class="mt-1" <?= $cur_sale_type === 'contado' ? 'checked' : '' ?>>
                    <div>
                        <p class="font-bold" style="color:var(--ink);">De Contado</p>
                        <p class="text-xs mt-0.5" style="color:var(--ink-3);">Solo Nombre, Celular, Artículo y Ubicación.</p>
                    </div>
                </label>
            </div>
        </div>

        <!-- Método de Pago (solo Contado) -->
        <?php $cur_payment_method = ($sticky['payment_method'] ?? 'normal') === 'rapicompra' ? 'rapicompra' : 'normal'; ?>
        <div id="paymentMethodBlock" class="contado-only rounded-2xl p-6 md:p-8" style="background:var(--card);border:1.5px solid var(--line);box-shadow:var(--shadow-card);">
            <label class="block text-xs font-bold uppercase mb-3 ml-1" style="color:var(--ink-3);">Método de Pago</label>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <label id="label_payment_method_normal" class="cursor-pointer rounded-xl p-4 flex items-start gap-3 transition" style="border:1.5px solid var(--accent);background:var(--accent-soft);">
                    <input type="radio" name="payment_method" value="normal" id="payment_method_normal" class="mt-1" <?= $cur_payment_method === 'normal' ? 'checked' : '' ?>>
                    <div>
                        <p class="font-bold" style="color:var(--ink);">Normal</p>
                        <p class="text-xs mt-0.5" style="color:var(--ink-3);">Comisión estándar del vendedor.</p>
                    </div>
                </label>
                <label id="label_payment_method_rapicompra" class="cursor-pointer rounded-xl p-4 flex items-start gap-3 transition" style="border:1.5px solid var(--line);">
                    <input type="radio" name="payment_method" value="rapicompra" id="payment_method_rapicompra" class="mt-1" <?= $cur_payment_method === 'rapicompra' ? 'checked' : '' ?>>
                    <div>
                        <p class="font-bold" style="color:var(--ink);">RapiCompra</p>
                        <p class="text-xs mt-0.5" style="color:var(--ink-3);">Comisión fija del 6% para esta venta.</p>
                    </div>
                </label>
            </div>
        </div>

        <!-- Sección 1: Datos del Cliente -->
        <div class="rounded-2xl p-6 md:p-8" style="background:var(--card);border:1.5px solid var(--line);box-shadow:var(--shadow-card);">
            <div class="flex items-center gap-3 mb-6 pb-4" style="color:var(--accent);border-bottom:1.5px solid var(--line);">
                <i data-lucide="user"></i>
                <h2 class="font-bold text-lg tracking-tight uppercase" style="color:var(--ink);">DATOS DEL CLIENTE</h2>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <div class="contado-hide">
                    <label class="block text-xs font-bold uppercase mb-2 ml-1" style="color:var(--ink-3);">DNI</label>
                    <input type="text" name="client_dni" required data-required-credito placeholder="Sin puntos ni espacios" value="<?= old('client_dni', $sticky) ?>"
                           class="w-full input-light px-4 py-3 font-mono">
                </div>
                <div class="md:col-span-1 lg:col-span-2">
                    <label class="block text-xs font-bold uppercase mb-2 ml-1" style="color:var(--ink-3);">Apellido y Nombre</label>
                    <input type="text" name="client_name" required placeholder="Nombre completo" value="<?= old('client_name', $sticky) ?>"
                           class="w-full input-light px-4 py-3">
                </div>

                <div class="md:col-span-2 contado-hide">
                    <label class="block text-xs font-bold uppercase mb-2 ml-1" style="color:var(--ink-3);">Domicilio</label>
                    <input type="text" name="client_address" required data-required-credito placeholder="Calle y número" value="<?= old('client_address', $sticky) ?>"
                           class="w-full input-light px-4 py-3">
                </div>
                <div class="contado-hide">
                    <label class="block text-xs font-bold uppercase mb-2 ml-1" style="color:var(--ink-3);">Barrio</label>
                    <input type="text" name="client_neighborhood" required data-required-credito placeholder="Barrio" value="<?= old('client_neighborhood', $sticky) ?>"
                           class="w-full input-light px-4 py-3">
                </div>

                <div class="contado-hide">
                    <label class="block text-xs font-bold uppercase mb-2 ml-1" style="color:var(--ink-3);">Localidad</label>
                    <?php
                    $localidades = ['Acheral','Aguilares','Alderete','Banda del Río Salí','Bella Vista','Burruyacu','Catamarca','Concepción','Famaillá','Leales','Lules','Manantial','Monteros','Río Colorado','San Miguel de Tucumán','Simoca','Tafí del Valle','Tafí Viejo','Termas','Sgo','Villa Carmela','Yerba Buena'];
                    $localidadesLabels = ['Sgo' => 'Sgo del Estero'];
                    $cur_loc = $sticky['client_locality'] ?? '';
                    ?>
                    <select name="client_locality" id="client_locality" required data-required-credito
                            class="w-full input-light px-4 py-3">
                        <option value="" disabled <?= $cur_loc === '' ? 'selected' : '' ?>>Seleccionar localidad...</option>
                        <?php foreach ($localidades as $loc): ?>
                        <option value="<?= htmlspecialchars($loc) ?>" <?= $cur_loc === $loc ? 'selected' : '' ?>><?= htmlspecialchars($localidadesLabels[$loc] ?? $loc) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase mb-2 ml-1" style="color:var(--ink-3);">WhatsApp / Celular</label>
                    <input type="tel" name="client_whatsapp" required inputmode="numeric" oninput="this.value=this.value.replace(/\D/g,'').slice(0,15)" placeholder="Ej: 381..." value="<?= old('client_whatsapp', $sticky) ?>"
                           class="w-full input-light px-4 py-3 font-mono">
                </div>
                <div class="contado-hide">
                    <label class="block text-xs font-bold uppercase mb-2 ml-1" style="color:var(--ink-3);">Nro Llamada</label>
                    <input type="tel" name="client_phone" required data-required-credito inputmode="numeric" oninput="this.value=this.value.replace(/\D/g,'').slice(0,15)" placeholder="Alternativo" value="<?= old('client_phone', $sticky) ?>"
                           class="w-full input-light px-4 py-3 font-mono">
                </div>

                <div class="md:col-span-2 lg:col-span-3">
                    <label class="block text-xs font-bold uppercase mb-2 ml-1" style="color:var(--ink-3);">Ubicación Google Maps</label>
                    <input type="text" name="client_map_link" required placeholder="Pegue el enlace aquí (https://maps...)" value="<?= old('client_map_link', $sticky) ?>"
                           class="w-full input-light px-4 py-3">
                </div>
            </div>
        </div>

        <!-- Sección 2: Datos Laborales -->
        <div class="rounded-2xl p-6 md:p-8 contado-hide" style="background:var(--card);border:1.5px solid var(--line);box-shadow:var(--shadow-card);">
            <div class="flex items-center gap-3 mb-6 pb-4" style="color:var(--accent);border-bottom:1.5px solid var(--line);">
                <i data-lucide="briefcase"></i>
                <h2 class="font-bold text-lg tracking-tight uppercase" style="color:var(--ink);">DATOS LABORALES</h2>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label class="block text-xs font-bold uppercase mb-2 ml-1" style="color:var(--ink-3);">Tipo de Empleo</label>
                    <input type="text" name="job_type" placeholder="Ej: Rel. Dependencia, Monotributo" value="<?= old('job_type', $sticky) ?>"
                           class="w-full input-light px-4 py-3">
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase mb-2 ml-1" style="color:var(--ink-3);">Ocupación</label>
                    <input type="text" name="job_occupation" placeholder="Ej: Empleado de comercio" value="<?= old('job_occupation', $sticky) ?>"
                           class="w-full input-light px-4 py-3">
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase mb-2 ml-1" style="color:var(--ink-3);">Nombre del Trabajo</label>
                    <input type="text" name="job_name" placeholder="Nombre de la empresa o lugar" value="<?= old('job_name', $sticky) ?>"
                           class="w-full input-light px-4 py-3">
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase mb-2 ml-1" style="color:var(--ink-3);">Domicilio Laboral</label>
                    <input type="text" name="job_address" placeholder="Dirección del trabajo" value="<?= old('job_address', $sticky) ?>"
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
                    <input type="text" name="item" required placeholder="Producto vendido" value="<?= old('item', $sticky) ?>"
                           class="w-full input-light px-4 py-3">
                </div>
                <div class="contado-hide">
                    <label class="block text-xs font-bold uppercase mb-2 ml-1" style="color:var(--ink-3);">Día de Cobro</label>
                    <?php $cur_day = $sticky['payment_day'] ?? ''; ?>
                    <select name="payment_day" id="payment_day" required data-required-credito
                            class="w-full input-light px-4 py-3 cursor-pointer">
                        <option value="" disabled <?= $cur_day === '' ? 'selected' : '' ?>>Seleccionar día...</option>
                        <option value="Lunes" <?= $cur_day === 'Lunes' ? 'selected' : '' ?>>Lunes</option>
                        <option value="Martes" <?= $cur_day === 'Martes' ? 'selected' : '' ?>>Martes</option>
                        <option value="Miércoles" <?= $cur_day === 'Miércoles' ? 'selected' : '' ?>>Miércoles</option>
                        <option value="Sábado" id="opt-sabado" <?= $cur_day === 'Sábado' ? 'selected' : '' ?>>Sábado</option>
                    </select>
                </div>
                <div class="contado-hide">
                    <label class="block text-xs font-bold uppercase mb-2 ml-1" style="color:var(--ink-3);">Cuotas</label>
                    <input type="number" name="installments_count" id="installments_count" required data-required-credito placeholder="Cant." value="<?= old('installments_count', $sticky) ?>"
                           class="w-full input-light px-4 py-3" oninput="calculateTotal()">
                </div>
                <div>
                    <label id="label_installment_amount" class="block text-xs font-bold uppercase mb-2 ml-1" style="color:var(--ink-3);">Monto ($)</label>
                    <input type="number" name="installment_amount" id="installment_amount" required placeholder="Monto" value="<?= old('installment_amount', $sticky) ?>"
                           class="w-full input-light px-4 py-3" oninput="calculateTotal()">
                </div>
            </div>

            <!-- Fila frecuencia y adelanto -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6 contado-hide">
                <div>
                    <label class="block text-xs font-bold uppercase mb-2 ml-1" style="color:var(--ink-3);">Frecuencia de Pago</label>
                    <?php $cur_freq = $sticky['payment_frequency'] ?? 'semanal'; ?>
                    <select name="payment_frequency"
                            class="w-full input-light px-4 py-3 cursor-pointer">
                        <option value="semanal" <?= $cur_freq === 'semanal' ? 'selected' : '' ?>>Semanal</option>
                        <option value="quincenal" <?= $cur_freq === 'quincenal' ? 'selected' : '' ?>>Quincenal</option>
                        <option value="mensual" <?= $cur_freq === 'mensual' ? 'selected' : '' ?>>Mensual</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase mb-2 ml-1" style="color:var(--ink-3);">Adelanto ($)</label>
                    <input type="number" name="down_payment" value="<?= old('down_payment', $sticky, '0') ?>"
                           class="w-full input-light px-4 py-3">
                </div>
            </div>

            <!-- Bloque de Total -->
            <div class="bg-emerald-950/20 border border-emerald-500/20 rounded-xl p-4 flex justify-between items-center px-8 shadow-inner">
                <span class="text-xs uppercase text-emerald-500 font-bold tracking-widest">TOTAL CALCULADO</span>
                <div class="flex items-center gap-1 text-2xl font-bold text-emerald-400">
                    <span>$</span>
                    <input type="number" name="total_amount" id="total_amount" value="<?= old('total_amount', $sticky, '0') ?>" readonly
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
                          class="w-full input-light px-4 py-4 resize-none h-[145px]"><?= old('observations', $sticky) ?></textarea>
            </div>
        </div>

        <button type="submit" class="w-full text-white font-bold py-4 rounded-2xl flex items-center justify-center gap-3 transition active:scale-[0.98]" style="background:var(--accent);box-shadow:0 4px 20px rgba(99,102,241,.3);" onmouseover="this.style.background='#4f46e5'" onmouseout="this.style.background='var(--accent)'"  >
            <i data-lucide="send"></i> GUARDAR Y ENVIAR VENTA PARA REVISIÓN
        </button>

    </form>
</main>

<script>
    // Alternar entre venta a Crédito y de Contado: muestra/oculta secciones
    // y activa/desactiva los campos "required" correspondientes.
    function updateSaleTypeUI() {
        const isContado = document.getElementById('sale_type_contado').checked;

        document.querySelectorAll('.contado-hide').forEach(function(el) {
            el.style.display = isContado ? 'none' : '';
        });

        document.querySelectorAll('.contado-only').forEach(function(el) {
            el.style.display = isContado ? '' : 'none';
        });

        document.querySelectorAll('[data-required-credito]').forEach(function(el) {
            el.required = !isContado;
        });

        const labelMonto = document.getElementById('label_installment_amount');
        if (labelMonto) labelMonto.textContent = isContado ? 'Monto Total ($)' : 'Monto ($)';

        const creditoBox = document.getElementById('label_sale_type_credito');
        const contadoBox = document.getElementById('label_sale_type_contado');
        if (creditoBox && contadoBox) {
            creditoBox.style.borderColor = isContado ? 'var(--line)' : 'var(--accent)';
            creditoBox.style.background  = isContado ? '' : 'var(--accent-soft)';
            contadoBox.style.borderColor = isContado ? 'var(--accent)' : 'var(--line)';
            contadoBox.style.background  = isContado ? 'var(--accent-soft)' : '';
        }

        if (isContado) {
            document.getElementById('installments_count').value = 1;
            calculateTotal();
        }
    }

    // Resalta la tarjeta activa (Normal / RapiCompra), mismo criterio que updateSaleTypeUI()
    function updatePaymentMethodUI() {
        const isRapi = document.getElementById('payment_method_rapicompra').checked;
        const normalBox = document.getElementById('label_payment_method_normal');
        const rapiBox = document.getElementById('label_payment_method_rapicompra');
        if (normalBox && rapiBox) {
            normalBox.style.borderColor = isRapi ? 'var(--line)' : 'var(--accent)';
            normalBox.style.background  = isRapi ? '' : 'var(--accent-soft)';
            rapiBox.style.borderColor = isRapi ? 'var(--accent)' : 'var(--line)';
            rapiBox.style.background  = isRapi ? 'var(--accent-soft)' : '';
        }
    }
    document.addEventListener('DOMContentLoaded', function() {
        updateSaleTypeUI();
        updatePaymentMethodUI();
        document.getElementById('sale_type_credito').addEventListener('change', updateSaleTypeUI);
        document.getElementById('sale_type_contado').addEventListener('change', updateSaleTypeUI);
        document.getElementById('payment_method_normal').addEventListener('change', updatePaymentMethodUI);
        document.getElementById('payment_method_rapicompra').addEventListener('change', updatePaymentMethodUI);
    });

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

    // Control dinámico del día de cobro "Sábado" según la localidad
    document.addEventListener('DOMContentLoaded', function() {
        const localitySelect = document.getElementById('client_locality');
        const paymentDaySelect = document.getElementById('payment_day');
        const optSabado = document.getElementById('opt-sabado');
        
        function updatePaymentDays() {
            if (!localitySelect || !paymentDaySelect || !optSabado) return;
            
            const locality = localitySelect.value;
            if (locality === 'Tafí del Valle' || locality === 'Leales') {
                optSabado.disabled = false;
                optSabado.style.display = '';
            } else {
                optSabado.disabled = true;
                optSabado.style.display = 'none';
                if (paymentDaySelect.value === 'Sábado') {
                    paymentDaySelect.value = '';
                }
            }
        }
        
        if (localitySelect) {
            localitySelect.addEventListener('change', updatePaymentDays);
            updatePaymentDays();
        }
    });

    // Loading state al enviar el formulario
    document.getElementById('ventaForm').addEventListener('submit', function() {
        const btn = this.querySelector('[type=submit]');
        btn.disabled = true;
        btn.classList.add('opacity-75', 'cursor-not-allowed');
        btn.innerHTML = '<svg class="animate-spin w-5 h-5 mr-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path></svg> Guardando...';
    });
</script>

<?php include 'includes/footer.php'; ?>