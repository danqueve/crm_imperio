<?php
require 'includes/db.php';

// SEGURIDAD: Solo Admin y Supervisor pueden asignar verificación
require_role(['admin', 'supervisor']);

// Solo acepta POST con CSRF válido
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: dashboard.php");
    exit;
}
csrf_verify();

$sale_id = (int)($_POST['sale_id'] ?? 0);
$verifier_id_raw = trim($_POST['verifier_id'] ?? '');

if ($sale_id > 0) {
    if ($verifier_id_raw === '') {
        // Desasignar
        $stmt = $pdo->prepare("UPDATE sales SET assigned_verifier_id = NULL, assigned_by = NULL, assigned_at = NULL WHERE id = ?");
        $stmt->execute([$sale_id]);
        log_audit($pdo, 'unassign_verifier', 'sale', $sale_id, "Asignación de verificador removida");
    } else {
        $verifier_id = (int)$verifier_id_raw;

        // Validar que el id corresponde a un verificador activo real (evita IDOR/tampering del <select>)
        $stmtChk = $pdo->prepare("SELECT id, name FROM users WHERE id = ? AND role = 'verificador' AND is_active = 1");
        $stmtChk->execute([$verifier_id]);
        $verifier = $stmtChk->fetch(PDO::FETCH_ASSOC);

        if ($verifier) {
            $stmt = $pdo->prepare("UPDATE sales SET assigned_verifier_id = ?, assigned_by = ?, assigned_at = NOW() WHERE id = ?");
            $stmt->execute([$verifier['id'], $_SESSION['user_id'], $sale_id]);
            log_audit($pdo, 'assign_verifier', 'sale', $sale_id, "Asignado a verificador: {$verifier['name']}");
        }
    }
}

// Redirección segura: solo se permite volver a páginas internas del CRM
$redirect = 'dashboard.php';
$allowed_pages = ['dashboard.php', 'ver_ficha.php'];
if (isset($_SERVER['HTTP_REFERER'])) {
    $referer_file = basename(parse_url($_SERVER['HTTP_REFERER'], PHP_URL_PATH) ?? '');
    if (in_array($referer_file, $allowed_pages)) {
        $redirect = $referer_file;
        if ($referer_file === 'ver_ficha.php') {
            $referer_query = parse_url($_SERVER['HTTP_REFERER'], PHP_URL_QUERY) ?? '';
            if ($referer_query) $redirect .= '?' . $referer_query;
        }
    }
}
header("Location: " . $redirect);
exit;
