<?php
$pg = basename($_SERVER['PHP_SELF']); $f = flash();
$pend = eh_admin() ? (int)db()->query("SELECT COUNT(*) FROM usuarios WHERE status = 'pendente'")->fetchColumn() : 0;
// Menu por módulo: cada módulo só aparece para quem tem um perfil dele (o Administrador vê todos).
$modulos = [];
if (pode_ver_ia()) {
    $modulos['Sugestões da IA'] = ['ia.php' => 'Alertas e sugestões'];
    if (eh_admin()) $modulos['Sugestões da IA']['ia_config.php'] = 'Configuração';
}
if (pode_ver_tours()) $modulos['Tour'] = ['painel.php' => 'Painel', 'tours.php' => 'Tours vendidos', 'relatorio.php' => 'Relatório mensal', 'guias.php' => 'Guias / Sommeliers'];
if (pode_ver_crm()) {
    $modulos['CRM'] = ['crm_painel.php' => 'Painel de clientes', 'clientes.php' => 'Clientes', 'crm_grupos.php' => 'Grupos de campanha'];
    if (pode_gerir_grupos()) $modulos['CRM']['crm_categorias.php'] = 'Categorias';
    if (eh_admin()) $modulos['CRM']['crm_importar.php'] = 'Importar clientes';
}
if (pode_ver_vendas()) {
    $modulos['Vendas'] = ['vendas_painel.php' => 'Painel de vendas', 'vendas_lista.php' => 'Vendas', 'vendas_produtos.php' => 'Produtos'];
    if (pode_importar_vendas()) $modulos['Vendas']['vendas_importar.php'] = 'Importar vendas';
}
if (pode_ver_cd()) {
    $modulos['Centro de Distribuição'] = ['cd_painel.php' => 'Entregas do dia', 'cd_entregas.php' => 'Entregas'];
    if (pode_ver_cd_gestao()) $modulos['Centro de Distribuição'] += ['cd_extrato.php' => 'Extrato mensal', 'cd_vinicolas.php' => 'Vinícolas', 'cd_motoristas.php' => 'Motoristas'];
}
if (eh_admin()) $modulos['Administração'] = ['usuarios.php' => 'Controle de acesso', 'auditoria.php' => 'Auditoria'];
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($titulo ?? APP_NOME) ?> · <?= e(APP_NOME) ?></title>
<link rel="stylesheet" href="assets/estilo.css?v=9.12">
</head>
<body>
<div class="app">
  <aside class="menu" id="menu">
    <div class="menu-topo">
      <div class="marca">Vinícola<br><strong>Brasília</strong></div>
      <button type="button" class="menu-botao" aria-expanded="false" aria-controls="menu-nav" onclick="var m=document.getElementById('menu');var a=m.classList.toggle('aberto');this.setAttribute('aria-expanded',a)">Menu</button>
    </div>
    <nav id="menu-nav">
      <?php foreach ($modulos as $modulo => $paginas): ?>
        <div class="modulo<?= isset($paginas[$pg]) ? ' atual' : '' ?>">
          <span class="modulo-titulo"><?= e($modulo) ?></span>
          <?php foreach ($paginas as $menuArq => $menuRot): ?>
            <a href="<?= $menuArq ?>" class="<?= $pg === $menuArq ? 'ativo' : '' ?>"><?= e($menuRot) ?><?php if ($menuArq === 'usuarios.php' && $pend): ?> <span class="selo"><?= $pend ?></span><?php endif; ?></a>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </nav>
    <div class="usuario"><?= e($GLOBALS['USUARIO']['nome'] ?? '') ?><br><small><?= e(nome_perfil(perfil())) ?></small><br><a href="sair.php">Sair</a></div>
  </aside>
  <main>
    <h1><?= e($titulo ?? '') ?></h1>
    <?php if ($f): ?><div class="aviso <?= e($f[1]) ?>"><?= e($f[0]) ?></div><?php endif; ?>
