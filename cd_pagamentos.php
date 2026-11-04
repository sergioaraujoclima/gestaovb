<?php
// Centro de Distribuição · Baixa Pagamentos Entregas: cobrança mensal por vinícola, extrato e baixa com comprovante.
require __DIR__ . '/inc/app.php';
exigir_login();
if (!pode_ver_cd_pagamentos()) negar();
$pdo = db();
if (!cd_v15()) {
    $titulo = 'CD · Baixa Pagamentos Entregas';
    require __DIR__ . '/inc/topo.php';
    echo '<div class="aviso erro">' . (eh_admin() ? 'Esta tela precisa da migração do banco: rode no phpMyAdmin o arquivo migracao_v15.sql (só cria uma tabela nova).' : 'A tela está em instalação. Tente novamente mais tarde.') . '</div>';
    require __DIR__ . '/inc/rodape.php';
    exit;
}
$quem = $GLOBALS['USUARIO']['nome'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!pode_baixar_cd()) negar();
    $acao = $_POST['acao'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    $s = $pdo->prepare('SELECT c.*, v.nome vinicola FROM cd_cobrancas c LEFT JOIN cd_vinicolas v ON v.id = c.vinicola_id WHERE c.id = ?'); $s->execute([$id]); $c = $s->fetch();
    $volta = 'cd_pagamentos.php?' . http_build_query(array_filter(['ver' => $_POST['ver'] ?? '', 'mes' => $_POST['mes'] ?? ''])) . '#cob-' . $id;
    if ($acao === 'vencimento') {
        $dia = max(1, min(28, (int)($_POST['dia'] ?? 10)));
        config_salvar('cd_dia_vencimento', (string)$dia);
        // Vale para as cobranças em aberto também (as pagas ficam como estavam).
        foreach ($pdo->query("SELECT id, mes FROM cd_cobrancas WHERE status = 'aberta'")->fetchAll() as $r)
            $pdo->prepare('UPDATE cd_cobrancas SET vencimento = ? WHERE id = ?')->execute([cd_vencimento($r['mes']), $r['id']]);
        registrar_log('vinicola', 0, 'Dia de vencimento das cobranças do CD', 'dia ' . $dia . ' do mês seguinte');
        flash('Vencimento definido para o dia ' . $dia . ' do mês seguinte ao das entregas.'); ir('cd_pagamentos.php');
    }
    if (!$c) { flash('Cobrança não encontrada.', 'erro'); ir('cd_pagamentos.php'); }
    if ($acao === 'baixa') {
        if ($c['status'] === 'paga') { flash('Esta cobrança já tem baixa.', 'erro'); ir($volta); }
        $pago = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['pago_em'] ?? '') ? $_POST['pago_em'] : null;
        $valor = parse_valor($_POST['valor_pago'] ?? '');
        if (!$pago || $pago > date('Y-m-d')) { flash('Informe a data do pagamento (hoje ou antes).', 'erro'); ir($volta); }
        if ($valor <= 0) { flash('Informe o valor pago.', 'erro'); ir($volta); }
        try { $arq = salvar_comprovante($_FILES['comprovante'] ?? null); } catch (Throwable $ex) { flash($ex->getMessage(), 'erro'); ir($volta); }
        if (!$arq) { flash('Anexe o comprovante de pagamento: sem ele a baixa não é feita.', 'erro'); ir($volta); }
        $obs = vazio_null(mb_substr(texto_limpo($_POST['obs'] ?? ''), 0, 500));
        $pdo->prepare("UPDATE cd_cobrancas SET status = 'paga', pago_em = ?, valor_pago = ?, total_na_baixa = total, comprovante = ?, obs = ?, baixa_por = ?, baixa_em = ?, atualizado_em = ? WHERE id = ? AND status = 'aberta'")
            ->execute([$pago, $valor, $arq, $obs, $quem, date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $id]);
        registrar_log('vinicola', $c['vinicola_id'], 'Baixa de pagamento das entregas', rotulo_mes($c['mes']) . ': ' . brl($valor) . ' pago em ' . data_br($pago)
            . (abs($valor - (float)$c['total']) > 0.004 ? ' (cobrado ' . brl($c['total']) . ')' : '') . ($obs ? ' · ' . $obs : ''));
        flash('Baixa registrada: ' . ($c['vinicola'] ?: 'vinícola') . ' · ' . rotulo_mes($c['mes']) . ' · ' . brl($valor) . '.');
        ir($volta);
    }
    if ($acao === 'desfazer') {
        if ($c['status'] !== 'paga') ir($volta);
        $pdo->prepare("UPDATE cd_cobrancas SET status = 'aberta', pago_em = NULL, valor_pago = NULL, total_na_baixa = NULL, comprovante = NULL, obs = NULL, baixa_por = NULL, baixa_em = NULL, atualizado_em = ? WHERE id = ?")
            ->execute([date('Y-m-d H:i:s'), $id]);
        // O arquivo do comprovante não é apagado: fica guardado e o nome vai para a auditoria.
        registrar_log('vinicola', $c['vinicola_id'], 'Baixa de pagamento desfeita', rotulo_mes($c['mes']) . ': era ' . brl($c['valor_pago']) . ' pago em ' . data_br($c['pago_em']) . ' · comprovante ' . $c['comprovante']);
        flash('Baixa desfeita: a cobrança voltou para em aberto.');
        ir($volta);
    }
    ir($volta);
}

$ver = in_array($_GET['ver'] ?? '', ['pendentes', 'pagas', 'todas'], true) ? $_GET['ver'] : 'pendentes';
$mesF = preg_match('/^\d{4}-\d{2}$/', $_GET['mes'] ?? '') ? $_GET['mes'] : '';
$lista = cd_cobrancas($ver, $mesF);
$abertas = cd_cobrancas('pendentes');
$k = ['aberto' => 0, 'n' => 0, 'vencido' => 0, 'nv' => 0, 'pago_mes' => 0];
foreach ($abertas as $c) { $k['aberto'] += (float)$c['total']; $k['n']++; if (cd_dias_atraso($c)) { $k['vencido'] += (float)$c['total']; $k['nv']++; } }
$s = $pdo->prepare("SELECT COALESCE(SUM(valor_pago), 0) FROM cd_cobrancas WHERE status = 'paga' AND pago_em >= ?"); $s->execute([date('Y-m-01')]); $k['pago_mes'] = (float)$s->fetchColumn();
// Mês em andamento (ainda não fechado): só para acompanhar.
$s = $pdo->prepare("SELECT v.nome, COUNT(*) qtd, COALESCE(SUM(e.valor), 0) total FROM cd_entregas e LEFT JOIN cd_vinicolas v ON v.id = e.vinicola_id WHERE e.status = 'entregue' AND e.entregue_em >= ? GROUP BY e.vinicola_id, v.nome ORDER BY v.nome");
$s->execute([date('Y-m-01 00:00:00')]); $correndo = $s->fetchAll();
$podeBaixa = pode_baixar_cd();
$titulo = 'CD · Baixa Pagamentos Entregas';
require __DIR__ . '/inc/topo.php';
?>
<p class="sub">Cada mês fechado gera uma cobrança por vinícola, com as entregas concluídas no mês. Envie o extrato, cobre e, quando a vinícola pagar, dê a baixa <strong>anexando o comprovante</strong>. Enquanto houver cobrança sem comprovante, o Financeiro e o Gerente geral recebem o alerta em Sugestões da IA › Alertas e sugestões.</p>
<div class="cards">
  <div class="card<?= $k['n'] ? ' alerta' : '' ?>"><span>Em aberto</span><strong><?= brl($k['aberto']) ?></strong><small><?= $k['n'] ?> cobrança(s)</small></div>
  <div class="card<?= $k['nv'] ? ' alerta' : '' ?>"><span>Vencido</span><strong><?= brl($k['vencido']) ?></strong><small><?= $k['nv'] ?> cobrança(s)</small></div>
  <div class="card"><span>Recebido neste mês</span><strong><?= brl($k['pago_mes']) ?></strong><small>baixas com pagamento desde <?= date('01/m') ?></small></div>
</div>

<form method="get" class="filtro cob-filtro">
  <select name="ver" onchange="this.form.submit()"><?php foreach (['pendentes' => 'Em aberto', 'pagas' => 'Pagas', 'todas' => 'Todas'] as $kk => $vv): ?><option value="<?= $kk ?>" <?= $ver === $kk ? 'selected' : '' ?>><?= $vv ?></option><?php endforeach; ?></select>
  <input type="month" name="mes" value="<?= e($mesF) ?>" onchange="this.form.submit()" title="Mês das entregas">
  <?php if ($mesF): ?><a class="btn sec" href="cd_pagamentos.php?ver=<?= e($ver) ?>">Todos os meses</a><?php endif; ?>
</form>

<div class="cartao rolagem"><table class="cob">
  <thead><tr><th>Vinícola</th><th>Mês das entregas</th><th class="num">Entregas</th><th class="num">Valor</th><th>Situação</th><th>Extrato</th><th>Pagamento</th></tr></thead>
  <tbody>
  <?php foreach ($lista as $c): $atr = cd_dias_atraso($c); $paga = $c['status'] === 'paga'; ?>
    <tr id="cob-<?= (int)$c['id'] ?>" class="<?= $paga ? 'paga' : ($atr ? 'vencida' : '') ?>">
      <td class="nome"><?= e($c['vinicola'] ?: 'Vinícola #' . $c['vinicola_id']) ?><?= $c['responsavel'] ? '<br><small class="sub">' . e($c['responsavel']) . '</small>' : '' ?></td>
      <td><?= e(rotulo_mes($c['mes'])) ?></td>
      <td class="num"><?= (int)$c['qtd'] ?></td>
      <td class="num"><?= brl($c['total']) ?><?= $paga && $c['total_na_baixa'] !== null && abs((float)$c['total'] - (float)$c['total_na_baixa']) > 0.004 ? '<br><small class="erro-txt" title="Entregas do mês foram alteradas depois da baixa">na baixa: ' . brl($c['total_na_baixa']) . '</small>' : '' ?></td>
      <td><span class="tag <?= $paga ? 'ok' : ($atr ? 'pend' : 'neutra') ?>"><?= e(cd_situacao_rotulo($c)) ?></span><?= !$paga && $atr ? '<br><small class="sub">vencimento ' . data_br($c['vencimento']) . '</small>' : '' ?></td>
      <td class="quebra">
        <a class="btn peq sec" href="cd_extrato.php?mes=<?= e($c['mes']) ?>&v=<?= (int)$c['vinicola_id'] ?>" target="_blank">Ver extrato</a>
        <?php if ($podeBaixa && !$paga): ?><form method="post" action="cd_extrato.php?mes=<?= e($c['mes']) ?>" class="inline" onsubmit="return confirm('Enviar o extrato por e-mail para <?= e(addslashes((string)$c['email'])) ?>?')"><?= csrf_campo() ?><input type="hidden" name="vinicola_id" value="<?= (int)$c['vinicola_id'] ?>"><input type="hidden" name="volta" value="pagamentos"><button class="peq sec"<?= $c['email'] ? '' : ' disabled title="Vinícola sem e-mail no cadastro"' ?>><?= $c['extrato_em'] ? 'Reenviar' : 'Enviar' ?> por e-mail</button></form><?php endif; ?>
        <br><small class="sub"><?= $c['extrato_em'] ? 'enviado em ' . date('d/m/Y H:i', strtotime($c['extrato_em'])) : 'ainda não enviado' ?></small>
      </td>
      <td class="quebra">
        <?php if ($paga): ?>
          <strong><?= brl($c['valor_pago']) ?></strong> em <?= data_br($c['pago_em']) ?>
          <br><a href="cd_comprovante.php?id=<?= (int)$c['id'] ?>" target="_blank">comprovante</a> · <small class="sub">baixa por <?= e($c['baixa_por']) ?></small>
          <?= $c['obs'] ? '<br><small class="sub">' . e($c['obs']) . '</small>' : '' ?>
          <?php if ($podeBaixa): ?><form method="post" class="inline" onsubmit="return confirm('Desfazer esta baixa? A cobrança volta para em aberto (o comprovante fica guardado na auditoria).')"><?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="ver" value="<?= e($ver) ?>"><input type="hidden" name="mes" value="<?= e($mesF) ?>"><button class="link" name="acao" value="desfazer">desfazer baixa</button></form><?php endif; ?>
        <?php elseif ($podeBaixa): ?>
          <details class="baixa"><summary class="btn peq">Dar baixa</summary>
            <form method="post" enctype="multipart/form-data" class="grade baixa-form"><?= csrf_campo() ?><input type="hidden" name="acao" value="baixa"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="ver" value="<?= e($ver) ?>"><input type="hidden" name="mes" value="<?= e($mesF) ?>">
              <label>Pago em<input type="date" name="pago_em" required max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>"></label>
              <label>Valor pago<input name="valor_pago" required inputmode="decimal" value="<?= e(number_format((float)$c['total'], 2, ',', '.')) ?>"></label>
              <label class="l2">Comprovante (PDF ou imagem, até 5 MB)*<input type="file" name="comprovante" required accept="application/pdf,image/jpeg,image/png,image/webp"></label>
              <label class="l2">Observação (opcional)<input name="obs" maxlength="500" placeholder="ex.: Pix recebido na conta do CD"></label>
              <div class="l2 acoes"><button>Confirmar baixa</button></div>
            </form>
          </details>
        <?php else: ?><span class="sub">aguardando pagamento</span><?php endif; ?>
      </td>
    </tr>
  <?php endforeach; if (!$lista): ?>
    <tr><td colspan="7" class="vazio"><?= $ver === 'pendentes' ? 'Nenhuma cobrança em aberto. 👍' : 'Nada encontrado com este filtro.' ?></td></tr>
  <?php endif; ?>
  </tbody>
</table></div>

<?php if ($correndo): ?>
<h2><?= e(rotulo_mes(date('Y-m'))) ?> <small>mês em andamento · vira cobrança quando o mês fechar</small></h2>
<div class="cartao rolagem"><table>
  <thead><tr><th>Vinícola</th><th class="num">Entregas até agora</th><th class="num">Valor até agora</th></tr></thead>
  <tbody><?php foreach ($correndo as $r): ?><tr><td><?= e($r['nome']) ?></td><td class="num"><?= (int)$r['qtd'] ?></td><td class="num"><?= brl($r['total']) ?></td></tr><?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>

<?php if ($podeBaixa): ?>
<form method="post" class="acoes nao-imprimir" style="margin-top:14px"><?= csrf_campo() ?><input type="hidden" name="acao" value="vencimento">
  <label class="inline">Vencimento das cobranças: dia <input type="number" name="dia" min="1" max="28" value="<?= cd_dia_vencimento() ?>" style="width:70px"> do mês seguinte ao das entregas</label>
  <button class="peq sec">Salvar</button>
</form>
<?php endif; ?>
<?php require __DIR__ . '/inc/rodape.php'; ?>
