<?php
require 'includes/db.php';

// SEGURIDAD: Solo Admin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: dashboard.php");
    exit;
}

$message = '';
$error = '';

// --- CREAR USUARIO ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    $dni = trim($_POST['dni']);
    $nombre = trim($_POST['nombre']);
    $apellido = trim($_POST['apellido']);
    $celular = trim($_POST['celular']);
    $role = $_POST['role'];

    if (empty($dni) || empty($nombre) || empty($apellido) || empty($role)) {
        $error = "DNI, Nombre, Apellido y Rol son obligatorios.";
    } else {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$dni]);
        if ($stmt->fetch()) {
            $error = "Este DNI ya está registrado.";
        } else {
            $full_name = $apellido . ', ' . $nombre;
            $hashed_password = password_hash($dni, PASSWORD_DEFAULT);
            $sql = "INSERT INTO users (name, username, password, role, phone) VALUES (?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            
            if ($stmt->execute([$full_name, $dni, $hashed_password, $role, $celular])) {
                $message = "¡Usuario registrado!";
            } else {
                $error = "Error al registrar.";
            }
        }
    }
}

// --- ACTUALIZAR ROL ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_role') {
    $target_user_id = $_POST['user_id'];
    $new_role = $_POST['new_role'];

    if ($target_user_id == $_SESSION['user_id']) {
        $error = "No puedes cambiar tu propio rol.";
    } else {
        $stmt = $pdo->prepare("UPDATE users SET role = ? WHERE id = ?");
        if ($stmt->execute([$new_role, $target_user_id])) {
            $message = "Rol actualizado.";
        } else {
            $error = "Error al actualizar.";
        }
    }
}

$stmt = $pdo->query("SELECT * FROM users ORDER BY name ASC");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

include 'includes/header.php';
?>

<main class="flex-1 max-w-7xl mx-auto w-full p-4 sm:p-6 lg:p-8 fade-in">
    
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-8">
        <div class="flex items-center gap-4">
            <a href="dashboard.php" class="p-2 bg-slate-800 rounded-full text-slate-400 hover:text-white transition border border-slate-700">
                <i data-lucide="chevron-left"></i>
            </a>
            <div>
                <h1 class="text-2xl font-bold text-white">Gestión de Usuarios</h1>
                <p class="text-sm text-slate-500">Alta de personal y administración de permisos.</p>
            </div>
        </div>
    </div>

    <div class="grid lg:grid-cols-2 gap-8">
        
        <!-- COLUMNA 1: FORMULARIO -->
        <div class="h-full">
            <div class="bg-slate-900 p-6 rounded-2xl border border-slate-800 shadow-xl h-full sticky top-24">
                <div class="flex items-center gap-3 mb-6 pb-4 border-b border-slate-800">
                    <div class="bg-blue-600/20 p-2 rounded-lg"><i data-lucide="user-plus" class="w-5 h-5 text-blue-400"></i></div>
                    <h2 class="font-bold text-white">Registrar Nuevo Usuario</h2>
                </div>

                <?php if ($message): ?>
                    <div class="mb-4 bg-green-500/10 border border-green-500/20 text-green-400 p-3 rounded-lg text-sm flex items-center gap-2 animate-pulse">
                        <i data-lucide="check-circle" class="w-4 h-4"></i> <?= $message ?>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="mb-4 bg-red-500/10 border border-red-500/20 text-red-400 p-3 rounded-lg text-sm flex items-center gap-2">
                        <i data-lucide="alert-circle" class="w-4 h-4"></i> <?= $error ?>
                    </div>
                <?php endif; ?>

                <form method="POST" class="space-y-5">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create">
                    
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-1.5 ml-1">DNI (Usuario y Clave)</label>
                        <div class="relative">
                            <input type="number" name="dni" class="w-full bg-slate-950 border border-slate-700 rounded-lg px-4 py-3 pl-10 text-white focus:border-blue-500 outline-none transition placeholder:text-slate-600 font-mono" placeholder="Sin puntos" required>
                            <div class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-500 pointer-events-none"><i data-lucide="credit-card" class="w-4 h-4"></i></div>
                        </div>
                        <p class="text-[10px] text-blue-400/80 mt-1 ml-1">* La clave inicial será igual al número de DNI.</p>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase mb-1.5 ml-1">Nombre</label>
                            <input type="text" name="nombre" class="w-full bg-slate-950 border border-slate-700 rounded-lg px-4 py-2.5 text-white focus:border-blue-500 outline-none transition" placeholder="Juan" required>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase mb-1.5 ml-1">Apellido</label>
                            <input type="text" name="apellido" class="w-full bg-slate-950 border border-slate-700 rounded-lg px-4 py-2.5 text-white focus:border-blue-500 outline-none transition" placeholder="Perez" required>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-1.5 ml-1">Celular</label>
                        <div class="relative">
                            <input type="text" name="celular" class="w-full bg-slate-950 border border-slate-700 rounded-lg px-4 py-2.5 pl-10 text-white focus:border-blue-500 outline-none transition" placeholder="Ej: 381...">
                            <div class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-500 pointer-events-none"><i data-lucide="smartphone" class="w-4 h-4"></i></div>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-1.5 ml-1">Rol / Permisos</label>
                        <div class="relative">
                            <select name="role" class="w-full bg-slate-950 border border-slate-700 rounded-lg px-4 py-3 pl-10 text-white focus:border-blue-500 outline-none appearance-none cursor-pointer transition hover:bg-slate-900">
                                <option value="vendedor">Vendedor</option>
                                <option value="verificador">Verificador</option> <!-- NUEVA OPCIÓN -->
                                <option value="supervisor">Supervisor</option>
                                <option value="admin">Administrador</option>
                            </select>
                            <div class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-500 pointer-events-none"><i data-lucide="shield" class="w-4 h-4"></i></div>
                            <div class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-500 pointer-events-none"><i data-lucide="chevron-down" class="w-4 h-4"></i></div>
                        </div>
                    </div>

                    <button type="submit" class="w-full bg-blue-600 hover:bg-blue-500 text-white font-bold py-3 rounded-lg shadow-lg shadow-blue-900/30 transition transform hover:-translate-y-0.5 mt-4 flex justify-center items-center gap-2">
                        <i data-lucide="save" class="w-4 h-4"></i> Registrar Usuario
                    </button>
                </form>
            </div>
        </div>

        <!-- COLUMNA 2: LISTADO -->
        <div class="h-full">
            <div class="bg-slate-900 rounded-2xl border border-slate-800 shadow-xl overflow-hidden h-full flex flex-col">
                <div class="p-5 border-b border-slate-800 flex items-center gap-3 bg-slate-950/30">
                    <div class="bg-purple-600/20 p-2 rounded-lg"><i data-lucide="users" class="w-5 h-5 text-purple-400"></i></div>
                    <h2 class="font-bold text-white">Equipo Activo</h2>
                </div>
                
                <div class="overflow-x-auto flex-1">
                    <table class="w-full text-sm text-left">
                        <thead class="bg-slate-950 text-slate-400 font-bold text-xs uppercase sticky top-0 z-10">
                            <tr>
                                <th class="p-4">Nombre / DNI</th>
                                <th class="p-4">Contacto</th>
                                <th class="p-4 text-right">Rol</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800">
                            <?php foreach ($users as $u): ?>
                            <tr class="hover:bg-slate-800/50 transition group">
                                <td class="p-4">
                                    <div class="font-bold text-white flex items-center gap-2">
                                        <?= htmlspecialchars($u['name']) ?>
                                        <?php if ($u['id'] == $_SESSION['user_id']): ?>
                                            <span class="text-[10px] bg-slate-800 text-slate-400 px-1.5 rounded border border-slate-700">Tú</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="flex items-center gap-3 mt-1 text-xs text-slate-500">
                                        <span class="flex items-center gap-1 font-mono"><i data-lucide="credit-card" class="w-3 h-3"></i> <?= htmlspecialchars($u['username']) ?></span>
                                    </div>
                                </td>
                                <td class="p-4">
                                    <div class="text-xs text-slate-400">
                                        <?php if (!empty($u['phone'])): ?>
                                            <div class="flex items-center gap-1"><i data-lucide="phone" class="w-3 h-3"></i> <?= htmlspecialchars($u['phone']) ?></div>
                                        <?php else: ?>
                                            <span class="text-slate-600">-</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="p-4 text-right">
                                    <?php if ($u['id'] == $_SESSION['user_id']): ?>
                                        <span class="text-xs text-slate-600 italic pr-2">Bloqueado</span>
                                    <?php else: ?>
                                        <!-- Formulario Pequeño para Cambio de Rol -->
                                        <form method="POST" class="flex items-center justify-end gap-2">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="update_role">
                                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                            
                                            <select name="new_role" class="bg-slate-950 border border-slate-700 rounded text-xs text-white py-1.5 px-2 focus:border-blue-500 outline-none w-24 cursor-pointer hover:bg-slate-900 transition">
                                                <option value="vendedor" <?= $u['role'] == 'vendedor' ? 'selected' : '' ?>>Vend.</option>
                                                <option value="verificador" <?= $u['role'] == 'verificador' ? 'selected' : '' ?>>Verif.</option> <!-- OPCIÓN VERIFICADOR -->
                                                <option value="supervisor" <?= $u['role'] == 'supervisor' ? 'selected' : '' ?>>Sup.</option>
                                                <option value="admin" <?= $u['role'] == 'admin' ? 'selected' : '' ?>>Admin</option>
                                            </select>
                                            
                                            <button type="submit" class="p-1.5 bg-slate-800 hover:bg-emerald-600 text-emerald-400 hover:text-white rounded border border-slate-700 transition shadow-sm" title="Guardar">
                                                <i data-lucide="check" class="w-3.5 h-3.5"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</main>

<?php include 'includes/footer.php'; ?>