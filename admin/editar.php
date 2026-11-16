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
        $t = fn($k) => trim((string)($_POST[$k] ?? '')) ?: null;
        $num = function ($k) { $v = str_replace(['R$', ' ', '.'], '', (string)($_POST[$k] ?? '')); $v = str_replace(',', '.', $v); return $v === '' ? null : (float)$v; };
        $badge = $t('badge') === '__custom' ? $t('badge_custom') : $t('badge');
        $rating = $num('rating');
        if ($rating !== null) $rating = max(0, min(5, $rating));
        $pdo->prepare('UPDATE products SET title=?, slug=?, affiliate_url=?, category_id=?, description=?, coupon=?,
            featured=?, active=?, badge=?, brand=?, brand_verified=?, rating=?, sold_label=?, pix_price=?,
            installments_qty=?, installments_value=?, installments_free=?, coupon_label=?, delivery_label=?, is_full=?,
            updated_at=? WHERE id=?')->execute([
            $title, $slug, $aff ?: null,
            ($_POST['category_id'] ?? '') !== '' ? (int)$_POST['category_id'] : null,
            $t('description'), $t('coupon'),
            isset($_POST['featured']) ? 1 : 0,
            isset($_POST['active']) ? 1 : 0,
            $badge ? mb_strtoupper($badge) : null, $t('brand'), isset($_POST['brand_verified']) ? 1 : 0,
            $rating, $t('sold_label'), $num('pix_price'),
            ($_POST['installments_qty'] ?? '') !== '' ? (int)$_POST['installments_qty'] : null,
            $num('installments_value'), isset($_POST['installments_free']) ? 1 : 0,
            $t('coupon_label'), $t('delivery_label'), isset($_POST['is_full']) ? 1 : 0,
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
    <label>Código do cupom (opcional) <input name="coupon" value="<?= e($p['coupon']) ?>" placeholder="ACHADO10"></label>

    <fieldset class="card-fields">
      <legend>Informações do card</legend>
      <?php $badges = ['' => '— sem selo —', 'BLACK FRIDAY' => 'BLACK FRIDAY (preto)', 'OFERTA DO DIA' => 'OFERTA DO DIA (azul)', 'MAIS VENDIDO' => 'MAIS VENDIDO (laranja)', 'OFERTA RELÂMPAGO' => 'OFERTA RELÂMPAGO (vermelho)'];
            $cur = (string)($p['badge'] ?? ''); $isCustom = $cur !== '' && !isset($badges[$cur]); ?>
      <div class="grid-2">
        <label>Selo na foto
          <select name="badge" onchange="this.form.badge_custom.hidden = this.value !== '__custom'">
            <?php foreach ($badges as $v => $l): ?><option value="<?= e($v) ?>" <?= $cur === $v ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
            <option value="__custom" <?= $isCustom ? 'selected' : '' ?>>Outro texto…</option>
          </select>
          <input name="badge_custom" value="<?= e($isCustom ? $cur : '') ?>" placeholder="Texto do selo" <?= $isCustom ? '' : 'hidden' ?>>
        </label>
        <label>Marca / loja <input name="brand" value="<?= e($p['brand']) ?>" placeholder="DARK LAB"></label>
      </div>
      <label class="inline"><input type="checkbox" name="brand_verified" <?= !empty($p['brand_verified']) ? 'checked' : '' ?>> Loja oficial (selo azul de verificado)</label>
      <div class="grid-2">
        <label>Nota (0 a 5) <input name="rating" value="<?= e($p['rating'] !== null ? (string)$p['rating'] : '') ?>" placeholder="4.8"></label>
        <label>Vendidos <input name="sold_label" value="<?= e($p['sold_label']) ?>" placeholder="+10mil vendidos"></label>
      </div>
      <div class="grid-2">
        <label>Preço no Pix (se for menor) <input name="pix_price" value="<?= e($p['pix_price'] !== null ? number_format((float)$p['pix_price'], 2, ',', '') : '') ?>" placeholder="86,99"></label>
        <label>Texto do cupom no card <input name="coupon_label" value="<?= e($p['coupon_label']) ?>" placeholder="R$ 20 OFF com Cupom"></label>
      </div>
      <div class="grid-2">
        <label>Parcelas <input type="number" min="1" name="installments_qty" value="<?= e((string)($p['installments_qty'] ?? '')) ?>" placeholder="4"></label>
        <label>Valor da parcela <input name="installments_value" value="<?= e($p['installments_value'] !== null ? number_format((float)$p['installments_value'], 2, ',', '') : '') ?>" placeholder="29,15"></label>
      </div>
      <label class="inline"><input type="checkbox" name="installments_free" <?= !empty($p['installments_free']) ? 'checked' : '' ?>> Sem juros</label>
      <div class="grid-2">
        <label>Texto da entrega <input name="delivery_label" value="<?= e($p['delivery_label']) ?>" placeholder="Chegará grátis amanhã"></label>
        <label class="inline" style="margin-top:22px"><input type="checkbox" name="is_full" <?= !empty($p['is_full']) ? 'checked' : '' ?>> Entrega FULL ⚡</label>
      </div>
      <small class="muted">Campos vazios não aparecem no card. Marca, FULL e parcelas são preenchidos pela API do Mercado Livre quando disponíveis.</small>
    </fieldset>
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
