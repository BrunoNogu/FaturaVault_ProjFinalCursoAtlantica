<?php
// Página de gestão de suprimentos (adiantamentos aos sócios)
require_once 'config_helper.php';

$successMsg = '';
$errorMsg = '';
$page = intval($_GET['page'] ?? 1);
$pageSize = 10;

// Buscar sócios
$stmtPartners = $pdo->query("SELECT * FROM partners WHERE active = 1 ORDER BY name");
$partners = $stmtPartners->fetchAll();

// Operações com suprimentos
$action = $_POST['action'] ?? null;

// Adicionar suprimento manual
if ($action === 'add_advance') {
    $partnerId = intval($_POST['partner_id'] ?? 0);
    $amount = floatval($_POST['amount'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $advanceDate = trim($_POST['advance_date'] ?? '');
    
    if ($partnerId <= 0) {
        $errorMsg = 'Selecione um sócio.';
    } elseif ($amount <= 0) {
        $errorMsg = 'Valor inválido.';
    } elseif (empty($advanceDate)) {
        $errorMsg = 'Introduza a data.';
    } else {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO advances (partner_id, amount, advance_date, description, status)
                VALUES (?, ?, ?, ?, 'active')
            ");
            $stmt->execute([$partnerId, $amount, $advanceDate, $description]);
            $successMsg = 'Suprimento adicionado com sucesso!';
        } catch (PDOException $e) {
            $errorMsg = 'Erro ao adicionar suprimento.';
        }
    }
}

// Liquidar suprimento
if ($action === 'settle_advance') {
    $advanceId = intval($_POST['advance_id'] ?? 0);
    if ($advanceId > 0) {
        $stmt = $pdo->prepare("UPDATE advances SET status = 'settled' WHERE id = ?");
        $stmt->execute([$advanceId]);
        $successMsg = 'Suprimento marcado como liquidado!';
    }
}

// Apagar suprimento
if ($action === 'delete_advance') {
    $advanceId = intval($_POST['advance_id'] ?? 0);
    if ($advanceId > 0) {
        $stmt = $pdo->prepare("DELETE FROM advances WHERE id = ?");
        $stmt->execute([$advanceId]);
        $successMsg = 'Suprimento eliminado!';
    }
}

// Alocar movimento de entrada como suprimento (múltiplos sócios)
if ($action === 'allocate_movement') {
    $movementId = intval($_POST['movement_id'] ?? 0);
    $allocAmounts = $_POST['alloc_amounts'] ?? [];
    
    if ($movementId <= 0) {
        $errorMsg = 'Dados inválidos.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM bank_movements WHERE id = ?");
        $stmt->execute([$movementId]);
        $movement = $stmt->fetch();
        
        if (!$movement || $movement['type'] !== 'entrada') {
            $errorMsg = 'Movimento inválido.';
        } else {
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as allocated FROM advances WHERE bank_movement_id = ?");
            $stmt->execute([$movementId]);
            $allocated = floatval($stmt->fetch()['allocated']);
            $remaining = $movement['amount'] - $allocated;
            
            // Calcular total das alocações
            $totalAlloc = 0;
            $validAllocs = [];
            foreach ($allocAmounts as $pid => $amt) {
                $amt = floatval($amt);
                if ($amt > 0) {
                    $validAllocs[intval($pid)] = $amt;
                    $totalAlloc += $amt;
                }
            }
            
            if (empty($validAllocs)) {
                $errorMsg = 'Atribua um valor a pelo menos um sócio.';
            } elseif (abs($totalAlloc - $remaining) > 0.10) {
                $errorMsg = 'O total alocado deve ser igual ao valor disponível (' . number_format($remaining, 2, ',', '.') . ' €). Margem máxima: 0,10 €.';
            } else {
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO advances (partner_id, bank_movement_id, amount, advance_date, status)
                        VALUES (?, ?, ?, ?, 'active')
                    ");
                    foreach ($validAllocs as $pid => $amt) {
                        $stmt->execute([$pid, $movementId, $amt, $movement['movement_date']]);
                    }
                    $successMsg = 'Suprimento alocado com sucesso!';
                } catch (PDOException $e) {
                    $errorMsg = 'Erro ao alocar suprimento.';
                }
            }
        }
    }
}

// Buscar movimentos de entrada com valor disponível (não totalmente alocados)
$stmtMovements = $pdo->query("
    SELECT bm.*, 
        bm.amount - COALESCE((SELECT SUM(a.amount) FROM advances a WHERE a.bank_movement_id = bm.id), 0) AS remaining
    FROM bank_movements bm
    WHERE bm.type = 'entrada'
    HAVING remaining > 0.01
    ORDER BY bm.movement_date DESC
    LIMIT 20
");
$pendingMovements = $stmtMovements->fetchAll();

// Buscar suprimentos totais
$stmt = $pdo->query("
    SELECT 
        p.id, p.name,
        SUM(CASE WHEN a.status = 'active' THEN a.amount ELSE 0 END) as active_total,
        SUM(CASE WHEN a.status = 'settled' THEN a.amount ELSE 0 END) as settled_total,
        COUNT(CASE WHEN a.status = 'active' THEN 1 END) as active_count
    FROM partners p
    LEFT JOIN advances a ON p.id = a.partner_id
    WHERE p.active = 1
    GROUP BY p.id, p.name
    ORDER BY p.name
");
$partnerTotals = $stmt->fetchAll();

// Buscar suprimentos com paginação
$offset = ($page - 1) * $pageSize;
$stmt = $pdo->query("SELECT COUNT(*) as total FROM advances");
$total = $stmt->fetch()['total'];
$totalPages = ceil($total / $pageSize);

$stmt = $pdo->query("
    SELECT a.*, p.name as partner_name
    FROM advances a
    JOIN partners p ON a.partner_id = p.id
    ORDER BY a.advance_date DESC
    LIMIT $pageSize OFFSET $offset
");
$advances = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Suprimentos - FaturaVault</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="container">
        <?php $activePage = 'advances'; include 'menu.php'; ?>
        
        <header>
            <h1>Suprimentos</h1>
            <p>Gestão de adiantamentos aos sócios</p>
        </header>

        <?php if ($successMsg): ?>
            <div class="mensagem sucesso"><?= htmlspecialchars($successMsg) ?></div>
        <?php endif; ?>
        
        <?php if ($errorMsg): ?>
            <div class="mensagem erro"><?= htmlspecialchars($errorMsg) ?></div>
        <?php endif; ?>

        <!-- Resumo por Sócio -->
        <fieldset>
            <legend>Resumo de Suprimentos por Sócio</legend>
            <?php if (count($partnerTotals) > 0): ?>
                <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Sócio</th>
                            <th>Suprimentos Ativos</th>
                            <th>Liquidados</th>
                            <th># Ativos</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($partnerTotals as $pt): ?>
                        <tr>
                            <td><?= htmlspecialchars($pt['name']) ?></td>
                            <td><strong><?= number_format($pt['active_total'] ?? 0, 2, ',', '.') ?> €</strong></td>
                            <td><?= number_format($pt['settled_total'] ?? 0, 2, ',', '.') ?> €</td>
                            <td><?= $pt['active_count'] ?? 0 ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php else: ?>
                <p style="color: #999;">Ainda não tem suprimentos registados.</p>
            <?php endif; ?>
        </fieldset>

        <!-- Movimentos de entrada pendentes -->
        <?php if (count($pendingMovements) > 0): ?>
        <fieldset>
            <legend>Movimentos de Entrada para Alocar como Suprimento</legend>
            <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>Descrição</th>
                        <th>Valor</th>
                        <th>Disponível</th>
                        <th>Ação</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pendingMovements as $mov): ?>
                    <tr>
                        <td><?= date('d/m/Y', strtotime($mov['movement_date'])) ?></td>
                        <td><?= htmlspecialchars($mov['description']) ?></td>
                        <td><?= number_format($mov['amount'], 2, ',', '.') ?> €</td>
                        <td><?= number_format($mov['remaining'], 2, ',', '.') ?> €</td>
                        <td>
                            <button class="btn btn-pequeno" onclick="openAllocateModal(<?= $mov['id'] ?>, '<?= addslashes(htmlspecialchars($mov['description'])) ?>', <?= $mov['remaining'] ?>)">Alocar</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </fieldset>
        <?php endif; ?>

        <!-- Adicionar suprimento manual -->
        <fieldset>
            <legend>Adicionar Suprimento Manual</legend>
            <form method="POST">
                <input type="hidden" name="action" value="add_advance">
                
                <div class="campo-grupo">
                    <div class="campo">
                        <label for="partner_id">Sócio:</label>
                        <select id="partner_id" name="partner_id" required>
                            <option value="">-- Selecione --</option>
                            <?php foreach ($partners as $p): ?>
                                <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="campo">
                        <label for="amount">Valor (€):</label>
                        <input type="number" id="amount" name="amount" step="0.01" min="0.01" placeholder="0.00" required>
                    </div>
                </div>
                
                <div class="campo-grupo">
                    <div class="campo">
                        <label for="advance_date">Data:</label>
                        <input type="date" id="advance_date" name="advance_date" required value="<?= date('Y-m-d') ?>">
                    </div>
                    
                    <div class="campo">
                        <label for="description">Descrição (opcional):</label>
                        <input type="text" id="description" name="description" placeholder="Motivo do suprimento">
                    </div>
                </div>
                
                <button type="submit" class="btn btn-principal">Adicionar Suprimento</button>
            </form>
        </fieldset>

        <!-- Lista de suprimentos -->
        <fieldset>
            <legend>Todos os Suprimentos</legend>
            <?php if (count($advances) > 0): ?>
                <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Sócio</th>
                            <th>Data</th>
                            <th>Valor</th>
                            <th>Descrição</th>
                            <th>Estado</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($advances as $adv): ?>
                        <tr>
                            <td><?= htmlspecialchars($adv['partner_name']) ?></td>
                            <td><?= date('d/m/Y', strtotime($adv['advance_date'])) ?></td>
                            <td><strong><?= number_format($adv['amount'], 2, ',', '.') ?> €</strong></td>
                            <td><?= htmlspecialchars($adv['description'] ?: '-') ?></td>
                            <td>
                                <span style="display: inline-block; padding: 4px 8px; border-radius: 4px; font-size: 0.85em; font-weight: bold;
                                    background: <?= $adv['status'] === 'active' ? '#ffc107' : '#28a745' ?>;
                                    color: #000;">
                                    <?= $adv['status'] === 'active' ? 'Ativo' : 'Liquidado' ?>
                                </span>
                            </td>
                            <td class="acoes">
                                <?php if ($adv['status'] === 'active'): ?>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="action" value="settle_advance">
                                        <input type="hidden" name="advance_id" value="<?= $adv['id'] ?>">
                                        <button type="submit" class="btn btn-pequeno">Liquidar</button>
                                    </form>
                                <?php endif; ?>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="delete_advance">
                                    <input type="hidden" name="advance_id" value="<?= $adv['id'] ?>">
                                    <button type="submit" class="btn btn-pequeno btn-perigo" onclick="return confirm('Tem a certeza que deseja eliminar este suprimento?')">Apagar</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>

                <?php if ($totalPages > 1): ?>
                    <div class="paginacao" style="margin-top: 20px;">
                        <?php if ($page > 1): ?>
                            <a href="advances.php?page=<?= $page - 1 ?>" class="btn btn-pequeno">← Anterior</a>
                        <?php endif; ?>
                        
                        <span style="padding: 8px; display: flex; align-items: center;">Página <?= $page ?> de <?= $totalPages ?></span>
                        
                        <?php if ($page < $totalPages): ?>
                            <a href="advances.php?page=<?= $page + 1 ?>" class="btn btn-pequeno">Seguinte →</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <p style="color: #999;">Nenhum suprimento registado.</p>
            <?php endif; ?>
        </fieldset>
    </div>

    <!-- Modal para alocar movimento -->
    <div id="allocateModal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:999;">
        <div class="modal-conteudo" style="position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); padding:30px; border-radius:8px; width:90%; max-width:500px; box-shadow:0 4px 20px rgba(0,0,0,0.3); max-height:80vh; overflow-y:auto;">
            <h2 style="margin-bottom: 20px;">Alocar Movimento como Suprimento</h2>
            <p id="modalInfo" style="margin-bottom: 15px; font-size: 0.9em;"></p>
            
            <form method="POST" id="allocateForm">
                <input type="hidden" name="action" value="allocate_movement">
                <input type="hidden" name="movement_id" id="modalMovementId">
                
                <p style="margin-bottom: 10px; font-weight: bold;">Distribuir valor pelos sócios:</p>
                
                <div id="divideBox" style="display: flex; align-items: center; gap: 10px; margin-bottom: 15px; padding: 10px; border-radius: 5px; background: #f8f9fa; border: 1px solid #dee2e6;">
                    <label style="margin: 0; white-space: nowrap;">Dividir por</label>
                    <input type="number" id="divideBy" min="1" max="<?= count($partners) ?>" value="<?= count($partners) ?>" style="width: 60px; padding: 6px; border: 1px solid #bdc3c7; border-radius: 5px; text-align: center;">
                    <button type="button" class="btn btn-pequeno" onclick="calcDivision()">Calcular</button>
                    <span id="divisionResult" style="font-size: 0.9em; color: #666;"></span>
                </div>
                
                <?php foreach ($partners as $p): ?>
                <div class="campo" style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
                    <label style="flex: 1; margin: 0;"><?= htmlspecialchars($p['name']) ?></label>
                    <button type="button" class="btn btn-pequeno assign-btn" onclick="assignDivision(this)" style="display:none; font-size: 0.8em; padding: 4px 8px;">Atribuir</button>
                    <input type="number" name="alloc_amounts[<?= $p['id'] ?>]" class="alloc-input" step="0.01" min="0" value="0" style="width: 120px; padding: 8px; border: 1px solid #bdc3c7; border-radius: 5px; text-align: right;">
                    <span>€</span>
                </div>
                <?php endforeach; ?>
                
                <div style="margin-top: 15px; padding: 10px; border-radius: 5px; background: #f0f0f0; display: flex; justify-content: space-between; align-items: center;" id="allocSummary">
                    <span>Total alocado: <strong id="allocTotal">0,00 €</strong></span>
                    <span>Restante: <strong id="allocRemaining">0,00 €</strong></span>
                </div>
                <p id="allocError" style="color: #e74c3c; font-size: 0.85em; margin-top: 5px; display: none;">O total deve ser igual ao valor disponível.</p>
                
                <div style="display: flex; gap: 10px; margin-top: 20px;">
                    <button type="submit" class="btn btn-principal" id="allocSubmitBtn" disabled>Alocar Suprimento</button>
                    <button type="button" class="btn" onclick="closeAllocateModal()">Cancelar</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    var allocRemaining = 0;
    var divisionValue = 0;

    function calcDivision() {
        var n = parseInt(document.getElementById('divideBy').value) || 1;
        if (n < 1) n = 1;
        divisionValue = Math.floor((allocRemaining / n) * 100) / 100;
        document.getElementById('divisionResult').textContent = '= ' + divisionValue.toFixed(2).replace('.', ',') + ' € cada';
        document.querySelectorAll('.assign-btn').forEach(function(btn) { btn.style.display = ''; });
    }

    function assignDivision(btn) {
        if (divisionValue <= 0) {
            calcDivision();
        }
        var input = btn.parentElement.querySelector('.alloc-input');
        input.value = divisionValue.toFixed(2);
        updateAllocTotal();
    }

    function openAllocateModal(movementId, description, remaining) {
        allocRemaining = parseFloat(remaining);
        document.getElementById('allocateModal').style.display = 'block';
        document.getElementById('modalMovementId').value = movementId;
        document.getElementById('modalInfo').textContent = description + ' — Valor a alocar: ' + allocRemaining.toFixed(2).replace('.', ',') + ' €';
        
        // Reset inputs
        var inputs = document.querySelectorAll('.alloc-input');
        inputs.forEach(function(inp) { inp.value = '0'; inp.max = allocRemaining; });
        document.querySelectorAll('.assign-btn').forEach(function(btn) { btn.style.display = 'none'; });
        document.getElementById('divisionResult').textContent = '';
        divisionValue = 0;
        
        // Se só há 1 sócio, preencher automaticamente
        if (inputs.length === 1) {
            inputs[0].value = allocRemaining.toFixed(2);
        }
        updateAllocTotal();
    }

    function closeAllocateModal() {
        document.getElementById('allocateModal').style.display = 'none';
    }

    function updateAllocTotal() {
        var inputs = document.querySelectorAll('.alloc-input');
        var total = 0;
        inputs.forEach(function(inp) { total += parseFloat(inp.value) || 0; });
        
        document.getElementById('allocTotal').textContent = total.toFixed(2).replace('.', ',') + ' €';
        var rest = allocRemaining - total;
        document.getElementById('allocRemaining').textContent = rest.toFixed(2).replace('.', ',') + ' €';
        
        var valid = Math.abs(total - allocRemaining) <= 0.10 && total > 0;
        document.getElementById('allocSubmitBtn').disabled = !valid;
        document.getElementById('allocError').style.display = (!valid && total > 0) ? 'block' : 'none';
        
        // Highlight summary
        var summary = document.getElementById('allocSummary');
        summary.style.background = valid ? '#d4edda' : '#f0f0f0';
    }

    document.querySelectorAll('.alloc-input').forEach(function(inp) {
        inp.addEventListener('input', updateAllocTotal);
    });

    document.getElementById('allocateModal').addEventListener('click', function(e) {
        if (e.target === this) closeAllocateModal();
    });
    </script>
</body>
</html>
