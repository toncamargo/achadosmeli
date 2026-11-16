<?php
declare(strict_types=1);

/**
 * Leitor simples de arquivo .env (sem dependências).
 * Formato: CHAVE=valor  (aceita "aspas", 'aspas', comentários com # e linhas em branco).
 * Variáveis já definidas no ambiente do servidor têm prioridade sobre o arquivo.
 */
function load_env(string $file): void
{
    if (!is_file($file)) return;
    foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (str_starts_with($line, 'export ')) $line = substr($line, 7);
        $pos = strpos($line, '=');
        if ($pos === false) continue;

        $key = trim(substr($line, 0, $pos));
        $val = trim(substr($line, $pos + 1));

        if ($val !== '' && ($val[0] === '"' || $val[0] === "'")) {
            $q = $val[0];
            $end = strpos($val, $q, 1);
            $val = $end === false ? substr($val, 1) : substr($val, 1, $end - 1);
            if ($q === '"') $val = str_replace(['\n', '\"'], ["\n", '"'], $val);
        } else {
            $val = trim(preg_replace('/\s+#.*$/', '', $val)); // comentário no fim da linha
        }

        if (getenv($key) !== false) continue; // ambiente real vence
        putenv("$key=$val");
        $_ENV[$key] = $val;
    }
}

function env(string $key, mixed $default = null): mixed
{
    $v = $_ENV[$key] ?? getenv($key);
    if ($v === false || $v === null) return $default;
    return match (strtolower((string)$v)) {
        'true' => true,
        'false' => false,
        'null', '' => $default,
        default => $v,
    };
}
