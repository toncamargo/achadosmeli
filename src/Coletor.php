<?php
declare(strict_types=1);

/**
 * Coleta automática de produtos do Mercado Livre.
 *
 * Cada "fonte" (tabela ml_sources) diz ONDE procurar e O QUE aceitar:
 *  - type = 'keyword'     → busca por palavra-chave (anúncios ou catálogo, conforme a permissão do app)
 *  - type = 'bestsellers' → 20 mais vendidos de uma categoria do ML (/highlights)
 *
 * Filtros: desconto mínimo, faixa de preço, só frete grátis, só FULL, palavras proibidas no título.
 * Produtos novos entram na categoria do site escolhida; ficam ativos na hora (auto_publish)
 * ou inativos aguardando sua revisão. Produtos que já existem só têm o preço atualizado.
 */

/** Campos editáveis de uma fonte (usado no formulário do admin). */
function source_defaults(): array
{
    return [
        'name' => '', 'type' => 'keyword', 'query' => '', 'ml_category_id' => '', 'category_id' => null,
        'min_discount' => 0, 'min_price' => null, 'max_price' => null,
        'free_shipping_only' => 0, 'full_only' => 0, 'exclude_words' => '',
        'max_items' => 20, 'auto_publish' => 0, 'every_hours' => 12, 'active' => 1,
    ];
}

function sources(bool $onlyActive = false): array
{
    $sql = 'SELECT s.*, c.name AS cat_name FROM ml_sources s LEFT JOIN categories c ON c.id = s.category_id'
        . ($onlyActive ? ' WHERE s.active = 1' : '') . ' ORDER BY s.name';
    return db()->query($sql)->fetchAll();
}

function source_find(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM ml_sources WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** Fontes ativas cujo intervalo (every_hours) já passou desde a última execução. */
function sources_due(): array
{
    return array_values(array_filter(sources(true), function ($s) {
        if (empty($s['last_run_at'])) return true;
        return strtotime($s['last_run_at']) + max(1, (int)$s['every_hours']) * 3600 <= time() + 60;
    }));
}

/**
 * Executa uma fonte. Retorna o resumo:
 * ['found' => n, 'created' => n, 'updated' => n, 'skipped' => n, 'errors' => [...]]
 */
function collect_source(array $src): array
{
    $sum = ['found' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];
    $max = max(1, min(50, (int)$src['max_items']));
    $started = now();

    try {
        $candidates = collect_candidates($src, $max, $sum);
        // + os mais vendidos do tipo USER_PRODUCT, que já entraram como ignorados
        $sum['found'] = count($candidates) + $sum['skipped'];

        foreach ($candidates as $d) {
            $why = collect_reject_reason($src, $d);
            if ($why !== null) { $sum['skipped']++; continue; }

            [$id, $isNew] = upsert_product($d, $src['category_id'] !== null ? (int)$src['category_id'] : null);
            if ($isNew) {
                db()->prepare('UPDATE products SET source_id = ?, active = ? WHERE id = ?')
                    ->execute([(int)$src['id'], (int)$src['auto_publish'] ? 1 : 0, $id]);
                $sum['created']++;
            } else {
                $sum['updated']++;
            }
        }
    } catch (Throwable $ex) {
        $sum['errors'][] = $ex->getMessage();
    }

    $status = sprintf('%d encontrados · %d novos · %d atualizados · %d fora dos filtros',
        $sum['found'], $sum['created'], $sum['updated'], $sum['skipped']);
    if ($sum['errors']) $status = 'ERRO: ' . $sum['errors'][0] . ($sum['found'] ? " ($status)" : '');

    db()->prepare('UPDATE ml_sources SET last_run_at = ?, last_status = ? WHERE id = ?')
        ->execute([now(), mb_substr($status, 0, 250), (int)$src['id']]);
    db()->prepare('INSERT INTO ml_collect_log (source_id, started_at, finished_at, found, created, updated, skipped, errors)
        VALUES (?,?,?,?,?,?,?,?)')
        ->execute([(int)$src['id'], $started, now(), $sum['found'], $sum['created'], $sum['updated'], $sum['skipped'],
            $sum['errors'] ? implode("\n", $sum['errors']) : null]);

    return $sum;
}

/** Busca na API os produtos da fonte, já com preço (lista de arrays normalizados). */
function collect_candidates(array $src, int $max, array &$sum): array
{
    $items = [];    // ids de anúncios (MLB123...)
    $catalog = [];  // ids de produtos de catálogo (/p/MLB123...)
    $ready = [];    // resultados que já vieram com preço

    if ($src['type'] === 'bestsellers') {
        $cat = trim((string)$src['ml_category_id']);
        if ($cat === '') throw new RuntimeException('Informe a categoria do Mercado Livre (ex.: MLB1648).');
        foreach (MercadoLivre::highlights($cat) as $h) {
            if ($h['type'] === 'PRODUCT') $catalog[] = $h['id'];
            elseif ($h['type'] === 'ITEM') $items[] = $h['id'];
            // USER_PRODUCT: não há endpoint público para resolver o anúncio → ignorado
            else $sum['skipped']++;
        }
    } else {
        $q = trim((string)$src['query']);
        if ($q === '') throw new RuntimeException('Informe a palavra-chave da busca.');
        $filters = [];
        if (trim((string)$src['ml_category_id']) !== '') $filters['category'] = trim((string)$src['ml_category_id']);
        // busca um pouco mais que o limite, porque parte cai nos filtros
        $res = MercadoLivre::search($q, 0, min(50, $max * 2), $filters);
        foreach ($res['results'] as $r) {
            if ($res['mode'] === 'catalog') $catalog[] = $r['ml_id'];
            elseif ($r['price'] !== null) $ready[] = $r + ['status' => 'active'];
            else $items[] = $r['ml_id'];
        }
    }

    $out = $ready;
    if ($items) {
        foreach (MercadoLivre::fetchMany(array_values(array_unique($items))) as $d) $out[] = $d;
    }
    foreach (array_slice(array_values(array_unique($catalog)), 0, $max * 2) as $id) {
        try { $out[] = MercadoLivre::fetch($id, true); }
        catch (Throwable $ex) { $sum['errors'][] = "$id: " . $ex->getMessage(); }
    }

    // ordena: maior desconto primeiro (mantém a ordem da API em caso de empate) e corta no limite
    $order = array_flip(array_keys($out));
    uksort($out, function ($a, $b) use ($out, $order) {
        return [discount_pct($out[$b]), $order[$a]] <=> [discount_pct($out[$a]), $order[$b]];
    });
    $accepted = [];
    foreach ($out as $d) {
        if (count($accepted) >= $max) break;
        if (collect_reject_reason($src, $d) === null) $accepted[] = $d;
    }
    // devolve os aceitos + os rejeitados (para contar como "fora dos filtros")
    $rejected = array_filter($out, fn($d) => collect_reject_reason($src, $d) !== null);
    return array_merge($accepted, array_values($rejected));
}

/** Motivo para recusar o produto (ou null se passa em todos os filtros). */
function collect_reject_reason(array $src, array $d): ?string
{
    $price = (float)($d['price'] ?? 0);
    if ($price <= 0) return 'sem preço / sem estoque';
    if (($d['status'] ?? 'active') !== 'active') return 'anúncio inativo';
    if ((int)$src['min_discount'] > 0 && discount_pct($d) < (int)$src['min_discount']) return 'desconto baixo';
    if ($src['min_price'] !== null && $src['min_price'] !== '' && $price < (float)$src['min_price']) return 'preço abaixo do mínimo';
    if ($src['max_price'] !== null && $src['max_price'] !== '' && $price > (float)$src['max_price']) return 'preço acima do máximo';
    if ((int)$src['free_shipping_only'] && empty($d['free_shipping'])) return 'sem frete grátis';
    if ((int)$src['full_only'] && empty($d['is_full'])) return 'não é FULL';
    $title = mb_strtolower((string)$d['title']);
    foreach (preg_split('/[,;\n]+/', mb_strtolower((string)$src['exclude_words'])) as $w) {
        $w = trim($w);
        if ($w !== '' && str_contains($title, $w)) return "contém \"$w\"";
    }
    return null;
}

/** Últimas execuções (para a tela de coleta). */
function collect_log(int $limit = 30): array
{
    return db()->query('SELECT l.*, s.name AS source_name FROM ml_collect_log l
        LEFT JOIN ml_sources s ON s.id = l.source_id ORDER BY l.id DESC LIMIT ' . (int)$limit)->fetchAll();
}
