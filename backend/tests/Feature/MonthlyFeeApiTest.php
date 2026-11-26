<?php

namespace Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MonthlyFeeApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Create floors table if missing
        if (!Schema::hasTable('floors')) {
            Schema::create('floors', function ($table) {
                $table->increments('floor_id');
                $table->string('floor_name', 50)->unique();
            });
        }

        // 2. Create rooms table if missing
        if (!Schema::hasTable('rooms')) {
            Schema::create('rooms', function ($table) {
                $table->increments('room_id');
                $table->string('room_number', 10)->unique();
                $table->unsignedInteger('floor_id');
                $table->integer('capacity')->default(3);
                $table->string('status', 20)->default('AVAILABLE');
            });
        }

        // 3. Create residents table if missing
        if (!Schema::hasTable('residents')) {
            Schema::create('residents', function ($table) {
                $table->increments('resident_id');
                $table->string('resident_code', 20)->unique();
                $table->string('full_name', 100);
                $table->string('email', 100)->unique();
                $table->string('phone', 20)->nullable();
                $table->string('status', 20)->default('ACTIVE');
                $table->timestamp('created_at')->useCurrent();
            });
        }

        // 4. Create room_allocations table if missing
        if (!Schema::hasTable('room_allocations')) {
            Schema::create('room_allocations', function ($table) {
                $table->increments('allocation_id');
                $table->string('allocation_code', 20)->unique();
                $table->unsignedInteger('resident_id');
                $table->unsignedInteger('room_id');
                $table->date('start_date');
                $table->date('end_date')->nullable();
                $table->string('status', 20)->default('Active');
                $table->string('remarks', 255)->nullable();
                $table->timestamps();
            });
        }

        // Seed reference floor and rooms
        DB::table('floors')->insert([
            ['floor_id' => 1, 'floor_name' => 'First floor'],
        ]);
        DB::table('rooms')->insert([
            ['room_id' => 1, 'room_number' => '01', 'floor_id' => 1, 'capacity' => 3, 'status' => 'AVAILABLE'],
            ['room_id' => 2, 'room_number' => '02', 'floor_id' => 1, 'capacity' => 3, 'status' => 'AVAILABLE'],
            ['room_id' => 3, 'room_number' => '03', 'floor_id' => 1, 'capacity' => 3, 'status' => 'AVAILABLE'],
            ['room_id' => 4, 'room_number' => '04', 'floor_id' => 1, 'capacity' => 3, 'status' => 'AVAILABLE'],
        ]);

        // Seed test residents:
        // RES-001: Active resident, full month stay in 2026-11 (eligible)
        // RES-002: Inactive resident, full month stay in 2026-11 (eligible for historical billing!)
        // RES-003: Active resident, partial month stay in 2026-11 (blocked pending prorating policy)
        // RES-004: Inactive resident, stay ended before 2026-11 (ineligible)
        DB::table('residents')->insert([
            ['resident_id' => 1, 'resident_code' => 'RES-001', 'full_name' => 'Amaya Perera', 'email' => 'amaya@test.com', 'status' => 'ACTIVE'],
            ['resident_id' => 2, 'resident_code' => 'RES-002', 'full_name' => 'Dinithi Fernando', 'email' => 'dinithi@test.com', 'status' => 'INACTIVE'],
            ['resident_id' => 3, 'resident_code' => 'RES-003', 'full_name' => 'Kavindi Jayasinghe', 'email' => 'kavindi@test.com', 'status' => 'ACTIVE'],
            ['resident_id' => 4, 'resident_code' => 'RES-004', 'full_name' => 'Nethmi Silva', 'email' => 'nethmi@test.com', 'status' => 'INACTIVE'],
        ]);

        // Seed allocations:
        DB::table('room_allocations')->insert([
            // RES-001: Active, full month stay (2026-09-01 onwards, open-ended)
            [
                'allocation_id' => 1,
                'allocation_code' => 'ALC-001',
                'resident_id' => 1,
                'room_id' => 1,
                'start_date' => '2026-09-01',
                'end_date' => null,
                'status' => 'Active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            // RES-002: Inactive resident, but had full month stay in 2026-11 (checked out 2026-11-30)
            [
                'allocation_id' => 2,
                'allocation_code' => 'ALC-002',
                'resident_id' => 2,
                'room_id' => 2,
                'start_date' => '2026-10-01',
                'end_date' => '2026-11-30',
                'status' => 'Checked Out',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            // RES-003: Active resident, but joined mid-month (2026-11-10 onwards -> partial stay!)
            [
                'allocation_id' => 3,
                'allocation_code' => 'ALC-003',
                'resident_id' => 3,
                'room_id' => 3,
                'start_date' => '2026-11-10',
                'end_date' => null,
                'status' => 'Active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            // RES-004: Checked out in October 2026 (stay ended before 2026-11-01 -> ineligible)
            [
                'allocation_id' => 4,
                'allocation_code' => 'ALC-004',
                'resident_id' => 4,
                'room_id' => 4,
                'start_date' => '2026-08-01',
                'end_date' => '2026-10-31',
                'status' => 'Checked Out',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    /**
     * Helper to authenticate requests using standard Laravel actingAs test facility.
     * No custom test headers are used in production middleware.
     */
    protected function testAuth(): self
    {
        return $this->actingAs(new GenericUser([
            'id' => 1,
            'name' => 'Hostel Warden',
            'email' => 'warden@stayledger.local',
        ]));
    }

    /**
     * Test unauthorized access rejection when no authentication is provided.
     */
    public function test_unauthorized_access_denied_without_authentication(): void
    {
        $response = $this->getJson('/api/fees');
        $response->assertStatus(401)
                 ->assertJsonFragment(['message' => 'Sign in to StayLedger before accessing monthly fees.']);
    }

    /**
     * Test authorized access succeeds under test authorization facility.
     */
    public function test_authorized_access_succeeds_under_test_facility(): void
    {
        $response = $this->testAuth()->getJson('/api/fees');
        $response->assertStatus(200);
    }

    /**
     * Test preview endpoint:
     * - Historical inactive resident with valid stay (RES-002) is ELIGIBLE.
     * - Active resident with valid stay (RES-001) is ELIGIBLE.
     * - Resident with partial-month stay (RES-003) is marked BLOCKED_POLICY_UNCONFIRMED.
     * - Resident whose stay ended before period (RES-004) is NOT in the roster.
     */
    public function test_preview_endpoint_historical_eligibility_and_partial_stay_blocking(): void
    {
        $response = $this->testAuth()->getJson('/api/fees/preview?period=2026-11');

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'billing_period',
                     'total_active_residents',
                     'eligible_count',
                     'already_billed_count',
                     'blocked_count',
                     'preview_list',
                 ]);

        // 2 full month stays eligible (RES-001 active + RES-002 historical inactive)
        $this->assertEquals(2, $response->json('eligible_count'));
        $this->assertEquals(0, $response->json('already_billed_count'));
        // 1 partial stay blocked (RES-003 moved in 2026-11-10)
        $this->assertEquals(1, $response->json('blocked_count'));

        $previewList = collect($response->json('preview_list'));

        // RES-002 must be ELIGIBLE despite status=INACTIVE
        $res2 = $previewList->firstWhere('resident_code', 'RES-002');
        $this->assertNotNull($res2);
        $this->assertEquals('ELIGIBLE', $res2['status']);

        // RES-003 must be BLOCKED_POLICY_UNCONFIRMED
        $res3 = $previewList->firstWhere('resident_code', 'RES-003');
        $this->assertNotNull($res3);
        $this->assertEquals('BLOCKED_POLICY_UNCONFIRMED', $res3['status']);
        $this->assertStringContainsString('Partial-month stay detected', $res3['block_reason']);

        // RES-004 must not appear in the November 2026 roster
        $res4 = $previewList->firstWhere('resident_code', 'RES-004');
        $this->assertNull($res4);
    }

    /**
     * Test generation validation rejects invalid periods and non-positive amounts.
     */
    public function test_generate_endpoint_validates_inputs(): void
    {
        // Invalid period
        $response1 = $this->testAuth()->postJson('/api/fees/generate', [
            'billing_period' => '2026-13',
            'amount' => 18000.00,
            'due_date' => '2026-11-15',
        ]);
        $response1->assertStatus(422)
                  ->assertJsonValidationErrors(['billing_period']);

        // Non-positive amount
        $response2 = $this->testAuth()->postJson('/api/fees/generate', [
            'billing_period' => '2026-11',
            'amount' => -500.00,
            'due_date' => '2026-11-15',
        ]);
        $response2->assertStatus(422)
                  ->assertJsonValidationErrors(['amount']);
    }

    /**
     * Test fee generation server-side:
     * - Only creates records for full-month stays (RES-001 and RES-002).
     * - Strictly blocks partial-month stays (RES-003 is NOT charged full fee).
     * - Safely prevents duplicates on repeated runs (idempotency).
     */
    public function test_generate_fees_enforces_partial_stay_blocking_and_idempotency(): void
    {
        // First run: should create 2 records (RES-001 and RES-002), block 1 (RES-003)
        $response1 = $this->testAuth()->postJson('/api/fees/generate', [
            'billing_period' => '2026-11',
            'amount' => 18000.00,
            'due_date' => '2026-11-15',
            'remarks' => 'Test monthly fee',
        ]);

        $response1->assertStatus(201)
                  ->assertJsonFragment([
                      'success' => true,
                      'created_count' => 2,
                      'skipped_count' => 0,
                      'total_eligible' => 2,
                      'blocked_count' => 1,
                  ]);

        // Assert database has exactly 2 records
        $this->assertDatabaseCount('monthly_fees', 2);

        // Verify partial-stay resident (RES-003, resident_id=3) has NO fee record
        $this->assertDatabaseMissing('monthly_fees', [
            'resident_id' => 3,
            'billing_period' => '2026-11',
        ]);

        // Verify inactive resident with valid historical stay (RES-002, resident_id=2) HAS a fee record
        $this->assertDatabaseHas('monthly_fees', [
            'resident_id' => 2,
            'billing_period' => '2026-11',
        ]);

        // Second run: must skip existing records and create 0 duplicates
        $response2 = $this->testAuth()->postJson('/api/fees/generate', [
            'billing_period' => '2026-11',
            'amount' => 18000.00,
            'due_date' => '2026-11-15',
        ]);

        $response2->assertStatus(201)
                  ->assertJsonFragment([
                      'success' => true,
                      'created_count' => 0,
                      'skipped_count' => 2,
                      'total_eligible' => 2,
                      'blocked_count' => 1,
                  ]);

        // Database count must remain exactly 2
        $this->assertDatabaseCount('monthly_fees', 2);
    }

    /**
     * Test honest payment data handling when payments module is unlinked (Section 6 compliance).
     */
    public function test_fee_list_handles_unavailable_payment_data_honestly(): void
    {
        // Generate fee records
        $this->testAuth()->postJson('/api/fees/generate', [
            'billing_period' => '2026-11',
            'amount' => 18000.00,
            'due_date' => '2026-11-15',
        ]);

        $response = $this->testAuth()->getJson('/api/fees?period=2026-11');

        $response->assertStatus(200);
        $fees = $response->json('fees');
        $this->assertCount(2, $fees);

        $firstFee = $fees[0];
        // Section 6 requirement: Do not classify unknown records as unpaid or paid. Return unavailable status.
        $this->assertEquals('UNAVAILABLE', $firstFee['payment_status']);
        $this->assertNull($firstFee['paid_amount']);
        $this->assertNull($firstFee['balance_amount']);
        $this->assertFalse($firstFee['payment_available']);
        $this->assertNotEmpty($firstFee['payment_notice']);
    }

    /**
     * Test search and filter functionality on fee list.
     */
    public function test_fee_list_search_and_period_filters(): void
    {
        // Generate records for 2026-11
        $this->testAuth()->postJson('/api/fees/generate', [
            'billing_period' => '2026-11',
            'amount' => 18000.00,
            'due_date' => '2026-11-15',
        ]);

        // Search by resident name
        $searchRes = $this->testAuth()->getJson('/api/fees?period=2026-11&search=Amaya');
        $searchRes->assertStatus(200);
        $this->assertCount(1, $searchRes->json('fees'));
        $this->assertEquals('Amaya Perera', $searchRes->json('fees.0.resident_name'));

        // Search for nonexistent term
        $emptyRes = $this->testAuth()->getJson('/api/fees?period=2026-11&search=Nonexistent');
        $emptyRes->assertStatus(200);
        $this->assertCount(0, $emptyRes->json('fees'));
    }

    /**
     * Test fee details retrieval by ID and 404 response for nonexistent ID.
     */
    public function test_fee_details_and_not_found(): void
    {
        $this->testAuth()->postJson('/api/fees/generate', [
            'billing_period' => '2026-11',
            'amount' => 18000.00,
            'due_date' => '2026-11-15',
        ]);

        $firstId = DB::table('monthly_fees')->value('fee_id');

        $response = $this->testAuth()->getJson("/api/fees/{$firstId}");
        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'fee' => [
                         'fee_id',
                         'fee_code',
                         'billing_period',
                         'amount_due',
                         'due_date',
                         'resident_name',
                         'resident_code',
                     ]
                 ]);

        // 404 for nonexistent ID
        $notFound = $this->testAuth()->getJson('/api/fees/99999');
        $notFound->assertStatus(404);
    }
}