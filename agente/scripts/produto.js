/**
 * AGENTE COLETOR — página do PRODUTO.
 *
 * Instalação (uma vez por domínio: www.mercadolivre.com.br e produto.mercadolivre.com.br):
 *   cole este arquivo inteiro no javascript_tool, trocando a última linha pelo JOB do produto.
 *   Ele se guarda no localStorage e roda.
 * Depois, nas páginas seguintes do mesmo domínio, basta:
 *   await new Function('return ' + localStorage.getItem('achadosmeli.agente.produto'))()({ ...JOB })
 *   (se devolver erro "não instalado", cole o arquivo inteiro de novo).
 *
 * JOB = { category_id, category_slug, position, offers_label, next }   (next = URL do próximo produto ou null)
 *
 * O que faz:
 * 1. Espera a página carregar (título + barra preta de afiliados).
 * 2. Extrai os dados do produto (título, preços, parcelas, links das imagens, avaliações, selos, entrega, loja...).
 * 3. Clica em "Compartilhar" na barra preta de Afiliados e lê o painel "Gerar link / ID de produto":
 *    link meli.la, ID do produto, texto sugerido e etiqueta em uso.
 * 4. Guarda o resultado no localStorage deste domínio (chave achadosmeli.agente → exportar.js)
 *    e navega para JOB.next.
 */
const AGENTE_PRODUTO = async (JOB) => {
  const KEY = 'achadosmeli.agente';
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
  const until = async (fn, ms = 15000) => { const t = Date.now(); while (Date.now() - t < ms) { const v = fn(); if (v) return v; await sleep(300); } return null; };
  const today = new Date().toLocaleDateString('sv-SE', { timeZone: 'America/Sao_Paulo' });
  function extrairProduto() {
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
}

  // 1. página carregada
  const ok = await until(() => document.querySelector('h1.ui-pdp-title'));
  if (!ok) return { erro: 'página do produto não carregou', url: location.pathname };
  await sleep(800);

  // 2. dados do produto
  const p = extrairProduto();

  // 3. botão "Compartilhar" da barra preta de afiliados (no topo da página)
  const shareBtn = await until(() => [...document.querySelectorAll('button, a')]
    .find((b) => b.textContent.trim() === 'Compartilhar' && b.getBoundingClientRect().top < 70), 10000);
  const aff = { affiliate_url: null, affiliate_code: null, affiliate_text: null, affiliate_tag: null };
  if (shareBtn) {
    shareBtn.click();
    const areas = await until(() => {
      const t = [...document.querySelectorAll('textarea')];
      return t.some((x) => /^https:\/\/meli\.la\//.test(x.value.trim())) ? t : null;
    }, 15000);
    if (areas) {
      const vals = areas.map((x) => x.value.trim());
      aff.affiliate_url = vals.find((v) => /^https:\/\/meli\.la\/[A-Za-z0-9]+$/.test(v)) || null;
      aff.affiliate_text = vals.find((v) => /\n/.test(v)) || null;
      aff.affiliate_code = vals.find((v) => v && v !== aff.affiliate_url && v !== aff.affiliate_text && v.length < 40) || null;
      let box = areas[0];
      for (let i = 0; i < 8 && box && !/Etiqueta em uso/i.test(box.innerText || ''); i++) box = box.parentElement;
      const lines = ((box && box.innerText) || '').split('\n').map((l) => l.trim()).filter(Boolean);
      const i = lines.findIndex((l) => /^Etiqueta em uso/i.test(l));
      const tag = i >= 0 ? lines[i + 1] : null;
      aff.affiliate_tag = tag && !/link do produto/i.test(tag) ? tag : null;
    }
    document.querySelector('[aria-label="Fechar"], [aria-label="Cerrar"]')?.click();
  }

  // 4. guarda e segue para o próximo
  const result = { ...p, ...aff, category_id: JOB.category_id, category_slug: JOB.category_slug,
    offers_label: JOB.offers_label || null, position: JOB.position || null };
  let st = JSON.parse(localStorage.getItem(KEY) || '{}');
  if (st.date !== today) st = { date: today, results: [] };
  st.results = st.results.filter((r) => !(r.ml_id === result.ml_id && r.category_id === result.category_id));
  st.results.push(result);
  localStorage.setItem(KEY, JSON.stringify(st));
  if (JOB.next) setTimeout(() => { location.href = JOB.next; }, 400);

  return {
    categoria: JOB.category_slug, posicao: JOB.position, ml_id: result.ml_id, titulo: (result.title || '').slice(0, 60),
    preco: result.price, de: result.original_price, selo: result.badge, imagens: result.images.length,
    link_afiliado: result.affiliate_url, id_afiliado: result.affiliate_code, etiqueta: result.affiliate_tag,
    salvos_neste_dominio: st.results.length, indo_para: JOB.next ? new URL(JOB.next).pathname.slice(0, 60) : null,
  };
};
localStorage.setItem('achadosmeli.agente.produto', AGENTE_PRODUTO.toString());
await AGENTE_PRODUTO({ category_id: 8, category_slug: 'ferramentas', position: 1, offers_label: 'Oferta do dia', next: null });
