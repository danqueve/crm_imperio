<?php
require 'includes/db.php';

// SEGURIDAD: Permitir acceso a Admin, Supervisor, Verificador y Entregador
// (Entregadores solo pueden marcar como entregado)
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'supervisor', 'verificador', 'entregador'])) {
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

// RESTRICCIÓN DE ENTREGADOR: Solo puede marcar como 'entregado' o anular entrega ('aprobado')
if ($_SESSION['role'] === 'entregador' && !in_array($status, ['entregado', 'aprobado'])) {
    header("Location: entregas.php");
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
        $updateFields .= ", approved_at = NULL, approved_by = NULL, delivered_at = NULL, delivered_by = NULL, rejected_reason = NULL, rejected_by = NULL, rejected_type = NULL";
    }

    // Agregamos el ID de la venta al final para el WHERE
    $params[] = $id;

    try {
        $stmt = $pdo->prepare("UPDATE sales SET $updateFields WHERE id = ?");
        $stmt->execute($params);
        log_audit($pdo, $status, 'sale', $id, "Estado cambiado a: $status");
    } catch (PDOException $e) {
        error_log("update_status [id={$id}]: " . $e->getMessage());
        header("Location: dashboard.php");
        exit;
    }
}

// Redirección segura: solo se permite volver a páginas internas del CRM
$redirect = 'dashboard.php';
$allowed_pages = ['dashboard.php', 'ver_ficha.php', 'entregas.php', 'historial_ventas.php'];
if (isset($_SERVER['HTTP_REFERER'])) {
    $referer_file = basename(parse_url($_SERVER['HTTP_REFERER'], PHP_URL_PATH) ?? '');
    if (in_array($referer_file, $allowed_pages)) {
        $redirect = $referer_file;
        // Preservar query string si viene de ver_ficha.php o historial_ventas.php
        if (in_array($referer_file, ['ver_ficha.php', 'historial_ventas.php'])) {
            $referer_query = parse_url($_SERVER['HTTP_REFERER'], PHP_URL_QUERY) ?? '';
            if ($referer_query) $redirect .= '?' . $referer_query;
        }
    }
}
header("Location: " . $redirect);
exit;
?>