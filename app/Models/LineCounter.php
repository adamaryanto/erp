<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LineCounter extends Model
{
    protected $fillable = ['code', 'name', 'stage', 'value'];
    protected function casts(): array
    {
        return [
            'value' => 'integer',
        ];
    }
}
