<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransferEvent extends Model
{
    protected $fillable = [
        'transfer_id',
        'status_catalog_id',
        'actor_user_id',
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

    public function transfer()
    {
        return $this->belongsTo(Transfer::class);
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
