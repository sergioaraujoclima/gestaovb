<?php
require __DIR__ . '/inc/app.php';
exigir_login();
if (!pode_importar_vendas()) negar();
vendas_exigir_migracao();
@set_time_limit(180);
$pdo = db();
$dir = __DIR__ . '/uploads/importacoes';
$tok = preg_match('/^[a-f0-9]{32}$/', $_REQUEST['t'] ?? '') ? $_REQUEST['t'] : '';
$arq = $tok ? "$dir/v$tok.dat" : '';
$nomeArq = $tok && is_file("$dir/v$tok.nome") ? (string)file_get_contents("$dir/v$tok.nome") : '';
// Arquivos enviados e não confirmados não ficam no servidor: apaga os com mais de 1 dia.
foreach (glob("$dir/*") ?: [] as $velho) if (filemtime($velho) < time() - 86400) @unlink($velho);
$limpar = function () use ($arq, $dir, $tok) { if ($arq) { @unlink($arq); @unlink("$dir/v$tok.nome"); } };

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $acao = $_POST['acao'] ?? '';
    if ($acao === 'enviar') {
        $f = $_FILES['arquivo'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) { flash('Escolha o arquivo do relatório.', 'erro'); ir('vendas_importar.php'); }
        if ($f['size'] > 15 * 1024 * 1024) { flash('Arquivo maior que 15 MB. Exporte um período menor.', 'erro'); ir('vendas_importar.php'); }
        if (!preg_match('/\.(xlsx|csv|txt)$/i', $f['name'])) { flash('Envie o relatório em .xlsx (ou .csv).', 'erro'); ir('vendas_importar.php'); }
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $tok = bin2hex(random_bytes(16));
        move_uploaded_file($f['tmp_name'], "$dir/v$tok.dat");
        file_put_contents("$dir/v$tok.nome", mb_substr(basename($f['name']), 0, 200));
        ir('vendas_importar.php?t=' . $tok);
    }
    if ($acao === 'cancelar') { $limpar(); flash('Importação cancelada. Nada foi gravado.'); ir('vendas_importar.php'); }
    if ($acao === 'confirmar') {
        if (!$arq || !is_file($arq)) { flash('Arquivo não encontrado. Envie novamente.', 'erro'); ir('vendas_importar.php'); }
        $dec = [];
        foreach ((array)($_POST['dec'] ?? []) as $k => $v) {
            $v = (string)$v;
            if ($v === 'busca') $v = (int)($_POST['busca_id'][$k] ?? 0) ? 'c:' . (int)$_POST['busca_id'][$k] : '';
            if (preg_match('/^(c:\d+|novo:PF|novo:PJ|nenhum)$/', $v)) $dec[(string)$k] = $v;
        }
        try {
            $dados = vendas_ler($arq, $nomeArq);
            $plano = vendas_planejar($dados);
            $lote = vendas_executar($dados, $plano, $dec, $nomeArq);
        } catch (Throwable $ex) {
            flash('A importação não foi gravada (nada mudou): ' . $ex->getMessage(), 'erro');
            ir('vendas_importar.php?t=' . $tok);
        }
        $limpar();
        $imp = $pdo->query('SELECT * FROM vendas_importacoes WHERE id = ' . (int)$lote)->fetch();
        flash('Importação de vendas #' . $lote . ' concluída: ' . $imp['vendas_novas'] . ' vendas novas, ' . $imp['vendas_atualizadas'] . ' atualizadas, '
            . $imp['vendas_iguais'] . ' já existiam; ' . $imp['clientes_novos'] . ' clientes e ' . $imp['produtos_novos'] . ' produtos cadastrados. Os grupos de campanha foram recalculados.');
        ir('vendas_painel.php?p=' . substr((string)$imp['periodo_fim'], 0, 7));
    }
    if ($acao === 'desfazer') {
        $r = vendas_desfazer((int)($_POST['lote'] ?? 0));
        if ($r === null) flash('Esta importação não pode ser desfeita.', 'erro');
        else flash('Importação desfeita: ' . $r[0] . ' venda(s) removida(s)' . ($r[1] ? '; ' . $r[1][0] . ' cliente(s) cadastrado(s) por ela removido(s)' . ($r[1][1] ? ', ' . $r[1][1] . ' mantido(s) por ter outras compras ou tours' : '') : '') . '.');
        ir('vendas_importar.php');
    }
}

$plano = null; $dados = null; $erro = '';
if ($arq) {
    if (!is_file($arq)) { flash('Arquivo não encontrado. Envie novamente.', 'erro'); ir('vendas_importar.php'); }
    try { $dados = vendas_ler($arq, $nomeArq); $plano = vendas_planejar($dados); }
    catch (Throwable $ex) { $erro = $ex->getMessage(); }
}
$lotes = $pdo->query('SELECT * FROM vendas_importacoes ORDER BY id DESC LIMIT 24')->fetchAll();
$titulo = 'Importar vendas';
require __DIR__ . '/inc/topo.php';
?>
<?php if (!$arq): ?>
<div class="cartao">
  <p>Envie o relatório <strong>Venda de itens</strong> exportado do ERP (<strong>.xlsx</strong>), de preferência um mês fechado. O sistema mostra uma prévia e só grava quando você confirmar.</p>
  <ul class="sub">
    <li>Cada documento (NF-e, NFC-e, Pedido de venda) vira uma venda com seus itens. Reenviar um período que já foi importado <strong>não duplica</strong>: vendas iguais são ignoradas e as que mudaram no ERP são atualizadas.</li>
    <li>O nome do cliente de cada venda é cruzado com o cadastro do CRM. O que não for encontrado você confere na prévia: vincular a um cliente existente, cadastrar como novo ou não vincular. A escolha fica lembrada para os próximos meses.</li>
    <li>Itens com CFOP de bonificação, brinde, amostra ou remessa (5910, 6910, 5911, 6911...) ficam registrados, mas <strong>fora do faturamento</strong>, do ticket médio e da contagem de compras.</li>
    <li>Produtos novos entram no cadastro de produtos com o tipo sugerido pela descrição (tinto, branco, espumante...), que pode ser corrigido depois.</li>
  </ul>
  <form method="post" enctype="multipart/form-data" class="acoes">
    <?= csrf_campo() ?>
    <input type="file" name="arquivo" accept=".xlsx,.csv,.txt" required>
    <button name="acao" value="enviar">Ver prévia</button>
  </form>
</div>

<h2>Importações anteriores</h2>
<div class="cartao rolagem"><table>
  <thead><tr><th>#</th><th>Data</th><th>Arquivo</th><th>Período</th><th>Por</th><th class="num">Novas</th><th class="num">Atualizadas</th><th class="num">Já existiam</th><th class="num">Clientes novos</th><th class="num">Valor</th><th>Situação</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($lotes as $l): ?>
    <tr>
      <td><?= (int)$l['id'] ?></td>
      <td><?= date('d/m/Y H:i', strtotime($l['criado_em'])) ?></td>
      <td><?= e($l['arquivo']) ?></td>
      <td><?= data_br($l['periodo_ini']) ?> a <?= data_br($l['periodo_fim']) ?></td>
      <td><?= e($l['usuario_nome']) ?></td>
      <td class="num"><?= (int)$l['vendas_novas'] ?></td>
      <td class="num"><?= (int)$l['vendas_atualizadas'] ?></td>
      <td class="num"><?= (int)$l['vendas_iguais'] ?></td>
      <td class="num"><?= $l['crm_lote_id'] ? '<a href="clientes.php?lote=' . (int)$l['crm_lote_id'] . '">' . (int)$l['clientes_novos'] . '</a>' : (int)$l['clientes_novos'] ?></td>
      <td class="num"><?= brl($l['valor_total']) ?></td>
      <td><?= $l['status'] === 'concluida' ? '<span class="tag ok">Concluída</span>' : '<span class="tag neutra">Desfeita ' . date('d/m H:i', strtotime($l['desfeita_em'])) . '</span>' ?></td>
      <td class="acoes">
        <?php if ($l['status'] === 'concluida'): ?>
        <form method="post" onsubmit="return confirm('Desfazer a importação de vendas #<?= (int)$l['id'] ?>? As vendas que ela criou serão apagadas (as que ela só atualizou continuam) e os clientes que ela cadastrou, se não tiverem outras compras nem tours, também.')">
          <?= csrf_campo() ?><input type="hidden" name="lote" value="<?= (int)$l['id'] ?>">
          <button class="peq perigo" name="acao" value="desfazer">Desfazer</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; if (!$lotes): ?><tr><td colspan="12" class="vazio">Nenhuma importação de vendas ainda.</td></tr><?php endif; ?>
  </tbody>
</table></div>

<?php elseif ($erro): ?>
<div class="aviso erro">Não foi possível ler o arquivo: <?= e($erro) ?></div>
<form method="post"><?= csrf_campo() ?><input type="hidden" name="t" value="<?= e($tok) ?>"><button class="sec" name="acao" value="cancelar">Voltar</button></form>

<?php else:
    $nomes = $plano['nomes']; $cli = $plano['clientes'];
    $conferir = array_filter($nomes, fn($n) => !in_array($n['match']['como'], ['nome', 'lembrado', 'ignorar'], true));
    $nNovos = count(array_filter($nomes, fn($n) => strpos($n['padrao'], 'novo:') === 0));
    $rotCli = function ($id) use ($cli) { $c = $cli[$id] ?? null; return $c ? $c['nome'] . ' · ' . ($c['tipo_pessoa'] === 'PJ' ? 'PJ' : 'PF') . ($c['cnpj'] ? ' · ' . $c['cnpj'] : ($c['cpf'] ? ' · CPF ' . $c['cpf'] : '')) . ($c['cidade'] ? ' · ' . $c['cidade'] : '') . ' (#' . $id . ')' : '#' . $id; };
?>
<p class="sub">Prévia de <strong><?= e($nomeArq) ?></strong> · <?= data_br($plano['ini']) ?> a <?= data_br($plano['fim']) ?> · <?= (int)$plano['linhas'] ?> linha(s) de item lida(s)<?= $plano['ignoradas'] ? ', ' . (int)$plano['ignoradas'] . ' ignorada(s)' : '' ?>. <strong>Nada foi gravado ainda.</strong></p>
<div class="cards">
  <div class="card"><span>Faturamento do arquivo</span><strong><?= brl($plano['valor']) ?></strong><small><?= (int)$plano['n_vendas'] ?> vendas · ticket <?= brl($plano['n_vendas'] ? $plano['valor'] / $plano['n_vendas'] : 0) ?></small></div>
  <div class="card"><span>Vendas novas</span><strong><?= count($plano['novas']) ?></strong><small>documentos ainda não importados</small></div>
  <div class="card"><span>Atualizadas</span><strong><?= count($plano['atualizar']) ?></strong><small>mudaram no ERP desde a última carga</small></div>
  <div class="card"><span>Já importadas</span><strong><?= count($plano['iguais']) ?></strong><small>iguais: ficam como estão</small></div>
  <div class="card alerta"><span>Bonificações e amostras</span><strong><?= brl($plano['bonificacao']) ?></strong><small>fora do faturamento</small></div>
  <div class="card"><span>Balcão sem cliente</span><strong><?= (int)$plano['balcao'] ?></strong><small><?= brl($plano['valor_balcao']) ?></small></div>
  <div class="card"><span>Clientes no arquivo</span><strong><?= count($nomes) ?></strong><small><?= count($nomes) - count($conferir) ?> reconhecidos · <?= $nNovos ?> a cadastrar</small></div>
  <div class="card"><span>Produtos novos</span><strong><?= count($plano['produtos_novos']) ?></strong></div>
</div>
<?php foreach ($plano['avisos'] as $a): ?><div class="aviso erro"><?= e($a) ?></div><?php endforeach; ?>

<form method="post" id="form-vendas">
  <?= csrf_campo() ?><input type="hidden" name="t" value="<?= e($tok) ?>">
  <h2>Clientes das vendas <small>confira os destacados · a escolha fica lembrada para os próximos meses</small></h2>
  <div class="cartao rolagem"><table class="tabela-vinculo">
    <thead><tr><th>Nome no ERP</th><th class="num">Vendas</th><th class="num">Valor</th><th>Como foi reconhecido</th><th>O que fazer</th></tr></thead>
    <tbody>
    <?php foreach ($nomes as $k => $n): $m = $n['match']; $atencao = !in_array($m['como'], ['nome', 'lembrado'], true); ?>
      <tr class="<?= $atencao ? 'atencao' : '' ?>">
        <td class="nome"><?= e($n['nome']) ?><br><span class="sub">1ª venda <?= data_br($n['primeira']) ?><?= $n['vendedor'] ? ' · ' . e($n['vendedor']) : '' ?><?= $n['bonif'] > 0 ? ' · bonificação ' . brl($n['bonif']) : '' ?></span></td>
        <td class="num"><?= (int)$n['vendas'] ?></td>
        <td class="num"><?= brl($n['valor']) ?></td>
        <td><span class="tag <?= $atencao ? 'pend' : 'ok' ?>"><?= e(vendas_como_rotulo($m['como'])) ?></span></td>
        <td>
          <select name="dec[<?= e($k) ?>]" class="dec">
            <?php $ops = [];
              foreach ($m['candidatos'] ?: ($m['cliente_id'] ? [$m['cliente_id']] : []) as $id) $ops['c:' . $id] = 'Vincular a ' . $rotCli($id);
              foreach ($m['sugestoes'] as $id => $pc) $ops['c:' . $id] = $ops['c:' . $id] ?? 'Vincular a ' . $rotCli($id) . ' · ' . $pc . '% parecido';
              $ops['novo:PJ'] = 'Cadastrar como novo cliente · empresa (PJ)';
              $ops['novo:PF'] = 'Cadastrar como novo cliente · pessoa física';
              $ops['nenhum'] = 'Não vincular (venda fica sem cliente)';
              $ops['busca'] = 'Vincular a outro cliente (buscar)…';
              foreach ($ops as $v => $rot): ?>
              <option value="<?= e($v) ?>" <?= $v === $n['padrao'] ? 'selected' : '' ?>><?= e($rot) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="busca-cli busca-vinc" hidden>
            <input type="search" placeholder="Digite nome, CNPJ, CPF ou telefone" autocomplete="off">
            <input type="hidden" name="busca_id[<?= e($k) ?>]" value="0">
            <div class="autocomplete" hidden></div>
          </div>
        </td>
      </tr>
    <?php endforeach; if (!$nomes): ?><tr><td colspan="5" class="vazio">Nenhuma venda com nome de cliente neste arquivo.</td></tr><?php endif; ?>
    </tbody>
  </table></div>

  <div class="acoes">
    <button name="acao" value="confirmar" onclick="return confirm('Gravar <?= count($plano['novas']) ?> venda(s) nova(s) e atualizar <?= count($plano['atualizar']) ?>?')">Confirmar importação</button>
    <button class="sec" name="acao" value="cancelar" formnovalidate>Cancelar</button>
  </div>
</form>

<?php if ($plano['produtos_novos']): ?>
<h2>Produtos novos <small><?= count($plano['produtos_novos']) ?> · entram no cadastro com o tipo sugerido</small></h2>
<div class="cartao rolagem"><table>
  <thead><tr><th>Código</th><th>Descrição</th><th>Grupo no ERP</th><th>Tipo sugerido</th></tr></thead>
  <tbody><?php foreach ($plano['produtos_novos'] as $p): ?><tr><td><?= e($p['codigo']) ?></td><td><?= e($p['descricao']) ?></td><td><?= e($p['grupo']) ?></td><td><?= e(nome_tipo_produto($p['tipo'])) ?></td></tr><?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>
<script>
(function () {
  document.querySelectorAll('.tabela-vinculo tr').forEach(function (tr) {
    var sel = tr.querySelector('select.dec'), box = tr.querySelector('.busca-vinc'); if (!sel || !box) return;
    var inp = box.querySelector('input[type=search]'), id = box.querySelector('input[type=hidden]'), res = box.querySelector(".autocomplete"), t = null, ultimo = '';
    function vis() { box.hidden = sel.value !== 'busca'; if (!box.hidden) inp.focus(); }
    sel.addEventListener('change', vis);
    function limpar() { res.hidden = true; res.innerHTML = ''; }
    inp.addEventListener('input', function () {
      id.value = 0; clearTimeout(t); var q = inp.value.trim();
      if (q.length < 2) { limpar(); return; }
      t = setTimeout(function () {
        ultimo = q;
        fetch('clientes_busca.php?q=' + encodeURIComponent(q), {credentials: 'same-origin'}).then(function (r) { return r.json(); }).then(function (lista) {
          if (q !== ultimo) return;
          res.innerHTML = '';
          if (!lista.length) { res.innerHTML = '<div class="vazio">Nenhum cliente encontrado.</div>'; res.hidden = false; return; }
          lista.forEach(function (c) {
            var b = document.createElement('button'); b.type = 'button';
            b.textContent = c.nome + (c.telefone ? ' · ' + c.telefone : '') + ' (#' + c.id + ')';
            b.addEventListener('click', function () { id.value = c.id; inp.value = b.textContent; limpar(); });
            res.appendChild(b);
          });
          res.hidden = false;
        }).catch(limpar);
      }, 250);
    });
  });
  document.getElementById('form-vendas').addEventListener('submit', function (e) {
    var falta = Array.prototype.filter.call(document.querySelectorAll('select.dec'), function (s) { return s.value === 'busca' && s.closest('td').querySelector('input[type=hidden]').value === '0'; });
    if (falta.length && e.submitter && e.submitter.value === 'confirmar') { e.preventDefault(); alert('Escolha o cliente na busca (ou outra opção) em ' + falta.length + ' linha(s).'); falta[0].focus(); }
  });
})();
</script>
<?php endif; ?>
<?php require __DIR__ . '/inc/rodape.php'; ?>
