<?php
require __DIR__ . '/inc/app.php';
exigir_login();
if (!pode_ver_tours()) ir(pagina_inicial());
$r = resumo_periodo(date('Y-m-01'), date('Y-m-t'));
$prox = db()->query("SELECT v.data_tour, v.horario, COUNT(*) reservas, SUM(v.qtd) pessoas,
    SUM(CASE WHEN v.recebido=0 THEN 1 ELSE 0 END) pendentes,
    GROUP_CONCAT(DISTINCT g.nome SEPARATOR ', ') guias
    FROM tour_vendas v LEFT JOIN guias g ON g.id = v.guia_id WHERE v.data_tour >= CURDATE()
    GROUP BY v.data_tour, v.horario ORDER BY v.data_tour, v.horario LIMIT 20")->fetchAll();
$titulo = 'Painel';
require __DIR__ . '/inc/topo.php';
?>
<p class="sub">Mês atual (<?= date('m/Y') ?>)</p>
<div class="cards">
  <div class="card"><span>Pessoas</span><strong><?= (int)$r['pessoas'] ?></strong></div>
  <div class="card"><span>Vendido</span><strong><?= brl($r['total']) ?></strong></div>
  <div class="card"><span>Recebido</span><strong><?= brl($r['recebido']) ?></strong></div>
  <div class="card alerta"><span>A confirmar</span><strong><?= brl($r['pendente']) ?></strong></div>
</div>
<h2>Próximos tours</h2>
<div class="cartao rolagem">
<table>
  <thead><tr><th>Data</th><th>Horário</th><th>Reservas</th><th>Pessoas</th><th>Pgto. pendente</th><th>Guia / Sommelier</th></tr></thead>
  <tbody>
  <?php foreach ($prox as $p): ?>
    <tr>
      <td><a href="tours.php?mes=<?= e(substr($p['data_tour'], 0, 7)) ?>"><?= data_br($p['data_tour']) ?></a> · <?= dia_semana($p['data_tour']) ?></td>
      <td><?= e(substr($p['horario'], 0, 5)) ?></td>
      <td><?= (int)$p['reservas'] ?></td>
      <td><?= (int)$p['pessoas'] ?></td>
      <td><?= (int)$p['pendentes'] ?></td>
      <td><?= e($p['guias'] ?: '—') ?></td>
    </tr>
  <?php endforeach; if (!$prox): ?><tr><td colspan="6" class="vazio">Nenhum tour agendado.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<?php require __DIR__ . '/inc/rodape.php'; ?>
