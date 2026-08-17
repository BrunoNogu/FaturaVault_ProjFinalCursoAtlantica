<?php
// Termina a sessão do utilizador e redireciona para o login
session_start();
session_destroy();
header('Location: login.php');
exit;
