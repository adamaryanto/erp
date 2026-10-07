<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    protected $fillable = ['name', 'address', 'phone'];
    /**
     * Get weighings for this supplier.
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
