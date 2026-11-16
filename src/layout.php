<?php
declare(strict_types=1);
require_once __DIR__ . '/ads.php';

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
<link rel="icon" href="<?= e(url('assets/favicon.svg')) ?>" type="image/svg+xml">
<link rel="icon" href="<?= e(url('assets/favicon.png')) ?>" type="image/png" sizes="64x64">
<link rel="apple-touch-icon" href="<?= e(url('assets/apple-touch-icon.png')) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,600;12..96,800&family=Figtree:ital,wght@0,400;0,600;0,700;1,700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('assets/style.css')) ?>">
<?php if (!empty($meta['jsonld'])): ?>
<script type="application/ld+json"><?= json_encode($meta['jsonld'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<?php endif; ?>
<?= adsense_head() ?>
</head>
<body>
<header class="top">
  <div class="wrap top-row">
    <a class="brand" href="<?= e(url('')) ?>" aria-label="<?= e($site) ?> — início">
      <img src="<?= e(url('assets/logo.png')) ?>" alt="<?= e($site) ?>" width="144" height="40">
    </a>
    <form class="search" action="<?= e(url('')) ?>" method="get" role="search" data-suggest="<?= e(url('sugestoes.php')) ?>">
      <input name="q" value="<?= e($q) ?>" autocomplete="off" placeholder="Buscar produtos, marcas e muito mais…" aria-label="Buscar">
      <button aria-label="Buscar"><?= icon('search') ?></button>
    </form>
    <div class="top-ad"><?= ad_header() ?></div>
  </div>
  <div class="wrap nav-row">
    <span class="nav-hint">Site independente<br><b>afiliado Mercado Livre</b></span>
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
  <section class="foot-info">
    <div class="wrap foot-cols">
      <div class="fcol">
        <svg class="fico" viewBox="0 0 120 100" fill="none" stroke="#333" stroke-width="3" stroke-linejoin="round" stroke-linecap="round" aria-hidden="true">
          <rect x="14" y="14" width="92" height="68" rx="5"/><path d="M14 30h92"/><circle cx="24" cy="22" r="2" fill="#333"/><circle cx="32" cy="22" r="2" fill="#333"/>
          <path d="M52 60l-5 5a8 8 0 0 1-11-11l8-8a8 8 0 0 1 11 0" stroke="#3483fa" stroke-width="4"/>
          <path d="M68 46l5-5a8 8 0 0 1 11 11l-8 8a8 8 0 0 1-11 0" stroke="#3483fa" stroke-width="4"/>
          <path d="M53 59l14-14" stroke="#3483fa" stroke-width="4"/>
        </svg>
        <h3>Site de afiliado</h3>
        <p>Somos um site independente que reúne achados do Mercado Livre. Podemos ganhar uma comissão pelas compras, sem nenhum custo extra para você.</p>
        <a href="https://www.mercadolivre.com.br/afiliados" target="_blank" rel="noopener">Conheça o Programa de Afiliados</a>
      </div>
      <div class="fcol">
        <svg class="fico" viewBox="0 0 120 100" fill="none" stroke="#333" stroke-width="3" stroke-linejoin="round" stroke-linecap="round" aria-hidden="true">
          <path d="M16 20h12l10 44h46l10-32H34"/><circle cx="44" cy="76" r="5"/><circle cx="78" cy="76" r="5"/>
          <path d="M70 12h24v24" stroke="#3483fa" stroke-width="4"/><path d="M94 12 66 40" stroke="#3483fa" stroke-width="4"/>
        </svg>
        <h3>Compra feita no Mercado Livre</h3>
        <p>Ao clicar em um produto, você é redirecionado para o Mercado Livre. Pagamento, entrega, trocas e atendimento acontecem direto por lá.</p>
        <a href="<?= e(url('?o=desconto')) ?>">Ver as ofertas do dia</a>
      </div>
      <div class="fcol">
        <svg class="fico" viewBox="0 0 120 100" fill="none" stroke="#333" stroke-width="3" stroke-linejoin="round" stroke-linecap="round" aria-hidden="true">
          <path d="M60 8 86 18v26c0 22-12 36-26 46C46 80 34 66 34 44V18z"/>
          <path d="M48 48l9 9 17-19" stroke="#3483fa" stroke-width="6"/>
        </svg>
        <h3>Segurança, do início ao fim</h3>
        <p>Aqui você não faz cadastro nem informa senha ou dados de pagamento. Sua compra conta com as proteções do próprio Mercado Livre.</p>
        <a href="#mais-info" data-open-more>Como funciona</a>
      </div>
    </div>
  </section>
  <details class="foot-more" id="mais-info">
    <summary>Mais informações</summary>
    <div class="wrap foot-more-in">
      <p><b><?= e($site) ?></b> participa do Programa de Afiliados do Mercado Livre e pode receber comissão
        pelas compras feitas a partir dos links deste site, sem custo extra para você.
        Este não é um site oficial do Mercado Livre. Preços e disponibilidade podem mudar a qualquer momento;
        o valor válido é sempre o exibido no Mercado Livre na hora da compra.</p>
      <p>Não vendemos nem entregamos produtos e não recebemos pagamentos. Dúvidas sobre pedidos, entregas e devoluções
        devem ser tratadas com o vendedor pelo Mercado Livre.</p>
    </div>
  </details>
  <div class="foot-bottom">
    <div class="wrap">
      <nav class="foot-links">
        <a href="<?= e(url('?o=desconto')) ?>">Ofertas</a>
        <a href="<?= e(url('?cupom=1')) ?>">Cupons</a>
        <a href="<?= e(url('?frete=1')) ?>">Frete grátis</a>
        <a href="<?= e(url('?o=populares')) ?>">Mais clicados</a>
        <a href="https://www.mercadolivre.com.br/afiliados" target="_blank" rel="noopener">Programa de Afiliados</a>
        <?php if ($l = setting('whatsapp_group')): ?><a href="<?= e($l) ?>" target="_blank" rel="noopener">WhatsApp</a><?php endif; ?>
        <?php if ($l = setting('telegram_channel')): ?><a href="<?= e($l) ?>" target="_blank" rel="noopener">Telegram</a><?php endif; ?>
        <?php if ($l = setting('instagram')): ?><a href="<?= e($l) ?>" target="_blank" rel="noopener">Instagram</a><?php endif; ?>
      </nav>
      <p>Copyright © <?= date('Y') ?> <?= e($site) ?>.</p>
      <p>Site independente de afiliados — não é o site oficial do Mercado Livre. Mercado Livre é marca de seus respectivos donos.</p>
    </div>
  </div>
</footer>
<script src="<?= e(asset('assets/app.js')) ?>" defer></script>
</body>
</html>
<?php
}

function product_card(array $p, string $extra = ''): void
{
    $price = (float)$p['price'];
    $pix = (float)($p['pix_price'] ?? 0);
    $hasPix = $pix > 0 && $pix < $price;
    $shown = $hasPix ? $pix : $price;                       // preço em destaque
    $orig = (float)($p['original_price'] ?? 0);
    $off = ($orig > $shown && $shown > 0) ? (int)floor((1 - $shown / $orig) * 100) : 0;
    $rating = $p['rating'] !== null && $p['rating'] !== '' ? (float)$p['rating'] : null;
    $sold = trim((string)($p['sold_label'] ?? ''));
    $instQ = (int)($p['installments_qty'] ?? 0);
    $instV = (float)($p['installments_value'] ?? 0);
    $coupon = trim((string)($p['coupon_label'] ?? '')) ?: (!empty($p['coupon']) ? 'Cupom ' . $p['coupon'] : '');
    $delivery = trim((string)($p['delivery_label'] ?? '')) ?: (!empty($p['free_shipping']) ? 'Frete grátis' : '');
    // textos que vêm do Mercado Livre todo em maiúsculas ("FRETE GRÁTIS ACIMA DE R$ 19") viram "Frete grátis acima de R$ 19"
    if ($delivery !== '' && preg_match('/\p{Lu}{3}/u', $delivery) && mb_strtoupper($delivery, 'UTF-8') === $delivery) {
        $delivery = mb_strtolower($delivery, 'UTF-8');
        $delivery = mb_strtoupper(mb_substr($delivery, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($delivery, 1, null, 'UTF-8');
        $delivery = str_replace(['r$', ' full'], ['R$', ' Full'], $delivery);
    }
    $badge = trim((string)($p['badge'] ?? ''));
    $href = product_url($p);
    ?>
  <article class="pc <?= e($extra) ?>">
    <div class="pc-img">
      <img src="<?= e($p['image']) ?>" alt="<?= e($p['title']) ?>" loading="lazy">
      <?php if ($badge !== ''): ?>
        <span class="pc-badge <?= badge_class($badge) ?>"><?php if (badge_class($badge) === 'bd-black'): ?><svg viewBox="0 0 16 16" aria-hidden="true"><path d="M8 1.2l1.6 1.2 2-.2.6 1.9 1.7 1.1-.6 1.9.6 1.9-1.7 1.1-.6 1.9-2-.2L8 14.8l-1.6-1.2-2 .2-.6-1.9-1.7-1.1.6-1.9-.6-1.9 1.7-1.1.6-1.9 2 .2z" fill="none" stroke="currentColor" stroke-width="1.2"/><path d="M5.8 10.2l4.4-4.4" stroke="currentColor" stroke-width="1.2"/><circle cx="6.2" cy="6.2" r=".9" fill="currentColor"/><circle cx="9.8" cy="9.8" r=".9" fill="currentColor"/></svg><?php endif; ?><?= e($badge) ?></span>
      <?php endif; ?>
    </div>
    <div class="pc-body">
      <h3 class="pc-title"><a href="<?= e($href) ?>" target="_blank" rel="sponsored nofollow noopener"><?= e($p['title']) ?></a></h3>
      <?php if (!empty($p['brand'])): ?>
        <span class="pc-brand"><?= e($p['brand']) ?><?php if (!empty($p['brand_verified'])): ?> <svg class="pc-verified" viewBox="0 0 16 16" aria-label="Loja oficial"><path d="M8 0l2 1.6 2.5-.2.7 2.4 2.2 1.3-.9 2.4.9 2.4-2.2 1.3-.7 2.4-2.5-.2L8 16l-2-1.6-2.5.2-.7-2.4L.6 10.9l.9-2.4L.6 6.1l2.2-1.3.7-2.4 2.5.2z" fill="#3483fa"/><path d="M4.8 8.2l2.1 2.1 4.3-4.4" fill="none" stroke="#fff" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg><?php endif; ?></span>
      <?php endif; ?>
      <?php if ($rating !== null): ?>
        <span class="pc-rev"><svg viewBox="0 0 16 16" aria-hidden="true"><path d="M8 .9l2.2 4.6 5 .7-3.6 3.5.9 5L8 12.3l-4.5 2.4.9-5L.8 6.2l5-.7z" fill="#3483fa"/></svg><span class="pc-rate"><?= number_format($rating, 1, '.', '') ?></span><?php if ($sold !== ''): ?> <span class="pc-sep">|</span> <?= e($sold) ?><?php endif; ?></span>
      <?php elseif ($sold !== ''): ?>
        <span class="pc-rev"><?= e($sold) ?></span>
      <?php endif; ?>
      <?php if ($off > 0): ?><s class="pc-old"><?= money_short($orig) ?></s><?php endif; ?>
      <div class="pc-price">
        <span class="pc-now"><?= price_html($shown) ?></span>
        <?php if ($off > 0): ?><span class="pc-off"><?= $off ?>% OFF</span><?php endif; ?>
      </div>
      <?php if ($hasPix): ?>
        <span class="pc-green">no Pix</span>
        <span class="pc-other">ou <?= money_short($price) ?> em outros meios</span>
      <?php endif; ?>
      <?php if ($instQ > 1 && $instV > 0): ?>
        <span class="<?= !empty($p['installments_free']) ? 'pc-green' : 'pc-other' ?>"><?= !empty($p['installments_free']) ? '' : 'em ' ?><?= $instQ ?>x <?= money_short($instV) ?><?= !empty($p['installments_free']) ? ' sem juros' : '' ?></span>
      <?php endif; ?>
      <?php if ($coupon !== ''): ?><span class="pc-coupon"><?= e($coupon) ?></span><?php endif; ?>
      <?php if ($delivery !== '' || !empty($p['is_full'])): ?>
        <div class="pc-ship">
          <?php if ($delivery !== ''): ?><span class="pc-free"><?= e($delivery) ?></span><?php endif; ?>
          <?php if (!empty($p['is_full'])): ?><span class="pc-full"><svg viewBox="0 0 10 16" aria-hidden="true"><path d="M6.5 0L0 9h4l-1.5 7L10 6H5.6z" fill="currentColor"/></svg>FULL</span><?php endif; ?>
        </div>
      <?php endif; ?>
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
