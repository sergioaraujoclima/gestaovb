<?php
// Endereços públicos usados nos e-mails de campanha (sem login): abertura (o), clique (c) e descadastro (d).
// Todos levam assinatura, para ninguém registrar cliques nem descadastrar outra pessoa trocando números no link.
require __DIR__ . '/inc/app.php';
$a = (string)($_GET['a'] ?? $_POST['a'] ?? ''); $k = preg_replace('/[^A-Za-z0-9]/', '', (string)($_GET['k'] ?? $_POST['k'] ?? ''));
$cid = (int)($_GET['c'] ?? $_POST['c'] ?? 0); $sig = (string)($_GET['s'] ?? $_POST['s'] ?? '');
$u = (string)($_GET['u'] ?? '');
$url = $u !== '' ? (string)base64_decode(strtr($u, '-_', '+/')) : '';
$c = null;
if ($k !== '' && camp_v12()) { $s = db()->prepare('SELECT * FROM crm_campanhas WHERE token = ?'); $s->execute([$k]); $c = $s->fetch() ?: null; }
$valido = $c && $cid && hash_equals(assinatura($a, $k, $cid, $url), $sig);
$marcar = function ($col) use ($c, $cid) {
    db()->prepare("UPDATE crm_campanha_envios SET $col = ? WHERE campanha_id = ? AND cliente_id = ? AND $col IS NULL")->execute([date('Y-m-d H:i:s'), $c['id'], $cid]);
};

if ($a === 'o') {   // pixel de abertura
    if ($valido) $marcar('aberto_em');
    header('Content-Type: image/gif'); header('Cache-Control: no-store');
    echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'); exit;
}
if ($a === 'c') {   // clique: registra e segue para o destino
    if ($valido && preg_match('~^(https?://|mailto:|tel:)~i', $url)) { $marcar('clicado_em'); $marcar('aberto_em'); header('Location: ' . $url, true, 302); exit; }
    header('Location: https://www.vinicolabrasilia.com.br', true, 302); exit;
}
if ($a === 'd') {   // descadastro: página personalizada (link do rodapé) e um clique do Gmail/Yahoo (POST sem "pagina")
    $cli = null;
    if ($valido) { $s = db()->prepare('SELECT * FROM clientes WHERE id = ?'); $s->execute([$cid]); $cli = $s->fetch() ?: null; }
    $mudar = function ($sair) use ($c, $cid, $cli) {
        if (!$cli) return;
        db()->prepare('UPDATE clientes SET aceita_email = ?, consentimento_em = ?, consentimento_origem = ? WHERE id = ?')
            ->execute([$sair ? 0 : 1, date('Y-m-d H:i:s'), mb_substr(($sair ? 'Descadastro pelo e-mail' : 'Desfez o descadastro') . ' (campanha #' . $c['id'] . ')', 0, 60), $cid]);
        registrar_log('cliente', $cid, $sair ? 'Descadastro de e-mail' : 'Descadastro de e-mail desfeito',
            ($sair ? 'Pelo link da campanha "' : 'O cliente pediu para continuar recebendo, na página da campanha "') . $c['titulo'] . '"', ['id' => null, 'nome' => 'O próprio cliente (link do e-mail)']);
        if (function_exists('grupos_recalcular_cliente')) grupos_recalcular_cliente($cid);
    };
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (empty($_POST['pagina'])) { if ($valido) $mudar(true); http_response_code($valido ? 200 : 400); echo $valido ? 'ok' : 'invalido'; exit; }   // um clique (RFC 8058)
        $op = ($_POST['op'] ?? '') === 'voltar' ? 'voltar' : 'sair';
        if ($valido && $cli) $mudar($op === 'sair');
        if (!empty($_POST['js'])) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => (bool)($valido && $cli), 'op' => $op]); exit; }
        $estado = $valido && $cli ? $op : 'invalido';
    } else {
        // Abrir o link não descadastra sozinho: a página faz isso por script ao carregar (os robôs que conferem links nos e-mails não disparam o descadastro).
        $estado = !$valido || !$cli ? 'invalido' : ((string)$cli['aceita_email'] === '0' ? 'sair' : 'abrir');
    }
    $nome = $cli ? nome_saudacao($cli['nome'], $cli['tipo_pessoa'] ?? 'PF') : '';
    $email = $cli ? (string)$cli['email'] : '';
    $site = 'https://www.vinicolabrasilia.com.br';
    $campos = '<input type="hidden" name="a" value="d"><input type="hidden" name="k" value="' . e($k) . '"><input type="hidden" name="c" value="' . (int)$cid . '"><input type="hidden" name="s" value="' . e($sig) . '"><input type="hidden" name="pagina" value="1">';
    header('Cache-Control: no-store');
    ?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title>E-mails da Vinícola Brasília</title>
<style>
:root{--vinho:#5c1a2b;--vinho2:#7a2439;--fundo:#f6f3ef;--txt:#2a2326;--sub:#766;--borda:#e6ddd4}
*{box-sizing:border-box}body{margin:0;font:16px/1.6 -apple-system,"Segoe UI",Roboto,Arial,sans-serif;background:var(--fundo);color:var(--txt)}
.topo{background:var(--vinho);color:#fff;text-align:center;padding:26px 16px}.topo a{color:#fff;text-decoration:none;font:600 26px Georgia,"Times New Roman",serif;letter-spacing:.3px}
.topo small{display:block;font:13px/1.4 -apple-system,"Segoe UI",Arial,sans-serif;opacity:.8;margin-top:4px;letter-spacing:1px;text-transform:uppercase}
.c{max-width:540px;width:calc(100% - 32px);margin:-14px auto 40px;background:#fff;border-radius:12px;padding:32px 28px;box-shadow:0 6px 24px rgba(60,20,30,.08);position:relative}
h1{font:600 24px/1.3 Georgia,"Times New Roman",serif;color:var(--vinho);margin:0 0 14px}
.email{display:inline-block;background:#f4eee8;border-radius:6px;padding:2px 8px;font-weight:600;word-break:break-all}
.sep{border:0;border-top:1px solid var(--borda);margin:24px 0}
.sub{color:var(--sub);font-size:14px}
button,.btn{font:inherit;font-size:15px;border-radius:8px;padding:11px 18px;cursor:pointer;display:inline-block;text-decoration:none;border:1px solid var(--vinho);background:#fff;color:var(--vinho)}
button.pri,.btn.pri{background:var(--vinho);color:#fff}button:hover,.btn:hover{background:var(--vinho2);color:#fff;border-color:var(--vinho2)}
.acoes{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}
[hidden]{display:none!important}
.rod{text-align:center;color:var(--sub);font-size:12px;padding:0 16px 30px}.rod a{color:var(--sub)}
</style></head>
<body>
<header class="topo"><a href="<?= $site ?>">Vinícola Brasília</a><small>Vinhos do Cerrado</small></header>
<main class="c">
<?php if ($estado === 'invalido'): ?>
  <h1>Link não reconhecido</h1>
  <p>Não conseguimos identificar o seu cadastro por este link. Se quiser deixar de receber nossos e-mails, responda qualquer mensagem nossa com a palavra <strong>descadastrar</strong> que a gente cuida disso.</p>
  <div class="acoes"><a class="btn pri" href="<?= $site ?>">Ir para o site</a></div>
<?php else: ?>
  <section id="st-abrir"<?= $estado === 'abrir' ? '' : ' hidden' ?>>
    <h1>Um momento, <?= e($nome) ?>…</h1>
    <p>Estamos tirando o e-mail <span class="email"><?= e($email) ?></span> da nossa lista.</p>
    <noscript><form method="post"><?= $campos ?><input type="hidden" name="op" value="sair"><button class="pri">Confirmar: não quero mais receber</button></form></noscript>
  </section>
  <section id="st-sair"<?= $estado === 'sair' ? '' : ' hidden' ?>>
    <h1>Pronto, <?= e($nome) ?>. Você não vai mais receber nossos e-mails.</h1>
    <p>O endereço <span class="email"><?= e($email) ?></span> saiu da lista de novidades e campanhas da Vinícola Brasília. Obrigado por ter acompanhado a gente até aqui. 🍷</p>
    <p class="sub">Mensagens ligadas a uma compra ou a uma reserva de tour que você fizer continuam chegando normalmente.</p>
    <hr class="sep">
    <p><strong>Foi sem querer ou mudou de ideia?</strong><br>É só desfazer: você continua recebendo nossas novidades, lançamentos e condições especiais.</p>
    <form method="post" class="acoes js-op"><?= $campos ?><input type="hidden" name="op" value="voltar"><button class="pri">Desfazer: quero continuar recebendo</button><a class="btn" href="<?= $site ?>">Ir para o site</a></form>
  </section>
  <section id="st-voltar"<?= $estado === 'voltar' ? '' : ' hidden' ?>>
    <h1>Que bom que você fica, <?= e($nome) ?>! 🥂</h1>
    <p>O endereço <span class="email"><?= e($email) ?></span> continua na nossa lista. Você segue recebendo as novidades da Vinícola Brasília.</p>
    <hr class="sep">
    <form method="post" class="acoes js-op"><?= $campos ?><input type="hidden" name="op" value="sair"><a class="btn pri" href="<?= $site ?>">Visitar o site</a><button>Prefiro não receber mais</button></form>
  </section>
  <p id="st-erro" class="sub" hidden>Não conseguimos concluir agora. Verifique sua conexão e tente de novo.</p>
<?php endif; ?>
</main>
<p class="rod">Vinícola Brasília · <a href="<?= $site ?>">vinicolabrasilia.com.br</a><br>Aprecie com moderação.</p>
<?php if ($estado !== 'invalido'): ?>
<script>
(function () {
  var base = <?= json_encode(['a' => 'd', 'k' => $k, 'c' => (int)$cid, 's' => $sig, 'pagina' => '1', 'js' => '1']) ?>;
  function mostra(id) { ['st-abrir', 'st-sair', 'st-voltar'].forEach(function (x) { document.getElementById(x).hidden = x !== id; }); }
  function envia(op) {
    var f = new FormData(); Object.keys(base).forEach(function (k) { f.append(k, base[k]); }); f.append('op', op);
    document.getElementById('st-erro').hidden = true;
    return fetch('e.php', {method: 'POST', body: f, credentials: 'omit'}).then(function (r) { return r.json(); }).then(function (j) {
      if (!j.ok) throw 0; mostra(op === 'sair' ? 'st-sair' : 'st-voltar'); window.scrollTo(0, 0);
    }).catch(function () { document.getElementById('st-erro').hidden = false; });
  }
  document.querySelectorAll('form.js-op').forEach(function (fm) {
    fm.addEventListener('submit', function (ev) { ev.preventDefault(); envia(fm.querySelector('[name=op]').value); });
  });
  if (!document.getElementById('st-abrir').hidden) envia('sair');
})();
</script>
<?php endif; ?>
</body></html><?php
    exit;
}
header('Location: https://www.vinicolabrasilia.com.br', true, 302);
