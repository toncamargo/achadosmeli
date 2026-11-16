<?php
/**
 * Atualiza os preços de todos os produtos ativos.
 * Rode pelo cron da hospedagem, por exemplo a cada 3 horas:
 *   0 *\/3 * * *  php /caminho/para/achadosmeli/cron/atualizar-precos.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require __DIR__ . '/../src/bootstrap.php';

$ids = db()->query('SELECT ml_id FROM products WHERE active = 1')->fetchAll(PDO::FETCH_COLUMN);
try {
    $n = refresh_prices($ids);
    echo date('c') . " - $n de " . count($ids) . " produtos atualizados\n";
} catch (Throwable $ex) {
    fwrite(STDERR, date('c') . ' - ERRO: ' . $ex->getMessage() . "\n");
    exit(1);
}
