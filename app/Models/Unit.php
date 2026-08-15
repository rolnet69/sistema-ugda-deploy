<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Model;

class Unit extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['name', 'code', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function parents()
    {
        return $this->belongsToMany(Unit::class, 'unit_dependencies', 'unit_id', 'parent_id');
    }

    public function children()
    {
        return $this->belongsToMany(Unit::class, 'unit_dependencies', 'parent_id', 'unit_id');
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'user_unit')
            ->wherePivotNull('deleted_at')
            ->withPivot(['is_active', 'created_at', 'updated_at', 'deleted_at']);
    }
}
