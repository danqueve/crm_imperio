<?php
/*
 * Archivo: includes/db.php
 * Propósito: Establecer la conexión con la base de datos MySQL y arrancar la sesión.
 */

// Cargar credenciales desde archivo de configuración (no versionado)
require_once dirname(__DIR__) . '/config/database.php';

// Base URL del proyecto — calculada desde la ubicación real del archivo en disco.
// Funciona en raíz (/), subdirectorio (/crm/) o subdirectorio anidado (/apps/crm/).
if (!defined('ASSET_BASE')) {
    $__docRoot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '/');
    $__appRoot = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');
    if ($__appRoot === $__docRoot) {
        // Caso VPS: app en la raíz del dominio → ASSET_BASE = '/'
        define('ASSET_BASE', '/');
    } else {
        $__rel = trim(str_replace($__docRoot, '', $__appRoot), '/');
        define('ASSET_BASE', '/' . $__rel . '/');
    }
    unset($__docRoot, $__appRoot, $__rel);
}

try {
    // Crear instancia PDO
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);

    // Configurar PDO para que lance excepciones en caso de error
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Configurar el modo de fetch por defecto a array asociativo
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Zona horaria fija: Tucumán, Argentina (UTC-3, sin horario de verano). Sin esto PHP corría en UTC
    // mientras MySQL devolvía NOW() en hora de Argentina: pasadas las 21 hs, "Hoy" quedaba vacío en el
    // panel y el objetivo mensual se reiniciaba la última noche del mes.
    date_default_timezone_set('America/Argentina/Tucuman');
    $pdo->exec("SET time_zone = '-03:00'"); // offset fijo: no depende de que el servidor tenga cargadas las tablas de zonas horarias de MySQL

} catch(PDOException $e) {
    // No exponer detalles del error en producción
    die("Error de conexión con la base de datos. Contacte al administrador.");
}

// Headers de seguridad HTTP
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

// Iniciar la sesión PHP si no está iniciada ya.
// Cookie propia del CRM: con el PHPSESSID por defecto, otra app del mismo dominio que guarde
// $_SESSION['user_id'] compartiría la sesión y el CRM la tomaría como un usuario propio.
if (session_status() == PHP_SESSION_NONE) {
    session_name('CRMIMPERIO');
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

    // El rol, el nombre y el estado se guardan en la sesión al ingresar; se vuelven a leer en cada
    // pedido para que un cambio de rol o una baja hecha desde Usuarios rija de inmediato
    // (sin esto el usuario conservaba su rol anterior hasta volver a ingresar).
    $stmtSessionUser = $pdo->prepare("SELECT name, role, is_active FROM users WHERE id = ?");
    $stmtSessionUser->execute([$_SESSION['user_id']]);
    $sessionUser = $stmtSessionUser->fetch();
    if (!$sessionUser || !$sessionUser['is_active']) {
        session_unset();
        session_destroy();
        session_start();
        header("Location: index.php?msg=timeout");
        exit;
    }
    $_SESSION['role'] = $sessionUser['role'];
    $_SESSION['name'] = $sessionUser['name'];
}
?>
