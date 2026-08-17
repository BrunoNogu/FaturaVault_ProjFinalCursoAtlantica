<?php
// Proteção de páginas - verifica se o utilizador tem sessão ativa

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
