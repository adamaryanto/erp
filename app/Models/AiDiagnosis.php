<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiDiagnosis extends Model
{
    protected $fillable = ['station', 'verdict', 'title', 'explanation', 'steps', 'payload'];
    protected function casts(): array
    {
        return [
            'steps' => 'array',
            'payload' => 'array',
        ];
    }
}
