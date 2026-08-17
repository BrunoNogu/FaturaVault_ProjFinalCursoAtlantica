<?php
// Eliminação de uma fatura - apaga o registo da BD e o ficheiro PDF
require_once 'db.php';

if (!isset($_GET['id'])) {
    header('Location: index.php');
    exit;
}

$id = intval($_GET['id']);

// Procura o caminho do PDF antes de apagar
$stmt = $pdo->prepare("SELECT file_path FROM invoices WHERE id = ?");
$stmt->execute([$id]);
$invoice = $stmt->fetch();

if ($invoice) {
    // Apaga o ficheiro PDF do disco
    $filePath = __DIR__ . '/uploads/' . $invoice['file_path'];
    if (file_exists($filePath)) {
        unlink($filePath);
    }
    // Apaga o registo da base de dados
    $stmt = $pdo->prepare("DELETE FROM invoices WHERE id = ?");
    $stmt->execute([$id]);
}

header('Location: invoices.php?msg=Fatura eliminada com sucesso.');
exit;
