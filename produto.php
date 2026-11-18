<?php
/**
 * O site não tem página de produto (PDP).
 * Links antigos (produto.php?s=slug) levam direto ao Mercado Livre pelo ir.php,
 * que conta o clique e redireciona para o link de afiliado.
 */
require __DIR__ . '/src/bootstrap.php';

$st = db()->prepare('SELECT id FROM products WHERE slug = ? AND active = 1');
$st->execute([(string)($_GET['s'] ?? '')]);
$p = $st->fetch();

header('X-Robots-Tag: noindex, nofollow');
if (!$p) redirect(url(''));
http_response_code(301);
redirect(go_url($p, 'legado'));
