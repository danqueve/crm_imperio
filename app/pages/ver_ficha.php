<?php
require dirname(__DIR__, 2) . '/includes/db.php';

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

// Historial de intentos de contacto durante la verificación (guardado en audit_log, no en tabla propia)
$stmtContacts = $pdo->prepare(
    "SELECT al.*, u.name as user_name
     FROM audit_log al JOIN users u ON al.user_id = u.id
     WHERE al.target_type = 'sale' AND al.target_id = ? AND al.action = 'contact_log'
     ORDER BY al.created_at DESC"
);
$stmtContacts->execute([$id]);
$contact_log = $stmtContacts->fetchAll(PDO::FETCH_ASSOC);

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

include dirname(__DIR__, 2) . '/includes/header.php';

// --- Derivados de presentación (no tocan datos ni permisos) ---
$tipoLabel = (($order['sale_type'] ?? 'credito') === 'contado')
    ? 'Contado'
    : ('Crédito ' . mb_strtolower($order['payment_frequency'] ?? 'Semanal'));
$wa_link = normalize_ar_whatsapp($order['client_whatsapp'] ?? '');
?>

<main class="app-main">
<div class="app-container" style="max-width:1180px">

    <!-- Mensajes Flash -->
    <?php if (isset($_GET['msg']) && $_GET['msg'] == 'updated'): ?>
    <div class="notice notice--ok"><?= ui_icon('check-circle') ?> <span>Ficha actualizada correctamente.</span></div>
    <?php elseif (isset($_GET['msg']) && $_GET['msg'] == 'file_deleted'): ?>
    <div class="notice notice--ok"><?= ui_icon('trash-2') ?> <span>Archivo eliminado correctamente.</span></div>
    <?php elseif (isset($_GET['msg']) && $_GET['msg'] == 'error_delete'): ?>
    <div class="notice notice--error"><?= ui_icon('alert-triangle') ?> <span>No se pudo eliminar el archivo.</span></div>
    <?php elseif (isset($_GET['msg']) && $_GET['msg'] == 'error'):
        $field_messages = [
            'telefono'         => 'El número de llamada (teléfono alternativo) es obligatorio.',
            'maps'             => 'La ubicación de Google Maps es obligatoria (debe ser un enlace válido).',
            'whatsapp_formato' => 'El WhatsApp debe contener solo dígitos (7 a 15 caracteres).',
            'telefono_formato' => 'El teléfono alternativo tiene un formato inválido.',
            'tipo_rechazo'     => 'No se pudo reclasificar el rechazo: tipo inválido o la venta no está rechazada.',
            'explicacion_requerida' => 'Para dejar el rechazo como "No es potable" tiene que haber una explicación cargada.',
            'contacto_invalido' => 'No se pudo registrar el contacto: resultado inválido o la venta ya no está en revisión.',
            'nota_requerida'   => 'Para registrar el contacto como "Otro" tenés que escribir una nota.',
        ];
        $fields = array_filter(explode(',', $_GET['fields'] ?? ''));
        $specific_msgs = array_filter(array_map(fn($f) => $field_messages[$f] ?? null, $fields));
    ?>
    <div class="notice notice--error">
        <?= ui_icon('alert-octagon') ?> <span><?= !empty($specific_msgs) ? implode(' ', $specific_msgs) : 'Ocurrió un error al guardar los cambios. Intente nuevamente.' ?></span>
    </div>
    <?php endif; ?>

    <!-- Volver + meta -->
    <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:8px">
        <a href="javascript:history.back()" class="navbar-back"><?= ui_icon('chevron-left') ?> Volver</a>
        <span class="tab caption">
            Venta #<?= $order['id'] ?> · cargada por <?= htmlspecialchars($order['seller_name'] ?? 'Desconocido') ?> · <?= date('d/m/Y H:i', strtotime($order['created_at'])) ?>
        </span>
        <?php if ($can_manage): ?>
        <button type="button" onclick="openEditModal()" class="btn btn--icon btn--sm only-mobile" aria-label="Editar ficha"><?= ui_icon('pencil') ?></button>
        <?php elseif ($role === 'vendedor' && $order['user_id'] == $_SESSION['user_id'] && $order['status'] === 'revision'): ?>
        <a href="editar_venta.php?id=<?= $order['id'] ?>" class="btn btn--icon btn--sm only-mobile" aria-label="Editar mi venta"><?= ui_icon('pencil') ?></a>
        <?php endif; ?>
    </div>

    <!-- Cabecera de la ficha -->
    <section class="card card--pad" style="display:flex;flex-direction:column;gap:20px">
        <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:16px">
            <div style="display:flex;align-items:center;gap:16px;min-width:0">
                <?= ui_avatar($order['client_name'], 'lg') ?>
                <div style="display:flex;flex-direction:column;gap:4px">
                    <div style="display:flex;flex-wrap:wrap;align-items:center;gap:10px">
                        <h1 class="title2"><?= htmlspecialchars($order['client_name'] ?? '-') ?></h1>
                        <?= status_badge($order['status']) ?>
                        <?php if (($order['payment_method'] ?? 'normal') === 'rapicompra'): ?>
                        <span class="tag tag--indigo">RapiCompra</span>
                        <?php endif; ?>
                    </div>
                    <span class="tab subhead">DNI <?= htmlspecialchars($order['client_dni'] ?? '-') ?> · <?= htmlspecialchars($order['client_locality'] ?? '-') ?> · <?= htmlspecialchars($tipoLabel) ?></span>
                </div>
            </div>
            <div class="only-desktop" style="display:flex;flex-wrap:wrap;gap:8px">
                <?php if ($can_manage && $order['status'] === 'revision'): ?>
                <a href="#contactoForm" class="btn"><?= ui_icon('phone', 'icon--orange') ?> Registrar contacto</a>
                <a href="rechazar_venta.php?id=<?= $order['id'] ?>" class="btn"><?= ui_icon('x-circle', 'icon--red') ?> Rechazar</a>
                <form method="POST" action="update_status.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $order['id'] ?>">
                    <input type="hidden" name="status" value="aprobado">
                    <button type="submit" class="btn btn--primary"><?= ui_icon('check') ?> Aprobar crédito</button>
                </form>
                <?php endif; ?>
                <?php if ($can_manage): ?>
                <button type="button" onclick="openEditModal()" class="btn"><?= ui_icon('pencil') ?> Editar ficha</button>
                <?php elseif ($role === 'vendedor' && $order['user_id'] == $_SESSION['user_id'] && $order['status'] === 'revision'): ?>
                <a href="editar_venta.php?id=<?= $order['id'] ?>" class="btn btn--primary"><?= ui_icon('pencil') ?> Editar mi venta</a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Acciones rápidas (celular) -->
        <?php if ($order['status'] === 'revision' || $wa_link || !empty($order['client_map_link'])): ?>
        <div class="only-mobile" style="display:grid;grid-template-columns:repeat(3, minmax(0,1fr));gap:10px">
            <?php if ($wa_link): ?>
            <a href="https://wa.me/<?= $wa_link ?>" target="_blank" class="card" style="display:flex;flex-direction:column;align-items:center;gap:6px;padding:12px 4px;text-decoration:none;color:var(--label);font-size:13px;font-weight:600">
                <span class="icon-box icon-box--green"><?= ui_icon('message-circle') ?></span> WhatsApp
            </a>
            <?php endif; ?>
            <?php if ($can_manage && $order['status'] === 'revision'): ?>
            <a href="#contactoForm" class="card" style="display:flex;flex-direction:column;align-items:center;gap:6px;padding:12px 4px;text-decoration:none;color:var(--label);font-size:13px;font-weight:600">
                <span class="icon-box icon-box--orange"><?= ui_icon('phone') ?></span> Registrar
            </a>
            <?php endif; ?>
            <?php if (!empty($order['client_map_link'])): ?>
            <a href="<?= htmlspecialchars($order['client_map_link']) ?>" target="_blank" class="card" style="display:flex;flex-direction:column;align-items:center;gap:6px;padding:12px 4px;text-decoration:none;color:var(--label);font-size:13px;font-weight:600">
                <span class="icon-box icon-box--tint"><?= ui_icon('map-pin') ?></span> Mapa
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Ciclo de vida -->
        <?php
        $pipelineSteps = [
            ['key' => 'revision',  'label' => 'Verificación',    'icon' => 'clock',       'detail_done' => 'Cargada'],
            ['key' => 'aprobado',  'label' => 'Aprobada',        'icon' => 'check',       'detail_done' => null],
            ['key' => 'entregado', 'label' => 'Entregada',       'icon' => 'truck',       'detail_done' => null],
        ];
        $currentStatus  = $order['status'];
        $currentStepIdx = array_search($currentStatus, array_column($pipelineSteps, 'key'));
        $isRejected     = ($currentStatus === 'rechazado');
        ?>
        <ol class="lifecycle" aria-label="Estado de la venta" style="padding-top:16px;border-top:1px solid var(--separator)">
            <li class="lifecycle__step is-done">
                <span class="lifecycle__dot"><?= ui_icon('check') ?></span>
                <span class="lifecycle__name">Cargada</span>
                <span class="lifecycle__detail caption"><?= date('d/m/Y H:i', strtotime($order['created_at'])) ?></span>
            </li>
            <?php foreach ($pipelineSteps as $i => $step):
                $isDone     = !$isRejected && $currentStepIdx !== false && $i < $currentStepIdx;
                $isCurrent  = !$isRejected && $currentStepIdx !== false && $i === $currentStepIdx;
                $isRejStep  = $isRejected && $i === 0; // el rechazo ocurre siempre durante la verificación
            ?>
            <li class="lifecycle__step <?= $isDone ? 'is-done' : ($isCurrent ? 'is-current' : ($isRejStep ? 'is-rejected' : '')) ?>">
                <span class="lifecycle__dot"><?= $isDone ? ui_icon('check') : ($isRejStep ? ui_icon('x') : '') ?></span>
                <span class="lifecycle__name"><?= $isRejStep ? 'Rechazada' : htmlspecialchars($step['label']) ?></span>
                <span class="lifecycle__detail caption">
                    <?php if ($isRejStep): ?>
                        <?= htmlspecialchars(rejection_type_label($order['rejected_type'] ?? null)) ?>
                    <?php elseif ($step['key'] === 'revision' && !empty($order['assigned_verifier_name'])): ?>
                        <?= htmlspecialchars($order['assigned_verifier_name']) ?><?= count($contact_log) > 0 ? ' · ' . count($contact_log) . (count($contact_log) === 1 ? ' intento' : ' intentos') : '' ?>
                    <?php elseif ($step['key'] === 'aprobado' && !empty($order['approved_at'])): ?>
                        <?= date('d/m/Y H:i', strtotime($order['approved_at'])) ?>
                    <?php elseif ($step['key'] === 'entregado' && !empty($order['delivered_at'])): ?>
                        <?= date('d/m/Y H:i', strtotime($order['delivered_at'])) ?>
                    <?php elseif (!$isDone && !$isCurrent): ?>
                        Pendiente
                    <?php endif; ?>
                </span>
            </li>
            <?php endforeach; ?>
        </ol>
    </section>

    <!-- BLOQUE DE VERIFICACIÓN ASIGNADA -->
    <?php if ($can_manage && $order['status'] === 'revision'): ?>
    <div class="card card--pad" style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:16px">
        <div style="display:flex;align-items:start;gap:12px">
            <span class="icon-box icon-box--tint"><?= ui_icon('user-check') ?></span>
            <div>
                <h4 class="eyebrow" style="margin:0 0 2px">Verificación</h4>
                <p class="subhead" style="margin:0">
                    <?php if (!empty($order['assigned_verifier_name'])): ?>
                        Asignada a <strong style="color:var(--label)"><?= htmlspecialchars($order['assigned_verifier_name']) ?></strong>
                        <?php if (!empty($order['assigned_at'])): ?>
                        <span class="tab caption">· <?= date('d/m/Y H:i', strtotime($order['assigned_at'])) ?></span>
                        <?php endif; ?>
                    <?php else: ?>
                        Sin verificador asignado
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <?php if ($can_assign): ?>
        <form method="POST" action="asignar_verificador.php" style="display:flex;align-items:center;gap:8px">
            <?= csrf_field() ?>
            <input type="hidden" name="sale_id" value="<?= $order['id'] ?>">
            <select name="verifier_id" class="select" style="height:40px;min-width:160px">
                <option value="">Sin asignar</option>
                <?php foreach ($verifiers as $v): ?>
                <option value="<?= $v['id'] ?>" <?= (int)($order['assigned_verifier_id'] ?? 0) === (int)$v['id'] ? 'selected' : '' ?>><?= htmlspecialchars($v['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn--primary btn--sm">Asignar</button>
        </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- BLOQUE DE AUDITORÍA: APROBACIÓN -->
    <?php if (in_array($order['status'], ['aprobado', 'entregado']) && !empty($order['approved_at'])): ?>
    <div class="card card--pad" style="display:flex;align-items:start;gap:12px">
        <span class="icon-box icon-box--green"><?= ui_icon('shield-check') ?></span>
        <div>
            <h4 class="eyebrow" style="margin:0 0 2px">Crédito aprobado</h4>
            <p class="subhead" style="margin:0">Verificado por <strong style="color:var(--label)"><?= htmlspecialchars($order['approved_by_name'] ?? 'Sistema') ?></strong></p>
            <p class="caption" style="margin:2px 0 0"><?= date('d/m/Y H:i', strtotime($order['approved_at'])) ?> hs.</p>
        </div>
    </div>
    <?php endif; ?>

    <!-- BLOQUE DE AUDITORÍA: RECHAZO -->
    <?php if ($order['status'] === 'rechazado'): ?>
    <div class="card card--pad" style="display:flex;align-items:start;gap:12px">
        <span class="icon-box icon-box--red"><?= ui_icon('alert-triangle') ?></span>
        <div style="flex:1;min-width:0">
            <h4 class="eyebrow" style="margin:0 0 2px">Operación rechazada</h4>
            <?php $rt = $order['rejected_type'] ?? null; ?>
            <p style="margin:0 0 4px;font-weight:700;display:flex;flex-wrap:wrap;align-items:center;gap:6px">
                <?= htmlspecialchars(rejection_type_label($rt)) ?>
                <?php if (rejection_type_blocks($rt)): ?>
                <span class="tag" style="background:var(--red-soft);color:var(--red-hover)">Queda en lista negra</span>
                <?php else: ?>
                <span class="tag" style="background:var(--tint-soft);color:var(--tint-text)">Se puede volver a cargar</span>
                <?php endif; ?>
            </p>
            <p class="subhead" style="margin:0;font-style:italic">"<?= htmlspecialchars($order['rejected_reason'] ?? 'Sin motivo especificado') ?>"</p>

            <?php if ($can_assign): // admin y supervisor pueden reclasificar ?>
            <form method="POST" action="reclasificar_rechazo.php" style="margin-top:10px;display:flex;flex-wrap:wrap;align-items:center;gap:8px">
                <?= csrf_field() ?>
                <input type="hidden" name="sale_id" value="<?= (int)$order['id'] ?>">
                <label class="caption" style="font-weight:700">Reclasificar:</label>
                <select name="reject_type" class="select" style="height:36px;width:auto">
                    <?php foreach (REJECTION_TYPES_SELECTABLE as $t): ?>
                    <option value="<?= $t ?>" <?= $rt === $t ? 'selected' : '' ?>><?= htmlspecialchars(rejection_type_label($t)) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn--danger btn--sm">Guardar</button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="row" style="align-items:flex-start">
        <!-- Columna principal -->
        <div class="col-main" style="display:flex;flex-direction:column;gap:20px">

            <section class="group">
                <h2 class="group__title">Datos del cliente</h2>
                <div class="group__body">
                    <div class="kv">
                        <span class="kv__label">WhatsApp</span>
                        <span style="display:flex;align-items:center;gap:10px">
                            <span class="tab" style="font-weight:600"><?= htmlspecialchars($order['client_whatsapp'] ?? '-') ?></span>
                            <?php if ($wa_link): ?>
                            <a href="https://wa.me/<?= $wa_link ?>" target="_blank" aria-label="Abrir chat de WhatsApp" class="icon-box icon-box--green"><?= ui_icon('message-circle') ?></a>
                            <?php else: ?>
                            <span class="icon-box icon-box--gray" title="No se pudo generar un enlace de WhatsApp válido"><?= ui_icon('message-circle-off') ?></span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="kv">
                        <span class="kv__label">Teléfono alternativo</span>
                        <span class="tab"><?= htmlspecialchars($order['client_phone'] ?? '-') ?></span>
                    </div>
                    <div class="kv">
                        <span class="kv__label">Domicilio</span>
                        <span><?= htmlspecialchars($order['client_address'] ?? '-') ?></span>
                    </div>
                    <div class="kv">
                        <span class="kv__label">Barrio · localidad</span>
                        <span><?= htmlspecialchars($order['client_neighborhood'] ?? 'Sin especificar') ?> · <?= htmlspecialchars($order['client_locality'] ?? '-') ?></span>
                    </div>
                    <?php if (!empty($order['client_map_link'])): ?>
                    <a href="<?= htmlspecialchars($order['client_map_link']) ?>" target="_blank" class="cell">
                        <?= ui_icon('map-pin', 'icon--tint') ?>
                        <span class="cell__body"><span class="cell__title" style="font-weight:600">Ver ubicación en Google Maps</span></span>
                        <span class="cell__chevron"><?= ui_icon('chevron-right') ?></span>
                    </a>
                    <?php endif; ?>
                </div>
            </section>

            <section class="group">
                <h2 class="group__title">Datos laborales</h2>
                <div class="group__body">
                    <div class="kv">
                        <span class="kv__label">Empresa</span>
                        <span><?= htmlspecialchars($order['job_name'] ?? '-') ?></span>
                    </div>
                    <div class="kv">
                        <span class="kv__label">Ocupación · tipo de empleo</span>
                        <span><?= htmlspecialchars($order['job_occupation'] ?? '-') ?> · <?= htmlspecialchars($order['job_type'] ?? '-') ?></span>
                    </div>
                    <div class="kv">
                        <span class="kv__label">Domicilio laboral</span>
                        <span><?= htmlspecialchars($order['job_address'] ?? '-') ?></span>
                    </div>
                </div>
            </section>

            <!-- DOCUMENTACIÓN ADJUNTA -->
            <?php if (!empty($files)): ?>
            <section style="display:flex;flex-direction:column;gap:8px">
                <h2 class="group__title" style="padding:0 4px">Documentación · <?= count($files) ?></h2>
                <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(140px, 1fr));gap:12px">
                    <?php foreach ($files as $file): $isPdf = strpos(strtolower($file['file_path'] ?? ''), '.pdf') !== false; ?>
                    <a href="uploads/<?= htmlspecialchars($file['file_path'] ?? '') ?>" target="_blank" class="card" style="overflow:hidden;text-decoration:none;color:var(--label)">
                        <?php if ($isPdf): ?>
                        <div style="height:90px;background:var(--bg-card-2);display:flex;align-items:center;justify-content:center;color:var(--label-2)"><?= ui_icon('file-text') ?></div>
                        <?php else: ?>
                        <img src="uploads/<?= htmlspecialchars($file['file_path'] ?? '') ?>" alt="" style="height:90px;width:100%;object-fit:cover;background:var(--bg-card-2)"
                             onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                        <div style="display:none;height:90px;background:var(--bg-card-2);align-items:center;justify-content:center;color:var(--label-2)"><?= ui_icon('image') ?></div>
                        <?php endif; ?>
                        <div class="caption" style="padding:8px 10px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($file['file_path'] ?? '') ?></div>
                    </a>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php endif; ?>
        </div>

        <!-- Columna lateral -->
        <aside class="col-side" style="display:flex;flex-direction:column;gap:20px">

            <section class="group__body">
                <div style="padding:16px 20px 14px;display:flex;flex-direction:column;gap:2px">
                    <span class="eyebrow">Plan de pago</span>
                    <span class="tab title2"><?= ui_money($order['total_amount'] ?? 0) ?></span>
                    <span class="subhead"><?= htmlspecialchars($order['item'] ?? '-') ?></span>
                </div>
                <div class="kv"><span class="kv__label">Adelanto</span><span class="tab"><?= ui_money($order['down_payment'] ?? 0) ?></span></div>
                <div class="kv"><span class="kv__label">Cuotas</span><span class="tab" style="font-weight:600"><?= (int)($order['installments_count'] ?? 0) ?> × <?= ui_money($order['installment_amount'] ?? 0) ?></span></div>
                <div class="kv"><span class="kv__label">Frecuencia · día de cobro</span><span><?= htmlspecialchars($order['payment_frequency'] ?? 'Semanal') ?> · <?= htmlspecialchars($order['payment_day'] ?? '-') ?></span></div>
                <div class="kv"><span class="kv__label">Método</span><span><?= ($order['payment_method'] ?? 'normal') === 'rapicompra' ? 'RapiCompra' : 'Normal' ?></span></div>
            </section>

            <!-- HISTORIAL DE CONTACTO -->
            <?php if (!empty($contact_log) || ($can_manage && $order['status'] === 'revision')): ?>
            <section class="card card--pad">
                <h2 class="headline" style="margin:0 0 12px">Historial de contacto</h2>

                <?php if (!empty($contact_log)): ?>
                <ol style="margin:0 0 14px;padding:0;list-style:none;display:flex;flex-direction:column;gap:12px">
                    <?php foreach ($contact_log as $c): $cdata = json_decode($c['details'] ?? '{}', true) ?: []; ?>
                    <li style="display:flex;gap:10px">
                        <span class="icon-box icon-box--orange"><?= ui_icon('phone') ?></span>
                        <div>
                            <p style="margin:0;font-weight:600"><?= htmlspecialchars(contact_result_label($cdata['result'] ?? null)) ?></p>
                            <?php if (!empty($cdata['note'])): ?>
                            <p class="subhead" style="margin:2px 0 0;font-style:italic">"<?= htmlspecialchars($cdata['note']) ?>"</p>
                            <?php endif; ?>
                            <p class="tab caption" style="margin:2px 0 0"><?= htmlspecialchars($c['user_name']) ?> · <?= date('d/m/Y H:i', strtotime($c['created_at'])) ?> hs.</p>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ol>
                <?php endif; ?>

                <?php if ($can_manage && $order['status'] === 'revision'): ?>
                <form method="POST" action="registrar_contacto.php" id="contactoForm" style="display:flex;flex-direction:column;gap:8px;padding-top:<?= empty($contact_log) ? '0' : '14px' ?>;<?= empty($contact_log) ? '' : 'border-top:1px solid var(--separator)' ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="sale_id" value="<?= (int)$order['id'] ?>">
                    <select name="contact_result" id="contact_result" class="select">
                        <?php foreach (CONTACT_RESULT_TYPES as $key => $label): ?>
                        <option value="<?= $key ?>"><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <textarea name="note" id="contact_note" rows="2" placeholder="Nota (opcional)" class="textarea"></textarea>
                    <button type="submit" class="btn btn--primary btn--block">Registrar contacto</button>
                </form>
                <script>
                    (function () {
                        const select = document.getElementById('contact_result');
                        const note   = document.getElementById('contact_note');
                        function updateHint() {
                            const requiere = select.value === 'otro';
                            note.required = requiere;
                            note.placeholder = requiere ? 'Nota (obligatoria para "Otro")' : 'Nota (opcional)';
                        }
                        select.addEventListener('change', updateHint);
                        updateHint();
                    })();
                </script>
                <?php endif; ?>
            </section>
            <?php endif; ?>

            <!-- OBSERVACIONES -->
            <?php if (!empty($order['observations'])): ?>
            <section class="card card--pad">
                <h2 class="headline" style="margin:0 0 8px">Observaciones del vendedor</h2>
                <p class="subhead" style="margin:0;white-space:pre-wrap;line-height:1.5"><?= htmlspecialchars($order['observations']) ?></p>
            </section>
            <?php endif; ?>
        </aside>
    </div>

    <!-- ACCIONES DE GESTIÓN (celular, fija abajo) -->
    <?php if ($can_manage && $order['status'] === 'revision'): ?>
    <div class="action-bar only-mobile">
        <a href="rechazar_venta.php?id=<?= $order['id'] ?>" class="btn btn--danger"><?= ui_icon('x-circle') ?> Rechazar</a>
        <form method="POST" action="update_status.php">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $order['id'] ?>">
            <input type="hidden" name="status" value="aprobado">
            <button type="submit" class="btn btn--primary btn--block"><?= ui_icon('check') ?> Aprobar crédito</button>
        </form>
    </div>
    <?php endif; ?>

</div>
</main>

<!-- SHEET DE EDICIÓN TOTAL -->
<?php if ($can_manage): ?>
<div id="editModal" class="sheet-backdrop" style="display:none">
    <div class="sheet">
        <span class="sheet__grabber"></span>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
            <h3 class="title2" style="display:flex;align-items:center;gap:8px"><?= ui_icon('settings-2', 'icon--tint') ?> Edición integral de la ficha</h3>
            <button type="button" onclick="closeEditModal()" class="btn btn--icon btn--sm" aria-label="Cerrar"><?= ui_icon('x') ?></button>
        </div>

        <form action="update_sale_full.php" method="POST" enctype="multipart/form-data" style="display:flex;flex-direction:column;gap:20px">
            <?= csrf_field() ?>
            <input type="hidden" name="sale_id" value="<?= $order['id'] ?>">

            <div class="form-grid">
                <!-- Columna 1: Cliente -->
                <div style="display:flex;flex-direction:column;gap:12px">
                    <h4 class="eyebrow" style="color:var(--tint-text)">Datos del cliente</h4>
                    <div class="field">
                        <label class="field__label">Nombre completo</label>
                        <input type="text" name="client_name" value="<?= htmlspecialchars($order['client_name'] ?? '') ?>" class="input">
                    </div>
                    <div class="row">
                        <div class="field" style="flex:1 1 140px">
                            <label class="field__label">DNI</label>
                            <input type="text" name="client_dni" value="<?= htmlspecialchars($order['client_dni'] ?? '') ?>" class="input tab">
                        </div>
                        <div class="field" style="flex:1 1 140px">
                            <label class="field__label">WhatsApp</label>
                            <input type="tel" name="client_whatsapp" inputmode="numeric" oninput="this.value=this.value.replace(/\D/g,'').slice(0,15)" value="<?= htmlspecialchars($order['client_whatsapp'] ?? '') ?>" class="input tab">
                        </div>
                    </div>
                    <div class="field">
                        <label class="field__label">Nro llamada</label>
                        <input type="tel" name="client_phone" <?= ($order['sale_type'] ?? 'credito') === 'contado' ? '' : 'required' ?> inputmode="numeric" oninput="this.value=this.value.replace(/\D/g,'').slice(0,15)" value="<?= htmlspecialchars($order['client_phone'] ?? '') ?>" class="input tab">
                    </div>
                    <div class="field">
                        <label class="field__label">Domicilio</label>
                        <input type="text" name="client_address" value="<?= htmlspecialchars($order['client_address'] ?? '') ?>" class="input">
                    </div>
                    <div class="row">
                        <div class="field" style="flex:1 1 140px">
                            <label class="field__label">Barrio</label>
                            <input type="text" name="client_neighborhood" value="<?= htmlspecialchars($order['client_neighborhood'] ?? '') ?>" class="input">
                        </div>
                        <div class="field" style="flex:1 1 140px">
                            <label class="field__label">Localidad</label>
                            <?php
                            $localidades = ['Acheral','Aguilares','Alderete','Banda del Río Salí','Bella Vista','Burruyacu','Catamarca','Concepción','Cruz Alta','Famaillá','La Florida','Leales','Lules','Manantial','Monteros','Ranchillos','Río Colorado','San Miguel de Tucumán','Simoca','Tafí del Valle','Tafí Viejo','Termas','Sgo','Villa Carmela','Yerba Buena'];
                            $localidadesLabels = ['Sgo' => 'Sgo del Estero'];
                            $cur_loc = $order['client_locality'] ?? '';
                            // Si la localidad guardada no está en la lista (dato viejo o ya no vigente), se agrega
                            // igual como opción: si no, no quedaba ninguna "selected" y al guardar sin tocar este
                            // campo se perdía la localidad real (el navegador manda la primera opción de la lista).
                            if ($cur_loc !== '' && !in_array($cur_loc, $localidades, true)) {
                                $localidades[] = $cur_loc;
                            }
                            ?>
                            <select name="client_locality" id="client_locality" class="select">
                                <option value="" disabled <?= $cur_loc === '' ? 'selected' : '' ?>>Seleccionar localidad...</option>
                                <?php foreach ($localidades as $loc): ?>
                                <option value="<?= htmlspecialchars($loc) ?>" <?= $cur_loc === $loc ? 'selected' : '' ?>><?= htmlspecialchars($localidadesLabels[$loc] ?? $loc) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="field">
                        <label class="field__label" style="color:var(--green-hover)">Enlace Google Maps</label>
                        <input type="text" name="client_map_link" required value="<?= htmlspecialchars($order['client_map_link'] ?? '') ?>" placeholder="Pegá aquí el enlace de ubicación" class="input">
                    </div>
                </div>

                <!-- Columna 2: Laboral y Venta -->
                <div style="display:flex;flex-direction:column;gap:20px">
                    <div style="display:flex;flex-direction:column;gap:12px">
                        <h4 class="eyebrow" style="color:var(--tint-text)">Datos laborales</h4>
                        <div class="row">
                            <div class="field" style="flex:1 1 140px">
                                <label class="field__label">Tipo empleo</label>
                                <input type="text" name="job_type" value="<?= htmlspecialchars($order['job_type'] ?? '') ?>" class="input">
                            </div>
                            <div class="field" style="flex:1 1 140px">
                                <label class="field__label">Ocupación</label>
                                <input type="text" name="job_occupation" value="<?= htmlspecialchars($order['job_occupation'] ?? '') ?>" class="input">
                            </div>
                        </div>
                        <div class="field">
                            <label class="field__label">Empresa</label>
                            <input type="text" name="job_name" value="<?= htmlspecialchars($order['job_name'] ?? '') ?>" class="input">
                        </div>
                        <div class="field">
                            <label class="field__label">Dirección laboral</label>
                            <input type="text" name="job_address" value="<?= htmlspecialchars($order['job_address'] ?? '') ?>" class="input">
                        </div>
                    </div>

                    <div style="display:flex;flex-direction:column;gap:12px">
                        <h4 class="eyebrow" style="color:var(--green-hover)">Plan de pago</h4>
                        <div class="field">
                            <label class="field__label">Artículo</label>
                            <input type="text" name="item" value="<?= htmlspecialchars($order['item'] ?? '') ?>" class="input" required>
                        </div>
                        <?php if (($order['sale_type'] ?? 'credito') === 'contado'): ?>
                        <div class="field">
                            <label class="field__label">Método de pago</label>
                            <select name="payment_method" class="select">
                                <option value="normal" <?= (($order['payment_method'] ?? 'normal') === 'normal') ? 'selected' : '' ?>>Normal</option>
                                <option value="rapicompra" <?= (($order['payment_method'] ?? 'normal') === 'rapicompra') ? 'selected' : '' ?>>RapiCompra (comisión 6%)</option>
                            </select>
                        </div>
                        <?php endif; ?>
                        <div class="row">
                            <div class="field" style="flex:1 1 140px">
                                <label class="field__label">Frecuencia</label>
                                <select name="payment_frequency" class="select">
                                    <option value="semanal" <?= (($order['payment_frequency'] ?? 'semanal') == 'semanal') ? 'selected' : '' ?>>Semanal</option>
                                    <option value="quincenal" <?= (($order['payment_frequency'] ?? '') == 'quincenal') ? 'selected' : '' ?>>Quincenal</option>
                                    <option value="mensual" <?= (($order['payment_frequency'] ?? '') == 'mensual') ? 'selected' : '' ?>>Mensual</option>
                                </select>
                            </div>
                            <div class="field" style="flex:1 1 140px">
                                <label class="field__label">Día cobro</label>
                                <select name="payment_day" id="payment_day" class="select">
                                    <?php
                                    $dias_permitidos = ['Lunes', 'Martes', 'Miércoles', 'Sábado'];
                                    $current_day = $order['payment_day'] ?? '';
                                    $options_to_render = $dias_permitidos;
                                    if (!empty($current_day) && !in_array($current_day, $dias_permitidos)) {
                                        $options_to_render[] = $current_day;
                                    }
                                    ?>
                                    <!-- Sin esta opción vacía, si no había día cargado (ej. venta de contado) el navegador
                                         dejaba el <select> en el primer valor de la lista y se guardaba "Lunes" solo. -->
                                    <option value="" <?= $current_day === '' ? 'selected' : '' ?>>Sin definir</option>
                                    <?php foreach ($options_to_render as $d):
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
                        </div>
                        <div class="row">
                            <div class="field" style="flex:1 1 140px">
                                <label class="field__label">Cant. cuotas</label>
                                <input type="number" id="edit_cuotas" name="installments" value="<?= $order['installments_count'] ?? 0 ?>" class="input" oninput="recalculateTotal()" required>
                            </div>
                            <div class="field" style="flex:1 1 140px">
                                <label class="field__label">Valor cuota ($)</label>
                                <input type="number" id="edit_monto" name="amount" value="<?= $order['installment_amount'] ?? 0 ?>" class="input" oninput="recalculateTotal()" required>
                            </div>
                        </div>
                        <div class="field">
                            <label class="field__label">Adelanto ($)</label>
                            <input type="number" name="down_payment" value="<?= htmlspecialchars($order['down_payment'] ?? 0) ?>" min="0" step="0.01" class="input">
                        </div>
                    </div>
                </div>
            </div>

            <div class="field">
                <label class="field__label" style="color:var(--orange-hover)">Observaciones</label>
                <textarea name="observations" rows="3" placeholder="Notas internas de la venta..." class="textarea"><?= htmlspecialchars($order['observations'] ?? '') ?></textarea>
            </div>

            <div class="card card--pad" style="display:flex;justify-content:space-between;align-items:center;background:var(--tint-soft);border-color:var(--tint)">
                <span class="eyebrow" style="color:var(--tint-text)">Nuevo total calculado</span>
                <div id="edit_total" class="tab title2">$<?= number_format($order['total_amount'] ?? 0, 0, ',', '.') ?></div>
            </div>

            <!-- DOCUMENTACIÓN ADJUNTA (EDICIÓN) -->
            <div style="display:flex;flex-direction:column;gap:12px">
                <h4 class="eyebrow" style="color:var(--indigo)">Documentación adjunta</h4>

                <?php
                $stmtFilesEdit = $pdo->prepare("SELECT sf.id, sf.file_path FROM sale_files sf WHERE sf.sale_id = ?");
                $stmtFilesEdit->execute([$order['id']]);
                $filesWithIds = $stmtFilesEdit->fetchAll(PDO::FETCH_ASSOC);
                ?>

                <?php if (!empty($filesWithIds)): ?>
                <div style="display:flex;flex-wrap:wrap;gap:8px">
                    <?php foreach ($filesWithIds as $ef): $isPdf = strpos(strtolower($ef['file_path']), '.pdf') !== false; ?>
                    <div class="tag" style="height:auto;padding:6px 8px;gap:6px" id="file-chip-<?= $ef['id'] ?>">
                        <?= ui_icon($isPdf ? 'file-text' : 'image') ?>
                        <a href="uploads/<?= htmlspecialchars($ef['file_path']) ?>" target="_blank" style="max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                            <?= htmlspecialchars($ef['file_path']) ?>
                        </a>
                        <?php if (in_array($role, ['admin', 'supervisor']) || ($role === 'vendedor' && $order['status'] === 'revision')): ?>
                        <button type="button" onclick="deleteFile(<?= $ef['id'] ?>, <?= $order['id'] ?>)" aria-label="Eliminar" style="background:none;border:0;cursor:pointer;color:inherit;display:flex">
                            <?= ui_icon('trash-2') ?>
                        </button>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <div class="dropzone" onclick="document.getElementById('edit_sale_files').click()">
                    <input type="file" name="sale_files[]" id="edit_sale_files" multiple accept="image/*,.pdf" class="sr-only" onchange="previewEditFiles(this)">
                    <div id="edit_file_list">
                        <?= ui_icon('upload-cloud') ?>
                        <p class="subhead" style="margin:0">Click para agregar más documentos</p>
                        <p class="caption" style="margin:0">JPG, PNG, PDF — máx. 5MB cada uno</p>
                    </div>
                </div>
            </div>

            <div style="display:flex;gap:12px">
                <button type="button" onclick="closeEditModal()" class="btn" style="flex:1">Cancelar</button>
                <button type="submit" class="btn btn--primary" style="flex:1">Guardar cambios</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openEditModal() {
        const modal = document.getElementById('editModal');
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        lucide.createIcons();
    }

    function closeEditModal() {
        const modal = document.getElementById('editModal');
        modal.style.display = 'none';
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
        let html = '<div style="display:flex;flex-wrap:wrap;gap:4px;justify-content:center;margin-top:4px">';
        Array.from(input.files).forEach(file => {
            html += `<span class="tag">${file.name}</span>`;
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
                const chip = document.getElementById('file-chip-' + fileId);
                if (response.url && response.url.includes('error_delete')) {
                    alert('No se pudo eliminar el archivo.');
                } else {
                    if (chip) chip.remove();
                }
            })
            .catch(() => alert('Error de conexión al intentar eliminar.'));
    }

    // Control dinámico del día de cobro "Sábado" y resguardo histórico según la localidad en el sheet de edición
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

<?php include dirname(__DIR__, 2) . '/includes/footer.php'; ?>
