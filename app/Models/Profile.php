<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Profile extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'profiles';

    protected $fillable = [
        'name',
        'description',
        'is_active',
    ];

    public function users()
    {
        return $this->belongsToMany(User::class, 'user_profile')
            ->wherePivotNull('deleted_at')
            ->withPivot(['is_active', 'created_at', 'updated_at', 'deleted_at']);
    }

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'profile_role')
            ->wherePivotNull('deleted_at')
            ->withPivot(['is_active', 'created_at', 'updated_at', 'deleted_at']);
    }
}
