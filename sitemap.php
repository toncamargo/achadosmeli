<?php
require __DIR__ . '/src/bootstrap.php';
header('Content-Type: application/xml; charset=utf-8');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
echo '<url><loc>' . e(url('')) . '</loc><changefreq>hourly</changefreq></url>' . "\n";
foreach (categories() as $c) {
    echo '<url><loc>' . e(url('?c=' . $c['slug'])) . '</loc><changefreq>daily</changefreq></url>' . "\n";
}
foreach (db()->query('SELECT slug, updated_at FROM products WHERE active = 1 ORDER BY updated_at DESC LIMIT 45000') as $p) {
    echo '<url><loc>' . e(product_url($p)) . '</loc><lastmod>' . e(substr((string)$p['updated_at'], 0, 10)) . '</lastmod></url>' . "\n";
}
echo '</urlset>';
