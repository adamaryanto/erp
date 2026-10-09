<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliverySession extends Model
{
    protected $fillable = ['supplier_id', 'do_id', 'basket_id', 'jenis_ayam', 'basket_deduction_manual', 'vehicle_no', 'driver_name', 'delivered_at', 'do_weight', 'total_ekor', 'dead_count', 'dead_kg', 'wet_kg', 'wet_count', 'note'];
    protected function casts(): array
    {
        return [
            'delivered_at' => 'date',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function deliveryOrder(): BelongsTo
    {
        return $this->belongsTo(DeliveryOrder::class, 'do_id');
    }

    public function basket(): BelongsTo
    {
        return $this->belongsTo(Basket::class);
    }

    /**
     * Timbangan yang cocok: (supplier + mobil + tanggal sama) ATAU do_id
     * yang sama (timbang-cam sekarang nyimpan DO langsung).
     * Plat dinormalisasi (kapital, tanpa spasi) agar "B 9482 FJA"
     * tetap nyambung dengan "B9482FJA".
     */
    public function matchedWeighings()
    {
        $plate = preg_replace('/\s+/', '', strtoupper($this->vehicle_no ?? ''));
        $doId = $this->do_id;

        return Weighing::where(function ($q) use ($plate, $doId) {
            $q->where(function ($qq) use ($plate) {
                $qq->where('supplier_id', $this->supplier_id)
                    ->whereDate('weighed_at', $this->delivered_at)
                    ->whereRaw("REPLACE(UPPER(vehicle_no), ' ', '') = ?", [$plate]);
            });
            if ($doId) {
                $q->orWhere('do_id', $doId);
            }
        });
    }

    /**
     * Ada data timbangan yang nyambung atau belum.
     * Kalau belum, angka turunan (bersih/susut) jangan ditampilkan
     * sebagai angka — tampilkan strip.
     */
    public function hasData(): bool
    {
        return $this->basketCount() > 0;
    }

    public function basketCount(): int
    {
        return $this->matchedWeighings()->count();
    }

    /** ① Timbang Kotor = jumlah semua bacaan keranjang. */
    public function grossTotal(): float
    {
        return (float) $this->matchedWeighings()->sum('gross_avg');
    }

    public function tarePerBasket(): float
    {
        return (float) ($this->basket->tare_default_kg ?? 0);
    }

    /** Potongan keranjang: manual (jika diisi) atau otomatis (count × tare). */
    public function basketDeduction(): float
    {
        if ($this->basket_deduction_manual !== null) {
            return (float) $this->basket_deduction_manual;
        }

        return $this->basketCount() * $this->tarePerBasket();
    }

    /** ② Timbang Bersih = Kotor − Keranjang − Mati − Basah. */
    public function netTotal(): float
    {
        return $this->grossTotal() - $this->basketDeduction() - (float) $this->dead_kg - (float) $this->wet_kg;
    }

    /** ③ Susut = DO Asal − Bersih. */
    public function shrinkage(): float
    {
        return (float) $this->do_weight - $this->netTotal();
    }

    /** ④ Persentase susut. */
    public function shrinkagePct(): float
    {
        if ((float) $this->do_weight <= 0) {
            return 0;
        }

        return $this->shrinkage() / (float) $this->do_weight * 100;
    }

    /** ⑤ Rata-rata ayam = DO ÷ ekor. */
    public function avgBird(): float
    {
        if ($this->total_ekor <= 0) {
            return 0;
        }

        return (float) $this->do_weight / $this->total_ekor;
    }

    public function isBalanced(): bool
    {
        return abs($this->shrinkage()) < 0.01;
    }

    /** Warning: dead_kg jauh dari dead_count × rata-rata. */
    public function warningDeadKg(): ?string
    {
        if (! $this->dead_count || ! $this->dead_kg || ! $this->avgBird()) {
            return null;
        }
        $expected = $this->dead_count * $this->avgBird();
        if (abs($this->dead_kg - $expected) > max(2.0, $expected * 0.2)) {
            return 'Ayam mati '.number_format($this->dead_kg, 2).' kg untuk '.$this->dead_count.' ekor, padahal rata-rata '.number_format($this->avgBird(), 2).' kg/ekor (≈ '.number_format($expected, 2).' kg). Cek lagi?';
        }

        return null;
    }

    /** Warning: ekor per keranjang di luar 5-20. */
    public function warningEkorPerBasket(): ?string
    {
        if (! $this->basketCount()) {
            return null;
        }
        $perBasket = $this->total_ekor / $this->basketCount();
        // Parent: 5-7 ekor/keranjang @ ~5 kg/ekor. Biasa: 5-20 ekor.
        $isParent = (($this->jenis_ayam ?? 'biasa') === 'parent');
        [$min, $max] = $isParent ? [4, 8] : [5, 20];
        if ($perBasket < $min || $perBasket > $max) {
            $label = $isParent ? 'parent' : 'biasa';

            return "Ekor per keranjang: {$perBasket}/keranjang (normal {$label}: {$min}-{$max}). Cek data?";
        }

        return null;
    }

    /** Warning: susut negatif (bersih > DO, mustahil). */
    public function warningShrinkageNegative(): ?string
    {
        if ($this->hasData() && $this->shrinkage() < -0.01) {
            return 'Susut negatif: bersih > DO. Cek input timbangan/potongan!';
        }

        return null;
    }
}
