<?php
// Página de gestão de movimentos bancários
require_once 'config_helper.php';

$successMsg = '';
$errorMsg = '';
$filter = $_GET['filter'] ?? 'all';
$page = intval($_GET['page'] ?? 1);
$pageSize = 15;

// Operações com movimentos
$action = $_POST['action'] ?? null;
$movementId = intval($_POST['movement_id'] ?? 0);

// Ligar fatura a movimento (apenas saídas)
if ($action === 'link_invoice') {
    $invoiceId = intval($_POST['invoice_id'] ?? 0);
    if ($movementId > 0 && $invoiceId > 0) {
        $stmt = $pdo->prepare("UPDATE bank_movements SET invoice_id = ?, status = 'matched' WHERE id = ? AND type = 'saida'");
        $stmt->execute([$invoiceId, $movementId]);
        if ($stmt->rowCount() > 0) {
            $successMsg = 'Fatura associada ao movimento com sucesso!';
        } else {
            $errorMsg = 'Só é possível associar faturas a saídas de dinheiro.';
        }
    }
}

// Remover ligação de fatura
if ($action === 'unlink_invoice') {
    if ($movementId > 0) {
        $stmt = $pdo->prepare("UPDATE bank_movements SET invoice_id = NULL, status = 'pending' WHERE id = ?");
        $stmt->execute([$movementId]);
        $successMsg = 'Associação de fatura removida!';
    }
}

// Apagar movimento
if ($action === 'delete_movement') {
    if ($movementId > 0) {
        $stmt = $pdo->prepare("DELETE FROM bank_movements WHERE id = ?");
        $stmt->execute([$movementId]);
        $successMsg = 'Movimento eliminado!';
    }
}

// Buscar movimentos
$whereClause = '';
switch ($filter) {
    case 'entrada':
        $whereClause = "WHERE type = 'entrada'";
        break;
    case 'saida':
        $whereClause = "WHERE type = 'saida'";
        break;
    case 'unmatched':
        $whereClause = "WHERE invoice_id IS NULL AND type = 'saida'";
        break;
}

$stmt = $pdo->query("SELECT COUNT(*) as total FROM bank_movements $whereClause");
$total = $stmt->fetch()['total'];
$totalPages = ceil($total / $pageSize);
$offset = ($page - 1) * $pageSize;

$stmt = $pdo->query("
    SELECT bm.*, inv.supplier_name, inv.total
    FROM bank_movements bm
    LEFT JOIN invoices inv ON bm.invoice_id = inv.id
    $whereClause
    ORDER BY bm.movement_date DESC
    LIMIT $pageSize OFFSET $offset
");
$movements = $stmt->fetchAll();

// Para sugestões de fatura, buscar movimentos
function suggestInvoice($pdo, $movement) {
    // Buscar fatura pela data (mesma data) e valor próximo
    $stmt = $pdo->prepare("
        SELECT * FROM invoices
        WHERE ABS(DATEDIFF(document_date, ?)) <= 3
        AND ABS(total - ?) < 1
        ORDER BY ABS(DATEDIFF(document_date, ?)), ABS(total - ?)
        LIMIT 5
    ");
    $stmt->execute([$movement['movement_date'], $movement['amount'], $movement['movement_date'], $movement['amount']]);
    return $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Movimentos Bancários - FaturaVault</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="container">
        <?php $activePage = 'bank'; include 'menu.php'; ?>
        
        <header>
            <h1>Movimentos Bancários</h1>
            <p>Gestão de movimentos e ligação com faturas</p>
        </header>

        <?php if ($successMsg): ?>
            <div class="mensagem sucesso"><?= htmlspecialchars($successMsg) ?></div>
        <?php endif; ?>
        <?php if ($errorMsg): ?>
            <div class="mensagem erro"><?= htmlspecialchars($errorMsg) ?></div>
        <?php endif; ?>

        <div style="display: flex; gap: 10px; margin-bottom: 20px;">
            <a href="import_bank.php" class="btn btn-principal">+ Importar CSV</a>
            <a href="bank_movements.php?filter=all" class="btn <?= $filter === 'all' ? 'btn-principal' : '' ?>">Todos (<?= $total ?>)</a>
            <a href="bank_movements.php?filter=entrada" class="btn <?= $filter === 'entrada' ? 'btn-principal' : '' ?>">Entradas</a>
            <a href="bank_movements.php?filter=saida" class="btn <?= $filter === 'saida' ? 'btn-principal' : '' ?>">Saídas</a>
            <a href="bank_movements.php?filter=unmatched" class="btn <?= $filter === 'unmatched' ? 'btn-principal' : '' ?>">Sem Fatura</a>
        </div>

        <?php if (count($movements) > 0): ?>
            <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>Tipo</th>
                        <th>Descrição</th>
                        <th>Valor</th>
                        <th>Fatura Ligada</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($movements as $mov): ?>
                    <tr>
                        <td><?= date('d/m/Y', strtotime($mov['movement_date'])) ?></td>
                        <td>
                            <span style="display: inline-block; padding: 4px 8px; border-radius: 4px; font-size: 0.85em; font-weight: bold;
                                background: <?= $mov['type'] === 'entrada' ? '#d4edda' : '#f8d7da' ?>;
                                color: <?= $mov['type'] === 'entrada' ? '#155724' : '#721c24' ?>;">
                                <?= $mov['type'] === 'entrada' ? 'Entrada' : 'Saída' ?>
                            </span>
                        </td>
                        <td><?= htmlspecialchars($mov['description']) ?></td>
                        <td><strong><?= number_format($mov['amount'], 2, ',', '.') ?> €</strong></td>
                        <td>
                            <?php if ($mov['invoice_id']): ?>
                                <span style="color: green;">✓ <?= htmlspecialchars($mov['supplier_name']) ?></span>
                            <?php else: ?>
                                <span style="color: orange;">Sem fatura</span>
                            <?php endif; ?>
                        </td>
                        <td class="acoes">
                            <?php if ($mov['type'] === 'saida'): ?>
                            <button class="btn btn-pequeno" onclick="openLinkModal(<?= $mov['id'] ?>, '<?= addslashes(htmlspecialchars($mov['description'])) ?>', <?= $mov['amount'] ?>, '<?= $mov['movement_date'] ?>')">Associar</button>
                            <?php if ($mov['invoice_id']): ?>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="unlink_invoice">
                                    <input type="hidden" name="movement_id" value="<?= $mov['id'] ?>">
                                    <button type="submit" class="btn btn-pequeno">Desassociar</button>
                                </form>
                            <?php endif; ?>
                            <?php endif; ?>
                            <?php if (!$mov['invoice_id']): ?>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="delete_movement">
                                    <input type="hidden" name="movement_id" value="<?= $mov['id'] ?>">
                                    <button type="submit" class="btn btn-pequeno btn-perigo" onclick="return confirm('Tem a certeza que deseja eliminar este movimento?')">Apagar</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>

            <?php if ($totalPages > 1): ?>
                <div class="paginacao" style="margin-top: 20px;">
                    <?php if ($page > 1): ?>
                        <a href="bank_movements.php?page=<?= $page - 1 ?>&filter=<?= $filter ?>" class="btn btn-pequeno">← Anterior</a>
                    <?php endif; ?>
                    
                    <span style="padding: 8px; display: flex; align-items: center;">Página <?= $page ?> de <?= $totalPages ?></span>
                    
                    <?php if ($page < $totalPages): ?>
                        <a href="bank_movements.php?page=<?= $page + 1 ?>&filter=<?= $filter ?>" class="btn btn-pequeno">Seguinte →</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="vazio">
                <p>Não há movimentos bancários.</p>
                <a href="import_bank.php" class="btn btn-principal">Importar Movimento do Banco</a>
            </div>
        <?php endif; ?>
    </div>

    <!-- Modal para ligar fatura -->
    <div id="linkModal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:999;">
        <div class="modal-conteudo" style="position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); padding:30px; border-radius:8px; width:90%; max-width:500px; box-shadow:0 4px 20px rgba(0,0,0,0.3); max-height:80vh; overflow-y:auto;">
            <h2 id="modalTitle" style="margin-bottom: 20px;"></h2>
            
            <div id="invoiceSuggestions" style="margin-bottom: 20px;"></div>
            
            <form method="POST" id="linkForm">
                <input type="hidden" name="action" value="link_invoice">
                <input type="hidden" name="movement_id" id="modalMovementId">
                <input type="hidden" name="invoice_id" id="invoiceIdHidden">
                
                <div class="campo">
                    <label for="invoiceIdManual">ID da fatura:</label>
                    <input type="number" id="invoiceIdManual" min="1" placeholder="Clique numa sugestão ou introduza o ID" style="width: 100%; padding: 10px; border: 1px solid #bdc3c7; border-radius: 5px;">
                </div>
                
                <div style="display: flex; gap: 10px; margin-top: 20px;">
                    <button type="submit" class="btn btn-principal">Associar Fatura</button>
                    <button type="button" class="btn" onclick="closeModal()">Cancelar</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function openLinkModal(movementId, description, amount, date) {
        document.getElementById('linkModal').style.display = 'block';
        document.getElementById('modalMovementId').value = movementId;
        document.getElementById('modalTitle').textContent = 'Associar Fatura: ' + description;
        document.getElementById('invoiceIdManual').value = '';
        document.getElementById('invoiceSuggestions').innerHTML = '<p style="color:#999;">A carregar sugestões...</p>';
        
        fetch('api_get_invoices.php?q=' + encodeURIComponent(description) + '&amount=' + amount + '&date=' + encodeURIComponent(date))
            .then(r => r.json())
            .then(invoices => {
                document.getElementById('invoiceSuggestions').innerHTML = '';
                if (invoices.length > 0) {
                    let html = '<div class="sugestoes-box" style="padding: 10px; border-radius: 5px;"><strong>Sugestões (clique para selecionar):</strong>';
                    invoices.forEach(inv => {
                        var label = '#' + inv.id + ' — ' + inv.supplier_name + ' - ' + inv.document_number + ' (' + new Number(inv.total).toLocaleString('pt-PT', {minimumFractionDigits: 2}) + ' €)';
                        if (inv.exact_match == 1) label = '✅ ' + label;
                        html += '<a href="#" onclick="selectInvoice(' + inv.id + '); return false;" style="display: block; padding: 8px 5px; text-decoration: none; border-bottom: 1px solid rgba(0,0,0,0.1);" class="sugestao-link">' + label + '</a>';
                    });
                    html += '</div>';
                    document.getElementById('invoiceSuggestions').innerHTML = html;
                } else {
                    document.getElementById('invoiceSuggestions').innerHTML = '<p style="color:#999;">Nenhuma sugestão encontrada. Introduza o ID manualmente.</p>';
                }
            })
            .catch(e => {
                document.getElementById('invoiceSuggestions').innerHTML = '<p style="color:#e74c3c;">Erro ao carregar sugestões.</p>';
            });
    }

    function selectInvoice(id) {
        document.getElementById('invoiceIdManual').value = id;
    }

    function closeModal() {
        document.getElementById('linkModal').style.display = 'none';
    }

    document.getElementById('linkForm').addEventListener('submit', function(e) {
        var val = document.getElementById('invoiceIdManual').value.trim();
        if (!val) {
            e.preventDefault();
            alert('Selecione uma sugestão ou introduza o ID da fatura.');
            return;
        }
        document.getElementById('invoiceIdHidden').value = val;
    });

    document.getElementById('linkModal').addEventListener('click', function(e) {
        if (e.target === this) closeModal();
    });
    </script>
</body>
</html>
