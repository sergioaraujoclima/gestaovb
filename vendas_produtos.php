<?php
require __DIR__ . '/inc/app.php';
exigir_login();
exigir_vendas();
vendas_exigir_migracao();
$pdo = db();
$custos = ver_custos();
$podeEditar = pode_vincular_vendas();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$podeEditar) negar();
    $id = (int)($_POST['id'] ?? 0);
    $antes = linha('produtos', $id);
    if (!$antes) { flash('Produto não encontrado.', 'erro'); ir('vendas_produtos.php'); }
    $desc = mb_substr(texto_limpo($_POST['descricao'] ?? ''), 0, 200);
    $tipo = isset(tipos_produto()[$_POST['tipo'] ?? '']) ? $_POST['tipo'] : $antes['tipo'];
    if ($desc === '') { flash('Informe a descrição.', 'erro'); ir('vendas_produtos.php?editar=' . $id); }
    $pdo->prepare('UPDATE produtos SET descricao = ?, tipo = ?, ativo = ? WHERE id = ?')->execute([$desc, $tipo, isset($_POST['ativo']) ? 1 : 0, $id]);
    registrar_alteracao('produto', 'produtos', $id, $antes);
    // Grupos que dependem do tipo ou do produto comprado mudam junto.
    if ($tipo !== $antes['tipo']) grupos_recalcular_todos();
    flash('Produto salvo.');
    ir('vendas_produtos.php?ver=' . $id);
}

$per = vendas_periodo($_GET);
$qsPer = array_filter(['p' => $per['p'] === 'livre' ? '' : $per['p'], 'ini' => $per['p'] === 'livre' ? $per['ini'] : '', 'fim' => $per['p'] === 'livre' ? $per['fim'] : '']);
$fmt = fn($n) => number_format((float)$n, 0, ',', '.');
$P = [$per['ini'] . ' 00:00:00', $per['fim'] . ' 23:59:59'];

// ---------- Ficha do produto ----------
$verId = (int)($_GET['ver'] ?? $_GET['editar'] ?? 0);
if ($verId) {
    $p = linha('produtos', $verId);
    if (!$p) { flash('Produto não encontrado.', 'erro'); ir('vendas_produtos.php'); }
    $s = $pdo->prepare('SELECT COALESCE(SUM(i.quantidade), 0) qtd, COALESCE(SUM(i.liquido), 0) receita, COUNT(DISTINCT v.id) nvendas, COUNT(DISTINCT v.cliente_id) nclientes,
        COALESCE(SUM(CASE WHEN i.custo_total > 0 THEN i.liquido ELSE 0 END), 0) rc, COALESCE(SUM(CASE WHEN i.custo_total > 0 THEN i.lucro ELSE 0 END), 0) lc
        FROM venda_itens i JOIN vendas v ON v.id = i.venda_id WHERE i.produto_id = ? AND i.bonificacao = 0 AND v.data >= ? AND v.data <= ?');
    $s->execute(array_merge([$verId], $P)); $m = $s->fetch();
    $s = $pdo->prepare('SELECT COALESCE(SUM(i.quantidade), 0) FROM venda_itens i JOIN vendas v ON v.id = i.venda_id WHERE i.produto_id = ? AND i.bonificacao = 1 AND v.data >= ? AND v.data <= ?');
    $s->execute(array_merge([$verId], $P)); $qBonif = (float)$s->fetchColumn();
    $s = $pdo->prepare('SELECT c.id, c.nome, c.tipo_pessoa, SUM(i.quantidade) qtd, SUM(i.liquido) valor, MAX(v.data) ultima FROM venda_itens i JOIN vendas v ON v.id = i.venda_id JOIN clientes c ON c.id = v.cliente_id
        WHERE i.produto_id = ? AND i.bonificacao = 0 AND v.data >= ? AND v.data <= ? GROUP BY c.id, c.nome, c.tipo_pessoa ORDER BY valor DESC LIMIT 30');
    $s->execute(array_merge([$verId], $P)); $compradores = $s->fetchAll();
    $s = $pdo->prepare('SELECT SUM(i.quantidade) FROM venda_itens i JOIN vendas v ON v.id = i.venda_id WHERE i.produto_id = ? AND i.bonificacao = 0 AND v.cliente_id IS NULL AND v.data >= ? AND v.data <= ?');
    $s->execute(array_merge([$verId], $P)); $qBalcao = (float)$s->fetchColumn();
    $meses = [];
    $s = $pdo->prepare('SELECT SUBSTR(v.data, 1, 7) m, SUM(i.quantidade) qtd, SUM(i.liquido) valor FROM venda_itens i JOIN vendas v ON v.id = i.venda_id WHERE i.produto_id = ? AND i.bonificacao = 0 GROUP BY SUBSTR(v.data, 1, 7) ORDER BY m DESC LIMIT 12');
    $s->execute([$verId]); $meses = array_reverse($s->fetchAll());
    $editar = isset($_GET['editar']) && $podeEditar;
    $titulo = $p['descricao'];
    require __DIR__ . '/inc/topo.php';
?>
<form method="get" class="filtro"><input type="hidden" name="ver" value="<?= (int)$verId ?>"><?= vendas_seletor_periodo($per) ?><button>Aplicar</button>
  <a class="btn sec" href="vendas_produtos.php?<?= e(http_build_query($qsPer)) ?>">Todos os produtos</a>
  <?php if ($podeEditar && !$editar): ?><a class="btn sec" href="vendas_produtos.php?editar=<?= (int)$verId ?>&<?= e(http_build_query($qsPer)) ?>">Editar</a><?php endif; ?>
</form>
<p class="sub">Código <?= e($p['codigo']) ?> · <?= e(nome_tipo_produto($p['tipo'])) ?> · grupo no ERP: <?= e($p['grupo'] ?: '—') ?><?= $p['ean'] ? ' · EAN ' . e($p['ean']) : '' ?> · último preço <?= $p['preco_venda'] !== null ? brl($p['preco_venda']) : '—' ?><?= $custos && $p['preco_custo'] !== null ? ' · custo ' . brl($p['preco_custo']) : '' ?><?= $p['ativo'] ? '' : ' · <strong>inativo</strong>' ?></p>
<?php if ($editar): ?>
<div class="cartao">
  <form method="post" class="grade">
    <?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
    <label class="l2">Descrição<input name="descricao" required maxlength="200" value="<?= e($p['descricao']) ?>"></label>
    <label>Tipo<select name="tipo"><?php foreach (tipos_produto() as $k => $v): ?><option value="<?= $k ?>" <?= $p['tipo'] === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></label>
    <label class="check"><input type="checkbox" name="ativo" <?= $p['ativo'] ? 'checked' : '' ?>> Ativo</label>
    <p class="l4 sub" style="margin:0">A descrição e o tipo editados aqui ficam: as próximas importações só atualizam grupo, EAN e últimos preços. O tipo alimenta os grupos de campanha ("Tipo de produto comprado").</p>
    <div class="l4 acoes"><button>Salvar</button><a class="btn sec" href="vendas_produtos.php?ver=<?= (int)$p['id'] ?>">Cancelar</a></div>
  </form>
</div>
<?php endif; ?>
<div class="cards">
  <div class="card"><span>Receita · <?= e($per['rot']) ?></span><strong><?= brl($m['receita']) ?></strong></div>
  <div class="card"><span>Quantidade vendida</span><strong><?= $fmt($m['qtd']) ?></strong><small>preço médio <?= brl($m['qtd'] > 0 ? $m['receita'] / $m['qtd'] : 0) ?></small></div>
  <a class="card" href="vendas_lista.php?<?= e(http_build_query(array_merge($qsPer, ['produto' => $verId]))) ?>"><span>Vendas</span><strong><?= $fmt($m['nvendas']) ?></strong><small><?= $fmt($m['nclientes']) ?> clientes identificados · <?= $fmt($qBalcao) ?> un. no balcão</small></a>
  <?php if ($custos): ?><div class="card"><span>Margem</span><strong><?= $m['rc'] > 0 ? number_format($m['lc'] * 100 / $m['rc'], 1, ',', '.') . '%' : '—' ?></strong><small><?= $m['rc'] > 0 ? 'lucro ' . brl($m['lc']) : 'sem custo no ERP' ?></small></div><?php endif; ?>
  <?php if ($qBonif > 0): ?><div class="card alerta"><span>Bonificado / amostra</span><strong><?= $fmt($qBonif) ?> un.</strong></div><?php endif; ?>
</div>
<div class="painel-grade">
  <section class="cartao quadro largo">
    <h2>Quem comprou <small><?= e($per['rot']) ?> · para campanhas use a regra "Produto comprado" nos grupos</small></h2>
    <div class="rolagem"><table>
      <thead><tr><th>Cliente</th><th>Tipo</th><th class="num">Qtd</th><th class="num">Valor</th><th>Última compra</th></tr></thead>
      <tbody><?php foreach ($compradores as $c): ?><tr><td class="nome"><a href="clientes.php?editar=<?= (int)$c['id'] ?>"><?= e($c['nome']) ?></a></td><td><?= $c['tipo_pessoa'] === 'PJ' ? 'Empresa' : 'Pessoa' ?></td><td class="num"><?= $fmt($c['qtd']) ?></td><td class="num"><?= brl($c['valor']) ?></td><td><?= data_br($c['ultima']) ?></td></tr><?php endforeach; ?>
      <?php if (!$compradores): ?><tr><td colspan="5" class="vazio">Nenhum cliente identificado comprou no período.</td></tr><?php endif; ?></tbody>
    </table></div>
  </section>
  <section class="cartao quadro largo">
    <h2>Por mês <small>quantidade vendida</small></h2>
    <?= painel_barras(array_map(fn($r) => [rotulo_mes($r['m']), (float)$r['qtd'], null, brl($r['valor'])], $meses)) ?>
  </section>
</div>
<?php mostrar_trilha('produto', $verId); ?>
<?php require __DIR__ . '/inc/rodape.php'; exit; } ?>
<?php
// ---------- Lista ----------
$tipoF = isset(tipos_produto()[$_GET['tipo'] ?? '']) ? $_GET['tipo'] : '';
$qF = mb_substr(texto_limpo($_GET['q'] ?? ''), 0, 100);
$w = ['1 = 1']; $par = [];
if ($tipoF !== '') { $w[] = 'p.tipo = ?'; $par[] = $tipoF; }
if ($qF !== '') { $w[] = '(p.descricao LIKE ? OR p.codigo LIKE ? OR p.ean LIKE ?)'; array_push($par, "%$qF%", "%$qF%", "%$qF%"); }
$s = $pdo->prepare('SELECT p.*, COALESCE(x.qtd, 0) qtd, COALESCE(x.receita, 0) receita, COALESCE(x.nvendas, 0) nvendas, COALESCE(x.nclientes, 0) nclientes, x.rc, x.lc, u.ultima
    FROM produtos p
    LEFT JOIN (SELECT i.produto_id, SUM(i.quantidade) qtd, SUM(i.liquido) receita, COUNT(DISTINCT v.id) nvendas, COUNT(DISTINCT v.cliente_id) nclientes,
        SUM(CASE WHEN i.custo_total > 0 THEN i.liquido ELSE 0 END) rc, SUM(CASE WHEN i.custo_total > 0 THEN i.lucro ELSE 0 END) lc
        FROM venda_itens i JOIN vendas v ON v.id = i.venda_id WHERE i.bonificacao = 0 AND v.data >= ? AND v.data <= ? GROUP BY i.produto_id) x ON x.produto_id = p.id
    LEFT JOIN (SELECT i.produto_id, MAX(v.data) ultima FROM venda_itens i JOIN vendas v ON v.id = i.venda_id WHERE i.bonificacao = 0 GROUP BY i.produto_id) u ON u.produto_id = p.id
    WHERE ' . implode(' AND ', $w) . ' ORDER BY receita DESC, p.ativo DESC, p.descricao');
$s->execute(array_merge($P, $par)); $lista = $s->fetchAll();
$recTotal = array_sum(array_column($lista, 'receita'));
$titulo = 'Produtos';
require __DIR__ . '/inc/topo.php';
?>
<form method="get" class="filtro">
  <?= vendas_seletor_periodo($per) ?>
  <input name="q" placeholder="Descrição, código ou EAN" value="<?= e($qF) ?>">
  <select name="tipo" style="width:auto"><option value="">Todo tipo</option><?php foreach (tipos_produto() as $k => $v): ?><option value="<?= $k ?>" <?= $tipoF === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select>
  <button>Filtrar</button>
</form>
<p class="sub"><?= count($lista) ?> produto(s) · <?= e($per['rot']) ?> · receita <?= brl($recTotal) ?>. Os produtos entram sozinhos pela importação de vendas; corrija aqui o tipo (tinto, branco, espumante...) quando a sugestão errar.</p>
<div class="cartao rolagem"><table>
  <thead><tr><th>Produto</th><th>Código</th><th>Tipo</th><th class="num">Qtd</th><th class="num">Receita</th><th class="num">%</th><th class="num">Preço médio</th><th class="num">Vendas</th><th class="num">Clientes</th><?php if ($custos): ?><th class="num">Margem</th><?php endif; ?><th>Última venda</th></tr></thead>
  <tbody>
  <?php foreach ($lista as $p): ?>
    <tr class="<?= $p['ativo'] ? '' : 'inativo' ?>">
      <td class="nome"><a href="vendas_produtos.php?<?= e(http_build_query(array_merge($qsPer, ['ver' => $p['id']]))) ?>"><?= e($p['descricao']) ?></a><br><span class="sub"><?= e($p['grupo']) ?></span></td>
      <td><?= e($p['codigo']) ?></td>
      <td><?= e(nome_tipo_produto($p['tipo'])) ?></td>
      <td class="num"><?= $fmt($p['qtd']) ?></td>
      <td class="num"><?= brl($p['receita']) ?></td>
      <td class="num"><?= $recTotal ? round($p['receita'] * 100 / $recTotal, 1) : 0 ?>%</td>
      <td class="num"><?= $p['qtd'] > 0 ? brl($p['receita'] / $p['qtd']) : '' ?></td>
      <td class="num"><?= $fmt($p['nvendas']) ?></td>
      <td class="num"><?= $fmt($p['nclientes']) ?></td>
      <?php if ($custos): ?><td class="num"><?= $p['rc'] > 0 ? '<span class="' . ($p['lc'] < 0 ? 'alerta-txt' : '') . '">' . number_format($p['lc'] * 100 / $p['rc'], 1, ',', '.') . '%</span>' : '<span class="sub">—</span>' ?></td><?php endif; ?>
      <td><?= data_br($p['ultima']) ?></td>
    </tr>
  <?php endforeach; if (!$lista): ?><tr><td colspan="11" class="vazio">Nenhum produto. Eles são cadastrados pela importação de vendas.</td></tr><?php endif; ?>
  </tbody>
</table></div>
<?php require __DIR__ . '/inc/rodape.php'; ?>
