<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SmsDispatchItem extends Model
{
    protected $table = 'sms_dispatch_items';

    protected $fillable = [
        'uuid', 'dispatch_id', 'layout_id', 'user_list_id', 'phone', 'message',
        'provider_batch_id', 'provider_message_id', 'provider_status', 'status',
        'attempts', 'error_message', 'scheduled_at', 'sent_at', 'delivered_at',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $item): void {
            $item->uuid ??= Str::limit(Str::uuid(), 8, '');
        });
    }

    public function dispatch(): BelongsTo
    {
        return $this->belongsTo(SmsDispatch::class, 'dispatch_id');
    }

    public function layout(): BelongsTo
    {
        return $this->belongsTo(SmsLayout::class, 'layout_id', 'uuid');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(UserList::class, 'user_list_id', 'uuid');
    }
}
