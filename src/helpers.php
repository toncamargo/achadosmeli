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

/** URL de arquivo estático com versão (evita cache antigo após publicar). */
function asset(string $path): string
{
    $file = __DIR__ . '/../' . ltrim($path, '/');
    $v = is_file($file) ? filemtime($file) : time();
    return url($path) . '?v=' . $v;
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
        update_api_extras((int)$existing['id'], $d, $existing);
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
    update_api_extras($id, $d, []);
    return [$id, true];
}

/**
 * Grava marca / FULL / parcelas vindos da API.
 * Marca só é preenchida se estiver vazia (não sobrescreve o que você editou no painel).
 */
function update_api_extras(int $id, array $d, array $current): void
{
    $set = [];
    $args = [];
    if (!empty($d['brand']) && empty($current['brand'])) {
        $set[] = 'brand = ?'; $args[] = $d['brand'];
        $set[] = 'brand_verified = ?'; $args[] = !empty($d['brand_verified']) ? 1 : 0;
    }
    if (array_key_exists('is_full', $d)) { $set[] = 'is_full = ?'; $args[] = $d['is_full'] ? 1 : 0; }
    if (!empty($d['installments_qty']) && !empty($d['installments_value'])) {
        $set[] = 'installments_qty = ?'; $args[] = (int)$d['installments_qty'];
        $set[] = 'installments_value = ?'; $args[] = (float)$d['installments_value'];
        $set[] = 'installments_free = ?'; $args[] = !empty($d['installments_free']) ? 1 : 0;
    }
    if (!$set) return;
    $args[] = $id;
    db()->prepare('UPDATE products SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($args);
}

/* ---------- Formatação de preço no estilo do card ---------- */

/** "R$ 206,90" e "R$ 212" (sem ,00). */
function money_short(float $v): string
{
    $cents = (int)round(($v - floor($v)) * 100);
    return 'R$ ' . ($cents === 0 ? number_format(floor($v), 0, ',', '.') : number_format($v, 2, ',', '.'));
}

/** "R$ 86<sup>99</sup>" */
function price_html(float $v): string
{
    $int = (int)floor($v);
    $cents = (int)round(($v - $int) * 100);
    if ($cents === 100) { $int++; $cents = 0; }
    return 'R$ ' . number_format($int, 0, ',', '.') . ($cents ? '<sup>' . sprintf('%02d', $cents) . '</sup>' : '');
}

/** Classe de cor do selo da foto. */
function badge_class(string $badge): string
{
    $b = mb_strtoupper($badge);
    return match (true) {
        str_contains($b, 'BLACK') => 'bd-black',
        str_contains($b, 'MAIS VENDIDO') => 'bd-orange',
        str_contains($b, 'RELÂMPAGO'), str_contains($b, 'RELAMPAGO') => 'bd-red',
        default => 'bd-blue',
    };
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

/** Banners ativos e dentro do período (datas vazias = sem limite). */
function active_banners(): array
{
    $today = date('Y-m-d');
    $st = db()->prepare("SELECT * FROM banners WHERE active = 1
        AND (starts_at IS NULL OR starts_at = '' OR starts_at <= ?)
        AND (ends_at IS NULL OR ends_at = '' OR ends_at >= ?)
        ORDER BY sort_order, id DESC");
    $st->execute([$today, $today]);
    return $st->fetchAll();
}

/** Salva imagem enviada no admin em assets/uploads. Retorna a URL ou null. */
function save_upload(array $file): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;
    if ($file['size'] > 4 * 1024 * 1024) throw new RuntimeException('Imagem maior que 4 MB.');
    $info = @getimagesize($file['tmp_name']);
    $ext = match ($info['mime'] ?? '') {
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif',
        default => throw new RuntimeException('Envie uma imagem JPG, PNG, WEBP ou GIF.'),
    };
    $dir = __DIR__ . '/../assets/uploads';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $name = date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], "$dir/$name")) throw new RuntimeException('Não consegui salvar a imagem (permissão da pasta assets/uploads).');
    return url('assets/uploads/' . $name);
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
