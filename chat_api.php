<?php
// API de chat com Ollama para pesquisa de faturas por linguagem natural
require_once 'config_helper.php';

header('Content-Type: application/json');

$ollamaHost = getConfig($pdo, 'ollama_host');
$ollamaModel = getConfig($pdo, 'ollama_model', 'llama3');
$azureOpenaiEndpoint = getConfig($pdo, 'azure_openai_endpoint');
$azureOpenaiKey = getConfig($pdo, 'azure_openai_key');
$azureOpenaiDeployment = getConfig($pdo, 'azure_openai_deployment');
$chatProvider = getConfig($pdo, 'chat_provider', 'ollama');

// Verificar se o fornecedor ativo está configurado
if ($chatProvider === 'azure_openai' && ($azureOpenaiEndpoint === '' || $azureOpenaiKey === '' || $azureOpenaiDeployment === '')) {
    echo json_encode(['error' => 'Azure OpenAI não está configurada. Aceda às Configurações e preencha endpoint, chave e deployment.']);
    exit;
}
if ($chatProvider === 'ollama' && $ollamaHost === '') {
    echo json_encode(['error' => 'Ollama não está configurado. Aceda às Configurações.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$userMessage = trim($input['message'] ?? '');
$history = $input['history'] ?? [];

if ($userMessage === '') {
    echo json_encode(['error' => 'Mensagem vazia.']);
    exit;
}

// Pesquisar faturas relevantes na BD com base em palavras-chave da mensagem
$contextInvoices = [];

// Extrair termos de pesquisa: limpar pontuação e manter apenas palavras úteis
$cleanMsg = preg_replace('/[?!.,;:\'"()\[\]{}\-]/', ' ', $userMessage);
$words = preg_split('/\s+/', $cleanMsg);

// Filtro: stop words + verbos/palavras comuns de conversa que não são úteis para pesquisa
$stopWords = ['a','o','e','de','do','da','dos','das','em','no','na','nos','nas','um','uma','ao','aos','à','às','que','se','me','te','lhe','ou','nem','já','mas','por','com','sem','para','pelo','pela','eu','tu','ele','ela','nós','eles','elas','seu','sua','meu','minha','este','esta','esse','essa','isso','isto','aqui','ali','lá',
    // Verbos e palavras comuns em perguntas sobre faturas
    'mostra','mostrar','quero','queria','quais','qual','onde','quando','como','todas','todos','delas','deles','dela','dele',
    'comprei','comprar','compraste','gastei','gastar','gastaste','quanto','quantas','quantos',
    'faturas','fatura','tenho','tens','tinha','tive','foram','pode','podes',
    'obrigado','obrigada','perfeito','fixe','okay','certo','pronto','muito','mais','menos',
    'sobre','entre','desde','ainda','também','depois','antes','agora','hoje','ontem',
    // Meses e datas
    'janeiro','fevereiro','março','abril','maio','junho','julho','agosto','setembro','outubro','novembro','dezembro',
    'mês','ano','dia','semana',
    // Verbos e nomes extra
    'compras','compra','ver','abre','abrir','lista','listar','preciso','informação','informações','detalhes','detalhe',
    'encontrei','encontraste','precisares','quiseres','perguntares','clica','mostro','alguma','coisa','dizeres',
    // Termos de campos de fatura (aparecem em todas as faturas via OCR)
    'valor','valores','preço','preços','montante','custo','custos','euros','data','nome','número','referência','nif','contribuinte','documento','pagamento','recibo',
    // Palavras de stats/resumo que não devem pesquisar na BD
    'maior','menor','gasto','gastos','total','resumo','categoria','categorias','fornecedor','fornecedores','movimento','movimentos','pendente','pendentes','mensal','meses'
];

// Também extrair termos das últimas mensagens do histórico para manter contexto
$historyTerms = [];
$recentHistory = array_slice($history, -4); // últimas 2 trocas
foreach ($recentHistory as $hMsg) {
    $hClean = preg_replace('/[?!.,;:\'"()\[\]{}\-]/', ' ', $hMsg['content'] ?? '');
    $hWords = preg_split('/\s+/', $hClean);
    foreach ($hWords as $hw) {
        if (mb_strlen($hw) > 3 && preg_match('/^[A-Z]/', $hw) && !in_array(mb_strtolower($hw), $stopWords)) {
            // Manter palavras com maiúscula inicial do histórico (nomes próprios: IKEA, Worten, etc.)
            $historyTerms[] = $hw;
        }
    }
}

$currentTerms = array_filter($words, function($w) use ($stopWords) { 
    return mb_strlen($w) > 3 && !in_array(mb_strtolower($w), $stopWords); 
});
// Para pesquisa na BD: termos atuais + histórico (para dar contexto ao LLM)
$searchTerms = array_merge($currentTerms, $historyTerms);
// Limpar termos para usar na pesquisa SQL
$searchTerms = array_map(function($w) { return mb_strtolower($w); }, $searchTerms);
$searchTerms = array_values(array_unique($searchTerms));
// Guardar se a mensagem atual tem termos próprios (para decidir cards)
$currentTermsClean = array_map(function($w) { return mb_strtolower($w); }, $currentTerms);
$hasOwnTerms = !empty($currentTermsClean);

// Se o utilizador pede para "ver/mostrar" algo, faz follow-up, ou pergunta sobre contexto anterior
$isShowRequest = preg_match('/(mostra|mostrar|ver|abre|abrir|visualizar)/iu', $userMessage);
$isFollowUp = preg_match('/(mais|outra|outras|outro|outros|todas|todos|resto|certeza|tenho mais|não são|faltam|além)/iu', $userMessage);
$isContextQuestion = preg_match('/(\?|qual|quando|onde|quanto|quem|quantas|quantos|diz|dizer|porqu[eê])/iu', $userMessage);
if (($isShowRequest || $isFollowUp || $isContextQuestion) && !$hasOwnTerms && !empty($historyTerms)) {
    $hasOwnTerms = true; // tratar como se tivesse termos — usar os do histórico para cards
}

// Estatísticas gerais — sempre disponíveis
$statsContext = "";

// Resumo mensal de faturas
$stmtStats = $pdo->query("SELECT DATE_FORMAT(document_date, '%Y-%m') as mes, COUNT(*) as total_faturas, SUM(total) as total_valor FROM invoices GROUP BY mes ORDER BY mes DESC LIMIT 12");
$monthlyStats = $stmtStats->fetchAll(PDO::FETCH_ASSOC);

$mesesPT = ['01'=>'Janeiro','02'=>'Fevereiro','03'=>'Março','04'=>'Abril','05'=>'Maio','06'=>'Junho','07'=>'Julho','08'=>'Agosto','09'=>'Setembro','10'=>'Outubro','11'=>'Novembro','12'=>'Dezembro'];

if (!empty($monthlyStats)) {
    $statsContext .= "RESUMO MENSAL DE FATURAS:\n";
    foreach ($monthlyStats as $ms) {
        $parts = explode('-', $ms['mes']);
        $nomeMes = ($mesesPT[$parts[1]] ?? $parts[1]) . ' ' . $parts[0];
        $statsContext .= "- {$nomeMes}: {$ms['total_faturas']} faturas, total €" . number_format($ms['total_valor'], 2, ',', '.') . "\n";
    }
    $statsContext .= "\n";
}

// Totais por categoria
$stmtCat = $pdo->query("SELECT category, COUNT(*) as total_faturas, SUM(total) as total_valor FROM invoices WHERE category IS NOT NULL AND category != '' GROUP BY category ORDER BY total_valor DESC");
$catStats = $stmtCat->fetchAll(PDO::FETCH_ASSOC);
if (!empty($catStats)) {
    $statsContext .= "TOTAIS POR CATEGORIA:\n";
    foreach ($catStats as $cs) {
        $statsContext .= "- {$cs['category']}: {$cs['total_faturas']} faturas, total €" . number_format($cs['total_valor'], 2, ',', '.') . "\n";
    }
    $statsContext .= "\n";
}

// Totais por fornecedor (top 10)
$stmtSup = $pdo->query("SELECT supplier_name, COUNT(*) as total_faturas, SUM(total) as total_valor FROM invoices GROUP BY supplier_name ORDER BY total_valor DESC LIMIT 10");
$supStats = $stmtSup->fetchAll(PDO::FETCH_ASSOC);
if (!empty($supStats)) {
    $statsContext .= "TOP FORNECEDORES:\n";
    foreach ($supStats as $ss) {
        $statsContext .= "- {$ss['supplier_name']}: {$ss['total_faturas']} faturas, total €" . number_format($ss['total_valor'], 2, ',', '.') . "\n";
    }
    $statsContext .= "\n";
}

// Suprimentos dos sócios
$stmtAdv = $pdo->query("SELECT p.name, SUM(a.amount) as total_suprimentos, COUNT(a.id) as num_entradas FROM advances a JOIN partners p ON a.partner_id = p.id WHERE a.status = 'active' GROUP BY p.id, p.name ORDER BY total_suprimentos DESC");
$advStats = $stmtAdv->fetchAll(PDO::FETCH_ASSOC);
if (!empty($advStats)) {
    $statsContext .= "SUPRIMENTOS DOS SÓCIOS (ativos):\n";
    $totalSup = 0;
    foreach ($advStats as $as) {
        $statsContext .= "- {$as['name']}: €" . number_format($as['total_suprimentos'], 2, ',', '.') . " ({$as['num_entradas']} entradas)\n";
        $totalSup += $as['total_suprimentos'];
    }
    $statsContext .= "- TOTAL: €" . number_format($totalSup, 2, ',', '.') . "\n\n";
}

// Movimentos bancários por conciliar
$stmtPending = $pdo->query("SELECT COUNT(*) as total, SUM(ABS(amount)) as valor FROM bank_movements WHERE status = 'pending'");
$pending = $stmtPending->fetch(PDO::FETCH_ASSOC);
$stmtMatched = $pdo->query("SELECT COUNT(*) as total FROM bank_movements WHERE status = 'matched'");
$matched = $stmtMatched->fetch(PDO::FETCH_ASSOC);
$statsContext .= "MOVIMENTOS BANCÁRIOS:\n";
$statsContext .= "- Pendentes (por conciliar): {$pending['total']} movimentos";
if ($pending['valor']) {
    $statsContext .= ", valor total €" . number_format($pending['valor'], 2, ',', '.');
}
$statsContext .= "\n- Conciliados: {$matched['total']} movimentos\n\n";

// Faturas sem movimento bancário associado
$stmtUnlinked = $pdo->query("SELECT COUNT(*) as total, SUM(i.total) as valor FROM invoices i LEFT JOIN bank_movements bm ON bm.invoice_id = i.id WHERE bm.id IS NULL");
$unlinked = $stmtUnlinked->fetch(PDO::FETCH_ASSOC);
$statsContext .= "FATURAS SEM MOVIMENTO ASSOCIADO:\n";
$statsContext .= "- {$unlinked['total']} faturas sem conciliação";
if ($unlinked['valor']) {
    $statsContext .= ", valor total €" . number_format($unlinked['valor'], 2, ',', '.');
}
$statsContext .= "\n\n";

// Comparação entre meses (últimos 6 meses com variação)
$stmtComp = $pdo->query("SELECT DATE_FORMAT(document_date, '%Y-%m') as mes, COUNT(*) as num, SUM(total) as valor FROM invoices GROUP BY mes ORDER BY mes DESC LIMIT 6");
$compStats = $stmtComp->fetchAll(PDO::FETCH_ASSOC);
if (count($compStats) >= 2) {
    $statsContext .= "COMPARAÇÃO MENSAL (últimos meses):\n";
    $prev = null;
    foreach (array_reverse($compStats) as $cs) {
        $parts = explode('-', $cs['mes']);
        $nomeMes = ($mesesPT[$parts[1]] ?? $parts[1]) . ' ' . $parts[0];
        $val = number_format($cs['valor'], 2, ',', '.');
        $line = "- {$nomeMes}: €{$val} ({$cs['num']} faturas)";
        if ($prev !== null && $prev > 0) {
            $diff = (($cs['valor'] - $prev) / $prev) * 100;
            $sinal = $diff >= 0 ? '+' : '';
            $line .= " [{$sinal}" . number_format($diff, 1) . "% vs mês anterior]";
        }
        $statsContext .= $line . "\n";
        $prev = $cs['valor'];
    }
    $statsContext .= "\n";
}

$showCards = false;
$cardInvoices = [];
if (!empty($searchTerms)) {
    // AND search: apenas termos da mensagem atual (mais preciso para cards)
    $andTerms = $currentTermsClean;
    if (empty($andTerms) && ($isShowRequest || $isFollowUp || $isContextQuestion) && !empty($historyTerms)) {
        $andTerms = array_values(array_unique(array_map('mb_strtolower', $historyTerms)));
    }
    
    if (!empty($andTerms)) {
        $andConditions = [];
        $andParams = [];
        foreach ($andTerms as $term) {
            $andConditions[] = "(supplier_name LIKE ? OR extracted_text LIKE ? OR document_number LIKE ? OR category LIKE ?)";
            $t = "%$term%";
            $andParams = array_merge($andParams, [$t, $t, $t, $t]);
        }
        
        $sql = "SELECT id, supplier_name, supplier_vat, document_number, document_date, total, category, extracted_text 
                FROM invoices 
                WHERE " . implode(' AND ', $andConditions) . "
                ORDER BY document_date DESC 
                LIMIT 20";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($andParams);
        $contextInvoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($contextInvoices)) {
            $showCards = true;
            $cardInvoices = $contextInvoices;
        }
    }
    
    // OR fallback com todos os termos (para contexto LLM, sem cards)
    if (empty($contextInvoices)) {
        $allConditions = [];
        $allParams = [];
        foreach ($searchTerms as $term) {
            $allConditions[] = "(supplier_name LIKE ? OR extracted_text LIKE ? OR document_number LIKE ? OR category LIKE ?)";
            $t = "%$term%";
            $allParams = array_merge($allParams, [$t, $t, $t, $t]);
        }
        
        $sql = "SELECT id, supplier_name, supplier_vat, document_number, document_date, total, category, extracted_text 
                FROM invoices 
                WHERE " . implode(' OR ', $allConditions) . "
                ORDER BY document_date DESC 
                LIMIT 20";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($allParams);
        $contextInvoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Se não encontrou nada por palavras, buscar as últimas faturas como contexto
$isGenericFallback = false;
if (empty($contextInvoices)) {
    $isGenericFallback = true;
    $stmt = $pdo->query("SELECT id, supplier_name, supplier_vat, document_number, document_date, total, category, extracted_text FROM invoices ORDER BY document_date DESC LIMIT 10");
    $contextInvoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Detetar se é uma pergunta de estatísticas (para decidir se mostra cards)
$isStatsQuery = preg_match('/(quantas|quantos|total|soma|somar|resumo|quanto|gastei|gast[oa]|mensal|meses|suprimento|pendente|concilia|comparar|evolução|média|maior|menor|gasto|gastos|categoria|categorias|fornecedor|fornecedores|bancário|bancarios|movimento)/iu', $userMessage);

// Detetar se é uma pesquisa por produto específico (precisa do texto extraído)
$isProductQuery = preg_match('/(comprei|compraste|produto|artigo|item|jarra|mesa|cadeira|caixa|lâmpada|parafuso|tinta|cabo|router)/iu', $userMessage);

// Construir contexto para o LLM — só incluir faturas se a mensagem atual tem termos de pesquisa relevantes
$invoiceContext = "";
if (!$isGenericFallback && $hasOwnTerms) {
    foreach ($contextInvoices as $inv) {
        $invoiceContext .= "- {$inv['supplier_name']} | {$inv['document_date']} | €{$inv['total']}\n";
        if ($isProductQuery && !empty($inv['extracted_text'])) {
            $invoiceContext .= "  Itens: {$inv['extracted_text']}\n";
        }
    }
    $numResults = count($contextInvoices);
    if (!empty($invoiceContext)) {
        $invoiceContext = "FATURAS ENCONTRADAS ({$numResults} resultados):\n" . $invoiceContext . "\n";
    }
}

$systemPrompt = "És um assistente informal que ajuda a consultar faturas. Data atual: " . date('d/m/Y') . ". Regras:

1. Fala SEMPRE em português de Portugal (PT-PT). Usa TU: compraste, tens, gastaste, precisares, quiseres. NUNCA uses \"você\", \"sua\", \"suas\", \"seu\". Em vez de \"você tem\" diz \"tens\". Em vez de \"sua fatura\" diz \"a tua\".
2. Sê breve e natural. Máximo 2-3 frases.
3. Se perguntarem por um produto específico E os dados mostrarem \"Itens:\", diz o nome do produto, a loja e a data.
4. Se perguntarem por todas as faturas de uma loja, diz APENAS quantas encontraste e as datas/totais. NÃO inventes nomes de produtos.
5. Nunca escrevas números de fatura, códigos, IDs nem a palavra \"fatura\". O sistema trata disso.
6. Usa **negrito** no nome do produto e da loja.
7. Se não encontrares, diz que não encontraste.
8. NÃO inventes dados. Se a informação não aparece nos DADOS abaixo, não a menciones. Diz apenas o que está nos dados.
9. Se não houver DADOS relevantes, responde naturalmente à conversa sem inventar.
10. Quando o utilizador pedir para ver/mostrar uma compra, diz que pode clicar no cartão que aparece em baixo para ver os detalhes. Os cartões clicáveis aparecem automaticamente.

Exemplos:
- Produto: \"Compraste uma **jarra** no **IKEA** em março de 2026.\"
- Listagem: \"Encontrei 2 compras no **IKEA**, uma em março e outra em abril de 2026.\"
- Despedida: \"De nada! Se precisares de mais alguma coisa, é só perguntares.\"
- Ver detalhes: \"Clica no cartão em baixo para veres os detalhes dessa compra.\"

DADOS:\n\n" . $statsContext . $invoiceContext;

// Construir mensagens para o Ollama
$messages = [['role' => 'system', 'content' => $systemPrompt]];
// Enviar histórico apenas quando a mensagem atual faz uma pesquisa relevante
if ($hasOwnTerms || $isStatsQuery) {
    foreach ($history as $msg) {
        $messages[] = ['role' => $msg['role'], 'content' => $msg['content']];
    }
}
$messages[] = ['role' => 'user', 'content' => $userMessage];

// Chamar o fornecedor de IA ativo
$ch = curl_init();
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 120);

if ($chatProvider === 'azure_openai') {
    // Azure OpenAI API
    $apiUrl = rtrim($azureOpenaiEndpoint, '/') . '/openai/deployments/' . $azureOpenaiDeployment . '/chat/completions?api-version=2024-02-01';
    $payload = json_encode([
        'messages' => $messages,
        'max_tokens' => 1024
    ]);
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'api-key: ' . $azureOpenaiKey
    ]);
} else {
    // Ollama API
    $apiUrl = rtrim($ollamaHost, '/') . '/api/chat';
    $payload = json_encode([
        'model' => $ollamaModel,
        'messages' => $messages,
        'stream' => false,
        'think' => false
    ]);
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
}

$response = curl_exec($ch);

$providerNames = ['azure_openai' => 'Azure OpenAI', 'ollama' => 'Ollama'];
$providerName = $providerNames[$chatProvider] ?? $chatProvider;

if (curl_errno($ch)) {
    echo json_encode(['error' => 'Erro de ligação ao ' . $providerName . ': ' . curl_error($ch)]);
    curl_close($ch);
    exit;
}

$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    if ($httpCode === 429) {
        echo json_encode(['error' => 'Limite de pedidos excedido na ' . $providerName . '. Aguarda alguns segundos e tenta novamente, ou verifica se tens créditos na conta.']);
    } elseif ($httpCode === 401) {
        echo json_encode(['error' => 'API Key inválida ou expirada. Verifica a chave nas Configurações.']);
    } elseif ($httpCode === 404) {
        echo json_encode(['error' => 'Modelo/deployment não encontrado. Verifica o nome nas Configurações.']);
    } else {
        echo json_encode(['error' => 'Erro do ' . $providerName . ' (código ' . $httpCode . '). Verifica as configurações.']);
    }
    exit;
}

$data = json_decode($response, true);

if ($chatProvider === 'azure_openai') {
    $reply = $data['choices'][0]['message']['content'] ?? 'Sem resposta do modelo.';
} else {
    $reply = $data['message']['content'] ?? '';
    // Modelos com thinking podem devolver content vazio — extrair do thinking
    if ($reply === '' && !empty($data['message']['thinking'])) {
        // Tentar extrair a última frase/resposta do thinking (após "Final" ou no fim)
        $thinking = $data['message']['thinking'];
        // Procurar padrões como "Response:" ou "Final:" no thinking
        if (preg_match('/(?:Final|Response|Resposta|Output)[:\s]*["\']?(.+?)["\']?\s*$/isu', $thinking, $m)) {
            $reply = trim($m[1]);
        } else {
            // Usar as últimas linhas não-vazias do thinking como resposta
            $lines = array_filter(explode("\n", trim($thinking)), fn($l) => trim($l) !== '');
            $reply = trim(end($lines));
        }
    }
    if ($reply === '') {
        $reply = 'Desculpa, não consegui processar. Tenta reformular a pergunta.';
    }
}

// Preparar lista de faturas para os cards
$matchedInvoices = [];
if ($showCards) {
    foreach ($cardInvoices as $inv) {
        $matchedInvoices[] = [
            'id' => $inv['id'],
            'supplier' => $inv['supplier_name'],
            'date' => $inv['document_date'],
            'total' => $inv['total']
        ];
    }
}

echo json_encode(['reply' => $reply, 'invoices' => $matchedInvoices]);
