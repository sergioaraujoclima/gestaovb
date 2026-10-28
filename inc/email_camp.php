<?php
// ---------- Campanhas por e-mail ----------
// Editor visual (negrito, fontes, cores, tamanhos, imagens, botão...), modelo com a cara da vinícola, envio em lotes
// respeitando o limite por hora da hospedagem, medição de aberturas e cliques e descadastro com um clique (LGPD).

function camp_publico_email($c) {
    $s = db()->prepare("SELECT cl.id, cl.nome, cl.tipo_pessoa, cl.email, cl.cidade, cl.aceita_email, e.situacao, e.em, e.por, e.aberto_em, e.clicado_em, e.erro
        FROM crm_grupo_membros m JOIN clientes cl ON cl.id = m.cliente_id LEFT JOIN crm_campanha_envios e ON e.campanha_id = ? AND e.cliente_id = cl.id
        WHERE m.grupo_id = ? AND m.origem <> 'excluido' ORDER BY cl.nome");
    $s->execute([$c['id'], $c['grupo_id']]);
    $r = ['fila' => [], 'feitos' => [], 'sem_celular' => 0, 'sem_email' => 0, 'recusaram' => 0, 'sem_optin' => 0];
    foreach ($s as $x) {
        if ($x['situacao']) { $r['feitos'][] = $x; continue; }
        if (!filter_var((string)$x['email'], FILTER_VALIDATE_EMAIL)) { $r['sem_email']++; continue; }
        if (recusou($x['aceita_email'])) { $r['recusaram']++; continue; }
        if ($c['so_optin'] && (string)$x['aceita_email'] !== '1') { $r['sem_optin']++; continue; }
        $r['fila'][] = $x;
    }
    return $r;
}

// ---------- Links assinados (abertura, clique, descadastro) ----------
function segredo_links() {
    $s = (string)config('segredo_links', '');
    if (strlen($s) < 32) { $s = bin2hex(random_bytes(24)); config_salvar('segredo_links', $s); }
    return $s;
}
function assinatura(...$partes) { return substr(hash_hmac('sha256', implode('|', $partes), segredo_links()), 0, 16); }
function email_link($acao, $c, $cid, $url = '') {
    $q = ['a' => $acao, 'k' => $c['token'], 'c' => (int)$cid];
    if ($url !== '') $q['u'] = rtrim(strtr(base64_encode($url), '+/', '-_'), '=');
    $q['s'] = assinatura($acao, $c['token'], (int)$cid, $url);
    return url_base() . '/e.php?' . http_build_query($q);
}

// ---------- HTML seguro ----------
// Só fica o que é seguro e funciona em e-mail: nada de script, formulário, iframe ou eventos; links só http(s), mailto e tel.
function email_sanitizar($html) {
    $html = trim((string)$html);
    if ($html === '') return '';
    $tags = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'strike', 'h1', 'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'a', 'img', 'span', 'div', 'font', 'hr', 'blockquote',
        'table', 'tbody', 'thead', 'tr', 'td', 'th', 'center', 'sub', 'sup', 'small', 'big'];
    $attrs = ['style', 'href', 'src', 'alt', 'width', 'height', 'align', 'color', 'size', 'face', 'target', 'title', 'border', 'cellpadding', 'cellspacing', 'bgcolor', 'valign', 'colspan', 'rowspan'];
    $d = new DOMDocument();
    libxml_use_internal_errors(true);
    $d->loadHTML('<?xml encoding="utf-8"?><div id="raiz">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    $limpar = function ($no) use (&$limpar, $tags, $attrs) {
        for ($i = $no->childNodes->length - 1; $i >= 0; $i--) {
            $f = $no->childNodes->item($i);
            if ($f->nodeType === XML_COMMENT_NODE) { $no->removeChild($f); continue; }
            if ($f->nodeType !== XML_ELEMENT_NODE) continue;
            $t = strtolower($f->nodeName);
            if (in_array($t, ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'textarea', 'select', 'svg', 'math', 'link', 'meta', 'base'], true)) { $no->removeChild($f); continue; }
            $limpar($f);
            if (!in_array($t, $tags, true)) {   // tag desconhecida: fica o conteúdo
                while ($f->firstChild) $no->insertBefore($f->firstChild, $f);
                $no->removeChild($f); continue;
            }
            for ($j = $f->attributes->length - 1; $j >= 0; $j--) {
                $a = $f->attributes->item($j); $n = strtolower($a->nodeName); $v = trim($a->nodeValue);
                $ok = in_array($n, $attrs, true);
                if ($ok && in_array($n, ['href', 'src'], true)) $ok = (bool)preg_match($n === 'href' ? '~^(https?://|mailto:|tel:|\{)~i' : '~^https?://~i', $v);
                if ($ok && $n === 'style') { $ok = !preg_match('/expression|javascript:|url\s*\(|behavior|@import/i', $v); }
                if (!$ok) $f->removeAttribute($a->nodeName);
            }
            if ($t === 'a') $f->setAttribute('target', '_blank');
            if ($t === 'img') { $st = $f->getAttribute('style'); if (stripos($st, 'max-width') === false) $f->setAttribute('style', trim($st . ';max-width:100%;height:auto', ';')); }
        }
    };
    $raiz = $d->getElementById('raiz');
    $limpar($raiz);
    $out = '';
    foreach ($raiz->childNodes as $f) $out .= $d->saveHTML($f);
    return $out;
}

// ---------- Montagem do e-mail ----------
function email_vars($cli) {
    $pj = ($cli['tipo_pessoa'] ?? 'PF') === 'PJ';
    return ['{nome}' => nome_saudacao($cli['nome'], $pj ? 'PJ' : 'PF'), '{nome_completo}' => $pj ? trim($cli['nome']) : nome_proprio($cli['nome']), '{cidade}' => (string)($cli['cidade'] ?? '')];
}
// Devolve [assunto, html, texto]. Com $rastrear, os links passam pelo contador de cliques e entra o pixel de abertura.
function email_montar($c, $cli, $rastrear = true) {
    $v = email_vars($cli); $ve = array_map('e', $v);
    $cid = (int)($cli['id'] ?? 0);
    $rastrear = $rastrear && $cid > 0;
    $cor = preg_match('/^#[0-9a-f]{6}$/i', (string)$c['cor']) ? $c['cor'] : '#5c1a2b';
    $corpo = strtr(email_sanitizar($c['html']), $ve);
    if ($rastrear) $corpo = preg_replace_callback('~href="(https?://[^"]+)"~i', fn($m) => 'href="' . e(email_link('c', $c, $cid, html_entity_decode($m[1]))) . '"', $corpo);
    $botao = '';
    if ($c['botao_texto'] && preg_match('~^https?://~i', (string)$c['botao_link'])) {
        $href = $rastrear ? email_link('c', $c, $cid, $c['botao_link']) : $c['botao_link'];
        $botao = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:24px auto 8px"><tr><td bgcolor="' . $cor . '" style="border-radius:6px">'
            . '<a href="' . e($href) . '" target="_blank" style="display:inline-block;padding:14px 28px;font:bold 16px Arial,sans-serif;color:#ffffff;text-decoration:none;border-radius:6px">' . e(strtr($c['botao_texto'], $v)) . '</a></td></tr></table>';
    }
    $img = $c['imagem'] ? '<tr><td style="padding:0"><img src="' . e(camp_url_imagem($c)) . '" width="600" alt="' . e($c['assunto'] ?? '') . '" style="display:block;width:100%;max-width:600px;height:auto;border:0"></td></tr>' : '';
    $sair = $cid ? email_link('d', $c, $cid) : url_base() . '/e.php';
    $pixel = $rastrear ? '<img src="' . e(email_link('o', $c, $cid)) . '" width="1" height="1" alt="" style="display:block;width:1px;height:1px;border:0">' : '';
    $pre = e(strtr((string)$c['preheader'], $v));
    $html = '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . e(strtr((string)$c['assunto'], $v)) . '</title></head>'
        . '<body style="margin:0;padding:0;background:#f6f3ef">'
        . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:#f6f3ef">' . $pre . str_repeat('&#8199;&#65279;&#847; ', 30) . '</div>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#f6f3ef"><tr><td align="center" style="padding:24px 12px">'
        . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;background:#ffffff;border-radius:10px;overflow:hidden">'
        . '<tr><td bgcolor="' . $cor . '" style="padding:22px 28px;font:600 24px Georgia,serif;color:#ffffff">Vinícola Brasília</td></tr>'
        . $img
        . '<tr><td style="padding:28px;font:16px/1.6 Arial,Helvetica,sans-serif;color:#2a2326">' . $corpo . $botao . '</td></tr>'
        . '<tr><td style="padding:18px 28px;background:#faf8f5;font:12px/1.5 Arial,Helvetica,sans-serif;color:#877">Você recebeu este e-mail porque é cliente ou visitou a Vinícola Brasília.<br>'
        . '<a href="' . e($sair) . '" style="color:#877">Não quero mais receber e-mails</a> · <a href="https://www.vinicolabrasilia.com.br" style="color:#877">vinicolabrasilia.com.br</a>' . $pixel . '</td></tr>'
        . '</table></td></tr></table></body></html>';
    // Versão em texto (para quem lê e-mail sem HTML e para os filtros de spam).
    $txt = strip_tags(preg_replace(['~<br\s*/?>~i', '~</(p|div|h[1-4]|li|tr|blockquote)>~i', '~<li[^>]*>~i'], ["\n", "\n\n", '• '], strtr($c['html'] ?? '', $ve)));
    $txt = trim(preg_replace("/\n{3,}/", "\n\n", html_entity_decode($txt, ENT_QUOTES, 'UTF-8')));
    if ($botao) $txt .= "\n\n" . strtr($c['botao_texto'], $v) . ': ' . $c['botao_link'];
    $txt .= "\n\n--\nVinícola Brasília · Para não receber mais e-mails: " . $sair;
    return [strtr((string)$c['assunto'], $v), $html, $txt];
}

// Envia um e-mail de campanha (HTML + texto), com descadastro de um clique no cabeçalho (exigido por Gmail e Yahoo).
function email_enviar_campanha($c, $para, $assunto, $html, $texto, $cid = 0) {
    if (!MAIL_ATIVO) throw new RuntimeException('O envio de e-mail está desligado no config.php (MAIL_ATIVO).');
    if (!filter_var($para, FILTER_VALIDATE_EMAIL)) return false;
    $nome = trim((string)($c['remetente'] ?: MAIL_NOME));
    $b = 'vb' . bin2hex(random_bytes(8));
    $resp = filter_var((string)config('email_resposta', ''), FILTER_VALIDATE_EMAIL) ? config('email_resposta', '') : MAIL_DE;
    $cab = "MIME-Version: 1.0\r\nFrom: " . mb_encode_mimeheader($nome, 'UTF-8', 'B') . ' <' . MAIL_DE . ">\r\nReply-To: $resp\r\n"
        . 'Message-ID: <' . bin2hex(random_bytes(10)) . '@' . substr(strrchr(MAIL_DE, '@'), 1) . ">\r\n"
        . ($cid ? 'List-Unsubscribe: <' . email_link('d', $c, $cid) . '>, <mailto:' . MAIL_DE . "?subject=descadastrar>\r\nList-Unsubscribe-Post: List-Unsubscribe=One-Click\r\n" : '')
        . "Content-Type: multipart/alternative; boundary=\"$b\"";
    $corpo = "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($texto))
        . "--$b\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html)) . "--$b--\r\n";
    return @mail($para, mb_encode_mimeheader($assunto, 'UTF-8', 'B'), $corpo, $cab, '-f' . MAIL_DE);
}

// Quantos e-mails de campanha ainda cabem nesta hora (a hospedagem compartilhada limita envios por hora).
function email_limite_hora() { return max(10, (int)config('email_limite_hora', '200')); }
function email_restante_hora() {
    $s = db()->prepare("SELECT COUNT(*) FROM crm_campanha_envios e JOIN crm_campanhas c ON c.id = e.campanha_id WHERE c.canal = 'email' AND e.situacao IN ('enviado', 'falhou') AND e.em >= ?");
    $s->execute([date('Y-m-d H:i:s', time() - 3600)]);
    return max(0, email_limite_hora() - (int)$s->fetchColumn());
}
// Envia o próximo lote de uma campanha. Devolve [enviados, falhas, pendentes, motivo da parada].
function email_lote($c, $max = 25, $quem = null) {
    $quem = $quem ?? ($GLOBALS['USUARIO']['nome'] ?? 'Sistema');
    $cabe = min($max, email_restante_hora());
    $p = camp_publico_email($c);
    if (!$p['fila']) return [0, 0, 0, 'fim'];
    if ($cabe <= 0) return [0, 0, count($p['fila']), 'limite'];
    @set_time_limit(180);
    $pdo = db(); $ok = 0; $fal = 0;
    $ins = $pdo->prepare('INSERT INTO crm_campanha_envios (campanha_id, cliente_id, situacao, em, por, erro) VALUES (?,?,?,?,?,?)');
    foreach (array_slice($p['fila'], 0, $cabe) as $cli) {
        [$as, $html, $txt] = email_montar($c, $cli);
        $res = false; $erro = null;
        try { $res = email_enviar_campanha($c, $cli['email'], $as, $html, $txt, $cli['id']); } catch (Throwable $ex) { $erro = $ex->getMessage(); }
        if (!$res && !$erro) $erro = 'o servidor de e-mail recusou o envio';
        $pdo->prepare('DELETE FROM crm_campanha_envios WHERE campanha_id = ? AND cliente_id = ?')->execute([$c['id'], $cli['id']]);
        $ins->execute([$c['id'], $cli['id'], $res ? 'enviado' : 'falhou', date('Y-m-d H:i:s'), $quem, $erro ? mb_substr($erro, 0, 200) : null]);
        $res ? $ok++ : $fal++;
        if ($erro && stripos($erro, 'MAIL_ATIVO') !== false) break;
        usleep(150000);   // um respiro entre envios para não sobrecarregar o servidor de e-mail
    }
    $rest = count($p['fila']) - $ok - $fal;
    if ($ok) $pdo->prepare("UPDATE ia_cartoes SET status = 'andamento', atualizado_em = ? WHERE chave LIKE ? AND status = 'nova'")->execute([date('Y-m-d H:i:s'), 'campanha:' . $c['id'] . ':%']);
    if (!$rest) $pdo->prepare("UPDATE ia_cartoes SET status = 'feita', resolvido_por = ?, resolvido_em = ?, comentario = 'Todos os e-mails da fila foram enviados' WHERE chave LIKE ? AND status IN ('nova', 'andamento')")
        ->execute([$quem, date('Y-m-d H:i:s'), 'campanha:' . $c['id'] . ':%']);
    registrar_log('campanha', $c['id'], 'Lote de e-mails', "$ok enviado(s), $fal falha(s), $rest na fila", ['id' => $GLOBALS['USUARIO']['id'] ?? null, 'nome' => $quem]);
    return [$ok, $fal, $rest, $rest ? ($fal && !$ok ? 'falha' : ($ok + $fal >= $cabe && $cabe < $max ? 'limite' : 'ok')) : 'fim'];
}

// ---------- Envio avulso (botão "E-mail" ao lado do WhatsApp) ----------
// Situação de cada cliente diante de uma campanha de e-mail: enviado, falhou, pode (tem e-mail e não recusou), recusou ou sem_email.
function email_situacoes($campId, array $ids) {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$campId || !$ids) return [];
    $in = implode(',', $ids); $r = [];
    foreach (ia_q("SELECT id, email, aceita_email FROM clientes WHERE id IN ($in)") as $x)
        $r[(int)$x['id']] = ['st' => !filter_var((string)$x['email'], FILTER_VALIDATE_EMAIL) ? 'sem_email' : (recusou($x['aceita_email']) ? 'recusou' : 'pode')];
    foreach (ia_q("SELECT cliente_id, situacao, em, por FROM crm_campanha_envios WHERE campanha_id = ? AND cliente_id IN ($in)", [(int)$campId]) as $x)
        if (isset($r[(int)$x['cliente_id']]) && in_array($x['situacao'], ['enviado', 'falhou'], true))
            $r[(int)$x['cliente_id']] = ['st' => $x['situacao'], 'em' => date('d/m H:i', strtotime($x['em'])), 'por' => $x['por']];
    return $r;
}
// Envia a campanha de e-mail para um cliente só. Devolve [ok, mensagem]. Não repete quem já recebeu e respeita o limite por hora.
function email_individual($c, $cliId, $quem) {
    if (!$c || camp_canal($c) !== 'email' || $c['status'] !== 'liberada') return [false, 'A campanha de e-mail não está liberada para envio.'];
    $cli = linha('clientes', (int)$cliId);
    if (!$cli) return [false, 'Cliente não encontrado.'];
    if (!filter_var((string)$cli['email'], FILTER_VALIDATE_EMAIL)) return [false, 'O cliente não tem e-mail válido no cadastro.'];
    if (recusou($cli['aceita_email'])) return [false, 'O cliente não aceita e-mails de marketing.'];
    $s = db()->prepare("SELECT em FROM crm_campanha_envios WHERE campanha_id = ? AND cliente_id = ? AND situacao = 'enviado'"); $s->execute([$c['id'], $cli['id']]);
    if ($em = $s->fetchColumn()) return [false, 'Este e-mail já foi enviado em ' . date('d/m H:i', strtotime($em)) . '.'];
    if (email_restante_hora() <= 0) return [false, 'Limite de e-mails desta hora atingido. Tente de novo mais tarde.'];
    [$as, $html, $txt] = email_montar($c, $cli);
    $res = false; $erro = null;
    try { $res = email_enviar_campanha($c, $cli['email'], $as, $html, $txt, $cli['id']); } catch (Throwable $ex) { $erro = $ex->getMessage(); }
    if (!$res && !$erro) $erro = 'o servidor de e-mail recusou o envio';
    $pdo = db();
    $pdo->prepare('DELETE FROM crm_campanha_envios WHERE campanha_id = ? AND cliente_id = ?')->execute([$c['id'], $cli['id']]);
    $pdo->prepare('INSERT INTO crm_campanha_envios (campanha_id, cliente_id, situacao, em, por, erro) VALUES (?,?,?,?,?,?)')
        ->execute([$c['id'], $cli['id'], $res ? 'enviado' : 'falhou', date('Y-m-d H:i:s'), $quem, $erro ? mb_substr($erro, 0, 200) : null]);
    registrar_log('campanha', $c['id'], 'E-mail individual', $cli['nome'] . ' <' . $cli['email'] . '>: ' . ($res ? 'enviado' : 'falhou (' . $erro . ')'));
    return [$res, $res ? 'enviado' : 'Falha no envio: ' . $erro];
}

// ---------- Editor visual ----------
function editor_email($nome, $valor, $urlUpload = null) {
    static $n = 0; $n++; $id = 'em-ed-' . $n;
    $emojis = '🍷 🥂 🍾 🍇 🧀 🍽️ 🎉 🎁 🎂 ✨ 🎄 💐 🌹 ❤️ 💛 😊 😍 🥰 🙌 👏 🙏 🤝 ☀️ 🌿 🌸 📍 📅 ⏰ 📞 ✅ 👉 🛒 🏷️ 🚚 📦 🆕 ⭐ 🏆';
    ob_start(); ?>
<div class="em-editor" id="<?= $id ?>">
  <div class="em-barra">
    <select data-cmd="formatBlock" title="Estilo do parágrafo"><option value="p">Parágrafo</option><option value="h1">Título</option><option value="h2">Subtítulo</option><option value="h3">Título pequeno</option><option value="blockquote">Destaque</option></select>
    <select data-cmd="fontName" title="Fonte"><option value="">Fonte</option><option value="Arial, Helvetica, sans-serif">Arial</option><option value="Georgia, serif">Georgia</option><option value="Verdana, sans-serif">Verdana</option><option value="Trebuchet MS, sans-serif">Trebuchet</option><option value="Times New Roman, serif">Times</option><option value="Courier New, monospace">Courier</option></select>
    <select data-cmd="fontSize" title="Tamanho"><option value="">Tamanho</option><option value="2">Pequeno</option><option value="3">Normal</option><option value="4">Médio</option><option value="5">Grande</option><option value="6">Muito grande</option></select>
    <span class="sep"></span>
    <button type="button" data-cmd="bold" title="Negrito"><strong>N</strong></button>
    <button type="button" data-cmd="italic" title="Itálico"><em>I</em></button>
    <button type="button" data-cmd="underline" title="Sublinhado"><u>S</u></button>
    <button type="button" data-cmd="strikeThrough" title="Tachado"><s>T</s></button>
    <label class="cor" title="Cor do texto">A<input type="color" data-cmd="foreColor" value="#5c1a2b"></label>
    <label class="cor fundo" title="Cor de destaque (fundo)">▆<input type="color" data-cmd="hiliteColor" value="#fdeed6"></label>
    <span class="sep"></span>
    <button type="button" data-cmd="justifyLeft" title="Alinhar à esquerda">⯇</button>
    <button type="button" data-cmd="justifyCenter" title="Centralizar">≡</button>
    <button type="button" data-cmd="justifyRight" title="Alinhar à direita">⯈</button>
    <button type="button" data-cmd="insertUnorderedList" title="Lista">• Lista</button>
    <button type="button" data-cmd="insertOrderedList" title="Lista numerada">1. Lista</button>
    <span class="sep"></span>
    <button type="button" data-acao="link" title="Inserir link">🔗 Link</button>
    <button type="button" data-acao="imagem" title="Inserir imagem no texto">🖼️ Imagem</button>
    <button type="button" data-acao="botao" title="Inserir botão">▭ Botão</button>
    <button type="button" data-cmd="insertHorizontalRule" title="Linha divisória">― Linha</button>
    <button type="button" data-acao="emoji" title="Emojis">😊</button>
    <select data-acao="var" title="Dado do cliente"><option value="">+ Dado do cliente</option><option value="{nome}">Primeiro nome</option><option value="{nome_completo}">Nome completo</option><option value="{cidade}">Cidade</option></select>
    <span class="sep"></span>
    <button type="button" data-cmd="removeFormat" title="Limpar formatação">⌫ Formato</button>
    <button type="button" data-cmd="undo" title="Desfazer">↶</button>
    <button type="button" data-cmd="redo" title="Refazer">↷</button>
    <button type="button" data-acao="html" title="Ver/editar o código HTML">&lt;/&gt;</button>
  </div>
  <div class="em-emojis" hidden><?php foreach (explode(' ', $emojis) as $em): ?><button type="button"><?= $em ?></button><?php endforeach; ?></div>
  <input type="file" accept="image/jpeg,image/png,image/webp" hidden class="em-arq">
  <div class="em-area" contenteditable="true"><?= $valor !== '' ? email_sanitizar($valor) : '<p>Olá, {nome}!</p><p></p>' ?></div>
  <textarea class="em-fonte" hidden spellcheck="false"></textarea>
  <textarea name="<?= e($nome) ?>" hidden class="em-valor"></textarea>
</div>
<script>
(function () {
  var box = document.getElementById(<?= json_encode($id) ?>), area = box.querySelector('.em-area'), fonte = box.querySelector('.em-fonte'), val = box.querySelector('.em-valor');
  var UP = <?= json_encode($urlUpload) ?>, faixa = null;
  try { document.execCommand('styleWithCSS', false, true); } catch (e) {}
  function guardar() { var s = window.getSelection(); if (s.rangeCount && area.contains(s.anchorNode)) faixa = s.getRangeAt(0); }
  function restaurar() { area.focus(); if (faixa) { var s = window.getSelection(); s.removeAllRanges(); s.addRange(faixa); } }
  function cmd(c, v) { restaurar(); document.execCommand(c, false, v === undefined ? null : v); guardar(); sync(); }
  function sync() { val.value = fonte.hidden ? area.innerHTML : fonte.value; }
  ['keyup', 'mouseup', 'input', 'blur'].forEach(function (ev) { area.addEventListener(ev, function () { guardar(); sync(); }); });
  box.querySelectorAll('button[data-cmd]').forEach(function (b) { b.addEventListener('mousedown', function (e) { e.preventDefault(); }); b.addEventListener('click', function () { cmd(b.dataset.cmd); }); });
  box.querySelectorAll('select[data-cmd]').forEach(function (s) { s.addEventListener('change', function () { if (s.value) cmd(s.dataset.cmd, s.dataset.cmd === 'formatBlock' ? '<' + s.value + '>' : s.value); if (s.dataset.cmd !== 'formatBlock') s.value = ''; }); });
  box.querySelectorAll('input[data-cmd]').forEach(function (i) { i.addEventListener('input', function () { cmd(i.dataset.cmd, i.value); }); });
  box.querySelector('[data-acao=link]').addEventListener('click', function () { guardar(); var u = prompt('Endereço do link (https://...)', 'https://'); if (u && /^(https?:\/\/|mailto:|tel:)/i.test(u)) cmd('createLink', u); });
  box.querySelector('[data-acao=botao]').addEventListener('click', function () {
    guardar(); var t = prompt('Texto do botão', 'Quero conhecer'); if (!t) return; var u = prompt('Link do botão (https://...)', 'https://'); if (!u || !/^https?:\/\//i.test(u)) return;
    var esc = function (s) { return s.replace(/[&<>"]/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]; }); };
    cmd('insertHTML', '<p style="text-align:center"><a href="' + esc(u) + '" style="display:inline-block;background:#5c1a2b;color:#ffffff;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:bold">' + esc(t) + '</a></p><p></p>');
  });
  var arq = box.querySelector('.em-arq');
  box.querySelector('[data-acao=imagem]').addEventListener('click', function () { guardar(); if (!UP) { alert('Salve o rascunho uma vez para poder inserir imagens no texto.'); return; } arq.click(); });
  arq.addEventListener('change', function () {
    if (!arq.files[0]) return; var f = new FormData(); f.append('imagem', arq.files[0]);
    var csrf = box.closest('form').querySelector('[name=csrf]'); if (csrf) f.append('csrf', csrf.value);
    Object.keys(UP.campos || {}).forEach(function (k) { f.append(k, UP.campos[k]); });
    fetch(UP.url || UP, {method: 'POST', body: f, credentials: 'same-origin'}).then(function (r) { return r.json(); }).then(function (j) {
      if (!j.ok) { alert(j.erro || 'Não foi possível enviar a imagem.'); return; }
      cmd('insertHTML', '<p><img src="' + j.url + '" alt="" style="max-width:100%;height:auto"></p>');
    }).catch(function () { alert('Falha no envio da imagem.'); }); arq.value = '';
  });
  var pe = box.querySelector('.em-emojis');
  box.querySelector('[data-acao=emoji]').addEventListener('click', function () { guardar(); pe.hidden = !pe.hidden; });
  pe.querySelectorAll('button').forEach(function (b) { b.addEventListener('mousedown', function (e) { e.preventDefault(); }); b.addEventListener('click', function () { cmd('insertText', b.textContent); }); });
  box.querySelector('[data-acao=var]').addEventListener('change', function () { if (this.value) cmd('insertText', this.value); this.value = ''; });
  box.querySelector('[data-acao=html]').addEventListener('click', function () {
    if (fonte.hidden) { fonte.value = area.innerHTML; fonte.hidden = false; area.hidden = true; } else { area.innerHTML = fonte.value; fonte.hidden = true; area.hidden = false; }
    sync();
  });
  fonte.addEventListener('input', sync);
  box.closest('form').addEventListener('submit', sync);
  sync();
})();
</script>
<?php
    return ob_get_clean();
}
