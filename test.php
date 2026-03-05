<?php
echo "<h1>Diagnóstico del Servidor</h1>";

// 1. Verificar Versión de PHP
echo "<p><strong>Versión de PHP:</strong> " . phpversion() . "</p>";
if (version_compare(phpversion(), '7.4', '<')) {
    echo "<p style='color:red'>❌ ALERTA: Tu versión de PHP es muy antigua. Necesitas PHP 7.4 o superior.</p>";
} else {
    echo "<p style='color:green'>✅ Versión de PHP correcta.</p>";
}

// 2. Verificar Conexión a BD
echo "<p><strong>Probando conexión a base de datos...</strong></p>";

if (file_exists('includes/db.php')) {
    try {
        require 'includes/db.php';
        if (isset($pdo)) {
            echo "<p style='color:green'>✅ Conexión a la Base de Datos EXITOSA.</p>";
        } else {
            echo "<p style='color:red'>❌ El archivo db.php se cargó pero no creó la conexión (\$pdo).</p>";
        }
    } catch (Throwable $e) {
        echo "<p style='color:red'>❌ Error Fatal al conectar: " . $e->getMessage() . "</p>";
    }
} else {
    echo "<p style='color:red'>❌ No se encuentra el archivo 'includes/db.php'. Verifica la carpeta 'includes'.</p>";
}

echo "<hr>";
echo "<p>Si ves esto, el servidor web funciona. Si al entrar a index.php ves Error 500, revisa el archivo error_log en tu hosting.</p>";
?>