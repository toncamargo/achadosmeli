<?php
/**
 * Configuração de infraestrutura — os valores vêm do arquivo .env na raiz do projeto.
 * Não coloque senhas aqui: edite o .env (use o .env.example como modelo).
 *
 * As demais opções (nome do site, app do Mercado Livre, links de afiliado)
 * são editadas pelo painel em /admin/configuracoes.php.
 */
require_once __DIR__ . '/src/env.php';
load_env(__DIR__ . '/.env');

return [
    'base_url' => (string)env('APP_URL', ''),
    'timezone' => (string)env('APP_TIMEZONE', 'America/Sao_Paulo'),
    'per_page' => (int)env('APP_PER_PAGE', 24),
    'debug'    => (bool)env('APP_DEBUG', false),

    // 'sqlite' ou 'mysql'
    'db_driver' => strtolower((string)env('DB_DRIVER', 'sqlite')),

    'sqlite_path' => (function () {
        $p = (string)env('DB_SQLITE_PATH', 'data/achados.sqlite');
        return str_starts_with($p, '/') ? $p : __DIR__ . '/' . $p;
    })(),

    'mysql' => [
        'host'     => (string)env('DB_HOST', '127.0.0.1'),
        'port'     => (int)env('DB_PORT', 3306),
        'database' => (string)env('DB_DATABASE', 'achadosmeli'),
        'user'     => (string)env('DB_USERNAME', 'root'),
        'password' => (string)env('DB_PASSWORD', ''),
    ],
];
