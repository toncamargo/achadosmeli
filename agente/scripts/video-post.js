// AGENTE_VIDEO_POST — monta o post de Instagram de um anúncio do Mercado Livre, dentro do Chrome.
// Usado pelo agente baixar-video-ml (agente/baixar-video-ml.md).
//
// Como usar (Claude in Chrome, ferramenta javascript_tool, na página do produto):
//   1) cole o conteúdo deste arquivo inteiro (define window.AGENTE_VIDEO_POST);
//   2) rode:  AGENTE_VIDEO_POST({ slug: 'nome-do-arquivo' }); 'ok'
//   3) consulte window.__rec até status = 'done' (ou 'sem-video' / 'error').
//
// O que faz:
//   - lê título, nota, nº de avaliações, preço e fotos da página;
//   - acha o vídeo principal (shorts-api), escolhe a maior resolução e grava em MP4
//     com um card do produto embaixo (margem de 10px nas laterais e embaixo, na escala
//     de uma tela de 360px de largura) e o selo "Comente QUERO" logo acima do card;
//   - baixa {slug}.mp4 e {slug}-imagens.zip (todas as fotos em resolução máxima, sempre em JPG).
// Sem bibliotecas externas e sem eval. O zip é montado aqui mesmo (sem compressão).
window.AGENTE_VIDEO_POST = async function (opt) {
  opt = opt || {};
  const R = (window.__rec = { status: 'iniciando' });
  const MARGEM = opt.margem == null ? 10 : opt.margem; // px numa tela de 360px de largura
  const CTA = opt.cta || 'Comente QUERO';
  const BS = String.fromCharCode(92);
  const $ = (s, el) => (el || document).querySelector(s);
  const txt = (s) => (($(s) || {}).textContent || '').replace(/\s+/g, ' ').trim();
  const espera = (ms) => new Promise((r) => setTimeout(r, ms));

  const baixar = (blob, nome) => {
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = nome;
    document.body.appendChild(a);
    a.click();
    setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 120000);
  };

  try {
    // ---------- 1. dados do produto ----------
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
    const original = ((($('.ui-pdp-price__original-value .andes-money-amount__fraction') || {}).textContent) || '').trim();
    const desconto = txt('.ui-pdp-price__second-line .andes-money-amount__discount');
    const vendidos = txt('.ui-pdp-header__subtitle');
    const destaques = [...document.querySelectorAll('.ui-vpp-highlighted-specs__features-list-item, .ui-pdp-features li')]
      .map((li) => li.textContent.replace(/\s+/g, ' ').trim()).filter(Boolean).slice(0, 6);
    const descricao = txt('.ui-pdp-description__content').slice(0, 900);

    // fotos: a galeria inteira, sem repetir. As miniaturas da página têm 70px; pelo ID da foto
    // monta a URL da versão grande (D_NQ_NP_2X_{id}-O.webp, ~1000px). Ícones/SVG ficam de fora.
    const vistos = new Set();
    const imagens = [];
    document.querySelectorAll('.ui-pdp-gallery__figure img, .ui-pdp-gallery img').forEach((img) => {
      const u = img.getAttribute('data-zoom') || img.getAttribute('data-src') || img.currentSrc || img.src || '';
      const id = (u.match(/D_(?:NQ_)?(?:NP_)?(?:2X_)?(?:Q_)?(\d+-ML[A-Z]?\d+_\d+)/) || [])[1];
      if (!id || vistos.has(id)) return;
      vistos.add(id);
      imagens.push({ grande: 'https://http2.mlstatic.com/D_NQ_NP_2X_' + id + '-O.webp', original: u });
    });

    R.info = { titulo, nota, avals, preco: 'R$ ' + reais + (cents ? ',' + cents : ''), original, desconto, vendidos, destaques, descricao, fotos: imagens.length };

    // ---------- 2. vídeo principal ----------
    R.status = 'procurando-video';
    const html = document.documentElement.innerHTML.split(BS + 'u002F').join('/').split(BS + '/').join('/');
    const m3u8 = [...new Set(html.match(/https?:[^"'\s<>\x5c]+?\.m3u8[^"'\s<>\x5c]*/g) || [])];
    const master = m3u8.find((u) => u.includes('/shorts-api/')) ||
      (opt.incluirClipes ? m3u8[0] : m3u8.find((u) => !u.includes('video-vod-clips')));
    let variante = null;
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
      variante = vs[0] ? vs[0].u : master; // master sem variantes: usa ela mesma
    }

    // ---------- 3. fotos (zip) — baixadas mesmo se não houver vídeo ----------
    R.status = 'baixando-fotos';
    const arquivos = [];
    for (let i = 0; i < imagens.length; i++) {
      let ok = false;
      for (const u of [imagens[i].grande, imagens[i].original]) {
        try {
          const r = await fetch(u, { mode: 'cors' });
          const tipo = r.headers.get('content-type') || '';
          if (!r.ok || !/^image\/(webp|jpeg|png)/.test(tipo)) continue;
          const jpg = await paraJpg(await r.blob()); // sempre JPG, seja qual for o formato do ML
          arquivos.push({ nome: (opt.slug || 'produto') + '-' + String(arquivos.length + 1).padStart(2, '0') + '.jpg', dados: new Uint8Array(await jpg.arrayBuffer()) });
          ok = true;
          break;
        } catch (e) { /* tenta a próxima URL */ }
      }
      if (!ok) (R.falhasFotos = R.falhasFotos || []).push(i + 1);
    }
    R.fotosBaixadas = arquivos.length;

    if (!variante) {
      if (arquivos.length) baixar(zip(arquivos), (opt.slug || 'produto') + '-imagens.zip');
      R.status = 'sem-video';
      return R;
    }

    // ---------- 4. grava o vídeo com o card ----------
    R.status = 'carregando-video';
    const v = document.createElement('video');
    v.crossOrigin = 'anonymous';
    v.playsInline = true;
    v.preload = 'auto';
    v.style.cssText = 'position:fixed;left:-9999px;top:0;width:320px;height:568px';
    document.body.appendChild(v);
    v.src = variante;
    await new Promise((ok, ko) => { v.onloadedmetadata = ok; v.onerror = () => ko(new Error('erro no video ' + (v.error && v.error.code))); });
    const W = v.videoWidth, H = v.videoHeight;
    R.resolucao = W + 'x' + H;
    R.duracao = Math.round(v.duration * 10) / 10;

    try { await v.play(); } catch (e) { R.mudo = true; v.muted = true; await v.play(); }
    v.pause();
    v.currentTime = 0;
    await new Promise((r) => { v.onseeked = r; });

    const sobreposicao = desenharCard(W, H, { titulo, nota, avals, reais, cents, MARGEM, CTA });
    const canvas = document.createElement('canvas');
    canvas.width = W;
    canvas.height = H;
    const ctx = canvas.getContext('2d');
    let gravando = true;
    const quadro = () => {
      ctx.drawImage(v, 0, 0, W, H);
      ctx.drawImage(sobreposicao, 0, 0);
      if (!gravando) return;
      if (v.requestVideoFrameCallback) v.requestVideoFrameCallback(quadro); else requestAnimationFrame(quadro);
    };
    quadro();

    const saida = canvas.captureStream(30);
    const audio = v.captureStream ? v.captureStream().getAudioTracks() : [];
    audio.forEach((t) => saida.addTrack(t));
    R.audio = audio.length > 0 && !R.mudo;

    let mime = 'video/mp4;codecs=avc1,mp4a.40.2';
    if (!MediaRecorder.isTypeSupported(mime)) mime = MediaRecorder.isTypeSupported('video/mp4') ? 'video/mp4' : 'video/webm';
    const rec = new MediaRecorder(saida, { mimeType: mime, videoBitsPerSecond: 6000000 });
    const partes = [];
    rec.ondataavailable = (e) => { if (e.data.size) partes.push(e.data); };
    const parou = new Promise((r) => { rec.onstop = r; });

    rec.start(1000);
    R.status = 'gravando';
    await v.play();
    await new Promise((r) => { v.onended = r; });
    await espera(150);
    gravando = false;
    rec.stop();
    await parou;
    v.remove();

    const ext = mime.startsWith('video/mp4') ? 'mp4' : 'webm';
    const video = new Blob(partes, { type: ext === 'mp4' ? 'video/mp4' : 'video/webm' });
    R.tamanho = video.size;
    R.arquivo = (opt.slug || 'produto') + '.' + ext;
    baixar(video, R.arquivo);
    await espera(1500); // dá tempo do Chrome registrar o 1º download antes do 2º
    if (arquivos.length) baixar(zip(arquivos), (opt.slug || 'produto') + '-imagens.zip');
    R.status = 'done';
  } catch (e) {
    R.status = 'error';
    R.erro = String(e);
  }
  return R;

  // ---------- card do produto + selo "Comente QUERO" ----------
  function desenharCard(W, H, d) {
    const c = document.createElement('canvas');
    c.width = W;
    c.height = H;
    const g = c.getContext('2d');
    const s = W / 360; // escala de uma tela de celular de 360px de largura
    const fonte = (peso, px) => peso + ' ' + Math.round(px * s) + 'px "Proxima Nova", -apple-system, "Helvetica Neue", Arial, sans-serif';
    const m = d.MARGEM * s, pad = 14 * s, cardW = W - 2 * m, textoW = cardW - 2 * pad;

    // título: até 2 linhas, com reticências
    g.font = fonte('600', 17);
    const palavras = d.titulo.split(' ');
    const linhas = [];
    let atual = '';
    for (let i = 0; i < palavras.length; i++) {
      const teste = atual ? atual + ' ' + palavras[i] : palavras[i];
      if (g.measureText(teste).width <= textoW) { atual = teste; continue; }
      if (linhas.length === 1) {
        let resto = atual;
        while (resto && g.measureText(resto + '…').width > textoW) resto = resto.slice(0, -1);
        linhas.push(resto.replace(/\s+\S*$/, '') + '…');
        atual = '';
        break;
      }
      linhas.push(atual);
      atual = palavras[i];
    }
    if (atual) linhas.push(atual);

    const lh = 22 * s, notaH = d.nota ? 22 * s : 0, precoH = 40 * s;
    const cardH = pad + linhas.length * lh + 4 * s + notaH + 2 * s + precoH + pad * 0.6;
    const x = m, y = H - m - cardH;

    // card branco com sombra
    g.save();
    g.shadowColor = 'rgba(0,0,0,.28)';
    g.shadowBlur = 16 * s;
    g.shadowOffsetY = 3 * s;
    g.fillStyle = '#fff';
    caixa(g, x, y, cardW, cardH, 10 * s);
    g.fill();
    g.restore();

    let cy = y + pad;
    g.textBaseline = 'top';
    g.fillStyle = 'rgba(0,0,0,.9)';
    g.font = fonte('600', 17);
    linhas.forEach((l) => { g.fillText(l, x + pad, cy); cy += lh; });
    cy += 4 * s;

    if (d.nota) {
      let cx = x + pad;
      g.font = fonte('400', 13);
      g.fillStyle = 'rgba(0,0,0,.55)';
      g.fillText(d.nota, cx, cy + 2 * s);
      cx += g.measureText(d.nota).width + 6 * s;
      const n = Math.round(parseFloat(d.nota.replace(',', '.')) || 0);
      g.font = fonte('400', 15);
      for (let i = 0; i < 5; i++) {
        g.fillStyle = i < n ? '#3483fa' : '#c8d9f7';
        g.fillText('★', cx, cy);
        cx += 16 * s;
      }
      if (d.avals) {
        g.font = fonte('400', 13);
        g.fillStyle = 'rgba(0,0,0,.55)';
        g.fillText('(' + d.avals + ')', cx + 4 * s, cy + 2 * s);
      }
      cy += notaH;
    }

    // preço: "R$ 54" grande + centavos sobrescritos
    cy += 2 * s;
    g.fillStyle = 'rgba(0,0,0,.9)';
    g.font = fonte('400', 30);
    const p = 'R$ ' + d.reais;
    g.fillText(p, x + pad, cy);
    if (d.cents) {
      const px = x + pad + g.measureText(p).width + 2 * s;
      g.font = fonte('400', 15);
      g.fillText(d.cents, px, cy + 2 * s);
    }

    // selo "Comente QUERO" logo acima do card
    g.font = fonte('700', 18);
    const t = '💬 ' + d.CTA;
    const sw = g.measureText(t).width + 32 * s, sh = 40 * s;
    const sx = x, sy = y - 10 * s - sh;
    g.save();
    g.shadowColor = 'rgba(0,0,0,.25)';
    g.shadowBlur = 10 * s;
    g.fillStyle = '#fff159';
    caixa(g, sx, sy, sw, sh, sh / 2);
    g.fill();
    g.restore();
    g.fillStyle = '#2d3277';
    g.textBaseline = 'middle';
    g.fillText(t, sx + 16 * s, sy + sh / 2 + s);
    return c;
  }

  // converte qualquer imagem (webp/png/jpg) em JPG de qualidade 92, com fundo branco
  async function paraJpg(blob) {
    if (blob.type === 'image/jpeg') return blob;
    const bmp = await createImageBitmap(blob);
    const c = document.createElement('canvas');
    c.width = bmp.width;
    c.height = bmp.height;
    const g = c.getContext('2d');
    g.fillStyle = '#fff';
    g.fillRect(0, 0, c.width, c.height);
    g.drawImage(bmp, 0, 0);
    bmp.close();
    return await new Promise((ok, ko) => c.toBlob((b) => (b ? ok(b) : ko(new Error('falha ao gerar jpg'))), 'image/jpeg', 0.92));
  }

  function caixa(g, x, y, w, h, r) {
    g.beginPath();
    g.moveTo(x + r, y);
    g.arcTo(x + w, y, x + w, y + h, r);
    g.arcTo(x + w, y + h, x, y + h, r);
    g.arcTo(x, y + h, x, y, r);
    g.arcTo(x, y, x + w, y, r);
    g.closePath();
  }

  // ---------- zip simples (sem compressão) ----------
  function zip(lista) {
    const tabela = new Uint32Array(256);
    for (let n = 0; n < 256; n++) { let c = n; for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1; tabela[n] = c >>> 0; }
    const crc = (d) => { let c = 0xffffffff; for (let i = 0; i < d.length; i++) c = tabela[(c ^ d[i]) & 255] ^ (c >>> 8); return (c ^ 0xffffffff) >>> 0; };
    const enc = new TextEncoder();
    const locais = [], centrais = [];
    let offset = 0;
    lista.forEach((f) => {
      const nome = enc.encode(f.nome), c = crc(f.dados), tam = f.dados.length;
      const h = new DataView(new ArrayBuffer(30));
      h.setUint32(0, 0x04034b50, true); h.setUint16(4, 20, true); h.setUint16(6, 0x0800, true);
      h.setUint32(14, c, true); h.setUint32(18, tam, true); h.setUint32(22, tam, true); h.setUint16(26, nome.length, true);
      locais.push(new Uint8Array(h.buffer), nome, f.dados);
      const k = new DataView(new ArrayBuffer(46));
      k.setUint32(0, 0x02014b50, true); k.setUint16(4, 20, true); k.setUint16(6, 20, true); k.setUint16(8, 0x0800, true);
      k.setUint32(16, c, true); k.setUint32(20, tam, true); k.setUint32(24, tam, true); k.setUint16(28, nome.length, true);
      k.setUint32(42, offset, true);
      centrais.push(new Uint8Array(k.buffer), nome);
      offset += 30 + nome.length + tam;
    });
    const tamCentral = centrais.reduce((a, b) => a + b.length, 0);
    const fim = new DataView(new ArrayBuffer(22));
    fim.setUint32(0, 0x06054b50, true); fim.setUint16(8, lista.length, true); fim.setUint16(10, lista.length, true);
    fim.setUint32(12, tamCentral, true); fim.setUint32(16, offset, true);
    return new Blob([...locais, ...centrais, new Uint8Array(fim.buffer)], { type: 'application/zip' });
  }
};
