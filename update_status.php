<?php
require 'includes/db.php';

// SEGURIDAD: Permitir acceso a Admin, Supervisor y Verificador
// (Verificadores pueden entregar y aprobar, pero no ver comisiones ni usuarios)
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'supervisor', 'verificador'])) {
    header("Location: dashboard.php");
    exit;
}

// Solo acepta POST con CSRF válido
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: dashboard.php");
    exit;
}
csrf_verify();

$id = $_POST['id'] ?? null;
$status = $_POST['status'] ?? null;

// Validar que el status sea uno de los valores permitidos
$allowed_statuses = ['aprobado', 'entregado', 'revision'];
if (!in_array($status, $allowed_statuses)) {
    header("Location: dashboard.php");
    exit;
}

if ($id && $status) {
    $updateFields = "status = ?";
    $params = [$status];

    // --- LÓGICA DE AUDITORÍA Y CAMBIO DE ESTADOS ---

    // CASO 1: APROBAR VENTA (Desde Revisión o Anulando Entrega)
    if ($status === 'aprobado') {
        // Registramos quién aprueba y el momento exacto
        $updateFields .= ", approved_at = NOW(), approved_by = ?";
        $params[] = $_SESSION['user_id'];
        
        // Si venía de "Entregado" (Anular Entrega), limpiamos los datos de entrega
        $updateFields .= ", delivered_at = NULL, delivered_by = NULL";
    }

    // CASO 2: CONFIRMAR ENTREGA
    if ($status === 'entregado') {
        // Registramos quién entrega y el momento exacto
        $updateFields .= ", delivered_at = NOW(), delivered_by = ?";
        $params[] = $_SESSION['user_id'];
    }

    // CASO 3: VOLVER A REVISIÓN (Rechazo o Reinicio de Ciclo)
    if ($status === 'revision') {
        // Limpiamos TODO el historial para reiniciar el proceso limpiamente
        // Se borran datos de aprobación, entrega y rechazo previo
        $updateFields .= ", approved_at = NULL, approved_by = NULL, delivered_at = NULL, delivered_by = NULL, rejected_reason = NULL, rejected_by = NULL";
    }

    // Agregamos el ID de la venta al final para el WHERE
    $params[] = $id;

    try {
        $stmt = $pdo->prepare("UPDATE sales SET $updateFields WHERE id = ?");
        $stmt->execute($params);
        log_audit($pdo, $status, 'sale', $id, "Estado cambiado a: $status");
    } catch (PDOException $e) {
        die("Error al actualizar estado: " . $e->getMessage());
    }
}

// Redirección inteligente: Vuelve a la página donde estaba el usuario
if (isset($_SERVER['HTTP_REFERER'])) {
    header("Location: " . $_SERVER['HTTP_REFERER']);
} else {
    header("Location: dashboard.php");
}
exit;
?>