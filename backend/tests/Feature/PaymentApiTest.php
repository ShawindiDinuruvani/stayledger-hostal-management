<?php

namespace Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PaymentApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('users')->insertOrIgnore([
            'id' => 1,
            'name' => 'Hostel Warden',
            'email' => 'warden@stayledger.local',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('residents')->insert([
            'resident_id' => 1,
            'resident_code' => 'RES-001',
            'full_name' => 'Amaya Perera',
            'email' => 'amaya@test.com',
            'status' => 'ACTIVE',
            'created_at' => '2026-11-01 00:00:00',
        ]);

        if (!\Illuminate\Support\Facades\Schema::hasTable('floors')) {
            \Illuminate\Support\Facades\Schema::create('floors', function ($table) {
                $table->increments('floor_id');
                $table->string('floor_name', 50);
            });
            DB::table('floors')->insert(['floor_id' => 1, 'floor_name' => 'First floor']);
        }
        if (!\Illuminate\Support\Facades\Schema::hasTable('rooms')) {
            \Illuminate\Support\Facades\Schema::create('rooms', function ($table) {
                $table->increments('room_id');
                $table->string('room_number', 10);
                $table->unsignedInteger('floor_id');
                $table->integer('capacity')->default(3);
                $table->string('status', 20)->default('AVAILABLE');
            });
            DB::table('rooms')->insert(['room_id' => 1, 'room_number' => '101', 'floor_id' => 1]);
        }
        if (!\Illuminate\Support\Facades\Schema::hasTable('room_allocations')) {
            \Illuminate\Support\Facades\Schema::create('room_allocations', function ($table) {
                $table->increments('allocation_id');
                $table->string('allocation_code', 20)->unique();
                $table->unsignedInteger('resident_id');
                $table->unsignedInteger('room_id');
                $table->date('start_date');
                $table->date('end_date')->nullable();
                $table->string('status', 20)->default('Active');
                $table->timestamps();
            });
        }

        DB::table('room_allocations')->insert([
            'allocation_id' => 1,
            'allocation_code' => 'ALC-001',
            'resident_id' => 1,
            'room_id' => 1,
            'start_date' => '2026-11-01',
            'status' => 'Active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('monthly_fees')->insert([
            'fee_id' => 1,
            'fee_code' => 'FEE-202611-0001',
            'resident_id' => 1,
            'billing_period' => '2026-11',
            'amount_due' => 1000,
            'due_date' => '2026-11-15',
            'status' => 'UNPAID',
            'created_at' => '2026-11-01 00:00:00',
            'updated_at' => '2026-11-01 00:00:00',
        ]);
    }

    protected function auth(): self
    {
        return $this->actingAs(new GenericUser([
            'id' => 1,
            'name' => 'Hostel Warden',
            'email' => 'warden@stayledger.local',
        ]));
    }

    public function test_unauthorized_payment_requests_are_rejected(): void
    {
        $this->postJson('/api/fees/1/payments', [
            'amount' => 100,
            'paid_date' => '2026-11-10',
            'method' => 'CASH',
        ])->assertStatus(401);
    }

    public function test_successful_partial_and_full_payments(): void
    {
        $this->auth()->postJson('/api/fees/1/payments', [
            'amount' => 400,
            'paid_date' => '2026-11-10',
            'method' => 'CASH',
        ])->assertStatus(201);

        $this->assertDatabaseHas('monthly_fees', ['fee_id' => 1, 'status' => 'PARTIALLY_PAID']);
        $this->assertDatabaseHas('payments', ['fee_id' => 1, 'amount' => 400, 'payment_type' => 'PAYMENT']);

        $this->auth()->postJson('/api/fees/1/payments', [
            'amount' => 600,
            'paid_date' => '2026-11-11',
            'method' => 'BANK',
            'reference_no' => 'BNK-100',
        ])->assertStatus(201);

        $this->assertDatabaseHas('monthly_fees', ['fee_id' => 1, 'status' => 'PAID']);
    }

    public function test_overpayment_paid_fee_and_invalid_amount_are_rejected(): void
    {
        $this->auth()->postJson('/api/fees/1/payments', [
            'amount' => 1001,
            'paid_date' => '2026-11-10',
            'method' => 'CASH',
        ])->assertStatus(422);

        $this->auth()->postJson('/api/fees/1/payments', [
            'amount' => 0,
            'paid_date' => '2026-11-10',
            'method' => 'CASH',
        ])->assertStatus(422)->assertJsonValidationErrors(['amount']);

        DB::table('monthly_fees')->where('fee_id', 1)->update(['status' => 'PAID']);
        $this->auth()->postJson('/api/fees/1/payments', [
            'amount' => 100,
            'paid_date' => '2026-11-10',
            'method' => 'CASH',
        ])->assertStatus(422);
    }

    public function test_future_and_pre_creation_dates_are_rejected(): void
    {
        $this->auth()->postJson('/api/fees/1/payments', [
            'amount' => 100,
            'paid_date' => '2999-01-01',
            'method' => 'CASH',
        ])->assertStatus(422)->assertJsonValidationErrors(['paid_date']);

        $this->auth()->postJson('/api/fees/1/payments', [
            'amount' => 100,
            'paid_date' => '2026-10-31',
            'method' => 'CASH',
        ])->assertStatus(422);
    }

    public function test_reversal_requires_reason_and_can_only_happen_once(): void
    {
        $this->auth()->postJson('/api/fees/1/payments', [
            'amount' => 300,
            'paid_date' => '2026-11-10',
            'method' => 'CARD',
        ])->assertStatus(201);
        $paymentId = DB::table('payments')->where('payment_type', 'PAYMENT')->value('payment_id');

        $this->auth()->postJson("/api/payments/{$paymentId}/reverse", [])
            ->assertStatus(422)->assertJsonValidationErrors(['reason']);

        $this->auth()->postJson("/api/payments/{$paymentId}/reverse", ['reason' => 'Duplicate charge'])
            ->assertStatus(200);
        $this->assertDatabaseHas('payments', ['reverses_payment_id' => $paymentId, 'payment_type' => 'REVERSAL', 'amount' => -300]);
        $this->assertDatabaseHas('monthly_fees', ['fee_id' => 1, 'status' => 'UNPAID']);

        $this->auth()->postJson("/api/payments/{$paymentId}/reverse", ['reason' => 'Second reversal'])
            ->assertStatus(422);
    }

    public function test_correction_is_atomic_and_failed_correction_rolls_back(): void
    {
        $this->auth()->postJson('/api/fees/1/payments', [
            'amount' => 300,
            'paid_date' => '2026-11-10',
            'method' => 'CASH',
        ])->assertStatus(201);
        $paymentId = DB::table('payments')->where('payment_type', 'PAYMENT')->value('payment_id');

        $this->auth()->postJson("/api/payments/{$paymentId}/correct", [
            'reason' => 'Wrong amount',
            'amount' => 500,
            'paid_date' => '2026-11-11',
            'method' => 'BANK',
            'reference_no' => 'CORRECT-1',
        ])->assertStatus(200);

        $this->assertDatabaseHas('payments', ['reverses_payment_id' => $paymentId, 'payment_type' => 'REVERSAL']);
        $this->assertDatabaseHas('payments', ['amount' => 500, 'payment_type' => 'PAYMENT']);

        $before = DB::table('payments')->count();
        $this->auth()->postJson("/api/payments/{$paymentId}/correct", [
            'reason' => 'Invalid replacement',
            'amount' => 5000,
            'paid_date' => '2026-11-11',
            'method' => 'CARD',
        ])->assertStatus(422);
        $this->assertEquals($before, DB::table('payments')->count());
    }

    public function test_history_and_balance_endpoints(): void
    {
        $this->auth()->postJson('/api/fees/1/payments', [
            'amount' => 250,
            'paid_date' => '2026-11-10',
            'method' => 'CASH',
        ])->assertStatus(201);

        $this->auth()->getJson('/api/fees/1/balance')
            ->assertStatus(200)
            ->assertJsonFragment(['total_paid' => 250, 'outstanding_balance' => 750]);

        $this->auth()->getJson('/api/payments?search=Amaya&method=CASH')
            ->assertStatus(200)
            ->assertJsonFragment(['resident_name' => 'Amaya Perera']);
    }
}
