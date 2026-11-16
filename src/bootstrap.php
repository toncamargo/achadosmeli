<?php
declare(strict_types=1);

$CONFIG = require __DIR__ . '/../config.php';
date_default_timezone_set($CONFIG['timezone'] ?? 'America/Sao_Paulo');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ]);
    session_start();
}

require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/MercadoLivre.php';

db_migrate();
