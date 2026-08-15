<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DocumentarySeries extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'documentary_series';

    protected $fillable = [
        'code',
        'name',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function subseries()
    {
        return $this->hasMany(DocumentarySubseries::class, 'documentary_series_id')
            ->whereNull('documentary_subseries.deleted_at')
            ->orderBy('code');
    }

    public function units()
    {
        return $this->belongsToMany(Unit::class, 'documentary_series_unit')
            ->withTimestamps()
            ->orderBy('name');
    }
}
