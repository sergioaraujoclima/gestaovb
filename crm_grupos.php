<?php
require __DIR__ . '/inc/app.php';
exigir_login();
exigir_crm();
crm_exigir_migracao();
@set_time_limit(120);
$pdo = db();
$campos = grupos_campos();
$etqTodas = etiquetas_todas();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $acao = $_POST['acao'] ?? '';
    $gid = (int)($_POST['grupo_id'] ?? 0);
    $g = $gid ? $pdo->query('SELECT * FROM crm_grupos WHERE id = ' . $gid)->fetch() : null;
    $volta = 'crm_grupos.php' . ($gid ? '?ver=' . $gid : '');

    if (in_array($acao, ['membro_remover', 'membro_reincluir', 'membro_incluir'], true)) {
        if (!pode_editar_clientes()) negar();
        $cid = (int)($_POST['cliente_id'] ?? 0);
        $cli = linha('clientes', $cid);
        if (!$g || !$cli) { flash('Registro não encontrado.', 'erro'); ir('crm_grupos.php'); }
        if ($acao === 'membro_incluir') { grupos_incluir_manual($gid, $cid); registrar_log('cliente', $cid, 'Grupo: inclusão manual', $g['nome']); flash($cli['nome'] . ' incluído(a) em ' . $g['nome'] . '.'); }
        if ($acao === 'membro_remover') { grupos_remover_manual($gid, $cid); registrar_log('cliente', $cid, 'Grupo: remoção manual', $g['nome']); flash($cli['nome'] . ' removido(a) de ' . $g['nome'] . ($g['tipo'] === 'dinamico' ? ' (fica fora mesmo atendendo à regra, até ser reincluído)' : '') . '.'); }
        if ($acao === 'membro_reincluir') { grupos_reincluir($gid, $cid); registrar_log('cliente', $cid, 'Grupo: exclusão desfeita', $g['nome']); flash($cli['nome'] . ' volta a seguir a regra de ' . $g['nome'] . '.'); }
        ir($_POST['volta'] ?? $volta);
    }
    if (!pode_gerir_grupos()) negar();
    if ($acao === 'recalcular' && $g) {
        [$e, $s] = grupos_recalcular($g);
        registrar_log('grupo', $gid, 'Recalculado', "$e entraram, $s saíram");
        flash("Grupo recalculado: $e entraram, $s saíram."); ir($volta);
    }
    if ($acao === 'excluir_grupo' && $g) {
        exigir_admin();
        $pdo->prepare('DELETE FROM crm_grupo_membros WHERE grupo_id = ?')->execute([$gid]);
        $pdo->prepare('DELETE FROM crm_grupos WHERE id = ?')->execute([$gid]);
        registrar_log('grupo', $gid, 'Exclusão', $g['nome']);
        flash('Grupo excluído. Os clientes continuam cadastrados.'); ir('crm_grupos.php');
    }
    if ($acao === 'salvar' || $acao === 'previa') {
        $nome = mb_substr(texto_limpo($_POST['nome'] ?? ''), 0, 80);
        $tipo = ($_POST['tipo'] ?? '') === 'manual' ? 'manual' : 'dinamico';
        $comb = ($_POST['combinacao'] ?? '') === 'qualquer' ? 'qualquer' : 'todas';
        $regras = $tipo === 'dinamico' ? grupos_regras_do_post($_POST) : [];
        $form = ['id' => $gid, 'nome' => $nome, 'descricao' => mb_substr(texto_limpo($_POST['descricao'] ?? ''), 0, 255), 'tipo' => $tipo, 'combinacao' => $comb,
                 'regras' => json_encode($regras, JSON_UNESCAPED_UNICODE), 'ativo' => isset($_POST['ativo']) ? 1 : 0, 'ordem' => (int)($_POST['ordem'] ?? 100)];
        $erro = '';
        if ($nome === '') $erro = 'Informe o nome do grupo.';
        elseif ($tipo === 'dinamico' && !$regras) $erro = 'Um grupo automático precisa de pelo menos uma regra completa.';
        else { $s = $pdo->prepare('SELECT id FROM crm_grupos WHERE nome = ? AND id <> ?'); $s->execute([$nome, $gid]); if ($s->fetchColumn()) $erro = 'Já existe um grupo com esse nome.'; }
        if ($acao === 'previa' || $erro) {
            $previa = null;
            if (!$erro && $tipo === 'dinamico') { $ctx = grupos_contexto(); $previa = array_values(array_filter($ctx, fn($c) => grupos_atende($form, $c))); }
            // cai na tela do formulário com a prévia (sem gravar)
        } else {
            if ($gid) {
                $pdo->prepare('UPDATE crm_grupos SET nome=?, descricao=?, tipo=?, combinacao=?, regras=?, ativo=?, ordem=? WHERE id=?')
                    ->execute([$form['nome'], $form['descricao'], $tipo, $comb, $form['regras'], $form['ativo'], $form['ordem'], $gid]);
                if ($tipo === 'manual') $pdo->prepare("DELETE FROM crm_grupo_membros WHERE grupo_id = ? AND origem <> 'manual'")->execute([$gid]);
                registrar_log('grupo', $gid, 'Alteração', $nome . ' · ' . grupos_resumo($form) . ($form['ativo'] ? '' : ' · inativo'));
            } else {
                $pdo->prepare('INSERT INTO crm_grupos (nome, descricao, tipo, combinacao, regras, ativo, ordem, criado_por) VALUES (?,?,?,?,?,?,?,?)')
                    ->execute([$form['nome'], $form['descricao'], $tipo, $comb, $form['regras'], $form['ativo'], $form['ordem'], $GLOBALS['USUARIO']['nome']]);
                $gid = (int)$pdo->lastInsertId();
                registrar_log('grupo', $gid, 'Inclusão', $nome . ' · ' . grupos_resumo($form));
            }
            $g = $pdo->query('SELECT * FROM crm_grupos WHERE id = ' . $gid)->fetch();
            [$e, $s] = grupos_recalcular($g);
            flash('Grupo salvo.' . ($tipo === 'dinamico' ? " $e cliente(s) entraram e $s saíram pela regra." : ''));
            ir('crm_grupos.php?ver=' . $gid);
        }
        if ($erro) flash($erro, 'erro');
    }
}

// ---------- Exportação dos membros (para a campanha) ----------
if (isset($_GET['exportar'], $_GET['ver'])) {
    if (!pode_gerir_grupos()) negar();
    $gid = (int)$_GET['ver']; $canal = $_GET['exportar'];
    $g = $pdo->query('SELECT * FROM crm_grupos WHERE id = ' . $gid)->fetch();
    if (!$g) ir('crm_grupos.php');
    $s = $pdo->prepare("SELECT c.* FROM crm_grupo_membros m JOIN clientes c ON c.id = m.cliente_id WHERE m.grupo_id = ? AND m.origem <> 'excluido' ORDER BY c.nome");
    $s->execute([$gid]);
    $rows = array_filter($s->fetchAll(), function ($c) use ($canal) {
        if ($canal === 'whatsapp') return e_celular($c['telefone_e164']) && !recusou($c['aceita_whatsapp']);
        if ($canal === 'email') return filter_var((string)$c['email'], FILTER_VALIDATE_EMAIL) && !recusou($c['aceita_email']);
        return true;
    });
    registrar_log('grupo', $gid, 'Exportação', count($rows) . ' cliente(s) · canal: ' . $canal);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="grupo-' . preg_replace('/[^a-z0-9]+/', '-', chave_nome($g['nome'])) . '-' . $canal . '-' . date('Y-m-d') . '.csv"');
    $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF");
    fputcsv($o, ['Código', 'Nome', 'Primeiro nome', 'Tipo', 'Sexo', 'WhatsApp', 'Telefone', 'E-mail', 'Cidade', 'UF', 'Aniversário', 'Aceita WhatsApp', 'Aceita e-mail', 'Clube'], ';', '"', '');
    foreach ($rows as $c) fputcsv($o, [$c['id'], $c['nome'], $c['tipo_pessoa'] === 'PF' ? explode(' ', $c['nome'])[0] : $c['nome'], $c['tipo_pessoa'], $c['sexo'] ? nome_sexo($c['sexo']) . ($c['sexo_presumido'] ? ' (presumido)' : '') : '',
        e_celular($c['telefone_e164']) ? ltrim($c['telefone_e164'], '+') : '', $c['telefone'], $c['email'], $c['cidade'], $c['uf'], $c['data_nascimento'] ? date('d/m', strtotime($c['data_nascimento'])) : '',
        nome_aceite($c['aceita_whatsapp']), nome_aceite($c['aceita_email']), $c['clube_beneficios'] ? 'Sim' : 'Não'], ';', '"', '');
    exit;
}

grupos_recalcular_se_velho();
$grupos = grupos_todos();
$cont = grupos_contagens();
$ver = isset($_GET['ver']) ? $pdo->query('SELECT * FROM crm_grupos WHERE id = ' . (int)$_GET['ver'])->fetch() : null;
if (!isset($form)) {
    $form = null;
    if (isset($_GET['novo']) && pode_gerir_grupos()) $form = ['id' => 0, 'nome' => '', 'descricao' => '', 'tipo' => 'dinamico', 'combinacao' => 'todas', 'regras' => '[]', 'ativo' => 1, 'ordem' => 100];
    if (!empty($_GET['editar']) && pode_gerir_grupos()) $form = $pdo->query('SELECT * FROM crm_grupos WHERE id = ' . (int)$_GET['editar'])->fetch() ?: null;
}
$titulo = 'Grupos de campanha';
require __DIR__ . '/inc/topo.php';
?>
<?php if ($form !== null): $regras = json_decode((string)$form['regras'], true) ?: []; while (count($regras) < 3) $regras[] = ['campo' => '', 'op' => '', 'valor' => '']; ?>
<div class="cartao">
  <div class="ficha-topo"><strong><?= $form['id'] ? 'Editar grupo' : 'Novo grupo' ?></strong><a class="btn peq sec" href="crm_grupos.php<?= $form['id'] ? '?ver=' . (int)$form['id'] : '' ?>">Fechar</a></div>
  <form method="post" id="form-grupo">
    <?= csrf_campo() ?><input type="hidden" name="grupo_id" value="<?= (int)$form['id'] ?>">
    <div class="grade">
      <label class="l2">Nome*<input name="nome" required maxlength="80" value="<?= e($form['nome']) ?>" placeholder="ex.: Dia dos Namorados"></label>
      <label>Tipo
        <select name="tipo" id="g-tipo">
          <option value="dinamico" <?= $form['tipo'] === 'dinamico' ? 'selected' : '' ?>>Automático (por regras)</option>
          <option value="manual" <?= $form['tipo'] === 'manual' ? 'selected' : '' ?>>Manual (lista escolhida à mão)</option>
        </select>
      </label>
      <label>Ordem na lista<input type="number" name="ordem" value="<?= (int)$form['ordem'] ?>"></label>
      <label class="l3">Descrição<input name="descricao" maxlength="255" value="<?= e($form['descricao']) ?>" placeholder="para que serve este grupo"></label>
      <label class="check"><input type="checkbox" name="ativo" <?= $form['ativo'] ? 'checked' : '' ?>> Ativo</label>
    </div>
    <div id="g-regras" class="regras">
      <p class="sub">Entra no grupo quem atender a
        <select name="combinacao"><option value="todas" <?= $form['combinacao'] !== 'qualquer' ? 'selected' : '' ?>>todas as regras</option><option value="qualquer" <?= $form['combinacao'] === 'qualquer' ? 'selected' : '' ?>>qualquer uma das regras</option></select>
        abaixo. Linhas em branco são ignoradas.</p>
      <?php foreach ($regras as $i => $r): ?>
      <div class="regra">
        <select name="campo[]" class="r-campo"><option value="">— campo —</option><?php foreach ($campos as $k => $d): ?><option value="<?= $k ?>" <?= $r['campo'] === $k ? 'selected' : '' ?>><?= e($d['rot']) ?></option><?php endforeach; ?></select>
        <select name="op[]" class="r-op" data-v="<?= e($r['op']) ?>"></select>
        <span class="r-valor" data-v="<?= e($r['valor']) ?>"></span>
      </div>
      <?php endforeach; ?>
      <button type="button" class="peq sec" id="g-mais">+ regra</button>
    </div>
    <div class="acoes" style="margin-top:14px">
      <button name="acao" value="salvar">Salvar grupo</button>
      <button name="acao" value="previa" class="sec" id="g-previa">Ver prévia (sem salvar)</button>
    </div>
  </form>
  <?php if (isset($previa) && $previa !== null): ?>
    <h2>Prévia <small><?= count($previa) ?> cliente(s) atendem às regras hoje</small></h2>
    <p class="sub"><?= e(implode(', ', array_map(fn($c) => $c['nome'], array_slice($previa, 0, 30)))) ?><?= count($previa) > 30 ? '…' : '' ?></p>
  <?php endif; ?>
</div>
<script>
(function () {
  var C = <?= json_encode(array_map(fn($d) => ['tipo' => $d['tipo'], 'ops' => $d['ops'], 'opcoes' => $d['opcoes'] ?? null], $campos), JSON_UNESCAPED_UNICODE) ?>;
  var OPS = <?= json_encode(grupos_ops(), JSON_UNESCAPED_UNICODE) ?>;
  var ETQ = <?= json_encode(array_map(fn($e) => [(string)$e['id'], $e['nome']], $etqTodas), JSON_UNESCAPED_UNICODE) ?>;
  function el(t, a) { var x = document.createElement(t); for (var k in a) x.setAttribute(k, a[k]); return x; }
  function montar(linha, manterValor) {
    var campo = linha.querySelector('.r-campo').value, op = linha.querySelector('.r-op'), box = linha.querySelector('.r-valor');
    var vOp = op.value || op.dataset.v, vVal = manterValor ? box.dataset.v : '';
    var inp = box.querySelector('input,select'); if (inp && !manterValor) vVal = '';
    op.innerHTML = ''; box.innerHTML = '';
    if (!campo) { op.style.visibility = 'hidden'; return; }
    op.style.visibility = '';
    var d = C[campo];
    d.ops.forEach(function (o) { var x = el('option', {value: o}); x.textContent = OPS[o]; if (o === vOp) x.selected = true; op.appendChild(x); });
    var ctl;
    if (d.tipo === 'enum' || d.tipo === 'etiqueta') {
      ctl = el('select', {name: 'valor[]'});
      var pares = d.tipo === 'etiqueta' ? ETQ : Object.keys(d.opcoes).map(function (k) { return [k, d.opcoes[k]]; });
      pares.forEach(function (p) { var x = el('option', {value: p[0]}); x.textContent = p[1]; if (String(p[0]) === String(vVal)) x.selected = true; ctl.appendChild(x); });
    } else {
      ctl = el('input', {name: 'valor[]', value: vVal || '', type: d.tipo === 'num' ? 'number' : 'text', step: 'any'});
    }
    box.appendChild(ctl);
  }
  var lista = document.getElementById('g-regras');
  function ligar(linha) { linha.querySelector('.r-campo').addEventListener('change', function () { montar(linha, false); }); montar(linha, true); }
  lista.querySelectorAll('.regra').forEach(ligar);
  document.getElementById('g-mais').addEventListener('click', function () {
    var base = lista.querySelector('.regra'), nova = base.cloneNode(true);
    nova.querySelector('.r-campo').value = ''; nova.querySelector('.r-op').dataset.v = ''; nova.querySelector('.r-valor').dataset.v = '';
    lista.insertBefore(nova, this); ligar(nova);
  });
  // Linhas sem campo não enviam valor (mantém os índices alinhados).
  document.getElementById('form-grupo').addEventListener('submit', function () {
    lista.querySelectorAll('.regra').forEach(function (l) { if (!l.querySelector('.r-campo').value) { var b = l.querySelector('.r-valor'); b.innerHTML = '<input type="hidden" name="valor[]" value="">'; var o = l.querySelector('.r-op'); o.innerHTML = '<option value=""></option>'; } });
  });
  var tipo = document.getElementById('g-tipo');
  function vis() { lista.style.display = tipo.value === 'manual' ? 'none' : ''; document.getElementById('g-previa').style.display = tipo.value === 'manual' ? 'none' : ''; }
  tipo.addEventListener('change', vis); vis();
})();
</script>

<?php elseif ($ver): ?>
<?php
$q = trim($_GET['q'] ?? '');
$fo = in_array($_GET['origem'] ?? '', ['regra', 'manual', 'excluido'], true) ? $_GET['origem'] : '';
$w = 'm.grupo_id = ?'; $par = [$ver['id']];
if ($fo) { $w .= ' AND m.origem = ?'; $par[] = $fo; } else $w .= " AND m.origem <> 'excluido'";
if ($q !== '') { $w .= ' AND (c.nome LIKE ? OR c.email LIKE ? OR c.telefone LIKE ?)'; array_push($par, "%$q%", "%$q%", "%$q%"); }
$s = $pdo->prepare("SELECT COUNT(*) FROM crm_grupo_membros m JOIN clientes c ON c.id = m.cliente_id WHERE $w"); $s->execute($par); $total = (int)$s->fetchColumn();
$porPag = 50; $pag = max(1, min((int)($_GET['p'] ?? 1), max(1, (int)ceil($total / $porPag))));
$s = $pdo->prepare("SELECT c.*, m.origem, m.incluido_em, m.incluido_por FROM crm_grupo_membros m JOIN clientes c ON c.id = m.cliente_id WHERE $w ORDER BY c.nome LIMIT $porPag OFFSET " . (($pag - 1) * $porPag));
$s->execute($par); $membros = $s->fetchAll();
$nExcl = (int)$pdo->query("SELECT COUNT(*) FROM crm_grupo_membros WHERE grupo_id = " . (int)$ver['id'] . " AND origem = 'excluido'")->fetchColumn();
$k = $cont[$ver['id']] ?? ['total' => 0, 'celular' => 0, 'optin_wa' => 0, 'recusa_wa' => 0, 'email' => 0, 'optin_email' => 0];
$candidatos = [];
if (pode_editar_clientes() && ($b = trim($_GET['buscar'] ?? '')) !== '') {
    $s = $pdo->prepare("SELECT id, nome, telefone FROM clientes WHERE (nome LIKE ? OR telefone LIKE ? OR email LIKE ?) AND id NOT IN (SELECT cliente_id FROM crm_grupo_membros WHERE grupo_id = ? AND origem <> 'excluido') ORDER BY nome LIMIT 20");
    $s->execute(["%$b%", "%$b%", "%$b%", $ver['id']]); $candidatos = $s->fetchAll();
}
$qsBase = 'ver=' . (int)$ver['id'];
?>
<div class="ficha-topo">
  <strong><?= e($ver['nome']) ?></strong>
  <span class="tag neutra"><?= $ver['tipo'] === 'dinamico' ? 'Automático' : 'Manual' ?></span><?php if (!$ver['ativo']): ?><span class="tag pend">Inativo</span><?php endif; ?>
  <a class="btn peq sec" href="crm_grupos.php">Todos os grupos</a>
</div>
<p class="sub"><?= e($ver['descricao']) ?><?= $ver['descricao'] ? '<br>' : '' ?><strong>Regra:</strong> <?= e(grupos_resumo($ver)) ?><?= $ver['recalculado_em'] ? ' · atualizado em ' . date('d/m/Y H:i', strtotime($ver['recalculado_em'])) : '' ?></p>
<div class="cards">
  <div class="card"><span>Membros</span><strong><?= (int)$k['total'] ?></strong></div>
  <div class="card"><span>Com celular</span><strong><?= (int)$k['celular'] ?></strong></div>
  <div class="card"><span>Aceitaram WhatsApp</span><strong><?= (int)$k['optin_wa'] ?></strong></div>
  <div class="card"><span>Com e-mail</span><strong><?= (int)$k['email'] ?></strong></div>
  <div class="card alerta"><span>Recusaram WhatsApp</span><strong><?= (int)$k['recusa_wa'] ?></strong></div>
</div>
<div class="barra-lista">
  <?php if (pode_gerir_grupos()): ?>
    <a class="btn peq" href="crm_grupos.php?editar=<?= (int)$ver['id'] ?>">Editar grupo</a>
    <a class="btn peq sec" href="crm_grupos.php?<?= $qsBase ?>&exportar=whatsapp">Exportar para WhatsApp</a>
    <a class="btn peq sec" href="crm_grupos.php?<?= $qsBase ?>&exportar=email">Exportar para e-mail</a>
    <a class="btn peq sec" href="crm_grupos.php?<?= $qsBase ?>&exportar=todos">Exportar todos</a>
    <?php if ($ver['tipo'] === 'dinamico'): ?><form method="post"><?= csrf_campo() ?><input type="hidden" name="grupo_id" value="<?= (int)$ver['id'] ?>"><button class="peq sec" name="acao" value="recalcular">Recalcular agora</button></form><?php endif; ?>
  <?php endif; ?>
  <a class="btn peq sec" href="clientes.php?grupo=<?= (int)$ver['id'] ?>">Abrir na lista de clientes</a>
</div>
<p class="sub">As exportações por canal já tiram quem recusou aquele canal e quem não tem celular ou e-mail válido.</p>

<form method="get" class="filtro">
  <input type="hidden" name="ver" value="<?= (int)$ver['id'] ?>">
  <input name="q" placeholder="Buscar membro" value="<?= e($q) ?>">
  <select name="origem" style="width:auto"><option value="">Membros atuais</option><option value="regra" <?= $fo === 'regra' ? 'selected' : '' ?>>Entraram pela regra</option><option value="manual" <?= $fo === 'manual' ? 'selected' : '' ?>>Incluídos à mão</option><option value="excluido" <?= $fo === 'excluido' ? 'selected' : '' ?>>Excluídos à mão (<?= $nExcl ?>)</option></select>
  <button>Filtrar</button>
</form>

<?php if (pode_editar_clientes()): ?>
<details class="cartao" <?= $candidatos || isset($_GET['buscar']) ? 'open' : '' ?>>
  <summary>Incluir cliente à mão</summary>
  <form method="get" class="filtro"><input type="hidden" name="ver" value="<?= (int)$ver['id'] ?>"><input name="buscar" placeholder="Nome, telefone ou e-mail" value="<?= e($_GET['buscar'] ?? '') ?>"><button>Buscar</button></form>
  <?php foreach ($candidatos as $c): ?>
    <form method="post" class="acoes linha-cand"><?= csrf_campo() ?><input type="hidden" name="grupo_id" value="<?= (int)$ver['id'] ?>"><input type="hidden" name="cliente_id" value="<?= (int)$c['id'] ?>">
      <span><?= e($c['nome']) ?> <span class="sub"><?= e($c['telefone']) ?></span></span><button class="peq" name="acao" value="membro_incluir">Incluir</button></form>
  <?php endforeach; if (isset($_GET['buscar']) && !$candidatos): ?><p class="sub">Ninguém encontrado fora do grupo.</p><?php endif; ?>
</details>
<?php endif; ?>

<div class="cartao rolagem"><table>
  <thead><tr><th>Nome</th><th>Telefone</th><th>E-mail</th><th>Sexo</th><th>UF</th><th>Como entrou</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($membros as $c): ?>
    <tr>
      <td class="nome"><a href="clientes.php?editar=<?= (int)$c['id'] ?>"><?= e($c['nome']) ?></a><?= $c['tipo_pessoa'] === 'PJ' ? ' <span class="tag neutra">PJ</span>' : '' ?></td>
      <td><?= e($c['telefone']) ?><?= recusou($c['aceita_whatsapp']) ? ' <span class="tag pend">recusou</span>' : '' ?></td>
      <td><?= e($c['email']) ?></td>
      <td><?= $c['sexo'] ? e($c['sexo']) . ($c['sexo_presumido'] ? ' <span class="sub" title="presumido pelo nome">(pres.)</span>' : '') : '—' ?></td>
      <td><?= e($c['uf']) ?></td>
      <td><?= ['regra' => 'Regra', 'manual' => 'À mão', 'excluido' => 'Excluído'][$c['origem']] ?> <span class="sub"><?= date('d/m/Y', strtotime($c['incluido_em'])) ?><?= $c['origem'] !== 'regra' ? ' · ' . e($c['incluido_por']) : '' ?></span></td>
      <td class="acoes">
        <?php if (pode_editar_clientes()): ?>
        <form method="post"><?= csrf_campo() ?><input type="hidden" name="grupo_id" value="<?= (int)$ver['id'] ?>"><input type="hidden" name="cliente_id" value="<?= (int)$c['id'] ?>">
          <?php if ($c['origem'] === 'excluido'): ?><button class="peq sec" name="acao" value="membro_reincluir">Reincluir</button>
          <?php else: ?><button class="peq perigo" name="acao" value="membro_remover" onclick="return confirm('Tirar <?= e(addslashes($c['nome'])) ?> deste grupo?')">Remover</button><?php endif; ?>
        </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; if (!$membros): ?><tr><td colspan="7" class="vazio">Nenhum membro.</td></tr><?php endif; ?>
  </tbody>
</table></div>
<?php if ($total > $porPag): $nP = (int)ceil($total / $porPag); $qs = $qsBase . ($q !== '' ? '&q=' . urlencode($q) : '') . ($fo ? '&origem=' . $fo : ''); ?>
<div class="paginas">
  <?php if ($pag > 1): ?><a class="btn peq sec" href="crm_grupos.php?<?= e($qs) ?>&p=<?= $pag - 1 ?>">‹ Anterior</a><?php endif; ?>
  <span>Página <?= $pag ?> de <?= $nP ?> · <?= $total ?> membro(s)</span>
  <?php if ($pag < $nP): ?><a class="btn peq sec" href="crm_grupos.php?<?= e($qs) ?>&p=<?= $pag + 1 ?>">Próxima ›</a><?php endif; ?>
</div>
<?php endif; ?>

<?php else: ?>
<p class="sub">Grupos automáticos se mantêm sozinhos: quem passa a atender à regra entra (por exemplo, ao cadastrar uma cliente com sexo feminino, ela entra em Dia das Mães) e quem deixa de atender sai. Grupos manuais são listas escolhidas à mão.</p>
<?php if (pode_gerir_grupos()): ?><p><a class="btn" href="crm_grupos.php?novo=1">Novo grupo</a></p><?php endif; ?>
<div class="cartao rolagem"><table>
  <thead><tr><th>Grupo</th><th>Regra</th><th class="num">Membros</th><th class="num">Com celular</th><th class="num">Aceitaram WhatsApp</th><th class="num">Com e-mail</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($grupos as $g): $k = $cont[$g['id']] ?? ['total' => 0, 'celular' => 0, 'optin_wa' => 0, 'email' => 0]; ?>
    <tr class="<?= $g['ativo'] ? '' : 'inativo' ?>">
      <td class="nome"><a href="crm_grupos.php?ver=<?= (int)$g['id'] ?>"><strong><?= e($g['nome']) ?></strong></a> <span class="tag neutra"><?= $g['tipo'] === 'dinamico' ? 'Automático' : 'Manual' ?></span><?= $g['ativo'] ? '' : ' <span class="tag pend">Inativo</span>' ?><br><span class="sub"><?= e($g['descricao']) ?></span></td>
      <td class="quebra"><?= e(grupos_resumo($g)) ?></td>
      <td class="num"><?= (int)$k['total'] ?></td>
      <td class="num"><?= (int)$k['celular'] ?></td>
      <td class="num"><?= (int)$k['optin_wa'] ?></td>
      <td class="num"><?= (int)$k['email'] ?></td>
      <td class="acoes">
        <a class="btn peq sec" href="crm_grupos.php?ver=<?= (int)$g['id'] ?>">Ver</a>
        <?php if (eh_admin()): ?>
        <form method="post" onsubmit="return confirm('Excluir o grupo <?= e(addslashes($g['nome'])) ?>? Os clientes continuam cadastrados.')"><?= csrf_campo() ?><input type="hidden" name="grupo_id" value="<?= (int)$g['id'] ?>"><button class="peq perigo" name="acao" value="excluir_grupo">Excluir</button></form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; if (!$grupos): ?><tr><td colspan="7" class="vazio">Nenhum grupo.</td></tr><?php endif; ?>
  </tbody>
</table></div>
<?php endif; ?>
<?php require __DIR__ . '/inc/rodape.php'; ?>
