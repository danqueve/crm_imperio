<?php
require 'includes/db.php';

/**
 * Archivo: update_sale_full.php
 * Propósito: Procesar la edición integral de todos los campos de una venta desde ver_ficha.php.
 */

// 1. SEGURIDAD: Gestores siempre. Vendedor solo si es dueño y la venta está en 'revision'.
$role    = $_SESSION['role'] ?? '';
$user_id = (int)($_SESSION['user_id'] ?? 0);

$allowed_manager = in_array($role, ['admin', 'supervisor', 'verificador']);
$is_vendedor     = ($role === 'vendedor');

if (!$allowed_manager && !$is_vendedor) {
    header("Location: dashboard.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $sale_id = (int)($_POST['sale_id'] ?? 0);

    // Verificación adicional para vendedor: ownership + estado revision
    if ($is_vendedor) {
        $chk = $pdo->prepare("SELECT id FROM sales WHERE id = ? AND user_id = ? AND status = 'revision'");
        $chk->execute([$sale_id, $user_id]);
        if (!$chk->fetch()) {
            header("Location: dashboard.php");
            exit;
        }
    }

    // 2. CAPTURA DE DATOS - SECCIÓN CLIENTE
    $client_name         = trim($_POST['client_name'] ?? '');
    $client_dni          = trim($_POST['client_dni'] ?? '');
    $client_whatsapp     = trim($_POST['client_whatsapp'] ?? '');
    $client_phone        = trim($_POST['client_phone'] ?? '');
    $client_address      = trim($_POST['client_address'] ?? '');
    $client_neighborhood = trim($_POST['client_neighborhood'] ?? '');
    $client_locality     = trim($_POST['client_locality'] ?? '');
    $client_map_link     = trim($_POST['client_map_link'] ?? '');

    // Validar URL del mapa (si se proporcionó)
    if (!empty($client_map_link)) {
        $parsed_url = parse_url($client_map_link);
        if (!$parsed_url || !in_array($parsed_url['scheme'] ?? '', ['http', 'https'])) {
            $client_map_link = '';
        }
    }

    // Las ventas de contado no exigen teléfono alternativo (nunca se pidió al cargarlas)
    $saleTypeStmt = $pdo->prepare("SELECT sale_type FROM sales WHERE id = ?");
    $saleTypeStmt->execute([$sale_id]);
    $is_contado = $saleTypeStmt->fetchColumn() === 'contado';

    // Normalizar WhatsApp y teléfono antes de validar: quitar todo lo que no sea dígito
    // (espacios, +, guiones, puntos, nbsp, etc. que suelen venir al pegar un número copiado)
    if (!empty($client_whatsapp)) $client_whatsapp = preg_replace('/\D/', '', $client_whatsapp);
    if (!empty($client_phone))    $client_phone    = preg_replace('/\D/', '', $client_phone);

    // Validar campos obligatorios y formato (defensa en profundidad además del required del HTML)
    $errors = [];
    if (!$is_contado && empty($client_phone)) $errors[] = 'telefono';
    if (empty($client_map_link)) $errors[] = 'maps';
    if (!empty($client_whatsapp) && !preg_match('/^\d{7,15}$/', $client_whatsapp)) $errors[] = 'whatsapp_formato';
    if (!empty($client_phone) && !preg_match('/^\d{7,15}$/', $client_phone)) $errors[] = 'telefono_formato';

    if (!empty($errors)) {
        $error_string = implode(',', $errors);
        $redirect = $is_vendedor
            ? "editar_venta.php?id={$sale_id}&msg=error&fields={$error_string}"
            : "ver_ficha.php?id={$sale_id}&msg=error&fields={$error_string}";
        header("Location: $redirect");
        exit;
    }

    // 3. CAPTURA DE DATOS - SECCIÓN LABORAL
    $job_type            = trim($_POST['job_type'] ?? '');
    $job_occupation      = trim($_POST['job_occupation'] ?? '');
    $job_name            = trim($_POST['job_name'] ?? '');
    $job_address         = trim($_POST['job_address'] ?? '');

    // Validar formato DNI (defensa en profundidad)
    if (!empty($client_dni) && !preg_match('/^\d{7,8}$/', $client_dni)) {
        $client_dni = preg_replace('/\D/', '', $client_dni);
    }

    // 4. CAPTURA DE DATOS - PLAN DE PAGO
    $item                = trim($_POST['item'] ?? '');
    // Método de pago (solo aplica a contado): Normal o RapiCompra (comisión fija 6%).
    // Se fuerza 'normal' para crédito sin importar lo enviado, igual criterio que save_sale.php.
    $payment_method      = ($is_contado && ($_POST['payment_method'] ?? '') === 'rapicompra') ? 'rapicompra' : 'normal';
    $payment_frequency   = $_POST['payment_frequency'] ?? 'semanal';
    $payment_day         = $_POST['payment_day'] ?? '';
    $installments        = (int)($_POST['installments'] ?? 0);
    $amount              = (float)($_POST['amount'] ?? 0);
    $total               = $installments * $amount;
    $down_payment        = max(0, (float)($_POST['down_payment'] ?? 0));
    $observations        = trim($_POST['observations'] ?? '');

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
                    payment_method = ?,
                    payment_frequency = ?,
                    payment_day = ?,
                    installments_count = ?,
                    installment_amount = ?,
                    total_amount = ?,
                    down_payment = ?,
                    observations = ?
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
            $payment_method,
            $payment_frequency,
            $payment_day,
            $installments,
            $amount,
            $total,
            $down_payment,
            $observations,
            $sale_id
        ]);

        $pdo->commit();

        // 5. MANEJO DE ARCHIVOS NUEVOS (post-commit, como en save_sale.php)
        if (isset($_FILES['sale_files']) && !empty($_FILES['sale_files']['name'][0])) {
            $upload_dir         = __DIR__ . '/uploads/';
            $files              = $_FILES['sale_files'];
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'pdf', 'jfif'];
            $allowed_mimes      = ['image/jpeg', 'image/png', 'application/pdf'];
            $max_size           = 5 * 1024 * 1024;

            for ($i = 0; $i < count($files['name']); $i++) {
                if ($files['error'][$i] !== UPLOAD_ERR_OK) {
                    if ($files['error'][$i] === UPLOAD_ERR_INI_SIZE || $files['error'][$i] === UPLOAD_ERR_FORM_SIZE) {
                        error_log("update_sale_full: Archivo excedió limite de tamaño: " . $files['name'][$i]);
                    }
                    continue;
                }

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
        $redirect = $is_vendedor ? "editar_venta.php?id={$sale_id}&msg=error" : "ver_ficha.php?id={$sale_id}&msg=error";
        header("Location: $redirect");
        exit;
    }
} else {
    // Protección contra acceso directo sin POST
    header("Location: dashboard.php");
    exit;
}