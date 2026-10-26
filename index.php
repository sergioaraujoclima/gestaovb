<?php
require __DIR__ . '/inc/app.php';
if (!empty($_SESSION['uid'])) ir('painel.php');
$erro = '';
$f = flash();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $st = db()->prepare('SELECT * FROM usuarios WHERE email = ?');
    $st->execute([trim($_POST['email'] ?? '')]);
    $u = $st->fetch();
    if ($u && password_verify($_POST['senha'] ?? '', $u['senha_hash'])) {
        if ($u['status'] === 'ativo' && $u['perfil']) {
            session_regenerate_id(true);
            $_SESSION['uid'] = $u['id'];
            registrar_log('acesso', $u['id'], 'Login', '', $u);
            ir('painel.php');
        }
        $erro = $u['status'] === 'bloqueado'
            ? 'Seu acesso está bloqueado. Fale com o administrador.'
            : 'Seu cadastro ainda aguarda liberação do administrador.';
    } else {
        sleep(1);
        $erro = 'E-mail ou senha inválidos.';
    }
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Entrar · <?= e(APP_NOME) ?></title>
<link rel="stylesheet" href="assets/estilo.css?v=6.1">
</head>
<body class="login">
<form method="post" class="cartao login-box">
  <div class="marca">Vinícola <strong>Brasília</strong></div>
  <p class="sub">Gestão interna</p>
  <?php if ($f): ?><div class="aviso <?= e($f[1]) ?>"><?= e($f[0]) ?></div><?php endif; ?>
  <?php if ($erro): ?><div class="aviso erro"><?= e($erro) ?></div><?php endif; ?>
  <?= csrf_campo() ?>
  <label>E-mail<input type="email" name="email" required autofocus></label>
  <label>Senha<input type="password" name="senha" required></label>
  <button type="submit">Entrar</button>
  <a href="cadastro.php">Não tem acesso? Cadastre-se</a>
</form>
</body>
</html>
