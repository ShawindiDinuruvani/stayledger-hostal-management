<?php
/**
 * Backend authorization boundary for the native PHP allocation and kitchen APIs.
 *
 * The browser supplies the same Laravel session cookie used by the User Access app.
 * Every request is verified server-to-server with that app. Mutations also require
 * a valid Laravel CSRF token. No local or fallback authentication is accepted.
 */
function stayledger_require_user_access(): void
{
    $cookie = $_SERVER['HTTP_COOKIE'] ?? '';
    if ($cookie === '') {
        stayledger_deny(401, 'Sign in to StayLedger before accessing this API.');
    }

    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $requiresCsrf = !in_array($method, ['GET', 'HEAD'], true);
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    if ($requiresCsrf && $csrf === '') {
        stayledger_deny(419, 'CSRF token is required.');
    }

    if (!function_exists('curl_init')) {
        stayledger_deny(503, 'Authentication service is unavailable.');
    }

    // Never construct the identity-service URL from a request header.
    $base = rtrim(getenv('STAYLEDGER_AUTH_URL') ?: 'http://127.0.0.1:8002', '/');
    $url = $base . ($requiresCsrf ? '/integration/verify' : '/me');

    $headers = [
        'Accept: application/json',
        'Cookie: ' . str_replace(["\r", "\n"], '', $cookie),
    ];
    if ($requiresCsrf) {
        $headers[] = 'X-CSRF-TOKEN: ' . str_replace(["\r", "\n"], '', $csrf);
    }

    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CUSTOMREQUEST => $requiresCsrf ? 'POST' : 'GET',
    ]);
    $response = curl_exec($handle);
    $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);

    if ($response === false || $status === 0) {
        stayledger_deny(503, 'Authentication service is unavailable.');
    }
    if ($status === 419) {
        stayledger_deny(419, 'Session verification failed. Refresh and retry.');
    }
    if ($status !== 200) {
        stayledger_deny(401, 'Login session is invalid or expired.');
    }

    $data = json_decode($response, true);
    $user = is_array($data) ? ($data['user'] ?? null) : null;
    if (!is_array($user) || !is_numeric($user['id'] ?? null) ||
        !in_array($user['role'] ?? null, ['admin', 'staff'], true) ||
        ($user['is_active'] ?? false) !== true) {
        stayledger_deny(401, 'Authorized, active StayLedger account required.');
    }

    // Controllers may use authenticated identity for auditing and authorization.
    $GLOBALS['stayledger_auth_user'] = $user;
}

function stayledger_deny(int $status, string $message): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['message' => $message]);
    exit;
}
