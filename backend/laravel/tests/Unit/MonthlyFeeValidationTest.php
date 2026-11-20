<?php

namespace Tests\Unit;

use App\Http\Requests\GenerateMonthlyFeeRequest;
use App\Http\Requests\PreviewMonthlyFeeRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class MonthlyFeeValidationTest extends TestCase
{
    /**
     * Test billing period format validation in GenerateMonthlyFeeRequest.
     */
    public function test_billing_period_format_validation(): void
    {
        $rules = (new GenerateMonthlyFeeRequest())->rules();

        // Valid periods (YYYY-MM)
        $validPeriods = ['2026-11', '2026-01', '2027-12', '2025-06'];
        foreach ($validPeriods as $period) {
            $validator = Validator::make(['billing_period' => $period, 'amount' => 18000, 'due_date' => '2026-11-15'], $rules);
            $this->assertFalse($validator->errors()->has('billing_period'), "Period {$period} should be valid.");
        }

        // Invalid periods
        $invalidPeriods = ['2026-00', '2026-13', '2026/11', 'Nov 2026', '26-11', '', 'invalid-date'];
        foreach ($invalidPeriods as $period) {
            $validator = Validator::make(['billing_period' => $period, 'amount' => 18000, 'due_date' => '2026-11-15'], $rules);
            $this->assertTrue($validator->errors()->has('billing_period'), "Period {$period} should be invalid.");
        }
    }

    /**
     * Test positive fee amount validation.
     */
    public function test_fee_amount_must_be_positive(): void
    {
        $rules = (new GenerateMonthlyFeeRequest())->rules();

        // Valid amounts
        $validAmounts = [18000.00, 15000, 0.01, 24500.50];
        foreach ($validAmounts as $amt) {
            $validator = Validator::make(['billing_period' => '2026-11', 'amount' => $amt, 'due_date' => '2026-11-15'], $rules);
            $this->assertFalse($validator->errors()->has('amount'), "Amount {$amt} should be valid.");
        }

        // Invalid amounts
        $invalidAmounts = [0, -100, -0.01, 'abc', null, ''];
        foreach ($invalidAmounts as $amt) {
            $validator = Validator::make(['billing_period' => '2026-11', 'amount' => $amt, 'due_date' => '2026-11-15'], $rules);
            $this->assertTrue($validator->errors()->has('amount'), "Amount " . var_export($amt, true) . " should be rejected.");
        }
    }

    /**
     * Test preview request validation rules.
     */
    public function test_preview_request_validation(): void
    {
        $rules = (new PreviewMonthlyFeeRequest())->rules();

        $validValidator = Validator::make(['period' => '2026-11'], $rules);
        $this->assertTrue($validValidator->passes());

        $invalidValidator = Validator::make(['period' => 'not-a-period'], $rules);
        $this->assertFalse($invalidValidator->passes());
        $this->assertTrue($invalidValidator->errors()->has('period'));
    }
}
