<?php
session_name('CRMIMPERIO'); // mismo nombre de sesión que includes/db.php
session_start();
session_destroy();
header("Location: index.php");
exit;
?>
