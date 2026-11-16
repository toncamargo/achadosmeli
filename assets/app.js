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
