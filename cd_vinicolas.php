<?php
require __DIR__ . '/inc/app.php';
exigir_login();
if (!pode_ver_cd_gestao()) negar();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    $antes = $id ? linha('cd_vinicolas', $id) : null;
    if (($_POST['acao'] ?? '') === 'excluir') {
        exigir_admin();
        try {
            $pdo->prepare('DELETE FROM cd_vinicolas WHERE id = ?')->execute([$id]);
            registrar_log('vinicola', $id, 'Exclusão', $antes['nome'] ?? '');
            flash('Vinícola excluída.');
        } catch (PDOException $ex) {
            flash('Não é possível excluir: a vinícola tem entregas registradas. Desative-a.', 'erro');
        }
        ir('cd_vinicolas.php');
    }
    exigir_cd_edicao();
    $nome = trim($_POST['nome'] ?? '');
    $email = trim($_POST['email'] ?? '');
    if ($nome === '' || ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL))) {
        flash('Informe o nome e um e-mail válido.', 'erro'); ir('cd_vinicolas.php' . ($id ? '?editar=' . $id : ''));
    }
    $d = [$nome, vazio_null($_POST['responsavel'] ?? ''), vazio_null($email), isset($_POST['ativo']) ? 1 : 0];
    if ($id) {
        $d[] = $id;
        $pdo->prepare('UPDATE cd_vinicolas SET nome=?, responsavel=?, email=?, ativo=? WHERE id=?')->execute($d);
        registrar_alteracao('vinicola', 'cd_vinicolas', $id, $antes);
    } else {
        $pdo->prepare('INSERT INTO cd_vinicolas (nome, responsavel, email, ativo) VALUES (?,?,?,?)')->execute($d);
        registrar_log('vinicola', (int)$pdo->lastInsertId(), 'Inclusão', $nome);
    }
    flash('Vinícola salva.'); ir('cd_vinicolas.php');
}

$ed = ['id' => 0, 'nome' => '', 'responsavel' => '', 'email' => '', 'ativo' => 1];
if (!empty($_GET['editar'])) $ed = linha('cd_vinicolas', (int)$_GET['editar']) ?: $ed;
$lista = $pdo->query("SELECT v.*, (SELECT COUNT(*) FROM cd_entregas en WHERE en.vinicola_id = v.id) entregas FROM cd_vinicolas v ORDER BY v.ativo DESC, v.nome")->fetchAll();
$titulo = 'CD · Vinícolas';
require __DIR__ . '/inc/topo.php';
?>
<?php if (pode_cd_editar() || $ed['id']): ?>
<details class="cartao" <?= $ed['id'] ? 'open' : '' ?>>
  <summary><?= $ed['id'] ? 'Vinícola' : 'Nova vinícola' ?></summary>
  <form method="post"><fieldset class="grade" <?= pode_cd_editar() ? '' : 'disabled' ?>>
    <?= csrf_campo() ?>
    <input type="hidden" name="id" value="<?= (int)$ed['id'] ?>">
    <label class="l2">Nome da vinícola*<input name="nome" required value="<?= e($ed['nome']) ?>"></label>
    <label class="l2">Responsável<input name="responsavel" value="<?= e($ed['responsavel']) ?>"></label>
    <label class="l2">E-mail (recebe os avisos de entrega e o extrato)<input type="email" name="email" value="<?= e($ed['email']) ?>"></label>
    <label class="check"><input type="checkbox" name="ativo" <?= $ed['ativo'] ? 'checked' : '' ?>> Ativa</label>
    <div class="l4 acoes">
      <button type="submit">Salvar</button>
      <?php if ($ed['id']): ?><a class="btn sec" href="cd_vinicolas.php">Cancelar</a><?php endif; ?>
    </div>
  </fieldset></form>
  <?php if ($ed['id']) mostrar_trilha('vinicola', $ed['id']); ?>
</details>
<?php endif; ?>

<div class="cartao rolagem">
<table>
  <thead><tr><th>Vinícola</th><th>Responsável</th><th>E-mail</th><th>Situação</th><th>Entregas</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($lista as $v): ?>
    <tr>
      <td><?= e($v['nome']) ?></td>
      <td><?= e($v['responsavel']) ?></td>
      <td><?= e($v['email']) ?></td>
      <td><?= $v['ativo'] ? 'Ativa' : 'Inativa' ?></td>
      <td><?= (int)$v['entregas'] ?></td>
      <td class="acoes">
        <a class="btn peq sec" href="cd_vinicolas.php?editar=<?= (int)$v['id'] ?>"><?= pode_cd_editar() ? 'Editar' : 'Histórico' ?></a>
        <?php if (eh_admin()): ?>
        <form method="post" onsubmit="return confirm('Excluir esta vinícola?')">
          <?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
          <button class="peq perigo" name="acao" value="excluir">Excluir</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; if (!$lista): ?><tr><td colspan="6" class="vazio">Nenhuma vinícola cadastrada.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<?php require __DIR__ . '/inc/rodape.php'; ?>
