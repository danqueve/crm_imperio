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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $reason  = trim($_POST['reason'] ?? '');
    $sale_id = (int)($_POST['sale_id'] ?? 0);

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

    if ($reason) {
        $stmt = $pdo->prepare("UPDATE sales SET status = 'rechazado', rejected_reason = ?, rejected_by = ? WHERE id = ?");
        $stmt->execute([$reason, $user_id, $sale_id]);
        log_audit($pdo, 'reject', 'sale', $sale_id, "Motivo: $reason");

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

            <form method="POST" class="space-y-4">
                <?= csrf_field() ?>
                <input type="hidden" name="sale_id" value="<?= htmlspecialchars($id) ?>">

                <div>
                    <label class="block text-xs font-bold uppercase mb-1.5 ml-1" style="color:var(--ink-3);">Motivo del Rechazo</label>
                    <textarea name="reason" rows="4" class="w-full input-light p-4 resize-none" placeholder="Ej: DNI ilegible, el cliente no atiende, dirección inexistente..." required autofocus></textarea>
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

    <script>lucide.createIcons();</script>
</body>
</html>