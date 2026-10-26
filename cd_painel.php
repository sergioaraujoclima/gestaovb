<?php
require __DIR__ . '/inc/app.php';
exigir_login();
exigir_cd();
$sql = "SELECT en.*, v.nome vinicola, m.nome motorista FROM cd_entregas en
    JOIN cd_vinicolas v ON v.id = en.vinicola_id LEFT JOIN cd_motoristas m ON m.id = en.motorista_id
    WHERE en.status = 'agendada'";
$par = [];
if (cd_so_meus()) { $sql .= ' AND (en.motorista_id IS NULL OR en.motorista_id = ?)'; $par[] = meu_motorista_id(); }
$st = db()->prepare($sql . ' ORDER BY en.data_entrega, en.id');
$st->execute($par);
$hoje = date('Y-m-d');
$grupos = ['Atrasadas' => [], 'Hoje' => [], 'Próximas' => []];
foreach ($st as $l) $grupos[$l['data_entrega'] < $hoje ? 'Atrasadas' : ($l['data_entrega'] === $hoje ? 'Hoje' : 'Próximas')][] = $l;
$titulo = 'CD · Entregas do dia';
require __DIR__ . '/inc/topo.php';
?>
<?php if (tem_perfil('motorista_cd') && !meu_motorista_id()): ?>
  <div class="aviso erro">Seu usuário ainda não está vinculado a um cadastro de motorista. Peça ao Cadastrador CD para fazer o vínculo em "CD · Motoristas".</div>
<?php endif; ?>
<div class="cards">
  <div class="card alerta"><span>Atrasadas</span><strong><?= count($grupos['Atrasadas']) ?></strong></div>
  <div class="card"><span>Hoje · <?= date('d/m') ?></span><strong><?= count($grupos['Hoje']) ?></strong></div>
  <div class="card"><span>Próximas</span><strong><?= count($grupos['Próximas']) ?></strong></div>
</div>
<?php foreach ($grupos as $nome => $itens): if (!$itens && $nome !== 'Hoje') continue; ?>
<h2><?= $nome ?> <small><?= count($itens) ?> entrega(s)</small></h2>
<?php if (!$itens): ?><div class="cartao vazio">Nenhuma entrega agendada para hoje.</div><?php endif; ?>
<?php foreach ($itens as $l): ?>
<div class="cartao entrega" id="e<?= (int)$l['id'] ?>">
  <div class="entrega-topo">
    <strong><?= data_br($l['data_entrega']) ?> · <?= dia_semana($l['data_entrega']) ?></strong>
    <span class="tag neutra"><?= e($l['vinicola']) ?></span>
    <span class="tag neutra">Motorista: <?= e($l['motorista'] ?: 'a definir') ?></span>
  </div>
  <p><?= e($l['descricao']) ?><?= !empty($l['nfe_numero']) ? ' · NFe ' . e($l['nfe_numero']) : '' ?></p>
  <p class="sub">
    <?= e($l['cliente_nome'] ?: 'Cliente não informado') ?><?= $l['cliente_telefone'] ? ' · ' . e($l['cliente_telefone']) : '' ?>
    <?= $l['endereco'] ? '<br>' . e($l['endereco']) : '' ?>
  </p>
  <?php if (pode_confirmar_entrega($l)): ?>
  <form method="post" action="cd_entregas.php" class="grade">
    <?= csrf_campo() ?>
    <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
    <input type="hidden" name="volta" value="painel">
    <label class="l2">Data e horário da entrega<input type="datetime-local" name="entregue_em" required value="<?= date('Y-m-d\TH:i') ?>"></label>
    <label class="l2">Observação<input name="obs_motorista" maxlength="500" placeholder="ex.: recebido pelo porteiro"></label>
    <div class="l4 acoes"><button name="acao" value="confirmar" onclick="return confirm('Confirmar que esta entrega foi realizada?')">Confirmar entrega</button></div>
  </form>
  <?php endif; ?>
</div>
<?php endforeach; endforeach; ?>
<?php require __DIR__ . '/inc/rodape.php'; ?>
