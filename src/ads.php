<?php
declare(strict_types=1);

/**
 * Espaços de anúncio do Google AdSense na home.
 * Os IDs são configurados em Admin › Configurações › Google AdSense.
 * Sem configuração, o espaço some. Para ver como fica: ?ads=teste (banners de exemplo) ou ?ads=espacos (onde fica cada espaço).
 */
const AD_SLOTS = [
    'ad_slot_duplo_1' => ['Dois banners lado a lado — esquerdo', '≈ 580×130'],
    'ad_slot_duplo_2' => ['Dois banners lado a lado — direito', '≈ 580×130'],
    'ad_slot_largo'   => ['Banner largo', '≈ 1170×280'],
    'ad_slot_trio_1'  => ['Painel com 3 cards — 1º', '≈ 360×250'],
    'ad_slot_trio_2'  => ['Painel com 3 cards — 2º', '≈ 360×250'],
    'ad_slot_trio_3'  => ['Painel com 3 cards — 3º', '≈ 360×250'],
    'ad_slot_topo'    => ['Topo do site, à direita da busca', '320×50 fixo — "Banner para celular"'],
];

function adsense_client(): ?string
{
    $c = trim((string)setting('adsense_client'));
    return preg_match('/^ca-pub-\d{10,20}$/', $c) ? $c : null;
}

function ads_preview(): bool
{
    if (session_status() === PHP_SESSION_NONE && isset($_COOKIE[session_name()]) && !headers_sent()) @session_start();
    return function_exists('is_admin') && is_admin();
}

/**
 * Banners de teste (marcas fictícias) para ver o layout antes de configurar o AdSense.
 * Só aparecem com ?ads=teste na URL — para qualquer pessoa, logada ou não. Sem o parâmetro, ninguém vê.
 */
function ads_demo(): bool
{
    return ($_GET['ads'] ?? '') === 'teste';
}

/** Moldura tracejada mostrando onde fica cada espaço: só com ?ads=espacos e para o admin logado. */
function ads_slots_preview(): bool
{
    return ($_GET['ads'] ?? '') === 'espacos' && ads_preview();
}

/** Script do AdSense para o <head> (só quando há ID de editor configurado). */
function adsense_head(): string
{
    $c = adsense_client();
    return $c ? '<script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=' . e($c) . '" crossorigin="anonymous"></script>' : '';
}

/** HTML de um espaço de anúncio, ou '' quando não há nada para mostrar. */
function ad_unit(string $key): string
{
    $client = adsense_client();
    $slot = trim((string)setting($key));
    if ($client && preg_match('/^\d{6,20}$/', $slot)) {
        return '<div class="ad-box"><span class="ad-tag">Publicidade</span>'
            . '<ins class="adsbygoogle" style="display:block;width:100%;height:100%" data-ad-client="' . e($client) . '" data-ad-slot="' . e($slot) . '"></ins>'
            . '<script>(adsbygoogle = window.adsbygoogle || []).push({});</script></div>';
    }
    if (ads_demo()) {
        $file = ['ad_slot_duplo_1' => 'duplo-1', 'ad_slot_duplo_2' => 'duplo-2', 'ad_slot_largo' => 'largo',
                 'ad_slot_trio_1' => 'trio-1', 'ad_slot_trio_2' => 'trio-2', 'ad_slot_trio_3' => 'trio-3'][$key] ?? null;
        if ($file) {
            return '<div class="ad-box ad-demo"><span class="ad-tag">Publicidade · teste</span>'
                . '<img src="' . e(url('assets/ads-teste/' . $file . '.svg')) . '" alt="Banner de teste" loading="lazy"></div>';
        }
    }
    if (ads_slots_preview()) {
        [$name, $size] = AD_SLOTS[$key] ?? [$key, ''];
        return '<a class="ad-box ad-empty" href="' . e(url('admin/configuracoes.php#adsense')) . '"><span>Espaço para anúncio Google'
            . '<small>' . e($name) . ' · ' . e($size) . '<br>Configure em Admin › Configurações (só você vê este aviso, com ?ads=espacos)</small></span></a>';
    }
    return '';
}

/** Seção com 2 banners lado a lado. */
function ad_section_duplo(): void
{
    $a = ad_unit('ad_slot_duplo_1'); $b = ad_unit('ad_slot_duplo_2');
    if ($a === '' && $b === '') return;
    echo '<section class="ads ads-duplo">' . $a . $b . '</section>';
}

/** Seção com 1 banner largo. */
function ad_section_largo(): void
{
    $a = ad_unit('ad_slot_largo');
    if ($a === '') return;
    echo '<section class="ads ads-largo">' . $a . '</section>';
}

/** Painel branco com título e 3 cards de anúncio. */
function ad_section_trio(): void
{
    $units = array_filter([ad_unit('ad_slot_trio_1'), ad_unit('ad_slot_trio_2'), ad_unit('ad_slot_trio_3')]);
    if (!$units) return;
    $title = setting('ads_trio_title', 'Recomendados para você');
    echo '<section class="panel ads-trio"><div class="panel-head"><h2>' . e($title) . '</h2></div>'
        . '<div class="ads-trio-row">' . implode('', $units) . '</div></section>';
}

/**
 * Anúncio do topo, à direita da busca: bloco de tamanho FIXO 320×50 (no AdSense: Anúncio display › Fixo › 320×50).
 * Fica onde era o selo "Achados conferidos todo dia". Some em telas menores que 900px.
 */
function ad_header(): string
{
    $client = adsense_client();
    $slot = trim((string)setting('ad_slot_topo'));
    if ($client && preg_match('/^\d{6,20}$/', $slot)) {
        return '<ins class="adsbygoogle" style="display:inline-block;width:320px;height:50px" data-ad-client="' . e($client)
            . '" data-ad-slot="' . e($slot) . '"></ins>'
            // só pede o anúncio quando o espaço está visível (evita erro do AdSense em tela pequena)
            . '<script>if (window.matchMedia("(min-width:900px)").matches) (adsbygoogle = window.adsbygoogle || []).push({});</script>';
    }
    if (ads_demo()) {
        return '<img src="' . e(url('assets/ads-teste/topo.svg')) . '" width="320" height="50" alt="Banner de teste">';
    }
    if (ads_slots_preview()) {
        return '<a class="top-ad-empty" href="' . e(url('admin/configuracoes.php#adsense')) . '">Anúncio Google 320×50'
            . '<small>configure em Admin › Configurações (só você vê)</small></a>';
    }
    return '';
}
