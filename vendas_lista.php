<?php
require __DIR__ . '/inc/app.php';
exigir_login();
exigir_vendas();
vendas_exigir_migracao();
$pdo = db();
$custos = ver_custos();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!pode_vincular_vendas()) negar();
    $vid = (int)($_POST['venda_id'] ?? 0);
    $cid = ($_POST['acao'] ?? '') === 'desvincular' ? 0 : (int)($_POST['cliente_id'] ?? 0);
    if (($_POST['acao'] ?? '') === 'vincular' && !$cid) { flash('Escolha o cliente na busca.', 'erro'); ir('vendas_lista.php?ver=' . $vid); }
    $n = vendas_vincular($vid, $cid, true);
    flash($n ? ($cid ? 'Vínculo salvo em ' . $n . ' venda(s).' : 'Vínculo removido de ' . $n . ' venda(s).') . ' Vale também para as próximas importações deste nome.' : 'Venda não encontrada.', $n ? 'ok' : 'erro');
    ir('vendas_lista.php?ver=' . $vid);
}

// ---------- Detalhe de uma venda ----------
if (!empty($_GET['ver'])) {
    $s = $pdo->prepare('SELECT v.*, c.nome cli, c.tipo_pessoa FROM vendas v LEFT JOIN clientes c ON c.id = v.cliente_id WHERE v.id = ?');
    $s->execute([(int)$_GET['ver']]); $v = $s->fetch();
    if (!$v) { flash('Venda não encontrada.', 'erro'); ir('vendas_lista.php'); }
    $s = $pdo->prepare('SELECT i.*, p.tipo, p.codigo FROM venda_itens i LEFT JOIN produtos p ON p.id = i.produto_id WHERE i.venda_id = ? ORDER BY i.bonificacao, i.liquido DESC');
    $s->execute([$v['id']]); $itens = $s->fetchAll();
    $outras = 0;
    if ($v['cliente_nome']) { $s = $pdo->prepare('SELECT COUNT(*) FROM vendas WHERE cliente_nome = ?'); $s->execute([$v['cliente_nome']]); $outras = (int)$s->fetchColumn(); }
    $titulo = $v['documento'];
    require __DIR__ . '/inc/topo.php';
?>
<p class="acoes"><a class="btn peq sec" href="javascript:history.back()">Voltar</a></p>
<div class="cards">
  <div class="card"><span>Data</span><strong><?= date('d/m/Y H:i', strtotime($v['data'])) ?></strong><small><?= dia_semana(substr($v['data'], 0, 10)) ?></small></div>
  <div class="card"><span>Valor da venda</span><strong><?= brl($v['valor_total']) ?></strong><small><?= $v['desconto'] > 0 ? 'desconto ' . brl($v['desconto']) : '' ?><?= $v['acrescimo'] > 0 ? ' acréscimo ' . brl($v['acrescimo']) : '' ?></small></div>
  <?php if ($v['valor_bonificacao'] > 0): ?><div class="card alerta"><span>Bonificação / amostra</span><strong><?= brl($v['valor_bonificacao']) ?></strong><small>fora do faturamento</small></div><?php endif; ?>
  <div class="card"><span>Canal</span><strong style="font-size:15px"><?= e(nome_canal($v['canal'])) ?></strong><small><?= e(trim($v['pdv'] . ' · ' . $v['deposito'], ' ·')) ?></small></div>
  <div class="card"><span>Vendedor</span><strong style="font-size:15px"><?= e($v['vendedor'] ?: '—') ?></strong></div>
  <?php if ($custos): ?><div class="card"><span>Lucro bruto</span><strong><?= brl($v['lucro']) ?></strong><small>custo <?= brl($v['custo_total']) ?></small></div><?php endif; ?>
</div>

<div class="cartao">
  <h2 style="margin-top:0">Cliente</h2>
  <p>Nome no ERP: <strong><?= e($v['cliente_nome'] ?: '— (venda sem cliente)') ?></strong><?= $outras > 1 ? ' · ' . $outras . ' vendas com este nome' : '' ?><br>
  No cadastro: <?= $v['cliente_id'] ? '<a href="clientes.php?editar=' . (int)$v['cliente_id'] . '"><strong>' . e($v['cli']) . '</strong></a> (' . ($v['tipo_pessoa'] === 'PJ' ? 'empresa' : 'pessoa física') . ')' : '<span class="tag pend">sem vínculo</span>' ?></p>
  <?php if (pode_vincular_vendas()): ?>
  <form method="post" class="acoes" id="form-vinc" style="flex-wrap:wrap">
    <?= csrf_campo() ?><input type="hidden" name="venda_id" value="<?= (int)$v['id'] ?>">
    <div class="busca-cli busca-vinc" style="min-width:320px">
      <input type="search" id="vb" placeholder="<?= $v['cliente_id'] ? 'Trocar o cliente: digite nome, CNPJ, CPF ou telefone' : 'Buscar cliente: nome, CNPJ, CPF ou telefone' ?>" autocomplete="off">
      <input type="hidden" name="cliente_id" id="vb-id" value="0">
      <div class="autocomplete" id="vb-res" hidden></div>
    </div>
    <button class="peq" name="acao" value="vincular">Vincular</button>
    <?php if ($v['cliente_id']): ?><button class="peq perigo" name="acao" value="desvincular" onclick="return confirm('Tirar o vínculo<?= $v['cliente_nome'] ? ' de todas as vendas com este nome' : '' ?>?')">Desvincular</button><?php endif; ?>
    <?php if ($v['cliente_nome']): ?><span class="sub" style="margin:0">Vale para as <?= max(1, $outras) ?> venda(s) com este nome e para as próximas importações.</span><?php endif; ?>
  </form>
  <p class="sub">Cliente ainda não cadastrado? <a href="clientes.php?novo=1" target="_blank">Cadastre-o</a> e depois volte aqui para vincular.</p>
  <?php endif; ?>
</div>

<h2>Itens <small><?= count($itens) ?></small></h2>
<div class="cartao rolagem"><table>
  <thead><tr><th>Produto</th><th>Tipo</th><th>CFOP</th><th class="num">Qtd</th><th class="num">Preço</th><th class="num">Desconto</th><th class="num">Acréscimo</th><th class="num">Líquido</th><?php if ($custos): ?><th class="num">Custo</th><th class="num">Lucro</th><?php endif; ?></tr></thead>
  <tbody>
  <?php foreach ($itens as $i): ?>
    <tr class="<?= $i['bonificacao'] ? 'inativo' : '' ?>"><td class="nome"><?= $i['produto_id'] ? '<a href="vendas_produtos.php?ver=' . (int)$i['produto_id'] . '">' . e($i['descricao']) . '</a>' : e($i['descricao']) ?><?= $i['bonificacao'] ? ' <span class="tag pend">bonificação/amostra</span>' : '' ?></td>
      <td><?= e(nome_tipo_produto($i['tipo'])) ?></td><td><?= e($i['cfop']) ?></td><td class="num"><?= rtrim(rtrim(number_format($i['quantidade'], 3, ',', '.'), '0'), ',') ?></td>
      <td class="num"><?= brl($i['preco_unit']) ?></td><td class="num"><?= $i['desconto'] > 0 ? brl($i['desconto']) : '' ?></td><td class="num"><?= $i['acrescimo'] > 0 ? brl($i['acrescimo']) : '' ?></td><td class="num"><?= brl($i['liquido']) ?></td>
      <?php if ($custos): ?><td class="num"><?= brl($i['custo_total']) ?></td><td class="num"><?= brl($i['lucro']) ?></td><?php endif; ?></tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<p class="sub">Documento <?= e($v['documento']) ?><?= $v['serie'] ? ' · série ' . e($v['serie']) : '' ?><?= $v['chave_acesso'] ? ' · chave ' . e($v['chave_acesso']) : '' ?><?= $v['observacao'] ? ' · ' . e($v['observacao']) : '' ?></p>
<?php mostrar_trilha('venda', $v['id']); ?>
<script>
(function () {
  var busca = document.getElementById('vb'); if (!busca) return;
  var id = document.getElementById('vb-id'), res = document.getElementById('vb-res'), t = null, ultimo = '';
  function limpar() { res.hidden = true; res.innerHTML = ''; }
  busca.addEventListener('input', function () {
    id.value = 0; clearTimeout(t); var q = busca.value.trim();
    if (q.length < 2) { limpar(); return; }
    t = setTimeout(function () {
      ultimo = q;
      fetch('clientes_busca.php?q=' + encodeURIComponent(q), {credentials: 'same-origin'}).then(function (r) { return r.json(); }).then(function (lista) {
        if (q !== ultimo) return;
        res.innerHTML = '';
        if (!lista.length) { res.innerHTML = '<div class="vazio">Nenhum cliente encontrado.</div>'; res.hidden = false; return; }
        lista.forEach(function (c) {
          var b = document.createElement('button'); b.type = 'button';
          b.textContent = c.nome + (c.telefone ? ' · ' + c.telefone : '') + ' (#' + c.id + ')';
          b.addEventListener('click', function () { id.value = c.id; busca.value = b.textContent; limpar(); });
          res.appendChild(b);
        });
        res.hidden = false;
      }).catch(limpar);
    }, 250);
  });
  document.addEventListener('click', function (e) { if (!res.contains(e.target) && e.target !== busca) limpar(); });
})();
</script>
<?php
    require __DIR__ . '/inc/rodape.php';
    exit;
}

// ---------- Lista ----------
$per = vendas_periodo($_GET);
$flt = [
    'q' => mb_substr(texto_limpo($_GET['q'] ?? ''), 0, 100),
    'canal' => in_array($_GET['canal'] ?? '', ['loja', 'faturada'], true) ? $_GET['canal'] : '',
    'vinculo' => in_array($_GET['vinculo'] ?? '', ['com', 'nome', 'balcao'], true) ? $_GET['vinculo'] : '',
    'pessoa' => in_array($_GET['pessoa'] ?? '', ['PF', 'PJ', 'sem'], true) ? $_GET['pessoa'] : '',
    'vendedor' => mb_substr(texto_limpo($_GET['vendedor'] ?? ''), 0, 120),
    'categoria' => (int)($_GET['categoria'] ?? 0) ?: '',
    'cliente' => (int)($_GET['cliente'] ?? 0) ?: '',
    'produto' => (int)($_GET['produto'] ?? 0) ?: '',
    'bonif' => ($_GET['bonif'] ?? '') === '1' ? '1' : '',
];
$w = ['v.data >= ?', 'v.data <= ?']; $par = [$per['ini'] . ' 00:00:00', $per['fim'] . ' 23:59:59'];
if ($flt['cliente']) { $w = ['v.cliente_id = ?']; $par = [$flt['cliente']]; }   // histórico completo do cliente
if ($flt['q'] !== '') { $w[] = '(v.cliente_nome LIKE ? OR c.nome LIKE ? OR v.documento LIKE ?)'; array_push($par, "%{$flt['q']}%", "%{$flt['q']}%", "%{$flt['q']}%"); }
if ($flt['canal'] !== '') { $w[] = 'v.canal = ?'; $par[] = $flt['canal']; }
if ($flt['vinculo'] === 'com') $w[] = 'v.cliente_id IS NOT NULL';
if ($flt['vinculo'] === 'nome') $w[] = "v.cliente_id IS NULL AND v.cliente_nome IS NOT NULL AND v.cliente_nome <> ''";
if ($flt['vinculo'] === 'balcao') $w[] = "v.cliente_id IS NULL AND (v.cliente_nome IS NULL OR v.cliente_nome = '')";
if ($flt['pessoa'] === 'sem') $w[] = 'v.cliente_id IS NULL'; elseif ($flt['pessoa'] !== '') { $w[] = 'c.tipo_pessoa = ?'; $par[] = $flt['pessoa']; }
if ($flt['vendedor'] !== '') { $w[] = 'v.vendedor = ?'; $par[] = $flt['vendedor']; }
if ($flt['categoria'] !== '') { $w[] = 'c.categoria_id = ?'; $par[] = (int)$flt['categoria']; }
if ($flt['produto']) { $w[] = 'EXISTS (SELECT 1 FROM venda_itens i WHERE i.venda_id = v.id AND i.produto_id = ? AND i.bonificacao = 0)'; $par[] = $flt['produto']; }
if ($flt['bonif']) $w[] = 'v.valor_bonificacao > 0';
$where = implode(' AND ', $w);
$s = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(v.valor_total), 0) total, COALESCE(SUM(v.valor_bonificacao), 0) bonif, SUM(CASE WHEN v.valor_total > 0 THEN 1 ELSE 0 END) nv FROM vendas v LEFT JOIN clientes c ON c.id = v.cliente_id WHERE $where");
$s->execute($par); $res = $s->fetch();
$porPag = 50; $total = (int)$res['n'];
$pag = max(1, min((int)($_GET['pg'] ?? 1), max(1, (int)ceil($total / $porPag))));
$s = $pdo->prepare("SELECT v.*, c.nome cli, c.tipo_pessoa FROM vendas v LEFT JOIN clientes c ON c.id = v.cliente_id WHERE $where ORDER BY v.data DESC LIMIT $porPag OFFSET " . (($pag - 1) * $porPag));
$s->execute($par); $lista = $s->fetchAll();
// Nomes do ERP sem vínculo no recorte (para vincular de uma vez).
$nomesSem = [];
if ($flt['vinculo'] === 'nome') {
    $s = $pdo->prepare("SELECT v.cliente_nome, COUNT(*) n, SUM(v.valor_total) total, MAX(v.id) id FROM vendas v LEFT JOIN clientes c ON c.id = v.cliente_id WHERE $where GROUP BY v.cliente_nome ORDER BY total DESC");
    $s->execute($par); $nomesSem = $s->fetchAll();
}
$nomeCliente = $flt['cliente'] ? (linha('clientes', $flt['cliente'])['nome'] ?? '#' . $flt['cliente']) : '';
$nomeProduto = $flt['produto'] ? ($pdo->query('SELECT descricao FROM produtos WHERE id = ' . (int)$flt['produto'])->fetchColumn() ?: '#' . $flt['produto']) : '';
$qs = function ($extra = []) use ($flt, $per) {
    $b = array_merge($flt, $per['p'] === 'livre' ? ['ini' => $per['ini'], 'fim' => $per['fim']] : ['p' => $per['p']], $extra);
    return http_build_query(array_filter($b, fn($v) => $v !== '' && $v !== null));
};
$vendedoresLista = $pdo->query("SELECT DISTINCT vendedor FROM vendas WHERE vendedor IS NOT NULL AND vendedor <> '' ORDER BY vendedor")->fetchAll(PDO::FETCH_COLUMN);
$titulo = $flt['cliente'] ? 'Compras de ' . $nomeCliente : 'Vendas';
require __DIR__ . '/inc/topo.php';
?>
<form method="get" class="filtro">
  <?php if (!$flt['cliente']): ?><?= vendas_seletor_periodo($per) ?><?php else: ?><input type="hidden" name="cliente" value="<?= (int)$flt['cliente'] ?>"><span class="sub" style="margin:0">Todas as compras do cliente · <a href="clientes.php?editar=<?= (int)$flt['cliente'] ?>">abrir ficha</a></span><?php endif; ?>
  <input name="q" placeholder="Cliente ou documento" value="<?= e($flt['q']) ?>">
  <select name="vinculo" style="width:auto"><option value="">Todas</option><option value="com" <?= $flt['vinculo'] === 'com' ? 'selected' : '' ?>>Com cliente vinculado</option><option value="nome" <?= $flt['vinculo'] === 'nome' ? 'selected' : '' ?>>Nome do ERP sem vínculo</option><option value="balcao" <?= $flt['vinculo'] === 'balcao' ? 'selected' : '' ?>>Balcão (sem nome)</option></select>
  <select name="canal" style="width:auto"><option value="">Todos os canais</option><?php foreach (['loja', 'faturada'] as $k): ?><option value="<?= $k ?>" <?= $flt['canal'] === $k ? 'selected' : '' ?>><?= e(nome_canal($k)) ?></option><?php endforeach; ?></select>
  <select name="vendedor" style="width:auto"><option value="">Todo vendedor</option><?php foreach ($vendedoresLista as $vd): ?><option <?= $flt['vendedor'] === $vd ? 'selected' : '' ?>><?= e($vd) ?></option><?php endforeach; ?></select>
  <label class="check"><input type="checkbox" name="bonif" value="1" <?= $flt['bonif'] ? 'checked' : '' ?>> Com bonificação</label>
  <?php foreach (['pessoa', 'categoria', 'produto'] as $hk): if ($flt[$hk] !== ''): ?><input type="hidden" name="<?= $hk ?>" value="<?= e($flt[$hk]) ?>"><?php endif; endforeach; ?>
  <button>Filtrar</button>
</form>
<?php $extras = array_filter(['pessoa' => $flt['pessoa'] ? 'Comprador: ' . ['PF' => 'pessoas físicas', 'PJ' => 'empresas', 'sem' => 'sem cliente'][$flt['pessoa']] : '', 'categoria' => $flt['categoria'] ? 'Categoria: ' . nome_categoria($flt['categoria']) : '', 'produto' => $flt['produto'] ? 'Produto: ' . $nomeProduto : '']); if ($extras): ?>
<p class="filtros-extra"><?php foreach ($extras as $k => $t): ?><span class="tag neutra"><?= e($t) ?> <a href="vendas_lista.php?<?= e($qs([$k => ''])) ?>" title="Tirar filtro">×</a></span> <?php endforeach; ?></p>
<?php endif; ?>
<p class="sub"><strong><?= number_format($total, 0, ',', '.') ?></strong> documento(s) · <?= (int)$res['nv'] ?> venda(s) · <?= brl($res['total']) ?><?= $res['nv'] ? ' · ticket ' . brl($res['total'] / $res['nv']) : '' ?><?= $res['bonif'] > 0 ? ' · bonificações ' . brl($res['bonif']) : '' ?></p>

<?php if ($nomesSem): ?>
<h2>Nomes do ERP sem vínculo <small>abra uma venda para vincular o nome a um cliente: vale para todas as vendas dele</small></h2>
<div class="cartao rolagem"><table>
  <thead><tr><th>Nome no ERP</th><th class="num">Documentos</th><th class="num">Valor</th><th></th></tr></thead>
  <tbody><?php foreach ($nomesSem as $n): ?><tr><td class="nome"><?= e($n['cliente_nome']) ?></td><td class="num"><?= (int)$n['n'] ?></td><td class="num"><?= brl($n['total']) ?></td><td><a class="btn peq sec" href="vendas_lista.php?ver=<?= (int)$n['id'] ?>">Vincular</a></td></tr><?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>

<div class="cartao rolagem"><table>
  <thead><tr><th>Data</th><th>Documento</th><th>Cliente</th><th>Vendedor</th><th>Canal</th><th class="num">Itens</th><th class="num">Valor</th><th class="num">Bonificação</th></tr></thead>
  <tbody>
  <?php foreach ($lista as $v): ?>
    <tr>
      <td><?= date('d/m/Y H:i', strtotime($v['data'])) ?></td>
      <td><a href="vendas_lista.php?ver=<?= (int)$v['id'] ?>"><?= e($v['documento']) ?></a></td>
      <td class="nome"><?php if ($v['cliente_id']): ?><a href="clientes.php?editar=<?= (int)$v['cliente_id'] ?>"><?= e($v['cli']) ?></a> <span class="tag neutra"><?= $v['tipo_pessoa'] === 'PJ' ? 'PJ' : 'PF' ?></span><?php elseif ($v['cliente_nome']): ?><?= e($v['cliente_nome']) ?> <span class="tag pend">sem vínculo</span><?php else: ?><span class="sub">balcão</span><?php endif; ?></td>
      <td><?= e($v['vendedor']) ?></td>
      <td><?= $v['canal'] === 'faturada' ? 'NF-e' : 'Loja' ?></td>
      <td class="num"><?= rtrim(rtrim(number_format($v['itens_qtd'], 3, ',', '.'), '0'), ',') ?></td>
      <td class="num"><?= brl($v['valor_total']) ?></td>
      <td class="num"><?= $v['valor_bonificacao'] > 0 ? brl($v['valor_bonificacao']) : '' ?></td>
    </tr>
  <?php endforeach; if (!$lista): ?><tr><td colspan="8" class="vazio">Nenhuma venda encontrada.</td></tr><?php endif; ?>
  </tbody>
</table></div>
<?php if ($total > $porPag): ?>
<p class="acoes">
  <?php if ($pag > 1): ?><a class="btn peq sec" href="vendas_lista.php?<?= e($qs(['pg' => $pag - 1])) ?>">‹ Anterior</a><?php endif; ?>
  <span class="sub" style="margin:0">Página <?= $pag ?> de <?= (int)ceil($total / $porPag) ?></span>
  <?php if ($pag * $porPag < $total): ?><a class="btn peq sec" href="vendas_lista.php?<?= e($qs(['pg' => $pag + 1])) ?>">Próxima ›</a><?php endif; ?>
</p>
<?php endif; ?>
<?php require __DIR__ . '/inc/rodape.php'; ?>
