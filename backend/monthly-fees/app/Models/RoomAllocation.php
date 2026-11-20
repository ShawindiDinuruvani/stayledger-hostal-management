<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoomAllocation extends Model
{
    protected $table = 'room_allocations';
    protected $primaryKey = 'allocation_id';

    protected $fillable = [
        'allocation_code',
        'resident_id',
        'room_id',
        'start_date',
        'end_date',
        'status',
        'remarks',
    ];

    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class, 'resident_id', 'resident_id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'room_id', 'room_id');
    }
}
