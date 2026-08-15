<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoanReturnItem extends Model
{
    protected $fillable = [
        'loan_return_id',
        'loan_document_id',
    ];

    public function loanReturn()
    {
        return $this->belongsTo(LoanReturn::class, 'loan_return_id');
    }

    public function document()
    {
        return $this->belongsTo(LoanDocument::class, 'loan_document_id');
    }
}
