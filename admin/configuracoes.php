<?php
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/admin_layout.php';
require_once __DIR__ . '/../src/ads.php';
require_admin();
csrf_check();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'site') {
        foreach (['site_name', 'site_tagline', 'whatsapp_group', 'telegram_channel', 'instagram'] as $k) {
            setting_set($k, trim((string)($_POST[$k] ?? '')));
        }
        flash('Dados do site salvos.');
    } elseif ($action === 'ml') {
        setting_set('ml_client_id', trim((string)$_POST['ml_client_id']));
        if (trim((string)$_POST['ml_client_secret']) !== '') {
            setting_set('ml_client_secret', trim((string)$_POST['ml_client_secret']));
        }
        flash('Credenciais do app salvas.');
    } elseif ($action === 'connect') {
        redirect(MercadoLivre::authorizeUrl());
    } elseif ($action === 'disconnect') {
        MercadoLivre::disconnect();
        flash('Conta desconectada.');
    } elseif ($action === 'test') {
        try {
            $me = MercadoLivre::me();
            flash('Conexão OK: conta ' . ($me['nickname'] ?? $me['id'] ?? '?') . '.');
            try {
                $r = MercadoLivre::search('fone bluetooth', 0, 1);
                flash($r['mode'] === 'items'
                    ? 'Busca de anúncios liberada para o seu app (busca completa, com preço).'
                    : 'Busca de anúncios bloqueada pelo ML para o seu app: a busca usa o catálogo. Use "Mais vendidos" na Coleta automática para os melhores resultados.',
                    $r['mode'] === 'items' ? 'ok' : 'warn');
            } catch (Throwable $ex) {
                flash('Busca falhou: ' . $ex->getMessage(), 'err');
            }
            try {
                $h = MercadoLivre::highlights('MLB1574'); // Casa, Móveis e Decoração
                flash('Mais vendidos liberado (' . count($h) . ' produtos em Casa, Móveis e Decoração).');
            } catch (Throwable $ex) {
                flash('Mais vendidos falhou: ' . $ex->getMessage(), 'err');
            }
        } catch (Throwable $ex) {
            flash('Falha na conexão: ' . $ex->getMessage(), 'err');
        }
    } elseif ($action === 'aff') {
        $link = trim((string)($_POST['aff_link'] ?? ''));
        $word = trim((string)$_POST['aff_matt_word']);
        $tool = trim((string)$_POST['aff_matt_tool']);
        if ($link !== '') {
            $found = MercadoLivre::affiliateParams($link);
            if ($found['matt_word'] || $found['matt_tool']) {
                $word = $found['matt_word'] ?? $word;
                $tool = $found['matt_tool'] ?? $tool;
                flash('Parâmetros lidos do seu link: matt_word=' . ($found['matt_word'] ?? '—') . ', matt_tool=' . ($found['matt_tool'] ?? '—') . '.',
                    $found['matt_word'] && $found['matt_tool'] ? 'ok' : 'warn');
            } else {
                flash('Não encontrei matt_word/matt_tool nesse link. Abra o link no navegador, copie a URL final '
                    . '(a página do produto, com os parâmetros) e cole aqui.', 'err');
            }
        }
        setting_set('aff_matt_word', $word);
        setting_set('aff_matt_tool', $tool);
        flash('Parâmetros de afiliado salvos.');
    } elseif ($action === 'adsense') {
        $client = trim((string)($_POST['adsense_client'] ?? ''));
        if (preg_match('/^pub-\d+$/', $client)) $client = 'ca-' . $client;
        if ($client !== '' && !preg_match('/^ca-pub-\d{10,20}$/', $client)) {
            flash('ID de editor inválido. Use o formato ca-pub-1234567890123456.', 'err');
            redirect(url('admin/configuracoes.php#adsense'));
        }
        setting_set('adsense_client', $client);
        foreach (array_keys(AD_SLOTS) as $k) {
            setting_set($k, preg_replace('/\D/', '', (string)($_POST[$k] ?? '')));
        }
        setting_set('ads_trio_title', trim((string)($_POST['ads_trio_title'] ?? '')));
        flash('Anúncios do Google salvos.');
    } elseif ($action === 'password') {
        $p = (string)$_POST['new_password'];
        if (!password_verify((string)$_POST['current_password'], (string)setting('admin_password_hash'))) {
            flash('Senha atual incorreta.', 'err');
        } elseif (mb_strlen($p) < 8) {
            flash('A nova senha precisa ter 8+ caracteres.', 'err');
        } else {
            setting_set('admin_password_hash', password_hash($p, PASSWORD_DEFAULT));
            flash('Senha alterada.');
        }
    }
    redirect(url('admin/configuracoes.php'));
}

admin_header('Configurações', 'config');
?>
<div class="grid-2">
<form method="post" class="card">
  <?= csrf_field() ?><input type="hidden" name="action" value="site">
  <h2>Site</h2>
  <label>Nome do site <input name="site_name" value="<?= e(setting('site_name', 'Achados Meli')) ?>"></label>
  <label>Frase de destaque <input name="site_tagline" value="<?= e(setting('site_tagline', 'Os melhores achados do Mercado Livre, todo dia.')) ?>"></label>
  <label>Link do grupo de WhatsApp (opcional) <input name="whatsapp_group" value="<?= e(setting('whatsapp_group')) ?>" placeholder="https://chat.whatsapp.com/..."></label>
  <label>Canal do Telegram (opcional) <input name="telegram_channel" value="<?= e(setting('telegram_channel')) ?>" placeholder="https://t.me/..."></label>
  <label>Instagram (opcional) <input name="instagram" value="<?= e(setting('instagram')) ?>" placeholder="https://instagram.com/..."></label>
  <button class="btn btn-primary">Salvar</button>
</form>

<div class="card">
  <h2>API do Mercado Livre</h2>
  <ol class="steps">
    <li>Crie um app grátis em <a href="https://developers.mercadolivre.com.br/devcenter" target="_blank" rel="noopener">developers.mercadolivre.com.br</a>.</li>
    <li>Em <b>Redirect URI</b>, cadastre exatamente:<br><code id="redir"><?= e(MercadoLivre::redirectUri()) ?></code>
      <button type="button" class="btn btn-sm" data-copy="#redir">Copiar</button></li>
    <li>Cole o <b>App ID</b> e a <b>Secret Key</b> abaixo e clique em Conectar.</li>
  </ol>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="ml">
    <label>App ID (client_id) <input name="ml_client_id" value="<?= e(setting('ml_client_id')) ?>"></label>
    <label>Secret Key (client_secret)
      <input name="ml_client_secret" type="password" placeholder="<?= setting('ml_client_secret') ? '•••••••• (salvo — deixe vazio para manter)' : '' ?>"></label>
    <button class="btn">Salvar credenciais</button>
  </form>
  <hr>
  <?php if (MercadoLivre::isConnected()): ?>
    <p class="ok-text">✅ Conectado (usuário ML <?= e(setting('ml_user_id')) ?>)</p>
    <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="test">
      <button class="btn btn-sm btn-primary">Testar conexão</button></form>
    <form method="post" data-confirm="Desconectar a conta?" style="display:inline">
      <?= csrf_field() ?><input type="hidden" name="action" value="disconnect">
      <button class="btn btn-sm">Desconectar</button>
    </form>
  <?php elseif (MercadoLivre::isConfigured()): ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="connect">
      <button class="btn btn-primary">Conectar conta Mercado Livre</button></form>
  <?php else: ?>
    <p class="muted">Salve as credenciais para liberar o botão de conectar.</p>
  <?php endif; ?>
</div>

<form method="post" class="card">
  <?= csrf_field() ?><input type="hidden" name="action" value="aff">
  <h2>Link de afiliado</h2>
  <p class="muted">O jeito mais seguro é gerar o link de cada produto no
    <a href="https://www.mercadolivre.com.br/afiliados" target="_blank" rel="noopener">painel de afiliados</a>
    (link <code>meli.la/...</code>) e colar na tela de edição do produto.</p>
  <p class="muted"><b>Link automático (recomendado para a coleta):</b> gere um link de afiliado de qualquer produto e cole abaixo.
    O site lê os parâmetros <code>matt_word</code> e <code>matt_tool</code> dele e aplica em todos os produtos
    <b>sem link colado</b> — inclusive os que a coleta automática traz.
    Depois, teste: clique em "Comprar" no site em uma aba anônima e confira se a URL do ML chega com esses parâmetros.</p>
  <label>Seu link de afiliado (meli.la/... ou a URL completa)
    <input name="aff_link" placeholder="https://meli.la/xxxxxxx"></label>
  <p class="muted small">Ou preencha manualmente:</p>
  <label>matt_word <input name="aff_matt_word" value="<?= e(setting('aff_matt_word')) ?>"></label>
  <label>matt_tool <input name="aff_matt_tool" value="<?= e(setting('aff_matt_tool')) ?>"></label>
  <button class="btn">Salvar</button>
</form>

<form method="post" class="card" id="adsense">
  <?= csrf_field() ?><input type="hidden" name="action" value="adsense">
  <h2>Google AdSense (banners da home)</h2>
  <p class="muted">Cole o ID de editor e o <b>ID de cada bloco de anúncio</b> (em AdSense › Anúncios › Por bloco de anúncios › Anúncio display).
    Espaço sem ID não aparece para o visitante; logado no admin, você vê onde ele fica.</p>
  <label>ID de editor <input name="adsense_client" value="<?= e(setting('adsense_client')) ?>" placeholder="ca-pub-1234567890123456"></label>
  <?php foreach (AD_SLOTS as $k => [$name, $size]): ?>
    <label><?= e($name) ?> <small class="muted">(<?= e($size) ?>)</small>
      <input name="<?= $k ?>" value="<?= e(setting($k)) ?>" placeholder="ID do bloco, ex.: 1234567890" inputmode="numeric"></label>
  <?php endforeach; ?>
  <label>Título do painel de 3 cards <input name="ads_trio_title" value="<?= e(setting('ads_trio_title')) ?>" placeholder="Recomendados para você"></label>
  <p class="muted small">O arquivo <code><?= e(url('ads.txt')) ?></code> é gerado sozinho com o seu ID — o AdSense exige ele para liberar os anúncios.</p>
  <button class="btn btn-primary">Salvar anúncios</button>
</form>

<form method="post" class="card">
  <?= csrf_field() ?><input type="hidden" name="action" value="password">
  <h2>Trocar senha</h2>
  <label>Senha atual <input type="password" name="current_password" required></label>
  <label>Nova senha <input type="password" name="new_password" required></label>
  <button class="btn">Alterar</button>
</form>
</div>
<?php admin_footer();
