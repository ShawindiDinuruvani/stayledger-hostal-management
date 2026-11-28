<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RoomManagementApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('room_allocations');
        Schema::dropIfExists('rooms');
        Schema::dropIfExists('floors');

        Schema::create('floors', function (Blueprint $table) {
            $table->increments('floor_id');
            $table->string('floor_name', 50)->unique();
        });

        Schema::create('rooms', function (Blueprint $table) {
            $table->increments('room_id');
            $table->string('room_number', 10)->unique();
            $table->unsignedInteger('floor_id');
            $table->unsignedInteger('capacity')->default(3);
            $table->string('status', 20)->default('AVAILABLE');
            $table->foreign('floor_id')->references('floor_id')->on('floors');
        });

        Schema::create('room_allocations', function (Blueprint $table) {
            $table->increments('allocation_id');
            $table->unsignedInteger('room_id');
            $table->string('status', 20);
            $table->foreign('room_id')->references('room_id')->on('rooms');
        });
    }

    public function test_room_list_reports_capacity_occupancy_and_availability(): void
    {
        $floorId = DB::table('floors')->insertGetId(['floor_name' => 'First floor'], 'floor_id');
        $roomId = DB::table('rooms')->insertGetId([
            'room_number' => '01',
            'floor_id' => $floorId,
            'capacity' => 3,
            'status' => 'AVAILABLE',
        ], 'room_id');
        DB::table('room_allocations')->insert([
            ['room_id' => $roomId, 'status' => 'Active'],
            ['room_id' => $roomId, 'status' => 'Active'],
            ['room_id' => $roomId, 'status' => 'Checked Out'],
        ]);

        $this->getJson('/api/rooms-management')
            ->assertOk()
            ->assertJsonPath('rooms.0.capacity', 3)
            ->assertJsonPath('rooms.0.occupied_beds', 2)
            ->assertJsonPath('rooms.0.available_beds', 1)
            ->assertJsonPath('rooms.0.occupancy_status', 'Partial')
            ->assertJsonPath('floors.0.room_count', 1);
    }

    public function test_room_capacity_cannot_be_reduced_below_active_occupancy(): void
    {
        $floorId = DB::table('floors')->insertGetId(['floor_name' => 'Second floor'], 'floor_id');
        $roomId = DB::table('rooms')->insertGetId([
            'room_number' => '12',
            'floor_id' => $floorId,
            'capacity' => 3,
            'status' => 'AVAILABLE',
        ], 'room_id');
        DB::table('room_allocations')->insert([
            ['room_id' => $roomId, 'status' => 'Active'],
            ['room_id' => $roomId, 'status' => 'Active'],
        ]);

        $this->putJson("/api/rooms/{$roomId}", [
            'room_number' => '12',
            'floor_id' => $floorId,
            'capacity' => 1,
            'status' => 'AVAILABLE',
        ])->assertUnprocessable()
            ->assertJsonPath('message', "Capacity cannot be lower than the room's 2 active occupant(s).");

        $this->assertDatabaseHas('rooms', ['room_id' => $roomId, 'capacity' => 3]);
    }

    public function test_room_cannot_be_marked_for_maintenance_while_occupied(): void
    {
        $floorId = DB::table('floors')->insertGetId(['floor_name' => 'Third floor'], 'floor_id');
        $roomId = DB::table('rooms')->insertGetId([
            'room_number' => '21',
            'floor_id' => $floorId,
            'capacity' => 2,
            'status' => 'AVAILABLE',
        ], 'room_id');
        DB::table('room_allocations')->insert(['room_id' => $roomId, 'status' => 'Active']);

        $this->putJson("/api/rooms/{$roomId}", [
            'room_number' => '21',
            'floor_id' => $floorId,
            'capacity' => 2,
            'status' => 'MAINTENANCE',
        ])->assertUnprocessable();

        $this->assertDatabaseHas('rooms', ['room_id' => $roomId, 'status' => 'AVAILABLE']);
    }

    public function test_room_creation_rejects_capacity_outside_the_supported_range_and_unknown_floors(): void
    {
        $floorId = DB::table('floors')->insertGetId(['floor_name' => 'Ground floor'], 'floor_id');

        $this->postJson('/api/rooms', [
            'room_number' => 'G1',
            'floor_id' => $floorId,
            'capacity' => 7,
            'status' => 'AVAILABLE',
        ])->assertUnprocessable();

        $this->postJson('/api/rooms', [
            'room_number' => 'G2',
            'floor_id' => 999,
            'capacity' => 3,
            'status' => 'AVAILABLE',
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'Selected floor does not exist.');

        $this->assertDatabaseCount('rooms', 0);
    }
}
