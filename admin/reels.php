<?php
/**
 * Reels: recebe os dados de um anúncio do Mercado Livre (do agente agente/baixar-video-ml.md),
 * grava o vídeo com o card do produto no navegador (assets/reels.js), envia o MP4 para o site
 * e publica no Instagram pela API oficial (src/Instagram.php). Depois de publicado, o Reels fica
 * ligado ao produto: quem comentar "quero" recebe o link no direct (mesma automação de admin/instagram.php).
 *
 * Como os dados chegam: na página do produto no ML, o script agente/scripts/reels-dados.js coloca
 * os dados em window.name e abre esta página na mesma aba.
 */
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/admin_layout.php';
require_admin();

const REELS_MAX_BYTES = 300 * 1024 * 1024; // limite do Instagram para Reels

function reels_json(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function reels_public(array $r): array
{
    return [
        'id' => (int)$r['id'], 'status' => $r['status'], 'erro' => $r['error'],
        'permalink' => $r['permalink'], 'media_id' => $r['media_id'], 'produto' => $r['product_id'] ? (int)$r['product_id'] : null,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    if (!hash_equals(csrf_token(), (string)($_POST['_csrf'] ?? ''))) reels_json(['erro' => 'Sessão expirada. Recarregue a página.'], 400);
    try {
        switch ((string)($_POST['action'] ?? '')) {
            case 'produto': // acha o produto do site pelo ID do ML (catálogo /p/MLB… ou anúncio MLB…)
                $ids = array_values(array_filter(array_map('trim', explode(',', (string)$_POST['ml_ids']))));
                $p = null;
                if ($ids) {
                    $in = implode(',', array_fill(0, count($ids), '?'));
                    $st = db()->prepare("SELECT id, title, price, affiliate_url, active FROM products WHERE ml_id IN ($in) OR item_id IN ($in) ORDER BY active DESC, id DESC LIMIT 1");
                    $st->execute([...$ids, ...$ids]);
                    $p = $st->fetch() ?: null;
                }
                reels_json(['produto' => $p]);

            case 'limite': // testa a permissão de publicar e mostra o uso do limite diário
                reels_json(['conta' => setting('ig_username'), 'limite' => Instagram::publishingLimit()]);

            case 'chunk': // envio do MP4 em partes de 1,5 MB (cabe no upload_max_filesize padrão de 2 MB do PHP)
                $uid = preg_replace('/[^a-f0-9]/', '', (string)$_POST['upload_id']);
                $index = (int)$_POST['index'];
                $total = (int)$_POST['total'];
                if (strlen($uid) < 16 || $total < 1 || $index < 0 || $index >= $total) throw new RuntimeException('Envio inválido.');
                if (($_FILES['parte']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('Falha no envio da parte ' . ($index + 1) . '.');
                $tmp = Instagram::reelsDir() . "/tmp-$uid.part";
                if ($index === 0) @unlink($tmp);
                elseif (!is_file($tmp)) throw new RuntimeException('Envio perdido; recomece.');
                file_put_contents($tmp, file_get_contents($_FILES['parte']['tmp_name']), FILE_APPEND | LOCK_EX);
                if (filesize($tmp) > REELS_MAX_BYTES) { @unlink($tmp); throw new RuntimeException('Vídeo maior que 300 MB.'); }
                if ($index < $total - 1) reels_json(['ok' => true]);

                $head = (string)file_get_contents($tmp, false, null, 0, 12);
                if (substr($head, 4, 4) !== 'ftyp') { @unlink($tmp); throw new RuntimeException('O arquivo enviado não é um MP4.'); }
                $slug = substr(slugify((string)($_POST['slug'] ?? '')) ?: 'reels', 0, 60);
                $name = $slug . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.mp4';
                rename($tmp, Instagram::reelsDir() . '/' . $name);
                reels_json(['video' => $name, 'url' => url('assets/reels/' . rawurlencode($name))]);

            case 'publicar': // registra e manda para a Meta
                if (!Instagram::isConnected()) throw new RuntimeException('Instagram não conectado (Admin › Instagram).');
                $video = basename((string)$_POST['video']);
                if (!preg_match('/^[a-z0-9-]+\.mp4$/', $video) || !is_file(Instagram::reelsDir() . "/$video")) throw new RuntimeException('Vídeo não encontrado no servidor.');
                $caption = trim((string)$_POST['legenda']);
                if ($caption === '') throw new RuntimeException('Escreva a legenda.');
                $pid = (int)($_POST['produto_id'] ?? 0);
                if ($pid) {
                    $st = db()->prepare('SELECT id FROM products WHERE id = ?');
                    $st->execute([$pid]);
                    if (!$st->fetchColumn()) throw new RuntimeException("Produto #$pid não existe no site.");
                }
                $id = Instagram::reelSave($video, $caption, $pid ?: null, (string)($_POST['ml_id'] ?? ''), (string)($_POST['titulo'] ?? ''),
                    !empty($_POST['share_to_feed']));
                reels_json(['reels' => reels_public(Instagram::reelStart($id))]);

            case 'status': // acompanha o processamento e publica quando pronto
                reels_json(['reels' => reels_public(Instagram::reelCheck((int)$_POST['id']))]);

            case 'tentar': // nova tentativa de um Reels com erro (o vídeo precisa ainda estar no servidor)
                reels_json(['reels' => reels_public(Instagram::reelStart((int)$_POST['id']))]);
        }
        reels_json(['erro' => 'Ação desconhecida.'], 400);
    } catch (Throwable $ex) {
        reels_json(['erro' => $ex->getMessage()], 422);
    }
}

$reels = db()->query('SELECT r.*, p.title AS p_title FROM ig_reels r LEFT JOIN products p ON p.id = r.product_id ORDER BY r.id DESC LIMIT 30')->fetchAll();
$connected = Instagram::isConnected();

admin_header('Reels no Instagram', 'reels');
?>
<style>
.rl-grid{display:grid;grid-template-columns:minmax(260px,340px) 1fr;gap:18px;align-items:start}
@media (max-width:860px){.rl-grid{grid-template-columns:1fr}}
.rl-video{width:100%;aspect-ratio:9/16;background:#111;border-radius:12px;display:block}
.rl-prod{display:flex;gap:10px;align-items:center}
.rl-prod img{width:64px;height:64px;object-fit:contain;border-radius:8px;background:#fff;border:1px solid #eee}
.rl-status{padding:10px 12px;border-radius:8px;background:#f4f6fb;font-size:14px}
.rl-status.ok{background:#e8f5e9;color:#1b5e20}.rl-status.err{background:#fdecea;color:#b71c1c}
.rl-row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
#legenda{min-height:260px;font-family:inherit}
</style>

<?php if (!$connected): ?>
  <div class="alert alert-err">Conecte a conta em <a href="<?= e(url('admin/instagram.php')) ?>">Admin › Instagram</a> antes de publicar.</div>
<?php else: ?>
  <div class="card rl-row">
    <span>Publicando como <b>@<?= e(setting('ig_username')) ?></b>.</span>
    <button type="button" class="btn btn-sm" id="testar">Testar permissão de publicar</button>
    <span id="limite" class="muted small"></span>
  </div>
<?php endif; ?>

<div class="card" id="vazio">
  <h2>Novo Reels</h2>
  <p class="muted">Use o agente <code>baixar-video-ml</code> com o link de um produto do Mercado Livre: ele lê o anúncio,
    abre esta página e preenche tudo. Aqui o vídeo é gravado com o card do produto, você revisa a legenda e publica.</p>
</div>

<div class="card" id="novo" hidden>
  <h2>Novo Reels</h2>
  <div class="rl-grid">
    <div>
      <video id="previa" class="rl-video" controls playsinline muted></video>
      <p class="muted small" id="info"></p>
    </div>
    <div>
      <div class="rl-prod" id="prod"></div>
      <br>
      <div class="rl-row">
        <label>Formato
          <select id="formato"><option value="reels">Reels 9:16 (1080×1920)</option><option value="feed">Feed 4:5 (1080×1350)</option></select></label>
        <label><input type="checkbox" id="baixar_mp4"> baixar o MP4 no computador</label>
        <label><input type="checkbox" id="baixar_fotos"> baixar as fotos (JPG, .zip)</label>
      </div>
      <p><button type="button" class="btn btn-primary" id="gravar">🎬 Gravar vídeo</button>
        <span class="muted small">Clique no botão para o vídeo sair com som.</span></p>
      <label>ID do produto no site <small class="muted">(liga o Reels à resposta automática do “quero”)</small>
        <input id="produto_id" inputmode="numeric" placeholder="ex.: 73"></label>
      <label>Legenda
        <textarea id="legenda" maxlength="2200" placeholder="Escreva a legenda com “💬 Comente QUERO para receber o link”…"></textarea></label>
      <p class="muted small"><span id="contador">0</span>/2200 caracteres</p>
      <label><input type="checkbox" id="share_to_feed" checked> mostrar também no feed do perfil</label>
      <p><button type="button" class="btn btn-primary" id="publicar" disabled>📤 Publicar no Instagram</button></p>
      <div class="rl-status" id="status">Aguardando a gravação do vídeo.</div>
    </div>
  </div>
</div>

<div class="card">
  <h2>Reels publicados por aqui</h2>
  <table class="tbl small">
    <tr><th>#</th><th>Quando</th><th>Produto</th><th>Status</th><th></th></tr>
    <?php foreach ($reels as $r): ?>
      <tr>
        <td><?= (int)$r['id'] ?></td>
        <td><?= e(date('d/m H:i', strtotime((string)$r['created_at']))) ?></td>
        <td><?= e($r['p_title'] ? '#' . $r['product_id'] . ' ' . mb_strimwidth((string)$r['p_title'], 0, 60, '…') : ($r['title'] ?: '—')) ?></td>
        <td><b><?= e($r['status']) ?></b><?php if ($r['error']): ?><div class="muted"><?= e($r['error']) ?></div><?php endif; ?></td>
        <td>
          <?php if ($r['permalink']): ?><a href="<?= e($r['permalink']) ?>" target="_blank" rel="noopener">ver no Instagram ↗</a><?php endif; ?>
          <?php if ($r['status'] === 'processando'): ?><button type="button" class="btn btn-sm" data-status="<?= (int)$r['id'] ?>">Verificar</button><?php endif; ?>
          <?php if ($r['status'] === 'erro' && is_file(Instagram::reelsDir() . '/' . $r['video'])): ?>
            <button type="button" class="btn btn-sm" data-tentar="<?= (int)$r['id'] ?>">Tentar de novo</button><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$reels): ?><tr><td colspan="5" class="muted">Nenhum Reels ainda.</td></tr><?php endif; ?>
  </table>
</div>

<script src="<?= e(asset('assets/reels.js')) ?>"></script>
<script>
(() => {
  const CSRF = <?= json_encode(csrf_token()) ?>;
  const $ = (s) => document.querySelector(s);
  // Estado para o agente acompanhar: window.__reels.etapa
  const R = window.__reels = { etapa: 'sem-dados' };

  async function api(action, campos = {}, arquivo = null) {
    const fd = new FormData();
    fd.append('ajax', '1'); fd.append('_csrf', CSRF); fd.append('action', action);
    for (const [k, v] of Object.entries(campos)) fd.append(k, v);
    if (arquivo) fd.append('parte', arquivo, 'parte.bin');
    const r = await fetch(location.pathname, { method: 'POST', body: fd, credentials: 'same-origin' });
    const j = await r.json().catch(() => ({ erro: 'Resposta inválida do servidor (' + r.status + ').' }));
    if (j.erro) throw new Error(j.erro);
    return j;
  }
  function status(msg, tipo = '') {
    const el = $('#status');
    el.textContent = msg; el.className = 'rl-status ' + tipo;
    R.mensagem = msg;
  }

  $('#testar')?.addEventListener('click', async () => {
    $('#limite').textContent = 'testando…';
    try {
      const j = await api('limite');
      $('#limite').textContent = `✅ permissão ok · ${j.limite.usados}/${j.limite.limite} publicações nas últimas 24 h`;
    } catch (e) { $('#limite').textContent = '❌ ' + e.message; }
  });
  document.querySelectorAll('[data-status],[data-tentar]').forEach((b) => b.addEventListener('click', async () => {
    b.disabled = true;
    try {
      const j = b.dataset.status ? await api('status', { id: b.dataset.status }) : await api('tentar', { id: b.dataset.tentar });
      alert('Status: ' + j.reels.status + (j.reels.erro ? '\n' + j.reels.erro : ''));
      location.reload();
    } catch (e) { alert(e.message); b.disabled = false; }
  }));

  // ---------- dados vindos do agente (window.name), guardados na aba ----------
  let dados = null;
  try {
    if (window.name && window.name.startsWith('{"tipo":"achadosmeli-reels"')) {
      sessionStorage.setItem('achadosmeli-reels', window.name);
      window.name = '';
    }
    const salvo = sessionStorage.getItem('achadosmeli-reels');
    if (salvo) dados = JSON.parse(salvo).dados;
  } catch (e) { dados = null; }
  if (!dados) return;

  $('#vazio').hidden = true;
  $('#novo').hidden = false;
  R.etapa = 'aguardando-gravacao';
  R.dados = { titulo: dados.titulo, preco: dados.preco, original: dados.original, desconto: dados.desconto,
    parcelas: dados.parcelas, nota: dados.nota, avals: dados.avals, vendidos: dados.vendidos, temVideo: !!dados.video,
    destaques: dados.destaques || [], descricao: dados.descricao || '' };
  $('#formato').value = dados.formato === 'feed' ? 'feed' : 'reels';
  $('#baixar_fotos').checked = !!dados.fotos;
  $('#baixar_mp4').checked = !!dados.baixarMp4;
  $('#prod').innerHTML = '';
  const img = document.createElement('img'); img.src = (dados.imagens?.[0]?.[0]) || ''; img.alt = '';
  const txt = document.createElement('div');
  txt.innerHTML = '<b></b><div class="muted small"></div>';
  txt.querySelector('b').textContent = dados.titulo;
  txt.querySelector('div').textContent = `${dados.preco}${dados.original ? ' · de ' + dados.original : ''}${dados.desconto ? ' · ' + dados.desconto : ''} · ${dados.ml_ids.join(' / ')}`;
  $('#prod').append(img, txt);

  const atualizaBotao = () => {
    $('#contador').textContent = $('#legenda').value.length;
    $('#publicar').disabled = !(R.video || R.blob) || !$('#legenda').value.trim() || ['enviando', 'processando', 'publicado'].includes(R.etapa);
  };
  $('#legenda').addEventListener('input', atualizaBotao);
  R.setLegenda = (t) => { $('#legenda').value = String(t); atualizaBotao(); return $('#legenda').value.length; };

  api('produto', { ml_ids: dados.ml_ids.join(',') }).then((j) => {
    R.produto = j.produto;
    if (j.produto) {
      $('#produto_id').value = j.produto.id;
      const aviso = document.createElement('div');
      aviso.className = 'muted small';
      aviso.textContent = `Produto #${j.produto.id} no site` + (j.produto.affiliate_url ? ' · com link de afiliado' : ' · ⚠️ sem link de afiliado (o direct vai com o link normal)');
      $('#prod').lastChild.append(aviso);
    } else {
      status('⚠️ Este produto ainda não está no site: o Reels publica, mas a resposta automática do “quero” só funciona com o ID de um produto do site.', 'err');
    }
  }).catch(() => {});

  if (!dados.video) {
    R.etapa = 'sem-video';
    status('Este anúncio não tem vídeo. Não há Reels para publicar.', 'err');
    $('#gravar').disabled = true;
  }

  // ---------- gravar ----------
  $('#gravar').addEventListener('click', async () => {
    $('#gravar').disabled = true;
    R.etapa = 'gravando';
    try {
      if ($('#baixar_fotos').checked && dados.imagens?.length) {
        status('baixando as fotos…');
        const z = await AchadosReels.fotosZip(dados.imagens, dados.slug);
        if (z.total) AchadosReels.baixar(z.blob, dados.slug + '-imagens.zip');
        R.fotos = z.total;
      }
      const g = await AchadosReels.gravar(dados, { formato: $('#formato').value, aoMudar: (m) => status(m + '…') });
      R.blob = g.blob; R.ext = g.ext; R.info = g.info;
      $('#previa').src = URL.createObjectURL(g.blob);
      $('#info').textContent = `${g.info.resolucao} · ${g.info.duracao} s · ${(g.info.tamanho / 1048576).toFixed(1)} MB · ${g.info.audio ? 'com áudio' : 'sem áudio'}`;
      if ($('#baixar_mp4').checked) AchadosReels.baixar(g.blob, dados.slug + '.' + g.ext);
      if (g.ext !== 'mp4') throw new Error('Este navegador gravou em WEBM; o Instagram precisa de MP4. Use o Chrome atualizado.');
      R.etapa = 'gravado';
      status('Vídeo pronto' + (g.info.audio ? '' : ' (sem áudio: a gravação não começou com um clique)') + '. Revise a legenda e publique.', 'ok');
    } catch (e) {
      R.etapa = 'erro'; R.erro = e.message;
      status('Erro na gravação: ' + e.message, 'err');
      $('#gravar').disabled = false;
    }
    atualizaBotao();
  });

  // ---------- publicar ----------
  $('#publicar').addEventListener('click', async () => {
    if (!R.blob && !R.video) return;
    R.etapa = 'enviando'; atualizaBotao();
    try {
      if (!R.video) {
        const uid = Array.from(crypto.getRandomValues(new Uint8Array(12)), (b) => b.toString(16).padStart(2, '0')).join('');
        const PARTE = 1536 * 1024, total = Math.ceil(R.blob.size / PARTE);
        for (let i = 0; i < total; i++) {
          status(`enviando o vídeo para o site… ${Math.round((i / total) * 100)}%`);
          const j = await api('chunk', { upload_id: uid, index: i, total, slug: dados.slug }, R.blob.slice(i * PARTE, (i + 1) * PARTE));
          if (j.video) { R.video = j.video; R.videoUrl = j.url; }
        }
      }
      status('enviando para o Instagram…');
      let j = await api('publicar', { video: R.video, legenda: $('#legenda').value, produto_id: $('#produto_id').value,
        ml_id: dados.ml_ids[0] || '', titulo: dados.titulo, share_to_feed: $('#share_to_feed').checked ? '1' : '' });
      R.reels = j.reels; R.etapa = 'processando';
      for (let n = 0; n < 60 && j.reels.status === 'processando'; n++) {
        status(`o Instagram está processando o vídeo… (${(n + 1) * 5} s)`);
        await new Promise((r) => setTimeout(r, 5000));
        j = await api('status', { id: j.reels.id });
        R.reels = j.reels;
      }
      if (j.reels.status === 'publicado') {
        R.etapa = 'publicado';
        sessionStorage.removeItem('achadosmeli-reels');
        const el = $('#status');
        status('✅ Publicado! ', 'ok');
        if (j.reels.permalink) {
          const a = document.createElement('a'); a.href = j.reels.permalink; a.target = '_blank'; a.textContent = 'Ver no Instagram ↗';
          el.append(a);
        }
        if (j.reels.produto) el.append(' · respostas do “quero” ligadas ao produto #' + j.reels.produto + '.');
      } else if (j.reels.status === 'processando') {
        status('Ainda processando. O cron do Instagram termina a publicação sozinho; acompanhe na tabela abaixo.', 'ok');
      } else {
        throw new Error(j.reels.erro || j.reels.status);
      }
    } catch (e) {
      R.etapa = 'erro'; R.erro = e.message;
      status('Erro ao publicar: ' + e.message, 'err');
    }
    atualizaBotao();
  });
})();
</script>
<?php admin_footer();
