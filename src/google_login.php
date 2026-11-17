<?php
declare(strict_types=1);

/**
 * Login do painel com Google (OAuth 2.0 / OpenID Connect), sem bibliotecas externas.
 *
 * Usa as chaves do .env:
 *   GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET  → app OAuth no Google Cloud
 *   BRAIN_EMAILS (ou ADMIN_EMAILS)          → e-mails que podem entrar, separados por vírgula
 *
 * No Google Cloud, o "URI de redirecionamento autorizado" precisa ser exatamente google_redirect_uri().
 */

function google_client_id(): string { return trim((string)env('GOOGLE_CLIENT_ID', '')); }
function google_client_secret(): string { return trim((string)env('GOOGLE_CLIENT_SECRET', '')); }

/** E-mails liberados para o painel (minúsculos). */
function google_allowed_emails(): array
{
    $raw = (string)(env('ADMIN_EMAILS', '') ?: env('BRAIN_EMAILS', ''));
    return array_values(array_filter(array_map(fn($e) => strtolower(trim($e)), explode(',', $raw))));
}

function google_configured(): bool
{
    return google_client_id() !== '' && google_client_secret() !== '' && google_allowed_emails();
}

function google_redirect_uri(): string
{
    return url('admin/login.php');
}

/** Monta o link para a tela de escolha de conta do Google. */
function google_auth_url(): string
{
    $_SESSION['google_state'] = bin2hex(random_bytes(16));
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id' => google_client_id(),
        'redirect_uri' => google_redirect_uri(),
        'response_type' => 'code',
        'scope' => 'openid email profile',
        'state' => $_SESSION['google_state'],
        'prompt' => 'select_account',
        'access_type' => 'online',
    ]);
}

/** Requisição HTTP simples (cURL) que devolve o JSON decodificado. */
function google_http(string $url, ?array $post = null, ?string $bearer = null): array
{
    $ch = curl_init($url);
    $headers = ['Accept: application/json'];
    if ($bearer) $headers[] = 'Authorization: Bearer ' . $bearer;
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false) throw new RuntimeException('Não foi possível falar com o Google (' . $err . '). Tente de novo.');
    $data = json_decode((string)$body, true);
    return is_array($data) ? $data : [];
}

/**
 * Finaliza o login depois que o Google devolve ?code=…&state=…
 * Retorna ['email','name','picture'] ou lança RuntimeException com uma mensagem amigável.
 */
function google_finish_login(string $code, string $state): array
{
    $expected = (string)($_SESSION['google_state'] ?? '');
    unset($_SESSION['google_state']);
    if ($expected === '' || !hash_equals($expected, $state)) {
        throw new RuntimeException('A sessão de login expirou. Clique em "Entrar com Google" de novo.');
    }

    $token = google_http('https://oauth2.googleapis.com/token', [
        'code' => $code,
        'client_id' => google_client_id(),
        'client_secret' => google_client_secret(),
        'redirect_uri' => google_redirect_uri(),
        'grant_type' => 'authorization_code',
    ]);
    if (empty($token['access_token'])) {
        $why = (string)($token['error_description'] ?? $token['error'] ?? 'resposta inválida');
        throw new RuntimeException('O Google recusou o login: ' . $why . '.');
    }

    $user = google_http('https://openidconnect.googleapis.com/v1/userinfo', null, (string)$token['access_token']);
    $email = strtolower(trim((string)($user['email'] ?? '')));
    if ($email === '' || empty($user['email_verified'])) {
        throw new RuntimeException('Não foi possível confirmar o e-mail dessa conta Google.');
    }
    if (!in_array($email, google_allowed_emails(), true)) {
        throw new RuntimeException('A conta ' . $email . ' não tem acesso a este painel.');
    }

    return [
        'email' => $email,
        'name' => (string)($user['name'] ?? $email),
        'picture' => (string)($user['picture'] ?? ''),
    ];
}
