<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PDO;
use PDOException;

class RoomManagementController extends Controller
{
    private function pdo(): PDO
    {
        return DB::connection()->getPdo();
    }

    public function index()
    {
        $pdo = $this->pdo();
        $floors = $pdo->query("
            SELECT fl.floor_id, fl.floor_name,
                CAST(COUNT(DISTINCT rm.room_id) AS INTEGER) AS room_count,
                CAST(COALESCE(SUM(rm.capacity), 0) AS INTEGER) AS total_capacity
            FROM floors fl
            LEFT JOIN rooms rm ON rm.floor_id = fl.floor_id
            GROUP BY fl.floor_id, fl.floor_name
            ORDER BY fl.floor_name
        ")->fetchAll(PDO::FETCH_ASSOC);

        $rooms = $pdo->query("
            SELECT rm.room_id, rm.room_number, rm.floor_id, fl.floor_name,
                rm.capacity, rm.status,
                CAST(COUNT(ra.allocation_id) AS INTEGER) AS occupied_beds,
                CAST(rm.capacity - COUNT(ra.allocation_id) AS INTEGER) AS available_beds,
                CASE
                    WHEN rm.status = 'MAINTENANCE' THEN 'Maintenance'
                    WHEN COUNT(ra.allocation_id) >= rm.capacity THEN 'Full'
                    WHEN COUNT(ra.allocation_id) > 0 THEN 'Partial'
                    ELSE 'Available'
                END AS occupancy_status
            FROM rooms rm
            INNER JOIN floors fl ON fl.floor_id = rm.floor_id
            LEFT JOIN room_allocations ra
                ON ra.room_id = rm.room_id AND ra.status = 'Active'
            GROUP BY rm.room_id, rm.room_number, rm.floor_id, fl.floor_name, rm.capacity, rm.status
            ORDER BY fl.floor_name, rm.room_number
        ")->fetchAll(PDO::FETCH_ASSOC);

        return response()->json(['floors' => $floors, 'rooms' => $rooms]);
    }

    public function storeFloor(Request $request)
    {
        try {
            $floorName = $this->floorName($request);
            $statement = $this->pdo()->prepare('INSERT INTO floors (floor_name) VALUES (?)');
            $statement->execute([$floorName]);

            return response()->json(['message' => 'Floor created.'], 201);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (PDOException $e) {
            return $this->databaseError($e, 'Could not create the floor.');
        }
    }

    public function updateFloor(Request $request, int $id)
    {
        try {
            $floorName = $this->floorName($request);
            $statement = $this->pdo()->prepare('UPDATE floors SET floor_name = ? WHERE floor_id = ?');
            $statement->execute([$floorName, $id]);

            if ($statement->rowCount() === 0 && !$this->floorExists($id)) {
                return response()->json(['message' => 'Floor not found.'], 404);
            }

            return response()->json(['message' => 'Floor updated.']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (PDOException $e) {
            return $this->databaseError($e, 'Could not update the floor.');
        }
    }

    public function destroyFloor(int $id)
    {
        try {
            $pdo = $this->pdo();
            $statement = $pdo->prepare('SELECT COUNT(*) FROM rooms WHERE floor_id = ?');
            $statement->execute([$id]);

            if (!$this->floorExists($id)) {
                return response()->json(['message' => 'Floor not found.'], 404);
            }
            if ((int) $statement->fetchColumn() > 0) {
                return response()->json(['message' => 'Move or delete this floor\'s rooms before deleting the floor.'], 409);
            }

            $statement = $pdo->prepare('DELETE FROM floors WHERE floor_id = ?');
            $statement->execute([$id]);

            return response()->json(['message' => 'Floor deleted.']);
        } catch (PDOException $e) {
            return $this->databaseError($e, 'Could not delete the floor.');
        }
    }

    public function storeRoom(Request $request)
    {
        try {
            [$roomNumber, $floorId, $capacity, $status] = $this->roomValues($request);
            $pdo = $this->pdo();
            $this->assertFloorExists($pdo, $floorId);

            $statement = $pdo->prepare('
                INSERT INTO rooms (room_number, floor_id, capacity, status)
                VALUES (?, ?, ?, ?)
            ');
            $statement->execute([$roomNumber, $floorId, $capacity, $status]);

            return response()->json(['message' => 'Room created.'], 201);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (PDOException $e) {
            return $this->databaseError($e, 'Could not create the room.');
        }
    }

    public function updateRoom(Request $request, int $id)
    {
        try {
            [$roomNumber, $floorId, $capacity, $status] = $this->roomValues($request);
            $pdo = $this->pdo();
            $pdo->beginTransaction();

            $lockSql = 'SELECT room_id FROM rooms WHERE room_id = ?';
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
                $lockSql .= ' FOR UPDATE';
            }
            $lock = $pdo->prepare($lockSql);
            $lock->execute([$id]);
            if (!$lock->fetch(PDO::FETCH_ASSOC)) {
                $pdo->rollBack();
                return response()->json(['message' => 'Room not found.'], 404);
            }

            $this->assertFloorExists($pdo, $floorId);
            $occupied = $this->activeOccupancy($pdo, $id);
            if ($capacity < $occupied) {
                throw new InvalidArgumentException("Capacity cannot be lower than the room's {$occupied} active occupant(s).");
            }
            if ($status === 'MAINTENANCE' && $occupied > 0) {
                throw new InvalidArgumentException('A room with active occupants cannot be placed under maintenance.');
            }

            $statement = $pdo->prepare('
                UPDATE rooms
                SET room_number = ?, floor_id = ?, capacity = ?, status = ?
                WHERE room_id = ?
            ');
            $statement->execute([$roomNumber, $floorId, $capacity, $status, $id]);
            $pdo->commit();

            return response()->json(['message' => 'Room updated.']);
        } catch (InvalidArgumentException $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (PDOException $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return $this->databaseError($e, 'Could not update the room.');
        }
    }

    public function destroyRoom(int $id)
    {
        try {
            $pdo = $this->pdo();
            $pdo->beginTransaction();
            $lockSql = 'SELECT room_id FROM rooms WHERE room_id = ?';
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
                $lockSql .= ' FOR UPDATE';
            }
            $lock = $pdo->prepare($lockSql);
            $lock->execute([$id]);
            if (!$lock->fetch(PDO::FETCH_ASSOC)) {
                $pdo->rollBack();
                return response()->json(['message' => 'Room not found.'], 404);
            }

            $statement = $pdo->prepare('SELECT COUNT(*) FROM room_allocations WHERE room_id = ?');
            $statement->execute([$id]);
            if ((int) $statement->fetchColumn() > 0) {
                $pdo->rollBack();
                return response()->json(['message' => 'Rooms with allocation history cannot be deleted.'], 409);
            }

            $statement = $pdo->prepare('DELETE FROM rooms WHERE room_id = ?');
            $statement->execute([$id]);
            $pdo->commit();

            return response()->json(['message' => 'Room deleted.']);
        } catch (PDOException $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return $this->databaseError($e, 'Could not delete the room.');
        }
    }

    private function floorName(Request $request): string
    {
        $name = trim((string) $request->input('floor_name', ''));
        if ($name === '' || mb_strlen($name) > 50) {
            throw new InvalidArgumentException('Floor name is required and must be 50 characters or fewer.');
        }

        return $name;
    }

    private function roomValues(Request $request): array
    {
        $roomNumber = trim((string) $request->input('room_number', ''));
        $floorId = filter_var($request->input('floor_id'), FILTER_VALIDATE_INT);
        $capacity = filter_var($request->input('capacity'), FILTER_VALIDATE_INT);
        $status = strtoupper(trim((string) $request->input('status', 'AVAILABLE')));

        if ($roomNumber === '' || mb_strlen($roomNumber) > 10) {
            throw new InvalidArgumentException('Room number is required and must be 10 characters or fewer.');
        }
        if ($floorId === false || $floorId < 1) {
            throw new InvalidArgumentException('Select a valid floor.');
        }
        if ($capacity === false || $capacity < 1 || $capacity > 6) {
            throw new InvalidArgumentException('Room capacity must be between 1 and 6 beds.');
        }
        if (!in_array($status, ['AVAILABLE', 'MAINTENANCE'], true)) {
            throw new InvalidArgumentException('Room status must be Available or Maintenance.');
        }

        return [$roomNumber, $floorId, $capacity, $status];
    }

    private function assertFloorExists(PDO $pdo, int $floorId): void
    {
        $statement = $pdo->prepare('SELECT 1 FROM floors WHERE floor_id = ?');
        $statement->execute([$floorId]);
        if (!$statement->fetchColumn()) {
            throw new InvalidArgumentException('Selected floor does not exist.');
        }
    }

    private function floorExists(int $floorId): bool
    {
        $statement = $this->pdo()->prepare('SELECT 1 FROM floors WHERE floor_id = ?');
        $statement->execute([$floorId]);

        return (bool) $statement->fetchColumn();
    }

    private function activeOccupancy(PDO $pdo, int $roomId): int
    {
        $statement = $pdo->prepare("SELECT COUNT(*) FROM room_allocations WHERE room_id = ? AND status = 'Active'");
        $statement->execute([$roomId]);

        return (int) $statement->fetchColumn();
    }

    private function databaseError(PDOException $exception, string $message)
    {
        if (in_array((string) $exception->getCode(), ['23503', '23505', '23514'], true)) {
            return response()->json(['message' => 'The requested change conflicts with existing room or floor data.'], 409);
        }

        report($exception);

        return response()->json(['message' => $message], 500);
    }
}
