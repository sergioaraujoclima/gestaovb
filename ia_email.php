<?php
// Envio de uma campanha de e-mail em lotes, dentro do limite por hora da hospedagem.
require __DIR__ . '/inc/app.php';
exigir_login();
if (!camp_v12()) { flash('Rode a migração v12 para usar as campanhas de e-mail.', 'erro'); ir(pagina_inicial()); }
$c = camp_por_id($_GET['c'] ?? $_POST['c'] ?? 0);
$pode = $c && camp_canal($c) === 'email' && ((pode_ver_ia() && in_array($c['area'], ia_areas_do_usuario(), true)) || pode_gerir_grupos());
if (!$pode) negar();
$lote = max(5, min(100, (int)config('email_lote', '25')));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    header('Content-Type: application/json; charset=utf-8');
    if ($c['status'] !== 'liberada') { echo json_encode(['ok' => false, 'msg' => 'Esta campanha não está liberada para envio.'], JSON_UNESCAPED_UNICODE); exit; }
    try { [$ok, $fal, $rest, $motivo] = email_lote($c, $lote); }
    catch (Throwable $ex) { echo json_encode(['ok' => false, 'msg' => $ex->getMessage()], JSON_UNESCAPED_UNICODE); exit; }
    $n = camp_contagem($c);
    echo json_encode(['ok' => true, 'enviados_lote' => $ok, 'falhas_lote' => $fal, 'pendentes' => $rest, 'motivo' => $rest && !email_restante_hora() ? 'limite' : $motivo,
        'enviados' => $n['enviados'], 'falhas' => $n['falhas'], 'restante_hora' => email_restante_hora()], JSON_UNESCAPED_UNICODE);
    exit;
}

$n = camp_contagem($c);
[$asPrev, $previa] = email_montar($c, ['id' => 0, 'nome' => 'Maria da Silva', 'tipo_pessoa' => 'PF', 'cidade' => 'Brasília'], false);
$ultimos = db()->prepare('SELECT e.*, cl.nome, cl.email FROM crm_campanha_envios e JOIN clientes cl ON cl.id = e.cliente_id WHERE e.campanha_id = ? ORDER BY e.em DESC LIMIT 40');
$ultimos->execute([$c['id']]); $ultimos = $ultimos->fetchAll();
$pct = fn($a, $b) => $b ? round($a * 100 / $b) . '%' : '—';
$titulo = 'Envio de e-mail';
require __DIR__ . '/inc/topo.php';
?>
<div class="disp-topo">
  <div><strong><?= e($c['titulo']) ?></strong> <span class="sub">· grupo <?= e($c['grupo_nome']) ?> · assunto: <?= e($c['assunto']) ?><?= $c['status'] !== 'liberada' ? ' · <strong>' . e(nome_status_campanha($c['status'])) . '</strong>' : '' ?></span></div>
  <div class="acoes"><?php if (pode_gerir_grupos()): ?><a class="btn peq sec" href="crm_grupos.php?ver=<?= (int)$c['grupo_id'] ?>&aba=email">Configurar e-mail</a><?php endif; ?><a class="btn peq sec" href="ia.php">Voltar</a></div>
</div>
<div class="disp-prog"><span id="e-barra" style="width:<?= $n['total'] ? round(($n['total'] - $n['pendentes']) * 100 / $n['total']) : 0 ?>%"></span></div>
<div class="cards">
  <div class="card"><span>Enviados</span><strong id="e-env"><?= $n['enviados'] ?></strong><small>de <?= $n['total'] ?> no público</small></div>
  <div class="card"><span>Na fila</span><strong id="e-fila"><?= $n['pendentes'] ?></strong><small><?= $n['sem_email'] ?> sem e-mail · <?= $n['recusaram'] ?> recusaram<?= $c['so_optin'] ? ' · ' . $n['sem_optin'] . ' sem autorização' : '' ?></small></div>
  <div class="card"><span>Abriram</span><strong><?= $pct($n['aberturas'], $n['enviados']) ?></strong><small><?= $n['aberturas'] ?> pessoa(s)</small></div>
  <div class="card"><span>Clicaram</span><strong><?= $pct($n['cliques'], $n['enviados']) ?></strong><small><?= $n['cliques'] ?> pessoa(s)</small></div>
  <div class="card<?= $n['falhas'] ? ' alerta' : '' ?>"><span>Falhas</span><strong id="e-fal"><?= $n['falhas'] ?></strong></div>
</div>
<div class="disp-grade">
  <section class="cartao">
    <h2 style="margin-top:0">Enviar</h2>
    <p class="sub">Os e-mails saem em lotes de <?= $lote ?>, respeitando o limite de <?= email_limite_hora() ?> por hora configurado. Nesta hora ainda cabem <strong id="e-hora"><?= email_restante_hora() ?></strong>.<?= $c['auto_envio'] ? ' O envio automático pelo agendamento está ligado: a fila também anda sozinha.' : '' ?></p>
    <div class="acoes" style="flex-wrap:wrap">
      <button id="e-lote" <?= $n['pendentes'] && $c['status'] === 'liberada' ? '' : 'disabled' ?>>Enviar próximo lote (<?= min($lote, $n['pendentes']) ?>)</button>
      <button class="sec" id="e-tudo" <?= $n['pendentes'] && $c['status'] === 'liberada' ? '' : 'disabled' ?>>Enviar todos, lote a lote</button>
      <button class="sec perigo" id="e-parar" hidden>Parar</button>
    </div>
    <p class="aviso" id="e-msg" hidden></p>
    <h2>Últimos envios</h2>
    <div class="rolagem"><table class="mini"><thead><tr><th>Cliente</th><th>Quando</th><th>Situação</th></tr></thead><tbody id="e-ult">
      <?php foreach ($ultimos as $u): ?><tr><td class="nome"><?= e($u['nome']) ?><br><span class="det-rk"><?= e($u['email']) ?></span></td><td><?= date('d/m H:i', strtotime($u['em'])) ?></td>
        <td><?= $u['situacao'] === 'falhou' ? '<span class="tag pend" title="' . e($u['erro']) . '">falhou</span>' : ($u['clicado_em'] ? '<span class="tag ok">clicou</span>' : ($u['aberto_em'] ? '<span class="tag ok">abriu</span>' : '<span class="tag neutra">enviado</span>')) ?></td></tr><?php endforeach; ?>
      <?php if (!$ultimos): ?><tr><td colspan="3" class="vazio">Nenhum envio ainda.</td></tr><?php endif; ?>
    </tbody></table></div>
  </section>
  <section class="cartao">
    <h2 style="margin-top:0">Prévia <small>assunto: <?= e($asPrev) ?></small></h2>
    <iframe class="em-previa" sandbox="allow-popups allow-popups-to-escape-sandbox" srcdoc="<?= e($previa) ?>" title="Prévia do e-mail"></iframe>
  </section>
</div>
<script>
(function () {
  var CSRF = <?= json_encode(csrf_token()) ?>, CID = <?= (int)$c['id'] ?>, PAUSA = <?= max(5, (int)config('email_pausa', '20')) ?>, rodando = false, total = <?= (int)$n['total'] ?>;
  var $ = function (id) { return document.getElementById(id); };
  function msg(t, erro) { var m = $('e-msg'); m.hidden = false; m.className = 'aviso' + (erro ? ' erro' : ''); m.textContent = t; }
  function lote() {
    var f = new FormData(); f.append('csrf', CSRF); f.append('c', CID);
    return fetch('ia_email.php?c=' + CID, {method: 'POST', body: f, credentials: 'same-origin'}).then(function (r) { return r.json(); }).then(function (j) {
      if (!j.ok) { msg(j.msg || 'Erro no envio.', true); return 'erro'; }
      $('e-env').textContent = j.enviados; $('e-fila').textContent = j.pendentes; $('e-fal').textContent = j.falhas; $('e-hora').textContent = j.restante_hora;
      $('e-barra').style.width = (total ? Math.round((total - j.pendentes) * 100 / total) : 100) + '%';
      $('e-lote').textContent = 'Enviar próximo lote (' + Math.min(<?= $lote ?>, j.pendentes) + ')';
      if (j.motivo === 'fim') { msg('Pronto: todos os e-mails da fila foram enviados.'); $('e-lote').disabled = $('e-tudo').disabled = true; return 'fim'; }
      if (j.motivo === 'limite') { msg('Limite de e-mails desta hora atingido. Continue mais tarde' + (<?= $c['auto_envio'] ? 'true' : 'false' ?> ? ' (o envio automático continua sozinho).' : ', ou ligue o envio automático na configuração do e-mail.'), true); return 'limite'; }
      if (j.falhas_lote && !j.enviados_lote) { msg('O servidor recusou os envios deste lote. Confira a configuração de e-mail.', true); return 'erro'; }
      msg(j.enviados_lote + ' enviado(s) neste lote' + (j.falhas_lote ? ', ' + j.falhas_lote + ' falha(s)' : '') + '. Faltam ' + j.pendentes + '.');
      return 'ok';
    }).catch(function () { msg('Sem conexão com o servidor. Tente de novo.', true); return 'erro'; });
  }
  function fim() { rodando = false; $('e-parar').hidden = true; $('e-tudo').disabled = $('e-lote').disabled = $('e-fila').textContent === '0'; }
  $('e-lote').addEventListener('click', function () { var b = this; b.disabled = true; lote().then(function () { b.disabled = $('e-fila').textContent === '0'; }); });
  $('e-tudo').addEventListener('click', function () {
    if (!confirm('Enviar todos os e-mails da fila, um lote a cada ' + PAUSA + ' segundos? Deixe esta página aberta até terminar.')) return;
    rodando = true; $('e-parar').hidden = false; $('e-tudo').disabled = $('e-lote').disabled = true;
    (function passo() {
      if (!rodando) return fim();
      lote().then(function (r) {
        if (r !== 'ok' || !rodando) return fim();
        var s = PAUSA; (function conta() { if (!rodando) return fim(); if (s <= 0) return passo(); msg('Próximo lote em ' + s + ' s… (deixe esta página aberta)'); s--; setTimeout(conta, 1000); })();
      });
    })();
  });
  $('e-parar').addEventListener('click', function () { rodando = false; msg('Envio pausado. A fila continua de onde parou.'); });
})();
</script>
<?php require __DIR__ . '/inc/rodape.php'; ?>
