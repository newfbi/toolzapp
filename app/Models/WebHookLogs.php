<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebHookLogs extends Model
{
    protected $table = 'webhook_logs';
    protected $guarded = ['id'];

    protected $casts = [
        'payload' => 'array',
    ];
}
