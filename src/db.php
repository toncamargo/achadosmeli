<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    global $CONFIG;

    if (($CONFIG['db_driver'] ?? 'sqlite') === 'mysql') {
        $m = $CONFIG['mysql'];
        $opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5];
        $server = "mysql:host={$m['host']};port={$m['port']};charset=utf8mb4";
        try {
            $pdo = new PDO("$server;dbname={$m['database']}", $m['user'], $m['password'], $opts);
        } catch (PDOException $ex) {
            // 1049 = banco não existe: tenta criar (funciona se o usuário tiver permissão)
            if (($ex->errorInfo[1] ?? null) !== 1049 && !str_contains($ex->getMessage(), '[1049]')) db_fail($ex);
            try {
                $tmp = new PDO($server, $m['user'], $m['password'], $opts);
                $tmp->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '', $m['database']) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                $pdo = new PDO("$server;dbname={$m['database']}", $m['user'], $m['password'], $opts);
            } catch (PDOException $ex2) {
                db_fail($ex2);
            }
        }
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

/** Página de erro amigável quando o banco não conecta. */
function db_fail(Throwable $ex): never
{
    global $CONFIG;
    error_log('[achadosmeli] Falha no banco: ' . $ex->getMessage());
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'Erro de banco: ' . $ex->getMessage() . "\n");
        exit(1);
    }
    http_response_code(500);
    $detail = !empty($CONFIG['debug'])
        ? '<pre style="white-space:pre-wrap;background:#f4f4f4;padding:12px;border-radius:6px">' . htmlspecialchars($ex->getMessage()) . '</pre>'
        : '';
    exit('<!doctype html><meta charset="utf-8"><title>Erro de banco</title>
      <div style="font:15px system-ui;max-width:560px;margin:60px auto;padding:0 20px">
      <h1 style="font-size:22px">Não foi possível conectar ao banco de dados</h1>
      <p>Confira os dados <code>DB_*</code> no arquivo <code>.env</code> na raiz do projeto
      (host, porta, nome do banco, usuário e senha).</p>' . $detail . '</div>');
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

    // Campos do card de produto (adicionados depois: cria as colunas que faltarem)
    db_ensure_columns('products', [
        'badge' => "$text NULL",                 // selo na foto: BLACK FRIDAY, OFERTA DO DIA, MAIS VENDIDO...
        'brand' => "$text NULL",                 // marca / loja
        'brand_verified' => 'INT NOT NULL DEFAULT 0',
        'rating' => 'DECIMAL(2,1) NULL',         // nota (ex.: 4.8)
        'sold_label' => "$text NULL",            // ex.: +10mil vendidos
        'pix_price' => 'DECIMAL(12,2) NULL',     // preço no Pix
        'installments_qty' => 'INT NULL',        // parcelas
        'installments_value' => 'DECIMAL(12,2) NULL',
        'installments_free' => 'INT NOT NULL DEFAULT 0', // sem juros
        'coupon_label' => "$text NULL",          // ex.: R$ 20 OFF com Cupom
        'delivery_label' => "$text NULL",        // ex.: Chegará grátis amanhã
        'is_full' => 'INT NOT NULL DEFAULT 0',   // entrega FULL
        'source_id' => 'INT NULL',               // fonte da coleta automática que trouxe o produto
        // Agente coletor (agente/): dados lidos da página do produto e do painel "Compartilhar" de afiliados
        'item_id' => "$text NULL",               // anúncio vencedor (MLB...) quando ml_id é de catálogo (/p/)
        'images' => 'TEXT NULL',                 // JSON com os links de todas as fotos (não baixadas)
        'affiliate_code' => "$text NULL",        // "ID do produto" do painel de afiliados (ex.: 9PDZND-9N3K)
        'affiliate_text' => 'TEXT NULL',         // texto sugerido do painel de afiliados
        'affiliate_tag' => "$text NULL",         // etiqueta em uso no link
        'discount_label' => "$text NULL",        // ex.: 39% OFF
        'reviews_count' => 'INT NULL',           // nº de avaliações
        'store_name' => "$text NULL",            // loja vendedora
        'ml_breadcrumb' => "$text NULL",         // categoria no ML (Ferramentas > Ferramentas Manuais > ...)
        'highlights' => 'TEXT NULL',             // JSON "O que você precisa saber"
        'collected_at' => "$text NULL",          // última coleta pelo agente
    ]);

    // Agente coletor: um registro por produto coletado por dia (controle das 5 ofertas por categoria)
    $pdo->exec("CREATE TABLE IF NOT EXISTS agente_coletas (
        id $pk,
        run_date $text NOT NULL,
        category_id INT NULL,
        ml_id $text NOT NULL,
        position INT NULL,
        offers_label $text NULL,
        affiliate_url $text NULL,
        collected_at $text NOT NULL
    )$engine");

    // Coleta automática: fontes (palavra-chave ou mais vendidos de uma categoria do ML)
    $pdo->exec("CREATE TABLE IF NOT EXISTS ml_sources (
        id $pk,
        name $text NOT NULL,
        type $text NOT NULL DEFAULT 'keyword',
        query $text NULL,
        ml_category_id $text NULL,
        category_id INT NULL,
        min_discount INT NOT NULL DEFAULT 0,
        min_price DECIMAL(12,2) NULL,
        max_price DECIMAL(12,2) NULL,
        free_shipping_only INT NOT NULL DEFAULT 0,
        full_only INT NOT NULL DEFAULT 0,
        exclude_words TEXT NULL,
        max_items INT NOT NULL DEFAULT 20,
        auto_publish INT NOT NULL DEFAULT 0,
        every_hours INT NOT NULL DEFAULT 12,
        active INT NOT NULL DEFAULT 1,
        last_run_at $text NULL,
        last_status $text NULL,
        created_at $text NOT NULL
    )$engine");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ml_collect_log (
        id $pk,
        source_id INT NULL,
        started_at $text NOT NULL,
        finished_at $text NULL,
        found INT NOT NULL DEFAULT 0,
        created INT NOT NULL DEFAULT 0,
        updated INT NOT NULL DEFAULT 0,
        skipped INT NOT NULL DEFAULT 0,
        errors TEXT NULL
    )$engine");

    // Banners do carrossel da home (campanhas: Black Friday, Dia das Mães...)
    $pdo->exec("CREATE TABLE IF NOT EXISTS banners (
        id $pk,
        kicker $text NULL,
        title $text NULL,
        prefix $text NULL,
        highlight $text NULL,
        pill $text NULL,
        footnote $text NULL,
        image TEXT,
        link_url TEXT,
        sort_order INT NOT NULL DEFAULT 0,
        active INT NOT NULL DEFAULT 1,
        starts_at $text NULL,
        ends_at $text NULL,
        created_at $text NOT NULL
    )$engine");

    // Instagram: posts ligados a produtos e histórico de comentários respondidos (src/Instagram.php)
    $pdo->exec("CREATE TABLE IF NOT EXISTS ig_posts (
        media_id $text PRIMARY KEY,
        keyword $text NOT NULL DEFAULT 'quero',
        active INT NOT NULL DEFAULT 1,
        caption TEXT NULL,
        permalink TEXT NULL,
        created_at $text NOT NULL
    )$engine");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ig_post_products (
        media_id $text NOT NULL,
        code INT NOT NULL,
        product_id INT NOT NULL,
        PRIMARY KEY (media_id, code)
    )$engine");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ig_comment_log (
        comment_id $text PRIMARY KEY,
        media_id $text NULL,
        username $text NULL,
        text TEXT NULL,
        product_id INT NULL,
        source $text NULL,
        status $text NOT NULL,
        error TEXT NULL,
        created_at $text NOT NULL
    )$engine");

    // Instagram: Reels publicados pelo painel (admin/reels.php) — vídeo do agente baixar-video-ml
    $pdo->exec("CREATE TABLE IF NOT EXISTS ig_reels (
        id $pk,
        video $text NOT NULL,
        caption TEXT NULL,
        product_id INT NULL,
        ml_id $text NULL,
        title $text NULL,
        share_to_feed INT NOT NULL DEFAULT 1,
        status $text NOT NULL,
        container_id $text NULL,
        media_id $text NULL,
        permalink TEXT NULL,
        error TEXT NULL,
        created_at $text NOT NULL,
        updated_at $text NOT NULL,
        published_at $text NULL
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

/** Adiciona à tabela as colunas que ainda não existem (migração simples). */
function db_ensure_columns(string $table, array $columns): void
{
    $pdo = db();
    $existing = db_is_mysql()
        ? array_column($pdo->query("SHOW COLUMNS FROM $table")->fetchAll(), 'Field')
        : array_column($pdo->query("PRAGMA table_info($table)")->fetchAll(), 'name');
    foreach ($columns as $name => $def) {
        if (!in_array($name, $existing, true)) $pdo->exec("ALTER TABLE $table ADD COLUMN $name $def");
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
