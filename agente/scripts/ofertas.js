/**
 * AGENTE COLETOR — lista de busca/ofertas da CATEGORIA (domínio lista.mercadolivre.com.br).
 *
 * Instalação (uma vez): cole este arquivo inteiro no javascript_tool, trocando a última linha pela categoria.
 * Depois basta:  await new Function('return ' + localStorage.getItem('achadosmeli.agente.ofertas'))()({ ...CAT })
 *
 * CAT = { id, slug, n: 5, skip: ['MLB...'] }   (skip = ml_ids já coletados hoje nessa categoria)
 *
 * Fase A — na página da BUSCA da categoria: acha o link de OFERTAS nos filtros
 *   ("Tipo de promoção → Oferta do dia"; senão Oferta relâmpago; senão o menor filtro de "Descontos")
 *   e navega para ele.
 * Fase B — rode de novo quando a lista de ofertas carregar: devolve os N primeiros produtos em oferta
 *   (anúncios patrocinados são pulados) com a URL de cada um. Se faltar produto, volte para a busca
 *   e rode de novo: ele tenta o próximo link de ofertas.
 */
const AGENTE_OFERTAS = async (CAT) => {
  const KEY = 'achadosmeli.agente.pendente';
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
  const until = async (fn, ms = 15000) => { const t = Date.now(); while (Date.now() - t < ms) { const v = fn(); if (v) return v; await sleep(300); } return null; };
  await until(() => document.querySelector('li.ui-search-layout__item, .ui-search-filter-dl'));
  await sleep(600);

  let pend = JSON.parse(localStorage.getItem(KEY) || 'null');
  if (pend && pend.slug !== CAT.slug) pend = null;
  const onOffers = pend && location.pathname === pend.path;

  if (!onOffers) {
    // ---------- Fase A
    const tried = pend ? pend.tried : [];
    const groups = [...document.querySelectorAll('.ui-search-filter-dl')];
    const linksOf = (title) => {
      const g = groups.find((x) => (x.querySelector('h3, .ui-search-filter-dt-title')?.textContent || '').trim() === title);
      return g ? [...g.querySelectorAll('a')] : [];
    };
    const promo = linksOf('Tipo de promoção');
    const options = [
      promo.find((a) => /oferta do dia/i.test(a.textContent)),
      promo.find((a) => /relâmpago|relampago/i.test(a.textContent)),
      ...linksOf('Descontos').reverse(), // o último é o menor desconto (mais produtos)
    ].filter(Boolean);
    const pick = options.find((a) => !tried.includes(a.textContent.trim()));
    // a busca não tem filtro de ofertas: entra na 1ª subcategoria (que costuma ter) e tenta de novo
    const sub = linksOf('Categorias')[0];
    if (!pick && sub && !tried.includes('__subcategoria__')) {
      localStorage.setItem(KEY, JSON.stringify({ slug: CAT.slug, path: '__busca__', label: null,
        tried: [...tried, '__subcategoria__'], search_path: new URL(sub.href).pathname }));
      setTimeout(() => { location.href = sub.href; }, 200);
      return { fase: 'A', categoria: CAT.slug, link_de_ofertas: null,
        nota: 'sem filtro de ofertas nesta busca; abrindo a subcategoria "' + sub.textContent.trim() + '" — rode de novo quando carregar' };
    }
    if (!pick) {
      localStorage.removeItem(KEY);
      return { fase: 'A', categoria: CAT.slug, erro: 'nenhum link de ofertas nos filtros desta busca', tentados: tried };
    }
    const label = pick.textContent.replace(/\s+/g, ' ').trim();
    localStorage.setItem(KEY, JSON.stringify({ slug: CAT.slug, path: new URL(pick.href).pathname, label,
      tried: [...tried, pick.textContent.trim()], search_path: location.pathname }));
    setTimeout(() => { location.href = pick.href; }, 200);
    return { fase: 'A', categoria: CAT.slug, link_de_ofertas: label, proximo_passo: 'rode de novo quando a página de ofertas carregar' };
  }

  // ---------- Fase B
  const skip = new Set(CAT.skip || []);
  const out = [];
  let sponsored = 0;
  for (const c of document.querySelectorAll('li.ui-search-layout__item')) {
    if (out.length >= CAT.n) break;
    const a = c.querySelector('a.poly-component__title, h3 a, a[href]');
    if (!a) continue;
    const u = new URL(a.href);
    if (!/mercadolivre\.com\.br$/.test(u.host) || /mclics/.test(u.host + u.pathname)) { sponsored++; continue; }
    const cat = (u.pathname.match(/\/p\/(MLB\d+)/) || [])[1];
    const item = (u.pathname.match(/(MLB)-?(\d{6,})/) || []).slice(1).join('');
    const up = (u.pathname.match(/\/up\/(MLBU\d+)/) || [])[1];
    const key = cat || up || item || u.pathname;
    if (skip.has(key) || out.some((o) => o.key === key)) continue;
    out.push({ key, url: u.origin + u.pathname, title: a.textContent.trim().slice(0, 70) });
  }
  const faltam = Math.max(0, CAT.n - out.length);
  // faltou produto: guarda os filtros já tentados para a próxima rodada (na página da busca) tentar o seguinte
  if (faltam > 0) localStorage.setItem(KEY, JSON.stringify({ ...pend, path: '__busca__' }));
  else localStorage.removeItem(KEY);
  return { fase: 'B', categoria: CAT.slug, link_de_ofertas: pend.label, patrocinados_pulados: sponsored,
    faltam, search_path: pend.search_path, tentados: pend.tried, produtos: out };
};
localStorage.setItem('achadosmeli.agente.ofertas', AGENTE_OFERTAS.toString());
await AGENTE_OFERTAS({ id: 8, slug: 'ferramentas', n: 5, skip: [] });
