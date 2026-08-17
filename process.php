<?php
// Processamento do formulário de nova fatura
// Recebe o PDF e os dados do formulário, valida, guarda na BD e move o ficheiro
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: upload.php');
    exit;
}

// Validação do ficheiro
if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    die('Erro ao carregar o ficheiro.');
}

$file = $_FILES['file'];

// Só aceita PDFs (verifica extensão e tipo MIME)
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if ($ext !== 'pdf') {
    die('Apenas ficheiros PDF são permitidos.');
}

$mime = mime_content_type($file['tmp_name']);
if ($mime !== 'application/pdf') {
    die('O ficheiro não é um PDF válido.');
}

// Cria a pasta uploads se não existir
$uploadDir = __DIR__ . '/uploads';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// Recolhe os campos do formulário
$supplierVat    = trim($_POST['supplier_vat'] ?? '');
$buyerVat       = trim($_POST['buyer_vat'] ?? '');
$supplierName   = trim($_POST['supplier_name'] ?? '');
$category       = trim($_POST['category'] ?? '');
$documentType   = trim($_POST['document_type'] ?? '');
$documentDate   = trim($_POST['document_date'] ?? '') ?: null;
$documentNumber = trim($_POST['document_number'] ?? '');
$atcud          = trim($_POST['atcud'] ?? '');
$baseExempt     = floatval($_POST['base_exempt'] ?? 0);
$baseReduced    = floatval($_POST['base_reduced'] ?? 0);
$vatReduced     = floatval($_POST['vat_reduced'] ?? 0);
$baseIntermediate = floatval($_POST['base_intermediate'] ?? 0);
$vatIntermediate  = floatval($_POST['vat_intermediate'] ?? 0);
$baseStandard   = floatval($_POST['base_standard'] ?? 0);
$vatStandard    = floatval($_POST['vat_standard'] ?? 0);
$totalVat       = floatval($_POST['total_vat'] ?? 0);
$total          = floatval($_POST['total'] ?? 0);
$qrData         = trim($_POST['qr_data'] ?? '');
$extractedText  = trim($_POST['extracted_text'] ?? '');

// Verificar duplicados pelo número do documento e NIF do fornecedor
if ($documentNumber !== '') {
    $stmtDup = $pdo->prepare("SELECT id FROM invoices WHERE document_number = ? AND supplier_vat = ?");
    $stmtDup->execute([$documentNumber, $supplierVat]);
    if ($stmtDup->fetch()) {
        $_SESSION['upload_error'] = 'Esta fatura já foi inserida (número ' . $documentNumber . ', NIF ' . $supplierVat . ').';
        header('Location: upload.php');
        exit;
    }
}

// Insere na BD com file_path temporário (precisa do ID para o nome do ficheiro)
$sql = "INSERT INTO invoices 
        (file_path, category, supplier_name, supplier_vat, buyer_vat, document_type, 
         document_date, document_number, atcud, base_exempt, base_reduced, 
         vat_reduced, base_intermediate, vat_intermediate, base_standard, vat_standard, 
         total_vat, total, qr_data, extracted_text, user_id) 
        VALUES ('temp', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

$stmt = $pdo->prepare($sql);
$stmt->execute([
    $category, $supplierName, $supplierVat, $buyerVat, $documentType,
    $documentDate, $documentNumber, $atcud, $baseExempt, $baseReduced,
    $vatReduced, $baseIntermediate, $vatIntermediate, $baseStandard, $vatStandard,
    $totalVat, $total, $qrData, $extractedText, $_SESSION['user_id']
]);

// Depois de ter o ID, dá o nome definitivo ao ficheiro (invoice_123.pdf)
$invoiceId = $pdo->lastInsertId();
$fileName = 'invoice_' . $invoiceId . '.pdf';

// Move o ficheiro temporário para a pasta uploads
if (!move_uploaded_file($file['tmp_name'], $uploadDir . '/' . $fileName)) {
    // Se falhar, apaga o registo para não ficar órfão
    $pdo->prepare("DELETE FROM invoices WHERE id = ?")->execute([$invoiceId]);
    die('Erro ao guardar o ficheiro.');
}

// Atualiza o caminho do ficheiro na BD
$pdo->prepare("UPDATE invoices SET file_path = ? WHERE id = ?")->execute(['uploads/' . $fileName, $invoiceId]);

header('Location: invoices.php?msg=Fatura carregada com sucesso!');
exit;
