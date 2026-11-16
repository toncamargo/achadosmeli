<?php
require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/src/layout.php';

$pdo = db();
$q = trim((string)($_GET['q'] ?? ''));
$catSlug = (string)($_GET['c'] ?? '');
$sort = (string)($_GET['o'] ?? 'novos');
$page = max(1, (int)($_GET['p'] ?? 1));
$per = (int)($CONFIG['per_page'] ?? 24);

$cats = $pdo->query('SELECT c.* FROM categories c WHERE EXISTS
    (SELECT 1 FROM products p WHERE p.category_id = c.id AND p.active = 1) ORDER BY sort_order, name')->fetchAll();
$currentCat = null;
foreach ($cats as $c) if ($c['slug'] === $catSlug) $currentCat = $c;

$where = ['p.active = 1'];
$args = [];
if ($q !== '') {
    foreach (preg_split('/\s+/', $q) as $w) { $where[] = 'p.title LIKE ?'; $args[] = "%$w%"; }
}
if ($currentCat) { $where[] = 'p.category_id = ?'; $args[] = (int)$currentCat['id']; }
$w = 'WHERE ' . implode(' AND ', $where);

$discExpr = 'CASE WHEN p.original_price > p.price THEN (p.original_price - p.price) / p.original_price ELSE 0 END';
$order = match ($sort) {
    'desconto' => "$discExpr DESC, p.created_at DESC",
    'menor' => 'p.price ASC',
    'maior' => 'p.price DESC',
    'populares' => 'p.clicks DESC, p.created_at DESC',
    default => 'p.created_at DESC',
};

$total = $pdo->prepare("SELECT COUNT(*) FROM products p $w");
$total->execute($args);
$total = (int)$total->fetchColumn();
$pages = max(1, (int)ceil($total / $per));
$offset = ($page - 1) * $per;

$st = $pdo->prepare("SELECT p.* FROM products p $w ORDER BY $order LIMIT $per OFFSET $offset");
$st->execute($args);
$products = $st->fetchAll();

$featured = [];
if ($q === '' && !$currentCat && $page === 1) {
    $featured = $pdo->query('SELECT * FROM products WHERE active = 1 AND featured = 1 ORDER BY updated_at DESC LIMIT 8')->fetchAll();
}

$qs = fn(array $over) => '?' . http_build_query(array_filter(array_merge(['q' => $q, 'c' => $catSlug, 'o' => $sort === 'novos' ? '' : $sort], $over), fn($v) => $v !== '' && $v !== null && $v !== 1));

$title = $q !== '' ? "Busca: $q" : ($currentCat ? $currentCat['name'] : null);
site_header([
    'title' => $title,
    'canonical' => url('') . ($currentCat ? '?c=' . $currentCat['slug'] : ''),
    'description' => $currentCat ? "Achados e ofertas de {$currentCat['name']} no Mercado Livre." : null,
]);
?>
<main class="wrap">
  <?php if (!$title): ?>
  <section class="hero">
    <h1><?= e(setting('site_tagline', 'Os melhores achados do Mercado Livre, todo dia.')) ?></h1>
    <p>Curadoria de ofertas com preço conferido. Clique, confira e compre direto no Mercado Livre.</p>
  </section>
  <?php else: ?>
  <h1 class="page-title"><?= e($title) ?> <small><?= $total ?> achado<?= $total === 1 ? '' : 's' ?></small></h1>
  <?php endif; ?>

  <?php if ($cats): ?>
  <nav class="chips" aria-label="Categorias">
    <a href="<?= e(url('') . $qs(['c' => '', 'p' => ''])) ?>" class="<?= $currentCat ? '' : 'on' ?>">Tudo</a>
    <?php foreach ($cats as $c): ?>
      <a href="<?= e(url('') . $qs(['c' => $c['slug'], 'p' => ''])) ?>" class="<?= $currentCat && $currentCat['id'] === $c['id'] ? 'on' : '' ?>"><?= e($c['name']) ?></a>
    <?php endforeach; ?>
  </nav>
  <?php endif; ?>

  <?php if ($featured): ?>
  <section>
    <h2 class="sec-title">Em destaque</h2>
    <div class="grid featured"><?php foreach ($featured as $p) product_card($p); ?></div>
  </section>
  <?php endif; ?>

  <section>
    <div class="sec-head">
      <h2 class="sec-title"><?= $featured ? 'Todos os achados' : 'Achados' ?></h2>
      <form class="sort" method="get">
        <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?= e($q) ?>"><?php endif; ?>
        <?php if ($catSlug !== ''): ?><input type="hidden" name="c" value="<?= e($catSlug) ?>"><?php endif; ?>
        <select name="o" onchange="this.form.submit()" aria-label="Ordenar">
          <?php foreach (['novos' => 'Mais recentes', 'desconto' => 'Maior desconto', 'populares' => 'Mais clicados', 'menor' => 'Menor preço', 'maior' => 'Maior preço'] as $k => $l): ?>
            <option value="<?= $k ?>" <?= $sort === $k ? 'selected' : '' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select>
      </form>
    </div>
    <?php if ($products): ?>
      <div class="grid"><?php foreach ($products as $p) product_card($p); ?></div>
    <?php else: ?>
      <p class="empty">Nenhum achado por aqui<?= $q !== '' ? ' para "' . e($q) . '"' : '' ?>. Tente outra busca.</p>
    <?php endif; ?>

    <?php if ($pages > 1): ?>
    <nav class="pager">
      <?php if ($page > 1): ?><a href="<?= e($qs(['p' => $page - 1])) ?>">← Anterior</a><?php endif; ?>
      <span><?= $page ?> / <?= $pages ?></span>
      <?php if ($page < $pages): ?><a href="<?= e($qs(['p' => $page + 1])) ?>">Próxima →</a><?php endif; ?>
    </nav>
    <?php endif; ?>
  </section>
</main>
<?php site_footer();
