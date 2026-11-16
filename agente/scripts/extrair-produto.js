/**
 * Extrai os dados de uma página de produto do Mercado Livre (rodar no console da página do produto).
 * Usa o JSON-LD da página (dados estruturados) e completa com o que está visível no HTML.
 * Retorna um objeto pronto para gravar (formato aceito por agente/importar.php).
 * As imagens NÃO são baixadas: só os links (versão grande, -O / zoom).
 */
(() => {
  const $ = (s, root = document) => root.querySelector(s);
  const $$ = (s, root = document) => [...root.querySelectorAll(s)];
  const txt = (s) => ($(s)?.textContent || '').replace(/\s+/g, ' ').trim();
  const num = (s) => {
    if (s == null) return null;
    const m = String(s).replace(/\./g, '').replace(',', '.').match(/\d+(\.\d+)?/);
    return m ? parseFloat(m[0]) : null;
  };
  const money = (el) => {
    if (!el) return null;
    const f = el.querySelector('.andes-money-amount__fraction')?.textContent || '';
    const c = el.querySelector('.andes-money-amount__cents')?.textContent || '00';
    return f ? parseFloat(f.replace(/\./g, '') + '.' + c) : null;
  };
  const dec = (s) => { const m = String(s ?? '').match(/\d+([.,]\d+)?/); return m ? parseFloat(m[0].replace(',', '.')) : null; };
  const lines = document.body.innerText.split('\n').map((l) => l.trim()).filter(Boolean);
  const line = (re) => lines.find((l) => re.test(l) && l.length < 90) || null;
  const big = (u) => (u || '').replace(/^http:/, 'https:').replace(/-[A-Z](\.(jpg|webp|png))$/i, '-O$1');

  // ---- JSON-LD (Product)
  let ld = {};
  for (const s of $$('script[type="application/ld+json"]')) {
    try {
      const j = JSON.parse(s.textContent);
      const arr = Array.isArray(j) ? j : (j['@graph'] || [j]);
      const p = arr.find((x) => x && x['@type'] === 'Product');
      if (p) { ld = p; break; }
    } catch (e) { /* ignora */ }
  }
  const offer = Array.isArray(ld.offers) ? ld.offers[0] : (ld.offers || {});

  // ---- IDs e link canônico
  const canonical = $('link[rel="canonical"]')?.href || location.origin + location.pathname;
  const catalogId = (canonical.match(/\/p\/(MLB\d+)/) || [])[1] || null;
  const itemId = (document.body.innerHTML.match(/"item_id"\s*:\s*"(MLB\d+)"/) || [])[1]
    || (location.href.match(/[?&]wid=(MLB\d+)/) || [])[1]
    || (canonical.match(/(MLB)-?(\d{6,})/) || []).slice(1).join('') || null;

  // ---- preços
  const priceBox = $('.ui-pdp-price__second-line') || $('.ui-pdp-price');
  const price = money(priceBox?.querySelector('.andes-money-amount:not(.andes-money-amount--previous)'))
    ?? num(offer.price);
  const originalPrice = money($('.ui-pdp-price s.andes-money-amount--previous, s.andes-money-amount--previous'));
  const discountTxt = txt('.ui-pdp-price__second-line .andes-money-amount__discount, .andes-money-amount__discount');

  // ---- parcelas: "5x R$ 33,98 sem juros"
  const instTxt = txt('.ui-pdp-price__subtitles, #pricing_price_subtitle');
  const instM = instTxt.match(/(\d+)\s*x\s*R\$\s*([\d.]+,\d{2}|[\d.]+)/i);

  // ---- imagens (todas da galeria, versão grande)
  let images = $$('.ui-pdp-gallery__figure img, figure.ui-pdp-gallery__figure img')
    .map((i) => i.getAttribute('data-zoom') || i.getAttribute('data-src') || i.src)
    .filter((u) => u && /mlstatic/.test(u));
  if (!images.length) images = [].concat(ld.image || []);
  // a galeria repete a mesma foto em tamanhos/formatos diferentes: 1 link por foto
  const seen = new Set();
  images = images.map(big).filter((u) => {
    const k = (u.match(/(\d+-ML[A-Z]\d+_\d+)/) || [u])[0];
    if (seen.has(k)) return false;
    seen.add(k);
    return true;
  });

  // ---- avaliações e vendas
  const rating = dec(txt('.ui-pdp-review__rating')) ?? dec(ld.aggregateRating?.ratingValue);
  const reviews = num(txt('.ui-pdp-review__amount')) ?? num(ld.aggregateRating?.reviewCount);
  const subtitle = txt('.ui-pdp-subtitle, .ui-pdp-header__subtitle'); // "Novo | +500 vendidos"
  const soldLabel = (subtitle.match(/\+?[\d.,]+\s*(mil\s*)?vendidos?/i) || [])[0] || null;
  const condition = /usado/i.test(subtitle) ? 'used' : (/novo/i.test(subtitle) ? 'new' : null);

  // ---- selos (OFERTA DO DIA, MAIS VENDIDO, OFERTA RELÂMPAGO...)
  const badges = [...new Set($$('.ui-pdp-promotions-pill-label, .ui-pdp-highlights .ui-pdp-label, .ui-pdp-header__tags .ui-pdp-promotions-pill-label, [class*="pill-label"]')
    .map((e) => e.textContent.replace(/\s+/g, ' ').trim())
    .filter((t) => t && t.length < 40 && t === t.toUpperCase() && /[A-ZÀ-Ú]{3}/.test(t)))];
  const badge = badges.find((b) => /oferta|relâmpago|relampago|black/i.test(b)) || badges[0] || null;

  // ---- entrega
  const shipTxt = line(/^(Chegará|Chega |Receba|Entrega|Frete grátis)/i);
  const freeShipping = /grátis|gratis/i.test(shipTxt || '') || !!line(/^FRETE GRÁTIS/i);
  const isFull = !!$('[class*="full"] svg, .ui-pdp-icon--full, svg[aria-label*="FULL" i]') || /Armazenado e enviado pelo\s*FULL/i.test(document.body.innerText);

  // ---- loja / marca / categoria
  const storeRaw = txt('.ui-pdp-seller__header__title, .ui-seller-data-header__title, [class*="seller"] h2').replace(/^(Produtos d[oa]s?|Vendido por)\s+/i, '').trim();
  const store = storeRaw && !/^vendedor$/i.test(storeRaw) ? storeRaw : null;
  const official = !!line(/^Loja oficial/i);
  const brandLine = line(/produtos da marca /i);
  const brandRaw = (ld.brand && (ld.brand.name || ld.brand)) || (brandLine ? brandLine.replace(/.*produtos da marca /i, '').trim() : null);
  const brand = brandRaw && /^[A-Za-zÀ-ú0-9]/.test(brandRaw) && !/^(importada|gen[ée]rica|sem marca)$/i.test(brandRaw) ? brandRaw : null;
  const breadcrumb = $$('.andes-breadcrumb__item a, .andes-breadcrumb a').map((a) => a.textContent.trim()).filter(Boolean);

  // ---- "O que você precisa saber" + descrição
  const highlights = $$('.ui-vpp-highlighted-specs__features-list li, .ui-pdp-features li').map((li) => li.textContent.trim()).filter(Boolean);
  const description = txt('.ui-pdp-description__content') || null;

  // ---- cupom ("R$ 20 OFF com Cupom")
  const coupon = $$('[class*="coupon"], .ui-pdp-promotions-label').map((e) => e.textContent.replace(/\s+/g, ' ').trim())
    .find((t) => /cupom/i.test(t) && t.length < 60) || null;
  const pixTxt = $$('.ui-pdp-price__second-line, .ui-pdp-price__subtitles, [class*="pix"]').map((e) => e.textContent).join(' ');
  const pixPrice = /no pix/i.test(pixTxt) ? price : null;

  return {
    ml_id: catalogId || itemId,
    item_id: itemId,
    catalog_id: catalogId,
    title: txt('h1.ui-pdp-title') || ld.name || document.title.split('|')[0].trim(),
    price,
    original_price: originalPrice && originalPrice > price ? originalPrice : null,
    discount_label: discountTxt || null,
    currency: offer.priceCurrency || 'BRL',
    installments_qty: instM ? parseInt(instM[1], 10) : null,
    installments_value: instM ? num(instM[2]) : null,
    installments_free: /sem juros/i.test(instTxt),
    pix_price: pixPrice,
    image: images[0] || null,
    images,
    permalink: canonical,
    condition,
    rating,
    reviews_count: reviews,
    sold_label: soldLabel,
    badge,
    badges,
    coupon_label: coupon,
    delivery_label: shipTxt,
    free_shipping: !!freeShipping,
    is_full: !!isFull,
    brand: brand || null,
    store_name: store,
    brand_verified: official,
    ml_breadcrumb: breadcrumb,
    highlights,
    description: description ? description.slice(0, 5000) : null,
    available: !/pausad|esgotad|indispon[ií]vel/i.test(txt('.ui-pdp-container__row--message, .ui-pdp-message, .andes-message')),
    scraped_at: new Date().toISOString(),
  };
})();
