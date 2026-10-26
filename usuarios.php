<?php
require __DIR__ . '/inc/app.php';
exigir_login();
exigir_admin();
$pdo = db();
$eu = (int)$GLOBALS['USUARIO']['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    if ($id === $eu) { flash('Você não pode alterar o seu próprio acesso.', 'erro'); ir('usuarios.php'); }
    $acao = $_POST['acao'] ?? '';
    $alvo = linha('usuarios', $id);
    if (!$alvo) ir('usuarios.php');
    if ($acao === 'excluir') {
        $pdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$id]);
        registrar_log('usuario', $id, 'Exclusão de usuário', $alvo['nome'] . ' <' . $alvo['email'] . '>');
        flash('Usuário excluído.');
    } elseif ($acao === 'bloquear') {
        $pdo->prepare("UPDATE usuarios SET status = 'bloqueado' WHERE id = ?")->execute([$id]);
        registrar_alteracao('usuario', 'usuarios', $id, $alvo, 'Bloqueio de ' . $alvo['nome']);
        flash('Usuário bloqueado.');
    } elseif ($acao === 'liberar') {
        $p = implode(',', array_intersect(array_keys(lista_perfis()), (array)($_POST['perfis'] ?? [])));
        if ($p === '') {
            flash('Marque ao menos um perfil.', 'erro');
        } else {
            $pdo->prepare("UPDATE usuarios SET perfil = ?, status = 'ativo' WHERE id = ?")->execute([$p, $id]);
            registrar_alteracao('usuario', 'usuarios', $id, $alvo, 'Liberação/perfil de ' . $alvo['nome']);
            flash('Acesso liberado como ' . nome_perfil($p) . '.');
        }
    }
    ir('usuarios.php');
}

$lista = $pdo->query("SELECT * FROM usuarios ORDER BY FIELD(status,'pendente','ativo','bloqueado'), nome")->fetchAll();
$titulo = 'Controle de acesso';
require __DIR__ . '/inc/topo.php';
?>
<p class="sub">Cada módulo tem seus perfis e só quem tem o perfil vê o módulo no menu; o Administrador vê tudo. Tour: Cadastrador (inclui e altera tours e guias) e Consulta. CRM: Marketing (clientes, grupos de campanha, categorias e exportações) e Consulta. CD: Cadastrador (vinícolas, motoristas e entregas), Motorista (vê e confirma as próprias entregas) e Consulta. Sugestões da IA: Gerente geral (vê os alertas de todas as áreas), Gerente comercial B2B, Gerente comercial B2C e Gerente de enoturismo e eventos (cada um vê os da sua área e recebe os e-mails). Os perfis podem ser acumulados: um gerente que também deve ver vendas ou clientes precisa do perfil do módulo (por exemplo, CRM · Consulta).</p>
<div class="cartao rolagem">
<table>
  <thead><tr><th>Nome</th><th>E-mail</th><th>Situação</th><th>Perfil</th><th>Cadastro</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($lista as $u): ?>
    <tr>
      <td><?= e($u['nome']) ?><?= (int)$u['id'] === $eu ? ' (você)' : '' ?></td>
      <td><?= e($u['email']) ?></td>
      <td><span class="tag <?= $u['status'] === 'ativo' ? 'ok' : 'pend' ?>"><?= e(ucfirst($u['status'])) ?></span></td>
      <td><?= e(nome_perfil($u['perfil'])) ?></td>
      <td><?= data_br($u['criado_em']) ?></td>
      <td class="acoes">
      <?php if ((int)$u['id'] !== $eu): ?>
        <form method="post">
          <?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
          <span class="perfis">
          <?php foreach (lista_perfis() as $p => $rot): ?>
            <label><input type="checkbox" name="perfis[]" value="<?= $p ?>" <?= in_array($p, expandir_perfis($u['perfil']), true) ? 'checked' : '' ?>> <?= e($rot) ?></label>
          <?php endforeach; ?>
          </span>
          <button class="peq" name="acao" value="liberar"><?= $u['status'] === 'ativo' ? 'Alterar' : 'Liberar' ?></button>
          <?php if ($u['status'] !== 'bloqueado'): ?><button class="peq sec" name="acao" value="bloquear">Bloquear</button><?php endif; ?>
          <button class="peq perigo" name="acao" value="excluir" onclick="return confirm('Excluir este usuário?')">Excluir</button>
        </form>
      <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php require __DIR__ . '/inc/rodape.php'; ?>
