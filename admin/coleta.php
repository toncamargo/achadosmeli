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
            $t = fn($k) => trim((string)($_POST[$k] ?? ''));
            $num = fn($k) => $t($k) === '' ? null : (float)str_replace(',', '.', $t($k));
            $type = $t('type') === 'bestsellers' ? 'bestsellers' : 'keyword';
            $mlCat = strtoupper($t('ml_category_id'));
            if ($mlCat !== '' && !preg_match('/^MLB\d+$/', $mlCat)) throw new RuntimeException('Categoria do ML inválida (formato MLB1234).');
            if ($type === 'keyword' && $t('query') === '') throw new RuntimeException('Informe a palavra-chave.');
            if ($type === 'bestsellers' && $mlCat === '') throw new RuntimeException('Escolha a categoria do Mercado Livre para os mais vendidos.');
            $name = $t('name') ?: ($type === 'keyword' ? 'Busca: ' . $t('query') : 'Mais vendidos ' . $mlCat);

            $vals = [
                $name, $type, $t('query') ?: null, $mlCat ?: null,
                $t('category_id') !== '' ? (int)$t('category_id') : null,
                max(0, min(90, (int)$t('min_discount'))), $num('min_price'), $num('max_price'),
                isset($_POST['free_shipping_only']) ? 1 : 0, isset($_POST['full_only']) ? 1 : 0,
                $t('exclude_words') ?: null, max(1, min(50, (int)($t('max_items') ?: 20))),
                isset($_POST['auto_publish']) ? 1 : 0, max(1, min(168, (int)($t('every_hours') ?: 12))),
                isset($_POST['active']) ? 1 : 0,
            ];
            $cols = 'name=?, type=?, query=?, ml_category_id=?, category_id=?, min_discount=?, min_price=?, max_price=?,
                free_shipping_only=?, full_only=?, exclude_words=?, max_items=?, auto_publish=?, every_hours=?, active=?';
            if ($id) {
                $pdo->prepare("UPDATE ml_sources SET $cols WHERE id=?")->execute([...$vals, $id]);
            } else {
                $pdo->prepare('INSERT INTO ml_sources (name, type, query, ml_category_id, category_id, min_discount, min_price,
                    max_price, free_shipping_only, full_only, exclude_words, max_items, auto_publish, every_hours, active, created_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([...$vals, now()]);
                $id = (int)$pdo->lastInsertId();
            }
            flash('Fonte salva.');
            if (isset($_POST['run_now'])) $action = 'run';
        }
        if ($action === 'run') {
            $src = source_find($id) ?? throw new RuntimeException('Fonte não encontrada.');
            @set_time_limit(180);
            $r = collect_source($src);
            flash(sprintf('%s: %d encontrados, %d novos, %d atualizados, %d fora dos filtros.',
                $src['name'], $r['found'], $r['created'], $r['updated'], $r['skipped']), $r['errors'] ? 'warn' : 'ok');
            foreach (array_slice($r['errors'], 0, 5) as $er) flash($er, 'err');
            if ($r['created'] && !(int)$src['auto_publish']) {
                flash('Os produtos novos entraram inativos. Revise em Produtos → filtro "Coletados aguardando revisão".', 'warn');
            }
        } elseif ($action === 'run_all') {
            @set_time_limit(600);
            $tot = ['created' => 0, 'updated' => 0, 'n' => 0];
            foreach (sources(true) as $src) {
                $r = collect_source($src);
                $tot['created'] += $r['created']; $tot['updated'] += $r['updated']; $tot['n']++;
                foreach (array_slice($r['errors'], 0, 2) as $er) flash($src['name'] . ': ' . $er, 'err');
            }
            flash("{$tot['n']} fonte(s) executadas: {$tot['created']} novos, {$tot['updated']} atualizados.");
        } elseif ($action === 'toggle') {
            $pdo->prepare('UPDATE ml_sources SET active = 1 - active WHERE id=?')->execute([$id]);
        } elseif ($action === 'delete') {
            $pdo->prepare('DELETE FROM ml_sources WHERE id=?')->execute([$id]);
            $pdo->prepare('UPDATE products SET source_id = NULL WHERE source_id=?')->execute([$id]);
            flash('Fonte excluída (os produtos já coletados continuam no site).');
        }
    } catch (Throwable $ex) {
        flash($ex->getMessage(), 'err');
        redirect(url('admin/coleta.php' . ($id ? "?id=$id" : '?novo=1')));
    }
    redirect(url('admin/coleta.php'));
}

// Categoria do ML: navegação por subcategorias (?mlcat=MLB1648)
$mlCats = [];
$mlCatInfo = null;
$mlError = null;
if (MercadoLivre::isConnected()) {
    try {
        $mlCats = MercadoLivre::siteCategories();
        if (!empty($_GET['mlcat']) && preg_match('/^MLB\d+$/', (string)$_GET['mlcat'])) {
            $mlCatInfo = MercadoLivre::category((string)$_GET['mlcat']);
        }
    } catch (Throwable $ex) {
        $mlError = $ex->getMessage();
    }
}

$editing = $editId ? source_find($editId) : null;
$showForm = $editing || isset($_GET['novo']) || isset($_GET['mlcat']);
$src = $editing ?? source_defaults();
if ($mlCatInfo) {
    // escolheu uma categoria navegando: preenche o campo (e sugere "mais vendidos" numa fonte nova)
    if (!$editing) $src['type'] = 'bestsellers';
    $src['ml_category_id'] = $mlCatInfo['id'];
}
$list = sources();
$log = collect_log(15);
$counts = [];
foreach ($pdo->query('SELECT source_id, COUNT(*) n FROM products WHERE source_id IS NOT NULL GROUP BY source_id') as $r) {
    $counts[(int)$r['source_id']] = (int)$r['n'];
}

admin_header('Coleta automática', 'coleta');

if (!MercadoLivre::isConnected()): ?>
  <div class="alert alert-warn">Conecte sua conta do Mercado Livre em <a href="configuracoes.php">Configurações</a> para coletar produtos.</div>
<?php endif;
if ($mlError): ?>
  <div class="alert alert-err"><?= e($mlError) ?></div>
<?php endif; ?>

<div class="card">
  <p class="muted" style="margin-top:0">Cada <b>fonte</b> diz onde procurar produtos e quais aceitar. O cron
    <code>cron/coletar.php</code> roda as fontes no intervalo de cada uma; você também pode rodar na hora.
    <?php if (MercadoLivre::catalogOnly()): ?>
      <br>ℹ️ Seu app está sem acesso à busca de anúncios do ML, então a busca por palavra-chave usa o <b>catálogo</b>
      (mais lenta, pois o preço é consultado produto a produto). <b>Mais vendidos</b> é a fonte mais completa.
    <?php endif; ?></p>
  <div class="toolbar">
    <a class="btn btn-primary" href="?novo=1">+ Nova fonte</a>
    <?php if ($list): ?>
    <form method="post" style="display:inline">
      <?= csrf_field() ?><input type="hidden" name="action" value="run_all">
      <button class="btn">Rodar todas as ativas agora</button>
    </form>
    <?php endif; ?>
  </div>
</div>

<?php if ($showForm): ?>
<form method="post" class="card">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save">
  <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
  <h2><?= $editing ? 'Editar fonte' : 'Nova fonte' ?></h2>
  <div class="grid-2">
    <div>
      <label>Tipo
        <select name="type" id="src-type">
          <option value="keyword" <?= $src['type'] === 'keyword' ? 'selected' : '' ?>>Busca por palavra-chave</option>
          <option value="bestsellers" <?= $src['type'] === 'bestsellers' ? 'selected' : '' ?>>Mais vendidos de uma categoria do ML</option>
        </select></label>
      <label data-for="keyword">Palavra-chave
        <input name="query" value="<?= e($src['query']) ?>" placeholder="ex.: air fryer, fone bluetooth, jogo de panelas"></label>
      <label>Categoria do Mercado Livre <small data-for="keyword">Opcional na busca: restringe a uma categoria do ML.</small>
        <input name="ml_category_id" value="<?= e($src['ml_category_id']) ?>" placeholder="ex.: MLB1648" list="ml-cats">
        <?php if ($mlCats): ?>
          <datalist id="ml-cats"><?php foreach ($mlCats as $cid => $cname): ?><option value="<?= e($cid) ?>"><?= e($cname) ?></option><?php endforeach; ?></datalist>
        <?php endif; ?>
      </label>
      <?php if ($mlCatInfo): ?>
        <div class="card" style="background:#faf8f3">
          <b><?= e(implode(' › ', $mlCatInfo['path']) ?: $mlCatInfo['name']) ?></b> (<?= e($mlCatInfo['id']) ?>)
          <?php if ($mlCatInfo['children']): ?>
            <p class="muted small">Quanto mais específica a categoria, mais certeiros os mais vendidos. Subcategorias:</p>
            <?php foreach ($mlCatInfo['children'] as $cid => $cname): ?>
              <a class="tag tag-dark" href="?<?= e(http_build_query(array_filter(['mlcat' => $cid, 'id' => $editing['id'] ?? null]))) ?>"><?= e($cname) ?></a>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      <?php elseif ($mlCats): ?>
        <p class="muted small">Explorar categorias do ML:
          <?php foreach ($mlCats as $cid => $cname): ?>
            <a href="?<?= e(http_build_query(array_filter(['mlcat' => $cid, 'id' => $editing['id'] ?? null]))) ?>"><?= e($cname) ?></a> ·
          <?php endforeach; ?></p>
      <?php endif; ?>
      <label>Categoria no seu site <?= category_select('category_id', $src['category_id'] !== null ? (int)$src['category_id'] : null) ?></label>
      <label>Nome da fonte <small>Vazio = gerado automaticamente.</small>
        <input name="name" value="<?= e($src['name']) ?>"></label>
    </div>
    <div>
      <label>Desconto mínimo (%) <input name="min_discount" type="number" min="0" max="90" value="<?= (int)$src['min_discount'] ?>"></label>
      <div class="grid-2">
        <label>Preço mínimo (R$) <input name="min_price" inputmode="decimal" value="<?= e($src['min_price'] === null ? '' : (string)$src['min_price']) ?>"></label>
        <label>Preço máximo (R$) <input name="max_price" inputmode="decimal" value="<?= e($src['max_price'] === null ? '' : (string)$src['max_price']) ?>"></label>
      </div>
      <label class="inline"><input type="checkbox" name="free_shipping_only" <?= $src['free_shipping_only'] ? 'checked' : '' ?>> Só com frete grátis</label>
      <label class="inline"><input type="checkbox" name="full_only" <?= $src['full_only'] ? 'checked' : '' ?>> Só entrega FULL</label>
      <label style="margin-top:12px">Palavras proibidas no título <small>Separadas por vírgula. Ex.: usado, recondicionado, capa</small>
        <input name="exclude_words" value="<?= e($src['exclude_words']) ?>"></label>
      <div class="grid-2">
        <label>Máx. de produtos por execução <input name="max_items" type="number" min="1" max="50" value="<?= (int)$src['max_items'] ?>"></label>
        <label>Rodar a cada (horas) <input name="every_hours" type="number" min="1" max="168" value="<?= (int)$src['every_hours'] ?>"></label>
      </div>
      <label class="hl"><input type="checkbox" name="auto_publish" <?= $src['auto_publish'] ? 'checked' : '' ?>>
        Publicar direto no site <small>Desmarcado: os produtos novos entram inativos para você revisar antes.</small></label>
      <label class="inline"><input type="checkbox" name="active" <?= $src['active'] ? 'checked' : '' ?>> Fonte ativa (roda no cron)</label>
    </div>
  </div>
  <div class="toolbar" style="margin-top:14px">
    <button class="btn btn-primary">Salvar</button>
    <button class="btn" name="run_now" value="1">Salvar e rodar agora</button>
    <a class="btn" href="coleta.php">Cancelar</a>
  </div>
</form>
<script>
(() => {
  const sel = document.getElementById('src-type');
  const sync = () => document.querySelectorAll('[data-for="keyword"]').forEach(el => el.style.display = sel.value === 'keyword' ? '' : 'none');
  sel.addEventListener('change', sync); sync();
})();
</script>
<?php endif; ?>

<?php if ($list): ?>
<div class="card">
  <h2>Fontes</h2>
  <table class="tbl">
    <thead><tr><th>Fonte</th><th>Filtros</th><th>Destino</th><th>Última execução</th><th>Produtos</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($list as $s):
      $flt = [];
      if ($s['min_discount']) $flt[] = "≥{$s['min_discount']}% off";
      if ($s['min_price'] !== null) $flt[] = 'de ' . money((float)$s['min_price']);
      if ($s['max_price'] !== null) $flt[] = 'até ' . money((float)$s['max_price']);
      if ($s['free_shipping_only']) $flt[] = 'frete grátis';
      if ($s['full_only']) $flt[] = 'FULL';
      if ($s['exclude_words']) $flt[] = 'sem: ' . mb_strimwidth($s['exclude_words'], 0, 30, '…');
    ?>
      <tr class="<?= $s['active'] ? '' : 'off' ?>">
        <td><a href="?id=<?= (int)$s['id'] ?>"><b><?= e($s['name']) ?></b></a>
          <div class="muted small"><?= $s['type'] === 'bestsellers' ? 'Mais vendidos · ' . e($s['ml_category_id']) : 'Busca · “' . e($s['query']) . '”' . ($s['ml_category_id'] ? ' em ' . e($s['ml_category_id']) : '') ?>
            · a cada <?= (int)$s['every_hours'] ?>h · até <?= (int)$s['max_items'] ?></div></td>
        <td class="small"><?= e(implode(' · ', $flt) ?: '—') ?></td>
        <td class="small"><?= e($s['cat_name'] ?? '— sem categoria —') ?><br>
          <em class="tag <?= $s['auto_publish'] ? 'tag-ok' : 'tag-warn' ?>"><?= $s['auto_publish'] ? 'publica direto' : 'revisão manual' ?></em></td>
        <td class="small"><?= $s['last_run_at'] ? e(date('d/m H:i', strtotime($s['last_run_at']))) : 'nunca' ?>
          <div class="muted"><?= e((string)$s['last_status']) ?></div></td>
        <td><?= $counts[(int)$s['id']] ?? 0 ?></td>
        <td class="nowrap">
          <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
            <button class="btn btn-sm btn-primary" name="action" value="run">Rodar</button>
            <button class="btn btn-sm" name="action" value="toggle"><?= $s['active'] ? 'Pausar' : 'Ativar' ?></button>
          </form>
          <form method="post" style="display:inline" data-confirm="Excluir esta fonte? Os produtos já coletados continuam no site.">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
            <button class="btn btn-sm btn-danger" name="action" value="delete">Excluir</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php elseif (!$showForm): ?>
  <div class="card empty">Nenhuma fonte ainda. <a href="?novo=1">Crie a primeira</a> — por exemplo, os mais vendidos de Casa ou uma busca por "air fryer" com 20% de desconto mínimo.</div>
<?php endif; ?>

<?php if ($log): ?>
<div class="card">
  <h2>Últimas execuções</h2>
  <table class="tbl">
    <thead><tr><th>Quando</th><th>Fonte</th><th>Encontrados</th><th>Novos</th><th>Atualizados</th><th>Fora dos filtros</th><th>Erros</th></tr></thead>
    <tbody>
    <?php foreach ($log as $l): ?>
      <tr>
        <td class="nowrap small"><?= e(date('d/m H:i', strtotime($l['started_at']))) ?></td>
        <td><?= e($l['source_name'] ?? '(excluída)') ?></td>
        <td><?= (int)$l['found'] ?></td><td><?= (int)$l['created'] ?></td>
        <td><?= (int)$l['updated'] ?></td><td><?= (int)$l['skipped'] ?></td>
        <td class="small"><?= $l['errors'] ? '<em class="tag tag-warn">' . e(mb_strimwidth($l['errors'], 0, 90, '…')) . '</em>' : '—' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif;
admin_footer();
