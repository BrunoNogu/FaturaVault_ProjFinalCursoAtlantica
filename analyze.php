<?php
// Endpoint para análise de faturas com Azure AI Document Intelligence
// Recebe um PDF via POST, envia para o Azure e devolve os campos extraídos em JSON
require_once 'config_helper.php';

header('Content-Type: application/json');

// Verifica se o Azure está configurado
$endpoint = getConfig($pdo, 'azure_endpoint');
$apiKey = getConfig($pdo, 'azure_key');

if ($endpoint === '' || $apiKey === '') {
    echo json_encode(['error' => 'Azure AI não está configurado. Aceda às Configurações.']);
    exit;
}

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['error' => 'Nenhum ficheiro recebido.']);
    exit;
}

$pdfContent = file_get_contents($_FILES['file']['tmp_name']);

// Envia o PDF para o modelo prebuilt-invoice do Azure
$endpoint = rtrim($endpoint, '/');
$url = $endpoint . '/documentintelligence/documentModels/prebuilt-invoice:analyze?api-version=2024-11-30';

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $pdfContent);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Ocp-Apim-Subscription-Key: ' . $apiKey,
    'Content-Type: application/pdf'
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$headers = substr($response, 0, $headerSize);

if (curl_errno($ch)) {
    echo json_encode(['error' => 'Erro de ligação ao Azure: ' . curl_error($ch)]);
    curl_close($ch);
    exit;
}
curl_close($ch);

// O Azure responde com 202 e um header Operation-Location para polling
if ($httpCode !== 202) {
    echo json_encode(['error' => 'Erro do Azure (código ' . $httpCode . ')']);
    exit;
}

$operationUrl = '';
foreach (explode("\r\n", $headers) as $line) {
    if (stripos($line, 'Operation-Location:') === 0) {
        $operationUrl = trim(substr($line, strlen('Operation-Location:')));
        break;
    }
}

if ($operationUrl === '') {
    echo json_encode(['error' => 'Não foi possível obter o estado da análise.']);
    exit;
}

// Polling: consulta o estado da análise a cada 2 segundos (máximo 30 tentativas)
$result = null;
for ($attempt = 0; $attempt < 30; $attempt++) {
    sleep(2);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $operationUrl);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Ocp-Apim-Subscription-Key: ' . $apiKey]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $statusResponse = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($statusResponse, true);
    if (isset($data['status']) && $data['status'] === 'succeeded') { $result = $data; break; }
    if (isset($data['status']) && $data['status'] === 'failed') {
        echo json_encode(['error' => 'A análise do Azure falhou.']);
        exit;
    }
}

if (!$result) {
    echo json_encode(['error' => 'Tempo de espera excedido. Tente novamente.']);
    exit;
}

// Extrai os campos relevantes do resultado da análise
$fields = [];
$documents = $result['analyzeResult']['documents'] ?? [];

if (!empty($documents)) {
    $f = $documents[0]['fields'];
    if (isset($f['VendorTaxId']['content'])) $fields['supplier_vat'] = preg_replace('/[^A-Za-z0-9]/', '', $f['VendorTaxId']['content']);
    if (isset($f['CustomerTaxId']['content'])) $fields['buyer_vat'] = preg_replace('/[^A-Za-z0-9]/', '', $f['CustomerTaxId']['content']);
    if (isset($f['InvoiceId']['content'])) $fields['document_number'] = $f['InvoiceId']['content'];
    if (isset($f['InvoiceDate']['valueDate'])) $fields['document_date'] = $f['InvoiceDate']['valueDate'];

    // Prefere valores em EUR; se InvoiceTotal estiver em USD, usa SubTotal em EUR
    $totalCurrency = $f['InvoiceTotal']['valueCurrency']['currencyCode'] ?? '';
    $subCurrency = $f['SubTotal']['valueCurrency']['currencyCode'] ?? '';
    if ($totalCurrency === 'EUR') {
        $fields['total'] = $f['InvoiceTotal']['valueCurrency']['amount'];
    } elseif ($subCurrency === 'EUR') {
        $fields['total'] = $f['SubTotal']['valueCurrency']['amount'];
    } elseif (isset($f['InvoiceTotal']['valueCurrency']['amount'])) {
        $fields['total'] = $f['InvoiceTotal']['valueCurrency']['amount'];
    }

    if (isset($f['TotalTax']['valueCurrency']['amount'])) $fields['total_vat'] = $f['TotalTax']['valueCurrency']['amount'];
    if (isset($f['SubTotal']['valueCurrency']['amount'])) $fields['base_standard'] = $f['SubTotal']['valueCurrency']['amount'];

    // Agrupa os itens por taxa de IVA para devolver linhas separadas
    $vatLines = [];
    $items = $f['Items']['valueArray'] ?? [];
    foreach ($items as $item) {
        $io = $item['valueObject'] ?? [];
        $rate = isset($io['TaxRate']['valueString']) ? (int) round(floatval($io['TaxRate']['valueString'])) : null;

        // Prefere o montante em EUR
        $amount = 0;
        $tax = null;
        $amountCurrency = $io['Amount']['valueCurrency']['currencyCode'] ?? '';
        if ($amountCurrency === 'EUR' || $amountCurrency === '') {
            $amount = $io['Amount']['valueCurrency']['amount'] ?? 0;
        }
        $taxCurrency = $io['Tax']['valueCurrency']['currencyCode'] ?? '';
        if ($taxCurrency === 'EUR' || $taxCurrency === '') {
            $tax = $io['Tax']['valueCurrency']['amount'] ?? null;
        }

        if ($rate !== null) {
            if (!isset($vatLines[$rate])) $vatLines[$rate] = ['base' => 0, 'vat' => 0];
            $vatLines[$rate]['base'] += $amount;
            if ($tax !== null) $vatLines[$rate]['vat'] += $tax;
        }
    }

    // Se conseguiu agrupar por taxa, devolve as linhas
    if (!empty($vatLines)) {
        $fields['vat_lines'] = [];
        foreach ($vatLines as $rate => $values) {
            // Se não veio valor de IVA por item, calcula
            $vatValue = $values['vat'] > 0 ? $values['vat'] : $values['base'] * $rate / 100;
            $fields['vat_lines'][] = ['base' => round($values['base'], 2), 'rate' => $rate, 'vat' => round($vatValue, 2)];
        }
    }
}

// Extrair texto completo do documento
$extractedText = $result['analyzeResult']['content'] ?? '';

echo json_encode(['success' => true, 'fields' => $fields, 'extracted_text' => $extractedText]);
