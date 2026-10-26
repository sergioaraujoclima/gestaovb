<?php
require __DIR__ . '/inc/app.php';
exigir_login();
exigir_tours();
$mes = preg_match('/^\d{4}-\d{2}$/', $_GET['mes'] ?? '') ? $_GET['mes'] : date('Y-m');
$ini = $mes . '-01';
$fim = date('Y-m-t', strtotime($ini));

$st = db()->prepare("SELECT v.*, c.nome, g.nome guia FROM tour_vendas v
    JOIN clientes c ON c.id = v.cliente_id LEFT JOIN guias g ON g.id = v.guia_id
    WHERE v.data_tour BETWEEN ? AND ? ORDER BY v.data_tour, v.horario, c.nome");
$st->execute([$ini, $fim]);

// Semanas de segunda a domingo, cortadas nos limites do mês.
$desloc = (int)date('N', strtotime($ini)) - 1;
$zero = ['vendas' => 0, 'pessoas' => 0, 'total' => 0.0, 'recebido' => 0.0, 'pendente' => 0.0];
$semanas = []; $geral = $zero;
foreach ($st as $v) {
    $n = intdiv((int)date('j', strtotime($v['data_tour'])) + $desloc - 1, 7) + 1;
    if (!isset($semanas[$n])) {
        $seg = strtotime('monday this week', strtotime($v['data_tour']));
        $semanas[$n] = ['de' => max($seg, strtotime($ini)), 'ate' => min(strtotime('+6 days', $seg), strtotime($fim)), 'linhas' => [], 'soma' => $zero];
    }
    $semanas[$n]['linhas'][] = $v;
    foreach ([&$semanas[$n]['soma'], &$geral] as &$s) {
        $s['vendas']++; $s['pessoas'] += $v['qtd']; $s['total'] += $v['valor_pago'];
        $s[$v['recebido'] ? 'recebido' : 'pendente'] += $v['valor_pago'];
    }
    unset($s);
}
$nomesMes = [1=>'Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];
$rotMes = $nomesMes[(int)substr($mes, 5, 2)] . ' de ' . substr($mes, 0, 4);
$ant = date('Y-m', strtotime($ini . ' -1 month'));
$prox = date('Y-m', strtotime($ini . ' +1 month'));
$titulo = 'Relatório mensal de tours';
require __DIR__ . '/inc/topo.php';
?>
<div class="filtro nao-imprimir">
  <a class="btn sec" href="relatorio.php?mes=<?= $ant ?>">‹</a>
  <form method="get"><input type="month" name="mes" value="<?= e($mes) ?>" onchange="this.form.submit()"></form>
  <a class="btn sec" href="relatorio.php?mes=<?= $prox ?>">›</a>
  <button type="button" onclick="window.print()">Imprimir / salvar PDF</button>
</div>
<p class="sub"><?= e($rotMes) ?> · semanas de segunda a domingo</p>

<?php foreach ($semanas as $n => $sem): $s = $sem['soma']; ?>
<h2>Semana <?= $n ?> <small><?= date('d/m', $sem['de']) ?> a <?= date('d/m', $sem['ate']) ?></small></h2>
<div class="cartao rolagem"><table>
  <thead><tr><th>Data</th><th>Horário</th><th>Cliente</th><th>Guia</th><th>Qtd</th><th>Modalidade</th><th>Pagamento</th><th>Presença</th><th class="num">Valor</th></tr></thead>
  <tbody>
  <?php foreach ($sem['linhas'] as $v): ?>
    <tr>
      <td><?= data_br($v['data_tour']) ?></td>
      <td><?= e(substr($v['horario'], 0, 5)) ?></td>
      <td><?= e($v['nome']) ?></td>
      <td><?= e($v['guia'] ?: '—') ?></td>
      <td><?= (int)$v['qtd'] ?></td>
      <td><?= $v['modalidade'] === 'pix' ? 'Pix' : 'Link' ?></td>
      <td><?= $v['recebido'] ? 'Recebido' : 'Pendente' ?><?= $v['estorno'] !== 'nenhum' ? ' · ' . e(nome_estorno($v['estorno'])) : '' ?></td>
      <td><?= e(nome_presenca($v['presenca'])) ?></td>
      <td class="num"><?= brl($v['valor_pago']) ?></td>
    </tr>
  <?php endforeach; ?>
    <tr class="soma">
      <td colspan="4">Total da semana <?= $n ?> · <?= $s['vendas'] ?> venda(s)</td>
      <td><?= $s['pessoas'] ?></td>
      <td colspan="3">Recebido <?= brl($s['recebido']) ?> · Pendente <?= brl($s['pendente']) ?></td>
      <td class="num"><?= brl($s['total']) ?></td>
    </tr>
  </tbody>
</table></div>
<?php endforeach; ?>

<?php if (!$semanas): ?>
  <div class="cartao vazio">Nenhum tour registrado neste mês.</div>
<?php else: ?>
<h2>Total do mês</h2>
<div class="cartao rolagem"><table>
  <thead><tr><th>Semana</th><th>Período</th><th>Vendas</th><th>Pessoas</th><th class="num">Recebido</th><th class="num">Pendente</th><th class="num">Total</th></tr></thead>
  <tbody>
  <?php foreach ($semanas as $n => $sem): $s = $sem['soma']; ?>
    <tr><td>Semana <?= $n ?></td><td><?= date('d/m', $sem['de']) ?> a <?= date('d/m', $sem['ate']) ?></td><td><?= $s['vendas'] ?></td><td><?= $s['pessoas'] ?></td>
      <td class="num"><?= brl($s['recebido']) ?></td><td class="num"><?= brl($s['pendente']) ?></td><td class="num"><?= brl($s['total']) ?></td></tr>
  <?php endforeach; ?>
    <tr class="soma"><td colspan="2"><?= e($rotMes) ?></td><td><?= $geral['vendas'] ?></td><td><?= $geral['pessoas'] ?></td>
      <td class="num"><?= brl($geral['recebido']) ?></td><td class="num"><?= brl($geral['pendente']) ?></td><td class="num"><?= brl($geral['total']) ?></td></tr>
  </tbody>
</table></div>
<?php endif; ?>
<?php require __DIR__ . '/inc/rodape.php'; ?>
