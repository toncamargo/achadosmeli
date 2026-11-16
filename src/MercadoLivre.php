<?php
declare(strict_types=1);

/**
 * Cliente mínimo da API do Mercado Livre (Brasil / MLB).
 *
 * Desde 2025 a API exige access token OAuth até nos endpoints de busca e item.
 * O token vale ~6h e é renovado automaticamente com o refresh_token salvo em settings.
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
        foreach (['ml_access_token', 'ml_token_expires', 'ml_refresh_token', 'ml_user_id'] as $k) setting_set($k, null);
    }

    /* ------------------------------------------------------------------ */

    /** Busca produtos. Tenta a busca de anúncios; se a conta não tiver permissão, cai para a busca de catálogo. */
    public static function search(string $q, int $offset = 0, int $limit = 20, array $filters = []): array
    {
        $params = array_merge(['q' => $q, 'offset' => $offset, 'limit' => $limit], $filters);
        try {
            $res = self::get('/sites/' . self::SITE . '/search?' . http_build_query($params));
            return [
                'mode' => 'items',
                'total' => (int)($res['paging']['total'] ?? 0),
                'results' => array_map([self::class, 'normalizeSearchResult'], $res['results'] ?? []),
            ];
        } catch (MlApiException $ex) {
            if ($ex->status !== 403 && $ex->status !== 401) throw $ex;
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
    }

    /** Busca um item (MLB123...) ou produto de catálogo (/p/MLB123...) pelo ID. */
    public static function fetch(string $id, bool $catalog = false): array
    {
        if ($catalog) {
            $p = self::get('/products/' . $id);
            $bb = $p['buy_box_winner'] ?? null;
            return [
                'ml_id' => $p['id'],
                'title' => $p['name'] ?? '',
                'price' => (float)($bb['price'] ?? 0),
                'original_price' => isset($bb['original_price']) ? (float)$bb['original_price'] : null,
                'image' => self::bigImage($p['pictures'][0]['url'] ?? ''),
                'permalink' => $p['permalink'] ?? ('https://www.mercadolivre.com.br/p/' . $p['id']),
                'ml_category_id' => $p['domain_id'] ?? null,
                'free_shipping' => (bool)($bb['shipping']['free_shipping'] ?? false),
                'condition' => $bb['condition'] ?? null,
            ];
        }
        $it = self::get('/items/' . $id);
        return self::normalizeItem($it);
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
        ];
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

    private static function http(string $method, string $path, ?array $form, bool $auth): array
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
