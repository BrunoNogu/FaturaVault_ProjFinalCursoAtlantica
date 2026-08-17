<?php
// Assistente de instalação - configura a BD e cria o primeiro utilizador
// Só é acessível se o config.php ainda não existir
$errors = [];
$success = false;

if (file_exists(__DIR__ . '/config.php')) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $host = trim($_POST['host'] ?? 'localhost');
    $dbUser = trim($_POST['db_user'] ?? '');
    $dbPass = $_POST['db_password'] ?? '';
    $dbname = trim($_POST['dbname'] ?? 'faturavault');

    // Validação dos campos
    if ($host === '') $errors[] = 'O servidor é obrigatório.';
    if ($dbUser === '') $errors[] = 'O utilizador é obrigatório.';
    if ($dbname === '') $errors[] = 'O nome da base de dados é obrigatório.';

    if ($dbname !== '' && !preg_match('/^[a-zA-Z0-9_]+$/', $dbname)) {
        $errors[] = 'O nome da base de dados só pode conter letras, números e underscores.';
    }

    // Tenta ligar ao MySQL com as credenciais fornecidas
    if (empty($errors)) {
        try {
            $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $dbUser, $dbPass);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            $errors[] = 'Não foi possível ligar ao MySQL: ' . $e->getMessage();
        }
    }

    // Cria a BD, tabelas e o primeiro utilizador
    if (empty($errors)) {
        try {
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
            $pdo->exec("USE `$dbname`");

            $sql = file_get_contents(__DIR__ . '/faturavault.sql');
            $pdo->exec($sql);

            $userName = trim($_POST['user_name'] ?? '');
            $userEmail = trim($_POST['user_email'] ?? '');
            $userPassword = $_POST['user_password'] ?? '';

            if ($userName === '' || $userEmail === '' || $userPassword === '') {
                $errors[] = 'Preencha todos os campos do utilizador.';
            } else {
                $passwordHash = password_hash($userPassword, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)");
                $stmt->execute([$userName, $userEmail, $passwordHash]);
            }
        } catch (PDOException $e) {
            $errors[] = 'Erro ao criar a base de dados: ' . $e->getMessage();
        }
    }

    if (empty($errors)) {
        try {
            $uploadDir = __DIR__ . '/uploads';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            $configContent = "<?php\n";
            $configContent .= "\$host = " . var_export($host, true) . ";\n";
            $configContent .= "\$dbname = " . var_export($dbname, true) . ";\n";
            $configContent .= "\$user = " . var_export($dbUser, true) . ";\n";
            $configContent .= "\$password = " . var_export($dbPass, true) . ";\n";

            if (file_put_contents(__DIR__ . '/config.php', $configContent) === false) {
                $errors[] = 'Não foi possível criar o ficheiro config.php. Verifique as permissões da pasta.';
            } else {
                $success = true;
            }
        } catch (PDOException $e) {
            $errors[] = 'Erro ao criar a base de dados: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalação - FaturaVault</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="container">
        <header>
            <h1>FaturaVault</h1>
            <p>Instalação inicial</p>
        </header>

        <?php if ($success): ?>
            <div class="mensagem sucesso">
                <strong>Instalação concluída com sucesso!</strong><br>
                A base de dados foi criada e o sistema está pronto a usar.
            </div>
            <div style="text-align:center; margin-top:20px;">
                <a href="login.php" class="btn btn-principal btn-grande">Entrar no FaturaVault</a>
            </div>
        <?php else: ?>

            <?php if (!empty($errors)): ?>
                <div class="mensagem erro">
                    <?php foreach ($errors as $err): ?>
                        <p><?= htmlspecialchars($err) ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <fieldset>
                    <legend>Ligação à Base de Dados</legend>
                    <p class="campo-descricao">Introduza os dados de acesso ao seu servidor MySQL. Se estiver a usar XAMPP com as definições padrão, os valores pré-preenchidos devem funcionar.</p>

                    <div class="campo">
                        <label for="host">Servidor:</label>
                        <input type="text" id="host" name="host" value="<?= htmlspecialchars($_POST['host'] ?? 'localhost') ?>" required>
                    </div>

                    <div class="campo-grupo">
                        <div class="campo">
                            <label for="db_user">Utilizador:</label>
                            <input type="text" id="db_user" name="db_user" value="<?= htmlspecialchars($_POST['db_user'] ?? 'root') ?>" required>
                        </div>
                        <div class="campo">
                            <label for="db_password">Palavra-passe:</label>
                            <input type="password" id="db_password" name="db_password" value="" placeholder="Deixar vazio se não tiver">
                        </div>
                    </div>

                    <div class="campo">
                        <label for="dbname">Nome da Base de Dados:</label>
                        <input type="text" id="dbname" name="dbname" value="<?= htmlspecialchars($_POST['dbname'] ?? 'faturavault') ?>" required>
                    </div>
                </fieldset>

                <fieldset>
                    <legend>Primeiro Utilizador</legend>
                    <p class="campo-descricao">Crie o utilizador que irá aceder ao sistema.</p>

                    <div class="campo">
                        <label for="user_name">Nome:</label>
                        <input type="text" id="user_name" name="user_name" value="<?= htmlspecialchars($_POST['user_name'] ?? '') ?>" required placeholder="Ex: João Silva">
                    </div>

                    <div class="campo-grupo">
                        <div class="campo">
                            <label for="user_email">Email:</label>
                            <input type="text" id="user_email" name="user_email" value="<?= htmlspecialchars($_POST['user_email'] ?? '') ?>" required placeholder="Ex: joao@empresa.pt">
                        </div>
                        <div class="campo">
                            <label for="user_password">Palavra-passe:</label>
                            <input type="password" id="user_password" name="user_password" required>
                        </div>
                    </div>
                </fieldset>

                <button type="submit" class="btn btn-principal btn-grande">Instalar FaturaVault</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
