<?php
// Funções auxiliares para gerir configurações guardadas na tabela 'settings'
// Usado pelas páginas que precisam de ler/guardar definições (cor, empresa, Azure, etc.)

require_once 'db.php';

// Lê um valor da tabela settings pela chave (filtrado pelo utilizador atual)
function getConfig($pdo, $key, $default = '') {
    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId) return $default;
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ? AND user_id = ?");
    $stmt->execute([$key, $userId]);
    $row = $stmt->fetch();
    return $row ? $row['setting_value'] : $default;
}

// Guarda (ou atualiza) um valor na tabela settings (associado ao utilizador atual)
function saveConfig($pdo, $key, $value) {
    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId) return;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM settings WHERE setting_key = ? AND user_id = ?");
    $stmt->execute([$key, $userId]);

    if ($stmt->fetchColumn() > 0) {
        $stmt = $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ? AND user_id = ?");
        $stmt->execute([$value, $key, $userId]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value, user_id) VALUES (?, ?, ?)");
        $stmt->execute([$key, $value, $userId]);
    }
}

// Verifica se o Azure AI está configurado (endpoint + chave preenchidos)
function isAzureConfigured($pdo) {
    $endpoint = getConfig($pdo, 'azure_endpoint');
    $key = getConfig($pdo, 'azure_key');
    return ($endpoint !== '' && $key !== '');
}
