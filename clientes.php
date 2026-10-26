<?php
require __DIR__ . '/inc/app.php';
exigir_login();
exigir_crm();
crm_exigir_migracao();
grupos_recalcular_se_velho();
$pdo = db();
$ed_ok = pode_editar_clientes();

// ---------- Filtros (os mesmos na lista, na exportação e nas ações em lote) ----------
$fonte = $_SERVER['REQUEST_METHOD'] === 'POST' ? (array)($_POST['f'] ?? []) : $_GET;
$flt = [
    'q' => trim((string)($fonte['q'] ?? '')),
    'tipo' => in_array($fonte['tipo'] ?? '', ['PF', 'PJ'], true) ? $fonte['tipo'] : '',
    'sexo' => in_array($fonte['sexo'] ?? '', ['F', 'M', 'nao', 'presumido'], true) ? $fonte['sexo'] : '',
    'origem' => isset(lista_origens()[$fonte['origem'] ?? '']) ? $fonte['origem'] : '',
    'clube' => in_array($fonte['clube'] ?? '', ['1', '0'], true) ? $fonte['clube'] : '',
    'uf' => preg_match('/^[A-Z]{2}$/', $fonte['uf'] ?? '') ? $fonte['uf'] : '',
    'etiqueta' => (int)($fonte['etiqueta'] ?? 0) ?: '',
    'grupo' => (int)($fonte['grupo'] ?? 0) ?: '',
    'aniv' => ((int)($fonte['aniv'] ?? 0) >= 1 && (int)($fonte['aniv'] ?? 0) <= 12) ? (int)$fonte['aniv'] : '',
    'pend' => ($fonte['pend'] ?? '') === '1' ? '1' : '',
    'lote' => (int)($fonte['lote'] ?? 0) ?: '',
    'categoria' => ($fonte['categoria'] ?? '') === 'sem' ? 'sem' : ((int)($fonte['categoria'] ?? 0) ?: ''),
    'cat_auto' => ($fonte['cat_auto'] ?? '') === '1' ? '1' : '',
    'idade' => isset(faixas_etarias()[$fonte['idade'] ?? '']) ? $fonte['idade'] : '',
    'cidade' => mb_substr(trim((string)($fonte['cidade'] ?? '')), 0, 100),
    'bairro' => mb_substr(trim((string)($fonte['bairro'] ?? '')), 0, 100),
];
function filtro_sql($flt) {
    $w = ['1 = 1']; $p = [];
    if ($flt['q'] !== '') {
        $c = ['c.nome LIKE ?', 'c.email LIKE ?', 'c.cpf LIKE ?', 'c.cnpj LIKE ?', 'c.rg LIKE ?', 'c.telefone LIKE ?', 'c.telefone2 LIKE ?'];
        for ($i = 0; $i < 7; $i++) $p[] = "%{$flt['q']}%";
        $d = so_digitos($flt['q']);
        if (strlen($d) >= 4) {
            $c[] = 'c.telefone_e164 LIKE ?'; $p[] = "%$d%";
            $c[] = "REPLACE(REPLACE(c.cpf, '.', ''), '-', '') LIKE ?"; $p[] = "%$d%";
            $c[] = "REPLACE(REPLACE(REPLACE(c.cnpj, '.', ''), '-', ''), '/', '') LIKE ?"; $p[] = "%$d%";
        }
        $w[] = '(' . implode(' OR ', $c) . ')';
    }
    if ($flt['tipo'] !== '') { $w[] = 'c.tipo_pessoa = ?'; $p[] = $flt['tipo']; }
    if ($flt['sexo'] === 'F' || $flt['sexo'] === 'M') { $w[] = 'c.sexo = ?'; $p[] = $flt['sexo']; }
    if ($flt['sexo'] === 'nao') $w[] = "c.sexo IS NULL AND c.tipo_pessoa = 'PF'";
    if ($flt['sexo'] === 'presumido') $w[] = 'c.sexo_presumido = 1';
    if ($flt['origem'] !== '') { $w[] = 'c.origem = ?'; $p[] = $flt['origem']; }
    if ($flt['clube'] !== '') { $w[] = 'c.clube_beneficios = ?'; $p[] = (int)$flt['clube']; }
    if ($flt['uf'] !== '') { $w[] = 'c.uf = ?'; $p[] = $flt['uf']; }
    if ($flt['etiqueta'] !== '') { $w[] = 'EXISTS (SELECT 1 FROM crm_cliente_etiqueta ce WHERE ce.cliente_id = c.id AND ce.etiqueta_id = ?)'; $p[] = (int)$flt['etiqueta']; }
    if ($flt['grupo'] !== '') { $w[] = "EXISTS (SELECT 1 FROM crm_grupo_membros gm WHERE gm.cliente_id = c.id AND gm.grupo_id = ? AND gm.origem <> 'excluido')"; $p[] = (int)$flt['grupo']; }
    if ($flt['aniv'] !== '') { $w[] = 'SUBSTR(c.data_nascimento, 6, 2) = ?'; $p[] = sprintf('%02d', $flt['aniv']); }
    if ($flt['pend'] !== '') $w[] = "c.pendencias IS NOT NULL AND c.pendencias <> ''";
    if ($flt['lote'] !== '') { $w[] = 'c.importacao_id = ?'; $p[] = (int)$flt['lote']; }
    if ($flt['categoria'] === 'sem') $w[] = 'c.categoria_id IS NULL';
    elseif ($flt['categoria'] !== '') { $w[] = 'c.categoria_id = ?'; $p[] = (int)$flt['categoria']; }
    if ($flt['cat_auto'] !== '') $w[] = 'c.categoria_auto = 1';
    if ($flt['idade'] !== '') {
        [$min, $max] = faixas_etarias()[$flt['idade']]['anos'];
        if ($min === null) $w[] = "c.data_nascimento IS NULL AND c.tipo_pessoa = 'PF'";
        else { $w[] = 'c.data_nascimento <= ? AND c.data_nascimento > ?'; $p[] = date('Y-m-d', strtotime("-$min years")); $p[] = date('Y-m-d', strtotime('-' . ($max + 1) . ' years')); $w[] = "c.tipo_pessoa = 'PF'"; }
    }
    if ($flt['cidade'] !== '') { $w[] = 'c.cidade = ?'; $p[] = $flt['cidade']; }
    if ($flt['bairro'] !== '') { $w[] = 'c.bairro = ?'; $p[] = $flt['bairro']; }
    return [implode(' AND ', $w), $p];
}
function filtro_qs($flt, $extra = []) { return http_build_query(array_filter(array_merge($flt, $extra), fn($v) => $v !== '' && $v !== null)); }
function filtro_hidden($flt) { $h = ''; foreach ($flt as $k => $v) if ($v !== '') $h .= '<input type="hidden" name="f[' . e($k) . ']" value="' . e($v) . '">'; return $h; }
[$where, $par] = filtro_sql($flt);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $acao = $_POST['acao'] ?? 'salvar';
    $id = (int)($_POST['id'] ?? 0);
    $antes = $id ? linha('clientes', $id) : null;
    $volta = 'clientes.php' . (filtro_qs($flt) ? '?' . filtro_qs($flt) : '');

    if ($acao === 'excluir') {
        exigir_admin();
        $s = $pdo->prepare('SELECT COUNT(*) FROM tour_vendas WHERE cliente_id = ?'); $s->execute([$id]);
        if ($s->fetchColumn()) { flash('Não é possível excluir: o cliente tem tours registrados.', 'erro'); ir($volta); }
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM crm_cliente_etiqueta WHERE cliente_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM crm_grupo_membros WHERE cliente_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM clientes WHERE id = ?')->execute([$id]);
        registrar_log('cliente', $id, 'Exclusão', ($antes['nome'] ?? '') . (!empty($antes['cpf']) ? ' · CPF ' . $antes['cpf'] : '') . (!empty($antes['cnpj']) ? ' · CNPJ ' . $antes['cnpj'] : ''));
        $pdo->commit();
        flash('Cliente excluído.');
        ir($volta);
    }
    if (!$ed_ok) negar();

    if ($acao === 'categoria_filtro') {
        $cat = (int)($_POST['categoria_id'] ?? 0);
        if (!$cat) { flash('Escolha a categoria.', 'erro'); ir($volta); }
        $s = $pdo->prepare("SELECT c.id FROM clientes c WHERE $where"); $s->execute($par); $ids = array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
        foreach (array_chunk($ids, 400) as $lote) $pdo->exec('UPDATE clientes SET categoria_id = ' . $cat . ', categoria_auto = 0 WHERE id IN (' . implode(',', $lote) . ')');
        registrar_log('cliente', 0, 'Categoria em lote', count($ids) . ' cliente(s) → ' . nome_categoria($cat) . '; filtro: ' . (filtro_qs($flt) ?: 'todos'));
        grupos_recalcular_todos();
        flash(count($ids) . ' cliente(s) agora na categoria ' . nome_categoria($cat) . '.');
        ir($volta);
    }
    if ($acao === 'etiquetar_filtro' || $acao === 'grupo_filtro') {
        $s = $pdo->prepare("SELECT c.id FROM clientes c WHERE $where"); $s->execute($par);
        $ids = $s->fetchAll(PDO::FETCH_COLUMN);
        if ($acao === 'etiquetar_filtro') {
            $eid = (int)($_POST['etiqueta_id'] ?? 0) ?: etiqueta_id($_POST['nova_etiqueta'] ?? '');
            if (!$eid) { flash('Escolha ou digite uma etiqueta.', 'erro'); ir($volta); }
            $n = 0; foreach ($ids as $cid) $n += etiquetar((int)$cid, $eid) ? 1 : 0;
            $nomeE = $pdo->query('SELECT nome FROM crm_etiquetas WHERE id = ' . (int)$eid)->fetchColumn();
            registrar_log('cliente', 0, 'Etiqueta em lote', 'Etiqueta "' . $nomeE . '" aplicada a ' . $n . ' cliente(s); filtro: ' . (filtro_qs($flt) ?: 'todos'));
            grupos_recalcular_todos();
            flash('Etiqueta "' . $nomeE . '" aplicada a ' . $n . ' cliente(s).');
        } else {
            $gid = (int)($_POST['grupo_id'] ?? 0);
            $g = $gid ? $pdo->query('SELECT * FROM crm_grupos WHERE id = ' . $gid)->fetch() : null;
            if (!$g) { flash('Escolha um grupo.', 'erro'); ir($volta); }
            $n = 0; foreach ($ids as $cid) $n += grupos_incluir_manual($gid, (int)$cid) ? 1 : 0;
            registrar_log('grupo', $gid, 'Inclusão em lote', $n . ' cliente(s) incluídos à mão; filtro: ' . (filtro_qs($flt) ?: 'todos'));
            flash($n . ' cliente(s) incluídos no grupo ' . $g['nome'] . '.');
        }
        ir($volta);
    }

    // ---------- Salvar a ficha ----------
    $erro = function ($m) use ($id) { flash($m, 'erro'); ir('clientes.php?' . ($id ? 'editar=' . $id : 'novo=1')); };
    $tipo = ($_POST['tipo_pessoa'] ?? 'PF') === 'PJ' ? 'PJ' : 'PF';
    $nome = texto_limpo($_POST['nome'] ?? '');
    if ($nome === '') $erro('Informe o nome' . ($tipo === 'PJ' ? ' (razão social).' : '.'));
    $cpf = $cnpj = null;
    if ($tipo === 'PF') {
        $d = so_digitos($_POST['cpf'] ?? '');
        if ($d !== '' && strlen($d) !== 11) $erro('O CPF deve ter 11 dígitos. Documento de outro tipo vai no campo RG.');
        $cpf = $d !== '' ? formatar_cpf($d) : null;
        if ($cpf && cpf_valido($cpf)) {
            $s = $pdo->prepare("SELECT id, nome FROM clientes WHERE id <> ? AND REPLACE(REPLACE(cpf, '.', ''), '-', '') = ?"); $s->execute([$id, $d]);
            if ($o = $s->fetch()) $erro('Este CPF já está no cadastro de ' . $o['nome'] . ' (código ' . $o['id'] . '). Abra aquele cadastro em vez de criar outro.');
        }
    } else {
        $d = so_digitos($_POST['cnpj'] ?? '');
        if ($d !== '' && strlen($d) !== 14) $erro('O CNPJ deve ter 14 dígitos.');
        $cnpj = $d !== '' ? formatar_cnpj($d) : null;
        if ($cnpj) {
            $s = $pdo->prepare("SELECT id, nome FROM clientes WHERE id <> ? AND REPLACE(REPLACE(REPLACE(cnpj, '.', ''), '-', ''), '/', '') = ?"); $s->execute([$id, $d]);
            if ($o = $s->fetch()) $erro('Este CNPJ já está no cadastro de ' . $o['nome'] . ' (código ' . $o['id'] . ').');
        }
    }
    [$tel, $e164] = normalizar_telefone($_POST['telefone'] ?? '');
    [$tel2] = normalizar_telefone($_POST['telefone2'] ?? '');
    $email = mb_strtolower(trim((string)($_POST['email'] ?? '')), 'UTF-8');
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $erro('E-mail inválido.');
    [$nasc, $pd] = normalizar_data($_POST['data_nascimento'] ?? '');
    if ($pd) $erro('Data de nascimento inválida.');
    $cepD = so_digitos($_POST['cep'] ?? '');
    if ($cepD !== '' && strlen($cepD) !== 8) $erro('O CEP deve ter 8 dígitos.');
    $uf = strtoupper(substr(trim((string)($_POST['uf'] ?? '')), 0, 2));
    if (!preg_match('/^[A-Z]{2}$/', $uf)) $uf = uf_por_cep($cepD) ?: null;
    [$ult] = normalizar_data($_POST['ultima_interacao'] ?? '');
    [$desde] = normalizar_data($_POST['cliente_desde'] ?? '');
    // Sexo: escolhido na ficha vale como confirmado; sem escolha, o sistema presume pelo nome e avisa.
    $sexo = null; $presumido = 0; $avisoSexo = '';
    if ($tipo === 'PF') {
        $sx = $_POST['sexo'] ?? '';
        if ($sx === 'F' || $sx === 'M') {
            $sexo = $sx;
            $presumido = ($antes && $antes['sexo'] === $sx && (int)$antes['sexo_presumido'] === 1 && !isset($_POST['sexo_confirmado'])) ? 1 : 0;
        } elseif ($sx === '' && ($sexo = sexo_por_nome($nome))) {
            $presumido = 1; $avisoSexo = ' Sexo presumido pelo nome: ' . nome_sexo($sexo) . ' (confira na ficha).';
        }
    }
    // Categoria: escolhida na ficha vale como confirmada; em branco, o sistema sugere e marca como automática.
    $cat = (int)($_POST['categoria_id'] ?? 0) ?: null; $catAuto = 0; $avisoCat = '';
    if ($cat && $antes && (int)$antes['categoria_id'] === $cat && (int)$antes['categoria_auto'] === 1 && !isset($_POST['categoria_confirmada'])) $catAuto = 1;
    if (!$cat) {
        $tq = $id ? (int)$pdo->query('SELECT COUNT(*) FROM tour_vendas WHERE cliente_id = ' . $id)->fetchColumn() : 0;
        if ($cat = categoria_sugerida(['nome' => $nome, 'tipo_pessoa' => $tipo, 'origem' => $_POST['origem'] ?? ''], $tq)) { $catAuto = 1; $avisoCat = ' Categoria sugerida: ' . nome_categoria($cat) . ' (confira).'; }
    }
    $aceite = fn($k) => in_array($_POST[$k] ?? '', ['1', '0'], true) ? (int)$_POST[$k] : null;
    $c = [
        'nome' => mb_substr($nome, 0, 160), 'tipo_pessoa' => $tipo, 'categoria_id' => $cat, 'categoria_auto' => $catAuto, 'cpf' => $cpf, 'rg' => $tipo === 'PF' ? vazio_null(mb_substr(texto_limpo($_POST['rg'] ?? ''), 0, 30)) : null,
        'cnpj' => $cnpj, 'ie' => $tipo === 'PJ' ? vazio_null(mb_substr(texto_limpo($_POST['ie'] ?? ''), 0, 30)) : null, 'im' => $tipo === 'PJ' ? vazio_null(mb_substr(texto_limpo($_POST['im'] ?? ''), 0, 30)) : null,
        'sexo' => $sexo, 'sexo_presumido' => $presumido,
        'email' => $email !== '' ? mb_substr($email, 0, 160) : null, 'telefone' => $tel ? mb_substr($tel, 0, 30) : null, 'telefone_e164' => $e164, 'telefone2' => $tel2 ? mb_substr($tel2, 0, 30) : null,
        'data_nascimento' => $nasc, 'endereco' => vazio_null(mb_substr(texto_limpo($_POST['endereco'] ?? ''), 0, 255)), 'bairro' => vazio_null(mb_substr(texto_limpo($_POST['bairro'] ?? ''), 0, 100)),
        'cep' => formatar_cep($cepD), 'cidade' => vazio_null(mb_substr(texto_limpo($_POST['cidade'] ?? ''), 0, 100)), 'uf' => $uf,
        'clube_beneficios' => isset($_POST['clube_beneficios']) ? 1 : 0,
        'origem' => isset(lista_origens()[$_POST['origem'] ?? '']) ? $_POST['origem'] : null,
        'interesses' => vazio_null(mb_substr(texto_limpo($_POST['interesses'] ?? ''), 0, 500)),
        'vendedor' => vazio_null(mb_substr(texto_limpo($_POST['vendedor'] ?? ''), 0, 120)), 'cliente_desde' => $desde,
        'ultima_interacao' => $ult, 'observacoes' => vazio_null(trim((string)($_POST['observacoes'] ?? ''))),
        'aceita_whatsapp' => $aceite('aceita_whatsapp'), 'aceita_email' => $aceite('aceita_email'),
    ];
    // Registro do consentimento: quando e por quem mudou.
    $mudouAceite = $antes ? ((string)$antes['aceita_whatsapp'] !== (string)$c['aceita_whatsapp'] || (string)$antes['aceita_email'] !== (string)$c['aceita_email'])
                          : ($c['aceita_whatsapp'] !== null || $c['aceita_email'] !== null);
    if ($mudouAceite) { $c['consentimento_em'] = date('Y-m-d H:i:s'); $c['consentimento_origem'] = mb_substr('Ficha · ' . $GLOBALS['USUARIO']['nome'], 0, 60); }
    $pend = pendencias_cliente($c);
    $c['pendencias'] = $pend ? implode("\n", $pend) : null;
    if ($id) {
        $pdo->prepare('UPDATE clientes SET ' . implode(' = ?, ', array_keys($c)) . ' = ? WHERE id = ?')->execute(array_merge(array_values($c), [$id]));
        registrar_alteracao('cliente', 'clientes', $id, $antes);
    } else {
        $pdo->prepare('INSERT INTO clientes (' . implode(', ', array_keys($c)) . ') VALUES (' . implode(',', array_fill(0, count($c), '?')) . ')')->execute(array_values($c));
        $id = (int)$pdo->lastInsertId();
        registrar_log('cliente', $id, 'Inclusão', $nome);
    }
    // Etiquetas
    $antesE = etiquetas_do_cliente($id);
    $quer = array_map('intval', (array)($_POST['etq'] ?? []));
    foreach (preg_split('/[,;]/', (string)($_POST['nova_etiqueta'] ?? '')) as $ne) if ($x = etiqueta_id($ne)) $quer[] = $x;
    $quer = array_unique(array_filter($quer));
    $pdo->prepare('DELETE FROM crm_cliente_etiqueta WHERE cliente_id = ?')->execute([$id]);
    foreach ($quer as $eid) etiquetar($id, $eid);
    $depoisE = etiquetas_do_cliente($id);
    if (array_values($antesE) !== array_values($depoisE)) registrar_log('cliente', $id, 'Etiquetas', (implode(', ', $antesE) ?: 'nenhuma') . ' → ' . (implode(', ', $depoisE) ?: 'nenhuma'));
    // Grupos automáticos: entra e sai na hora.
    [$entrou, $saiu] = grupos_recalcular_cliente($id);
    // Avisos de possível duplicidade (não bloqueiam: famílias costumam dividir telefone ou e-mail).
    $av = [];
    if ($e164) { $s = $pdo->prepare('SELECT nome FROM clientes WHERE id <> ? AND telefone_e164 = ? LIMIT 3'); $s->execute([$id, $e164]); if ($n = $s->fetchAll(PDO::FETCH_COLUMN)) $av[] = 'mesmo telefone de ' . implode(', ', $n); }
    if ($email !== '') { $s = $pdo->prepare('SELECT nome FROM clientes WHERE id <> ? AND email = ? LIMIT 3'); $s->execute([$id, $email]); if ($n = $s->fetchAll(PDO::FETCH_COLUMN)) $av[] = 'mesmo e-mail de ' . implode(', ', $n); }
    flash('Cliente salvo.' . ($entrou ? ' Entrou nos grupos: ' . implode(', ', $entrou) . '.' : '') . ($saiu ? ' Saiu dos grupos: ' . implode(', ', $saiu) . '.' : '')
        . $avisoSexo . $avisoCat . ($av ? ' Atenção: ' . implode('; ', $av) . '.' : '') . ($pend ? ' Ficou com pendência: ' . implode('; ', $pend) . '.' : ''));
    ir('clientes.php?editar=' . $id);
}

// ---------- Exportação (só Administrador) ----------
if (isset($_GET['exportar'])) {
    exigir_admin();
    $s = $pdo->prepare("SELECT c.* FROM clientes c WHERE $where ORDER BY c.nome"); $s->execute($par);
    $rows = $s->fetchAll();
    registrar_log('cliente', 0, 'Exportação de clientes', count($rows) . ' cliente(s); filtro: ' . (filtro_qs($flt) ?: 'todos'));
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="clientes-' . date('Y-m-d') . '.csv"');
    $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF");
    fputcsv($o, ['Código', 'Tipo', 'Categoria', 'Nome', 'CPF', 'RG', 'CNPJ', 'IE', 'Sexo', 'Nascimento', 'Celular', 'WhatsApp', 'Telefone 2', 'E-mail', 'Endereço', 'Bairro', 'CEP', 'Cidade', 'UF',
        'Clube', 'Origem', 'Interesses', 'Vendedor', 'Cliente desde', 'Última interação', 'Aceita WhatsApp', 'Aceita e-mail', 'Observações', 'Pendências'], ';', '"', '');
    foreach ($rows as $r) fputcsv($o, [$r['id'], $r['tipo_pessoa'], $r['categoria_id'] ? nome_categoria($r['categoria_id']) . ($r['categoria_auto'] ? ' (automática)' : '') : '', $r['nome'], $r['cpf'], $r['rg'], $r['cnpj'], $r['ie'], $r['sexo'] ? nome_sexo($r['sexo']) . ($r['sexo_presumido'] ? ' (presumido)' : '') : '',
        data_br($r['data_nascimento']), $r['telefone'], $r['telefone_e164'], $r['telefone2'], $r['email'], $r['endereco'], $r['bairro'], $r['cep'], $r['cidade'], $r['uf'],
        $r['clube_beneficios'] ? 'Sim' : 'Não', nome_origem($r['origem']), $r['interesses'], $r['vendedor'], data_br($r['cliente_desde']), data_br($r['ultima_interacao']),
        nome_aceite($r['aceita_whatsapp']), nome_aceite($r['aceita_email']), $r['observacoes'], str_replace("\n", ' | ', (string)$r['pendencias'])], ';', '"', '');
    exit;
}

// ---------- Ficha ----------
$ed = array_fill_keys(['nome', 'cpf', 'rg', 'cnpj', 'ie', 'im', 'sexo', 'email', 'telefone', 'telefone2', 'data_nascimento', 'endereco', 'bairro', 'cep', 'cidade', 'uf', 'origem',
    'interesses', 'vendedor', 'cliente_desde', 'ultima_interacao', 'observacoes', 'pendencias', 'aceita_whatsapp', 'aceita_email', 'consentimento_em', 'consentimento_origem'], '')
    + ['id' => 0, 'tipo_pessoa' => 'PF', 'categoria_id' => null, 'categoria_auto' => 0, 'sexo_presumido' => 0, 'clube_beneficios' => 0, 'importacao_id' => null];
if (!empty($_GET['editar'])) $ed = linha('clientes', (int)$_GET['editar']) ?: $ed;
$abrir = $ed['id'] || isset($_GET['novo']);
$etqTodas = etiquetas_todas();
$gruposTodos = grupos_todos(true);
$etqCli = $ed['id'] ? etiquetas_do_cliente($ed['id']) : [];
$gruposCli = $ed['id'] ? grupos_do_cliente($ed['id']) : [];
$resumo = null;
if ($ed['id']) {
    $s = $pdo->prepare('SELECT COUNT(*) n, MIN(data_tour) primeiro, MAX(data_tour) ultimo, COALESCE(SUM(valor_pago), 0) total FROM tour_vendas WHERE cliente_id = ?');
    $s->execute([$ed['id']]); $resumo = $s->fetch();
}

// ---------- Lista ----------
$porPag = 50;
$s = $pdo->prepare("SELECT COUNT(*) FROM clientes c WHERE $where"); $s->execute($par); $total = (int)$s->fetchColumn();
$pag = max(1, min((int)($_GET['p'] ?? 1), max(1, (int)ceil($total / $porPag))));
$s = $pdo->prepare("SELECT c.*, (SELECT COUNT(*) FROM tour_vendas v WHERE v.cliente_id = c.id) tours FROM clientes c WHERE $where ORDER BY c.nome LIMIT $porPag OFFSET " . (($pag - 1) * $porPag));
$s->execute($par); $lista = $s->fetchAll();
$etqLista = [];
if ($lista) {
    $ids = implode(',', array_map(fn($r) => (int)$r['id'], $lista));
    foreach ($pdo->query("SELECT ce.cliente_id, e.nome FROM crm_cliente_etiqueta ce JOIN crm_etiquetas e ON e.id = ce.etiqueta_id WHERE ce.cliente_id IN ($ids) ORDER BY e.nome") as $r) $etqLista[$r['cliente_id']][] = $r['nome'];
}
$ufs = $pdo->query("SELECT DISTINCT uf FROM clientes WHERE uf IS NOT NULL AND uf <> '' ORDER BY uf")->fetchAll(PDO::FETCH_COLUMN);
$nPend = (int)$pdo->query("SELECT COUNT(*) FROM clientes WHERE pendencias IS NOT NULL AND pendencias <> ''")->fetchColumn();
$meses = [1 => 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
$titulo = 'Clientes';
require __DIR__ . '/inc/topo.php';
?>
<?php if ($abrir): $pj = $ed['tipo_pessoa'] === 'PJ'; ?>
<div class="cartao">
  <div class="ficha-topo">
    <strong><?= $ed['id'] ? e($ed['nome']) : 'Novo cliente' ?></strong>
    <?php if ($ed['id']): ?><span class="sub">código <?= (int)$ed['id'] ?> · origem: <?= e(nome_origem($ed['origem'])) ?><?= $ed['importacao_id'] ? ' · importação #' . (int)$ed['importacao_id'] : '' ?></span><?php endif; ?>
    <a class="btn peq sec" href="clientes.php?<?= e(filtro_qs($flt)) ?>">Fechar</a>
  </div>
  <?php if (!empty($ed['pendencias'])): ?>
    <div class="aviso erro"><strong>Pendências do cadastro:</strong><br><?= nl2br(e($ed['pendencias'])) ?></div>
  <?php endif; ?>
  <?php if ($resumo && $resumo['n']): ?>
    <p class="sub"><?= (int)$resumo['n'] ?> tour(s) · primeiro em <?= data_br($resumo['primeiro']) ?> · último em <?= data_br($resumo['ultimo']) ?> · total <?= brl($resumo['total']) ?></p>
  <?php endif; ?>
  <form method="post" id="ficha"><fieldset class="grade" <?= $ed_ok ? '' : 'disabled' ?>>
    <?= csrf_campo() ?>
    <input type="hidden" name="id" value="<?= (int)$ed['id'] ?>">
    <label>Tipo
      <select name="tipo_pessoa" id="tp">
        <option value="PF" <?= $pj ? '' : 'selected' ?>>Pessoa física</option>
        <option value="PJ" <?= $pj ? 'selected' : '' ?>>Pessoa jurídica (empresa)</option>
      </select>
    </label>
    <label class="l2"><span><span class="so-pf">Nome*</span><span class="so-pj">Razão social / nome*</span></span><input name="nome" required maxlength="160" value="<?= e($ed['nome']) ?>"></label>
    <label>Categoria<?= $ed['categoria_auto'] ? ' <span class="tag pend">automática</span>' : '' ?>
      <select name="categoria_id">
        <option value="">— sugerir automaticamente —</option>
        <?php foreach (categorias_todas() as $k): if (!$k['ativo'] && (int)$k['id'] !== (int)$ed['categoria_id']) continue; ?><option value="<?= (int)$k['id'] ?>" <?= (int)$ed['categoria_id'] === (int)$k['id'] ? 'selected' : '' ?>><?= e($k['nome']) ?></option><?php endforeach; ?>
      </select>
    </label>
    <?php if ($ed['categoria_auto']): ?><label class="check"><input type="checkbox" name="categoria_confirmada"> Confirmo a categoria</label><?php endif; ?>
    <label class="so-pf">CPF<input name="cpf" maxlength="14" inputmode="numeric" value="<?= e($ed['cpf']) ?>"></label>
    <label class="so-pf">RG / outro documento<input name="rg" maxlength="30" value="<?= e($ed['rg']) ?>"></label>
    <label class="so-pf">Sexo<?= $ed['sexo_presumido'] ? ' <span class="tag pend">presumido pelo nome</span>' : '' ?>
      <select name="sexo">
        <option value="">Não informado</option>
        <option value="F" <?= $ed['sexo'] === 'F' ? 'selected' : '' ?>>Feminino</option>
        <option value="M" <?= $ed['sexo'] === 'M' ? 'selected' : '' ?>>Masculino</option>
      </select>
    </label>
    <?php if ($ed['sexo_presumido']): ?><label class="check so-pf"><input type="checkbox" name="sexo_confirmado"> Confirmo o sexo</label><?php endif; ?>
    <label class="so-pj">CNPJ<input name="cnpj" maxlength="18" inputmode="numeric" value="<?= e($ed['cnpj']) ?>"></label>
    <label class="so-pj">Inscrição estadual<input name="ie" maxlength="30" value="<?= e($ed['ie']) ?>"></label>
    <label class="so-pj">Inscrição municipal<input name="im" maxlength="30" value="<?= e($ed['im']) ?>"></label>
    <label><span><span class="so-pf">Nascimento</span><span class="so-pj">Data de fundação</span></span><input type="date" name="data_nascimento" value="<?= e($ed['data_nascimento']) ?>"></label>
    <label>Celular / WhatsApp<input name="telefone" placeholder="(61) 99999-0000" value="<?= e($ed['telefone']) ?>"></label>
    <label>Outro telefone<input name="telefone2" value="<?= e($ed['telefone2']) ?>"></label>
    <label class="l2">E-mail<input type="email" name="email" value="<?= e($ed['email']) ?>"></label>
    <label class="l2">Endereço<input name="endereco" maxlength="255" value="<?= e($ed['endereco']) ?>"></label>
    <label>Bairro<input name="bairro" maxlength="100" value="<?= e($ed['bairro']) ?>"></label>
    <label>CEP<input name="cep" maxlength="9" inputmode="numeric" value="<?= e($ed['cep']) ?>"></label>
    <label class="l2">Cidade<input name="cidade" maxlength="100" value="<?= e($ed['cidade']) ?>"></label>
    <label>UF<input name="uf" maxlength="2" value="<?= e($ed['uf']) ?>"></label>
    <label>Origem
      <select name="origem">
        <option value="">—</option>
        <?php foreach (lista_origens() as $k => $v): ?><option value="<?= $k ?>" <?= $ed['origem'] === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
      </select>
    </label>
    <label class="l2">Interesses<input name="interesses" maxlength="500" placeholder="ex.: espumantes, lançamentos, jantares" value="<?= e($ed['interesses']) ?>"></label>
    <label>Vendedor responsável<input name="vendedor" maxlength="120" value="<?= e($ed['vendedor']) ?>"></label>
    <label>Cliente desde<input type="date" name="cliente_desde" value="<?= e($ed['cliente_desde']) ?>"></label>
    <label>Última interação<input type="date" name="ultima_interacao" value="<?= e($ed['ultima_interacao']) ?>"></label>
    <label class="check l2"><input type="checkbox" name="clube_beneficios" <?= $ed['clube_beneficios'] ? 'checked' : '' ?>> Membro do clube de benefícios</label>
    <div class="l4 consentimento">
      <span class="rot">Consentimento para marketing (LGPD)</span>
      <label>Aceita WhatsApp de marketing
        <select name="aceita_whatsapp"><?php foreach (['' => 'Não perguntado', '1' => 'Sim', '0' => 'Não'] as $k => $v): ?><option value="<?= $k ?>" <?= (string)$ed['aceita_whatsapp'] === (string)$k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select>
      </label>
      <label>Aceita e-mail de marketing
        <select name="aceita_email"><?php foreach (['' => 'Não perguntado', '1' => 'Sim', '0' => 'Não'] as $k => $v): ?><option value="<?= $k ?>" <?= (string)$ed['aceita_email'] === (string)$k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select>
      </label>
      <span class="sub"><?= $ed['consentimento_em'] ? 'Registrado em ' . date('d/m/Y H:i', strtotime($ed['consentimento_em'])) . ' · ' . e($ed['consentimento_origem']) : 'Pergunte ao cliente e registre aqui. Quem recusar sai das exportações de campanha.' ?></span>
    </div>
    <div class="l4 etiquetas">
      <span class="rot">Etiquetas</span>
      <?php foreach ($etqTodas as $et): ?>
        <label class="chip"><input type="checkbox" name="etq[]" value="<?= (int)$et['id'] ?>" <?= isset($etqCli[$et['id']]) ? 'checked' : '' ?>> <?= e($et['nome']) ?></label>
      <?php endforeach; ?>
      <input name="nova_etiqueta" class="nova" maxlength="120" placeholder="nova etiqueta (separe por vírgula)">
    </div>
    <label class="l4">Observações<textarea name="observacoes" rows="3"><?= e($ed['observacoes']) ?></textarea></label>
    <?php if ($ed_ok): ?><div class="l4 acoes"><button type="submit">Salvar</button></div><?php endif; ?>
  </fieldset></form>

  <?php $comp = $ed['id'] && pode_ver_vendas() ? vendas_resumo_cliente($ed['id']) : null; if ($comp && ($comp['n'] || $comp['ultimas'])): ?>
  <h2>Compras <small>vendas do ERP · <a href="vendas_lista.php?cliente=<?= (int)$ed['id'] ?>">ver todas</a></small></h2>
  <p class="sub"><?= (int)$comp['n'] ?> compra(s) · total <?= brl($comp['total']) ?><?= $comp['n'] ? ' · ticket médio ' . brl($comp['total'] / $comp['n']) . ' · primeira em ' . data_br($comp['primeira']) . ' · última em ' . data_br($comp['ultima']) : '' ?><?= $comp['bonif'] > 0 ? ' · recebeu ' . brl($comp['bonif']) . ' em bonificações/amostras' : '' ?></p>
  <?php if ($comp['produtos']): ?><p class="sub">Mais comprados: <?= e(implode(' · ', array_map(fn($x) => $x['produto'] . ' (' . number_format((float)$x['qtd'], 0, ',', '.') . ')', $comp['produtos']))) ?></p><?php endif; ?>
  <div class="cartao rolagem"><table>
    <thead><tr><th>Data</th><th>Documento</th><th>Vendedor</th><th class="num">Itens</th><th class="num">Valor</th></tr></thead>
    <tbody><?php foreach ($comp['ultimas'] as $cv): ?><tr><td><?= data_br($cv['data']) ?></td><td><a href="vendas_lista.php?ver=<?= (int)$cv['id'] ?>"><?= e($cv['documento']) ?></a></td><td><?= e($cv['vendedor']) ?></td><td class="num"><?= rtrim(rtrim(number_format((float)$cv['itens_qtd'], 3, ',', '.'), '0'), ',') ?></td><td class="num"><?= brl($cv['valor_total']) ?><?= $cv['valor_bonificacao'] > 0 ? ' <span class="tag pend">+ bonif. ' . brl($cv['valor_bonificacao']) . '</span>' : '' ?></td></tr><?php endforeach; ?></tbody>
  </table></div>
  <?php endif; ?>
  <?php if ($ed['id']): ?>
  <h2>Grupos de campanha</h2>
  <div class="grupos-cli">
    <?php $dentro = array_filter($gruposCli, fn($g) => $g['origem'] !== 'excluido'); ?>
    <?php foreach ($dentro as $gc): ?>
      <span class="chip-grupo"><a href="crm_grupos.php?ver=<?= (int)$gc['id'] ?>"><?= e($gc['nome']) ?></a> <span class="sub"><?= $gc['origem'] === 'regra' ? 'automático' : 'à mão' ?></span>
        <?php if ($ed_ok): ?><form method="post" action="crm_grupos.php"><?= csrf_campo() ?><input type="hidden" name="grupo_id" value="<?= (int)$gc['id'] ?>"><input type="hidden" name="cliente_id" value="<?= (int)$ed['id'] ?>"><input type="hidden" name="volta" value="clientes.php?editar=<?= (int)$ed['id'] ?>"><button class="x" name="acao" value="membro_remover" title="Tirar deste grupo" onclick="return confirm('Tirar do grupo <?= e(addslashes($gc['nome'])) ?>?')">×</button></form><?php endif; ?>
      </span>
    <?php endforeach; ?>
    <?php foreach ($gruposCli as $gc): if ($gc['origem'] !== 'excluido') continue; ?>
      <span class="chip-grupo fora"><?= e($gc['nome']) ?> <span class="sub">excluído à mão</span>
        <?php if ($ed_ok): ?><form method="post" action="crm_grupos.php"><?= csrf_campo() ?><input type="hidden" name="grupo_id" value="<?= (int)$gc['id'] ?>"><input type="hidden" name="cliente_id" value="<?= (int)$ed['id'] ?>"><input type="hidden" name="volta" value="clientes.php?editar=<?= (int)$ed['id'] ?>"><button class="peq sec" name="acao" value="membro_reincluir">Reincluir</button></form><?php endif; ?>
      </span>
    <?php endforeach; ?>
    <?php if (!$dentro): ?><span class="sub">Em nenhum grupo.</span><?php endif; ?>
    <?php if ($ed_ok): $idsDentro = array_column($dentro, 'id'); $fora = array_filter($gruposTodos, fn($g) => !in_array($g['id'], $idsDentro)); if ($fora): ?>
      <form method="post" action="crm_grupos.php" class="acoes"><?= csrf_campo() ?><input type="hidden" name="cliente_id" value="<?= (int)$ed['id'] ?>"><input type="hidden" name="volta" value="clientes.php?editar=<?= (int)$ed['id'] ?>">
        <select name="grupo_id"><?php foreach ($fora as $g): ?><option value="<?= (int)$g['id'] ?>"><?= e($g['nome']) ?></option><?php endforeach; ?></select>
        <button class="peq sec" name="acao" value="membro_incluir">Incluir no grupo</button>
      </form>
    <?php endif; endif; ?>
  </div>
  <?php mostrar_trilha('cliente', $ed['id']); ?>
  <?php endif; ?>
</div>
<script>
(function () {
  var tp = document.getElementById('tp'), f = document.getElementById('ficha');
  function vis() { var pj = tp.value === 'PJ'; f.querySelectorAll('.so-pf').forEach(function (x) { x.style.display = pj ? 'none' : ''; }); f.querySelectorAll('.so-pj').forEach(function (x) { x.style.display = pj ? '' : 'none'; }); }
  tp.addEventListener('change', vis); vis();
})();
</script>
<?php endif; ?>

<form method="get" class="filtro filtros-crm">
  <input name="q" placeholder="Nome, CPF, CNPJ, telefone ou e-mail" value="<?= e($flt['q']) ?>">
  <select name="tipo"><option value="">PF e PJ</option><option value="PF" <?= $flt['tipo'] === 'PF' ? 'selected' : '' ?>>Pessoa física</option><option value="PJ" <?= $flt['tipo'] === 'PJ' ? 'selected' : '' ?>>Empresas (PJ)</option></select>
  <select name="sexo"><option value="">Sexo: todos</option><option value="F" <?= $flt['sexo'] === 'F' ? 'selected' : '' ?>>Feminino</option><option value="M" <?= $flt['sexo'] === 'M' ? 'selected' : '' ?>>Masculino</option><option value="nao" <?= $flt['sexo'] === 'nao' ? 'selected' : '' ?>>Não informado (PF)</option><option value="presumido" <?= $flt['sexo'] === 'presumido' ? 'selected' : '' ?>>Presumido pelo nome (conferir)</option></select>
  <select name="categoria"><option value="">Toda categoria</option><?php foreach (categorias_todas() as $k): ?><option value="<?= (int)$k['id'] ?>" <?= (string)$flt['categoria'] === (string)$k['id'] ? 'selected' : '' ?>><?= e($k['nome']) ?></option><?php endforeach; ?><option value="sem" <?= $flt['categoria'] === 'sem' ? 'selected' : '' ?>>Sem categoria</option></select>
  <select name="grupo"><option value="">Todo grupo</option><?php foreach ($gruposTodos as $g): ?><option value="<?= (int)$g['id'] ?>" <?= (string)$flt['grupo'] === (string)$g['id'] ? 'selected' : '' ?>><?= e($g['nome']) ?></option><?php endforeach; ?></select>
  <select name="origem"><option value="">Toda origem</option><?php foreach (lista_origens() as $k => $v): ?><option value="<?= $k ?>" <?= $flt['origem'] === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select>
  <select name="clube"><option value="">Clube: todos</option><option value="1" <?= $flt['clube'] === '1' ? 'selected' : '' ?>>Membros do clube</option><option value="0" <?= $flt['clube'] === '0' ? 'selected' : '' ?>>Fora do clube</option></select>
  <select name="uf"><option value="">Toda UF</option><?php foreach ($ufs as $u): ?><option <?= $flt['uf'] === $u ? 'selected' : '' ?>><?= e($u) ?></option><?php endforeach; ?></select>
  <select name="etiqueta"><option value="">Toda etiqueta</option><?php foreach ($etqTodas as $et): ?><option value="<?= (int)$et['id'] ?>" <?= (string)$flt['etiqueta'] === (string)$et['id'] ? 'selected' : '' ?>><?= e($et['nome']) ?></option><?php endforeach; ?></select>
  <select name="aniv"><option value="">Aniversário: todos</option><?php foreach ($meses as $n => $m): ?><option value="<?= $n ?>" <?= (string)$flt['aniv'] === (string)$n ? 'selected' : '' ?>>Aniversariantes de <?= $m ?></option><?php endforeach; ?></select>
  <label class="check"><input type="checkbox" name="pend" value="1" <?= $flt['pend'] ? 'checked' : '' ?>> Com pendências (<?= $nPend ?>)</label>
  <label class="check"><input type="checkbox" name="cat_auto" value="1" <?= $flt['cat_auto'] ? 'checked' : '' ?>> Categoria automática (revisar)</label>
  <?php foreach (['lote', 'idade', 'cidade', 'bairro'] as $hk): if ($flt[$hk] !== ''): ?><input type="hidden" name="<?= $hk ?>" value="<?= e($flt[$hk]) ?>"><?php endif; endforeach; ?>
  <button type="submit">Filtrar</button>
  <a class="btn sec" href="clientes.php">Limpar</a>
</form>

<?php $extras = array_filter(['idade' => $flt['idade'] !== '' ? 'Idade: ' . faixas_etarias()[$flt['idade']]['rot'] : '', 'cidade' => $flt['cidade'] !== '' ? 'Cidade: ' . $flt['cidade'] : '', 'bairro' => $flt['bairro'] !== '' ? 'Bairro: ' . $flt['bairro'] : '']); if ($extras): ?>
<p class="filtros-extra"><?php foreach ($extras as $k => $rot): ?><a class="tag neutra" href="clientes.php?<?= e(filtro_qs(array_merge($flt, [$k => '']))) ?>" title="Tirar este filtro"><?= e($rot) ?> ×</a> <?php endforeach; ?></p>
<?php endif; ?>
<div class="barra-lista">
  <span><strong><?= number_format($total, 0, ',', '.') ?></strong> cliente(s)<?= $flt['lote'] ? ' da importação #' . (int)$flt['lote'] : '' ?></span>
  <?php if ($ed_ok): ?><a class="btn peq" href="clientes.php?novo=1">Novo cliente</a><?php endif; ?>
  <?php if (eh_admin()): ?><a class="btn peq sec" href="clientes.php?<?= e(filtro_qs($flt, ['exportar' => 1])) ?>">Exportar CSV</a><?php endif; ?>
</div>
<?php if ($ed_ok && $total): ?>
<div class="barra-lista">
  <form method="post" class="acoes" onsubmit="return confirm('Aplicar a etiqueta aos <?= $total ?> cliente(s) filtrados?')">
    <?= csrf_campo() ?><?= filtro_hidden($flt) ?>
    <select name="etiqueta_id"><option value="">etiquetar os filtrados…</option><?php foreach ($etqTodas as $et): ?><option value="<?= (int)$et['id'] ?>"><?= e($et['nome']) ?></option><?php endforeach; ?></select>
    <input name="nova_etiqueta" maxlength="60" placeholder="ou nova etiqueta">
    <button class="peq sec" name="acao" value="etiquetar_filtro">Aplicar</button>
  </form>
  <form method="post" class="acoes" onsubmit="return confirm('Definir a categoria dos <?= $total ?> cliente(s) filtrados?')">
    <?= csrf_campo() ?><?= filtro_hidden($flt) ?>
    <select name="categoria_id"><option value="">categoria dos filtrados…</option><?php foreach (categorias_todas(true) as $k): ?><option value="<?= (int)$k['id'] ?>"><?= e($k['nome']) ?></option><?php endforeach; ?></select>
    <button class="peq sec" name="acao" value="categoria_filtro">Definir</button>
  </form>
  <form method="post" class="acoes" onsubmit="return confirm('Incluir os <?= $total ?> cliente(s) filtrados no grupo escolhido?')">
    <?= csrf_campo() ?><?= filtro_hidden($flt) ?>
    <select name="grupo_id"><option value="">incluir os filtrados no grupo…</option><?php foreach ($gruposTodos as $g): ?><option value="<?= (int)$g['id'] ?>"><?= e($g['nome']) ?></option><?php endforeach; ?></select>
    <button class="peq sec" name="acao" value="grupo_filtro">Incluir</button>
  </form>
</div>
<?php endif; ?>

<div class="cartao rolagem">
<table>
  <thead><tr><th>Nome</th><th>Categoria</th><th>Celular</th><th>E-mail</th><th>Cidade/UF</th><th>Origem</th><th>Tours</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($lista as $c): ?>
    <tr>
      <td class="nome">
        <?= e($c['nome']) ?><?= $c['tipo_pessoa'] === 'PJ' ? ' <span class="tag neutra">PJ</span>' : '' ?><?php if (!empty($c['pendencias'])): ?> <span class="tag pend" title="<?= e($c['pendencias']) ?>">pendência</span><?php endif; ?>
        <?php foreach ($etqLista[$c['id']] ?? [] as $en): ?> <span class="tag neutra"><?= e($en) ?></span><?php endforeach; ?>
      </td>
      <td><?= $c['categoria_id'] ? e(nome_categoria($c['categoria_id'])) . ($c['categoria_auto'] ? ' <span class="sub" title="sugerida automaticamente">(auto)</span>' : '') : '—' ?></td>
      <td><?= e($c['telefone']) ?></td>
      <td><?= e($c['email']) ?></td>
      <td><?= e(trim(($c['cidade'] ?? '') . ($c['uf'] ? ' / ' . $c['uf'] : ''), ' /')) ?></td>
      <td><?= e(nome_origem($c['origem'])) ?><?= $c['clube_beneficios'] ? ' · clube' : '' ?></td>
      <td><?= (int)$c['tours'] ?></td>
      <td class="acoes">
        <a class="btn peq sec" href="clientes.php?<?= e(filtro_qs($flt, ['editar' => $c['id'], 'p' => $pag > 1 ? $pag : ''])) ?>"><?= $ed_ok ? 'Abrir' : 'Ver' ?></a>
        <?php if (eh_admin()): ?>
        <form method="post" onsubmit="return confirm('Excluir este cliente?')">
          <?= csrf_campo() ?><?= filtro_hidden($flt) ?><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
          <button class="peq perigo" name="acao" value="excluir">Excluir</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; if (!$lista): ?><tr><td colspan="8" class="vazio">Nenhum cliente encontrado.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<?php if ($total > $porPag): $nPags = (int)ceil($total / $porPag); ?>
<div class="paginas">
  <?php if ($pag > 1): ?><a class="btn peq sec" href="clientes.php?<?= e(filtro_qs($flt, ['p' => $pag - 1])) ?>">‹ Anterior</a><?php endif; ?>
  <span>Página <?= $pag ?> de <?= $nPags ?></span>
  <?php if ($pag < $nPags): ?><a class="btn peq sec" href="clientes.php?<?= e(filtro_qs($flt, ['p' => $pag + 1])) ?>">Próxima ›</a><?php endif; ?>
</div>
<?php endif; ?>
<?php require __DIR__ . '/inc/rodape.php'; ?>
