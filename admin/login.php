<?php
/**
 * Entrada do painel: login com Google.
 * Esta mesma URL é o "URI de redirecionamento" cadastrado no Google Cloud (veja src/google_login.php).
 */
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/admin_layout.php';
require __DIR__ . '/../src/google_login.php';

if (is_admin()) redirect(url('admin/'));

$error = null;
$ready = google_configured();

if (isset($_GET['error'])) {
    $error = $_GET['error'] === 'access_denied' ? 'Login cancelado.' : 'O Google não concluiu o login. Tente de novo.';
} elseif (isset($_GET['code'])) {
    try {
        $u = google_finish_login((string)$_GET['code'], (string)($_GET['state'] ?? ''));
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        $_SESSION['admin_email'] = $u['email'];
        $_SESSION['admin_name'] = $u['name'];
        $_SESSION['admin_picture'] = $u['picture'];
        $next = (string)($_SESSION['admin_next'] ?? '');
        unset($_SESSION['admin_next']);
        redirect(str_starts_with($next, '/') && !str_starts_with($next, '//') ? $next : url('admin/'));
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
} elseif (isset($_GET['entrar']) && $ready) {
    redirect(google_auth_url());
}

admin_header('Entrar', '', false);
?>
<section class="panel login-box">
  <img class="login-ico" src="<?= e(url('assets/favicon.svg')) ?>" alt="" width="64" height="64">
  <h1>Entrar no painel</h1>
  <p class="muted">Acesso restrito à administração do site.</p>
  <?php if ($error): ?><div class="alert alert-err"><?= e($error) ?></div><?php endif; ?>
  <?php if ($ready): ?>
    <a class="btn-google" href="<?= e(url('admin/login.php?entrar=1')) ?>">
      <svg viewBox="0 0 48 48" aria-hidden="true"><path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.4-.4-3.5z"/><path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/><path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-8l-6.5 5C9.5 39.6 16.2 44 24 44z"/><path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.4-.4-3.5z"/></svg>
      Entrar com Google
    </a>
  <?php else: ?>
    <div class="alert alert-warn">O login com Google ainda não está configurado. Preencha <code>GOOGLE_CLIENT_ID</code>, <code>GOOGLE_CLIENT_SECRET</code> e <code>BRAIN_EMAILS</code> no <code>.env</code>.</div>
  <?php endif; ?>
</section>
<?php admin_footer();
