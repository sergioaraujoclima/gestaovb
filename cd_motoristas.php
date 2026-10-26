<?php
require __DIR__ . '/inc/app.php';
exigir_login();
if (!pode_ver_cd_gestao()) negar();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    $antes = $id ? linha('cd_motoristas', $id) : null;
    if (($_POST['acao'] ?? '') === 'excluir') {
        exigir_admin();
        $pdo->prepare('DELETE FROM cd_motoristas WHERE id = ?')->execute([$id]);
        registrar_log('motorista', $id, 'Exclusão', $antes['nome'] ?? '');
        flash('Motorista excluído.'); ir('cd_motoristas.php');
    }
    exigir_cd_edicao();
    $nome = trim($_POST['nome'] ?? '');
    if ($nome === '') { flash('Informe o nome.', 'erro'); ir('cd_motoristas.php'); }
    $d = [$nome, vazio_null($_POST['telefone'] ?? ''), (int)($_POST['usuario_id'] ?? 0) ?: null, isset($_POST['ativo']) ? 1 : 0];
    if ($id) {
        $d[] = $id;
        $pdo->prepare('UPDATE cd_motoristas SET nome=?, telefone=?, usuario_id=?, ativo=? WHERE id=?')->execute($d);
        registrar_alteracao('motorista', 'cd_motoristas', $id, $antes);
    } else {
        $pdo->prepare('INSERT INTO cd_motoristas (nome, telefone, usuario_id, ativo) VALUES (?,?,?,?)')->execute($d);
        registrar_log('motorista', (int)$pdo->lastInsertId(), 'Inclusão', $nome);
    }
    flash('Motorista salvo.'); ir('cd_motoristas.php');
}

$ed = ['id' => 0, 'nome' => '', 'telefone' => '', 'usuario_id' => 0, 'ativo' => 1];
if (!empty($_GET['editar'])) $ed = linha('cd_motoristas', (int)$_GET['editar']) ?: $ed;
$usuarios = $pdo->query("SELECT id, nome, email FROM usuarios WHERE status = 'ativo' AND FIND_IN_SET('motorista_cd', perfil) ORDER BY nome")->fetchAll();
$lista = $pdo->query("SELECT m.*, u.nome usuario, (SELECT COUNT(*) FROM cd_entregas en WHERE en.motorista_id = m.id) entregas
    FROM cd_motoristas m LEFT JOIN usuarios u ON u.id = m.usuario_id ORDER BY m.ativo DESC, m.nome")->fetchAll();
$titulo = 'CD · Motoristas';
require __DIR__ . '/inc/topo.php';
?>
<p class="sub">Para o motorista confirmar entregas pelo sistema, vincule o cadastro a um usuário com o perfil Motorista CD.</p>
<?php if (pode_cd_editar() || $ed['id']): ?>
<details class="cartao" <?= $ed['id'] ? 'open' : '' ?>>
  <summary><?= $ed['id'] ? 'Motorista' : 'Novo motorista' ?></summary>
  <form method="post"><fieldset class="grade" <?= pode_cd_editar() ? '' : 'disabled' ?>>
    <?= csrf_campo() ?>
    <input type="hidden" name="id" value="<?= (int)$ed['id'] ?>">
    <label class="l2">Nome*<input name="nome" required value="<?= e($ed['nome']) ?>"></label>
    <label>Telefone<input name="telefone" value="<?= e($ed['telefone']) ?>"></label>
    <label class="check"><input type="checkbox" name="ativo" <?= $ed['ativo'] ? 'checked' : '' ?>> Ativo</label>
    <label class="l2">Usuário do sistema (perfil Motorista CD)
      <select name="usuario_id">
        <option value="0">— sem acesso ao sistema —</option>
        <?php foreach ($usuarios as $u): ?>
          <option value="<?= (int)$u['id'] ?>" <?= $u['id'] == $ed['usuario_id'] ? 'selected' : '' ?>><?= e($u['nome'] . ' · ' . $u['email']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <div class="l4 acoes">
      <button type="submit">Salvar</button>
      <?php if ($ed['id']): ?><a class="btn sec" href="cd_motoristas.php">Cancelar</a><?php endif; ?>
    </div>
  </fieldset></form>
  <?php if ($ed['id']) mostrar_trilha('motorista', $ed['id']); ?>
</details>
<?php endif; ?>

<div class="cartao rolagem">
<table>
  <thead><tr><th>Nome</th><th>Telefone</th><th>Usuário vinculado</th><th>Situação</th><th>Entregas</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($lista as $m): ?>
    <tr>
      <td><?= e($m['nome']) ?></td>
      <td><?= e($m['telefone']) ?></td>
      <td><?= e($m['usuario'] ?: '—') ?></td>
      <td><?= $m['ativo'] ? 'Ativo' : 'Inativo' ?></td>
      <td><?= (int)$m['entregas'] ?></td>
      <td class="acoes">
        <a class="btn peq sec" href="cd_motoristas.php?editar=<?= (int)$m['id'] ?>"><?= pode_cd_editar() ? 'Editar' : 'Histórico' ?></a>
        <?php if (eh_admin()): ?>
        <form method="post" onsubmit="return confirm('Excluir este motorista? As entregas dele ficam sem motorista.')">
          <?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
          <button class="peq perigo" name="acao" value="excluir">Excluir</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; if (!$lista): ?><tr><td colspan="6" class="vazio">Nenhum motorista cadastrado.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<?php require __DIR__ . '/inc/rodape.php'; ?>
