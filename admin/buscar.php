<?php
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/admin_layout.php';
require_admin();
csrf_check();

/** Importa uma lista de refs ['id'=>..., 'catalog'=>bool]. Retorna [criados, atualizados, erros[]]. */
function import_refs(array $refs, ?int $categoryId): array
{
    $created = $updated = 0;
    $errors = [];
    $items = array_values(array_unique(array_map(fn($r) => $r['id'], array_filter($refs, fn($r) => !$r['catalog']))));
    $catalog = array_values(array_unique(array_map(fn($r) => $r['id'], array_filter($refs, fn($r) => $r['catalog']))));

    $data = [];
    if ($items) {
        try {
            $data = MercadoLivre::fetchMany($items);
            foreach (array_diff($items, array_keys($data)) as $missing) $errors[] = "$missing: não encontrado";
        } catch (Throwable $ex) {
            $errors[] = $ex->getMessage();
        }
    }
    foreach ($catalog as $id) {
        try { $data[$id] = MercadoLivre::fetch($id, true); }
        catch (Throwable $ex) { $errors[] = "$id: " . $ex->getMessage(); }
    }
    foreach ($data as $d) {
        [, $isNew] = upsert_product($d, $categoryId);
        $isNew ? $created++ : $updated++;
    }
    return [$created, $updated, $errors];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cat = ($_POST['category_id'] ?? '') !== '' ? (int)$_POST['category_id'] : null;
    $refs = [];
    if (($_POST['action'] ?? '') === 'import_selected') {
        foreach ((array)($_POST['ids'] ?? []) as $v) {
            [$id, $type] = array_pad(explode('|', (string)$v, 2), 2, 'i');
            if (preg_match('/^MLB\d+$/', $id)) $refs[] = ['id' => $id, 'catalog' => $type === 'c'];
        }
    } elseif (($_POST['action'] ?? '') === 'import_urls') {
        foreach (preg_split('/\s+/', (string)$_POST['urls']) as $line) {
            if ($line === '') continue;
            $r = MercadoLivre::parseUrl($line);
            if ($r) $refs[] = $r; else flash("Não reconheci o link: $line", 'err');
        }
    }
    if ($refs) {
        [$c, $u, $errs] = import_refs($refs, $cat);
        flash("Importação concluída: $c novo(s), $u atualizado(s).");
        foreach ($errs as $er) flash($er, 'err');
        if ($c > 0) flash('Lembre de colar o link de afiliado (meli.la) de cada produto novo — eles aparecem marcados na lista.', 'warn');
    } else {
        flash('Nenhum produto selecionado.', 'warn');
    }
    redirect(url('admin/buscar.php?' . http_build_query(['q' => $_POST['q'] ?? '', 'page' => $_POST['page'] ?? 1])));
}

$q = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 20;
$result = null;
$error = null;
if ($q !== '') {
    try {
        $result = MercadoLivre::search($q, ($page - 1) * $limit, $limit);
    } catch (Throwable $ex) {
        $error = $ex->getMessage();
    }
}

// quais já estão no banco
$existing = [];
if ($result && $result['results']) {
    $ids = array_column($result['results'], 'ml_id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = db()->prepare("SELECT ml_id FROM products WHERE ml_id IN ($in)");
    $st->execute($ids);
    $existing = array_flip($st->fetchAll(PDO::FETCH_COLUMN));
}

admin_header('Buscar produtos no Mercado Livre', 'buscar');

if (!MercadoLivre::isConnected()): ?>
  <div class="alert alert-warn">Conecte sua conta do Mercado Livre em <a href="configuracoes.php">Configurações</a> para buscar e importar produtos.</div>
<?php endif; ?>

<div class="grid-2">
  <form class="card" method="get">
    <h2>Buscar</h2>
    <label>Palavra-chave <input name="q" value="<?= e($q) ?>" placeholder="ex.: air fryer, fone bluetooth" autofocus></label>
    <button class="btn btn-primary">Buscar</button>
  </form>

  <form class="card" method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="import_urls">
    <h2>Importar por link</h2>
    <label>Cole um ou mais links de produtos do ML (um por linha)
      <textarea name="urls" rows="4" placeholder="https://produto.mercadolivre.com.br/MLB-1234567890-...&#10;https://www.mercadolivre.com.br/.../p/MLB12345678"></textarea></label>
    <label>Categoria <?= category_select('category_id', null) ?></label>
    <button class="btn btn-primary">Importar</button>
  </form>
</div>

<?php if ($error): ?>
  <div class="alert alert-err"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($result): ?>
<form method="post" class="card">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="import_selected">
  <input type="hidden" name="q" value="<?= e($q) ?>">
  <input type="hidden" name="page" value="<?= $page ?>">
  <div class="toolbar">
    <label class="inline"><input type="checkbox" data-check-all=".pick"> Selecionar todos</label>
    <span class="muted"><?= number_format($result['total'], 0, ',', '.') ?> resultados
      <?= $result['mode'] === 'catalog' ? '(busca de catálogo — preço é carregado ao importar)' : '' ?></span>
    <span class="spacer"></span>
    <label class="inline">Categoria <?= category_select('category_id', null) ?></label>
    <button class="btn btn-primary">Importar selecionados</button>
  </div>
  <div class="pick-grid">
    <?php foreach ($result['results'] as $r): $has = isset($existing[$r['ml_id']]); ?>
      <label class="pick-card <?= $has ? 'has' : '' ?>">
        <input type="checkbox" class="pick" name="ids[]" value="<?= e($r['ml_id'] . '|' . ($result['mode'] === 'catalog' ? 'c' : 'i')) ?>">
        <img src="<?= e($r['image']) ?>" alt="" loading="lazy">
        <span class="t"><?= e($r['title']) ?></span>
        <?php if ($r['price'] !== null): ?>
          <span class="p">
            <?php if ($r['original_price'] && $r['original_price'] > $r['price']): ?><s><?= money($r['original_price']) ?></s><?php endif; ?>
            <?= money($r['price']) ?>
          </span>
        <?php endif; ?>
        <span class="tags">
          <?php if ($r['free_shipping']): ?><em class="tag">Frete grátis</em><?php endif; ?>
          <?php if ($has): ?><em class="tag tag-dark">Já no site</em><?php endif; ?>
          <a href="<?= e($r['permalink']) ?>" target="_blank" rel="noopener" onclick="event.stopPropagation()">ver ↗</a>
        </span>
      </label>
    <?php endforeach; ?>
  </div>
  <div class="pager">
    <?php if ($page > 1): ?><a class="btn" href="?<?= e(http_build_query(['q' => $q, 'page' => $page - 1])) ?>">← Anterior</a><?php endif; ?>
    <span>Página <?= $page ?></span>
    <?php if ($page * $limit < min($result['total'], 1000)): ?><a class="btn" href="?<?= e(http_build_query(['q' => $q, 'page' => $page + 1])) ?>">Próxima →</a><?php endif; ?>
  </div>
</form>
<?php endif;
admin_footer();
