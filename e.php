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
if ($a === 'd') {   // descadastro (link do rodapé e botão "cancelar inscrição" do Gmail/Yahoo, que chega por POST)
    $feito = false;
    if ($valido && ($_SERVER['REQUEST_METHOD'] === 'POST')) {
        $s = db()->prepare('SELECT * FROM clientes WHERE id = ?'); $s->execute([$cid]); $cli = $s->fetch();
        if ($cli) {
            db()->prepare('UPDATE clientes SET aceita_email = 0, consentimento_em = ?, consentimento_origem = ? WHERE id = ?')
                ->execute([date('Y-m-d H:i:s'), mb_substr('Descadastro pelo e-mail (campanha #' . $c['id'] . ')', 0, 60), $cid]);
            registrar_log('cliente', $cid, 'Descadastro de e-mail', 'Pelo link da campanha "' . $c['titulo'] . '"', ['id' => null, 'nome' => 'O próprio cliente (link do e-mail)']);
            if (function_exists('grupos_recalcular_cliente')) grupos_recalcular_cliente($cid);
        }
        $feito = true;
    }
    if ($feito && empty($_POST['pagina'])) { http_response_code(200); echo 'ok'; exit; }   // one-click (RFC 8058)
    ?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>E-mails da Vinícola Brasília</title><meta name="robots" content="noindex">
<style>body{margin:0;font:16px/1.5 system-ui,sans-serif;background:#f6f3ef;color:#2a2326}.c{max-width:480px;margin:40px auto;background:#fff;border-radius:10px;padding:28px}h1{font:600 22px Georgia,serif;color:#5c1a2b;margin:0 0 12px}button{font:inherit;background:#5c1a2b;color:#fff;border:0;border-radius:6px;padding:11px 18px;cursor:pointer}</style></head>
<body><div class="c"><h1>Vinícola Brasília</h1>
<?php if (!$valido): ?><p>Este link não é válido. Se quiser deixar de receber nossos e-mails, responda qualquer mensagem com a palavra "descadastrar".</p>
<?php elseif ($feito): ?><p>Pronto: você não vai mais receber nossos e-mails de novidades e campanhas. Se mudar de ideia, é só nos avisar.</p>
<?php else: ?><p>Quer deixar de receber os e-mails de novidades e campanhas da Vinícola Brasília?</p>
<form method="post"><input type="hidden" name="a" value="d"><input type="hidden" name="k" value="<?= e($k) ?>"><input type="hidden" name="c" value="<?= (int)$cid ?>"><input type="hidden" name="s" value="<?= e($sig) ?>"><input type="hidden" name="pagina" value="1"><button>Sim, não quero mais receber</button></form>
<?php endif; ?></div></body></html><?php
    exit;
}
header('Location: https://www.vinicolabrasilia.com.br', true, 302);
