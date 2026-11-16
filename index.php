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
        // Banner "arte pronta": sem etiqueta, destaque nem botão -> a imagem ocupa o slide inteiro
        $full = trim((string)$b['kicker']) === '' && $hl === '' && trim((string)$b['pill']) === '';
        $slides[] = [
            'full' => $full, 'alt' => (string)($b['title'] ?: 'Banner de ofertas'),
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
                'full' => false, 'campaign' => false, 'kicker' => $b['cat_name'] ?? 'Achado do dia', 'title' => mb_strimwidth($b['title'], 0, 70, '…'),
                'prefix' => $pct ? 'ATÉ' : '', 'mega' => $pct ? $pct . '%' : money((float)$b['price']), 'suffix' => $pct ? 'OFF' : '',
                'pill' => ($pct ? 'Por ' . money((float)$b['price']) . ' · ' : '') . 'Ver oferta',
                'footnote' => '*Preço conferido em ' . date('d/m/Y', strtotime((string)$b['price_updated_at'])) . '. Pode mudar sem aviso.',
                'image' => $b['image'], 'href' => go_url($b), 'external' => true,
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
        $sort === 'desconto' => 'Ofertas',
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
      <a class="slide <?= $i === 0 ? 'on' : '' ?> <?= $sl['campaign'] ? 'campaign' : '' ?> <?= $sl['full'] ? 'full' : '' ?>" href="<?= e($sl['href']) ?>"
         <?= $sl['external'] ? 'target="_blank" rel="sponsored nofollow noopener"' : '' ?>>
        <?php if ($sl['full']): ?>
        <img class="slide-fill" src="<?= e($sl['image']) ?>" alt="" aria-hidden="true" <?= $i ? 'loading="lazy"' : '' ?>>
        <img class="slide-art" src="<?= e($sl['image']) ?>" alt="<?= e($sl['alt']) ?>" <?= $i ? 'loading="lazy"' : 'fetchpriority="high"' ?>>
        <?php else: ?>
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
        <?php endif; ?>
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
    <!-- ===== Categorias em destaque ===== -->
    <section class="shortcuts">
      <?php
      $shortcuts = [
        ['casa', 'Casa', 'Tudo para deixar seu lar completo.', url('?c=casa-e-cozinha'), 'Ver produtos'],
        ['ferramentas', 'Ferramentas', 'Equipamentos para obras e projetos.', url('?c=ferramentas'), 'Ver ferramentas'],
        ['eletronicos', 'Eletrônicos', 'Celulares, notebooks e gadgets em oferta.', url('?c=tecnologia'), 'Ver eletrônicos'],
        ['moda', 'Moda', 'Roupas, tênis e acessórios com estilo.', url('?c=moda'), 'Ver moda'],
        ['automotivo', 'Acessórios automotivos', 'Cuidado completo para o seu veículo.', url('?c=acessorios-automotivos'), 'Ver acessórios'],
        ['ofertas', 'Ofertas exclusivas', 'Os maiores descontos do dia.', url('?o=desconto'), 'Ver ofertas'],
      ];
      foreach ($shortcuts as [$ic, $t, $d, $href, $btn]): ?>
        <a class="sc" href="<?= e($href) ?>">
          <h3><?= e($t) ?></h3>
          <span class="sc-ill"><img src="<?= e(url('assets/categorias/' . $ic . '.svg')) ?>" alt="" width="120" height="120"></span>
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

    <!-- ===== Anúncios: 2 banners lado a lado ===== -->
    <?php ad_section_duplo(); ?>

    <!-- ===== Prateleiras por categoria (banner largo e painel de 3 cards no meio) ===== -->
    <?php foreach ($shelves as $si => [$c, $rows]) {
        product_shelf($c['name'], $rows, url('?c=' . $c['slug']));
        if ($si === 1) ad_section_largo();
        if ($si === 3) ad_section_trio();
    }
    if (count($shelves) < 2) ad_section_largo();
    if (count($shelves) < 4) ad_section_trio(); ?>

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

<?php
  /* ---------- cabeçalho das páginas de categoria (abas com ícones) ---------- */
  $tabIcons = [
    'todas' => '<path d="M26 6h14v14L22 38a3 3 0 0 1-4 0l-8-8a3 3 0 0 1 0-4z"/><circle cx="34" cy="12" r="2"/><path d="M18 30l8-8"/><circle cx="19" cy="23" r="2"/><circle cx="25.5" cy="29.5" r="2"/>',
    'casa-e-cozinha' => '<path d="M6 22 24 7l18 15"/><path d="M11 18v22h26V18"/><path d="M20 40V28h8v12"/>',
    'tecnologia' => '<rect x="9" y="11" width="30" height="20" rx="2"/><path d="M4 37h40l-2-6H6z"/>',
    'moda' => '<path d="M17 7 6 13l4 9 5-2v21h18V20l5 2 4-9-11-6c-1 4-4 6-7 6s-6-2-7-6z"/>',
    'beleza' => '<path d="M19 22h10v20H19z"/><path d="M21 22V11l6-4v15"/><path d="M16 42h16"/>',
    'esporte' => '<circle cx="24" cy="24" r="17"/><path d="M24 7v34M7 24h34"/><path d="M12 12c6 6 6 18 0 24M36 12c-6 6-6 18 0 24"/>',
    'games' => '<path d="M14 16h20c5 0 8 4 9 10l1 6c1 5-4 7-7 3l-4-5H15l-4 5c-3 4-8 2-7-3l1-6c1-6 4-10 9-10z"/><path d="M15 21v6M12 24h6"/><circle cx="32" cy="22" r="1.5"/><circle cx="35" cy="26" r="1.5"/>',
    'bebes-e-criancas' => '<path d="M20 6h8v5h-8z"/><path d="M18 11h12l2 6v23a2 2 0 0 1-2 2H18a2 2 0 0 1-2-2V17z"/><path d="M16 24h6M16 30h6M16 36h6"/>',
    'ferramentas' => '<path d="M30 6a10 10 0 0 0-9 14L7 34a4 4 0 0 0 6 6l14-14a10 10 0 0 0 14-9l-6 6-5-1-1-5z"/>',
    'pet' => '<circle cx="13" cy="21" r="3.5"/><circle cx="19.5" cy="12.5" r="3.5"/><circle cx="28.5" cy="12.5" r="3.5"/><circle cx="35" cy="21" r="3.5"/><path d="M24 22c-6 0-11 8-11 13 0 4 4 5 7 4s5-1 8 0 7 0 7-4c0-5-5-13-11-13z"/>',
    'acessorios-automotivos' => '<path d="M8 29l4-10a3 3 0 0 1 3-2h18a3 3 0 0 1 3 2l4 10"/><rect x="6" y="28" width="36" height="10" rx="3"/><path d="M10 38v4h6v-4M32 38v4h6v-4"/><circle cx="13" cy="33" r="1.5"/><circle cx="35" cy="33" r="1.5"/>',
    '_' => '<path d="M10 16h28l-2 26H12z"/><path d="M18 16v-3a6 6 0 0 1 12 0v3"/><text x="24" y="33" text-anchor="middle" font-size="8" font-weight="700" fill="currentColor" stroke="none" font-family="Inter,sans-serif">SALE</text>',
  ];
  $tabText = [
    'casa-e-cozinha' => ['Tudo para deixar seu lar completo.', 'Achados novos todos os dias.'],
    'tecnologia' => ['Celulares, notebooks e gadgets em oferta.', 'Preços conferidos diariamente.'],
    'moda' => ['Roupas, tênis e acessórios com estilo.', 'Novidades todos os dias.'],
    'ferramentas' => ['Equipamentos para obras e projetos.', 'Ofertas novas todos os dias.'],
    'acessorios-automotivos' => ['Cuidado completo para o seu veículo.', 'Ofertas novas todos os dias.'],
  ];
  $bandTxt = $currentCat ? ($tabText[$currentCat['slug']] ?? null) : null;
  $bandTxt ??= ['Aproveite esses preços agora mesmo!', 'Novas ofertas todos os dias.'];
  $tabSvg = fn($k) => '<svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($tabIcons[$k] ?? $tabIcons['_']) . '</svg>';
  ?>
  <section class="cat-band">
    <div class="wrap cat-band-in">
      <div class="cat-band-txt">
        <h1><?= e($title) ?></h1>
        <p><?= e($bandTxt[0]) ?><br><?= e($bandTxt[1]) ?></p>
      </div>
      <div class="cat-tabs" data-tabs>
        <button class="ct-arrow prev" type="button" aria-label="Anterior" hidden><?= icon('chevl') ?></button>
        <nav class="ct-track" aria-label="Categorias">
          <a class="ct <?= $currentCat ? '' : 'on' ?>" href="<?= e($qs(['c' => '', 'p' => ''])) ?>" <?= $currentCat ? '' : 'aria-current="page"' ?>>
            <span class="ct-ico"><?= $tabSvg('todas') ?></span><span class="ct-lbl">Todas as ofertas</span></a>
          <?php foreach ($cats as $c): $on = $currentCat && $currentCat['id'] === $c['id']; ?>
          <a class="ct <?= $on ? 'on' : '' ?>" href="<?= e($qs(['c' => $c['slug'], 'p' => ''])) ?>" <?= $on ? 'aria-current="page"' : '' ?>>
            <span class="ct-ico"><?= $tabSvg($c['slug']) ?></span><span class="ct-lbl"><?= e($c['name']) ?></span></a>
          <?php endforeach; ?>
        </nav>
        <button class="ct-arrow next" type="button" aria-label="Próximo"><?= icon('chev') ?></button>
      </div>
    </div>
  </section>

  <div class="wrap listing">
    <div class="list-head">
      <div>
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
