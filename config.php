<?php
/**
 * Configuração principal do site.
 * A maior parte das opções (nome do site, credenciais do app Mercado Livre,
 * parâmetros de afiliado) é editada pelo painel em /admin/configuracoes.php.
 * Aqui ficam só as coisas de infraestrutura.
 */
return [
    // URL pública do site, SEM barra no final. Ex.: https://achados.seudominio.com.br
    // Deixe vazio para detectar automaticamente.
    'base_url' => '',

    // Banco de dados: 'sqlite' (zero configuração) ou 'mysql'
    'db_driver' => 'sqlite',
    'sqlite_path' => __DIR__ . '/data/achados.sqlite',

    'mysql' => [
        'host' => 'localhost',
        'port' => 3306,
        'database' => 'achados',
        'user' => 'root',
        'password' => '',
    ],

    // Fuso horário do site
    'timezone' => 'America/Sao_Paulo',

    // Produtos por página na home
    'per_page' => 24,
];
