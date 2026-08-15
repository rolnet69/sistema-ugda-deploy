<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PhysicalLocationShelf extends Model
{
    use HasFactory;

    protected $fillable = [
        'physical_location_aisle_id',
        'name',
        'code',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function aisle()
    {
        return $this->belongsTo(PhysicalLocationAisle::class, 'physical_location_aisle_id');
    }
}
