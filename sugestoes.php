<?php
/**
 * Sugestões da busca (autocomplete).
 * Monta frases de 1 a 3 palavras a partir dos títulos, marcas e categorias dos produtos ativos
 * que começam com o texto digitado. Ex.: "air" → "Air fryer", "Air fryer mondial".
 * Retorna JSON: ["Air fryer", "Air fryer mondial", ...]
 */
require __DIR__ . '/src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');

function sug_norm(string $s): string
{
    $s = mb_strtolower($s, 'UTF-8');
    return strtr($s, ['á'=>'a','à'=>'a','ã'=>'a','â'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','õ'=>'o','ô'=>'o','ú'=>'u','ü'=>'u','ç'=>'c']);
}

$q = trim(preg_replace('/\s+/', ' ', (string)($_GET['q'] ?? '')));
if (mb_strlen($q) < 2 || mb_strlen($q) > 60) { echo '[]'; exit; }
$nq = sug_norm($q);
$qWords = count(explode(' ', $nq));

// Fontes: títulos, marcas e categorias
$texts = [];
$rows = db()->query("SELECT title, brand FROM products WHERE active = 1 ORDER BY clicks DESC, created_at DESC LIMIT 3000")->fetchAll();
foreach ($rows as $r) {
    $texts[] = [(string)$r['title'], 1];
    if (!empty($r['brand'])) $texts[] = [(string)$r['brand'], 2];
}
foreach (categories() as $c) $texts[] = [(string)$c['name'], 3];

$stop = ['de','da','do','das','dos','e','com','para','a','o','em','na','no','por','à','-','+','|','/'];
$scores = [];   // chave normalizada => [texto, pontos]

foreach ($texts as [$text, $weight]) {
    // quebra em palavras preservando a grafia original
    $words = preg_split('/[\s,;:()\[\]"]+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);
    $norms = array_map('sug_norm', $words);
    $n = count($words);
    for ($i = 0; $i < $n; $i++) {
        // o trecho que começa em $i precisa casar com o que foi digitado (prefixo)
        if ($i > 0 && in_array($norms[$i], $stop, true)) continue;      // não começa em "para", "de"...
        $span = implode(' ', array_slice($norms, $i, $qWords));
        if (!str_starts_with($span, $nq)) continue;
        // gera frases com 0, 1 e 2 palavras a mais depois do que foi digitado
        for ($extra = 0; $extra <= 2; $extra++) {
            $len = $qWords + $extra;
            if ($i + $len > $n) break;
            $slice = array_slice($words, $i, $len);
            $last = sug_norm(end($slice));
            if (in_array($last, $stop, true) || mb_strlen($last) < 2) continue;   // não termina em "de", "com"...
            $phrase = mb_strtolower(implode(' ', $slice), 'UTF-8');
            $phrase = mb_strtoupper(mb_substr($phrase, 0, 1), 'UTF-8') . mb_substr($phrase, 1);
            $key = sug_norm($phrase);
            $pts = $weight * (3 - $extra) + ($i === 0 ? 2 : 0);
            if (!isset($scores[$key])) $scores[$key] = [$phrase, 0];
            $scores[$key][1] += $pts;
        }
    }
}

uasort($scores, fn($a, $b) => [$b[1], mb_strlen($a[0])] <=> [$a[1], mb_strlen($b[0])]);
$out = [];
foreach ($scores as $key => [$phrase]) {
    if ($key === $nq) continue;                 // não repete exatamente o que foi digitado
    $out[] = $phrase;
    if (count($out) >= 6) break;
}
echo json_encode($out, JSON_UNESCAPED_UNICODE);
