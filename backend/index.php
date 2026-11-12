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

// 1. GET Dashboard Data (Live from MySQL)
if ($method === 'GET' && $uri === '/api/allocations/dashboard') {
    $rooms = $pdo->query('SELECT * FROM vw_room_occupancy ORDER BY room_num_int ASC')->fetchAll();

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
            EXISTS(
                SELECT 1 FROM room_allocations ra 
                WHERE ra.resident_id = r.resident_id AND ra.status = 'Active'
            ) AS is_allocated
        FROM residents r
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

// 2. POST Allocate Room (ACID Transaction + Row Locking)
if ($method === 'POST' && $uri === '/api/allocations') {
    try {
        if (empty($input['resident_id']) || empty($input['room_id']) || empty($input['start_date'])) {
            throw new Exception('Resident, target room, and start date are required.');
        }

        $pdo->beginTransaction();

        $stmtRoom = $pdo->prepare('SELECT * FROM rooms WHERE room_id = ? FOR UPDATE');
        $stmtRoom->execute([$input['room_id']]);
        $room = $stmtRoom->fetch();

        if (!$room) {
            throw new Exception('Target room not found.');
        }
        if ($room['status'] === 'MAINTENANCE') {
            throw new Exception("Room {$room['room_number']} is currently under maintenance.");
        }

        $stmtOcc = $pdo->prepare("SELECT COUNT(*) FROM room_allocations WHERE room_id = ? AND status = 'Active' FOR UPDATE");
        $stmtOcc->execute([$input['room_id']]);
        $occupied = (int) $stmtOcc->fetchColumn();

        if ($occupied >= (int) $room['capacity']) {
            throw new Exception("Capacity Error: Room {$room['room_number']} is full ({$occupied}/{$room['capacity']}).");
        }

        // Properly generates ALLOC-101, ALLOC-102, ALLOC-103...
        $maxId = (int) $pdo->query('SELECT COALESCE(MAX(allocation_id), 0) FROM room_allocations FOR UPDATE')->fetchColumn();
        $code = 'ALLOC-' . (100 + $maxId + 1);

        $stmtIns = $pdo->prepare("
            INSERT INTO room_allocations (allocation_code, resident_id, room_id, start_date, end_date, status, remarks)
            VALUES (?, ?, ?, ?, NULL, 'Active', ?)
        ");
        $stmtIns->execute([
            $code,
            $input['resident_id'],
            $input['room_id'],
            $input['start_date'],
            !empty($input['remarks']) ? trim($input['remarks']) : 'Standard bed allocation'
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

// 3. POST Atomic Room Transfer
if ($method === 'POST' && preg_match('#^/api/allocations/(\d+)/transfer$#', $uri, $m)) {
    $allocId = (int) $m[1];
    try {
        if (empty($input['target_room_id']) || empty($input['transfer_date']) || empty(trim($input['reason'] ?? ''))) {
            throw new Exception('Destination room, transfer date, and reason are required.');
        }

        $pdo->beginTransaction();

        $stmtCur = $pdo->prepare("SELECT * FROM room_allocations WHERE allocation_id = ? AND status = 'Active' FOR UPDATE");
        $stmtCur->execute([$allocId]);
        $currentAlloc = $stmtCur->fetch();

        if (!$currentAlloc) {
            throw new Exception('Active allocation not found.');
        }

        $targetRoomId = (int) $input['target_room_id'];
        $oldRoomId = (int) $currentAlloc['room_id'];

        if ($targetRoomId === $oldRoomId) {
            throw new Exception('Target room must be different from current room.');
        }

        if ($input['transfer_date'] < $currentAlloc['start_date']) {
            throw new Exception('Transfer date cannot be earlier than original check-in start date.');
        }

        // Lock both rooms in ascending ID order to prevent deadlocks
        $lockIds = [$oldRoomId, $targetRoomId];
        sort($lockIds);
        $stmtLock = $pdo->prepare('SELECT * FROM rooms WHERE room_id IN (?, ?) FOR UPDATE');
        $stmtLock->execute($lockIds);

        $stmtTarget = $pdo->prepare('SELECT * FROM rooms WHERE room_id = ?');
        $stmtTarget->execute([$targetRoomId]);
        $targetRoom = $stmtTarget->fetch();

        $stmtOld = $pdo->prepare('SELECT * FROM rooms WHERE room_id = ?');
        $stmtOld->execute([$oldRoomId]);
        $oldRoom = $stmtOld->fetch();

        if (!$targetRoom) {
            throw new Exception('Destination room not found.');
        }
        if ($targetRoom['status'] === 'MAINTENANCE') {
            throw new Exception("Room {$targetRoom['room_number']} is currently under maintenance.");
        }

        $stmtOcc = $pdo->prepare("SELECT COUNT(*) FROM room_allocations WHERE room_id = ? AND status = 'Active' FOR UPDATE");
        $stmtOcc->execute([$targetRoomId]);
        if ((int) $stmtOcc->fetchColumn() >= (int) $targetRoom['capacity']) {
            throw new Exception("Room {$targetRoom['room_number']} has no available beds.");
        }

        $reason = trim($input['reason']);

        // Step A: Close old allocation as Transferred
        $stmtUpd = $pdo->prepare("UPDATE room_allocations SET end_date = ?, status = 'Transferred', remarks = ? WHERE allocation_id = ?");
        $stmtUpd->execute([$input['transfer_date'], "Transferred to Room {$targetRoom['room_number']}: {$reason}", $allocId]);

        // Step B: Create new Active allocation in target room
        $maxId = (int) $pdo->query('SELECT COALESCE(MAX(allocation_id), 0) FROM room_allocations FOR UPDATE')->fetchColumn();
        $code = 'ALLOC-' . (100 + $maxId + 1);

        $stmtIns = $pdo->prepare("
            INSERT INTO room_allocations (allocation_code, resident_id, room_id, start_date, end_date, status, remarks) 
            VALUES (?, ?, ?, ?, NULL, 'Active', ?)
        ");
        $stmtIns->execute([
            $code,
            $currentAlloc['resident_id'],
            $targetRoomId,
            $input['transfer_date'],
            "Transferred from Room {$oldRoom['room_number']}: {$reason}"
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

// 4. POST Checkout Resident
if ($method === 'POST' && preg_match('#^/api/allocations/(\d+)/checkout$#', $uri, $m)) {
    $allocId = (int) $m[1];
    try {
        if (empty($input['checkout_date']) || empty(trim($input['remarks'] ?? ''))) {
            throw new Exception('Checkout date and clearance remarks are required.');
        }

        $pdo->beginTransaction();

        $stmtCur = $pdo->prepare("SELECT * FROM room_allocations WHERE allocation_id = ? AND status = 'Active' FOR UPDATE");
        $stmtCur->execute([$allocId]);
        $alloc = $stmtCur->fetch();

        if (!$alloc) {
            throw new Exception('Active allocation not found.');
        }

        if ($input['checkout_date'] < $alloc['start_date']) {
            throw new Exception('Checkout date cannot be earlier than check-in start date.');
        }

        $stmtUpd = $pdo->prepare("UPDATE room_allocations SET end_date = ?, status = 'Checked Out', remarks = ? WHERE allocation_id = ?");
        $stmtUpd->execute([$input['checkout_date'], trim($input['remarks']), $allocId]);

        $pdo->commit();
        echo json_encode(['message' => 'Checkout committed in MySQL: Bed released.']);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(422);
        echo json_encode(['message' => $e->getMessage()]);
    }
    exit;
}

http_response_code(404);
echo json_encode(['message' => 'Endpoint not found']);