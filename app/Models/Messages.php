<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Messages extends Model
{
    protected $table = 'messages';
    protected $fillable = [
        'agent_id',
        'chat_id',
        'name',
        'content',
        'credit_usage',
        'direction',
        'phone',
        'audio',
        'whatsapp_message_id',
        'delivery_status',
        'delivered_at',
        'read_at',
    ];

    protected $casts = [
        'delivered_at' => 'datetime',
        'read_at' => 'datetime',
    ];

    public function agent()
    {
        return $this->belongsTo(Agents::class, 'agent_id', 'uuid');
    }

    public function chat()
    {
        return $this->belongsTo(Chats::class, 'chat_id', 'uuid');
    }
}
