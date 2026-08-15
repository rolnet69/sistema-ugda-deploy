<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Person extends Model
{
    use HasFactory;

    protected $table = 'person';

    protected $fillable = [
        'first_name',
        'second_name',
        'first_last_name',
        'second_last_name',
        'carnet',
        'user_id',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
