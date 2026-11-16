<?php
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/admin_layout.php';
require_admin();
csrf_check();

$pdo = db();
$editId = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save';
    $id = (int)($_POST['id'] ?? 0);
    try {
        if ($action === 'save') {
            $f = fn($k) => trim((string)($_POST[$k] ?? '')) ?: null;
            $image = save_upload($_FILES['image_file'] ?? []) ?? $f('image');
            if (!$image) throw new RuntimeException('Informe a imagem do banner (envie um arquivo ou cole a URL).');
            $link = $f('link_url');
            if ($link && !preg_match('~^(https?://|/|\?)~', $link)) throw new RuntimeException('O link deve começar com https://, / ou ?');
            $vals = [$f('kicker'), $f('title'), $f('prefix'), $f('highlight'), $f('pill'), $f('footnote'), $image, $link,
                (int)($_POST['sort_order'] ?? 0), isset($_POST['active']) ? 1 : 0, $f('starts_at'), $f('ends_at')];
            if ($id) {
                $pdo->prepare('UPDATE banners SET kicker=?, title=?, prefix=?, highlight=?, pill=?, footnote=?, image=?, link_url=?,
                    sort_order=?, active=?, starts_at=?, ends_at=? WHERE id=?')->execute([...$vals, $id]);
            } else {
                $pdo->prepare('INSERT INTO banners (kicker, title, prefix, highlight, pill, footnote, image, link_url, sort_order, active,
                    starts_at, ends_at, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([...$vals, now()]);
            }
            flash('Banner salvo.');
        } elseif ($action === 'toggle') {
            $pdo->prepare('UPDATE banners SET active = 1 - active WHERE id=?')->execute([$id]);
        } elseif ($action === 'delete') {
            $pdo->prepare('DELETE FROM banners WHERE id=?')->execute([$id]);
            flash('Banner excluído.');
        }
    } catch (Throwable $ex) {
        flash($ex->getMessage(), 'err');
        redirect(url('admin/banners.php' . ($id ? "?id=$id" : '?novo=1')));
    }
    redirect(url('admin/banners.php'));
}

$banners = $pdo->query('SELECT * FROM banners ORDER BY sort_order, id DESC')->fetchAll();
$b = ['id' => 0, 'kicker' => '', 'title' => '', 'prefix' => 'ATÉ', 'highlight' => '', 'pill' => '', 'footnote' => '',
      'image' => '', 'link_url' => '', 'sort_order' => 0, 'active' => 1, 'starts_at' => '', 'ends_at' => ''];
if ($editId) {
    $st = $pdo->prepare('SELECT * FROM banners WHERE id=?');
    $st->execute([$editId]);
    $b = $st->fetch() ?: $b;
}
$showForm = $editId || isset($_GET['novo']) || !$banners;

admin_header('Banners da home', 'banners');
?>
<p class="muted">Os banners ativos giram no topo da home, na ordem definida. Sem banner ativo, o carrossel mostra os produtos marcados como destaque.
  Imagem ideal: produto com fundo transparente (PNG) ou foto quadrada, ~800×600.</p>
<p class="muted"><b>Banner com arte pronta:</b> deixe <i>Etiqueta</i>, <i>Destaque grande</i> e <i>Texto do botão</i> em branco e envie a arte inteira
  (com o texto já na imagem, proporção ~3:1, ex.: 2576×850). Ela ocupa o slide todo; o <i>Título</i> vira o texto alternativo da imagem.</p>

<?php if ($banners): ?>
<div class="card">
  <div class="toolbar"><span class="spacer"></span><a class="btn btn-primary" href="?novo=1">+ Novo banner</a></div>
  <table class="tbl">
    <thead><tr><th></th><th>Banner</th><th>Período</th><th>Ordem</th><th>Status</th><th></th></tr></thead>
    <?php foreach ($banners as $r): ?>
      <tr class="<?= $r['active'] ? '' : 'off' ?>">
        <td><img class="thumb" src="<?= e($r['image']) ?>" alt=""></td>
        <td><b><?= e($r['kicker']) ?></b> <?= e($r['title']) ?><div class="muted small"><?= e(trim(($r['prefix'] ?? '') . ' ' . ($r['highlight'] ?? ''))) ?> → <?= e($r['link_url']) ?></div></td>
        <td class="small"><?= e($r['starts_at'] ?: '—') ?> até <?= e($r['ends_at'] ?: '—') ?></td>
        <td><?= (int)$r['sort_order'] ?></td>
        <td><?= $r['active'] ? '<em class="tag tag-ok">ativo</em>' : '<em class="tag tag-dark">inativo</em>' ?></td>
        <td class="nowrap">
          <a class="btn btn-sm" href="?id=<?= (int)$r['id'] ?>">Editar</a>
          <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button class="btn btn-sm" name="action" value="toggle"><?= $r['active'] ? 'Desativar' : 'Ativar' ?></button>
            <button class="btn btn-sm btn-danger" name="action" value="delete" onclick="return confirm('Excluir banner?')">Excluir</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endif; ?>

<?php if ($showForm): ?>
<form method="post" enctype="multipart/form-data" class="card">
  <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
  <h2><?= $b['id'] ? 'Editar banner' : 'Novo banner' ?></h2>
  <div class="grid-2">
    <div>
      <label>Etiqueta (destaque em caixa alta) <input name="kicker" value="<?= e($b['kicker']) ?>" placeholder="BLACK FRIDAY"></label>
      <label>Título <input name="title" value="<?= e($b['title']) ?>" placeholder="Tecnologia no modo black"></label>
      <div class="grid-2">
        <label>Antes do destaque <input name="prefix" value="<?= e($b['prefix']) ?>" placeholder="ATÉ"></label>
        <label>Destaque grande <input name="highlight" value="<?= e($b['highlight']) ?>" placeholder="45% OFF"></label>
      </div>
      <label>Texto do botão branco <input name="pill" value="<?= e($b['pill']) ?>" placeholder="Ver ofertas"></label>
      <label>Letra miúda <input name="footnote" value="<?= e($b['footnote']) ?>" placeholder="*Ofertas válidas até 30/11 ou enquanto durarem os estoques."></label>
    </div>
    <div>
      <label>Imagem (enviar arquivo) <input type="file" name="image_file" accept="image/*"></label>
      <label>…ou URL da imagem <input name="image" value="<?= e($b['image']) ?>" placeholder="https://..."></label>
      <?php if ($b['image']): ?><img src="<?= e($b['image']) ?>" alt="" style="max-height:120px;margin-bottom:12px"><?php endif; ?>
      <label>Link ao clicar
        <input name="link_url" value="<?= e($b['link_url']) ?>" placeholder="?c=tecnologia  ou  ?o=desconto  ou  https://meli.la/...">
        <small>Use <code>?c=slug-da-categoria</code>, <code>?q=busca</code>, <code>?o=desconto</code> para levar a uma lista do site, ou um link de afiliado.</small></label>
      <div class="grid-2">
        <label>Início (opcional) <input type="date" name="starts_at" value="<?= e($b['starts_at']) ?>"></label>
        <label>Fim (opcional) <input type="date" name="ends_at" value="<?= e($b['ends_at']) ?>"></label>
      </div>
      <div class="row">
        <label>Ordem <input type="number" name="sort_order" value="<?= (int)$b['sort_order'] ?>" class="w-num"></label>
        <label class="inline"><input type="checkbox" name="active" <?= $b['active'] ? 'checked' : '' ?>> Ativo</label>
      </div>
    </div>
  </div>
  <div class="row"><button class="btn btn-primary">Salvar banner</button><a class="btn" href="banners.php">Cancelar</a></div>
</form>
<?php endif;
admin_footer();
