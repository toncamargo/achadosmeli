<?php
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/admin_layout.php';
require_admin();
csrf_check();

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ids = array_map('intval', (array)($_POST['ids'] ?? []));
    $action = $_POST['action'] ?? '';
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        switch ($action) {
            case 'activate':   $pdo->prepare("UPDATE products SET active=1 WHERE id IN ($in)")->execute($ids); break;
            case 'deactivate': $pdo->prepare("UPDATE products SET active=0 WHERE id IN ($in)")->execute($ids); break;
            case 'feature':    $pdo->prepare("UPDATE products SET featured=1 WHERE id IN ($in)")->execute($ids); break;
            case 'unfeature':  $pdo->prepare("UPDATE products SET featured=0 WHERE id IN ($in)")->execute($ids); break;
            case 'delete':
                $pdo->prepare("DELETE FROM price_history WHERE product_id IN ($in)")->execute($ids);
                $pdo->prepare("DELETE FROM products WHERE id IN ($in)")->execute($ids);
                break;
            case 'category':
                $cat = ($_POST['category_id'] ?? '') !== '' ? (int)$_POST['category_id'] : null;
                $pdo->prepare("UPDATE products SET category_id=? WHERE id IN ($in)")->execute(array_merge([$cat], $ids));
                break;
            case 'refresh':
                $rows = $pdo->prepare("SELECT ml_id FROM products WHERE id IN ($in)");
                $rows->execute($ids);
                $n = refresh_prices($rows->fetchAll(PDO::FETCH_COLUMN));
                flash("$n produto(s) atualizados com o preço atual.");
                break;
        }
        if ($action !== 'refresh') flash('Feito: ' . count($ids) . ' produto(s).');
    } else {
        flash('Selecione ao menos um produto.', 'warn');
    }
    redirect(url('admin/?' . http_build_query(array_intersect_key($_GET, array_flip(['q', 'cat', 'f'])))));
}

$q = trim((string)($_GET['q'] ?? ''));
$cat = (string)($_GET['cat'] ?? '');
$f = (string)($_GET['f'] ?? '');

$where = [];
$args = [];
if ($q !== '') { $where[] = '(p.title LIKE ? OR p.ml_id LIKE ?)'; $args[] = "%$q%"; $args[] = "%$q%"; }
if ($cat === 'none') $where[] = 'p.category_id IS NULL';
elseif ($cat !== '') { $where[] = 'p.category_id = ?'; $args[] = (int)$cat; }
if ($f === 'noaff') $where[] = "(p.affiliate_url IS NULL OR p.affiliate_url = '')";
if ($f === 'inactive') $where[] = 'p.active = 0';
if ($f === 'featured') $where[] = 'p.featured = 1';
if ($f === 'notposted') $where[] = 'p.posted_at IS NULL';
if ($f === 'review') $where[] = 'p.active = 0 AND p.source_id IS NOT NULL';
$sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$st = $pdo->prepare("SELECT p.*, c.name AS cat_name FROM products p LEFT JOIN categories c ON c.id = p.category_id
    $sqlWhere ORDER BY p.created_at DESC LIMIT 300");
$st->execute($args);
$products = $st->fetchAll();

$stats = $pdo->query("SELECT COUNT(*) total, COALESCE(SUM(clicks),0) clicks,
    SUM(CASE WHEN affiliate_url IS NULL OR affiliate_url = '' THEN 1 ELSE 0 END) noaff,
    SUM(CASE WHEN active = 1 THEN 1 ELSE 0 END) active FROM products")->fetch();

$autoAff = setting('aff_matt_word') && setting('aff_matt_tool');

admin_header('Produtos', 'produtos');
?>
<div class="stats">
  <div><b><?= (int)$stats['total'] ?></b><span>produtos</span></div>
  <div><b><?= (int)$stats['active'] ?></b><span>ativos no site</span></div>
  <div><b><?= (int)$stats['clicks'] ?></b><span>cliques enviados ao ML</span></div>
  <div class="<?= $stats['noaff'] && !$autoAff ? 'warn' : '' ?>"><b><?= (int)$stats['noaff'] ?></b><span><?= $autoAff ? 'com link de afiliado automático' : 'sem link de afiliado' ?></span></div>
</div>

<form class="filters" method="get">
  <input name="q" value="<?= e($q) ?>" placeholder="Buscar título ou MLB...">
  <select name="cat">
    <option value="">Todas as categorias</option>
    <option value="none" <?= $cat === 'none' ? 'selected' : '' ?>>Sem categoria</option>
    <?php foreach (categories() as $c): ?>
      <option value="<?= (int)$c['id'] ?>" <?= $cat === (string)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="f">
    <option value="">Todos</option>
    <option value="noaff" <?= $f === 'noaff' ? 'selected' : '' ?>>Sem link de afiliado</option>
    <option value="featured" <?= $f === 'featured' ? 'selected' : '' ?>>Em destaque</option>
    <option value="inactive" <?= $f === 'inactive' ? 'selected' : '' ?>>Inativos</option>
    <option value="notposted" <?= $f === 'notposted' ? 'selected' : '' ?>>Ainda não divulgados</option>
    <option value="review" <?= $f === 'review' ? 'selected' : '' ?>>Coletados aguardando revisão</option>
  </select>
  <button class="btn">Filtrar</button>
  <a class="btn btn-primary" href="buscar.php">+ Adicionar produtos</a>
</form>

<?php if (!$products): ?>
  <div class="card empty">Nenhum produto ainda. <a href="buscar.php">Busque no Mercado Livre</a> para começar.</div>
<?php else: ?>
<form method="post" class="card">
  <?= csrf_field() ?>
  <div class="toolbar">
    <select name="action">
      <option value="">Ações em massa…</option>
      <option value="refresh">Atualizar preços (API)</option>
      <option value="feature">Destacar</option>
      <option value="unfeature">Tirar destaque</option>
      <option value="activate">Ativar</option>
      <option value="deactivate">Desativar</option>
      <option value="category">Mudar categoria →</option>
      <option value="delete">Excluir</option>
    </select>
    <?= category_select('category_id', null) ?>
    <button class="btn">Aplicar</button>
  </div>
  <table class="tbl">
    <thead><tr>
      <th><input type="checkbox" data-check-all=".row-cb"></th>
      <th></th><th>Produto</th><th>Preço</th><th>Categoria</th><th>Cliques</th><th>Status</th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($products as $p): $pct = discount_pct($p); ?>
      <tr class="<?= $p['active'] ? '' : 'off' ?>">
        <td><input type="checkbox" class="row-cb" name="ids[]" value="<?= (int)$p['id'] ?>"></td>
        <td><img class="thumb" src="<?= e($p['image']) ?>" alt="" loading="lazy"></td>
        <td>
          <a href="editar.php?id=<?= (int)$p['id'] ?>"><?= e(mb_strimwidth($p['title'], 0, 80, '…')) ?></a>
          <div class="muted small"><?= e($p['ml_id']) ?></div>
        </td>
        <td class="nowrap"><?= money((float)$p['price']) ?><?php if ($pct): ?> <em class="tag">-<?= $pct ?>%</em><?php endif; ?></td>
        <td><?= e($p['cat_name'] ?? '—') ?></td>
        <td><?= (int)$p['clicks'] ?></td>
        <td class="small">
          <?php if (!$p['affiliate_url']): ?><em class="tag <?= $autoAff ? '' : 'tag-warn' ?>"><?= $autoAff ? 'link automático' : 'sem link afiliado' ?></em><?php endif; ?>
          <?php if ($p['featured']): ?><em class="tag">destaque</em><?php endif; ?>
          <?php if (!$p['active']): ?><em class="tag tag-dark">inativo</em><?php endif; ?>
          <?php if ($p['posted_at']): ?><em class="tag tag-ok">divulgado</em><?php endif; ?>
        </td>
        <td><a class="btn btn-sm" href="editar.php?id=<?= (int)$p['id'] ?>">Editar</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</form>
<?php endif;
admin_footer();
