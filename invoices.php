<?php
// Listagem de faturas com pesquisa e paginação
require_once 'config_helper.php';
$companyName = getConfig($pdo, 'company_name');

$perPage = 15;
$currentPage = max(1, intval($_GET['page'] ?? 1));

$search = isset($_GET['search']) ? trim($_GET['search']) : '';

if ($search !== '') {
    $term = "%$search%";
    
    // Converter valor: suporta 107.90 e 107,90 e 1.107,90
    $searchNorm = $search;
    if (strpos($searchNorm, ',') !== false && strpos($searchNorm, '.') !== false) {
        // Formato 1.107,90 → remover pontos, trocar vírgula
        $searchNorm = str_replace('.', '', $searchNorm);
        $searchNorm = str_replace(',', '.', $searchNorm);
    } elseif (strpos($searchNorm, ',') !== false) {
        // Formato 107,90 → trocar vírgula por ponto
        $searchNorm = str_replace(',', '.', $searchNorm);
    }
    $numericSearch = floatval($searchNorm);
    
    if ($numericSearch > 0) {
        $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE supplier_name LIKE ? OR supplier_vat LIKE ? OR document_number LIKE ? OR ABS(total - ?) < 0.01");
        $stmtCount->execute([$term, $term, $term, $numericSearch]);
        $totalInvoices = $stmtCount->fetchColumn();

        $offset = ($currentPage - 1) * $perPage;
        $stmt = $pdo->prepare("SELECT * FROM invoices WHERE supplier_name LIKE ? OR supplier_vat LIKE ? OR document_number LIKE ? OR ABS(total - ?) < 0.01 ORDER BY document_date DESC LIMIT $perPage OFFSET $offset");
        $stmt->execute([$term, $term, $term, $numericSearch]);
    } else {
        $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE supplier_name LIKE ? OR supplier_vat LIKE ? OR document_number LIKE ?");
        $stmtCount->execute([$term, $term, $term]);
        $totalInvoices = $stmtCount->fetchColumn();

        $offset = ($currentPage - 1) * $perPage;
        $stmt = $pdo->prepare("SELECT * FROM invoices WHERE supplier_name LIKE ? OR supplier_vat LIKE ? OR document_number LIKE ? ORDER BY document_date DESC LIMIT $perPage OFFSET $offset");
        $stmt->execute([$term, $term, $term]);
    }
} else {
    $totalInvoices = $pdo->query("SELECT COUNT(*) FROM invoices")->fetchColumn();

    $offset = ($currentPage - 1) * $perPage;
    $stmt = $pdo->query("SELECT * FROM invoices ORDER BY document_date DESC LIMIT $perPage OFFSET $offset");
}

$invoices = $stmt->fetchAll();
$totalPages = max(1, ceil($totalInvoices / $perPage));
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Faturas - FaturaVault</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="container">
        <?php $activePage = 'invoices'; $subtitle = 'Listagem de faturas'; include 'menu.php'; ?>

        <div class="pesquisa">
            <form method="GET" action="invoices.php">
                <input type="text" name="search" placeholder="Pesquisar por fornecedor, NIF ou número..." value="<?= htmlspecialchars($search) ?>">
                <button type="submit" class="btn">Pesquisar</button>
            </form>
        </div>

        <?php if (isset($_GET['msg'])): ?>
            <div class="mensagem sucesso"><?= htmlspecialchars($_GET['msg']) ?></div>
        <?php endif; ?>

        <?php if (count($invoices) === 0): ?>
            <div class="vazio">
                <p>Nenhuma fatura encontrada.</p>
                <a href="upload.php" class="btn btn-principal">Carregar primeira fatura</a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>Fornecedor</th>
                        <th>Categoria</th>
                        <th>Número</th>
                        <th>Total</th>
                        <th>IVA</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($invoices as $inv): ?>
                        <tr>
                            <td><?= $inv['document_date'] ? date('d/m/Y', strtotime($inv['document_date'])) : '-' ?></td>
                            <td><?= htmlspecialchars($inv['supplier_name'] ?: ($inv['supplier_vat'] ?: '-')) ?></td>
                            <td><?= htmlspecialchars($inv['category'] ?: '-') ?></td>
                            <td><?= htmlspecialchars($inv['document_number'] ?: '-') ?></td>
                            <td><strong><?= number_format($inv['total'], 2, ',', '.') ?> €</strong></td>
                            <td><?= number_format($inv['total_vat'], 2, ',', '.') ?> €</td>
                            <td class="acoes">
                                <a href="view.php?id=<?= $inv['id'] ?>" class="btn btn-pequeno">Ver</a>
                                <a href="delete.php?id=<?= $inv['id'] ?>" class="btn btn-pequeno btn-perigo" onclick="return confirm('Tem a certeza que deseja eliminar esta fatura?')">Eliminar</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>

            <?php if ($totalPages > 1): ?>
                <div class="paginacao">
                    <?php if ($currentPage > 1): ?>
                        <a href="invoices.php?page=<?= $currentPage - 1 ?>&search=<?= urlencode($search) ?>" class="btn btn-pequeno">Anterior</a>
                    <?php endif; ?>

                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <a href="invoices.php?page=<?= $i ?>&search=<?= urlencode($search) ?>" class="btn btn-pequeno <?= $i === $currentPage ? 'btn-principal' : '' ?>"><?= $i ?></a>
                    <?php endfor; ?>

                    <?php if ($currentPage < $totalPages): ?>
                        <a href="invoices.php?page=<?= $currentPage + 1 ?>&search=<?= urlencode($search) ?>" class="btn btn-pequeno">Seguinte</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        <?php endif; ?>
    </div>
</body>
</html>
