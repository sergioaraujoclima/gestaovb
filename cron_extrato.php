<?php
// Envio automático do extrato mensal do CD. Só roda pela linha de comando (cron da hospedagem).
// Agende para o dia 1 de cada mês: envia o extrato do mês anterior às vinícolas com entregas.
// Uso manual: php cron_extrato.php 2026-10   (mês específico)
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Acesso negado.'); }
require __DIR__ . '/inc/app.php';
$mes = (isset($argv[1]) && preg_match('/^\d{4}-\d{2}$/', $argv[1])) ? $argv[1] : date('Y-m', strtotime('first day of last month'));
$sistema = ['id' => null, 'nome' => 'Sistema (automático)'];
$ja = db()->prepare('SELECT COUNT(*) FROM cd_extratos WHERE vinicola_id = ? AND mes = ?');
foreach (db()->query('SELECT id, nome FROM cd_vinicolas ORDER BY nome') as $v) {
    $x = extrato_cd($v['id'], $mes);
    $ja->execute([$v['id'], $mes]);
    if (!$x['qtd']) { echo "{$v['nome']}: sem entregas\n"; continue; }
    if ($ja->fetchColumn()) { echo "{$v['nome']}: extrato já enviado\n"; continue; }
    echo $v['nome'] . ': ' . (enviar_extrato_cd($v['id'], $mes, $sistema) ? 'enviado' : 'FALHA NO ENVIO') . "\n";
}
