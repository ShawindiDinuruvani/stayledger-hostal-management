<?php
// backend/db.php

$DATABASE_URL = getenv('DATABASE_URL') ?: 'postgresql://stayledger_db_owner:npg_9iAEmaGHeKo1@ep-lingering-pine-b3dk5yfn-pooler.c-4.ap-southeast-1.aws.neon.tech:5432/stayledger_db?sslmode=require';

try {
    $dbParts = parse_url($DATABASE_URL);
    $host = $dbParts['host'];
    $port = $dbParts['port'] ?? 5432;
    $user = $dbParts['user'];
    
    $pass = isset($dbParts['pass']) ? urldecode($dbParts['pass']) : ''; 
    $dbname = ltrim($dbParts['path'], '/');

   
    $dsn = "pgsql:host={$host};port={$port};dbname={$dbname};sslmode=require";

    
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => true,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['message' => 'Neon DB Error: ' . $e->getMessage()]);
    exit;
}
