<?php
/**
 * Webhook do Instagram (comentários). URL para cadastrar na Meta:
 *   https://SEU-DOMINIO/instagram/webhook.php
 * O "Verify token" e o "Instagram app secret" ficam em Admin › Instagram.
 */
require __DIR__ . '/../src/bootstrap.php';

// 1) Verificação da URL (GET). O PHP troca "hub.mode" por "hub_mode".
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (($_GET['hub_mode'] ?? '') === 'subscribe'
        && hash_equals(Instagram::verifyToken(), (string)($_GET['hub_verify_token'] ?? ''))) {
        header('Content-Type: text/plain');
        echo (string)($_GET['hub_challenge'] ?? '');
    } else {
        http_response_code(403);
        echo 'Forbidden';
    }
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

// 2) Notificação (POST): confere a assinatura com o Instagram app secret
$raw = (string)file_get_contents('php://input');
$secret = (string)setting('ig_app_secret', '');
$sig = (string)($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
if ($secret === '' || !hash_equals('sha256=' . hash_hmac('sha256', $raw, $secret), $sig)) {
    error_log('[achadosmeli] Webhook Instagram com assinatura inválida');
    http_response_code(403);
    exit;
}

// Responde 200 na hora (a Meta exige resposta rápida) e processa em seguida
http_response_code(200);
echo 'EVENT_RECEIVED';
session_write_close();
if (function_exists('litespeed_finish_request')) litespeed_finish_request();
elseif (function_exists('fastcgi_finish_request')) fastcgi_finish_request();

$payload = json_decode($raw, true);
if (($payload['object'] ?? '') !== 'instagram') exit;

foreach ($payload['entry'] ?? [] as $entry) {
    foreach ($entry['changes'] ?? [] as $change) {
        if (($change['field'] ?? '') !== 'comments') continue;
        $v = $change['value'] ?? [];
        try {
            Instagram::handleComment([
                'id'        => (string)($v['id'] ?? ''),
                'text'      => (string)($v['text'] ?? ''),
                'username'  => (string)($v['from']['username'] ?? ''),
                'from_id'   => (string)($v['from']['id'] ?? ''),
                'media_id'  => (string)($v['media']['id'] ?? ''),
                'parent_id' => $v['parent_id'] ?? null,
                'timestamp' => null,
            ], 'webhook');
        } catch (Throwable $ex) {
            error_log('[achadosmeli] Webhook Instagram: ' . $ex->getMessage());
        }
    }
}
