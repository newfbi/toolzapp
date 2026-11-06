<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class Saques extends Model
{
    protected $table = 'saques';
    protected $fillable = [
        'uuid',
        'user_id',
        'valor',
        'chave_pix',
        'tipo',
        'status',
        'data'
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'uuid');
    }
}
