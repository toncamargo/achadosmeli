<?php
declare(strict_types=1);

/**
 * Automação do Instagram: quem comenta a palavra-chave num post cadastrado recebe o link
 * do produto por direct (Private Reply) e uma resposta curta no comentário.
 *
 * Duas formas de receber os comentários:
 *  - instagram/webhook.php  (tempo real; exige app Live + Acesso Avançado na Meta)
 *  - cron/instagram.php     (consulta a cada 5 min; funciona com o acesso padrão)
 *
 * Credenciais e textos ficam na tabela settings (painel /admin/instagram.php).
 */
final class Instagram
{
    public const API_VERSION = 'v24.0';
    public const DEFAULT_KEYWORD = 'quero';
    public const DEFAULT_PUBLIC_REPLIES = "Te mandei no direct, @{username}! 📩\nEnviado na sua DM, @{username} 💌\nCorre lá no direct, @{username} 😉\nLink enviado no seu direct! 🛒";
    public const DEFAULT_DM_SINGLE = "Oi, @{username}! 😊 Aqui está o link do produto:\n\n{title}\n💰 {price}\n\n👉 {link}";
    public const DEFAULT_DM_LIST = "Oi, @{username}! 😊 Aqui estão os produtos do post:";

    public static function isConnected(): bool
    {
        return (bool)setting('ig_access_token');
    }

    /** Token usado na verificação do webhook (gerado na primeira vez). */
    public static function verifyToken(): string
    {
        $t = setting('ig_verify_token');
        if (!$t) {
            $t = bin2hex(random_bytes(12));
            setting_set('ig_verify_token', $t);
        }
        return $t;
    }

    public static function webhookUrl(): string
    {
        return url('instagram/webhook.php');
    }

    /** "instagram" (token IGAA…, graph.instagram.com) ou "facebook" (token EAA…, graph.facebook.com). */
    public static function host(): string
    {
        return setting('ig_api_host', 'instagram') === 'facebook' ? 'facebook' : 'instagram';
    }

    /** Chamada à API da Meta. Lança RuntimeException com a mensagem da Meta. */
    public static function api(string $method, string $path, array $query = [], ?array $json = null, ?string $token = null, ?string $host = null): array
    {
        $token = $token ?? setting('ig_access_token');
        if (!$token) throw new RuntimeException('Instagram não conectado (cole o token em Admin › Instagram).');
        $host = ($host ?? self::host()) === 'facebook' ? 'graph.facebook.com' : 'graph.instagram.com';
        $url = "https://$host/" . self::API_VERSION . '/' . ltrim($path, '/');
        if ($query) $url .= '?' . http_build_query($query);

        $headers = ['Authorization: Bearer ' . $token];
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CUSTOMREQUEST => $method]);
        if ($json !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json, JSON_UNESCAPED_UNICODE));
        } elseif ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, '');
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false) throw new RuntimeException('Falha de rede: ' . $err);
        $data = json_decode((string)$body, true) ?? [];
        if ($code >= 400 || isset($data['error'])) {
            throw new RuntimeException("Instagram $code: " . ($data['error']['message'] ?? $body));
        }
        return $data;
    }

    /**
     * Valida e salva o token, detectando o tipo:
     *  - IGAA… (login do Instagram)  → graph.instagram.com
     *  - EAA…  (login unificado / Facebook) → graph.facebook.com, achando a conta do Instagram
     *    pela Página do Facebook ligada a ela, ou pelo ID informado.
     */
    public static function saveToken(string $token, string $igId = ''): array
    {
        $token = trim(preg_replace('/^Bearer\s+/i', '', trim($token)));
        $igId = preg_replace('/\D/', '', $igId);
        if ($token === '') throw new RuntimeException('Cole o token.');

        if (!str_starts_with($token, 'EAA')) {
            $me = self::api('GET', 'me', ['fields' => 'user_id,username'], null, $token, 'instagram');
            self::store('instagram', $token, (string)$me['user_id'], (string)$me['username'], time() + 60 * 86400);
            $msgs = ['Instagram conectado: @' . $me['username'] . '.'];
            try {
                self::api('POST', $me['user_id'] . '/subscribed_apps', ['subscribed_fields' => 'comments']);
                $msgs[] = 'Conta inscrita no webhook de comentários.';
            } catch (Throwable $ex) {
                $msgs[] = 'Aviso: não inscreveu no webhook (' . $ex->getMessage() . '). O cron funciona mesmo assim.';
            }
            return $msgs;
        }

        // Token do Facebook: primeiro tenta pelas Páginas (dá o token da Página, usado nas mensagens)
        $errors = [];
        try {
            $pages = self::api('GET', 'me/accounts', [
                'fields' => 'id,name,access_token,instagram_business_account{id,username}', 'limit' => 100,
            ], null, $token, 'facebook')['data'] ?? [];
            foreach ($pages as $pg) {
                $ig = $pg['instagram_business_account'] ?? null;
                if (!$ig || ($igId !== '' && $ig['id'] !== $igId)) continue;
                self::store('facebook', (string)($pg['access_token'] ?? $token), (string)$ig['id'], (string)($ig['username'] ?? ''), 0);
                setting_set('ig_page_id', (string)$pg['id']);
                return ['Instagram conectado: @' . ($ig['username'] ?? $ig['id']) . ' (via Página “' . $pg['name'] . '”).'];
            }
            $errors[] = 'nenhuma Página com conta do Instagram ligada';
        } catch (Throwable $ex) {
            $errors[] = $ex->getMessage();
        }

        // Token de usuário do sistema / token já da conta: usa o ID informado
        if ($igId !== '') {
            $ig = self::api('GET', $igId, ['fields' => 'id,username'], null, $token, 'facebook');
            self::store('facebook', $token, (string)$ig['id'], (string)($ig['username'] ?? ''), 0);
            return ['Instagram conectado: @' . ($ig['username'] ?? $ig['id']) . '.'];
        }
        throw new RuntimeException('Token do Facebook (EAA…) detectado, mas não achei a conta do Instagram (' . implode('; ', $errors)
            . '). Preencha o campo “ID da conta do Instagram” e tente de novo.');
    }

    private static function store(string $host, string $token, string $igId, string $username, int $expiresAt): void
    {
        setting_set('ig_api_host', $host);
        setting_set('ig_access_token', $token);
        setting_set('ig_user_id', $igId);
        setting_set('ig_username', $username);
        setting_set('ig_token_expires_at', $expiresAt ? (string)$expiresAt : null);
        setting_set('ig_page_id', null);
    }

    /** Renova o token (vale 60 dias). Só renova se faltarem menos de 15 dias, a não ser com $force. */
    public static function refreshToken(bool $force = false): ?string
    {
        $token = setting('ig_access_token');
        if (!$token || self::host() === 'facebook') return null; // tokens do Facebook (Página/sistema) não vencem
        $exp = (int)setting('ig_token_expires_at', '0');
        if (!$force && $exp - time() > 15 * 86400) return null;

        $ch = curl_init('https://graph.instagram.com/refresh_access_token?' . http_build_query([
            'grant_type' => 'ig_refresh_token', 'access_token' => $token,
        ]));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
        $data = json_decode((string)curl_exec($ch), true) ?? [];
        curl_close($ch);
        if (empty($data['access_token'])) {
            throw new RuntimeException('Falha ao renovar token: ' . ($data['error']['message'] ?? json_encode($data)));
        }
        setting_set('ig_access_token', $data['access_token']);
        $newExp = time() + (int)($data['expires_in'] ?? 5184000);
        setting_set('ig_token_expires_at', (string)$newExp);
        return 'Token renovado até ' . date('d/m/Y', $newExp) . '.';
    }

    /** Posts recentes da conta. */
    public static function media(int $limit = 24): array
    {
        return self::api('GET', (setting('ig_user_id') ?: 'me') . '/media', [
            'fields' => 'id,caption,media_type,permalink,thumbnail_url,media_url,timestamp',
            'limit' => $limit,
        ])['data'] ?? [];
    }

    /** Produtos ligados a um post, na ordem (code 1, 2, 3...). */
    public static function postProducts(string $mediaId): array
    {
        $st = db()->prepare('SELECT pp.code, p.* FROM ig_post_products pp JOIN products p ON p.id = pp.product_id
            WHERE pp.media_id = ? AND p.active = 1 ORDER BY pp.code');
        $st->execute([$mediaId]);
        return $st->fetchAll();
    }

    /** Cadastra/atualiza um post. $productIds vazio = remove o post. */
    public static function savePost(string $mediaId, array $productIds, string $keyword, bool $active, string $caption = '', string $permalink = ''): void
    {
        $pdo = db();
        $pdo->prepare('DELETE FROM ig_post_products WHERE media_id = ?')->execute([$mediaId]);
        if (!$productIds) {
            $pdo->prepare('DELETE FROM ig_posts WHERE media_id = ?')->execute([$mediaId]);
            return;
        }
        $keyword = trim($keyword) ?: self::DEFAULT_KEYWORD;
        $st = $pdo->prepare('SELECT 1 FROM ig_posts WHERE media_id = ?');
        $st->execute([$mediaId]);
        if ($st->fetchColumn()) {
            $pdo->prepare('UPDATE ig_posts SET keyword = ?, active = ? WHERE media_id = ?')
                ->execute([$keyword, $active ? 1 : 0, $mediaId]);
        } else {
            $pdo->prepare('INSERT INTO ig_posts (media_id, keyword, active, caption, permalink, created_at) VALUES (?,?,?,?,?,?)')
                ->execute([$mediaId, $keyword, $active ? 1 : 0, mb_substr($caption, 0, 500), $permalink, now()]);
        }
        $ins = $pdo->prepare('INSERT INTO ig_post_products (media_id, code, product_id) VALUES (?,?,?)');
        foreach (array_values($productIds) as $i => $pid) $ins->execute([$mediaId, $i + 1, (int)$pid]);
    }

    /** Link enviado no direct: passa pelo ir.php (conta o clique) quando a URL do site é conhecida. */
    public static function productLink(array $p, string $mediaId = ''): string
    {
        global $CONFIG;
        if (PHP_SAPI === 'cli' && empty($CONFIG['base_url'])) return product_destination($p);
        return go_url($p, 'instagram', 'direct', $mediaId);
    }

    private static function normalize(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        return strtr($s, ['á'=>'a','à'=>'a','ã'=>'a','â'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','õ'=>'o','ô'=>'o','ú'=>'u','ü'=>'u','ç'=>'c']);
    }

    private static function fill(string $tpl, array $vars): string
    {
        foreach ($vars as $k => $v) $tpl = str_replace('{' . $k . '}', (string)$v, $tpl);
        return $tpl;
    }

    /**
     * Regra principal (webhook e cron).
     * $c = id, text, username, from_id, media_id, timestamp (int|null), parent_id (opcional)
     * Retorna o status gravado.
     */
    public static function handleComment(array $c, string $source): string
    {
        $pdo = db();
        $myId = (string)setting('ig_user_id', '');
        $myUser = (string)setting('ig_username', '');

        // Comentários da própria conta (evita loop) e respostas dentro de threads
        if (($myId !== '' && $c['from_id'] === $myId) || ($myUser !== '' && strcasecmp($c['username'], $myUser) === 0)) return 'proprio';
        if (!empty($c['parent_id'])) return 'resposta_em_thread';

        $st = $pdo->prepare('SELECT * FROM ig_posts WHERE media_id = ? AND active = 1');
        $st->execute([$c['media_id']]);
        $post = $st->fetch();
        if (!$post) return 'post_nao_mapeado';

        // Só comentários feitos depois do cadastro do post e dentro dos 7 dias da Meta
        if (!empty($c['timestamp'])) {
            if ($c['timestamp'] < strtotime((string)$post['created_at'])) return 'antigo';
            if ($c['timestamp'] < time() - 7 * 86400 + 600) return 'mais_de_7_dias';
        }

        // Cada comentário é processado uma vez só
        $ignore = db_is_mysql() ? 'INSERT IGNORE' : 'INSERT OR IGNORE';
        $ins = $pdo->prepare("$ignore INTO ig_comment_log (comment_id, media_id, username, text, source, status, created_at) VALUES (?,?,?,?,?,?,?)");
        $ins->execute([$c['id'], $c['media_id'], $c['username'], $c['text'], $source, 'recebido', now()]);
        if ($ins->rowCount() === 0) return 'duplicado';

        $done = function (string $status, ?int $pid = null, ?string $err = null) use ($pdo, $c): string {
            $pdo->prepare('UPDATE ig_comment_log SET status = ?, product_id = ?, error = ? WHERE comment_id = ?')
                ->execute([$status, $pid, $err, $c['id']]);
            return $status;
        };

        // Palavra-chave (várias separadas por vírgula; ignora acento e maiúscula)
        $text = self::normalize($c['text']);
        $hit = false;
        foreach (explode(',', (string)$post['keyword']) as $kw) {
            $kw = trim(self::normalize($kw));
            if ($kw !== '' && str_contains($text, $kw)) { $hit = true; break; }
        }
        if (!$hit) return $done('sem_palavra_chave');

        $products = self::postProducts($c['media_id']);
        if (!$products) return $done('sem_produto');

        // Um produto: manda ele. Vários: o número citado no comentário, senão a lista.
        $chosen = null;
        if (count($products) === 1) {
            $chosen = $products[0];
        } elseif (preg_match_all('/\d+/', $c['text'], $m)) {
            foreach ($products as $p) {
                if (in_array((string)$p['code'], $m[0], true)) { $chosen = $p; break; }
            }
        }

        $vars = ['username' => $c['username']];
        if ($chosen) {
            $dm = self::fill((string)setting('ig_dm_single', self::DEFAULT_DM_SINGLE), $vars + [
                'title' => mb_strimwidth((string)$chosen['title'], 0, 120, '…'),
                'price' => money((float)$chosen['price']),
                'link'  => self::productLink($chosen, (string)$c['media_id']),
            ]);
        } else {
            $dm = self::fill((string)setting('ig_dm_list', self::DEFAULT_DM_LIST), $vars) . "\n";
            foreach ($products as $p) {
                $dm .= "\n{$p['code']}) " . mb_strimwidth((string)$p['title'], 0, 60, '…') . ' – ' . money((float)$p['price'])
                     . "\n👉 " . self::productLink($p, (string)$c['media_id']) . "\n";
            }
        }
        $dm = mb_substr($dm, 0, 1000);
        $pid = $chosen ? (int)$chosen['id'] : null;

        try {
            self::api('POST', ($myId ?: 'me') . '/messages', [], [
                'recipient' => ['comment_id' => $c['id']],
                'message'   => ['text' => $dm],
            ]);
        } catch (Throwable $ex) {
            error_log('[achadosmeli] Instagram DM: ' . $ex->getMessage());
            return $done('erro_dm', $pid, $ex->getMessage());
        }

        try {
            $replies = array_values(array_filter(array_map('trim', explode("\n", (string)setting('ig_public_replies', self::DEFAULT_PUBLIC_REPLIES)))));
            if ($replies) {
                self::api('POST', $c['id'] . '/replies', ['message' => self::fill($replies[array_rand($replies)], $vars)]);
            }
        } catch (Throwable $ex) {
            error_log('[achadosmeli] Instagram resposta pública: ' . $ex->getMessage());
            return $done('dm_ok_resposta_erro', $pid, $ex->getMessage());
        }

        return $done('enviado', $pid);
    }

    /* ==================== Publicação de Reels (admin/reels.php) ==================== */

    /** Pasta pública onde o MP4 fica até a Meta buscar (a API exige um video_url público). */
    public static function reelsDir(): string
    {
        $dir = __DIR__ . '/../assets/reels';
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        return $dir;
    }

    /** Uso do limite de publicação (100 posts/24 h). Também serve para testar a permissão de publicar. */
    public static function publishingLimit(): array
    {
        $r = self::api('GET', setting('ig_user_id') . '/content_publishing_limit', ['fields' => 'config,quota_usage']);
        $d = $r['data'][0] ?? [];
        return ['usados' => (int)($d['quota_usage'] ?? 0), 'limite' => (int)($d['config']['quota_total'] ?? 100)];
    }

    /** Registra um Reels a publicar. $video = nome do arquivo em assets/reels. */
    /** $status = 'enviado' (publica em seguida) ou 'rascunho' (fica no painel até alguém clicar em Publicar). */
    public static function reelSave(string $video, string $caption, ?int $productId, string $mlId, string $title, bool $shareToFeed = true, string $status = 'enviado'): int
    {
        db()->prepare('INSERT INTO ig_reels (video, caption, product_id, ml_id, title, share_to_feed, status, created_at, updated_at)
            VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$video, mb_substr($caption, 0, 2200), $productId ?: null, $mlId, mb_substr($title, 0, 250), $shareToFeed ? 1 : 0, $status, now(), now()]);
        return (int)db()->lastInsertId();
    }

    public static function reel(int $id): array
    {
        $st = db()->prepare('SELECT * FROM ig_reels WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch();
        if (!$r) throw new RuntimeException("Reels #$id não encontrado.");
        return $r;
    }

    private static function reelUpdate(int $id, array $f): void
    {
        $f['updated_at'] = now();
        $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($f)));
        db()->prepare("UPDATE ig_reels SET $set WHERE id = ?")->execute([...array_values($f), $id]);
    }

    /**
     * Passo 1: cria o contêiner do Reels na Meta (ela baixa o vídeo do nosso site).
     * Passo 2 (reelCheck): quando o processamento termina, publica.
     */
    public static function reelStart(int $id): array
    {
        $r = self::reel($id);
        if (!self::isConnected()) throw new RuntimeException('Instagram não conectado (Admin › Instagram).');
        $file = self::reelsDir() . '/' . $r['video'];
        if (!is_file($file)) throw new RuntimeException('Arquivo do vídeo não está mais no servidor.');
        try {
            $c = self::api('POST', setting('ig_user_id') . '/media', [], [
                'media_type'    => 'REELS',
                'video_url'     => url('assets/reels/' . rawurlencode($r['video'])),
                'caption'       => (string)$r['caption'],
                'share_to_feed' => (bool)$r['share_to_feed'],
            ]);
        } catch (Throwable $ex) {
            self::reelUpdate($id, ['status' => 'erro', 'error' => self::friendlyError($ex->getMessage())]);
            throw new RuntimeException(self::friendlyError($ex->getMessage()));
        }
        self::reelUpdate($id, ['container_id' => (string)$c['id'], 'status' => 'processando', 'error' => null]);
        return self::reel($id);
    }

    /** Confere o processamento na Meta e publica quando estiver pronto. Seguro chamar várias vezes. */
    public static function reelCheck(int $id): array
    {
        $r = self::reel($id);
        if ($r['status'] !== 'processando' || !$r['container_id']) return $r;

        $st = self::api('GET', $r['container_id'], ['fields' => 'status_code,status']);
        $code = (string)($st['status_code'] ?? '');
        if ($code === 'IN_PROGRESS' || $code === '') return $r;
        if ($code === 'ERROR' || $code === 'EXPIRED') {
            self::reelUpdate($id, ['status' => 'erro', 'error' => 'Meta recusou o vídeo: ' . ($st['status'] ?? $code)]);
            return self::reel($id);
        }
        if ($code !== 'FINISHED' && $code !== 'PUBLISHED') return $r;

        try {
            $pub = self::api('POST', setting('ig_user_id') . '/media_publish', [], ['creation_id' => $r['container_id']]);
        } catch (Throwable $ex) {
            self::reelUpdate($id, ['status' => 'erro', 'error' => self::friendlyError($ex->getMessage())]);
            return self::reel($id);
        }
        $mediaId = (string)$pub['id'];
        $permalink = '';
        try { $permalink = (string)(self::api('GET', $mediaId, ['fields' => 'permalink'])['permalink'] ?? ''); } catch (Throwable) {}

        self::reelUpdate($id, ['status' => 'publicado', 'media_id' => $mediaId, 'permalink' => $permalink,
            'published_at' => now(), 'error' => null]);

        // Liga o Reels ao produto: quem comentar "quero" recebe o link no direct (handleComment)
        if ($r['product_id']) {
            self::savePost($mediaId, [(int)$r['product_id']], self::DEFAULT_KEYWORD, true, (string)$r['caption'], $permalink);
        }
        // A Meta já copiou o vídeo: libera espaço no servidor
        @unlink(self::reelsDir() . '/' . $r['video']);
        return self::reel($id);
    }

    /** Cron: termina de publicar os Reels em processamento e apaga envios parciais antigos. */
    public static function reelsPending(): array
    {
        $out = [];
        foreach (db()->query("SELECT id FROM ig_reels WHERE status = 'processando' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN) as $id) {
            try {
                $r = self::reelCheck((int)$id);
                $out[] = "reels #$id: {$r['status']}" . ($r['error'] ? " ({$r['error']})" : '');
            } catch (Throwable $ex) {
                $out[] = "reels #$id: ERRO " . $ex->getMessage();
            }
        }
        foreach (glob(self::reelsDir() . '/tmp-*.part') ?: [] as $f) {
            if (filemtime($f) < time() - 86400) @unlink($f);
        }
        return $out;
    }

    /** Mensagens da Meta mais comuns, explicadas. */
    private static function friendlyError(string $msg): string
    {
        if (preg_match('/permission|#10|#200|not authorized|instagram_content_publish|content_publish/i', $msg)) {
            return $msg . ' — o token não tem a permissão de publicar. Gere um token novo com '
                . '“instagram_content_publish” (ou “instagram_business_content_publish”) e cole em Admin › Instagram.';
        }
        if (preg_match('/2207042|rate limit/i', $msg)) return $msg . ' — limite de 100 publicações em 24 h atingido.';
        return $msg;
    }

    /** Usado pelo cron: lê os comentários dos posts ativos mais recentes. */
    public static function poll(int $maxPosts = 10): array
    {
        $posts = db()->query('SELECT media_id FROM ig_posts WHERE active = 1 ORDER BY created_at DESC LIMIT ' . $maxPosts)
            ->fetchAll(PDO::FETCH_COLUMN);
        $out = [];
        foreach ($posts as $mediaId) {
            try {
                $res = self::api('GET', $mediaId . '/comments', ['fields' => 'id,text,timestamp,username,from', 'limit' => 50]);
            } catch (Throwable $ex) {
                $out[] = "$mediaId: ERRO " . $ex->getMessage();
                continue;
            }
            foreach ($res['data'] ?? [] as $cm) {
                try {
                    $status = self::handleComment([
                        'id'        => (string)$cm['id'],
                        'text'      => (string)($cm['text'] ?? ''),
                        'username'  => (string)($cm['from']['username'] ?? $cm['username'] ?? ''),
                        'from_id'   => (string)($cm['from']['id'] ?? ''),
                        'media_id'  => (string)$mediaId,
                        'timestamp' => isset($cm['timestamp']) ? strtotime((string)$cm['timestamp']) : null,
                    ], 'cron');
                    if (!in_array($status, ['duplicado', 'antigo', 'proprio', 'mais_de_7_dias'], true)) $out[] = "{$cm['id']}: $status";
                } catch (Throwable $ex) {
                    $out[] = "{$cm['id']}: ERRO " . $ex->getMessage();
                }
            }
        }
        return ['posts' => count($posts), 'log' => $out];
    }

    /* ==================== Métricas (painel admin/insights.php) ==================== */

    /** Métricas de Reels e de posts do feed (API v22+: "views" substituiu plays/impressions). */
    private const REELS_METRICS = 'views,reach,likes,comments,shares,saved,total_interactions,ig_reels_avg_watch_time,ig_reels_video_view_total_time';
    private const FEED_METRICS = 'views,reach,likes,comments,shares,saved,total_interactions,follows,profile_visits';
    private const ACCOUNT_METRICS = 'reach,views,accounts_engaged,total_interactions,profile_links_taps';

    /**
     * Busca as métricas da conta e dos posts recentes e grava em ig_media_stats / ig_account_daily.
     * A Meta limita 200 chamadas/hora: o cron chama isto no máximo a cada $everyMinutes.
     * Precisa da permissão instagram_manage_insights (token EAA…) ou instagram_business_manage_insights (IGAA…).
     */
    public static function syncStats(int $maxMedia = 30, int $everyMinutes = 0): array
    {
        $last = (int)setting('ig_stats_synced_at', '0');
        if ($everyMinutes > 0 && time() - $last < $everyMinutes * 60) return ['pulado' => true, 'log' => []];

        $pdo = db();
        $igId = setting('ig_user_id') ?: 'me';
        $log = [];
        $permError = null;

        // 1) Posts recentes: contagens públicas (sempre funcionam) + insights (precisam da permissão)
        $media = self::api('GET', "$igId/media", [
            'fields' => 'id,caption,media_type,media_product_type,permalink,thumbnail_url,media_url,timestamp,like_count,comments_count',
            'limit' => $maxMedia,
        ])['data'] ?? [];
        $up = $pdo->prepare(db_is_mysql()
            ? 'REPLACE INTO ig_media_stats (media_id, media_type, caption, permalink, thumbnail, posted_at, views, reach, likes, comments, shares, saved,
                interactions, follows, profile_visits, avg_watch_ms, total_watch_ms, insights_ok, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            : 'INSERT OR REPLACE INTO ig_media_stats (media_id, media_type, caption, permalink, thumbnail, posted_at, views, reach, likes, comments, shares, saved,
                interactions, follows, profile_visits, avg_watch_ms, total_watch_ms, insights_ok, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $ok = 0;
        foreach ($media as $m) {
            $type = (string)($m['media_product_type'] ?? '') === 'REELS' ? 'REELS' : (string)($m['media_type'] ?? 'IMAGE');
            $v = [];
            if ($permError === null) {
                try {
                    $res = self::api('GET', $m['id'] . '/insights', ['metric' => $type === 'REELS' ? self::REELS_METRICS : self::FEED_METRICS]);
                    foreach ($res['data'] ?? [] as $row) $v[$row['name']] = (int)($row['values'][0]['value'] ?? $row['total_value']['value'] ?? 0);
                    $ok++;
                } catch (Throwable $ex) {
                    if (preg_match('/permission|#10\b|#200\b|not authorized|manage_insights/i', $ex->getMessage())) $permError = $ex->getMessage();
                    else $log[] = "{$m['id']}: " . $ex->getMessage(); // ex.: post muito novo ou anterior à conta profissional
                }
            }
            $up->execute([
                (string)$m['id'], $type, mb_substr((string)($m['caption'] ?? ''), 0, 500), (string)($m['permalink'] ?? ''),
                (string)($m['thumbnail_url'] ?? $m['media_url'] ?? ''), date('Y-m-d H:i:s', strtotime((string)$m['timestamp'])),
                $v['views'] ?? null, $v['reach'] ?? null, $v['likes'] ?? (int)($m['like_count'] ?? 0), $v['comments'] ?? (int)($m['comments_count'] ?? 0),
                $v['shares'] ?? null, $v['saved'] ?? null, $v['total_interactions'] ?? null, $v['follows'] ?? null, $v['profile_visits'] ?? null,
                $v['ig_reels_avg_watch_time'] ?? null, $v['ig_reels_video_view_total_time'] ?? null, $v ? 1 : 0, now(),
            ]);
        }
        $log[] = count($media) . " post(s) lidos, $ok com métricas completas";

        // 2) Conta: seguidores (foto do dia) + alcance/visualizações por dia dos últimos 7 dias
        $acc = self::api('GET', $igId, ['fields' => 'followers_count,follows_count,media_count']);
        $today = date('Y-m-d');
        $ins = $pdo->prepare(db_is_mysql()
            ? 'INSERT INTO ig_account_daily (day, followers_total, updated_at) VALUES (?,?,?) ON DUPLICATE KEY UPDATE followers_total = VALUES(followers_total), updated_at = VALUES(updated_at)'
            : 'INSERT INTO ig_account_daily (day, followers_total, updated_at) VALUES (?,?,?) ON CONFLICT(day) DO UPDATE SET followers_total = excluded.followers_total, updated_at = excluded.updated_at');
        $ins->execute([$today, (int)($acc['followers_count'] ?? 0), now()]);
        setting_set('ig_followers', (string)(int)($acc['followers_count'] ?? 0));

        if ($permError === null) {
            $have = $pdo->query("SELECT day FROM ig_account_daily WHERE reach IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
            $ensure = $pdo->prepare(db_is_mysql() ? 'INSERT IGNORE INTO ig_account_daily (day, updated_at) VALUES (?,?)' : 'INSERT OR IGNORE INTO ig_account_daily (day, updated_at) VALUES (?,?)');
            $set = $pdo->prepare('UPDATE ig_account_daily SET reach = ?, views = ?, accounts_engaged = ?, interactions = ?, link_taps = ?, updated_at = ? WHERE day = ?');
            for ($i = 6; $i >= 0; $i--) {
                $day = date('Y-m-d', strtotime("-$i days"));
                // Hoje e ontem ainda mudam: sempre atualiza. Dias anteriores só se faltarem.
                if ($i > 1 && in_array($day, $have, true)) continue;
                try {
                    $res = self::api('GET', "$igId/insights", [
                        'metric' => self::ACCOUNT_METRICS, 'period' => 'day', 'metric_type' => 'total_value',
                        'since' => strtotime("$day 00:00:00"), 'until' => min(time(), strtotime("$day 23:59:59")),
                    ]);
                    $v = [];
                    foreach ($res['data'] ?? [] as $row) $v[$row['name']] = (int)($row['total_value']['value'] ?? 0);
                    $ensure->execute([$day, now()]);
                    $set->execute([$v['reach'] ?? 0, $v['views'] ?? 0, $v['accounts_engaged'] ?? 0, $v['total_interactions'] ?? 0, $v['profile_links_taps'] ?? 0, now(), $day]);
                } catch (Throwable $ex) {
                    if (preg_match('/permission|#10\b|#200\b|not authorized|manage_insights/i', $ex->getMessage())) { $permError = $ex->getMessage(); break; }
                    $log[] = "conta $day: " . $ex->getMessage();
                }
            }
        }

        setting_set('ig_stats_synced_at', (string)time());
        setting_set('ig_stats_error', $permError ? self::insightsPermissionHelp($permError) : null);
        if ($permError) $log[] = 'SEM PERMISSÃO DE MÉTRICAS: ' . $permError;
        return ['pulado' => false, 'log' => $log];
    }

    private static function insightsPermissionHelp(string $msg): string
    {
        $perm = self::host() === 'facebook' ? 'instagram_manage_insights' : 'instagram_business_manage_insights';
        return "A Meta não liberou as métricas ($msg). Gere um token novo incluindo a permissão “$perm” e cole em Admin › Instagram. "
            . 'Enquanto isso, só curtidas e comentários aparecem.';
    }
}
