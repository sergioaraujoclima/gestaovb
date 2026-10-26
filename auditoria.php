<?php
require __DIR__ . '/inc/app.php';
exigir_login();
exigir_admin();
$ent = $_GET['entidade'] ?? '';
$q = trim($_GET['q'] ?? '');
$sql = 'SELECT * FROM auditoria WHERE (usuario_nome LIKE ? OR operacao LIKE ? OR detalhes LIKE ?)';
$par = ["%$q%", "%$q%", "%$q%"];
if (in_array($ent, ['tour', 'cliente', 'guia', 'usuario', 'acesso', 'entrega', 'vinicola', 'motorista', 'importacao', 'grupo', 'categoria'], true)) { $sql .= ' AND entidade = ?'; $par[] = $ent; }
$st = db()->prepare($sql . ' ORDER BY id DESC LIMIT 500');
$st->execute($par);
$rot = ['tour' => 'Tour', 'cliente' => 'Cliente', 'guia' => 'Guia', 'usuario' => 'Usuário', 'acesso' => 'Acesso', 'entrega' => 'CD · Entrega', 'vinicola' => 'CD · Vinícola', 'motorista' => 'CD · Motorista', 'importacao' => 'Importação', 'grupo' => 'Grupo de campanha', 'categoria' => 'Categoria de cliente'];
$titulo = 'Auditoria';
require __DIR__ . '/inc/topo.php';
?>
<form method="get" class="filtro">
  <select name="entidade" style="width:auto">
    <option value="">Tudo</option>
    <?php foreach ($rot as $k => $r): ?><option value="<?= $k ?>" <?= $ent === $k ? 'selected' : '' ?>><?= $r ?></option><?php endforeach; ?>
  </select>
  <input name="q" placeholder="Buscar por usuário, operação ou detalhe" value="<?= e($q) ?>">
  <button type="submit">Filtrar</button>
</form>
<p class="sub">Últimos 500 registros. Inclui operações em registros já excluídos.</p>
<div class="cartao rolagem">
<table>
  <thead><tr><th>Data e hora</th><th>Quem fez</th><th>Módulo</th><th>Operação</th><th>Detalhes</th></tr></thead>
  <tbody>
  <?php $tem = false; foreach ($st as $l): $tem = true; ?>
    <tr>
      <td><?= date('d/m/Y H:i', strtotime($l['criado_em'])) ?></td>
      <td><?= e($l['usuario_nome']) ?></td>
      <td><?= e(($rot[$l['entidade']] ?? $l['entidade']) . ($l['entidade_id'] ? ' #' . $l['entidade_id'] : '')) ?></td>
      <td><?= e($l['operacao']) ?></td>
      <td class="quebra"><?= e($l['detalhes']) ?></td>
    </tr>
  <?php endforeach; if (!$tem): ?><tr><td colspan="5" class="vazio">Nenhum registro.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<?php require __DIR__ . '/inc/rodape.php'; ?>
