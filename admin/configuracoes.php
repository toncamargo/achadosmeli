<?php
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/admin_layout.php';
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
    } elseif ($action === 'aff') {
        setting_set('aff_matt_word', trim((string)$_POST['aff_matt_word']));
        setting_set('aff_matt_tool', trim((string)$_POST['aff_matt_tool']));
        flash('Parâmetros de afiliado salvos.');
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
    <form method="post" data-confirm="Desconectar a conta?">
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
  <p class="muted">Opcional: se seus links gerados trazem os parâmetros <code>matt_word</code> e <code>matt_tool</code>,
    informe-os aqui para que produtos <b>sem link colado</b> recebam esses parâmetros automaticamente.
    Confira com um link real do seu painel antes de confiar nisso.</p>
  <label>matt_word <input name="aff_matt_word" value="<?= e(setting('aff_matt_word')) ?>"></label>
  <label>matt_tool <input name="aff_matt_tool" value="<?= e(setting('aff_matt_tool')) ?>"></label>
  <button class="btn">Salvar</button>
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
