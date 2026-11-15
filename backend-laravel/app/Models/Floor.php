<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Floor extends Model
{
    protected $table = 'floors';
    protected $primaryKey = 'floor_id';
    public $timestamps = false;

    protected $fillable = ['floor_name'];

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class, 'floor_id', 'floor_id');
    }
}
