<?php
require __DIR__ . '/inc/app.php';
if (!empty($_SESSION['uid'])) { $u = linha('usuarios', $_SESSION['uid']); if ($u) registrar_log('acesso', $u['id'], 'Logout', '', $u); }
$_SESSION = [];
session_destroy();
ir('index.php');
