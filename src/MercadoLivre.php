<?php
declare(strict_types=1);

/**
 * Cliente mínimo da API do Mercado Livre (Brasil / MLB).
 *
 * Desde 2025 a API exige access token OAuth até nos endpoints de busca e item.
 * O token vale ~6h e é renovado automaticamente com o refresh_token salvo em settings.
 *
 * Restrições atuais da API (apps de terceiros):
 *  - /sites/MLB/search costuma responder 403 → caímos para /products/search (catálogo, sem preço).
 *    Quando isso acontece, o modo "catálogo" fica memorizado por 24h para não gastar chamada à toa.
 *  - /highlights/MLB/category/{id} (20 mais vendidos da categoria) segue liberado com token:
 *    é a fonte mais estável para coleta automática.
 *  - Não existe API pública para gerar link de afiliado (meli.la). O site usa os parâmetros
 *    matt_word / matt_tool do seu link (ver Configurações) ou o link colado em cada produto.
 */
final class MercadoLivre
{
    const API = 'https://api.mercadolibre.com';
    const AUTH = 'https://auth.mercadolivre.com.br/authorization';
    const SITE = 'MLB';

    public static function redirectUri(): string
    {
        return url('admin/ml-callback.php');
    }

    public static function isConfigured(): bool
    {
        return (bool)setting('ml_client_id') && (bool)setting('ml_client_secret');
    }

    public static function isConnected(): bool
    {
        return (bool)setting('ml_refresh_token');
    }

    public static function authorizeUrl(): string
    {
        $state = bin2hex(random_bytes(8));
        $_SESSION['ml_oauth_state'] = $state;
        return self::AUTH . '?' . http_build_query([
            'response_type' => 'code',
            'client_id' => setting('ml_client_id'),
            'redirect_uri' => self::redirectUri(),
            'state' => $state,
        ]);
    }

    public static function exchangeCode(string $code): void
    {
        $res = self::http('POST', '/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => setting('ml_client_id'),
            'client_secret' => setting('ml_client_secret'),
            'code' => $code,
            'redirect_uri' => self::redirectUri(),
        ], false);
        self::saveToken($res);
    }

    private static function saveToken(array $res): void
    {
        if (empty($res['access_token'])) {
            throw new RuntimeException('Resposta de token inválida: ' . json_encode($res));
        }
        setting_set('ml_access_token', $res['access_token']);
        setting_set('ml_token_expires', (string)(time() + (int)($res['expires_in'] ?? 21600) - 300));
        if (!empty($res['refresh_token'])) setting_set('ml_refresh_token', $res['refresh_token']);
        if (!empty($res['user_id'])) setting_set('ml_user_id', (string)$res['user_id']);
    }

    public static function token(): string
    {
        if (!self::isConnected()) throw new RuntimeException('Conta Mercado Livre não conectada. Vá em Configurações.');
        $tok = setting('ml_access_token');
        if ($tok && (int)setting('ml_token_expires', '0') > time()) return $tok;

        $res = self::http('POST', '/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => setting('ml_client_id'),
            'client_secret' => setting('ml_client_secret'),
            'refresh_token' => setting('ml_refresh_token'),
        ], false);
        self::saveToken($res);
        return (string)setting('ml_access_token');
    }

    public static function disconnect(): void
    {
        foreach (['ml_access_token', 'ml_token_expires', 'ml_refresh_token', 'ml_user_id', 'ml_search_mode'] as $k) setting_set($k, null);
    }

    /** Dados da conta conectada (usado no "Testar conexão"). */
    public static function me(): array
    {
        return self::get('/users/me');
    }

    /* ------------------------------------------------------------------ */

    /** Busca produtos. Tenta a busca de anúncios; se a conta não tiver permissão, cai para a busca de catálogo. */
    public static function search(string $q, int $offset = 0, int $limit = 20, array $filters = []): array
    {
        $params = array_merge(['q' => $q, 'offset' => $offset, 'limit' => $limit], $filters);
        if (!self::catalogOnly()) {
            try {
                $res = self::get('/sites/' . self::SITE . '/search?' . http_build_query($params));
                return [
                    'mode' => 'items',
                    'total' => (int)($res['paging']['total'] ?? 0),
                    'results' => array_map([self::class, 'normalizeSearchResult'], $res['results'] ?? []),
                ];
            } catch (MlApiException $ex) {
                if ($ex->status !== 403 && $ex->status !== 401) throw $ex;
                // sem permissão na busca de anúncios: lembra por 24h e usa o catálogo
                setting_set('ml_search_mode', 'catalog:' . time());
            }
        }
        // fallback: busca no catálogo de produtos (sem preço na listagem)
        $res = self::get('/products/search?' . http_build_query([
            'status' => 'active', 'site_id' => self::SITE, 'q' => $q, 'offset' => $offset, 'limit' => $limit,
        ]));
        return [
            'mode' => 'catalog',
            'total' => (int)($res['paging']['total'] ?? 0),
            'results' => array_map(fn($r) => [
                'ml_id' => $r['id'],
                'title' => $r['name'] ?? '',
                'price' => null,
                'original_price' => null,
                'image' => self::bigImage($r['pictures'][0]['url'] ?? ''),
                'permalink' => 'https://www.mercadolivre.com.br/p/' . $r['id'],
                'ml_category_id' => $r['domain_id'] ?? null,
                'free_shipping' => false,
                'condition' => null,
            ], $res['results'] ?? []),
        ];
    }

    /** true se a busca de anúncios deu 403 nas últimas 24h. */
    public static function catalogOnly(): bool
    {
        $m = (string)setting('ml_search_mode', '');
        if (!str_starts_with($m, 'catalog:')) return false;
        return (time() - (int)substr($m, 8)) < 86400;
    }

    /**
     * Top 20 mais vendidos de uma categoria do ML (ex.: MLB1648 = Informática).
     * Retorna [['id' => 'MLB...', 'type' => 'ITEM'|'PRODUCT'|'USER_PRODUCT', 'position' => n], ...]
     */
    public static function highlights(string $mlCategoryId): array
    {
        $res = self::get('/highlights/' . self::SITE . '/category/' . rawurlencode($mlCategoryId));
        return array_map(fn($c) => [
            'id' => (string)($c['id'] ?? ''),
            'type' => strtoupper((string)($c['type'] ?? 'ITEM')),
            'position' => (int)($c['position'] ?? 0),
        ], $res['content'] ?? []);
    }

    /** Categorias de 1º nível do ML Brasil (cache de 7 dias em settings). */
    public static function siteCategories(): array
    {
        $cache = json_decode((string)setting('ml_site_categories', ''), true);
        if (is_array($cache) && ($cache['t'] ?? 0) > time() - 7 * 86400) return $cache['list'];
        $list = [];
        foreach (self::get('/sites/' . self::SITE . '/categories') as $c) {
            if (!empty($c['id'])) $list[$c['id']] = $c['name'] ?? $c['id'];
        }
        asort($list);
        setting_set('ml_site_categories', json_encode(['t' => time(), 'list' => $list], JSON_UNESCAPED_UNICODE));
        return $list;
    }

    /** Nome, caminho e subcategorias de uma categoria do ML. */
    public static function category(string $mlCategoryId): array
    {
        $c = self::get('/categories/' . rawurlencode($mlCategoryId));
        $children = [];
        foreach ($c['children_categories'] ?? [] as $ch) $children[$ch['id']] = $ch['name'];
        return [
            'id' => $c['id'] ?? $mlCategoryId,
            'name' => $c['name'] ?? $mlCategoryId,
            'path' => array_column($c['path_from_root'] ?? [], 'name'),
            'children' => $children,
        ];
    }

    /** Busca um item (MLB123...) ou produto de catálogo (/p/MLB123...) pelo ID. */
    public static function fetch(string $id, bool $catalog = false): array
    {
        if ($catalog) {
            $p = self::get('/products/' . $id);
            $offer = $p['buy_box_winner'] ?? null;
            // buy_box_winner nem sempre vem preenchido: pega a oferta mais barata do catálogo
            if (empty($offer['price'])) $offer = self::cheapestOffer($id) ?? $offer;
            $offer = is_array($offer) ? $offer : [];

            $brand = null;
            foreach ($p['attributes'] ?? [] as $a) {
                if (($a['id'] ?? '') === 'BRAND' && !empty($a['value_name'])) { $brand = $a['value_name']; break; }
            }
            $extras = self::extras($offer);
            if ($brand) $extras['brand'] = $brand;

            return [
                'ml_id' => $p['id'],
                'title' => $p['name'] ?? '',
                'price' => (float)($offer['price'] ?? 0),
                'original_price' => isset($offer['original_price']) ? (float)$offer['original_price'] : null,
                'image' => self::bigImage($p['pictures'][0]['url'] ?? ''),
                'permalink' => $p['permalink'] ?? ('https://www.mercadolivre.com.br/p/' . $p['id']),
                'ml_category_id' => $p['domain_id'] ?? null,
                'free_shipping' => (bool)($offer['shipping']['free_shipping'] ?? false),
                'condition' => $offer['condition'] ?? null,
                // sem nenhuma oferta com preço = produto sem estoque no momento
                'status' => (($p['status'] ?? 'active') === 'active' && !empty($offer['price'])) ? 'active' : 'inactive',
            ] + $extras;
        }
        $it = self::get('/items/' . $id);
        return self::normalizeItem($it) + ['status' => $it['status'] ?? 'active'];
    }

    /** Oferta (anúncio) mais barata de um produto de catálogo. */
    private static function cheapestOffer(string $productId): ?array
    {
        try {
            $res = self::get('/products/' . $productId . '/items?limit=20');
        } catch (MlApiException) {
            return null;
        }
        $best = null;
        foreach ($res['results'] ?? [] as $o) {
            if (empty($o['price'])) continue;
            if (!$best || (float)$o['price'] < (float)$best['price']) $best = $o;
        }
        return $best;
    }

    /** Busca vários itens (até 20 por chamada). Retorna [ml_id => dados]. */
    public static function fetchMany(array $ids): array
    {
        $out = [];
        foreach (array_chunk($ids, 20) as $chunk) {
            $res = self::get('/items?ids=' . implode(',', $chunk));
            foreach ($res as $row) {
                if (($row['code'] ?? 0) === 200) {
                    $n = self::normalizeItem($row['body']);
                    $n['status'] = $row['body']['status'] ?? 'active';
                    $out[$n['ml_id']] = $n;
                }
            }
        }
        return $out;
    }

    /**
     * Extrai o ID de uma URL do Mercado Livre.
     * Retorna ['id' => 'MLB123', 'catalog' => bool] ou null.
     */
    public static function parseUrl(string $input): ?array
    {
        $input = trim($input);
        // produto de catálogo: /p/MLB12345678
        if (preg_match('~/p/(MLB\d+)~i', $input, $m)) return ['id' => strtoupper($m[1]), 'catalog' => true];
        // parâmetro wid=MLB123 (item vencedor dentro de página de catálogo)
        if (preg_match('~[?&#]wid=(MLB\d+)~i', $input, $m)) return ['id' => strtoupper($m[1]), 'catalog' => false];
        // anúncio: MLB-1234567890 ou MLB1234567890
        if (preg_match('~(MLB)-?(\d{6,})~i', $input, $m)) return ['id' => 'MLB' . $m[2], 'catalog' => false];
        return null;
    }

    /**
     * Lê um link de afiliado seu (meli.la/... ou a URL completa) e devolve os parâmetros
     * de rastreio encontrados: ['matt_word' => ..., 'matt_tool' => ..., 'final_url' => ...].
     * Links curtos são seguidos (redirect a redirect) até achar os parâmetros.
     */
    public static function affiliateParams(string $link): array
    {
        $found = ['matt_word' => null, 'matt_tool' => null, 'final_url' => trim($link)];
        $url = trim($link);
        for ($i = 0; $i < 6 && $url !== ''; $i++) {
            parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
            foreach (['matt_word', 'matt_tool'] as $k) {
                if (!empty($q[$k]) && !$found[$k]) $found[$k] = (string)$q[$k];
            }
            $found['final_url'] = $url;
            if ($found['matt_word'] && $found['matt_tool']) break;

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120 Safari/537.36',
            ]);
            $html = (string)curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $next = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            curl_close($ch);

            if ($status >= 300 && $status < 400 && $next !== '') { $url = $next; continue; }
            // algumas páginas redirecionam por JS/meta: procura a URL com matt_ no HTML
            if (preg_match('~https?:(?:\\\\?/){2}[^"\'\s<>]+matt_(?:word|tool)=[^"\'\s<>]+~i', $html, $m)) {
                $url = html_entity_decode(str_replace('\/', '/', $m[0]));
                continue;
            }
            break;
        }
        return $found;
    }

    /* ------------------------------------------------------------------ */

    private static function normalizeSearchResult(array $r): array
    {
        return [
            'ml_id' => $r['id'],
            'title' => $r['title'] ?? '',
            'price' => isset($r['price']) ? (float)$r['price'] : null,
            'original_price' => isset($r['original_price']) ? (float)$r['original_price'] : null,
            'image' => self::bigImage($r['thumbnail'] ?? ''),
            'permalink' => $r['permalink'] ?? '',
            'ml_category_id' => $r['category_id'] ?? null,
            'free_shipping' => (bool)($r['shipping']['free_shipping'] ?? false),
            'condition' => $r['condition'] ?? null,
        ] + self::extras($r);
    }

    private static function normalizeItem(array $it): array
    {
        $img = $it['pictures'][0]['secure_url'] ?? $it['pictures'][0]['url'] ?? $it['thumbnail'] ?? '';
        return [
            'ml_id' => $it['id'],
            'title' => $it['title'] ?? '',
            'price' => (float)($it['price'] ?? 0),
            'original_price' => isset($it['original_price']) ? (float)$it['original_price'] : null,
            'image' => self::bigImage($img),
            'permalink' => $it['permalink'] ?? '',
            'ml_category_id' => $it['category_id'] ?? null,
            'free_shipping' => (bool)($it['shipping']['free_shipping'] ?? false),
            'condition' => $it['condition'] ?? null,
        ] + self::extras($it);
    }

    /** Campos extras do card: marca, FULL e parcelas (quando a API informa). */
    private static function extras(array $r): array
    {
        $brand = null;
        foreach ($r['attributes'] ?? [] as $a) {
            if (($a['id'] ?? '') === 'BRAND' && !empty($a['value_name'])) { $brand = $a['value_name']; break; }
        }
        $inst = $r['installments'] ?? null;
        return [
            'brand' => $brand ?? ($r['official_store_name'] ?? null),
            'brand_verified' => !empty($r['official_store_id']) || !empty($r['official_store_name']),
            'is_full' => ($r['shipping']['logistic_type'] ?? '') === 'fulfillment',
            'installments_qty' => $inst['quantity'] ?? null,
            'installments_value' => isset($inst['amount']) ? (float)$inst['amount'] : null,
            'installments_free' => isset($inst['rate']) && (float)$inst['rate'] == 0.0,
        ];
    }

    /** Troca thumbnail pequena (-I.jpg) pela versão maior (-O.jpg) e força https. */
    public static function bigImage(string $url): string
    {
        $url = preg_replace('~^http://~', 'https://', $url);
        return preg_replace('~-[IT](\.(jpg|webp|png))$~', '-O$1', $url);
    }

    /* ------------------------------------------------------------------ */

    private static function get(string $path): array
    {
        return self::http('GET', $path, null, true);
    }

    private static function http(string $method, string $path, ?array $form, bool $auth, int $attempt = 1): array
    {
        $ch = curl_init(self::API . $path);
        $headers = ['Accept: application/json'];
        if ($auth) $headers[] = 'Authorization: Bearer ' . self::token();
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $method,
        ];
        if ($form !== null) {
            $opts[CURLOPT_POSTFIELDS] = http_build_query($form);
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $opts[CURLOPT_HTTPHEADER] = $headers;
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        // limite de requisições (429) ou instabilidade (5xx/queda): espera e tenta de novo
        if (($body === false || $status === 429 || $status >= 500) && $attempt < 3) {
            sleep($attempt * 2);
            return self::http($method, $path, $form, $auth, $attempt + 1);
        }
        // token recusado antes de expirar (ex.: revogado): força renovação uma vez
        if ($status === 401 && $auth && $attempt === 1 && self::isConnected()) {
            setting_set('ml_token_expires', '0');
            return self::http($method, $path, $form, $auth, 3);
        }

        if ($body === false) throw new MlApiException('Falha de conexão com a API: ' . $err, 0);
        $json = json_decode((string)$body, true);
        if ($status >= 400) {
            $msg = is_array($json) ? ($json['message'] ?? $json['error'] ?? $body) : $body;
            throw new MlApiException("API Mercado Livre ($status): $msg", $status);
        }
        return is_array($json) ? $json : [];
    }
}

final class MlApiException extends RuntimeException
{
    public function __construct(string $message, public int $status)
    {
        parent::__construct($message, $status);
    }
}
