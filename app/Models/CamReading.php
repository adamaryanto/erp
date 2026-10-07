<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CamReading extends Model
{
    protected $fillable = [
        'scale_station_id', 'mesin_weighing_id', 'weighed_at',
        'image_path',
        'ocr_weight', 'ocr_conf',
        'basket_pred', 'basket_conf',
        'chicken_count', 'chicken_conf',
        'selisih_kg', 'selisih_persen',
        'raw_payload',
    ];
    protected function casts(): array
    {
        return [
            'weighed_at' => 'datetime',
            'raw_payload' => 'array',
        ];
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(ScaleStation::class, 'scale_station_id');
    }

    /**
     * Timbangan mesin pasangannya (ground truth buat bayar).
     */
    public function mesinWeighing(): BelongsTo
    {
        return $this->belongsTo(Weighing::class, 'mesin_weighing_id');
    }
}
