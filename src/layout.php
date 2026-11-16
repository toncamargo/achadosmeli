<?php
declare(strict_types=1);

/**
 * $meta: title, description, image, canonical, jsonld (array)
 */
function site_header(array $meta = []): void
{
    $site = setting('site_name', 'Achados');
    $title = isset($meta['title']) ? $meta['title'] . ' · ' . $site : $site . ' — ' . setting('site_tagline', 'Os melhores achados do Mercado Livre, todo dia.');
    $desc = $meta['description'] ?? setting('site_tagline', 'Os melhores achados do Mercado Livre, todo dia.');
    $q = (string)($_GET['q'] ?? '');
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
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,600;12..96,800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(url('assets/style.css')) ?>">
<?php if (!empty($meta['jsonld'])): ?>
<script type="application/ld+json"><?= json_encode($meta['jsonld'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<?php endif; ?>
</head>
<body>
<header class="top">
  <div class="wrap top-in">
    <a class="brand" href="<?= e(url('')) ?>"><span class="brand-mark">◆</span><?= e($site) ?></a>
    <form class="search" action="<?= e(url('')) ?>" method="get" role="search">
      <input name="q" value="<?= e($q) ?>" placeholder="Buscar achados..." aria-label="Buscar">
      <button aria-label="Buscar">→</button>
    </form>
    <?php if ($wa = setting('whatsapp_group')): ?>
      <a class="join" href="<?= e($wa) ?>" target="_blank" rel="noopener">Grupo de ofertas</a>
    <?php endif; ?>
  </div>
</header>
<?php
}

function site_footer(): void
{
    $site = setting('site_name', 'Achados');
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
</body>
</html>
<?php
}

function product_card(array $p): void
{
    $pct = discount_pct($p);
    ?>
  <article class="card-p">
    <a class="card-img" href="<?= e(product_url($p)) ?>">
      <img src="<?= e($p['image']) ?>" alt="<?= e($p['title']) ?>" loading="lazy">
      <?php if ($pct >= 5): ?><span class="badge">-<?= $pct ?>%</span><?php endif; ?>
    </a>
    <div class="card-body">
      <h3><a href="<?= e(product_url($p)) ?>"><?= e($p['title']) ?></a></h3>
      <div class="price">
        <?php if ($pct): ?><s><?= money((float)$p['original_price']) ?></s><?php endif; ?>
        <b><?= money((float)$p['price']) ?></b>
      </div>
      <div class="perks">
        <?php if ($p['free_shipping']): ?><span>Frete grátis</span><?php endif; ?>
        <?php if (!empty($p['coupon'])): ?><span class="coupon">Cupom</span><?php endif; ?>
      </div>
      <a class="cta" href="<?= e(go_url($p)) ?>" target="_blank" rel="sponsored nofollow noopener">Ver no Mercado Livre</a>
    </div>
  </article>
<?php
}
