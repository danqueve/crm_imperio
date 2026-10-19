<?php
/**
 * Archivo: mas.php  (NUEVO — rediseño 2026)
 * Menú "Más": secciones a las que el rol tiene acceso que no caben en la barra inferior,
 * más cambio de contraseña y cierre de sesión. Pensado sobre todo para celular
 * (en PC esas mismas secciones ya están en la barra lateral), pero debe verse bien en ambos.
 */
require 'includes/db.php';

if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }

$user_id = $_SESSION['user_id'];
$role    = $_SESSION['role'];
$name    = $_SESSION['name'];

include 'includes/header.php';
?>
<main class="app-main">
    <div class="page-head" style="margin-bottom:20px">
        <div class="page-head__text">
            <h1 class="large-title">Más</h1>
        </div>
    </div>

    <div style="display:flex;flex-direction:column;gap:20px;max-width:480px">

        <a href="<?= ASSET_BASE ?>perfil_vendedor.php?id=<?= (int)$user_id ?>" class="card card--pad" style="display:flex;align-items:center;gap:14px;text-decoration:none;color:inherit">
            <?= ui_avatar($name, 'lg') ?>
            <span style="flex:1;min-width:0;display:flex;flex-direction:column;gap:2px">
                <span class="cell__title"><?= htmlspecialchars($name) ?></span>
                <span class="cell__sub"><?= htmlspecialchars($role_labels[$role] ?? $role) ?> · ver mis ventas</span>
            </span>
            <span class="cell__chevron"><?= ui_icon('chevron-right') ?></span>
        </a>

        <?php foreach ($nav['groups'] as $group):
            // Solo lo que el rol puede ver y que no está ya en la barra inferior
            // (mis_ventas ya se muestra arriba, en la tarjeta de perfil)
            $extra = $group['items'];
            unset($extra['mis_ventas']);
            foreach (array_keys($extra) as $key) {
                if (isset($nav['tabbar'][$key])) unset($extra[$key]);
            }
            if (!$extra) continue;
        ?>
        <section class="group">
            <?php if ($group['title']): ?>
                <h2 class="group__title"><?= htmlspecialchars($group['title']) ?></h2>
            <?php endif; ?>
            <div class="group__body">
                <?php foreach ($extra as $item): ?>
                <a href="<?= ASSET_BASE . htmlspecialchars($item['href']) ?>" class="cell">
                    <span class="icon-box icon-box--solid <?= ui_avatar_class($item['label']) ?>"><?= ui_icon($item['icon']) ?></span>
                    <span class="cell__body"><span class="cell__title"><?= htmlspecialchars($item['label']) ?></span></span>
                    <span class="cell__chevron"><?= ui_icon('chevron-right') ?></span>
                </a>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endforeach; ?>

        <section class="group">
            <h2 class="group__title">Cuenta</h2>
            <div class="group__body">
                <a href="<?= ASSET_BASE ?>perfil_vendedor.php?id=<?= (int)$user_id ?>" class="cell">
                    <span class="icon-box icon-box--solid avatar--2"><?= ui_icon('lock') ?></span>
                    <span class="cell__body"><span class="cell__title">Cambiar contraseña</span></span>
                    <span class="cell__chevron"><?= ui_icon('chevron-right') ?></span>
                </a>
                <a href="<?= ASSET_BASE ?>logout.php" class="cell">
                    <span class="icon-box icon-box--solid avatar--5"><?= ui_icon('log-out') ?></span>
                    <span class="cell__body"><span class="cell__title">Cerrar sesión</span></span>
                </a>
            </div>
        </section>

        <p class="caption" style="text-align:center">CRM Imperio · Imperio Comercial</p>
    </div>
</main>

<?php include 'includes/footer.php'; ?>
