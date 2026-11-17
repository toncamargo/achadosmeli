<?php
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/admin_layout.php';
require_admin();
csrf_check();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'app') {
            foreach (['ig_app_secret', 'fb_app_secret'] as $k) {
                if (trim((string)($_POST[$k] ?? '')) !== '') setting_set($k, trim((string)$_POST[$k]));
            }
            flash('Chaves salvas.');
        } elseif ($action === 'token') {
            foreach (Instagram::saveToken((string)$_POST['token'], (string)($_POST['ig_id'] ?? '')) as $m) flash($m, str_starts_with($m, 'Aviso') ? 'warn' : 'ok');
        } elseif ($action === 'refresh') {
            flash(Instagram::refreshToken(true) ?? 'Nada a renovar.');
        } elseif ($action === 'disconnect') {
            foreach (['ig_access_token', 'ig_user_id', 'ig_username', 'ig_token_expires_at', 'ig_page_id', 'ig_api_host'] as $k) setting_set($k, null);
            flash('Instagram desconectado.');
        } elseif ($action === 'texts') {
            setting_set('ig_public_replies', trim((string)$_POST['ig_public_replies']));
            setting_set('ig_dm_single', trim((string)$_POST['ig_dm_single']));
            setting_set('ig_dm_list', trim((string)$_POST['ig_dm_list']));
            flash('Textos salvos.');
        } elseif ($action === 'post') {
            $ids = array_values(array_filter(array_map('intval', preg_split('/[\s,;]+/', (string)$_POST['products']))));
            Instagram::savePost((string)$_POST['media_id'], $ids, (string)$_POST['keyword'], isset($_POST['active']),
                (string)($_POST['caption'] ?? ''), (string)($_POST['permalink'] ?? ''));
            flash($ids ? 'Post salvo com ' . count($ids) . ' produto(s).' : 'Post desligado.');
        } elseif ($action === 'poll') {
            $r = Instagram::poll(10);
            flash("Verificados {$r['posts']} post(s). " . ($r['log'] ? implode(' · ', array_slice($r['log'], 0, 10)) : 'Nenhum comentário novo.'));
        }
    } catch (Throwable $ex) {
        flash('Erro: ' . $ex->getMessage(), 'err');
    }
    redirect(url('admin/instagram.php') . (($_POST['action'] ?? '') === 'post' ? '#posts' : ''));
}

$connected = Instagram::isConnected();
$media = [];
$mediaErr = null;
if ($connected) {
    try { $media = Instagram::media(24); } catch (Throwable $ex) { $mediaErr = $ex->getMessage(); }
}
$mapped = [];
foreach (db()->query('SELECT * FROM ig_posts') as $r) $mapped[$r['media_id']] = $r + ['products' => []];
foreach (db()->query('SELECT media_id, product_id FROM ig_post_products ORDER BY code') as $r) {
    if (isset($mapped[$r['media_id']])) $mapped[$r['media_id']]['products'][] = $r['product_id'];
}
$products = db()->query('SELECT id, title, price, image FROM products WHERE active = 1 ORDER BY id DESC')->fetchAll();
$log = db()->query('SELECT * FROM ig_comment_log ORDER BY created_at DESC LIMIT 50')->fetchAll();
$exp = (int)setting('ig_token_expires_at', '0');

admin_header('Instagram', 'instagram');
?>
<style>
.ig-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:14px}
.ig-post{border:1px solid #e5e5e5;border-radius:10px;overflow:hidden;background:#fff;display:flex;flex-direction:column}
.ig-post.on{border:2px solid #2e7d32}
.ig-post img{width:100%;aspect-ratio:1;object-fit:cover;background:#eee;display:block}
.ig-post .b{padding:10px;display:flex;flex-direction:column;gap:6px;font-size:13px}
.ig-post label{font-size:12px}
pre.ig{white-space:pre-wrap;margin:0}
</style>

<div class="grid-2">
<div class="card">
  <h2>1. App da Meta</h2>
  <ol class="steps">
    <li>No <a href="https://developers.facebook.com/apps" target="_blank" rel="noopener">painel da Meta</a>, abra o app ›
      <b>Casos de uso</b> › “Gerenciar mensagens e conteúdo no Instagram” › <b>Personalizar</b>.</li>
    <li>Em <b>Webhooks</b>, use:<br>
      Callback URL: <code id="igurl"><?= e(Instagram::webhookUrl()) ?></code>
      <button type="button" class="btn btn-sm" data-copy="#igurl">Copiar</button><br>
      Verify token: <code id="igvt"><?= e(Instagram::verifyToken()) ?></code>
      <button type="button" class="btn btn-sm" data-copy="#igvt">Copiar</button><br>
      e assine o campo <code>comments</code>.</li>
    <li>Cole abaixo as chaves usadas para conferir a assinatura do webhook (basta a que o seu app usa; na dúvida, as duas).</li>
  </ol>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="app">
    <label>App secret do app <small class="muted">(Configurações do app › Básico › Chave secreta do aplicativo)</small>
      <input name="fb_app_secret" type="password" placeholder="<?= setting('fb_app_secret') ? '•••••••• (salvo — deixe vazio para manter)' : '' ?>"></label>
    <label>Instagram app secret <small class="muted">(se o app tiver “API setup with Instagram login”)</small>
      <input name="ig_app_secret" type="password" placeholder="<?= setting('ig_app_secret') ? '•••••••• (salvo — deixe vazio para manter)' : '' ?>"></label>
    <button class="btn">Salvar</button>
  </form>
  <p class="muted small">O webhook só recebe comentários com o app em modo <b>Live</b> e Acesso Avançado (análise da Meta).
    Enquanto isso, o cron <code>cron/instagram.php</code> a cada 5 min faz o mesmo trabalho.</p>
</div>

<div class="card">
  <h2>2. Conta do Instagram</h2>
  <?php if ($connected): ?>
    <p class="ok-text">✅ Conectado como <b>@<?= e(setting('ig_username')) ?></b>
      <?= $exp ? '· token válido até ' . date('d/m/Y', $exp) . ' (renovado pelo cron)' : '· token sem vencimento' ?>
      <span class="muted small">(<?= e(Instagram::host() === 'facebook' ? 'graph.facebook.com' : 'graph.instagram.com') ?>)</span></p>
    <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="poll">
      <button class="btn btn-sm btn-primary">Verificar comentários agora</button></form>
    <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="refresh">
      <button class="btn btn-sm">Renovar token</button></form>
    <form method="post" style="display:inline" data-confirm="Desconectar o Instagram?"><?= csrf_field() ?><input type="hidden" name="action" value="disconnect">
      <button class="btn btn-sm">Desconectar</button></form>
    <hr>
  <?php endif; ?>
  <p class="muted">No app da Meta: <b>Casos de uso</b> › “Gerenciar mensagens e conteúdo no Instagram” › <b>Personalizar</b> ›
    <b>2. Gerar tokens de acesso</b> › <b>Gerar token</b> na linha da sua conta. Copie o token e cole aqui
    (aceita token <code>IGAA…</code> ou <code>EAA…</code>).</p>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="token">
    <label>Access token <input name="token" required placeholder="IGAA… ou EAA…"></label>
    <label>ID da conta do Instagram <small class="muted">(número abaixo do nome da conta, em “Gerar tokens de acesso”)</small>
      <input name="ig_id" inputmode="numeric" value="<?= e(setting('ig_user_id')) ?>" placeholder="17841400…"></label>
    <button class="btn btn-primary"><?= $connected ? 'Trocar token' : 'Conectar' ?></button>
  </form>
</div>
</div>

<div class="card" id="posts">
  <h2>3. Posts → produtos</h2>
  <p class="muted">Digite os IDs dos produtos na ordem (ex.: <code>45</code> ou <code>45, 46, 47</code>) e salve.
    Com vários produtos, quem comentar “quero 2” recebe o 2º; só “quero” recebe a lista.
    Apague os IDs e salve para desligar o post. Só são respondidos comentários feitos depois do cadastro.</p>
  <details><summary>Lista de produtos e IDs (<?= count($products) ?>)</summary>
    <table class="tbl small">
      <?php foreach ($products as $p): ?>
        <tr><td><b><?= (int)$p['id'] ?></b></td><td><img class="thumb" src="<?= e($p['image']) ?>" alt="" loading="lazy"></td>
          <td><?= e($p['title']) ?></td><td><?= e(money((float)$p['price'])) ?></td></tr>
      <?php endforeach; ?>
    </table>
  </details>
  <br>
  <?php if (!$connected): ?>
    <p class="muted">Conecte a conta para listar os posts.</p>
  <?php elseif ($mediaErr): ?>
    <div class="alert alert-err">Erro ao listar posts: <?= e($mediaErr) ?></div>
  <?php endif; ?>
  <div class="ig-grid">
  <?php foreach ($media as $m): $mp = $mapped[$m['id']] ?? null; ?>
    <form method="post" class="ig-post <?= $mp && $mp['active'] ? 'on' : '' ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="post">
      <input type="hidden" name="media_id" value="<?= e($m['id']) ?>">
      <input type="hidden" name="caption" value="<?= e(mb_substr((string)($m['caption'] ?? ''), 0, 500)) ?>">
      <input type="hidden" name="permalink" value="<?= e($m['permalink'] ?? '') ?>">
      <img src="<?= e($m['thumbnail_url'] ?? $m['media_url'] ?? '') ?>" alt="" loading="lazy">
      <div class="b">
        <a class="muted small" href="<?= e($m['permalink'] ?? '#') ?>" target="_blank" rel="noopener">
          <?= e(date('d/m/Y', strtotime((string)$m['timestamp']))) ?> · <?= e($m['media_type']) ?> ↗</a>
        <div><?= e(mb_strimwidth((string)($m['caption'] ?? ''), 0, 90, '…')) ?></div>
        <label>IDs dos produtos <input name="products" value="<?= e(implode(', ', $mp['products'] ?? [])) ?>"></label>
        <label>Palavra-chave <input name="keyword" value="<?= e($mp['keyword'] ?? Instagram::DEFAULT_KEYWORD) ?>"></label>
        <label><input type="checkbox" name="active" <?= !$mp || $mp['active'] ? 'checked' : '' ?>> ativo</label>
        <button class="btn btn-sm btn-primary">Salvar</button>
      </div>
    </form>
  <?php endforeach; ?>
  </div>
</div>

<form method="post" class="card">
  <?= csrf_field() ?><input type="hidden" name="action" value="texts">
  <h2>4. Textos</h2>
  <p class="muted small">Variáveis: <code>{username}</code>, <code>{title}</code>, <code>{price}</code>, <code>{link}</code>.</p>
  <label>Respostas públicas no comentário (uma por linha, sorteia uma)
    <textarea name="ig_public_replies" rows="4"><?= e(setting('ig_public_replies', Instagram::DEFAULT_PUBLIC_REPLIES)) ?></textarea></label>
  <label>Direct com 1 produto
    <textarea name="ig_dm_single" rows="6"><?= e(setting('ig_dm_single', Instagram::DEFAULT_DM_SINGLE)) ?></textarea></label>
  <label>Início do direct com a lista de produtos
    <textarea name="ig_dm_list" rows="2"><?= e(setting('ig_dm_list', Instagram::DEFAULT_DM_LIST)) ?></textarea></label>
  <button class="btn">Salvar textos</button>
</form>

<div class="card">
  <h2>Últimos comentários</h2>
  <table class="tbl small">
    <tr><th>Quando</th><th>Usuário</th><th>Comentário</th><th>Produto</th><th>Status</th><th>Origem</th></tr>
    <?php foreach ($log as $l): ?>
      <tr><td><?= e(date('d/m H:i', strtotime((string)$l['created_at']))) ?></td>
        <td>@<?= e($l['username']) ?></td><td><?= e($l['text']) ?></td><td><?= e((string)$l['product_id']) ?></td>
        <td><?= e($l['status']) ?><?php if ($l['error']): ?><div class="muted small"><?= e($l['error']) ?></div><?php endif; ?></td>
        <td><?= e($l['source']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$log): ?><tr><td colspan="6" class="muted">Nenhum comentário processado ainda.</td></tr><?php endif; ?>
  </table>
</div>
<?php admin_footer();
