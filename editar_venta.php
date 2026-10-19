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

// Las ventas de contado se cargaron con datos mínimos: estos campos no son obligatorios al editarlas
$is_contado = ($order['sale_type'] ?? 'credito') === 'contado';
$req_credito = $is_contado ? '' : 'required';

// Obtener archivos adjuntos con sus IDs (para botón eliminar)
$stmtFiles = $pdo->prepare("SELECT id, file_path FROM sale_files WHERE sale_id = ?");
$stmtFiles->execute([$sale_id]);
$files = $stmtFiles->fetchAll(PDO::FETCH_ASSOC);

include 'includes/header.php';
?>

<main class="app-main">
<div class="app-container" style="max-width:1180px">

    <div style="display:flex;flex-direction:column;gap:4px">
        <a href="ver_ficha.php?id=<?= $sale_id ?>" class="navbar-back"><?= ui_icon('chevron-left') ?> Volver a la ficha</a>
        <h1 class="large-title">Editar venta <span class="tab" style="color:var(--tint-text)">#<?= $sale_id ?></span></h1>
        <p class="subhead">Modifique los datos y guarde los cambios. Solo disponible mientras la venta esté en revisión.</p>
    </div>

    <div class="notice" style="background:var(--orange-soft);color:var(--orange-text)">
        <?= ui_icon('clock') ?> <span>Esta venta está <strong>en revisión</strong>. Una vez aprobada o rechazada no podrá editarla.</span>
    </div>

    <?php if (isset($_GET['msg']) && $_GET['msg'] === 'file_deleted'): ?>
    <div class="notice notice--ok"><?= ui_icon('trash-2') ?> <span>Archivo eliminado correctamente.</span></div>
    <?php endif; ?>

    <?php if (isset($_GET['msg']) && $_GET['msg'] === 'error'):
        $field_messages = [
            'telefono'         => 'El número de llamada (teléfono alternativo) es obligatorio.',
            'maps'             => 'La ubicación de Google Maps es obligatoria (debe ser un enlace válido).',
            'whatsapp_formato' => 'El WhatsApp debe contener solo dígitos (7 a 15 caracteres).',
            'telefono_formato' => 'El teléfono alternativo tiene un formato inválido.',
        ];
        $fields = array_filter(explode(',', $_GET['fields'] ?? ''));
        $specific_msgs = array_filter(array_map(fn($f) => $field_messages[$f] ?? null, $fields));
    ?>
    <div class="notice notice--error"><?= ui_icon('alert-octagon') ?> <span><?= !empty($specific_msgs) ? implode(' ', $specific_msgs) : 'Ocurrió un error al guardar los cambios. Intente nuevamente.' ?></span></div>
    <?php endif; ?>

    <form action="update_sale_full.php" method="POST" enctype="multipart/form-data" style="display:flex;flex-direction:column;gap:20px">
        <?= csrf_field() ?>
        <input type="hidden" name="sale_id" value="<?= $sale_id ?>">

        <!-- Datos del cliente -->
        <section class="card card--pad" style="display:flex;flex-direction:column;gap:18px">
            <h2 class="headline" style="margin:0;display:flex;align-items:center;gap:10px"><?= ui_icon('user', 'icon--tint') ?> Datos del cliente</h2>
            <div class="form-grid">
                <div class="field">
                    <label class="field__label">DNI <?= $is_contado ? '' : '*' ?></label>
                    <input type="text" name="client_dni" <?= $req_credito ?> value="<?= htmlspecialchars($order['client_dni'] ?? '') ?>" class="input tab">
                </div>
                <div class="field">
                    <label class="field__label">Apellido y nombre *</label>
                    <input type="text" name="client_name" required value="<?= htmlspecialchars($order['client_name'] ?? '') ?>" class="input">
                </div>
                <div class="field">
                    <label class="field__label">WhatsApp *</label>
                    <input type="tel" name="client_whatsapp" required inputmode="numeric" oninput="this.value=this.value.replace(/\D/g,'').slice(0,15)" value="<?= htmlspecialchars($order['client_whatsapp'] ?? '') ?>" class="input tab">
                </div>
                <div class="field">
                    <label class="field__label">Nro llamada <?= $is_contado ? '' : '*' ?></label>
                    <input type="tel" name="client_phone" <?= $req_credito ?> inputmode="numeric" oninput="this.value=this.value.replace(/\D/g,'').slice(0,15)" value="<?= htmlspecialchars($order['client_phone'] ?? '') ?>" class="input tab">
                </div>
                <div class="field">
                    <label class="field__label">Domicilio <?= $is_contado ? '' : '*' ?></label>
                    <input type="text" name="client_address" <?= $req_credito ?> value="<?= htmlspecialchars($order['client_address'] ?? '') ?>" class="input">
                </div>
                <div class="field">
                    <label class="field__label">Barrio <?= $is_contado ? '' : '*' ?></label>
                    <input type="text" name="client_neighborhood" <?= $req_credito ?> value="<?= htmlspecialchars($order['client_neighborhood'] ?? '') ?>" class="input">
                </div>
                <div class="field">
                    <label class="field__label">Localidad <?= $is_contado ? '' : '*' ?></label>
                    <?php
                    $localidades = ['Acheral','Aguilares','Alderete','Banda del Río Salí','Bella Vista','Burruyacu','Concepción','Cruz Alta','Famaillá','La Florida','Leales','Lules','Manantial','Monteros','Ranchillos','Río Colorado','San Miguel de Tucumán','Simoca','Tafí del Valle','Tafí Viejo','Termas','Sgo','Villa Carmela','Yerba Buena'];
                    $localidadesLabels = ['Sgo' => 'Sgo del Estero'];
                    $current_locality = $order['client_locality'] ?? '';
                    ?>
                    <select name="client_locality" id="client_locality" <?= $req_credito ?> class="select">
                        <option value="" disabled <?= $current_locality === '' ? 'selected' : '' ?>>Seleccionar localidad...</option>
                        <?php foreach ($localidades as $loc): ?>
                        <option value="<?= htmlspecialchars($loc) ?>" <?= $current_locality === $loc ? 'selected' : '' ?>><?= htmlspecialchars($localidadesLabels[$loc] ?? $loc) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="field">
                <label class="field__label">Ubicación Google Maps *</label>
                <input type="text" name="client_map_link" required value="<?= htmlspecialchars($order['client_map_link'] ?? '') ?>" placeholder="Pegue el enlace aquí (https://maps...)" class="input">
            </div>
        </section>

        <!-- Datos laborales -->
        <section class="card card--pad" style="display:flex;flex-direction:column;gap:18px">
            <h2 class="headline" style="margin:0;display:flex;align-items:center;gap:10px"><?= ui_icon('briefcase', 'icon--tint') ?> Datos laborales</h2>
            <div class="form-grid">
                <div class="field">
                    <label class="field__label">Tipo de empleo</label>
                    <input type="text" name="job_type" value="<?= htmlspecialchars($order['job_type'] ?? '') ?>" placeholder="Ej: Rel. Dependencia, Monotributo" class="input">
                </div>
                <div class="field">
                    <label class="field__label">Ocupación</label>
                    <input type="text" name="job_occupation" value="<?= htmlspecialchars($order['job_occupation'] ?? '') ?>" placeholder="Ej: Empleado de comercio" class="input">
                </div>
                <div class="field">
                    <label class="field__label">Lugar de trabajo</label>
                    <input type="text" name="job_name" value="<?= htmlspecialchars($order['job_name'] ?? '') ?>" placeholder="Nombre de la empresa o lugar" class="input">
                </div>
                <div class="field">
                    <label class="field__label">Domicilio laboral</label>
                    <input type="text" name="job_address" value="<?= htmlspecialchars($order['job_address'] ?? '') ?>" placeholder="Dirección del trabajo" class="input">
                </div>
            </div>
        </section>

        <!-- Detalle de la venta -->
        <section class="card card--pad" style="display:flex;flex-direction:column;gap:18px">
            <h2 class="headline" style="margin:0;display:flex;align-items:center;gap:10px"><?= ui_icon('dollar-sign', 'icon--tint') ?> Detalle de la venta</h2>

            <div class="form-grid">
                <div class="field">
                    <label class="field__label">Artículo *</label>
                    <input type="text" name="item" required value="<?= htmlspecialchars($order['item'] ?? '') ?>" placeholder="Producto vendido" class="input">
                </div>
                <?php if ($is_contado): ?>
                <div class="field">
                    <label class="field__label">Método de pago</label>
                    <select name="payment_method" class="select">
                        <option value="normal" <?= (($order['payment_method'] ?? 'normal') === 'normal') ? 'selected' : '' ?>>Normal</option>
                        <option value="rapicompra" <?= (($order['payment_method'] ?? 'normal') === 'rapicompra') ? 'selected' : '' ?>>RapiCompra (comisión 6%)</option>
                    </select>
                </div>
                <?php endif; ?>
                <div class="field">
                    <label class="field__label">Día de cobro *</label>
                    <select name="payment_day" id="payment_day" required class="select">
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
                <div class="field">
                    <label class="field__label">Frecuencia *</label>
                    <select name="payment_frequency" required class="select">
                        <?php foreach (['semanal' => 'Semanal', 'quincenal' => 'Quincenal', 'mensual' => 'Mensual'] as $val => $label): ?>
                            <option value="<?= $val ?>" <?= ($order['payment_frequency'] ?? 'semanal') === $val ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-grid">
                <div class="field">
                    <label class="field__label">Cuotas *</label>
                    <input type="number" name="installments" id="edit_cuotas" required min="1" value="<?= (int)($order['installments_count'] ?? 0) ?>" class="input tab" oninput="recalcTotal()">
                </div>
                <div class="field">
                    <label class="field__label">Monto por cuota ($) *</label>
                    <input type="number" name="amount" id="edit_monto" required min="0" value="<?= (float)($order['installment_amount'] ?? 0) ?>" class="input tab" oninput="recalcTotal()">
                </div>
                <div class="field">
                    <label class="field__label">Adelanto ($)</label>
                    <input type="number" name="down_payment" min="0" step="0.01" value="<?= (float)($order['down_payment'] ?? 0) ?>" class="input tab">
                </div>
            </div>

            <div class="card card--pad" style="background:var(--tint-soft);border-color:var(--tint);display:flex;justify-content:space-between;align-items:center">
                <span class="eyebrow" style="color:var(--tint-text)">Total calculado</span>
                <div class="tab title2" id="edit_total_display">$<?= number_format((float)($order['total_amount'] ?? 0), 0, ',', '.') ?></div>
            </div>
        </section>

        <!-- Documentación y observaciones -->
        <div class="row" style="align-items:flex-start">
            <section class="card card--pad col-main" style="display:flex;flex-direction:column;gap:18px">
                <h2 class="headline" style="margin:0;display:flex;align-items:center;gap:10px"><?= ui_icon('files', 'icon--tint') ?> Documentación adjunta</h2>

                <?php if (!empty($files)): ?>
                <div style="display:flex;flex-wrap:wrap;gap:8px" id="files-container">
                    <?php foreach ($files as $f): $isPdf = str_ends_with(strtolower($f['file_path']), '.pdf'); ?>
                    <div class="tag" style="height:auto;padding:6px 8px;gap:6px" id="chip-<?= $f['id'] ?>">
                        <?= ui_icon($isPdf ? 'file-text' : 'image') ?>
                        <a href="uploads/<?= htmlspecialchars($f['file_path']) ?>" target="_blank" style="max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                            <?= htmlspecialchars($f['file_path']) ?>
                        </a>
                        <button type="button" onclick="deleteFileFromEdit(<?= $f['id'] ?>, <?= $sale_id ?>)" aria-label="Eliminar" style="background:none;border:0;cursor:pointer;color:inherit;display:flex">
                            <?= ui_icon('x') ?>
                        </button>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <p class="caption">No hay archivos adjuntos aún.</p>
                <?php endif; ?>

                <div class="dropzone" onclick="document.getElementById('sale_files').click()">
                    <input type="hidden" name="MAX_FILE_SIZE" value="5242880">
                    <input type="file" name="sale_files[]" id="sale_files" multiple accept="image/*,.pdf" class="sr-only" onchange="previewNewFiles(this)">
                    <?= ui_icon('upload-cloud') ?>
                    <span class="headline">Click para agregar documentos</span>
                    <span class="caption">JPG, PNG, PDF · máx. 5 MB por archivo</span>
                </div>
                <div id="new-file-list" style="display:flex;flex-wrap:wrap;gap:8px;justify-content:center"></div>
            </section>

            <section class="card card--pad col-side">
                <h2 class="headline" style="margin:0 0 10px;display:flex;align-items:center;gap:10px"><?= ui_icon('message-square', 'icon--orange') ?> Observaciones</h2>
                <textarea name="observations" rows="6" placeholder="Horarios de entrega, aclaraciones del crédito, etc." class="textarea"><?= htmlspecialchars($order['observations'] ?? '') ?></textarea>
            </section>
        </div>

        <!-- Botones -->
        <div style="display:flex;gap:12px;flex-wrap:wrap">
            <a href="ver_ficha.php?id=<?= $sale_id ?>" class="btn" style="flex:1">Cancelar</a>
            <button type="submit" class="btn btn--primary" style="flex:1"><?= ui_icon('save') ?> Guardar cambios</button>
        </div>
    </form>
</div>
</main>

<script>
    // Token CSRF para operaciones via fetch
    const _csrfToken = '<?= htmlspecialchars(csrf_token()) ?>';

    // Recalcular total en tiempo real
    function recalcTotal() {
        const cuotas = parseFloat(document.getElementById('edit_cuotas').value) || 0;
        const monto  = parseFloat(document.getElementById('edit_monto').value)  || 0;
        const total  = Math.round(cuotas * monto);
        document.getElementById('edit_total_display').textContent = '$' + total.toLocaleString('es-AR');
    }

    // Previsualizar archivos nuevos seleccionados
    function previewNewFiles(input) {
        const list = document.getElementById('new-file-list');
        list.innerHTML = '';
        if (input.files) {
            Array.from(input.files).forEach(file => {
                const span = document.createElement('span');
                span.className = 'tag';
                span.textContent = file.name;
                list.appendChild(span);
            });
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
