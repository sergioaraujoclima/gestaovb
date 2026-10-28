<?php
// ---------- Sugestões da IA ----------
// Duas camadas:
// 1) Regras (todo dia, de hora em hora): tours do dia, agradecimentos, datas comemorativas, recompra, B2B parado,
//    queda de vendas e tours, entregas do CD atrasadas... viram cartões para o gerente de cada área.
// 2) Análise semanal: um resumo só com números agregados (sem nome, CPF ou telefone de pessoas) vai para a IA
//    (API da Anthropic), que devolve sugestões de iniciativas por área.

function ia_v10() {
    static $ok = null;
    if ($ok === null) {
        try { foreach (['ia_cartoes', 'ia_analises', 'ia_datas', 'configuracoes'] as $t) db()->query("SELECT 1 FROM $t WHERE 1 = 0"); $ok = true; }
        catch (Throwable $ex) { $ok = false; }
    }
    return $ok;
}
function ia_areas() { return ['geral' => 'Gerente geral', 'b2b' => 'Comercial B2B', 'b2c' => 'Comercial B2C', 'enoturismo' => 'Enoturismo e eventos']; }
function ia_perfil_area() { return ['gerente_geral' => 'geral', 'gerente_b2b' => 'b2b', 'gerente_b2c' => 'b2c', 'gerente_enoturismo' => 'enoturismo']; }
// Áreas que um usuário vê: Administrador e Gerente geral veem todas; cada gerente vê a sua.
function ia_areas_de($perfis) {
    $p = expandir_perfis($perfis);
    if (array_intersect(['admin', 'gerente_geral'], $p)) return array_keys(ia_areas());
    $a = array_values(array_intersect_key(ia_perfil_area(), array_flip($p)));
    // CRM · Marketing opera as campanhas do consumidor final: vê os alertas do Comercial B2C (sem receber os e-mails de gerente).
    if (in_array('marketing', $p, true) && !in_array('b2c', $a, true)) $a[] = 'b2c';
    return $a;
}
function ia_areas_do_usuario() { return ia_areas_de(perfil()); }
function pode_ver_ia() { return (bool)ia_areas_do_usuario(); }
function exigir_ia() { if (!pode_ver_ia()) negar(); }
function ia_exigir_migracao() {
    if (ia_v10()) return;
    $titulo = 'Sugestões da IA';
    require __DIR__ . '/topo.php';
    echo '<div class="aviso erro">' . (eh_admin() ? 'O módulo Sugestões da IA precisa da migração do banco. Rode no phpMyAdmin o arquivo migracao_v10.sql (só cria tabelas novas).' : 'O módulo está em instalação. Tente novamente mais tarde.') . '</div>';
    require __DIR__ . '/rodape.php';
    exit;
}

// ---------- Configurações ----------
function ia_config_padrao() {
    return [
        'ia_modelo' => 'claude-sonnet-5-5',
        'ia_emails' => '1',
        'endereco_vinicola' => 'Vinícola Brasília',
        'link_avaliacao' => '',
        'msg_tour_chegada' => "Olá, {nome}! Tudo pronto para o seu tour hoje às {horario} na Vinícola Brasília. Estamos te esperando! 🍷\nEndereço: {endereco}\nQualquer dúvida, é só responder esta mensagem.",
        'msg_tour_amanha' => "Olá, {nome}! Passando para confirmar seu tour amanhã ({data}) às {horario} na Vinícola Brasília. Podemos contar com você? 🍷",
        'msg_tour_agradecimento' => "Olá, {nome}! Obrigado pela visita à Vinícola Brasília. Foi um prazer receber você! 🍷\nSe puder, conte como foi a experiência: {link_avaliacao}\nAté a próxima!",
        'msg_convite_clube' => "Olá, {nome}! Que bom que você conheceu a Vinícola Brasília. Que tal fazer parte do nosso clube de benefícios? Membros têm condições especiais nos vinhos e nos eventos. Posso te contar mais?",
        'msg_aniversario' => "Feliz aniversário, {nome}! 🎉 A Vinícola Brasília deseja um ano cheio de bons momentos e bons vinhos. Para comemorar, preparamos uma condição especial para você. Quer saber?",
        'msg_recompra' => "Olá, {nome}! Faz um tempo que não nos vemos por aqui. Chegaram novidades na Vinícola Brasília e separamos uma sugestão especial para você. Posso te mandar?",
        'msg_segunda_compra' => "Olá, {nome}! Obrigado pela sua compra na Vinícola Brasília. Gostou do vinho? Temos outros rótulos que combinam com o seu gosto. Posso te indicar?",
        'msg_b2b_reposicao' => "Olá! Aqui é da Vinícola Brasília. Passando para saber como estão os nossos vinhos aí na {nome}. Precisa de reposição? Temos novidades na tabela deste mês.",
        'wa_optout' => 'Se não quiser mais receber nossas mensagens, responda *N*.',
        'msg_b2b_boas_vindas' => "Olá! Aqui é da Vinícola Brasília. Obrigado pela primeira compra da {nome}! Fico à disposição para pedidos, material de divulgação e treinamento da equipe sobre os nossos vinhos.",
    ];
}
function config($chave, $padrao = null) {
    static $cache = null;
    if ($cache === null) { $cache = []; if (ia_v10()) foreach (db()->query('SELECT chave, valor FROM configuracoes') as $r) $cache[$r['chave']] = $r['valor']; }
    if (func_num_args() === 3) { $cache = null; return null; }   // config(x, y, true) limpa o cache
    $v = $cache[$chave] ?? null;
    return ($v === null || $v === '') ? ($padrao ?? (ia_config_padrao()[$chave] ?? null)) : $v;
}
function config_salvar($chave, $valor) {
    $pdo = db();
    $pdo->prepare('DELETE FROM configuracoes WHERE chave = ?')->execute([$chave]);
    $pdo->prepare('INSERT INTO configuracoes (chave, valor, atualizado_em, atualizado_por) VALUES (?,?,?,?)')->execute([$chave, $valor, date('Y-m-d H:i:s'), $GLOBALS['USUARIO']['nome'] ?? 'Sistema']);
    config($chave, null, true);
}
function ia_mensagem($modelo, $vars) {
    $vars += ['endereco' => config('endereco_vinicola'), 'link_avaliacao' => config('link_avaliacao') ?: ''];
    $t = (string)config($modelo);
    foreach ($vars as $k => $v) $t = str_replace('{' . $k . '}', (string)$v, $t);
    return trim(preg_replace('/[ \t]+\n/', "\n", $t));
}
function whatsapp_link($e164, $texto) {
    $n = preg_replace('/\D/', '', (string)$e164);
    // Direto no api.whatsapp.com: o redirecionamento do wa.me estraga os emojis (chegam como "�").
    return $n ? 'https://api.whatsapp.com/send/?phone=' . $n . '&text=' . rawurlencode($texto) : '';
}
// Linha de descadastro no fim das mensagens de marketing ("responda N"). Quem responder N é marcado à mão (Sugestões da IA › Registrar "N").
function wa_optout($t) {
    $r = trim((string)config('wa_optout'));
    if ($t === null || $t === '' || $r === '' || config('wa_optout_ligado', '1') !== '1' || mb_stripos($t, $r) !== false) return $t;
    return rtrim($t) . "\n\n" . $r;
}
// Campanha de um grupo de aniversariantes (regra "Mês de aniversário"). WhatsApp: o texto dela vale para o cartão de aniversário.
// E-mail: só a liberada, que vira o botão "E-mail" ao lado do WhatsApp.
function ia_camp_aniversario($canal = 'whatsapp') {
    if (!function_exists('camp_v11') || !camp_v11()) return null;
    $v12 = function_exists('camp_v12') && camp_v12();
    if ($canal === 'email' && !$v12) return null;
    $cond = $canal === 'email' ? "c.canal = 'email' AND c.status = 'liberada'" : "c.status <> 'encerrada' AND c.texto IS NOT NULL AND c.texto <> ''" . ($v12 ? " AND c.canal = 'whatsapp'" : '');
    foreach (ia_q("SELECT c.*, g.regras FROM crm_campanhas c JOIN crm_grupos g ON g.id = c.grupo_id WHERE g.ativo = 1 AND $cond ORDER BY c.status = 'liberada' DESC, c.id DESC") as $c)
        foreach (json_decode((string)$c['regras'], true) ?: [] as $r) if (($r['campo'] ?? '') === 'mes_aniversario') return $c;
    return null;
}
// Primeiro nome para a mensagem ("MARIA DA SILVA" → "Maria"). Empresas ficam com o nome inteiro.
function nome_saudacao($nome, $tipo = 'PF') {
    if ($tipo === 'PJ') return trim($nome);
    $p = explode(' ', trim(nome_proprio($nome)));
    return $p[0] ?? $nome;
}
function ia_contato($c, $modelo = null, $vars = [], $detalhe = '', $texto = null) {
    $tel = $c['telefone_e164'] ?? null;
    if (!$tel && !empty($c['telefone'])) [, $tel] = normalizar_telefone($c['telefone']);
    $msg = $texto ?? ($modelo ? ia_mensagem($modelo, $vars + ['nome' => nome_saudacao($c['nome'], $c['tipo_pessoa'] ?? 'PF')]) : null);
    if ($texto === null && in_array($modelo, ['msg_convite_clube', 'msg_aniversario', 'msg_recompra', 'msg_segunda_compra'], true)) $msg = wa_optout($msg);
    return ['cliente_id' => (int)$c['id'], 'nome' => $c['nome'], 'tel' => $tel, 'detalhe' => $detalhe, 'msg' => $msg,
            'recusou' => isset($c['aceita_whatsapp']) && $c['aceita_whatsapp'] !== null && $c['aceita_whatsapp'] !== '' && (int)$c['aceita_whatsapp'] === 0];
}

// Grava ou atualiza um cartão. Cartões já resolvidos (feitos ou descartados) não voltam; as marcações de "enviado" são mantidas.
function ia_cartao($c) {
    $pdo = db(); $agora = date('Y-m-d H:i:s');
    $c += ['origem' => 'regra', 'prioridade' => 'media', 'texto' => null, 'dados' => [], 'link' => null, 'quando' => null];
    $s = $pdo->prepare('SELECT id, status, dados FROM ia_cartoes WHERE chave = ?'); $s->execute([$c['chave']]); $at = $s->fetch();
    if ($at) {
        $velhos = json_decode((string)$at['dados'], true) ?: [];
        // As marcações de "enviado" seguem o cliente (não a posição na lista), porque a lista pode mudar ao longo do dia.
        $porCliente = [];
        foreach ($velhos['enviados'] ?? [] as $i => $env) if (isset($velhos['contatos'][$i]['cliente_id'])) $porCliente[(int)$velhos['contatos'][$i]['cliente_id']] = $env;
        $antigos = array_map(fn($x) => (int)($x['cliente_id'] ?? 0), $velhos['contatos'] ?? []);
        $novosIds = array_diff(array_map(fn($x) => (int)($x['cliente_id'] ?? 0), $c['dados']['contatos'] ?? []), $antigos);
        $resolvido = !in_array($at['status'], ['nova', 'andamento'], true);
        // Cartão já resolvido só volta se aparecer gente nova na lista (ex.: aniversário cadastrado depois); senão fica como está.
        if ($resolvido && !$novosIds) return (int)$at['id'];
        $env = [];
        foreach ($c['dados']['contatos'] ?? [] as $i => $x) if (isset($porCliente[(int)($x['cliente_id'] ?? 0)])) $env[$i] = $porCliente[(int)$x['cliente_id']];
        if ($env) $c['dados']['enviados'] = $env;
        $pdo->prepare('UPDATE ia_cartoes SET area = ?, tipo = ?, prioridade = ?, titulo = ?, texto = ?, dados = ?, link = ?, quando = ?, valido_ate = ?, atualizado_em = ?' . ($resolvido ? ", status = 'nova', resolvido_por = NULL, resolvido_em = NULL" : '') . ' WHERE id = ?')
            ->execute([$c['area'], $c['tipo'], $c['prioridade'], mb_substr($c['titulo'], 0, 200), $c['texto'], json_encode($c['dados'], JSON_UNESCAPED_UNICODE), $c['link'], $c['quando'], $c['valido_ate'], $agora, $at['id']]);
        return (int)$at['id'];
    }
    $pdo->prepare('INSERT INTO ia_cartoes (chave, area, tipo, origem, prioridade, titulo, texto, dados, link, quando, valido_ate, criado_em) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([mb_substr($c['chave'], 0, 120), $c['area'], $c['tipo'], $c['origem'], $c['prioridade'], mb_substr($c['titulo'], 0, 200), $c['texto'],
            json_encode($c['dados'], JSON_UNESCAPED_UNICODE), $c['link'], $c['quando'], $c['valido_ate'], $agora]);
    return (int)$pdo->lastInsertId();
}
// Some com o cartão de regra que deixou de valer (ex.: as entregas atrasadas foram resolvidas) enquanto ninguém mexeu nele.
function ia_cartao_retirar($chave) {
    db()->prepare("DELETE FROM ia_cartoes WHERE chave = ? AND origem = 'regra' AND status = 'nova'")->execute([$chave]);
}
// Algo mudou (ficha, tour, entrega, venda): os alertas são refeitos na próxima vez que alguém abrir a tela, sem esperar 1 hora.
function ia_marcar_desatualizado() {
    static $feito = false;
    if ($feito || !ia_v10()) return;
    $feito = true;
    try { db()->prepare("UPDATE configuracoes SET valor = '' WHERE chave = 'ia_ultima_regra'")->execute(); } catch (Throwable $ex) {}
}
function ia_semana($d = null) { return date('o-\SW', $d ? strtotime($d) : time()); }
function ia_q($sql, $p = []) { $s = db()->prepare($sql); $s->execute($p); return $s->fetchAll(); }
function tour_ativo_sql($a = 'v') { return "($a.estorno IS NULL OR $a.estorno IN ('nenhum', 'negado'))"; }

// ---------- Regras ----------
// Roda tudo; cada regra é isolada (uma falha não impede as outras). Sem $forcar, roda no máximo 1 vez por hora.
function ia_gerar_alertas($forcar = false) {
    if (!ia_v10()) return [];
    $ultima = config('ia_ultima_regra', '');
    // Última alteração relevante na auditoria (pelo id, para não depender do fuso do MySQL): se houve algo depois da última rodada, refaz já.
    $aud = 0;
    try { $aud = (int)db()->query("SELECT MAX(id) FROM auditoria WHERE entidade IN ('cliente', 'tour', 'entrega', 'venda', 'vendas_importacao', 'campanha')")->fetchColumn(); } catch (Throwable $ex) {}
    if (!$forcar && $ultima && strtotime($ultima) > time() - 3600 && $aud <= (int)config('ia_ultima_auditoria', '0') && config('ia_versao', '') === APP_VERSAO) return [];
    @set_time_limit(120);
    $erros = [];
    foreach (['ia_r_tours', 'ia_r_datas', 'ia_r_b2c', 'ia_r_b2b', 'ia_r_geral', 'ia_r_campanhas'] as $f) {
        try { $f(); } catch (Throwable $ex) { $erros[] = $f . ': ' . $ex->getMessage(); }
    }
    config_salvar('ia_ultima_regra', date('Y-m-d H:i:s'));
    config_salvar('ia_ultima_auditoria', (string)$aud);
    config_salvar('ia_versao', APP_VERSAO);   // sistema atualizado: os cartões são refeitos com as regras novas
    if ($erros) registrar_log('ia', 0, 'Erro nas regras de alerta', implode(' | ', $erros), ['id' => null, 'nome' => 'Sistema (automático)']);
    return $erros;
}

function ia_r_tours() {
    $hoje = date('Y-m-d'); $amanha = date('Y-m-d', strtotime('+1 day')); $ontem = date('Y-m-d', strtotime('-1 day'));
    $base = 'SELECT v.id tour_id, v.data_tour, v.horario, v.qtd, v.presenca, v.recebido, v.valor_pago, c.id, c.nome, c.telefone, c.telefone_e164, c.aceita_whatsapp, c.clube_beneficios, c.tipo_pessoa
        FROM tour_vendas v JOIN clientes c ON c.id = v.cliente_id WHERE ' . tour_ativo_sql();
    // Chegada: um cartão por horário de hoje, para mandar 1 hora antes.
    $porHora = [];
    foreach (ia_q("$base AND v.data_tour = ? AND v.presenca <> 'ausente' ORDER BY v.horario, c.nome", [$hoje]) as $t) $porHora[substr($t['horario'], 0, 5)][] = $t;
    foreach ($porHora as $h => $ts) {
        $pessoas = array_sum(array_column($ts, 'qtd'));
        $envio = date('H:i', strtotime("$hoje $h -1 hour"));
        ia_cartao(['chave' => "tour_chegada:$hoje:$h", 'area' => 'enoturismo', 'tipo' => 'tour_chegada', 'prioridade' => 'alta',
            'titulo' => "Tour de hoje às $h: avisar " . count($ts) . ' cliente(s) a partir das ' . $envio,
            'texto' => "São $pessoas pessoa(s) no tour das $h. Envie a mensagem de boas-vindas a partir das $envio (1 hora antes), com o endereço e o horário.",
            'dados' => ['contatos' => array_map(fn($t) => ia_contato($t, 'msg_tour_chegada', ['horario' => $h, 'data' => data_br($hoje)], $t['qtd'] . ' pessoa(s)' . ($t['recebido'] ? '' : ' · pagamento pendente')), $ts)],
            'link' => 'tours.php?mes=' . substr($hoje, 0, 7), 'quando' => "$hoje $envio:00", 'valido_ate' => $hoje]);
    }
    // Confirmação dos tours de amanhã.
    $ts = ia_q("$base AND v.data_tour = ? ORDER BY v.horario, c.nome", [$amanha]);
    if ($ts) ia_cartao(['chave' => "tour_amanha:$amanha", 'area' => 'enoturismo', 'tipo' => 'tour_amanha', 'prioridade' => 'media',
        'titulo' => 'Confirmar os tours de amanhã (' . data_br($amanha) . '): ' . count($ts) . ' cliente(s)',
        'texto' => 'Confirme a presença hoje para organizar guias, degustação e mesas. ' . array_sum(array_column($ts, 'qtd')) . ' pessoa(s) no total.',
        'dados' => ['contatos' => array_map(fn($t) => ia_contato($t, 'msg_tour_amanha', ['horario' => substr($t['horario'], 0, 5), 'data' => data_br($amanha)], substr($t['horario'], 0, 5) . 'h · ' . $t['qtd'] . ' pessoa(s)'), $ts)],
        'link' => 'tours.php?mes=' . substr($amanha, 0, 7), 'valido_ate' => $hoje]);
    // Agradecimento no dia seguinte.
    $ts = ia_q("$base AND v.data_tour = ? AND v.presenca <> 'ausente' ORDER BY c.nome", [$ontem]);
    if ($ts) ia_cartao(['chave' => "tour_agradecer:$ontem", 'area' => 'enoturismo', 'tipo' => 'tour_agradecimento', 'prioridade' => 'media',
        'titulo' => 'Agradecer a visita de ontem: ' . count($ts) . ' cliente(s)',
        'texto' => 'Mande o agradecimento hoje, com o link de avaliação' . (config('link_avaliacao') ? '' : ' (cadastre o link do Google em Configuração)') . '. Quem ainda não é do clube recebe o convite no cartão da semana.',
        'dados' => ['contatos' => array_map(fn($t) => ia_contato($t, 'msg_tour_agradecimento', ['data' => data_br($ontem)], $t['qtd'] . ' pessoa(s)' . ($t['presenca'] === 'pendente' ? ' · presença não marcada' : '')), $ts)],
        'link' => 'tours.php?mes=' . substr($ontem, 0, 7), 'valido_ate' => date('Y-m-d', strtotime('+1 day'))]);
    // Pagamentos de tours já realizados que continuam pendentes.
    $ts = ia_q("$base AND v.data_tour < ? AND v.recebido = 0 AND v.presenca <> 'ausente' ORDER BY v.data_tour", [$hoje]);
    $ch = 'tour_pagto:' . ia_semana();
    if ($ts) ia_cartao(['chave' => $ch, 'area' => 'enoturismo', 'tipo' => 'tour_pagamento', 'prioridade' => 'alta',
        'titulo' => count($ts) . ' tour(s) já realizado(s) com pagamento pendente (' . brl(array_sum(array_column($ts, 'valor_pago'))) . ')',
        'texto' => 'Confira se o valor entrou e marque como recebido no tour, ou cobre o cliente.',
        'dados' => ['contatos' => array_map(fn($t) => ia_contato($t, null, [], data_br($t['data_tour']) . ' · ' . brl($t['valor_pago'])), $ts)],
        'link' => 'tours.php', 'valido_ate' => date('Y-m-d', strtotime('sunday this week'))]);
    else ia_cartao_retirar($ch);
    // Convite para o clube: visitaram nos últimos 30 dias e não são do clube.
    $ts = ia_q("$base AND v.data_tour >= ? AND v.data_tour < ? AND v.presenca = 'presente' AND c.clube_beneficios = 0 ORDER BY v.data_tour DESC", [date('Y-m-d', strtotime('-30 days')), $hoje]);
    $vistos = []; $ts = array_values(array_filter($ts, function ($t) use (&$vistos) { if (isset($vistos[$t['id']])) return false; return $vistos[$t['id']] = true; }));
    if ($ts) ia_cartao(['chave' => 'tour_clube:' . ia_semana(), 'area' => 'enoturismo', 'tipo' => 'convite_clube', 'prioridade' => 'baixa',
        'titulo' => 'Convidar para o clube: ' . count($ts) . ' visitante(s) dos últimos 30 dias',
        'texto' => 'Quem acabou de visitar está no melhor momento para entrar no clube de benefícios.',
        'dados' => ['contatos' => array_map(fn($t) => ia_contato($t, 'msg_convite_clube', [], 'visitou em ' . data_br($t['data_tour'])), array_slice($ts, 0, 40))],
        'link' => 'crm_grupos.php', 'valido_ate' => date('Y-m-d', strtotime('sunday this week'))]);
}

// Próxima ocorrência de uma data comemorativa a partir de hoje.
function ia_pascoa($ano) { return date('Y-m-d', easter_date_seguro($ano)); }
function easter_date_seguro($ano) {
    if (function_exists('easter_date')) return easter_date($ano);
    $a = $ano % 19; $b = intdiv($ano, 100); $c = $ano % 100; $d = intdiv($b, 4); $e = $b % 4; $f = intdiv($b + 8, 25); $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30; $i = intdiv($c, 4); $k = $c % 4; $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7; $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $mes = intdiv($h + $l - 7 * $m + 114, 31); $dia = (($h + $l - 7 * $m + 114) % 31) + 1;
    return mktime(12, 0, 0, $mes, $dia, $ano);
}
function ia_data_no_ano($d, $ano) {
    $r = (string)$d['regra'];
    if ($r === 'fixa') return ($d['mes'] && $d['dia'] && checkdate((int)$d['mes'], (int)$d['dia'], $ano)) ? sprintf('%04d-%02d-%02d', $ano, $d['mes'], $d['dia']) : null;
    if (preg_match('/^pascoa:(-?\d+)$/', $r, $m)) return date('Y-m-d', strtotime(ia_pascoa($ano) . ' ' . ((int)$m[1] >= 0 ? '+' : '') . (int)$m[1] . ' days'));
    if (preg_match('/^nsemana:(\d{1,2}):([0-6]):([1-5])(?::\+?(-?\d+))?$/', $r, $m)) {
        $prim = mktime(12, 0, 0, (int)$m[1], 1, $ano);
        $desl = ((int)$m[2] - (int)date('w', $prim) + 7) % 7;
        $dt = strtotime('+' . ($desl + 7 * ((int)$m[3] - 1)) . ' days', $prim);
        if ((int)date('n', $dt) !== (int)$m[1]) return null;
        return date('Y-m-d', strtotime('+' . (int)($m[4] ?? 0) . ' days', $dt));
    }
    return null;
}
function ia_proxima_data($d, $hoje = null) {
    $hoje = $hoje ?? date('Y-m-d'); $ano = (int)substr($hoje, 0, 4);
    foreach ([$ano, $ano + 1] as $a) { $x = ia_data_no_ano($d, $a); if ($x && $x >= $hoje) return $x; }
    return null;
}
function ia_grupo_por_nome($nome) {
    if (!$nome || !crm_v7()) return null;
    $s = db()->prepare('SELECT id, nome FROM crm_grupos WHERE nome = ? AND ativo = 1'); $s->execute([$nome]);
    $g = $s->fetch(); if (!$g) return null;
    $c = grupos_contagens()[$g['id']] ?? ['total' => 0, 'celular' => 0, 'optin_wa' => 0, 'email' => 0];
    return $g + ['total' => (int)$c['total'], 'celular' => (int)$c['celular'], 'optin_wa' => (int)$c['optin_wa'], 'email' => (int)$c['email']];
}
function ia_r_datas() {
    $hoje = date('Y-m-d');
    foreach (ia_q('SELECT * FROM ia_datas WHERE ativo = 1') as $d) {
        $dt = ia_proxima_data($d, $hoje);
        if (!$dt) continue;
        $faltam = (int)(new DateTime($hoje))->diff(new DateTime($dt))->days;
        if ($faltam > (int)$d['antecedencia']) continue;
        $g = ia_grupo_por_nome($d['grupo_nome']);
        $txt = trim((string)$d['sugestao']);
        if ($g) $txt .= "\nPúblico sugerido: grupo \"{$g['nome']}\" com {$g['total']} cliente(s), {$g['celular']} com celular e {$g['optin_wa']} com autorização para WhatsApp de marketing.";
        elseif ($d['grupo_nome']) $txt .= "\nPúblico sugerido: grupo \"{$d['grupo_nome']}\" (ainda não existe; crie em Grupos de campanha).";
        ia_cartao(['chave' => "data:{$d['id']}:$dt", 'area' => $d['area'], 'tipo' => 'data_comemorativa',
            'prioridade' => $faltam <= 7 ? 'alta' : ($faltam <= 14 ? 'media' : 'baixa'),
            'titulo' => $d['nome'] . ($faltam === 0 ? ' é hoje' : ' em ' . $faltam . ' dia(s)') . ' (' . data_br($dt) . ' · ' . dia_semana($dt) . ')',
            'texto' => $txt, 'dados' => ['data' => $dt, 'grupo_id' => $g['id'] ?? null],
            'link' => $g ? 'crm_grupos.php?ver=' . $g['id'] : 'crm_grupos.php', 'quando' => "$dt 00:00:00", 'valido_ate' => $dt]);
    }
}

function ia_r_b2c() {
    $hoje = date('Y-m-d'); $sem = ia_semana(); $dom = date('Y-m-d', strtotime('sunday this week'));
    // Aniversariantes dos próximos 7 dias.
    $aniv = [];
    foreach (ia_q("SELECT id, nome, telefone, telefone_e164, aceita_whatsapp, data_nascimento, tipo_pessoa, cidade FROM clientes WHERE tipo_pessoa = 'PF' AND data_nascimento IS NOT NULL") as $c) {
        $d = date('Y') . substr($c['data_nascimento'], 4);
        if (!checkdate((int)substr($d, 5, 2), (int)substr($d, 8, 2), (int)substr($d, 0, 4))) continue;
        if ($d < $hoje) $d = (date('Y') + 1) . substr($c['data_nascimento'], 4);
        $faltam = (int)(new DateTime($hoje))->diff(new DateTime($d))->days;
        if ($faltam <= 7) $aniv[] = $c + ['_d' => $d, '_f' => $faltam];
    }
    usort($aniv, fn($a, $b) => $a['_f'] <=> $b['_f']);
    $campAniv = ia_camp_aniversario();   // se houver campanha de aniversariantes, o texto (e o cartão/imagem) dela vale aqui
    $campAnivEmail = ia_camp_aniversario('email');
    if ($aniv) ia_cartao(['chave' => "aniversarios:$hoje", 'area' => 'b2c', 'tipo' => 'aniversario', 'prioridade' => 'media',
        'titulo' => 'Aniversariantes dos próximos 7 dias: ' . count($aniv) . ' cliente(s)',
        'texto' => 'Uma mensagem no dia (com uma condição especial) costuma gerar visita ou compra. Quem recusou WhatsApp de marketing aparece marcado.'
            . ($campAniv ? "\nTexto: campanha \"" . $campAniv['titulo'] . '" (Grupos de campanha).' : "\nTexto: mensagem padrão de aniversário (Configuração). Crie uma campanha de WhatsApp num grupo de aniversariantes para usar o seu texto.")
            . ($campAnivEmail ? "\nE-mail: campanha \"" . $campAnivEmail['titulo'] . '".' : ''),
        'dados' => ['contatos' => array_map(fn($c) => ia_contato($c, 'msg_aniversario', [], date('d/m', strtotime($c['_d'])) . ($c['_f'] === 0 ? ' · hoje' : ' · em ' . $c['_f'] . ' dia(s)'), $campAniv ? camp_texto($campAniv, $c) : null), $aniv)] + ($campAnivEmail ? ['email_camp' => (int)$campAnivEmail['id']] : []),
        'link' => 'crm_grupos.php', 'valido_ate' => $hoje]);
    if (!function_exists('vendas_v9') || !vendas_v9()) return;
    // Recompra: pessoas que compravam com frequência e sumiram.
    $ref = db()->query('SELECT MAX(data) FROM vendas')->fetchColumn() ?: $hoje;
    $ref = substr(min($ref, date('Y-m-d H:i:s')), 0, 10);
    $hist = ia_q("SELECT c.id, c.nome, c.telefone, c.telefone_e164, c.aceita_whatsapp, c.tipo_pessoa, COUNT(*) n, SUM(v.valor_total) total, MIN(v.data) primeira, MAX(v.data) ultima
        FROM vendas v JOIN clientes c ON c.id = v.cliente_id WHERE v.valor_total > 0 GROUP BY c.id, c.nome, c.telefone, c.telefone_e164, c.aceita_whatsapp, c.tipo_pessoa");
    $dias = fn($a, $b) => (int)(new DateTime(substr($a, 0, 10)))->diff(new DateTime(substr($b, 0, 10)))->days;
    $sumiram = []; $segunda = [];
    foreach ($hist as $h) {
        if ($h['tipo_pessoa'] === 'PJ') continue;
        $desde = $dias($h['ultima'], $ref);
        if ($h['n'] >= 2) {
            $interv = max(1, $dias($h['primeira'], $h['ultima']) / ($h['n'] - 1));
            if ($desde >= max(45, 1.5 * $interv)) $sumiram[] = $h + ['_desde' => $desde, '_int' => $interv];
        } elseif ($desde >= 20 && $desde <= 60) $segunda[] = $h + ['_desde' => $desde];
    }
    usort($sumiram, fn($a, $b) => $b['total'] <=> $a['total']);
    if ($sumiram) ia_cartao(['chave' => "recompra_pf:$sem", 'area' => 'b2c', 'tipo' => 'recompra', 'prioridade' => 'media',
        'titulo' => 'Clientes recorrentes que não voltaram: ' . count($sumiram),
        'texto' => 'Compravam com frequência e passaram do intervalo de costume. Uma mensagem personalizada (com uma sugestão de vinho) traz parte deles de volta.',
        'dados' => ['contatos' => array_map(fn($h) => ia_contato($h, 'msg_recompra', [], $h['n'] . ' compras · ' . brl($h['total']) . ' · última há ' . $h['_desde'] . ' dias (costumava voltar a cada ' . round($h['_int']) . ')'), array_slice($sumiram, 0, 30))],
        'link' => 'crm_grupos.php', 'valido_ate' => $dom]);
    usort($segunda, fn($a, $b) => $b['total'] <=> $a['total']);
    if ($segunda) ia_cartao(['chave' => "segunda_compra:$sem", 'area' => 'b2c', 'tipo' => 'segunda_compra', 'prioridade' => 'baixa',
        'titulo' => 'Transformar em cliente recorrente: ' . count($segunda) . ' pessoa(s) com 1 compra recente',
        'texto' => 'Compraram uma vez entre 20 e 60 dias atrás. A segunda compra é a mais difícil: um contato pós-venda com indicação de outro rótulo ajuda.',
        'dados' => ['contatos' => array_map(fn($h) => ia_contato($h, 'msg_segunda_compra', [], brl($h['total']) . ' em ' . data_br($h['ultima'])), array_slice($segunda, 0, 30))],
        'valido_ate' => $dom]);
}

function ia_r_b2b() {
    if (!function_exists('vendas_v9') || !vendas_v9()) return;
    $sem = ia_semana(); $dom = date('Y-m-d', strtotime('sunday this week'));
    $ref = substr(min(db()->query('SELECT MAX(data) FROM vendas')->fetchColumn() ?: date('Y-m-d'), date('Y-m-d H:i:s')), 0, 10);
    $pj = ia_q("SELECT c.id, c.nome, c.telefone, c.telefone_e164, c.aceita_whatsapp, c.tipo_pessoa, c.cnpj, c.email, COUNT(*) n, SUM(v.valor_total) total, MIN(v.data) primeira, MAX(v.data) ultima
        FROM vendas v JOIN clientes c ON c.id = v.cliente_id WHERE v.valor_total > 0 AND c.tipo_pessoa = 'PJ'
        GROUP BY c.id, c.nome, c.telefone, c.telefone_e164, c.aceita_whatsapp, c.tipo_pessoa, c.cnpj, c.email");
    $dias = fn($a) => (int)(new DateTime(substr($a, 0, 10)))->diff(new DateTime($ref))->days;
    $parados = array_values(array_filter($pj, fn($c) => $c['total'] >= 1000 && $dias($c['ultima']) >= 45));
    usort($parados, fn($a, $b) => $b['total'] <=> $a['total']);
    if ($parados) ia_cartao(['chave' => "b2b_parados:$sem", 'area' => 'b2b', 'tipo' => 'b2b_reposicao', 'prioridade' => $parados[0]['total'] >= 5000 ? 'alta' : 'media',
        'titulo' => 'Empresas sem comprar há 45+ dias: ' . count($parados) . ' (somavam ' . brl(array_sum(array_column($parados, 'total'))) . ')',
        'texto' => 'Clientes B2B relevantes que pararam de pedir. Ligue ou visite: confira estoque, giro dos rótulos e ofereça a tabela do mês.',
        'dados' => ['contatos' => array_map(fn($c) => ia_contato($c, 'msg_b2b_reposicao', [], brl($c['total']) . ' em ' . $c['n'] . ' pedido(s) · último em ' . data_br($c['ultima'])), array_slice($parados, 0, 30))],
        'link' => 'vendas_painel.php?pessoa=PJ', 'valido_ate' => $dom]);
    // "Nova" só faz sentido quando há histórico anterior importado (no primeiro mês carregado, todas pareceriam novas).
    $min = substr((string)db()->query('SELECT MIN(data) FROM vendas')->fetchColumn(), 0, 10);
    $novos = $min && $min <= date('Y-m-d', strtotime("$ref -60 days")) ? array_values(array_filter($pj, fn($c) => $dias($c['primeira']) <= 30)) : [];
    usort($novos, fn($a, $b) => $b['total'] <=> $a['total']);
    if ($novos) ia_cartao(['chave' => "b2b_novos:$sem", 'area' => 'b2b', 'tipo' => 'b2b_boas_vindas', 'prioridade' => 'media',
        'titulo' => 'Novas empresas clientes nos últimos 30 dias: ' . count($novos),
        'texto' => 'Faça o pós-venda da primeira compra: material de divulgação, treinamento da equipe sobre os rótulos e combinação da próxima reposição.',
        'dados' => ['contatos' => array_map(fn($c) => ia_contato($c, 'msg_b2b_boas_vindas', [], 'primeira compra em ' . data_br($c['primeira']) . ' · ' . brl($c['total'])), $novos)],
        'link' => 'vendas_painel.php?pessoa=PJ', 'valido_ate' => $dom]);
    $incomp = array_values(array_filter($pj, fn($c) => empty($c['cnpj']) || (empty($c['telefone_e164']) && empty($c['telefone']) && empty($c['email']))));
    usort($incomp, fn($a, $b) => $b['total'] <=> $a['total']);
    if ($incomp) ia_cartao(['chave' => "b2b_cadastro:$sem", 'area' => 'b2b', 'tipo' => 'cadastro', 'prioridade' => 'baixa',
        'titulo' => 'Empresas compradoras com cadastro incompleto: ' . count($incomp),
        'texto' => 'Sem CNPJ ou sem nenhum contato: complete a ficha para poder incluí-las nas campanhas e nos alertas de reposição.',
        'dados' => ['contatos' => array_map(fn($c) => ia_contato($c, null, [], (empty($c['cnpj']) ? 'sem CNPJ' : 'sem contato') . ' · ' . brl($c['total'])), array_slice($incomp, 0, 30))],
        'valido_ate' => $dom]);
    $sv = ia_q("SELECT cliente_nome, COUNT(*) n, SUM(valor_total) total, MAX(id) id FROM vendas WHERE cliente_id IS NULL AND cliente_nome IS NOT NULL AND cliente_nome <> '' GROUP BY cliente_nome ORDER BY total DESC");
    $ch = "sem_vinculo:$sem";
    if ($sv) ia_cartao(['chave' => $ch, 'area' => 'b2b', 'tipo' => 'cadastro', 'prioridade' => 'baixa',
        'titulo' => count($sv) . ' nome(s) do ERP sem vínculo com o cadastro (' . brl(array_sum(array_column($sv, 'total'))) . ')',
        'texto' => 'Vendas que não entram nos grupos nem nos alertas porque o comprador não foi ligado a um cliente. Abra cada uma e vincule.',
        'dados' => ['itens' => array_map(fn($r) => ['rot' => $r['cliente_nome'], 'detalhe' => $r['n'] . ' venda(s) · ' . brl($r['total']), 'link' => 'vendas_lista.php?ver=' . $r['id']], array_slice($sv, 0, 30))],
        'link' => 'vendas_lista.php?vinculo=nome', 'valido_ate' => $dom]);
    else ia_cartao_retirar($ch);
}

function ia_r_geral() {
    $hoje = date('Y-m-d'); $sem = ia_semana(); $dom = date('Y-m-d', strtotime('sunday this week'));
    // Entregas do CD com data vencida e ainda agendadas.
    $atr = ia_q("SELECT e.id, e.data_entrega, e.descricao, e.cliente_nome, vi.nome vinicola, m.nome motorista FROM cd_entregas e JOIN cd_vinicolas vi ON vi.id = e.vinicola_id
        LEFT JOIN cd_motoristas m ON m.id = e.motorista_id WHERE e.status = 'agendada' AND e.data_entrega < ? ORDER BY e.data_entrega", [$hoje]);
    $ch = "cd_atraso:$hoje";
    if ($atr) ia_cartao(['chave' => $ch, 'area' => 'geral', 'tipo' => 'cd_atraso', 'prioridade' => 'alta',
        'titulo' => count($atr) . ' entrega(s) do CD atrasada(s)',
        'texto' => 'Entregas com data vencida que continuam agendadas. Confirme com o motorista e atualize (entregue, reagendada ou cancelada).',
        'dados' => ['itens' => array_map(fn($e) => ['rot' => data_br($e['data_entrega']) . ' · ' . $e['vinicola'] . ($e['cliente_nome'] ? ' → ' . $e['cliente_nome'] : ''), 'detalhe' => trim($e['descricao'] . ($e['motorista'] ? ' · motorista ' . $e['motorista'] : ' · sem motorista')), 'link' => 'cd_entregas.php?editar=' . $e['id']], $atr)],
        'link' => 'cd_entregas.php', 'valido_ate' => $hoje]);
    else ia_cartao_retirar($ch);
    // Tours: pessoas nos últimos 30 dias contra os 30 anteriores.
    $p = fn($i, $f) => (int)(ia_q('SELECT COALESCE(SUM(qtd), 0) n FROM tour_vendas v WHERE ' . tour_ativo_sql() . ' AND data_tour >= ? AND data_tour <= ?', [$i, $f])[0]['n']);
    $a = $p(date('Y-m-d', strtotime('-30 days')), date('Y-m-d', strtotime('-1 day'))); $b = $p(date('Y-m-d', strtotime('-60 days')), date('Y-m-d', strtotime('-31 days')));
    if ($b >= 10 && $a < $b * 0.8) ia_cartao(['chave' => "queda_tours:$sem", 'area' => 'geral', 'tipo' => 'queda', 'prioridade' => $a < $b * 0.6 ? 'alta' : 'media',
        'titulo' => 'Tours em queda: ' . $a . ' pessoas nos últimos 30 dias, contra ' . $b . ' nos 30 anteriores (' . round(($a - $b) * 100 / $b) . '%)',
        'texto' => 'Reveja divulgação, parcerias com hotéis e agências e a agenda dos fins de semana. Combine com o gerente de enoturismo uma ação para as próximas semanas.',
        'link' => 'painel.php', 'valido_ate' => $dom]);
    if (!function_exists('vendas_v9') || !vendas_v9()) return;
    // Vendas: compara os últimos 30 dias de dados importados com os 30 anteriores (só quando há dados dos dois períodos).
    $max = db()->query('SELECT MAX(data) FROM vendas')->fetchColumn(); $min = db()->query('SELECT MIN(data) FROM vendas')->fetchColumn();
    if ($max) {
        $fim = substr($max, 0, 10); $i1 = date('Y-m-d', strtotime("$fim -29 days")); $i0 = date('Y-m-d', strtotime("$fim -59 days")); $f0 = date('Y-m-d', strtotime("$fim -30 days"));
        if (substr($min, 0, 10) <= $i0) {
            $s = fn($i, $f) => ia_q('SELECT COUNT(*) n, COALESCE(SUM(valor_total), 0) v FROM vendas WHERE valor_total > 0 AND data >= ? AND data <= ?', ["$i 00:00:00", "$f 23:59:59"])[0];
            $x = $s($i1, $fim); $y = $s($i0, $f0);
            if ($y['v'] > 0 && $x['v'] < $y['v'] * 0.92) {
                $d = round(($x['v'] - $y['v']) * 100 / $y['v']);
                ia_cartao(['chave' => "queda_vendas:$fim", 'area' => 'geral', 'tipo' => 'queda', 'prioridade' => $d <= -15 ? 'alta' : 'media',
                    'titulo' => "Vendas em queda: $d% nos 30 dias até " . data_br($fim),
                    'texto' => brl($x['v']) . ' em ' . $x['n'] . ' vendas, contra ' . brl($y['v']) . ' em ' . $y['n'] . ' vendas nos 30 dias anteriores. Veja no painel se a queda veio do B2B, da loja ou de um cliente grande que não comprou.',
                    'link' => 'vendas_painel.php?ini=' . $i1 . '&fim=' . $fim, 'valido_ate' => date('Y-m-d', strtotime('+7 days'))]);
            }
        }
        // Relatório do mês anterior ainda não importado.
        $mesAnt = date('Y-m', strtotime('first day of last month'));
        if ((int)date('j') >= 5 && substr($max, 0, 7) < $mesAnt) ia_cartao(['chave' => "importar_vendas:$mesAnt", 'area' => 'geral', 'tipo' => 'rotina', 'prioridade' => 'media',
            'titulo' => 'Importar o relatório de vendas de ' . rotulo_mes($mesAnt),
            'texto' => 'Sem as vendas do mês passado, o painel, os grupos de compradores e os alertas de recompra ficam desatualizados.',
            'link' => 'vendas_importar.php', 'valido_ate' => date('Y-m-t')]);
        // Produtos vendidos abaixo do custo nos últimos 60 dias de dados.
        $ab = ia_q("SELECT MAX(COALESCE(p.descricao, i.descricao)) d, SUM(i.lucro) l, SUM(i.quantidade) q FROM venda_itens i JOIN vendas v ON v.id = i.venda_id LEFT JOIN produtos p ON p.id = i.produto_id
            WHERE i.bonificacao = 0 AND i.custo_total > 0 AND v.data >= ? GROUP BY i.produto_id HAVING SUM(i.lucro) < 0", [date('Y-m-d', strtotime("$fim -60 days")) . ' 00:00:00']);
        if ($ab) ia_cartao(['chave' => "abaixo_custo:$sem", 'area' => 'geral', 'tipo' => 'margem', 'prioridade' => 'media',
            'titulo' => count($ab) . ' produto(s) vendido(s) abaixo do custo',
            'texto' => 'Confira o preço de venda ou o custo cadastrado no ERP.',
            'dados' => ['itens' => array_map(fn($r) => ['rot' => $r['d'], 'detalhe' => number_format((float)$r['q'], 0, ',', '.') . ' un. · prejuízo ' . brl(-$r['l'])], $ab)],
            'link' => 'vendas_produtos.php', 'valido_ate' => $dom]);
    }
}

// Campanhas liberadas nos grupos: um cartão por dia em "Para hoje" enquanto houver clientes na fila.
function ia_r_campanhas() {
    if (!function_exists('camp_v11') || !camp_v11()) return;
    $hoje = date('Y-m-d');
    foreach (ia_q("SELECT c.*, g.nome grupo_nome FROM crm_campanhas c JOIN crm_grupos g ON g.id = c.grupo_id WHERE c.status = 'liberada'") as $c) {
        if ($c['fim'] && $c['fim'] < $hoje) {
            db()->prepare("UPDATE crm_campanhas SET status = 'encerrada', atualizado_em = ? WHERE id = ?")->execute([date('Y-m-d H:i:s'), $c['id']]);
            continue;
        }
        if ($c['inicio'] && $c['inicio'] > $hoje) continue;
        $n = camp_contagem($c);
        if (!$n['pendentes']) continue;
        $email = camp_canal($c) === 'email';
        ia_cartao(['chave' => 'campanha:' . $c['id'] . ':' . $hoje, 'area' => $c['area'], 'tipo' => 'campanha', 'prioridade' => 'media',
            'titulo' => ($email ? 'E-mail "' : 'WhatsApp "') . $c['titulo'] . '": ' . $n['pendentes'] . ' cliente(s) para enviar',
            'texto' => 'Grupo ' . $c['grupo_nome'] . ($c['fim'] ? ' · até ' . data_br($c['fim']) : '') . '. ' . ($email
                ? ($c['auto_envio'] ? 'O envio automático está ligado: os lotes saem sozinhos dentro do limite por hora; acompanhe aberturas e cliques.' : 'Abra o envio: os e-mails saem em lotes, dentro do limite por hora da hospedagem.')
                : 'Abra a fila de envio: o sistema abre cada conversa com a mensagem pronta e já deixa o próximo cliente pronto.'),
            'dados' => ['campanha_id' => (int)$c['id']], 'link' => ($email ? 'ia_email.php?c=' : 'ia_disparo.php?c=') . $c['id'], 'valido_ate' => $hoje]);
    }
}

// ---------- Análise semanal com a IA ----------
// Números agregados para a IA. Não vão nomes, CPF, telefone ou e-mail de pessoas físicas (só nomes de empresas clientes).
function ia_resumo_dados() {
    $hoje = date('Y-m-d'); $r = ['hoje' => $hoje, 'dia_semana' => dia_semana($hoje)];
    $r['tours_8_semanas'] = [];
    for ($i = 7; $i >= 0; $i--) {
        $ini = date('Y-m-d', strtotime("monday this week -$i weeks")); $fim = date('Y-m-d', strtotime("$ini +6 days"));
        $x = ia_q('SELECT COUNT(*) tours, COALESCE(SUM(qtd), 0) pessoas, COALESCE(SUM(valor_pago), 0) valor, SUM(CASE WHEN presenca = \'ausente\' THEN qtd ELSE 0 END) ausentes FROM tour_vendas v WHERE ' . tour_ativo_sql() . ' AND data_tour >= ? AND data_tour <= ?', [$ini, $fim])[0];
        $r['tours_8_semanas'][] = ['semana_de' => $ini] + array_map(fn($v) => round((float)$v, 2), $x);
    }
    $r['tours_proximos_14_dias'] = ia_q('SELECT data_tour, COUNT(*) reservas, SUM(qtd) pessoas FROM tour_vendas v WHERE ' . tour_ativo_sql() . ' AND data_tour >= ? AND data_tour <= ? GROUP BY data_tour ORDER BY data_tour', [$hoje, date('Y-m-d', strtotime('+14 days'))]);
    $r['tours_pagamento_pendente'] = ia_q("SELECT COUNT(*) n, COALESCE(SUM(valor_pago), 0) valor FROM tour_vendas v WHERE " . tour_ativo_sql() . " AND recebido = 0 AND data_tour < ?", [$hoje])[0];
    if (crm_v7()) {
        $r['crm'] = ia_q("SELECT COUNT(*) clientes, SUM(CASE WHEN tipo_pessoa = 'PJ' THEN 1 ELSE 0 END) empresas, SUM(CASE WHEN telefone_e164 LIKE '+55__9%' THEN 1 ELSE 0 END) com_celular,
            SUM(CASE WHEN aceita_whatsapp = 1 THEN 1 ELSE 0 END) aceitam_whatsapp, SUM(CASE WHEN aceita_email = 1 THEN 1 ELSE 0 END) aceitam_email, SUM(clube_beneficios) clube FROM clientes")[0];
        $cont = grupos_contagens();
        $r['grupos_de_campanha'] = array_map(fn($g) => ['grupo' => $g['nome'], 'clientes' => (int)($cont[$g['id']]['total'] ?? 0), 'aceitam_whatsapp' => (int)($cont[$g['id']]['optin_wa'] ?? 0)], grupos_todos(true));
    }
    if (function_exists('vendas_v9') && vendas_v9() && ($max = db()->query('SELECT MAX(data) FROM vendas')->fetchColumn())) {
        $fim = substr($max, 0, 10); $r['vendas_dados_ate'] = $fim;
        $r['vendas_por_mes'] = [];
        for ($i = 5; $i >= 0; $i--) {
            $m = date('Y-m', strtotime(substr($fim, 0, 7) . "-01 -$i months"));
            $x = ia_q("SELECT SUM(CASE WHEN v.valor_total > 0 THEN 1 ELSE 0 END) vendas, COALESCE(SUM(v.valor_total), 0) faturamento, SUM(CASE WHEN c.tipo_pessoa = 'PJ' THEN v.valor_total ELSE 0 END) empresas,
                SUM(CASE WHEN c.tipo_pessoa = 'PF' THEN v.valor_total ELSE 0 END) pessoas_fisicas, SUM(CASE WHEN v.cliente_id IS NULL THEN v.valor_total ELSE 0 END) balcao_sem_cliente,
                SUM(CASE WHEN v.canal = 'loja' THEN v.valor_total ELSE 0 END) loja_wine_bar, SUM(v.valor_bonificacao) bonificacoes
                FROM vendas v LEFT JOIN clientes c ON c.id = v.cliente_id WHERE v.valor_total >= 0 AND v.data >= ? AND v.data <= ?", ["$m-01 00:00:00", date('Y-m-t', strtotime("$m-01")) . ' 23:59:59'])[0];
            if ((float)$x['faturamento'] > 0 || (float)$x['bonificacoes'] > 0) $r['vendas_por_mes'][] = ['mes' => $m] + array_map(fn($v) => round((float)$v, 2), $x);
        }
        $i90 = date('Y-m-d', strtotime("$fim -89 days")) . ' 00:00:00';
        $r['produtos_90_dias'] = array_map(fn($p) => ['produto' => $p['d'], 'tipo' => $p['t'], 'unidades' => round((float)$p['q']), 'receita' => round((float)$p['v'], 2), 'margem_pct' => $p['rc'] > 0 ? round($p['lc'] * 100 / $p['rc'], 1) : null],
            ia_q("SELECT MAX(COALESCE(p.descricao, i.descricao)) d, MAX(p.tipo) t, SUM(i.quantidade) q, SUM(i.liquido) v, SUM(CASE WHEN i.custo_total > 0 THEN i.liquido ELSE 0 END) rc, SUM(CASE WHEN i.custo_total > 0 THEN i.lucro ELSE 0 END) lc
                FROM venda_itens i JOIN vendas v ON v.id = i.venda_id LEFT JOIN produtos p ON p.id = i.produto_id WHERE i.bonificacao = 0 AND v.data >= ? GROUP BY i.produto_id ORDER BY v DESC LIMIT 12", [$i90]));
        $r['vendedores_90_dias'] = ia_q("SELECT COALESCE(vendedor, 'não informado') vendedor, COUNT(*) vendas, ROUND(SUM(valor_total), 2) faturamento FROM vendas WHERE valor_total > 0 AND data >= ? GROUP BY vendedor ORDER BY faturamento DESC", [$i90]);
        $r['empresas_clientes_90_dias'] = array_map(fn($c) => ['empresa' => $c['nome'], 'categoria' => $c['categoria_id'] ? nome_categoria($c['categoria_id']) : null, 'pedidos' => (int)$c['n'], 'total' => round((float)$c['t'], 2), 'ultimo_pedido' => substr($c['u'], 0, 10)],
            ia_q("SELECT c.nome, c.categoria_id, COUNT(*) n, SUM(v.valor_total) t, MAX(v.data) u FROM vendas v JOIN clientes c ON c.id = v.cliente_id WHERE c.tipo_pessoa = 'PJ' AND v.valor_total > 0 AND v.data >= ? GROUP BY c.id, c.nome, c.categoria_id ORDER BY t DESC LIMIT 15", [$i90]));
        $rec = ia_q('SELECT COUNT(*) clientes, SUM(CASE WHEN n >= 2 THEN 1 ELSE 0 END) recorrentes FROM (SELECT cliente_id, COUNT(*) n FROM vendas WHERE cliente_id IS NOT NULL AND valor_total > 0 GROUP BY cliente_id) x')[0];
        $r['recorrencia'] = ['clientes_que_ja_compraram' => (int)$rec['clientes'], 'compraram_2_vezes_ou_mais' => (int)$rec['recorrentes']];
    }
    $r['cd_entregas_30_dias'] = ia_q("SELECT status, COUNT(*) n FROM cd_entregas WHERE data_entrega >= ? GROUP BY status", [date('Y-m-d', strtotime('-30 days'))]);
    $r['cd_atrasadas'] = (int)ia_q("SELECT COUNT(*) n FROM cd_entregas WHERE status = 'agendada' AND data_entrega < ?", [$hoje])[0]['n'];
    $r['proximas_datas_60_dias'] = [];
    foreach (ia_q('SELECT * FROM ia_datas WHERE ativo = 1') as $d) { $dt = ia_proxima_data($d); if ($dt && $dt <= date('Y-m-d', strtotime('+60 days'))) $r['proximas_datas_60_dias'][] = ['data' => $dt, 'nome' => $d['nome'], 'area' => $d['area']]; }
    usort($r['proximas_datas_60_dias'], fn($a, $b) => strcmp($a['data'], $b['data']));
    $r['alertas_abertos_por_area'] = ia_q("SELECT area, COUNT(*) n FROM ia_cartoes WHERE status IN ('nova', 'andamento') AND valido_ate >= ? GROUP BY area", [$hoje]);
    return $r;
}
function ia_chamar_claude($sistema, $usuario, $maxTokens = 4000) {
    $chave = trim((string)config('ia_chave_api', ''));
    if ($chave === '') throw new RuntimeException('Cadastre a chave da API da Anthropic em Sugestões da IA › Configuração.');
    if (!function_exists('curl_init')) throw new RuntimeException('O servidor não tem a extensão cURL do PHP.');
    $ch = curl_init(defined('IA_API_URL') ? IA_API_URL : 'https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 150, CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['x-api-key: ' . $chave, 'anthropic-version: 2023-06-01', 'content-type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['model' => config('ia_modelo'), 'max_tokens' => $maxTokens, 'system' => $sistema,
            'messages' => [['role' => 'user', 'content' => $usuario]]], JSON_UNESCAPED_UNICODE)]);
    $resp = curl_exec($ch); $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch);
    curl_close($ch);
    if ($resp === false) throw new RuntimeException('Sem conexão com a API da Anthropic: ' . $err);
    $j = json_decode($resp, true);
    if ($http !== 200) throw new RuntimeException('A API da Anthropic respondeu ' . $http . ': ' . ($j['error']['message'] ?? mb_substr($resp, 0, 200)));
    $texto = '';
    foreach ($j['content'] ?? [] as $b) if (($b['type'] ?? '') === 'text') $texto .= $b['text'];
    return ['texto' => $texto, 'in' => (int)($j['usage']['input_tokens'] ?? 0), 'out' => (int)($j['usage']['output_tokens'] ?? 0)];
}
function ia_extrair_json($t) {
    $t = trim(preg_replace('/^```(?:json)?|```$/m', '', trim($t)));
    $j = json_decode($t, true);
    if (!is_array($j) && ($a = strpos($t, '{')) !== false && ($b = strrpos($t, '}')) !== false) $j = json_decode(substr($t, $a, $b - $a + 1), true);
    return is_array($j) ? $j : null;
}
function ia_analise_semanal($forcar = false) {
    $semana = date('Y-m-d', strtotime('monday this week'));
    if (!$forcar) { $s = db()->prepare("SELECT id FROM ia_analises WHERE semana = ? AND status = 'ok'"); $s->execute([$semana]); if ($id = $s->fetchColumn()) return (int)$id; }
    @set_time_limit(200);
    $dados = ia_resumo_dados();
    $sistema = "Você é um consultor de negócios experiente em vinícolas e enoturismo e trabalha para a Vinícola Brasília, vinícola do Cerrado em Brasília-DF. "
        . "Ela tem loja/wine bar com degustação, vendas B2B (restaurantes, empórios, supermercados, empresas), tours de enoturismo e eventos, e um Centro de Distribuição que entrega vinhos de outras vinícolas. "
        . "A equipe: gerente geral; gerente comercial B2B; gerente comercial B2C (consumidor final, loja, e-commerce, campanhas); gerente de enoturismo e eventos. "
        . "Sua tarefa: ler os números da semana e sugerir iniciativas práticas e específicas para cada gerente, com o porquê baseado nos números. "
        . "Use só os dados fornecidos: não invente números, clientes ou fatos. Quando um dado faltar, sugira como obtê-lo. Português do Brasil, tom direto e profissional.";
    $usuario = "Dados do sistema de gestão (JSON):\n" . json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        . "\n\nResponda SOMENTE com um JSON válido, sem texto fora dele, neste formato:\n"
        . '{"resumo": "3 a 5 frases para o gerente geral sobre como foi o período e o que mais importa agora",'
        . ' "geral": [ {"titulo": "até 90 caracteres", "texto": "2 a 4 frases: o que fazer, por que (com os números) e o resultado esperado", "prioridade": "alta|media|baixa"} ],'
        . ' "b2b": [...], "b2c": [...], "enoturismo": [...]}'
        . "\nDê de 2 a 4 sugestões por área, ordenadas da mais importante para a menos importante.";
    $pdo = db();
    try {
        $r = ia_chamar_claude($sistema, $usuario);
        $j = ia_extrair_json($r['texto']);
        if (!$j) throw new RuntimeException('A IA respondeu fora do formato esperado.');
    } catch (Throwable $ex) {
        $pdo->prepare("INSERT INTO ia_analises (semana, modelo, dados, status, erro, criado_por, criado_em) VALUES (?,?,?,'erro',?,?,?)")
            ->execute([$semana, config('ia_modelo'), json_encode($dados, JSON_UNESCAPED_UNICODE), mb_substr($ex->getMessage(), 0, 500), $GLOBALS['USUARIO']['nome'] ?? 'Sistema', date('Y-m-d H:i:s')]);
        throw $ex;
    }
    $pdo->prepare("INSERT INTO ia_analises (semana, modelo, resumo, resposta, dados, tokens_in, tokens_out, status, criado_por, criado_em) VALUES (?,?,?,?,?,?,?,'ok',?,?)")
        ->execute([$semana, config('ia_modelo'), mb_substr((string)($j['resumo'] ?? ''), 0, 5000), $r['texto'], json_encode($dados, JSON_UNESCAPED_UNICODE), $r['in'], $r['out'], $GLOBALS['USUARIO']['nome'] ?? 'Sistema', date('Y-m-d H:i:s')]);
    $id = (int)$pdo->lastInsertId();
    // Ao refazer a análise da semana, as sugestões antigas que ninguém tocou dão lugar às novas.
    $pdo->prepare("DELETE FROM ia_cartoes WHERE origem = 'ia' AND status = 'nova' AND chave LIKE ?")->execute(["ia:$semana:%"]);
    $fim = date('Y-m-d', strtotime("$semana +6 days"));
    foreach (array_keys(ia_areas()) as $area) foreach (array_values((array)($j[$area] ?? [])) as $i => $s) {
        if (!is_array($s) || trim((string)($s['titulo'] ?? '')) === '') continue;
        ia_cartao(['chave' => "ia:$semana:$area:" . ($i + 1) . ':' . $id, 'area' => $area, 'tipo' => 'sugestao_ia', 'origem' => 'ia',
            'prioridade' => in_array($s['prioridade'] ?? '', ['alta', 'media', 'baixa'], true) ? $s['prioridade'] : 'media',
            'titulo' => (string)$s['titulo'], 'texto' => (string)($s['texto'] ?? ''), 'dados' => ['analise_id' => $id], 'valido_ate' => $fim]);
    }
    registrar_log('ia', $id, 'Análise semanal da IA', 'Semana de ' . data_br($semana) . ' · ' . $r['in'] . ' tokens enviados, ' . $r['out'] . ' recebidos', $GLOBALS['USUARIO'] ?? ['id' => null, 'nome' => 'Sistema (automático)']);
    return $id;
}

// ---------- Leitura dos cartões ----------
function ia_cartoes_abertos($areas, $incluirFuturo = true) {
    if (!$areas) return [];
    $in = implode(',', array_fill(0, count($areas), '?'));
    $l = ia_q("SELECT * FROM ia_cartoes WHERE area IN ($in) AND status IN ('nova', 'andamento') AND valido_ate >= ? ORDER BY CASE prioridade WHEN 'alta' THEN 0 WHEN 'media' THEN 1 ELSE 2 END, quando, id DESC", array_merge($areas, [date('Y-m-d')]));
    foreach ($l as &$c) $c['dados'] = json_decode((string)$c['dados'], true) ?: [];
    return $l;
}
function nome_prioridade($p) { return ['alta' => 'Alta', 'media' => 'Média', 'baixa' => 'Baixa'][$p] ?? $p; }
function nome_status_cartao($s) { return ['nova' => 'Nova', 'andamento' => 'Em andamento', 'feita' => 'Feita', 'descartada' => 'Descartada'][$s] ?? $s; }

// ---------- E-mails ----------
function ia_destinatarios() {
    $r = [];
    foreach (db()->query("SELECT id, nome, email, perfil FROM usuarios WHERE status = 'ativo' AND perfil IS NOT NULL") as $u) {
        $p = expandir_perfis($u['perfil']);
        if (!array_intersect(array_keys(ia_perfil_area()), $p)) continue;   // e-mail só para quem tem perfil de gerente
        if (filter_var($u['email'], FILTER_VALIDATE_EMAIL)) $r[] = $u + ['areas' => ia_areas_de($u['perfil'])];
    }
    return $r;
}
function ia_url_sistema() { return defined('APP_URL') ? APP_URL : 'https://www.vinicolabrasilia.com.br/gestao'; }
function ia_html_cartoes($cartoes) {
    $cor = ['alta' => '#a02020', 'media' => '#a35a00', 'baixa' => '#655'];
    $h = '';
    foreach (ia_areas() as $a => $rot) {
        $da = array_filter($cartoes, fn($c) => $c['area'] === $a);
        if (!$da) continue;
        $h .= '<h3 style="color:#5c1a2b;margin:18px 0 6px">' . e($rot) . '</h3>';
        foreach ($da as $c) {
            $n = count($c['dados']['contatos'] ?? $c['dados']['itens'] ?? []);
            $h .= '<div style="border-left:3px solid ' . $cor[$c['prioridade']] . ';padding:4px 10px;margin:6px 0"><strong>' . e($c['titulo']) . '</strong>'
                . ($c['origem'] === 'ia' ? ' <span style="color:#7a2439">· sugestão da IA</span>' : '')
                . '<br><span style="color:#544">' . nl2br(e(mb_strimwidth((string)$c['texto'], 0, 400, '…'))) . '</span>' . ($n ? '<br><small style="color:#766">' . $n . ' na lista</small>' : '') . '</div>';
        }
    }
    return $h;
}
function ia_email_diario($forcar = false) {
    if (config('ia_emails') !== '1') return 0;
    if (!$forcar && config('ia_email_diario_em', '') === date('Y-m-d')) return 0;
    $n = 0;
    foreach (ia_destinatarios() as $u) {
        $cs = array_filter(ia_cartoes_abertos($u['areas']), fn($c) => $c['origem'] === 'regra');
        if (!$cs) continue;
        $html = '<p>Bom dia, ' . e(nome_saudacao($u['nome'])) . '! Estes são os alertas de hoje (' . data_br(date('Y-m-d')) . '):</p>' . ia_html_cartoes($cs)
            . '<p style="margin-top:18px"><a href="' . e(ia_url_sistema()) . '/ia.php" style="background:#5c1a2b;color:#fff;padding:10px 16px;border-radius:6px;text-decoration:none">Abrir as Sugestões da IA</a></p>';
        if (enviar_email($u['email'], 'Alertas de hoje · ' . count($cs) . ' item(ns) · ' . data_br(date('Y-m-d')), $html)) $n++;
    }
    config_salvar('ia_email_diario_em', date('Y-m-d'));
    return $n;
}
function ia_email_semanal($analiseId) {
    if (config('ia_emails') !== '1') return 0;
    $s = db()->prepare('SELECT * FROM ia_analises WHERE id = ?'); $s->execute([$analiseId]); $a = $s->fetch();
    if (!$a || $a['status'] !== 'ok') return 0;
    $n = 0;
    foreach (ia_destinatarios() as $u) {
        $cs = array_filter(ia_cartoes_abertos($u['areas']), fn($c) => $c['origem'] === 'ia');
        $html = '<p>Olá, ' . e(nome_saudacao($u['nome'])) . '! Saiu a análise da semana de ' . data_br($a['semana']) . '.</p>'
            . (in_array('geral', $u['areas'], true) && $a['resumo'] ? '<p style="background:#f6f3ef;padding:10px 14px;border-radius:6px">' . nl2br(e($a['resumo'])) . '</p>' : '')
            . ia_html_cartoes($cs)
            . '<p style="margin-top:18px"><a href="' . e(ia_url_sistema()) . '/ia.php" style="background:#5c1a2b;color:#fff;padding:10px 16px;border-radius:6px;text-decoration:none">Abrir as Sugestões da IA</a></p>';
        if (enviar_email($u['email'], 'Sugestões da semana · Vinícola Brasília · ' . data_br($a['semana']), $html)) $n++;
    }
    return $n;
}
