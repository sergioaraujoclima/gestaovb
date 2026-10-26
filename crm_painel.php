<?php
require __DIR__ . '/inc/app.php';
exigir_login();
exigir_crm();
crm_exigir_migracao();
grupos_recalcular_se_velho();
$pdo = db();

// ---------- Recorte do painel (vale para todos os quadros) ----------
$rec = [
    'tipo' => in_array($_GET['tipo'] ?? '', ['PF', 'PJ'], true) ? $_GET['tipo'] : '',
    'categoria' => (int)($_GET['categoria'] ?? 0) ?: '',
    'origem' => isset(lista_origens()[$_GET['origem'] ?? '']) ? $_GET['origem'] : '',
    'uf' => preg_match('/^[A-Z]{2}$/', $_GET['uf'] ?? '') ? $_GET['uf'] : '',
    'clube' => in_array($_GET['clube'] ?? '', ['1', '0'], true) ? $_GET['clube'] : '',
];
$w = ['1 = 1']; $p = [];
foreach (['tipo' => 'c.tipo_pessoa', 'categoria' => 'c.categoria_id', 'origem' => 'c.origem', 'uf' => 'c.uf', 'clube' => 'c.clube_beneficios'] as $k => $col)
    if ($rec[$k] !== '') { $w[] = "$col = ?"; $p[] = $rec[$k]; }
$W = implode(' AND ', $w);
$q = function ($sql, $extra = []) use ($pdo, $p) { $s = $pdo->prepare($sql); $s->execute(array_merge($p, $extra)); return $s; };
// Link para a lista de clientes com o recorte atual + o filtro do item clicado.
$link = function ($extra) use ($rec) { return 'clientes.php?' . http_build_query(array_filter(array_merge($rec, $extra), fn($v) => $v !== '' && $v !== null)); };
$pct = fn($n, $t) => $t ? round($n * 100 / $t) : 0;
$fmt = fn($n) => number_format((float)$n, 0, ',', '.');

$CEL = "c.telefone_e164 LIKE '+55__9%' AND LENGTH(c.telefone_e164) = 14";
$tot = $q("SELECT COUNT(*) total, SUM(CASE WHEN c.tipo_pessoa = 'PF' THEN 1 ELSE 0 END) pf, SUM(CASE WHEN c.tipo_pessoa = 'PJ' THEN 1 ELSE 0 END) pj,
    SUM(CASE WHEN c.clube_beneficios = 1 THEN 1 ELSE 0 END) clube, SUM(CASE WHEN $CEL THEN 1 ELSE 0 END) celular,
    SUM(CASE WHEN c.email LIKE '%_@_%._%' THEN 1 ELSE 0 END) email, SUM(CASE WHEN c.aceita_whatsapp = 1 OR c.aceita_email = 1 THEN 1 ELSE 0 END) optin
    FROM clientes c WHERE $W")->fetch();
// Novo = cliente desde os últimos 30 dias; quem veio de carga sem data de cadastro não conta como novo.
$d30 = date('Y-m-d', strtotime('-30 days'));
$s = $pdo->prepare("SELECT COUNT(*) FROM clientes c WHERE $W AND (c.cliente_desde >= ? OR (c.cliente_desde IS NULL AND c.importacao_id IS NULL AND c.criado_em >= ?))"); $s->execute(array_merge($p, [$d30, $d30]));
$tot['novos'] = (int)$s->fetchColumn();
$total = (int)$tot['total'];

// Pessoas físicas: sexo e idade
$sexo = ['F' => 0, 'M' => 0, '' => 0]; $presumidos = 0;
foreach ($q("SELECT c.sexo, c.sexo_presumido, COUNT(*) n FROM clientes c WHERE $W AND c.tipo_pessoa = 'PF' GROUP BY c.sexo, c.sexo_presumido") as $r) { $sexo[(string)$r['sexo']] += $r['n']; if ($r['sexo_presumido']) $presumidos += $r['n']; }
$faixas = array_fill_keys(array_keys(faixas_etarias()), 0);
$hoje = new DateTime('today');
foreach ($q("SELECT c.data_nascimento FROM clientes c WHERE $W AND c.tipo_pessoa = 'PF'") as $r)
    $faixas[$r['data_nascimento'] ? faixa_da_idade((new DateTime($r['data_nascimento']))->diff($hoje)->y) : 'sem']++;
$mulheresPorFaixa = array_fill_keys(array_keys(faixas_etarias()), 0);
foreach ($q("SELECT c.data_nascimento FROM clientes c WHERE $W AND c.tipo_pessoa = 'PF' AND c.sexo = 'F' AND c.data_nascimento IS NOT NULL") as $r)
    $mulheresPorFaixa[faixa_da_idade((new DateTime($r['data_nascimento']))->diff($hoje)->y)]++;

// Onde estão
$ufs = $q("SELECT COALESCE(c.uf, '') uf, COUNT(*) n FROM clientes c WHERE $W GROUP BY c.uf ORDER BY n DESC")->fetchAll();
$cidades = $q("SELECT c.cidade, c.uf, COUNT(*) n FROM clientes c WHERE $W AND c.cidade IS NOT NULL AND c.cidade <> '' GROUP BY c.cidade, c.uf ORDER BY n DESC LIMIT 10")->fetchAll();
$bairros = $q("SELECT c.bairro, COUNT(*) n FROM clientes c WHERE $W AND c.uf = 'DF' AND c.bairro IS NOT NULL AND c.bairro <> '' GROUP BY c.bairro ORDER BY n DESC LIMIT 10")->fetchAll();

// Quem são e de onde vieram
$cats = $q("SELECT c.categoria_id, COUNT(*) n, SUM(c.categoria_auto) auto FROM clientes c WHERE $W GROUP BY c.categoria_id ORDER BY n DESC")->fetchAll();
$origens = $q("SELECT COALESCE(c.origem, '') origem, COUNT(*) n FROM clientes c WHERE $W GROUP BY c.origem ORDER BY n DESC")->fetchAll();

// Relação com a vinícola (tours)
$estagios = array_fill_keys(array_keys(grupos_estagios()), 0);
foreach ($q("SELECT COUNT(v.id) n, MAX(v.data_tour) ultimo FROM clientes c LEFT JOIN tour_vendas v ON v.cliente_id = c.id WHERE $W GROUP BY c.id") as $r) {
    $n = (int)$r['n'];
    $dias = $n ? (int)(new DateTime($r['ultimo']))->diff($hoje)->format('%r%a') : 0;
    $estagios[$n === 0 ? 'contato' : ($dias > 365 ? 'inativo' : ($n >= 2 ? 'recorrente' : 'cliente'))]++;
}
$ini12 = date('Y-m-01', strtotime('first day of -11 months'));
$meses = [];
for ($i = 11; $i >= 0; $i--) { $m = date('Y-m', strtotime("first day of -$i months")); $meses[$m] = ['tours' => 0, 'pessoas' => 0, 'valor' => 0.0]; }
$s = $pdo->prepare("SELECT SUBSTR(v.data_tour, 1, 7) m, COUNT(*) tours, SUM(v.qtd) pessoas, SUM(v.valor_pago) valor FROM tour_vendas v JOIN clientes c ON c.id = v.cliente_id
    WHERE $W AND v.data_tour >= ? AND v.data_tour <= ? GROUP BY SUBSTR(v.data_tour, 1, 7)");
$s->execute(array_merge($p, [$ini12, date('Y-m-t')]));
foreach ($s as $r) if (isset($meses[$r['m']])) $meses[$r['m']] = ['tours' => (int)$r['tours'], 'pessoas' => (int)$r['pessoas'], 'valor' => (float)$r['valor']];
$t12 = array_sum(array_column($meses, 'tours')); $v12 = array_sum(array_column($meses, 'valor')); $p12 = array_sum(array_column($meses, 'pessoas'));

// Próximos aniversariantes (30 dias)
$aniv = [];
foreach ($q("SELECT c.id, c.nome, c.data_nascimento, c.telefone FROM clientes c WHERE $W AND c.tipo_pessoa = 'PF' AND c.data_nascimento IS NOT NULL") as $r) {
    $d = new DateTime(date('Y') . substr($r['data_nascimento'], 4));
    if ($d < $hoje) $d->modify('+1 year');
    $dias = (int)$hoje->diff($d)->days;
    if ($dias <= 30) $aniv[] = $r + ['dias' => $dias, 'quando' => $d->format('d/m'), 'idade' => (int)(new DateTime($r['data_nascimento']))->diff($d)->y];
}
usort($aniv, fn($a, $b) => $a['dias'] <=> $b['dias']);

// Vendedores e qualidade do cadastro
$vendedores = $q("SELECT c.vendedor, COUNT(*) n FROM clientes c WHERE $W AND c.vendedor IS NOT NULL AND c.vendedor <> '' GROUP BY c.vendedor ORDER BY n DESC LIMIT 6")->fetchAll();
$qual = $q("SELECT SUM(CASE WHEN c.pendencias IS NOT NULL AND c.pendencias <> '' THEN 1 ELSE 0 END) pend, SUM(c.sexo_presumido) sexo_pres, SUM(c.categoria_auto) cat_auto,
    SUM(CASE WHEN c.telefone IS NULL AND c.telefone2 IS NULL AND c.email IS NULL THEN 1 ELSE 0 END) sem_contato,
    SUM(CASE WHEN c.aceita_whatsapp IS NULL AND c.aceita_email IS NULL THEN 1 ELSE 0 END) sem_consent FROM clientes c WHERE $W")->fetch();
$grupos = grupos_todos(true); $contG = grupos_contagens(); $totalBase = (int)$pdo->query('SELECT COUNT(*) FROM clientes')->fetchColumn();

// Frase de resumo: o que o painel diz em uma linha
$pfComSexo = $sexo['F'] + $sexo['M'];
$faixaTop = $faixas; unset($faixaTop['sem']); arsort($faixaTop); $faixaTopK = key($faixaTop);
$ufTop = $ufs[0] ?? null; if ($ufTop && $ufTop['uf'] === '' && isset($ufs[1])) $ufTop = $ufs[1];
$resumo = [];
if ($pfComSexo) $resumo[] = $pct($sexo['F'], $pfComSexo) . '% das pessoas físicas são mulheres';
if ($faixaTop && reset($faixaTop) > 0) $resumo[] = 'a faixa etária mais comum é ' . faixas_etarias()[$faixaTopK]['rot'] . ' anos';
if ($ufTop && $total) $resumo[] = $pct($ufTop['n'], $total) . '% estão em ' . ($ufTop['uf'] ?: 'UF não informada');
if ($tot['pj']) $resumo[] = $fmt($tot['pj']) . ' são empresas';

// Barra horizontal: rótulo, contagem, % do total do quadro e link para a lista filtrada.
function barras($itens, $base = null) {
    $max = max(1, ...array_map(fn($i) => $i[1], $itens ?: [[0, 0]]));
    $base = $base ?? array_sum(array_map(fn($i) => $i[1], $itens));
    $h = '<div class="barras">';
    foreach ($itens as $i) {
        [$rot, $n, $href] = $i + [2 => null];
        $pc = $base ? round($n * 100 / $base) : 0;
        $tag = $href ? 'a href="' . e($href) . '"' : 'div';
        $h .= '<' . $tag . ' class="barra" title="' . e($rot . ': ' . number_format($n, 0, ',', '.') . ' (' . $pc . '%)') . '"><span class="rot">' . e($rot) . '</span>'
            . '<span class="trilho"><span class="preench" style="width:' . max($n ? 1.5 : 0, $n * 100 / $max) . '%"></span></span>'
            . '<span class="val">' . number_format($n, 0, ',', '.') . ' <small>' . $pc . '%</small></span></' . ($href ? 'a' : 'div') . '>';
    }
    return $h . ($itens ? '' : '<p class="sub">Sem dados neste recorte.</p>') . '</div>';
}
$titulo = 'Painel de clientes';
require __DIR__ . '/inc/topo.php';
?>
<form method="get" class="filtro">
  <select name="tipo" style="width:auto"><option value="">PF e PJ</option><option value="PF" <?= $rec['tipo'] === 'PF' ? 'selected' : '' ?>>Pessoas físicas</option><option value="PJ" <?= $rec['tipo'] === 'PJ' ? 'selected' : '' ?>>Empresas</option></select>
  <select name="categoria" style="width:auto"><option value="">Toda categoria</option><?php foreach (categorias_todas() as $k): ?><option value="<?= (int)$k['id'] ?>" <?= (string)$rec['categoria'] === (string)$k['id'] ? 'selected' : '' ?>><?= e($k['nome']) ?></option><?php endforeach; ?></select>
  <select name="origem" style="width:auto"><option value="">Toda origem</option><?php foreach (lista_origens() as $k => $v): ?><option value="<?= $k ?>" <?= $rec['origem'] === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select>
  <select name="uf" style="width:auto"><option value="">Toda UF</option><?php foreach ($pdo->query("SELECT DISTINCT uf FROM clientes WHERE uf IS NOT NULL AND uf <> '' ORDER BY uf") as $u): ?><option <?= $rec['uf'] === $u['uf'] ? 'selected' : '' ?>><?= e($u['uf']) ?></option><?php endforeach; ?></select>
  <select name="clube" style="width:auto"><option value="">Clube: todos</option><option value="1" <?= $rec['clube'] === '1' ? 'selected' : '' ?>>Membros do clube</option><option value="0" <?= $rec['clube'] === '0' ? 'selected' : '' ?>>Fora do clube</option></select>
  <button>Aplicar</button><?php if (array_filter($rec)): ?><a class="btn sec" href="crm_painel.php">Limpar</a><?php endif; ?>
</form>

<?php if ($resumo): ?><p class="resumo-painel"><?= e(ucfirst(implode('; ', $resumo))) ?>.</p><?php endif; ?>

<div class="cards">
  <a class="card" href="<?= e($link([])) ?>"><span>Clientes</span><strong><?= $fmt($total) ?></strong><small><?= $fmt($tot['pf']) ?> pessoas · <?= $fmt($tot['pj']) ?> empresas</small></a>
  <a class="card" href="<?= e($link(['sexo' => 'F'])) ?>"><span>Mulheres</span><strong><?= $fmt($sexo['F']) ?></strong><small><?= $pct($sexo['F'], $pfComSexo) ?>% das pessoas com sexo informado</small></a>
  <a class="card" href="<?= e($link(['sexo' => 'M'])) ?>"><span>Homens</span><strong><?= $fmt($sexo['M']) ?></strong><small><?= $pct($sexo['M'], $pfComSexo) ?>% das pessoas com sexo informado</small></a>
  <div class="card"><span>Com celular (WhatsApp)</span><strong><?= $pct($tot['celular'], $total) ?>%</strong><small><?= $fmt($tot['celular']) ?> clientes</small></div>
  <div class="card alerta"><span>Aceitaram marketing</span><strong><?= $fmt($tot['optin']) ?></strong><small><?= $pct($tot['optin'], $total) ?>% · registre na ficha</small></div>
  <a class="card" href="<?= e($link(['clube' => '1'])) ?>"><span>Clube de benefícios</span><strong><?= $fmt($tot['clube']) ?></strong><small><?= $fmt($tot['novos']) ?> novos clientes em 30 dias</small></a>
</div>

<div class="painel-grade">
  <section class="cartao quadro">
    <h2>Sexo <small>pessoas físicas</small></h2>
    <?= barras([['Feminino', $sexo['F'], $link(['sexo' => 'F'])], ['Masculino', $sexo['M'], $link(['sexo' => 'M'])], ['Não informado', $sexo[''], $link(['sexo' => 'nao'])]]) ?>
    <p class="nota"><?= $fmt($presumidos) ?> presumidos pelo primeiro nome. <a href="<?= e($link(['sexo' => 'presumido'])) ?>">Conferir</a></p>
  </section>
  <section class="cartao quadro">
    <h2>Faixa etária <small>pessoas físicas</small></h2>
    <?php $fx = []; foreach (faixas_etarias() as $k => $fe) if ($k !== 'sem' && ($faixas[$k] || $k !== 'ate17')) $fx[] = [$fe['rot'], $faixas[$k], $link(['idade' => $k])]; ?>
    <?= barras($fx, array_sum(array_map(fn($i) => $i[1], $fx))) ?>
    <p class="nota"><a href="<?= e($link(['idade' => 'sem'])) ?>"><?= $fmt($faixas['sem']) ?> sem data de nascimento</a> não entram nas faixas.</p>
  </section>
  <section class="cartao quadro">
    <h2>Mulheres por faixa etária <small>público do Dia das Mães</small></h2>
    <?php $mx = []; foreach (faixas_etarias() as $k => $fe) if ($k !== 'sem' && ($mulheresPorFaixa[$k] || $k !== 'ate17')) $mx[] = [$fe['rot'], $mulheresPorFaixa[$k], $link(['sexo' => 'F', 'idade' => $k])]; ?>
    <?= barras($mx) ?>
  </section>
  <section class="cartao quadro">
    <h2>Categoria <small>quem é o cliente</small></h2>
    <?= barras(array_map(fn($r) => [$r['categoria_id'] ? nome_categoria($r['categoria_id']) : 'Sem categoria', (int)$r['n'], $link(['categoria' => $r['categoria_id'] ?: 'sem'])], $cats), $total) ?>
    <p class="nota"><?= $fmt($qual['cat_auto']) ?> categorias sugeridas automaticamente. <a href="<?= e($link(['cat_auto' => '1'])) ?>">Revisar</a></p>
  </section>
  <section class="cartao quadro">
    <h2>Origem <small>por onde chegou</small></h2>
    <?= barras(array_map(fn($r) => [$r['origem'] !== '' ? nome_origem($r['origem']) : 'Não informada', (int)$r['n'], $r['origem'] !== '' ? $link(['origem' => $r['origem']]) : null], $origens), $total) ?>
  </section>
  <section class="cartao quadro">
    <h2>Estado (UF)</h2>
    <?php $u10 = array_slice($ufs, 0, 8); $resto = array_sum(array_column(array_slice($ufs, 8), 'n')); ?>
    <?= barras(array_merge(array_map(fn($r) => [$r['uf'] !== '' ? $r['uf'] : 'Não informada', (int)$r['n'], $r['uf'] !== '' ? $link(['uf' => $r['uf']]) : null], $u10), $resto ? [['Outras UFs', $resto, null]] : []), $total) ?>
  </section>
  <section class="cartao quadro">
    <h2>Cidades <small>10 maiores</small></h2>
    <?= barras(array_map(fn($r) => [$r['cidade'] . ($r['uf'] ? ' / ' . $r['uf'] : ''), (int)$r['n'], $link(['cidade' => $r['cidade']])], $cidades), $total) ?>
  </section>
  <section class="cartao quadro">
    <h2>Bairros e regiões do DF <small>10 maiores</small></h2>
    <?= barras(array_map(fn($r) => [$r['bairro'], (int)$r['n'], $link(['uf' => 'DF', 'bairro' => $r['bairro']])], $bairros)) ?>
  </section>
  <section class="cartao quadro">
    <h2>Relação com a vinícola <small>pelos tours registrados</small></h2>
    <?= barras(array_map(fn($k, $rot) => [$rot, $estagios[$k], null], array_keys(grupos_estagios()), grupos_estagios()), $total) ?>
  </section>
  <section class="cartao quadro largo">
    <h2>Tours por mês <small>últimos 12 meses · <?= $fmt($t12) ?> tours, <?= $fmt($p12) ?> pessoas, <?= brl($v12) ?><?= $t12 ? ' · ticket médio ' . brl($v12 / $t12) : '' ?></small></h2>
    <?php $maxM = max(1, ...array_column($meses, 'tours')); $nomesM = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez']; ?>
    <div class="colunas">
      <?php foreach ($meses as $m => $x): ?>
        <a class="coluna" href="tours.php?mes=<?= $m ?>" title="<?= e($nomesM[(int)substr($m, 5) - 1] . '/' . substr($m, 0, 4) . ': ' . $x['tours'] . ' tours, ' . $x['pessoas'] . ' pessoas, ' . brl($x['valor'])) ?>">
          <span class="v"><?= $x['tours'] ?: '' ?></span>
          <span class="b" style="height:<?= $x['tours'] ? max(2, $x['tours'] * 100 / $maxM) : 0 ?>%"></span>
          <span class="m"><?= $nomesM[(int)substr($m, 5) - 1] ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </section>
  <section class="cartao quadro">
    <h2>Aniversariantes <small>próximos 30 dias · <?= count($aniv) ?></small></h2>
    <ul class="lista-aniv">
      <?php foreach (array_slice($aniv, 0, 10) as $a): ?>
        <li><a href="clientes.php?editar=<?= (int)$a['id'] ?>"><?= e($a['nome']) ?></a> <span class="sub"><?= $a['quando'] ?><?= $a['dias'] === 0 ? ' · hoje' : '' ?> · faz <?= $a['idade'] ?></span></li>
      <?php endforeach; if (!$aniv): ?><li class="sub">Ninguém nos próximos 30 dias.</li><?php endif; ?>
    </ul>
    <?php if (count($aniv) > 10): ?><p class="nota">e mais <?= count($aniv) - 10 ?>. Veja o grupo Aniversariantes do mês.</p><?php endif; ?>
  </section>
  <section class="cartao quadro">
    <h2>Qualidade do cadastro <small>o que corrigir</small></h2>
    <ul class="lista-qualidade">
      <li><a href="<?= e($link(['pend' => '1'])) ?>"><strong><?= $fmt($qual['pend']) ?></strong> com pendência (CPF, telefone, data...)</a></li>
      <li><a href="<?= e($link(['sexo' => 'presumido'])) ?>"><strong><?= $fmt($qual['sexo_pres']) ?></strong> com sexo presumido pelo nome</a></li>
      <li><a href="<?= e($link(['cat_auto' => '1'])) ?>"><strong><?= $fmt($qual['cat_auto']) ?></strong> com categoria automática</a></li>
      <li><strong><?= $fmt($qual['sem_contato']) ?></strong> sem telefone nem e-mail</li>
      <li><strong><?= $fmt($qual['sem_consent']) ?></strong> sem resposta sobre marketing (LGPD)</li>
    </ul>
  </section>
  <?php if ($vendedores): ?>
  <section class="cartao quadro">
    <h2>Clientes por vendedor</h2>
    <?= barras(array_map(fn($r) => [$r['vendedor'], (int)$r['n'], null], $vendedores)) ?>
  </section>
  <?php endif; ?>
  <section class="cartao quadro">
    <h2>Grupos de campanha <small>tamanho hoje · base inteira</small></h2>
    <?= barras(array_map(fn($g) => [$g['nome'], (int)($contG[$g['id']]['total'] ?? 0), 'crm_grupos.php?ver=' . $g['id']], $grupos), $totalBase) ?>
  </section>
</div>
<p class="sub">Clique em qualquer barra para abrir a lista desses clientes. Os filtros acima recortam todos os quadros, menos o de grupos.</p>
<?php require __DIR__ . '/inc/rodape.php'; ?>
