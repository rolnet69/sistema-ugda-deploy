<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoanDocumentModification extends Model
{
    protected $fillable = [
        'loan_id',
        'loan_document_id',
        'registered_by_user_id',
        'description',
    ];

    public function loan()
    {
        return $this->belongsTo(Loan::class);
    }

    public function document()
    {
        return $this->belongsTo(LoanDocument::class, 'loan_document_id');
    }

    public function registeredBy()
    {
        return $this->belongsTo(User::class, 'registered_by_user_id');
    }
}
