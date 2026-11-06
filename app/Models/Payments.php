<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payments extends Model
{
    protected $fillable = [
        'uuid',
        'user_id',
        'spon_id',
        'reference',
        'product_name',
        'client_id',
        'billingType',
        'tipo',
        'gateway',
        'validade',
        'valor',
        'status',
        'codigo',
        'pix_code',
        'qr_code',
        'data_compra',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'uuid');
    }

}
