<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Room extends Model
{
    protected $table = 'rooms';
    protected $primaryKey = 'room_id';
    public $timestamps = false;

    protected $fillable = [
        'room_number',
        'floor_id',
        'capacity',
        'status',
    ];

    public function floor(): BelongsTo
    {
        return $this->belongsTo(Floor::class, 'floor_id', 'floor_id');
    }

    public function roomAllocations(): HasMany
    {
        return $this->hasMany(RoomAllocation::class, 'room_id', 'room_id');
    }
}
