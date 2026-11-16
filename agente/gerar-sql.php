<?php
/**
 * Gera o SQL de gravação de uma coleta (sem conectar no banco).
 *   php agente/gerar-sql.php agente/coletas/2026-11-16.json            → SQL completo
 *   php agente/gerar-sql.php agente/coletas/2026-11-16.json --basico   → só colunas antigas (antes da migração)
 * Os comandos saem separados por uma linha contendo apenas ";;" (rode um por vez).
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require __DIR__ . '/lib.php';
date_default_timezone_set('America/Sao_Paulo');

$file = $argv[1] ?? '';
if (!is_file($file)) { fwrite(STDERR, "uso: php agente/gerar-sql.php ARQUIVO.json [--basico]\n"); exit(1); }
$completo = !in_array('--basico', $argv, true);
$agora = date('Y-m-d H:i:s');

$out = [];
foreach (agente_ler_arquivo($file) as $p) {
    if ($err = agente_validar($p)) { fwrite(STDERR, ($p['ml_id'] ?? '?') . ': ' . implode(', ', $err) . " (ignorado)\n"); continue; }
    foreach (agente_comandos($p, $agora, $completo) as [$sql, $params]) $out[] = agente_sql_literal($sql, $params);
}
echo implode("\n;;\n", $out), "\n";
