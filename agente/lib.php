<?php
declare(strict_types=1);

/**
 * Agente coletor — monta os comandos SQL que gravam um produto coletado no banco do site.
 * Usado por importar.php (grava via PDO, com o .env do site) e por gerar-sql.php
 * (gera SQL pronto para rodar num cliente MySQL / ferramenta de banco do agente).
 */

/** Colunas que o agente grava além das originais (criadas por db_migrate() em src/db.php). */
const AGENTE_COLUNAS_NOVAS = ['item_id', 'images', 'affiliate_code', 'affiliate_text', 'affiliate_tag',
    'discount_label', 'reviews_count', 'store_name', 'ml_breadcrumb', 'highlights', 'collected_at'];

function agente_slugify(string $text): string
{
    $text = mb_strtolower(trim($text), 'UTF-8');
    $text = strtr($text, ['á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
        'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','ó'=>'o','ò'=>'o','õ'=>'o','ô'=>'o','ö'=>'o',
        'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c','ñ'=>'n']);
    $text = trim((string)preg_replace('/[^a-z0-9]+/', '-', $text), '-');
    return substr($text, 0, 80) ?: 'produto';
}

/** Valida o produto vindo do navegador. Retorna lista de problemas (vazia = ok). */
function agente_validar(array $p): array
{
    $err = [];
    if (empty($p['ml_id']) || !preg_match('/^MLBU?\d+$/', (string)$p['ml_id'])) $err[] = 'ml_id inválido';
    if (trim((string)($p['title'] ?? '')) === '') $err[] = 'sem título';
    if (!is_numeric($p['price'] ?? null) || (float)$p['price'] <= 0) $err[] = 'sem preço';
    if (empty($p['category_id'])) $err[] = 'sem categoria do site';
    if (!empty($p['affiliate_url']) && !preg_match('~^https://meli\.la/[A-Za-z0-9]+$~', (string)$p['affiliate_url'])) $err[] = 'link de afiliado inválido';
    return $err;
}

/**
 * Comandos para gravar um produto: upsert em products, histórico de preço e log da coleta.
 * $completo = false grava só nas colunas que já existiam antes do agente.
 * Retorna [[sql, params], ...].
 */
function agente_comandos(array $p, string $agora, bool $completo = true): array
{
    $mlId = (string)$p['ml_id'];
    $brand = $p['brand'] ?? null;
    if (!$brand && !empty($p['brand_verified'])) $brand = $p['store_name'] ?? null; // loja oficial = marca
    $s = fn($v, $max = 255) => ($v === null || $v === '') ? null : mb_substr(trim((string)$v), 0, $max);
    $n = fn($v) => is_numeric($v) ? (float)$v : null;
    $b = fn($v) => !empty($v) ? 1 : 0;

    $cols = [
        'ml_id' => $mlId,
        'title' => $s($p['title'], 500),
        'slug' => agente_slugify((string)$p['title']) . '-' . strtolower($mlId),
        'price' => $n($p['price']),
        'original_price' => $n($p['original_price'] ?? null),
        'image' => $s($p['image'] ?? null, 1000),
        'permalink' => $s($p['permalink'] ?? null, 1000),
        'affiliate_url' => $s($p['affiliate_url'] ?? null),
        'category_id' => (int)$p['category_id'],
        'free_shipping' => $b($p['free_shipping'] ?? 0),
        'item_condition' => $s($p['condition'] ?? null),
        'description' => $s($p['description'] ?? null, 5000),
        'badge' => $s($p['badge'] ?? null),
        'brand' => $s($brand),
        'brand_verified' => $b($p['brand_verified'] ?? 0),
        'rating' => $n($p['rating'] ?? null) !== null && (float)$p['rating'] <= 5 ? round((float)$p['rating'], 1) : null,
        'sold_label' => $s($p['sold_label'] ?? null),
        'pix_price' => $n($p['pix_price'] ?? null),
        'installments_qty' => is_numeric($p['installments_qty'] ?? null) ? (int)$p['installments_qty'] : null,
        'installments_value' => $n($p['installments_value'] ?? null),
        'installments_free' => $b($p['installments_free'] ?? 0),
        'coupon_label' => $s($p['coupon_label'] ?? null),
        'delivery_label' => $s($p['delivery_label'] ?? null),
        'is_full' => $b($p['is_full'] ?? 0),
        'active' => 1,
        'featured' => 0,
        'clicks' => 0,
        'price_updated_at' => $agora,
        'created_at' => $agora,
        'updated_at' => $agora,
    ];
    if ($completo) {
        $cols += [
            'item_id' => $s($p['item_id'] ?? null),
            'images' => json_encode(array_values(array_slice((array)($p['images'] ?? []), 0, 20)), JSON_UNESCAPED_SLASHES),
            'affiliate_code' => $s($p['affiliate_code'] ?? null),
            'affiliate_text' => $s($p['affiliate_text'] ?? null, 1000),
            'affiliate_tag' => $s($p['affiliate_tag'] ?? null),
            'discount_label' => $s($p['discount_label'] ?? null),
            'reviews_count' => is_numeric($p['reviews_count'] ?? null) ? (int)$p['reviews_count'] : null,
            'store_name' => $s($p['store_name'] ?? null),
            'ml_breadcrumb' => $s(implode(' > ', (array)($p['ml_breadcrumb'] ?? []))),
            'highlights' => json_encode(array_values(array_slice((array)($p['highlights'] ?? []), 0, 15)), JSON_UNESCAPED_UNICODE),
            'collected_at' => $agora,
        ];
    }

    // Produto que já existe: atualiza preço/dados do ML; mantém o que você editou no painel
    // (título, categoria, ativo, destaque) e só preenche marca/descrição/link se estiverem vazios.
    $keep = ['ml_id', 'slug', 'title', 'category_id', 'active', 'featured', 'clicks', 'created_at'];
    $fill = ['brand', 'description', 'affiliate_url'];
    $upd = [];
    foreach (array_keys($cols) as $c) {
        if (in_array($c, $keep, true)) continue;
        $upd[] = in_array($c, $fill, true) ? "$c = COALESCE(NULLIF($c, ''), VALUES($c))" : "$c = VALUES($c)";
    }
    // o link meli.la recém-gerado sempre vence um link vazio; se o produto já tinha outro meli.la, mantém
    $sql = 'INSERT INTO products (' . implode(', ', array_keys($cols)) . ') VALUES ('
        . implode(', ', array_fill(0, count($cols), '?')) . ') ON DUPLICATE KEY UPDATE ' . implode(', ', $upd);

    $out = [[$sql, array_values($cols)]];
    $out[] = ['INSERT INTO price_history (product_id, price, recorded_at) SELECT id, price, ? FROM products WHERE ml_id = ?',
        [$agora, $mlId]];
    if ($completo) {
        $out[] = ['INSERT INTO agente_coletas (run_date, category_id, ml_id, position, offers_label, affiliate_url, collected_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)', [substr($agora, 0, 10), (int)$p['category_id'], $mlId,
            is_numeric($p['position'] ?? null) ? (int)$p['position'] : null, $s($p['offers_label'] ?? null),
            $s($p['affiliate_url'] ?? null), $agora]];
    }
    return $out;
}

/** Troca os ? pelos valores já escapados (para gerar SQL em texto). */
function agente_sql_literal(string $sql, array $params): string
{
    $i = 0;
    return (string)preg_replace_callback('/\?/', function () use (&$i, $params) {
        $v = $params[$i++] ?? null;
        if ($v === null) return 'NULL';
        if (is_int($v) || is_float($v)) return (string)$v;
        return "'" . str_replace(['\\', "\0", "\n", "\r", "'", '"', "\x1a"], ['\\\\', '\\0', '\\n', '\\r', "\\'", '\\"', '\\Z'], (string)$v) . "'";
    }, $sql);
}

/** Lê um arquivo de coleta (JSON exportado pelo navegador). Aceita {results:[...]} ou [...]. */
function agente_ler_arquivo(string $file): array
{
    $j = json_decode((string)file_get_contents($file), true);
    if (!is_array($j)) throw new RuntimeException("JSON inválido: $file");
    return isset($j['results']) ? $j['results'] : $j;
}
