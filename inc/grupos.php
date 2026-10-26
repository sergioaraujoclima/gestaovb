<?php
// ---------- CRM · grupos de campanha ----------
// Grupo automático (dinâmico): a lista se mantém sozinha pelas regras; quem passa a atender entra, quem deixa de atender sai.
// Grupo manual (estático): só entra quem alguém incluir. Nos automáticos também dá para incluir alguém à mão
// ou excluir alguém que as regras trariam (a exclusão vale até ser desfeita).

function grupos_campos() {
    static $c = null;
    if ($c !== null) return $c;
    $sn = ['1' => 'Sim', '0' => 'Não'];
    $c = [
        'tipo_pessoa' => ['rot' => 'Tipo de pessoa', 'tipo' => 'enum', 'ops' => ['=', '!='], 'opcoes' => ['PF' => 'Pessoa física', 'PJ' => 'Pessoa jurídica (empresa)']],
        'categoria' => ['rot' => 'Categoria do cliente', 'tipo' => 'enum', 'ops' => ['=', '!='], 'opcoes' => array_column(categorias_todas(), 'nome', 'id') + ['' => 'Sem categoria']],
        'sexo' => ['rot' => 'Sexo', 'tipo' => 'enum', 'ops' => ['=', '!='], 'opcoes' => ['F' => 'Feminino', 'M' => 'Masculino', '' => 'Não informado']],
        'clube' => ['rot' => 'Membro do clube', 'tipo' => 'enum', 'ops' => ['='], 'opcoes' => $sn],
        'origem' => ['rot' => 'Origem do cadastro', 'tipo' => 'enum', 'ops' => ['=', '!='], 'opcoes' => lista_origens()],
        'estagio' => ['rot' => 'Estágio (pelos tours)', 'tipo' => 'enum', 'ops' => ['=', '!='], 'opcoes' => grupos_estagios()],
        'idade' => ['rot' => 'Idade (anos)', 'tipo' => 'num', 'ops' => ['>=', '<=']],
        'mes_aniversario' => ['rot' => 'Mês de aniversário', 'tipo' => 'enum', 'ops' => ['='], 'opcoes' => ['atual' => 'Mês atual', 'proximo' => 'Próximo mês'] + array_combine(array_map('strval', range(1, 12)), ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'])],
        'uf' => ['rot' => 'UF', 'tipo' => 'texto', 'ops' => ['=', '!=']],
        'cidade' => ['rot' => 'Cidade', 'tipo' => 'texto', 'ops' => ['=', 'contem']],
        'bairro' => ['rot' => 'Bairro', 'tipo' => 'texto', 'ops' => ['contem']],
        'etiqueta' => ['rot' => 'Etiqueta', 'tipo' => 'etiqueta', 'ops' => ['tem', 'nao_tem']],
        'interesses' => ['rot' => 'Interesses', 'tipo' => 'texto', 'ops' => ['contem']],
        'tours' => ['rot' => 'Quantidade de tours', 'tipo' => 'num', 'ops' => ['>=', '<=', '=']],
        'dias_ultimo_tour' => ['rot' => 'Dias desde o último tour', 'tipo' => 'num', 'ops' => ['>=', '<=']],
        'total_gasto' => ['rot' => 'Total gasto em tours (R$)', 'tipo' => 'num', 'ops' => ['>=', '<=']],
        'dias_cadastro' => ['rot' => 'Dias desde o cadastro', 'tipo' => 'num', 'ops' => ['<=', '>=']],
        'tem_whatsapp' => ['rot' => 'Tem celular (WhatsApp)', 'tipo' => 'enum', 'ops' => ['='], 'opcoes' => $sn],
        'tem_email' => ['rot' => 'Tem e-mail', 'tipo' => 'enum', 'ops' => ['='], 'opcoes' => $sn],
        'aceita_whatsapp' => ['rot' => 'Aceita WhatsApp de marketing', 'tipo' => 'enum', 'ops' => ['=', '!='], 'opcoes' => ['1' => 'Sim', '0' => 'Não', '' => 'Não perguntado']],
        'aceita_email' => ['rot' => 'Aceita e-mail de marketing', 'tipo' => 'enum', 'ops' => ['=', '!='], 'opcoes' => ['1' => 'Sim', '0' => 'Não', '' => 'Não perguntado']],
        'vendedor' => ['rot' => 'Vendedor responsável', 'tipo' => 'texto', 'ops' => ['=', 'contem']],
    ];
    // Compras registradas pelo relatório de vendas do ERP (bonificações e amostras não contam como compra).
    if (function_exists('vendas_v9') && vendas_v9()) $c += [
        'compras' => ['rot' => 'Quantidade de compras', 'tipo' => 'num', 'ops' => ['>=', '<=', '=']],
        'valor_compras' => ['rot' => 'Total comprado (R$)', 'tipo' => 'num', 'ops' => ['>=', '<=']],
        'ticket_compras' => ['rot' => 'Ticket médio das compras (R$)', 'tipo' => 'num', 'ops' => ['>=', '<=']],
        'dias_ultima_compra' => ['rot' => 'Dias desde a última compra', 'tipo' => 'num', 'ops' => ['>=', '<=']],
        'comprou_tipo' => ['rot' => 'Tipo de produto comprado', 'tipo' => 'lista', 'ops' => ['tem', 'nao_tem'], 'opcoes' => tipos_produto()],
        'comprou_produto' => ['rot' => 'Produto comprado', 'tipo' => 'lista', 'ops' => ['tem', 'nao_tem'],
            'opcoes' => db()->query('SELECT id, descricao FROM produtos ORDER BY ativo DESC, descricao')->fetchAll(PDO::FETCH_KEY_PAIR)],
    ];
    return $c;
}
function recusou($v) { return $v !== null && $v !== '' && (string)$v === '0'; }
function grupos_ops() { return ['=' => 'é', '!=' => 'não é', '>=' => 'maior ou igual a', '<=' => 'menor ou igual a', 'contem' => 'contém', 'tem' => 'tem', 'nao_tem' => 'não tem']; }
function grupos_estagios() { return ['contato' => 'Sem tour registrado', 'cliente' => 'Fez 1 tour', 'recorrente' => 'Recorrente (2+ tours)', 'inativo' => 'Inativo (último tour há mais de 1 ano)']; }

// Lê e valida as regras vindas do formulário.
function grupos_regras_do_post($post) {
    $r = []; $campos = grupos_campos();
    foreach ((array)($post['campo'] ?? []) as $i => $campo) {
        if (!isset($campos[$campo])) continue;
        $op = (string)($post['op'][$i] ?? '');
        if (!in_array($op, $campos[$campo]['ops'], true)) $op = $campos[$campo]['ops'][0];
        $v = trim((string)($post['valor'][$i] ?? ''));
        $def = $campos[$campo];
        if ($def['tipo'] === 'num') { if (!is_numeric(str_replace(',', '.', $v))) continue; $v = (string)(float)str_replace(',', '.', $v); }
        if ($def['tipo'] === 'enum' && !array_key_exists($v, $def['opcoes'])) continue;
        if ($def['tipo'] === 'etiqueta' && !(int)$v) continue;
        if ($def['tipo'] === 'lista' && !array_key_exists($v, $def['opcoes'])) continue;
        if ($def['tipo'] === 'texto' && $v === '') continue;
        $r[] = ['campo' => $campo, 'op' => $op, 'valor' => $v];
    }
    return $r;
}
function grupos_resumo($g) {
    if ($g['tipo'] === 'manual') return 'Lista manual';
    $campos = grupos_campos(); $ops = grupos_ops(); $p = [];
    $etq = null;
    foreach (json_decode((string)$g['regras'], true) ?: [] as $r) {
        $d = $campos[$r['campo']] ?? null; if (!$d) continue;
        $v = $r['valor'];
        if ($d['tipo'] === 'enum' || $d['tipo'] === 'lista') $v = $d['opcoes'][$v] ?? $v;
        if ($d['tipo'] === 'etiqueta') { $etq = $etq ?? db()->query('SELECT id, nome FROM crm_etiquetas')->fetchAll(PDO::FETCH_KEY_PAIR); $v = $etq[(int)$v] ?? '#' . $v; }
        if ($d['tipo'] === 'num') $v = rtrim(rtrim(number_format((float)$v, 2, ',', '.'), '0'), ',');
        $p[] = $d['rot'] . ' ' . ($ops[$r['op']] ?? $r['op']) . ' ' . $v;
    }
    return $p ? implode($g['combinacao'] === 'qualquer' ? ' OU ' : ' E ', $p) : 'Sem regras';
}

// Dados de cada cliente usados pelas regras (uma consulta para todos, ou só para um cliente).
function grupos_contexto($clienteId = null) {
    $pdo = db();
    $w = $clienteId ? ' WHERE c.id = ' . (int)$clienteId : '';
    $agg = [];
    foreach ($pdo->query('SELECT cliente_id, COUNT(*) n, MAX(data_tour) ultimo, COALESCE(SUM(valor_pago), 0) total FROM tour_vendas' . ($clienteId ? ' WHERE cliente_id = ' . (int)$clienteId : '') . ' GROUP BY cliente_id') as $r) $agg[$r['cliente_id']] = $r;
    $etq = [];
    foreach ($pdo->query('SELECT cliente_id, etiqueta_id FROM crm_cliente_etiqueta' . ($clienteId ? ' WHERE cliente_id = ' . (int)$clienteId : '')) as $r) $etq[$r['cliente_id']][(int)$r['etiqueta_id']] = true;
    // Compras do ERP: quantidade, total, última compra e o que comprou (tipos e produtos).
    $comp = []; $compT = []; $compP = [];
    if (function_exists('vendas_v9') && vendas_v9()) {
        $wc = $clienteId ? ' AND cliente_id = ' . (int)$clienteId : '';
        foreach ($pdo->query('SELECT cliente_id, COUNT(*) n, SUM(valor_total) total, MAX(data) ultima FROM vendas WHERE cliente_id IS NOT NULL AND valor_total > 0' . $wc . ' GROUP BY cliente_id') as $r) $comp[$r['cliente_id']] = $r;
        foreach ($pdo->query('SELECT DISTINCT v.cliente_id, i.produto_id, p.tipo FROM venda_itens i JOIN vendas v ON v.id = i.venda_id LEFT JOIN produtos p ON p.id = i.produto_id
            WHERE v.cliente_id IS NOT NULL AND i.bonificacao = 0' . ($clienteId ? ' AND v.cliente_id = ' . (int)$clienteId : '')) as $r) {
            if ($r['tipo']) $compT[$r['cliente_id']][$r['tipo']] = true;
            if ($r['produto_id']) $compP[$r['cliente_id']][(int)$r['produto_id']] = true;
        }
    }
    $hoje = new DateTime('today'); $ctx = [];
    foreach ($pdo->query('SELECT c.* FROM clientes c' . $w) as $c) {
        $a = $agg[$c['id']] ?? ['n' => 0, 'ultimo' => null, 'total' => 0];
        $c['_tours'] = (int)$a['n']; $c['_total'] = (float)$a['total'];
        $c['_dias_ultimo'] = $a['ultimo'] ? (int)(new DateTime($a['ultimo']))->diff($hoje)->format('%r%a') : null;
        $c['_idade'] = $c['data_nascimento'] ? (int)(new DateTime($c['data_nascimento']))->diff($hoje)->y : null;
        $desde = $c['cliente_desde'] ?: substr((string)$c['criado_em'], 0, 10);
        $c['_dias_cadastro'] = $desde ? (int)(new DateTime($desde))->diff($hoje)->format('%r%a') : null;
        $c['_estagio'] = $c['_tours'] === 0 ? 'contato' : ($c['_dias_ultimo'] !== null && $c['_dias_ultimo'] > 365 ? 'inativo' : ($c['_tours'] >= 2 ? 'recorrente' : 'cliente'));
        $c['_etq'] = $etq[$c['id']] ?? [];
        $cp = $comp[$c['id']] ?? null;
        $c['_compras'] = $cp ? (int)$cp['n'] : 0; $c['_valor_compras'] = $cp ? (float)$cp['total'] : 0.0;
        $c['_ticket_compras'] = $cp && $cp['n'] ? (float)$cp['total'] / (int)$cp['n'] : null;
        $c['_dias_ultima_compra'] = $cp ? (int)(new DateTime(substr($cp['ultima'], 0, 10)))->diff($hoje)->format('%r%a') : null;
        $c['_comprou_tipo'] = $compT[$c['id']] ?? []; $c['_comprou_produto'] = $compP[$c['id']] ?? [];
        $ctx[(int)$c['id']] = $c;
    }
    return $ctx;
}
function grupos_valor($c, $campo) {
    switch ($campo) {
        case 'clube': return (string)(int)$c['clube_beneficios'];
        case 'categoria': return (string)($c['categoria_id'] ?? '');
        case 'estagio': return $c['_estagio'];
        case 'idade': return $c['_idade'];
        case 'mes_aniversario': return $c['data_nascimento'] ? (int)substr($c['data_nascimento'], 5, 2) : null;
        case 'tours': return $c['_tours'];
        case 'dias_ultimo_tour': return $c['_dias_ultimo'];
        case 'total_gasto': return $c['_total'];
        case 'dias_cadastro': return $c['_dias_cadastro'];
        case 'tem_whatsapp': return e_celular($c['telefone_e164']) ? '1' : '0';
        case 'tem_email': return filter_var((string)$c['email'], FILTER_VALIDATE_EMAIL) ? '1' : '0';
        case 'aceita_whatsapp': case 'aceita_email': return $c[$campo] === null ? '' : (string)(int)$c[$campo];
        case 'sexo': return (string)$c['sexo'];
        case 'compras': return $c['_compras'] ?? 0;
        case 'valor_compras': return $c['_valor_compras'] ?? 0;
        case 'ticket_compras': return $c['_ticket_compras'] ?? null;
        case 'dias_ultima_compra': return $c['_dias_ultima_compra'] ?? null;
        default: return $c[$campo] ?? null;
    }
}
function grupos_regra_ok($r, $c) {
    if ($r['campo'] === 'etiqueta') { $tem = isset($c['_etq'][(int)$r['valor']]); return $r['op'] === 'tem' ? $tem : !$tem; }
    if ($r['campo'] === 'comprou_tipo' || $r['campo'] === 'comprou_produto') { $tem = isset(($c['_' . $r['campo']] ?? [])[$r['valor']]); return $r['op'] === 'nao_tem' ? !$tem : $tem; }
    $v = grupos_valor($c, $r['campo']); $alvo = $r['valor'];
    if ($r['campo'] === 'mes_aniversario') {
        if ($alvo === 'atual') $alvo = (int)date('n'); elseif ($alvo === 'proximo') $alvo = (int)date('n', strtotime('first day of next month')); else $alvo = (int)$alvo;
        return $v !== null && (int)$v === $alvo;
    }
    $tipo = grupos_campos()[$r['campo']]['tipo'] ?? 'texto';
    if ($tipo === 'num') {
        if ($v === null) return false;
        $v = (float)$v; $alvo = (float)$alvo;
        return $r['op'] === '>=' ? $v >= $alvo : ($r['op'] === '<=' ? $v <= $alvo : abs($v - $alvo) < 0.0001);
    }
    $a = chave_nome((string)$v); $b = chave_nome((string)$alvo);
    if ($tipo === 'enum') { $a = (string)$v; $b = (string)$alvo; }
    switch ($r['op']) {
        case '=': return $a === $b;
        case '!=': return $a !== $b;
        case 'contem': return $b !== '' && strpos($a, $b) !== false;
    }
    return false;
}
function grupos_atende($g, $c) {
    $regras = json_decode((string)$g['regras'], true) ?: [];
    if (!$regras) return false;
    foreach ($regras as $r) {
        $ok = grupos_regra_ok($r, $c);
        if ($g['combinacao'] === 'qualquer' && $ok) return true;
        if ($g['combinacao'] !== 'qualquer' && !$ok) return false;
    }
    return $g['combinacao'] !== 'qualquer';
}

function grupos_todos($soAtivos = false) {
    return db()->query('SELECT * FROM crm_grupos' . ($soAtivos ? ' WHERE ativo = 1' : '') . ' ORDER BY ordem, nome')->fetchAll();
}

// Recalcula um grupo automático para todos os clientes. Devolve [entraram, saíram].
function grupos_recalcular($g, $ctx = null) {
    if ($g['tipo'] !== 'dinamico') return [0, 0];
    $pdo = db();
    $ctx = $ctx ?? grupos_contexto();
    $s = $pdo->prepare('SELECT cliente_id, origem FROM crm_grupo_membros WHERE grupo_id = ?'); $s->execute([$g['id']]);
    $atual = $s->fetchAll(PDO::FETCH_KEY_PAIR);
    $entram = []; $saem = [];
    if ($g['ativo']) foreach ($ctx as $id => $c) if (grupos_atende($g, $c) && !isset($atual[$id])) $entram[] = $id;
    foreach ($atual as $id => $orig) if ($orig === 'regra' && (!$g['ativo'] || !isset($ctx[$id]) || !grupos_atende($g, $ctx[$id]))) $saem[] = $id;
    $pdo->beginTransaction();
    $agora = date('Y-m-d H:i:s'); $gid = (int)$g['id'];
    // Em lotes de 400: no MySQL da hospedagem, um comando por cliente deixava a tela lenta.
    foreach (array_chunk($entram, 400) as $lote) {
        $pdo->prepare('INSERT INTO crm_grupo_membros (grupo_id, cliente_id, origem, incluido_em, incluido_por) VALUES '
            . implode(',', array_fill(0, count($lote), "(?, ?, 'regra', ?, 'Regra automática')")))
            ->execute(array_merge(...array_map(fn($id) => [$gid, (int)$id, $agora], $lote)));
    }
    foreach (array_chunk($saem, 400) as $lote)
        $pdo->exec("DELETE FROM crm_grupo_membros WHERE grupo_id = $gid AND origem = 'regra' AND cliente_id IN (" . implode(',', array_map('intval', $lote)) . ')');
    $pdo->prepare('UPDATE crm_grupos SET recalculado_em = ? WHERE id = ?')->execute([$agora, $gid]);
    $pdo->commit();
    return [count($entram), count($saem)];
}
// Clientes pessoa física sem sexo (cadastros antigos e cargas sem essa coluna): presume pelo primeiro nome e marca como presumido.
function crm_presumir_sexo_faltante() {
    $pdo = db(); $porSexo = ['F' => [], 'M' => []];
    foreach ($pdo->query("SELECT id, nome FROM clientes WHERE tipo_pessoa = 'PF' AND sexo IS NULL")->fetchAll() as $c)
        if ($sx = sexo_por_nome($c['nome'])) $porSexo[$sx][] = (int)$c['id'];
    $n = count($porSexo['F']) + count($porSexo['M']);
    if (!$n) return 0;
    $pdo->beginTransaction();
    foreach ($porSexo as $sx => $ids) foreach (array_chunk($ids, 400) as $lote)
        $pdo->exec("UPDATE clientes SET sexo = '$sx', sexo_presumido = 1 WHERE sexo IS NULL AND id IN (" . implode(',', $lote) . ')');
    $pdo->commit();
    if ($n) registrar_log('cliente', 0, 'Sexo presumido pelo nome', $n . ' cliente(s) pessoa física sem sexo informado; marcados como presumidos para conferência');
    return $n;
}
function grupos_recalcular_todos() {
    if (!crm_v7()) return;
    crm_presumir_sexo_faltante();
    crm_classificar_faltantes();
    $ctx = grupos_contexto();
    foreach (grupos_todos() as $g) grupos_recalcular($g, $ctx);
}
// Grupos com regras que dependem da data (aniversariantes do mês, idade, dias desde o último tour, inativos...)
// precisam ser revistos uma vez por dia. Os demais já se atualizam na hora em que a ficha, o tour ou a carga mudam.
function grupos_dependem_da_data($g) {
    foreach (json_decode((string)$g['regras'], true) ?: [] as $r)
        if (in_array($r['campo'], ['mes_aniversario', 'idade', 'dias_ultimo_tour', 'dias_cadastro', 'estagio', 'dias_ultima_compra'], true)) return true;
    return false;
}
function grupos_recalcular_se_velho() {
    $hoje = date('Y-m-d');
    $velhos = array_filter(grupos_todos(), fn($g) => $g['tipo'] === 'dinamico'
        && (!$g['recalculado_em'] || (grupos_dependem_da_data($g) && substr($g['recalculado_em'], 0, 10) < $hoje)));
    if (!$velhos) return;
    @set_time_limit(120);
    crm_presumir_sexo_faltante();
    crm_classificar_faltantes();
    $ctx = grupos_contexto();
    foreach ($velhos as $g) grupos_recalcular($g, $ctx);
}
// Recalcula os grupos automáticos para um cliente (ao salvar a ficha ou lançar um tour). Devolve [entrou em, saiu de].
function grupos_recalcular_cliente($clienteId) {
    if (!crm_v7()) return [[], []];
    $pdo = db();
    $ctx = grupos_contexto($clienteId);
    $c = $ctx[(int)$clienteId] ?? null;
    $s = $pdo->prepare('SELECT grupo_id, origem FROM crm_grupo_membros WHERE cliente_id = ?'); $s->execute([$clienteId]);
    $atual = $s->fetchAll(PDO::FETCH_KEY_PAIR);
    $entrou = []; $saiu = [];
    foreach (grupos_todos() as $g) {
        if ($g['tipo'] !== 'dinamico') continue;
        $atende = $c && $g['ativo'] && grupos_atende($g, $c);
        if ($atende && !isset($atual[$g['id']])) {
            $pdo->prepare("INSERT INTO crm_grupo_membros (grupo_id, cliente_id, origem, incluido_em, incluido_por) VALUES (?, ?, 'regra', ?, 'Regra automática')")->execute([$g['id'], $clienteId, date('Y-m-d H:i:s')]);
            $entrou[] = $g['nome'];
        } elseif (!$atende && ($atual[$g['id']] ?? '') === 'regra') {
            $pdo->prepare("DELETE FROM crm_grupo_membros WHERE grupo_id = ? AND cliente_id = ? AND origem = 'regra'")->execute([$g['id'], $clienteId]);
            $saiu[] = $g['nome'];
        }
    }
    if ($entrou || $saiu) registrar_log('cliente', $clienteId, 'Grupos (automático)', ($entrou ? 'entrou em: ' . implode(', ', $entrou) : '') . ($entrou && $saiu ? '; ' : '') . ($saiu ? 'saiu de: ' . implode(', ', $saiu) : ''));
    return [$entrou, $saiu];
}
function grupos_do_cliente($clienteId) {
    $s = db()->prepare('SELECT g.id, g.nome, g.tipo, m.origem, m.incluido_em, m.incluido_por FROM crm_grupo_membros m JOIN crm_grupos g ON g.id = m.grupo_id WHERE m.cliente_id = ? ORDER BY g.ordem, g.nome');
    $s->execute([(int)$clienteId]);
    return $s->fetchAll();
}
// Inclusão ou exclusão manual (vale também para grupos automáticos).
function grupos_incluir_manual($grupoId, $clienteId) {
    $pdo = db(); $quem = $GLOBALS['USUARIO']['nome'] ?? '—';
    $s = $pdo->prepare('SELECT origem FROM crm_grupo_membros WHERE grupo_id = ? AND cliente_id = ?'); $s->execute([$grupoId, $clienteId]);
    $o = $s->fetchColumn();
    if ($o === 'manual' || $o === 'regra') return false;
    if ($o === 'excluido') $pdo->prepare("UPDATE crm_grupo_membros SET origem = 'manual', incluido_em = ?, incluido_por = ? WHERE grupo_id = ? AND cliente_id = ?")->execute([date('Y-m-d H:i:s'), $quem, $grupoId, $clienteId]);
    else $pdo->prepare("INSERT INTO crm_grupo_membros (grupo_id, cliente_id, origem, incluido_em, incluido_por) VALUES (?, ?, 'manual', ?, ?)")->execute([$grupoId, $clienteId, date('Y-m-d H:i:s'), $quem]);
    return true;
}
function grupos_remover_manual($grupoId, $clienteId) {
    $pdo = db(); $quem = $GLOBALS['USUARIO']['nome'] ?? '—';
    $g = $pdo->query('SELECT tipo FROM crm_grupos WHERE id = ' . (int)$grupoId)->fetchColumn();
    if ($g === 'dinamico') {
        // Em grupo automático, a remoção vira exclusão permanente (senão a regra traria a pessoa de volta).
        $s = $pdo->prepare('SELECT origem FROM crm_grupo_membros WHERE grupo_id = ? AND cliente_id = ?'); $s->execute([$grupoId, $clienteId]);
        if ($s->fetchColumn() === false) $pdo->prepare("INSERT INTO crm_grupo_membros (grupo_id, cliente_id, origem, incluido_em, incluido_por) VALUES (?, ?, 'excluido', ?, ?)")->execute([$grupoId, $clienteId, date('Y-m-d H:i:s'), $quem]);
        else $pdo->prepare("UPDATE crm_grupo_membros SET origem = 'excluido', incluido_em = ?, incluido_por = ? WHERE grupo_id = ? AND cliente_id = ?")->execute([date('Y-m-d H:i:s'), $quem, $grupoId, $clienteId]);
    } else {
        $pdo->prepare('DELETE FROM crm_grupo_membros WHERE grupo_id = ? AND cliente_id = ?')->execute([$grupoId, $clienteId]);
    }
}
// Desfaz uma exclusão: o cliente volta a seguir a regra.
function grupos_reincluir($grupoId, $clienteId) {
    db()->prepare("DELETE FROM crm_grupo_membros WHERE grupo_id = ? AND cliente_id = ? AND origem = 'excluido'")->execute([$grupoId, $clienteId]);
    grupos_recalcular_cliente($clienteId);
}
// Contagens por grupo, para a lista e para planejar campanhas.
function grupos_contagens() {
    $r = [];
    $sql = "SELECT m.grupo_id, COUNT(*) total,
        SUM(CASE WHEN c.telefone_e164 LIKE '+55__9%' AND LENGTH(c.telefone_e164) = 14 THEN 1 ELSE 0 END) celular,
        SUM(CASE WHEN c.telefone_e164 LIKE '+55__9%' AND LENGTH(c.telefone_e164) = 14 AND c.aceita_whatsapp = 1 THEN 1 ELSE 0 END) optin_wa,
        SUM(CASE WHEN c.aceita_whatsapp = 0 THEN 1 ELSE 0 END) recusa_wa,
        SUM(CASE WHEN c.email LIKE '%_@_%._%' THEN 1 ELSE 0 END) email,
        SUM(CASE WHEN c.email LIKE '%_@_%._%' AND c.aceita_email = 1 THEN 1 ELSE 0 END) optin_email
        FROM crm_grupo_membros m JOIN clientes c ON c.id = m.cliente_id WHERE m.origem <> 'excluido' GROUP BY m.grupo_id";
    foreach (db()->query($sql) as $x) $r[$x['grupo_id']] = $x;
    return $r;
}
