<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class WhatsappDispatchItem extends Model
{
    protected $table = 'whatsapp_dispatch_items';

    protected $fillable = [
        'uuid',
        'dispatch_id',
        'layout_id',
        'user_list_id',
        'phone',
        'whatsapp_message_id',
        'status',
        'delivery_status',
        'attempts',
        'error_message',
        'scheduled_at',
        'sent_at',
        'delivered_at',
        'read_at',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'read_at' => 'datetime',
    ];

    protected static function booted()
    {
        static::creating(function ($item) {
            if (empty($item->uuid)) {
                $item->uuid = Str::limit(Str::uuid(), 8, '');
            }
        });
    }

    public function dispatch(): BelongsTo
    {
        return $this->belongsTo(WhatsappDispatch::class, 'dispatch_id');
    }

    public function layout(): BelongsTo
    {
        return $this->belongsTo(WhatsappLayout::class, 'layout_id', 'uuid');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(UserList::class, 'user_list_id', 'uuid');
    }
}
