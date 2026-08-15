<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoanDocument extends Model
{
    protected $fillable = [
        'loan_id',
        'document_kind',
        'group_title',
        'title',
        'series_label',
        'box_code',
        'year_label',
        'unit_name_snapshot',
        'document_type_label',
        'document_type_tone',
        'quantity_label',
        'note',
        'found_in_search',
        'selected_for_loan',
        'returned',
        'sort_order',
    ];

    protected $casts = [
        'found_in_search' => 'boolean',
        'selected_for_loan' => 'boolean',
        'returned' => 'boolean',
    ];

    public function loan()
    {
        return $this->belongsTo(Loan::class);
    }

    public function modifications()
    {
        return $this->hasMany(LoanDocumentModification::class)->latest();
    }
}
