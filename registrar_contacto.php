<?php
require 'includes/db.php';

/**
 * Archivo: registrar_contacto.php
 * Propósito: dejar un registro de cada intento de contacto con el cliente
 * mientras la venta está en verificación (En Revisión), para que el vendedor
 * pueda ver en qué está sin tener que preguntar.
 *
 * No usa una tabla propia: se guarda como una entrada más de audit_log
 * (action='contact_log', target_type='sale'), igual criterio que el resto
 * de los eventos de una venta (reject, assign_verifier, create_sale, etc.).
 */

$role    = $_SESSION['role'] ?? '';
$user_id = (int)($_SESSION['user_id'] ?? 0);

if (!in_array($role, ['admin', 'supervisor', 'verificador'], true)) {
    header("Location: dashboard.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: dashboard.php");
    exit;
}
csrf_verify();

$sale_id = (int)($_POST['sale_id'] ?? 0);
$result  = $_POST['contact_result'] ?? '';
$note    = trim($_POST['note'] ?? '');

$redirect = "ver_ficha.php?id={$sale_id}";

// Whitelist: nunca confiar en lo que llega por POST
if (!$sale_id || !array_key_exists($result, CONTACT_RESULT_TYPES)) {
    header("Location: {$redirect}&msg=error&fields=contacto_invalido");
    exit;
}

// "Otro" exige una nota que explique de qué se trató (igual criterio que "no potable" en el rechazo)
if ($result === 'otro' && $note === '') {
    header("Location: {$redirect}&msg=error&fields=nota_requerida");
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT status FROM sales WHERE id = ?");
    $stmt->execute([$sale_id]);
    $sale = $stmt->fetch();

    // Solo se puede registrar contacto mientras la venta está en verificación
    if (!$sale || $sale['status'] !== 'revision') {
        header("Location: {$redirect}&msg=error&fields=contacto_invalido");
        exit;
    }

    $details = json_encode(['result' => $result, 'note' => $note], JSON_UNESCAPED_UNICODE);
    log_audit($pdo, 'contact_log', 'sale', $sale_id, $details);

    header("Location: {$redirect}&msg=updated");
    exit;

} catch (PDOException $e) {
    error_log("registrar_contacto [id={$sale_id}]: " . $e->getMessage());
    header("Location: {$redirect}&msg=error");
    exit;
}
