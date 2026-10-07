<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityLog extends Model
{
    protected $fillable = ['user_id', 'action', 'entity_type', 'entity_id', 'old_values', 'new_values', 'description'];
    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
        ];
    }

    /**
     * Get the user for this activity log.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
