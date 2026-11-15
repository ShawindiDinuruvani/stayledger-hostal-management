<?php
// backend/index.php
require_once 'db.php'; // Your Neon PDO connection

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true) ?? [];

// ---------------------------------------------------------
// GROUP ROUTER: Send traffic to the correct Controller
// ---------------------------------------------------------

// 1. Nimsara's Module: Room Allocations
if (strpos($uri, '/api/allocations') === 0) {
    require_once 'app/Http/Controllers/RoomAllocationController.php';
    $controller = new RoomAllocationController($pdo);
    $controller->handleRequest($method, $uri, $input);
    exit;
}

// 2. Teammate's Module: Kitchen Expenses
if (strpos($uri, '/api/kitchens') === 0) {
    require_once 'app/Http/Controllers/SharedKitchenController.php';
    $controller = new SharedKitchenController($pdo);
    $controller->handleRequest($method, $uri, $input);
    exit;
}

// 3. Hostel Expenses
if (strpos($uri, '/api/expenses') === 0) {
    require_once 'app/Http/Controllers/ExpenseController.php';
    $controller = new ExpenseController($pdo);
    $controller->handleRequest($method, $uri, $input);
    exit;
}

// If no route matches
http_response_code(404);
echo json_encode(['message' => 'API Endpoint not found in router']);