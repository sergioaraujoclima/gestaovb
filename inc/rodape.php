    <footer class="versao"><?= e(APP_NOME) ?> · v<?= e(APP_VERSAO) ?><?php if (function_exists('eh_admin') && !empty($GLOBALS['USUARIO']) && eh_admin()): ?><span class="tempo-pagina">· página gerada em <?= number_format((microtime(true) - INICIO_PAGINA) * 1000, 0, ',', '.') ?> ms</span><?php endif; ?></footer>
  </main>
</div>
</body>
</html>
