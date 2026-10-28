<?php
// Cartão público de uma campanha (o link que vai na mensagem do WhatsApp). Não exige login.
// O WhatsApp lê as etiquetas og:* desta página e mostra a imagem, o título e a descrição como prévia do link.
require __DIR__ . '/inc/app.php';
$k = preg_replace('/[^A-Za-z0-9]/', '', (string)($_GET['k'] ?? ''));
$c = null;
if ($k !== '' && camp_v11()) {
    $s = db()->prepare('SELECT c.*, g.nome grupo_nome FROM crm_campanhas c JOIN crm_grupos g ON g.id = c.grupo_id WHERE c.token = ?');
    $s->execute([$k]); $c = $s->fetch() ?: null;
}
if (isset($_GET['img']) || isset($_GET['f'])) {
    // img = imagem principal; f = imagem inserida no texto do e-mail (sempre um arquivo desta campanha).
    $f = basename((string)($_GET['f'] ?? ''));
    $arq = !$c ? '' : (isset($_GET['f']) ? (preg_match('/^' . (int)$c['id'] . '-[a-f0-9]{12}\.jpg$/', $f) ? DIR_CAMPANHAS . '/' . $f : '') : ($c['imagem'] ? DIR_CAMPANHAS . '/' . basename($c['imagem']) : ''));
    if (!$arq || !is_file($arq)) { http_response_code(404); exit; }
    header('Content-Type: image/jpeg');
    header('Content-Length: ' . filesize($arq));
    header('Cache-Control: public, max-age=604800');
    readfile($arq); exit;
}
if (!$c) { http_response_code(404); }
$titulo = $c ? ($c['cartao_titulo'] ?: $c['titulo']) : 'Vinícola Brasília';
$texto = $c ? ($c['cartao_texto'] ?: mb_strimwidth(trim(preg_replace('/[*_~`]/', '', strtok((string)$c['texto'], "\n"))), 0, 200, '…')) : '';
$img = $c ? camp_url_imagem($c) : null;
$zap = preg_replace('/\D/', '', (string)(function_exists('config') && ia_v10() ? config('whatsapp_vinicola', '') : ''));
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($titulo) ?> · Vinícola Brasília</title>
<meta property="og:type" content="website">
<meta property="og:site_name" content="Vinícola Brasília">
<meta property="og:title" content="<?= e($titulo) ?>">
<meta property="og:description" content="<?= e($texto) ?>">
<?php if ($img): ?><meta property="og:image" content="<?= e($img) ?>"><meta property="og:image:type" content="image/jpeg"><meta name="twitter:card" content="summary_large_image"><?php endif; ?>
<meta name="robots" content="noindex">
<style>
body{margin:0;font:16px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:#f6f3ef;color:#2a2326}
.c{max-width:560px;margin:0 auto;padding:20px 16px 40px}
.m{font:600 22px Georgia,serif;color:#5c1a2b;margin:6px 0 16px}
img{width:100%;height:auto;border-radius:12px;display:block}
h1{font:600 24px/1.25 Georgia,serif;color:#5c1a2b;margin:18px 0 8px}
p{margin:0 0 16px;color:#544}
.b{display:inline-block;background:#1f7a4d;color:#fff;text-decoration:none;padding:12px 18px;border-radius:8px;font-weight:600;margin:4px 6px 4px 0}
.b.s{background:#5c1a2b}
</style>
</head>
<body><div class="c">
  <div class="m">Vinícola Brasília</div>
  <?php if (!$c): ?><p>Esta página não está mais disponível.</p><?php else: ?>
  <?php if ($img): ?><img src="<?= e($img) ?>" alt="<?= e($titulo) ?>"><?php endif; ?>
  <h1><?= e($titulo) ?></h1>
  <?php if ($texto): ?><p><?= e($texto) ?></p><?php endif; ?>
  <?php if ($zap): ?><a class="b" href="https://wa.me/<?= e($zap) ?>?text=<?= rawurlencode('Olá! Vi a mensagem "' . $titulo . '" e quero saber mais.') ?>">Falar no WhatsApp</a><?php endif; ?>
  <a class="b s" href="https://www.vinicolabrasilia.com.br">Conhecer a vinícola</a>
  <?php endif; ?>
</div></body>
</html>
