<?php
require __DIR__ . '/inc/app.php';
exigir_login();
exigir_crm();
crm_exigir_migracao();
if (!pode_gerir_grupos()) negar();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    $antes = $id ? $pdo->query('SELECT * FROM crm_categorias WHERE id = ' . $id)->fetch() : null;
    if (($_POST['acao'] ?? '') === 'excluir') {
        exigir_admin();
        $n = (int)$pdo->query('SELECT COUNT(*) FROM clientes WHERE categoria_id = ' . $id)->fetchColumn();
        if ($n) { flash("Esta categoria tem $n cliente(s). Mova os clientes para outra categoria ou desative-a.", 'erro'); ir('crm_categorias.php'); }
        $pdo->prepare('DELETE FROM crm_categorias WHERE id = ?')->execute([$id]);
        registrar_log('categoria', $id, 'Exclusão', $antes['nome'] ?? '');
        flash('Categoria excluída.'); ir('crm_categorias.php');
    }
    $nome = mb_substr(texto_limpo($_POST['nome'] ?? ''), 0, 60);
    $tipo = in_array($_POST['tipo_pessoa'] ?? '', ['PF', 'PJ', 'ambos'], true) ? $_POST['tipo_pessoa'] : 'ambos';
    $d = [$nome, vazio_null(mb_substr(texto_limpo($_POST['descricao'] ?? ''), 0, 255)), $tipo, (int)($_POST['ordem'] ?? 100), isset($_POST['ativo']) ? 1 : 0];
    $s = $pdo->prepare('SELECT id FROM crm_categorias WHERE nome = ? AND id <> ?'); $s->execute([$nome, $id]);
    if ($nome === '' || $s->fetchColumn()) { flash($nome === '' ? 'Informe o nome.' : 'Já existe uma categoria com esse nome.', 'erro'); ir('crm_categorias.php' . ($id ? '?editar=' . $id : '')); }
    if ($id) {
        $pdo->prepare('UPDATE crm_categorias SET nome=?, descricao=?, tipo_pessoa=?, ordem=?, ativo=? WHERE id=?')->execute(array_merge($d, [$id]));
        registrar_log('categoria', $id, 'Alteração', ($antes['nome'] !== $nome ? $antes['nome'] . ' → ' : '') . $nome . ($d[4] ? '' : ' (inativa)'));
    } else {
        $pdo->prepare('INSERT INTO crm_categorias (nome, descricao, tipo_pessoa, ordem, ativo) VALUES (?,?,?,?,?)')->execute($d);
        registrar_log('categoria', (int)$pdo->lastInsertId(), 'Inclusão', $nome);
    }
    flash('Categoria salva.'); ir('crm_categorias.php');
}

$ed = ['id' => 0, 'nome' => '', 'descricao' => '', 'tipo_pessoa' => 'ambos', 'ordem' => 100, 'ativo' => 1];
if (!empty($_GET['editar'])) $ed = $pdo->query('SELECT * FROM crm_categorias WHERE id = ' . (int)$_GET['editar'])->fetch() ?: $ed;
$lista = $pdo->query('SELECT k.*, (SELECT COUNT(*) FROM clientes c WHERE c.categoria_id = k.id) clientes,
    (SELECT COUNT(*) FROM clientes c WHERE c.categoria_id = k.id AND c.categoria_auto = 1) automaticas FROM crm_categorias k ORDER BY k.ordem, k.nome')->fetchAll();
$semCat = (int)$pdo->query('SELECT COUNT(*) FROM clientes WHERE categoria_id IS NULL')->fetchColumn();
$titulo = 'Categorias de cliente';
require __DIR__ . '/inc/topo.php';
?>
<p class="sub"><strong>Categoria</strong> diz quem é o cliente (supermercado, restaurante, turista...). <strong>Origem</strong> diz por onde ele chegou (tour, e-commerce, ERP...). Clientes sem categoria recebem uma sugestão automática, marcada para revisão; ao escolher a categoria na ficha, ela vale como confirmada.</p>
<details class="cartao" <?= $ed['id'] ? 'open' : '' ?>>
  <summary><?= $ed['id'] ? 'Editar categoria' : 'Nova categoria' ?></summary>
  <form method="post" class="grade">
    <?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int)$ed['id'] ?>">
    <label class="l2">Nome*<input name="nome" required maxlength="60" value="<?= e($ed['nome']) ?>"></label>
    <label>Vale para
      <select name="tipo_pessoa"><?php foreach (['ambos' => 'Pessoa física e empresa', 'PF' => 'Pessoa física', 'PJ' => 'Empresa (PJ)'] as $k => $v): ?><option value="<?= $k ?>" <?= $ed['tipo_pessoa'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select>
    </label>
    <label>Ordem<input type="number" name="ordem" value="<?= (int)$ed['ordem'] ?>"></label>
    <label class="l3">Descrição<input name="descricao" maxlength="255" value="<?= e($ed['descricao']) ?>"></label>
    <label class="check"><input type="checkbox" name="ativo" <?= $ed['ativo'] ? 'checked' : '' ?>> Ativa</label>
    <div class="l4 acoes"><button type="submit">Salvar</button><?php if ($ed['id']): ?><a class="btn sec" href="crm_categorias.php">Cancelar</a><?php endif; ?></div>
  </form>
</details>
<div class="cartao rolagem"><table>
  <thead><tr><th>Categoria</th><th>Vale para</th><th class="num">Clientes</th><th class="num">Automáticas (revisar)</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($lista as $k): ?>
    <tr class="<?= $k['ativo'] ? '' : 'inativo' ?>">
      <td class="nome"><strong><?= e($k['nome']) ?></strong><?= $k['ativo'] ? '' : ' <span class="tag pend">Inativa</span>' ?><br><span class="sub"><?= e($k['descricao']) ?></span></td>
      <td><?= ['ambos' => 'PF e PJ', 'PF' => 'Pessoa física', 'PJ' => 'Empresa'][$k['tipo_pessoa']] ?></td>
      <td class="num"><a href="clientes.php?categoria=<?= (int)$k['id'] ?>"><?= (int)$k['clientes'] ?></a></td>
      <td class="num"><?php if ($k['automaticas']): ?><a href="clientes.php?categoria=<?= (int)$k['id'] ?>&cat_auto=1"><?= (int)$k['automaticas'] ?></a><?php else: ?>0<?php endif; ?></td>
      <td class="acoes">
        <a class="btn peq sec" href="crm_categorias.php?editar=<?= (int)$k['id'] ?>">Editar</a>
        <?php if (eh_admin() && !$k['clientes']): ?>
        <form method="post" onsubmit="return confirm('Excluir a categoria?')"><?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int)$k['id'] ?>"><button class="peq perigo" name="acao" value="excluir">Excluir</button></form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
    <tr><td class="nome sub">Sem categoria</td><td></td><td class="num"><?= $semCat ?></td><td></td><td></td></tr>
  </tbody>
</table></div>
<?php require __DIR__ . '/inc/rodape.php'; ?>
