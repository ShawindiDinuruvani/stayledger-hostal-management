
<?php
// Native PHP database connection.
// Set DATABASE_URL in the private server environment.

$databaseUrl = getenv('DATABASE_URL');

if (!is_string($databaseUrl) || $databaseUrl === '') {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'message' => 'Database connection is not configured.'
    ]);
    exit;
}

try {
    $parts = parse_url($databaseUrl);

    if (!is_array($parts) ||
        empty($parts['host']) ||
        empty($parts['user']) ||
        empty($parts['path'])) {
        throw new RuntimeException(
            'Invalid database connection configuration.'
        );
    }

    $host = $parts['host'];
    $port = (int) ($parts['port'] ?? 5432);
    $name = ltrim($parts['path'], '/');
    $user = urldecode($parts['user']);
    $password = urldecode($parts['pass'] ?? '');

    $pdo = new PDO(
        "pgsql:host={$host};port={$port};dbname={$name};sslmode=require",
        $user,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

} catch (Throwable $exception) {
    error_log('StayLedger native PHP database connection failed.');

    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode([
        'message' => 'Database service unavailable.'
    ]);
    exit;
}
