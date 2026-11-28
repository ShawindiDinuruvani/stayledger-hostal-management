<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $table = 'payments';
    protected $primaryKey = 'payment_id';
    public $timestamps = false;

    protected $fillable = [
        'payment_code',
        'fee_id',
        'amount',
        'paid_date',
        'method',
        'reference_no',
        'payment_type',
        'reverses_payment_id',
        'reason',
        'recorded_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_date' => 'date:Y-m-d',
        'created_at' => 'datetime',
    ];

    public function fee(): BelongsTo
    {
        return $this->belongsTo(MonthlyFee::class, 'fee_id', 'fee_id');
    }
}
