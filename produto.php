<?php
require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/src/layout.php';

$pdo = db();
$st = $pdo->prepare('SELECT p.*, c.name AS cat_name, c.slug AS cat_slug FROM products p
    LEFT JOIN categories c ON c.id = p.category_id WHERE p.slug = ? AND p.active = 1');
$st->execute([(string)($_GET['s'] ?? '')]);
$p = $st->fetch();

if (!$p) {
    http_response_code(404);
    site_header(['title' => 'Achado não encontrado']);
    echo '<main class="wrap"><p class="empty">Esse achado saiu do ar ou não existe. <a href="' . e(url('')) . '">Ver outros achados</a></p></main>';
    site_footer();
    exit;
}

$related = [];
if ($p['category_id']) {
    $r = $pdo->prepare('SELECT * FROM products WHERE active = 1 AND category_id = ? AND id <> ? ORDER BY created_at DESC LIMIT 4');
    $r->execute([$p['category_id'], $p['id']]);
    $related = $r->fetchAll();
}

$pct = discount_pct($p);
$desc = $p['description'] ?: ($p['title'] . ' por ' . money((float)$p['price']) . ' no Mercado Livre' . ($pct ? " ({$pct}% OFF)" : '') . '.');
$shareText = '🔥 ' . $p['title'] . ' por ' . money((float)$p['price']) . ' 👉 ' . product_url($p);

site_header([
    'title' => $p['title'],
    'description' => $desc,
    'image' => $p['image'],
    'canonical' => product_url($p),
    'jsonld' => [
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => $p['title'],
        'image' => [$p['image']],
        'description' => $desc,
        'sku' => $p['ml_id'],
        'offers' => [
            '@type' => 'Offer',
            'priceCurrency' => 'BRL',
            'price' => number_format((float)$p['price'], 2, '.', ''),
            'availability' => 'https://schema.org/InStock',
            'url' => product_url($p),
            'seller' => ['@type' => 'Organization', 'name' => 'Mercado Livre'],
        ],
    ],
]);
?>
<main class="wrap">
  <nav class="crumbs">
    <a href="<?= e(url('')) ?>">Início</a>
    <?php if ($p['cat_name']): ?> / <a href="<?= e(url('?c=' . $p['cat_slug'])) ?>"><?= e($p['cat_name']) ?></a><?php endif; ?>
  </nav>

  <article class="pdp">
    <div class="pdp-img">
      <img src="<?= e($p['image']) ?>" alt="<?= e($p['title']) ?>">
      <?php if ($pct >= 5): ?><span class="badge">-<?= $pct ?>%</span><?php endif; ?>
    </div>
    <div class="pdp-info">
      <h1><?= e($p['title']) ?></h1>
      <div class="price big">
        <?php if ($pct): ?><s><?= money((float)$p['original_price']) ?></s><?php endif; ?>
        <b><?= money((float)$p['price']) ?></b>
      </div>
      <div class="perks">
        <?php if ($p['free_shipping']): ?><span>Frete grátis</span><?php endif; ?>
        <?php if ($p['item_condition'] === 'used'): ?><span>Usado</span><?php endif; ?>
      </div>
      <?php if ($p['coupon']): ?>
        <div class="coupon-box">Cupom: <code id="cp"><?= e($p['coupon']) ?></code>
          <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('cp').textContent);this.textContent='Copiado!'">Copiar</button></div>
      <?php endif; ?>

      <a class="cta big" href="<?= e(go_url($p)) ?>" target="_blank" rel="sponsored nofollow noopener">Comprar no Mercado Livre</a>
      <a class="share" href="https://wa.me/?text=<?= rawurlencode($shareText) ?>" target="_blank" rel="noopener">Compartilhar no WhatsApp</a>

      <p class="muted small">Preço conferido em <?= e(date('d/m/Y H:i', strtotime((string)$p['price_updated_at']))) ?>. Pode mudar a qualquer momento.</p>

      <?php if ($p['description']): ?>
        <div class="review"><h2>Por que vale a pena</h2><?= nl2br(e($p['description'])) ?></div>
      <?php endif; ?>
    </div>
  </article>

  <?php if ($related): ?>
  <section>
    <h2 class="sec-title">Mais achados de <?= e($p['cat_name']) ?></h2>
    <div class="grid"><?php foreach ($related as $r) product_card($r); ?></div>
  </section>
  <?php endif; ?>
</main>
<?php site_footer();
