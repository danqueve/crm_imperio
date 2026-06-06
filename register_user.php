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
    $dni              = trim($_POST['dni']);
    $nombre           = trim($_POST['nombre']);
    $apellido         = trim($_POST['apellido']);
    $celular          = trim($_POST['celular']);
    $role             = $_POST['role'];
    $commission_rate  = max(0, min(100, (float)($_POST['commission_rate'] ?? 5.00)));

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
            $sql = "INSERT INTO users (name, username, password, role, phone, commission_rate) VALUES (?, ?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            if ($stmt->execute([$full_name, $dni, $hashed_password, $role, $celular, $commission_rate])) {
                $message = "¡Usuario registrado!";
            } else {
                $error = "Error al registrar.";
            }
        }
    }
}

// --- ACTUALIZAR COMISIÓN ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_commission') {
    $target_user_id  = (int)$_POST['user_id'];
    $new_commission  = max(0, min(100, (float)($_POST['commission_rate'] ?? 5.00)));

    $stmt = $pdo->prepare("UPDATE users SET commission_rate = ? WHERE id = ?");
    if ($stmt->execute([$new_commission, $target_user_id])) {
        $message = "Comisión actualizada.";
    } else {
        $error = "Error al actualizar comisión.";
    }
}

// --- ACTIVAR / DESACTIVAR USUARIO ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_active') {
    $target_user_id = (int)$_POST['user_id'];
    if ($target_user_id == $_SESSION['user_id']) {
        $error = "No puedes desactivarte a ti mismo.";
    } else {
        $stmt = $pdo->prepare("UPDATE users SET is_active = NOT is_active WHERE id = ?");
        if ($stmt->execute([$target_user_id])) {
            $message = "Estado del usuario actualizado.";
        } else {
            $error = "Error al actualizar estado.";
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

$stmt = $pdo->query("SELECT * FROM users ORDER BY is_active DESC, name ASC");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

include 'includes/header.php';
?>

<main class="flex-1 max-w-7xl mx-auto w-full p-4 sm:p-6 lg:p-8 fade-in">
    
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-8">
        <div class="flex items-center gap-4">
            <a href="dashboard.php" class="p-2 rounded-full transition" style="background:var(--paper);border:1.5px solid var(--line);color:var(--ink-3);" onmouseover="this.style.background='var(--accent-soft)';this.style.color='var(--accent-ink)'" onmouseout="this.style.background='var(--paper)';this.style.color='var(--ink-3)'">
                <i data-lucide="chevron-left"></i>
            </a>
            <div>
                <h1 class="text-2xl font-bold" style="color:var(--ink);">Gestión de Usuarios</h1>
                <p class="text-sm text-slate-500">Alta de personal y administración de permisos.</p>
            </div>
        </div>
    </div>

    <div class="grid lg:grid-cols-2 gap-8">
        
        <!-- COLUMNA 1: FORMULARIO -->
        <div class="h-full">
            <div class="p-6 rounded-2xl h-full sticky top-24" style="background:var(--card);border:1.5px solid var(--line);box-shadow:var(--shadow-card);">
                <div class="flex items-center gap-3 mb-6 pb-4" style="border-bottom:1.5px solid var(--line);">
                    <div class="bg-blue-600/20 p-2 rounded-lg"><i data-lucide="user-plus" class="w-5 h-5 text-blue-400"></i></div>
                    <h2 class="font-bold" style="color:var(--ink);">Registrar Nuevo Usuario</h2>
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
                            <input type="number" name="dni" class="w-full input-light px-4 py-3 pl-10 font-mono" placeholder="Sin puntos" required>
                            <div class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-500 pointer-events-none"><i data-lucide="credit-card" class="w-4 h-4"></i></div>
                        </div>
                        <p class="text-[10px] text-blue-400/80 mt-1 ml-1">* La clave inicial será igual al número de DNI.</p>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase mb-1.5 ml-1">Nombre</label>
                            <input type="text" name="nombre" class="w-full input-light px-4 py-2.5" placeholder="Juan" required>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase mb-1.5 ml-1">Apellido</label>
                            <input type="text" name="apellido" class="w-full input-light px-4 py-2.5" placeholder="Perez" required>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-1.5 ml-1">Celular</label>
                        <div class="relative">
                            <input type="text" name="celular" class="w-full input-light px-4 py-2.5 pl-10" placeholder="Ej: 381...">
                            <div class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-500 pointer-events-none"><i data-lucide="smartphone" class="w-4 h-4"></i></div>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div class="col-span-2 sm:col-span-1">
                            <label class="block text-xs font-bold text-slate-500 uppercase mb-1.5 ml-1">Rol / Permisos</label>
                            <div class="relative">
                                <select name="role" class="w-full input-light px-4 py-3 pl-10 appearance-none cursor-pointer">
                                    <option value="vendedor">Vendedor</option>
                                    <option value="entregador">Entregador</option>
                                    <option value="verificador">Verificador</option>
                                    <option value="supervisor">Supervisor</option>
                                    <option value="admin">Administrador</option>
                                </select>
                                <div class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-500 pointer-events-none"><i data-lucide="shield" class="w-4 h-4"></i></div>
                                <div class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-500 pointer-events-none"><i data-lucide="chevron-down" class="w-4 h-4"></i></div>
                            </div>
                        </div>
                        <div class="col-span-2 sm:col-span-1">
                            <label class="block text-xs font-bold text-slate-500 uppercase mb-1.5 ml-1">% Comisión</label>
                            <div class="relative">
                                <input type="number" name="commission_rate" value="5" min="0" max="100" step="0.5" class="w-full input-light px-4 py-3 pl-10 font-mono">
                                <div class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-500 pointer-events-none"><i data-lucide="percent" class="w-4 h-4"></i></div>
                            </div>
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
            <div class="rounded-2xl overflow-hidden h-full flex flex-col" style="background:var(--card);border:1.5px solid var(--line);box-shadow:var(--shadow-card);">
                <div class="p-5 flex items-center gap-3" style="border-bottom:1.5px solid var(--line);background:#f8f7fc;">
                    <div class="bg-purple-600/20 p-2 rounded-lg"><i data-lucide="users" class="w-5 h-5 text-purple-400"></i></div>
                    <h2 class="font-bold" style="color:var(--ink);">Usuarios</h2>
                </div>
                
                <div class="overflow-x-auto flex-1">
                    <table class="w-full text-sm text-left">
                        <thead class="font-bold text-xs uppercase sticky top-0 z-10" style="background:#f8f7fc;color:var(--ink-3);"  >
                            <tr>
                                <th class="p-4">Nombre / DNI</th>
                                <th class="p-4 hidden sm:table-cell">Contacto</th>
                                <th class="p-4 hidden md:table-cell text-center">% Comis.</th>
                                <th class="p-4 text-right">Rol</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800">
                            <?php foreach ($users as $u): ?>
                            <?php $inactive = !$u['is_active']; ?>
                            <tr class="transition group <?= $inactive ? 'opacity-50' : '' ?>" style="border-bottom:1px dashed var(--line);" onmouseover="this.style.background='rgba(99,102,241,.04)'" onmouseout="this.style.background=''">
                                <td class="p-4">
                                    <div class="font-bold flex items-center gap-2" style="color:var(--ink);">
                                        <?= htmlspecialchars($u['name']) ?>
                                        <?php if ($u['id'] == $_SESSION['user_id']): ?>
                                            <span class="text-[10px] px-1.5 rounded" style="background:var(--accent-soft);color:var(--accent-ink);border:1px solid rgba(99,102,241,.2);">Tú</span>
                                        <?php elseif ($inactive): ?>
                                            <span class="text-[10px] px-1.5 rounded font-semibold" style="background:#fef2f2;color:#dc2626;border:1px solid #fecaca;">Inactivo</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="flex items-center gap-3 mt-1 text-xs text-slate-500">
                                        <span class="flex items-center gap-1 font-mono"><i data-lucide="credit-card" class="w-3 h-3"></i> <?= htmlspecialchars($u['username']) ?></span>
                                    </div>
                                </td>
                                <td class="p-4 hidden sm:table-cell">
                                    <div class="text-xs text-slate-400">
                                        <?php if (!empty($u['phone'])): ?>
                                            <div class="flex items-center gap-1"><i data-lucide="phone" class="w-3 h-3"></i> <?= htmlspecialchars($u['phone']) ?></div>
                                        <?php else: ?>
                                            <span class="text-slate-600">-</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="p-4 hidden md:table-cell text-center">
                                    <?php if ($u['id'] != $_SESSION['user_id']): ?>
                                    <form method="POST" class="flex items-center justify-center gap-1">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="update_commission">
                                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                        <input type="number" name="commission_rate" value="<?= number_format((float)($u['commission_rate'] ?? 5), 1) ?>" min="0" max="100" step="0.5"
                                               class="w-16 input-light text-xs py-1 px-2 text-center font-mono">
                                        <button type="submit" class="p-1 rounded transition" style="background:var(--apr-bg);color:var(--apr-ink);border:1px solid rgba(11,107,70,.2);" title="Guardar comisión" onmouseover="this.style.background='var(--apr-ink)';this.style.color='#fff'" onmouseout="this.style.background='var(--apr-bg)';this.style.color='var(--apr-ink)'">
                                            <i data-lucide="percent" class="w-3 h-3"></i>
                                        </button>
                                    </form>
                                    <?php else: ?>
                                        <span class="text-xs text-slate-500 font-mono"><?= number_format((float)($u['commission_rate'] ?? 5), 1) ?>%</span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-4 text-right">
                                    <?php if ($u['id'] == $_SESSION['user_id']): ?>
                                        <span class="text-xs text-slate-600 italic pr-2">Bloqueado</span>
                                    <?php else: ?>
                                        <div class="flex flex-col items-end gap-2">
                                            <!-- Cambio de Rol -->
                                            <?php if ($u['is_active']): ?>
                                            <form method="POST" class="flex items-center justify-end gap-2">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="update_role">
                                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                                <select name="new_role" class="input-light text-xs py-1.5 px-2 w-28 cursor-pointer">
                                                    <option value="vendedor" <?= $u['role'] == 'vendedor' ? 'selected' : '' ?>>Vend.</option>
                                                    <option value="entregador" <?= $u['role'] == 'entregador' ? 'selected' : '' ?>>Entreg.</option>
                                                    <option value="verificador" <?= $u['role'] == 'verificador' ? 'selected' : '' ?>>Verif.</option>
                                                    <option value="supervisor" <?= $u['role'] == 'supervisor' ? 'selected' : '' ?>>Sup.</option>
                                                    <option value="admin" <?= $u['role'] == 'admin' ? 'selected' : '' ?>>Admin</option>
                                                </select>
                                                <button type="submit" class="p-1.5 rounded transition shadow-sm" style="background:var(--apr-bg);color:var(--apr-ink);border:1px solid rgba(11,107,70,.2);" title="Guardar rol" onmouseover="this.style.background='var(--apr-ink)';this.style.color='#fff'" onmouseout="this.style.background='var(--apr-bg)';this.style.color='var(--apr-ink)'">
                                                    <i data-lucide="check" class="w-3.5 h-3.5"></i>
                                                </button>
                                            </form>
                                            <?php endif; ?>
                                            <!-- Toggle Activar / Desactivar -->
                                            <form method="POST" onsubmit="return confirm('<?= $inactive ? '¿Activar a ' : '¿Desactivar a ' ?><?= addslashes(htmlspecialchars($u['name'])) ?>?')">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="toggle_active">
                                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                                <?php if ($inactive): ?>
                                                    <button type="submit" class="flex items-center gap-1 text-xs px-2.5 py-1.5 rounded transition font-semibold" style="background:#dcfce7;color:#16a34a;border:1px solid #bbf7d0;" onmouseover="this.style.background='#16a34a';this.style.color='#fff'" onmouseout="this.style.background='#dcfce7';this.style.color='#16a34a'">
                                                        <i data-lucide="user-check" class="w-3.5 h-3.5"></i> Activar
                                                    </button>
                                                <?php else: ?>
                                                    <button type="submit" class="flex items-center gap-1 text-xs px-2.5 py-1.5 rounded transition font-semibold" style="background:#fee2e2;color:#dc2626;border:1px solid #fecaca;" onmouseover="this.style.background='#dc2626';this.style.color='#fff'" onmouseout="this.style.background='#fee2e2';this.style.color='#dc2626'">
                                                        <i data-lucide="user-x" class="w-3.5 h-3.5"></i> Desactivar
                                                    </button>
                                                <?php endif; ?>
                                            </form>
                                        </div>
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