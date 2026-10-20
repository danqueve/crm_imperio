<?php
require dirname(__DIR__, 2) . '/includes/db.php';

// SEGURIDAD: Admin, Supervisor, Verificador y Vendedor (solo para cancelar su propia venta en revisión)
$role    = $_SESSION['role'] ?? '';
$user_id = $_SESSION['user_id'] ?? 0;

if (!in_array($role, ['admin', 'supervisor', 'verificador', 'vendedor'])) {
    header("Location: dashboard.php");
    exit;
}

$id = (int)($_GET['id'] ?? 0);

// Si es vendedor, verificar propiedad y estado en GET (acceso a la página)
if ($role === 'vendedor' && $id) {
    $stmtChk = $pdo->prepare("SELECT user_id, status FROM sales WHERE id = ?");
    $stmtChk->execute([$id]);
    $saleChk = $stmtChk->fetch();
    if (!$saleChk || (int)$saleChk['user_id'] !== (int)$user_id || $saleChk['status'] !== 'revision') {
        header("Location: dashboard.php");
        exit;
    }
}

$form_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $reason  = trim($_POST['reason'] ?? '');
    $sale_id = (int)($_POST['sale_id'] ?? 0);

    // Tipo de rechazo: whitelist, nunca confiar en lo que llega por POST.
    // Solo 'no_potable' deja al cliente en lista negra (ver rejection_type_blocks()).
    $reject_type = $_POST['reject_type'] ?? '';
    if (!in_array($reject_type, REJECTION_TYPES_SELECTABLE, true)) {
        $reject_type = '';
        $form_error  = 'Elegí el motivo del rechazo.';
    }

    // La explicación solo es obligatoria cuando se rechaza por "no es potable"
    if ($reject_type === 'no_potable' && $reason === '') {
        $form_error = 'Si rechazás por "No es potable" tenés que dejar una explicación.';
    }

    // Re-verificar propiedad en POST para vendedores
    if ($role === 'vendedor') {
        $stmtChk2 = $pdo->prepare("SELECT user_id, status FROM sales WHERE id = ?");
        $stmtChk2->execute([$sale_id]);
        $saleChk2 = $stmtChk2->fetch();
        if (!$saleChk2 || (int)$saleChk2['user_id'] !== (int)$user_id || $saleChk2['status'] !== 'revision') {
            header("Location: dashboard.php");
            exit;
        }
    }

    if (!$form_error) {
        $stmt = $pdo->prepare("UPDATE sales SET status = 'rechazado', rejected_reason = ?, rejected_type = ?, rejected_by = ? WHERE id = ?");
        $stmt->execute([$reason, $reject_type, $user_id, $sale_id]);

        $typeLabel = rejection_type_label($reject_type);
        $detail    = "Tipo: $typeLabel" . ($reason !== '' ? " | Motivo: $reason" : '');
        log_audit($pdo, 'reject', 'sale', $sale_id, $detail);

        header("Location: dashboard.php");
        exit;
    }
}
include dirname(__DIR__, 2) . '/includes/header.php';
?>

<main class="app-main">
<div class="app-container" style="max-width:640px">

    <div class="page-head">
        <div class="page-head__text">
            <a href="ver_ficha.php?id=<?= (int)$id ?>" class="navbar-back"><?= ui_icon('chevron-left') ?> Volver a la ficha</a>
            <h1 class="large-title">Rechazar venta <span class="tab" style="color:var(--red-text)">#<?= (int)$id ?></span></h1>
            <p class="subhead">Esta acción notificará al vendedor: va a ver el rechazo y el motivo en la ficha.</p>
        </div>
    </div>

    <?php if ($form_error): ?>
    <div class="notice notice--error" role="alert"><?= ui_icon('alert-circle') ?> <span><?= htmlspecialchars($form_error) ?></span></div>
    <?php endif; ?>

    <form method="POST" id="rechazoForm" class="card card--pad" style="display:flex;flex-direction:column;gap:18px">
        <?= csrf_field() ?>
        <input type="hidden" name="sale_id" value="<?= (int)$id ?>">

        <?php
        $cur_type = $_POST['reject_type'] ?? '';
        $opciones = [
            'mora'       => ['Mora', 'El cliente se puede volver a cargar más adelante.'],
            'no_quiere'  => ['El cliente no lo quiere en este momento', 'También se puede volver a cargar más adelante.'],
            'no_potable' => ['No es potable', 'Queda en lista negra. Requiere explicación.'],
        ];
        ?>
        <div class="field">
            <span class="field__label" id="tipoLabel">Motivo del rechazo</span>
            <div role="radiogroup" aria-labelledby="tipoLabel" style="display:flex;flex-direction:column;gap:10px">
                <?php foreach ($opciones as $val => [$titulo, $ayuda]): ?>
                <label id="label_type_<?= $val ?>" class="choice">
                    <input type="radio" name="reject_type" value="<?= $val ?>" id="type_<?= $val ?>" <?= $cur_type === $val ? 'checked' : '' ?> required>
                    <span><span class="choice__title"><?= htmlspecialchars($titulo) ?></span><span class="choice__sub"><?= htmlspecialchars($ayuda) ?></span></span>
                </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="field">
            <label class="field__label" for="reasonField">Explicación <span id="reasonHint" class="field__hint">(opcional)</span></label>
            <textarea name="reason" id="reasonField" rows="4" class="textarea" placeholder="Ej: DNI ilegible, el cliente no atiende, dirección inexistente..."><?= htmlspecialchars($_POST['reason'] ?? '') ?></textarea>
        </div>

        <div style="display:flex;gap:12px">
            <a href="dashboard.php" class="btn" style="flex:0 1 auto">Cancelar</a>
            <button type="submit" class="btn btn--danger" style="flex:1;white-space:nowrap"><?= ui_icon('x-circle') ?> Confirmar rechazo</button>
        </div>
    </form>
</div>
</main>

<script>
    // Resalta la tarjeta elegida y hace obligatoria la explicación solo para "No es potable".
    // El servidor revalida lo mismo, esto es solo la ayuda visual.
    (function () {
        const radios   = document.querySelectorAll('input[name="reject_type"]');
        const reasonEl = document.getElementById('reasonField');
        const hintEl   = document.getElementById('reasonHint');

        function updateUI(ev) {
            let selected = null;
            radios.forEach(function (r) {
                const box = document.getElementById('label_type_' + r.value);
                if (box) {
                    box.style.borderColor = r.checked ? 'var(--red)' : '';
                    box.style.background  = r.checked ? 'var(--red-soft)' : '';
                }
                if (r.checked) selected = r.value;
            });

            const requiere = (selected === 'no_potable');
            reasonEl.required = requiere;
            hintEl.textContent = requiere ? '(obligatoria)' : '(opcional)';
            // Solo al elegirlo el usuario: al cargar la página (error o restauración del navegador) no se roba el foco
            if (requiere && ev && ev.type === "change") reasonEl.focus();
        }

        radios.forEach(function (r) { r.addEventListener('change', updateUI); });
        updateUI();
    })();
</script>

<?php include dirname(__DIR__, 2) . '/includes/footer.php'; ?>
