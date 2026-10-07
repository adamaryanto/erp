<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Weighing extends Model
{
    protected $fillable = ['supplier_id', 'basket_id', 'scale_station_id', 'source', 'operator_id', 'vehicle_no', 'weighed_at', 'gross_avg', 'tare_kg', 'net_kg', 'min_kg', 'max_kg', 'stddev', 'raw_samples', 'is_manual_tare', 'note'];
    protected function casts(): array
    {
        return [
            'weighed_at' => 'datetime',
            'raw_samples' => 'array',
            'is_manual_tare' => 'boolean',
        ];
    }

    /**
     * Get the supplier for this weighing.
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * Get the basket for this weighing.
     */
    public function basket(): BelongsTo
    {
        return $this->belongsTo(Basket::class);
    }

    /**
     * Get the operator for this weighing.
     */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }

    /**
     * Get the scale station where this weighing happened.
     */
    public function station(): BelongsTo
    {
        return $this->belongsTo(ScaleStation::class, 'scale_station_id');
    }
}
