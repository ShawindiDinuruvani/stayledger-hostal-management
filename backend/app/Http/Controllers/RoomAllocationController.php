<?php

namespace App\Http\Controllers;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PDO;

class RoomAllocationController extends Controller
{
    private function pdo(): PDO
    {
        return DB::connection()->getPdo();
    }

    // 1. GET Dashboard Data
    public function dashboard()
    {
        $pdo = $this->pdo();

        $rooms = $pdo->query('SELECT * FROM vw_room_occupancy ORDER BY room_num_int ASC')
            ->fetchAll(PDO::FETCH_ASSOC);

        $allocations = $pdo->query("
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
        ")->fetchAll(PDO::FETCH_ASSOC);

        $residents = $pdo->query("
            SELECT 
                r.resident_id, r.resident_code, r.full_name,
                CASE WHEN EXISTS (
                    SELECT 1 FROM room_allocations ra 
                    WHERE ra.resident_id = r.resident_id AND ra.status = 'Active'
                ) THEN 1 ELSE 0 END AS is_allocated
            FROM residents r
            WHERE r.status = 'ACTIVE'
            ORDER BY r.full_name ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        return response()->json([
            'rooms' => $rooms,
            'allocations' => $allocations,
            'residents' => $residents,
        ]);
    }

    // 2. POST Allocate Room
    public function store(Request $request)
    {
        $pdo = $this->pdo();

        try {
            if (empty($request->input('resident_id')) || empty($request->input('room_id')) || empty($request->input('start_date'))) {
                throw new Exception('Resident, target room, and start date are required.');
            }

            $pdo->beginTransaction();

            $stmtRoom = $pdo->prepare('SELECT * FROM rooms WHERE room_id = ? FOR UPDATE');
            $stmtRoom->execute([$request->input('room_id')]);
            $room = $stmtRoom->fetch(PDO::FETCH_ASSOC);

            if (!$room) {
                throw new Exception('Target room not found.');
            }
            if ($room['status'] === 'MAINTENANCE') {
                throw new Exception("Room {$room['room_number']} is under maintenance.");
            }

            $stmtOcc = $pdo->prepare("SELECT COUNT(*) FROM room_allocations WHERE room_id = ? AND status = 'Active'");
            $stmtOcc->execute([$request->input('room_id')]);
            if ((int) $stmtOcc->fetchColumn() >= (int) $room['capacity']) {
                throw new Exception("Room {$room['room_number']} is full.");
            }

            $maxId = (int) $pdo->query('SELECT COALESCE(MAX(allocation_id), 0) FROM room_allocations')->fetchColumn();
            $code = 'ALLOC-' . (100 + $maxId + 1);

            $stmtIns = $pdo->prepare("INSERT INTO room_allocations (allocation_code, resident_id, room_id, start_date, end_date, status, remarks) VALUES (?, ?, ?, ?, NULL, 'Active', ?)");
            $stmtIns->execute([
                $code,
                $request->input('resident_id'),
                $request->input('room_id'),
                $request->input('start_date'),
                $request->input('remarks', ''),
            ]);

            $pdo->commit();

            return response()->json([
                'message' => "Allocated to Room {$room['room_number']} ({$code}).",
            ], 201);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    // 3. POST Transfer
    public function transfer(Request $request, int $id)
    {
        $pdo = $this->pdo();

        try {
            $pdo->beginTransaction();

            $stmtCur = $pdo->prepare("SELECT * FROM room_allocations WHERE allocation_id = ? AND status = 'Active' FOR UPDATE");
            $stmtCur->execute([$id]);
            $currentAlloc = $stmtCur->fetch(PDO::FETCH_ASSOC);

            if (!$currentAlloc) {
                throw new Exception('Active allocation not found.');
            }

            $targetRoomId = (int) $request->input('target_room_id');
            $oldRoomId = (int) $currentAlloc['room_id'];

            if ($targetRoomId === $oldRoomId) {
                throw new Exception('Target room must be different.');
            }

            $lockIds = [$oldRoomId, $targetRoomId];
            sort($lockIds);
            $stmtLock = $pdo->prepare('SELECT * FROM rooms WHERE room_id IN (?, ?) ORDER BY room_id FOR UPDATE');
            $stmtLock->execute($lockIds);

            $stmtTarget = $pdo->prepare('SELECT * FROM rooms WHERE room_id = ?');
            $stmtTarget->execute([$targetRoomId]);
            $targetRoom = $stmtTarget->fetch(PDO::FETCH_ASSOC);

            $stmtOld = $pdo->prepare('SELECT * FROM rooms WHERE room_id = ?');
            $stmtOld->execute([$oldRoomId]);
            $oldRoom = $stmtOld->fetch(PDO::FETCH_ASSOC);

            $stmtOcc = $pdo->prepare("SELECT COUNT(*) FROM room_allocations WHERE room_id = ? AND status = 'Active'");
            $stmtOcc->execute([$targetRoomId]);
            if ((int) $stmtOcc->fetchColumn() >= (int) $targetRoom['capacity']) {
                throw new Exception('Target room is full.');
            }

            $stmtUpd = $pdo->prepare("UPDATE room_allocations SET end_date = ?, status = 'Transferred', remarks = ?, updated_at = CURRENT_TIMESTAMP WHERE allocation_id = ?");
            $stmtUpd->execute([
                $request->input('transfer_date'),
                "Transferred to Room {$targetRoom['room_number']}: {$request->input('reason')}",
                $id,
            ]);

            $maxId = (int) $pdo->query('SELECT COALESCE(MAX(allocation_id), 0) FROM room_allocations')->fetchColumn();
            $stmtIns = $pdo->prepare("INSERT INTO room_allocations (allocation_code, resident_id, room_id, start_date, end_date, status, remarks) VALUES (?, ?, ?, ?, NULL, 'Active', ?)");
            $stmtIns->execute([
                'ALLOC-' . (100 + $maxId + 1),
                $currentAlloc['resident_id'],
                $targetRoomId,
                $request->input('transfer_date'),
                "Transferred from Room {$oldRoom['room_number']}: {$request->input('reason')}",
            ]);

            $pdo->commit();

            return response()->json([
                'message' => "Transferred to Room {$targetRoom['room_number']}.",
            ]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    // 4. POST Checkout
    public function checkout(Request $request, int $id)
    {
        $pdo = $this->pdo();

        try {
            $pdo->beginTransaction();

            $stmtCur = $pdo->prepare("SELECT * FROM room_allocations WHERE allocation_id = ? AND status = 'Active' FOR UPDATE");
            $stmtCur->execute([$id]);
            if (!$stmtCur->fetch(PDO::FETCH_ASSOC)) {
                throw new Exception('Active allocation not found.');
            }

            $stmtUpd = $pdo->prepare("UPDATE room_allocations SET end_date = ?, status = 'Checked Out', remarks = ?, updated_at = CURRENT_TIMESTAMP WHERE allocation_id = ?");
            $stmtUpd->execute([
                $request->input('checkout_date'),
                $request->input('remarks'),
                $id,
            ]);

            $pdo->commit();

            return response()->json(['message' => 'Checkout committed: Bed released.']);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}