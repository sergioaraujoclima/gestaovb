<?php
require __DIR__ . '/inc/app.php';
exigir_login();
exigir_tours();
$st = db()->prepare('SELECT comprovante FROM tour_vendas WHERE id = ?');
$st->execute([(int)($_GET['id'] ?? 0)]);
$nome = basename((string)$st->fetchColumn());
$caminho = DIR_COMPROVANTES . '/' . $nome;
if ($nome === '' || !is_file($caminho)) { http_response_code(404); exit('Comprovante não encontrado.'); }
header('Content-Type: ' . (new finfo(FILEINFO_MIME_TYPE))->file($caminho));
header('Content-Disposition: inline; filename="' . $nome . '"');
header('Content-Length: ' . filesize($caminho));
header('X-Content-Type-Options: nosniff');
readfile($caminho);
