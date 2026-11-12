<?php

class RoomAllocationController {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function handleRequest($method, $uri, $input) {
        // 1. GET Dashboard Data
        if ($method === 'GET' && $uri === '/api/allocations/dashboard') {
            $rooms = $this->pdo->query('SELECT * FROM vw_room_occupancy ORDER BY room_num_int ASC')->fetchAll();

            $allocations = $this->pdo->query("
                SELECT 
                    ra.allocation_id, ra.allocation_code AS \"allocId\",
                    res.resident_id, res.resident_code AS \"residentCode\", res.full_name AS \"residentName\",
                    rm.room_id, rm.room_number AS room, fl.floor_name AS floor,
                    ra.start_date AS \"startDate\", ra.end_date AS \"endDate\",
                    ra.status, ra.remarks
                FROM room_allocations ra
                INNER JOIN residents res ON ra.resident_id = res.resident_id
                INNER JOIN rooms rm ON ra.room_id = rm.room_id
                INNER JOIN floors fl ON rm.floor_id = fl.floor_id
                ORDER BY ra.allocation_id DESC
            ")->fetchAll();

            $residents = $this->pdo->query("
                SELECT 
                    r.resident_id, r.resident_code, r.full_name,
                    CASE WHEN EXISTS (
                        SELECT 1 FROM room_allocations ra 
                        WHERE ra.resident_id = r.resident_id AND ra.status = 'Active'
                    ) THEN 1 ELSE 0 END AS is_allocated
                FROM residents r
                WHERE r.status = 'ACTIVE'
                ORDER BY r.full_name ASC
            ")->fetchAll();

            echo json_encode(['rooms' => $rooms, 'allocations' => $allocations, 'residents' => $residents]);
            exit;
        }

        // 2. POST Allocate Room
        if ($method === 'POST' && $uri === '/api/allocations') {
            try {
                if (empty($input['resident_id']) || empty($input['room_id']) || empty($input['start_date'])) {
                    throw new Exception('Resident, target room, and start date are required.');
                }
                $this->pdo->beginTransaction();
                
                $stmtRoom = $this->pdo->prepare('SELECT * FROM rooms WHERE room_id = ? FOR UPDATE');
                $stmtRoom->execute([$input['room_id']]);
                $room = $stmtRoom->fetch();

                if (!$room) throw new Exception('Target room not found.');
                if ($room['status'] === 'MAINTENANCE') throw new Exception("Room {$room['room_number']} is under maintenance.");

                $stmtOcc = $this->pdo->prepare("SELECT COUNT(*) FROM room_allocations WHERE room_id = ? AND status = 'Active'");
                $stmtOcc->execute([$input['room_id']]);
                if ((int)$stmtOcc->fetchColumn() >= (int)$room['capacity']) {
                    throw new Exception("Room {$room['room_number']} is full.");
                }

                $maxId = (int) $this->pdo->query('SELECT COALESCE(MAX(allocation_id), 0) FROM room_allocations')->fetchColumn();
                $code = 'ALLOC-' . (100 + $maxId + 1);

                $stmtIns = $this->pdo->prepare("INSERT INTO room_allocations (allocation_code, resident_id, room_id, start_date, end_date, status, remarks) VALUES (?, ?, ?, ?, NULL, 'Active', ?)");
                $stmtIns->execute([$code, $input['resident_id'], $input['room_id'], $input['start_date'], $input['remarks'] ?? '']);

                $this->pdo->commit();
                http_response_code(201);
                echo json_encode(['message' => "Allocated to Room {$room['room_number']} ({$code})."]);
            } catch (Exception $e) {
                if ($this->pdo->inTransaction()) $this->pdo->rollBack();
                http_response_code(422);
                echo json_encode(['message' => $e->getMessage()]);
            }
            exit;
        }

        // 3. POST Transfer
        if ($method === 'POST' && preg_match('#^/api/allocations/(\d+)/transfer$#', $uri, $m)) {
            $allocId = (int) $m[1];
            try {
                $this->pdo->beginTransaction();
                $stmtCur = $this->pdo->prepare("SELECT * FROM room_allocations WHERE allocation_id = ? AND status = 'Active' FOR UPDATE");
                $stmtCur->execute([$allocId]);
                $currentAlloc = $stmtCur->fetch();

                if (!$currentAlloc) throw new Exception('Active allocation not found.');

                $targetRoomId = (int) $input['target_room_id'];
                $oldRoomId = (int) $currentAlloc['room_id'];

                if ($targetRoomId === $oldRoomId) throw new Exception('Target room must be different.');
                
                $lockIds = [$oldRoomId, $targetRoomId];
                sort($lockIds);
                $stmtLock = $this->pdo->prepare('SELECT * FROM rooms WHERE room_id IN (?, ?) ORDER BY room_id FOR UPDATE');
                $stmtLock->execute($lockIds);

                $stmtTarget = $this->pdo->prepare('SELECT * FROM rooms WHERE room_id = ?');
                $stmtTarget->execute([$targetRoomId]);
                $targetRoom = $stmtTarget->fetch();

                $stmtOld = $this->pdo->prepare('SELECT * FROM rooms WHERE room_id = ?');
                $stmtOld->execute([$oldRoomId]);
                $oldRoom = $stmtOld->fetch();

                $stmtOcc = $this->pdo->prepare("SELECT COUNT(*) FROM room_allocations WHERE room_id = ? AND status = 'Active'");
                $stmtOcc->execute([$targetRoomId]);
                if ((int)$stmtOcc->fetchColumn() >= (int)$targetRoom['capacity']) throw new Exception("Target room is full.");

                $stmtUpd = $this->pdo->prepare("UPDATE room_allocations SET end_date = ?, status = 'Transferred', remarks = ?, updated_at = CURRENT_TIMESTAMP WHERE allocation_id = ?");
                $stmtUpd->execute([$input['transfer_date'], "Transferred to Room {$targetRoom['room_number']}: {$input['reason']}", $allocId]);

                $maxId = (int) $this->pdo->query('SELECT COALESCE(MAX(allocation_id), 0) FROM room_allocations')->fetchColumn();
                $stmtIns = $this->pdo->prepare("INSERT INTO room_allocations (allocation_code, resident_id, room_id, start_date, end_date, status, remarks) VALUES (?, ?, ?, ?, NULL, 'Active', ?)");
                $stmtIns->execute(['ALLOC-' . (100 + $maxId + 1), $currentAlloc['resident_id'], $targetRoomId, $input['transfer_date'], "Transferred from Room {$oldRoom['room_number']}: {$input['reason']}"]);

                $this->pdo->commit();
                echo json_encode(['message' => "Transferred to Room {$targetRoom['room_number']}."]);
            } catch (Exception $e) {
                if ($this->pdo->inTransaction()) $this->pdo->rollBack();
                http_response_code(422);
                echo json_encode(['message' => $e->getMessage()]);
            }
            exit;
        }

        // 4. POST Checkout
        if ($method === 'POST' && preg_match('#^/api/allocations/(\d+)/checkout$#', $uri, $m)) {
            $allocId = (int) $m[1];
            try {
                $this->pdo->beginTransaction();
                $stmtCur = $this->pdo->prepare("SELECT * FROM room_allocations WHERE allocation_id = ? AND status = 'Active' FOR UPDATE");
                $stmtCur->execute([$allocId]);
                if (!$stmtCur->fetch()) throw new Exception('Active allocation not found.');

                $stmtUpd = $this->pdo->prepare("UPDATE room_allocations SET end_date = ?, status = 'Checked Out', remarks = ?, updated_at = CURRENT_TIMESTAMP WHERE allocation_id = ?");
                $stmtUpd->execute([$input['checkout_date'], $input['remarks'], $allocId]);

                $this->pdo->commit();
                echo json_encode(['message' => 'Checkout committed: Bed released.']);
            } catch (Exception $e) {
                if ($this->pdo->inTransaction()) $this->pdo->rollBack();
                http_response_code(422);
                echo json_encode(['message' => $e->getMessage()]);
            }
            exit;
        }
    }
}