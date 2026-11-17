/*
 * Achados Meli — monta o vídeo do Reels no navegador (usado por admin/reels.php).
 *
 * AchadosReels.gravar(dados, opcoes) → Promise<{ blob, ext, info }>
 *   dados: { titulo, nota, avals, reais, cents, video (URL .m3u8 da maior resolução) }
 *   opcoes: { formato: 'reels' | 'feed', escalaTexto: 0.7, margem: 10, margemInferior, cta, aoMudar(status) }
 *
 * - Formato do Instagram: Reels 9:16 (1080x1920) ou feed 4:5 (1080x1350). Vídeo com proporção
 *   diferente entra inteiro, com o próprio vídeo desfocado no fundo.
 * - Por cima: card compacto do produto (título, nota, avaliações, preço) e o selo "💬 Comente QUERO".
 *   No Reels o card fica acima da área que o app cobre embaixo (legenda, insights).
 * - Áudio: se a gravação começar com um clique de verdade no botão, o som vai junto (sem tocar
 *   nos alto-falantes). Sem clique, o Chrome bloqueia o som e o vídeo sai mudo.
 *
 * AchadosReels.fotosZip(urls, slug) → Promise<Blob>  (todas as fotos em JPG, num .zip)
 * AchadosReels.baixar(blob, nome)
 */
(function () {
  'use strict';

  const espera = (ms) => new Promise((r) => setTimeout(r, ms));

  function baixar(blob, nome) {
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = nome;
    document.body.appendChild(a);
    a.click();
    setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 120000);
  }

  async function gravar(d, opt) {
    opt = opt || {};
    const aoMudar = opt.aoMudar || (() => {});
    const feed = opt.formato === 'feed';
    const W = 1080, H = feed ? 1350 : 1920;
    const margem = opt.margem == null ? 10 : opt.margem;
    const margemInf = opt.margemInferior != null ? opt.margemInferior : (feed ? margem : 110);
    const escala = opt.escalaTexto || 0.7;
    const cta = opt.cta || 'Comente QUERO';
    const info = {};

    aoMudar('carregando vídeo');
    const v = document.createElement('video');
    v.crossOrigin = 'anonymous';
    v.playsInline = true;
    v.preload = 'auto';
    v.style.cssText = 'position:fixed;left:-9999px;top:0;width:320px;height:568px';
    document.body.appendChild(v);
    v.src = d.video;
    await new Promise((ok, ko) => {
      v.onloadedmetadata = ok;
      v.onerror = () => ko(new Error('não consegui abrir o vídeo (erro ' + (v.error && v.error.code) + ')'));
    });
    const VW = v.videoWidth, VH = v.videoHeight;
    info.original = VW + 'x' + VH;
    info.resolucao = W + 'x' + H;
    info.duracao = Math.round(v.duration * 10) / 10;

    // Áudio pelo Web Audio: grava o som sem tocar nos alto-falantes (precisa de clique do usuário)
    let audio = [];
    try {
      const ac = new (window.AudioContext || window.webkitAudioContext)();
      if (ac.state === 'suspended') await Promise.race([ac.resume(), espera(500)]);
      if (ac.state === 'running') {
        const dest = ac.createMediaStreamDestination();
        ac.createMediaElementSource(v).connect(dest);
        audio = dest.stream.getAudioTracks();
      }
    } catch (e) { /* sem áudio */ }

    try { await v.play(); } catch (e) { info.mudo = true; v.muted = true; await v.play(); }
    v.pause();
    v.currentTime = 0;
    await new Promise((r) => { v.onseeked = r; });
    if (info.mudo || !audio.length) { info.mudo = true; audio = []; }

    const capa = Math.max(W / VW, H / VH), contem = Math.min(W / VW, H / VH);
    const cortar = Math.abs(VW / VH - W / H) / (W / H) <= 0.06;
    const esc = cortar ? capa : contem;
    const dw = VW * esc, dh = VH * esc, dx = (W - dw) / 2, dy = (H - dh) / 2;
    const bw = VW * capa, bh = VH * capa, bx = (W - bw) / 2, by = (H - bh) / 2;

    const sobre = desenharCard(W, H, d, { margem, margemInf, escala, cta });
    const canvas = document.createElement('canvas');
    canvas.width = W;
    canvas.height = H;
    const ctx = canvas.getContext('2d');
    let gravando = true;
    const quadro = () => {
      if (!cortar) {
        ctx.save();
        ctx.filter = 'blur(40px) brightness(0.55)';
        ctx.drawImage(v, bx, by, bw, bh);
        ctx.restore();
      }
      ctx.drawImage(v, dx, dy, dw, dh);
      ctx.drawImage(sobre, 0, 0);
      if (!gravando) return;
      if (v.requestVideoFrameCallback) v.requestVideoFrameCallback(quadro); else requestAnimationFrame(quadro);
    };
    quadro();

    const saida = canvas.captureStream(30);
    audio.forEach((t) => saida.addTrack(t));
    info.audio = audio.length > 0;

    let mime = 'video/mp4;codecs=avc1,mp4a.40.2';
    if (!MediaRecorder.isTypeSupported(mime)) mime = MediaRecorder.isTypeSupported('video/mp4') ? 'video/mp4' : 'video/webm';
    const rec = new MediaRecorder(saida, { mimeType: mime, videoBitsPerSecond: 6000000 });
    const partes = [];
    rec.ondataavailable = (e) => { if (e.data.size) partes.push(e.data); };
    const parou = new Promise((r) => { rec.onstop = r; });

    rec.start(1000);
    aoMudar('gravando (' + info.duracao + ' s)');
    await v.play();
    await new Promise((r) => { v.onended = r; });
    await espera(150);
    gravando = false;
    rec.stop();
    await parou;
    v.remove();

    const ext = mime.startsWith('video/mp4') ? 'mp4' : 'webm';
    const blob = new Blob(partes, { type: ext === 'mp4' ? 'video/mp4' : 'video/webm' });
    info.tamanho = blob.size;
    return { blob, ext, info };
  }

  function desenharCard(W, H, d, o) {
    const c = document.createElement('canvas');
    c.width = W;
    c.height = H;
    const g = c.getContext('2d');
    const tela = W / 360;
    const s = tela * o.escala;
    const fonte = (peso, px) => peso + ' ' + Math.round(px * s) + 'px "Proxima Nova", Inter, -apple-system, "Helvetica Neue", Arial, sans-serif';
    const m = o.margem * tela, pad = 14 * s, cardW = W - 2 * m, textoW = cardW - 2 * pad;

    g.font = fonte('600', 17);
    const palavras = String(d.titulo || '').split(' ');
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
    const x = m, y = H - o.margemInf * tela - cardH;

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
      const n = Math.round(parseFloat(String(d.nota).replace(',', '.')) || 0);
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

    g.font = fonte('700', 18);
    const t = '💬 ' + o.cta;
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

  function caixa(g, x, y, w, h, r) {
    g.beginPath();
    g.moveTo(x + r, y);
    g.arcTo(x + w, y, x + w, y + h, r);
    g.arcTo(x + w, y + h, x, y + h, r);
    g.arcTo(x, y + h, x, y, r);
    g.arcTo(x, y, x + w, y, r);
    g.closePath();
  }

  // ---------- fotos: sempre JPG, num zip sem compressão ----------
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

  async function fotosZip(urls, slug) {
    const arquivos = [];
    for (const item of urls) {
      const opcoes = Array.isArray(item) ? item : [item];
      for (const u of opcoes) {
        try {
          const r = await fetch(u, { mode: 'cors' });
          const tipo = r.headers.get('content-type') || '';
          if (!r.ok || !/^image\/(webp|jpeg|png)/.test(tipo)) continue;
          const jpg = await paraJpg(await r.blob());
          arquivos.push({ nome: slug + '-' + String(arquivos.length + 1).padStart(2, '0') + '.jpg', dados: new Uint8Array(await jpg.arrayBuffer()) });
          break;
        } catch (e) { /* tenta a próxima URL */ }
      }
    }
    return { blob: zip(arquivos), total: arquivos.length };
  }

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

  window.AchadosReels = { gravar, fotosZip, baixar };
})();
