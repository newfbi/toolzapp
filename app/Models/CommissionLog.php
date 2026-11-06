<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CommissionLog extends Model
{
    use HasFactory;
    protected $table = 'commission_log';
    protected $fillable = [
        'user_id',
        'client_id',
        'spon_id',
        'payment_id',
        'tipo',
        'servico',
        'valor_servico',
        'valor',
        'status',
    ];

    public function user()
    {
        return $this->hasOne(User::class, 'uuid', 'user_id');
    }

    public function sponsor()
    {
        return $this->hasOne(User::class, 'uuid', 'spon_id');
    }

    public function client()
    {
        return $this->hasOne(User::class, 'uuid', 'client_id');
    }

    public function payment()
    {
        return $this->hasOne(Payments::class, 'uuid', 'payment_id');
    }
}
