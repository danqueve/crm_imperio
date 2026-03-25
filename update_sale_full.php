<?php
require 'includes/db.php';

/**
 * Archivo: update_sale_full.php
 * Propósito: Procesar la edición integral de todos los campos de una venta desde ver_ficha.php.
 */

// 1. SEGURIDAD: Solo Admin, Supervisor o Verificador pueden editar
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'supervisor', 'verificador'])) {
    header("Location: dashboard.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $sale_id = (int)($_POST['sale_id'] ?? 0);

    // 2. CAPTURA DE DATOS - SECCIÓN CLIENTE
    $client_name         = trim($_POST['client_name'] ?? '');
    $client_dni          = trim($_POST['client_dni'] ?? '');
    $client_whatsapp     = trim($_POST['client_whatsapp'] ?? '');
    $client_phone        = trim($_POST['client_phone'] ?? '');
    $client_address      = trim($_POST['client_address'] ?? '');
    $client_neighborhood = trim($_POST['client_neighborhood'] ?? '');
    $client_locality     = trim($_POST['client_locality'] ?? '');
    $client_map_link     = trim($_POST['client_map_link'] ?? '');

    // 3. CAPTURA DE DATOS - SECCIÓN LABORAL
    $job_type            = trim($_POST['job_type'] ?? '');
    $job_occupation      = trim($_POST['job_occupation'] ?? '');
    $job_name            = trim($_POST['job_name'] ?? '');
    $job_address         = trim($_POST['job_address'] ?? '');

    // 4. CAPTURA DE DATOS - PLAN DE PAGO
    $item                = trim($_POST['item'] ?? '');
    $payment_frequency   = $_POST['payment_frequency'] ?? 'semanal';
    $payment_day         = $_POST['payment_day'] ?? '';
    $installments        = (int)($_POST['installments'] ?? 0);
    $amount              = (float)($_POST['amount'] ?? 0);
    $total               = $installments * $amount;
    $down_payment        = (float)($_POST['down_payment'] ?? 0);

    try {
        $pdo->beginTransaction();

        // SQL de actualización con todos los campos
        $sql = "UPDATE sales SET
                    client_name = ?,
                    client_dni = ?,
                    client_whatsapp = ?,
                    client_phone = ?,
                    client_address = ?,
                    client_neighborhood = ?,
                    client_locality = ?,
                    client_map_link = ?,
                    job_type = ?,
                    job_occupation = ?,
                    job_name = ?,
                    job_address = ?,
                    item = ?,
                    payment_frequency = ?,
                    payment_day = ?,
                    installments_count = ?,
                    installment_amount = ?,
                    total_amount = ?,
                    down_payment = ?
                WHERE id = ?";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $client_name,
            $client_dni,
            $client_whatsapp,
            $client_phone,
            $client_address,
            $client_neighborhood,
            $client_locality,
            $client_map_link,
            $job_type,
            $job_occupation,
            $job_name,
            $job_address,
            $item,
            $payment_frequency,
            $payment_day,
            $installments,
            $amount,
            $total,
            $down_payment,
            $sale_id
        ]);

        $pdo->commit();

        // 5. MANEJO DE ARCHIVOS NUEVOS (post-commit, como en save_sale.php)
        if (isset($_FILES['sale_files']) && !empty($_FILES['sale_files']['name'][0])) {
            $upload_dir         = __DIR__ . '/uploads/';
            $files              = $_FILES['sale_files'];
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'pdf'];
            $allowed_mimes      = ['image/jpeg', 'image/png', 'application/pdf'];
            $max_size           = 5 * 1024 * 1024;

            for ($i = 0; $i < count($files['name']); $i++) {
                if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;

                $tmp_name  = $files['tmp_name'][$i];
                $extension = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));

                if ($files['size'][$i] > $max_size) continue;

                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime  = finfo_file($finfo, $tmp_name);
                finfo_close($finfo);

                if (!in_array($mime, $allowed_mimes)) continue;
                if (!in_array($extension, $allowed_extensions)) continue;

                $new_name = 'sale_' . $sale_id . '_' . time() . '_' . $i . '.' . $extension;
                if (move_uploaded_file($tmp_name, $upload_dir . $new_name)) {
                    $pdo->prepare("INSERT INTO sale_files (sale_id, file_path) VALUES (?, ?)")
                        ->execute([$sale_id, $new_name]);
                }
            }
        }

        log_audit($pdo, 'edit_sale', 'sale', $sale_id, "Ficha editada: cliente {$client_name}");

        header("Location: ver_ficha.php?id=$sale_id&msg=updated");
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("update_sale_full [sale_id={$sale_id}]: " . $e->getMessage());
        header("Location: ver_ficha.php?id={$sale_id}&msg=error");
        exit;
    }
} else {
    // Protección contra acceso directo sin POST
    header("Location: dashboard.php");
    exit;
}