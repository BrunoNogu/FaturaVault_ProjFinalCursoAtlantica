<?php
// Importação de backup - lê um ZIP com backup.json + PDFs
// Verifica duplicados pelo número da fatura + NIF + total
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['backup_file'])) {
    header('Location: settings.php');
    exit;
}

// Validação do ficheiro enviado
$file = $_FILES['backup_file'];
if ($file['error'] !== UPLOAD_ERR_OK || strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'zip') {
    header('Location: settings.php?restore=error&msg=' . urlencode('Ficheiro inválido. Envie um ficheiro ZIP.'));
    exit;
}

// Abre o ZIP e lê o backup.json
$zip = new ZipArchive();
if ($zip->open($file['tmp_name']) !== true) {
    header('Location: settings.php?restore=error&msg=' . urlencode('Não foi possível abrir o ficheiro ZIP.'));
    exit;
}

$jsonContent = $zip->getFromName('backup.json');
if ($jsonContent === false) {
    $zip->close();
    header('Location: settings.php?restore=error&msg=' . urlencode('O ficheiro ZIP não contém backup.json.'));
    exit;
}

$invoices = json_decode($jsonContent, true);
if (!is_array($invoices) || empty($invoices)) {
    $zip->close();
    header('Location: settings.php?restore=error&msg=' . urlencode('O ficheiro backup.json é inválido ou está vazio.'));
    exit;
}

$uploadDir = __DIR__ . '/uploads';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// Percorre cada fatura do JSON e importa
$imported = 0;
$skipped = 0;

foreach ($invoices as $inv) {
    $documentNumber = $inv['document_number'] ?? '';
    $supplierVat    = $inv['supplier_vat'] ?? '';
    $total          = floatval($inv['total'] ?? 0);

    // Verifica se já existe uma fatura com os mesmos dados (evita duplicados)
    if ($documentNumber && $supplierVat) {
        $check = $pdo->prepare("SELECT id FROM invoices WHERE document_number = ? AND supplier_vat = ? AND total = ?");
        $check->execute([$documentNumber, $supplierVat, $total]);
        if ($check->fetch()) {
            $skipped++;
            continue;
        }
    }

    $stmt = $pdo->prepare("INSERT INTO invoices 
        (file_path, category, supplier_name, supplier_vat, buyer_vat, document_type,
         document_date, document_number, atcud, base_exempt, base_reduced,
         vat_reduced, base_intermediate, vat_intermediate, base_standard, vat_standard,
         total_vat, total, qr_data, upload_date)
        VALUES ('temp', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $stmt->execute([
        $inv['category'] ?? '',
        $inv['supplier_name'] ?? '',
        $supplierVat,
        $inv['buyer_vat'] ?? '',
        $inv['document_type'] ?? '',
        $inv['document_date'] ?? null,
        $documentNumber,
        $inv['atcud'] ?? '',
        floatval($inv['base_exempt'] ?? 0),
        floatval($inv['base_reduced'] ?? 0),
        floatval($inv['vat_reduced'] ?? 0),
        floatval($inv['base_intermediate'] ?? 0),
        floatval($inv['vat_intermediate'] ?? 0),
        floatval($inv['base_standard'] ?? 0),
        floatval($inv['vat_standard'] ?? 0),
        floatval($inv['total_vat'] ?? 0),
        $total,
        $inv['qr_data'] ?? '',
        $inv['upload_date'] ?? date('Y-m-d H:i:s')
    ]);

    $newId = $pdo->lastInsertId();
    $newFileName = "invoice_{$newId}.pdf";

    // Tenta extrair o PDF correspondente do ZIP
    $pdfContent = $zip->getFromName('storage/' . ($inv['file_name'] ?? ''));
    if ($pdfContent !== false) {
        file_put_contents($uploadDir . '/' . $newFileName, $pdfContent);
        $pdo->prepare("UPDATE invoices SET file_path = ? WHERE id = ?")->execute(["uploads/$newFileName", $newId]);
    } else {
        $pdo->prepare("UPDATE invoices SET file_path = '' WHERE id = ?")->execute([$newId]);
    }

    $imported++;
}

$zip->close();

$msg = "Backup restaurado: {$imported} fatura(s) importada(s)";
if ($skipped > 0) {
    $msg .= ", {$skipped} ignorada(s) (duplicadas)";
}

header('Location: settings.php?restore=success&msg=' . urlencode($msg));
exit;
