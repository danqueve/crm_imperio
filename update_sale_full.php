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

    $sale_id = $_POST['sale_id'];

    // 2. CAPTURA DE DATOS - SECCIÓN CLIENTE (Evitando NULL para PHP 8.1+)
    $client_name         = trim($_POST['client_name'] ?? '');
    $client_dni          = trim($_POST['client_dni'] ?? '');
    $client_whatsapp     = trim($_POST['client_whatsapp'] ?? '');
    $client_phone        = trim($_POST['client_phone'] ?? ''); // Nro Llamada Opcional
    $client_address      = trim($_POST['client_address'] ?? '');
    $client_neighborhood = trim($_POST['client_neighborhood'] ?? '');
    $client_locality     = trim($_POST['client_locality'] ?? '');
    $client_map_link    = trim($_POST['client_map_link'] ?? '');

    // 3. CAPTURA DE DATOS - SECCIÓN LABORAL
    $job_type            = trim($_POST['job_type'] ?? '');
    $job_occupation      = trim($_POST['job_occupation'] ?? '');
    $job_name            = trim($_POST['job_name'] ?? '');
    $job_address         = trim($_POST['job_address'] ?? ''); // Dirección laboral

    // 4. CAPTURA DE DATOS - PLAN DE PAGO
    $item                = trim($_POST['item'] ?? '');
    $payment_frequency   = $_POST['payment_frequency'] ?? 'semanal';
    $payment_day         = $_POST['payment_day'] ?? '';
    $installments        = (int)($_POST['installments'] ?? 0);
    $amount              = (float)($_POST['amount'] ?? 0);
    $total               = $installments * $amount;

    try {
        // SQL de actualización con todos los campos disponibles en la base de datos
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
                    total_amount = ? 
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
            $sale_id
        ]);

        // Redirigir de vuelta a la ficha con mensaje de éxito
        header("Location: ver_ficha.php?id=$sale_id&msg=updated");
        exit;

    } catch (Exception $e) {
        // En caso de error, mostramos el detalle técnico para corregir la DB
        die("Error crítico al actualizar la ficha: " . $e->getMessage());
    }
} else {
    // Protección contra acceso directo sin POST
    header("Location: dashboard.php");
    exit;
}