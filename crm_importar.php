<?php
require __DIR__ . '/inc/app.php';
exigir_login();
exigir_admin();
crm_exigir_migracao();
@set_time_limit(120);
$pdo = db();
$dir = __DIR__ . '/uploads/importacoes';
$tok = preg_match('/^[a-f0-9]{32}$/', $_REQUEST['t'] ?? '') ? $_REQUEST['t'] : '';
$arq = $tok ? "$dir/$tok.dat" : '';
$nomeArq = $tok && is_file("$dir/$tok.nome") ? (string)file_get_contents("$dir/$tok.nome") : '';
$origemPadrao = $tok && is_file("$dir/$tok.origem") ? (string)file_get_contents("$dir/$tok.origem") : 'planilha';
// Linhas sem origem própria recebem a origem escolhida no envio.
$ler = function () use ($arq, $nomeArq, $origemPadrao) {
    return array_map(function ($l) use ($origemPadrao) { if (trim((string)($l['origem'] ?? '')) === '') $l['origem'] = $origemPadrao; return $l; }, crm_ler($arq, $nomeArq));
};
// Arquivos enviados e não confirmados não ficam no servidor: apaga os com mais de 1 dia.
foreach (glob("$dir/*") ?: [] as $velho) if (filemtime($velho) < time() - 86400) @unlink($velho);

if (isset($_GET['modelo'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="modelo-importacao-clientes.csv"');
    echo crm_modelo_csv();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $acao = $_POST['acao'] ?? '';
    if ($acao === 'enviar') {
        $f = $_FILES['arquivo'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) { flash('Escolha o arquivo CSV.', 'erro'); ir('crm_importar.php'); }
        if ($f['size'] > 8 * 1024 * 1024) { flash('Arquivo maior que 8 MB.', 'erro'); ir('crm_importar.php'); }
        if (!preg_match('/\.(csv|txt|xlsx)$/i', $f['name'])) { flash('Envie uma planilha .xlsx ou um arquivo .csv.', 'erro'); ir('crm_importar.php'); }
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $tok = bin2hex(random_bytes(16));
        move_uploaded_file($f['tmp_name'], "$dir/$tok.dat");
        file_put_contents("$dir/$tok.nome", mb_substr(basename($f['name']), 0, 200));
        file_put_contents("$dir/$tok.origem", isset(lista_origens()[$_POST['origem'] ?? '']) ? $_POST['origem'] : 'planilha');
        ir('crm_importar.php?t=' . $tok);
    }
    if ($acao === 'cancelar') {
        if ($arq) { @unlink($arq); @unlink("$dir/$tok.nome"); @unlink("$dir/$tok.origem"); }
        flash('Importação cancelada. Nada foi gravado.');
        ir('crm_importar.php');
    }
    if ($acao === 'confirmar') {
        if (!$arq || !is_file($arq)) { flash('Arquivo não encontrado. Envie novamente.', 'erro'); ir('crm_importar.php'); }
        try {
            $plano = crm_planejar($ler());
            $lote = crm_executar($plano, $nomeArq);
        } catch (Throwable $ex) {
            flash('A importação não foi gravada (nada mudou): ' . $ex->getMessage(), 'erro');
            ir('crm_importar.php?t=' . $tok);
        }
        @unlink($arq); @unlink("$dir/$tok.nome"); @unlink("$dir/$tok.origem");
        flash('Importação #' . $lote . ' concluída: ' . count($plano['novos']) . ' clientes novos, ' . count($plano['atualizar']) . ' atualizados, ' . count($plano['pendencias']) . ' com pendências.');
        ir('clientes.php?lote=' . $lote);
    }
    if ($acao === 'desfazer') {
        $r = crm_desfazer((int)($_POST['lote'] ?? 0));
        if ($r === null) flash('Esta importação não pode ser desfeita.', 'erro');
        else flash('Importação desfeita: ' . $r[0] . ' cliente(s) removido(s)' . ($r[1] ? '; ' . $r[1] . ' mantido(s) porque já têm tour' : '') . '. As atualizações em clientes antigos continuam (estão na trilha de auditoria).');
        ir('crm_importar.php');
    }
}

$plano = null; $erro = '';
if ($arq) {
    if (!is_file($arq)) { flash('Arquivo não encontrado. Envie novamente.', 'erro'); ir('crm_importar.php'); }
    try { $plano = crm_planejar($ler()); }
    catch (Throwable $ex) { $erro = $ex->getMessage(); }
    if ($plano && isset($_GET['pendencias'])) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="pendencias-importacao.csv"');
        $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF");
        fputcsv($o, ['Origem no arquivo', 'Nome', 'Pendência'], ';', '"', '');
        foreach ($plano['pendencias'] as $p) foreach ($p['itens'] as $i) fputcsv($o, [$p['ref'], $p['nome'], $i], ';', '"', '');
        exit;
    }
}
$lotes = $pdo->query('SELECT * FROM crm_importacoes ORDER BY id DESC LIMIT 20')->fetchAll();
$titulo = 'Importar clientes';
require __DIR__ . '/inc/topo.php';
?>
<?php if (!$arq): ?>
<div class="cartao">
  <p>Envie a planilha (<strong>.xlsx</strong>) ou um arquivo <strong>.csv</strong> com os clientes, por exemplo o relatório de pessoas exportado do ERP. O sistema mostra uma prévia e só grava quando você confirmar.</p>
  <ul class="sub">
    <li>O cabeçalho pode estar em qualquer uma das primeiras 15 linhas. Colunas reconhecidas: Nome (obrigatória), CPF/CNPJ, RG, IE, IM, Sexo, Nascimento/Aniversário, Telefone, Celular, Telefone comercial, E-mail, Endereço, Bairro, Cidade, UF, CEP, Clube, Origem, Grupo (vira etiqueta), Vendedor, Interesses, Observação, Cadastro (cliente desde). <a href="crm_importar.php?modelo=1">Baixar modelo</a></li>
    <li>Quem já está cadastrado (mesmo CPF ou CNPJ; ou mesmo e-mail ou telefone com o mesmo nome) não é duplicado: o sistema só preenche os campos vazios.</li>
    <li>Sem coluna de sexo, o sistema presume pelo primeiro nome (só pessoa física) e marca como "presumido" para a equipe conferir. Depois da carga, os grupos automáticos são recalculados.</li>
    <li>Linhas repetidas dentro do arquivo são juntadas. O que não der para padronizar entra como está e fica marcado como pendência.</li>
  </ul>
  <form method="post" enctype="multipart/form-data" class="acoes">
    <?= csrf_campo() ?>
    <input type="file" name="arquivo" accept=".xlsx,.csv,.txt" required>
    <label class="inline">De onde vêm estes clientes?
      <select name="origem"><?php foreach (lista_origens() as $k => $v): ?><option value="<?= $k ?>" <?= $k === 'planilha' ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select>
    </label>
    <button name="acao" value="enviar">Ver prévia</button>
  </form>
</div>

<h2>Importações anteriores</h2>
<div class="cartao rolagem"><table>
  <thead><tr><th>#</th><th>Data</th><th>Arquivo</th><th>Por</th><th>Novos</th><th>Atualizados</th><th>Pendências</th><th>Situação</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($lotes as $l): ?>
    <tr>
      <td><?= (int)$l['id'] ?></td>
      <td><?= date('d/m/Y H:i', strtotime($l['criado_em'])) ?></td>
      <td><?= e($l['arquivo']) ?></td>
      <td><?= e($l['usuario_nome']) ?></td>
      <td><?= (int)$l['novos'] ?></td>
      <td><?= (int)$l['atualizados'] ?></td>
      <td><?= (int)$l['pendencias'] ?></td>
      <td><?= $l['status'] === 'concluida' ? '<span class="tag ok">Concluída</span>' : '<span class="tag neutra">Desfeita ' . date('d/m H:i', strtotime($l['desfeita_em'])) . '</span>' ?></td>
      <td class="acoes">
        <a class="btn peq sec" href="clientes.php?lote=<?= (int)$l['id'] ?>">Ver clientes</a>
        <?php if ($l['status'] === 'concluida'): ?>
        <form method="post" onsubmit="return confirm('Desfazer a importação #<?= (int)$l['id'] ?>? Os clientes criados por ela (e sem tour) serão apagados.')">
          <?= csrf_campo() ?><input type="hidden" name="lote" value="<?= (int)$l['id'] ?>">
          <button class="peq perigo" name="acao" value="desfazer">Desfazer</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; if (!$lotes): ?><tr><td colspan="9" class="vazio">Nenhuma importação ainda.</td></tr><?php endif; ?>
  </tbody>
</table></div>

<?php elseif ($erro): ?>
<div class="aviso erro">Não foi possível ler o arquivo: <?= e($erro) ?></div>
<form method="post"><?= csrf_campo() ?><input type="hidden" name="t" value="<?= e($tok) ?>"><button class="sec" name="acao" value="cancelar">Voltar</button></form>

<?php else: ?>
<p class="sub">Prévia de <strong><?= e($nomeArq) ?></strong> · origem: <?= e(nome_origem($origemPadrao)) ?> · <?= (int)$plano['total'] ?> linha(s) lida(s). <strong>Nada foi gravado ainda.</strong></p>
<div class="cards">
  <div class="card"><span>Clientes novos</span><strong><?= count($plano['novos']) ?></strong></div>
  <div class="card"><span>Já cadastrados, a completar</span><strong><?= count($plano['atualizar']) ?></strong></div>
  <div class="card"><span>Linhas repetidas juntadas</span><strong><?= count($plano['mesclas']) ?></strong></div>
  <div class="card alerta"><span>Com pendências</span><strong><?= count($plano['pendencias']) ?></strong></div>
  <div class="card"><span>Ignoradas (sem nome)</span><strong><?= count($plano['ignorados']) ?></strong></div>
  <div class="card"><span>Empresas (PJ) novas</span><strong><?= (int)$plano['pj'] ?></strong></div>
  <div class="card"><span>Sexo presumido pelo nome</span><strong><?= (int)$plano['sexo_presumido'] ?></strong></div>
</div>
<form method="post" class="acoes">
  <?= csrf_campo() ?><input type="hidden" name="t" value="<?= e($tok) ?>">
  <button name="acao" value="confirmar" onclick="return confirm('Gravar <?= count($plano['novos']) ?> cliente(s) novo(s) e completar <?= count($plano['atualizar']) ?> cadastro(s) existente(s)?')">Confirmar importação</button>
  <button class="sec" name="acao" value="cancelar">Cancelar</button>
  <?php if ($plano['pendencias']): ?><a class="btn sec" href="crm_importar.php?t=<?= e($tok) ?>&pendencias=1">Baixar pendências (CSV)</a><?php endif; ?>
</form>

<?php if ($plano['conflitos']): ?>
<h2>CPF repetido com nomes diferentes <small><?= count($plano['conflitos']) ?> · entram sem o CPF, para a equipe conferir</small></h2>
<div class="cartao rolagem"><table>
  <thead><tr><th>Origem no arquivo</th><th>Nome</th><th>CPF</th><th>Também consta para</th></tr></thead>
  <tbody><?php foreach ($plano['conflitos'] as $c): ?><tr><td><?= e($c['ref']) ?></td><td><?= e($c['nome']) ?></td><td><?= e($c['cpf']) ?></td><td><?= e($c['outro']) ?></td></tr><?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>

<?php if ($plano['atualizar']): ?>
<h2>Clientes já cadastrados que serão completados <small><?= count($plano['atualizar']) ?> · só campos vazios são preenchidos</small></h2>
<div class="cartao rolagem"><table>
  <thead><tr><th>Cliente</th><th>Origem no arquivo</th><th>O que muda</th></tr></thead>
  <tbody><?php foreach ($plano['atualizar'] as $id => $u): ?>
    <tr><td><a href="clientes.php?editar=<?= (int)$id ?>" target="_blank"><?= e($u['antes']['nome']) ?></a></td><td><?= e(implode(', ', $u['refs'])) ?></td>
      <td class="quebra"><?= e(trim(diff_log($u['antes'], $u['campos']) . (!empty($u['etiquetas_novas']) ? '; Etiquetas: + ' . implode(', ', $u['etiquetas_novas']) : ''), '; ')) ?></td></tr>
  <?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>

<?php if ($plano['mesclas']): ?>
<h2>Linhas repetidas que serão juntadas <small><?= count($plano['mesclas']) ?></small></h2>
<div class="cartao rolagem"><table>
  <thead><tr><th>Origem no arquivo</th><th>Nome</th><th>Juntada com</th></tr></thead>
  <tbody><?php foreach ($plano['mesclas'] as $m): ?><tr><td><?= e($m['ref']) ?></td><td><?= e($m['nome']) ?></td><td><?= e($m['com']) ?></td></tr><?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>

<?php if ($plano['pendencias']): ?>
<h2>Pendências <small><?= count($plano['pendencias']) ?> cadastro(s) · entram assim mesmo e ficam marcados para correção</small></h2>
<div class="cartao rolagem"><table>
  <thead><tr><th>Origem no arquivo</th><th>Nome</th><th>Pendência</th></tr></thead>
  <tbody><?php foreach ($plano['pendencias'] as $p): ?><tr><td><?= e($p['ref']) ?></td><td><?= e($p['nome']) ?></td><td class="quebra"><?= e(implode('; ', $p['itens'])) ?></td></tr><?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>

<h2>Clientes novos <small>primeiros <?= min(100, count($plano['novos'])) ?> de <?= count($plano['novos']) ?></small></h2>
<div class="cartao rolagem"><table>
  <thead><tr><th>Nome</th><th>Documento</th><th>Sexo</th><th>Celular</th><th>E-mail</th><th>Nascimento</th><th>CEP / UF</th><th>Clube</th><th>Etiquetas</th></tr></thead>
  <tbody><?php foreach (array_slice($plano['novos'], 0, 100) as $n): ?>
    <tr><td><?= e($n['nome']) ?><?= $n['tipo_pessoa'] === 'PJ' ? ' <span class="tag neutra">PJ</span>' : '' ?></td><td><?= e($n['cnpj'] ?: ($n['cpf'] ?: $n['rg'])) ?></td><td><?= e($n['sexo'] ?? '') ?><?= !empty($n['sexo_presumido']) ? ' <span class="sub">(pres.)</span>' : '' ?></td><td><?= e($n['telefone']) ?></td><td><?= e($n['email']) ?></td>
      <td><?= data_br($n['data_nascimento']) ?></td><td><?= e(trim(($n['cep'] ?? '') . ' / ' . ($n['uf'] ?? ''), ' /')) ?></td><td><?= $n['clube_beneficios'] ? 'Sim' : '—' ?></td><td><?= e(implode(', ', $n['_etiquetas'] ?? [])) ?></td></tr>
  <?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>
<?php require __DIR__ . '/inc/rodape.php'; ?>
