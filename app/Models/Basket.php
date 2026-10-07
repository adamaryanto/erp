<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Basket extends Model
{
    protected $fillable = ['code', 'size', 'tare_default_kg'];
    /**
     * Get weighings for this basket.
     */
    public function weighings(): HasMany
    {
        return $this->hasMany(Weighing::class);
    }

    public function deliverySessions(): HasMany
    {
        return $this->hasMany(DeliverySession::class);
    }
}
