<?php
declare(strict_types=1);

function admin_header(string $title, string $active = ''): void
{
    $site = setting('site_name', 'Achados Meli');
    $nav = [
        'produtos' => ['index.php', 'Produtos'],
        'buscar' => ['buscar.php', 'Buscar no ML'],
        'coleta' => ['coleta.php', 'Coleta automática'],
        'categorias' => ['categorias.php', 'Categorias'],
        'banners' => ['banners.php', 'Banners'],
        'config' => ['configuracoes.php', 'Configurações'],
    ];
    ?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= e($title) ?> · Admin <?= e($site) ?></title>
<link rel="icon" href="<?= e(url('assets/favicon.svg')) ?>" type="image/svg+xml">
<link rel="icon" href="<?= e(url('assets/favicon.png')) ?>" type="image/png" sizes="64x64">
<link rel="apple-touch-icon" href="<?= e(url('assets/apple-touch-icon.png')) ?>">
<link rel="stylesheet" href="<?= e(asset('assets/style.css')) ?>">
</head>
<body class="admin">
<header class="adm-top">
  <a class="adm-brand" href="<?= e(url('admin/')) ?>"><?= e($site) ?> <small>admin</small></a>
  <?php if (is_admin()): ?>
  <nav>
    <?php foreach ($nav as $key => [$href, $label]): ?>
      <a href="<?= e(url('admin/' . $href)) ?>" class="<?= $active === $key ? 'on' : '' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
    <a href="<?= e(url('')) ?>" target="_blank">Ver site ↗</a>
    <a href="<?= e(url('admin/logout.php')) ?>">Sair</a>
  </nav>
  <?php endif; ?>
</header>
<main class="adm-main">
<?php foreach (flash() ?? [] as $f): ?>
  <div class="alert alert-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
<?php endforeach; ?>
<h1><?= e($title) ?></h1>
<?php
}

function admin_footer(): void
{
    ?>
</main>
<script>
document.querySelectorAll('[data-copy]').forEach(btn => btn.addEventListener('click', () => {
  const el = document.querySelector(btn.dataset.copy);
  navigator.clipboard.writeText(el.value ?? el.textContent).then(() => {
    const t = btn.textContent; btn.textContent = 'Copiado!'; setTimeout(() => btn.textContent = t, 1500);
  });
}));
document.querySelectorAll('[data-check-all]').forEach(cb => cb.addEventListener('change', () => {
  document.querySelectorAll(cb.dataset.checkAll).forEach(x => x.checked = cb.checked);
}));
document.querySelectorAll('form[data-confirm]').forEach(f => f.addEventListener('submit', ev => {
  if (!confirm(f.dataset.confirm)) ev.preventDefault();
}));
</script>
</body>
</html>
<?php
}

function category_select(string $name, ?int $selected, bool $allowEmpty = true): string
{
    $h = '<select name="' . e($name) . '">';
    if ($allowEmpty) $h .= '<option value="">— sem categoria —</option>';
    foreach (categories() as $c) {
        $sel = ((int)$c['id'] === $selected) ? ' selected' : '';
        $h .= '<option value="' . (int)$c['id'] . '"' . $sel . '>' . e($c['name']) . '</option>';
    }
    return $h . '</select>';
}
