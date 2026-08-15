<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PhysicalLocationAisle extends Model
{
    use HasFactory;

    protected $fillable = [
        'physical_location_office_id',
        'name',
        'code',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function office()
    {
        return $this->belongsTo(PhysicalLocationOffice::class, 'physical_location_office_id');
    }

    public function shelves()
    {
        return $this->hasMany(PhysicalLocationShelf::class);
    }
}
