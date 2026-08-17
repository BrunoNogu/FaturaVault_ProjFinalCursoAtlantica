<?php
// Validação de NIF através do sistema VIES da Comissão Europeia
// Chamado via AJAX quando o utilizador preenche o NIF do fornecedor
require_once 'db.php';

header('Content-Type: application/json');

$vat = trim($_GET['vat'] ?? '');

if ($vat === '') {
    echo json_encode(['error' => 'NIF não fornecido.']);
    exit;
}

// Separa o código do país do número (ex: PT510314600 -> PT + 510314600)
$countryCode = 'PT';
$number = $vat;

if (preg_match('/^([A-Za-z]{2})(.+)$/', $vat, $matches)) {
    $countryCode = strtoupper($matches[1]);
    $number = $matches[2];
}

$number = preg_replace('/[^A-Za-z0-9]/', '', $number);

// Procura o nome e categoria na base de dados local (faturas anteriores)
$lastCategory = '';
$localName = '';
$stmtLocal = $pdo->prepare("SELECT supplier_name, category FROM invoices WHERE supplier_vat = ? AND supplier_name != '' ORDER BY id DESC LIMIT 1");
$stmtLocal->execute([$vat]);
$localRow = $stmtLocal->fetch();
if ($localRow) {
    $localName = $localRow['supplier_name'];
    $lastCategory = $localRow['category'] ?? '';
}

// Se já tem nome local, devolve sem consultar o VIES
if ($localName !== '') {
    echo json_encode(['valid' => true, 'name' => $localName, 'source' => 'local', 'last_category' => $lastCategory]);
    exit;
}

// Consulta a API REST do VIES
$url = 'https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number';
$payload = json_encode(['countryCode' => $countryCode, 'vatNumber' => $number]);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);

$response = curl_exec($ch);

if (curl_errno($ch)) {
    echo json_encode(['error' => 'Erro de ligação ao serviço VIES.']);
    curl_close($ch);
    exit;
}
curl_close($ch);

$result = json_decode($response, true);

if (!$result) {
    echo json_encode(['error' => 'Resposta inválida do serviço VIES.']);
    exit;
}

// Devolve o resultado: válido com nome, ou inválido
if (isset($result['valid']) && $result['valid'] === true) {
    echo json_encode(['valid' => true, 'name' => $result['name'] ?? '', 'address' => $result['address'] ?? '', 'last_category' => $lastCategory]);
} else {
    echo json_encode(['valid' => false, 'name' => '', 'error' => 'NIF não encontrado no sistema VIES.', 'last_category' => $lastCategory]);
}
