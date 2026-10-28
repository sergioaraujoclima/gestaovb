<?php
// Sugestões da IA · tarefas automáticas. Só roda pela linha de comando (Tarefas Cron do cPanel).
//   De hora em hora:        php /home3/vinico02/public_html/gestao/cron_ia.php alertas
//   Todo dia às 7h:         php /home3/vinico02/public_html/gestao/cron_ia.php diario     (alertas + e-mail do dia)
//   Segunda-feira às 7h30:  php /home3/vinico02/public_html/gestao/cron_ia.php semanal    (análise da IA + e-mail da semana)
//   A cada 15 minutos:      php /home3/vinico02/public_html/gestao/cron_ia.php emails     (lotes das campanhas de e-mail com envio automático)
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Acesso negado.'); }
require __DIR__ . '/inc/app.php';
if (!ia_v10()) exit("Migração v10 ainda não aplicada.\n");
$GLOBALS['USUARIO'] = ['id' => null, 'nome' => 'Sistema (automático)'];
$modo = $argv[1] ?? 'alertas';
$t = microtime(true);
if ($modo === 'emails') {
    if (!function_exists('camp_v12') || !camp_v12()) exit("Migração v12 ainda não aplicada.\n");
    $hoje = date('Y-m-d');
    foreach (ia_q("SELECT c.*, g.nome grupo_nome FROM crm_campanhas c JOIN crm_grupos g ON g.id = c.grupo_id WHERE c.canal = 'email' AND c.status = 'liberada' AND c.auto_envio = 1 ORDER BY c.id") as $c) {
        if (($c['inicio'] && $c['inicio'] > $hoje) || ($c['fim'] && $c['fim'] < $hoje)) continue;
        if (email_restante_hora() <= 0) { echo "Limite de e-mails da hora atingido.\n"; break; }
        try { [$ok, $fal, $rest] = email_lote($c, max(5, min(100, (int)config('email_lote', '25'))), 'Sistema (automático)'); echo "{$c['titulo']}: $ok enviado(s), $fal falha(s), $rest na fila\n"; }
        catch (Throwable $ex) { echo "{$c['titulo']}: " . $ex->getMessage() . "\n"; }
    }
    printf("Concluído em %.1f s\n", microtime(true) - $t);
    exit;
}
$erros = ia_gerar_alertas(true);
echo 'Alertas gerados' . ($erros ? ' com erros: ' . implode(' | ', $erros) : '') . "\n";
if ($modo === 'diario') echo 'E-mails do dia enviados: ' . ia_email_diario() . "\n";
if ($modo === 'semanal') {
    try {
        $id = ia_analise_semanal();
        echo "Análise da semana: #$id\n";
        echo 'E-mails da semana enviados: ' . ia_email_semanal($id) . "\n";
    } catch (Throwable $ex) { echo 'Análise da IA não gerada: ' . $ex->getMessage() . "\n"; }
}
printf("Concluído em %.1f s\n", microtime(true) - $t);
