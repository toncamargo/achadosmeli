<?php
require __DIR__ . '/src/bootstrap.php';
header('Content-Type: application/xml; charset=utf-8');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
echo '<url><loc>' . e(url('')) . '</loc><changefreq>hourly</changefreq></url>' . "\n";
foreach (categories() as $c) {
    echo '<url><loc>' . e(url('?c=' . $c['slug'])) . '</loc><changefreq>daily</changefreq></url>' . "\n";
}
echo '<url><loc>' . e(url('politica-de-privacidade.php')) . '</loc><changefreq>yearly</changefreq></url>' . "\n";
echo '</urlset>';
