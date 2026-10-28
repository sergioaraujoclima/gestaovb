<?php
require __DIR__ . '/inc/app.php';
exigir_login();
exigir_ia();
ia_exigir_migracao();
$pdo = db();
$minhas = ia_areas_do_usuario();
$quem = $GLOBALS['USUARIO']['nome'];
$tiposMarketing = ['aniversario', 'recompra', 'segunda_compra', 'convite_clube'];
$cartao = function ($id) use ($pdo, $minhas) {
    $s = $pdo->prepare('SELECT * FROM ia_cartoes WHERE id = ?'); $s->execute([(int)$id]); $c = $s->fetch();
    return $c && in_array($c['area'], $minhas, true) ? $c : null;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $acao = $_POST['acao'] ?? '';
    // Marca um contato como enviado (chamado pelo botão do WhatsApp, sem recarregar a página).
    if ($acao === 'enviado') {
        header('Content-Type: application/json; charset=utf-8');
        $c = $cartao($_POST['id'] ?? 0); $i = (int)($_POST['idx'] ?? -1);
        if (!$c) { echo '{"ok":false}'; exit; }
        $d = json_decode((string)$c['dados'], true) ?: [];
        if (!isset($d['contatos'][$i])) { echo '{"ok":false}'; exit; }
        $d['enviados'][$i] = ['em' => date('Y-m-d H:i:s'), 'por' => $quem];
        $pdo->prepare('UPDATE ia_cartoes SET dados = ?, status = CASE WHEN status = \'nova\' THEN \'andamento\' ELSE status END, atualizado_em = ? WHERE id = ?')
            ->execute([json_encode($d, JSON_UNESCAPED_UNICODE), date('Y-m-d H:i:s'), $c['id']]);
        echo json_encode(['ok' => true, 'txt' => 'enviado ' . date('H:i') . ' · ' . $quem, 'feitos' => count($d['enviados']), 'total' => count($d['contatos'])], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($acao === 'status') {
        $c = $cartao($_POST['id'] ?? 0);
        $st = in_array($_POST['status'] ?? '', ['nova', 'andamento', 'feita', 'descartada'], true) ? $_POST['status'] : '';
        if ($c && $st) {
            $fim = in_array($st, ['feita', 'descartada'], true);
            $com = vazio_null(mb_substr(texto_limpo($_POST['comentario'] ?? ''), 0, 500));
            $pdo->prepare('UPDATE ia_cartoes SET status = ?, comentario = COALESCE(?, comentario), resolvido_por = ?, resolvido_em = ?, atualizado_em = ? WHERE id = ?')
                ->execute([$st, $com, $fim ? $quem : null, $fim ? date('Y-m-d H:i:s') : null, date('Y-m-d H:i:s'), $c['id']]);
            registrar_log('ia_cartao', $c['id'], 'Situação: ' . nome_status_cartao($st), $c['titulo'] . ($com ? ' · ' . $com : ''));
            flash('"' . mb_strimwidth($c['titulo'], 0, 70, '…') . '" marcado como ' . mb_strtolower(nome_status_cartao($st)) . '.');
        }
        ir('ia.php?' . http_build_query(array_filter(['aba' => $_POST['aba'] ?? '', 'historico' => $_POST['historico'] ?? ''])));
    }
    // Cliente respondeu "N" no WhatsApp da vinícola: deixa de receber mensagens de marketing.
    if ($acao === 'optout' || $acao === 'optout_desfazer') {
        $id = (int)($_POST['cliente_id'] ?? 0); $antes = $id ? linha('clientes', $id) : null;
        if ($antes) {
            $sair = $acao === 'optout';
            $pdo->prepare('UPDATE clientes SET aceita_whatsapp = ?, consentimento_em = ?, consentimento_origem = ? WHERE id = ?')
                ->execute([$sair ? 0 : null, date('Y-m-d H:i:s'), mb_substr(($sair ? 'Respondeu N no WhatsApp · ' : 'Descadastro desfeito · ') . $quem, 0, 60), $id]);
            registrar_alteracao('cliente', 'clientes', $id, $antes, $sair ? 'Descadastro do WhatsApp (respondeu N)' : 'Descadastro do WhatsApp desfeito');
            flash($sair ? nome_proprio($antes['nome']) . ' não recebe mais mensagens de marketing pelo WhatsApp.' : 'Descadastro de ' . nome_proprio($antes['nome']) . ' desfeito (voltou para "não perguntado").');
        }
        ir('ia.php?' . http_build_query(['optout' => $_POST['termo'] ?? '']) . '#optout');
    }
    if ($acao === 'gerar_alertas') {
        $erros = ia_gerar_alertas(true);
        flash($erros ? 'Alertas atualizados, com erro em: ' . implode(' | ', $erros) : 'Alertas atualizados agora.', $erros ? 'erro' : 'ok');
        ir('ia.php');
    }
    if ($acao === 'gerar_analise') {
        exigir_admin();
        try { $id = ia_analise_semanal(true); flash('Análise da semana gerada pela IA.'); }
        catch (Throwable $ex) { flash('A análise não foi gerada: ' . $ex->getMessage(), 'erro'); }
        ir('ia.php');
    }
}

ia_gerar_alertas();   // no máximo uma vez por hora; o cron mantém em dia mesmo sem ninguém abrir a tela
$aba = in_array($_GET['aba'] ?? '', $minhas, true) ? $_GET['aba'] : '';
$hist = !empty($_GET['historico']);
$areasAba = $aba ? [$aba] : $minhas;
$todos = ia_cartoes_abertos($minhas);
$cont = array_fill_keys($minhas, 0); foreach ($todos as $c) $cont[$c['area']]++;
$lista = array_values(array_filter($todos, fn($c) => in_array($c['area'], $areasAba, true)));
$hoje = date('Y-m-d');
// Quem pediu para sair depois que o cartão foi montado também aparece como "recusou" (sem esperar o cartão ser refeito).
$ids = [];
foreach ($lista as $c) foreach ($c['dados']['contatos'] ?? [] as $p) $ids[(int)($p['cliente_id'] ?? 0)] = 1;
unset($ids[0]);
$GLOBALS['IA_RECUSARAM'] = $ids ? array_flip(array_map('intval', array_column(ia_q('SELECT id FROM clientes WHERE aceita_whatsapp = 0 AND id IN (' . implode(',', array_keys($ids)) . ')'), 'id'))) : [];
// Busca para registrar o "N": pelo celular (últimos 8 dígitos, com ou sem o 9) ou pelo nome.
$optTermo = trim(mb_substr((string)($_GET['optout'] ?? ''), 0, 80));
$optRes = null;
if ($optTermo !== '') {
    $dig = preg_replace('/\D/', '', $optTermo);
    $optRes = strlen($dig) >= 8
        ? ia_q('SELECT id, nome, telefone, telefone_e164, tipo_pessoa, aceita_whatsapp, consentimento_em, consentimento_origem FROM clientes WHERE telefone_e164 LIKE ? OR telefone LIKE ? ORDER BY nome LIMIT 200', ['%' . substr($dig, -8), '%' . substr($dig, -4)])
        : (mb_strlen($optTermo) >= 3 ? ia_q('SELECT id, nome, telefone, tipo_pessoa, aceita_whatsapp, consentimento_em, consentimento_origem FROM clientes WHERE nome LIKE ? ORDER BY nome LIMIT 20', ['%' . $optTermo . '%']) : []);
    if (strlen($dig) >= 8) $optRes = array_slice(array_values(array_filter($optRes, fn($r) => substr(preg_replace('/\D/', '', (string)$r['telefone_e164']), -8) === substr($dig, -8) || substr(preg_replace('/\D/', '', (string)$r['telefone']), -8) === substr($dig, -8))), 0, 20);
}
$secoes = ['hoje' => [], 'semana' => [], 'ia' => []];
foreach ($lista as $c) {
    if ($c['origem'] === 'ia') $secoes['ia'][] = $c;
    elseif ($c['valido_ate'] === $hoje || substr((string)$c['quando'], 0, 10) === $hoje) $secoes['hoje'][] = $c;
    else $secoes['semana'][] = $c;
}
$analise = $pdo->query("SELECT * FROM ia_analises WHERE status = 'ok' ORDER BY id DESC LIMIT 1")->fetch();
$ultErro = $pdo->query("SELECT * FROM ia_analises ORDER BY id DESC LIMIT 1")->fetch();
$semChave = trim((string)config('ia_chave_api', '')) === '';
$historico = $hist ? ia_q("SELECT * FROM ia_cartoes WHERE area IN (" . implode(',', array_fill(0, count($areasAba), '?')) . ") AND (status IN ('feita', 'descartada') OR valido_ate < ?) AND COALESCE(resolvido_em, atualizado_em, criado_em) >= ? ORDER BY COALESCE(resolvido_em, atualizado_em, criado_em) DESC LIMIT 200",
    array_merge($areasAba, [$hoje, date('Y-m-d', strtotime('-60 days'))])) : [];

function ia_render_cartao($c, $aba, $tiposMarketing) {
    $d = $c['dados']; $env = $d['enviados'] ?? []; $contatos = $d['contatos'] ?? []; $itens = $d['itens'] ?? [];
    $mkt = in_array($c['tipo'], $tiposMarketing, true);
    $h = '<article class="ia-cartao pri-' . $c['prioridade'] . ($c['status'] === 'andamento' ? ' andamento' : '') . '">';
    $h .= '<header><span class="chip area-' . $c['area'] . '">' . e(ia_areas()[$c['area']]) . '</span><span class="chip pri">' . e(nome_prioridade($c['prioridade'])) . '</span>'
        . ($c['origem'] === 'ia' ? '<span class="chip ia">✦ IA</span>' : '') . ($c['status'] === 'andamento' ? '<span class="chip and">Em andamento</span>' : '')
        . ($c['quando'] && substr($c['quando'], 0, 10) === date('Y-m-d') && substr($c['quando'], 11, 5) !== '00:00' ? '<span class="chip hora' . (strtotime($c['quando']) <= time() ? ' agora' : '') . '">' . (strtotime($c['quando']) <= time() ? 'Enviar agora' : 'A partir das ' . substr($c['quando'], 11, 5)) . '</span>' : '')
        . '</header>';
    $h .= '<h3>' . e($c['titulo']) . '</h3>';
    if ($c['texto']) $h .= '<p class="ia-texto">' . nl2br(e($c['texto'])) . '</p>';
    if ($c['tipo'] === 'campanha' && !empty($d['campanha_id']) && function_exists('camp_v11') && camp_v11() && ($cp = camp_por_id($d['campanha_id']))) {
        $n = camp_contagem($cp);
        if (camp_canal($cp) === 'email') {
            $h .= '<div class="ia-camp">' . ($cp['imagem'] ? '<img src="' . e(camp_url_imagem($cp)) . '" alt="">' : '') . '<div class="em-resumo"><span class="chip">✉️ E-mail</span><strong>' . e($cp['assunto']) . '</strong><span class="det-rk">' . e($cp['preheader']) . '</span></div></div>'
                . '<div class="disp-prog"><span style="width:' . ($n['total'] ? round(($n['total'] - $n['pendentes']) * 100 / $n['total']) : 0) . '%"></span></div>'
                . '<p class="ia-prog">' . $n['enviados'] . ' enviado(s) · ' . $n['pendentes'] . ' na fila · ' . $n['aberturas'] . ' abriram · ' . $n['cliques'] . ' clicaram' . ($n['falhas'] ? ' · ' . $n['falhas'] . ' falha(s)' : '') . '</p>'
                . '<p class="acoes"><a class="btn" href="ia_email.php?c=' . (int)$cp['id'] . '">Enviar e-mails ▶</a>' . (pode_gerir_grupos() ? '<a class="btn peq sec" href="crm_grupos.php?ver=' . (int)$cp['grupo_id'] . '&aba=email">Ver e-mail</a>' : '') . '</p>';
            $c['link'] = null;
        } else {
        $h .= '<div class="ia-camp">' . ($cp['imagem'] ? '<img src="' . e(camp_url_imagem($cp)) . '" alt="">' : '') . '<div class="wa-bolha mini"><div class="wa-txt">' . wa_formatar_html(mb_strimwidth(strtr((string)$cp['texto'], ['{nome}' => 'Maria', '{nome_completo}' => 'Maria da Silva', '{cidade}' => 'Brasília']), 0, 260, '…')) . '</div></div></div>'
            . '<div class="disp-prog"><span style="width:' . ($n['total'] ? round(($n['enviados'] + $n['pulados']) * 100 / $n['total']) : 0) . '%"></span></div>'
            . '<p class="ia-prog">' . $n['enviados'] . ' enviada(s) · ' . $n['pendentes'] . ' na fila</p>'
            . '<p class="acoes"><a class="btn wa" href="ia_disparo.php?c=' . (int)$cp['id'] . '">Iniciar envio ▶</a>' . (pode_ver_crm() ? '<a class="btn peq sec" href="crm_grupos.php?ver=' . (int)$cp['grupo_id'] . '&aba=whatsapp#campanha">Ver mensagem</a>' : '') . '</p>';
        $c['link'] = null;
        }
    }
    if ($contatos) {
        $h .= '<div class="ia-contatos"><div class="ia-prog">' . count($env) . ' de ' . count($contatos) . ' contatados</div>'
            . (count($contatos) > 8 ? '<input type="search" class="ia-busca" placeholder="Procurar nesta lista (' . count($contatos) . ' nomes)">' : '') . '<ul>';
        foreach ($contatos as $i => $p) {
            if ($i === 8) $h .= '</ul><details><summary>Ver os outros ' . (count($contatos) - 8) . '</summary><ul>';
            $h .= '<li><div><a href="clientes.php?editar=' . (int)$p['cliente_id'] . '">' . e($p['nome']) . '</a>' . ($p['detalhe'] ? '<br><small>' . e($p['detalhe']) . '</small>' : '') . '</div><div class="ia-acao" id="ct-' . (int)$c['id'] . '-' . $i . '">';
            if (isset($env[$i])) $h .= '<span class="ok-env">✓ enviado ' . date('d/m H:i', strtotime($env[$i]['em'])) . ' · ' . e($env[$i]['por']) . '</span>';
            if ($p['msg'] !== null) {
                if ($mkt && (!empty($p['recusou']) || isset($GLOBALS['IA_RECUSARAM'][(int)$p['cliente_id']]))) $h .= '<span class="tag pend">recusou marketing</span>';
                elseif ($p['tel']) $h .= '<a class="btn peq wa" target="_blank" rel="noopener" href="' . e(whatsapp_link($p['tel'], $p['msg'])) . '" data-id="' . (int)$c['id'] . '" data-idx="' . $i . '" title="' . e($p['msg']) . '">' . (isset($env[$i]) ? 'Reenviar' : 'WhatsApp') . '</a>';
                else $h .= '<a class="tag neutra" href="clientes.php?editar=' . (int)$p['cliente_id'] . '">sem celular</a>';
            } elseif ($p['tel']) {
                $h .= '<a class="btn peq sec" target="_blank" rel="noopener" href="https://api.whatsapp.com/send/?phone=' . e(preg_replace('/\D/', '', $p['tel'])) . '">Conversar</a>';
            }
            $h .= '</div></li>';
        }
        $h .= '</ul>' . (count($contatos) > 8 ? '</details>' : '') . '</div>';
    }
    if ($itens) {
        $h .= '<ul class="ia-itens">';
        foreach ($itens as $it) $h .= '<li>' . (!empty($it['link']) ? '<a href="' . e($it['link']) . '">' . e($it['rot']) . '</a>' : e($it['rot'])) . (!empty($it['detalhe']) ? '<br><small>' . e($it['detalhe']) . '</small>' : '') . '</li>';
        $h .= '</ul>';
    }
    $h .= '<footer><form method="post" class="ia-status">' . csrf_campo() . '<input type="hidden" name="acao" value="status"><input type="hidden" name="id" value="' . (int)$c['id'] . '"><input type="hidden" name="aba" value="' . e($aba) . '">'
        . ($c['link'] ? '<a class="btn peq sec" href="' . e($c['link']) . '">Abrir</a>' : '')
        . '<input name="comentario" maxlength="500" placeholder="Comentário (opcional)">'
        . ($c['status'] === 'nova' ? '<button class="peq sec" name="status" value="andamento">Em andamento</button>' : '')
        . '<button class="peq" name="status" value="feita">Feito</button><button class="peq perigo" name="status" value="descartada">Descartar</button></form></footer>';
    return $h . '</article>';
}
$titulo = 'Sugestões da IA';
require __DIR__ . '/inc/topo.php';
?>
<div class="ia-topo">
  <nav class="abas">
    <a href="ia.php<?= $hist ? '?historico=1' : '' ?>" class="<?= $aba === '' ? 'ativa' : '' ?>">Todas <span><?= count($todos) ?></span></a>
    <?php foreach ($minhas as $a): ?><a href="ia.php?aba=<?= $a ?><?= $hist ? '&historico=1' : '' ?>" class="<?= $aba === $a ? 'ativa' : '' ?>"><?= e(ia_areas()[$a]) ?> <span><?= $cont[$a] ?></span></a><?php endforeach; ?>
  </nav>
  <div class="acoes">
    <a class="btn peq sec" href="ia.php?<?= e(http_build_query(array_filter(['aba' => $aba, 'historico' => $hist ? '' : '1']))) ?>"><?= $hist ? 'Ver abertos' : 'Histórico' ?></a>
    <form method="post"><?= csrf_campo() ?><button class="peq sec" name="acao" value="gerar_alertas" title="Os alertas são atualizados sozinhos de hora em hora">Atualizar alertas</button></form>
  </div>
</div>

<?php if ($hist): ?>
<h2>Histórico <small>últimos 60 dias · feitos, descartados e vencidos</small></h2>
<div class="cartao rolagem"><table>
  <thead><tr><th>Área</th><th>Alerta</th><th>Situação</th><th>Por</th><th>Quando</th><th>Comentário</th></tr></thead>
  <tbody><?php foreach ($historico as $c): $env = count((json_decode((string)$c['dados'], true) ?: [])['enviados'] ?? []); ?>
    <tr><td><?= e(ia_areas()[$c['area']]) ?></td><td class="nome"><?= $c['origem'] === 'ia' ? '✦ ' : '' ?><?= e($c['titulo']) ?><?= $env ? '<br><small class="sub">' . $env . ' contato(s) enviado(s)</small>' : '' ?></td>
      <td><?= in_array($c['status'], ['nova', 'andamento'], true) ? 'Vencido (' . e(mb_strtolower(nome_status_cartao($c['status']))) . ')' : e(nome_status_cartao($c['status'])) ?></td>
      <td><?= e($c['resolvido_por']) ?></td><td><?= $c['resolvido_em'] ? date('d/m/Y H:i', strtotime($c['resolvido_em'])) : data_br($c['valido_ate']) ?></td><td class="quebra"><?= e($c['comentario']) ?></td></tr>
  <?php endforeach; if (!$historico): ?><tr><td colspan="6" class="vazio">Nada no histórico ainda.</td></tr><?php endif; ?></tbody>
</table></div>
<?php else: ?>

<?php if (in_array('geral', $areasAba, true) || $aba === ''): ?>
<section class="cartao ia-analise">
  <div class="ia-analise-topo">
    <h2>✦ Análise da semana <small><?= $analise ? 'semana de ' . data_br($analise['semana']) . ' · gerada em ' . date('d/m H:i', strtotime($analise['criado_em'])) : 'ainda não gerada' ?></small></h2>
    <?php if (eh_admin() && !$semChave): ?><form method="post" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Analisando… (até 1 minuto)'"><?= csrf_campo() ?><button class="peq" name="acao" value="gerar_analise"><?= $analise ? 'Refazer análise agora' : 'Gerar análise agora' ?></button></form><?php endif; ?>
  </div>
  <?php if ($analise && $analise['resumo'] && in_array('geral', $minhas, true)): ?><p class="ia-resumo"><?= nl2br(e($analise['resumo'])) ?></p><?php endif; ?>
  <?php if ($semChave): ?><p class="sub"><?= eh_admin() ? 'Para a IA escrever as sugestões da semana, cadastre a chave da API da Anthropic em <a href="ia_config.php">Configuração</a>.' : 'A análise da IA ainda não foi configurada pelo Administrador.' ?> Os alertas automáticos abaixo já funcionam sem ela.</p><?php endif; ?>
  <?php if ($ultErro && $ultErro['status'] === 'erro' && eh_admin()): ?><p class="aviso erro">Última tentativa (<?= date('d/m H:i', strtotime($ultErro['criado_em'])) ?>) falhou: <?= e($ultErro['erro']) ?></p><?php endif; ?>
</section>
<?php endif; ?>

<details class="cartao ia-optout" id="optout"<?= $optTermo !== '' ? ' open' : '' ?>>
  <summary><strong>Registrar “N”</strong> <span class="sub">· o cliente respondeu N no WhatsApp e não quer mais receber mensagens</span></summary>
  <form method="get" action="ia.php#optout" class="ia-optout-busca">
    <input type="search" name="optout" value="<?= e($optTermo) ?>" placeholder="Celular de quem respondeu (ex.: 61 99999-0000) ou nome" autocomplete="off" required>
    <button class="peq">Procurar</button>
  </form>
  <?php if ($optRes !== null): ?>
    <?php if (!$optRes): ?><p class="sub">Nenhum cliente com esse <?= strlen(preg_replace('/\D/', '', $optTermo)) >= 8 ? 'celular' : 'nome' ?>. Confira o número no WhatsApp (copie e cole) ou procure pelo nome.</p><?php endif; ?>
    <ul class="ia-optout-lista">
    <?php foreach ($optRes as $r): $fora = (string)$r['aceita_whatsapp'] === '0'; ?>
      <li><div><a href="clientes.php?editar=<?= (int)$r['id'] ?>"><?= e($r['nome']) ?></a><br><small><?= e($r['telefone'] ?: 'sem telefone') ?> · <?= $fora ? 'descadastrado' . ($r['consentimento_em'] ? ' em ' . date('d/m/Y H:i', strtotime($r['consentimento_em'])) : '') . ($r['consentimento_origem'] ? ' · ' . e($r['consentimento_origem']) : '') : ((string)$r['aceita_whatsapp'] === '1' ? 'autorizou WhatsApp' : 'não perguntado') ?></small></div>
        <form method="post"><?= csrf_campo() ?><input type="hidden" name="cliente_id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="termo" value="<?= e($optTermo) ?>">
          <?php if ($fora): ?><span class="tag pend">não recebe</span> <button class="peq sec" name="acao" value="optout_desfazer" onclick="return confirm('Desfazer o descadastro? Só faça isso se o cliente pediu para voltar a receber.')">Desfazer</button>
          <?php else: ?><button class="peq perigo" name="acao" value="optout" onclick="return confirm('Marcar <?= e(addslashes(nome_proprio($r['nome']))) ?> como quem NÃO quer receber mensagens pelo WhatsApp?')">Não quer receber</button><?php endif; ?>
        </form></li>
    <?php endforeach; ?>
    </ul>
  <?php endif; ?>
  <p class="sub">Quem é marcado sai das filas das campanhas e aparece como “recusou marketing” nos cartões. Avisos de tour (confirmação, boas-vindas, agradecimento) continuam liberados. Também dá para mudar na ficha do cliente, em “Aceita WhatsApp”.</p>
</details>

<?php $rot = ['hoje' => ['Para hoje', 'tours do dia, confirmações, agradecimentos e o que vence hoje'], 'semana' => ['Esta semana', 'campanhas, clientes para contatar e pontos de atenção'], 'ia' => ['✦ Sugestões da IA', 'iniciativas da análise semanal']];
foreach ($secoes as $k => $cs): if (!$cs && $k !== 'hoje') continue; ?>
<h2><?= $rot[$k][0] ?> <small><?= count($cs) ?> · <?= $rot[$k][1] ?></small></h2>
<?php if (!$cs): ?><p class="sub">Nada para hoje nesta área. 👍</p><?php endif; ?>
<div class="ia-grade"><?php foreach ($cs as $c) echo ia_render_cartao($c, $aba, $tiposMarketing); ?></div>
<?php endforeach; ?>
<?php if (!$lista): ?><div class="cartao"><p>Nenhum alerta aberto<?= $aba ? ' para ' . e(ia_areas()[$aba]) : '' ?>. Os alertas aparecem sozinhos conforme os tours, as vendas e as datas se aproximam.</p></div><?php endif; ?>
<p class="sub">Os alertas são revistos de hora em hora. O botão WhatsApp abre a conversa com a mensagem pronta (dá para ajustar antes de enviar) e marca o contato como enviado. Alertas marcados como feitos ou descartados não voltam.</p>
<script>
// Busca dentro da lista de contatos de um cartão (abre o "Ver os outros" quando o nome está lá).
document.querySelectorAll('.ia-busca').forEach(function (inp) {
  inp.addEventListener('input', function () {
    var t = inp.value.trim().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, ''), box = inp.closest('.ia-contatos'), d = box.querySelector('details');
    box.querySelectorAll('li').forEach(function (li) { li.hidden = t && (li.firstElementChild || li).textContent.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').indexOf(t) < 0; });
    if (d && t) d.open = true;
  });
});
document.querySelectorAll('a.wa').forEach(function (a) {
  a.addEventListener('click', function () {
    var f = new FormData(); f.append('csrf', <?= json_encode(csrf_token()) ?>); f.append('acao', 'enviado'); f.append('id', a.dataset.id); f.append('idx', a.dataset.idx);
    fetch('ia.php', {method: 'POST', body: f, credentials: 'same-origin'}).then(function (r) { return r.json(); }).then(function (j) {
      if (!j.ok) return;
      var box = a.parentNode, s = box.querySelector('.ok-env');
      if (!s) { s = document.createElement('span'); s.className = 'ok-env'; box.insertBefore(s, a); }
      s.textContent = '✓ ' + j.txt; a.textContent = 'Reenviar';
      var art = a.closest('.ia-cartao'), p = art && art.querySelector('.ia-prog'); if (p) p.textContent = j.feitos + ' de ' + j.total + ' contatados';
    }).catch(function () {});
  });
});
</script>
<?php endif; ?>
<?php require __DIR__ . '/inc/rodape.php'; ?>
