/**
 * AGENTE COLETOR — junta a coleta do dia num arquivo só e baixa (Downloads/achadosmeli-coleta-AAAA-MM-DD.json).
 *
 * Cada domínio do ML (www. e produto.) guarda os seus resultados no próprio localStorage.
 * 1. Rode numa página de produto.mercadolivre.com.br: os resultados vão para window.name e a aba
 *    navega sozinha para www.mercadolivre.com.br/ofertas.
 * 2. Rode de novo (já em www.): junta com os resultados de www. e baixa o JSON.
 *    Se o Chrome bloquear o download ("vários downloads"), libere em Configurações do site ou use status.js.
 */
(async () => {
  const KEY = 'achadosmeli.agente';
  const st = JSON.parse(localStorage.getItem(KEY) || '{}');
  if (location.host.startsWith('produto.')) {
    window.name = 'ACHADOSMELI:' + JSON.stringify(st.results || []);
    setTimeout(() => { location.href = 'https://www.mercadolivre.com.br/ofertas'; }, 200);
    return { passo: 1, transferidos: (st.results || []).length, proximo: 'rode exportar.js de novo quando a página carregar' };
  }
  if (window.name.startsWith('ACHADOSMELI:')) {
    const extra = JSON.parse(window.name.slice(12));
    window.name = '';
    const ids = new Set(extra.map((r) => r.ml_id));
    st.results = [...(st.results || []).filter((r) => !ids.has(r.ml_id)), ...extra];
    localStorage.setItem(KEY, JSON.stringify(st));
  }
  const blob = new Blob([JSON.stringify({ date: st.date, results: st.results || [] }, null, 1)], { type: 'application/json' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'achadosmeli-coleta-' + st.date + '.json';
  document.body.appendChild(a); a.click(); a.remove();
  const por = {};
  (st.results || []).forEach((r) => { por[r.category_slug] = (por[r.category_slug] || 0) + 1; });
  return { passo: 2, arquivo: a.download, total: (st.results || []).length, por_categoria: por,
    sem_link_afiliado: (st.results || []).filter((r) => !r.affiliate_url).map((r) => r.ml_id) };
})();
