<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Loan extends Model
{
    use HasFactory;

    protected $fillable = [
        'number',
        'user_id',
        'unit_id',
        'requested_at',
        'authorization_status_id',
        'workflow_status_id',
        'search_status_id',
        'authorized_by_user_id',
        'authorized_at',
        'ugda_authorized_by_user_id',
        'ugda_authorized_at',
        'search_started_by_user_id',
        'search_started_at',
        'search_completed_by_user_id',
        'search_completed_at',
        'search_comments',
        'view_mode',
        'description',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'authorized_at' => 'datetime',
        'ugda_authorized_at' => 'datetime',
        'search_started_at' => 'datetime',
        'search_completed_at' => 'datetime',
    ];

    public function requester()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    public function authorizationStatus()
    {
        return $this->belongsTo(RequestStatusCatalog::class, 'authorization_status_id');
    }

    public function workflowStatus()
    {
        return $this->belongsTo(RequestStatusCatalog::class, 'workflow_status_id');
    }

    public function searchStatus()
    {
        return $this->belongsTo(RequestStatusCatalog::class, 'search_status_id');
    }

    public function authorizedBy()
    {
        return $this->belongsTo(User::class, 'authorized_by_user_id');
    }

    public function ugdaAuthorizedBy()
    {
        return $this->belongsTo(User::class, 'ugda_authorized_by_user_id');
    }

    public function searchStartedBy()
    {
        return $this->belongsTo(User::class, 'search_started_by_user_id');
    }

    public function searchCompletedBy()
    {
        return $this->belongsTo(User::class, 'search_completed_by_user_id');
    }

    public function documents()
    {
        return $this->hasMany(LoanDocument::class)->orderBy('sort_order');
    }

    public function documentModifications()
    {
        return $this->hasMany(LoanDocumentModification::class)->latest();
    }

    public function events()
    {
        return $this->hasMany(LoanEvent::class)->orderByDesc('occurred_at');
    }

    public function dispatches()
    {
        return $this->hasMany(LoanDispatch::class)->latest();
    }

    public function latestDispatch()
    {
        return $this->hasOne(LoanDispatch::class)->latestOfMany();
    }

    public function returns()
    {
        return $this->hasMany(LoanReturn::class)->latest();
    }

    public function latestReturn()
    {
        return $this->hasOne(LoanReturn::class)->latestOfMany();
    }
}
