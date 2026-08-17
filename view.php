<?php
// Visualização detalhada de uma fatura - dados + pré-visualização do PDF
require_once 'config_helper.php';
$companyName = getConfig($pdo, 'company_name');

if (!isset($_GET['id'])) {
    header('Location: index.php');
    exit;
}

$stmt = $pdo->prepare("SELECT invoices.*, users.name AS uploaded_by FROM invoices LEFT JOIN users ON invoices.user_id = users.id WHERE invoices.id = ?");
$stmt->execute([intval($_GET['id'])]);
$invoice = $stmt->fetch();

if (!$invoice) {
    die('Fatura não encontrada.');
}

// Verificar se há movimento bancário associado
$stmtMov = $pdo->prepare("SELECT id, movement_date, description, amount FROM bank_movements WHERE invoice_id = ?");
$stmtMov->execute([$invoice['id']]);
$linkedMovement = $stmtMov->fetch();
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fatura #<?= $invoice['id'] ?> - FaturaVault</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="container container-largo">
        <?php $subtitle = 'Detalhes da fatura'; include 'menu.php'; ?>

        <div class="acoes-fatura">
            <a href="invoices.php" class="btn">Voltar</a>
            <a href="delete.php?id=<?= $invoice['id'] ?>" class="btn btn-perigo" onclick="return confirm('Tem a certeza que deseja eliminar esta fatura?')">Eliminar</a>
        </div>

        <div class="layout-pdf">
            <div class="layout-pdf-formulario">
        <div class="detalhes">
            <div class="fatura-destaque">
                <div class="fatura-fornecedor">
                    <?= htmlspecialchars($invoice['supplier_name'] ?: 'Fornecedor não especificado') ?>
                </div>
                <div class="fatura-valores">
                    <div class="fatura-total">
                        <span class="fatura-total-valor"><?= number_format($invoice['total'], 2, ',', '.') ?> €</span>
                        <span class="fatura-total-titulo">Total</span>
                    </div>
                    <div class="fatura-iva-destaque">
                        <span class="fatura-iva-valor"><?= number_format($invoice['total_vat'], 2, ',', '.') ?> €</span>
                        <span class="fatura-iva-titulo">IVA incluído</span>
                    </div>
                </div>
            </div>

            <fieldset>
                <h3 class="seccao-titulo">Informações Gerais</h3>
                <div class="detalhe-grupo">
                    <div class="detalhe">
                        <strong>ID:</strong>
                        <span><?= $invoice['id'] ?></span>
                    </div>
                    <div class="detalhe">
                        <strong>Data de upload:</strong>
                        <span><?= date('d/m/Y H:i', strtotime($invoice['upload_date'])) ?></span>
                    </div>
                    <div class="detalhe">
                        <strong>Inserido por:</strong>
                        <span><?= htmlspecialchars($invoice['uploaded_by'] ?? 'Desconhecido') ?></span>
                    </div>
                </div>
                <div class="detalhe-grupo">
                    <div class="detalhe">
                        <strong>Movimento bancário:</strong>
                        <?php if ($linkedMovement): ?>
                            <span style="color: green;">✓ <?= date('d/m/Y', strtotime($linkedMovement['movement_date'])) ?> — <?= number_format($linkedMovement['amount'], 2, ',', '.') ?> €</span>
                        <?php else: ?>
                            <span style="color: #999;">Sem movimento associado</span>
                        <?php endif; ?>
                    </div>
                </div>
            </fieldset>

            <fieldset>
                <h3 class="seccao-titulo">Dados da Fatura</h3>
                <div class="detalhe-grupo">
                    <div class="detalhe">
                        <strong>Fornecedor:</strong>
                        <span><?= htmlspecialchars($invoice['supplier_name'] ?: '-') ?></span>
                    </div>
                    <div class="detalhe">
                        <strong>Categoria:</strong>
                        <span><?= htmlspecialchars($invoice['category'] ?: '-') ?></span>
                    </div>
                </div>
                <div class="detalhe-grupo">
                    <div class="detalhe">
                        <strong>NIF Emitente:</strong>
                        <span><?= htmlspecialchars($invoice['supplier_vat'] ?: '-') ?></span>
                    </div>
                    <div class="detalhe">
                        <strong>Número da Fatura:</strong>
                        <span><?= htmlspecialchars($invoice['document_number'] ?: '-') ?></span>
                    </div>
                </div>
                <div class="detalhe-grupo">
                    <div class="detalhe">
                        <strong>Data do Documento:</strong>
                        <span><?= $invoice['document_date'] ? date('d/m/Y', strtotime($invoice['document_date'])) : '-' ?></span>
                    </div>
                    <div class="detalhe">
                        <strong>ATCUD:</strong>
                        <span><?= htmlspecialchars($invoice['atcud'] ?: '-') ?></span>
                    </div>
                </div>
                <div class="detalhe-grupo">
                    <div class="detalhe">
                        <strong>NIF Adquirente:</strong>
                        <span><?= htmlspecialchars($invoice['buyer_vat'] ?: '-') ?></span>
                    </div>
                    <div class="detalhe">
                        <strong>Tipo de Documento:</strong>
                        <span><?= htmlspecialchars($invoice['document_type'] ?: '-') ?></span>
                    </div>
                </div>
            </fieldset>

            <fieldset>
                <h3 class="seccao-titulo">Discriminação do IVA</h3>
                <p class="campo-descricao">Uma fatura pode conter artigos com diferentes taxas de IVA em simultâneo (isenta, 6%, 13%, 23%).</p>
                <div class="table-responsive">
                <table class="tabela-iva">
                    <thead>
                        <tr>
                            <th>Taxa</th>
                            <th>Base Tributável</th>
                            <th>IVA</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($invoice['base_exempt'] > 0): ?>
                        <tr>
                            <td>Isenta (0%)</td>
                            <td><?= number_format($invoice['base_exempt'], 2, ',', '.') ?> €</td>
                            <td>—</td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($invoice['base_reduced'] > 0 || $invoice['vat_reduced'] > 0): ?>
                        <tr>
                            <td>Reduzida (6%)</td>
                            <td><?= number_format($invoice['base_reduced'], 2, ',', '.') ?> €</td>
                            <td><?= number_format($invoice['vat_reduced'], 2, ',', '.') ?> €</td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($invoice['base_intermediate'] > 0 || $invoice['vat_intermediate'] > 0): ?>
                        <tr>
                            <td>Intermédia (13%)</td>
                            <td><?= number_format($invoice['base_intermediate'], 2, ',', '.') ?> €</td>
                            <td><?= number_format($invoice['vat_intermediate'], 2, ',', '.') ?> €</td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($invoice['base_standard'] > 0 || $invoice['vat_standard'] > 0): ?>
                        <tr>
                            <td>Normal (23%)</td>
                            <td><?= number_format($invoice['base_standard'], 2, ',', '.') ?> €</td>
                            <td><?= number_format($invoice['vat_standard'], 2, ',', '.') ?> €</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td><strong>Total IVA</strong></td>
                            <td></td>
                            <td><strong><?= number_format($invoice['total_vat'], 2, ',', '.') ?> €</strong></td>
                        </tr>
                        <tr>
                            <td><strong>Total do Documento</strong></td>
                            <td></td>
                            <td><strong><?= number_format($invoice['total'], 2, ',', '.') ?> €</strong></td>
                        </tr>
                    </tfoot>
                </table>
                </div>
            </fieldset>

            <?php if (!empty($invoice['qr_data'])): ?>
            <fieldset>
                <legend>Dados brutos do QR Code</legend>
                <pre class="dados-qr"><?= htmlspecialchars($invoice['qr_data']) ?></pre>
            </fieldset>
            <?php endif; ?>

            <?php if (!empty($invoice['extracted_text'])): ?>
            <fieldset>
                <legend>Conteúdo extraído da fatura</legend>
                <pre class="dados-qr"><?= htmlspecialchars($invoice['extracted_text']) ?></pre>
            </fieldset>
            <?php endif; ?>
        </div>

            </div>
            <div class="layout-pdf-viewer">
                <iframe src="<?= htmlspecialchars($invoice['file_path']) ?>" frameborder="0"></iframe>
            </div>
        </div>
    </div>
</body>
</html>
