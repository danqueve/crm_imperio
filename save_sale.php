<?php
require 'includes/db.php';

/**
 * Archivo: save_sale.php
 * Propósito: Procesar el formulario de carga de venta del Canvas, guardar en BD y gestionar archivos múltiples con redirección de errores.
 */

// 1. SEGURIDAD: Verificar sesión y roles permitidos
$allowed_roles = ['vendedor', 'admin', 'supervisor', 'verificador'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    die("Acceso denegado. No tiene permisos para realizar esta acción.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $user_id = $_SESSION['user_id'];

    // 2. CAPTURA DE DATOS - SECCIÓN CLIENTE
    $client_dni          = trim($_POST['client_dni'] ?? '');
    $client_name         = trim($_POST['client_name'] ?? '');
    $client_address      = trim($_POST['client_address'] ?? '');
    $client_neighborhood = trim($_POST['client_neighborhood'] ?? '');
    $client_locality     = trim($_POST['client_locality'] ?? '');
    $client_whatsapp     = trim($_POST['client_whatsapp'] ?? '');
    $client_phone        = trim($_POST['client_phone'] ?? '');
    $client_map_link    = trim($_POST['client_map_link'] ?? '');

    // 3. CAPTURA DE DATOS - SECCIÓN LABORAL
    $job_type            = trim($_POST['job_type'] ?? '');
    $job_occupation      = trim($_POST['job_occupation'] ?? '');
    $job_name            = trim($_POST['job_name'] ?? '');
    $job_address         = trim($_POST['job_address'] ?? '');

    // 4. CAPTURA DE DATOS - SECCIÓN VENTA
    $item                = trim($_POST['item'] ?? '');
    $payment_day         = $_POST['payment_day'] ?? null;
    $installments_count  = (int)($_POST['installments_count'] ?? 0);
    $installment_amount  = (float)($_POST['installment_amount'] ?? 0);
    $payment_frequency   = $_POST['payment_frequency'] ?? 'semanal';
    $down_payment        = (float)($_POST['down_payment'] ?? 0);
    
    // El total se toma del input hidden/readonly o se recalcula por seguridad
    $total_amount        = (float)($_POST['total_amount'] ?? ($installments_count * $installment_amount));
    $observations        = trim($_POST['observations'] ?? '');

    // --- VALIDACIÓN DE DATOS OBLIGATORIOS ---
    $errors = [];
    if (empty($client_dni)) $errors[] = "dni";
    if (empty($client_name)) $errors[] = "nombre";
    if (empty($item)) $errors[] = "articulo";
    if ($total_amount <= 0) $errors[] = "monto";

    // Si hay errores, redirigir con los códigos de error
    if (!empty($errors)) {
        $error_string = implode(',', $errors);
        header("Location: cargar_venta.php?error=missing_data&fields=" . $error_string);
        exit;
    }

    try {
        // INICIO DE TRANSACCIÓN: Asegura integridad referencial entre la venta y sus archivos
        $pdo->beginTransaction();

        // 5. INSERTAR VENTA EN LA TABLA 'sales'
        $sql = "INSERT INTO sales (
                    user_id, client_dni, client_name, client_address, client_neighborhood, 
                    client_locality, client_whatsapp, client_phone, client_map_link,
                    job_type, job_occupation, job_name, job_address,
                    item, installments_count, installment_amount, total_amount, 
                    payment_day, payment_frequency, down_payment, observations, 
                    status, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?, 
                    ?, ?, ?, ?, 
                    ?, ?, ?, ?, 
                    ?, ?, ?, ?, 
                    ?, ?, ?, ?, 
                    'revision', NOW()
                )";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $user_id, $client_dni, $client_name, $client_address, $client_neighborhood,
            $client_locality, $client_whatsapp, $client_phone, $client_map_link,
            $job_type, $job_occupation, $job_name, $job_address,
            $item, $installments_count, $installment_amount, $total_amount,
            $payment_day, $payment_frequency, $down_payment, $observations
        ]);

        $sale_id = $pdo->lastInsertId();

        // 6. PROCESAR ARCHIVOS ADJUNTOS (Múltiples)
        if (isset($_FILES['sale_files']) && !empty($_FILES['sale_files']['name'][0])) {
            $upload_dir = 'uploads/';

            // Crear carpeta si no existe
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }

            $files               = $_FILES['sale_files'];
            $allowed_extensions  = ['jpg', 'jpeg', 'png', 'pdf'];
            $allowed_mime_types  = ['image/jpeg', 'image/png', 'application/pdf'];
            $max_file_size       = 5 * 1024 * 1024; // 5 MB por archivo

            for ($i = 0; $i < count($files['name']); $i++) {
                if ($files['error'][$i] !== UPLOAD_ERR_OK) {
                    continue;
                }

                $tmp_name      = $files['tmp_name'][$i];
                $original_name = $files['name'][$i];
                $extension     = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));

                // Validar tamaño
                if ($files['size'][$i] > $max_file_size) {
                    continue;
                }

                // Validar MIME real del archivo (no confiar solo en la extensión)
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime  = finfo_file($finfo, $tmp_name);
                finfo_close($finfo);

                if (!in_array($mime, $allowed_mime_types)) {
                    continue;
                }

                // Validar extensión (doble control)
                if (!in_array($extension, $allowed_extensions)) {
                    continue;
                }

                // Generar nombre único para el servidor
                $new_file_name = 'sale_' . $sale_id . '_' . time() . '_' . $i . '.' . $extension;
                $dest_path     = $upload_dir . $new_file_name;

                if (move_uploaded_file($tmp_name, $dest_path)) {
                    // Registrar cada archivo en la tabla auxiliar 'sale_files'
                    $sql_file = "INSERT INTO sale_files (sale_id, file_path) VALUES (?, ?)";
                    $pdo->prepare($sql_file)->execute([$sale_id, $new_file_name]);
                }
            }
        }

        // 7. FINALIZAR OPERACIÓN
        $pdo->commit();
        log_audit($pdo, 'create_sale', 'sale', $sale_id, "Venta creada: $item - $client_name");

        // Redirigir al panel con mensaje de éxito
        header("Location: dashboard.php?msg=saved");
        exit;

    } catch (Exception $e) {
        // En caso de error, revertir cambios en la base de datos
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // Enviar el error a cargar_venta para debugging
        header("Location: cargar_venta.php?error=db_error&msg=" . urlencode($e->getMessage()));
        exit;
    }
} else {
    // Protección contra acceso directo
    header("Location: cargar_venta.php");
    exit;
}