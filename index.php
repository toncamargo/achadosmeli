<?php
require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/src/layout.php';

$pdo = db();
$q = trim((string)($_GET['q'] ?? ''));
$catSlug = (string)($_GET['c'] ?? '');
$sort = (string)($_GET['o'] ?? '');
$frete = !empty($_GET['frete']);
$cupom = !empty($_GET['cupom']);
$max = isset($_GET['max']) ? max(0, (float)$_GET['max']) : 0;
$page = max(1, (int)($_GET['p'] ?? 1));
$per = (int)($CONFIG['per_page'] ?? 24);

$cats = $pdo->query('SELECT c.* FROM categories c WHERE EXISTS
    (SELECT 1 FROM products p WHERE p.category_id = c.id AND p.active = 1) ORDER BY sort_order, name')->fetchAll();
$currentCat = null;
foreach ($cats as $c) if ($c['slug'] === $catSlug) $currentCat = $c;

$isHome = $q === '' && !$currentCat && $sort === '' && !$frete && !$cupom && !$max && $page === 1;

$discExpr = 'CASE WHEN p.original_price > p.price THEN (p.original_price - p.price) / p.original_price ELSE 0 END';

/* ---------- listagem (usada na home e nas páginas filtradas) ---------- */
$where = ['p.active = 1'];
$args = [];
if ($q !== '') foreach (preg_split('/\s+/', $q) as $w) { $where[] = 'p.title LIKE ?'; $args[] = "%$w%"; }
if ($currentCat) { $where[] = 'p.category_id = ?'; $args[] = (int)$currentCat['id']; }
if ($frete) $where[] = 'p.free_shipping = 1';
if ($cupom) $where[] = "(p.coupon IS NOT NULL AND p.coupon <> '')";
if ($max) { $where[] = 'p.price <= ?'; $args[] = $max; }
$w = 'WHERE ' . implode(' AND ', $where);
$order = match ($sort) {
    'desconto' => "$discExpr DESC, p.created_at DESC",
    'menor' => 'p.price ASC',
    'maior' => 'p.price DESC',
    'populares' => 'p.clicks DESC, p.created_at DESC',
    default => 'p.created_at DESC',
};
$st = $pdo->prepare("SELECT COUNT(*) FROM products p $w");
$st->execute($args);
$total = (int)$st->fetchColumn();
$pages = max(1, (int)ceil($total / $per));
$offset = ($page - 1) * $per;
$st = $pdo->prepare("SELECT p.* FROM products p $w ORDER BY $order LIMIT $per OFFSET $offset");
$st->execute($args);
$products = $st->fetchAll();

/* ---------- blocos da home ---------- */
$slides = $deal = $offers = $shelves = [];
if ($isHome) {
    // 1) banners de campanha cadastrados no admin
    foreach (active_banners() as $b) {
        $hl = trim((string)$b['highlight']);
        $suffix = '';
        if (preg_match('/^(.*?)\s*(OFF|DESCONTO)$/iu', $hl, $m) && $m[1] !== '') { $hl = $m[1]; $suffix = $m[2]; }
        $link = (string)$b['link_url'];
        $slides[] = [
            'campaign' => true, 'kicker' => $b['kicker'], 'title' => $b['title'], 'prefix' => $b['prefix'],
            'mega' => $hl, 'suffix' => $suffix, 'pill' => $b['pill'], 'footnote' => $b['footnote'], 'image' => $b['image'],
            'href' => $link === '' ? url('?o=desconto') : (preg_match('~^https?://~', $link) ? $link : url(ltrim($link, '/'))),
            'external' => (bool)preg_match('~^https?://~', $link) && !str_starts_with($link, base_url()),
        ];
    }
    // 2) sem banner ativo: usa os produtos em destaque (ou os de maior desconto)
    if (!$slides) {
        $rows = $pdo->query("SELECT p.*, c.name AS cat_name FROM products p LEFT JOIN categories c ON c.id = p.category_id
            WHERE p.active = 1 AND p.featured = 1 ORDER BY p.updated_at DESC LIMIT 6")->fetchAll();
        if (!$rows) {
            $rows = $pdo->query("SELECT p.*, c.name AS cat_name FROM products p LEFT JOIN categories c ON c.id = p.category_id
                WHERE p.active = 1 ORDER BY $discExpr DESC LIMIT 4")->fetchAll();
        }
        foreach ($rows as $b) {
            $pct = discount_pct($b);
            $slides[] = [
                'campaign' => false, 'kicker' => $b['cat_name'] ?? 'Achado do dia', 'title' => mb_strimwidth($b['title'], 0, 70, '…'),
                'prefix' => $pct ? 'ATÉ' : '', 'mega' => $pct ? $pct . '%' : money((float)$b['price']), 'suffix' => $pct ? 'OFF' : '',
                'pill' => ($pct ? 'Por ' . money((float)$b['price']) . ' · ' : '') . 'Ver oferta',
                'footnote' => '*Preço conferido em ' . date('d/m/Y', strtotime((string)$b['price_updated_at'])) . '. Pode mudar sem aviso.',
                'image' => $b['image'], 'href' => product_url($b), 'external' => false,
            ];
        }
    }
    $deal = $pdo->query("SELECT p.* FROM products p WHERE p.active = 1 ORDER BY $discExpr DESC, p.created_at DESC LIMIT 1")->fetch() ?: null;
    $st = $pdo->prepare("SELECT p.* FROM products p WHERE p.active = 1 AND p.id <> ? ORDER BY $discExpr DESC, p.created_at DESC LIMIT 12");
    $st->execute([$deal['id'] ?? 0]);
    $offers = $st->fetchAll();
    $st = $pdo->prepare('SELECT * FROM products WHERE active = 1 AND category_id = ? ORDER BY created_at DESC LIMIT 12');
    foreach ($cats as $c) {
        $st->execute([$c['id']]);
        $rows = $st->fetchAll();
        if (count($rows) >= 4) $shelves[] = [$c, $rows];
    }
}

$qs = function (array $over) use ($q, $catSlug, $sort, $frete, $cupom, $max) {
    $base = ['q' => $q, 'c' => $catSlug, 'o' => $sort, 'frete' => $frete ? 1 : '', 'cupom' => $cupom ? 1 : '', 'max' => $max ?: ''];
    return '?' . http_build_query(array_filter(array_merge($base, $over), fn($v) => $v !== '' && $v !== null));
};

$title = null;
if (!$isHome) {
    $title = match (true) {
        $q !== '' => "Resultados para \"$q\"",
        (bool)$currentCat => $currentCat['name'],
        $cupom => 'Achados com cupom',
        $frete => 'Achados com frete grátis',
        $max > 0 => 'Achados até ' . money($max),
        $sort === 'desconto' => 'Ofertas: maiores descontos',
        $sort === 'populares' => 'Mais clicados',
        default => 'Todos os achados',
    };
}

site_header([
    'title' => $title,
    'canonical' => url('') . ($currentCat ? '?c=' . $currentCat['slug'] : ''),
    'description' => $currentCat ? "Achados e ofertas de {$currentCat['name']} no Mercado Livre." : null,
]);
?>
<main>
<?php if ($isHome): ?>

  <!-- ===== Banner rotativo ===== -->
  <section class="hero">
    <?php if ($slides): ?>
    <div class="hero-slider" data-slider>
      <?php foreach ($slides as $i => $sl): ?>
      <a class="slide <?= $i === 0 ? 'on' : '' ?> <?= $sl['campaign'] ? 'campaign' : '' ?>" href="<?= e($sl['href']) ?>"
         <?= $sl['external'] ? 'target="_blank" rel="sponsored nofollow noopener"' : '' ?>>
        <div class="wrap slide-in">
          <div class="slide-txt">
            <?php if ($sl['campaign']): ?>
              <div class="slide-head"><?php if ($sl['kicker']): ?><b><?= e($sl['kicker']) ?></b><?php endif; ?> <?= e($sl['title']) ?></div>
            <?php else: ?>
              <span class="kicker"><?= e($sl['kicker']) ?></span>
              <h2><?= e($sl['title']) ?></h2>
            <?php endif; ?>
            <?php if ($sl['mega'] !== ''): ?>
              <div class="mega <?= mb_strlen($sl['mega']) > 6 ? 'mega-long' : '' ?>"><?php if ($sl['prefix']): ?><small><?= e($sl['prefix']) ?></small><?php endif; ?><?= e($sl['mega']) ?><?php if ($sl['suffix']): ?><span><?= e($sl['suffix']) ?></span><?php endif; ?></div>
            <?php endif; ?>
            <?php if ($sl['pill']): ?><span class="slide-cta"><?= e($sl['pill']) ?></span><?php endif; ?>
            <?php if ($sl['footnote']): ?><p class="fine"><?= e($sl['footnote']) ?></p><?php endif; ?>
          </div>
          <?php if ($sl['image']): ?><div class="slide-img"><img src="<?= e($sl['image']) ?>" alt="" <?= $i ? 'loading="lazy"' : '' ?>></div><?php endif; ?>
        </div>
      </a>
      <?php endforeach; ?>
      <?php if (count($slides) > 1): ?>
        <button class="hero-arrow prev" type="button" aria-label="Anterior"><?= icon('chevl') ?></button>
        <button class="hero-arrow next" type="button" aria-label="Próximo"><?= icon('chev') ?></button>
        <div class="dots"><?php foreach ($slides as $i => $_): ?><button type="button" class="<?= $i === 0 ? 'on' : '' ?>" aria-label="Banner <?= $i + 1 ?>"></button><?php endforeach; ?></div>
      <?php endif; ?>
    </div>
    <?php else: ?>
      <div class="wrap hero-empty"><h1><?= e(setting('site_tagline', 'Os melhores achados do Mercado Livre, todo dia.')) ?></h1></div>
    <?php endif; ?>
  </section>

  <div class="wrap home">
    <!-- ===== Atalhos ===== -->
    <section class="shortcuts">
      <?php
      $wa = setting('whatsapp_group');
      $shortcuts = [
        ['truck', 'Frete grátis', 'Achados que chegam sem pagar entrega.', url('?frete=1'), 'Mostrar produtos'],
        ['pct', 'Maiores descontos', 'As quedas de preço mais fortes do dia.', url('?o=desconto'), 'Ver ofertas'],
        ['coin', 'Até R$100', 'Achados baratinhos que valem a pena.', url('?max=100'), 'Mostrar produtos'],
        ['fire', 'Mais clicados', 'O que a galera está de olho agora.', url('?o=populares'), 'Ver tendências'],
        ['ticket', 'Com cupom', 'Ofertas com cupom para pagar menos.', url('?cupom=1'), 'Ver cupons'],
        ['chat', 'Grupo de ofertas', 'Receba os achados primeiro no celular.', $wa ?: url('?o=desconto'), $wa ? 'Entrar no grupo' : 'Ver ofertas'],
      ];
      foreach ($shortcuts as [$ic, $t, $d, $href, $btn]): ?>
        <a class="sc" href="<?= e($href) ?>" <?= str_starts_with($href, 'http') && !str_starts_with($href, base_url()) ? 'target="_blank" rel="noopener"' : '' ?>>
          <h3><?= e($t) ?></h3>
          <span class="sc-ico"><?= icon($ic) ?></span>
          <p><?= e($d) ?></p>
          <span class="sc-btn"><?= e($btn) ?></span>
        </a>
      <?php endforeach; ?>
    </section>

    <!-- ===== Oferta do dia + Ofertas ===== -->
    <?php if ($deal): ?>
    <section class="deal-row">
      <div class="panel deal">
        <h2>Oferta do dia</h2>
        <?php if (trim((string)($deal['badge'] ?? '')) === '') $deal['badge'] = 'OFERTA DO DIA'; product_card($deal, 'pc-deal'); ?>
      </div>
      <?php if ($offers): ?>
      <div class="panel shelf offers">
        <div class="panel-head"><h2>Ofertas</h2><a class="more" href="<?= e(url('?o=desconto')) ?>">Mostrar todas as ofertas</a></div>
        <div class="shelf-wrap">
          <button class="arrow prev" type="button" aria-label="Anterior"><?= icon('chevl') ?></button>
          <div class="shelf-track"><?php foreach ($offers as $p) product_card($p, 'in-shelf'); ?></div>
          <button class="arrow next" type="button" aria-label="Próximo"><?= icon('chev') ?></button>
        </div>
      </div>
      <?php endif; ?>
    </section>
    <?php endif; ?>

    <!-- ===== Prateleiras por categoria ===== -->
    <?php foreach ($shelves as [$c, $rows]) product_shelf('Achados de ' . $c['name'], $rows, url('?c=' . $c['slug'])); ?>

    <!-- ===== Todos ===== -->
    <?php if ($products): ?>
    <section class="panel">
      <div class="panel-head"><h2>Achados recentes</h2><a class="more" href="<?= e(url('?o=novos')) ?>">Ver todos</a></div>
      <div class="grid"><?php foreach ($products as $p) product_card($p); ?></div>
    </section>
    <?php else: ?>
      <section class="panel empty">Ainda não há achados publicados. Volte em breve!</section>
    <?php endif; ?>
  </div>

<?php else: ?>

  <div class="wrap listing">
    <div class="list-head">
      <div>
        <h1><?= e($title) ?></h1>
        <span class="muted"><?= $total ?> resultado<?= $total === 1 ? '' : 's' ?></span>
      </div>
      <form class="sort" method="get">
        <?php foreach (['q' => $q, 'c' => $catSlug, 'frete' => $frete ? 1 : '', 'cupom' => $cupom ? 1 : '', 'max' => $max ?: ''] as $k => $v): if ($v === '') continue; ?>
          <input type="hidden" name="<?= $k ?>" value="<?= e((string)$v) ?>">
        <?php endforeach; ?>
        <label>Ordenar por
          <select name="o" onchange="this.form.submit()">
            <?php foreach (['novos' => 'Mais recentes', 'desconto' => 'Maior desconto', 'populares' => 'Mais clicados', 'menor' => 'Menor preço', 'maior' => 'Maior preço'] as $k => $l): ?>
              <option value="<?= $k ?>" <?= ($sort ?: 'novos') === $k ? 'selected' : '' ?>><?= $l ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      </form>
    </div>

    <div class="list-body">
      <aside class="filters-side">
        <h3>Categorias</h3>
        <a href="<?= e($qs(['c' => '', 'p' => ''])) ?>" class="<?= $currentCat ? '' : 'on' ?>">Todas</a>
        <?php foreach ($cats as $c): ?>
          <a href="<?= e($qs(['c' => $c['slug'], 'p' => ''])) ?>" class="<?= $currentCat && $currentCat['id'] === $c['id'] ? 'on' : '' ?>"><?= e($c['name']) ?></a>
        <?php endforeach; ?>
        <h3>Filtros</h3>
        <a href="<?= e($qs(['frete' => $frete ? '' : 1, 'p' => ''])) ?>" class="tog <?= $frete ? 'on' : '' ?>">Frete grátis</a>
        <a href="<?= e($qs(['cupom' => $cupom ? '' : 1, 'p' => ''])) ?>" class="tog <?= $cupom ? 'on' : '' ?>">Com cupom</a>
        <a href="<?= e($qs(['max' => $max == 100 ? '' : 100, 'p' => ''])) ?>" class="tog <?= $max == 100 ? 'on' : '' ?>">Até R$100</a>
      </aside>
      <div class="list-main">
        <?php if ($products): ?>
          <div class="grid"><?php foreach ($products as $p) product_card($p); ?></div>
        <?php else: ?>
          <div class="panel empty">Nenhum achado encontrado. Tente outra busca ou remova filtros.</div>
        <?php endif; ?>
        <?php if ($pages > 1): ?>
        <nav class="pager">
          <?php if ($page > 1): ?><a href="<?= e($qs(['p' => $page - 1])) ?>">← Anterior</a><?php endif; ?>
          <span><?= $page ?> de <?= $pages ?></span>
          <?php if ($page < $pages): ?><a href="<?= e($qs(['p' => $page + 1])) ?>">Próxima →</a><?php endif; ?>
        </nav>
        <?php endif; ?>
      </div>
    </div>
  </div>

<?php endif; ?>
</main>
<?php site_footer();
