<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HangingCount extends Model
{
    protected $fillable = ['station', 'count', 'direction', 'note', 'counted_at'];

    protected $casts = [
        'counted_at' => 'datetime',
    ];
}
