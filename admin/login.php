<?php
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/admin_layout.php';
csrf_check();

$firstRun = !admin_has_password();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pass = (string)($_POST['password'] ?? '');
    if ($firstRun) {
        if (mb_strlen($pass) < 8) {
            $error = 'A senha precisa ter pelo menos 8 caracteres.';
        } elseif ($pass !== ($_POST['password2'] ?? '')) {
            $error = 'As senhas não conferem.';
        } else {
            setting_set('admin_password_hash', password_hash($pass, PASSWORD_DEFAULT));
            session_regenerate_id(true);
            $_SESSION['admin'] = true;
            flash('Senha criada. Agora configure o app do Mercado Livre.');
            redirect(url('admin/configuracoes.php'));
        }
    } else {
        // atraso simples contra força bruta
        usleep(400000);
        if (password_verify($pass, (string)setting('admin_password_hash'))) {
            session_regenerate_id(true);
            $_SESSION['admin'] = true;
            redirect(url('admin/'));
        }
        $error = 'Senha incorreta.';
    }
}

admin_header($firstRun ? 'Criar senha do painel' : 'Entrar');
?>
<form method="post" class="card narrow">
  <?= csrf_field() ?>
  <?php if ($error): ?><div class="alert alert-err"><?= e($error) ?></div><?php endif; ?>
  <?php if ($firstRun): ?>
    <p>Primeiro acesso: defina a senha de administrador.</p>
  <?php endif; ?>
  <label>Senha <input type="password" name="password" required autofocus></label>
  <?php if ($firstRun): ?>
    <label>Repita a senha <input type="password" name="password2" required></label>
  <?php endif; ?>
  <button class="btn btn-primary"><?= $firstRun ? 'Criar senha' : 'Entrar' ?></button>
</form>
<?php admin_footer();
