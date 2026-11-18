<?php
/**
 * Insights: de onde vêm os cliques, o que converte e como está o catálogo.
 * Dados: click_events (gravado pelo ir.php), products, categories, agente_coletas, ig_*, price_history.
 */
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/admin_layout.php';
require_admin();

$pdo = db();
$days = (int)($_GET['d'] ?? 7);
if (!in_array($days, [1, 7, 30, 90], true)) $days = 7;
$from = date('Y-m-d H:i:s', strtotime("-$days days"));
$prevFrom = date('Y-m-d H:i:s', strtotime('-' . ($days * 2) . ' days'));
$today = date('Y-m-d');
$meta = max(1, (int)setting('agente_meta_dia', '5'));

$q = function (string $sql, array $args = []) use ($pdo) {
    $st = $pdo->prepare($sql);
    $st->execute($args);
    return $st;
};
$pct = fn($a, $b) => $b > 0 ? round($a / $b * 100) : 0;

/* ---------- números principais ---------- */
$k = $q("SELECT COUNT(*) c, COUNT(DISTINCT visitor) v, COUNT(DISTINCT product_id) p
    FROM click_events WHERE is_admin = 0 AND created_at >= ?", [$from])->fetch();
$prev = (int)$q("SELECT COUNT(*) FROM click_events WHERE is_admin = 0 AND created_at >= ? AND created_at < ?", [$prevFrom, $from])->fetchColumn();
$adminClicks = (int)$q("SELECT COUNT(*) FROM click_events WHERE is_admin = 1 AND created_at >= ?", [$from])->fetchColumn();
$active = (int)$pdo->query('SELECT COUNT(*) FROM products WHERE active = 1')->fetchColumn();
$firstEvent = (string)$pdo->query('SELECT MIN(created_at) FROM click_events')->fetchColumn();
$clicks = (int)$k['c'];
$delta = $prev > 0 ? round(($clicks - $prev) / $prev * 100) : null;

/* ---------- séries ---------- */
$daily = [];
for ($i = min($days, 30) - 1; $i >= 0; $i--) $daily[date('Y-m-d', strtotime("-$i days"))] = 0;
foreach ($q("SELECT SUBSTR(created_at,1,10) d, COUNT(*) n FROM click_events WHERE is_admin = 0 AND created_at >= ? GROUP BY SUBSTR(created_at,1,10)", [$from]) as $r) {
    if (isset($daily[$r['d']])) $daily[$r['d']] = (int)$r['n'];
}
$hours = array_fill(0, 24, 0);
foreach ($q("SELECT SUBSTR(created_at,12,2) h, COUNT(*) n FROM click_events WHERE is_admin = 0 AND created_at >= ? GROUP BY SUBSTR(created_at,12,2)", [$from]) as $r) {
    $hours[(int)$r['h']] = (int)$r['n'];
}
$group = fn(string $col) => $q("SELECT COALESCE($col,'(sem)') k, COUNT(*) n, COUNT(DISTINCT visitor) v FROM click_events
    WHERE is_admin = 0 AND created_at >= ? GROUP BY $col ORDER BY n DESC", [$from])->fetchAll();
$byOrigin = $group('origin');
$byPlacement = $group('placement');
$byDevice = $group('device');

/* ---------- categorias ---------- */
$catClicks = [];
foreach ($q("SELECT category_id, COUNT(*) n FROM click_events WHERE is_admin = 0 AND created_at >= ? GROUP BY category_id", [$from]) as $r) {
    $catClicks[(int)$r['category_id']] = (int)$r['n'];
}
$catToday = [];
foreach ($q("SELECT category_id, COUNT(*) n FROM agente_coletas WHERE run_date = ? GROUP BY category_id", [$today]) as $r) {
    $catToday[(int)$r['category_id']] = (int)$r['n'];
}
$cats = $pdo->query("SELECT c.id, c.name, c.slug, SUM(CASE WHEN p.active = 1 THEN 1 ELSE 0 END) ativos, MAX(p.created_at) ultimo
    FROM categories c LEFT JOIN products p ON p.category_id = c.id GROUP BY c.id, c.name, c.slug, c.sort_order ORDER BY c.sort_order, c.name")->fetchAll();
foreach ($cats as &$c) {
    $c['cliques'] = $catClicks[(int)$c['id']] ?? 0;
    $c['cpp'] = $c['ativos'] ? $c['cliques'] / $c['ativos'] : 0;
    $c['hoje'] = $catToday[(int)$c['id']] ?? 0;
}
unset($c);
$catsByCpp = $cats;
usort($catsByCpp, fn($a, $b) => $b['cpp'] <=> $a['cpp']);

/* ---------- produtos ---------- */
$top = $q("SELECT p.id, p.title, p.image, p.price, p.original_price, p.active, c.name cat, COUNT(*) n, COUNT(DISTINCT e.visitor) v,
        SUM(CASE WHEN e.origin = 'instagram' THEN 1 ELSE 0 END) ig
    FROM click_events e JOIN products p ON p.id = e.product_id LEFT JOIN categories c ON c.id = p.category_id
    WHERE e.is_admin = 0 AND e.created_at >= ?
    GROUP BY p.id, p.title, p.image, p.price, p.original_price, p.active, c.name ORDER BY n DESC LIMIT 15", [$from])->fetchAll();

// Ativos há mais tempo que o período e sem nenhum clique nele: candidatos a trocar
$cold = $q("SELECT p.id, p.title, p.price, p.created_at, c.name cat FROM products p LEFT JOIN categories c ON c.id = p.category_id
    WHERE p.active = 1 AND p.created_at < ? AND NOT EXISTS
      (SELECT 1 FROM click_events e WHERE e.product_id = p.id AND e.is_admin = 0 AND e.created_at >= ?)
    ORDER BY p.created_at LIMIT 30", [$from, $from])->fetchAll();

/* ---------- saúde do catálogo ---------- */
$autoAff = setting('aff_matt_word') && setting('aff_matt_tool');
$h = $q("SELECT
    SUM(CASE WHEN price_updated_at IS NULL OR price_updated_at < ? THEN 1 ELSE 0 END) preco_velho,
    SUM(CASE WHEN affiliate_url IS NULL OR affiliate_url = '' THEN 1 ELSE 0 END) sem_link,
    SUM(CASE WHEN image IS NULL OR image = '' THEN 1 ELSE 0 END) sem_foto,
    SUM(CASE WHEN category_id IS NULL THEN 1 ELSE 0 END) sem_cat,
    SUM(CASE WHEN posted_at IS NULL THEN 1 ELSE 0 END) nao_divulgado
    FROM products WHERE active = 1", [date('Y-m-d H:i:s', strtotime('-48 hours'))])->fetch();
$collectedToday = array_sum($catToday);
$goalToday = $meta * count($cats);

// Quedas de preço nos últimos 7 dias (bom gatilho para post)
$drops = $q("SELECT p.id, p.title, p.price, h.mx FROM products p
    JOIN (SELECT product_id, MAX(price) mx FROM price_history WHERE recorded_at >= ? GROUP BY product_id) h ON h.product_id = p.id
    WHERE p.active = 1 AND p.price < h.mx ORDER BY (h.mx - p.price) / h.mx DESC LIMIT 10", [date('Y-m-d H:i:s', strtotime('-7 days'))])->fetchAll();

/* ---------- Instagram ---------- */
$igClicks = [];
foreach ($q("SELECT media_id, COUNT(*) n FROM click_events WHERE is_admin = 0 AND origin = 'instagram' AND media_id IS NOT NULL AND created_at >= ? GROUP BY media_id", [$from]) as $r) {
    $igClicks[$r['media_id']] = (int)$r['n'];
}
$ig = $q("SELECT ps.media_id, ps.caption, ps.permalink, ps.created_at,
        COUNT(l.comment_id) comentarios,
        SUM(CASE WHEN l.status = 'enviado' THEN 1 ELSE 0 END) enviados,
        SUM(CASE WHEN l.status LIKE 'erro%' THEN 1 ELSE 0 END) erros
    FROM ig_posts ps LEFT JOIN ig_comment_log l ON l.media_id = ps.media_id AND l.created_at >= ?
    GROUP BY ps.media_id, ps.caption, ps.permalink, ps.created_at ORDER BY ps.created_at DESC LIMIT 20", [$from])->fetchAll();
$igSent = array_sum(array_column($ig, 'enviados'));
$igClicksTotal = array_sum($igClicks);

/* ---------- leituras automáticas ---------- */
$tips = [];
if ($clicks === 0) {
    $tips[] = ['warn', $firstEvent === ''
        ? 'O rastreamento detalhado acabou de ser ligado: os números aparecem conforme os visitantes clicarem. Volte em alguns dias.'
        : "Nenhum clique de visitante nos últimos $days dia(s). Divulgar é o próximo passo: Reels com \"Comente QUERO\" e textos prontos em Produtos → Editar."];
} else {
    $topHour = array_search(max($hours), $hours, true);
    $tips[] = ['ok', sprintf('Pico de cliques às %02dh. Publique Reels e posts cerca de 1h antes (%02dh) para pegar a onda.', $topHour, ($topHour + 23) % 24)];
    $best = $catsByCpp[0] ?? null;
    if ($best && $best['cpp'] > 0) {
        $tips[] = ['ok', sprintf('"%s" é a categoria que mais converte: %s clique(s) por produto ativo. Vale coletar mais ofertas nela.', $best['name'], number_format($best['cpp'], 1, ',', '.'))];
    }
    $worst = array_values(array_filter($catsByCpp, fn($c) => $c['ativos'] >= 3 && $c['cliques'] === 0));
    if ($worst) $tips[] = ['warn', 'Sem clique no período apesar de ter produtos: ' . implode(', ', array_column(array_slice($worst, 0, 4), 'name')) . '. Teste ofertas com mais desconto ou troque a seleção.'];
    $mob = 0;
    foreach ($byDevice as $d) if ($d['k'] === 'mobile') $mob = (int)$d['n'];
    if ($pct($mob, $clicks) >= 60) $tips[] = ['ok', $pct($mob, $clicks) . '% dos cliques são no celular: priorize como o site fica no mobile (fotos, preço e botão visíveis sem rolar).'];
    if ($top && $clicks >= 10 && $pct((int)$top[0]['n'], $clicks) >= 25) {
        $tips[] = ['ok', 'Um único produto puxa ' . $pct((int)$top[0]['n'], $clicks) . '% dos cliques ("' . mb_strimwidth($top[0]['title'], 0, 50, '…') . '"). Procure similares e coloque em destaque.'];
    }
    if ($delta !== null) $tips[] = [$delta >= 0 ? 'ok' : 'warn', sprintf('Cliques %s %d%% em relação aos %d dias anteriores.', $delta >= 0 ? 'subiram' : 'caíram', abs($delta), $days)];
}
if ($igSent > 0) {
    $tips[] = ['ok', sprintf('Instagram: %d link(s) enviados por direct e %d clique(s) vindos deles (%d%%).', $igSent, $igClicksTotal, $pct($igClicksTotal, $igSent))];
}
if ($collectedToday < $goalToday) {
    $tips[] = ['warn', sprintf('Coleta de hoje: %d de %d ofertas (meta de %d por categoria). Rode o agente coletor-ofertas-ml.', $collectedToday, $goalToday, $meta)];
}
if ($drops) $tips[] = ['ok', count($drops) . ' produto(s) ficaram mais baratos nos últimos 7 dias: ótimo gatilho para post "baixou o preço".'];
if ((int)$h['preco_velho'] > 0) $tips[] = ['warn', (int)$h['preco_velho'] . ' produto(s) ativos com preço sem conferir há mais de 48h. Rode "Atualizar preços".'];
if ($adminClicks > 0) $tips[] = ['muted', "$adminClicks clique(s) seus (logado no painel) foram separados e não entram nos números."];

/* ---------- helpers de tela ---------- */
$labels = [
    'site' => 'Site', 'instagram' => 'Instagram', 'social' => 'Texto de divulgação (WhatsApp/Telegram)', 'legado' => 'Link antigo (produto.php)',
    'direct' => 'Direct do Instagram', 'home-banner' => 'home › banner', 'outro' => 'Outro', 'mobile' => 'Celular', 'desktop' => 'Computador', 'tablet' => 'Tablet', '(sem)' => 'Não identificado',
];
$label = fn(string $k) => $labels[$k] ?? str_replace('-', ' › ', $k);

function bars(array $rows, int $total, callable $label): void
{
    if (!$rows) { echo '<p class="muted small">Sem dados no período.</p>'; return; }
    $max = max(array_column($rows, 'n')) ?: 1;
    echo '<div class="ins-bars">';
    foreach ($rows as $r) {
        $w = max(2, round($r['n'] / $max * 100));
        $share = $total ? round($r['n'] / $total * 100) : 0;
        echo '<div class="ins-bar" title="' . e($label((string)$r['k'])) . ': ' . (int)$r['n'] . ' cliques, ' . (int)$r['v'] . ' visitantes">'
           . '<span class="ins-bar-l">' . e($label((string)$r['k'])) . '</span>'
           . '<span class="ins-bar-t"><i style="width:' . $w . '%"></i></span>'
           . '<span class="ins-bar-v">' . (int)$r['n'] . ' <small>' . $share . '%</small></span></div>';
    }
    echo '</div>';
}

function columns(array $series, callable $tick, callable $tip): void
{
    $max = max($series) ?: 1;
    echo '<div class="ins-cols" style="--n:' . count($series) . '">';
    foreach ($series as $key => $n) {
        $hgt = $n ? max(3, round($n / $max * 100)) : 0;
        echo '<div class="ins-col" title="' . e($tip($key, $n)) . '"><i style="height:' . $hgt . '%"></i><span>' . e($tick($key)) . '</span></div>';
    }
    echo '</div>';
}

admin_header('Insights', 'insights', false);
?>
<div class="ins-head">
  <h1>Insights</h1>
  <nav class="ins-range">
    <?php foreach ([1 => 'Hoje', 7 => '7 dias', 30 => '30 dias', 90 => '90 dias'] as $d => $t): ?>
      <a href="?d=<?= $d ?>" class="<?= $d === $days ? 'on' : '' ?>"><?= $t ?></a>
    <?php endforeach; ?>
  </nav>
</div>

<div class="stats ins-stats">
  <div><b><?= $clicks ?></b><span>cliques enviados ao ML<?php if ($delta !== null): ?> · <em class="<?= $delta >= 0 ? 'up' : 'down' ?>"><?= $delta >= 0 ? '▲' : '▼' ?> <?= abs($delta) ?>%</em><?php endif; ?></span></div>
  <div><b><?= (int)$k['v'] ?></b><span>visitantes únicos que clicaram</span></div>
  <div><b><?= $pct((int)$k['p'], $active) ?>%</b><span>dos <?= $active ?> produtos ativos receberam clique</span></div>
  <div class="<?= $collectedToday < $goalToday ? 'warn' : '' ?>"><b><?= $collectedToday ?>/<?= $goalToday ?></b><span>ofertas coletadas hoje (meta)</span></div>
</div>

<div class="card ins-tips">
  <h2>O que os números dizem</h2>
  <ul>
    <?php foreach ($tips as [$type, $txt]): ?><li class="tip-<?= $type ?>"><?= e($txt) ?></li><?php endforeach; ?>
  </ul>
</div>

<div class="ins-grid">
  <div class="card">
    <h2>Cliques por dia</h2>
    <?php columns($daily, fn($d) => date('d/m', strtotime($d)), fn($d, $n) => date('d/m', strtotime($d)) . ": $n cliques"); ?>
  </div>
  <div class="card">
    <h2>Cliques por hora do dia</h2>
    <?php columns($hours, fn($h) => $h % 3 === 0 ? sprintf('%02dh', $h) : '', fn($h, $n) => sprintf('%02dh: %d cliques', $h, $n)); ?>
  </div>
  <div class="card">
    <h2>De onde vêm os cliques</h2>
    <?php bars($byOrigin, $clicks, $label); ?>
    <h2 class="mt">Aparelho</h2>
    <?php bars($byDevice, $clicks, $label); ?>
  </div>
  <div class="card">
    <h2>Onde o produto estava no site</h2>
    <?php bars($byPlacement, $clicks, $label); ?>
  </div>
</div>

<div class="card">
  <h2>Categorias</h2>
  <table class="tbl">
    <thead><tr><th>Categoria</th><th>Ativos</th><th>Cliques</th><th>Cliques por produto</th><th>Coleta hoje</th><th>Último produto</th></tr></thead>
    <tbody>
    <?php foreach ($catsByCpp as $c): ?>
      <tr>
        <td><a href="index.php?cat=<?= (int)$c['id'] ?>"><?= e($c['name']) ?></a></td>
        <td><?= (int)$c['ativos'] ?></td>
        <td><?= $c['cliques'] ?></td>
        <td><?= number_format($c['cpp'], 1, ',', '.') ?></td>
        <td><em class="tag <?= $c['hoje'] >= $meta ? 'tag-ok' : 'tag-warn' ?>"><?= $c['hoje'] ?>/<?= $meta ?></em></td>
        <td class="small muted"><?= $c['ultimo'] ? date('d/m H:i', strtotime((string)$c['ultimo'])) : '—' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="card">
  <h2>Produtos mais clicados</h2>
  <?php if (!$top): ?><p class="muted small">Sem cliques de visitantes no período.</p><?php else: ?>
  <table class="tbl">
    <thead><tr><th></th><th>Produto</th><th>Preço</th><th>Cliques</th><th>Visitantes</th><th>Do Instagram</th></tr></thead>
    <tbody>
    <?php foreach ($top as $p): $off = discount_pct($p); ?>
      <tr class="<?= $p['active'] ? '' : 'off' ?>">
        <td><img class="thumb" src="<?= e($p['image']) ?>" alt="" loading="lazy"></td>
        <td><a href="editar.php?id=<?= (int)$p['id'] ?>"><?= e(mb_strimwidth($p['title'], 0, 80, '…')) ?></a><div class="muted small"><?= e($p['cat'] ?? '—') ?></div></td>
        <td class="nowrap"><?= money((float)$p['price']) ?><?php if ($off): ?> <em class="tag">-<?= $off ?>%</em><?php endif; ?></td>
        <td><b><?= (int)$p['n'] ?></b></td>
        <td><?= (int)$p['v'] ?></td>
        <td><?= (int)$p['ig'] ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<div class="ins-grid">
  <div class="card">
    <h2>Instagram: posts com "QUERO"</h2>
    <?php if (!$ig): ?><p class="muted small">Nenhum post cadastrado.</p><?php else: ?>
    <table class="tbl">
      <thead><tr><th>Post</th><th>Comentários</th><th>Links enviados</th><th>Cliques</th></tr></thead>
      <tbody>
      <?php foreach ($ig as $r): $n = $igClicks[$r['media_id']] ?? 0; ?>
        <tr>
          <td><?php if ($r['permalink']): ?><a href="<?= e($r['permalink']) ?>" target="_blank" rel="noopener"><?php endif; ?><?= e(mb_strimwidth(trim((string)$r['caption']) ?: $r['media_id'], 0, 50, '…')) ?><?php if ($r['permalink']): ?></a><?php endif; ?>
            <div class="muted small"><?= date('d/m', strtotime((string)$r['created_at'])) ?></div></td>
          <td><?= (int)$r['comentarios'] ?></td>
          <td><?= (int)$r['enviados'] ?><?php if ((int)$r['erros']): ?> <em class="tag tag-warn"><?= (int)$r['erros'] ?> erro</em><?php endif; ?></td>
          <td><?= $n ?><?php if ((int)$r['enviados']): ?> <small class="muted">(<?= $pct($n, (int)$r['enviados']) ?>%)</small><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>Saúde do catálogo</h2>
    <ul class="ins-health">
      <li class="<?= $h['preco_velho'] ? 'bad' : '' ?>"><b><?= (int)$h['preco_velho'] ?></b> com preço sem conferir há +48h</li>
      <?php if (!$autoAff): ?><li class="<?= $h['sem_link'] ? 'bad' : '' ?>"><b><?= (int)$h['sem_link'] ?></b> sem link de afiliado</li><?php endif; ?>
      <li class="<?= $h['sem_foto'] ? 'bad' : '' ?>"><b><?= (int)$h['sem_foto'] ?></b> sem foto</li>
      <li class="<?= $h['sem_cat'] ? 'bad' : '' ?>"><b><?= (int)$h['sem_cat'] ?></b> sem categoria</li>
      <li><b><?= (int)$h['nao_divulgado'] ?></b> ainda não divulgados (<a href="index.php?f=notposted">ver fila</a>)</li>
    </ul>
    <h2 class="mt">Baixaram de preço (7 dias)</h2>
    <?php if (!$drops): ?><p class="muted small">Nenhuma queda registrada.</p><?php else: ?>
      <ul class="ins-list">
      <?php foreach ($drops as $d): ?>
        <li><a href="editar.php?id=<?= (int)$d['id'] ?>"><?= e(mb_strimwidth($d['title'], 0, 55, '…')) ?></a>
          <span class="nowrap"><s class="muted"><?= money((float)$d['mx']) ?></s> <?= money((float)$d['price']) ?></span></li>
      <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <h2>Sem clique no período <small class="muted">(ativos desde antes de <?= date('d/m', strtotime($from)) ?>)</small></h2>
  <?php if ($firstEvent === '' || $firstEvent > $from): ?>
    <p class="muted small">Esta lista fica confiável depois que o rastreamento tiver dados do período inteiro<?= $firstEvent ? ' (começou em ' . date('d/m H:i', strtotime($firstEvent)) . ')' : '' ?>.</p>
  <?php elseif (!$cold): ?>
    <p class="muted small">Todos os produtos ativos tiveram clique. 👏</p>
  <?php else: ?>
    <p class="muted small">Candidatos a trocar por ofertas novas ou com mais desconto.</p>
    <ul class="ins-list">
    <?php foreach ($cold as $p): ?>
      <li><a href="editar.php?id=<?= (int)$p['id'] ?>"><?= e(mb_strimwidth($p['title'], 0, 70, '…')) ?></a>
        <span class="muted small"><?= e($p['cat'] ?? '—') ?> · <?= money((float)$p['price']) ?></span></li>
    <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>
<?php
admin_footer();
