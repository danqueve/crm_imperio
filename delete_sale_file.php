<?php
require 'includes/db.php';

/**
 * Archivo: delete_sale_file.php
 * Propósito: Eliminar un archivo adjunto de una venta.
 * Permisos: Admin y Supervisor siempre. Vendedor solo si es dueño y la venta está en 'revision'.
 */

$role    = $_SESSION['role'] ?? '';
$user_id = (int)($_SESSION['user_id'] ?? 0);

if (!in_array($role, ['admin', 'supervisor', 'vendedor'])) {
    header("Location: dashboard.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: dashboard.php");
    exit;
}

csrf_verify();

$file_id = (int)($_POST['file_id'] ?? 0);
$sale_id = (int)($_POST['sale_id'] ?? 0);

if (!$file_id || !$sale_id) {
    header("Location: ver_ficha.php?id={$sale_id}&msg=error_delete");
    exit;
}

// Obtener el archivo y verificar que pertenece a la venta (prevención IDOR)
$stmt = $pdo->prepare("SELECT sf.id, sf.file_path, sf.sale_id, s.user_id AS sale_owner, s.status
                       FROM sale_files sf
                       JOIN sales s ON sf.sale_id = s.id
                       WHERE sf.id = ? AND sf.sale_id = ?");
$stmt->execute([$file_id, $sale_id]);
$file = $stmt->fetch();

if (!$file) {
    header("Location: ver_ficha.php?id={$sale_id}&msg=error_delete");
    exit;
}

// Vendedor: solo puede eliminar archivos de sus propias ventas en revisión
if ($role === 'vendedor') {
    if ((int)$file['sale_owner'] !== $user_id || $file['status'] !== 'revision') {
        header("Location: dashboard.php");
        exit;
    }
}

try {
    $pdo->beginTransaction();

    // Eliminar registro de BD
    $pdo->prepare("DELETE FROM sale_files WHERE id = ?")->execute([$file_id]);

    // Eliminar archivo del disco con validación de path traversal
    $uploads_dir = realpath(__DIR__ . '/uploads');
    $disk_path   = realpath(__DIR__ . '/uploads/' . $file['file_path']);
    if ($disk_path && $uploads_dir && strpos($disk_path, $uploads_dir . DIRECTORY_SEPARATOR) === 0 && file_exists($disk_path)) {
        unlink($disk_path);
    }

    $pdo->commit();

    log_audit($pdo, 'delete_file', 'sale_file', $file_id, "Archivo eliminado de venta #{$sale_id}: " . $file['file_path']);

    header("Location: ver_ficha.php?id={$sale_id}&msg=file_deleted");
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("delete_sale_file [file_id={$file_id}, sale_id={$sale_id}]: " . $e->getMessage());
    header("Location: ver_ficha.php?id={$sale_id}&msg=error_delete");
    exit;
}
