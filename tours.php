<?php
require __DIR__ . '/inc/app.php';
exigir_login();
exigir_tours();
$pdo = db();
$mes = preg_match('/^\d{4}-\d{2}$/', $_GET['mes'] ?? '') ? $_GET['mes'] : date('Y-m');
$ini = $mes . '-01';
$fim = date('Y-m-t', strtotime($ini));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    $acao = $_POST['acao'] ?? 'salvar';
    $volta = 'tours.php?mes=' . $mes;
    $reg = $id ? linha('tour_vendas', $id) : null;
    if ($id && !$reg) { flash('Registro não encontrado.', 'erro'); ir($volta); }

    if ($acao === 'excluir') {
        exigir_admin();
        apagar_comprovante($reg['comprovante']);
        $pdo->prepare('DELETE FROM tour_vendas WHERE id = ?')->execute([$id]);
        grupos_recalcular_cliente((int)$reg['cliente_id']);
        registrar_log('tour', $id, 'Exclusão', 'Cliente: ' . valor_legivel('cliente_id', $reg['cliente_id']) . '; tour de ' . data_br($reg['data_tour']) . ' ' . substr($reg['horario'], 0, 5) . '; ' . brl($reg['valor_pago']));
        flash('Registro excluído.'); ir($volta);
    }
    exigir_edicao();
    if ($reg && !pode_alterar_tour($reg)) {
        flash('Presença já registrada: só o Administrador pode alterar este tour.', 'erro'); ir($volta);
    }
    if ($acao === 'receber') {
        $pdo->prepare('UPDATE tour_vendas SET recebido = 1, data_recebimento = COALESCE(data_recebimento, CURDATE()) WHERE id = ?')->execute([$id]);
        registrar_alteracao('tour', 'tour_vendas', $id, $reg, 'Recebimento confirmado');
        flash('Recebimento confirmado.'); ir($volta);
    }
    if ($acao === 'presente' || $acao === 'ausente') {
        $pdo->prepare('UPDATE tour_vendas SET presenca = ?, presenca_em = NOW(), presenca_por = ? WHERE id = ?')
            ->execute([$acao, (int)$GLOBALS['USUARIO']['id'], $id]);
        registrar_alteracao('tour', 'tour_vendas', $id, $reg, 'Presença registrada');
        flash($acao === 'presente' ? 'Presença confirmada.' : 'Ausência registrada.'); ir($volta);
    }
    if ($acao === 'email') {
        if (enviar_email_tour($id)) { registrar_log('tour', $id, 'E-mail de confirmação enviado'); flash('E-mail de confirmação enviado.'); }
        else flash('Não foi possível enviar o e-mail (cliente sem e-mail válido ou falha no servidor).', 'erro');
        ir($volta);
    }

    $data = $_POST['data_tour'] ?? ''; $hora = $_POST['horario'] ?? '';
    $cliente = (int)($_POST['cliente_id'] ?? 0);
    $novoNome = trim($_POST['novo_nome'] ?? '');
    $erroVolta = $volta . ($id ? '&editar=' . $id : '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) || !preg_match('/^\d{2}:\d{2}/', $hora) || (!$cliente && $novoNome === '')) {
        flash('Informe data, horário e cliente.', 'erro'); ir($erroVolta);
    }
    try {
        $arquivo = salvar_comprovante($_FILES['comprovante'] ?? null);
    } catch (RuntimeException $ex) {
        flash($ex->getMessage(), 'erro'); ir($erroVolta);
    }
    if (!$cliente) {
        if (crm_v7()) {
            [$tf, $e164] = normalizar_telefone($_POST['novo_telefone'] ?? '');
            $sx = sexo_por_nome($novoNome);
            $pdo->prepare("INSERT INTO clientes (nome, telefone, telefone_e164, email, origem, sexo, sexo_presumido) VALUES (?, ?, ?, ?, 'tour', ?, ?)")
                ->execute([$novoNome, $tf, $e164, vazio_null(mb_strtolower(trim($_POST['novo_email'] ?? ''))), $sx, $sx ? 1 : 0]);
        } elseif (crm_v6()) {
            [$tf, $e164] = normalizar_telefone($_POST['novo_telefone'] ?? '');
            $pdo->prepare("INSERT INTO clientes (nome, telefone, telefone_e164, email, origem) VALUES (?, ?, ?, ?, 'tour')")
                ->execute([$novoNome, $tf, $e164, vazio_null(mb_strtolower(trim($_POST['novo_email'] ?? '')))]);
        } else {
            $pdo->prepare('INSERT INTO clientes (nome, telefone, email) VALUES (?, ?, ?)')
                ->execute([$novoNome, vazio_null($_POST['novo_telefone'] ?? ''), vazio_null($_POST['novo_email'] ?? '')]);
        }
        $cliente = (int)$pdo->lastInsertId();
        registrar_log('cliente', $cliente, 'Inclusão', 'Cadastro rápido pelo lançamento de tour: ' . $novoNome);
    }
    $recebido = isset($_POST['recebido']) ? 1 : 0;
    $modal = ($_POST['modalidade'] ?? 'pix') === 'link' ? 'link' : 'pix';
    $guia = (int)($_POST['guia_id'] ?? 0) ?: null;
    $estorno = in_array($_POST['estorno'] ?? '', ['nenhum', 'solicitado', 'realizado', 'negado'], true) ? $_POST['estorno'] : 'nenhum';
    $d = [$cliente, $guia, $data, $hora, max(1, (int)($_POST['qtd'] ?? 1)), parse_valor($_POST['valor_pago'] ?? '0'),
          $modal, $recebido, $recebido ? (vazio_null($_POST['data_recebimento'] ?? '') ?: date('Y-m-d')) : null,
          vazio_null($_POST['comentario'] ?? ''), $estorno];
    $msg = 'Tour salvo.';
    if ($id) {
        $d[] = $id;
        $pdo->prepare('UPDATE tour_vendas SET cliente_id=?, guia_id=?, data_tour=?, horario=?, qtd=?, valor_pago=?, modalidade=?, recebido=?, data_recebimento=?, comentario=?, estorno=? WHERE id=?')->execute($d);
        // Só o Administrador corrige a presença pelo formulário.
        $pres = $_POST['presenca'] ?? '';
        if (eh_admin() && in_array($pres, ['pendente', 'presente', 'ausente'], true) && $pres !== $reg['presenca']) {
            $pdo->prepare('UPDATE tour_vendas SET presenca = ?, presenca_em = NOW(), presenca_por = ? WHERE id = ?')
                ->execute([$pres, (int)$GLOBALS['USUARIO']['id'], $id]);
        }
    } else {
        $d[] = (int)$GLOBALS['USUARIO']['id'];
        $pdo->prepare('INSERT INTO tour_vendas (cliente_id, guia_id, data_tour, horario, qtd, valor_pago, modalidade, recebido, data_recebimento, comentario, estorno, criado_por) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')->execute($d);
        $id = (int)$pdo->lastInsertId();
        registrar_log('tour', $id, 'Inclusão', 'Cliente: ' . valor_legivel('cliente_id', $cliente) . '; tour de ' . data_br($data) . ' ' . substr($hora, 0, 5) . '; ' . $d[4] . ' pessoa(s); ' . brl($d[5]) . ' via ' . valor_legivel('modalidade', $modal));
        if (enviar_email_tour($id)) { $msg .= ' E-mail de confirmação enviado ao cliente.'; registrar_log('tour', $id, 'E-mail de confirmação enviado'); }
    }
    if ($arquivo || isset($_POST['remover_comprovante'])) {
        $s = $pdo->prepare('SELECT comprovante FROM tour_vendas WHERE id = ?'); $s->execute([$id]);
        apagar_comprovante($s->fetchColumn());
        $pdo->prepare('UPDATE tour_vendas SET comprovante = ? WHERE id = ?')->execute([$arquivo, $id]);
        if (!$reg) registrar_log('tour', $id, 'Comprovante anexado');
    }
    if ($reg) registrar_alteracao('tour', 'tour_vendas', $id, $reg);
    // Tour muda estágio do cliente (visitante, recorrente, inativo): atualiza os grupos dele.
    grupos_recalcular_cliente($cliente);
    if ($reg && (int)$reg['cliente_id'] !== (int)$cliente) grupos_recalcular_cliente((int)$reg['cliente_id']);
    flash($msg);
    ir('tours.php?mes=' . substr($data, 0, 7));
}

$st = $pdo->prepare("SELECT v.*, c.nome, c.telefone, c.cpf, c.email, g.nome guia FROM tour_vendas v
    JOIN clientes c ON c.id = v.cliente_id LEFT JOIN guias g ON g.id = v.guia_id
    WHERE v.data_tour BETWEEN ? AND ? ORDER BY v.data_tour, v.horario, c.nome");
$st->execute([$ini, $fim]);
$vendas = $st->fetchAll();

if (isset($_GET['exportar'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="tours-' . $mes . '.csv"');
    $o = fopen('php://output', 'w');
    fwrite($o, "\xEF\xBB\xBF");
    fputcsv($o, ['Data', 'Horário', 'Cliente', 'CPF', 'Telefone', 'E-mail', 'Guia/Sommelier', 'Qtd', 'Valor pago', 'Modalidade', 'Recebido', 'Data recebimento', 'Comprovante', 'Presença', 'Estorno', 'Comentário'], ';');
    foreach ($vendas as $v) {
        fputcsv($o, [data_br($v['data_tour']), substr($v['horario'], 0, 5), $v['nome'], $v['cpf'], $v['telefone'], $v['email'], $v['guia'],
            $v['qtd'], number_format($v['valor_pago'], 2, ',', ''), strtoupper($v['modalidade']),
            $v['recebido'] ? 'Sim' : 'Não', data_br($v['data_recebimento']), $v['comprovante'] ? 'Sim' : 'Não',
            nome_presenca($v['presenca']), nome_estorno($v['estorno']), $v['comentario']], ';');
    }
    exit;
}

$ed = ['id'=>0,'cliente_id'=>0,'guia_id'=>0,'data_tour'=>'','horario'=>'','qtd'=>1,'valor_pago'=>'','modalidade'=>'pix','recebido'=>0,'data_recebimento'=>'','comentario'=>'','comprovante'=>'','presenca'=>'pendente','estorno'=>'nenhum'];
if (!empty($_GET['editar'])) $ed = linha('tour_vendas', (int)$_GET['editar']) ?: $ed;
// A tela abre para todos os perfis (para ver a trilha); os campos só ficam liberados para quem pode alterar.
$podeForm = $ed['id'] ? pode_alterar_tour($ed) : pode_editar();
// Só o cliente do tour em edição: a escolha de outro é feita pela busca (com milhares de clientes, a lista inteira deixava a tela pesada).
$cliAtual = $ed['cliente_id'] ? linha('clientes', $ed['cliente_id']) : null;
$sg = $pdo->prepare('SELECT id, nome, funcao FROM guias WHERE ativo = 1 OR id = ? ORDER BY nome'); $sg->execute([(int)$ed['guia_id']]);
$guias = $sg->fetchAll();
$r = resumo_periodo($ini, $fim);
$cont = ['presente' => 0, 'ausente' => 0, 'estorno' => 0];
foreach ($vendas as $v) {
    if (isset($cont[$v['presenca']])) $cont[$v['presenca']] += $v['qtd'];
    if ($v['estorno'] === 'solicitado') $cont['estorno']++;
}
$ant = date('Y-m', strtotime($ini . ' -1 month'));
$prox = date('Y-m', strtotime($ini . ' +1 month'));
$titulo = 'Tours vendidos';
require __DIR__ . '/inc/topo.php';
?>
<div class="filtro">
  <a class="btn sec" href="tours.php?mes=<?= $ant ?>">‹</a>
  <form method="get"><input type="month" name="mes" value="<?= e($mes) ?>" onchange="this.form.submit()"></form>
  <a class="btn sec" href="tours.php?mes=<?= $prox ?>">›</a>
  <a class="btn sec" href="relatorio.php?mes=<?= e($mes) ?>">Relatório por semana</a>
  <a class="btn sec" href="tours.php?mes=<?= e($mes) ?>&exportar=1">Exportar Excel (CSV)</a>
</div>

<div class="cards">
  <div class="card"><span>Pessoas</span><strong><?= (int)$r['pessoas'] ?></strong></div>
  <div class="card"><span>Vendido</span><strong><?= brl($r['total']) ?></strong></div>
  <div class="card"><span>Recebido</span><strong><?= brl($r['recebido']) ?></strong></div>
  <div class="card alerta"><span>A confirmar</span><strong><?= brl($r['pendente']) ?></strong></div>
  <div class="card"><span>Presentes</span><strong><?= $cont['presente'] ?></strong></div>
  <div class="card"><span>Ausentes</span><strong><?= $cont['ausente'] ?></strong></div>
  <div class="card alerta"><span>Estornos solicitados</span><strong><?= $cont['estorno'] ?></strong></div>
</div>

<?php if (pode_editar() || $ed['id']): ?>
<details class="cartao" <?= $ed['id'] ? 'open' : '' ?>>
  <summary><?= !$ed['id'] ? 'Registrar tour vendido' : ($podeForm ? 'Editar tour vendido' : 'Tour vendido (somente leitura)') ?></summary>
  <?php if ($ed['id'] && !$podeForm && pode_editar()): ?><div class="aviso erro">Presença já registrada: só o Administrador pode alterar este tour.</div><?php endif; ?>
  <form method="post" action="tours.php?mes=<?= e($mes) ?>" enctype="multipart/form-data">
  <fieldset class="grade" <?= $podeForm ? '' : 'disabled' ?>>
    <?= csrf_campo() ?>
    <input type="hidden" name="id" value="<?= (int)$ed['id'] ?>">
    <label>Data do tour*<input type="date" name="data_tour" required value="<?= e($ed['data_tour']) ?>"></label>
    <label>Horário*<input type="time" name="horario" required value="<?= e(substr((string)$ed['horario'], 0, 5)) ?>"></label>
    <label class="l2">Guia / Sommelier responsável
      <select name="guia_id">
        <option value="0">— não definido —</option>
        <?php foreach ($guias as $g): ?>
          <option value="<?= (int)$g['id'] ?>" <?= $g['id'] == $ed['guia_id'] ? 'selected' : '' ?>><?= e($g['nome'] . ' · ' . nome_funcao($g['funcao'])) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <div class="l4 busca-cli">
      <label for="cli-busca">Cliente</label>
      <input type="hidden" name="cliente_id" id="cli-id" value="<?= (int)$ed['cliente_id'] ?>">
      <input type="search" id="cli-busca" autocomplete="off" placeholder="Digite nome, telefone ou CPF (mín. 2 letras)" value="<?= $cliAtual ? e($cliAtual['nome'] . ($cliAtual['telefone'] ? ' · ' . $cliAtual['telefone'] : '')) : '' ?>">
      <div id="cli-res" class="autocomplete" hidden></div>
      <span class="sub" id="cli-dica"><?= $cliAtual ? 'Cliente escolhido.' : 'Cliente novo? Deixe a busca em branco e preencha os campos "Novo cliente" abaixo.' ?></span>
    </div>
    <label class="l2">Novo cliente: nome<input name="novo_nome"></label>
    <label>Novo cliente: telefone<input name="novo_telefone"></label>
    <label>Novo cliente: e-mail<input type="email" name="novo_email"></label>
    <label>Qtd. pessoas<input type="number" name="qtd" min="1" value="<?= (int)$ed['qtd'] ?>"></label>
    <label>Valor pago (R$)<input name="valor_pago" inputmode="decimal" placeholder="0,00" value="<?= $ed['valor_pago'] !== '' ? e(number_format((float)$ed['valor_pago'], 2, ',', '')) : '' ?>"></label>
    <label>Modalidade
      <select name="modalidade">
        <option value="pix" <?= $ed['modalidade'] === 'pix' ? 'selected' : '' ?>>Pix</option>
        <option value="link" <?= $ed['modalidade'] === 'link' ? 'selected' : '' ?>>Link de pagamento</option>
      </select>
    </label>
    <label>Data do recebimento<input type="date" name="data_recebimento" value="<?= e($ed['data_recebimento']) ?>"></label>
    <label class="l2">Comprovante de pagamento (PDF ou imagem, até 5 MB)
      <input type="file" name="comprovante" accept=".pdf,image/jpeg,image/png,image/webp">
    </label>
    <label class="check l2"><input type="checkbox" name="recebido" <?= $ed['recebido'] ? 'checked' : '' ?>> Recebimento confirmado</label>
    <?php if ($ed['comprovante']): ?>
      <label class="check l2"><input type="checkbox" name="remover_comprovante"> Remover comprovante atual</label>
    <?php endif; ?>
    <label>Estorno
      <select name="estorno">
        <?php foreach (['nenhum', 'solicitado', 'realizado', 'negado'] as $x): ?>
          <option value="<?= $x ?>" <?= $ed['estorno'] === $x ? 'selected' : '' ?>><?= nome_estorno($x) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <?php if ($ed['id']): ?>
    <label>Presença<?= eh_admin() ? '' : ' (use os botões da lista)' ?>
      <select name="presenca" <?= eh_admin() ? '' : 'disabled' ?>>
        <?php foreach (['pendente', 'presente', 'ausente'] as $x): ?>
          <option value="<?= $x ?>" <?= $ed['presenca'] === $x ? 'selected' : '' ?>><?= nome_presenca($x) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <?php endif; ?>
    <label class="l4">Comentário<input name="comentario" maxlength="255" placeholder="ex.: 1 sem beber" value="<?= e($ed['comentario']) ?>"></label>
    <?php if ($podeForm): ?><div class="l4 acoes"><button type="submit">Salvar</button></div><?php endif; ?>
  </fieldset>
  </form>
  <?php if ($ed['id']): ?>
    <p class="acoes">
      <?php if ($ed['comprovante']): ?><a class="btn peq sec" href="comprovante.php?id=<?= (int)$ed['id'] ?>" target="_blank">Ver comprovante</a><?php endif; ?>
      <a class="btn peq sec" href="tours.php?mes=<?= e($mes) ?>">Fechar</a>
    </p>
    <?php mostrar_trilha('tour', $ed['id']); ?>
  <?php endif; ?>
</details>
<script>
(function () {
  var busca = document.getElementById('cli-busca'); if (!busca) return;
  var id = document.getElementById('cli-id'), res = document.getElementById('cli-res'), dica = document.getElementById('cli-dica'), t = null, ultimo = '';
  function limpar() { res.hidden = true; res.innerHTML = ''; }
  busca.addEventListener('input', function () {
    id.value = 0; dica.textContent = 'Escolha um cliente da lista, ou deixe em branco para cadastrar um novo abaixo.';
    clearTimeout(t); var q = busca.value.trim();
    if (q.length < 2) { limpar(); return; }
    t = setTimeout(function () {
      ultimo = q;
      fetch('clientes_busca.php?q=' + encodeURIComponent(q), {credentials: 'same-origin'}).then(function (r) { return r.json(); }).then(function (lista) {
        if (q !== ultimo) return;
        res.innerHTML = '';
        if (!lista.length) { res.innerHTML = '<div class="vazio">Nenhum cliente encontrado.</div>'; res.hidden = false; return; }
        lista.forEach(function (c) {
          var b = document.createElement('button'); b.type = 'button';
          b.textContent = c.nome + (c.telefone ? ' · ' + c.telefone : '');
          b.addEventListener('click', function () { id.value = c.id; busca.value = b.textContent; dica.textContent = 'Cliente escolhido.'; limpar(); });
          res.appendChild(b);
        });
        res.hidden = false;
      }).catch(limpar);
    }, 250);
  });
  document.addEventListener('click', function (e) { if (!res.contains(e.target) && e.target !== busca) limpar(); });
})();
</script>

<?php endif; ?>

<?php
$grupo = null;
foreach ($vendas as $v):
    $chave = $v['data_tour'] . ' ' . $v['horario'];
    $alt = pode_alterar_tour($v);
    if ($chave !== $grupo):
        if ($grupo !== null) echo '</tbody></table></div>';
        $grupo = $chave;
        $pessoas = 0;
        foreach ($vendas as $x) if ($x['data_tour'] . ' ' . $x['horario'] === $chave) $pessoas += $x['qtd'];
?>
<h2><?= data_br($v['data_tour']) ?> · <?= dia_semana($v['data_tour']) ?> · <?= e(substr($v['horario'], 0, 5)) ?>h <small><?= (int)$pessoas ?> pessoa(s)</small></h2>
<div class="cartao rolagem"><table>
  <thead><tr><th>Cliente</th><th>Contato</th><th>Qtd</th><th>Valor</th><th>Modalidade</th><th>Pagamento</th><th>Presença</th><th>Guia</th><th>Comentário</th><th></th></tr></thead>
  <tbody>
<?php endif; ?>
    <tr>
      <td><?= e($v['nome']) ?></td>
      <td><?= e($v['telefone']) ?></td>
      <td><?= (int)$v['qtd'] ?></td>
      <td><?= brl($v['valor_pago']) ?></td>
      <td><?= $v['modalidade'] === 'pix' ? 'Pix' : 'Link' ?></td>
      <td>
        <?php if ($v['recebido']): ?><span class="tag ok">Recebido <?= data_br($v['data_recebimento']) ?></span><?php else: ?><span class="tag pend">Pendente</span><?php endif; ?>
        <?php if ($v['comprovante']): ?> <a href="comprovante.php?id=<?= (int)$v['id'] ?>" target="_blank">comprovante</a><?php endif; ?>
        <?php if ($v['estorno'] !== 'nenhum'): ?> <span class="tag <?= $v['estorno'] === 'solicitado' ? 'pend' : 'neutra' ?>"><?= e(nome_estorno($v['estorno'])) ?></span><?php endif; ?>
      </td>
      <td><span class="tag <?= $v['presenca'] === 'presente' ? 'ok' : ($v['presenca'] === 'ausente' ? 'pend' : 'neutra') ?>"><?= e(nome_presenca($v['presenca'])) ?></span></td>
      <td><?= e($v['guia'] ?: '—') ?></td>
      <td><?= e($v['comentario']) ?></td>
      <td class="acoes">
        <form method="post" action="tours.php?mes=<?= e($mes) ?>">
          <?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
          <?php if ($alt): ?>
            <?php if ($v['presenca'] === 'pendente'): ?>
              <button class="peq" name="acao" value="presente" onclick="return confirm('Confirmar a presença? Depois disso só o Administrador altera este tour.')">Presente</button>
              <button class="peq sec" name="acao" value="ausente" onclick="return confirm('Registrar ausência? Depois disso só o Administrador altera este tour.')">Ausente</button>
            <?php endif; ?>
            <?php if (!$v['recebido']): ?><button class="peq" name="acao" value="receber">Confirmar pgto.</button><?php endif; ?>
            <?php if ($v['email']): ?><button class="peq sec" name="acao" value="email" title="<?= $v['email_enviado_em'] ? 'Enviado em ' . date('d/m/Y H:i', strtotime($v['email_enviado_em'])) : 'Ainda não enviado' ?>"><?= $v['email_enviado_em'] ? 'Reenviar e-mail' : 'Enviar e-mail' ?></button><?php endif; ?>
          <?php endif; ?>
          <a class="btn peq sec" href="tours.php?mes=<?= e($mes) ?>&editar=<?= (int)$v['id'] ?>"><?= $alt ? 'Editar' : 'Histórico' ?></a>
          <?php if (eh_admin()): ?><button class="peq perigo" name="acao" value="excluir" onclick="return confirm('Excluir este registro?')">Excluir</button><?php endif; ?>
        </form>
      </td>
    </tr>
<?php endforeach;
if ($grupo !== null) echo '</tbody></table></div>';
else echo '<div class="cartao vazio">Nenhum tour registrado neste mês.</div>';
require __DIR__ . '/inc/rodape.php'; ?>
