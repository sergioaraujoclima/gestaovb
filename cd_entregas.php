<?php
require __DIR__ . '/inc/app.php';
exigir_login();
exigir_cd();
$pdo = db();
$mes = preg_match('/^\d{4}-\d{2}$/', $_GET['mes'] ?? '') ? $_GET['mes'] : date('Y-m');
$ini = $mes . '-01';
$fim = date('Y-m-t', strtotime($ini));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    $acao = $_POST['acao'] ?? 'salvar';
    $volta = ($_POST['volta'] ?? '') === 'painel' ? 'cd_painel.php' : 'cd_entregas.php?mes=' . $mes;
    $reg = $id ? linha('cd_entregas', $id) : null;
    if ($id && (!$reg || !cd_entrega_visivel($reg))) { flash('Entrega não encontrada.', 'erro'); ir($volta); }

    if ($acao === 'confirmar') {
        if (!pode_confirmar_entrega($reg)) { flash('Você não pode confirmar esta entrega.', 'erro'); ir($volta); }
        $quando = strtotime((string)($_POST['entregue_em'] ?? ''));
        if (!$quando || $quando > time() + 600) { flash('Informe a data e o horário em que a entrega foi feita.', 'erro'); ir($volta); }
        $pdo->prepare("UPDATE cd_entregas SET status = 'entregue', entregue_em = ?, entregue_por = ?, obs_motorista = ?, motorista_id = COALESCE(motorista_id, ?) WHERE id = ?")
            ->execute([date('Y-m-d H:i:s', $quando), (int)$GLOBALS['USUARIO']['id'], vazio_null($_POST['obs_motorista'] ?? ''), meu_motorista_id() ?: null, $id]);
        registrar_alteracao('entrega', 'cd_entregas', $id, $reg, 'Entrega confirmada');
        $env = enviar_emails_entrega($id);
        if ($env) registrar_log('entrega', $id, 'E-mail de entrega enviado', 'Para: ' . implode(' e ', $env));
        flash('Entrega confirmada.' . ($env ? ' E-mail enviado para ' . implode(' e ', $env) . '.' : ' Nenhum e-mail enviado (sem endereço válido ou falha no servidor).'));
        ir($volta);
    }
    if ($acao === 'excluir') {
        exigir_admin();
        $pdo->prepare('DELETE FROM cd_entregas WHERE id = ?')->execute([$id]);
        registrar_log('entrega', $id, 'Exclusão', valor_legivel('vinicola_id', $reg['vinicola_id']) . '; ' . data_br($reg['data_entrega']) . '; ' . $reg['descricao'] . '; ' . brl($reg['valor']));
        flash('Entrega excluída.'); ir($volta);
    }
    if ($acao === 'email') {
        exigir_cd_edicao();
        $env = enviar_emails_entrega($id);
        if ($env) { registrar_log('entrega', $id, 'E-mail de entrega reenviado', 'Para: ' . implode(' e ', $env)); flash('E-mail enviado para ' . implode(' e ', $env) . '.'); }
        else flash('Nenhum e-mail enviado (entrega não concluída, sem endereço válido ou falha no servidor).', 'erro');
        ir($volta);
    }

    exigir_cd_edicao();
    if ($reg && !pode_alterar_entrega($reg)) { flash('Entrega já concluída: só o Administrador pode alterar.', 'erro'); ir($volta); }
    $data = $_POST['data_entrega'] ?? '';
    $vin = (int)($_POST['vinicola_id'] ?? 0);
    $desc = trim($_POST['descricao'] ?? '');
    $cliEmail = trim($_POST['cliente_email'] ?? '');
    $erroVolta = $volta . ($id ? '&editar=' . $id : '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) || !$vin || $desc === '') { flash('Informe vinícola, data e descrição.', 'erro'); ir($erroVolta); }
    if ($cliEmail !== '' && !filter_var($cliEmail, FILTER_VALIDATE_EMAIL)) { flash('E-mail do cliente inválido.', 'erro'); ir($erroVolta); }
    $valor = (float)($_POST['valor'] ?? 0);
    if (!in_array($valor, array_map('floatval', CD_VALORES), true)) $valor = 0.0;
    // Situação: o Cadastrador CD e o Administrador também podem marcar a entrega como realizada pelo formulário.
    $atual = $reg ? $reg['status'] : 'agendada';
    $st = in_array($_POST['status'] ?? '', ['agendada', 'cancelada', 'entregue'], true) ? $_POST['status'] : $atual;
    $marcou = $st === 'entregue' && $atual !== 'entregue';
    $entEm = $reg['entregue_em'] ?? null; $entPor = $reg['entregue_por'] ?? null; $obs = $reg['obs_motorista'] ?? null;
    if ($st === 'entregue') {
        $t = trim((string)($_POST['entregue_em'] ?? ''));
        $quando = $t !== '' ? strtotime($t) : ($entEm ? strtotime($entEm) : time());
        if (!$quando || $quando > time() + 600) { flash('Data e horário da entrega inválidos (não podem ser no futuro).', 'erro'); ir($erroVolta); }
        // Só troca o horário gravado se o minuto informado for diferente (o formulário não tem segundos).
        if (!$entEm || date('Y-m-d H:i', strtotime($entEm)) !== date('Y-m-d H:i', $quando)) $entEm = date('Y-m-d H:i:s', $quando);
        if ($marcou) $entPor = (int)$GLOBALS['USUARIO']['id'];
        if (isset($_POST['obs_motorista'])) $obs = vazio_null($_POST['obs_motorista']);
    } elseif ($atual === 'entregue') {
        $entEm = null; $entPor = null; // reaberta ou cancelada pelo Administrador
    }
    $c = ['vinicola_id' => $vin, 'motorista_id' => (int)($_POST['motorista_id'] ?? 0) ?: null, 'data_entrega' => $data, 'descricao' => $desc,
          'valor' => $valor, 'cliente_nome' => vazio_null($_POST['cliente_nome'] ?? ''), 'cliente_email' => vazio_null($cliEmail),
          'cliente_telefone' => vazio_null($_POST['cliente_telefone'] ?? ''), 'endereco' => vazio_null($_POST['endereco'] ?? ''),
          'status' => $st, 'entregue_em' => $entEm, 'entregue_por' => $entPor, 'obs_motorista' => $obs];
    if (cd_tem_nfe()) $c['nfe_numero'] = vazio_null(mb_substr(trim((string)($_POST['nfe_numero'] ?? '')), 0, 20));
    if ($id) {
        $pdo->prepare('UPDATE cd_entregas SET ' . implode(' = ?, ', array_keys($c)) . ' = ? WHERE id = ?')->execute(array_merge(array_values($c), [$id]));
        registrar_alteracao('entrega', 'cd_entregas', $id, $reg, $marcou ? 'Entrega marcada como entregue' : 'Alteração');
    } else {
        $c['criado_por'] = (int)$GLOBALS['USUARIO']['id'];
        $pdo->prepare('INSERT INTO cd_entregas (' . implode(', ', array_keys($c)) . ') VALUES (' . implode(',', array_fill(0, count($c), '?')) . ')')->execute(array_values($c));
        $id = (int)$pdo->lastInsertId();
        registrar_log('entrega', $id, $marcou ? 'Inclusão (já entregue)' : 'Inclusão (agendamento)', valor_legivel('vinicola_id', $vin) . '; ' . data_br($data) . '; ' . $desc
            . (!empty($c['nfe_numero']) ? '; NFe ' . $c['nfe_numero'] : '') . '; ' . brl($valor) . '; motorista: ' . valor_legivel('motorista_id', $c['motorista_id'])
            . ($marcou ? '; entregue em ' . date('d/m/Y H:i', strtotime($entEm)) : ''));
    }
    if ($marcou) {
        $env = enviar_emails_entrega($id);
        if ($env) registrar_log('entrega', $id, 'E-mail de entrega enviado', 'Para: ' . implode(' e ', $env));
        flash('Entrega salva e marcada como entregue.' . ($env ? ' E-mail enviado para ' . implode(' e ', $env) . '.' : ' Nenhum e-mail enviado (sem endereço válido ou falha no servidor).'));
        ir('cd_entregas.php?mes=' . substr($data, 0, 7));
    }
    flash('Entrega salva.');
    ir('cd_entregas.php?mes=' . substr($data, 0, 7));
}

$sql = "SELECT en.*, v.nome vinicola, m.nome motorista FROM cd_entregas en
    JOIN cd_vinicolas v ON v.id = en.vinicola_id LEFT JOIN cd_motoristas m ON m.id = en.motorista_id
    WHERE en.data_entrega BETWEEN ? AND ?";
$par = [$ini, $fim];
if (cd_so_meus()) { $sql .= ' AND (en.motorista_id IS NULL OR en.motorista_id = ?)'; $par[] = meu_motorista_id(); }
$st = $pdo->prepare($sql . ' ORDER BY en.data_entrega, en.id');
$st->execute($par);
$lista = $st->fetchAll();

$ed = ['id' => 0, 'vinicola_id' => 0, 'motorista_id' => 0, 'data_entrega' => date('Y-m-d'), 'descricao' => '', 'valor' => 20, 'cliente_nome' => '',
       'cliente_email' => '', 'cliente_telefone' => '', 'endereco' => '', 'nfe_numero' => '', 'status' => 'agendada', 'entregue_em' => '', 'obs_motorista' => ''];
if (!empty($_GET['editar'])) {
    $x = linha('cd_entregas', (int)$_GET['editar']);
    if ($x && cd_entrega_visivel($x)) $ed = $x;
}
$podeForm = $ed['id'] ? pode_alterar_entrega($ed) : pode_cd_editar();
$sv = $pdo->prepare('SELECT id, nome FROM cd_vinicolas WHERE ativo = 1 OR id = ? ORDER BY nome'); $sv->execute([(int)$ed['vinicola_id']]);
$vinicolas = $sv->fetchAll();
$sm = $pdo->prepare('SELECT id, nome FROM cd_motoristas WHERE ativo = 1 OR id = ? ORDER BY nome'); $sm->execute([(int)$ed['motorista_id']]);
$motoristas = $sm->fetchAll();
$tot = ['agendada' => 0, 'entregue' => 0, 'cancelada' => 0, 'valor' => 0.0];
foreach ($lista as $l) { $tot[$l['status']]++; if ($l['status'] === 'entregue') $tot['valor'] += $l['valor']; }
$ant = date('Y-m', strtotime($ini . ' -1 month'));
$prox = date('Y-m', strtotime($ini . ' +1 month'));
$titulo = 'CD · Entregas';
require __DIR__ . '/inc/topo.php';
?>
<div class="filtro">
  <a class="btn sec" href="cd_entregas.php?mes=<?= $ant ?>">‹</a>
  <form method="get"><input type="month" name="mes" value="<?= e($mes) ?>" onchange="this.form.submit()"></form>
  <a class="btn sec" href="cd_entregas.php?mes=<?= $prox ?>">›</a>
  <a class="btn sec" href="cd_painel.php">Entregas do dia</a>
</div>
<div class="cards">
  <div class="card alerta"><span>Agendadas</span><strong><?= $tot['agendada'] ?></strong></div>
  <div class="card"><span>Entregues</span><strong><?= $tot['entregue'] ?></strong></div>
  <div class="card"><span>Canceladas</span><strong><?= $tot['cancelada'] ?></strong></div>
  <div class="card"><span>Serviço (entregues)</span><strong><?= brl($tot['valor']) ?></strong></div>
</div>

<?php if (!cd_tem_nfe() && eh_admin()): ?><div class="aviso erro">O campo Número da NFe ainda não existe no banco. Rode o arquivo migracao_v5.sql no phpMyAdmin.</div><?php endif; ?>
<?php if (pode_cd_editar() || $ed['id']): ?>
<details class="cartao" <?= $ed['id'] ? 'open' : '' ?>>
  <summary><?= !$ed['id'] ? 'Agendar entrega' : ($podeForm ? 'Editar entrega' : 'Entrega (somente leitura)') ?></summary>
  <?php if ($ed['id'] && !$podeForm && pode_cd_editar()): ?><div class="aviso erro">Entrega já concluída: só o Administrador pode alterar.</div><?php endif; ?>
  <form method="post" action="cd_entregas.php?mes=<?= e($mes) ?>">
  <fieldset class="grade" <?= $podeForm ? '' : 'disabled' ?>>
    <?= csrf_campo() ?>
    <input type="hidden" name="id" value="<?= (int)$ed['id'] ?>">
    <label class="l2">Vinícola*
      <select name="vinicola_id" required>
        <option value="">— selecione —</option>
        <?php foreach ($vinicolas as $v): ?><option value="<?= (int)$v['id'] ?>" <?= $v['id'] == $ed['vinicola_id'] ? 'selected' : '' ?>><?= e($v['nome']) ?></option><?php endforeach; ?>
      </select>
    </label>
    <label>Data da entrega*<input type="date" name="data_entrega" required value="<?= e($ed['data_entrega']) ?>"></label>
    <label>Valor do serviço
      <select name="valor">
        <?php foreach (CD_VALORES as $vl): ?><option value="<?= $vl ?>" <?= (float)$ed['valor'] === (float)$vl ? 'selected' : '' ?>><?= brl($vl) ?></option><?php endforeach; ?>
      </select>
    </label>
    <label class="l4">Descrição*<input name="descricao" required maxlength="255" placeholder="ex.: 1 caixa de vinho tinto" value="<?= e($ed['descricao']) ?>"></label>
    <label class="l2">Motorista da entrega
      <select name="motorista_id">
        <option value="0">— a definir —</option>
        <?php foreach ($motoristas as $m): ?><option value="<?= (int)$m['id'] ?>" <?= $m['id'] == $ed['motorista_id'] ? 'selected' : '' ?>><?= e($m['nome']) ?></option><?php endforeach; ?>
      </select>
    </label>
    <label>Número da NFe<input name="nfe_numero" maxlength="20" inputmode="numeric" value="<?= e($ed['nfe_numero'] ?? '') ?>" <?= cd_tem_nfe() ? '' : 'disabled' ?>></label>
    <label>Situação
      <select name="status" id="cd-status" data-inicial="<?= e($ed['status']) ?>">
        <?php foreach (['agendada', 'entregue', 'cancelada'] as $x): ?><option value="<?= $x ?>" <?= $ed['status'] === $x ? 'selected' : '' ?>><?= nome_status_entrega($x) ?></option><?php endforeach; ?>
      </select>
    </label>
    <label class="l2">Cliente (destinatário)<input name="cliente_nome" value="<?= e($ed['cliente_nome']) ?>"></label>
    <label>Telefone do cliente<input name="cliente_telefone" value="<?= e($ed['cliente_telefone']) ?>"></label>
    <label>E-mail do cliente<input type="email" name="cliente_email" value="<?= e($ed['cliente_email']) ?>"></label>
    <label class="l4">Endereço de entrega<input name="endereco" maxlength="255" value="<?= e($ed['endereco']) ?>"></label>
    <label class="l2 cd-entregue">Entregue em (data e horário)<input type="datetime-local" name="entregue_em" value="<?= $ed['entregue_em'] ? date('Y-m-d\TH:i', strtotime($ed['entregue_em'])) : '' ?>"></label>
    <label class="l2 cd-entregue">Observação da entrega<input name="obs_motorista" maxlength="500" placeholder="ex.: recebido pelo porteiro" value="<?= e($ed['obs_motorista']) ?>"></label>
    <?php if ($podeForm): ?><div class="l4 acoes"><button type="submit">Salvar</button></div><?php endif; ?>
  </fieldset>
  </form>
  <script>
  (function () {
    var sel = document.getElementById('cd-status'); if (!sel) return;
    var campos = document.querySelectorAll('.cd-entregue'), quando = sel.form.elements['entregue_em'];
    function p(n) { return (n < 10 ? '0' : '') + n; }
    function ajustar() {
      var ent = sel.value === 'entregue';
      campos.forEach(function (c) { c.style.display = ent ? '' : 'none'; });
      if (ent && !quando.value) { var d = new Date(); quando.value = d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + 'T' + p(d.getHours()) + ':' + p(d.getMinutes()); }
    }
    sel.addEventListener('change', ajustar); ajustar();
    sel.form.addEventListener('submit', function (ev) {
      if (sel.value === 'entregue' && sel.dataset.inicial !== 'entregue' &&
          !confirm('Marcar esta entrega como ENTREGUE?\n\nO cliente e a vinícola serão avisados por e-mail e, depois disso, só o Administrador poderá alterá-la.')) ev.preventDefault();
    });
  })();
  </script>
  <?php if ($ed['id']): ?>
    <?php if ($ed['status'] === 'entregue'): ?>
      <p class="sub">Entregue em <?= date('d/m/Y H:i', strtotime($ed['entregue_em'])) ?><?= $ed['obs_motorista'] ? ' · Observação do motorista: ' . e($ed['obs_motorista']) : '' ?></p>
    <?php endif; ?>
    <p class="acoes"><a class="btn peq sec" href="cd_entregas.php?mes=<?= e($mes) ?>">Fechar</a></p>
    <?php mostrar_trilha('entrega', $ed['id']); ?>
  <?php endif; ?>
</details>
<?php endif; ?>

<div class="cartao rolagem">
<table>
  <thead><tr><th>Data</th><th>Vinícola</th><th>Descrição</th><th>NFe</th><th>Cliente</th><th>Motorista</th><th class="num">Valor</th><th>Situação</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($lista as $l): ?>
    <tr>
      <td><?= data_br($l['data_entrega']) ?></td>
      <td><?= e($l['vinicola']) ?></td>
      <td class="quebra"><?= e($l['descricao']) ?></td>
      <td><?= e($l['nfe_numero'] ?? '') ?></td>
      <td><?= e($l['cliente_nome']) ?></td>
      <td><?= e($l['motorista'] ?: '—') ?></td>
      <td class="num"><?= brl($l['valor']) ?></td>
      <td><span class="tag <?= $l['status'] === 'entregue' ? 'ok' : ($l['status'] === 'agendada' ? 'pend' : 'neutra') ?>"><?= e(nome_status_entrega($l['status'])) ?><?= $l['status'] === 'entregue' ? ' ' . date('d/m H:i', strtotime($l['entregue_em'])) : '' ?></span></td>
      <td class="acoes">
        <form method="post" action="cd_entregas.php?mes=<?= e($mes) ?>">
          <?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
          <?php if (pode_confirmar_entrega($l)): ?><a class="btn peq" href="cd_painel.php#e<?= (int)$l['id'] ?>">Confirmar</a><?php endif; ?>
          <?php if ($l['status'] === 'entregue' && pode_cd_editar()): ?><button class="peq sec" name="acao" value="email">Reenviar e-mail</button><?php endif; ?>
          <a class="btn peq sec" href="cd_entregas.php?mes=<?= e($mes) ?>&editar=<?= (int)$l['id'] ?>"><?= pode_alterar_entrega($l) ? 'Editar' : 'Histórico' ?></a>
          <?php if (eh_admin()): ?><button class="peq perigo" name="acao" value="excluir" onclick="return confirm('Excluir esta entrega?')">Excluir</button><?php endif; ?>
        </form>
      </td>
    </tr>
  <?php endforeach; if (!$lista): ?><tr><td colspan="9" class="vazio">Nenhuma entrega neste mês.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<?php require __DIR__ . '/inc/rodape.php'; ?>
