<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoanReturn extends Model
{
    protected $fillable = [
        'loan_id',
        'return_date',
        'received_by_user_id',
        'condition_label',
        'observations',
    ];

    protected $casts = [
        'return_date' => 'date',
    ];

    public function loan()
    {
        return $this->belongsTo(Loan::class);
    }

    public function receivedBy()
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }

    public function items()
    {
        return $this->hasMany(LoanReturnItem::class);
    }
}
