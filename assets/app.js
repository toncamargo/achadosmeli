// Banner rotativo
document.querySelectorAll('[data-slider]').forEach(slider => {
  const slides = [...slider.querySelectorAll('.slide')];
  const dots = [...slider.querySelectorAll('.dots button')];
  if (slides.length < 2) return;
  let i = 0, timer;
  const show = n => {
    i = (n + slides.length) % slides.length;
    slides.forEach((s, k) => s.classList.toggle('on', k === i));
    dots.forEach((d, k) => d.classList.toggle('on', k === i));
  };
  const auto = () => { clearInterval(timer); timer = setInterval(() => show(i + 1), 5500); };
  slider.querySelector('.hero-arrow.prev')?.addEventListener('click', () => { show(i - 1); auto(); });
  slider.querySelector('.hero-arrow.next')?.addEventListener('click', () => { show(i + 1); auto(); });
  dots.forEach((d, k) => d.addEventListener('click', () => { show(k); auto(); }));
  slider.addEventListener('mouseenter', () => clearInterval(timer));
  slider.addEventListener('mouseleave', auto);
  auto();
});

// Prateleiras horizontais
document.querySelectorAll('.shelf-wrap').forEach(wrap => {
  const track = wrap.querySelector('.shelf-track');
  const prev = wrap.querySelector('.arrow.prev');
  const next = wrap.querySelector('.arrow.next');
  const step = () => track.clientWidth * 0.9;
  const update = () => {
    prev.hidden = track.scrollLeft < 8;
    next.hidden = track.scrollLeft + track.clientWidth >= track.scrollWidth - 8;
  };
  prev.addEventListener('click', () => track.scrollBy({ left: -step(), behavior: 'smooth' }));
  next.addEventListener('click', () => track.scrollBy({ left: step(), behavior: 'smooth' }));
  track.addEventListener('scroll', update, { passive: true });
  window.addEventListener('resize', update);
  update();
});

// Fecha o menu de categorias ao clicar fora
document.addEventListener('click', ev => {
  document.querySelectorAll('details.cat-menu[open]').forEach(d => { if (!d.contains(ev.target)) d.open = false; });
});

// Abas de categoria (rolagem horizontal com setas)
document.querySelectorAll('[data-tabs]').forEach(box => {
  const track = box.querySelector('.ct-track');
  const prev = box.querySelector('.ct-arrow.prev');
  const next = box.querySelector('.ct-arrow.next');
  const update = () => {
    prev.hidden = track.scrollLeft < 8;
    next.hidden = track.scrollLeft + track.clientWidth >= track.scrollWidth - 8;
  };
  prev.addEventListener('click', () => track.scrollBy({ left: -track.clientWidth * 0.8 }));
  next.addEventListener('click', () => track.scrollBy({ left: track.clientWidth * 0.8 }));
  track.addEventListener('scroll', update, { passive: true });
  window.addEventListener('resize', update);
  const on = track.querySelector('.ct.on');
  if (on && on.offsetLeft + on.offsetWidth > track.clientWidth) track.scrollLeft = on.offsetLeft - 60;
  update();
});

// Sugestões da busca (autocomplete)
document.querySelectorAll('form.search[data-suggest]').forEach(form => {
  const input = form.querySelector('input[name="q"]');
  const endpoint = form.dataset.suggest;
  const list = document.createElement('ul');
  list.className = 'sug';
  list.id = 'sug-list';
  list.setAttribute('role', 'listbox');
  list.hidden = true;
  form.appendChild(list);
  input.setAttribute('role', 'combobox');
  input.setAttribute('aria-controls', 'sug-list');
  input.setAttribute('aria-expanded', 'false');

  const lens = '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><circle cx="11" cy="11" r="6.5"/><path d="M16 16l4.5 4.5"/></svg>';
  const norm = s => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
  const cache = new Map();
  let items = [], active = -1, timer, lastQ = '', ctrl;

  const close = () => { list.hidden = true; active = -1; input.setAttribute('aria-expanded', 'false'); };
  const open = () => { if (items.length) { list.hidden = false; input.setAttribute('aria-expanded', 'true'); } };
  const mark = () => [...list.children].forEach((li, i) => li.classList.toggle('on', i === active));
  const go = text => { input.value = text; close(); form.submit(); };

  const render = (sugs, q) => {
    items = sugs; active = -1;
    list.innerHTML = '';
    sugs.forEach(text => {
      const li = document.createElement('li');
      li.setAttribute('role', 'option');
      li.innerHTML = lens;
      const span = document.createElement('span');
      // parte digitada normal, o restante em negrito (como no Mercado Livre)
      const cut = norm(text).startsWith(norm(q)) ? q.length : 0;
      span.append(document.createTextNode(text.slice(0, cut)));
      if (cut < text.length) { const b = document.createElement('b'); b.textContent = text.slice(cut); span.append(b); }
      li.append(span);
      li.addEventListener('mousedown', ev => { ev.preventDefault(); go(text); });
      list.append(li);
    });
    sugs.length ? open() : close();
  };

  const fetchSug = async q => {
    if (cache.has(q)) return render(cache.get(q), q);
    ctrl?.abort(); ctrl = new AbortController();
    try {
      const res = await fetch(endpoint + '?q=' + encodeURIComponent(q), { signal: ctrl.signal });
      const data = res.ok ? await res.json() : [];
      cache.set(q, data);
      if (input.value.trim() === q) render(data, q);
    } catch (e) { /* requisição cancelada ou offline */ }
  };

  input.addEventListener('input', () => {
    const q = input.value.trim();
    clearTimeout(timer);
    if (q.length < 2) { items = []; close(); return; }
    lastQ = q;
    timer = setTimeout(() => fetchSug(q), 150);
  });
  input.addEventListener('keydown', ev => {
    if (list.hidden || !items.length) return;
    if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
      ev.preventDefault();
      const n = items.length;
      if (ev.key === 'ArrowDown') active = active + 1 >= n ? -1 : active + 1;
      else active = active - 1 < -1 ? n - 1 : active - 1;
      mark();
      input.value = active >= 0 ? items[active] : lastQ;
    } else if (ev.key === 'Enter' && active >= 0) {
      ev.preventDefault(); go(items[active]);
    } else if (ev.key === 'Escape') {
      close();
    }
  });
  input.addEventListener('focus', open);
  input.addEventListener('blur', () => setTimeout(close, 120));
});

// Rodapé: "Como funciona" abre o bloco "Mais informações"
document.querySelectorAll("[data-open-more]").forEach(a => a.addEventListener("click", () => {
  const d = document.getElementById("mais-info");
  if (d) d.open = true;
}));
