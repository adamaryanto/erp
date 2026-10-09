<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeliveryOrder extends Model
{
    protected $fillable = ['number', 'supplier_id', 'issued_at', 'target_kg', 'target_ekor', 'status', 'note'];
    protected function casts(): array
    {
        return [
            'issued_at' => 'date',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(DeliverySession::class, 'do_id');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    /** Total bersih sudah ditimbang semua truk DO ini. */
    public function weighedNet(): float
    {
        return (float) $this->sessions->sum(fn (DeliverySession $s) => $s->netTotal());
    }

    public function weighedCount(): int
    {
        return (int) $this->sessions->sum(fn (DeliverySession $s) => $s->basketCount());
    }

    public function remainingKg(): float
    {
        return (float) $this->target_kg - $this->weighedNet();
    }

    /** 0-100, buat progress bar. */
    public function progressPct(): float
    {
        if ((float) $this->target_kg <= 0) {
            return 0;
        }

        return min(100, max(0, $this->weighedNet() / (float) $this->target_kg * 100));
    }
}
