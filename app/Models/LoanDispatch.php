<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoanDispatch extends Model
{
    protected $fillable = [
        'loan_id',
        'loan_date',
        'due_date',
        'received_by_name',
        'delivered_by_user_id',
        'observations',
    ];

    protected $casts = [
        'loan_date' => 'date',
        'due_date' => 'date',
    ];

    public function loan()
    {
        return $this->belongsTo(Loan::class);
    }

    public function deliveredBy()
    {
        return $this->belongsTo(User::class, 'delivered_by_user_id');
    }

    public function items()
    {
        return $this->hasMany(LoanDispatchItem::class);
    }
}
