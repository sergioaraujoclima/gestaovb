<?php
// Aba "E-mail" da configuração do grupo (incluída por crm_grupos.php; usa $ver, $pdo e $k).
if (!camp_v12()): ?>
<div class="aviso erro">Para configurar e-mails de campanha, rode no phpMyAdmin a migração v12 (migracao_v12.sql).</div>
<?php return; endif;
$campE = camp_do_grupo($ver['id'], 'email');
$ce = $campE ? camp_contagem($campE) : null;
$podeCamp = pode_gerir_grupos();
$histE = $pdo->query("SELECT * FROM crm_campanhas WHERE grupo_id = " . (int)$ver['id'] . " AND canal = 'email' AND status = 'encerrada' ORDER BY id DESC LIMIT 10")->fetchAll();
$pct = fn($a, $b) => $b ? round($a * 100 / $b) . '%' : '—';
// Calibragem: quantos clientes com e-mail seriam atingidos (com e sem "só quem autorizou"), para a previsão ao vivo.
$v13 = function_exists('camp_v13') && camp_v13();
$agE = $campE && $v13 ? camp_agenda($campE, $ce) : null;
$alvo = ['todos' => 0, 'optin' => 0];
if ($v13) {
    $base = ['id' => (int)($campE['id'] ?? 0), 'grupo_id' => (int)$ver['id']];
    $alvo['todos'] = count(camp_publico_email($base + ['so_optin' => 0])['fila']);
    $alvo['optin'] = count(camp_publico_email($base + ['so_optin' => 1])['fila']);
}
$cronCmd = 'php ' . dirname(__DIR__) . '/cron_ia.php emails';
$previa = null;
if ($campE && ($campE['html'] || $campE['assunto'])) { [$asPrev, $previa] = email_montar($campE, ['id' => 0, 'nome' => 'Maria da Silva', 'tipo_pessoa' => 'PF', 'cidade' => 'Brasília'], false); }
?>
<section class="cartao campanha email" id="campanha">
  <div class="ficha-topo"><strong>E-mail da campanha</strong>
    <?php if ($campE): ?><span class="tag <?= $campE['status'] === 'liberada' ? 'ok' : 'neutra' ?>"><?= e(nome_status_campanha($campE['status'])) ?></span><?php endif; ?>
  </div>
  <?php if ($campE && ($pq = camp_por_que_nao_aparece($campE))): ?><p class="aviso erro">Não aparece em Alertas e sugestões: <?= e($pq) ?></p><?php elseif ($campE && $campE['status'] === 'liberada'): ?><p class="aviso camp-ir">Aparece em <a href="ia.php">Sugestões da IA › Alertas e sugestões</a> com <?= (int)$ce['pendentes'] ?> cliente(s) para enviar. <a class="btn peq" href="ia_email.php?c=<?= (int)$campE['id'] ?>">Ir para o envio ▶</a></p><?php endif; ?>
  <?php if ($ce): ?>
  <div class="cards">
    <div class="card"><span>Enviados</span><strong><?= $ce['enviados'] ?></strong><small><?= $ce['pendentes'] ?> na fila</small></div>
    <div class="card"><span>Abriram</span><strong><?= $pct($ce['aberturas'], $ce['enviados']) ?></strong><small><?= $ce['aberturas'] ?> pessoa(s)</small></div>
    <div class="card"><span>Clicaram</span><strong><?= $pct($ce['cliques'], $ce['enviados']) ?></strong><small><?= $ce['cliques'] ?> pessoa(s)</small></div>
    <div class="card<?= $ce['falhas'] ? ' alerta' : '' ?>"><span>Falhas</span><strong><?= $ce['falhas'] ?></strong><small><?= $ce['sem_email'] ?> sem e-mail · <?= $ce['recusaram'] ?> recusaram<?= $campE['so_optin'] ? ' · ' . $ce['sem_optin'] . ' sem autorização' : '' ?></small></div>
  </div>
  <?php endif; ?>
  <p class="sub">Configure aqui o e-mail do grupo. Ao <strong>liberar para envio</strong>, ele aparece em <a href="ia.php">Sugestões da IA › Alertas e sugestões</a>, onde é enviado em lotes (a hospedagem limita quantos e-mails saem por hora). Cada e-mail sai personalizado, com o link de descadastro exigido pela LGPD, e o sistema mede quem abriu e quem clicou.</p>
  <?php if ($podeCamp): ?>
  <form method="post" enctype="multipart/form-data" id="form-email">
    <?= csrf_campo() ?><input type="hidden" name="grupo_id" value="<?= (int)$ver['id'] ?>"><input type="hidden" name="campanha_id" value="<?= (int)($campE['id'] ?? 0) ?>"><input type="hidden" name="canal" value="email">
    <div class="grade">
      <label class="l2">Nome da campanha (uso interno)<input name="titulo" maxlength="120" value="<?= e($campE['titulo'] ?? $ver['nome'] . ' · e-mail · ' . rotulo_mes(date('Y-m'))) ?>"></label>
      <label>Quem acompanha<select name="area"><?php foreach (['b2c' => 'Comercial B2C', 'b2b' => 'Comercial B2B', 'enoturismo' => 'Enoturismo e eventos'] as $kk => $vv): ?><option value="<?= $kk ?>" <?= ($campE['area'] ?? 'b2c') === $kk ? 'selected' : '' ?>><?= $vv ?></option><?php endforeach; ?></select></label>
      <label>Nome do remetente<input name="remetente" maxlength="80" placeholder="<?= e(MAIL_NOME) ?>" value="<?= e($campE['remetente'] ?? '') ?>"></label>
      <label class="l3">Assunto*<input name="assunto" maxlength="200" placeholder="ex.: {nome}, o presente perfeito para o Dia das Mães 🌹" value="<?= e($campE['assunto'] ?? '') ?>"></label>
      <label>Cor do e-mail<input type="color" name="cor" value="<?= e($campE['cor'] ?? '#5c1a2b') ?>"></label>
      <label class="l4">Texto de pré-visualização <span class="sub">(aparece ao lado do assunto na caixa de entrada)</span><input name="preheader" maxlength="200" placeholder="ex.: Kit com rosé e taça gravada, entrega grátis em Brasília" value="<?= e($campE['preheader'] ?? '') ?>"></label>
      <label class="l2">Imagem do topo (banner) <input type="file" name="imagem" accept="image/jpeg,image/png,image/webp"><span class="sub">Largura ideal 1200 px; o sistema ajusta.</span></label>
      <?php if ($campE && $campE['imagem']): ?><div class="l2 camp-mini"><img src="<?= e(camp_url_imagem($campE)) ?>" alt=""><button class="peq perigo" name="acao" value="camp_remover_imagem" formnovalidate onclick="return confirm('Remover a imagem do topo?')">Remover imagem</button></div><?php endif; ?>
    </div>
    <span class="rot-editor">Texto do e-mail</span>
    <?= editor_email('html', $campE['html'] ?? '', $campE ? ['url' => 'crm_grupos.php', 'campos' => ['acao' => 'camp_img_corpo', 'grupo_id' => (int)$ver['id'], 'campanha_id' => (int)$campE['id'], 'canal' => 'email']] : null) ?>
    <div class="grade">
      <label class="l2">Botão principal · texto<input name="botao_texto" maxlength="80" placeholder="ex.: Quero o meu kit" value="<?= e($campE['botao_texto'] ?? '') ?>"></label>
      <label class="l2">Botão principal · link<input name="botao_link" maxlength="300" placeholder="https://..." value="<?= e($campE['botao_link'] ?? '') ?>"></label>
      <label class="check l2"><input type="checkbox" name="so_optin" <?= !empty($campE['so_optin']) ? 'checked' : '' ?>> Só quem autorizou e-mail de marketing</label>
      <?php if (!$v13): ?>
      <label class="check l2"><input type="checkbox" name="auto_envio" <?= !empty($campE['auto_envio']) ? 'checked' : '' ?>> Enviar sozinho, em lotes, pelo agendamento (sem ninguém precisar clicar)</label>
      <label>Começar em<input type="date" name="inicio" value="<?= e($campE['inicio'] ?? date('Y-m-d')) ?>"></label>
      <?php endif; ?>
      <label>Encerrar em (opcional)<input type="date" name="fim" value="<?= e($campE['fim'] ?? '') ?>"></label>
    </div>
    <?php if ($v13): $diVal = $campE && $campE['disparo_inicio'] ? date('Y-m-d\TH:i', strtotime($campE['disparo_inicio'])) : ''; ?>
    <fieldset class="calibra" id="calibragem">
      <legend>Calibragem do disparo</legend>
      <p class="sub">Os e-mails saem sozinhos, um de cada vez: o primeiro na data e hora de início e os seguintes a cada intervalo. Obrigatório para liberar o envio.</p>
      <div class="grade">
        <label>Iniciar disparo em<input type="datetime-local" name="disparo_inicio" id="cal-ini" value="<?= e($diVal) ?>"></label>
        <label>Intervalo entre e-mails (minutos)<input type="number" name="disparo_intervalo" id="cal-int" min="2" max="1440" step="1" value="<?= e((string)($campE['disparo_intervalo'] ?? 5)) ?>"></label>
        <div class="l2 cal-prev" id="cal-prev" aria-live="polite"></div>
      </div>
      <?php if ($agE): ?><p class="cal-status st-<?= e($agE['situacao']) ?>"><strong><?= e(camp_agenda_rotulo($agE['situacao'])) ?>:</strong> <?= e(camp_agenda_resumo($agE)) ?>
        <?php if (in_array($agE['situacao'], ['enviando', 'programada'], true)): ?><button class="peq sec" name="acao" value="camp_pausar" formnovalidate>Pausar disparo</button><?php elseif ($agE['situacao'] === 'pausada'): ?><button class="peq" name="acao" value="camp_retomar" formnovalidate>Retomar disparo</button><?php endif; ?></p>
        <?php if ($agE['cron_parado']): ?><p class="aviso erro">O disparo deveria estar andando, mas a tarefa agendada não roda há mais de 40 minutos<?= $agE['cron'] ? ' (última vez: ' . e(data_hora_curta($agE['cron'])) . ')' : ' (nunca rodou)' ?>. Confira no cPanel › Trabalhos Cron a linha <code><?= e($cronCmd) ?></code> a cada 15 minutos.</p><?php endif; ?>
      <?php endif; ?>
      <details class="dicas"><summary>Como o disparo acontece</summary><ul>
        <li>Quem envia é a tarefa agendada do servidor (cPanel › <strong>Trabalhos Cron</strong>), com a linha <code>*/15 * * * * <?= e($cronCmd) ?></code>. A cada rodada ela envia os e-mails que já "venceram" no cronograma; com intervalo de 2 minutos, saem uns 7 por rodada, com uma pausa curta entre eles. O ritmo médio e o término previsto seguem a calibragem.</li>
        <li>O limite de e-mails por hora (Sugestões da IA › Configuração) continua valendo: se a calibragem pedir mais do que o limite, o disparo segura e continua na hora seguinte.</li>
        <li>Quem entra no grupo depois de começar também recebe, no fim da fila. Quem se descadastra sai na hora.</li>
      </ul></details>
    </fieldset>
    <script>
    (function () {
      var N = <?= json_encode($alvo) ?>, ini = document.getElementById('cal-ini'), iv = document.getElementById('cal-int'), out = document.getElementById('cal-prev'),
          opt = document.querySelector('#form-email [name=so_optin]');
      function fmt(d) { var p = function (x) { return ('0' + x).slice(-2); }; return p(d.getDate()) + '/' + p(d.getMonth() + 1) + ' às ' + p(d.getHours()) + ':' + p(d.getMinutes()); }
      function dur(min) { var h = Math.floor(min / 60), m = Math.round(min % 60); return (h ? h + ' h ' : '') + (m || !h ? m + ' min' : ''); }
      function calc() {
        var n = opt && opt.checked ? N.optin : N.todos, m = Math.max(2, parseInt(iv.value, 10) || 0);
        if (!n) { out.innerHTML = '<strong>0 clientes com e-mail</strong> seriam atingidos neste grupo.'; return; }
        if (!ini.value) { out.innerHTML = '<strong>' + n + ' cliente(s) com e-mail</strong> serão atingidos. Informe a data e a hora de início para ver quando termina.'; return; }
        var a = new Date(ini.value), agora = new Date(), jaComecou = a < agora;
        if (jaComecou) a = agora;   // disparo em andamento (ou início no passado): conta a partir de agora
        var f = new Date(a.getTime() + (n - 1) * m * 60000);
        out.innerHTML = '<strong>' + n + ' cliente(s) com e-mail</strong> ' + (jaComecou ? 'ainda na fila' : 'serão atingidos') + ' · ' + (jaComecou ? 'a partir de agora' : 'primeiro envio ' + fmt(a)) + ' · último por volta de <strong>' + fmt(f) + '</strong> (' + dur((n - 1) * m) + ' de disparo, ' + Math.floor(60 / m) + ' por hora)';
      }
      [ini, iv].forEach(function (x) { x.addEventListener('input', calc); });
      if (opt) opt.addEventListener('change', calc);
      calc();
    })();
    </script>
    <?php endif; ?>
    <div class="acoes" style="flex-wrap:wrap;margin-top:12px">
      <button class="sec" name="acao" value="camp_salvar">Salvar rascunho</button>
      <button name="acao" value="camp_liberar" onclick="return confirm('Liberar este e-mail para envio?')"><?= ($campE['status'] ?? '') === 'liberada' ? 'Salvar alterações (já liberado)' : 'Salvar e liberar para envio' ?></button>
      <?php if ($campE): ?>
        <span class="camp-teste"><input type="email" name="teste_para" value="<?= e($GLOBALS['USUARIO']['email'] ?? '') ?>" placeholder="e-mail para o teste" title="Endereço que recebe o teste"><button class="sec" name="acao" value="camp_teste_email" formnovalidate title="Envia a última versão salva para o endereço ao lado (não conta como envio da campanha)">Enviar teste</button></span>
        <?php if ($ce && $ce['falhas']): ?><button class="sec" name="acao" value="camp_reenviar_falhas" formnovalidate>Tentar de novo as <?= $ce['falhas'] ?> falha(s)</button><?php endif; ?>
        <button class="perigo" name="acao" value="camp_encerrar" formnovalidate onclick="return confirm('Encerrar esta campanha de e-mail? Os envios param; o histórico fica guardado.')">Encerrar campanha</button>
      <?php endif; ?>
    </div>
  </form>
  <?php endif; ?>
  <?php if ($previa): ?>
  <h2>Prévia do e-mail <small>versão salva · como chega para "Maria da Silva" · assunto: <?= e($asPrev) ?></small></h2>
  <iframe class="em-previa" sandbox="allow-popups allow-popups-to-escape-sandbox" srcdoc="<?= e($previa) ?>" title="Prévia do e-mail"></iframe>
  <?php if ($campE['status'] === 'liberada' && $ce && $ce['pendentes']): ?><p class="acoes"><a class="btn" href="ia_email.php?c=<?= (int)$campE['id'] ?>">Ir para o envio (<?= (int)$ce['pendentes'] ?> na fila) ▶</a></p><?php elseif ($campE['status'] !== 'liberada'): ?><p class="sub">Esta é só a prévia. Para enviar aos clientes do grupo, clique em <strong>Salvar e liberar para envio</strong>; o envio é feito em Sugestões da IA › Alertas e sugestões.</p><?php endif; ?>
  <?php endif; ?>
  <details class="dicas"><summary>Para os e-mails chegarem na caixa de entrada (e não no spam)</summary>
    <ul>
      <li>Confira <?= eh_admin() ? '<a href="ia_config.php#diag-email">' : '' ?>Sugestões da IA › Configuração › Diagnóstico do e-mail<?= eh_admin() ? '</a>' : '' ?>: mostra se o domínio tem <strong>SPF e DKIM</strong> e onde ajustar. Sem isso, Gmail e Outlook mandam muita coisa para o spam (ou descartam).</li>
      <li>O e-mail já sai com versão em texto, o link "não quero mais receber" e o descadastro de um clique que o Gmail e o Yahoo exigem de quem envia em quantidade. Quem se descadastra fica marcado na ficha como "não aceita e-mail" e sai dos próximos envios.</li>
      <li>Assunto curto e pessoal ({nome} ajuda), texto de verdade além das imagens e um único botão principal costumam ter mais abertura e clique.</li>
      <li>A hospedagem compartilhada limita quantos e-mails saem por hora: o envio é feito em lotes dentro desse limite (ajuste em Sugestões da IA › Configuração). Para bases grandes, um serviço de e-mail marketing dedicado entrega melhor.</li>
      <li>A abertura é medida por uma imagem invisível; alguns programas de e-mail bloqueiam imagens, então o número real de aberturas tende a ser maior que o mostrado.</li>
    </ul>
  </details>
  <?php if ($histE): ?>
  <h2>Campanhas de e-mail anteriores</h2>
  <div class="rolagem"><table class="mini"><thead><tr><th>Campanha</th><th>Liberada em</th><th class="num">Enviados</th><th class="num">Abriram</th><th class="num">Clicaram</th></tr></thead><tbody>
    <?php foreach ($histE as $h): $st = $pdo->query("SELECT SUM(CASE WHEN situacao = 'enviado' THEN 1 ELSE 0 END) env, SUM(CASE WHEN aberto_em IS NOT NULL THEN 1 ELSE 0 END) ab, SUM(CASE WHEN clicado_em IS NOT NULL THEN 1 ELSE 0 END) cl FROM crm_campanha_envios WHERE campanha_id = " . (int)$h['id'])->fetch(); ?>
    <tr><td class="nome"><?= e($h['titulo']) ?><br><span class="det-rk"><?= e($h['assunto']) ?></span></td><td><?= $h['liberada_em'] ? date('d/m/Y', strtotime($h['liberada_em'])) : '—' ?></td>
      <td class="num"><?= (int)$st['env'] ?></td><td class="num"><?= $pct((int)$st['ab'], (int)$st['env']) ?></td><td class="num"><?= $pct((int)$st['cl'], (int)$st['env']) ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
  <?php endif; ?>
</section>
