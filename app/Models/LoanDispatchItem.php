<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoanDispatchItem extends Model
{
    protected $fillable = [
        'loan_dispatch_id',
        'loan_document_id',
    ];

    public function dispatch()
    {
        return $this->belongsTo(LoanDispatch::class, 'loan_dispatch_id');
    }

    public function document()
    {
        return $this->belongsTo(LoanDocument::class, 'loan_document_id');
    }
}
