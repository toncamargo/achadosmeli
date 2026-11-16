/** AGENTE COLETOR — abre o primeiro produto da fila (depois disso, produto.js navega sozinho). */
(() => {
  const st = JSON.parse(localStorage.getItem('achadosmeli.agente') || '{}');
  const next = (st.queue || [])[0];
  if (!next) return { erro: 'fila vazia' };
  setTimeout(() => { location.href = next.href; }, 200);
  return { abrindo: next.category_slug + ' #' + next.position, fila: st.queue.length };
})();
