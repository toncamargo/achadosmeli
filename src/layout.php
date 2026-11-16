<?php
declare(strict_types=1);

/** Ícones SVG próprios (traço simples), usados nos atalhos da home. */
function icon(string $name): string
{
    $paths = [
        'truck'  => '<path d="M3 7h11v9H3z"/><path d="M14 10h4l3 3v3h-7z"/><circle cx="7" cy="17.5" r="1.8"/><circle cx="17" cy="17.5" r="1.8"/>',
        'coin'   => '<circle cx="12" cy="12" r="8"/><path d="M14.5 9.2c-.6-.8-1.5-1.2-2.6-1.2-1.6 0-2.6.8-2.6 1.9 0 2.6 5.4 1.4 5.4 4.1 0 1.1-1.1 2-2.8 2-1.2 0-2.2-.5-2.8-1.3M12 6.5V8m0 8v1.5"/>',
        'pct'    => '<path d="M5 19L19 5"/><circle cx="7" cy="7" r="2.2"/><circle cx="17" cy="17" r="2.2"/>',
        'fire'   => '<path d="M12 3c1 3.5 5 5.5 5 10a5 5 0 0 1-10 0c0-2.2 1-3.6 2.2-4.8.3 1.6 1 2.6 2 3 0-3 .3-5.6.8-8.2z"/>',
        'ticket' => '<path d="M3 8a2 2 0 0 0 0 4v0a2 2 0 0 1 0 4v2h18v-2a2 2 0 0 1 0-4 2 2 0 0 0 0-4V6H3z"/><path d="M9 6v12" stroke-dasharray="2 2"/>',
        'chat'   => '<path d="M4 5h16v11H9l-5 4z"/><path d="M8 9.5h8M8 12.5h5"/>',
        'search' => '<circle cx="11" cy="11" r="6.5"/><path d="M16 16l4.5 4.5"/>',
        'chev'   => '<path d="M9 5l7 7-7 7"/>',
        'chevl'  => '<path d="M15 5l-7 7 7 7"/>',
        'down'   => '<path d="M6 9l6 6 6-6"/>',
    ];
    return '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . ($paths[$name] ?? '') . '</svg>';
}

/**
 * $meta: title, description, image, canonical, jsonld (array)
 */
function site_header(array $meta = []): void
{
    $site = setting('site_name', 'Achados Meli');
    $tagline = setting('site_tagline', 'Os melhores achados do Mercado Livre, todo dia.');
    $title = isset($meta['title']) ? $meta['title'] . ' · ' . $site : $site . ' — ' . $tagline;
    $desc = $meta['description'] ?? $tagline;
    $q = (string)($_GET['q'] ?? '');
    $cats = db()->query('SELECT c.* FROM categories c WHERE EXISTS
        (SELECT 1 FROM products p WHERE p.category_id = c.id AND p.active = 1) ORDER BY sort_order, name')->fetchAll();
    ?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?></title>
<meta name="description" content="<?= e(mb_strimwidth($desc, 0, 160, '…')) ?>">
<?php if (!empty($meta['canonical'])): ?><link rel="canonical" href="<?= e($meta['canonical']) ?>"><?php endif; ?>
<meta property="og:site_name" content="<?= e($site) ?>">
<meta property="og:title" content="<?= e($meta['title'] ?? $site) ?>">
<meta property="og:description" content="<?= e(mb_strimwidth($desc, 0, 200, '…')) ?>">
<?php if (!empty($meta['image'])): ?><meta property="og:image" content="<?= e($meta['image']) ?>"><?php endif; ?>
<meta property="og:type" content="<?= isset($meta['jsonld']) ? 'product' : 'website' ?>">
<meta name="twitter:card" content="summary_large_image">
<meta name="theme-color" content="#ffe102">
<link rel="icon" href="<?= e(url('assets/favicon.png')) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,600;12..96,800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(url('assets/style.css')) ?>">
<?php if (!empty($meta['jsonld'])): ?>
<script type="application/ld+json"><?= json_encode($meta['jsonld'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<?php endif; ?>
</head>
<body>
<header class="top">
  <div class="wrap top-row">
    <a class="brand" href="<?= e(url('')) ?>" aria-label="<?= e($site) ?> — início">
      <img src="<?= e(url('assets/logo.png')) ?>" alt="<?= e($site) ?>" width="144" height="40">
    </a>
    <form class="search" action="<?= e(url('')) ?>" method="get" role="search">
      <input name="q" value="<?= e($q) ?>" placeholder="Buscar produtos, marcas e muito mais…" aria-label="Buscar">
      <button aria-label="Buscar"><?= icon('search') ?></button>
    </form>
    <?php if ($wa = setting('whatsapp_group')): ?>
      <a class="promo-pill" href="<?= e($wa) ?>" target="_blank" rel="noopener"><b>Grupo VIP</b> ofertas no WhatsApp</a>
    <?php else: ?>
      <span class="promo-pill"><b>Achados</b> conferidos todo dia</span>
    <?php endif; ?>
  </div>
  <div class="wrap nav-row">
    <span class="nav-hint">Site independente<br><b>de achados afiliados</b></span>
    <nav class="nav-main">
      <?php if ($cats): ?>
      <details class="cat-menu">
        <summary>Categorias <?= icon('down') ?></summary>
        <div class="cat-pop">
          <?php foreach ($cats as $c): ?><a href="<?= e(url('?c=' . $c['slug'])) ?>"><?= e($c['name']) ?></a><?php endforeach; ?>
        </div>
      </details>
      <?php endif; ?>
      <a href="<?= e(url('?o=desconto')) ?>">Ofertas</a>
      <a href="<?= e(url('?cupom=1')) ?>">Cupons</a>
      <a href="<?= e(url('?frete=1')) ?>">Frete grátis</a>
      <a href="<?= e(url('?max=100')) ?>">Até R$100</a>
      <a href="<?= e(url('?o=populares')) ?>">Mais clicados</a>
    </nav>
    <nav class="nav-side">
      <?php if ($l = setting('telegram_channel')): ?><a href="<?= e($l) ?>" target="_blank" rel="noopener">Telegram</a><?php endif; ?>
      <?php if ($l = setting('instagram')): ?><a href="<?= e($l) ?>" target="_blank" rel="noopener">Instagram</a><?php endif; ?>
      <?php if ($l = setting('whatsapp_group')): ?><a href="<?= e($l) ?>" target="_blank" rel="noopener">WhatsApp</a><?php endif; ?>
    </nav>
  </div>
</header>
<?php
}

function site_footer(): void
{
    $site = setting('site_name', 'Achados Meli');
    ?>
<footer class="foot">
  <div class="wrap">
    <div class="foot-links">
      <?php if ($l = setting('whatsapp_group')): ?><a href="<?= e($l) ?>" target="_blank" rel="noopener">WhatsApp</a><?php endif; ?>
      <?php if ($l = setting('telegram_channel')): ?><a href="<?= e($l) ?>" target="_blank" rel="noopener">Telegram</a><?php endif; ?>
      <?php if ($l = setting('instagram')): ?><a href="<?= e($l) ?>" target="_blank" rel="noopener">Instagram</a><?php endif; ?>
    </div>
    <p><b><?= e($site) ?></b> participa do Programa de Afiliados do Mercado Livre e pode receber comissão
      pelas compras feitas a partir dos links deste site, sem custo extra para você.
      Este não é um site oficial do Mercado Livre. Preços e disponibilidade podem mudar a qualquer momento;
      o valor válido é sempre o exibido no Mercado Livre na hora da compra.</p>
    <p class="muted">© <?= date('Y') ?> <?= e($site) ?></p>
  </div>
</footer>
<script src="<?= e(url('assets/app.js')) ?>" defer></script>
</body>
</html>
<?php
}

function product_card(array $p, string $extra = ''): void
{
    $pct = discount_pct($p);
    ?>
  <article class="card-p <?= e($extra) ?>">
    <a class="card-img" href="<?= e(product_url($p)) ?>">
      <img src="<?= e($p['image']) ?>" alt="<?= e($p['title']) ?>" loading="lazy">
    </a>
    <div class="card-body">
      <?php if ($pct): ?><s class="old"><?= money((float)$p['original_price']) ?></s><?php endif; ?>
      <div class="price-line">
        <b class="now"><?= money((float)$p['price']) ?></b>
        <?php if ($pct): ?><span class="off"><?= $pct ?>% OFF</span><?php endif; ?>
      </div>
      <?php if ($p['free_shipping']): ?><span class="ship">Frete grátis</span><?php endif; ?>
      <?php if (!empty($p['coupon'])): ?><span class="cpn">Cupom <?= e($p['coupon']) ?></span><?php endif; ?>
      <h3><a href="<?= e(product_url($p)) ?>"><?= e($p['title']) ?></a></h3>
      <a class="cta" href="<?= e(go_url($p)) ?>" target="_blank" rel="sponsored nofollow noopener">Ver no Mercado Livre</a>
    </div>
  </article>
<?php
}

/** Prateleira horizontal com setas (estilo carrossel). */
function product_shelf(string $title, array $products, ?string $moreUrl = null, string $moreLabel = 'Mostrar todos'): void
{
    if (!$products) return;
    ?>
  <section class="panel shelf">
    <div class="panel-head">
      <h2><?= e($title) ?></h2>
      <?php if ($moreUrl): ?><a class="more" href="<?= e($moreUrl) ?>"><?= e($moreLabel) ?></a><?php endif; ?>
    </div>
    <div class="shelf-wrap">
      <button class="arrow prev" type="button" aria-label="Anterior"><?= icon('chevl') ?></button>
      <div class="shelf-track"><?php foreach ($products as $p) product_card($p, 'in-shelf'); ?></div>
      <button class="arrow next" type="button" aria-label="Próximo"><?= icon('chev') ?></button>
    </div>
  </section>
<?php
}
