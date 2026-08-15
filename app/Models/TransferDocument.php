<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransferDocument extends Model
{
    protected $table = 'transfer_box_documents';

    protected $fillable = [
        'transfer_box_id',
        'code',
        'name',
        'series_label',
        'support_type',
        'year_label',
        'pages_label',
        'digital_file_name',
        'digital_file_path',
        'sort_order',
        'is_reserved',
        'reserved_by_user_id',
        'reserved_at',
    ];

    protected $casts = [
        'is_reserved' => 'boolean',
        'reserved_at' => 'datetime',
    ];

    public function box()
    {
        return $this->belongsTo(TransferBox::class, 'transfer_box_id');
    }

    public function reservedBy()
    {
        return $this->belongsTo(User::class, 'reserved_by_user_id');
    }
}
