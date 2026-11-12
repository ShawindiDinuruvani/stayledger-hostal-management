<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Exception;

class RoomAllocationController extends Controller
{
    /**
     * GET /api/allocations/dashboard
     * Returns live data from MySQL tables & vw_room_occupancy view
     */
    public function index()
    {
        // 1. Query the MySQL View for live room capacity
        $rooms = DB::table('vw_room_occupancy')->get();

        // 2. Query Allocation History joined with Residents, Rooms, and Floors
        $allocations = DB::table('room_allocations as ra')
            ->join('residents as res', 'ra.resident_id', '=', 'res.resident_id')
            ->join('rooms as rm', 'ra.room_id', '=', 'rm.room_id')
            ->join('floors as fl', 'rm.floor_id', '=', 'fl.floor_id')
            ->select(
                'ra.allocation_id',
                'ra.allocation_code as allocId',
                'res.resident_id',
                'res.resident_code as residentCode',
                'res.full_name as residentName',
                'rm.room_id',
                'rm.room_number as room',
                'fl.floor_name as floor',
                'ra.start_date as startDate',
                'ra.end_date as endDate',
                'ra.status',
                'ra.remarks'
            )
            ->orderByDesc('ra.allocation_id')
            ->get();

        // 3. Query Residents safely with EXISTS subquery (prevents duplicate rows)
        $residents = DB::table('residents as r')
            ->where('r.status', 'ACTIVE')
            ->select(
                'r.resident_id',
                'r.resident_code',
                'r.full_name',
                DB::raw('EXISTS(
                    SELECT 1 FROM room_allocations ra 
                    WHERE ra.resident_id = r.resident_id AND ra.status = "Active"
                ) AS is_allocated')
            )
            ->orderBy('r.full_name')
            ->get();

        return response()->json([
            'rooms'       => $rooms,
            'allocations' => $allocations,
            'residents'   => $residents,
        ]);
    }

    /**
     * POST /api/allocations
     * Allocates a resident to a room using ACID Transaction + Pessimistic Row Locking
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'resident_id' => 'required|integer|exists:residents,resident_id',
            'room_id'     => 'required|integer|exists:rooms,room_id',
            'start_date'  => 'required|date',
            'remarks'     => 'nullable|string|max:255',
        ]);

        try {
            return DB::transaction(function () use ($validated) {
                // 1. PESSIMISTIC ROW LOCK: Lock target room row (SELECT ... FOR UPDATE)
                $room = DB::table('rooms')
                    ->where('room_id', $validated['room_id'])
                    ->lockForUpdate()
                    ->first();

                if ($room->status === 'MAINTENANCE') {
                    return response()->json([
                        'message' => "Room {$room->room_number} is currently under maintenance."
                    ], 422);
                }

                // 2. Lock and count current active allocations in this room
                $occupiedCount = DB::table('room_allocations')
                    ->where('room_id', $validated['room_id'])
                    ->where('status', 'Active')
                    ->lockForUpdate()
                    ->count();

                if ($occupiedCount >= $room->capacity) {
                    return response()->json([
                        'message' => "Capacity Error: Room {$room->room_number} is full ({$occupiedCount}/{$room->capacity})."
                    ], 422);
                }

                // 3. Generate unique sequential code (ALLOC-101, ALLOC-102, ...)
                $maxId = DB::table('room_allocations')->lockForUpdate()->max('allocation_id') ?? 0;
                $code  = 'ALLOC-' . (100 + $maxId + 1);

                DB::table('room_allocations')->insert([
                    'allocation_code' => $code,
                    'resident_id'     => $validated['resident_id'],
                    'room_id'         => $validated['room_id'],
                    'start_date'      => $validated['start_date'],
                    'end_date'        => null,
                    'status'          => 'Active',
                    'remarks'         => !empty($validated['remarks']) ? $validated['remarks'] : 'Standard bed allocation',
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]);

                return response()->json([
                    'message' => "Transaction committed: Allocated to Room {$room->room_number} ({$code})."
                ], 201);
            });
        } catch (Exception $e) {
            return response()->json([
                'message' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * POST /api/allocations/{id}/transfer
     * Atomically closes old room allocation and opens new room allocation
     */
    public function transfer(Request $request, $id)
    {
        $validated = $request->validate([
            'target_room_id' => 'required|integer|exists:rooms,room_id',
            'transfer_date'  => 'required|date',
            'reason'         => 'required|string|max:255',
        ]);

        try {
            return DB::transaction(function () use ($validated, $id) {
                // 1. Lock current active allocation row
                $currentAlloc = DB::table('room_allocations')
                    ->where('allocation_id', $id)
                    ->where('status', 'Active')
                    ->lockForUpdate()
                    ->first();

                if (!$currentAlloc) {
                    return response()->json(['message' => 'Active allocation not found.'], 404);
                }

                if ($currentAlloc->room_id == $validated['target_room_id']) {
                    return response()->json(['message' => 'Target room must be different from current room.'], 422);
                }

                if ($validated['transfer_date'] < $currentAlloc->start_date) {
                    return response()->json([
                        'message' => 'Transfer date cannot be earlier than original check-in start date.'
                    ], 422);
                }

                // 2. Lock both rooms in ascending ID order to prevent deadlocks
                $roomIds = [$currentAlloc->room_id, $validated['target_room_id']];
                sort($roomIds);
                DB::table('rooms')->whereIn('room_id', $roomIds)->lockForUpdate()->get();

                $targetRoom = DB::table('rooms')->where('room_id', $validated['target_room_id'])->first();
                $oldRoom    = DB::table('rooms')->where('room_id', $currentAlloc->room_id'])->first();

                if ($targetRoom->status === 'MAINTENANCE') {
                    return response()->json([
                        'message' => "Room {$targetRoom->room_number} is currently under maintenance."
                    ], 422);
                }

                // 3. Check target room capacity under lock
                $targetOccupied = DB::table('room_allocations')
                    ->where('room_id', $targetRoom->room_id)
                    ->where('status', 'Active')
                    ->lockForUpdate()
                    ->count();

                if ($targetOccupied >= $targetRoom->capacity) {
                    return response()->json([
                        'message' => "Room {$targetRoom->room_number} is already at full capacity."
                    ], 422);
                }

                // 4. Step A of Transaction: Close old allocation as 'Transferred'
                DB::table('room_allocations')
                    ->where('allocation_id', $id)
                    ->update([
                        'end_date'   => $validated['transfer_date'],
                        'status'     => 'Transferred',
                        'remarks'    => "Transferred to Room {$targetRoom->room_number}: {$validated['reason']}",
                        'updated_at' => now(),
                    ]);

                // 5. Step B of Transaction: Insert new 'Active' allocation in target room
                $maxId = DB::table('room_allocations')->lockForUpdate()->max('allocation_id') ?? 0;
                $code  = 'ALLOC-' . (100 + $maxId + 1);

                DB::table('room_allocations')->insert([
                    'allocation_code' => $code,
                    'resident_id'     => $currentAlloc->resident_id,
                    'room_id'         => $targetRoom->room_id,
                    'start_date'      => $validated['transfer_date'],
                    'end_date'        => null,
                    'status'          => 'Active',
                    'remarks'         => "Transferred from Room {$oldRoom->room_number}: {$validated['reason']}",
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]);

                return response()->json([
                    'message' => "Atomic Transfer committed: Moved from Room {$oldRoom->room_number} to Room {$targetRoom->room_number}."
                ]);
            });
        } catch (Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /api/allocations/{id}/checkout
     * Releases bed and marks resident allocation as Checked Out
     */
    public function checkout(Request $request, $id)
    {
        $validated = $request->validate([
            'checkout_date' => 'required|date',
            'remarks'       => 'required|string|max:255',
        ]);

        try {
            return DB::transaction(function () use ($validated, $id) {
                $alloc = DB::table('room_allocations')
                    ->where('allocation_id', $id)
                    ->where('status', 'Active')
                    ->lockForUpdate()
                    ->first();

                if (!$alloc) {
                    return response()->json(['message' => 'Active allocation record not found.'], 404);
                }

                if ($validated['checkout_date'] < $alloc->start_date) {
                    return response()->json([
                        'message' => 'Checkout date cannot be earlier than check-in start date.'
                    ], 422);
                }

                DB::table('room_allocations')
                    ->where('allocation_id', $id)
                    ->update([
                        'end_date'   => $validated['checkout_date'],
                        'status'     => 'Checked Out',
                        'remarks'    => $validated['remarks'],
                        'updated_at' => now(),
                    ]);

                return response()->json([
                    'message' => 'Checkout committed: Bed released and occupancy view updated.'
                ]);
            });
        } catch (Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}