<?php
// ---------- CRM · cadastro de clientes: padronização, duplicidade e importação ----------

// As migrações v6 e v7 criam os campos do CRM; enquanto não rodam, as telas avisam em vez de quebrar.
function crm_v6() {
    static $ok = null;
    if ($ok === null) {
        try { db()->query('SELECT telefone_e164, importacao_id FROM clientes WHERE 1 = 0'); $ok = true; }
        catch (Throwable $ex) { $ok = false; }
    }
    return $ok;
}
function crm_v7() {
    static $ok = null;
    if ($ok === null) {
        try { db()->query('SELECT c.tipo_pessoa, c.sexo, c.aceita_whatsapp FROM clientes c WHERE 1 = 0'); db()->query('SELECT id FROM crm_grupos WHERE 1 = 0'); $ok = true; }
        catch (Throwable $ex) { $ok = false; }
    }
    return $ok;
}
function crm_v8() {
    static $ok = null;
    if ($ok === null) {
        try { db()->query('SELECT c.categoria_id, c.categoria_auto FROM clientes c WHERE 1 = 0'); db()->query('SELECT id FROM crm_categorias WHERE 1 = 0'); $ok = true; }
        catch (Throwable $ex) { $ok = false; }
    }
    return $ok;
}
function crm_exigir_migracao() {
    if (crm_v6() && crm_v7() && crm_v8()) return;
    $falta = array_keys(array_filter(['migracao_v6.sql' => !crm_v6(), 'migracao_v7.sql' => !crm_v7(), 'migracao_v8.sql' => !crm_v8()]));
    $arq = implode(', depois o ', $falta);
    $titulo = 'Clientes';
    require __DIR__ . '/topo.php';
    echo '<div class="aviso erro">' . (eh_admin()
        ? 'O cadastro de clientes foi atualizado e precisa da migração do banco. Rode no phpMyAdmin o arquivo ' . $arq . '.'
        : 'O cadastro de clientes está em atualização. Tente novamente mais tarde.') . '</div>';
    require __DIR__ . '/rodape.php';
    exit;
}
function pode_ver_crm() { return tem_perfil('admin', 'marketing', 'consulta_crm'); }
function pode_editar_clientes() { return tem_perfil('admin', 'marketing'); }
function pode_gerir_grupos() { return tem_perfil('admin', 'marketing'); }
function exigir_crm() { if (!pode_ver_crm()) negar(); }

function lista_origens() {
    return ['tour' => 'Tour', 'clube' => 'Clube de benefícios', 'loja' => 'Loja física', 'ecommerce' => 'E-commerce (site)', 'evento' => 'Evento',
            'indicacao' => 'Indicação', 'redes' => 'Redes sociais', 'cd' => 'Entrega do CD', 'erp' => 'ERP (vendas)', 'formulario' => 'Formulário',
            'planilha' => 'Planilha (carga)', 'outro' => 'Outro'];
}
function nome_origem($o) { return lista_origens()[$o] ?? ($o ?: '—'); }
function nome_sexo($s) { return ['F' => 'Feminino', 'M' => 'Masculino'][$s] ?? 'Não informado'; }
function nome_aceite($v) { return $v === null || $v === '' ? 'Não perguntado' : ((int)$v ? 'Sim' : 'Não'); }

function so_digitos($s) { return preg_replace('/\D/', '', (string)$s); }
function cpf_valido($c) {
    $c = so_digitos($c);
    if (strlen($c) !== 11 || preg_match('/^(\d)\1{10}$/', $c)) return false;
    for ($t = 9; $t < 11; $t++) {
        $s = 0;
        for ($i = 0; $i < $t; $i++) $s += (int)$c[$i] * ($t + 1 - $i);
        if ((($s * 10) % 11) % 10 !== (int)$c[$t]) return false;
    }
    return true;
}
function cnpj_valido($c) {
    $c = so_digitos($c);
    if (strlen($c) !== 14 || preg_match('/^(\d)\1{13}$/', $c)) return false;
    foreach ([12, 13] as $t) {
        $p = $t === 12 ? [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2] : [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $s = 0; for ($i = 0; $i < $t; $i++) $s += (int)$c[$i] * $p[$i];
        $d = $s % 11 < 2 ? 0 : 11 - $s % 11;
        if ($d !== (int)$c[$t]) return false;
    }
    return true;
}
function formatar_cpf($d) { return substr($d, 0, 3) . '.' . substr($d, 3, 3) . '.' . substr($d, 6, 3) . '-' . substr($d, 9, 2); }
function formatar_cnpj($d) { return substr($d, 0, 2) . '.' . substr($d, 2, 3) . '.' . substr($d, 5, 3) . '/' . substr($d, 8, 4) . '-' . substr($d, 12, 2); }

// Texto "limpo": tira espaços invisíveis e repetidos.
function texto_limpo($s) {
    $s = preg_replace('/[\x{00A0}\x{2000}-\x{200F}\x{2028}-\x{202F}\x{205F}-\x{2064}\x{FEFF}]/u', ' ', (string)$s);
    return trim(preg_replace('/\s+/u', ' ', $s));
}
// Nome todo em maiúsculas ou minúsculas vira "Nome Próprio" (de, da, do, dos, das, e ficam minúsculos).
function nome_proprio($nome) {
    $nome = trim(texto_limpo($nome), " .,;:-_*'\"");
    if ($nome === '' || ($nome !== mb_strtoupper($nome, 'UTF-8') && $nome !== mb_strtolower($nome, 'UTF-8'))) return $nome;
    $p = explode(' ', mb_convert_case(mb_strtolower($nome, 'UTF-8'), MB_CASE_TITLE, 'UTF-8'));
    foreach ($p as $i => $w) if ($i > 0 && in_array(mb_strtolower($w, 'UTF-8'), ['de', 'da', 'do', 'dos', 'das', 'e'], true)) $p[$i] = mb_strtolower($w, 'UTF-8');
    return implode(' ', $p);
}
// Chave de comparação de nomes: sem acento, minúsculo, só letras.
function chave_nome($nome) {
    $s = mb_strtolower(texto_limpo($nome), 'UTF-8');
    $s = strtr($s, ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a','é'=>'e','ê'=>'e','è'=>'e','ë'=>'e','í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
                    'ó'=>'o','ò'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c','ñ'=>'n']);
    return trim(preg_replace('/[^a-z]+/', ' ', $s));
}
function primeiro_nome($nome) { return explode(' ', chave_nome($nome) . ' ')[0]; }
function primeiro_ultimo($nome) { $p = explode(' ', chave_nome($nome)); return $p[0] . ' ' . end($p); }

// Sexo presumido pelo primeiro nome (lista de nomes comuns + terminações). Devolve 'F', 'M' ou null quando não dá para saber.
function sexo_por_nome($nome) {
    static $m = null, $f = null;
    if ($m === null) {
        $m = array_flip(explode(' ', 'jose luiz luis alexandre carlos rafael raphael felipe filipe phelipe andre guilherme gabriel marcos marcus matheus mateus lucas lukas daniel jorge henrique ivan christian cristian raul cesar cezar igor wesley vinicius vitor victor wilson jean alan allan douglas artur arthur tadeu anderson jayme jaime vilmar sandoval washington helder miguel robson wagner clemilson jonathan jonathas jonatas ruben rubens edson peterson walter erick eric keven kevin roque clever robert adair valdir cleyton welington wellington diogenes gilvan adilson domingos lucivam volnei newton edmond wilmar edilson david davi roberson wilker andrei efrem kleber dirceu thomas tomas ruan michael devanir elias xavier wilton celmar edinei odair normeu vanderson gilmar vanderlei wanderson ulisses itamar nelson ademir josias luidy welbert nathan weber willian william gleyson edison fabian lionel edmur edgar geovani giovani marconny joseval valner donnovan regis jocir anthony alex aleksander alexander wander randel charles hudson esli joaquim nikolas nicolas araken richarlison jair hirotake khauan kallel manoel manuel samuel ismael emanuel israel abel noel joel moises renan gean yuri iuri levi elvis denis dennis heitor nestor rui ruy luan bryan brian leonel moacir valmir ademar osmar gilberto norberto hamilton milton everton ailton nilton cleber felix max oscar caique kaique enrique vagner wanderley sidney sydney wendel rodney cleiton kleiton alisson ismar baltazar cristovam'));
        $f = array_flip(explode(' ', 'thais tais thaisi lais sarah raquel simone karen viviane vivian dayane cristiane christiane christianne beatriz danielle daniele danieli danielli aline alyne caroline jacqueline jaqueline marlene vandete eliene marli nicole michele michelle michelli solange lilian gisele giselle yasmim yasmin polyane rosane marcilene izabelle geni carmen juliane liane isabel izabel lidiane hulianne maryanne joseni elizabeth elisabeth adriene kelly ester esther emily emilly tatiane marilene deise ziraime eliane elaine deborah myllane anelore stephane stephanie stephany stephannie hanne judite gislaine laiciane hingrid ingrid janete marise miriam mirian myriam ediane arlete fabriciele fabricieli matilde franciane francielle paonne cristine mylene alcione kelen alene aliete deide jeniffer jennifer goreth daysy daisy smyley leonice diane mical loyanne anali elen helen rosimar ketuly gabrielli gabrieli gabrielly rafaelle clarisse clarice arolinne karine rejane mithielle araci cibelle cibele heather desire desiree jeizyane lanusse rutiele inez ines eunice catiane quele rhaine jeanne suzane jamile lydiane gracy luciane cleidi glauce evelize hebbyeh doraci gizelly leydiane waldelice marildete neci rosiney nayane julie anelise laise helayne stivilane eciniele nilvane cilene sylvie thamiris irani leoni eunimar juciane josanny ariadne geysilene monah anete janiny juliette lourdes edilene rosimeiry alice roseane raiane suellen suely dayse darlis marcelle irene ivone yvone cleide neide rosemary rosemeire rosimeire elisabete elizabete ivete odete bernadete claudete isabelle evelyn liz luz beth socorro conceicao ruth rute noemi abigail lis eliete denise edite edith adele adelaide joyce grace jane jeane'));
    }
    $p = primeiro_nome($nome);
    if ($p === '' || strlen($p) < 3) return null;
    if (isset($f[$p])) return 'F';
    if (isset($m[$p])) return 'M';
    if (in_array($p, ['luca', 'joshua', 'mustafa', 'jonata', 'jonatha', 'issa', 'nikita'], true)) return 'M';
    if (preg_match('/a$/', $p)) return 'F';
    if (preg_match('/o$/', $p)) return 'M';
    if (preg_match('/(iane|yane|eane|ane|ene|ine|lle|elle|ette|ete|ise|ice|eth|ah|elly|ely|ilda)$/', $p)) return 'F';
    if (preg_match('/(son|ton|iel|ael|uel|er|or|ur|ir|us|os|aldo|valdo)$/', $p)) return 'M';
    return null;
}

// Telefone: devolve [exibição, e164, problema]. Sem DDD, presume 61 (Brasília).
function normalizar_telefone($raw) {
    $raw = texto_limpo($raw);
    if ($raw === '') return [null, null, null];
    if (preg_match('/^\d(\.\d+)?E\+?\d+$/i', $raw)) $raw = sprintf('%.0f', (float)$raw);
    $d = so_digitos(preg_replace('/\.0+$/', '', $raw));
    $presumido = false;
    if (strlen($d) >= 12 && strpos($d, '55') === 0 && in_array(strlen($d), [12, 13], true)) $d = substr($d, 2);
    if (strlen($d) === 11 && $d[0] === '0') $d = substr($d, 1);
    if (strlen($d) === 12 && $d[0] === '0') $d = substr($d, 1);
    if (in_array(strlen($d), [8, 9], true)) { $d = '61' . $d; $presumido = true; }
    $ok = false;
    if (strlen($d) === 11 && $d[2] === '9' && $d[0] !== '0') { $ok = true; $fmt = '(' . substr($d, 0, 2) . ') ' . substr($d, 2, 5) . '-' . substr($d, 7); }
    elseif (strlen($d) === 10 && $d[0] !== '0' && $d[2] !== '0') { $ok = true; $fmt = '(' . substr($d, 0, 2) . ') ' . substr($d, 2, 4) . '-' . substr($d, 6); }
    if (!$ok) return [$raw, null, 'telefone fora do padrão: ' . $raw];
    return [$fmt, '+55' . $d, $presumido ? 'DDD 61 presumido para ' . $raw : null];
}
function e_celular($e164) { return (bool)preg_match('/^\+55\d{2}9\d{8}$/', (string)$e164); }

// Data: devolve [Y-m-d, problema]. Aceita dd/mm/aaaa (com espaços), aaaa-mm-dd e o número de série do Excel.
function normalizar_data($raw) {
    $s = texto_limpo($raw);
    if ($s === '') return [null, null];
    if (preg_match('/^\d{5}(\.\d+)?$/', $s) && (int)$s > 1 && (int)$s < 80000) $s = date('Y-m-d', strtotime('1899-12-30 +' . (int)$s . ' days'));
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m)) { $a = (int)$m[1]; $mes = (int)$m[2]; $dia = (int)$m[3]; }
    elseif (preg_match('/^(\d{1,2})\s*[\/.\-]\s*(\d{1,2})\s*[\/.\-]\s*(\d{2,4})$/', $s, $m)) {
        $dia = (int)$m[1]; $mes = (int)$m[2]; $a = (int)$m[3];
        if (strlen($m[3]) === 2) $a += $a > (int)date('y') ? 1900 : 2000;
    } else return [null, 'data não reconhecida: ' . $s];
    if ($a < 1900 || $a > (int)date('Y') || !checkdate($mes, $dia, $a)) return [null, 'data inválida: ' . $s];
    return [sprintf('%04d-%02d-%02d', $a, $mes, $dia), null];
}

function extrair_cep($texto) {
    $t = (string)$texto;
    if (preg_match('/CEP\D{0,6}(\d{2})\.?(\d{3})\s?-?\s?(\d{3})/i', $t, $m)
        || preg_match('/\b(\d{2})\.?(\d{3})-(\d{3})\b/', $t, $m)) return $m[1] . $m[2] . $m[3];
    return null;
}
function formatar_cep($d) { return strlen((string)$d) === 8 ? substr($d, 0, 5) . '-' . substr($d, 5) : null; }
function uf_por_cep($cep) {
    $d = so_digitos($cep);
    if (strlen($d) !== 8) return null;
    $n = (int)substr($d, 0, 5);
    $faixas = [[1000,19999,'SP'],[20000,28999,'RJ'],[29000,29999,'ES'],[30000,39999,'MG'],[40000,48999,'BA'],[49000,49999,'SE'],
        [50000,56999,'PE'],[57000,57999,'AL'],[58000,58999,'PB'],[59000,59999,'RN'],[60000,63999,'CE'],[64000,64999,'PI'],
        [65000,65999,'MA'],[66000,68899,'PA'],[68900,68999,'AP'],[69000,69299,'AM'],[69300,69399,'RR'],[69400,69899,'AM'],
        [69900,69999,'AC'],[70000,72799,'DF'],[72800,72999,'GO'],[73000,73699,'DF'],[73700,76799,'GO'],[76800,76999,'RO'],
        [77000,77999,'TO'],[78000,78899,'MT'],[79000,79999,'MS'],[80000,87999,'PR'],[88000,89999,'SC'],[90000,99999,'RS']];
    foreach ($faixas as $f) if ($n >= $f[0] && $n <= $f[1]) return $f[2];
    return null;
}

// Documento: devolve [cpf, rg, cnpj, problema].
function separar_documento($raw) {
    $s = texto_limpo(preg_replace('/\.0+$/', '', (string)$raw));
    if ($s === '') return [null, null, null, null];
    $d = so_digitos($s);
    if (!preg_match('/[a-z]/i', $s)) {
        if (strlen($d) === 14) return [null, null, formatar_cnpj($d), cnpj_valido($d) ? null : 'CNPJ com dígito verificador inválido: ' . $s];
        if (strlen($d) === 11) return [formatar_cpf($d), null, null, cpf_valido($d) ? null : 'CPF com dígito verificador inválido: ' . $s];
        if (in_array(strlen($d), [9, 10], true) && cpf_valido(str_pad($d, 11, '0', STR_PAD_LEFT))) return [formatar_cpf(str_pad($d, 11, '0', STR_PAD_LEFT)), null, null, null];
        if (in_array(strlen($d), [12, 13], true) && cnpj_valido(str_pad($d, 14, '0', STR_PAD_LEFT))) return [null, null, formatar_cnpj(str_pad($d, 14, '0', STR_PAD_LEFT)), null];
    }
    return [null, mb_substr($s, 0, 30), null, null];
}

function email_limpo($raw) {
    $e = mb_strtolower(str_replace(' ', '', texto_limpo($raw)), 'UTF-8');
    if ($e === '') return [null, null];
    $e = preg_split('/[;,]/', $e)[0];  // vários e-mails na mesma célula: fica o primeiro
    if (preg_match('/\.com\.com$/', $e)) $e = substr($e, 0, -4);
    return [$e, filter_var($e, FILTER_VALIDATE_EMAIL) ? null : 'e-mail inválido: ' . $e];
}

// Pendências que dependem só dos dados atuais da ficha.
function pendencias_cliente($c) {
    $p = [];
    if (!empty($c['cpf']) && !cpf_valido($c['cpf'])) $p[] = 'CPF com dígito verificador inválido: ' . $c['cpf'];
    if (!empty($c['cnpj']) && !cnpj_valido($c['cnpj'])) $p[] = 'CNPJ com dígito verificador inválido: ' . $c['cnpj'];
    if (!empty($c['telefone']) && empty($c['telefone_e164'])) $p[] = 'telefone fora do padrão: ' . $c['telefone'];
    if (!empty($c['email']) && !filter_var($c['email'], FILTER_VALIDATE_EMAIL)) $p[] = 'e-mail inválido: ' . $c['email'];
    if (empty($c['telefone']) && empty($c['telefone2']) && empty($c['email'])) $p[] = 'sem telefone e sem e-mail';
    return $p;
}

// ---------- Etiquetas ----------
function etiquetas_todas() { return db()->query('SELECT * FROM crm_etiquetas ORDER BY nome')->fetchAll(); }
function etiqueta_id($nome) {
    $nome = mb_substr(texto_limpo($nome), 0, 60);
    if ($nome === '') return 0;
    $s = db()->prepare('SELECT id FROM crm_etiquetas WHERE nome = ?'); $s->execute([$nome]);
    $id = (int)$s->fetchColumn();
    if (!$id) { db()->prepare('INSERT INTO crm_etiquetas (nome) VALUES (?)')->execute([$nome]); $id = (int)db()->lastInsertId(); }
    return $id;
}
function etiquetas_do_cliente($id) {
    $s = db()->prepare('SELECT e.id, e.nome FROM crm_etiquetas e JOIN crm_cliente_etiqueta ce ON ce.etiqueta_id = e.id WHERE ce.cliente_id = ? ORDER BY e.nome');
    $s->execute([(int)$id]);
    return $s->fetchAll(PDO::FETCH_KEY_PAIR);
}
function etiquetar($clienteId, $etiquetaId) {
    $s = db()->prepare('SELECT 1 FROM crm_cliente_etiqueta WHERE cliente_id = ? AND etiqueta_id = ?'); $s->execute([$clienteId, $etiquetaId]);
    if ($s->fetchColumn()) return false;
    db()->prepare('INSERT INTO crm_cliente_etiqueta (cliente_id, etiqueta_id) VALUES (?, ?)')->execute([$clienteId, $etiquetaId]);
    return true;
}

// ---------- Importação ----------
// Colunas reais de clientes que a importação preenche.
define('CRM_COLUNAS', ['nome', 'tipo_pessoa', 'categoria_id', 'categoria_auto', 'cpf', 'rg', 'cnpj', 'ie', 'im', 'sexo', 'sexo_presumido', 'email', 'telefone', 'telefone_e164', 'telefone2',
    'data_nascimento', 'endereco', 'bairro', 'cep', 'cidade', 'uf', 'clube_beneficios', 'origem', 'interesses', 'vendedor', 'cliente_desde',
    'ultima_interacao', 'observacoes']);

function crm_modelo_csv() {
    return "\xEF\xBB\xBF" . "nome;tipo_pessoa;cpf_cnpj;rg;ie;sexo;data_nascimento;celular;telefone2;email;endereco;bairro;cep;cidade;uf;clube;origem;grupo;vendedor;interesses;observacoes;cliente_desde;ultima_interacao\r\n"
         . "Maria da Silva;PF;123.456.789-09;;;F;15/03/1980;(61) 99999-0000;;maria@exemplo.com.br;SQN 212 Bloco E Apto 206;Asa Norte;70864-050;Brasília;DF;sim;tour;Visita guiada;;Espumantes;Veio com a família;14/03/2026;14/03/2026\r\n";
}

// Lê a primeira aba de um .xlsx sem bibliotecas externas (ZipArchive + SimpleXML).
function crm_ler_xlsx($caminho) {
    if (!class_exists('ZipArchive')) throw new RuntimeException('O servidor não lê .xlsx (extensão zip do PHP desligada). Salve a planilha como CSV.');
    $z = new ZipArchive();
    if ($z->open($caminho) !== true) throw new RuntimeException('Não foi possível abrir a planilha .xlsx.');
    $txt = function ($no) { $t = ''; foreach ((array)$no->xpath('.//*[local-name()="t"]') as $x) $t .= (string)$x; return $t; };
    $comp = [];
    if (($ss = $z->getFromName('xl/sharedStrings.xml')) !== false) foreach (simplexml_load_string($ss)->si as $si) $comp[] = $txt($si);
    $alvo = 'xl/worksheets/sheet1.xml';
    $wb = $z->getFromName('xl/workbook.xml'); $rels = $z->getFromName('xl/_rels/workbook.xml.rels');
    if ($wb && $rels) {
        $w = simplexml_load_string($wb); $r = simplexml_load_string($rels);
        $sh = $w->xpath('//*[local-name()="sheet"]')[0] ?? null;
        if ($sh) {
            $rid = (string)$sh->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            foreach ($r->children() as $rel) if ((string)$rel['Id'] === $rid) { $t = ltrim((string)$rel['Target'], '/'); $alvo = strpos($t, 'xl/') === 0 ? $t : 'xl/' . $t; }
        }
    }
    $xml = $z->getFromName($alvo);
    $z->close();
    if ($xml === false) throw new RuntimeException('A planilha não tem abas legíveis.');
    $linhas = [];
    foreach (simplexml_load_string($xml)->sheetData->row as $row) {
        $l = [];
        foreach ($row->c as $c) {
            $ref = preg_replace('/\d+/', '', (string)$c['r']); $col = 0;
            foreach (str_split($ref) as $ch) $col = $col * 26 + ord($ch) - 64;
            $t = (string)$c['t'];
            $v = $t === 's' ? ($comp[(int)$c->v] ?? '') : ($t === 'inlineStr' ? $txt($c->is) : (string)$c->v);
            if ($t === '' && preg_match('/^\d(\.\d+)?E\+?\d+$/i', $v)) $v = sprintf('%.0f', (float)$v);
            $l[$col - 1] = $v;
        }
        $linhas[(int)$row['r']] = $l;
    }
    return $linhas;
}
function crm_ler_csv_bruto($caminho) {
    $txt = (string)file_get_contents($caminho);
    if (strncmp($txt, "\xEF\xBB\xBF", 3) === 0) $txt = substr($txt, 3);
    if (!mb_check_encoding($txt, 'UTF-8')) $txt = mb_convert_encoding($txt, 'UTF-8', 'Windows-1252');
    $txt = str_replace(["\r\n", "\r"], "\n", $txt);
    $l1 = strtok($txt, "\n");
    $sep = substr_count((string)$l1, ';') >= substr_count((string)$l1, ',') ? ';' : ',';
    $h = fopen('php://temp', 'r+'); fwrite($h, $txt); rewind($h);
    $linhas = []; $n = 0;
    while (($r = fgetcsv($h, 0, $sep, '"', '')) !== false) $linhas[++$n] = $r;
    fclose($h);
    return $linhas;
}

// Lê CSV ou XLSX e devolve as linhas pelos nomes dos campos. O cabeçalho é a primeira linha (até a 15ª) que tem "Nome".
function crm_ler($caminho, $nomeOriginal = '') {
    $bruto = preg_match('/\.xlsx$/i', $nomeOriginal ?: $caminho) ? crm_ler_xlsx($caminho) : crm_ler_csv_bruto($caminho);
    $alias = ['nome' => 'nome', 'nomecompleto' => 'nome', 'razaosocial' => 'nome', 'cliente' => 'nome',
        'cpf' => 'documento', 'cnpj' => 'documento', 'cpfrg' => 'documento', 'cpfcnpj' => 'documento', 'documento' => 'documento', 'rg' => 'rg',
        'ie' => 'ie', 'inscricaoestadual' => 'ie', 'im' => 'im', 'inscricaomunicipal' => 'im', 'tipo' => 'tipo_pessoa', 'tipopessoa' => 'tipo_pessoa', 'tipodepessoa' => 'tipo_pessoa',
        'sexo' => 'sexo', 'genero' => 'sexo',
        'datadenascimento' => 'data_nascimento', 'nascimento' => 'data_nascimento', 'datanascimento' => 'data_nascimento', 'aniversario' => 'data_nascimento',
        'telefone' => 'telefone', 'contato' => 'telefone', 'celular' => 'celular', 'whatsapp' => 'celular', 'telefonecomercial' => 'telefone2', 'telefone2' => 'telefone2', 'fixo' => 'telefone2',
        'email' => 'email', 'endereco' => 'endereco', 'bairro' => 'bairro', 'cep' => 'cep', 'cidade' => 'cidade', 'municipio' => 'cidade', 'uf' => 'uf', 'estado' => 'uf',
        'clube' => 'clube_beneficios', 'clubebeneficios' => 'clube_beneficios', 'clubedebeneficios' => 'clube_beneficios', 'origem' => 'origem',
        'grupo' => 'grupo', 'etiqueta' => 'grupo', 'etiquetas' => 'grupo', 'vendedor' => 'vendedor', 'vendedorresponsavel' => 'vendedor',
        'interesses' => 'interesses', 'observacoesinteresses' => 'interesses', 'observacoes' => 'observacoes', 'observacao' => 'observacoes', 'obs' => 'observacoes',
        'cadastro' => 'cliente_desde', 'datadecadastro' => 'cliente_desde', 'clientedesde' => 'cliente_desde',
        'categoria' => 'categoria', 'segmento' => 'categoria', 'tipodecliente' => 'categoria', 'perfil' => 'categoria',
        'ultimainteracao' => 'ultima_interacao', 'datadavisita' => 'ultima_interacao', 'datavisita' => 'ultima_interacao', 'referencia' => 'referencia'];
    $cabN = null; $mapa = [];
    foreach ($bruto as $n => $r) {
        $m = [];
        foreach ($r as $i => $c) { $k = str_replace(' ', '', chave_nome($c)); if (isset($alias[$k]) && !in_array($alias[$k], $m, true)) $m[$i] = $alias[$k]; }
        if (in_array('nome', $m, true)) { $cabN = $n; $mapa = $m; break; }
        if ($n >= 15) break;
    }
    if ($cabN === null) throw new RuntimeException('Não achei a coluna "Nome" nas primeiras linhas. Use o modelo.');
    $linhas = [];
    foreach ($bruto as $n => $r) {
        if ($n <= $cabN || implode('', array_map('strval', $r)) === '') continue;
        $l = ['_linha' => $n];
        foreach ($mapa as $i => $campo) $l[$campo] = $r[$i] ?? '';
        $linhas[] = $l;
    }
    if (count($linhas) > 20000) throw new RuntimeException('Arquivo com mais de 20.000 linhas. Divida em partes.');
    return $linhas;
}

// Uma linha do arquivo → cliente padronizado + pendências. Devolve null se não houver nome.
function crm_normalizar($l) {
    $p = [];
    [$cpf, $rg, $cnpj, $pc] = separar_documento($l['documento'] ?? '');
    if ($pc) $p[] = $pc;
    $tipo = strtoupper(substr(texto_limpo($l['tipo_pessoa'] ?? ''), 0, 2));
    if (!in_array($tipo, ['PF', 'PJ'], true)) $tipo = in_array(chave_nome($l['tipo_pessoa'] ?? ''), ['juridica', 'pessoa juridica', 'empresa'], true) ? 'PJ' : ($cnpj ? 'PJ' : 'PF');
    $nomeBruto = texto_limpo($l['nome'] ?? '');
    $nome = $tipo === 'PJ' ? trim($nomeBruto, " .,;:-_*'\"") : nome_proprio($nomeBruto);
    if ($nome === '') return null;
    $rgCol = texto_limpo($l['rg'] ?? '');
    if (!$rg && $rgCol !== '') $rg = mb_substr($rgCol, 0, 30);
    [$nasc, $pd] = normalizar_data($l['data_nascimento'] ?? '');
    if ($pd) $p[] = ($tipo === 'PJ' ? 'aniversário: ' : 'nascimento: ') . $pd;
    // Pessoa com menos de 12 anos é quase sempre a data de cadastro digitada no lugar do nascimento: não grava, deixa como pendência.
    if ($nasc && $tipo === 'PF' && $nasc > date('Y-m-d', strtotime('-12 years'))) { $p[] = 'nascimento provavelmente errado (não gravado): ' . data_br($nasc); $nasc = null; }
    // Telefones: o celular válido vai para o principal (WhatsApp); o outro, para o segundo telefone.
    $tels = [];
    foreach (['celular', 'telefone', 'telefone2'] as $k) {
        if (texto_limpo($l[$k] ?? '') === '') continue;
        $t = normalizar_telefone($l[$k]);
        if (!in_array($t[1] ?: $t[0], array_map(fn($x) => $x[1] ?: $x[0], $tels), true)) $tels[] = $t;
    }
    usort($tels, fn($a, $b) => (e_celular($b[1]) <=> e_celular($a[1])) ?: ((bool)$b[1] <=> (bool)$a[1]));
    [$tel, $e164, $pt] = $tels[0] ?? [null, null, null];
    if ($pt) $p[] = $pt;
    $tel2 = isset($tels[1]) ? $tels[1][0] : null;
    [$email, $pe] = email_limpo($l['email'] ?? '');
    if ($pe) $p[] = $pe;
    $end = texto_limpo($l['endereco'] ?? '');
    if (preg_match('/^[\s0-9.,-]*$/', $end)) $end = '';
    $cepD = so_digitos($l['cep'] ?? '');
    if (strlen($cepD) !== 8) $cepD = (string)extrair_cep($end);
    $uf = strtoupper(substr(texto_limpo($l['uf'] ?? ''), 0, 2));
    if (!preg_match('/^[A-Z]{2}$/', $uf)) $uf = uf_por_cep($cepD);
    [$ult] = normalizar_data($l['ultima_interacao'] ?? '');
    [$desde] = normalizar_data($l['cliente_desde'] ?? '');
    $clube = in_array(chave_nome($l['clube_beneficios'] ?? ''), ['sim', 's', 'x', 'yes', 'membro'], true) || trim((string)($l['clube_beneficios'] ?? '')) === '1';
    $orig = chave_nome($l['origem'] ?? '');
    if (!isset(lista_origens()[$orig])) $orig = 'planilha';
    // Sexo: o informado vale; sem informação, presume pelo primeiro nome (só pessoa física).
    $sx = strtoupper(substr(chave_nome($l['sexo'] ?? ''), 0, 1));
    $sexo = in_array($sx, ['F', 'M'], true) ? $sx : null; $presumido = 0;
    if (!$sexo && $tipo === 'PF' && ($sexo = sexo_por_nome($nome))) $presumido = 1;
    if (!$tel && !$tel2 && !$email) $p[] = 'sem telefone e sem e-mail';
    $grupos = array_values(array_filter(array_map(fn($g) => mb_substr(texto_limpo($g), 0, 60), preg_split('/[,;|]/', (string)($l['grupo'] ?? '')))));
    return [
        'nome' => mb_substr($nome, 0, 160), 'tipo_pessoa' => $tipo, 'cpf' => $tipo === 'PF' ? $cpf : null, 'rg' => $rg, 'cnpj' => $cnpj,
        'ie' => vazio_null(mb_substr(texto_limpo($l['ie'] ?? ''), 0, 30)), 'im' => vazio_null(mb_substr(texto_limpo($l['im'] ?? ''), 0, 30)),
        'sexo' => $tipo === 'PF' ? $sexo : null, 'sexo_presumido' => $tipo === 'PF' ? $presumido : 0,
        'email' => $email ? mb_substr($email, 0, 160) : null, 'telefone' => $tel ? mb_substr($tel, 0, 30) : null, 'telefone_e164' => $e164,
        'telefone2' => $tel2 ? mb_substr($tel2, 0, 30) : null, 'data_nascimento' => $nasc,
        'endereco' => $end !== '' ? mb_substr($end, 0, 255) : null, 'bairro' => vazio_null(mb_substr(texto_limpo($l['bairro'] ?? ''), 0, 100)),
        'cep' => formatar_cep($cepD), 'cidade' => vazio_null(mb_substr(texto_limpo($l['cidade'] ?? ''), 0, 100)),
        'uf' => $uf ?: null, 'clube_beneficios' => $clube ? 1 : 0, 'origem' => $orig,
        'interesses' => vazio_null(mb_substr(texto_limpo($l['interesses'] ?? ''), 0, 500)),
        'vendedor' => vazio_null(mb_substr(texto_limpo($l['vendedor'] ?? ''), 0, 120)), 'cliente_desde' => $desde,
        'observacoes' => vazio_null(trim(str_replace("\r", '', (string)($l['observacoes'] ?? '')))), 'ultima_interacao' => $ult,
        'categoria_id' => ($cat = categoria_por_nome($l['categoria'] ?? '')) ?: null, 'categoria_auto' => 0,
        '_pend' => $p, '_ref' => texto_limpo($l['referencia'] ?? '') ?: 'linha ' . $l['_linha'], '_etiquetas' => $grupos,
    ];
}

// Mesma pessoa? CNPJ igual; CPF válido igual (com o mesmo primeiro nome); ou e-mail igual (mesmo primeiro nome);
// ou telefone igual (mesmo primeiro e último nome); ou nome completo igual sem nenhum dado que contradiga.
function crm_mesma_pessoa($a, $b) {
    $jA = cnpj_valido($a['cnpj'] ?? '') ? so_digitos($a['cnpj']) : ''; $jB = cnpj_valido($b['cnpj'] ?? '') ? so_digitos($b['cnpj']) : '';
    if ($jA && $jB) return $jA === $jB;
    if (($a['tipo_pessoa'] ?? 'PF') !== ($b['tipo_pessoa'] ?? 'PF') && ($jA || $jB)) return false;
    $cpfA = cpf_valido($a['cpf'] ?? '') ? so_digitos($a['cpf']) : ''; $cpfB = cpf_valido($b['cpf'] ?? '') ? so_digitos($b['cpf']) : '';
    if ($cpfA && $cpfB) return $cpfA === $cpfB && primeiro_nome($a['nome']) === primeiro_nome($b['nome']);
    $emA = mb_strtolower((string)($a['email'] ?? '')); $emB = mb_strtolower((string)($b['email'] ?? ''));
    $tA = (string)($a['telefone_e164'] ?? ''); $tB = (string)($b['telefone_e164'] ?? '');
    if ($emA !== '' && $emA === $emB && primeiro_nome($a['nome']) === primeiro_nome($b['nome'])) return true;
    if ($tA !== '' && $tA === $tB && primeiro_ultimo($a['nome']) === primeiro_ultimo($b['nome'])) return true;
    $na = chave_nome($a['nome']);
    if ($na === '' || $na !== chave_nome($b['nome'])) return false;
    $nA = (string)($a['data_nascimento'] ?? ''); $nB = (string)($b['data_nascimento'] ?? '');
    return !(($emA !== '' && $emB !== '' && $emA !== $emB) || ($tA !== '' && $tB !== '' && $tA !== $tB) || ($nA !== '' && $nB !== '' && $nA !== $nB));
}

// Junta $novo em $base: só preenche o que está vazio; clube só liga; textos são acrescentados sem repetir.
function crm_mesclar($base, $novo) {
    // Telefone antigo fora do padrão é trocado por um válido (o antigo vai para o segundo telefone).
    if (empty($base['telefone_e164']) && !empty($novo['telefone_e164'])) {
        if (!empty($base['telefone']) && empty($base['telefone2'])) $base['telefone2'] = $base['telefone'];
        $base['telefone'] = $novo['telefone']; $base['telefone_e164'] = $novo['telefone_e164'];
    }
    foreach (['cpf', 'rg', 'cnpj', 'ie', 'im', 'email', 'telefone', 'telefone2', 'data_nascimento', 'endereco', 'bairro', 'cep', 'cidade', 'uf', 'origem', 'vendedor'] as $c) {
        if ((!isset($base[$c]) || $base[$c] === '' || $base[$c] === null) && !empty($novo[$c])) $base[$c] = $novo[$c];
    }
    if (($base['telefone2'] ?? null) !== null && $base['telefone2'] === ($base['telefone'] ?? null)) $base['telefone2'] = null;
    if (!empty($base['cnpj']) && empty($base['cpf'])) $base['tipo_pessoa'] = 'PJ';
    // Sexo informado vence o presumido; presumido só entra onde não há nada.
    if (!empty($novo['sexo']) && (empty($base['sexo']) || (!empty($base['sexo_presumido']) && empty($novo['sexo_presumido'])))) { $base['sexo'] = $novo['sexo']; $base['sexo_presumido'] = $novo['sexo_presumido']; }
    if (($base['tipo_pessoa'] ?? 'PF') === 'PJ') { $base['sexo'] = null; $base['sexo_presumido'] = 0; }
    if (!empty($novo['clube_beneficios'])) $base['clube_beneficios'] = 1;
    // Categoria informada no arquivo vence a automática; nunca troca uma categoria já confirmada.
    if (!empty($novo['categoria_id']) && (empty($base['categoria_id']) || !empty($base['categoria_auto']))) { $base['categoria_id'] = $novo['categoria_id']; $base['categoria_auto'] = 0; }
    foreach (['interesses' => 500, 'observacoes' => 5000] as $c => $max) {
        $n = trim((string)($novo[$c] ?? '')); $b = trim((string)($base[$c] ?? ''));
        if ($n !== '' && mb_stripos($b, $n) === false) $base[$c] = mb_substr($b === '' ? $n : $b . ' | ' . $n, 0, $max);
    }
    if (!empty($novo['ultima_interacao']) && (empty($base['ultima_interacao']) || $novo['ultima_interacao'] > $base['ultima_interacao'])) $base['ultima_interacao'] = $novo['ultima_interacao'];
    if (!empty($novo['cliente_desde']) && (empty($base['cliente_desde']) || $novo['cliente_desde'] < $base['cliente_desde'])) $base['cliente_desde'] = $novo['cliente_desde'];
    $base['_pend'] = array_values(array_unique(array_merge($base['_pend'] ?? [], $novo['_pend'] ?? [])));
    $base['_etiquetas'] = array_values(array_unique(array_merge($base['_etiquetas'] ?? [], $novo['_etiquetas'] ?? [])));
    $base['_refs'] = array_merge($base['_refs'] ?? (isset($base['_ref']) ? [$base['_ref']] : []), [$novo['_ref'] ?? '']);
    return $base;
}

// Índices para achar candidatos sem comparar cada linha com a base inteira.
function crm_chaves($c) {
    return ['cnpj' => cnpj_valido($c['cnpj'] ?? '') ? so_digitos($c['cnpj']) : '', 'cpf' => cpf_valido($c['cpf'] ?? '') ? so_digitos($c['cpf']) : '',
            'email' => mb_strtolower((string)($c['email'] ?? '')), 'tel' => (string)($c['telefone_e164'] ?? ''), 'nome' => chave_nome($c['nome'] ?? '')];
}
function crm_indexar(&$ix, $k, $c) { foreach (crm_chaves($c) as $t => $v) if ($v !== '' && !in_array($k, $ix[$t][$v] ?? [], true)) $ix[$t][$v][] = $k; }
function crm_achar($ix, $lista, $n) {
    $cand = [];
    foreach (crm_chaves($n) as $t => $v) if ($v !== '') foreach ($ix[$t][$v] ?? [] as $k) $cand[$k] = true;
    foreach (array_keys($cand) as $k) if (crm_mesma_pessoa($n, $lista[$k])) return $k;
    return null;
}

// Monta o plano da importação sem gravar nada.
function crm_planejar($linhas) {
    $plano = ['novos' => [], 'atualizar' => [], 'mesclas' => [], 'ignorados' => [], 'conflitos' => [], 'total' => count($linhas)];
    $base = []; $ixB = [];
    foreach (db()->query('SELECT * FROM clientes') as $c) {
        $calc = $c;
        if (empty($calc['telefone_e164'])) { [, $e] = normalizar_telefone($calc['telefone']); $calc['telefone_e164'] = $e; }
        $base[(int)$c['id']] = ['raw' => $c, 'calc' => $calc];
        crm_indexar($ixB, (int)$c['id'], $calc);
    }
    $baseCalc = array_map(fn($x) => $x['calc'], $base);
    $novos = []; $ixN = [];
    foreach ($linhas as $l) {
        $n = crm_normalizar($l);
        if (!$n) {
            $resto = array_diff_key($l, ['_linha' => 1]);
            if (trim(implode('', array_map('strval', $resto))) !== '') $plano['ignorados'][] = ['ref' => $l['referencia'] ?? 'linha ' . $l['_linha'], 'motivo' => 'sem nome'];
            continue;
        }
        // CPF já usado por outra pessoa (primeiro nome diferente): entra sem CPF e vira pendência.
        if (cpf_valido($n['cpf'] ?? '')) {
            $d = so_digitos($n['cpf']); $dono = null;
            foreach ($ixB['cpf'][$d] ?? [] as $id) if (primeiro_nome($baseCalc[$id]['nome']) !== primeiro_nome($n['nome'])) $dono = $baseCalc[$id]['nome'];
            foreach ($ixN['cpf'][$d] ?? [] as $k) if (primeiro_nome($novos[$k]['nome']) !== primeiro_nome($n['nome'])) $dono = $novos[$k]['nome'];
            if ($dono !== null) {
                $n['_pend'][] = 'CPF ' . $n['cpf'] . ' também consta para ' . $dono . ' (não gravado)';
                $plano['conflitos'][] = ['ref' => $n['_ref'], 'nome' => $n['nome'], 'cpf' => $n['cpf'], 'outro' => $dono];
                $n['cpf'] = null;
            }
        }
        $id = crm_achar($ixB, $baseCalc, $n);
        if ($id !== null) {
            if (isset($plano['atualizar'][$id])) $plano['mesclas'][] = ['ref' => $n['_ref'], 'nome' => $n['nome'], 'com' => $baseCalc[$id]['nome'] . ' (já cadastrado)'];
            $atual = $plano['atualizar'][$id]['depois'] ?? $baseCalc[$id];
            $plano['atualizar'][$id] = ['antes' => $base[$id]['raw'], 'depois' => crm_mesclar($atual, $n), 'refs' => array_merge($plano['atualizar'][$id]['refs'] ?? [], [$n['_ref']])];
            continue;
        }
        $k = crm_achar($ixN, $novos, $n);
        if ($k !== null) {
            $plano['mesclas'][] = ['ref' => $n['_ref'], 'nome' => $n['nome'], 'com' => $novos[$k]['nome'] . ' (' . $novos[$k]['_ref'] . ')'];
            $novos[$k] = crm_mesclar($novos[$k], $n);
            crm_indexar($ixN, $k, $novos[$k]);
            continue;
        }
        $k = count($novos);
        $novos[$k] = $n;
        crm_indexar($ixN, $k, $n);
    }
    $plano['novos'] = array_values($novos);
    // Cliente existente sem nada de novo não conta como atualização (etiquetas novas contam).
    $etqAtuais = [];
    foreach (db()->query('SELECT ce.cliente_id, e.nome FROM crm_cliente_etiqueta ce JOIN crm_etiquetas e ON e.id = ce.etiqueta_id') as $r) $etqAtuais[$r['cliente_id']][] = $r['nome'];
    foreach ($plano['atualizar'] as $id => $u) {
        $mud = crm_campos_alterados($u['antes'], $u['depois']);
        unset($mud['telefone_e164']);
        $etq = array_values(array_diff($u['depois']['_etiquetas'] ?? [], $etqAtuais[$id] ?? []));
        if (!$mud && !$etq) { unset($plano['atualizar'][$id]); continue; }
        if ((string)($u['antes']['telefone_e164'] ?? '') !== (string)($u['depois']['telefone_e164'] ?? '')) $mud['telefone_e164'] = $u['depois']['telefone_e164'];
        $plano['atualizar'][$id]['campos'] = $mud;
        $plano['atualizar'][$id]['etiquetas_novas'] = $etq;
    }
    $plano['pendencias'] = [];
    foreach ($plano['novos'] as $n) if ($n['_pend']) $plano['pendencias'][] = ['ref' => implode(', ', $n['_refs'] ?? [$n['_ref']]), 'nome' => $n['nome'], 'itens' => $n['_pend']];
    foreach ($plano['atualizar'] as $u) if (!empty($u['depois']['_pend'])) $plano['pendencias'][] = ['ref' => implode(', ', $u['refs']), 'nome' => $u['antes']['nome'], 'itens' => $u['depois']['_pend']];
    $plano['sexo_presumido'] = count(array_filter($plano['novos'], fn($n) => !empty($n['sexo_presumido'])));
    $plano['pj'] = count(array_filter($plano['novos'], fn($n) => $n['tipo_pessoa'] === 'PJ'));
    return $plano;
}
function crm_campos_alterados($antes, $depois) {
    $m = [];
    foreach (CRM_COLUNAS as $c) if ($c !== 'nome' && (string)($antes[$c] ?? '') !== (string)($depois[$c] ?? '')) $m[$c] = $depois[$c] ?? null;
    return $m;
}

// Grava o plano numa transação única. Devolve o número do lote.
function crm_executar($plano, $arquivo) {
    $pdo = db(); $u = $GLOBALS['USUARIO'];
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO crm_importacoes (arquivo, usuario_id, usuario_nome, novos, atualizados, mesclados, ignorados, pendencias) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([mb_substr($arquivo, 0, 255), $u['id'], $u['nome'], count($plano['novos']), count($plano['atualizar']), count($plano['mesclas']), count($plano['ignorados']), count($plano['pendencias'])]);
        $lote = (int)$pdo->lastInsertId();
        $cols = array_merge(CRM_COLUNAS, ['pendencias', 'importacao_id']);
        $ins = $pdo->prepare('INSERT INTO clientes (' . implode(', ', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')');
        $etqCache = [];
        $etq = function ($cid, $nomes) use (&$etqCache) { foreach ($nomes as $nm) { $etqCache[$nm] = $etqCache[$nm] ?? etiqueta_id($nm); if ($etqCache[$nm]) etiquetar($cid, $etqCache[$nm]); } };
        $padrao = ['tipo_pessoa' => 'PF', 'clube_beneficios' => 0, 'sexo_presumido' => 0, 'categoria_auto' => 0];
        foreach ($plano['novos'] as $n) {
            $n['pendencias'] = $n['_pend'] ? implode("\n", $n['_pend']) : null; $n['importacao_id'] = $lote;
            $ins->execute(array_map(fn($c) => $n[$c] ?? ($padrao[$c] ?? null), $cols));
            $cid = (int)$pdo->lastInsertId();
            $etq($cid, $n['_etiquetas'] ?? []);
            registrar_log('cliente', $cid, 'Inclusão (importação #' . $lote . ')', $n['nome'] . ' · ' . implode(', ', $n['_refs'] ?? [$n['_ref']]));
        }
        foreach ($plano['atualizar'] as $id => $x) {
            $campos = $x['campos'];
            $pend = array_unique(array_merge(array_filter(explode("\n", (string)($x['antes']['pendencias'] ?? ''))), $x['depois']['_pend'] ?? []));
            $campos['pendencias'] = $pend ? implode("\n", $pend) : null;
            $pdo->prepare('UPDATE clientes SET ' . implode(' = ?, ', array_keys($campos)) . ' = ? WHERE id = ?')->execute(array_merge(array_values($campos), [$id]));
            registrar_alteracao('cliente', 'clientes', $id, linha_sem('clientes', $x['antes']), 'Atualização (importação #' . $lote . ')');
            if (!empty($x['etiquetas_novas'])) { $etq($id, $x['etiquetas_novas']); registrar_log('cliente', $id, 'Etiquetas (importação #' . $lote . ')', '+ ' . implode(', ', $x['etiquetas_novas'])); }
        }
        // Padroniza o telefone dos clientes antigos que ainda não têm o formato do WhatsApp (só preenche a coluna nova).
        $up = $pdo->prepare('UPDATE clientes SET telefone_e164 = ? WHERE id = ? AND telefone_e164 IS NULL');
        foreach ($pdo->query('SELECT id, telefone FROM clientes WHERE telefone_e164 IS NULL AND telefone IS NOT NULL')->fetchAll() as $c) {
            [, $e] = normalizar_telefone($c['telefone']);
            if ($e) $up->execute([$e, $c['id']]);
        }
        registrar_log('importacao', $lote, 'Importação de clientes', $arquivo . ': ' . count($plano['novos']) . ' novos, ' . count($plano['atualizar']) . ' atualizados, '
            . count($plano['mesclas']) . ' linhas repetidas mescladas, ' . count($plano['ignorados']) . ' ignoradas, ' . count($plano['pendencias']) . ' com pendências');
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
    crm_classificar_faltantes();
    grupos_recalcular_todos();
    return $lote;
}
// O registro "antes" vem do plano (com campos calculados); deixa só as colunas reais para a trilha.
function linha_sem($tabela, $r) { return array_filter($r, fn($k) => $k[0] !== '_', ARRAY_FILTER_USE_KEY); }

// Desfaz um lote: apaga os clientes que ele criou e que ainda não têm tour. Atualizações em clientes antigos ficam (estão na trilha).
function crm_desfazer($lote) {
    $pdo = db();
    $imp = $pdo->prepare('SELECT * FROM crm_importacoes WHERE id = ?'); $imp->execute([$lote]); $imp = $imp->fetch();
    if (!$imp || $imp['status'] !== 'concluida') return null;
    $pdo->beginTransaction();
    try {
        // Fica quem já tem tour ou compra registrada (vendas do ERP).
        $s = $pdo->prepare('SELECT id, nome FROM clientes WHERE importacao_id = ? AND NOT EXISTS (SELECT 1 FROM tour_vendas v WHERE v.cliente_id = clientes.id)'
            . (vendas_v9() ? ' AND NOT EXISTS (SELECT 1 FROM vendas vd WHERE vd.cliente_id = clientes.id)' : ''));
        $s->execute([$lote]); $apagar = $s->fetchAll();
        $del = $pdo->prepare('DELETE FROM clientes WHERE id = ?');
        $delE = $pdo->prepare('DELETE FROM crm_cliente_etiqueta WHERE cliente_id = ?');
        $delG = $pdo->prepare('DELETE FROM crm_grupo_membros WHERE cliente_id = ?');
        foreach ($apagar as $c) { $delE->execute([$c['id']]); $delG->execute([$c['id']]); $del->execute([$c['id']]); }
        $s = $pdo->prepare('SELECT COUNT(*) FROM clientes WHERE importacao_id = ?'); $s->execute([$lote]); $ficaram = (int)$s->fetchColumn();
        $pdo->prepare("UPDATE crm_importacoes SET status = 'desfeita', desfeita_em = ?, desfeita_por = ? WHERE id = ?")
            ->execute([date('Y-m-d H:i:s'), $GLOBALS['USUARIO']['nome'], $lote]);
        registrar_log('importacao', $lote, 'Importação desfeita', count($apagar) . ' clientes removidos' . ($ficaram ? '; ' . $ficaram . ' mantidos por já terem tour ou compra' : '') . '; atualizações em clientes antigos mantidas');
        $pdo->commit();
        return [count($apagar), $ficaram];
    } catch (Throwable $ex) { $pdo->rollBack(); throw $ex; }
}

// Faixas etárias usadas no painel e nos filtros (anos completos).
function faixas_etarias() {
    return ['ate17' => ['rot' => 'Até 17', 'anos' => [0, 17]], '18-24' => ['rot' => '18 a 24', 'anos' => [18, 24]], '25-34' => ['rot' => '25 a 34', 'anos' => [25, 34]],
            '35-44' => ['rot' => '35 a 44', 'anos' => [35, 44]], '45-54' => ['rot' => '45 a 54', 'anos' => [45, 54]], '55-64' => ['rot' => '55 a 64', 'anos' => [55, 64]],
            '65+' => ['rot' => '65 ou mais', 'anos' => [65, 130]], 'sem' => ['rot' => 'Sem data de nascimento', 'anos' => [null, null]]];
}
function faixa_da_idade($idade) {
    foreach (faixas_etarias() as $k => $f) if ($f['anos'][0] !== null && $idade >= $f['anos'][0] && $idade <= $f['anos'][1]) return $k;
    return 'sem';
}

// ---------- Categorias de cliente (quem é o cliente) ----------
// Origem = por onde o cliente chegou (tour, e-commerce, ERP...). Categoria = quem ele é (supermercado, restaurante, turista...).
function categorias_todas($soAtivas = false) {
    static $cache = [];
    $k = $soAtivas ? 1 : 0;
    if (!isset($cache[$k])) $cache[$k] = crm_v8() ? db()->query('SELECT * FROM crm_categorias' . ($soAtivas ? ' WHERE ativo = 1' : '') . ' ORDER BY ordem, nome')->fetchAll() : [];
    return $cache[$k];
}
function nome_categoria($id) { foreach (categorias_todas() as $c) if ((int)$c['id'] === (int)$id) return $c['nome']; return '—'; }
function categoria_por_nome($nome) {
    $k = chave_nome($nome);
    if ($k === '') return 0;
    foreach (categorias_todas() as $c) if (chave_nome($c['nome']) === $k || ($c['chave'] && $c['chave'] === str_replace(' ', '', $k))) return (int)$c['id'];
    foreach (categorias_todas() as $c) if (strpos(chave_nome($c['nome']), $k) === 0) return (int)$c['id'];
    return 0;
}
function categoria_por_chave($chave) { foreach (categorias_todas() as $c) if ($c['chave'] === $chave) return (int)$c['id']; return 0; }
// Sugestão automática: empresas pelo nome (supermercado, restaurante...); pessoas pela relação com a vinícola.
function categoria_sugerida($c, $tours = 0) {
    if (($c['tipo_pessoa'] ?? 'PF') === 'PJ') {
        $n = ' ' . chave_nome($c['nome']) . ' ';
        $regras = [
            'supermercado' => '/ (super ?mercado|supermerc|mercado|mercadinho|atacad|atacarejo|hiper|sacolao|hortifruti|minimercado|conveniencia|varejo) /',
            'restaurante' => '/ (restaurante|bar|bistro|bistrot|cafe|cafeteria|pizzaria|pizza|churrascaria|choperia|chopp|cervejaria|beer|pub|gastronomia|cozinha|lanchonete|burger|sushi|trattoria|osteria|botequim|boteco) /',
            'emporio' => '/ (emporio|adega|vinhos?|wines?|wine|delicatessen|enoteca|casa de vinhos) /',
            'hotel' => '/ (hotel|hoteis|pousada|resort|hostel|flat|hospedagem|inn) /',
            'eventos' => '/ (eventos?|buffet|cerimonial|festas?|recepcoes) /',
            'distribuidor' => '/ (distribuidora?|distribuicao|representac(ao|oes)|importadora) /',
        ];
        foreach ($regras as $chave => $re) if (preg_match($re, $n) && ($id = categoria_por_chave($chave))) return $id;
        return categoria_por_chave('empresa') ?: null;
    }
    if ($tours > 0 || in_array($c['origem'] ?? '', ['tour', 'clube'], true)) return categoria_por_chave('turista') ?: null;
    return categoria_por_chave('consumidor') ?: null;
}
// Clientes sem categoria recebem a sugestão, marcada como automática para revisão.
function crm_classificar_faltantes() {
    if (!crm_v8() || !categorias_todas()) return 0;
    $pdo = db();
    $tours = $pdo->query('SELECT cliente_id, COUNT(*) FROM tour_vendas GROUP BY cliente_id')->fetchAll(PDO::FETCH_KEY_PAIR);
    $por = [];
    foreach ($pdo->query('SELECT id, nome, tipo_pessoa, origem FROM clientes WHERE categoria_id IS NULL')->fetchAll() as $c)
        if ($cat = categoria_sugerida($c, (int)($tours[$c['id']] ?? 0))) $por[$cat][] = (int)$c['id'];
    if (!$por) return 0;
    $pdo->beginTransaction();
    $n = 0;
    foreach ($por as $cat => $ids) foreach (array_chunk($ids, 400) as $lote) { $pdo->exec('UPDATE clientes SET categoria_id = ' . (int)$cat . ', categoria_auto = 1 WHERE categoria_id IS NULL AND id IN (' . implode(',', $lote) . ')'); $n += count($lote); }
    $pdo->commit();
    registrar_log('cliente', 0, 'Categoria automática', $n . ' cliente(s) sem categoria receberam uma sugestão (marcada como automática para revisão)');
    return $n;
}

require_once __DIR__ . '/grupos.php';
require_once __DIR__ . '/vendas.php';
