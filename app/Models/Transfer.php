<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transfer extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'user_id',
        'unit_id',
        'request_date',
        'requested_at',
        'status',
        'authorization_status_id',
        'workflow_status_id',
        'authorized_by_user_id',
        'authorized_at',
        'completed_by_user_id',
        'completed_at',
        'scheduled_for',
        'view_mode',
        'box_display_state',
        'show_print_card',
        'description',
        'observation',
    ];

    protected $casts = [
        'request_date' => 'date',
        'requested_at' => 'datetime',
        'authorized_at' => 'datetime',
        'completed_at' => 'datetime',
        'scheduled_for' => 'datetime',
        'show_print_card' => 'boolean',
    ];

    public function requester()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    public function units()
    {
        return $this->belongsToMany(Unit::class, 'transfer_unit')
            ->withTimestamps()
            ->orderBy('name');
    }

    public function authorizationStatus()
    {
        return $this->belongsTo(RequestStatusCatalog::class, 'authorization_status_id');
    }

    public function workflowStatus()
    {
        return $this->belongsTo(RequestStatusCatalog::class, 'workflow_status_id');
    }

    public function authorizedBy()
    {
        return $this->belongsTo(User::class, 'authorized_by_user_id');
    }

    public function completedBy()
    {
        return $this->belongsTo(User::class, 'completed_by_user_id');
    }

    public function boxes()
    {
        return $this->hasMany(TransferBox::class)->orderBy('box_number');
    }

    public function events()
    {
        return $this->hasMany(TransferEvent::class)->orderByDesc('occurred_at');
    }
}
