<?php
require __DIR__ . '/inc/app.php';
exigir_login();
exigir_vendas();
vendas_exigir_migracao();
$pdo = db();
$custos = ver_custos();

// ---------- Período e recorte ----------
$per = vendas_periodo($_GET);
$rec = [
    'pessoa' => in_array($_GET['pessoa'] ?? '', ['PF', 'PJ', 'sem'], true) ? $_GET['pessoa'] : '',
    'canal' => in_array($_GET['canal'] ?? '', ['loja', 'faturada'], true) ? $_GET['canal'] : '',
    'vendedor' => mb_substr(texto_limpo($_GET['vendedor'] ?? ''), 0, 120),
    'categoria' => (int)($_GET['categoria'] ?? 0) ?: '',
];
$filtro = function ($ini, $fim) use ($rec) {
    $w = ['v.data >= ?', 'v.data <= ?']; $p = [$ini . ' 00:00:00', $fim . ' 23:59:59'];
    if ($rec['pessoa'] === 'sem') $w[] = 'v.cliente_id IS NULL';
    elseif ($rec['pessoa'] !== '') { $w[] = 'c.tipo_pessoa = ?'; $p[] = $rec['pessoa']; }
    if ($rec['canal'] !== '') { $w[] = 'v.canal = ?'; $p[] = $rec['canal']; }
    if ($rec['vendedor'] !== '') { $w[] = 'v.vendedor = ?'; $p[] = $rec['vendedor']; }
    if ($rec['categoria'] !== '') { $w[] = 'c.categoria_id = ?'; $p[] = (int)$rec['categoria']; }
    return [implode(' AND ', $w), $p];
};
$q = function ($sql, $par) use ($pdo) { $s = $pdo->prepare($sql); $s->execute($par); return $s; };
[$W, $P] = $filtro($per['ini'], $per['fim']);
$FROM = 'FROM vendas v LEFT JOIN clientes c ON c.id = v.cliente_id';
$qsPer = array_filter(['p' => $per['p'] === 'livre' ? '' : $per['p'], 'ini' => $per['p'] === 'livre' ? $per['ini'] : '', 'fim' => $per['p'] === 'livre' ? $per['fim'] : '']);
$link = function ($pagina, $extra = []) use ($qsPer, $rec) { return $pagina . '?' . http_build_query(array_filter(array_merge($qsPer, $rec, $extra), fn($v) => $v !== '' && $v !== null)); };
$fmt = fn($n) => number_format((float)$n, 0, ',', '.');
$pct = fn($n, $t) => $t ? round($n * 100 / $t) : 0;

// ---------- Vendas do período (uma consulta; o resto é somado aqui) ----------
$vendas = $q("SELECT v.id, v.documento, v.data, v.valor_total, v.valor_bonificacao, v.custo_bonificacao, v.desconto, v.itens_qtd, v.canal, v.vendedor, v.cliente_id, v.cliente_nome,
    c.nome, c.tipo_pessoa, c.categoria_id, c.sexo, c.data_nascimento $FROM WHERE $W ORDER BY v.data", $P)->fetchAll();
$tot = ['receita' => 0.0, 'n' => 0, 'bonif' => 0.0, 'bonif_custo' => 0.0, 'bonif_n' => 0, 'desconto' => 0.0, 'itens' => 0.0];
$porPessoa = ['PJ' => ['n' => 0, 'v' => 0.0, 'cli' => []], 'PF' => ['n' => 0, 'v' => 0.0, 'cli' => []], 'sem' => ['n' => 0, 'v' => 0.0, 'cli' => []]];
$porCanal = []; $porVend = []; $porDia = []; $porSemana = array_fill(0, 7, ['n' => 0, 'v' => 0.0]); $porHora = [];
$faixas = ['Até R$ 100' => [0, 100], 'R$ 100 a 200' => [100, 200], 'R$ 200 a 300' => [200, 300], 'R$ 300 a 500' => [300, 500], 'R$ 500 a 1.000' => [500, 1000], 'R$ 1.000 a 3.000' => [1000, 3000], 'Acima de R$ 3.000' => [3000, PHP_INT_MAX]];
$porFaixa = array_fill_keys(array_keys($faixas), ['n' => 0, 'v' => 0.0]);
$cliVend = []; $itensVend = []; $calor = [];
$porCli = []; $porCat = []; $bonifDest = []; $semVinculo = []; $valores = [];
foreach ($vendas as $v) {
    $val = (float)$v['valor_total'];
    if ((float)$v['valor_bonificacao'] > 0) {
        $tot['bonif'] += $v['valor_bonificacao']; $tot['bonif_custo'] += $v['custo_bonificacao']; $tot['bonif_n']++;
        $bd = $v['nome'] ?? $v['cliente_nome'] ?? 'Sem cliente';
        $bonifDest[$bd] = ($bonifDest[$bd] ?? 0) + $v['valor_bonificacao'];
    }
    if ($val <= 0) continue;
    $tot['receita'] += $val; $tot['n']++; $tot['desconto'] += $v['desconto']; $tot['itens'] += $v['itens_qtd']; $valores[] = $val;
    $pk = $v['cliente_id'] ? ($v['tipo_pessoa'] === 'PJ' ? 'PJ' : 'PF') : 'sem';
    $vd0 = $v['vendedor'] ?: 'Não informado'; if ($v['cliente_id']) $cliVend[$vd0][$v['cliente_id']] = true; $itensVend[$vd0] = ($itensVend[$vd0] ?? 0) + $v['itens_qtd'];
    $ds0 = (int)date('w', strtotime(substr($v['data'], 0, 10))); $h0 = (int)substr($v['data'], 11, 2);
    $calor[$ds0][$h0]['n'] = ($calor[$ds0][$h0]['n'] ?? 0) + 1; $calor[$ds0][$h0]['v'] = ($calor[$ds0][$h0]['v'] ?? 0) + $val;
    $porPessoa[$pk]['n']++; $porPessoa[$pk]['v'] += $val; if ($v['cliente_id']) $porPessoa[$pk]['cli'][$v['cliente_id']] = true;
    $porCanal[$v['canal']]['n'] = ($porCanal[$v['canal']]['n'] ?? 0) + 1; $porCanal[$v['canal']]['v'] = ($porCanal[$v['canal']]['v'] ?? 0) + $val;
    $vd = $v['vendedor'] ?: 'Não informado';
    $porVend[$vd]['n'] = ($porVend[$vd]['n'] ?? 0) + 1; $porVend[$vd]['v'] = ($porVend[$vd]['v'] ?? 0) + $val;
    $dia = substr($v['data'], 0, 10);
    $porDia[$dia]['n'] = ($porDia[$dia]['n'] ?? 0) + 1; $porDia[$dia]['v'] = ($porDia[$dia]['v'] ?? 0) + $val;
    $ds = (int)date('w', strtotime($dia)); $porSemana[$ds]['n']++; $porSemana[$ds]['v'] += $val;
    $h = (int)substr($v['data'], 11, 2); $porHora[$h]['n'] = ($porHora[$h]['n'] ?? 0) + 1; $porHora[$h]['v'] = ($porHora[$h]['v'] ?? 0) + $val;
    foreach ($faixas as $fx => [$a, $b]) if ($val > $a && $val <= $b || ($a === 0 && $val <= $b)) { $porFaixa[$fx]['n']++; $porFaixa[$fx]['v'] += $val; break; }
    if ($v['cliente_id']) {
        $id = (int)$v['cliente_id'];
        $porCli[$id] = $porCli[$id] ?? ['nome' => $v['nome'], 'tipo' => $v['tipo_pessoa'], 'cat' => $v['categoria_id'], 'sexo' => $v['sexo'], 'nasc' => $v['data_nascimento'], 'n' => 0, 'v' => 0.0, 'ultima' => ''];
        $porCli[$id]['n']++; $porCli[$id]['v'] += $val; $porCli[$id]['ultima'] = max($porCli[$id]['ultima'], $v['data']);
        $ck = $v['categoria_id'] ?: 0; $porCat[$ck] = ($porCat[$ck] ?? 0) + $val;
    } elseif ($v['cliente_nome']) {
        $semVinculo[$v['cliente_nome']] = ($semVinculo[$v['cliente_nome']] ?? 0) + $val;
    }
}
sort($valores);
$nV = count($valores);
$mediana = $nV ? ($nV % 2 ? $valores[intdiv($nV, 2)] : ($valores[$nV / 2 - 1] + $valores[$nV / 2]) / 2) : 0;
$ticket = $tot['n'] ? $tot['receita'] / $tot['n'] : 0;
$idReceita = $porPessoa['PF']['v'] + $porPessoa['PJ']['v'];
$nClientes = count($porCli);

// Período anterior, para comparar.
[$Wa, $Pa] = $filtro($per['ant_ini'], $per['ant_fim']);
$ant = $q("SELECT COUNT(*) n, COALESCE(SUM(v.valor_total), 0) receita $FROM WHERE $Wa AND v.valor_total > 0", $Pa)->fetch();
$antTicket = $ant['n'] ? $ant['receita'] / $ant['n'] : 0;

// Produtos e tipos (só itens vendidos; bonificações à parte).
$prods = $q("SELECT i.produto_id, MAX(COALESCE(p.descricao, i.descricao)) descricao, MAX(p.tipo) tipo, SUM(i.quantidade) qtd, SUM(i.liquido) receita,
    SUM(CASE WHEN i.custo_total > 0 THEN i.liquido ELSE 0 END) rec_custo, SUM(CASE WHEN i.custo_total > 0 THEN i.lucro ELSE 0 END) lucro_custo,
    COUNT(DISTINCT v.id) nvendas, COUNT(DISTINCT v.cliente_id) nclientes
    FROM venda_itens i JOIN vendas v ON v.id = i.venda_id LEFT JOIN clientes c ON c.id = v.cliente_id LEFT JOIN produtos p ON p.id = i.produto_id
    WHERE $W AND i.bonificacao = 0 GROUP BY i.produto_id ORDER BY receita DESC", $P)->fetchAll();
$porTipo = []; $garrafas = 0; $recCusto = 0.0; $lucroCusto = 0.0; $abaixo = [];
foreach ($prods as $p) {
    $t = $p['tipo'] ?: 'outro';
    $porTipo[$t]['v'] = ($porTipo[$t]['v'] ?? 0) + $p['receita']; $porTipo[$t]['q'] = ($porTipo[$t]['q'] ?? 0) + $p['qtd'];
    if (in_array($t, tipos_garrafa(), true)) $garrafas += $p['qtd'];
    $recCusto += $p['rec_custo']; $lucroCusto += $p['lucro_custo'];
    if ($p['rec_custo'] > 0 && $p['lucro_custo'] < 0) $abaixo[] = $p;
}
arsort($porTipo);
uasort($porTipo, fn($a, $b) => $b['v'] <=> $a['v']);
$margem = $recCusto > 0 ? $lucroCusto * 100 / $recCusto : null;
$semCusto = $tot['receita'] - $recCusto;

// Evolução: 12 meses até o fim do período.
$ini12 = date('Y-m-01', strtotime(substr($per['fim'], 0, 7) . '-01 -11 months'));
[$We, $Pe] = $filtro($ini12, $per['fim']);
$meses = [];
for ($i = 11; $i >= 0; $i--) $meses[date('Y-m', strtotime(substr($per['fim'], 0, 7) . "-01 -$i months"))] = ['n' => 0, 'v' => 0.0];
foreach ($q("SELECT SUBSTR(v.data, 1, 7) m, COUNT(*) n, SUM(v.valor_total) v $FROM WHERE $We AND v.valor_total > 0 GROUP BY SUBSTR(v.data, 1, 7)", $Pe) as $r)
    if (isset($meses[$r['m']])) $meses[$r['m']] = ['n' => (int)$r['n'], 'v' => (float)$r['v']];

// Clientes novos x recorrentes: primeira compra dentro ou antes do período.
$novos = ['n' => 0, 'v' => 0.0]; $recor = ['n' => 0, 'v' => 0.0];
if ($porCli) {
    $prim = [];
    foreach (array_chunk(array_keys($porCli), 400) as $l)
        foreach ($pdo->query('SELECT cliente_id, MIN(data) FROM vendas WHERE valor_total > 0 AND cliente_id IN (' . implode(',', $l) . ') GROUP BY cliente_id')->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $d) $prim[$k] = $d;
    foreach ($porCli as $id => $c) { if (($prim[$id] ?? '') >= $per['ini']) { $novos['n']++; $novos['v'] += $c['v']; } else { $recor['n']++; $recor['v'] += $c['v']; } }
}
// Perfil das pessoas físicas que compraram.
$pfSexo = ['F' => 0, 'M' => 0, '' => 0]; $pfIdade = array_fill_keys(array_keys(faixas_etarias()), 0); $hoje = new DateTime('today');
foreach ($porCli as $c) if ($c['tipo'] !== 'PJ') {
    $pfSexo[(string)$c['sexo']]++;
    $pfIdade[$c['nasc'] ? faixa_da_idade((new DateTime($c['nasc']))->diff($hoje)->y) : 'sem']++;
}
uasort($porCli, fn($a, $b) => $b['v'] <=> $a['v']);
arsort($semVinculo); arsort($bonifDest); arsort($porCat);
uasort($porVend, fn($a, $b) => $b['v'] <=> $a['v']);

// Frase-resumo.
$resumo = [];
if ($tot['n']) {
    $resumo[] = $fmt($tot['n']) . ' vendas somando ' . brl($tot['receita']) . ', ticket médio de ' . brl($ticket);
    if ($idReceita) $resumo[] = $pct($porPessoa['PJ']['v'], $tot['receita']) . '% da receita veio de empresas';
    if ($porPessoa['sem']['n']) $resumo[] = $pct($porPessoa['sem']['n'], $tot['n']) . '% das vendas foram sem cliente identificado';
    if ($prods) $resumo[] = 'o mais vendido foi ' . $prods[0]['descricao'];
}
// Destaques e recorrência.
$maiores = array_values(array_filter($vendas, fn($v) => (float)$v['valor_total'] > 0));
usort($maiores, fn($a, $b) => (float)$b['valor_total'] <=> (float)$a['valor_total']);
$maiores = array_slice($maiores, 0, 10);
$picoDia = null; foreach ($porDia as $d => $x) if (!$picoDia || $x['v'] > $porDia[$picoDia]['v']) $picoDia = $d;
// Clientes recorrentes: compraram 2 vezes ou mais (todo o histórico importado até o fim do período).
$recorrentes = [];
if ($porCli) {
    foreach (array_chunk(array_keys($porCli), 400) as $l) {
        $s = $pdo->prepare('SELECT cliente_id, COUNT(*) n, SUM(valor_total) total, MIN(data) primeira, MAX(data) ultima FROM vendas WHERE valor_total > 0 AND data <= ? AND cliente_id IN (' . implode(',', $l) . ') GROUP BY cliente_id HAVING COUNT(*) >= 2');
        $s->execute([$per['fim'] . ' 23:59:59']);
        foreach ($s as $r) {
            $dias = (int)(new DateTime(substr($r['primeira'], 0, 10)))->diff(new DateTime(substr($r['ultima'], 0, 10)))->days;
            $recorrentes[(int)$r['cliente_id']] = $r + ['intervalo' => $r['n'] > 1 ? $dias / ($r['n'] - 1) : null, 'no_periodo' => $porCli[(int)$r['cliente_id']]['n']];
        }
    }
    uasort($recorrentes, fn($a, $b) => [(int)$b['n'], (float)$b['total']] <=> [(int)$a['n'], (float)$a['total']]);
}
$horasCalor = []; foreach ($calor as $hs) foreach (array_keys($hs) as $hr) $horasCalor[$hr] = true; ksort($horasCalor); $horasCalor = array_keys($horasCalor);
if ($horasCalor) $horasCalor = range(min($horasCalor), max($horasCalor));
// Série de dias completa (dias sem venda entram com zero); períodos longos viram meses.
$serieDias = [];
if ((new DateTime($per['ini']))->diff(new DateTime($per['fim']))->days <= 62) {
    $fimSerie = min($per['fim'], date('Y-m-d'));
    $ultimoDado = $porDia ? max(array_keys($porDia)) : $per['ini'];
    if ($fimSerie < $ultimoDado) $fimSerie = $ultimoDado;
    for ($d = $per['ini']; $d <= $fimSerie; $d = date('Y-m-d', strtotime($d . ' +1 day'))) $serieDias[$d] = $porDia[$d] ?? ['n' => 0, 'v' => 0];
} else { foreach ($porDia as $d => $x) { $m = substr($d, 0, 7); $serieDias[$m]['n'] = ($serieDias[$m]['n'] ?? 0) + $x['n']; $serieDias[$m]['v'] = ($serieDias[$m]['v'] ?? 0) + $x['v']; } ksort($serieDias); }
$diasComVenda = count($porDia);
$prodsQtd = $prods; usort($prodsQtd, fn($a, $b) => (float)$b['qtd'] <=> (float)$a['qtd']);
$margemTxt = fn($p) => ver_custos() ? ($p['rec_custo'] > 0 ? ' · margem ' . number_format($p['lucro_custo'] * 100 / $p['rec_custo'], 1, ',', '.') . '%' : ' · sem custo') : '';

$vendedoresLista = $pdo->query("SELECT DISTINCT vendedor FROM vendas WHERE vendedor IS NOT NULL AND vendedor <> '' ORDER BY vendedor")->fetchAll(PDO::FETCH_COLUMN);
$titulo = 'Painel de vendas';
require __DIR__ . '/inc/topo.php';
if (!$pdo->query('SELECT 1 FROM vendas LIMIT 1')->fetchColumn()): ?>
<div class="cartao"><p>Nenhuma venda importada ainda.<?php if (pode_importar_vendas()): ?> <a href="vendas_importar.php">Importe o relatório de vendas do ERP</a> para ver o painel.<?php endif; ?></p></div>
<?php require __DIR__ . '/inc/rodape.php'; exit; endif; ?>
<form method="get" class="filtro">
  <?= vendas_seletor_periodo($per) ?>
  <select name="pessoa" style="width:auto"><option value="">Todos os compradores</option><option value="PJ" <?= $rec['pessoa'] === 'PJ' ? 'selected' : '' ?>>Empresas (PJ)</option><option value="PF" <?= $rec['pessoa'] === 'PF' ? 'selected' : '' ?>>Pessoas físicas</option><option value="sem" <?= $rec['pessoa'] === 'sem' ? 'selected' : '' ?>>Sem cliente identificado</option></select>
  <select name="canal" style="width:auto"><option value="">Todos os canais</option><?php foreach (['loja', 'faturada'] as $k): ?><option value="<?= $k ?>" <?= $rec['canal'] === $k ? 'selected' : '' ?>><?= e(nome_canal($k)) ?></option><?php endforeach; ?></select>
  <select name="vendedor" style="width:auto"><option value="">Todo vendedor</option><?php foreach ($vendedoresLista as $vd): ?><option <?= $rec['vendedor'] === $vd ? 'selected' : '' ?>><?= e($vd) ?></option><?php endforeach; ?></select>
  <select name="categoria" style="width:auto"><option value="">Toda categoria</option><?php foreach (categorias_todas() as $k): ?><option value="<?= (int)$k['id'] ?>" <?= (string)$rec['categoria'] === (string)$k['id'] ? 'selected' : '' ?>><?= e($k['nome']) ?></option><?php endforeach; ?></select>
  <button>Aplicar</button><?php if (array_filter($rec)): ?><a class="btn sec" href="vendas_painel.php?<?= e(http_build_query($qsPer)) ?>">Limpar recorte</a><?php endif; ?>
</form>
<p class="sub"><?= e($per['rot']) ?> · comparação com <?= data_br($per['ant_ini']) ?> a <?= data_br($per['ant_fim']) ?></p>
<?php if ($resumo): ?><p class="resumo-painel"><?= e(ucfirst(implode('; ', $resumo))) ?>.</p><?php endif; ?>

<div class="cards">
  <a class="card" href="<?= e($link('vendas_lista.php')) ?>"><span>Faturamento</span><strong><?= brl($tot['receita']) ?></strong><small><?= variacao($tot['receita'], $ant['receita']) ?> sem bonificações</small></a>
  <a class="card" href="<?= e($link('vendas_lista.php')) ?>"><span>Vendas</span><strong><?= $fmt($tot['n']) ?></strong><small><?= variacao($tot['n'], $ant['n']) ?> em <?= $fmt($diasComVenda) ?> dias com venda</small></a>
  <div class="card"><span>Ticket médio</span><strong><?= brl($ticket) ?></strong><small><?= variacao($ticket, $antTicket) ?> mediana <?= brl($mediana) ?></small></div>
  <div class="card"><span>Garrafas de vinho</span><strong><?= $fmt($garrafas) ?></strong><small><?= $fmt($tot['itens']) ?> itens no total</small></div>
  <a class="card" href="<?= e($link('vendas_lista.php', ['vinculo' => 'com'])) ?>"><span>Clientes identificados</span><strong><?= $fmt($nClientes) ?></strong><small><?= $pct($idReceita, $tot['receita']) ?>% da receita · <?= $pct($tot['n'] - $porPessoa['sem']['n'], $tot['n']) ?>% das vendas</small></a>
  <?php if ($custos): ?><div class="card"><span>Margem bruta</span><strong><?= $margem === null ? '—' : number_format($margem, 1, ',', '.') . '%' ?></strong><small>lucro <?= brl($lucroCusto) ?> · só itens com custo</small></div><?php endif; ?>
  <div class="card alerta"><span>Bonificações e amostras</span><strong><?= brl($tot['bonif']) ?></strong><small><?= $tot['bonif_n'] ?> documento(s)<?= $custos ? ' · custo ' . brl($tot['bonif_custo']) : '' ?></small></div>
</div>

<?php if ($tot['n']): $mv = $maiores[0] ?? null; $pv = reset($porVend); $kv = key($porVend); $pc = reset($porCli); $kc = key($porCli); ?>
<h2>Destaques do período</h2>
<div class="destaques">
  <?php if ($mv): ?><a class="destaque" href="vendas_lista.php?ver=<?= (int)$mv['id'] ?>"><span>Maior venda</span><strong><?= brl($mv['valor_total']) ?></strong><small><?= e($mv['nome'] ?? $mv['cliente_nome'] ?? 'Balcão') ?> · <?= data_br($mv['data']) ?> · <?= e($mv['documento']) ?></small></a><?php endif; ?>
  <?php if ($picoDia): ?><a class="destaque" href="<?= e($link('vendas_lista.php', ['ini' => $picoDia, 'fim' => $picoDia, 'p' => ''])) ?>"><span>Pico de vendas</span><strong><?= brl($porDia[$picoDia]['v']) ?></strong><small><?= data_br($picoDia) ?> (<?= e(dia_semana($picoDia)) ?>) · <?= $porDia[$picoDia]['n'] ?> venda(s)</small></a><?php endif; ?>
  <?php if ($prods): ?><a class="destaque" href="vendas_produtos.php?<?= e(http_build_query(array_merge($qsPer, ['ver' => $prods[0]['produto_id']]))) ?>"><span>Produto campeão</span><strong><?= e($prods[0]['descricao']) ?></strong><small><?= brl($prods[0]['receita']) ?> · <?= $fmt($prods[0]['qtd']) ?> un. · <?= $pct($prods[0]['receita'], $tot['receita']) ?>% da receita</small></a><?php endif; ?>
  <?php if ($pv): ?><a class="destaque" href="<?= e($link('vendas_painel.php', ['vendedor' => $kv])) ?>"><span>Vendedor destaque</span><strong><?= e($kv) ?></strong><small><?= brl($pv['v']) ?> · <?= $pv['n'] ?> vendas · ticket <?= brl($pv['v'] / $pv['n']) ?></small></a><?php endif; ?>
  <?php if ($pc): ?><a class="destaque" href="clientes.php?editar=<?= (int)$kc ?>"><span>Maior cliente</span><strong><?= e($pc['nome']) ?></strong><small><?= brl($pc['v']) ?> em <?= $pc['n'] ?> compra(s)</small></a><?php endif; ?>
</div>
<?php endif; ?>

<div class="painel-grade">
  <section class="cartao quadro largo">
    <h2><?= count($serieDias) && strlen(array_key_first($serieDias)) === 7 ? 'Faturamento por mês' : 'Faturamento por dia' ?> <small><?= e($per['rot']) ?> · passe o mouse sobre o gráfico para ver cada dia; clique para abrir as vendas</small></h2>
    <?= grafico_linha_dias($serieDias, fn($d) => strlen($d) === 10 ? $link('vendas_lista.php', ['ini' => $d, 'fim' => $d, 'p' => '']) : $link('vendas_painel.php', ['p' => $d])) ?>
    <h2 class="sub-graf">Quantidade de vendas</h2>
    <?= grafico_colunas_dias($serieDias) ?>
  </section>

  <section class="cartao quadro">
    <h2>Produtos mais vendidos <small>por receita · <a href="<?= e('vendas_produtos.php?' . http_build_query($qsPer)) ?>">ver todos</a></small></h2>
    <?= ranking(array_map(fn($p) => [$p['descricao'], (float)$p['receita'], $p['produto_id'] ? 'vendas_produtos.php?' . http_build_query(array_merge($qsPer, ['ver' => $p['produto_id']])) : null,
        $fmt($p['qtd']) . ' un. · ' . $pct($p['receita'], $tot['receita']) . '% · preço médio ' . brl($p['qtd'] > 0 ? $p['receita'] / $p['qtd'] : 0) . $margemTxt($p)], $prods)) ?>
  </section>
  <section class="cartao quadro">
    <h2>Produtos mais vendidos <small>por quantidade</small></h2>
    <?= ranking(array_map(fn($p) => [$p['descricao'], (float)$p['qtd'], $p['produto_id'] ? 'vendas_produtos.php?' . http_build_query(array_merge($qsPer, ['ver' => $p['produto_id']])) : null,
        brl($p['receita']) . ' · ' . $fmt($p['nvendas']) . ' vendas · ' . $fmt($p['nclientes']) . ' clientes', $fmt($p['qtd']) . ' un.'], $prodsQtd), false) ?>
  </section>

  <section class="cartao quadro">
    <h2>Melhores vendedores <small>por receita no período</small></h2>
    <?= ranking(array_map(fn($k, $x) => [$k, $x['v'], $k !== 'Não informado' ? $link('vendas_painel.php', ['vendedor' => $k]) : null,
        $x['n'] . ' vendas · ticket ' . brl($x['v'] / $x['n']) . ' · ' . count($cliVend[$k] ?? []) . ' clientes · ' . $fmt($itensVend[$k] ?? 0) . ' itens'], array_keys($porVend), $porVend)) ?>
  </section>
  <section class="cartao quadro">
    <h2>Quem compra <small>receita por tipo de comprador</small></h2>
    <?= barra_composicao([['Empresas (PJ)', $porPessoa['PJ']['v'], 'c-pj', $link('vendas_painel.php', ['pessoa' => 'PJ'])], ['Pessoas físicas', $porPessoa['PF']['v'], 'c-pf', $link('vendas_painel.php', ['pessoa' => 'PF'])], ['Balcão (sem cliente)', $porPessoa['sem']['v'], 'c-sem', $link('vendas_painel.php', ['pessoa' => 'sem'])]]) ?>
    <table class="mini"><thead><tr><th></th><th class="num">Vendas</th><th class="num">Clientes</th><th class="num">Ticket</th></tr></thead><tbody>
      <?php foreach (['PJ' => 'Empresas', 'PF' => 'Pessoas físicas', 'sem' => 'Balcão'] as $k => $rot): $x = $porPessoa[$k]; ?>
        <tr><td><?= $rot ?></td><td class="num"><?= $fmt($x['n']) ?></td><td class="num"><?= $k === 'sem' ? '—' : $fmt(count($x['cli'])) ?></td><td class="num"><?= brl($x['n'] ? $x['v'] / $x['n'] : 0) ?></td></tr>
      <?php endforeach; ?>
    </tbody></table>
    <h2 style="margin-top:14px">Canal</h2>
    <?= painel_barras(array_map(fn($k) => [nome_canal($k), $porCanal[$k]['v'] ?? 0, $link('vendas_painel.php', ['canal' => $k]), $fmt($porCanal[$k]['n'] ?? 0) . ' vendas · ticket ' . brl(($porCanal[$k]['n'] ?? 0) ? $porCanal[$k]['v'] / $porCanal[$k]['n'] : 0)], ['faturada', 'loja']), true, $tot['receita']) ?>
  </section>

  <section class="cartao quadro">
    <h2>Melhores clientes <small><?= $fmt($nClientes) ?> identificados no período</small></h2>
    <?= ranking(array_map(fn($id, $c) => [$c['nome'], $c['v'], 'clientes.php?editar=' . $id, ($c['tipo'] === 'PJ' ? 'Empresa' : 'Pessoa') . ($c['cat'] ? ' · ' . nome_categoria($c['cat']) : '') . ' · ' . $c['n'] . ' compra(s) · ticket ' . brl($c['v'] / $c['n'])], array_keys($porCli), $porCli)) ?>
    <?php if ($semVinculo): ?><p class="nota"><a href="<?= e($link('vendas_lista.php', ['vinculo' => 'nome'])) ?>"><?= count($semVinculo) ?> nome(s) do ERP sem vínculo com o cadastro</a> (<?= brl(array_sum($semVinculo)) ?>).</p><?php endif; ?>
  </section>
  <section class="cartao quadro">
    <h2>Clientes recorrentes <small>2 compras ou mais · histórico até <?= data_br($per['fim']) ?></small></h2>
    <?php if ($recorrentes): ?>
    <?= ranking(array_map(fn($id, $r) => [$porCli[$id]['nome'], (int)$r['n'], 'clientes.php?editar=' . $id,
        brl($r['total']) . ' no total · ticket ' . brl($r['total'] / $r['n']) . ($r['intervalo'] !== null ? ($r['intervalo'] < 1 ? ' · compras no mesmo dia' : ' · volta a cada ' . $fmt($r['intervalo']) . ' dia(s)') : '') . ' · última ' . data_br($r['ultima']), $r['n'] . ' compras'], array_keys($recorrentes), $recorrentes), false) ?>
    <?php else: ?><p class="sub">Nenhum cliente com 2 compras ou mais ainda.</p><?php endif; ?>
    <p class="nota"><?= count($recorrentes) ?> de <?= $fmt($nClientes) ?> clientes do período já compraram mais de uma vez. A recorrência fica mais clara a cada mês importado.</p>
  </section>

  <section class="cartao quadro">
    <h2>Maiores vendas <small>as 10 de maior valor</small></h2>
    <div class="rolagem"><table class="mini">
      <thead><tr><th>#</th><th>Cliente</th><th class="num">Valor</th></tr></thead>
      <tbody><?php foreach ($maiores as $i => $mv): ?><tr><td><?= $i + 1 ?></td><td class="nome"><a href="vendas_lista.php?ver=<?= (int)$mv['id'] ?>"><?= e($mv['nome'] ?? $mv['cliente_nome'] ?? 'Balcão') ?></a><br><span class="det-rk"><?= data_br($mv['data']) ?> · <?= e($mv['documento']) ?> · <?= e($mv['vendedor'] ?: '—') ?></span></td><td class="num"><strong><?= brl($mv['valor_total']) ?></strong></td></tr><?php endforeach; ?></tbody>
    </table></div>
  </section>
  <section class="cartao quadro">
    <h2>Quando vende <small>dia da semana × horário</small></h2>
    <?= mapa_calor($calor, $horasCalor) ?>
  </section>

  <section class="cartao quadro">
    <h2>Tipo de produto <small>receita</small></h2>
    <?= painel_barras(array_map(fn($t, $x) => [nome_tipo_produto($t), $x['v'], 'vendas_produtos.php?' . http_build_query(array_merge($qsPer, ['tipo' => $t])), $fmt($x['q']) . ' unidades'], array_keys($porTipo), $porTipo), true, $tot['receita']) ?>
  </section>
  <section class="cartao quadro">
    <h2>Categoria do cliente <small>receita dos identificados</small></h2>
    <?= painel_barras(array_map(fn($k, $v) => [$k ? nome_categoria($k) : 'Sem categoria', $v, $k ? $link('vendas_painel.php', ['categoria' => $k]) : null], array_keys($porCat), $porCat), true, $idReceita) ?>
  </section>
  <section class="cartao quadro">
    <h2>Novos × recorrentes <small>clientes identificados</small></h2>
    <?= painel_barras([['Primeira compra no período', $novos['v'], null, $novos['n'] . ' clientes'], ['Já tinham comprado antes', $recor['v'], null, $recor['n'] . ' clientes']], true, $idReceita) ?>
    <p class="nota"><?= $novos['n'] ?> novo(s) e <?= $recor['n'] ?> que já compravam antes do período.</p>
  </section>
  <section class="cartao quadro">
    <h2>Pessoas físicas que compraram <small><?= $fmt(count($porPessoa['PF']['cli'])) ?> pessoas</small></h2>
    <?= painel_barras([['Mulheres', $pfSexo['F']], ['Homens', $pfSexo['M']], ['Não informado', $pfSexo['']]]) ?>
    <?php $fx = []; foreach (faixas_etarias() as $k => $fe) if ($pfIdade[$k]) $fx[] = [$fe['rot'], $pfIdade[$k]]; ?>
    <?php if ($fx): ?><h2 style="margin-top:12px">Faixa etária</h2><?= painel_barras($fx) ?><?php endif; ?>
  </section>
  <section class="cartao quadro">
    <h2>Valor das vendas <small>quantas vendas em cada faixa</small></h2>
    <?= painel_barras(array_map(fn($k, $x) => [$k, $x['n'], null, brl($x['v'])], array_keys($porFaixa), $porFaixa)) ?>
  </section>
  <section class="cartao quadro">
    <h2>Bonificações e amostras <small>fora do faturamento · a preço de venda</small></h2>
    <?= painel_barras(array_map(fn($k, $v) => [$k, $v], array_slice(array_keys($bonifDest), 0, 8), array_slice($bonifDest, 0, 8)), true, $tot['bonif']) ?>
    <p class="nota">CFOP de bonificação, brinde, amostra ou remessa.<?= $custos ? ' Custo das mercadorias: ' . brl($tot['bonif_custo']) . '.' : '' ?></p>
  </section>
  <?php if ($custos): ?>
  <section class="cartao quadro">
    <h2>Custo e margem <small>visível só para o Administrador</small></h2>
    <ul class="lista-qualidade">
      <li>Margem bruta sobre itens com custo: <strong><?= $margem === null ? '—' : number_format($margem, 1, ',', '.') . '%' ?></strong></li>
      <li><strong><?= brl($semCusto) ?></strong> em itens sem custo no ERP (<?= $pct($semCusto, $tot['receita']) ?>% da receita) não entram na margem</li>
      <li>Descontos concedidos: <strong><?= brl($tot['desconto']) ?></strong></li>
      <?php foreach ($abaixo as $p): ?><li class="alerta-txt"><?= e($p['descricao']) ?>: vendido abaixo do custo (<?= brl($p['lucro_custo']) ?>)</li><?php endforeach; ?>
    </ul>
  </section>
  <?php endif; ?>

  <section class="cartao quadro largo">
    <h2>Faturamento por mês <small>12 meses até <?= e(rotulo_mes(substr($per['fim'], 0, 7))) ?> · mesmo recorte</small></h2>
    <?php $maxM = max(1, ...array_values(array_map(fn($x) => $x['v'], $meses))); $nomesM = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez']; ?>
    <div class="colunas">
      <?php foreach ($meses as $m => $x): ?>
        <a class="coluna" href="<?= e($link('vendas_painel.php', ['p' => $m, 'ini' => '', 'fim' => ''])) ?>" title="<?= e(rotulo_mes($m) . ': ' . $x['n'] . ' vendas, ' . brl($x['v']) . ($x['n'] ? ', ticket ' . brl($x['v'] / $x['n']) : '')) ?>">
          <span class="v"><?= $x['v'] ? e(moeda_curta($x['v'])) : '' ?></span>
          <span class="b" style="height:<?= $x['v'] ? max(2, $x['v'] * 100 / $maxM) : 0 ?>%"></span>
          <span class="m"><?= $nomesM[(int)substr($m, 5) - 1] ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </section>
</div>
<p class="sub">Faturamento = valor líquido dos itens vendidos (com descontos e acréscimos), sem bonificações e amostras. Ticket médio = faturamento ÷ número de vendas. Os filtros acima recortam todos os quadros.</p>
<?php require __DIR__ . '/inc/rodape.php'; ?>
