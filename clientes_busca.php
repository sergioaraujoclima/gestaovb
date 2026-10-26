<?php
// Busca de clientes para os campos de seleção (tours): devolve até 15 resultados em JSON.
require __DIR__ . '/inc/app.php';
exigir_login();
header('Content-Type: application/json; charset=utf-8');
if (!pode_ver_tours() && !pode_ver_crm()) { http_response_code(403); echo '[]'; exit; }
$q = texto_limpo($_GET['q'] ?? '');
if (mb_strlen($q) < 2) { echo '[]'; exit; }
$w = ['nome LIKE ?', 'telefone LIKE ?', 'email LIKE ?']; $p = ["%$q%", "%$q%", "%$q%"];
$d = so_digitos($q);
if (strlen($d) >= 4) {
    $w[] = "REPLACE(REPLACE(cpf, '.', ''), '-', '') LIKE ?"; $p[] = "%$d%";
    if (crm_v6()) { $w[] = 'telefone_e164 LIKE ?'; $p[] = "%$d%"; }
}
$s = db()->prepare('SELECT id, nome, telefone FROM clientes WHERE ' . implode(' OR ', $w) . ' ORDER BY nome LIMIT 15');
$s->execute($p);
echo json_encode($s->fetchAll(), JSON_UNESCAPED_UNICODE);
