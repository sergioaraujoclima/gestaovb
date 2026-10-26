<?php
require __DIR__ . '/inc/app.php';
exigir_login();
if (!pode_ver_cd_gestao()) negar();
$pdo = db();
$mes = preg_match('/^\d{4}-\d{2}$/', $_GET['mes'] ?? '') ? $_GET['mes'] : date('Y-m');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    exigir_cd_edicao();
    $ok = enviar_extrato_cd((int)($_POST['vinicola_id'] ?? 0), $mes);
    flash($ok ? 'Extrato enviado.' : 'Não foi possível enviar (vinícola sem e-mail válido ou falha no servidor).', $ok ? 'ok' : 'erro');
    ir('cd_extrato.php?mes=' . $mes);
}

$vinicolas = $pdo->query('SELECT * FROM cd_vinicolas ORDER BY nome')->fetchAll();
$se = $pdo->prepare('SELECT vinicola_id, enviado_em FROM cd_extratos WHERE mes = ?'); $se->execute([$mes]);
$enviados = $se->fetchAll(PDO::FETCH_KEY_PAIR);
$geral = ['qtd' => 0, 'total' => 0.0];
$dados = [];
foreach ($vinicolas as $v) {
    $x = extrato_cd($v['id'], $mes);
    if (!$x['qtd']) continue;
    $dados[] = [$v, $x];
    $geral['qtd'] += $x['qtd']; $geral['total'] += $x['total'];
}
$ini = $mes . '-01';
$ant = date('Y-m', strtotime($ini . ' -1 month'));
$prox = date('Y-m', strtotime($ini . ' +1 month'));
$titulo = 'CD · Extrato mensal';
require __DIR__ . '/inc/topo.php';
?>
<div class="filtro nao-imprimir">
  <a class="btn sec" href="cd_extrato.php?mes=<?= $ant ?>">‹</a>
  <form method="get"><input type="month" name="mes" value="<?= e($mes) ?>" onchange="this.form.submit()"></form>
  <a class="btn sec" href="cd_extrato.php?mes=<?= $prox ?>">›</a>
  <button type="button" onclick="window.print()">Imprimir / salvar PDF</button>
</div>
<p class="sub"><?= e(rotulo_mes($mes)) ?> · entregas concluídas no mês · <?= $geral['qtd'] ?> entrega(s) · total a receber <?= brl($geral['total']) ?></p>

<?php foreach ($dados as [$v, $x]): ?>
<h2><?= e($v['nome']) ?> <small><?= e($v['responsavel']) ?><?= $v['email'] ? ' · ' . e($v['email']) : '' ?></small></h2>
<div class="cartao rolagem"><table>
  <thead><tr><th>Entregue em</th><th>Descrição</th><th>NFe</th><th>Cliente</th><th>Motorista</th><th class="num">Valor</th></tr></thead>
  <tbody>
  <?php foreach ($x['linhas'] as $l): ?>
    <tr>
      <td><?= date('d/m/Y H:i', strtotime($l['entregue_em'])) ?></td>
      <td class="quebra"><?= e($l['descricao']) ?></td>
      <td><?= e($l['nfe_numero'] ?? '') ?></td>
      <td><?= e($l['cliente_nome']) ?></td>
      <td><?= e($l['motorista'] ?: '—') ?></td>
      <td class="num"><?= brl($l['valor']) ?></td>
    </tr>
  <?php endforeach; ?>
    <tr class="soma"><td colspan="5">Total a pagar · <?= $x['qtd'] ?> entrega(s)</td><td class="num"><?= brl($x['total']) ?></td></tr>
  </tbody>
</table></div>
<div class="acoes nao-imprimir">
  <?php if (pode_cd_editar()): ?>
  <form method="post" action="cd_extrato.php?mes=<?= e($mes) ?>" onsubmit="return confirm('Enviar o extrato por e-mail ao responsável?')">
    <?= csrf_campo() ?><input type="hidden" name="vinicola_id" value="<?= (int)$v['id'] ?>">
    <button class="peq"><?= isset($enviados[$v['id']]) ? 'Reenviar extrato por e-mail' : 'Enviar extrato por e-mail' ?></button>
  </form>
  <?php endif; ?>
  <span class="sub"><?= isset($enviados[$v['id']]) ? 'Enviado em ' . date('d/m/Y H:i', strtotime($enviados[$v['id']])) : 'Ainda não enviado' ?></span>
</div>
<?php endforeach; if (!$dados): ?>
  <div class="cartao vazio">Nenhuma entrega concluída neste mês.</div>
<?php endif; ?>
<?php require __DIR__ . '/inc/rodape.php'; ?>
