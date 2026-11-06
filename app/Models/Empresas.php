<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Empresas extends Model
{
    protected $fillable = [
        'uuid',
        'user_id',
        'name',
        'cnpj',
        'phone',
        'logo_light',
        'logo_dark',
        'thema',
        'primary_color',
        'secundary_color',
        'description'
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'uuid', 'user_id');
    }
}
