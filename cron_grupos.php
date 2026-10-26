<?php
// Opcional: recalcula os grupos de campanha toda madrugada, para ninguém esperar esse cálculo ao abrir uma tela.
// Agende no cPanel (Tarefas Cron), por exemplo às 3h:  php /home3/vinico02/public_html/gestao/cron_grupos.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Acesso negado.'); }
require __DIR__ . '/inc/app.php';
if (!crm_v7()) exit("Migração v7 ainda não aplicada.\n");
$GLOBALS['USUARIO'] = ['id' => null, 'nome' => 'Sistema (automático)'];
$t = microtime(true);
crm_presumir_sexo_faltante();
$ctx = grupos_contexto();
foreach (grupos_todos() as $g) { [$e, $s] = grupos_recalcular($g, $ctx); echo $g['nome'] . ": +$e -$s\n"; }
printf("Concluído em %.1f s\n", microtime(true) - $t);
