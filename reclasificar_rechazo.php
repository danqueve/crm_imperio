<?php
require 'includes/db.php';

/**
 * Archivo: reclasificar_rechazo.php
 * Propósito: permitir que admin/supervisor cambien el TIPO de un rechazo ya hecho.
 * Es la única vía dentro del sistema para sacar a un cliente de la lista negra
 * (pasando un rechazo de 'no_potable' a 'mora' o 'no_quiere').
 */

$role    = $_SESSION['role'] ?? '';
$user_id = (int)($_SESSION['user_id'] ?? 0);

if (!in_array($role, ['admin', 'supervisor'], true)) {
    header("Location: dashboard.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: dashboard.php");
    exit;
}
csrf_verify();

$sale_id  = (int)($_POST['sale_id'] ?? 0);
$new_type = $_POST['reject_type'] ?? '';
$reason   = trim($_POST['reason'] ?? '');

$redirect = "ver_ficha.php?id={$sale_id}";

// Whitelist: nunca confiar en lo que llega por POST
if (!$sale_id || !in_array($new_type, REJECTION_TYPES_SELECTABLE, true)) {
    header("Location: {$redirect}&msg=error&fields=tipo_rechazo");
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT status, rejected_type, rejected_reason FROM sales WHERE id = ?");
    $stmt->execute([$sale_id]);
    $sale = $stmt->fetch();

    // Solo tiene sentido reclasificar una venta que efectivamente está rechazada
    if (!$sale || $sale['status'] !== 'rechazado') {
        header("Location: {$redirect}&msg=error&fields=tipo_rechazo");
        exit;
    }

    // Si pasa a "no potable" tiene que quedar una explicación (igual criterio que al rechazar)
    $final_reason = $reason !== '' ? $reason : (string)$sale['rejected_reason'];
    if ($new_type === 'no_potable' && trim($final_reason) === '') {
        header("Location: {$redirect}&msg=error&fields=explicacion_requerida");
        exit;
    }

    $upd = $pdo->prepare("UPDATE sales SET rejected_type = ?, rejected_reason = ? WHERE id = ?");
    $upd->execute([$new_type, $final_reason, $sale_id]);

    $antes   = rejection_type_label($sale['rejected_type']);
    $despues = rejection_type_label($new_type);
    log_audit($pdo, 'reclassify_reject', 'sale', $sale_id, "Tipo de rechazo: {$antes} -> {$despues}");

    header("Location: {$redirect}&msg=updated");
    exit;

} catch (PDOException $e) {
    error_log("reclasificar_rechazo [id={$sale_id}]: " . $e->getMessage());
    header("Location: {$redirect}&msg=error");
    exit;
}
