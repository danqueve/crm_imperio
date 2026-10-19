<?php
require dirname(__DIR__, 2) . '/includes/db.php';

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
$activeUsers = count(array_filter($users, static function (array $user): bool {
    return (bool) $user['is_active'];
}));
$roleLabels = [
    'vendedor' => 'Vendedor',
    'entregador' => 'Entregador',
    'verificador' => 'Verificador',
    'supervisor' => 'Supervisor',
    'admin' => 'Administrador',
];

include dirname(__DIR__, 2) . '/includes/header.php';
?>

<main class="app-main">
<div class="app-container users-page">
    <div class="page-head">
        <div class="page-head__text">
            <a href="mas.php" class="navbar-back only-mobile"><?= ui_icon('chevron-left') ?> Más</a>
            <a href="dashboard.php" class="navbar-back only-desktop"><?= ui_icon('chevron-left') ?> Panel</a>
            <p class="eyebrow">Administración</p>
            <h1 class="large-title">Usuarios</h1>
            <p class="subhead">Creá accesos y administrá permisos desde un solo lugar.</p>
        </div>
        <div class="users-summary" aria-label="Resumen de usuarios">
            <span class="users-summary__value"><?= $activeUsers ?></span>
            <span class="users-summary__label">activos de <?= count($users) ?></span>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="notice notice--success" role="status"><?= ui_icon('check-circle') ?> <?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="notice notice--error" role="alert"><?= ui_icon('alert-circle') ?> <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="users-layout">
        <section class="card card--pad users-create-card" aria-labelledby="createUserTitle">
            <div class="users-section-title">
                <span class="icon-box icon-box--tint"><?= ui_icon('user-plus') ?></span>
                <div>
                    <h2 id="createUserTitle" class="title2">Nuevo usuario</h2>
                    <p class="caption">El DNI será el usuario y la clave inicial.</p>
                </div>
            </div>

            <form method="POST" class="users-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create">

                <div class="field">
                    <label class="field__label" for="new-user-dni">DNI</label>
                    <input id="new-user-dni" type="number" name="dni" class="input" inputmode="numeric" placeholder="Sin puntos" required>
                    <p class="field__hint">Se utilizará para el ingreso inicial.</p>
                </div>

                <div class="users-form__identity">
                    <div class="field">
                        <label class="field__label" for="new-user-name">Nombre</label>
                        <input id="new-user-name" type="text" name="nombre" class="input" autocomplete="given-name" placeholder="Juan" required>
                    </div>
                    <div class="field">
                        <label class="field__label" for="new-user-lastname">Apellido</label>
                        <input id="new-user-lastname" type="text" name="apellido" class="input" autocomplete="family-name" placeholder="Pérez" required>
                    </div>
                </div>

                <div class="field">
                    <label class="field__label" for="new-user-phone">Celular <span class="caption">opcional</span></label>
                    <input id="new-user-phone" type="tel" name="celular" class="input" inputmode="tel" autocomplete="tel" placeholder="Ej. 381 555 1234">
                </div>

                <div class="users-form__settings">
                    <div class="field">
                        <label class="field__label" for="new-user-role">Rol y permisos</label>
                        <select id="new-user-role" name="role" class="select">
                            <?php foreach ($roleLabels as $roleKey => $roleLabel): ?>
                                <option value="<?= $roleKey ?>"><?= $roleLabel ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label class="field__label" for="new-user-commission">Comisión</label>
                        <div class="users-percent-input">
                            <input id="new-user-commission" type="number" name="commission_rate" value="5" min="0" max="100" step="0.5" class="input" inputmode="decimal">
                            <span aria-hidden="true">%</span>
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn btn--primary btn--block btn--lg"><?= ui_icon('user-plus') ?> Crear usuario</button>
            </form>
        </section>

        <section class="card users-directory" aria-labelledby="usersDirectoryTitle">
            <div class="card__head users-directory__head">
                <div class="users-section-title">
                    <span class="icon-box icon-box--indigo"><?= ui_icon('users') ?></span>
                    <div>
                        <h2 id="usersDirectoryTitle" class="title2">Directorio</h2>
                        <p class="caption">Actualizá rol, comisión o acceso por persona.</p>
                    </div>
                </div>
                <span class="tag users-count"><?= count($users) ?> total</span>
            </div>

            <?php if (empty($users)): ?>
                <div class="empty users-empty"><?= ui_icon('users') ?><p class="empty__title">Todavía no hay usuarios registrados</p></div>
            <?php else: ?>
                <div class="users-list">
                    <?php foreach ($users as $u): ?>
                    <?php
                        $inactive = !$u['is_active'];
                        $isCurrentUser = (int)$u['id'] === (int)$_SESSION['user_id'];
                        $userName = htmlspecialchars($u['name']);
                        $roleLabel = $roleLabels[$u['role']] ?? ucfirst($u['role']);
                    ?>
                    <article class="user-record <?= $inactive ? 'is-inactive' : '' ?>">
                        <div class="user-record__identity">
                            <?= ui_avatar($u['name'], 'avatar--sm') ?>
                            <div class="user-record__body">
                                <div class="user-record__name-row">
                                    <h3 class="headline"><?= $userName ?></h3>
                                    <?php if ($isCurrentUser): ?>
                                        <span class="tag tag--indigo">Vos</span>
                                    <?php elseif ($inactive): ?>
                                        <span class="tag users-tag--inactive">Inactivo</span>
                                    <?php endif; ?>
                                </div>
                                <p class="caption">DNI <?= htmlspecialchars($u['username']) ?><?= !empty($u['phone']) ? ' · ' . htmlspecialchars($u['phone']) : '' ?></p>
                            </div>
                            <span class="tag user-record__role"><?= htmlspecialchars($roleLabel) ?></span>
                        </div>

                        <?php if ($isCurrentUser): ?>
                            <p class="user-record__locked"><?= ui_icon('lock-keyhole') ?> Tu rol y acceso no se pueden cambiar desde esta pantalla.</p>
                        <?php else: ?>
                            <div class="user-record__controls">
                                <form method="POST" class="user-quick-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="update_role">
                                    <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                    <label class="user-quick-form__field">
                                        <span>Rol</span>
                                        <select name="new_role" class="select">
                                            <?php foreach ($roleLabels as $roleKey => $optionLabel): ?>
                                                <option value="<?= $roleKey ?>" <?= $u['role'] === $roleKey ? 'selected' : '' ?>><?= $optionLabel ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                    <button type="submit" class="btn btn--sm" title="Guardar rol"><?= ui_icon('check') ?> Guardar</button>
                                </form>

                                <form method="POST" class="user-quick-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="update_commission">
                                    <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                    <label class="user-quick-form__field user-quick-form__commission">
                                        <span>Comisión</span>
                                        <div class="users-percent-input">
                                            <input type="number" name="commission_rate" value="<?= number_format((float)($u['commission_rate'] ?? 5), 1, '.', '') ?>" min="0" max="100" step="0.5" class="input" inputmode="decimal">
                                            <span aria-hidden="true">%</span>
                                        </div>
                                    </label>
                                    <button type="submit" class="btn btn--sm" title="Guardar comisión"><?= ui_icon('check') ?> Guardar</button>
                                </form>

                                <form method="POST" class="user-record__toggle" onsubmit="return confirm('<?= $inactive ? '¿Activar a ' : '¿Desactivar a ' ?><?= addslashes($userName) ?>?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle_active">
                                    <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                    <button type="submit" class="btn btn--sm <?= $inactive ? 'btn--success' : 'btn--danger' ?>">
                                        <?= ui_icon($inactive ? 'user-check' : 'user-x') ?> <?= $inactive ? 'Activar' : 'Desactivar' ?>
                                    </button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>
</main>

<?php include dirname(__DIR__, 2) . '/includes/footer.php'; ?>
