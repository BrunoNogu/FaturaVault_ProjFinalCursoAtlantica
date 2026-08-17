<?php
// Página de login - autenticação do utilizador
// Se já tem sessão, redireciona para o painel
session_start();

if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// Se o sistema ainda não está instalado, vai para o instalador
if (!file_exists(__DIR__ . '/config.php')) {
    header('Location: install.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/config.php';
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $email = trim($_POST['email'] ?? '');
    $pwd = $_POST['password'] ?? '';

    if ($email === '' || $pwd === '') {
        $error = 'Preencha todos os campos.';
    } else {
        // Procura o utilizador pelo email e verifica a password com bcrypt
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");;
        $stmt->execute([$email]);
        $userRow = $stmt->fetch();

        if ($userRow && password_verify($pwd, $userRow['password_hash'])) {
            $_SESSION['user_id'] = $userRow['id'];
            $_SESSION['user_name'] = $userRow['name'];
            $_SESSION['user_email'] = $userRow['email'];
            header('Location: index.php');
            exit;
        } else {
            $error = 'Email ou palavra-passe incorretos.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - FaturaVault</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="container container-login">
        <header>
            <h1>FaturaVault</h1>
            <p>Iniciar sessão</p>
        </header>

        <?php if ($error): ?>
            <div class="mensagem erro"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <fieldset>
                <div class="campo">
                    <label for="email">Email:</label>
                    <input type="text" id="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
                </div>
                <div class="campo">
                    <label for="password">Palavra-passe:</label>
                    <input type="password" id="password" name="password" required>
                </div>
            </fieldset>
            <button type="submit" class="btn btn-principal btn-grande">Entrar</button>
        </form>
    </div>

    <button id="btn-tema" class="btn-tema" onclick="toggleTheme()" title="Mudar tema">🔆</button>
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
</body>
</html>
