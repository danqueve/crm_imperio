<?php
require 'includes/db.php';

// SEGURIDAD: Solo Admin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: dashboard.php");
    exit;
}

// Solo acepta POST con CSRF válido
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: dashboard.php");
    exit;
}
csrf_verify();

$target_user_id = (int)($_POST['user_id'] ?? 0);
$target_sales = filter_var($_POST['target_sales'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

if ($target_user_id <= 0 || $target_sales === false) {
    header("Location: dashboard.php");
    exit;
}

// Verificar que el destino sea un usuario activo existente (cualquier rol)
$stmtChk = $pdo->prepare("SELECT is_active FROM users WHERE id = ?");
$stmtChk->execute([$target_user_id]);
$targetActive = $stmtChk->fetchColumn();
if ($targetActive === false || (int)$targetActive !== 1) {
    header("Location: dashboard.php");
    exit;
}

try {
    $stmt = $pdo->prepare(
        "INSERT INTO seller_goals (user_id, target_sales, updated_by) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE target_sales = VALUES(target_sales), updated_by = VALUES(updated_by)"
    );
    $stmt->execute([$target_user_id, $target_sales, $_SESSION['user_id']]);
    log_audit($pdo, 'set_goal', 'user', $target_user_id, "Meta mensual actualizada a {$target_sales} ventas");
    header("Location: dashboard.php?msg=goal_saved");
    exit;
} catch (PDOException $e) {
    error_log("set_goal [user_id={$target_user_id}]: " . $e->getMessage());
    header("Location: dashboard.php?msg=error");
    exit;
}
