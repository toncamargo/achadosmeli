/** AGENTE COLETOR — resumo do que foi coletado hoje NESTE domínio (www. ou produto.). */
(() => {
  const st = JSON.parse(localStorage.getItem('achadosmeli.agente') || '{}');
  const por = {};
  for (const r of st.results || []) por[r.category_slug] = (por[r.category_slug] || 0) + 1;
  return { dominio: location.host, date: st.date, coletados: (st.results || []).length, por_categoria: por,
    sem_link_afiliado: (st.results || []).filter((r) => !r.affiliate_url).map((r) => r.ml_id) };
})();
