<?php
/**
 * Grava uma coleta no banco do site usando a conexão do .env (para quando há terminal na máquina).
 *   php agente/importar.php agente/coletas/2026-11-16.json
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require __DIR__ . '/../src/bootstrap.php'; // conecta e roda as migrações (cria as colunas do agente)
require __DIR__ . '/lib.php';

$file = $argv[1] ?? '';
if (!is_file($file)) { fwrite(STDERR, "uso: php agente/importar.php ARQUIVO.json\n"); exit(1); }

$pdo = db();
$agora = now();
$ok = $skip = 0;
foreach (agente_ler_arquivo($file) as $p) {
    if ($err = agente_validar($p)) { fwrite(STDERR, ($p['ml_id'] ?? '?') . ': ' . implode(', ', $err) . "\n"); $skip++; continue; }
    $pdo->beginTransaction();
    foreach (agente_comandos($p, $agora, true) as [$sql, $params]) $pdo->prepare($sql)->execute($params);
    $pdo->commit();
    $ok++;
}
echo "$ok produto(s) gravados, $skip ignorados.\n";
