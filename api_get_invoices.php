<?php
// API para buscar faturas (JSON)
require_once 'config_helper.php';

header('Content-Type: application/json');

$q = trim($_GET['q'] ?? '');
$amount = floatval($_GET['amount'] ?? 0);
$date = trim($_GET['date'] ?? '');

$invoices = [];

if (!empty($q) || $amount > 0) {
    $dateParam = !empty($date) ? $date : date('Y-m-d');
    
    $stmt = $pdo->prepare("
        SELECT id, supplier_name, document_number, total, document_date,
            CASE WHEN ABS(total - ?) < 0.01 AND ABS(DATEDIFF(document_date, ?)) <= 3 THEN 1 ELSE 0 END AS exact_match
        FROM invoices
        WHERE (ABS(total - ?) < 0.01 AND ABS(DATEDIFF(document_date, ?)) <= 3)
        OR (supplier_name LIKE ? OR document_number LIKE ?)
        ORDER BY 
            exact_match DESC,
            ABS(DATEDIFF(document_date, ?))
        LIMIT 10
    ");
    $stmt->execute([$amount, $dateParam, $amount, $dateParam, "%$q%", "%$q%", $dateParam]);
    $invoices = $stmt->fetchAll();
}

echo json_encode($invoices);
?>
