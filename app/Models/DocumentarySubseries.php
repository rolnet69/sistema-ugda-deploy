<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DocumentarySubseries extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'documentary_subseries';

    protected $fillable = [
        'documentary_series_id',
        'code',
        'name',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function series()
    {
        return $this->belongsTo(DocumentarySeries::class, 'documentary_series_id');
    }

    public function units()
    {
        return $this->belongsToMany(Unit::class, 'documentary_subseries_unit')
            ->withTimestamps()
            ->orderBy('name');
    }
}
