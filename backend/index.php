<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    $pdo = new PDO(
        'mysql:host=127.0.0.1;port=3306;dbname=stayledger_db;charset=utf8mb4',
        'root',
        '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['message' => 'MySQL Connection Error: ' . $e->getMessage()]);
    exit;
}

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true) ?? [];

if ($method === 'GET' && $uri === '/api/allocations/dashboard') {
    $rooms = $pdo->query('SELECT * FROM vw_room_occupancy')->fetchAll();

    $allocations = $pdo->query("
        SELECT 
            ra.allocation_id,
            ra.allocation_code AS allocId,
            res.resident_id,
            res.resident_code AS residentCode,
            res.full_name AS residentName,
            rm.room_id,
            rm.room_number AS room,
            fl.floor_name AS floor,
            ra.start_date AS startDate,
            ra.end_date AS endDate,
            ra.status,
            ra.remarks
        FROM room_allocations ra
        INNER JOIN residents res ON ra.resident_id = res.resident_id
        INNER JOIN rooms rm ON ra.room_id = rm.room_id
        INNER JOIN floors fl ON rm.floor_id = fl.floor_id
        ORDER BY ra.allocation_id DESC
    ")->fetchAll();

    $residents = $pdo->query("
        SELECT 
            r.resident_id,
            r.resident_code,
            r.full_name,
            CASE WHEN ra.allocation_id IS NOT NULL THEN 1 ELSE 0 END AS is_allocated
        FROM residents r
        LEFT JOIN room_allocations ra 
            ON r.resident_id = ra.resident_id AND ra.status = 'Active'
        WHERE r.status = 'ACTIVE'
        ORDER BY r.full_name ASC
    ")->fetchAll();

    echo json_encode([
        'rooms' => $rooms,
        'allocations' => $allocations,
        'residents' => $residents,
    ]);
    exit;
}

if ($method === 'POST' && $uri === '/api/allocations') {
    try {
        $pdo->beginTransaction();

        $stmtRoom = $pdo->prepare('SELECT * FROM rooms WHERE room_id = ? FOR UPDATE');
        $stmtRoom->execute([$input['room_id']]);
        $room = $stmtRoom->fetch();

        if (!$room) throw new Exception('Target room not found.');

        $stmtOcc = $pdo->prepare("SELECT COUNT(*) FROM room_allocations WHERE room_id = ? AND status = 'Active' FOR UPDATE");
        $stmtOcc->execute([$input['room_id']]);
        $occupied = (int) $stmtOcc->fetchColumn();

        if ($occupied >= (int) $room['capacity']) {
            throw new Exception("Capacity Error: Room {$room['room_number']} is full ({$occupied}/{$room['capacity']}).");
        }

        $nextId = ((int) $pdo->query('SELECT COALESCE(MAX(allocation_id), 100) FROM room_allocations')->fetchColumn()) + 1;
        $code = 'ALLOC-' . $nextId;

        $stmtIns = $pdo->prepare("
            INSERT INTO room_allocations (allocation_code, resident_id, room_id, start_date, end_date, status, remarks)
            VALUES (?, ?, ?, ?, NULL, 'Active', ?)
        ");
        $stmtIns->execute([
            $code,
            $input['resident_id'],
            $input['room_id'],
            $input['start_date'],
            !empty($input['remarks']) ? $input['remarks'] : 'Standard bed allocation'
        ]);

        $pdo->commit();
        http_response_code(201);
        echo json_encode(['message' => "Transaction committed: Allocated to Room {$room['room_number']} ({$code})."]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(422);
        echo json_encode(['message' => $e->getMessage()]);
    }
    exit;
}

if ($method === 'POST' && preg_match('#^/api/allocations/(\d+)/transfer$#', $uri, $m)) {
    $allocId = (int) $m[1];
    try {
        $pdo->beginTransaction();

        $stmtCur = $pdo->prepare("SELECT * FROM room_allocations WHERE allocation_id = ? AND status = 'Active' FOR UPDATE");
        $stmtCur->execute([$allocId]);
        $currentAlloc = $stmtCur->fetch();

        if (!$currentAlloc) throw new Exception('Active allocation not found.');

        $targetRoomId = (int) $input['target_room_id'];
        if ((int) $currentAlloc['room_id'] === $targetRoomId) {
            throw new Exception('Destination room must be different from current room.');
        }

        $stmtTarget = $pdo->prepare('SELECT * FROM rooms WHERE room_id = ? FOR UPDATE');
        $stmtTarget->execute([$targetRoomId]);
        $targetRoom = $stmtTarget->fetch();

        $stmtOld = $pdo->prepare('SELECT * FROM rooms WHERE room_id = ? FOR UPDATE');
        $stmtOld->execute([$currentAlloc['room_id']]);
        $oldRoom = $stmtOld->fetch();

        $stmtOcc = $pdo->prepare("SELECT COUNT(*) FROM room_allocations WHERE room_id = ? AND status = 'Active' FOR UPDATE");
        $stmtOcc->execute([$targetRoomId]);
        if ((int) $stmtOcc->fetchColumn() >= (int) $targetRoom['capacity']) {
            throw new Exception("Room {$targetRoom['room_number']} has no available beds.");
        }

        $stmtUpd = $pdo->prepare("
            UPDATE room_allocations 
            SET end_date = ?, status = 'Transferred', remarks = ?
            WHERE allocation_id = ?
        ");
        $stmtUpd->execute([
            $input['transfer_date'],
            "Transferred to Room {$targetRoom['room_number']}: {$input['reason']}",
            $allocId
        ]);

        $nextId = ((int) $pdo->query('SELECT COALESCE(MAX(allocation_id), 100) FROM room_allocations')->fetchColumn()) + 1;
        $stmtIns = $pdo->prepare("
            INSERT INTO room_allocations (allocation_code, resident_id, room_id, start_date, end_date, status, remarks)
            VALUES (?, ?, ?, ?, NULL, 'Active', ?)
        ");
        $stmtIns->execute([
            'ALLOC-' . $nextId,
            $currentAlloc['resident_id'],
            $targetRoomId,
            $input['transfer_date'],
            "Transferred from Room {$oldRoom['room_number']}: {$input['reason']}"
        ]);

        $pdo->commit();
        echo json_encode(['message' => "Atomic Transfer committed: Moved from Room {$oldRoom['room_number']} to Room {$targetRoom['room_number']}."]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(422);
        echo json_encode(['message' => $e->getMessage()]);
    }
    exit;
}

if ($method === 'POST' && preg_match('#^/api/allocations/(\d+)/checkout$#', $uri, $m)) {
    $allocId = (int) $m[1];
    try {
        $pdo->beginTransaction();

        $stmtCur = $pdo->prepare("SELECT * FROM room_allocations WHERE allocation_id = ? AND status = 'Active' FOR UPDATE");
        $stmtCur->execute([$allocId]);
        $alloc = $stmtCur->fetch();

        if (!$alloc) throw new Exception('Active allocation not found.');

        if ($input['checkout_date'] < $alloc['start_date']) {
            throw new Exception('Checkout date cannot be earlier than check-in start date.');
        }

        $stmtUpd = $pdo->prepare("
            UPDATE room_allocations 
            SET end_date = ?, status = 'Checked Out', remarks = ?
            WHERE allocation_id = ?
        ");
        $stmtUpd->execute([$input['checkout_date'], $input['remarks'], $allocId]);

        $pdo->commit();
        echo json_encode(['message' => 'Checkout committed in MySQL: Bed released and vw_room_occupancy updated.']);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(422);
        echo json_encode(['message' => $e->getMessage()]);
    }
    exit;
}

http_response_code(404);
echo json_encode(['message' => 'Endpoint not found']);