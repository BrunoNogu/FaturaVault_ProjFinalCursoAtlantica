<?php
// Ligação à base de dados
// Incluído em praticamente todas as páginas para ter acesso ao $pdo

// Se ainda não foi instalado, redireciona para o instalador
if (!file_exists(__DIR__ . '/config.php')) {
    header('Location: install.php');
    exit;
}

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';

// Ligação via PDO com charset utf8mb4 para suportar acentos
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Erro na ligação à base de dados: " . $e->getMessage());
}

// Migração: adicionar user_id à tabela invoices se não existir
$colCheck = $pdo->query("SHOW COLUMNS FROM invoices LIKE 'user_id'");
if ($colCheck->rowCount() === 0) {
    $pdo->exec("ALTER TABLE invoices ADD COLUMN user_id INT, ADD FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL");
}

// Migração: adicionar user_id à tabela settings se não existir
$colCheck = $pdo->query("SHOW COLUMNS FROM settings LIKE 'user_id'");
if ($colCheck->rowCount() === 0) {
    $pdo->exec("ALTER TABLE settings DROP PRIMARY KEY, ADD COLUMN user_id INT NOT NULL DEFAULT 1, ADD PRIMARY KEY (setting_key, user_id), ADD FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE");
}
