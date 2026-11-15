<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Resident extends Model
{
    protected $table = 'residents';
    protected $primaryKey = 'resident_id';
    public $timestamps = false; // schema has created_at only

    protected $fillable = [
        'resident_code',
        'full_name',
        'email',
        'phone',
        'status',
        'created_at',
    ];

    public function monthlyFees(): HasMany
    {
        return $this->hasMany(MonthlyFee::class, 'resident_id', 'resident_id');
    }

    public function roomAllocations(): HasMany
    {
        return $this->hasMany(RoomAllocation::class, 'resident_id', 'resident_id');
    }

    public function activeAllocation()
    {
        return $this->hasOne(RoomAllocation::class, 'resident_id', 'resident_id')
            ->where('status', 'Active')
            ->latest('allocation_id');
    }
}
