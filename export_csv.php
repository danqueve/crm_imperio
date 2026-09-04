<?php
require 'includes/db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$role    = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

$start  = $_GET['start'] ?? date('Y-m-01');
$end    = $_GET['end']   ?? date('Y-m-d');
$search = $_GET['search'] ?? '';

$startSql = $start . ' 00:00:00';
$endSql   = $end   . ' 23:59:59';

$whereClause = "WHERE s.status IN ('rechazado', 'entregado')
                AND s.created_at BETWEEN :start AND :end";
$params = [':start' => $startSql, ':end' => $endSql];

if ($role === 'vendedor' || $role === 'entregador') {
    $whereClause .= " AND s.user_id = :user_id";
    $params[':user_id'] = $user_id;
}

if (!empty($search)) {
    $whereClause .= " AND (s.client_name LIKE :search OR s.client_dni LIKE :search)";
    $params[':search'] = "%$search%";
}

$sql = "SELECT
            s.created_at, s.client_name, s.client_dni, s.client_address,
            s.client_locality, s.client_whatsapp, s.item,
            s.installments_count, s.installment_amount, s.total_amount,
            s.down_payment, s.payment_frequency, s.status,
            s.delivered_at, s.rejected_type, s.rejected_reason,
            u_seller.name AS vendedor,
            u_del.name    AS entregado_por,
            u_rej.name    AS rechazado_por
        FROM sales s
        LEFT JOIN users u_seller ON s.user_id      = u_seller.id
        LEFT JOIN users u_del    ON s.delivered_by = u_del.id
        LEFT JOIN users u_rej    ON s.rejected_by  = u_rej.id
        $whereClause
        ORDER BY s.created_at DESC";

$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$filename = 'historial_ventas_' . $start . '_' . $end . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');

// BOM para compatibilidad con Excel
fwrite($output, "\xEF\xBB\xBF");

fputcsv($output, [
    'Fecha Carga', 'Cliente', 'DNI', 'Direccion', 'Localidad', 'WhatsApp',
    'Articulo', 'Cuotas', 'Monto Cuota', 'Total', 'Entrada',
    'Frecuencia', 'Estado', 'Fecha Entrega', 'Tipo Rechazo', 'Motivo Rechazo',
    'Vendedor', 'Entregado por', 'Rechazado por'
]);

foreach ($rows as $row) {
    fputcsv($output, [
        $row['created_at'] ? date('d/m/Y', strtotime($row['created_at'])) : '',
        $row['client_name'],
        $row['client_dni'],
        $row['client_address'],
        $row['client_locality'],
        $row['client_whatsapp'],
        $row['item'],
        $row['installments_count'],
        number_format((float)$row['installment_amount'], 2, '.', ''),
        number_format((float)$row['total_amount'], 2, '.', ''),
        number_format((float)$row['down_payment'], 2, '.', ''),
        $row['payment_frequency'],
        $row['status'],
        $row['delivered_at'] ? date('d/m/Y', strtotime($row['delivered_at'])) : '',
        $row['status'] === 'rechazado' ? rejection_type_label($row['rejected_type'] ?? null) : '',
        $row['rejected_reason'] ?? '',
        $row['vendedor'] ?? '',
        $row['entregado_por'] ?? '',
        $row['rechazado_por'] ?? '',
    ]);
}

fclose($output);
exit;
