<?php
require __DIR__ . '/inc/app.php';
if (!empty($_SESSION['uid'])) ir('painel.php');
$erro = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $nome = trim($_POST['nome'] ?? ''); $email = trim($_POST['email'] ?? ''); $senha = $_POST['senha'] ?? '';
    if ($nome === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($senha) < 8) {
        $erro = 'Preencha nome, e-mail válido e senha com 8 ou mais caracteres.';
    } elseif ($senha !== ($_POST['senha2'] ?? '')) {
        $erro = 'As senhas não conferem.';
    } else {
        $pdo = db();
        // O primeiro cadastro do sistema já entra como Administrador ativo.
        $primeiro = (int)$pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn() === 0;
        try {
            $pdo->prepare('INSERT INTO usuarios (nome, email, senha_hash, perfil, status) VALUES (?,?,?,?,?)')
                ->execute([$nome, $email, password_hash($senha, PASSWORD_DEFAULT), $primeiro ? 'admin' : null, $primeiro ? 'ativo' : 'pendente']);
            registrar_log('usuario', (int)$pdo->lastInsertId(), $primeiro ? 'Cadastro do primeiro Administrador' : 'Solicitação de acesso', $nome . ' <' . $email . '>', ['id' => (int)$pdo->lastInsertId(), 'nome' => $nome]);
            flash($primeiro ? 'Administrador criado. Faça o login.' : 'Cadastro enviado. Aguarde a liberação do administrador.');
            ir('index.php');
        } catch (PDOException $ex) {
            $erro = 'Este e-mail já está cadastrado.';
        }
    }
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Cadastro · <?= e(APP_NOME) ?></title>
<link rel="stylesheet" href="assets/estilo.css?v=6.1">
</head>
<body class="login">
<form method="post" class="cartao login-box">
  <div class="marca">Solicitar acesso</div>
  <p class="sub">Após o cadastro, o administrador libera seu perfil.</p>
  <?php if ($erro): ?><div class="aviso erro"><?= e($erro) ?></div><?php endif; ?>
  <?= csrf_campo() ?>
  <label>Nome<input name="nome" required value="<?= e($_POST['nome'] ?? '') ?>"></label>
  <label>E-mail<input type="email" name="email" required value="<?= e($_POST['email'] ?? '') ?>"></label>
  <label>Senha (mín. 8)<input type="password" name="senha" minlength="8" required></label>
  <label>Repita a senha<input type="password" name="senha2" minlength="8" required></label>
  <button type="submit">Cadastrar</button>
  <a href="index.php">Voltar ao login</a>
</form>
</body>
</html>
