<?php
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/admin_layout.php';
require_admin();
csrf_check();

$pdo = db();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $name = trim((string)($_POST['name'] ?? ''));
    try {
        if ($action === 'add' && $name !== '') {
            $pdo->prepare('INSERT INTO categories (name, slug, sort_order) VALUES (?,?,?)')
                ->execute([$name, slugify($name), (int)($_POST['sort_order'] ?? 0)]);
            flash('Categoria criada.');
        } elseif ($action === 'update' && $name !== '') {
            $pdo->prepare('UPDATE categories SET name=?, slug=?, sort_order=? WHERE id=?')
                ->execute([$name, slugify($name), (int)$_POST['sort_order'], (int)$_POST['id']]);
            flash('Categoria salva.');
        } elseif ($action === 'delete') {
            $pdo->prepare('UPDATE products SET category_id=NULL WHERE category_id=?')->execute([(int)$_POST['id']]);
            $pdo->prepare('DELETE FROM categories WHERE id=?')->execute([(int)$_POST['id']]);
            flash('Categoria excluída (produtos ficaram sem categoria).');
        }
    } catch (PDOException) {
        flash('Já existe uma categoria com esse nome.', 'err');
    }
    redirect(url('admin/categorias.php'));
}

$cats = $pdo->query('SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) AS total
    FROM categories c ORDER BY sort_order, name')->fetchAll();

admin_header('Categorias', 'categorias');
?>
<div class="card">
  <table class="tbl">
    <thead><tr><th>Ordem</th><th>Nome</th><th>Produtos</th><th></th></tr></thead>
    <?php foreach ($cats as $c): $f = 'cat' . (int)$c['id']; ?>
      <tr>
        <td><input form="<?= $f ?>" name="sort_order" type="number" value="<?= (int)$c['sort_order'] ?>" class="w-num"></td>
        <td><input form="<?= $f ?>" name="name" value="<?= e($c['name']) ?>"></td>
        <td><?= (int)$c['total'] ?></td>
        <td class="nowrap">
          <button form="<?= $f ?>" class="btn btn-sm" name="action" value="update">Salvar</button>
          <button form="<?= $f ?>" class="btn btn-sm btn-danger" name="action" value="delete" onclick="return confirm('Excluir categoria?')">Excluir</button>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
  <?php foreach ($cats as $c): ?>
    <form method="post" id="cat<?= (int)$c['id'] ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"></form>
  <?php endforeach; ?>
</div>
<form method="post" class="card narrow">
  <?= csrf_field() ?><input type="hidden" name="action" value="add">
  <h2>Nova categoria</h2>
  <label>Nome <input name="name" required></label>
  <label>Ordem <input name="sort_order" type="number" value="0"></label>
  <button class="btn btn-primary">Adicionar</button>
</form>
<?php admin_footer();
