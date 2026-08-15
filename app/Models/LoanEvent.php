<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoanEvent extends Model
{
    protected $fillable = [
        'loan_id',
        'status_catalog_id',
        'actor_user_id',
        'actor_name_snapshot',
        'event_type',
        'title',
        'description',
        'context',
        'occurred_at',
    ];

    protected $casts = [
        'context' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function loan()
    {
        return $this->belongsTo(Loan::class);
    }

    public function status()
    {
        return $this->belongsTo(RequestStatusCatalog::class, 'status_catalog_id');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
