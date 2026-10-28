<?php
require __DIR__ . '/inc/app.php';
exigir_login();
exigir_admin();
ia_exigir_migracao();
$pdo = db();
$msgs = ['msg_tour_chegada' => 'Tour · boas-vindas (1 hora antes)', 'msg_tour_amanha' => 'Tour · confirmação na véspera', 'msg_tour_agradecimento' => 'Tour · agradecimento no dia seguinte',
    'msg_convite_clube' => 'Convite para o clube (visitantes)', 'msg_aniversario' => 'Aniversário', 'msg_recompra' => 'Recompra (recorrentes que sumiram)',
    'msg_segunda_compra' => 'Segunda compra', 'msg_b2b_reposicao' => 'B2B · reposição', 'msg_b2b_boas_vindas' => 'B2B · boas-vindas (primeira compra)'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $acao = $_POST['acao'] ?? '';
    if ($acao === 'ia') {
        $k = trim((string)($_POST['ia_chave_api'] ?? ''));
        if ($k !== '') config_salvar('ia_chave_api', $k);
        if (!empty($_POST['remover_chave'])) config_salvar('ia_chave_api', '');
        $mod = preg_replace('/[^a-z0-9.\-]/', '', strtolower((string)($_POST['ia_modelo'] ?? '')));
        config_salvar('ia_modelo', $mod ?: 'claude-sonnet-5-5');
        config_salvar('ia_emails', isset($_POST['ia_emails']) ? '1' : '0');
        config_salvar('email_limite_hora', (string)max(10, min(2000, (int)($_POST['email_limite_hora'] ?? 200))));
        config_salvar('email_lote', (string)max(5, min(100, (int)($_POST['email_lote'] ?? 25))));
        config_salvar('email_pausa', (string)max(5, min(600, (int)($_POST['email_pausa'] ?? 20))));
        config_salvar('email_resposta', filter_var(trim($_POST['email_resposta'] ?? ''), FILTER_VALIDATE_EMAIL) ? trim($_POST['email_resposta']) : '');
        registrar_log('ia', 0, 'Configuração da IA', 'modelo ' . config('ia_modelo') . ($k !== '' ? '; chave da API atualizada' : '') . (!empty($_POST['remover_chave']) ? '; chave removida' : '') . '; e-mails ' . (config('ia_emails') === '1' ? 'ligados' : 'desligados'));
        flash('Configuração da IA salva.'); ir('ia_config.php');
    }
    if ($acao === 'testar') {
        try { $r = ia_chamar_claude('Responda em uma frase curta.', 'Diga "Conexão com a IA funcionando" e mais nada.', 50); flash('Conexão ok. Resposta da IA: ' . trim($r['texto'])); }
        catch (Throwable $ex) { flash('Falha no teste: ' . $ex->getMessage(), 'erro'); }
        ir('ia_config.php');
    }
    if ($acao === 'mensagens') {
        foreach (array_merge(array_keys($msgs), ['endereco_vinicola', 'link_avaliacao', 'whatsapp_vinicola']) as $k) {
            $v = trim(str_replace("\r", '', (string)($_POST[$k] ?? '')));
            config_salvar($k, $v === trim(ia_config_padrao()[$k] ?? '') ? '' : mb_substr($v, 0, 1500));
        }
        registrar_log('ia', 0, 'Mensagens padrão', 'mensagens e dados da vinícola atualizados');
        flash('Mensagens salvas.'); ir('ia_config.php#mensagens');
    }
    if ($acao === 'data') {
        $id = (int)($_POST['id'] ?? 0);
        if (!empty($_POST['excluir'])) { $pdo->prepare('DELETE FROM ia_datas WHERE id = ?')->execute([$id]); registrar_log('ia', $id, 'Data comemorativa excluída', (string)($_POST['nome'] ?? '')); flash('Data excluída.'); ir('ia_config.php#datas'); }
        $tipo = $_POST['tipo_regra'] ?? 'fixa';
        $regra = 'fixa'; $mes = (int)($_POST['mes'] ?? 0) ?: null; $dia = (int)($_POST['dia'] ?? 0) ?: null;
        if ($tipo === 'outra') {
            $regra = trim((string)($_POST['regra'] ?? ''));
            if (!preg_match('/^(pascoa:-?\d+|nsemana:\d{1,2}:[0-6]:[1-5](:\+?-?\d+)?)$/', $regra)) { flash('Regra inválida. Exemplos: pascoa:-47 ou nsemana:5:0:2', 'erro'); ir('ia_config.php?data=' . $id . '#datas'); }
            $mes = $dia = null;
        } elseif (!$mes || !$dia || !checkdate($mes, $dia, 2028)) { flash('Informe dia e mês válidos.', 'erro'); ir('ia_config.php?data=' . $id . '#datas'); }
        $v = [mb_substr(texto_limpo($_POST['nome'] ?? ''), 0, 100), $regra, $mes, $dia, in_array($_POST['area'] ?? '', ['b2b', 'b2c', 'enoturismo'], true) ? $_POST['area'] : 'b2c',
            max(1, min(120, (int)($_POST['antecedencia'] ?? 21))), vazio_null(mb_substr(texto_limpo($_POST['grupo_nome'] ?? ''), 0, 80)), vazio_null(mb_substr(texto_limpo($_POST['sugestao'] ?? ''), 0, 500)), isset($_POST['ativo']) ? 1 : 0];
        if ($v[0] === '') { flash('Informe o nome da data.', 'erro'); ir('ia_config.php#datas'); }
        if ($id) $pdo->prepare('UPDATE ia_datas SET nome = ?, regra = ?, mes = ?, dia = ?, area = ?, antecedencia = ?, grupo_nome = ?, sugestao = ?, ativo = ? WHERE id = ?')->execute(array_merge($v, [$id]));
        else { $pdo->prepare('INSERT INTO ia_datas (nome, regra, mes, dia, area, antecedencia, grupo_nome, sugestao, ativo) VALUES (?,?,?,?,?,?,?,?,?)')->execute($v); $id = (int)$pdo->lastInsertId(); }
        registrar_log('ia', $id, 'Data comemorativa salva', $v[0]);
        flash('Data salva. Ela gera o alerta ' . $v[5] . ' dia(s) antes.'); ir('ia_config.php#datas');
    }
}

$chave = (string)config('ia_chave_api', '');
$datas = ia_q('SELECT * FROM ia_datas ORDER BY nome');
foreach ($datas as &$d) $d['_prox'] = ia_proxima_data($d);
unset($d);
usort($datas, fn($a, $b) => strcmp($a['_prox'] ?? '9999', $b['_prox'] ?? '9999'));
$ed = ['id' => 0, 'nome' => '', 'regra' => 'fixa', 'mes' => '', 'dia' => '', 'area' => 'b2c', 'antecedencia' => 21, 'grupo_nome' => '', 'sugestao' => '', 'ativo' => 1];
if (!empty($_GET['data'])) foreach ($datas as $d) if ((int)$d['id'] === (int)$_GET['data']) $ed = $d;
$grupos = crm_v7() ? array_column(grupos_todos(true), 'nome') : [];
$dest = ia_destinatarios();
$titulo = 'Sugestões da IA · Configuração';
require __DIR__ . '/inc/topo.php';
?>
<div class="cartao">
  <h2 style="margin-top:0">Inteligência artificial (análise semanal)</h2>
  <p class="sub">A análise usa a API da Anthropic (Claude). Crie uma chave em <strong>console.anthropic.com</strong> › API Keys e cole abaixo. Vão para a IA só números agregados do sistema (vendas, tours, grupos, entregas) e nomes de empresas clientes; nomes, CPF, telefones e e-mails de pessoas não são enviados. O custo de uma análise por semana fica em centavos de dólar.</p>
  <form method="post" class="grade">
    <?= csrf_campo() ?><input type="hidden" name="acao" value="ia">
    <label class="l2">Chave da API<input type="password" name="ia_chave_api" autocomplete="off" placeholder="<?= $chave ? 'configurada · termina em ' . e(substr($chave, -4)) . ' (deixe em branco para manter)' : 'sk-ant-...' ?>"></label>
    <label>Modelo<input name="ia_modelo" value="<?= e(config('ia_modelo')) ?>"></label>
    <label class="check"><input type="checkbox" name="ia_emails" <?= config('ia_emails') === '1' ? 'checked' : '' ?>> Enviar e-mails aos gerentes</label>
    <?php if ($chave): ?><label class="check"><input type="checkbox" name="remover_chave" value="1"> Remover a chave</label><?php endif; ?>
    <p class="l4 sub" style="margin:8px 0 0"><strong>Campanhas de e-mail</strong> · a hospedagem compartilhada limita quantos e-mails saem por hora; confira o limite do seu plano com a HostGator e deixe uma folga para os e-mails do dia a dia.</p>
    <label>Limite por hora<input type="number" name="email_limite_hora" min="10" max="2000" value="<?= (int)config('email_limite_hora', '200') ?>"></label>
    <label>E-mails por lote<input type="number" name="email_lote" min="5" max="100" value="<?= (int)config('email_lote', '25') ?>"></label>
    <label>Pausa entre lotes (s)<input type="number" name="email_pausa" min="5" max="600" value="<?= (int)config('email_pausa', '20') ?>"></label>
    <label>Responder para (e-mail)<input type="email" name="email_resposta" placeholder="<?= e(MAIL_DE) ?>" value="<?= e(config('email_resposta', '')) ?>"></label>
    <div class="l4 acoes"><button>Salvar</button></div>
  </form>
  <?php if ($chave): ?><form method="post" class="acoes"><?= csrf_campo() ?><button class="peq sec" name="acao" value="testar">Testar conexão com a IA</button></form><?php endif; ?>
  <p class="sub" style="margin-top:12px">E-mails: <?= MAIL_ATIVO ? 'envio ligado no servidor' : '<strong>o envio de e-mail está desligado no config.php (MAIL_ATIVO)</strong>' ?>. Recebem os alertas os usuários com perfil de gerente:
    <?= $dest ? e(implode('; ', array_map(fn($u) => $u['nome'] . ' (' . implode(', ', array_map(fn($a) => ia_areas()[$a], $u['areas'])) . ')', $dest))) : '<strong>nenhum ainda</strong> — dê os perfis de gerente em <a href="usuarios.php">Controle de acesso</a>' ?>.</p>
</div>

<div class="cartao" id="mensagens">
  <h2 style="margin-top:0">Mensagens prontas do WhatsApp</h2>
  <p class="sub">Use {nome}, {horario}, {data}, {endereco} e {link_avaliacao}; o sistema troca pelos dados de cada cliente. Para empresas, {nome} é o nome da empresa. Deixe como está para usar o texto padrão.</p>
  <form method="post" class="grade">
    <?= csrf_campo() ?><input type="hidden" name="acao" value="mensagens">
    <label class="l2">Endereço / como chegar ({endereco})<input name="endereco_vinicola" maxlength="300" value="<?= e(config('endereco_vinicola')) ?>"></label>
    <label class="l2">WhatsApp da vinícola (botão do cartão das campanhas)<input name="whatsapp_vinicola" maxlength="20" placeholder="61999990000" value="<?= e(config('whatsapp_vinicola', '')) ?>"></label>
    <label class="l2">Link de avaliação no Google ({link_avaliacao})<input name="link_avaliacao" maxlength="300" placeholder="https://g.page/r/..." value="<?= e(config('link_avaliacao', '')) ?>"></label>
    <?php foreach ($msgs as $k => $rot): ?><label class="l2"><?= e($rot) ?><textarea name="<?= $k ?>" rows="4"><?= e(config($k)) ?></textarea></label><?php endforeach; ?>
    <div class="l4 acoes"><button>Salvar mensagens</button></div>
  </form>
</div>

<div class="cartao" id="datas">
  <h2 style="margin-top:0">Datas comemorativas e feriados</h2>
  <p class="sub">Cada data gera um alerta para a área escolhida, com a antecedência definida e o grupo de campanha sugerido.</p>
  <details <?= $ed['id'] || !empty($_GET['nova']) ? 'open' : '' ?>><summary><?= $ed['id'] ? 'Editar: ' . e($ed['nome']) : 'Nova data' ?></summary>
  <form method="post" class="grade">
    <?= csrf_campo() ?><input type="hidden" name="acao" value="data"><input type="hidden" name="id" value="<?= (int)$ed['id'] ?>">
    <label class="l2">Nome*<input name="nome" required maxlength="100" value="<?= e($ed['nome']) ?>"></label>
    <label>Área<select name="area"><?php foreach (['b2c' => 'Comercial B2C', 'b2b' => 'Comercial B2B', 'enoturismo' => 'Enoturismo e eventos'] as $k => $v): ?><option value="<?= $k ?>" <?= $ed['area'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></label>
    <label>Avisar com (dias)<input type="number" name="antecedencia" min="1" max="120" value="<?= (int)$ed['antecedencia'] ?>"></label>
    <label>Quando<select name="tipo_regra" id="tr"><option value="fixa" <?= $ed['regra'] === 'fixa' ? 'selected' : '' ?>>Data fixa</option><option value="outra" <?= $ed['regra'] !== 'fixa' ? 'selected' : '' ?>>Data móvel (regra)</option></select></label>
    <label class="r-fixa">Dia<input type="number" name="dia" min="1" max="31" value="<?= e($ed['dia']) ?>"></label>
    <label class="r-fixa">Mês<input type="number" name="mes" min="1" max="12" value="<?= e($ed['mes']) ?>"></label>
    <label class="r-outra l2">Regra<input name="regra" value="<?= $ed['regra'] !== 'fixa' ? e($ed['regra']) : '' ?>" placeholder="pascoa:-47 · nsemana:5:0:2"></label>
    <label class="l2">Grupo de campanha sugerido<input name="grupo_nome" list="grupos-l" value="<?= e($ed['grupo_nome']) ?>"><datalist id="grupos-l"><?php foreach ($grupos as $g): ?><option value="<?= e($g) ?>"><?php endforeach; ?></datalist></label>
    <label class="l4">Sugestão de ação<input name="sugestao" maxlength="500" value="<?= e($ed['sugestao']) ?>"></label>
    <label class="check"><input type="checkbox" name="ativo" <?= $ed['ativo'] ? 'checked' : '' ?>> Ativa</label>
    <p class="l4 sub r-outra" style="margin:0">Regras: <code>pascoa:N</code> = N dias depois da Páscoa (negativo = antes; Carnaval = pascoa:-47, Corpus Christi = pascoa:60). <code>nsemana:MÊS:DIA:N</code> = N-ésimo dia da semana do mês, com 0 = domingo (Dia das Mães = nsemana:5:0:2). Acrescente <code>:+1</code> para deslocar dias.</p>
    <div class="l4 acoes"><button>Salvar data</button><?php if ($ed['id']): ?><a class="btn sec" href="ia_config.php#datas">Cancelar</a><button class="perigo" name="excluir" value="1" onclick="return confirm('Excluir esta data?')">Excluir</button><?php endif; ?></div>
  </form></details>
  <div class="rolagem"><table>
    <thead><tr><th>Próxima</th><th>Data</th><th>Área</th><th class="num">Avisa com</th><th>Grupo sugerido</th><th></th></tr></thead>
    <tbody><?php foreach ($datas as $d): ?>
      <tr class="<?= $d['ativo'] ? '' : 'inativo' ?>"><td><?= $d['_prox'] ? data_br($d['_prox']) . ' <span class="sub">' . e(dia_semana($d['_prox'])) . '</span>' : '—' ?></td><td class="nome"><strong><?= e($d['nome']) ?></strong><?= $d['ativo'] ? '' : ' <span class="tag pend">inativa</span>' ?><br><span class="sub"><?= e($d['sugestao']) ?></span></td>
        <td><?= e(ia_areas()[$d['area']]) ?></td><td class="num"><?= (int)$d['antecedencia'] ?> dias</td><td><?= e($d['grupo_nome']) ?><?= $d['grupo_nome'] && !in_array($d['grupo_nome'], $grupos, true) ? ' <span class="tag pend">grupo não existe</span>' : '' ?></td>
        <td><a class="btn peq sec" href="ia_config.php?data=<?= (int)$d['id'] ?>#datas">Editar</a></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <p><a class="btn peq sec" href="ia_config.php?nova=1#datas">Nova data</a></p>
</div>

<div class="cartao">
  <h2 style="margin-top:0">Tarefas automáticas (cPanel › Tarefas Cron)</h2>
  <p class="sub">Sem elas, os alertas são atualizados quando alguém abre a tela, mas os e-mails e a análise semanal não saem sozinhos. Cadastre as linhas abaixo (a última envia, em lotes, as campanhas de e-mail marcadas como "enviar sozinho"):</p>
  <pre class="cron">0 * * * *   php <?= e(__DIR__) ?>/cron_ia.php alertas
0 7 * * *   php <?= e(__DIR__) ?>/cron_ia.php diario
30 7 * * 1  php <?= e(__DIR__) ?>/cron_ia.php semanal
*/15 * * * * php <?= e(__DIR__) ?>/cron_ia.php emails</pre>
  <p class="sub">Última atualização dos alertas: <?= config('ia_ultima_regra', '') ? date('d/m/Y H:i', strtotime(config('ia_ultima_regra', ''))) : 'nunca' ?> · último e-mail do dia: <?= config('ia_email_diario_em', '') ? data_br(config('ia_email_diario_em', '')) : 'nunca' ?>.</p>
</div>
<script>
(function () { var t = document.getElementById('tr'); function v() { document.querySelectorAll('.r-fixa').forEach(function (e) { e.style.display = t.value === 'fixa' ? '' : 'none'; }); document.querySelectorAll('.r-outra').forEach(function (e) { e.style.display = t.value === 'fixa' ? 'none' : ''; }); } t.addEventListener('change', v); v(); })();
</script>
<?php require __DIR__ . '/inc/rodape.php'; ?>
