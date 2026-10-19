<?php
require dirname(__DIR__, 2) . '/includes/db.php';

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

include dirname(__DIR__, 2) . '/includes/header.php';
?>

<main class="app-main">
<div class="app-container" style="max-width:1180px">

    <div style="display:flex;flex-direction:column;gap:4px" class="only-desktop">
        <a href="dashboard.php" class="navbar-back"><?= ui_icon('chevron-left') ?> Panel</a>
        <h1 class="large-title">Nueva venta</h1>
        <p class="subhead">Los campos con * son obligatorios. En contado solo pedimos nombre, WhatsApp, ubicación y artículo.</p>
    </div>

    <!-- Cabecera + progreso (celular) -->
    <header id="wizardHeader" class="only-mobile" style="display:none;flex-direction:column;gap:10px">
        <div style="display:flex;align-items:center;justify-content:space-between">
            <a href="dashboard.php" class="navbar-back" style="margin:0">Cancelar</a>
            <span class="tab caption" id="wizardStepCount">1 de 4</span>
        </div>
        <div class="steps-progress" id="wizardProgress" aria-hidden="true">
            <span></span><span></span><span></span><span></span>
        </div>
        <div>
            <h1 class="large-title" id="wizardTitle">Cliente</h1>
            <p class="subhead" id="wizardSub" style="margin:0">Datos de contacto de quien compra.</p>
        </div>
    </header>

    <!-- BLOQUE DE ERRORES -->
    <?php if (isset($_GET['error'])):
        $field_messages = [
            'dni'              => 'DNI es obligatorio.',
            'dni_formato'      => 'El DNI debe tener 7 u 8 dígitos numéricos.',
            'dni_rechazado'    => 'Este DNI fue rechazado por no ser potable y no registra una compra exitosa posterior. Comuníquese con el Administrador.',
            'whatsapp_rechazado' => 'Este número de WhatsApp/Celular fue rechazado por no ser potable y no registra una compra exitosa posterior. Comuníquese con el Administrador.',
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
    <div class="notice notice--error" style="align-items:flex-start">
        <?= ui_icon('alert-circle') ?>
        <div>
            <strong style="display:block">Atención: no se pudo guardar la venta</strong>
            <p style="margin:4px 0 0">
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
            <p class="caption" style="margin:4px 0 0">Los demás datos que había completado se mantuvieron. Si había adjuntado archivos, debe volver a seleccionarlos.</p>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="row" style="align-items:flex-start">
        <form action="save_sale.php" method="POST" enctype="multipart/form-data" id="ventaForm" class="col-main" style="display:flex;flex-direction:column;gap:20px">
            <?= csrf_field() ?>

            <!-- PASO 1 · Cliente (tipo de venta + datos de contacto) -->
            <section class="card card--pad" data-step="1" style="display:flex;flex-direction:column;gap:18px">
                <h2 class="headline" style="margin:0;display:flex;align-items:center;gap:10px">
                    <span class="tab" style="width:24px;height:24px;border-radius:4px;background:var(--bg-card-2);font-size:12px;font-weight:700;display:inline-flex;align-items:center;justify-content:center">1</span>
                    Tipo de venta
                </h2>
                <?php $cur_sale_type = ($sticky['sale_type'] ?? 'credito') === 'contado' ? 'contado' : 'credito'; ?>
                <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(220px,1fr));gap:12px">
                    <label id="label_sale_type_credito" class="choice">
                        <input type="radio" name="sale_type" value="credito" id="sale_type_credito" <?= $cur_sale_type === 'credito' ? 'checked' : '' ?>>
                        <span><span class="choice__title">A crédito</span><span class="choice__sub">Requiere todos los datos del cliente para evaluar el crédito.</span></span>
                    </label>
                    <label id="label_sale_type_contado" class="choice">
                        <input type="radio" name="sale_type" value="contado" id="sale_type_contado" <?= $cur_sale_type === 'contado' ? 'checked' : '' ?>>
                        <span><span class="choice__title">De contado</span><span class="choice__sub">Solo nombre, celular, artículo y ubicación.</span></span>
                    </label>
                </div>

                <?php $cur_payment_method = ($sticky['payment_method'] ?? 'normal') === 'rapicompra' ? 'rapicompra' : 'normal'; ?>
                <div id="paymentMethodBlock" class="contado-only" style="display:flex;flex-direction:column;gap:8px">
                    <span class="field__label">Método de pago</span>
                    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(220px,1fr));gap:12px">
                        <label id="label_payment_method_normal" class="choice">
                            <input type="radio" name="payment_method" value="normal" id="payment_method_normal" <?= $cur_payment_method === 'normal' ? 'checked' : '' ?>>
                            <span><span class="choice__title">Normal</span><span class="choice__sub">Comisión estándar del vendedor.</span></span>
                        </label>
                        <label id="label_payment_method_rapicompra" class="choice">
                            <input type="radio" name="payment_method" value="rapicompra" id="payment_method_rapicompra" <?= $cur_payment_method === 'rapicompra' ? 'checked' : '' ?>>
                            <span><span class="choice__title">RapiCompra</span><span class="choice__sub">Comisión fija del 6% para esta venta.</span></span>
                        </label>
                    </div>
                </div>

                <div class="form-grid">
                    <div class="field contado-hide">
                        <label class="field__label">DNI</label>
                        <input type="text" name="client_dni" required data-required-credito placeholder="Sin puntos ni espacios" value="<?= old('client_dni', $sticky) ?>" class="input tab">
                    </div>
                    <div class="field">
                        <label class="field__label">Apellido y nombre</label>
                        <input type="text" name="client_name" required placeholder="Como figura en el DNI" value="<?= old('client_name', $sticky) ?>" class="input">
                    </div>
                    <div class="field">
                        <label class="field__label">WhatsApp</label>
                        <input type="tel" name="client_whatsapp" required inputmode="numeric" oninput="this.value=this.value.replace(/\D/g,'').slice(0,15)" placeholder="Ej: 381..." value="<?= old('client_whatsapp', $sticky) ?>" class="input tab">
                    </div>
                    <div class="field contado-hide">
                        <label class="field__label">Teléfono alternativo</label>
                        <input type="tel" name="client_phone" required data-required-credito inputmode="numeric" oninput="this.value=this.value.replace(/\D/g,'').slice(0,15)" placeholder="Para llamar" value="<?= old('client_phone', $sticky) ?>" class="input tab">
                    </div>
                </div>
            </section>

            <!-- PASO 2 · Domicilio -->
            <section class="card card--pad" data-step="2" style="display:flex;flex-direction:column;gap:18px">
                <h2 class="headline" style="margin:0;display:flex;align-items:center;gap:10px">
                    <span class="tab" style="width:24px;height:24px;border-radius:4px;background:var(--bg-card-2);font-size:12px;font-weight:700;display:inline-flex;align-items:center;justify-content:center">2</span>
                    Domicilio
                </h2>
                <div class="form-grid">
                    <div class="field contado-hide">
                        <label class="field__label">Domicilio</label>
                        <input type="text" name="client_address" required data-required-credito placeholder="Calle y número" value="<?= old('client_address', $sticky) ?>" class="input">
                    </div>
                    <div class="field contado-hide">
                        <label class="field__label">Barrio</label>
                        <input type="text" name="client_neighborhood" required data-required-credito placeholder="Barrio" value="<?= old('client_neighborhood', $sticky) ?>" class="input">
                    </div>
                    <div class="field">
                        <label class="field__label">Localidad</label>
                        <?php
                        $localidades = ['Acheral','Aguilares','Alderete','Banda del Río Salí','Bella Vista','Burruyacu','Catamarca','Concepción','Cruz Alta','Famaillá','La Florida','Leales','Lules','Manantial','Monteros','Ranchillos','Río Colorado','San Miguel de Tucumán','Simoca','Tafí del Valle','Tafí Viejo','Termas','Sgo','Villa Carmela','Yerba Buena'];
                        $localidadesLabels = ['Sgo' => 'Sgo del Estero'];
                        $cur_loc = $sticky['client_locality'] ?? '';
                        ?>
                        <select name="client_locality" id="client_locality" required data-required-credito class="select">
                            <option value="" disabled <?= $cur_loc === '' ? 'selected' : '' ?>>Seleccionar localidad...</option>
                            <?php foreach ($localidades as $loc): ?>
                            <option value="<?= htmlspecialchars($loc) ?>" <?= $cur_loc === $loc ? 'selected' : '' ?>><?= htmlspecialchars($localidadesLabels[$loc] ?? $loc) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="field">
                    <label class="field__label">Ubicación Google Maps</label>
                    <button type="button" id="btnGeo" class="btn btn--block only-mobile" style="border-color:var(--tint);background:var(--tint-soft);color:var(--label)">
                        <?= ui_icon('locate-fixed', 'icon--tint') ?> Usar mi ubicación actual
                    </button>
                    <input type="text" name="client_map_link" id="client_map_link_input" required placeholder="Pegue el enlace aquí (https://maps...)" value="<?= old('client_map_link', $sticky) ?>" class="input">
                    <div id="geoNotice" class="notice notice--error" style="display:none"><?= ui_icon('alert-triangle') ?> <span>No pudimos acceder a tu ubicación. Pegá el enlace manualmente.</span></div>
                    <p class="caption only-mobile" style="margin:0">Si estás en la casa del cliente, la ubicación actual es la más precisa.</p>
                </div>
            </section>

            <!-- PASO 3 · Venta -->
            <section class="card card--pad" data-step="3" style="display:flex;flex-direction:column;gap:18px">
                <h2 class="headline" style="margin:0;display:flex;align-items:center;gap:10px">
                    <span class="tab" style="width:24px;height:24px;border-radius:4px;background:var(--bg-card-2);font-size:12px;font-weight:700;display:inline-flex;align-items:center;justify-content:center">3</span>
                    Detalle de la venta
                </h2>

                <div class="field">
                    <label class="field__label">Artículo</label>
                    <input type="text" name="item" required placeholder="Producto vendido" value="<?= old('item', $sticky) ?>" class="input">
                </div>

                <div class="form-grid">
                    <div class="field contado-hide">
                        <label class="field__label">Día de cobro</label>
                        <?php $cur_day = $sticky['payment_day'] ?? ''; ?>
                        <select name="payment_day" id="payment_day" required data-required-credito class="select only-desktop">
                            <option value="" disabled <?= $cur_day === '' ? 'selected' : '' ?>>Seleccionar día...</option>
                            <option value="Lunes" <?= $cur_day === 'Lunes' ? 'selected' : '' ?>>Lunes</option>
                            <option value="Martes" <?= $cur_day === 'Martes' ? 'selected' : '' ?>>Martes</option>
                            <option value="Miércoles" <?= $cur_day === 'Miércoles' ? 'selected' : '' ?>>Miércoles</option>
                            <option value="Sábado" id="opt-sabado" <?= $cur_day === 'Sábado' ? 'selected' : '' ?>>Sábado</option>
                        </select>
                        <div class="chips only-mobile" id="dayChips" data-error-id="dayChipsError" style="flex-wrap:wrap">
                            <button type="button" class="chip" data-value="Lunes">Lunes</button>
                            <button type="button" class="chip" data-value="Martes">Martes</button>
                            <button type="button" class="chip" data-value="Miércoles">Miércoles</button>
                            <button type="button" class="chip" data-value="Sábado" id="chip-sabado">Sábado</button>
                        </div>
                        <span class="field__error" id="dayChipsError" style="display:none">Elegí el día de cobro.</span>
                        <span class="caption only-mobile">Sábado solo para Tafí del Valle y Leales.</span>
                    </div>
                    <div class="field contado-hide">
                        <label class="field__label">Cuotas</label>
                        <input type="number" name="installments_count" id="installments_count" required data-required-credito placeholder="Cant." value="<?= old('installments_count', $sticky) ?>" class="input tab" oninput="calculateTotal()">
                    </div>
                    <div class="field">
                        <label id="label_installment_amount" class="field__label">Monto ($)</label>
                        <input type="number" name="installment_amount" id="installment_amount" required placeholder="Monto" value="<?= old('installment_amount', $sticky) ?>" class="input tab" oninput="calculateTotal()">
                    </div>
                </div>

                <div class="form-grid contado-hide">
                    <div class="field">
                        <label class="field__label">Frecuencia de pago</label>
                        <?php $cur_freq = $sticky['payment_frequency'] ?? 'semanal'; ?>
                        <select name="payment_frequency" id="payment_frequency" class="select only-desktop">
                            <option value="semanal" <?= $cur_freq === 'semanal' ? 'selected' : '' ?>>Semanal</option>
                            <option value="quincenal" <?= $cur_freq === 'quincenal' ? 'selected' : '' ?>>Quincenal</option>
                            <option value="mensual" <?= $cur_freq === 'mensual' ? 'selected' : '' ?>>Mensual</option>
                        </select>
                        <div class="segmented only-mobile" id="freqChips">
                            <button type="button" class="segmented__item" data-value="semanal">Semanal</button>
                            <button type="button" class="segmented__item" data-value="quincenal">Quincenal</button>
                            <button type="button" class="segmented__item" data-value="mensual">Mensual</button>
                        </div>
                    </div>
                    <div class="field">
                        <label class="field__label">Adelanto ($)</label>
                        <input type="number" name="down_payment" value="<?= old('down_payment', $sticky, '0') ?>" class="input tab">
                    </div>
                </div>

                <div class="card card--pad" style="background:var(--tint-soft);border-color:var(--tint);display:flex;justify-content:space-between;align-items:center">
                    <span class="eyebrow" style="color:var(--tint-text)">Total calculado</span>
                    <div style="display:flex;align-items:center;gap:2px" class="tab title2">
                        <span>$</span>
                        <input type="number" name="total_amount" id="total_amount" value="<?= old('total_amount', $sticky, '0') ?>" readonly
                               style="background:transparent;border:0;width:140px;text-align:right;font:inherit;font-weight:700;outline:none">
                    </div>
                </div>
            </section>

            <!-- PASO 4 · Fotos y cierre -->
            <section class="card card--pad contado-hide" data-step="4" style="display:flex;flex-direction:column;gap:18px">
                <h2 class="headline" style="margin:0;display:flex;align-items:center;gap:10px">
                    <span class="tab" style="width:24px;height:24px;border-radius:4px;background:var(--bg-card-2);font-size:12px;font-weight:700;display:inline-flex;align-items:center;justify-content:center">4</span>
                    Datos laborales <span class="caption" style="font-weight:400">Opcional</span>
                </h2>
                <div class="form-grid">
                    <div class="field">
                        <label class="field__label">Tipo de empleo</label>
                        <input type="text" name="job_type" placeholder="Ej: Rel. Dependencia, Monotributo" value="<?= old('job_type', $sticky) ?>" class="input">
                    </div>
                    <div class="field">
                        <label class="field__label">Ocupación</label>
                        <input type="text" name="job_occupation" placeholder="Ej: Empleado de comercio" value="<?= old('job_occupation', $sticky) ?>" class="input">
                    </div>
                    <div class="field">
                        <label class="field__label">Lugar de trabajo</label>
                        <input type="text" name="job_name" placeholder="Nombre de la empresa o lugar" value="<?= old('job_name', $sticky) ?>" class="input">
                    </div>
                    <div class="field">
                        <label class="field__label">Domicilio laboral</label>
                        <input type="text" name="job_address" placeholder="Dirección del trabajo" value="<?= old('job_address', $sticky) ?>" class="input">
                    </div>
                </div>
            </section>

            <!-- PASO 4 (cont.) · Documentación y observaciones (siempre, incluso contado) -->
            <section class="card card--pad" data-step="4" style="display:flex;flex-direction:column;gap:18px">
                <h2 class="headline only-desktop" style="margin:0;display:flex;align-items:center;gap:10px">
                    <?= ui_icon('file-text') ?> Documentación y observaciones
                </h2>

                <div class="dropzone only-mobile" style="padding:0;border:0;background:none;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px" onclick="event.stopPropagation()">
                    <label class="btn btn--primary" style="height:72px;flex-direction:column;gap:4px">
                        <?= ui_icon('camera') ?> Sacar foto
                        <input type="file" name="sale_files[]" accept="image/*" capture="environment" class="sr-only" onchange="previewFiles(this)">
                    </label>
                    <label class="btn" style="height:72px;flex-direction:column;gap:4px">
                        <?= ui_icon('image') ?> Elegir de la galería
                        <input type="file" name="sale_files[]" multiple accept="image/*,.pdf" class="sr-only" onchange="previewFiles(this)">
                    </label>
                </div>

                <div class="dropzone only-desktop" onclick="document.getElementById('sale_files_desktop').click()">
                    <input type="hidden" name="MAX_FILE_SIZE" value="5242880">
                    <input type="file" name="sale_files[]" id="sale_files_desktop" multiple accept="image/*,.pdf" class="sr-only" onchange="previewFiles(this)">
                    <?= ui_icon('upload-cloud') ?>
                    <span class="headline">Click para subir documentos</span>
                    <span class="caption">DNI, servicios, recibos de sueldo</span>
                </div>
                <div id="file-list" style="display:flex;flex-wrap:wrap;gap:8px;justify-content:center"></div>

                <div class="field">
                    <label class="field__label">Observaciones</label>
                    <textarea name="observations" rows="4" placeholder="Horarios de entrega, aclaraciones del crédito, etc." class="textarea"><?= old('observations', $sticky) ?></textarea>
                </div>
            </section>
        </form>

        <!-- Resumen -->
        <aside class="col-side only-desktop" style="display:flex;flex-direction:column;gap:16px">
            <section class="group__body">
                <div style="padding:18px 20px;display:flex;flex-direction:column;gap:2px">
                    <span class="eyebrow" id="resumenTitulo">Resumen · crédito</span>
                    <span class="tab title2" id="resumenTotal">$0</span>
                    <span class="subhead">Valor total final</span>
                </div>
                <div class="kv contado-hide"><span class="kv__label">Plan</span><span class="tab" id="resumenPlan" style="font-weight:600">0 × $0</span></div>
                <div class="kv contado-hide"><span class="kv__label">Adelanto</span><span class="tab" id="resumenAdelanto">$0</span></div>
                <div style="padding:16px 20px;border-top:1px solid var(--separator);display:flex;flex-direction:column;gap:10px">
                    <button type="submit" form="ventaForm" class="btn btn--primary btn--block"><?= ui_icon('check') ?> Guardar venta</button>
                    <a href="dashboard.php" class="btn btn--block">Cancelar</a>
                </div>
            </section>

            <section class="card card--pad">
                <h2 class="headline" style="margin:0 0 10px">Antes de guardar</h2>
                <ul id="checklist" style="margin:0;padding:0;list-style:none;display:flex;flex-direction:column;gap:10px;font-size:13px"></ul>
            </section>
        </aside>
    </div>

    <!-- Barra de acciones del asistente (celular) -->
    <div class="action-bar only-mobile" id="wizardActionBar">
        <button type="button" id="btnAtras" class="btn">Atrás</button>
        <button type="button" id="btnSiguiente" class="btn btn--primary"><?= ui_icon('chevron-right') ?> Siguiente</button>
        <button type="submit" form="ventaForm" id="btnGuardar" class="btn btn--primary" style="display:none"><?= ui_icon('check') ?> Guardar venta</button>
    </div>

</div>
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
            creditoBox.classList.toggle('is-checked', !isContado);
            contadoBox.classList.toggle('is-checked', isContado);
        }

        if (isContado) {
            document.getElementById('installments_count').value = 1;
        }
        calculateTotal(); // ya refresca el resumen y el checklist
        if (window.__wizard) window.__wizard.refreshSteps();
    }

    function updatePaymentMethodUI() {
        updateChecklist();
    }
    document.addEventListener('DOMContentLoaded', function() {
        updateSaleTypeUI();
        updatePaymentMethodUI();
        document.getElementById('sale_type_credito').addEventListener('change', updateSaleTypeUI);
        document.getElementById('sale_type_contado').addEventListener('change', updateSaleTypeUI);
        document.getElementById('payment_method_normal').addEventListener('change', updatePaymentMethodUI);
        document.getElementById('payment_method_rapicompra').addEventListener('change', updatePaymentMethodUI);
    });

    // Cálculo del total en tiempo real (Cuotas * Monto, o precio fijo en contado) + resumen lateral
    function calculateTotal() {
        const isContado = document.getElementById('sale_type_contado').checked;
        const installments = parseFloat(document.getElementById('installments_count').value) || 0;
        const amount = parseFloat(document.getElementById('installment_amount').value) || 0;
        const total = isContado ? amount : installments * amount;
        document.getElementById('total_amount').value = Math.round(total);

        const fmt = (n) => '$' + Math.round(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        document.getElementById('resumenTitulo').textContent = 'Resumen · ' + (isContado ? 'contado' : 'crédito');
        document.getElementById('resumenTotal').textContent = fmt(total);
        document.getElementById('resumenPlan').textContent = installments + ' × ' + fmt(amount);
        const down = parseFloat(document.querySelector('[name=down_payment]').value) || 0;
        document.getElementById('resumenAdelanto').textContent = fmt(down);
        updateChecklist();
    }
    document.querySelector('[name=down_payment]').addEventListener('input', calculateTotal);

    // Previsualización de archivos seleccionados (acumula entre los distintos inputs de carga)
    let _selectedFiles = [];
    function previewFiles(input) {
        const list = document.getElementById('file-list');
        if (input.files) {
            Array.from(input.files).forEach(file => _selectedFiles.push(file.name));
        }
        list.innerHTML = '';
        _selectedFiles.forEach(name => {
            const span = document.createElement('span');
            span.className = 'tag';
            span.textContent = name;
            list.appendChild(span);
        });
        updateChecklist();
    }

    // Control dinámico del día de cobro "Sábado" según la localidad (select de PC y chip de celular)
    document.addEventListener('DOMContentLoaded', function() {
        const localitySelect = document.getElementById('client_locality');
        const paymentDaySelect = document.getElementById('payment_day');
        const optSabado = document.getElementById('opt-sabado');
        const chipSabado = document.getElementById('chip-sabado');

        function updatePaymentDays() {
            if (!localitySelect || !paymentDaySelect) return;
            const locality = localitySelect.value;
            const habilitado = (locality === 'Tafí del Valle' || locality === 'Leales');

            if (optSabado) {
                optSabado.disabled = !habilitado;
                optSabado.style.display = habilitado ? '' : 'none';
            }
            if (chipSabado) chipSabado.disabled = !habilitado;
            if (!habilitado && paymentDaySelect.value === 'Sábado') {
                paymentDaySelect.value = '';
                paymentDaySelect.dispatchEvent(new Event('change'));
            }
        }

        if (localitySelect) {
            localitySelect.addEventListener('change', updatePaymentDays);
            updatePaymentDays();
        }
    });

    // Chips de celular que reflejan y actualizan un <select> real oculto en ese ancho
    function wireChipSelect(selectId, containerId, activeClass) {
        const select = document.getElementById(selectId);
        const container = document.getElementById(containerId);
        if (!select || !container) return;
        const chips = Array.from(container.querySelectorAll('[data-value]'));
        function sync() {
            chips.forEach(c => c.classList.toggle(activeClass, c.dataset.value === select.value));
        }
        chips.forEach(chip => {
            chip.addEventListener('click', function() {
                if (chip.disabled) return;
                select.value = chip.dataset.value;
                select.dispatchEvent(new Event('change'));
                sync();
                if (container.dataset.errorId) document.getElementById(container.dataset.errorId).style.display = 'none';
            });
        });
        select.addEventListener('change', sync);
        sync();
    }
    document.addEventListener('DOMContentLoaded', function() {
        wireChipSelect('payment_frequency', 'freqChips', 'is-active');
        wireChipSelect('payment_day', 'dayChips', 'is-active');
    });

    // "Usar mi ubicación actual" → completa el enlace de Google Maps
    document.getElementById('btnGeo').addEventListener('click', function() {
        const notice = document.getElementById('geoNotice');
        const input  = document.getElementById('client_map_link_input');
        if (notice) notice.style.display = 'none';
        if (!navigator.geolocation) {
            if (notice) notice.style.display = 'flex';
            return;
        }
        navigator.geolocation.getCurrentPosition(function(pos) {
            input.value = 'https://www.google.com/maps?q=' + pos.coords.latitude + ',' + pos.coords.longitude;
            updateChecklist();
        }, function() {
            if (notice) notice.style.display = 'flex';
        });
    });

    // "Antes de guardar": checklist en vivo en el resumen lateral (PC)
    function updateChecklist() {
        const list = document.getElementById('checklist');
        if (!list) return;
        const isContado = document.getElementById('sale_type_contado').checked;

        function reqFieldsOk() {
            const names = isContado
                ? ['client_name', 'client_whatsapp', 'client_map_link', 'item']
                : ['client_dni', 'client_name', 'client_address', 'client_neighborhood', 'client_locality', 'client_whatsapp', 'client_phone', 'client_map_link', 'item', 'payment_day', 'installments_count', 'installment_amount'];
            return names.every(n => {
                const el = document.querySelector('[name=' + n + ']');
                return el && el.value.trim() !== '';
            });
        }

        const items = [
            { ok: true, label: 'Tipo y método de pago elegidos' },
            { ok: reqFieldsOk(), label: reqFieldsOk() ? 'Datos obligatorios completos' : 'Faltan datos obligatorios del cliente' },
            { ok: _selectedFiles.length > 0, label: _selectedFiles.length > 0 ? _selectedFiles.length + ' archivo(s) adjuntado(s)' : 'Sin fotos del DNI todavía' },
        ];
        list.innerHTML = items.map(it => (
            '<li style="display:flex;gap:10px;align-items:flex-start">' +
            '<span style="color:' + (it.ok ? 'var(--green)' : 'var(--orange)') + ';flex-shrink:0;margin-top:1px">' +
            (it.ok
                ? '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6 9 17l-5-5"/></svg>'
                : '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16.5 12"/></svg>') +
            '</span><span>' + it.label + '</span></li>'
        )).join('');
    }
    document.addEventListener('DOMContentLoaded', updateChecklist);
    document.addEventListener('input', function(e) {
        if (e.target.closest('#ventaForm')) updateChecklist();
    });

    // Loading state al enviar el formulario
    document.getElementById('ventaForm').addEventListener('submit', function() {
        document.querySelectorAll('[form=ventaForm][type=submit], #ventaForm [type=submit]').forEach(function(btn) {
            btn.disabled = true;
            btn.style.opacity = '.7';
        });
    });

    // ───────────────────────────────────────────────────────────
    // Asistente de 4 pasos (solo celular, mejora progresiva: si JS
    // no corre o el ancho es de PC, se ve el formulario completo).
    // ───────────────────────────────────────────────────────────
    (function () {
        const steps = Array.from(document.querySelectorAll('[data-step]'));
        const titles = {
            1: ['Cliente', 'Datos de contacto de quien compra.'],
            2: ['Domicilio', 'Dónde vive y cómo llegar.'],
            3: ['Venta', 'Artículo y plan de pago.'],
            4: ['Fotos y cierre', 'DNI, recibo y datos opcionales.'],
        };
        let current = 1;
        let active = false;

        // Autoridad final sobre el display de cada [data-step]: oculta los pasos
        // que no son el actual; en el paso actual, si la sección es la de
        // "Datos laborales" (.contado-hide), respeta el tipo de venta elegido.
        function applyVisibility() {
            if (!active) return;
            const isContado = document.getElementById('sale_type_contado').checked;
            steps.forEach(s => {
                const isCurrent = Number(s.dataset.step) === current;
                if (!isCurrent) { s.style.display = 'none'; return; }
                s.style.display = (isContado && s.classList.contains('contado-hide')) ? 'none' : '';
            });
        }

        function showStep(n) {
            current = n;
            applyVisibility();
            const [title, sub] = titles[n];
            document.getElementById('wizardTitle').textContent = title;
            document.getElementById('wizardSub').textContent = sub;
            document.getElementById('wizardStepCount').textContent = n + ' de 4';
            document.querySelectorAll('#wizardProgress span').forEach((el, i) => {
                el.classList.toggle('is-done', i < n);
            });
            document.getElementById('btnAtras').disabled = (n === 1);
            document.getElementById('btnSiguiente').style.display = (n === 4) ? 'none' : '';
            document.getElementById('btnGuardar').style.display = (n === 4) ? '' : 'none';
            window.scrollTo(0, 0);
        }

        function validateStep(n) {
            let ok = true;
            steps.filter(s => Number(s.dataset.step) === n).forEach(section => {
                section.querySelectorAll('input, select, textarea').forEach(el => {
                    if (el.offsetParent === null) {
                        // Un <select> oculto en celular que se elige con chips (día de cobro) se valida igual:
                        // si no, el navegador bloquea "Guardar venta" sin poder mostrar ningún aviso.
                        const field = el.closest('.field');
                        const chips = field && field.offsetParent !== null ? field.querySelector('[data-error-id]') : null;
                        if (chips && !el.checkValidity()) {
                            document.getElementById(chips.dataset.errorId).style.display = '';
                            chips.scrollIntoView({ block: 'center' });
                            ok = false;
                        }
                        return;
                    }
                    if (!el.reportValidity()) ok = false;
                });
            });
            return ok;
        }

        function activateWizard() {
            if (active) return;
            active = true;
            document.getElementById('wizardHeader').style.display = 'flex';
            showStep(current);
        }
        function deactivateWizard() {
            if (!active) return;
            active = false;
            document.getElementById('wizardHeader').style.display = 'none';
            steps.forEach(s => { s.style.display = ''; });
            updateSaleTypeUI(); // restaura el display correcto de .contado-hide en la vista completa
        }

        document.getElementById('btnAtras').addEventListener('click', function () {
            if (current > 1) showStep(current - 1);
        });
        document.getElementById('btnSiguiente').addEventListener('click', function () {
            if (!validateStep(current)) return;
            if (current < 4) showStep(current + 1);
        });

        const mq = window.matchMedia('(max-width: 1023px)');
        function onMqChange() { mq.matches ? activateWizard() : deactivateWizard(); }
        mq.addEventListener ? mq.addEventListener('change', onMqChange) : mq.addListener(onMqChange);
        onMqChange();

        window.__wizard = { refreshSteps: function () { if (active) applyVisibility(); } };
    })();
</script>

<?php include dirname(__DIR__, 2) . '/includes/footer.php'; ?>
