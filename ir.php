<?php
/** Redireciona para o link de afiliado e conta o clique. */
require __DIR__ . '/src/bootstrap.php';

$st = db()->prepare('SELECT * FROM products WHERE id = ?');
$st->execute([(int)($_GET['id'] ?? 0)]);
$p = $st->fetch();
if (!$p) redirect(url(''));

$ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
$isBot = $ua === '' || preg_match('/bot|crawl|spider|preview|facebookexternalhit|whatsapp|telegram|slurp/', $ua);
if (!$isBot) {
    db()->prepare('UPDATE products SET clicks = clicks + 1 WHERE id = ?')->execute([$p['id']]);
}

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
redirect(product_destination($p) ?: url(''));
