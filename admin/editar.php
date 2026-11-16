<?php
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/admin_layout.php';
require_admin();
csrf_check();

$pdo = db();
$id = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare('SELECT * FROM products WHERE id = ?');
$st->execute([$id]);
$p = $st->fetch();
if (!$p) { flash('Produto não encontrado.', 'err'); redirect(url('admin/')); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save';
    if ($action === 'save') {
        $aff = trim((string)$_POST['affiliate_url']);
        if ($aff !== '' && !preg_match('~^https://~i', $aff)) {
            flash('O link de afiliado precisa começar com https://', 'err');
            redirect(url('admin/editar.php?id=' . $id));
        }
        $title = trim((string)$_POST['title']) ?: $p['title'];
        $slug = slugify(trim((string)$_POST['slug']) ?: $title);
        if ($slug !== $p['slug']) $slug = unique_slug($slug, $id);
        $pdo->prepare('UPDATE products SET title=?, slug=?, affiliate_url=?, category_id=?, description=?, coupon=?,
            featured=?, active=?, updated_at=? WHERE id=?')->execute([
            $title, $slug, $aff ?: null,
            ($_POST['category_id'] ?? '') !== '' ? (int)$_POST['category_id'] : null,
            trim((string)$_POST['description']) ?: null,
            trim((string)$_POST['coupon']) ?: null,
            isset($_POST['featured']) ? 1 : 0,
            isset($_POST['active']) ? 1 : 0,
            now(), $id,
        ]);
        flash('Produto salvo.');
    } elseif ($action === 'refresh') {
        try { refresh_prices([$p['ml_id']]); flash('Preço e foto atualizados.'); }
        catch (Throwable $ex) { flash($ex->getMessage(), 'err'); }
    } elseif ($action === 'posted') {
        $pdo->prepare('UPDATE products SET posted_at=? WHERE id=?')->execute([now(), $id]);
        flash('Marcado como divulgado.');
    } elseif ($action === 'unposted') {
        $pdo->prepare('UPDATE products SET posted_at=NULL WHERE id=?')->execute([$id]);
    } elseif ($action === 'delete') {
        $pdo->prepare('DELETE FROM price_history WHERE product_id=?')->execute([$id]);
        $pdo->prepare('DELETE FROM products WHERE id=?')->execute([$id]);
        flash('Produto excluído.');
        redirect(url('admin/'));
    }
    redirect(url('admin/editar.php?id=' . $id));
}

$hist = $pdo->prepare('SELECT price, recorded_at FROM price_history WHERE product_id=? ORDER BY recorded_at DESC LIMIT 15');
$hist->execute([$id]);
$history = $hist->fetchAll();
$text = social_text($p);

admin_header('Editar produto', 'produtos');
?>
<div class="edit-layout">
  <form method="post" class="card">
    <?= csrf_field() ?><input type="hidden" name="action" value="save">
    <div class="edit-head">
      <img src="<?= e($p['image']) ?>" alt="">
      <div>
        <div class="muted small"><?= e($p['ml_id']) ?> · <a href="<?= e($p['permalink']) ?>" target="_blank" rel="noopener">abrir no ML ↗</a></div>
        <div class="big-price">
          <?php if (discount_pct($p)): ?><s><?= money((float)$p['original_price']) ?></s><?php endif; ?>
          <?= money((float)$p['price']) ?>
        </div>
        <div class="muted small">Preço atualizado em <?= e($p['price_updated_at']) ?> · <?= (int)$p['clicks'] ?> cliques</div>
      </div>
    </div>

    <label class="hl">Link de afiliado (gerado no painel de afiliados do ML)
      <input name="affiliate_url" value="<?= e($p['affiliate_url']) ?>" placeholder="https://meli.la/xxxxxx">
      <small>Abra o produto no ML logado como afiliado, use a barra de afiliados no topo para gerar o link e cole aqui.
        <a href="<?= e($p['permalink']) ?>" target="_blank" rel="noopener">Abrir produto ↗</a></small>
    </label>
    <label>Título exibido <input name="title" value="<?= e($p['title']) ?>"></label>
    <label>Slug (URL) <input name="slug" value="<?= e($p['slug']) ?>"></label>
    <label>Categoria <?= category_select('category_id', $p['category_id'] !== null ? (int)$p['category_id'] : null) ?></label>
    <label>Cupom (opcional) <input name="coupon" value="<?= e($p['coupon']) ?>"></label>
    <label>Sua descrição / review (aparece na página do produto e ajuda no SEO)
      <textarea name="description" rows="5"><?= e($p['description']) ?></textarea></label>
    <div class="row">
      <label class="inline"><input type="checkbox" name="active" <?= $p['active'] ? 'checked' : '' ?>> Ativo no site</label>
      <label class="inline"><input type="checkbox" name="featured" <?= $p['featured'] ? 'checked' : '' ?>> Destaque</label>
    </div>
    <div class="row">
      <button class="btn btn-primary">Salvar</button>
      <a class="btn" href="<?= e(product_url($p)) ?>" target="_blank">Ver no site ↗</a>
    </div>
  </form>

  <div>
    <div class="card">
      <h2>Divulgar nas redes</h2>
      <textarea id="social" rows="10" readonly><?= e($text) ?></textarea>
      <div class="row">
        <button type="button" class="btn" data-copy="#social">Copiar texto</button>
        <a class="btn" target="_blank" rel="noopener" href="https://wa.me/?text=<?= rawurlencode($text) ?>">WhatsApp</a>
        <a class="btn" target="_blank" rel="noopener" href="https://t.me/share/url?url=<?= rawurlencode(product_url($p)) ?>&text=<?= rawurlencode($text) ?>">Telegram</a>
      </div>
      <form method="post" class="row">
        <?= csrf_field() ?>
        <?php if ($p['posted_at']): ?>
          <span class="ok-text">Divulgado em <?= e($p['posted_at']) ?></span>
          <input type="hidden" name="action" value="unposted"><button class="btn btn-sm">Desmarcar</button>
        <?php else: ?>
          <input type="hidden" name="action" value="posted"><button class="btn btn-sm">Marcar como divulgado</button>
        <?php endif; ?>
      </form>
    </div>

    <div class="card">
      <h2>Preço</h2>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="refresh">
        <button class="btn btn-sm">Atualizar preço agora</button></form>
      <table class="tbl small">
        <?php foreach ($history as $h): ?>
          <tr><td><?= e($h['recorded_at']) ?></td><td><?= money((float)$h['price']) ?></td></tr>
        <?php endforeach; ?>
      </table>
    </div>

    <form method="post" class="card" data-confirm="Excluir este produto?">
      <?= csrf_field() ?><input type="hidden" name="action" value="delete">
      <button class="btn btn-danger">Excluir produto</button>
    </form>
  </div>
</div>
<?php admin_footer();
