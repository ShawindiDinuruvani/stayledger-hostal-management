<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonthlyFee extends Model
{
    protected $table = 'monthly_fees';
    protected $primaryKey = 'fee_id';

    protected $fillable = [
        'fee_code',
        'resident_id',
        'billing_period',
        'amount_due',
        'due_date',
        'status',
        'remarks',
    ];

    protected $casts = [
        'amount_due' => 'decimal:2',
        'due_date' => 'date:Y-m-d',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class, 'resident_id', 'resident_id');
    }
}
