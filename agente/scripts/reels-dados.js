// AGENTE_REELS_DADOS — lê o anúncio do Mercado Livre e abre o painel de Reels do site com os dados.
// Usado pelo agente baixar-video-ml (agente/baixar-video-ml.md).
//
// Como usar (Claude in Chrome, ferramenta javascript_tool, na página do produto no ML):
//   1) cole o conteúdo deste arquivo inteiro (define window.AGENTE_REELS_DADOS);
//   2) rode:  await AGENTE_REELS_DADOS({ site: 'https://achadosmeli.com', slug: '...', fotos: true|false })
//      → devolve um resumo e, em seguida, a aba vai para {site}/admin/reels.php,
//        que lê os dados de window.name (o nome da aba sobrevive à troca de site).
//
// Opções: site, slug, fotos (false), baixarMp4 (false), formato ('reels' | 'feed'),
//         incluirClipes (false), naoAbrir (false: só monta os dados, sem trocar de página).
// Sem bibliotecas externas e sem eval.
window.AGENTE_REELS_DADOS = async function (opt) {
  opt = opt || {};
  const BS = String.fromCharCode(92);
  const $ = (s, el) => (el || document).querySelector(s);
  const txt = (s) => (($(s) || {}).textContent || '').replace(/\s+/g, ' ').trim();

  const titulo = txt('h1.ui-pdp-title') || document.title.split('|')[0].trim();
  const nota = txt('.ui-pdp-review__rating');
  const avals = txt('.ui-pdp-review__amount').replace(/[^\d.]/g, '');
  let reais = '', cents = '';
  const preco = $('.ui-pdp-price__second-line .andes-money-amount') || $('.ui-pdp-price .andes-money-amount');
  if (preco) {
    reais = (($('.andes-money-amount__fraction', preco) || {}).textContent || '').trim();
    cents = (($('.andes-money-amount__cents', preco) || {}).textContent || '').trim();
  }
  if (!reais) {
    const m = ($('meta[itemprop="price"]') || {}).content || '';
    const p = m.split('.');
    reais = Number(p[0] || 0).toLocaleString('pt-BR');
    cents = p[1] ? (p[1] + '0').slice(0, 2) : '';
  }
  if (cents === '00') cents = '';
  const origF = txt('.ui-pdp-price__original-value .andes-money-amount__fraction');
  const origC = txt('.ui-pdp-price__original-value .andes-money-amount__cents');

  // IDs do ML: catálogo (/p/MLB…) e anúncio vencedor (wid / item_id)
  const ids = [];
  const pm = location.pathname.match(/\/p\/(MLB\d+)/);
  if (pm) ids.push(pm[1]);
  const am = location.pathname.match(/(MLB)-?(\d{6,})/);
  if (am) ids.push(am[1] + am[2]);
  const html = document.documentElement.innerHTML.split(BS + 'u002F').join('/').split(BS + '/').join('/');
  const im = html.match(/"item_id":"(MLB\d+)"/);
  if (im) ids.push(im[1]);
  const wid = new URLSearchParams(location.hash.slice(1) + '&' + location.search.slice(1)).get('wid');
  if (wid) ids.push(wid);

  // fotos: [versão grande, original] — o painel converte para JPG
  const vistos = new Set();
  const imagens = [];
  document.querySelectorAll('.ui-pdp-gallery__figure img, .ui-pdp-gallery img').forEach((img) => {
    const u = img.getAttribute('data-zoom') || img.getAttribute('data-src') || img.currentSrc || img.src || '';
    const id = (u.match(/D_(?:NQ_)?(?:NP_)?(?:2X_)?(?:Q_)?(\d+-ML[A-Z]?\d+_\d+)/) || [])[1];
    if (!id || vistos.has(id)) return;
    vistos.add(id);
    imagens.push(['https://http2.mlstatic.com/D_NQ_NP_2X_' + id + '-O.webp', u]);
  });

  // vídeo principal: playlist da shorts-api → variante de maior resolução
  const m3u8 = [...new Set(html.match(/https?:[^"'\s<>\x5c]+?\.m3u8[^"'\s<>\x5c]*/g) || [])];
  const master = m3u8.find((u) => u.includes('/shorts-api/')) ||
    (opt.incluirClipes ? m3u8[0] : m3u8.find((u) => !u.includes('video-vod-clips')));
  let video = null;
  if (master) {
    const t = await fetch(master).then((r) => r.text());
    const ls = t.split('\n');
    const vs = [];
    for (let i = 0; i < ls.length; i++) {
      if (ls[i].startsWith('#EXT-X-STREAM-INF')) {
        const r = ls[i].match(/RESOLUTION=(\d+)x(\d+)/) || [0, 0, 0];
        vs.push({ w: +r[1], h: +r[2], u: new URL(ls[i + 1].trim(), master).href });
      }
    }
    vs.sort((a, b) => b.w * b.h - a.w * a.h);
    video = vs[0] ? vs[0].u : master;
  }

  const dados = {
    titulo, nota, avals, reais, cents,
    preco: 'R$ ' + reais + (cents ? ',' + cents : ''),
    original: origF ? 'R$ ' + origF + (origC ? ',' + origC : '') : '',
    desconto: txt('.ui-pdp-price__second-line .andes-money-amount__discount'),
    parcelas: txt('.ui-pdp-price__subtitles'),
    vendidos: txt('.ui-pdp-header__subtitle'),
    destaques: [...document.querySelectorAll('.ui-vpp-highlighted-specs__features-list-item, .ui-pdp-features li')]
      .map((li) => li.textContent.replace(/\s+/g, ' ').trim()).filter(Boolean).slice(0, 6),
    descricao: txt('.ui-pdp-description__content').slice(0, 900),
    ml_ids: [...new Set(ids)],
    permalink: location.origin + location.pathname,
    imagens, video,
    slug: opt.slug || 'produto',
    fotos: !!opt.fotos, baixarMp4: !!opt.baixarMp4, formato: opt.formato === 'feed' ? 'feed' : 'reels',
  };
  window.__reelsDados = dados;
  window.name = JSON.stringify({ tipo: 'achadosmeli-reels', dados });

  const resumo = { titulo: titulo.slice(0, 80), preco: dados.preco, ids: dados.ml_ids, fotos: imagens.length, temVideo: !!video };
  if (!opt.naoAbrir) setTimeout(() => { location.href = String(opt.site || 'https://achadosmeli.com').replace(/\/$/, '') + '/admin/reels.php'; }, 300);
  return resumo;
};
