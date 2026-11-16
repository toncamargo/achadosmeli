<?php
/** Gera o /ads.txt exigido pelo Google AdSense a partir do ID de editor salvo no admin. */
require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/src/ads.php';
header('Content-Type: text/plain; charset=utf-8');
$c = adsense_client();
echo $c ? 'google.com, ' . substr($c, 3) . ", DIRECT, f08c47fec0942fa0\n" : "# AdSense ainda não configurado\n";
