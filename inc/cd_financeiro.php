<?php
// ---------- Centro de Distribuição · baixa de pagamentos das entregas (perfil Financeiro) ----------
// Cada mês fechado vira uma cobrança por vinícola (entregas concluídas no mês). O Financeiro envia o extrato,
// cobra e, quando a vinícola paga, dá a baixa anexando o comprovante. Sem comprovante, a cobrança segue em aberto
// e aparece em Sugestões da IA › Alertas e sugestões para o Financeiro e o Gerente geral.

function cd_v15() {
    static $ok = null;
    if ($ok === null) {
        try { db()->query('SELECT 1 FROM cd_cobrancas WHERE 1 = 0'); $ok = true; }
        catch (Throwable $ex) { $ok = false; }
    }
    return $ok;
}
function pode_ver_cd_pagamentos() { return tem_perfil('admin', 'financeiro', 'gerente_geral'); }
function pode_baixar_cd() { return tem_perfil('admin', 'financeiro'); }
function cd_dia_vencimento() { return max(1, min(28, (int)(function_exists('config') ? config('cd_dia_vencimento', '10') : 10))); }
// Vencimento: dia configurado do mês seguinte ao das entregas.
function cd_vencimento($mes) { return date('Y-m-', strtotime($mes . '-01 +1 month')) . sprintf('%02d', cd_dia_vencimento()); }

// Atualiza as cobranças dos meses fechados (últimos 24) a partir das entregas concluídas.
// Cobrança aberta acompanha o valor das entregas; paga fica como está (e a tela avisa se o valor mudou depois da baixa).
function cd_cobrancas_sincronizar() {
    if (!cd_v15()) return;
    static $feito = false; if ($feito) return; $feito = true;
    $pdo = db(); $agora = date('Y-m-d H:i:s');
    $ini = date('Y-m-01 00:00:00', strtotime('first day of -24 months')); $fim = date('Y-m-01 00:00:00');
    $s = $pdo->prepare("SELECT vinicola_id, SUBSTR(entregue_em, 1, 7) mes, COUNT(*) qtd, COALESCE(SUM(valor), 0) total FROM cd_entregas
        WHERE status = 'entregue' AND entregue_em >= ? AND entregue_em < ? GROUP BY vinicola_id, SUBSTR(entregue_em, 1, 7)");
    $s->execute([$ini, $fim]);
    $atual = [];
    foreach ($s as $r) $atual[(int)$r['vinicola_id'] . '|' . $r['mes']] = $r;
    $exist = [];
    $s = $pdo->prepare('SELECT * FROM cd_cobrancas WHERE mes >= ?'); $s->execute([substr($ini, 0, 7)]);
    foreach ($s as $r) $exist[(int)$r['vinicola_id'] . '|' . $r['mes']] = $r;
    $ins = $pdo->prepare('INSERT INTO cd_cobrancas (vinicola_id, mes, qtd, total, vencimento, criado_em) VALUES (?,?,?,?,?,?)');
    $upd = $pdo->prepare('UPDATE cd_cobrancas SET qtd = ?, total = ?, atualizado_em = ? WHERE id = ?');
    foreach ($atual as $k => $r) {
        if (!isset($exist[$k])) { $ins->execute([(int)$r['vinicola_id'], $r['mes'], (int)$r['qtd'], round((float)$r['total'], 2), cd_vencimento($r['mes']), $agora]); continue; }
        $e = $exist[$k];
        if ((int)$e['qtd'] !== (int)$r['qtd'] || abs((float)$e['total'] - (float)$r['total']) > 0.004) $upd->execute([(int)$r['qtd'], round((float)$r['total'], 2), $agora, $e['id']]);
    }
    // Mês que ficou sem entregas (ex.: entrega desfeita): a cobrança em aberto some; a paga fica, zerada, para não perder o comprovante.
    foreach ($exist as $k => $e) if (!isset($atual[$k])) {
        if ($e['status'] === 'aberta') $pdo->prepare('DELETE FROM cd_cobrancas WHERE id = ?')->execute([$e['id']]);
        elseif ((float)$e['total'] != 0) $upd->execute([0, 0, $agora, $e['id']]);
    }
}
function cd_cobrancas($filtro = 'pendentes', $mes = '') {
    if (!cd_v15()) return [];
    cd_cobrancas_sincronizar();
    $w = []; $p = [];
    if ($filtro === 'pendentes') $w[] = "c.status = 'aberta'";
    elseif ($filtro === 'pagas') $w[] = "c.status = 'paga'";
    if (preg_match('/^\d{4}-\d{2}$/', $mes)) { $w[] = 'c.mes = ?'; $p[] = $mes; }
    $s = db()->prepare('SELECT c.*, v.nome vinicola, v.responsavel, v.email, x.enviado_em extrato_em FROM cd_cobrancas c LEFT JOIN cd_vinicolas v ON v.id = c.vinicola_id
        LEFT JOIN cd_extratos x ON x.vinicola_id = c.vinicola_id AND x.mes = c.mes' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY c.status, c.mes, v.nome');
    $s->execute($p);
    return $s->fetchAll();
}
function cd_dias_atraso($c) { return $c['status'] === 'aberta' && $c['vencimento'] && $c['vencimento'] < date('Y-m-d') ? (int)((strtotime(date('Y-m-d')) - strtotime($c['vencimento'])) / 86400) : 0; }
function cd_situacao_rotulo($c) {
    if ($c['status'] === 'paga') return 'Pago em ' . data_br($c['pago_em']);
    $d = cd_dias_atraso($c);
    if ($d) return 'Vencida há ' . $d . ' dia' . ($d > 1 ? 's' : '');
    $f = (int)((strtotime($c['vencimento']) - strtotime(date('Y-m-d'))) / 86400);
    return $f === 0 ? 'Vence hoje' : 'Vence em ' . data_br($c['vencimento']);
}
