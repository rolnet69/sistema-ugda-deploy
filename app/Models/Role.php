<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Role extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'is_active',
    ];

    public function profiles()
    {
        return $this->belongsToMany(Profile::class, 'profile_role')
            ->wherePivotNull('deleted_at')
            ->withPivot(['is_active', 'created_at', 'updated_at', 'deleted_at']);
    }
}
