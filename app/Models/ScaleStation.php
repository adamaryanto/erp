<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScaleStation extends Model
{
    protected $fillable = ['code', 'name', 'stage', 'location', 'com_port', 'baud_rate', 'is_active'];
    /**
     * Tahap proses yang dilayani pos ini (untuk pengelompokan monitoring).
     */
    public const STAGES = [
        'hidup' => 'Timbang Hidup',
        'gantung' => 'Gantung',
        'karkas' => 'Karkas',
        'parting' => 'Parting',
        'gudang' => 'Gudang',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get weighings recorded at this station.
     */
    public function weighings(): HasMany
    {
        return $this->hasMany(Weighing::class);
    }

    /**
     * Resolve station by code, auto-creating it on first bridge contact
     * so a new machine appears by just running the bridge with --station.
     */
    public static function resolve(?string $code): self
    {
        $code = strtoupper(trim($code ?: 'POS-01'));

        return self::firstOrCreate(
            ['code' => $code],
            ['name' => 'Pos '.$code, 'com_port' => 'AUTO', 'baud_rate' => 9600, 'is_active' => true]
        );
    }
}
