<?php
define('INICIO_PAGINA', microtime(true));
require __DIR__ . '/config.php';
define('APP_NOME', 'Gestão Vinícola Brasília');
define('APP_VERSAO', '8.1');
define('DIR_COMPROVANTES', dirname(__DIR__) . '/uploads/comprovantes');
date_default_timezone_set('America/Sao_Paulo');

session_name('gestao_vb');
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();

function db() {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}
function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function csrf_token() {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_campo() { return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'; }
function csrf_check() {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(400); exit('Sessão expirada. Volte e tente novamente.');
    }
}
function flash($msg = null, $tipo = 'ok') {
    if ($msg !== null) { $_SESSION['flash'] = [$msg, $tipo]; return null; }
    $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $f;
}
function ir($url) { header('Location: ' . $url); exit; }

// ---------- Controle de acesso ----------
// O perfil é relido do banco a cada página: bloqueio ou troca de perfil vale na hora.
function exigir_login() {
    if (empty($_SESSION['uid'])) ir('index.php');
    $st = db()->prepare('SELECT id, nome, email, perfil, status FROM usuarios WHERE id = ?');
    $st->execute([(int)$_SESSION['uid']]);
    $u = $st->fetch();
    if (!$u || $u['status'] !== 'ativo' || !$u['perfil']) {
        $_SESSION = []; session_destroy(); ir('index.php');
    }
    $GLOBALS['USUARIO'] = $u;
}
// Um usuário pode acumular perfis: a coluna guarda a lista separada por vírgula.
function lista_perfis() {
    return ['admin' => 'Administrador',
            'cadastrador' => 'Tour · Cadastrador', 'consulta_tour' => 'Tour · Consulta',
            'marketing' => 'CRM · Marketing', 'consulta_crm' => 'CRM · Consulta',
            'cadastrador_cd' => 'CD · Cadastrador', 'motorista_cd' => 'CD · Motorista', 'consulta_cd' => 'CD · Consulta'];
}
function perfil() { return (string)($GLOBALS['USUARIO']['perfil'] ?? ''); }
// O antigo perfil "Consulta" valia para Tour e CD: continua valendo como os dois perfis de consulta.
function expandir_perfis($lista) {
    $p = array_values(array_filter(explode(',', (string)$lista)));
    if (in_array('consulta', $p, true)) $p = array_values(array_unique(array_merge(array_diff($p, ['consulta']), ['consulta_tour', 'consulta_cd'])));
    return $p;
}
function perfis() { return expandir_perfis(perfil()); }
function tem_perfil(...$ps) { return (bool)array_intersect($ps, perfis()); }
function pode_ver_tours() { return tem_perfil('admin', 'cadastrador', 'consulta_tour'); }
function pode_ver_cd() { return tem_perfil('admin', 'consulta_cd', 'cadastrador_cd', 'motorista_cd'); }
function pode_ver_cd_gestao() { return tem_perfil('admin', 'consulta_cd', 'cadastrador_cd'); }
function pode_cd_editar() { return tem_perfil('admin', 'cadastrador_cd'); }
function exigir_tours() { if (!pode_ver_tours()) negar(); }
function exigir_cd() { if (!pode_ver_cd()) negar(); }
function exigir_cd_edicao() { if (!pode_cd_editar()) negar(); }
function eh_admin() { return tem_perfil('admin'); }
function pode_editar() { return tem_perfil('admin', 'cadastrador'); }
// Página inicial de cada perfil (evita mandar alguém para uma tela que ele também não pode ver).
function pagina_inicial() {
    if (pode_ver_tours()) return 'painel.php';
    if (pode_ver_cd()) return 'cd_painel.php';
    if (function_exists('pode_ver_crm') && pode_ver_crm()) return 'crm_painel.php';
    return 'sair.php';
}
function negar() { flash('Seu perfil não permite esta ação.', 'erro'); ir(pagina_inicial()); }
function exigir_edicao() { if (!pode_editar()) negar(); }
function exigir_admin() { if (!eh_admin()) negar(); }
function nome_perfil($p) {
    $n = lista_perfis(); $r = [];
    foreach (expandir_perfis($p) as $x) $r[] = $n[$x] ?? $x;
    return $r ? implode(' + ', $r) : '—';
}

// ---------- Formatação ----------
function brl($v) { return 'R$ ' . number_format((float)$v, 2, ',', '.'); }
function data_br($d) { return $d ? date('d/m/Y', strtotime($d)) : ''; }
function dia_semana($d) {
    $n = ['Domingo','Segunda-feira','Terça-feira','Quarta-feira','Quinta-feira','Sexta-feira','Sábado'];
    return $n[(int)date('w', strtotime($d))];
}
function parse_valor($s) {
    $s = trim((string)$s);
    if (strpos($s, ',') !== false) $s = str_replace(',', '.', str_replace('.', '', $s));
    return round((float)$s, 2);
}
function vazio_null($s) { $s = trim((string)$s); return $s === '' ? null : $s; }
function nome_funcao($f) {
    $n = ['guia' => 'Guia', 'sommelier' => 'Sommelier', 'ambos' => 'Guia e Sommelier'];
    return $n[$f] ?? '';
}
function resumo_periodo($ini, $fim) {
    $st = db()->prepare("SELECT COUNT(*) vendas, COALESCE(SUM(qtd),0) pessoas,
        COALESCE(SUM(valor_pago),0) total,
        COALESCE(SUM(CASE WHEN recebido=1 THEN valor_pago ELSE 0 END),0) recebido,
        COALESCE(SUM(CASE WHEN recebido=0 THEN valor_pago ELSE 0 END),0) pendente,
        COALESCE(SUM(CASE WHEN modalidade='pix' THEN valor_pago ELSE 0 END),0) pix,
        COALESCE(SUM(CASE WHEN modalidade='link' THEN valor_pago ELSE 0 END),0) link
        FROM tour_vendas WHERE data_tour BETWEEN ? AND ?");
    $st->execute([$ini, $fim]);
    return $st->fetch();
}

// ---------- Comprovante de pagamento ----------
// Retorna o nome do arquivo salvo, null se nada foi enviado; lança exceção se inválido.
function salvar_comprovante($arq) {
    if (empty($arq) || ($arq['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if ($arq['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Falha no envio do comprovante.');
    if ($arq['size'] > 5 * 1024 * 1024) throw new RuntimeException('Comprovante maior que 5 MB.');
    $tipos = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($arq['tmp_name']);
    if (!isset($tipos[$mime])) throw new RuntimeException('Comprovante deve ser PDF, JPG, PNG ou WEBP.');
    if (!is_dir(DIR_COMPROVANTES)) mkdir(DIR_COMPROVANTES, 0755, true);
    $nome = date('Ymd') . '_' . bin2hex(random_bytes(8)) . '.' . $tipos[$mime];
    if (!move_uploaded_file($arq['tmp_name'], DIR_COMPROVANTES . '/' . $nome)) {
        throw new RuntimeException('Não foi possível gravar o comprovante.');
    }
    return $nome;
}
function apagar_comprovante($nome) {
    if ($nome) { $c = DIR_COMPROVANTES . '/' . basename($nome); if (is_file($c)) unlink($c); }
}

// ---------- E-mail de confirmação do tour ----------
function enviar_email_tour($vendaId) {
    if (!MAIL_ATIVO) return false;
    $st = db()->prepare("SELECT v.*, c.nome, c.email, g.nome guia, g.funcao FROM tour_vendas v
        JOIN clientes c ON c.id = v.cliente_id LEFT JOIN guias g ON g.id = v.guia_id WHERE v.id = ?");
    $st->execute([(int)$vendaId]);
    $v = $st->fetch();
    if (!$v || !filter_var((string)$v['email'], FILTER_VALIDATE_EMAIL)) return false;

    $linhas = '<li><strong>Data:</strong> ' . data_br($v['data_tour']) . ' (' . dia_semana($v['data_tour']) . ')</li>'
            . '<li><strong>Horário:</strong> ' . e(substr($v['horario'], 0, 5)) . 'h</li>'
            . '<li><strong>Pessoas:</strong> ' . (int)$v['qtd'] . '</li>';
    if ($v['guia']) $linhas .= '<li><strong>' . e(nome_funcao($v['funcao'])) . ':</strong> ' . e($v['guia']) . '</li>';
    $corpo = '<div style="font-family:Arial,sans-serif;font-size:15px;color:#2a2326;max-width:560px">'
           . '<h2 style="color:#5c1a2b;font-family:Georgia,serif">Seu tour está confirmado!</h2>'
           . '<p>Olá, ' . e($v['nome']) . '!</p>'
           . '<p>Parabéns pela escolha! Sua reserva para o tour na Vinícola Brasília está confirmada.</p>'
           . '<ul>' . $linhas . '</ul>'
           . '<p>Estamos ansiosos para receber você. Se precisar de algo, é só responder este e-mail.</p>'
           . '<p>Um brinde,<br><strong>' . e(MAIL_NOME) . '</strong></p></div>';

    $de = mb_encode_mimeheader(MAIL_NOME, 'UTF-8', 'B') . ' <' . MAIL_DE . '>';
    $cab = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: $de\r\nReply-To: " . MAIL_DE;
    $assunto = mb_encode_mimeheader('Seu tour na Vinícola Brasília está confirmado', 'UTF-8', 'B');
    $ok = @mail($v['email'], $assunto, $corpo, $cab, '-f' . MAIL_DE);
    if ($ok) db()->prepare('UPDATE tour_vendas SET email_enviado_em = NOW() WHERE id = ?')->execute([(int)$vendaId]);
    return $ok;
}

// ---------- Presença / estorno ----------
function nome_presenca($p) {
    $n = ['pendente' => 'A confirmar', 'presente' => 'Presente', 'ausente' => 'Ausente'];
    return $n[$p] ?? $p;
}
function nome_estorno($x) {
    $n = ['nenhum' => 'Sem estorno', 'solicitado' => 'Estorno solicitado', 'realizado' => 'Estorno realizado', 'negado' => 'Estorno negado'];
    return $n[$x] ?? $x;
}
// Depois de registrada a presença (presente ou ausente), só o Administrador altera o tour.
function pode_alterar_tour($v) {
    return eh_admin() || (pode_editar() && ($v['presenca'] ?? 'pendente') === 'pendente');
}

// ---------- Trilha de auditoria ----------
function linha($tabela, $id) {
    if (!in_array($tabela, ['clientes', 'guias', 'tour_vendas', 'usuarios', 'cd_vinicolas', 'cd_motoristas', 'cd_entregas', 'produtos', 'vendas'], true)) return null;
    $s = db()->prepare("SELECT * FROM $tabela WHERE id = ?");
    $s->execute([(int)$id]);
    return $s->fetch() ?: null;
}
function registrar_log($entidade, $id, $operacao, $detalhes = '', $quem = null) {
    $u = $quem ?: ($GLOBALS['USUARIO'] ?? null);
    db()->prepare('INSERT INTO auditoria (usuario_id, usuario_nome, entidade, entidade_id, operacao, detalhes) VALUES (?,?,?,?,?,?)')
        ->execute([$u['id'] ?? null, $u['nome'] ?? '—', $entidade, (int)$id, $operacao, mb_substr((string)$detalhes, 0, 2000)]);
}
function valor_legivel($campo, $v) {
    if (($campo === 'aceita_whatsapp' || $campo === 'aceita_email') && ($v === null || $v === '')) return 'Não perguntado';
    if ($v === null || $v === '') return 'vazio';
    if ($campo === 'vinicola_id' || $campo === 'motorista_id' || $campo === 'usuario_id') {
        $t = ['vinicola_id' => 'cd_vinicolas', 'motorista_id' => 'cd_motoristas', 'usuario_id' => 'usuarios'];
        $r = linha($t[$campo], $v);
        return $r ? $r['nome'] : '#' . $v;
    }
    if ($campo === 'data_entrega' || $campo === 'ultima_interacao') return data_br($v);
    if ($campo === 'origem') return nome_origem($v);
    if ($campo === 'cliente_desde') return data_br($v);
    if ($campo === 'categoria_id') return nome_categoria($v);
    if ($campo === 'categoria_auto') return $v ? 'Sim' : 'Não';
    if ($campo === 'sexo') return nome_sexo($v);
    if ($campo === 'tipo' && function_exists('tipos_produto') && isset(tipos_produto()[$v])) return nome_tipo_produto($v);
    if ($campo === 'tipo_pessoa') return $v === 'PJ' ? 'Pessoa jurídica' : 'Pessoa física';
    if ($campo === 'aceita_whatsapp' || $campo === 'aceita_email') return nome_aceite($v);
    if ($campo === 'sexo_presumido') return $v ? 'Sim' : 'Não';
    if ($campo === 'entregue_em') return date('d/m/Y H:i', strtotime($v));
    if ($campo === 'valor') return brl($v);
    if ($campo === 'cliente_id' || $campo === 'guia_id') {
        $r = linha($campo === 'cliente_id' ? 'clientes' : 'guias', $v);
        return $r ? $r['nome'] : '#' . $v;
    }
    if (in_array($campo, ['recebido', 'ativo', 'clube_beneficios'], true)) return $v ? 'Sim' : 'Não';
    if (in_array($campo, ['data_tour', 'data_recebimento', 'data_nascimento'], true)) return data_br($v);
    if ($campo === 'valor_pago') return brl($v);
    if ($campo === 'horario') return substr($v, 0, 5);
    if ($campo === 'presenca') return nome_presenca($v);
    if ($campo === 'estorno') return nome_estorno($v);
    if ($campo === 'perfil') return nome_perfil($v);
    if ($campo === 'funcao') return nome_funcao($v);
    if ($campo === 'modalidade') return $v === 'pix' ? 'Pix' : 'Link';
    return (string)$v;
}
function diff_log($antes, $depois) {
    $rot = ['cliente_id' => 'Cliente', 'guia_id' => 'Guia', 'data_tour' => 'Data do tour', 'horario' => 'Horário', 'qtd' => 'Qtd',
        'valor_pago' => 'Valor', 'modalidade' => 'Modalidade', 'recebido' => 'Recebido', 'data_recebimento' => 'Data do recebimento',
        'comentario' => 'Comentário', 'comprovante' => 'Comprovante', 'presenca' => 'Presença', 'estorno' => 'Estorno',
        'nome' => 'Nome', 'cpf' => 'CPF', 'email' => 'E-mail', 'telefone' => 'Telefone', 'data_nascimento' => 'Nascimento',
        'cidade' => 'Cidade', 'uf' => 'UF', 'clube_beneficios' => 'Clube', 'observacoes' => 'Observações',
        'funcao' => 'Função', 'vinicola_id' => 'Vinícola', 'motorista_id' => 'Motorista', 'usuario_id' => 'Usuário do sistema',
        'data_entrega' => 'Data da entrega', 'descricao' => 'Descrição', 'nfe_numero' => 'Número NFe', 'valor' => 'Valor', 'responsavel' => 'Responsável',
        'cliente_nome' => 'Cliente', 'cliente_email' => 'E-mail do cliente', 'cliente_telefone' => 'Telefone do cliente', 'endereco' => 'Endereço',
        'entregue_em' => 'Entregue em', 'obs_motorista' => 'Observação do motorista',
        'rg' => 'RG', 'cep' => 'CEP', 'origem' => 'Origem', 'interesses' => 'Interesses', 'ultima_interacao' => 'Última interação',
        'categoria_id' => 'Categoria', 'categoria_auto' => 'Categoria automática', 'tipo_pessoa' => 'Tipo', 'cnpj' => 'CNPJ', 'ie' => 'IE', 'im' => 'IM', 'sexo' => 'Sexo', 'sexo_presumido' => 'Sexo presumido', 'telefone2' => 'Outro telefone',
        'bairro' => 'Bairro', 'vendedor' => 'Vendedor', 'cliente_desde' => 'Cliente desde', 'aceita_whatsapp' => 'Aceita WhatsApp', 'aceita_email' => 'Aceita e-mail', 'ativo' => 'Ativo', 'perfil' => 'Perfil', 'status' => 'Situação', 'tipo' => 'Tipo'];
    $ignorar = ['id', 'criado_em', 'criado_por', 'senha_hash', 'email_enviado_em', 'presenca_em', 'presenca_por', 'entregue_por', 'telefone_e164', 'pendencias', 'importacao_id', 'atualizado_em', 'consentimento_em', 'consentimento_origem'];
    $m = [];
    foreach ($depois as $c => $novo) {
        if (in_array($c, $ignorar, true) || (string)($antes[$c] ?? '') === (string)$novo) continue;
        $m[] = ($rot[$c] ?? $c) . ': ' . valor_legivel($c, $antes[$c] ?? null) . ' → ' . valor_legivel($c, $novo);
    }
    return implode('; ', $m);
}
// Compara o registro de antes com o atual e grava o que mudou.
function registrar_alteracao($entidade, $tabela, $id, $antes, $operacao = 'Alteração') {
    $d = diff_log($antes ?: [], linha($tabela, $id) ?: []);
    if ($d !== '') registrar_log($entidade, $id, $operacao, $d);
}
function mostrar_trilha($entidade, $id) {
    $s = db()->prepare('SELECT * FROM auditoria WHERE entidade = ? AND entidade_id = ? ORDER BY id DESC LIMIT 200');
    $s->execute([$entidade, (int)$id]);
    echo '<h2>Trilha de auditoria</h2><div class="rolagem trilha"><table><thead><tr><th>Data e hora</th><th>Quem fez</th><th>Operação</th><th>Detalhes</th></tr></thead><tbody>';
    $tem = false;
    foreach ($s as $l) {
        $tem = true;
        echo '<tr><td>' . date('d/m/Y H:i', strtotime($l['criado_em'])) . '</td><td>' . e($l['usuario_nome']) . '</td><td>' . e($l['operacao']) . '</td><td class="quebra">' . e($l['detalhes']) . '</td></tr>';
    }
    if (!$tem) echo '<tr><td colspan="4" class="vazio">Sem registros (o histórico começa na versão 3).</td></tr>';
    echo '</tbody></table></div>';
}

// ---------- E-mail genérico ----------
function enviar_email($para, $assunto, $corpoHtml) {
    if (!MAIL_ATIVO || !filter_var((string)$para, FILTER_VALIDATE_EMAIL)) return false;
    $de = mb_encode_mimeheader(MAIL_NOME, 'UTF-8', 'B') . ' <' . MAIL_DE . '>';
    $cab = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: $de\r\nReply-To: " . MAIL_DE;
    $html = '<div style="font-family:Arial,sans-serif;font-size:15px;color:#2a2326;max-width:640px">' . $corpoHtml
          . '<p>Atenciosamente,<br><strong>' . e(MAIL_NOME) . '</strong></p></div>';
    return @mail($para, mb_encode_mimeheader($assunto, 'UTF-8', 'B'), $html, $cab, '-f' . MAIL_DE);
}

// ---------- Centro de Distribuição (CD) ----------
define('CD_VALORES', [0, 20]); // opções do valor do serviço de entrega
// A coluna nfe_numero entra na migração v5; enquanto ela não existir, o sistema segue funcionando sem o campo.
function cd_tem_nfe() {
    static $tem = null;
    if ($tem === null) {
        try { $tem = (bool)db()->query("SHOW COLUMNS FROM cd_entregas LIKE 'nfe_numero'")->fetch(); }
        catch (Throwable $ex) { $tem = false; }
    }
    return $tem;
}
function nome_status_entrega($s) {
    $n = ['agendada' => 'Agendada', 'entregue' => 'Entregue', 'cancelada' => 'Cancelada'];
    return $n[$s] ?? $s;
}
// Cadastro de motorista vinculado ao usuário logado (0 se não houver).
function meu_motorista_id() {
    static $id = null;
    if ($id === null) {
        $s = db()->prepare('SELECT id FROM cd_motoristas WHERE usuario_id = ? LIMIT 1');
        $s->execute([(int)$GLOBALS['USUARIO']['id']]);
        $id = (int)$s->fetchColumn();
    }
    return $id;
}
// Quem é só Motorista CD enxerga apenas as entregas dele e as sem motorista definido.
function cd_so_meus() { return !tem_perfil('admin', 'cadastrador_cd', 'consulta_cd'); }
function cd_entrega_visivel($en) {
    return !cd_so_meus() || !$en['motorista_id'] || (int)$en['motorista_id'] === meu_motorista_id();
}
// Depois de entregue, só o Administrador altera.
function pode_alterar_entrega($en) {
    return eh_admin() || (pode_cd_editar() && $en['status'] !== 'entregue');
}
function pode_confirmar_entrega($en) {
    if ($en['status'] !== 'agendada') return false;
    if (eh_admin()) return true;
    return tem_perfil('motorista_cd') && (!$en['motorista_id'] || (int)$en['motorista_id'] === meu_motorista_id());
}
function entrega_completa($id) {
    $s = db()->prepare("SELECT en.*, v.nome vinicola, v.responsavel, v.email vinicola_email, m.nome motorista
        FROM cd_entregas en JOIN cd_vinicolas v ON v.id = en.vinicola_id
        LEFT JOIN cd_motoristas m ON m.id = en.motorista_id WHERE en.id = ?");
    $s->execute([(int)$id]);
    return $s->fetch() ?: null;
}
// Avisa o cliente e o responsável da vinícola. Retorna a lista de quem recebeu.
function enviar_emails_entrega($id) {
    $en = entrega_completa($id);
    if (!$en || $en['status'] !== 'entregue') return [];
    $quando = date('d/m/Y \à\s H:i', strtotime($en['entregue_em']));
    $itens = '<li><strong>Vinícola:</strong> ' . e($en['vinicola']) . '</li>'
           . '<li><strong>Descrição:</strong> ' . e($en['descricao']) . '</li>'
           . (!empty($en['nfe_numero']) ? '<li><strong>NFe:</strong> ' . e($en['nfe_numero']) . '</li>' : '')
           . '<li><strong>Entregue em:</strong> ' . $quando . '</li>'
           . ($en['motorista'] ? '<li><strong>Motorista:</strong> ' . e($en['motorista']) . '</li>' : '')
           . ($en['obs_motorista'] ? '<li><strong>Observação:</strong> ' . e($en['obs_motorista']) . '</li>' : '');
    $ok = [];
    if (enviar_email($en['cliente_email'], 'Sua entrega foi realizada',
        '<h2 style="color:#5c1a2b;font-family:Georgia,serif">Entrega realizada</h2><p>Olá, ' . e($en['cliente_nome'] ?: 'cliente') . '!</p>'
        . '<p>Sua compra foi entregue pelo Centro de Distribuição da Vinícola Brasília.</p><ul>' . $itens . '</ul>')) $ok[] = 'cliente';
    if (enviar_email($en['vinicola_email'], 'Entrega realizada - ' . ($en['cliente_nome'] ?: $en['descricao']),
        '<h2 style="color:#5c1a2b;font-family:Georgia,serif">Entrega realizada</h2><p>Olá, ' . e($en['responsavel'] ?: $en['vinicola']) . '!</p>'
        . '<p>Uma entrega da sua vinícola foi concluída.</p><ul>'
        . ($en['cliente_nome'] ? '<li><strong>Cliente:</strong> ' . e($en['cliente_nome']) . '</li>' : '') . $itens
        . '<li><strong>Valor do serviço:</strong> ' . brl($en['valor']) . '</li></ul>')) $ok[] = 'vinícola';
    return $ok;
}
// Extrato: entregas concluídas (status entregue) cuja data/hora de entrega cai no mês.
function extrato_cd($vinicolaId, $mes) {
    $ini = $mes . '-01 00:00:00';
    $fim = date('Y-m-t', strtotime($ini)) . ' 23:59:59';
    $s = db()->prepare("SELECT en.*, m.nome motorista FROM cd_entregas en LEFT JOIN cd_motoristas m ON m.id = en.motorista_id
        WHERE en.vinicola_id = ? AND en.status = 'entregue' AND en.entregue_em BETWEEN ? AND ? ORDER BY en.entregue_em");
    $s->execute([(int)$vinicolaId, $ini, $fim]);
    $linhas = $s->fetchAll();
    return ['linhas' => $linhas, 'qtd' => count($linhas), 'total' => array_sum(array_column($linhas, 'valor'))];
}
function rotulo_mes($mes) {
    $n = [1 => 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
    return $n[(int)substr($mes, 5, 2)] . ' de ' . substr($mes, 0, 4);
}
function enviar_extrato_cd($vinicolaId, $mes, $quem = null) {
    $v = linha('cd_vinicolas', $vinicolaId);
    if (!$v) return false;
    $x = extrato_cd($vinicolaId, $mes);
    $td = 'style="padding:6px 8px;border-bottom:1px solid #e2dbd3;text-align:left"';
    $tab = '<table style="border-collapse:collapse;width:100%;font-size:14px"><tr><th ' . $td . '>Entregue em</th><th ' . $td . '>Descrição</th><th ' . $td . '>NFe</th><th ' . $td . '>Cliente</th><th ' . $td . '>Valor</th></tr>';
    foreach ($x['linhas'] as $l) {
        $tab .= '<tr><td ' . $td . '>' . date('d/m/Y H:i', strtotime($l['entregue_em'])) . '</td><td ' . $td . '>' . e($l['descricao']) . '</td><td ' . $td . '>' . e($l['nfe_numero'] ?? '') . '</td><td ' . $td . '>' . e($l['cliente_nome']) . '</td><td ' . $td . '>' . brl($l['valor']) . '</td></tr>';
    }
    $tab .= '<tr><td ' . $td . ' colspan="4"><strong>Total a pagar (' . $x['qtd'] . ' entrega(s))</strong></td><td ' . $td . '><strong>' . brl($x['total']) . '</strong></td></tr></table>';
    $ok = enviar_email($v['email'], 'Extrato de entregas - ' . rotulo_mes($mes),
        '<h2 style="color:#5c1a2b;font-family:Georgia,serif">Extrato de entregas · ' . e(rotulo_mes($mes)) . '</h2><p>Olá, ' . e($v['responsavel'] ?: $v['nome']) . '!</p>'
        . '<p>Segue o extrato das entregas realizadas pelo Centro de Distribuição da Vinícola Brasília para a ' . e($v['nome']) . '.</p>' . $tab);
    if ($ok) {
        db()->prepare('REPLACE INTO cd_extratos (vinicola_id, mes, qtd, total, enviado_em) VALUES (?,?,?,?,NOW())')
            ->execute([(int)$vinicolaId, $mes, $x['qtd'], $x['total']]);
        registrar_log('vinicola', $vinicolaId, 'Extrato enviado', rotulo_mes($mes) . ': ' . $x['qtd'] . ' entrega(s), ' . brl($x['total']) . ' para ' . $v['email'], $quem);
    }
    return $ok;
}

require_once __DIR__ . '/crm.php';
