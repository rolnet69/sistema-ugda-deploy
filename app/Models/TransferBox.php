<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TransferBox extends Model
{
    use HasFactory;

    protected $fillable = [
        'transfer_id',
        'series_name',
        'start_year',
        'end_year',
        'box_number',
        'box_code',
        'title',
        'period_label',
        'location_code',
        'assigned_by_user_id',
        'assigned_at',
        'content_description',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
    ];

    public function formattedBoxNumber(): string
    {
        return str_pad((string) $this->box_number, 3, '0', STR_PAD_LEFT);
    }

    public function boxCode(?string $transferCode = null): string
    {
        if (filled($this->box_code)) {
            return (string) $this->box_code;
        }

        $code = $transferCode ?: $this->transfer?->code ?: 'PENDIENTE';

        return 'C-' . $code . '-' . $this->formattedBoxNumber();
    }

    public function transfer()
    {
        return $this->belongsTo(Transfer::class);
    }

    public function assignedBy()
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }

    public function documents()
    {
        return $this->hasMany(TransferDocument::class)->orderBy('sort_order');
    }
}
