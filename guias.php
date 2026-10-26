<?php
require __DIR__ . '/inc/app.php';
exigir_login();
exigir_tours();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    $antes = $id ? linha('guias', $id) : null;
    if (($_POST['acao'] ?? '') === 'excluir') {
        exigir_admin();
        $pdo->prepare('DELETE FROM guias WHERE id = ?')->execute([$id]);
        registrar_log('guia', $id, 'Exclusão', $antes['nome'] ?? '');
        flash('Guia excluído.'); ir('guias.php');
    }
    exigir_edicao();
    $nome = trim($_POST['nome'] ?? '');
    if ($nome === '') { flash('Informe o nome.', 'erro'); ir('guias.php'); }
    $funcao = in_array($_POST['funcao'] ?? '', ['guia', 'sommelier', 'ambos'], true) ? $_POST['funcao'] : 'guia';
    $d = [$nome, $funcao, vazio_null($_POST['telefone'] ?? ''), vazio_null($_POST['email'] ?? ''), isset($_POST['ativo']) ? 1 : 0];
    if ($id) {
        $d[] = $id;
        $pdo->prepare('UPDATE guias SET nome=?, funcao=?, telefone=?, email=?, ativo=? WHERE id=?')->execute($d);
        registrar_alteracao('guia', 'guias', $id, $antes);
    } else {
        $pdo->prepare('INSERT INTO guias (nome, funcao, telefone, email, ativo) VALUES (?,?,?,?,?)')->execute($d);
        registrar_log('guia', (int)$pdo->lastInsertId(), 'Inclusão', $nome);
    }
    flash('Guia salvo.'); ir('guias.php');
}

$ed = ['id'=>0,'nome'=>'','funcao'=>'guia','telefone'=>'','email'=>'','ativo'=>1];
if (!empty($_GET['editar'])) {
    $st = $pdo->prepare('SELECT * FROM guias WHERE id = ?'); $st->execute([(int)$_GET['editar']]);
    $ed = $st->fetch() ?: $ed;
}
$lista = $pdo->query("SELECT g.*, (SELECT COUNT(*) FROM tour_vendas v WHERE v.guia_id = g.id) tours FROM guias g ORDER BY g.ativo DESC, g.nome")->fetchAll();
$titulo = 'Guias / Sommeliers';
require __DIR__ . '/inc/topo.php';
?>
<?php if (pode_editar() || $ed['id']): ?>
<details class="cartao" <?= $ed['id'] ? 'open' : '' ?>>
  <summary><?= $ed['id'] ? 'Editar guia / sommelier' : 'Novo guia / sommelier' ?></summary>
  <form method="post"><fieldset class="grade" <?= pode_editar() ? '' : 'disabled' ?>>
    <?= csrf_campo() ?>
    <input type="hidden" name="id" value="<?= (int)$ed['id'] ?>">
    <label class="l2">Nome*<input name="nome" required value="<?= e($ed['nome']) ?>"></label>
    <label>Função
      <select name="funcao">
        <?php foreach (['guia', 'sommelier', 'ambos'] as $fn): ?>
          <option value="<?= $fn ?>" <?= $ed['funcao'] === $fn ? 'selected' : '' ?>><?= nome_funcao($fn) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Telefone<input name="telefone" value="<?= e($ed['telefone']) ?>"></label>
    <label class="l2">E-mail<input type="email" name="email" value="<?= e($ed['email']) ?>"></label>
    <label class="check"><input type="checkbox" name="ativo" <?= $ed['ativo'] ? 'checked' : '' ?>> Ativo</label>
    <div class="l4 acoes">
      <button type="submit">Salvar</button>
      <?php if ($ed['id']): ?><a class="btn sec" href="guias.php">Cancelar</a><?php endif; ?>
    </div>
  </fieldset></form>
  <?php if ($ed['id']) mostrar_trilha('guia', $ed['id']); ?>
</details>
<?php endif; ?>

<div class="cartao rolagem">
<table>
  <thead><tr><th>Nome</th><th>Função</th><th>Telefone</th><th>E-mail</th><th>Situação</th><th>Tours</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($lista as $g): ?>
    <tr>
      <td><?= e($g['nome']) ?></td>
      <td><?= e(nome_funcao($g['funcao'])) ?></td>
      <td><?= e($g['telefone']) ?></td>
      <td><?= e($g['email']) ?></td>
      <td><?= $g['ativo'] ? 'Ativo' : 'Inativo' ?></td>
      <td><?= (int)$g['tours'] ?></td>
      <td class="acoes">
        <a class="btn peq sec" href="guias.php?editar=<?= (int)$g['id'] ?>"><?= pode_editar() ? 'Editar' : 'Histórico' ?></a>
        <?php if (eh_admin()): ?>
        <form method="post" onsubmit="return confirm('Excluir este guia? Os tours dele ficam sem responsável.')">
          <?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
          <button class="peq perigo" name="acao" value="excluir">Excluir</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; if (!$lista): ?><tr><td colspan="7" class="vazio">Nenhum guia cadastrado.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<?php require __DIR__ . '/inc/rodape.php'; ?>
