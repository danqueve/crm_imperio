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

    $sale_id = $_POST['sale_id'];
    $item = trim($_POST['item']); // NUEVO CAMPO
    $installments = (int)$_POST['installments'];
    $amount = (float)$_POST['amount'];
    
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
            die("Error al actualizar la venta: " . $e->getMessage());
        }
    } else {
        die("Datos inválidos. Por favor complete todos los campos.");
    }
}

header("Location: dashboard.php");
exit;
?>