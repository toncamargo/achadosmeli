<?php
/**
 * Responde comentários do Instagram consultando os posts cadastrados e renova o token quando
 * faltar pouco para vencer. Funciona sem o webhook (acesso padrão da Meta). Cron a cada 5 min:
 *   *\/5 * * * *  php /caminho/para/achadosmeli/cron/instagram.php
 * Limite da Meta: 200 chamadas/hora — por isso só os 10 posts ativos mais recentes.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require __DIR__ . '/../src/bootstrap.php';

if (!Instagram::isConnected()) { echo date('c') . " - Instagram não conectado\n"; exit; }

try {
    if ($msg = Instagram::refreshToken()) echo date('c') . " - $msg\n";
} catch (Throwable $ex) {
    fwrite(STDERR, date('c') . ' - ' . $ex->getMessage() . "\n");
}

$r = Instagram::poll(10);
echo date('c') . " - {$r['posts']} post(s) verificados\n";
foreach ($r['log'] as $line) echo "  $line\n";
