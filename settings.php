<?php
// Página de configurações - empresa, cor de destaque, Azure AI e backups
require_once 'config_helper.php';

$successMsg = '';
$partners = [];

// Operações com sócios
$partnerAction = $_GET['partner_action'] ?? null;
$partnerId = isset($_GET['partner_id']) ? intval($_GET['partner_id']) : null;

// Guardar novo sócio
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_partner') {
    $name = trim($_POST['partner_name'] ?? '');
    $email = trim($_POST['partner_email'] ?? '');
    $percentage = floatval($_POST['partner_percentage'] ?? 0);

    if ($name === '') {
        $successMsg = 'erro|Nome do sócio obrigatório.';
    } else {
        if ($partnerId) {
            $stmt = $pdo->prepare("UPDATE partners SET name = ?, email = ?, percentage = ? WHERE id = ?");
            $stmt->execute([$name, $email, $percentage, $partnerId]);
            $successMsg = 'sucesso|Sócio atualizado com sucesso!';
        } else {
            $stmt = $pdo->prepare("INSERT INTO partners (name, email, percentage, active) VALUES (?, ?, ?, 1)");
            $stmt->execute([$name, $email, $percentage]);
            $successMsg = 'sucesso|Sócio adicionado com sucesso!';
        }
    }
    $partnerAction = null;
    $partnerId = null;
}

// Apagar sócio
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_partner') {
    $deleteId = intval($_POST['partner_id'] ?? 0);
    if ($deleteId > 0) {
        $pdo->prepare("DELETE FROM partners WHERE id = ?")->execute([$deleteId]);
        $successMsg = 'sucesso|Sócio eliminado com sucesso!';
    }
}

// Buscar sócios
$stmtPartners = $pdo->query("SELECT * FROM partners ORDER BY name");
$partners = $stmtPartners->fetchAll();

// Buscar sócio para edição
$partnerToEdit = null;
if ($partnerAction === 'edit' && $partnerId) {
    $stmt = $pdo->prepare("SELECT * FROM partners WHERE id = ?");
    $stmt->execute([$partnerId]);
    $partnerToEdit = $stmt->fetch();
}

// Guarda as configurações quando o formulário é submetido
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
    // Formulário da empresa e tema
    if (isset($_POST['company_name'])) {
        saveConfig($pdo, 'company_name', trim($_POST['company_name'] ?? ''));
        saveConfig($pdo, 'company_vat', trim($_POST['company_vat'] ?? ''));
        saveConfig($pdo, 'accent_color', trim($_POST['accent_color'] ?? '#3498db'));
    }
    // Formulário do Azure AI
    if (isset($_POST['azure_endpoint'])) {
        saveConfig($pdo, 'azure_endpoint', trim($_POST['azure_endpoint'] ?? ''));
        saveConfig($pdo, 'azure_key', trim($_POST['azure_key'] ?? ''));
        $alwaysAnalyze = isset($_POST['azure_always_analyze']) ? '1' : '0';
        $autoAnalyze = ($alwaysAnalyze === '1' || isset($_POST['azure_auto_analyze'])) ? '1' : '0';
        saveConfig($pdo, 'azure_auto_analyze', $autoAnalyze);
        saveConfig($pdo, 'azure_always_analyze', $alwaysAnalyze);
    }
    // Formulário do Assistente IA
    if (isset($_POST['chat_provider'])) {
        saveConfig($pdo, 'chat_provider', trim($_POST['chat_provider'] ?? 'ollama'));
        saveConfig($pdo, 'ollama_host', trim($_POST['ollama_host'] ?? ''));
        saveConfig($pdo, 'ollama_model', trim($_POST['ollama_model'] ?? 'llama3'));
        saveConfig($pdo, 'azure_openai_endpoint', trim($_POST['azure_openai_endpoint'] ?? ''));
        saveConfig($pdo, 'azure_openai_key', trim($_POST['azure_openai_key'] ?? ''));
        saveConfig($pdo, 'azure_openai_deployment', trim($_POST['azure_openai_deployment'] ?? ''));
    }
    if ($successMsg === '') {
        $successMsg = 'sucesso|Configurações guardadas com sucesso!';
    }
}

$companyName = getConfig($pdo, 'company_name');
$companyVat = getConfig($pdo, 'company_vat');
$azureEndpoint = getConfig($pdo, 'azure_endpoint');
$azureKey = getConfig($pdo, 'azure_key');
$azureAutoAnalyze = getConfig($pdo, 'azure_auto_analyze', '0');
$azureAlwaysAnalyze = getConfig($pdo, 'azure_always_analyze', '0');
$ollamaHost = getConfig($pdo, 'ollama_host');
$ollamaModel = getConfig($pdo, 'ollama_model', 'llama3');
$azureOpenaiEndpoint = getConfig($pdo, 'azure_openai_endpoint');
$azureOpenaiKey = getConfig($pdo, 'azure_openai_key');
$azureOpenaiDeployment = getConfig($pdo, 'azure_openai_deployment');
$chatProvider = getConfig($pdo, 'chat_provider', 'ollama');
$accentColor = getConfig($pdo, 'accent_color', '#3498db');
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configurações - FaturaVault</title>
    <link rel="stylesheet" href="css/style.css?v=<?= time() ?>">
</head>
<body>
    <div class="container">
        <?php $activePage = 'settings'; $subtitle = 'Configurações'; include 'menu.php'; ?>

        <?php if ($successMsg): ?>
            <?php 
                $msgParts = explode('|', $successMsg);
                $msgType = $msgParts[0] ?? 'sucesso';
                $msgText = $msgParts[1] ?? '';
            ?>
            <div class="mensagem <?= htmlspecialchars($msgType) ?>"><?= htmlspecialchars($msgText) ?></div>
        <?php endif; ?>

        <?php if (isset($_GET['restore'])): ?>
            <div class="mensagem <?= $_GET['restore'] === 'success' ? 'sucesso' : 'erro' ?>">
                <?= htmlspecialchars($_GET['msg'] ?? '') ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="settings-lado-a-lado">
                <fieldset>
                    <legend>Empresa</legend>
                    <div class="campo">
                        <label for="company_name">Nome da Empresa:</label>
                        <input type="text" id="company_name" name="company_name" value="<?= htmlspecialchars($companyName) ?>" placeholder="Ex: Empresa Exemplo, Lda.">
                    </div>
                    <div class="campo">
                        <label for="company_vat">NIF da Empresa:</label>
                        <input type="text" id="company_vat" name="company_vat" value="<?= htmlspecialchars($companyVat) ?>" placeholder="Ex: 510314600">
                    </div>
                </fieldset>

                <fieldset>
                    <legend>Cor de Destaque</legend>
                    <input type="hidden" id="accent_color" name="accent_color" value="<?= htmlspecialchars($accentColor) ?>">
                    <div class="cor-opcoes">
                        <label class="cor-opcao" onclick="selectColor('#b45309')">
                            <span class="cor-circulo" style="background:#b45309"></span>
                            <span class="cor-nome">Laranja</span>
                            <span class="cor-hex">#b45309</span>
                        </label>
                        <label class="cor-opcao" onclick="selectColor('#195b9a')">
                            <span class="cor-circulo" style="background:#195b9a"></span>
                            <span class="cor-nome">Azul</span>
                            <span class="cor-hex">#195b9a</span>
                        </label>
                        <label class="cor-opcao" onclick="selectColor('#711ea9')">
                            <span class="cor-circulo" style="background:#711ea9"></span>
                            <span class="cor-nome">Roxo</span>
                            <span class="cor-hex">#711ea9</span>
                        </label>
                        <label class="cor-opcao" onclick="selectColor('#1e7e11')">
                            <span class="cor-circulo" style="background:#1e7e11"></span>
                            <span class="cor-nome">Verde</span>
                            <span class="cor-hex">#1e7e11</span>
                        </label>
                        <label class="cor-opcao" onclick="selectColor('#cb2a2a')">
                            <span class="cor-circulo" style="background:#cb2a2a"></span>
                            <span class="cor-nome">Vermelho</span>
                            <span class="cor-hex">#cb2a2a</span>
                        </label>
                        <div class="cor-opcao-wrap">
                            <label class="cor-opcao cor-opcao-custom" id="custom_option" onclick="toggleCustomInput()">
                                <span class="cor-circulo" id="custom_circle" style="background:<?= htmlspecialchars($accentColor) ?>"></span>
                                <span class="cor-nome">Personalizada</span>
                                <span class="cor-hex" id="custom_hex"><?= htmlspecialchars($accentColor) ?></span>
                            </label>
                            <div id="custom_input_wrap" class="cor-custom-input" style="display:none;">
                                <input type="text" id="custom_color_input" value="<?= htmlspecialchars($accentColor) ?>" placeholder="#000000" maxlength="7" oninput="applyCustomColor(this.value)">
                            </div>
                        </div>
                    </div>
                </fieldset>
            </div>

            <button type="submit" class="btn btn-principal btn-grande">Guardar Configurações</button>
        </form>

        <div class="settings-separador"></div>

        <fieldset>
            <legend>Sócios da Empresa</legend>
            <p class="campo-descricao">Apenas os sócios podem receber suprimentos (adiantamentos). Adicione todos os sócios da empresa e respetivas participações.</p>
            
            <?php if ($partnerAction !== 'add' && $partnerAction !== 'edit'): ?>
                <button type="button" class="btn btn-principal" onclick="location.href='settings.php?partner_action=add'">+ Adicionar Sócio</button>
                
                <?php if (count($partners) > 0): ?>
                    <div class="table-responsive" style="margin-top: 20px;">
                    <table>
                        <thead>
                            <tr>
                                <th>Nome</th>
                                <th>Email</th>
                                <th>Participação</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($partners as $p): ?>
                            <tr>
                                <td><?= htmlspecialchars($p['name']) ?></td>
                                <td><?= htmlspecialchars($p['email'] ?: '-') ?></td>
                                <td><?= htmlspecialchars($p['percentage']) ?>%</td>
                                <td class="acoes">
                                    <a href="settings.php?partner_action=edit&partner_id=<?= $p['id'] ?>" class="btn btn-pequeno">Editar</a>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="action" value="delete_partner">
                                        <input type="hidden" name="partner_id" value="<?= $p['id'] ?>">
                                        <button type="submit" class="btn btn-pequeno btn-perigo" onclick="return confirm('Tem a certeza que deseja eliminar este sócio?')">Apagar</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                <?php else: ?>
                    <div class="vazio" style="margin-top: 20px; padding: 30px;">
                        <p>Ainda não tem sócios registados.</p>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="modal-conteudo" style="padding: 20px; border-radius: 8px; margin-top: 15px; border: 1px solid #ddd;">
                    <form method="POST">
                        <input type="hidden" name="action" value="save_partner">
                        <?php if ($partnerToEdit): ?>
                            <input type="hidden" name="partner_id" value="<?= $partnerToEdit['id'] ?>">
                        <?php endif; ?>
                        
                        <div class="campo">
                            <label for="partner_name">Nome do Sócio:</label>
                            <input type="text" id="partner_name" name="partner_name" value="<?= htmlspecialchars($partnerToEdit['name'] ?? '') ?>" placeholder="Nome completo" required>
                        </div>
                        
                        <div class="campo">
                            <label for="partner_email">Email:</label>
                            <input type="email" id="partner_email" name="partner_email" value="<?= htmlspecialchars($partnerToEdit['email'] ?? '') ?>" placeholder="email@exemplo.com">
                        </div>
                        
                        <div class="campo">
                            <label for="partner_percentage">Participação (%):</label>
                            <input type="number" id="partner_percentage" name="partner_percentage" value="<?= htmlspecialchars($partnerToEdit['percentage'] ?? '0') ?>" min="0" max="100" step="0.01" placeholder="0" required>
                        </div>
                        
                        <div style="display: flex; gap: 10px;">
                            <button type="submit" class="btn btn-principal">Guardar Sócio</button>
                            <a href="settings.php" class="btn">Cancelar</a>
                        </div>
                    </form>
                </div>
            <?php endif; ?>
        </fieldset>

        <div class="settings-separador"></div>

        <form method="POST">
            <fieldset>
                <legend>Azure AI (Leitura de Faturas sem QR Code)</legend>
                <p class="campo-descricao">Estas configurações são opcionais. Apenas necessárias para analisar faturas que não tenham QR code, utilizando o serviço Azure AI Document Intelligence.</p>
                <div class="campo">
                    <label for="azure_endpoint">Endpoint do Azure AI:</label>
                    <input type="text" id="azure_endpoint" name="azure_endpoint" value="<?= htmlspecialchars($azureEndpoint) ?>" placeholder="Ex: https://nome-do-recurso.cognitiveservices.azure.com">
                </div>
                <div class="campo">
                    <label for="azure_key">Chave de Acesso:</label>
                    <div class="campo-password">
                        <input type="password" id="azure_key" name="azure_key" value="<?= htmlspecialchars($azureKey) ?>" placeholder="Chave do recurso Azure AI">
                        <button type="button" class="btn-mostrar" onclick="toggleAzureKey()" title="Mostrar/Ocultar">👁</button>
                    </div>
                </div>
<?php $azureConfigured = ($azureEndpoint !== '' && $azureKey !== ''); ?>
                <div class="campo" style="margin-top: 10px;">
                    <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; <?= !$azureConfigured ? 'opacity: 0.5;' : '' ?>">
                        <input type="checkbox" id="azure_auto_analyze" name="azure_auto_analyze" value="1" <?= $azureAutoAnalyze === '1' || $azureAlwaysAnalyze === '1' ? 'checked' : '' ?> <?= $azureAlwaysAnalyze === '1' || !$azureConfigured ? 'disabled' : '' ?>>
                        Analisar automaticamente faturas sem QR Code
                    </label>
                    <p class="campo-descricao" style="margin-top: 5px;">Quando ativo, as faturas sem QR Code são enviadas automaticamente para o Azure AI sem pedir confirmação. Extrai também o conteúdo completo da fatura para permitir pesquisa por itens e descrições.</p>
                </div>
                <div class="campo" style="margin-top: 10px;">
                    <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; <?= !$azureConfigured ? 'opacity: 0.5;' : '' ?>">
                        <input type="checkbox" id="azure_always_analyze" name="azure_always_analyze" value="1" <?= $azureAlwaysAnalyze === '1' ? 'checked' : '' ?> <?= !$azureConfigured ? 'disabled' : '' ?> onchange="var auto = document.getElementById('azure_auto_analyze'); if(this.checked) { auto.checked = true; auto.disabled = true; } else { auto.disabled = false; }">
                        Analisar sempre com Azure AI (mesmo com QR Code)
                    </label>
                    <p class="campo-descricao" style="margin-top: 5px;">Extrai o conteúdo completo da fatura para permitir pesquisa por itens e descrições. Ativa automaticamente a opção acima. Quando existe QR Code, os dados fiscais do QR mantêm precedência.</p>
                </div>
                <?php if ($azureAlwaysAnalyze === '1'): ?>
                    <input type="hidden" name="azure_auto_analyze" value="1">
                <?php endif; ?>
            </fieldset>

            <button type="submit" class="btn btn-principal btn-grande">Guardar Configurações Azure AI</button>
        </form>

        <div class="settings-separador"></div>

        <form method="POST">
            <fieldset>
                <legend>Assistente IA (Chat)</legend>
                <p class="campo-descricao">Configure o fornecedor de IA para o assistente de pesquisa por linguagem natural. Pode guardar as configurações de ambos, mas apenas um pode estar ativo.</p>
                
                <div class="campo">
                    <label>Fornecedor ativo:</label>
                    <div style="display:flex;gap:12px;margin-top:4px;">
                        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-weight:normal;">
                            <input type="radio" name="chat_provider" value="ollama" <?= $chatProvider === 'ollama' ? 'checked' : '' ?> onchange="toggleProviderUI()">
                            Ollama (local)
                        </label>
                        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-weight:normal;">
                            <input type="radio" name="chat_provider" value="azure_openai" <?= $chatProvider === 'azure_openai' ? 'checked' : '' ?> onchange="toggleProviderUI()">
                            Azure OpenAI
                        </label>
                    </div>
                </div>

                <div id="ollama-config" style="margin-top:12px;">
                    <h4 style="margin:0 0 8px;color:var(--accent);">Configuração Ollama</h4>
                    <div class="campo">
                        <label for="ollama_host">Endereço do servidor:</label>
                        <input type="text" id="ollama_host" name="ollama_host" value="<?= htmlspecialchars($ollamaHost) ?>" placeholder="Ex: http://192.168.1.100:11434">
                    </div>
                    <div class="campo">
                        <label for="ollama_model">Modelo:</label>
                        <input type="text" id="ollama_model" name="ollama_model" value="<?= htmlspecialchars($ollamaModel) ?>" placeholder="Ex: llama3">
                    </div>
                </div>

                <div id="azure-openai-config" style="margin-top:12px;">
                    <h4 style="margin:0 0 8px;color:var(--accent);">Configuração Azure OpenAI</h4>
                    <div class="campo">
                        <label for="azure_openai_endpoint">Endpoint:</label>
                        <input type="text" id="azure_openai_endpoint" name="azure_openai_endpoint" value="<?= htmlspecialchars($azureOpenaiEndpoint) ?>" placeholder="Ex: https://myresource.openai.azure.com">
                    </div>
                    <div class="campo">
                        <label for="azure_openai_key">API Key:</label>
                        <div style="display:flex;gap:6px;align-items:center;">
                            <input type="password" id="azure_openai_key" name="azure_openai_key" value="<?= htmlspecialchars($azureOpenaiKey) ?>" placeholder="Chave do recurso Azure" style="flex:1;">
                            <button type="button" onclick="toggleKeyVisibility('azure_openai_key', 'btn-toggle-azure-key')" id="btn-toggle-azure-key" class="btn" style="padding:6px 10px;font-size:0.85rem;">👁</button>
                        </div>
                    </div>
                    <div class="campo">
                        <label for="azure_openai_deployment">Deployment (modelo):</label>
                        <input type="text" id="azure_openai_deployment" name="azure_openai_deployment" value="<?= htmlspecialchars($azureOpenaiDeployment) ?>" placeholder="Ex: gpt-4o-mini">
                    </div>
                </div>
            </fieldset>
            <button type="submit" class="btn btn-principal btn-grande">Guardar Configurações do Assistente</button>
        </form>

        <script>
        function toggleProviderUI() {
            var provider = document.querySelector('input[name="chat_provider"]:checked').value;
            document.getElementById('ollama-config').style.opacity = provider === 'ollama' ? '1' : '0.5';
            document.getElementById('azure-openai-config').style.opacity = provider === 'azure_openai' ? '1' : '0.5';
        }
        function toggleKeyVisibility(inputId, btnId) {
            var input = document.getElementById(inputId);
            var btn = document.getElementById(btnId);
            if (input.type === 'password') {
                input.type = 'text';
                btn.textContent = '🔒';
            } else {
                input.type = 'password';
                btn.textContent = '👁';
            }
        }
        toggleProviderUI();
        </script>

        <div class="settings-separador"></div>

        <fieldset>
            <legend>Cópias de Segurança</legend>
            <div class="backup-section">
                <div class="backup-item">
                    <div>
                        <strong>Exportar Backup</strong>
                        <p class="campo-descricao">Descarrega um ficheiro ZIP com todas as faturas e respetivos PDFs.</p>
                    </div>
                    <a href="backup.php" class="btn btn-principal">Descarregar Backup</a>
                </div>
                <div class="backup-item">
                    <div>
                        <strong>Importar Backup</strong>
                        <p class="campo-descricao">Carrega um ficheiro ZIP de backup. Faturas duplicadas são ignoradas automaticamente.</p>
                    </div>
                    <form action="restore.php" method="POST" enctype="multipart/form-data" class="backup-upload-form">
                        <input type="file" name="backup_file" accept=".zip" required>
                        <button type="submit" class="btn btn-principal" onclick="return confirm('Tem a certeza que pretende importar este backup?')">Importar</button>
                    </form>
                </div>
            </div>
        </fieldset>
    </div>

    <script>
    var presetColors = ['#b45309','#195b9a','#711ea9','#1e7e11','#cb2a2a'];

    function toggleAzureKey() {
        var input = document.getElementById('azure_key');
        input.type = input.type === 'password' ? 'text' : 'password';
    }

    function selectColor(color) {
        document.getElementById('accent_color').value = color;
        document.querySelectorAll('.cor-opcao').forEach(function(el) { el.classList.remove('cor-selecionada'); });
        document.querySelectorAll('.cor-opcao-wrap').forEach(function(el) { el.classList.remove('aberto'); });
        document.querySelectorAll('.cor-opcao').forEach(function(el) {
            var hex = el.querySelector('.cor-hex');
            if (hex && hex.textContent.toLowerCase() === color.toLowerCase()) el.classList.add('cor-selecionada');
        });
        document.documentElement.style.setProperty('--accent', color);
        if (presetColors.indexOf(color.toLowerCase()) !== -1) {
            document.getElementById('custom_input_wrap').style.display = 'none';
        }
    }

    function toggleCustomInput() {
        var wrap = document.getElementById('custom_input_wrap');
        var opcaoWrap = wrap.parentElement;
        if (wrap.style.display === 'none') {
            wrap.style.display = 'block';
            opcaoWrap.classList.add('aberto');
            document.getElementById('custom_color_input').focus();
            var val = document.getElementById('custom_color_input').value;
            if (/^#[0-9a-fA-F]{6}$/.test(val)) {
                applyCustomColor(val);
            }
            document.querySelectorAll('.cor-opcao').forEach(function(el) { el.classList.remove('cor-selecionada'); });
            document.getElementById('custom_option').classList.add('cor-selecionada');
        } else {
            wrap.style.display = 'none';
            opcaoWrap.classList.remove('aberto');
        }
    }

    function applyCustomColor(val) {
        if (/^#[0-9a-fA-F]{6}$/.test(val)) {
            document.getElementById('accent_color').value = val;
            document.getElementById('custom_circle').style.background = val;
            document.getElementById('custom_hex').textContent = val;
            document.documentElement.style.setProperty('--accent', val);
            document.querySelectorAll('.cor-opcao').forEach(function(el) { el.classList.remove('cor-selecionada'); });
            document.getElementById('custom_option').classList.add('cor-selecionada');
        }
    }

    (function() {
        var current = '<?= htmlspecialchars($accentColor) ?>'.toLowerCase();
        var isPreset = presetColors.indexOf(current) !== -1;
        selectColor(current);
        if (!isPreset) {
            document.getElementById('custom_input_wrap').style.display = 'block';
            document.getElementById('custom_option').classList.add('cor-selecionada');
        }
    })();
    </script>
</body>
</html>
