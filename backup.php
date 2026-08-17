<?php
// Exportação de backup - gera um ZIP com backup.json + PDFs
// O ZIP fica com o nome: faturavault-backup-[empresa]-[data].zip
require_once 'db.php';

// Procura o nome da empresa para usar no nome do ficheiro
$companyName = '';
$stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'company_name'");
$row = $stmt->fetch();
if ($row) $companyName = $row['setting_value'];

$slug = $companyName ? preg_replace('/[^a-z0-9]+/', '-', strtolower($companyName)) : 'faturavault';
$date = date('Y-m-d');
$zipName = "faturavault-backup-{$slug}-{$date}.zip";

// Cria o ZIP num ficheiro temporário
$tmpFile = tempnam(sys_get_temp_dir(), 'fv_backup_');

$zip = new ZipArchive();
if ($zip->open($tmpFile, ZipArchive::OVERWRITE) !== true) {
    die('Erro ao criar o ficheiro ZIP.');
}

$stmt = $pdo->query("SELECT * FROM invoices ORDER BY id ASC");
$invoices = $stmt->fetchAll();

// Monta o array JSON com os dados de cada fatura
$jsonData = [];
foreach ($invoices as $inv) {
    $entry = [
        'category'          => $inv['category'],
        'supplier_name'     => $inv['supplier_name'],
        'supplier_vat'      => $inv['supplier_vat'],
        'buyer_vat'         => $inv['buyer_vat'],
        'document_type'     => $inv['document_type'],
        'document_date'     => $inv['document_date'],
        'document_number'   => $inv['document_number'],
        'atcud'             => $inv['atcud'],
        'base_exempt'       => floatval($inv['base_exempt']),
        'base_reduced'      => floatval($inv['base_reduced']),
        'vat_reduced'       => floatval($inv['vat_reduced']),
        'base_intermediate' => floatval($inv['base_intermediate']),
        'vat_intermediate'  => floatval($inv['vat_intermediate']),
        'base_standard'     => floatval($inv['base_standard']),
        'vat_standard'      => floatval($inv['vat_standard']),
        'total_vat'         => floatval($inv['total_vat']),
        'total'             => floatval($inv['total']),
        'qr_data'           => $inv['qr_data'],
        'upload_date'       => $inv['upload_date'],
        'file_name'         => basename($inv['file_path'])
    ];
    $jsonData[] = $entry;

    $pdfPath = __DIR__ . '/' . $inv['file_path'];
    if (file_exists($pdfPath)) {
        $zip->addFile($pdfPath, 'storage/' . basename($inv['file_path']));
    }
}

// Adiciona o JSON ao ZIP e fecha
$zip->addFromString('backup.json', json_encode($jsonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
$zip->close();

// Envia o ZIP para download e apaga o ficheiro temporário
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zipName . '"');
header('Content-Length: ' . filesize($tmpFile));
readfile($tmpFile);
unlink($tmpFile);
exit;
