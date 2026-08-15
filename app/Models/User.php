<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'email',
        'password',
        'is_active',
        'two_factor_method',
        'two_factor_secret',
        'two_factor_confirmed_at',
        'must_change_password',
        'temporary_password_expires_at',
        'password_changed_at',
        'last_login_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'two_factor_expires_at' => 'datetime',
            'two_factor_secret' => 'encrypted',
            'two_factor_confirmed_at' => 'datetime',
            'must_change_password' => 'boolean',
            'temporary_password_expires_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public function units()
    {
        return $this->belongsToMany(Unit::class, 'user_unit')
            ->wherePivotNull('deleted_at')
            ->withPivot(['is_active', 'created_at', 'updated_at', 'deleted_at']);
    }

    public function person()
    {
        return $this->hasOne(Person::class);
    }

    public function profiles()
    {
        return $this->belongsToMany(Profile::class, 'user_profile')
            ->wherePivotNull('deleted_at')
            ->withPivot(['is_active', 'created_at', 'updated_at', 'deleted_at']);
    }

    public function systemNotifications()
    {
        return $this->hasMany(SystemNotification::class);
    }

    public function activityLogs()
    {
        return $this->hasMany(UserActivityLog::class)->latest();
    }

    public function activeProfile(): ?Profile
    {
        if ($this->relationLoaded('profiles')) {
            return $this->profiles
                ->first(fn ($profile) => (bool) ($profile->pivot->is_active ?? false))
                ?? $this->profiles->first();
        }

        return $this->profiles()
            ->wherePivot('is_active', true)
            ->wherePivotNull('deleted_at')
            ->first()
            ?? $this->profiles()->wherePivotNull('deleted_at')->first();
    }

    public function permissionNames(): array
    {
        $profile = $this->activeProfile();

        if ($profile === null) {
            return [];
        }

        if (!$profile->relationLoaded('roles')) {
            $profile->load('roles');
        }

        return $profile->roles
            ->pluck('name')
            ->unique()
            ->values()
            ->all();
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissionNames(), true);
    }
}
