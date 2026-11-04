<?php
// Comprovante de pagamento de uma cobrança de entregas do CD (só para quem vê a Baixa Pagamentos Entregas).
require __DIR__ . '/inc/app.php';
exigir_login();
if (!pode_ver_cd_pagamentos() || !cd_v15()) negar();
$st = db()->prepare('SELECT comprovante FROM cd_cobrancas WHERE id = ?');
$st->execute([(int)($_GET['id'] ?? 0)]);
$nome = basename((string)$st->fetchColumn());
$caminho = DIR_COMPROVANTES . '/' . $nome;
if ($nome === '' || !is_file($caminho)) { http_response_code(404); exit('Comprovante não encontrado.'); }
header('Content-Type: ' . (new finfo(FILEINFO_MIME_TYPE))->file($caminho));
header('Content-Disposition: inline; filename="' . $nome . '"');
header('Content-Length: ' . filesize($caminho));
header('X-Content-Type-Options: nosniff');
readfile($caminho);
