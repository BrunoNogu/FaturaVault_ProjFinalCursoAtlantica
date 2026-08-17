<?php
// Página de importação de movimentos bancários a partir de CSV
require_once 'config_helper.php';

$step = 1;
$preview = [];
$headers = [];
$successMsg = '';
$errorMsg = '';
$bankFormat = '';
$delimiter = ';';

// Formatos de banco suportados
$bankFormats = [
    'cgd' => 'CGD (Caixa Geral de Depósitos)'
];

// Função para validar data em vários formatos
function parseDate($dateStr) {
    $formats = ['d/m/Y', 'd-m-Y', 'Y/m/d', 'Y-m-d', 'd.m.Y', 'Y-m-d H:i:s'];
    foreach ($formats as $format) {
        $date = DateTime::createFromFormat($format, trim($dateStr));
        if ($date) return $date->format('Y-m-d');
    }
    return null;
}

// Função para validar valor numérico em vários formatos
function parseAmount($amountStr) {
    $amountStr = trim($amountStr);
    // Remove pontos se forem separadores de milhares e vírgula é decimal
    $amountStr = str_replace('.', '', $amountStr);
    $amountStr = str_replace(',', '.', $amountStr);
    if (preg_match('/^-?\d+(\.\d{1,2})?$/', $amountStr)) {
        return floatval($amountStr);
    }
    return null;
}

// Função para processar CSV do CGD
function processCGDCsv($filePath, $delimiter = ';') {
    $handle = fopen($filePath, 'r');
    $lines = [];
    $dataStartLine = -1;
    
    // Lê todas as linhas até encontrar os dados
    while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
        // Procura pela linha que começa com "Data mov."
        if (!empty($row[0]) && stripos(trim($row[0]), 'data mov') === 0) {
            $dataStartLine = count($lines);
            break;
        }
        $lines[] = $row;
    }
    
    if ($dataStartLine < 0) {
        fclose($handle);
        return ['error' => 'Formato CGD não reconhecido. Não foi encontrada a linha de cabeçalho dos dados.'];
    }
    
    // Headers: Data mov. | Data-valor | Descrição | Montante
    $headers = ['Data mov.', 'Data-valor', 'Descrição', 'Montante'];
    
    // Processa as linhas de dados
    $data = [];
    while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
        // Pula linhas vazias no final
        if (count($row) < 3 || empty(trim($row[0] ?? ''))) {
            continue;
        }
        // Pega nas primeiras 4 colunas (é tudo o que precisa)
        $data[] = array_slice($row, 0, 4);
    }
    
    fclose($handle);
    
    return [
        'headers' => $headers,
        'preview' => $data,
        'data' => $data,
        'colMap' => [
            'date' => 0,        // Data mov.
            'description' => 2,  // Descrição
            'amount' => 3        // Montante
        ]
    ];
}

// Etapa 1: Upload e seleção de banco
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['step']) && $_POST['step'] == 1) {
    $bankFormat = $_POST['bank_format'] ?? '';
    $delimiter = $_POST['delimiter'] ?? ';';
    
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] != 0) {
        $errorMsg = 'Por favor selecione um ficheiro CSV válido.';
    } elseif (empty($bankFormat) || !isset($bankFormats[$bankFormat])) {
        $errorMsg = 'Selecione um formato de banco.';
    } else {
        $file = $_FILES['csv_file']['tmp_name'];
        
        // Processamento específico por banco
        if ($bankFormat === 'cgd') {
            $result = processCGDCsv($file, $delimiter);
            if (isset($result['error'])) {
                $errorMsg = $result['error'];
            } else {
                $headers = $result['headers'];
                $preview = $result['preview'];
                $step = 2;
                $bankFormat = 'cgd';
                
                // Armazena ficheiro temporariamente
                $tempFile = sys_get_temp_dir() . '/' . uniqid('csv_') . '.csv';
                copy($file, $tempFile);
                
                // Guarda a configuração do formato
                $_SESSION['csv_format'] = $bankFormat;
                $_SESSION['csv_col_map'] = $result['colMap'];
                $_SESSION['csv_delimiter'] = $delimiter;
                $_SESSION['csv_temp_file'] = $tempFile;
            }
        }
    }
}

// Etapa 2: Confirmação e importação
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['step']) && $_POST['step'] == 2) {
    $bankFormat = $_SESSION['csv_format'] ?? '';
    $delimiter = $_SESSION['csv_delimiter'] ?? ';';
    $tempFile = $_SESSION['csv_temp_file'] ?? '';
    $colMap = $_SESSION['csv_col_map'] ?? [];
    
    $dateCol = $colMap['date'] ?? 0;
    $descCol = $colMap['description'] ?? 2;
    $amountCol = $colMap['amount'] ?? 3;
    
    if (!file_exists($tempFile)) {
        $errorMsg = 'Ficheiro temporário não encontrado. Tente novamente.';
        $step = 1;
    } else {
        $handle = fopen($tempFile, 'r');
        $imported = 0;
        $duplicates = 0;
        $failed = 0;
        
        // Para CGD, avança até à linha de dados
        if ($bankFormat === 'cgd') {
            while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                if (stripos($row[0] ?? '', 'data mov') !== false) {
                    break;
                }
            }
        }
        
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            if (count($row) > max($dateCol, $descCol, $amountCol)) {
                $date = parseDate($row[$dateCol] ?? '');
                $description = trim($row[$descCol] ?? '');
                $amount = parseAmount($row[$amountCol] ?? '0');
                
                if ($amount === null) continue;
                
                // Negativos = saída, positivos = entrada
                $type = $amount >= 0 ? 'entrada' : 'saida';
                $amount = abs($amount);
                
                if ($date && $description && $amount > 0) {
                    try {
                        $stmt = $pdo->prepare("
                            SELECT COUNT(*) as cnt FROM bank_movements 
                            WHERE movement_date = ? AND description = ? AND amount = ?
                        ");
                        $stmt->execute([$date, $description, $amount]);
                        
                        if ($stmt->fetch()['cnt'] == 0) {
                            $stmt = $pdo->prepare("
                                INSERT INTO bank_movements (movement_date, description, amount, type, status)
                                VALUES (?, ?, ?, ?, 'pending')
                            ");
                            $stmt->execute([$date, $description, $amount, $type]);
                            $imported++;
                        } else {
                            $duplicates++;
                        }
                    } catch (PDOException $e) {
                        $failed++;
                    }
                } else {
                    if (!empty(trim($row[0] ?? ''))) {
                        $failed++;
                    }
                }
            }
        }
        
        fclose($handle);
        unlink($tempFile);
        
        unset($_SESSION['csv_format']);
        unset($_SESSION['csv_col_map']);
        unset($_SESSION['csv_delimiter']);
        unset($_SESSION['csv_temp_file']);
        
        $step = 3;
        $successMsg = "Importação concluída: $imported importados, $duplicates duplicados ignorados" . ($failed > 0 ? ", $failed falharam" : "") . ".";
    }
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Importar Movimentos Bancários - FaturaVault</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="container">
        <?php $activePage = 'bank'; include 'menu.php'; ?>
        
        <header>
            <h1>Importar Movimentos Bancários</h1>
            <p>Carregue um ficheiro CSV do seu banco</p>
        </header>

        <?php if ($errorMsg): ?>
            <div class="mensagem erro"><?= htmlspecialchars($errorMsg) ?></div>
        <?php endif; ?>

        <?php if ($successMsg && $step === 3): ?>
            <div class="mensagem sucesso"><?= htmlspecialchars($successMsg) ?></div>
            <a href="bank_movements.php" class="btn btn-principal btn-grande">Ver Movimentos</a>
        <?php endif; ?>

        <?php if ($step === 1): ?>
            <fieldset>
                <legend>Passo 1: Selecionar Banco e Ficheiro CSV</legend>
                <p class="campo-descricao">Selecione o banco de onde exportou o extracto, depois o ficheiro CSV.</p>
                
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="step" value="1">
                    
                    <div class="campo">
                        <label for="bank_format">Banco/Formato:</label>
                        <select id="bank_format" name="bank_format" required>
                            <option value="">-- Selecione --</option>
                            <?php foreach ($bankFormats as $key => $label): ?>
                                <option value="<?= $key ?>"><?= htmlspecialchars($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="campo-descricao" style="margin-top: 5px;">Novos bancos podem ser adicionados conforme necessário.</p>
                    </div>
                    
                    <div class="campo">
                        <label for="csv_file">Ficheiro CSV:</label>
                        <input type="file" id="csv_file" name="csv_file" accept=".csv" required>
                    </div>
                    
                    <input type="hidden" name="delimiter" value=";">
                    
                    <button type="submit" class="btn btn-principal btn-grande">Carregar e Pré-visualizar</button>
                </form>


            </fieldset>

        <?php elseif ($step === 2): ?>
            <fieldset>
                <legend>Passo 2: Confirmar Importação</legend>
                <p class="campo-descricao">Verifique os dados abaixo e confirme a importação. Movimentos duplicados serão ignorados automaticamente.</p>
                
                <?php if (!empty($preview)): ?>
                    <h3 style="margin-top: 20px;">Pré-visualização (<?= count($preview) ?> registos):</h3>
                    <div class="table-responsive" style="margin: 15px 0;">
                    <table>
                        <thead>
                            <tr>
                                <th>Data</th>
                                <th>Descrição</th>
                                <th>Montante</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($preview as $row): ?>
                                <tr>
                                    <td><?= htmlspecialchars($row[0] ?? '') ?></td>
                                    <td><?= htmlspecialchars($row[2] ?? '') ?></td>
                                    <td><?= htmlspecialchars($row[3] ?? '') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                <?php endif; ?>
                
                <form method="POST">
                    <input type="hidden" name="step" value="2">
                    <div style="display: flex; gap: 10px;">
                        <button type="submit" class="btn btn-principal">Confirmar Importação</button>
                        <a href="import_bank.php" class="btn">Cancelar</a>
                    </div>
                </form>
            </fieldset>

        <?php elseif ($step === 3): ?>
            <fieldset>
                <legend>Importação Concluída</legend>
                <p class="campo-descricao">Os movimentos foram importados com sucesso. Pode agora ligar as faturas a cada movimento.</p>
                <a href="bank_movements.php" class="btn btn-principal btn-grande">Ver Todos os Movimentos</a>
            </fieldset>
        <?php endif; ?>
    </div>
</body>
</html>
