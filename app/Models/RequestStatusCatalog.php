<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RequestStatusCatalog extends Model
{
    protected $fillable = [
        'request_type',
        'category',
        'code',
        'label',
        'tone',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
