<?php
/*
 * Archivo: includes/db.php
 * Propósito: Establecer la conexión con la base de datos MySQL y arrancar la sesión.
 */

// Cargar credenciales desde archivo de configuración (no versionado)
require_once dirname(__DIR__) . '/config.php';

try {
    // Crear instancia PDO
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);

    // Configurar PDO para que lance excepciones en caso de error
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Configurar el modo de fetch por defecto a array asociativo
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

} catch(PDOException $e) {
    // No exponer detalles del error en producción
    die("Error de conexión con la base de datos. Contacte al administrador.");
}

// Iniciar la sesión PHP si no está iniciada ya
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Cargar funciones auxiliares (incluye helpers CSRF)
require_once __DIR__ . '/functions.php';

// Timeout de sesión: 30 minutos de inactividad
define('SESSION_TIMEOUT', 1800);
if (isset($_SESSION['user_id'])) {
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT)) {
        session_unset();
        session_destroy();
        session_start();
        header("Location: index.php?msg=timeout");
        exit;
    }
    $_SESSION['last_activity'] = time();
}
?>