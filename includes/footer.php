<?php
/**
 * Archivo: includes/footer.php  (rediseño 2026)
 * Cierra el layout abierto en header.php y agrega la barra inferior + botón flotante (solo celular).
 * Usa $nav y $current_page definidos en header.php.
 */
// El botón flotante solo aparece si "Nueva" no está ya en la barra inferior
$show_fab = ($current_page ?? '') !== 'cargar_venta.php' && !isset($nav['tabbar']['nueva']);
?>
        <footer class="only-desktop" style="margin-top:auto;padding:24px var(--margen-lateral);border-top:1px solid var(--separator)">
            <p class="caption" style="margin:0;text-align:center">
                &copy; <?= date('Y') ?> CRM Imperio · Versión 2.0 · Desarrollado por Alejandro Quevedo
            </p>
        </footer>
    </div><!-- /.app-content -->
</div><!-- /.app-shell -->

<?php if ($show_fab): ?>
<a href="<?= ASSET_BASE ?>cargar_venta.php" class="fab" aria-label="Nueva venta">
    <?= ui_icon('plus') ?>
</a>
<?php endif; ?>

<nav class="tabbar" aria-label="Secciones">
    <?php foreach ($nav['tabbar'] as $key => $item):
        $active = ui_is_active($item['href'])
            || ($key === 'mas' && in_array($current_page, ['mas.php', 'lista_vendedores.php', 'register_user.php', 'admin_audit.php', 'perfil_vendedor.php'], true) && !isset($nav['tabbar']['mis_ventas']));
    ?>
    <a href="<?= ASSET_BASE . htmlspecialchars($item['href']) ?>"
       class="tabbar__item <?= $active ? 'is-active' : '' ?>"
       <?= $active ? 'aria-current="page"' : '' ?>>
        <?= ui_icon($item['icon']) ?>
        <span class="tabbar__label"><?= htmlspecialchars($item['short'] ?? $item['label']) ?></span>
        <?php if (!empty($item['badge'])): ?>
            <span class="badge"><?= $item['badge'] > 99 ? '99+' : (int)$item['badge'] ?></span>
        <?php endif; ?>
    </a>
    <?php endforeach; ?>
</nav>

<script>
    lucide.createIcons();
</script>
</body>
</html>
