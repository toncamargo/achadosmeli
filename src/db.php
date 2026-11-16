<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    global $CONFIG;

    if (($CONFIG['db_driver'] ?? 'sqlite') === 'mysql') {
        $m = $CONFIG['mysql'];
        $dsn = "mysql:host={$m['host']};port={$m['port']};dbname={$m['database']};charset=utf8mb4";
        $pdo = new PDO($dsn, $m['user'], $m['password']);
    } else {
        $path = $CONFIG['sqlite_path'];
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0775, true);
        $pdo = new PDO('sqlite:' . $path);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
}

function db_is_mysql(): bool
{
    global $CONFIG;
    return ($CONFIG['db_driver'] ?? 'sqlite') === 'mysql';
}

function db_migrate(): void
{
    $pdo = db();
    $pk = db_is_mysql() ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $text = db_is_mysql() ? 'VARCHAR(255)' : 'TEXT';
    $engine = db_is_mysql() ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        k $text PRIMARY KEY,
        v TEXT
    )$engine");

    $pdo->exec("CREATE TABLE IF NOT EXISTS categories (
        id $pk,
        name $text NOT NULL,
        slug $text NOT NULL UNIQUE,
        sort_order INT NOT NULL DEFAULT 0
    )$engine");

    $pdo->exec("CREATE TABLE IF NOT EXISTS products (
        id $pk,
        ml_id $text NOT NULL UNIQUE,
        title TEXT NOT NULL,
        slug $text NOT NULL UNIQUE,
        price DECIMAL(12,2) NOT NULL DEFAULT 0,
        original_price DECIMAL(12,2) NULL,
        image TEXT,
        permalink TEXT,
        affiliate_url TEXT,
        category_id INT NULL,
        ml_category_id $text NULL,
        free_shipping INT NOT NULL DEFAULT 0,
        item_condition $text NULL,
        description TEXT,
        coupon $text NULL,
        featured INT NOT NULL DEFAULT 0,
        active INT NOT NULL DEFAULT 1,
        clicks INT NOT NULL DEFAULT 0,
        posted_at $text NULL,
        price_updated_at $text NULL,
        created_at $text NOT NULL,
        updated_at $text NOT NULL
    )$engine");

    $pdo->exec("CREATE TABLE IF NOT EXISTS price_history (
        id $pk,
        product_id INT NOT NULL,
        price DECIMAL(12,2) NOT NULL,
        recorded_at $text NOT NULL
    )$engine");

    // Categorias iniciais (só na primeira vez)
    $count = (int)$pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn();
    if ($count === 0) {
        $defaults = ['Casa e Cozinha', 'Tecnologia', 'Moda', 'Beleza', 'Esporte', 'Games', 'Bebês e Crianças', 'Ferramentas', 'Pet'];
        $st = $pdo->prepare('INSERT INTO categories (name, slug, sort_order) VALUES (?, ?, ?)');
        foreach ($defaults as $i => $name) {
            $st->execute([$name, slugify($name), $i]);
        }
    }
}

function setting(string $key, ?string $default = null): ?string
{
    if (!isset($GLOBALS['__settings'])) {
        $GLOBALS['__settings'] = [];
        foreach (db()->query('SELECT k, v FROM settings') as $row) {
            $GLOBALS['__settings'][$row['k']] = $row['v'];
        }
    }
    $v = $GLOBALS['__settings'][$key] ?? null;
    return ($v === null || $v === '') ? $default : $v;
}

function setting_set(string $key, ?string $value): void
{
    $pdo = db();
    $pdo->prepare('DELETE FROM settings WHERE k = ?')->execute([$key]);
    $pdo->prepare('INSERT INTO settings (k, v) VALUES (?, ?)')->execute([$key, $value]);
    unset($GLOBALS['__settings']);
}
