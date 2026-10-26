<?php
// ---------- Vendas · relatório de vendas por item do ERP, produtos e cruzamento com o CRM ----------
// Cada linha do relatório é um item; o documento ("NF-e 2129", "Pedido Vendas 4958"...) agrupa os itens numa venda.
// Reimportar um período já carregado não duplica: o documento é a chave. Bonificações e amostras (CFOP de remessa)
// ficam guardadas, mas fora do faturamento, do ticket e da contagem de compras.

function vendas_v9() {
    static $ok = null;
    if ($ok === null) {
        try {
            db()->query('SELECT v.id, v.cliente_id, v.valor_bonificacao FROM vendas v WHERE 1 = 0');
            db()->query('SELECT i.bonificacao FROM venda_itens i WHERE 1 = 0');
            db()->query('SELECT p.tipo FROM produtos p WHERE 1 = 0');
            db()->query('SELECT a.ignorar FROM vendas_apelidos a WHERE 1 = 0');
            $ok = true;
        } catch (Throwable $ex) { $ok = false; }
    }
    return $ok;
}
function pode_ver_vendas() { return tem_perfil('admin', 'marketing', 'consulta_crm'); }
function pode_vincular_vendas() { return tem_perfil('admin', 'marketing'); }
function pode_importar_vendas() { return eh_admin(); }
// Custo, lucro e margem só para o Administrador.
function ver_custos() { return eh_admin(); }
function exigir_vendas() { if (!pode_ver_vendas()) negar(); }
function vendas_exigir_migracao() {
    crm_exigir_migracao();
    if (vendas_v9()) return;
    $titulo = 'Vendas';
    require __DIR__ . '/topo.php';
    echo '<div class="aviso erro">' . (eh_admin()
        ? 'O módulo de vendas precisa da migração do banco. Rode no phpMyAdmin o arquivo migracao_v9.sql (só cria tabelas novas; nenhum dado existente é alterado).'
        : 'O módulo de vendas está em instalação. Tente novamente mais tarde.') . '</div>';
    require __DIR__ . '/rodape.php';
    exit;
}

// ---------- Produtos ----------
function tipos_produto() {
    return ['tinto' => 'Vinho tinto', 'branco' => 'Vinho branco', 'rose' => 'Vinho rosé', 'espumante' => 'Espumante',
            'taca' => 'Taça (wine bar)', 'experiencia' => 'Tour / degustação / evento', 'comida' => 'Comida', 'bebida' => 'Bebida sem álcool',
            'acessorio' => 'Acessório', 'amostra' => 'Amostra', 'outro' => 'Outro'];
}
function nome_tipo_produto($t) { return tipos_produto()[$t] ?? ($t ?: '—'); }
// Tipos que contam como garrafa de vinho.
function tipos_garrafa() { return ['tinto', 'branco', 'rose', 'espumante']; }
// Sugestão do tipo pela descrição e pelo grupo do ERP (pode ser corrigida na tela de produtos e não volta a mudar).
function tipo_produto_sugerido($descricao, $grupo) {
    $d = ' ' . chave_nome($descricao) . ' '; $g = chave_nome($grupo);
    if (strpos($d, ' amostra ') !== false) return 'amostra';
    if (strpos($g, 'taca') === 0 || preg_match('/ taca (com )?vinho /', $d)) return 'taca';
    if (strpos($g, 'comida') === 0 || strpos($g, 'alimento') === 0 || strpos($g, 'petisco') === 0) return 'comida';
    if (strpos($g, 'bebida') === 0) return 'bebida';
    if (strpos($g, 'evento') === 0 || preg_match('/ (tour|degustacao|experiencia|visita) /', $d)) return 'experiencia';
    if (strpos($g, 'acessorio') === 0) return 'acessorio';
    if (preg_match('/ (espumante|espumantes|brut|nature|prosecco|cava|champagne|frisante) /', $d)) return 'espumante';
    if (preg_match('/ (rose|rosado|rosato) /', $d)) return 'rose';
    if (preg_match('/ (sauvignon blanc|chardonnay|branco|blanc|riesling|viognier|moscato|alvarinho|torrontes|pinot grigio|gewurztraminer|semillon|chenin) /', $d)) return 'branco';
    if (strpos($g, 'vinho') === 0 || preg_match('/ (vinho|syrah|shiraz|malbec|cabernet|merlot|tempranillo|barbera|pinot noir|tannat|carmenere|sangiovese|nebbiolo|tinto|marselan) /', $d)) return 'tinto';
    return 'outro';
}

// CFOPs de saída que não são venda: bonificação/doação/brinde, amostra grátis, demonstração, consignação e "outras saídas".
define('VENDAS_CFOP_NAO_VENDA', ['5910', '6910', '5911', '6911', '5912', '6912', '5917', '6917', '5949', '6949']);
function cfop_nao_venda($cfop) { return in_array(preg_replace('/\D/', '', (string)$cfop), VENDAS_CFOP_NAO_VENDA, true); }
function nome_canal($c) { return ['loja' => 'Loja / wine bar (PDV)', 'faturada' => 'Venda faturada (NF-e)'][$c] ?? $c; }

// ---------- Leitura do relatório ----------
function vendas_num($s) {
    $s = str_replace([' ', 'R$', "\xC2\xA0"], '', trim((string)$s));
    if ($s === '' || $s === '-') return 0.0;
    if (strpos($s, ',') !== false) $s = str_replace(['.', ','], ['', '.'], $s);
    return is_numeric($s) ? (float)$s : 0.0;
}
// Data e hora: número de série do Excel (com fração de hora), dd/mm/aaaa hh:mm[:ss] ou aaaa-mm-dd hh:mm:ss.
function vendas_data($raw) {
    $s = texto_limpo($raw);
    if ($s === '') return null;
    if (is_numeric($s) && (float)$s > 20000 && (float)$s < 80000) {
        $seg = (int)round(((float)$s - 25569) * 86400);   // 25569 = 01/01/1970 no calendário do Excel
        return gmdate('Y-m-d H:i:s', $seg);
    }
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})(?:[ T]+(\d{1,2}):(\d{2})(?::(\d{2}))?)?/', $s, $m) && checkdate((int)$m[2], (int)$m[1], (int)$m[3]))
        return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $m[3], $m[2], $m[1], $m[4] ?? 0, $m[5] ?? 0, $m[6] ?? 0);
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T]+(\d{1,2}):(\d{2})(?::(\d{2}))?)?/', $s, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1]))
        return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $m[1], $m[2], $m[3], $m[4] ?? 0, $m[5] ?? 0, $m[6] ?? 0);
    return null;
}
function vendas_texto($s, $max) { $s = texto_limpo($s); return $s === '' ? null : mb_substr($s, 0, $max); }

// Lê o relatório "Venda de itens" (xlsx ou csv) e devolve as vendas agrupadas por documento.
function vendas_ler($caminho, $nomeOriginal = '') {
    $bruto = preg_match('/\.xlsx$/i', $nomeOriginal ?: $caminho) ? crm_ler_xlsx($caminho) : crm_ler_csv_bruto($caminho);
    $alias = ['filial' => 'filial', 'codigo' => 'codigo', 'eans' => 'ean', 'ean' => 'ean', 'pdv' => 'pdv', 'descricao' => 'descricao', 'produto' => 'descricao',
        'grupo' => 'grupo', 'origem' => 'documento', 'documento' => 'documento', 'data' => 'data', 'cfop' => 'cfop', 'precocusto' => 'preco_custo',
        'precovenda' => 'preco_venda', 'quantidade' => 'quantidade', 'qtd' => 'quantidade', 'deposito' => 'deposito', 'totalcusto' => 'total_custo',
        'desconto' => 'desconto', 'acrescimo' => 'acrescimo', 'liquidoitem' => 'liquido', 'brutoitem' => 'bruto', 'lucro' => 'lucro',
        'totalvenda' => 'total_venda', 'nvenda' => 'numero', 'novenda' => 'numero', 'numerovenda' => 'numero', 'serie' => 'serie', 'cliente' => 'cliente',
        'vendedor' => 'vendedor', 'chaveacesso' => 'chave', 'chavedeacesso' => 'chave', 'observacaovenda' => 'obs'];
    $cabN = null; $mapa = [];
    foreach ($bruto as $n => $r) {
        $m = [];
        foreach ($r as $i => $c) { $k = str_replace(' ', '', chave_nome($c)); if (isset($alias[$k]) && !in_array($alias[$k], $m, true)) $m[$i] = $alias[$k]; }
        if (count(array_intersect(['documento', 'descricao', 'quantidade', 'liquido', 'data'], $m)) === 5) { $cabN = $n; $mapa = $m; break; }
        if ($n >= 15) break;
    }
    if ($cabN === null) throw new RuntimeException('Este arquivo não parece o relatório de venda de itens do ERP: faltam as colunas Origem, Data, Descrição, Quantidade e Líquido item.');
    $vendas = []; $avisos = []; $linhas = 0; $ignoradas = 0;
    foreach ($bruto as $n => $r) {
        if ($n <= $cabN) continue;
        $l = [];
        foreach ($mapa as $i => $campo) $l[$campo] = (string)($r[$i] ?? '');
        $doc = texto_limpo($l['documento'] ?? '');
        if (stripos(texto_limpo($l['filial'] ?? ''), 'totais') === 0 || $doc === '' || texto_limpo($l['descricao'] ?? '') === '') {
            if (trim(implode('', $l)) !== '' && stripos(texto_limpo($l['filial'] ?? ''), 'totais') !== 0) $ignoradas++;
            continue;
        }
        $data = vendas_data($l['data'] ?? '');
        if (!$data) { $avisos[] = "Linha $n ($doc): data não reconhecida, linha ignorada."; $ignoradas++; continue; }
        $linhas++;
        $doc = mb_substr($doc, 0, 60);
        if (!isset($vendas[$doc])) {
            $tipo = preg_match('/^(.*?)\s*(\d+)\s*$/u', $doc, $m) ? $m[1] : $doc;
            $num = preg_match('/^(.*?)\s*(\d+)\s*$/u', $doc, $m) ? $m[2] : vendas_texto(preg_replace('/\.0+$/', '', $l['numero'] ?? ''), 30);
            $vendas[$doc] = ['documento' => $doc, 'tipo_doc' => mb_substr(texto_limpo($tipo) ?: 'Documento', 0, 30), 'numero' => $num,
                'canal' => preg_match('/^nf e$/', chave_nome($tipo)) ? 'faturada' : 'loja', 'data' => $data,
                'cliente_nome' => vendas_texto($l['cliente'] ?? '', 200), 'vendedor' => vendas_texto($l['vendedor'] ?? '', 120),
                'pdv' => vendas_texto($l['pdv'] ?? '', 60), 'deposito' => vendas_texto($l['deposito'] ?? '', 120), 'serie' => vendas_texto($l['serie'] ?? '', 30),
                'chave_acesso' => vendas_texto(preg_replace('/\D/', '', $l['chave'] ?? ''), 60), 'observacao' => vendas_texto($l['obs'] ?? '', 500),
                'total_erp' => vendas_num($l['total_venda'] ?? ''), 'itens' => [], '_linhas' => []];
        }
        $cod = texto_limpo(preg_replace('/\.0+$/', '', $l['codigo'] ?? ''));
        $desc = mb_substr(texto_limpo(str_replace(["\r", "\n"], ' ', $l['descricao'])), 0, 200);
        if ($cod === '') $cod = 'SEM-' . substr(md5(chave_nome($desc)), 0, 12);
        $cfop = preg_replace('/\D/', '', preg_replace('/\.0+$/', '', $l['cfop'] ?? ''));
        $vendas[$doc]['itens'][] = ['codigo' => mb_substr($cod, 0, 30), 'descricao' => $desc, 'grupo' => vendas_texto($l['grupo'] ?? '', 80),
            'ean' => vendas_texto(preg_replace('/\.0+$/', '', $l['ean'] ?? ''), 40), 'cfop' => $cfop !== '' ? mb_substr($cfop, 0, 10) : null,
            'bonificacao' => cfop_nao_venda($cfop) ? 1 : 0, 'quantidade' => round(vendas_num($l['quantidade'] ?? ''), 3),
            'preco_unit' => round(vendas_num($l['preco_venda'] ?? ''), 2), 'preco_custo' => round(vendas_num($l['preco_custo'] ?? ''), 2),
            'custo_total' => round(vendas_num($l['total_custo'] ?? ''), 2), 'desconto' => round(vendas_num($l['desconto'] ?? ''), 2),
            'acrescimo' => round(vendas_num($l['acrescimo'] ?? ''), 2), 'bruto' => round(vendas_num($l['bruto'] ?? ''), 2),
            'liquido' => round(vendas_num($l['liquido'] ?? ''), 2), 'lucro' => round(vendas_num($l['lucro'] ?? ''), 2), '_data' => $data];
        $vendas[$doc]['_linhas'][] = $n;
    }
    if (!$vendas) throw new RuntimeException('Nenhuma venda encontrada no arquivo.');
    if ($linhas > 50000) throw new RuntimeException('Arquivo com mais de 50.000 linhas. Exporte um período menor.');
    $ini = null; $fim = null;
    foreach ($vendas as $doc => &$v) {
        $soma = 0.0;
        foreach (['itens_qtd' => 0, 'valor_bruto' => 0, 'desconto' => 0, 'acrescimo' => 0, 'valor_total' => 0, 'custo_total' => 0, 'lucro' => 0, 'valor_bonificacao' => 0, 'custo_bonificacao' => 0] as $k => $z) $v[$k] = 0.0;
        foreach ($v['itens'] as $i) {
            $soma += $i['liquido'];
            if ($i['bonificacao']) { $v['valor_bonificacao'] += $i['liquido']; $v['custo_bonificacao'] += $i['custo_total']; continue; }
            $v['itens_qtd'] += $i['quantidade']; $v['valor_bruto'] += $i['bruto']; $v['desconto'] += $i['desconto']; $v['acrescimo'] += $i['acrescimo'];
            $v['valor_total'] += $i['liquido']; $v['custo_total'] += $i['custo_total']; $v['lucro'] += $i['lucro'];
        }
        foreach (['valor_bruto', 'desconto', 'acrescimo', 'valor_total', 'custo_total', 'lucro', 'valor_bonificacao', 'custo_bonificacao'] as $k) $v[$k] = round($v[$k], 2);
        $v['itens_qtd'] = round($v['itens_qtd'], 3);
        if ($v['total_erp'] > 0 && abs(round($soma, 2) - $v['total_erp']) > 0.05)
            $avisos[] = $doc . ': a soma dos itens (' . brl($soma) . ') difere do total da venda no ERP (' . brl($v['total_erp']) . '). Confira se o relatório saiu completo.';
        $itensHash = array_map(fn($i) => array_diff_key($i, ['_data' => 1]), $v['itens']);
        usort($itensHash, fn($a, $b) => strcmp(json_encode($a), json_encode($b)));
        $v['hash'] = md5(json_encode([$v['data'], $v['cliente_nome'], $v['vendedor'], $v['pdv'], $v['deposito'], $v['canal'], $itensHash]));
        $d = substr($v['data'], 0, 10);
        if ($ini === null || $d < $ini) $ini = $d;
        if ($fim === null || $d > $fim) $fim = $d;
    }
    unset($v);
    return ['vendas' => $vendas, 'avisos' => $avisos, 'linhas' => $linhas, 'ignoradas' => $ignoradas, 'ini' => $ini, 'fim' => $fim];
}

// ---------- Cruzamento do nome do ERP com o cadastro de clientes ----------
function chave_cliente_erp($nome) { return mb_substr(chave_nome($nome), 0, 200); }
// Chave "de empresa": sem LTDA, ME, EIRELI, S/A... e sem conectivos, para "VN SERVICOS MEDICOS LTDA" = "VN Serviços Médicos".
function chave_empresa($nome) {
    $t = explode(' ', chave_nome($nome));
    $fora = ['ltda', 'ltd', 'me', 'epp', 'eireli', 'eirele', 'mei', 'cia', 'limitada', 'ss', 'de', 'da', 'do', 'dos', 'das', 'e'];
    $r = [];
    foreach ($t as $i => $w) if ($w !== '' && ($i === 0 || !in_array($w, $fora, true))) $r[] = $w;
    $s = implode(' ', $r);
    return trim(preg_replace('/ s a$| sa$/', '', $s));
}
function parece_empresa($nome) {
    $n = ' ' . chave_nome($nome) . ' ';
    return (bool)preg_match('/ (ltda|ltd|me|epp|eireli|mei|s a|sa|cia|comercio|comercial|industria|restaurante|restaurantes|bar|bares|supermercados?|mercado|atacadista|atacado|distribuidora?|distribuicao|importacao|importadora|exportacao|associacao|conselho|sindicato|instituto|fundacao|cooperativa|hotelaria|hotel|hoteis|pousada|emporio|adega|vinhos|vinhedo|vineria|winebar|wine|advogados|advocacia|consultoria|servicos|solucoes|empreendimentos|construtora|construcao|engenharia|clinica|odontologia|medicos|gastronomia|eventos|buffet|grupo|holding|participacoes|tecnologia|sistemas|transportes|logistica|veiculos|parrilla|lounge|cafe|grill|pizzaria|padaria|farmacia|escola|colegio|igreja|banco|cartorio|condominio|embaixada|ministerio|secretaria|tribunal|universidade|faculdade|federacao|confederacao|camara|prefeitura|governo|marketing|mkt|comunicacao|publicidade|imobiliaria|incorporadora|agropecuaria|agricola|fazenda|industrial) /', $n);
}

// Índices de nomes dos clientes, para cruzar sem comparar cada nome com a base inteira.
function vendas_indice_clientes() {
    $ix = ['k1' => [], 'k2' => [], 'tok' => [], 'cli' => []];
    foreach (db()->query('SELECT id, nome, tipo_pessoa, cpf, cnpj, telefone, email, cidade FROM clientes') as $c) {
        $id = (int)$c['id'];
        $c['k1'] = chave_cliente_erp($c['nome']); $c['k2'] = chave_empresa($c['nome']);
        $ix['cli'][$id] = $c;
        if ($c['k1'] !== '') $ix['k1'][$c['k1']][] = $id;
        if ($c['k2'] !== '') $ix['k2'][$c['k2']][] = $id;
        foreach (array_unique(explode(' ', $c['k2'])) as $w) if (strlen($w) >= 3) $ix['tok'][$w][] = $id;
    }
    $ix['vendas'] = db()->query('SELECT cliente_id, COUNT(*) FROM vendas WHERE cliente_id IS NOT NULL GROUP BY cliente_id')->fetchAll(PDO::FETCH_KEY_PAIR);
    $ix['apelidos'] = [];
    foreach (db()->query('SELECT chave, cliente_id, ignorar FROM vendas_apelidos') as $a) $ix['apelidos'][$a['chave']] = $a;
    return $ix;
}
// Entre vários clientes com o mesmo nome, fica o que já tem compras, depois o que tem documento, depois o mais antigo.
function vendas_melhor_candidato($ids, $ix) {
    usort($ids, function ($a, $b) use ($ix) {
        $va = (int)($ix['vendas'][$a] ?? 0) > 0; $vb = (int)($ix['vendas'][$b] ?? 0) > 0;
        if ($va !== $vb) return $vb <=> $va;
        $da = !empty($ix['cli'][$a]['cnpj']) || !empty($ix['cli'][$a]['cpf']); $db = !empty($ix['cli'][$b]['cnpj']) || !empty($ix['cli'][$b]['cpf']);
        if ($da !== $db) return $db <=> $da;
        return $a <=> $b;
    });
    return $ids;
}
// Devolve como o nome do ERP casa com o cadastro: vínculo lembrado, nome igual, nome de empresa igual, nome cortado pelo ERP,
// ou nada (com até 3 sugestões parecidas).
function vendas_achar_cliente($nome, $ix) {
    $k1 = chave_cliente_erp($nome); $k2 = chave_empresa($nome);
    $r = ['chave' => $k1, 'como' => 'nenhum', 'cliente_id' => null, 'candidatos' => [], 'sugestoes' => []];
    if (isset($ix['apelidos'][$k1])) {
        $a = $ix['apelidos'][$k1];
        if ($a['ignorar']) return ['como' => 'ignorar'] + $r;
        if ($a['cliente_id'] && isset($ix['cli'][(int)$a['cliente_id']])) return ['como' => 'lembrado', 'cliente_id' => (int)$a['cliente_id']] + $r;
    }
    $achou = function ($ids, $como) use ($ix, $r) {
        $ids = vendas_melhor_candidato(array_values(array_unique($ids)), $ix);
        return ['como' => count($ids) > 1 ? 'ambiguo' : $como, 'cliente_id' => $ids[0], 'candidatos' => $ids] + $r;
    };
    if (!empty($ix['k1'][$k1])) return $achou($ix['k1'][$k1], 'nome');
    if ($k2 !== '' && !empty($ix['k2'][$k2])) return $achou($ix['k2'][$k2], 'empresa');
    // O ERP corta nomes longos (60 caracteres): compara o começo, sem a última palavra, que pode estar cortada.
    if (mb_strlen(texto_limpo($nome)) >= 55) {
        $p = explode(' ', $k2); array_pop($p); $pref = implode(' ', $p);
        if (strlen($pref) >= 20) {
            $ids = [];
            foreach ($ix['cli'] as $id => $c) if (strpos($c['k2'], $pref) === 0) $ids[] = $id;
            if ($ids) return $achou($ids, 'cortado');
        }
    }
    // Sugestões: clientes com palavras em comum (palavras genéricas como "comércio" ou "restaurante" não bastam),
    // pontuados pela semelhança do nome. Quando todas as palavras de um nome estão no outro, a nota é 92.
    $genericas = array_flip(['comercio', 'comercial', 'restaurante', 'restaurantes', 'alimentos', 'bebidas', 'distribuicao', 'distribuidora', 'importacao', 'exportacao',
        'logistica', 'servicos', 'solucoes', 'empreendimentos', 'brasil', 'brasilia', 'bar', 'vinhos', 'produtos', 'gastronomia', 'industria', 'grupo', 'eventos',
        'organizacao', 'construcao', 'materiais', 'veiculos', 'pecas', 'projetos', 'empresariais', 'consultoria', 'associacao', 'conselho', 'regional', 'nacional',
        'supermercado', 'supermercados', 'atacadista', 'emporio', 'adega', 'hotel', 'hotelaria', 'cafe', 'lounge', 'grill', 'wine', 'porto', 'terra', 'lago', 'central',
        'centro', 'park', 'grande', 'nova', 'novo', 'prime', 'alto', 'administracao', 'brasileira', 'brasileiro', 'empresas', 'empresarial', 'superintendencia', 'distrito',
        'federal', 'banco', 'casa', 'santa', 'santo', 'sao', 'norte', 'oeste', 'leste', 'maria', 'jose', 'ana', 'joao', 'silva', 'souza', 'santos', 'oliveira', 'pereira', 'costa', 'ferreira', 'rodrigues', 'lima', 'alves', 'gomes']);
    $cand = [];
    $toks = array_filter(array_unique(explode(' ', $k2)), fn($w) => strlen($w) >= 3);
    $fortes = array_filter($toks, fn($w) => strlen($w) >= 4 && !isset($genericas[$w]));
    $pj = parece_empresa($nome);
    foreach ($toks as $w) foreach ($ix['tok'][$w] ?? [] as $id) $cand[$id]['n'] = ($cand[$id]['n'] ?? 0) + 1;
    foreach ($fortes as $w) foreach ($ix['tok'][$w] ?? [] as $id) $cand[$id]['f'] = ($cand[$id]['f'] ?? 0) + 1;
    $pont = [];
    $tA = array_values(array_filter(explode(' ', $k2)));
    foreach ($cand as $id => $x) {
        if (empty($x['f']) && ($x['n'] ?? 0) < 2) continue;
        if ($pj && $ix['cli'][$id]['tipo_pessoa'] !== 'PJ') continue;   // nome de empresa não sugere pessoa física
        $c2 = $ix['cli'][$id]['k2'];
        $tB = array_values(array_filter(explode(' ', $c2)));
        similar_text($k2, $c2, $pc);
        $menor = count($tA) <= count($tB) ? $tA : $tB; $maior = count($tA) <= count($tB) ? $tB : $tA;
        if (count($menor) >= 2 && !array_diff($menor, $maior)) $pc = max($pc, 92);   // todas as palavras de um estão no outro
        elseif (!empty($x['f'])) $pc = max($pc, min(80, 45 + 15 * $x['f']));          // compartilham palavra própria (ex.: o sobrenome da empresa)
        if ($pc >= 55) $pont[$id] = round($pc);
    }
    arsort($pont);
    $r['sugestoes'] = array_slice($pont, 0, 3, true);
    return $r;
}

// ---------- Plano da importação (nada é gravado) ----------
function vendas_planejar($dados) {
    $pdo = db();
    $docs = array_keys($dados['vendas']);
    $exist = [];
    foreach (array_chunk($docs, 300) as $lote) {
        $s = $pdo->prepare('SELECT id, documento, hash, cliente_id, cliente_nome FROM vendas WHERE documento IN (' . implode(',', array_fill(0, count($lote), '?')) . ')');
        $s->execute($lote);
        foreach ($s as $r) $exist[$r['documento']] = $r;
    }
    $plano = ['novas' => [], 'atualizar' => [], 'iguais' => [], 'nomes' => [], 'produtos_novos' => [], 'avisos' => $dados['avisos'],
        'linhas' => $dados['linhas'], 'ignoradas' => $dados['ignoradas'], 'ini' => $dados['ini'], 'fim' => $dados['fim'],
        'valor' => 0.0, 'bonificacao' => 0.0, 'n_vendas' => 0, 'balcao' => 0, 'valor_balcao' => 0.0];
    foreach ($dados['vendas'] as $doc => $v) {
        if (!isset($exist[$doc])) $plano['novas'][] = $doc;
        elseif ($exist[$doc]['hash'] !== $v['hash']) $plano['atualizar'][$doc] = (int)$exist[$doc]['id'];
        else $plano['iguais'][$doc] = (int)$exist[$doc]['id'];
        $plano['valor'] += $v['valor_total']; $plano['bonificacao'] += $v['valor_bonificacao'];
        if ($v['valor_total'] > 0) $plano['n_vendas']++;
        if ($v['cliente_nome'] === null && $v['valor_total'] > 0) { $plano['balcao']++; $plano['valor_balcao'] += $v['valor_total']; }
    }
    // Nomes de cliente do arquivo (um por pessoa/empresa), com o cruzamento sugerido.
    $ix = vendas_indice_clientes();
    foreach ($dados['vendas'] as $v) {
        if ($v['cliente_nome'] === null) continue;
        $k = chave_cliente_erp($v['cliente_nome']);
        if ($k === '') continue;
        if (!isset($plano['nomes'][$k])) {
            $m = vendas_achar_cliente($v['cliente_nome'], $ix);
            $pj = parece_empresa($v['cliente_nome']);
            $padrao = $m['cliente_id'] ? 'c:' . $m['cliente_id'] : ($m['como'] === 'ignorar' ? 'nenhum' : 'novo:' . ($pj ? 'PJ' : 'PF'));
            if (!$m['cliente_id'] && $m['como'] !== 'ignorar' && $m['sugestoes'] && reset($m['sugestoes']) >= 92) { $padrao = 'c:' . key($m['sugestoes']); $m['como'] = 'parecido'; }
            $plano['nomes'][$k] = ['nome' => $v['cliente_nome'], 'chave' => $k, 'pj' => $pj, 'vendas' => 0, 'valor' => 0.0, 'bonif' => 0.0,
                'primeira' => $v['data'], 'vendedor' => $v['vendedor'], 'match' => $m, 'padrao' => $padrao];
        }
        $n = &$plano['nomes'][$k];
        if ($v['valor_total'] > 0) $n['vendas']++;
        $n['valor'] += $v['valor_total']; $n['bonif'] += $v['valor_bonificacao'];
        if ($v['data'] < $n['primeira']) { $n['primeira'] = $v['data']; $n['vendedor'] = $v['vendedor']; }
        unset($n);
    }
    uasort($plano['nomes'], fn($a, $b) => [vendas_ordem_nome($a), -$a['valor']] <=> [vendas_ordem_nome($b), -$b['valor']]);
    $plano['clientes'] = $ix['cli'];
    // Produtos que ainda não estão no cadastro.
    $cods = $pdo->query('SELECT codigo FROM produtos')->fetchAll(PDO::FETCH_COLUMN);
    $cods = array_flip($cods);
    foreach ($dados['vendas'] as $v) foreach ($v['itens'] as $i) if (!isset($cods[$i['codigo']]) && !isset($plano['produtos_novos'][$i['codigo']]))
        $plano['produtos_novos'][$i['codigo']] = ['codigo' => $i['codigo'], 'descricao' => $i['descricao'], 'grupo' => $i['grupo'], 'tipo' => tipo_produto_sugerido($i['descricao'], $i['grupo'])];
    $plano['valor'] = round($plano['valor'], 2); $plano['bonificacao'] = round($plano['bonificacao'], 2);
    return $plano;
}
// Ordem da lista de nomes na prévia: primeiro o que precisa de conferência.
function vendas_ordem_nome($n) { return ['ambiguo' => 0, 'nenhum' => 1, 'parecido' => 2, 'cortado' => 3, 'empresa' => 4, 'ignorar' => 4, 'nome' => 5, 'lembrado' => 6][$n['match']['como']] ?? 9; }
function vendas_como_rotulo($como) {
    return ['lembrado' => 'Vínculo lembrado', 'nome' => 'Nome igual', 'empresa' => 'Mesma empresa (sem LTDA/ME)', 'cortado' => 'Nome cortado pelo ERP',
            'ambiguo' => 'Mais de um cliente com esse nome', 'nenhum' => 'Não encontrado', 'parecido' => 'Nome parecido (confira)', 'ignorar' => 'Marcado para não vincular'][$como] ?? $como;
}

// ---------- Gravação ----------
// $decisoes: chave do nome → 'c:ID' (vincular), 'novo:PF' / 'novo:PJ' (cadastrar) ou 'nenhum'. O que não vier usa o padrão do plano.
function vendas_executar($dados, $plano, $decisoes, $arquivo) {
    $pdo = db(); $u = $GLOBALS['USUARIO']; $agora = date('Y-m-d H:i:s');
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO vendas_importacoes (arquivo, usuario_id, usuario_nome, periodo_ini, periodo_fim, criado_em) VALUES (?,?,?,?,?,?)')
            ->execute([mb_substr($arquivo, 0, 255), $u['id'], $u['nome'], $plano['ini'], $plano['fim'], $agora]);
        $lote = (int)$pdo->lastInsertId();

        // 1) Clientes: resolve cada nome; cadastra os novos num lote do CRM (aparece em Importar clientes e pode ser desfeito lá).
        $clienteDe = []; $crmLote = null; $novosCli = 0; $lembrar = [];
        foreach ($plano['nomes'] as $k => $n) {
            $d = (string)($decisoes[$k] ?? $n['padrao']);
            if (preg_match('/^c:(\d+)$/', $d, $m)) {
                $id = (int)$m[1];
                $s = $pdo->prepare('SELECT id FROM clientes WHERE id = ?'); $s->execute([$id]);
                if (!$s->fetchColumn()) throw new RuntimeException('O cliente escolhido para "' . $n['nome'] . '" não existe mais. Recarregue a prévia.');
                $clienteDe[$k] = $id;
                if (!in_array($n['match']['como'], ['lembrado', 'nome'], true) || $d !== $n['padrao']) $lembrar[$k] = [$n['nome'], $id, 0];
            } elseif (preg_match('/^novo:(PF|PJ)$/', $d, $m)) {
                if ($crmLote === null) {
                    $pdo->prepare('INSERT INTO crm_importacoes (arquivo, usuario_id, usuario_nome, criado_em) VALUES (?,?,?,?)')
                        ->execute([mb_substr('Vendas do ERP · ' . $arquivo, 0, 255), $u['id'], $u['nome'], $agora]);
                    $crmLote = (int)$pdo->lastInsertId();
                }
                $tipo = $m[1];
                $nome = $tipo === 'PJ' ? trim(texto_limpo($n['nome']), " .,;:-_*'\"") : nome_proprio($n['nome']);
                $sexo = $tipo === 'PF' ? sexo_por_nome($nome) : null;
                $pdo->prepare('INSERT INTO clientes (nome, tipo_pessoa, sexo, sexo_presumido, origem, vendedor, cliente_desde, ultima_interacao, pendencias, importacao_id, clube_beneficios, categoria_auto, criado_em)
                    VALUES (?,?,?,?,?,?,?,?,?,?,0,0,?)')
                    ->execute([mb_substr($nome, 0, 160), $tipo, $sexo, $sexo ? 1 : 0, 'erp', $n['vendedor'], substr($n['primeira'], 0, 10), substr($n['primeira'], 0, 10),
                        'cadastrado pela importação de vendas: completar ' . ($tipo === 'PJ' ? 'CNPJ' : 'CPF') . ', telefone e e-mail', $crmLote, $agora]);
                $id = (int)$pdo->lastInsertId();
                registrar_log('cliente', $id, 'Inclusão (vendas do ERP #' . $lote . ')', $nome . ' · comprou ' . brl($n['valor']) . ' em ' . $n['vendas'] . ' venda(s)');
                $clienteDe[$k] = $id; $novosCli++;
                $lembrar[$k] = [$n['nome'], $id, 0];
            } else {
                $clienteDe[$k] = null;
                if ($n['match']['como'] !== 'ignorar') $lembrar[$k] = [$n['nome'], null, 1];
            }
        }
        $delA = $pdo->prepare('DELETE FROM vendas_apelidos WHERE chave = ?');
        $insA = $pdo->prepare('INSERT INTO vendas_apelidos (chave, nome_erp, cliente_id, ignorar, definido_por, definido_em) VALUES (?,?,?,?,?,?)');
        foreach ($lembrar as $k => [$nomeErp, $cid, $ign]) { $delA->execute([$k]); $insA->execute([$k, mb_substr($nomeErp, 0, 200), $cid, $ign, $u['nome'], $agora]); }
        if ($crmLote) {
            $pdo->prepare('UPDATE crm_importacoes SET novos = ? WHERE id = ?')->execute([$novosCli, $crmLote]);
            registrar_log('importacao', $crmLote, 'Importação de clientes', 'Cadastrados pela importação de vendas #' . $lote . ': ' . $novosCli . ' novos');
        }

        // 2) Produtos: cria os novos; nos existentes atualiza grupo, EAN e últimos preços (descrição e tipo editados na tela ficam).
        $prod = $pdo->query('SELECT codigo, id FROM produtos')->fetchAll(PDO::FETCH_KEY_PAIR);
        $ultimo = [];
        foreach ($dados['vendas'] as $v) foreach ($v['itens'] as $i) {
            $c = $i['codigo'];
            if (!isset($ultimo[$c])) $ultimo[$c] = ['descricao' => $i['descricao'], 'grupo' => null, 'ean' => null, 'preco_unit' => 0, 'preco_custo' => 0, '_data' => '', '_dataPreco' => ''];
            $u = &$ultimo[$c];
            if ($i['_data'] >= $u['_data']) { $u['_data'] = $i['_data']; $u['grupo'] = $i['grupo'] ?? $u['grupo']; $u['ean'] = $i['ean'] ?? $u['ean']; }
            if (!$i['bonificacao'] && $i['preco_unit'] > 0 && $i['_data'] >= $u['_dataPreco']) { $u['_dataPreco'] = $i['_data']; $u['preco_unit'] = $i['preco_unit']; $u['preco_custo'] = $i['preco_custo']; }
            unset($u);
        }
        $insP = $pdo->prepare('INSERT INTO produtos (codigo, descricao, grupo, tipo, ean, preco_venda, preco_custo, criado_em, atualizado_em) VALUES (?,?,?,?,?,?,?,?,?)');
        $upP = $pdo->prepare('UPDATE produtos SET grupo = COALESCE(?, grupo), ean = COALESCE(?, ean), preco_venda = ?, preco_custo = ?, atualizado_em = ? WHERE id = ?');
        $prodNovos = 0;
        foreach ($ultimo as $c => $i) {
            $pv = $i['preco_unit'] > 0 ? $i['preco_unit'] : null; $pc = $i['preco_custo'] > 0 ? $i['preco_custo'] : null;
            if (isset($prod[$c])) { $upP->execute([$i['grupo'], $i['ean'], $pv, $pc, $agora, $prod[$c]]); continue; }
            $insP->execute([$c, $i['descricao'], $i['grupo'], tipo_produto_sugerido($i['descricao'], $i['grupo']), $i['ean'], $pv, $pc, $agora, $agora]);
            $prod[$c] = (int)$pdo->lastInsertId(); $prodNovos++;
        }

        // 3) Vendas e itens.
        $cols = ['documento', 'tipo_doc', 'numero', 'canal', 'data', 'cliente_nome', 'cliente_id', 'vendedor', 'pdv', 'deposito', 'serie', 'chave_acesso', 'observacao',
            'itens_qtd', 'valor_bruto', 'desconto', 'acrescimo', 'valor_total', 'custo_total', 'lucro', 'valor_bonificacao', 'custo_bonificacao', 'hash'];
        $insV = $pdo->prepare('INSERT INTO vendas (' . implode(', ', $cols) . ', importacao_id, criado_em) VALUES (' . implode(',', array_fill(0, count($cols) + 2, '?')) . ')');
        $upV = $pdo->prepare('UPDATE vendas SET ' . implode(' = ?, ', $cols) . ' = ? WHERE id = ?');
        $delI = $pdo->prepare('DELETE FROM venda_itens WHERE venda_id = ?');
        $icols = ['venda_id', 'produto_id', 'descricao', 'cfop', 'bonificacao', 'quantidade', 'preco_unit', 'custo_total', 'desconto', 'acrescimo', 'bruto', 'liquido', 'lucro'];
        $atuais = [];
        $ids = array_merge(array_values($plano['atualizar']), array_values($plano['iguais']));
        foreach (array_chunk($ids, 400) as $l) foreach ($pdo->query('SELECT id, cliente_id, cliente_nome FROM vendas WHERE id IN (' . implode(',', array_map('intval', $l)) . ')') as $r) $atuais[(int)$r['id']] = $r;
        $nItens = 0; $religadas = 0;
        foreach ($dados['vendas'] as $doc => $v) {
            $k = $v['cliente_nome'] !== null ? chave_cliente_erp($v['cliente_nome']) : '';
            $cid = $k !== '' ? ($clienteDe[$k] ?? null) : null;
            $idExist = $plano['atualizar'][$doc] ?? $plano['iguais'][$doc] ?? null;
            // Venda de balcão (sem nome) vinculada à mão a um cliente: o vínculo é mantido.
            if ($idExist && $k === '' && !empty($atuais[$idExist]['cliente_id'])) $cid = (int)$atuais[$idExist]['cliente_id'];
            $vals = array_map(fn($c) => $c === 'cliente_id' ? $cid : $v[$c], $cols);
            if (isset($plano['iguais'][$doc])) {
                if ((string)($atuais[$idExist]['cliente_id'] ?? '') !== (string)$cid) {
                    $pdo->prepare('UPDATE vendas SET cliente_id = ? WHERE id = ?')->execute([$cid, $idExist]); $religadas++;
                }
                continue;
            }
            if ($idExist) { $upV->execute(array_merge($vals, [$idExist])); $delI->execute([$idExist]); $vid = $idExist; }
            else { $insV->execute(array_merge($vals, [$lote, $agora])); $vid = (int)$pdo->lastInsertId(); }
            foreach (array_chunk($v['itens'], 200) as $bloco) {
                $par = [];
                foreach ($bloco as $i) array_push($par, $vid, $prod[$i['codigo']] ?? null, $i['descricao'], $i['cfop'], $i['bonificacao'], $i['quantidade'], $i['preco_unit'],
                    $i['custo_total'], $i['desconto'], $i['acrescimo'], $i['bruto'], $i['liquido'], $i['lucro']);
                $pdo->prepare('INSERT INTO venda_itens (' . implode(', ', $icols) . ') VALUES ' . implode(',', array_fill(0, count($bloco), '(' . implode(',', array_fill(0, count($icols), '?')) . ')')))->execute($par);
                $nItens += count($bloco);
            }
        }
        $pdo->prepare('UPDATE vendas_importacoes SET vendas_novas = ?, vendas_atualizadas = ?, vendas_iguais = ?, itens = ?, clientes_novos = ?, produtos_novos = ?, valor_total = ?, crm_lote_id = ? WHERE id = ?')
            ->execute([count($plano['novas']), count($plano['atualizar']), count($plano['iguais']), $nItens, $novosCli, $prodNovos, $plano['valor'], $crmLote, $lote]);
        registrar_log('vendas_importacao', $lote, 'Importação de vendas', $arquivo . ' (' . data_br($plano['ini']) . ' a ' . data_br($plano['fim']) . '): '
            . count($plano['novas']) . ' vendas novas, ' . count($plano['atualizar']) . ' atualizadas, ' . count($plano['iguais']) . ' já existiam'
            . ($religadas ? ' (' . $religadas . ' com vínculo de cliente revisto)' : '') . '; ' . $novosCli . ' clientes e ' . $prodNovos . ' produtos cadastrados; ' . brl($plano['valor']));
        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
    grupos_recalcular_todos();
    return $lote;
}

// Desfaz um lote: apaga as vendas que ele criou (vendas que ele só atualizou continuam) e os clientes que ele cadastrou
// e que não têm outras compras nem tours.
function vendas_desfazer($lote) {
    $pdo = db();
    $s = $pdo->prepare('SELECT * FROM vendas_importacoes WHERE id = ?'); $s->execute([$lote]); $imp = $s->fetch();
    if (!$imp || $imp['status'] !== 'concluida') return null;
    $pdo->beginTransaction();
    try {
        $s = $pdo->prepare('SELECT id FROM vendas WHERE importacao_id = ?'); $s->execute([$lote]); $ids = $s->fetchAll(PDO::FETCH_COLUMN);
        foreach (array_chunk($ids, 400) as $l) {
            $in = implode(',', array_map('intval', $l));
            $pdo->exec("DELETE FROM venda_itens WHERE venda_id IN ($in)");
            $pdo->exec("DELETE FROM vendas WHERE id IN ($in)");
        }
        $pdo->prepare("UPDATE vendas_importacoes SET status = 'desfeita', desfeita_em = ?, desfeita_por = ? WHERE id = ?")->execute([date('Y-m-d H:i:s'), $GLOBALS['USUARIO']['nome'], $lote]);
        registrar_log('vendas_importacao', $lote, 'Importação de vendas desfeita', count($ids) . ' venda(s) removida(s); vendas que ela só atualizou continuam');
        $pdo->commit();
    } catch (Throwable $ex) { $pdo->rollBack(); throw $ex; }
    $cli = null;
    if ($imp['crm_lote_id']) $cli = crm_desfazer((int)$imp['crm_lote_id']);
    grupos_recalcular_todos();
    return [count($ids), $cli];
}

// Vincula (ou desvincula) as vendas de um nome do ERP a um cliente e lembra para as próximas importações.
// Sem nome (venda de balcão), vincula só aquela venda.
function vendas_vincular($vendaId, $clienteId, $todasDoNome = true) {
    $pdo = db();
    $s = $pdo->prepare('SELECT * FROM vendas WHERE id = ?'); $s->execute([$vendaId]); $v = $s->fetch();
    if (!$v) return 0;
    $clienteId = $clienteId ?: null;
    $afetados = [];
    $pdo->beginTransaction();
    try {
        if ($v['cliente_nome'] !== null && $v['cliente_nome'] !== '' && $todasDoNome) {
            $k = chave_cliente_erp($v['cliente_nome']);
            $s = $pdo->prepare('SELECT id, cliente_id FROM vendas WHERE cliente_nome = ?'); $s->execute([$v['cliente_nome']]);
            foreach ($s->fetchAll() as $r) $afetados[(int)$r['id']] = $r['cliente_id'];
            $pdo->prepare('UPDATE vendas SET cliente_id = ? WHERE cliente_nome = ?')->execute([$clienteId, $v['cliente_nome']]);
            $pdo->prepare('DELETE FROM vendas_apelidos WHERE chave = ?')->execute([$k]);
            $pdo->prepare('INSERT INTO vendas_apelidos (chave, nome_erp, cliente_id, ignorar, definido_por, definido_em) VALUES (?,?,?,?,?,?)')
                ->execute([$k, $v['cliente_nome'], $clienteId, $clienteId ? 0 : 1, $GLOBALS['USUARIO']['nome'], date('Y-m-d H:i:s')]);
        } else {
            $afetados[(int)$v['id']] = $v['cliente_id'];
            $pdo->prepare('UPDATE vendas SET cliente_id = ? WHERE id = ?')->execute([$clienteId, $v['id']]);
        }
        $nomeCli = $clienteId ? (linha('clientes', $clienteId)['nome'] ?? '#' . $clienteId) : 'nenhum';
        registrar_log('venda', $v['id'], 'Vínculo de cliente', ($v['cliente_nome'] ?: 'venda sem nome') . ' → ' . $nomeCli . ' (' . count($afetados) . ' venda(s)' . (count($afetados) > 1 || $v['cliente_nome'] ? '; vale para as próximas importações' : '') . ')');
        $pdo->commit();
    } catch (Throwable $ex) { $pdo->rollBack(); throw $ex; }
    foreach (array_unique(array_filter(array_merge(array_values($afetados), [$clienteId]))) as $c) grupos_recalcular_cliente((int)$c);
    return count($afetados);
}

// ---------- Consultas para telas ----------
// Período escolhido nas telas: mês (aaaa-mm), últimos 3/12 meses, ano atual ou intervalo de datas.
function vendas_periodo($g) {
    $ultimo = db()->query('SELECT MAX(data) FROM vendas')->fetchColumn();
    $ref = $ultimo ? substr($ultimo, 0, 7) : date('Y-m');
    $p = (string)($g['p'] ?? '');
    $ini = $fim = null; $rot = '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $g['ini'] ?? '') && preg_match('/^\d{4}-\d{2}-\d{2}$/', $g['fim'] ?? '') && $g['ini'] <= $g['fim']) {
        $ini = $g['ini']; $fim = $g['fim']; $p = 'livre'; $rot = data_br($ini) . ' a ' . data_br($fim);
    } elseif ($p === 'u3' || $p === 'u12') {
        $n = $p === 'u3' ? 2 : 11;
        $ini = date('Y-m-01', strtotime($ref . "-01 -$n months")); $fim = date('Y-m-t', strtotime($ref . '-01'));
        $rot = ($p === 'u3' ? 'Últimos 3 meses' : 'Últimos 12 meses') . ' (' . rotulo_mes(substr($ini, 0, 7)) . ' a ' . rotulo_mes($ref) . ')';
    } elseif ($p === 'ano') {
        $a = substr($ref, 0, 4); $ini = "$a-01-01"; $fim = "$a-12-31"; $rot = 'Ano de ' . $a;
    } else {
        if (!preg_match('/^\d{4}-\d{2}$/', $p)) $p = $ref;
        $ini = $p . '-01'; $fim = date('Y-m-t', strtotime($ini)); $rot = rotulo_mes($p);
    }
    $dias = (int)(new DateTime($ini))->diff(new DateTime($fim))->days + 1;
    $antFim = date('Y-m-d', strtotime($ini . ' -1 day'));
    $antIni = preg_match('/^\d{4}-\d{2}$/', $p) ? date('Y-m-01', strtotime($ini . ' -1 month')) : date('Y-m-d', strtotime($antFim . ' -' . ($dias - 1) . ' days'));
    return ['p' => $p, 'ini' => $ini, 'fim' => $fim, 'rot' => $rot, 'ant_ini' => $antIni, 'ant_fim' => $antFim, 'ref' => $ref];
}
function vendas_meses_com_dados() {
    return db()->query('SELECT DISTINCT SUBSTR(data, 1, 7) m FROM vendas ORDER BY m DESC')->fetchAll(PDO::FETCH_COLUMN);
}
// Seletor de período usado nas telas de vendas.
function vendas_seletor_periodo($per, $extras = []) {
    $h = '<select name="p" style="width:auto" onchange="this.form.ini&&(this.form.ini.value=\'\',this.form.fim.value=\'\');this.form.submit()">';
    foreach (vendas_meses_com_dados() ?: [date('Y-m')] as $m) $h .= '<option value="' . $m . '"' . ($per['p'] === $m ? ' selected' : '') . '>' . e(rotulo_mes($m)) . '</option>';
    foreach (['u3' => 'Últimos 3 meses', 'u12' => 'Últimos 12 meses', 'ano' => 'Ano atual dos dados'] as $k => $v) $h .= '<option value="' . $k . '"' . ($per['p'] === $k ? ' selected' : '') . '>' . $v . '</option>';
    if ($per['p'] === 'livre') $h .= '<option value="livre" selected>De ' . data_br($per['ini']) . ' a ' . data_br($per['fim']) . '</option>';
    $h .= '</select>';
    $h .= '<input type="date" name="ini" value="' . ($per['p'] === 'livre' ? e($per['ini']) : '') . '" style="width:auto" title="De"><input type="date" name="fim" value="' . ($per['p'] === 'livre' ? e($per['fim']) : '') . '" style="width:auto" title="Até">';
    foreach ($extras as $k => $v) if ($v !== '' && $v !== null) $h .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
    return $h;
}
// Resumo de compras de um cliente (ficha do CRM).
function vendas_resumo_cliente($clienteId) {
    if (!vendas_v9()) return null;
    $s = db()->prepare('SELECT COUNT(*) n, COALESCE(SUM(valor_total), 0) total, MIN(data) primeira, MAX(data) ultima FROM vendas WHERE cliente_id = ? AND valor_total > 0');
    $s->execute([(int)$clienteId]); $r = $s->fetch();
    $s = db()->prepare('SELECT id, documento, data, valor_total, valor_bonificacao, itens_qtd, vendedor FROM vendas WHERE cliente_id = ? ORDER BY data DESC LIMIT 10');
    $s->execute([(int)$clienteId]); $r['ultimas'] = $s->fetchAll();
    $s = db()->prepare('SELECT COALESCE(p.descricao, i.descricao) produto, SUM(i.quantidade) qtd, SUM(i.liquido) valor FROM venda_itens i JOIN vendas v ON v.id = i.venda_id
        LEFT JOIN produtos p ON p.id = i.produto_id WHERE v.cliente_id = ? AND i.bonificacao = 0 GROUP BY COALESCE(p.descricao, i.descricao) ORDER BY valor DESC LIMIT 5');
    $s->execute([(int)$clienteId]); $r['produtos'] = $s->fetchAll();
    $s = db()->prepare('SELECT COALESCE(SUM(valor_bonificacao), 0) FROM vendas WHERE cliente_id = ?'); $s->execute([(int)$clienteId]); $r['bonif'] = (float)$s->fetchColumn();
    return $r;
}

// Barras horizontais com valor (moeda ou número), % do total e link opcional.
function painel_barras($itens, $moeda = false, $base = null) {
    $max = 0.0; foreach ($itens as $i) $max = max($max, (float)$i[1]);
    $max = $max ?: 1;
    $base = $base ?? array_sum(array_map(fn($i) => (float)$i[1], $itens));
    $h = '<div class="barras">';
    foreach ($itens as $i) {
        [$rot, $n, $href, $extra] = $i + [2 => null, 3 => ''];
        $pc = $base ? round($n * 100 / $base) : 0;
        $val = $moeda ? brl($n) : number_format((float)$n, 0, ',', '.');
        $tag = $href ? 'a href="' . e($href) . '"' : 'div';
        $h .= '<' . $tag . ' class="barra' . ($moeda ? ' moeda' : '') . '" title="' . e($rot . ': ' . $val . ' (' . $pc . '%)' . ($extra ? ' · ' . $extra : '')) . '"><span class="rot">' . e($rot) . '</span>'
            . '<span class="trilho"><span class="preench" style="width:' . max($n > 0 ? 1.5 : 0, $n * 100 / $max) . '%"></span></span>'
            . '<span class="val">' . $val . ' <small>' . $pc . '%</small></span></' . ($href ? 'a' : 'div') . '>';
    }
    return $h . ($itens ? '' : '<p class="sub">Sem dados neste período.</p>') . '</div>';
}
function variacao($atual, $anterior) {
    if (!$anterior) return '';
    $d = ($atual - $anterior) * 100 / $anterior;
    return '<span class="var ' . ($d >= 0 ? 'sobe' : 'desce') . '">' . ($d >= 0 ? '▲ ' : '▼ ') . number_format(abs($d), 0, ',', '.') . '%</span>';
}
