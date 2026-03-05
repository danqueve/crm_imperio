<?php
require 'includes/db.php';

// SEGURIDAD: Permitir Admin, Supervisor Y VERIFICADOR
// Antes: if (!isset($_SESSION['role']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'supervisor')) {
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'supervisor', 'verificador'])) {
    header("Location: dashboard.php");
    exit;
}

$id = $_GET['id'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $reason = trim($_POST['reason']);
    $sale_id = $_POST['sale_id'];
    
    if ($reason) {
        // Guardamos rejected_by con el ID del usuario actual (incluido Verificador)
        $stmt = $pdo->prepare("UPDATE sales SET status = 'rechazado', rejected_reason = ?, rejected_by = ? WHERE id = ?");
        $stmt->execute([$reason, $_SESSION['user_id'], $sale_id]);
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
</head>
<body class="bg-slate-950 text-gray-100 flex items-center justify-center min-h-screen p-4 font-sans">
    
    <div class="bg-slate-900 p-8 rounded-2xl border border-slate-800 max-w-md w-full shadow-2xl relative overflow-hidden">
        
        <!-- Decoración de fondo -->
        <div class="absolute top-0 left-0 w-full h-1 bg-red-600"></div>
        
        <div class="flex items-center gap-3 mb-6">
            <div class="bg-red-500/10 p-3 rounded-full">
                <i data-lucide="alert-octagon" class="text-red-500 w-8 h-8"></i>
            </div>
            <div>
                <h2 class="text-xl font-bold text-white">Rechazar Venta #<?= htmlspecialchars($id) ?></h2>
                <p class="text-sm text-slate-500">Esta acción notificará al vendedor.</p>
            </div>
        </div>
        
        <form method="POST" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="sale_id" value="<?= htmlspecialchars($id) ?>">
            
            <div>
                <label class="block text-xs font-bold text-slate-400 uppercase mb-1.5 ml-1">Motivo del Rechazo</label>
                <textarea name="reason" rows="4" class="w-full bg-slate-950 border border-slate-700 rounded-xl p-4 text-white placeholder:text-slate-600 focus:border-red-500 focus:ring-1 focus:ring-red-500/50 outline-none transition resize-none" placeholder="Ej: DNI ilegible, el cliente no atiende, dirección inexistente..." required autofocus></textarea>
            </div>
            
            <div class="flex gap-3 pt-2">
                <a href="dashboard.php" class="flex-1 py-2.5 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800 transition text-center font-medium border border-transparent hover:border-slate-700">Cancelar</a>
                <button type="submit" class="flex-1 py-2.5 bg-red-600 hover:bg-red-700 text-white font-bold rounded-lg shadow-lg shadow-red-900/20 transition transform hover:-translate-y-0.5 flex justify-center items-center gap-2">
                    Confirmar Rechazo
                </button>
            </div>
        </form>
    </div>

    <script>lucide.createIcons();</script>
</body>
</html>