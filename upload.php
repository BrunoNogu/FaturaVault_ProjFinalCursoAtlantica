<?php
// Página de carregamento de nova fatura
// Formulario com leitura automática de QR code e opção de Azure AI
require_once 'config_helper.php';
$hasAzure = isAzureConfigured($pdo);
$azureAutoAnalyze = getConfig($pdo, 'azure_auto_analyze', '0');
$azureAlwaysAnalyze = getConfig($pdo, 'azure_always_analyze', '0');
$companyName = getConfig($pdo, 'company_name');
$companyVat = getConfig($pdo, 'company_vat');
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Carregar Fatura - FaturaVault</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="container container-largo">
        <?php $activePage = 'upload'; $subtitle = 'Carregar nova fatura'; include 'menu.php'; ?>

        <?php if (!empty($_SESSION['upload_error'])): ?>
            <div class="mensagem erro"><?= htmlspecialchars($_SESSION['upload_error']) ?></div>
            <?php unset($_SESSION['upload_error']); ?>
        <?php endif; ?>

        <div class="layout-pdf">
            <div class="layout-pdf-formulario">

        <form id="formInvoice" action="process.php" method="POST" enctype="multipart/form-data" onsubmit="return prepareSubmission()">

            <div class="campo">
                <label for="file">Ficheiro da fatura</label>
                <input type="file" id="file" name="file" accept=".pdf" required>
            </div>

            <div id="qr-status" class="mensagem" style="display:none;"></div>

            <div id="azure-zone" style="display:none;">
                <button type="button" id="btn-azure" class="btn btn-azure" onclick="analyzeWithAzure()">
                    <img src="img/azure.webp" alt=""> Analisar com Azure AI
                </button>
            </div>

            <div class="campo-grupo">
                <div class="campo">
                    <label for="atcud">ATCUD (opcional)</label>
                    <input type="text" id="atcud" name="atcud" placeholder="Ex.: AB12CD34-000123">
                </div>
                <div class="campo">
                    <label for="category">Categoria *</label>
                    <select id="category" name="category" required>
                        <option value="">Escolha uma categoria</option>
                        <option value="Banca">Banca</option>
                        <option value="Consumíveis">Consumíveis</option>
                        <option value="Decoração">Decoração</option>
                        <option value="Eletrónicos">Eletrónicos</option>
                        <option value="Mobiliário">Mobiliário</option>
                        <option value="Estado">Estado</option>
                        <option value="Seguros">Seguros</option>
                        <option value="Manutenção">Manutenção</option>
                        <option value="Obras">Obras</option>
                        <option value="Rendas">Rendas</option>
                        <option value="Serviços">Serviços</option>
                        <option value="Software">Software</option>
                        <option value="Utensílios">Utensílios</option>
                        <option value="Utilitários">Utilitários</option>
                    </select>
                </div>
            </div>

            <div class="campo">
                <label for="document_date">Data da fatura *</label>
                <input type="date" id="document_date" name="document_date" required>
            </div>

            <div class="campo">
                <label for="supplier_name">Nome do fornecedor</label>
                <input type="text" id="supplier_name" name="supplier_name" placeholder="Preenchido automaticamente pelo NIF">
            </div>

            <div class="campo-grupo">
                <div class="campo">
                    <label for="supplier_vat">NIF do fornecedor *</label>
                    <input type="text" id="supplier_vat" name="supplier_vat" placeholder="Ex.: PT510314600 ou DE123456789" required>
                    <span id="vat-status" class="campo-estado"></span>
                </div>
                <div class="campo">
                    <label for="document_number">Número da fatura *</label>
                    <input type="text" id="document_number" name="document_number" required>
                </div>
            </div>

            <input type="hidden" id="buyer_vat" name="buyer_vat" value="<?= htmlspecialchars($companyVat) ?>">
            <input type="hidden" id="document_type" name="document_type">
            <input type="hidden" id="qr_data" name="qr_data">
            <input type="hidden" id="extracted_text" name="extracted_text">

            <input type="hidden" id="base_exempt" name="base_exempt" value="0">
            <input type="hidden" id="base_reduced" name="base_reduced" value="0">
            <input type="hidden" id="vat_reduced" name="vat_reduced" value="0">
            <input type="hidden" id="base_intermediate" name="base_intermediate" value="0">
            <input type="hidden" id="vat_intermediate" name="vat_intermediate" value="0">
            <input type="hidden" id="base_standard" name="base_standard" value="0">
            <input type="hidden" id="vat_standard" name="vat_standard" value="0">

            <fieldset>
                <div class="seccao-titulo-flex">
                    <div>
                        <h3 class="seccao-titulo">Detalhe do IVA</h3>
                        <p class="campo-descricao">Cada linha tem valor bruto, tipo de IVA e o IVA calculado automaticamente.</p>
                    </div>
                    <button type="button" id="btn-add-vat" class="btn" onclick="addVatLine()">Adicionar linha de IVA</button>
                </div>

                <div id="vat-lines">
                </div>
            </fieldset>

            <div class="campo-grupo">
                <div class="campo">
                    <label for="total"><strong>Total da fatura</strong></label>
                    <input type="number" step="0.01" id="total" name="total" value="0.00" class="campo-bloqueado" readonly>
                </div>
                <div class="campo">
                    <label for="total_vat"><strong>Total de IVA</strong></label>
                    <input type="number" step="0.01" id="total_vat" name="total_vat" value="0.00" class="campo-bloqueado" readonly>
                </div>
            </div>

            <button type="submit" class="btn btn-principal btn-grande">Guardar fatura</button>
        </form>

            </div>
            <div class="layout-pdf-viewer" id="pdf-zone">
                <div id="pdf-placeholder" class="pdf-vazio">
                    <p>Selecione um ficheiro PDF para ver a pré-visualização</p>
                </div>
                <iframe id="pdf-viewer" src="" frameborder="0" style="display:none;"></iframe>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js"></script>
    <script>
    var hasAzure = <?= $hasAzure ? 'true' : 'false' ?>;
    var azureAutoAnalyze = <?= $azureAutoAnalyze === '1' ? 'true' : 'false' ?>;
    var azureAlwaysAnalyze = <?= $azureAlwaysAnalyze === '1' ? 'true' : 'false' ?>;
    var companyVat = <?= json_encode($companyVat) ?>;
    </script>
    <script src="js/qr-reader.js?v=<?= time() ?>"></script>
    <script>
    document.getElementById('file').addEventListener('change', function() {
        var iframe = document.getElementById('pdf-viewer');
        var placeholder = document.getElementById('pdf-placeholder');
        if (this.files && this.files[0] && this.files[0].type === 'application/pdf') {
            iframe.src = URL.createObjectURL(this.files[0]);
            iframe.style.display = 'block';
            placeholder.style.display = 'none';
        } else {
            iframe.style.display = 'none';
            iframe.src = '';
            placeholder.style.display = 'flex';
        }
    });
    addVatLine();
    </script>
</body>
</html>
