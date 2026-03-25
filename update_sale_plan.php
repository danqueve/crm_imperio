<?php
require 'includes/db.php';

// SEGURIDAD: Solo Admin, Supervisor y Verificador pueden editar
$allowed_roles = ['admin', 'supervisor', 'verificador'];

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    header("Location: dashboard.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $sale_id     = (int)($_POST['sale_id'] ?? 0);
    $item        = trim($_POST['item'] ?? '');
    $installments = (int)($_POST['installments'] ?? 0);
    $amount      = (float)($_POST['amount'] ?? 0);

    // Validaciones básicas
    if ($sale_id && !empty($item) && $installments > 0 && $amount >= 0) {

        // 1. Recalcular el total
        $new_total = $installments * $amount;

        try {
            // 2. Actualizar en Base de Datos (Incluyendo 'item')
            $sql = "UPDATE sales SET item = ?, installments_count = ?, installment_amount = ?, total_amount = ? WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$item, $installments, $amount, $new_total, $sale_id]);

            // 3. Volver a la ficha con mensaje de éxito
            header("Location: ver_ficha.php?id=" . $sale_id . "&msg=updated");
            exit;

        } catch (PDOException $e) {
            error_log("update_sale_plan [sale_id={$sale_id}]: " . $e->getMessage());
            header("Location: ver_ficha.php?id=" . $sale_id . "&msg=error");
            exit;
        }
    } else {
        $redirect_id = $sale_id ?: 0;
        header("Location: " . ($redirect_id ? "ver_ficha.php?id={$redirect_id}&msg=invalid" : "dashboard.php"));
        exit;
    }
}

header("Location: dashboard.php");
exit;
?>