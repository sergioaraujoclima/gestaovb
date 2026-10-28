<?php
// ---------- Mensagens de campanha dos grupos (WhatsApp sem API) ----------
// Sem a API oficial da Meta, cada mensagem é aberta no WhatsApp (aplicativo do computador ou WhatsApp Web) já com o texto
// pronto, um cliente por vez; a pessoa só confere e aperta Enviar. A imagem vai como cartão: um link público que o WhatsApp
// mostra com a foto, o título e a descrição (prévia do link). Também dá para copiar a imagem e colar na conversa.

define('DIR_CAMPANHAS', dirname(__DIR__) . '/uploads/campanhas');

function camp_v11() {
    static $ok = null;
    if ($ok === null) {
        try { db()->query('SELECT 1 FROM crm_campanhas WHERE 1 = 0'); db()->query('SELECT 1 FROM crm_campanha_envios WHERE 1 = 0'); $ok = true; }
        catch (Throwable $ex) { $ok = false; }
    }
    return $ok;
}
function nome_status_campanha($s) { return ['rascunho' => 'Rascunho', 'liberada' => 'Liberada para envio', 'encerrada' => 'Encerrada'][$s] ?? $s; }
function camp_por_id($id) { $s = db()->prepare('SELECT c.*, g.nome grupo_nome FROM crm_campanhas c JOIN crm_grupos g ON g.id = c.grupo_id WHERE c.id = ?'); $s->execute([(int)$id]); return $s->fetch() ?: null; }
// Migração v12: canal (WhatsApp ou e-mail), campos do e-mail, falhas, aberturas e cliques.
function camp_v12() {
    static $ok = null;
    if ($ok === null) {
        try { db()->query('SELECT c.canal, c.html, c.auto_envio FROM crm_campanhas c WHERE 1 = 0'); db()->query('SELECT e.aberto_em, e.erro FROM crm_campanha_envios e WHERE 1 = 0'); $ok = true; }
        catch (Throwable $ex) { $ok = false; }
    }
    return $ok;
}
function camp_canal($c) { return $c['canal'] ?? 'whatsapp'; }
function camp_do_grupo($gid, $canal = 'whatsapp') {
    if (!camp_v12() && $canal !== 'whatsapp') return null;
    $s = db()->prepare("SELECT * FROM crm_campanhas WHERE grupo_id = ? AND status <> 'encerrada'" . (camp_v12() ? ' AND canal = ?' : '') . ' ORDER BY id DESC LIMIT 1');
    $s->execute(camp_v12() ? [(int)$gid, $canal] : [(int)$gid]);
    return $s->fetch() ?: null;
}
function camp_token() { return substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(9))), 0, 10); }

// Endereço público do sistema (para o cartão). Usa o endereço desta requisição; no cron, o endereço padrão.
function url_base() {
    if (!empty($_SERVER['HTTP_HOST'])) {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        return ($https ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
    }
    return function_exists('ia_url_sistema') ? ia_url_sistema() : 'https://www.vinicolabrasilia.com.br/gestao';
}
function camp_link_cartao($c) { return url_base() . '/c.php?k=' . rawurlencode($c['token']); }
function camp_url_imagem($c) { return $c['imagem'] ? url_base() . '/c.php?k=' . rawurlencode($c['token']) . '&img=' . substr(md5($c['imagem']), 0, 6) : null; }

// Destinatários: membros do grupo com celular válido, sem quem recusou WhatsApp (e, se marcado, só quem autorizou).
function camp_publico($c) {
    if (camp_canal($c) === 'email') return camp_publico_email($c);
    $s = db()->prepare("SELECT cl.id, cl.nome, cl.tipo_pessoa, cl.telefone, cl.telefone_e164, cl.cidade, cl.aceita_whatsapp, e.situacao, e.em, e.por
        FROM crm_grupo_membros m JOIN clientes cl ON cl.id = m.cliente_id LEFT JOIN crm_campanha_envios e ON e.campanha_id = ? AND e.cliente_id = cl.id
        WHERE m.grupo_id = ? AND m.origem <> 'excluido' ORDER BY cl.nome");
    $s->execute([$c['id'], $c['grupo_id']]);
    $r = ['fila' => [], 'feitos' => [], 'sem_celular' => 0, 'recusaram' => 0, 'sem_optin' => 0];
    foreach ($s as $x) {
        if ($x['situacao']) { $r['feitos'][] = $x; continue; }
        if (!e_celular($x['telefone_e164'])) { $r['sem_celular']++; continue; }
        if (recusou($x['aceita_whatsapp'])) { $r['recusaram']++; continue; }
        if ($c['so_optin'] && (string)$x['aceita_whatsapp'] !== '1') { $r['sem_optin']++; continue; }
        $r['fila'][] = $x;
    }
    return $r;
}
function camp_contagem($c) {
    $p = camp_publico($c);
    $env = count(array_filter($p['feitos'], fn($x) => $x['situacao'] === 'enviado'));
    $fal = count(array_filter($p['feitos'], fn($x) => $x['situacao'] === 'falhou'));
    return ['pendentes' => count($p['fila']), 'enviados' => $env, 'falhas' => $fal, 'pulados' => count($p['feitos']) - $env - $fal, 'total' => count($p['fila']) + count($p['feitos']),
        'aberturas' => count(array_filter($p['feitos'], fn($x) => !empty($x['aberto_em']))), 'cliques' => count(array_filter($p['feitos'], fn($x) => !empty($x['clicado_em'])))] + $p;
}
// Texto final para um cliente: troca as variáveis e acrescenta o link do cartão (quando ligado).
function camp_texto($c, $cli) {
    $pj = ($cli['tipo_pessoa'] ?? 'PF') === 'PJ';
    $t = strtr((string)$c['texto'], ['{nome}' => nome_saudacao($cli['nome'], $pj ? 'PJ' : 'PF'), '{nome_completo}' => $pj ? trim($cli['nome']) : nome_proprio($cli['nome']), '{cidade}' => (string)($cli['cidade'] ?? '')]);
    if ($c['cartao']) $t = rtrim($t) . "\n\n" . camp_link_cartao($c);
    return trim($t);
}

// Imagem: reduz para no máximo 1200 px e grava em JPEG (o WhatsApp mostra melhor imagens leves).
function camp_salvar_imagem($arq, $campId) {
    if (!$arq || $arq['error'] === UPLOAD_ERR_NO_FILE) return null;
    if ($arq['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Falha no envio da imagem.');
    if ($arq['size'] > 8 * 1024 * 1024) throw new RuntimeException('Imagem maior que 8 MB.');
    $info = @getimagesize($arq['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) throw new RuntimeException('Envie uma imagem JPG, PNG ou WEBP.');
    if (!is_dir(DIR_CAMPANHAS)) mkdir(DIR_CAMPANHAS, 0755, true);
    $nome = (int)$campId . '-' . bin2hex(random_bytes(6)) . '.jpg';
    $dest = DIR_CAMPANHAS . '/' . $nome;
    if (function_exists('imagecreatefromstring') && ($im = @imagecreatefromstring((string)file_get_contents($arq['tmp_name'])))) {
        $w = imagesx($im); $h = imagesy($im); $f = min(1, 1200 / max($w, $h));
        $nw = max(1, (int)round($w * $f)); $nh = max(1, (int)round($h * $f));
        $out = imagecreatetruecolor($nw, $nh);
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));   // fundo branco para PNG transparente
        imagecopyresampled($out, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagejpeg($out, $dest, 82);
        imagedestroy($im); imagedestroy($out);
    } else {
        if ($info[2] !== IMAGETYPE_JPEG) throw new RuntimeException('O servidor não converte imagens; envie em JPG.');
        move_uploaded_file($arq['tmp_name'], $dest);
    }
    return $nome;
}
function camp_apagar_imagem($nome) { if ($nome && preg_match('/^[0-9]+-[a-f0-9]{12}\.jpg$/', $nome)) @unlink(DIR_CAMPANHAS . '/' . $nome); }

// Mostra o texto como o WhatsApp mostra (*negrito*, _itálico_, ~tachado~, ```mono```, listas e citações).
function wa_formatar_html($t) {
    $h = e($t);
    $h = preg_replace('/```(.+?)```/s', '<code>$1</code>', $h);
    $h = preg_replace('/(?<![\w*])\*(?!\s)([^*\n]+?)(?<!\s)\*(?![\w*])/u', '<strong>$1</strong>', $h);
    $h = preg_replace('/(?<![\w_])_(?!\s)([^_\n]+?)(?<!\s)_(?![\w_])/u', '<em>$1</em>', $h);
    $h = preg_replace('/(?<![\w~])~(?!\s)([^~\n]+?)(?<!\s)~(?![\w~])/u', '<s>$1</s>', $h);
    $h = preg_replace('/^&gt; ?(.*)$/m', '<span class="wa-cit">$1</span>', $h);
    $h = preg_replace('~(https?://[^\s<]+)~', '<a href="$1" target="_blank" rel="noopener">$1</a>', $h);
    return nl2br($h);
}

// Caixa de edição com barra de formatação do WhatsApp, emojis, variáveis e prévia ao lado.
function editor_whatsapp($nome, $valor, $imagemUrl = null, $cartao = null) {
    static $n = 0; $n++; $id = 'wa-ed-' . $n;
    $emojis = ['Vinho e mesa' => '🍷 🥂 🍾 🍇 🧀 🍽️ 🥩 🍝 🍫 🍓 🫒 🥖', 'Festa' => '🎉 🎊 🎁 🎂 🥳 ✨ 🎈 🎄 🎆 💐 🌹 🎶',
        'Pessoas' => '😊 😍 🥰 😉 🤩 😋 🙌 👏 🙏 👋 🤝 💪', 'Amor' => '❤️ 💕 💖 💛 💚 💜 🧡 🤍 😘 💌', 'Natureza' => '☀️ 🌅 🌄 🌿 🍃 🌸 🌻 🌙 ⭐ 🌧️ ❄️ 🔥',
        'Avisos' => '📍 📅 ⏰ 📞 📲 💬 ✅ ☑️ ⚠️ 👉 ➡️ 🔔', 'Compras' => '🛒 🏷️ 💰 💳 🚚 📦 🆕 🔝 💯 ⭐ 🏆 🎯'];
    ob_start(); ?>
<div class="wa-editor" id="<?= $id ?>">
  <div class="wa-col">
    <div class="wa-barra">
      <button type="button" data-f="*" title="Negrito: *texto*"><strong>N</strong></button>
      <button type="button" data-f="_" title="Itálico: _texto_"><em>I</em></button>
      <button type="button" data-f="~" title="Tachado: ~texto~"><s>T</s></button>
      <button type="button" data-f="```" title="Monoespaçado: ```texto```"><code>{ }</code></button>
      <button type="button" data-l="- " title="Lista com marcadores">• Lista</button>
      <button type="button" data-l="1. " title="Lista numerada">1. Lista</button>
      <button type="button" data-l="> " title="Citação">❝ Citação</button>
      <button type="button" class="wa-emoji-bt" title="Emojis">😊 Emojis</button>
      <select class="wa-var" title="Inserir dado do cliente"><option value="">+ Dado do cliente</option><option value="{nome}">Primeiro nome (empresa: nome)</option><option value="{nome_completo}">Nome completo</option><option value="{cidade}">Cidade</option></select>
    </div>
    <div class="wa-emojis" hidden><?php foreach ($emojis as $g => $l): ?><div><span><?= e($g) ?></span><?php foreach (explode(' ', $l) as $em): ?><button type="button"><?= $em ?></button><?php endforeach; ?></div><?php endforeach; ?>
      <p class="sub">Mais emojis: no Windows, tecla <strong>Windows + .</strong> (ponto); no Mac, <strong>Ctrl + Cmd + Espaço</strong>.</p></div>
    <textarea name="<?= e($nome) ?>" rows="9" maxlength="3500"><?= e($valor) ?></textarea>
    <div class="wa-rodape"><span class="wa-cont">0 caracteres</span><span class="sub">O WhatsApp não aceita outra fonte, tamanho ou cor: só negrito, itálico, tachado e monoespaçado, que já aparecem na prévia.</span></div>
  </div>
  <div class="wa-previa-col">
    <span class="rot">Prévia no WhatsApp</span>
    <div class="wa-tela"><div class="wa-bolha">
      <img class="wa-img"<?= $imagemUrl ? ' src="' . e($imagemUrl) . '"' : '' ?> alt=""<?= $imagemUrl && empty($cartao['ligado']) ? '' : ' hidden' ?>>
      <div class="wa-txt"></div>
      <div class="wa-card"<?= !empty($cartao['ligado']) ? '' : ' hidden' ?>><?php if ($imagemUrl): ?><img src="<?= e($imagemUrl) ?>" alt=""><?php endif; ?><strong class="wa-card-t"><?= e($cartao['titulo'] ?? '') ?></strong><span class="wa-card-d"><?= e($cartao['texto'] ?? '') ?></span><small><?= e(parse_url($cartao['link'] ?? 'https://www.vinicolabrasilia.com.br', PHP_URL_HOST)) ?></small></div>
      <span class="wa-hora"><?= date('H:i') ?> ✓✓</span>
    </div></div>
  </div>
</div>
<script>
(function () {
  var box = document.getElementById(<?= json_encode($id) ?>), ta = box.querySelector('textarea'), pv = box.querySelector('.wa-txt'), ct = box.querySelector('.wa-cont');
  var exemplo = {'{nome}': 'Maria', '{nome_completo}': 'Maria da Silva', '{cidade}': 'Brasília'};
  function esc(s) { return s.replace(/[&<>"]/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]; }); }
  function fmt(t) {
    Object.keys(exemplo).forEach(function (k) { t = t.split(k).join(exemplo[k]); });
    var h = esc(t);
    h = h.replace(/```([\s\S]+?)```/g, '<code>$1</code>');
    h = h.replace(/(^|[^\w*])\*(?!\s)([^*\n]+?)\*(?![\w*])/g, '$1<strong>$2</strong>');
    h = h.replace(/(^|[^\w_])_(?!\s)([^_\n]+?)_(?![\w_])/g, '$1<em>$2</em>');
    h = h.replace(/(^|[^\w~])~(?!\s)([^~\n]+?)~(?![\w~])/g, '$1<s>$2</s>');
    h = h.replace(/^&gt; ?(.*)$/gm, '<span class="wa-cit">$1</span>');
    return h.replace(/\n/g, '<br>');
  }
  function at() { pv.innerHTML = fmt(ta.value) || '<span class="sub">Escreva a mensagem…</span>'; ct.textContent = ta.value.length + ' caracteres'; }
  function inserir(antes, depois) {
    var a = ta.selectionStart, b = ta.selectionEnd, sel = ta.value.substring(a, b);
    ta.setRangeText(antes + sel + (depois || ''), a, b, sel ? 'end' : 'end');
    if (!sel && depois) ta.selectionStart = ta.selectionEnd = a + antes.length;
    ta.focus(); at();
  }
  box.querySelectorAll('[data-f]').forEach(function (b) { b.addEventListener('click', function () { inserir(b.dataset.f, b.dataset.f); }); });
  box.querySelectorAll('[data-l]').forEach(function (b) { b.addEventListener('click', function () {
    var a = ta.selectionStart, b2 = ta.selectionEnd, ini = ta.value.lastIndexOf('\n', a - 1) + 1, trecho = ta.value.substring(ini, b2), n = 0;
    var novo = trecho.split('\n').map(function (l) { n++; return (b.dataset.l === '1. ' ? n + '. ' : b.dataset.l) + l; }).join('\n');
    ta.setRangeText(novo, ini, b2, 'end'); ta.focus(); at();
  }); });
  var pe = box.querySelector('.wa-emojis');
  box.querySelector('.wa-emoji-bt').addEventListener('click', function () { pe.hidden = !pe.hidden; });
  pe.querySelectorAll('button').forEach(function (b) { b.addEventListener('click', function () { inserir(b.textContent); }); });
  box.querySelector('.wa-var').addEventListener('change', function () { if (this.value) inserir(this.value); this.value = ''; });
  ta.addEventListener('input', at); at();
  // Cartão com link: liga/desliga a prévia e acompanha o título e a descrição digitados no formulário.
  // Os campos do cartão ficam depois do editor no formulário: liga quando a página terminar de carregar.
  document.addEventListener('DOMContentLoaded', function () {
  var form = box.closest('form'), ck = form && form.querySelector('[name=cartao]'), card = box.querySelector('.wa-card'), img = box.querySelector('.wa-img');
  function cartao() {
    if (!ck) return;
    card.hidden = !ck.checked; img.hidden = ck.checked || !img.getAttribute('src');
    var t = form.querySelector('[name=cartao_titulo]'), d = form.querySelector('[name=cartao_texto]'), tit = form.querySelector('[name=titulo]');
    box.querySelector('.wa-card-t').textContent = (t && t.value) || (tit && tit.value) || 'Vinícola Brasília';
    box.querySelector('.wa-card-d').textContent = (d && d.value) || '';
  }
  if (form) form.querySelectorAll('[name=cartao],[name=cartao_titulo],[name=cartao_texto],[name=titulo]').forEach(function (x) { x.addEventListener('input', cartao); x.addEventListener('change', cartao); });
  var arq = form && form.querySelector('[name=imagem]');
  if (arq) arq.addEventListener('change', function () { if (arq.files[0]) { var u = URL.createObjectURL(arq.files[0]); img.src = u; var ci = card.querySelector('img'); if (!ci) { ci = document.createElement('img'); card.insertBefore(ci, card.firstChild); } ci.src = u; cartao(); } });
  cartao();
  });
})();
</script>
<?php
    return ob_get_clean();
}

// Contatos do grupo em vCard (para salvar no celular da vinícola: assim os clientes aparecem com nome no WhatsApp
// e podem entrar numa lista de transmissão do WhatsApp Business).
function camp_vcard($nome, $tel, $grupo) {
    $esc = fn($s) => str_replace([',', ';', "\n"], ['\,', '\;', ' '], (string)$s);
    return "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:" . $esc($nome) . "\r\nN:" . $esc($nome) . ";;;;\r\nTEL;TYPE=CELL:" . $tel . "\r\nNOTE:" . $esc('Vinícola Brasília · ' . $grupo) . "\r\nEND:VCARD\r\n";
}

require_once __DIR__ . '/email_camp.php';
