<?php
// Fila de envio de uma campanha pelo WhatsApp (sem API): abre a conversa de cada cliente com o texto pronto,
// marca como enviado e já deixa o próximo pronto. Continua de onde parou e nunca repete cliente.
require __DIR__ . '/inc/app.php';
exigir_login();
if (!camp_v11()) { flash('Rode a migração v11 para usar as campanhas.', 'erro'); ir(pagina_inicial()); }
$c = camp_por_id($_GET['c'] ?? $_POST['c'] ?? 0);
$pode = $c && ((pode_ver_ia() && in_array($c['area'], ia_areas_do_usuario(), true)) || pode_gerir_grupos());
if (!$pode) negar();
$pdo = db(); $quem = $GLOBALS['USUARIO']['nome'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    header('Content-Type: application/json; charset=utf-8');
    $cli = (int)($_POST['cliente_id'] ?? 0); $sit = $_POST['situacao'] ?? '';
    if ($sit === 'desfazer') $pdo->prepare('DELETE FROM crm_campanha_envios WHERE campanha_id = ? AND cliente_id = ?')->execute([$c['id'], $cli]);
    elseif (in_array($sit, ['enviado', 'pulado'], true)) {
        $s = $pdo->prepare('SELECT 1 FROM crm_grupo_membros WHERE grupo_id = ? AND cliente_id = ?'); $s->execute([$c['grupo_id'], $cli]);
        if (!$s->fetchColumn()) { echo '{"ok":false}'; exit; }
        $pdo->prepare('DELETE FROM crm_campanha_envios WHERE campanha_id = ? AND cliente_id = ?')->execute([$c['id'], $cli]);
        $pdo->prepare('INSERT INTO crm_campanha_envios (campanha_id, cliente_id, situacao, em, por) VALUES (?,?,?,?,?)')->execute([$c['id'], $cli, $sit, date('Y-m-d H:i:s'), $quem]);
        if ($sit === 'enviado') {
            $pdo->prepare('UPDATE clientes SET ultima_interacao = ? WHERE id = ? AND (ultima_interacao IS NULL OR ultima_interacao < ?)')->execute([date('Y-m-d'), $cli, date('Y-m-d')]);
            $pdo->prepare("UPDATE ia_cartoes SET status = 'andamento', atualizado_em = ? WHERE chave = ? AND status = 'nova'")->execute([date('Y-m-d H:i:s'), 'campanha:' . $c['id'] . ':' . date('Y-m-d')]);
        }
    }
    $n = camp_contagem($c);
    if (!$n['pendentes']) $pdo->prepare("UPDATE ia_cartoes SET status = 'feita', resolvido_por = ?, resolvido_em = ?, comentario = 'Todos os clientes da fila foram atendidos' WHERE chave LIKE ? AND status IN ('nova', 'andamento')")
        ->execute([$quem, date('Y-m-d H:i:s'), 'campanha:' . $c['id'] . ':%']);
    echo json_encode(['ok' => true, 'enviados' => $n['enviados'], 'pendentes' => $n['pendentes'], 'pulados' => $n['pulados'], 'em' => date('H:i'), 'por' => $quem], JSON_UNESCAPED_UNICODE);
    exit;
}

$pub = camp_publico($c);
$item = function ($x) use ($c) {
    $tel = preg_replace('/\D/', '', $x['telefone_e164']);
    return ['id' => (int)$x['id'], 'nome' => $x['nome'], 'tel' => $tel, 'telFmt' => $x['telefone'], 'cidade' => $x['cidade'], 'pj' => $x['tipo_pessoa'] === 'PJ', 'texto' => camp_texto($c, $x)];
};
$fila = array_map($item, $pub['fila']);
usort($pub['feitos'], fn($a, $b) => strcmp($b['em'], $a['em']));
$feitos = array_map(fn($x) => ['id' => (int)$x['id'], 'nome' => $x['nome'], 'sit' => $x['situacao'], 'em' => date('d/m H:i', strtotime($x['em'])), 'por' => $x['por']], $pub['feitos']);
$img = camp_url_imagem($c);
$titulo = 'Envio da campanha';
require __DIR__ . '/inc/topo.php';
?>
<div class="disp-topo">
  <div><strong><?= e($c['titulo']) ?></strong> <span class="sub">· grupo <?= e($c['grupo_nome']) ?><?= $c['status'] !== 'liberada' ? ' · <strong>' . e(nome_status_campanha($c['status'])) . '</strong>' : '' ?></span></div>
  <a class="btn peq sec" href="ia.php">Voltar para Alertas e sugestões</a>
</div>
<div class="disp-prog"><span id="d-barra" style="width:0%"></span></div>
<p class="sub" id="d-cont"></p>

<div class="disp-grade">
  <section class="cartao disp-atual" id="d-atual">
    <div class="disp-opcoes">
      <label class="inline"><input type="radio" name="d-modo" value="app"> Aplicativo do WhatsApp (computador)</label>
      <label class="inline"><input type="radio" name="d-modo" value="web"> WhatsApp Web</label>
      <label class="inline"><input type="checkbox" id="d-auto" checked> Passar para o próximo ao abrir</label>
    </div>
    <div id="d-vazio" class="vazio" hidden>🎉 Todos os clientes da fila foram atendidos.</div>
    <div id="d-cliente">
      <div class="disp-nome"><span id="d-pos"></span><strong id="d-n"></strong><span id="d-tel" class="sub"></span></div>
      <div class="wa-tela">
        <?php if ($img && !$c['cartao']): ?><div class="wa-bolha"><img class="wa-img" src="<?= e($img) ?>" alt=""><span class="wa-hora">imagem: use "Copiar imagem" e cole na conversa (Ctrl+V)</span></div><?php endif; ?>
        <div class="wa-bolha"><div class="wa-txt" id="d-txt"></div>
          <?php if ($c['cartao']): ?><div class="wa-card"><?php if ($img): ?><img src="<?= e($img) ?>" alt=""><?php endif; ?><strong><?= e($c['cartao_titulo'] ?: $c['titulo']) ?></strong><span><?= e($c['cartao_texto']) ?></span><small><?= e(parse_url(url_base(), PHP_URL_HOST)) ?></small></div><?php endif; ?>
        </div></div>
      <div class="disp-botoes">
        <?php if ($img): ?><button type="button" class="sec" id="d-img" title="Copia a imagem; na conversa, cole com Ctrl+V">📋 Copiar imagem</button><?php endif; ?>
        <button type="button" class="sec" id="d-copiar" title="Copia o texto da mensagem">📋 Copiar texto</button>
        <button type="button" class="wa grande" id="d-abrir">Abrir conversa no WhatsApp ▶</button>
        <button type="button" class="sec" id="d-pular" title="Não enviar para este cliente nesta campanha">Pular</button>
      </div>
      <p class="sub" id="d-dica">No WhatsApp, confira e aperte <strong>Enviar</strong>; volte aqui e clique de novo (ou tecle <strong>Enter</strong>) para o próximo. Atalho: <strong>P</strong> pula.</p>
    </div>
  </section>
  <section class="cartao">
    <h2 style="margin-top:0">Próximos <small id="d-nprox"></small></h2>
    <ol class="disp-lista" id="d-prox"></ol>
    <h2>Já atendidos <small id="d-nfeitos"></small></h2>
    <ul class="disp-lista" id="d-feitos"></ul>
    <p class="nota">Fora da fila: <?= $pub['sem_celular'] ?> sem celular, <?= $pub['recusaram'] ?> recusaram WhatsApp<?= $c['so_optin'] ? ', ' . $pub['sem_optin'] . ' sem autorização' : '' ?>.</p>
  </section>
</div>
<script>
(function () {
  var fila = <?= json_encode($fila, JSON_UNESCAPED_UNICODE) ?>, feitos = <?= json_encode($feitos, JSON_UNESCAPED_UNICODE) ?>;
  var total = fila.length + feitos.length, CSRF = <?= json_encode(csrf_token()) ?>, CID = <?= (int)$c['id'] ?>, IMG = <?= json_encode($img) ?>;
  var $ = function (id) { return document.getElementById(id); };
  var modo = 'app'; try { modo = localStorage.getItem('wa_modo') || 'app'; } catch (e) {}
  document.querySelectorAll('[name=d-modo]').forEach(function (r) { r.checked = r.value === modo; r.addEventListener('change', function () { modo = r.value; try { localStorage.setItem('wa_modo', modo); } catch (e) {} }); });
  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]; }); }
  function fmt(t) {
    var h = esc(t);
    h = h.replace(/```([\s\S]+?)```/g, '<code>$1</code>').replace(/(^|[^\w*])\*(?!\s)([^*\n]+?)\*(?![\w*])/g, '$1<strong>$2</strong>')
      .replace(/(^|[^\w_])_(?!\s)([^_\n]+?)_(?![\w_])/g, '$1<em>$2</em>').replace(/(^|[^\w~])~(?!\s)([^~\n]+?)~(?![\w~])/g, '$1<s>$2</s>')
      .replace(/^&gt; ?(.*)$/gm, '<span class="wa-cit">$1</span>').replace(/(https?:\/\/[^\s<]+)/g, '<a href="$1" target="_blank">$1</a>');
    return h.replace(/\n/g, '<br>');
  }
  function enviados() { return feitos.filter(function (f) { return f.sit === 'enviado'; }).length; }
  function render() {
    var at = fila[0];
    $('d-barra').style.width = (total ? (feitos.length * 100 / total) : 100) + '%';
    $('d-cont').textContent = enviados() + ' enviada(s) · ' + (feitos.length - enviados()) + ' pulada(s) · ' + fila.length + ' na fila · ' + total + ' no total';
    $('d-vazio').hidden = !!at; $('d-cliente').hidden = !at;
    if (at) {
      $('d-pos').textContent = (feitos.length + 1) + '/' + total + ' ';
      $('d-n').textContent = at.nome; $('d-tel').textContent = ' · ' + (at.telFmt || at.tel) + (at.cidade ? ' · ' + at.cidade : '');
      $('d-txt').innerHTML = fmt(at.texto);
    }
    $('d-nprox').textContent = Math.max(0, fila.length - 1);
    $('d-prox').innerHTML = fila.slice(1, 16).map(function (f) { return '<li>' + esc(f.nome) + ' <span class="sub">' + esc(f.telFmt || '') + '</span></li>'; }).join('') + (fila.length > 16 ? '<li class="sub">e mais ' + (fila.length - 16) + '</li>' : '');
    $('d-nfeitos').textContent = feitos.length;
    $('d-feitos').innerHTML = feitos.slice(0, 15).map(function (f, i) { return '<li>' + (f.sit === 'enviado' ? '✓ ' : '↷ ') + esc(f.nome) + ' <span class="sub">' + esc(f.em + ' · ' + (f.por || '')) + '</span> <button type="button" class="link" data-i="' + i + '">desfazer</button></li>'; }).join('');
    $('d-feitos').querySelectorAll('button').forEach(function (b) { b.addEventListener('click', function () { desfazer(+b.dataset.i); }); });
  }
  function marcar(cli, sit) {
    var f = new FormData(); f.append('csrf', CSRF); f.append('c', CID); f.append('cliente_id', cli); f.append('situacao', sit);
    return fetch('ia_disparo.php?c=' + CID, {method: 'POST', body: f, credentials: 'same-origin'}).then(function (r) { return r.json(); });
  }
  function avancar(sit) {
    var at = fila.shift(); if (!at) return;
    var agora = new Date(), em = ('0' + agora.getDate()).slice(-2) + '/' + ('0' + (agora.getMonth() + 1)).slice(-2) + ' ' + ('0' + agora.getHours()).slice(-2) + ':' + ('0' + agora.getMinutes()).slice(-2);
    feitos.unshift({id: at.id, nome: at.nome, sit: sit, em: em, por: <?= json_encode($quem) ?>, item: at});
    render(); marcar(at.id, sit).catch(function () { alert('Sem conexão: a marcação de ' + at.nome + ' não foi gravada. Recarregue a página.'); });
  }
  function desfazer(i) {
    var f = feitos[i]; if (!f) return;
    marcar(f.id, 'desfazer').then(function () { location.reload(); });
  }
  function abrir() {
    var at = fila[0]; if (!at) return;
    var t = encodeURIComponent(at.texto);
    if (modo === 'web') window.open('https://web.whatsapp.com/send?phone=' + at.tel + '&text=' + t, 'wa_vinicola');
    else window.location.href = 'whatsapp://send?phone=' + at.tel + '&text=' + t;
    if ($('d-auto').checked) avancar('enviado');
    else { var b = $('d-abrir'); b.textContent = 'Marcar como enviado e ir para o próximo ✓'; b.dataset.marcar = '1'; }
  }
  $('d-abrir').addEventListener('click', function () {
    if (this.dataset.marcar) { delete this.dataset.marcar; this.textContent = 'Abrir conversa no WhatsApp ▶'; avancar('enviado'); return; }
    abrir();
  });
  $('d-pular').addEventListener('click', function () { avancar('pulado'); });
  $('d-copiar').addEventListener('click', function () { var at = fila[0]; if (at && navigator.clipboard) navigator.clipboard.writeText(at.texto).then(function () { $('d-copiar').textContent = '✓ Texto copiado'; setTimeout(function () { $('d-copiar').textContent = '📋 Copiar texto'; }, 1500); }); });
  if ($('d-img')) $('d-img').addEventListener('click', function () {
    var b = this;
    if (!window.ClipboardItem || !navigator.clipboard || !navigator.clipboard.write) { alert('Este navegador não copia imagens. Use o Chrome ou o Edge.'); return; }
    var pngBlob = fetch(IMG, {credentials: 'same-origin'}).then(function (r) { return r.blob(); }).then(createImageBitmap).then(function (bmp) {
      var cv = document.createElement('canvas'); cv.width = bmp.width; cv.height = bmp.height; cv.getContext('2d').drawImage(bmp, 0, 0);
      return new Promise(function (ok) { cv.toBlob(ok, 'image/png'); });
    });
    navigator.clipboard.write([new ClipboardItem({'image/png': pngBlob})]).then(function () { b.textContent = '✓ Imagem copiada: cole com Ctrl+V'; setTimeout(function () { b.textContent = '📋 Copiar imagem'; }, 2500); })
      .catch(function () { alert('Não foi possível copiar a imagem neste navegador.'); });
  });
  document.addEventListener('keydown', function (e) {
    if (/INPUT|TEXTAREA|SELECT/.test(document.activeElement.tagName) || e.ctrlKey || e.metaKey || e.altKey) return;
    if (e.key === 'Enter') { e.preventDefault(); $('d-abrir').click(); }
    if (e.key === 'p' || e.key === 'P') { e.preventDefault(); avancar('pulado'); }
  });
  render();
})();
</script>
<?php require __DIR__ . '/inc/rodape.php'; ?>
