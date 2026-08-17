<?php
// Componente de navegação reutilizável - incluído em todas as páginas
// A variável $activePage define qual o botão que fica destacado
$activePage = $activePage ?? '';
$accentColor = getConfig($pdo, 'accent_color', '#3498db');
?>
<!-- Injeta a cor de destaque como variável CSS -->
<style>:root { --accent: <?= htmlspecialchars($accentColor) ?>; }</style>
<header>
    <h1>FaturaVault</h1>
    <p><?= !empty($companyName) ? htmlspecialchars($companyName) . ' — ' : '' ?><?= $subtitle ?? 'Gestão e armazenamento de faturas' ?></p>
</header>

<nav>
    <a href="index.php" class="btn <?= $activePage === 'home' ? 'btn-principal' : '' ?>">Início</a>
    <a href="invoices.php" class="btn <?= $activePage === 'invoices' ? 'btn-principal' : '' ?>">Faturas</a>
    <a href="upload.php" class="btn <?= $activePage === 'upload' ? 'btn-principal' : '' ?>">Nova Fatura</a>
    <a href="bank_movements.php" class="btn <?= $activePage === 'bank' ? 'btn-principal' : '' ?>">Conta Bancária</a>
    <a href="advances.php" class="btn <?= $activePage === 'advances' ? 'btn-principal' : '' ?>">Suprimentos</a>
    <a href="settings.php" class="btn <?= $activePage === 'settings' ? 'btn-principal' : '' ?>">Configurações</a>
    <span class="nav-espaco"></span>
    <span class="nav-utilizador"><?= htmlspecialchars($_SESSION['user_name']) ?></span>
    <a href="logout.php" class="btn btn-perigo">Sair</a>
</nav>

<button id="btn-tema" class="btn-tema" onclick="toggleTheme()" title="Mudar tema">🔆</button>

<?php 
$chatProvider = getConfig($pdo, 'chat_provider', 'ollama');
$ollamaHost = getConfig($pdo, 'ollama_host');
$azureOpenaiKey = getConfig($pdo, 'azure_openai_key');
$chatAtivo = ($chatProvider === 'ollama' && $ollamaHost !== '') || ($chatProvider === 'azure_openai' && $azureOpenaiKey !== '');
if ($chatAtivo): 
?>
<button id="btn-chat" class="btn-chat" onclick="toggleChat()" title="Assistente IA">💬</button>

<div id="chat-window" class="chat-window">
    <div class="chat-header">
        <span>Assistente IA</span>
        <div>
            <button onclick="newChat()" title="Nova conversa" class="chat-header-btn">🗑</button>
            <button onclick="toggleChat()" title="Fechar" class="chat-header-btn">✕</button>
        </div>
    </div>
    <div id="chat-messages" class="chat-messages">
        <div class="chat-msg chat-bot">Olá! Posso ajudar a pesquisar faturas. Experimente perguntar sobre um fornecedor, valor ou produto.</div>
    </div>
    <div class="chat-input-area">
        <input type="text" id="chat-input" placeholder="Escreva a sua pergunta..." onkeydown="if(event.key==='Enter')sendChat()">
        <button onclick="sendChat()" class="btn btn-principal" id="chat-send-btn">Enviar</button>
    </div>
</div>

<script>
var chatHistory = JSON.parse(sessionStorage.getItem('chatHistory') || '[]');
var chatOpen = sessionStorage.getItem('chatOpen') === 'true';

(function() {
    if (chatOpen) {
        document.getElementById('chat-window').classList.add('chat-aberto');
    }
    // Restaurar mensagens
    if (chatHistory.length > 0) {
        var container = document.getElementById('chat-messages');
        container.innerHTML = '';
        chatHistory.forEach(function(msg) {
            appendMessage(msg.role === 'user' ? 'chat-user' : 'chat-bot', msg.display);
            if (msg.role === 'assistant' && msg.invoices && msg.invoices.length > 0) {
                appendFaturaCards(msg.invoices);
            }
        });
    }
})();

function toggleChat() {
    var win = document.getElementById('chat-window');
    if (!win.classList.contains('chat-aberto')) {
        win.classList.add('chat-aberto');
        sessionStorage.setItem('chatOpen', 'true');
        document.getElementById('chat-input').focus();
    } else {
        win.classList.remove('chat-aberto');
        sessionStorage.setItem('chatOpen', 'false');
    }
}

function newChat() {
    chatHistory = [];
    sessionStorage.setItem('chatHistory', '[]');
    var container = document.getElementById('chat-messages');
    container.innerHTML = '<div class="chat-msg chat-bot">Olá! Posso ajudar a pesquisar faturas. Experimente perguntar sobre um fornecedor, valor ou produto.</div>';
}

function formatChat(text) {
    // Remover qualquer link markdown: [texto](url)
    text = text.replace(/\[[^\]]*\]\([^)]+\)/gi, '');
    // Remover a linha FATURAS: ... do final
    text = text.replace(/\n*FATURAS:.*$/gim, '');
    // Remover referências a faturas (Fatura #ID, FAC xxx, etc)
    text = text.replace(/\[?[Ff]atura\s*#?\d+[^\]]*\]?/gi, '');
    text = text.replace(/\bFAC\s*[\w\/]+/gi, '');
    text = text.replace(/\(ID\s*=\s*\d+\)/gi, '');
    // Remover a palavra "fatura" seguida de asteriscos ou mascarada
    text = text.replace(/fatura\s*\*+[^*]*\**/gi, '');
    // Limpar asteriscos órfãos (3 ou mais seguidos)
    text = text.replace(/\*{3,}/g, '');
    // Negrito: **texto**
    text = text.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
    // Remover asteriscos soltos restantes
    text = text.replace(/\*{2,}/g, '');
    // Itálico: *texto* (apenas se não for asterisco solto)
    text = text.replace(/\*([^*]+)\*/g, '<em>$1</em>');
    // Limpar espaços e pontuação órfã
    text = text.replace(/\s{2,}/g, ' ');
    text = text.replace(/^\s*[–\-—,;:()]\s*/gm, '');
    // Quebras de linha
    text = text.replace(/\n{3,}/g, '\n\n');
    text = text.replace(/\n/g, '<br>');
    text = text.replace(/(<br>\s*){3,}/g, '<br><br>');
    return text.trim();
}
function appendMessage(cls, text) {
    var container = document.getElementById('chat-messages');
    var div = document.createElement('div');
    div.className = 'chat-msg ' + cls;
    div.innerHTML = formatChat(text);
    container.appendChild(div);
    container.scrollTop = container.scrollHeight;
}
function appendFaturaCards(invoices) {
    var container = document.getElementById('chat-messages');
    invoices.forEach(function(f) {
        var card = document.createElement('a');
        card.href = 'view.php?id=' + f.id;
        card.className = 'chat-msg chat-bot chat-fatura-card';
        var label = 'Fatura #' + f.id + ' — ' + f.supplier;
        if (f.total) label += ' (€' + parseFloat(f.total).toFixed(2).replace('.', ',') + ')';
        card.innerHTML = '<span class="fatura-icon">📄</span><span class="fatura-info">' + label + '</span>';
        container.appendChild(card);
    });
    container.scrollTop = container.scrollHeight;
}

function sendChat() {
    var input = document.getElementById('chat-input');
    var msg = input.value.trim();
    if (!msg) return;

    input.value = '';
    appendMessage('chat-user', msg);

    // Adicionar ao histórico
    chatHistory.push({role: 'user', content: msg, display: msg});
    sessionStorage.setItem('chatHistory', JSON.stringify(chatHistory));

    // Mostrar indicador de escrita
    var typing = document.createElement('div');
    typing.className = 'chat-msg chat-bot chat-typing';
    typing.innerHTML = '<span class="typing-dots"><span></span><span></span><span></span></span>';
    document.getElementById('chat-messages').appendChild(typing);
    document.getElementById('chat-messages').scrollTop = document.getElementById('chat-messages').scrollHeight;

    // Desativar input
    input.disabled = true;
    document.getElementById('chat-send-btn').disabled = true;

    var apiHistory = chatHistory.filter(function(m) { return m.role === 'user' || m.role === 'assistant'; }).map(function(m) { return {role: m.role, content: m.content}; });
    // Remover a última (é a que estamos a enviar agora)
    apiHistory.pop();

    fetch('chat_api.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({message: msg, history: apiHistory})
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        typing.remove();
        input.disabled = false;
        document.getElementById('chat-send-btn').disabled = false;
        input.focus();

        if (data.error) {
            appendMessage('chat-bot', 'Erro: ' + data.error);
            return;
        }
        var invoices = data.invoices || [];
        appendMessage('chat-bot', data.reply);
        if (invoices.length > 0) {
            appendFaturaCards(invoices);
        }
        chatHistory.push({role: 'assistant', content: data.reply, display: data.reply, invoices: invoices});
        sessionStorage.setItem('chatHistory', JSON.stringify(chatHistory));
    })
    .catch(function(e) {
        typing.remove();
        input.disabled = false;
        document.getElementById('chat-send-btn').disabled = false;
        appendMessage('chat-bot', 'Erro de ligação ao servidor.');
    });
}
</script>
<?php endif; ?>

<script>
(function() {
    if (localStorage.getItem('theme') === 'dark') {
        document.body.classList.add('tema-escuro');
        document.getElementById('btn-tema').textContent = '🌙';
    }
})();
function toggleTheme() {
    var btn = document.getElementById('btn-tema');
    document.body.classList.toggle('tema-escuro');
    if (document.body.classList.contains('tema-escuro')) {
        localStorage.setItem('theme', 'dark');
        btn.textContent = '🌙';
    } else {
        localStorage.setItem('theme', 'light');
        btn.textContent = '🔆';
    }
}
</script>
