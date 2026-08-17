<?php
// Painel de controlo (Dashboard)
// Mostra estatísticas, gráfico mensal e distribuição por categoria
require_once 'config_helper.php';
$companyName = getConfig($pdo, 'company_name');

// Procura os anos disponíveis para o filtro
$stmtYears = $pdo->query("SELECT DISTINCT YEAR(document_date) AS year FROM invoices WHERE document_date IS NOT NULL ORDER BY year DESC");
$years = $stmtYears->fetchAll();

$selectedYear = isset($_GET['year']) ? intval($_GET['year']) : null;
if ($selectedYear === null && !empty($years)) {
    $selectedYear = $years[0]['year'];
}

$totalInvoices = 0;
$totalAmount = 0;
$totalVat = 0;
$monthlyData = [];
$categoryData = [];

// Consultas para o ano selecionado: totais, gastos mensais e por categoria
if ($selectedYear) {
    $stmt = $pdo->prepare("SELECT 
        COUNT(*) AS total_invoices,
        COALESCE(SUM(total), 0) AS total_amount,
        COALESCE(SUM(total_vat), 0) AS total_vat
        FROM invoices WHERE YEAR(document_date) = ?");
    $stmt->execute([$selectedYear]);
    $data = $stmt->fetch();
    $totalInvoices = $data['total_invoices'];
    $totalAmount = $data['total_amount'];
    $totalVat = $data['total_vat'];

    $stmtMonthly = $pdo->prepare("SELECT MONTH(document_date) AS month, COALESCE(SUM(total), 0) AS total 
        FROM invoices WHERE YEAR(document_date) = ? AND document_date IS NOT NULL 
        GROUP BY MONTH(document_date) ORDER BY month");
    $stmtMonthly->execute([$selectedYear]);
    foreach ($stmtMonthly->fetchAll() as $row) {
        $monthlyData[$row['month']] = $row['total'];
    }

    $stmtCategory = $pdo->prepare("SELECT category, COALESCE(SUM(total), 0) AS total 
        FROM invoices WHERE YEAR(document_date) = ? AND category != '' 
        GROUP BY category ORDER BY total DESC");
    $stmtCategory->execute([$selectedYear]);
    $categoryData = $stmtCategory->fetchAll();
}

$months = ['Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'];
$monthlyValues = [];
for ($m = 1; $m <= 12; $m++) {
    $monthlyValues[] = round($monthlyData[$m] ?? 0, 2);
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FaturaVault</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="container">
        <?php $activePage = 'home'; include 'menu.php'; ?>

        <?php if (isset($_GET['msg'])): ?>
            <div class="mensagem sucesso"><?= htmlspecialchars($_GET['msg']) ?></div>
        <?php endif; ?>

        <?php if (empty($years)): ?>
            <div class="vazio">
                <p>Ainda não existem faturas no sistema.</p>
                <a href="upload.php" class="btn btn-principal">Carregar primeira fatura</a>
            </div>
        <?php else: ?>

            <div class="dashboard-ano">
                <form method="GET" action="index.php">
                    <label for="year">Ano:</label>
                    <select name="year" id="year" onchange="this.form.submit()">
                        <?php foreach ($years as $y): ?>
                            <option value="<?= $y['year'] ?>" <?= $y['year'] == $selectedYear ? 'selected' : '' ?>><?= $y['year'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>

            <div class="dashboard-cartoes">
                <div class="cartao">
                    <span class="cartao-valor"><?= $totalInvoices ?></span>
                    <span class="cartao-titulo">Faturas</span>
                </div>
                <div class="cartao cartao-destaque">
                    <span class="cartao-valor"><?= number_format($totalAmount, 2, ',', '.') ?> €</span>
                    <span class="cartao-titulo">Total Faturas</span>
                </div>
                <div class="cartao">
                    <span class="cartao-valor"><?= number_format($totalVat, 2, ',', '.') ?> €</span>
                    <span class="cartao-titulo">Total IVA</span>
                </div>
            </div>

            <div class="dashboard-graficos">
                <fieldset class="grafico-container">
                    <h3 class="seccao-titulo">Gastos por Mês — <?= $selectedYear ?></h3>
                    <canvas id="chartMonthly"></canvas>
                </fieldset>
                <fieldset class="grafico-container">
                    <h3 class="seccao-titulo">Distribuição por Categoria — <?= $selectedYear ?></h3>
                    <canvas id="chartCategory"></canvas>
                </fieldset>
            </div>

        <?php endif; ?>
    </div>

    <?php if (!empty($years)): ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
    <script>
    var isDark = document.body.classList.contains('tema-escuro');
    var textColor = isDark ? '#ccc' : '#555';
    var gridColor = isDark ? '#333' : '#eee';

    var accentColor = getComputedStyle(document.documentElement).getPropertyValue('--accent').trim() || '#3498db';

    Chart.defaults.color = textColor;

    new Chart(document.getElementById('chartMonthly'), {
        type: 'bar',
        data: {
            labels: <?= json_encode($months) ?>,
            datasets: [{
                label: 'Total (€)',
                data: <?= json_encode($monthlyValues) ?>,
                backgroundColor: accentColor,
                borderRadius: 4
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, grid: { color: gridColor } },
                x: { grid: { display: false } }
            }
        }
    });

    <?php if (!empty($categoryData)): ?>
    new Chart(document.getElementById('chartCategory'), {
        type: 'doughnut',
        data: {
            labels: <?= json_encode(array_column($categoryData, 'category')) ?>,
            datasets: [{
                data: <?= json_encode(array_map(function($r){ return round($r['total'], 2); }, $categoryData)) ?>,
                backgroundColor: ['#3498db','#e74c3c','#2ecc71','#f39c12','#9b59b6','#1abc9c','#e67e22','#34495e','#16a085','#c0392b','#8e44ad']
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { position: 'bottom' }
            }
        }
    });
    <?php endif; ?>
    </script>
    <?php endif; ?>
</body>
</html>
