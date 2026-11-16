<?php
declare(strict_types=1);

function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function slugify(string $text): string
{
    $text = mb_strtolower(trim($text), 'UTF-8');
    $map = ['á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
            'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','ó'=>'o','ò'=>'o','õ'=>'o','ô'=>'o','ö'=>'o',
            'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c','ñ'=>'n'];
    $text = strtr($text, $map);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    $text = trim($text, '-');
    return substr($text, 0, 90) ?: 'produto';
}

function base_url(): string
{
    global $CONFIG;
    if (!empty($CONFIG['base_url'])) return rtrim($CONFIG['base_url'], '/');
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // diretório raiz do app (pasta onde está index.php)
    $root = realpath(__DIR__ . '/..');
    $docRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '';
    $sub = '';
    if ($docRoot && str_starts_with($root, $docRoot)) {
        $sub = str_replace('\\', '/', substr($root, strlen($docRoot)));
    }
    return ($https ? 'https' : 'http') . '://' . $host . rtrim($sub, '/');
}

function url(string $path = ''): string
{
    return base_url() . '/' . ltrim($path, '/');
}

function product_url(array $p): string
{
    return url('produto.php?s=' . rawurlencode($p['slug']));
}

function go_url(array $p): string
{
    return url('ir.php?id=' . (int)$p['id']);
}

function money(?float $v): string
{
    if ($v === null) return '';
    return 'R$ ' . number_format($v, 2, ',', '.');
}

function discount_pct(array $p): int
{
    $o = (float)($p['original_price'] ?? 0);
    $n = (float)($p['price'] ?? 0);
    if ($o <= 0 || $n <= 0 || $o <= $n) return 0;
    return (int)round((1 - $n / $o) * 100);
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function redirect(string $to): never
{
    header('Location: ' . $to);
    exit;
}

function flash(?string $msg = null, string $type = 'ok'): ?array
{
    if ($msg !== null) {
        $_SESSION['flash'][] = ['msg' => $msg, 'type' => $type];
        return null;
    }
    $all = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $all;
}

/* ---------- CSRF ---------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals(csrf_token(), (string)($_POST['_csrf'] ?? ''))) {
            http_response_code(400);
            exit('Sessão expirada. Volte e tente de novo.');
        }
    }
}

/* ---------- Admin auth ---------- */

function admin_has_password(): bool
{
    return (bool)setting('admin_password_hash');
}

function is_admin(): bool
{
    return !empty($_SESSION['admin']);
}

function require_admin(): void
{
    if (!is_admin()) redirect(url('admin/login.php'));
}

/* ---------- Produtos ---------- */

/**
 * Link final para onde o clique vai.
 * Prioridade: link de afiliado colado (meli.la) > permalink + parâmetros de afiliado > permalink.
 */
function product_destination(array $p): string
{
    if (!empty($p['affiliate_url'])) return $p['affiliate_url'];
    $link = (string)($p['permalink'] ?? '');
    $word = setting('aff_matt_word');
    $tool = setting('aff_matt_tool');
    if ($link && ($word || $tool)) {
        $sep = str_contains($link, '?') ? '&' : '?';
        $q = [];
        if ($word) $q['matt_word'] = $word;
        if ($tool) $q['matt_tool'] = $tool;
        $link .= $sep . http_build_query($q);
    }
    return $link;
}

function unique_slug(string $title, ?int $ignoreId = null): string
{
    $base = slugify($title);
    $slug = $base;
    $i = 2;
    $st = db()->prepare('SELECT id FROM products WHERE slug = ?');
    while (true) {
        $st->execute([$slug]);
        $id = $st->fetchColumn();
        if (!$id || (int)$id === $ignoreId) return $slug;
        $slug = $base . '-' . $i++;
    }
}

/**
 * Insere ou atualiza um produto vindo da API (array normalizado por MercadoLivre::normalize*).
 * Retorna [id, criado(bool)].
 */
function upsert_product(array $d, ?int $categoryId = null): array
{
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM products WHERE ml_id = ?');
    $st->execute([$d['ml_id']]);
    $existing = $st->fetch();

    if ($existing) {
        $pdo->prepare('UPDATE products SET title=?, price=?, original_price=?, image=?, permalink=?,
            ml_category_id=?, free_shipping=?, item_condition=?, price_updated_at=?, updated_at=? WHERE id=?')
            ->execute([$d['title'], $d['price'], $d['original_price'], $d['image'], $d['permalink'],
                $d['ml_category_id'], $d['free_shipping'] ? 1 : 0, $d['condition'], now(), now(), $existing['id']]);
        if ((float)$existing['price'] !== (float)$d['price']) record_price((int)$existing['id'], (float)$d['price']);
        return [(int)$existing['id'], false];
    }

    $pdo->prepare('INSERT INTO products (ml_id, title, slug, price, original_price, image, permalink,
        category_id, ml_category_id, free_shipping, item_condition, price_updated_at, created_at, updated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$d['ml_id'], $d['title'], unique_slug($d['title']), $d['price'], $d['original_price'],
            $d['image'], $d['permalink'], $categoryId, $d['ml_category_id'], $d['free_shipping'] ? 1 : 0,
            $d['condition'], now(), now(), now()]);
    $id = (int)$pdo->lastInsertId();
    record_price($id, (float)$d['price']);
    return [$id, true];
}

/**
 * Atualiza preço/foto dos produtos informados (por ml_id) consultando a API.
 * Anúncios pausados/encerrados no ML são desativados no site.
 * Retorna quantos foram atualizados.
 */
function refresh_prices(array $mlIds): int
{
    if (!$mlIds) return 0;
    $in = implode(',', array_fill(0, count($mlIds), '?'));
    $st = db()->prepare("SELECT ml_id, permalink FROM products WHERE ml_id IN ($in)");
    $st->execute($mlIds);
    $items = $catalog = [];
    foreach ($st as $r) {
        if (str_contains((string)$r['permalink'], '/p/' . $r['ml_id'])) $catalog[] = $r['ml_id'];
        else $items[] = $r['ml_id'];
    }

    $n = 0;
    if ($items) {
        $data = MercadoLivre::fetchMany($items);
        foreach ($data as $d) {
            upsert_product($d);
            if (($d['status'] ?? 'active') !== 'active') {
                db()->prepare('UPDATE products SET active = 0 WHERE ml_id = ?')->execute([$d['ml_id']]);
            }
            $n++;
        }
    }
    foreach ($catalog as $id) {
        try { upsert_product(MercadoLivre::fetch($id, true)); $n++; } catch (Throwable) { /* ignora um item com erro */ }
    }
    return $n;
}

function record_price(int $productId, float $price): void
{
    db()->prepare('INSERT INTO price_history (product_id, price, recorded_at) VALUES (?,?,?)')
        ->execute([$productId, $price, now()]);
}

function categories(): array
{
    return db()->query('SELECT * FROM categories ORDER BY sort_order, name')->fetchAll();
}

/** Texto pronto para postar em WhatsApp / Telegram / Instagram. */
function social_text(array $p): string
{
    $lines = [];
    $lines[] = '🔥 ' . $p['title'];
    $pct = discount_pct($p);
    if ($pct > 0) {
        $lines[] = '';
        $lines[] = '❌ De ' . money((float)$p['original_price']);
        $lines[] = '✅ Por ' . money((float)$p['price']) . " ({$pct}% OFF)";
    } else {
        $lines[] = '';
        $lines[] = '💰 ' . money((float)$p['price']);
    }
    if (!empty($p['coupon'])) $lines[] = '🎟️ Cupom: ' . $p['coupon'];
    if (!empty($p['free_shipping'])) $lines[] = '🚚 Frete grátis';
    $lines[] = '';
    $lines[] = '👉 ' . product_url($p);
    $lines[] = '';
    $lines[] = '_Preço sujeito a alteração._';
    return implode("\n", $lines);
}
