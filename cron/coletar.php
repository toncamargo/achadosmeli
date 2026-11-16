<?php
/**
 * Coleta automática: roda as fontes ativas cujo intervalo já passou (Painel → Coleta automática).
 * Configure no cron da hospedagem, por exemplo de hora em hora:
 *   0 * * * *  php /caminho/para/achadosmeli/cron/coletar.php
 *
 * Opções:
 *   --todas      roda todas as fontes ativas, ignorando o intervalo
 *   --fonte=ID   roda só a fonte informada
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require __DIR__ . '/../src/bootstrap.php';

// evita duas coletas ao mesmo tempo (cron atrasado + execução manual)
$lock = fopen(sys_get_temp_dir() . '/achadosmeli-coletar.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo date('c') . " - outra coleta já está rodando, saindo.\n";
    exit(0);
}

$opts = getopt('', ['todas', 'fonte:']);
if (isset($opts['fonte'])) {
    $one = source_find((int)$opts['fonte']);
    $list = $one ? [$one] : [];
} elseif (isset($opts['todas'])) {
    $list = sources(true);
} else {
    $list = sources_due();
}

if (!$list) {
    echo date('c') . " - nenhuma fonte para rodar agora.\n";
    exit(0);
}

$fail = false;
foreach ($list as $src) {
    $r = collect_source($src);
    printf("%s - [%s] %d encontrados, %d novos, %d atualizados, %d fora dos filtros\n",
        date('c'), $src['name'], $r['found'], $r['created'], $r['updated'], $r['skipped']);
    foreach ($r['errors'] as $er) {
        fwrite(STDERR, date('c') . " - [{$src['name']}] ERRO: $er\n");
        $fail = true;
    }
}
exit($fail ? 1 : 0);
