<?php
require 'includes/db.php';

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
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rechazar Venta</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <link rel="stylesheet" href="style.css">
</head>
<body style="display:flex;align-items:center;justify-content:center;min-height:100vh;padding:16px;">

    <div class="max-w-md w-full relative overflow-hidden" style="background:var(--card);border:1.6px solid var(--line);border-radius:20px;box-shadow:0 8px 40px rgba(43,43,58,.1);">

        <!-- Franja superior -->
        <div style="height:5px;background:var(--rec-ink);"></div>

        <div style="padding:32px;">
            <div class="flex items-center gap-3 mb-6">
                <div class="p-3 rounded-full" style="background:var(--rec-bg);">
                    <i data-lucide="alert-octagon" class="w-8 h-8" style="color:var(--rec-ink);"></i>
                </div>
                <div>
                    <h2 class="text-xl font-bold" style="color:var(--ink);">Rechazar Venta #<?= htmlspecialchars($id) ?></h2>
                    <p class="text-sm" style="color:var(--ink-3);">Esta acción notificará al vendedor.</p>
                </div>
            </div>

            <?php if ($form_error): ?>
            <div class="mb-4 p-3 rounded-xl text-sm flex items-start gap-2" style="background:var(--rec-bg);color:var(--rec-ink);border:1px solid var(--rec-ink);">
                <i data-lucide="alert-circle" class="w-4 h-4 shrink-0 mt-0.5"></i>
                <span><?= htmlspecialchars($form_error) ?></span>
            </div>
            <?php endif; ?>

            <form method="POST" class="space-y-4" id="rechazoForm">
                <?= csrf_field() ?>
                <input type="hidden" name="sale_id" value="<?= htmlspecialchars($id) ?>">

                <?php
                $cur_type = $_POST['reject_type'] ?? '';
                $opciones = [
                    'mora'       => ['Mora', 'El cliente se puede volver a cargar más adelante.'],
                    'no_quiere'  => ['El cliente no lo quiere en este momento', 'También se puede volver a cargar más adelante.'],
                    'no_potable' => ['No es potable', 'Queda en lista negra. Requiere explicación.'],
                ];
                ?>
                <div>
                    <label class="block text-xs font-bold uppercase mb-2 ml-1" style="color:var(--ink-3);">Motivo del Rechazo</label>
                    <div class="space-y-2">
                        <?php foreach ($opciones as $val => [$titulo, $ayuda]): ?>
                        <label id="label_type_<?= $val ?>" class="cursor-pointer rounded-xl p-3 flex items-start gap-3 transition" style="border:1.5px solid <?= $cur_type === $val ? 'var(--rec-ink)' : 'var(--line)' ?>;<?= $cur_type === $val ? 'background:var(--rec-bg);' : '' ?>">
                            <input type="radio" name="reject_type" value="<?= $val ?>" id="type_<?= $val ?>" class="mt-1" <?= $cur_type === $val ? 'checked' : '' ?> required>
                            <div>
                                <p class="font-bold text-sm" style="color:var(--ink);"><?= htmlspecialchars($titulo) ?></p>
                                <p class="text-xs mt-0.5" style="color:var(--ink-3);"><?= htmlspecialchars($ayuda) ?></p>
                            </div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase mb-1.5 ml-1" style="color:var(--ink-3);">
                        Explicación <span id="reasonHint" style="color:var(--ink-3);text-transform:none;font-weight:400;">(opcional)</span>
                    </label>
                    <textarea name="reason" id="reasonField" rows="4" class="w-full input-light p-4 resize-none" placeholder="Ej: DNI ilegible, el cliente no atiende, dirección inexistente..."><?= htmlspecialchars($_POST['reason'] ?? '') ?></textarea>
                </div>

                <div class="flex gap-3 pt-2">
                    <a href="dashboard.php" class="flex-1 py-2.5 rounded-xl text-center font-medium transition" style="background:var(--paper);border:1.5px solid var(--line);color:var(--ink-2);" onmouseover="this.style.background='var(--accent-soft)';this.style.color='var(--accent-ink)'" onmouseout="this.style.background='var(--paper)';this.style.color='var(--ink-2)'">Cancelar</a>
                    <button type="submit" class="flex-1 py-2.5 text-white font-bold rounded-xl flex justify-center items-center gap-2 transition" style="background:var(--rec-ink);" onmouseover="this.style.opacity='.88'" onmouseout="this.style.opacity='1'">
                        Confirmar Rechazo
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        lucide.createIcons();

        // Resalta la tarjeta elegida y hace obligatoria la explicación solo para "No es potable".
        // El servidor revalida lo mismo, esto es solo la ayuda visual.
        (function () {
            const radios     = document.querySelectorAll('input[name="reject_type"]');
            const reasonEl   = document.getElementById('reasonField');
            const hintEl     = document.getElementById('reasonHint');

            function updateUI() {
                let selected = null;
                radios.forEach(function (r) {
                    const box = document.getElementById('label_type_' + r.value);
                    if (box) {
                        box.style.borderColor = r.checked ? 'var(--rec-ink)' : 'var(--line)';
                        box.style.background  = r.checked ? 'var(--rec-bg)' : '';
                    }
                    if (r.checked) selected = r.value;
                });

                const requiere = (selected === 'no_potable');
                reasonEl.required = requiere;
                hintEl.textContent = requiere ? '(obligatoria)' : '(opcional)';
                if (requiere) reasonEl.focus();
            }

            radios.forEach(function (r) { r.addEventListener('change', updateUI); });
            updateUI();
        })();
    </script>
</body>
</html>