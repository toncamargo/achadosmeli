<?php
/**
 * Responde comentários do Instagram consultando os posts cadastrados, termina a publicação dos
 * Reels pendentes (admin/reels.php), busca as métricas (a cada 3 h) e renova o token quando
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

// Reels enviados pelo painel que ainda estão sendo processados pela Meta
foreach (Instagram::reelsPending() as $line) echo date('c') . " - $line\n";

// Métricas para o painel Insights: no máximo a cada 3 h (limite de 200 chamadas/hora da Meta)
try {
    $st = Instagram::syncStats(30, 180);
    if (!$st['pulado']) foreach ($st['log'] as $line) echo date('c') . " - métricas: $line\n";
} catch (Throwable $ex) {
    fwrite(STDERR, date('c') . ' - métricas: ' . $ex->getMessage() . "\n");
}

$r = Instagram::poll(10);
echo date('c') . " - {$r['posts']} post(s) verificados\n";
foreach ($r['log'] as $line) echo "  $line\n";
