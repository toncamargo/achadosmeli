<?php
require __DIR__ . '/../src/bootstrap.php';
require_admin();

$code = (string)($_GET['code'] ?? '');
$state = (string)($_GET['state'] ?? '');

if (!$code || !$state || !hash_equals((string)($_SESSION['ml_oauth_state'] ?? ''), $state)) {
    flash('Retorno do Mercado Livre inválido. Tente conectar de novo.', 'err');
    redirect(url('admin/configuracoes.php'));
}
unset($_SESSION['ml_oauth_state']);

try {
    MercadoLivre::exchangeCode($code);
    flash('Conta Mercado Livre conectada!');
} catch (Throwable $ex) {
    flash('Erro ao conectar: ' . $ex->getMessage(), 'err');
}
redirect(url('admin/configuracoes.php'));
